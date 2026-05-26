<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie Notice Modules WP Consent API class.
 *
 * Registers the plugin as a CMP under the WP Consent API
 * (wordpress.org/plugins/wp-consent-api), so cooperative consumer
 * plugins (WooCommerce, Google Site Kit, Burst Statistics, etc.)
 * automatically gate themselves on the consent state captured by
 * the Hu-manity banner.
 *
 * Two filters are registered:
 *   1. wp_consent_api_registered_<plugin_basename> — declares this
 *      plugin as the active CMP for the site. Without this, WP
 *      Consent API treats every consumer plugin as deny-all.
 *   2. wp_get_consent_type — resolves the consent regime ('optin' /
 *      'optout' / '') from the site's selected regulations.
 *
 * JS-side category dispatch (wp_set_consent calls) lives in the
 * Web Channel banner bundle (src/thirdparty.js), not here. The
 * banner already fires set-consent.<prefix> on every consent
 * decision; the bundle calls wp_set_consent in response.
 *
 * Registration is unconditional — both hook names live in WP Consent
 * API's namespace, so if the plugin never loads, no one ever fires
 * them and the filters sit inert in the global hook table. The
 * earlier feature-detect-then-hook pattern is documented as a known
 * pitfall in research/wp-consent-api-adoption-proposal.md §5.5
 * (load-order race: cookie-notice boots before wp-consent-api in the
 * default alphabetical plugin order, so a plugins_loaded@9 callback
 * runs before wp_has_consent is defined).
 *
 * Disable lever: site admins can turn the integration off via the
 * "WP Consent API" toggle on the plugin's settings page (legacy +
 * React admin). Code-level override is available via the
 * cookie_notice_wp_consent_api_enabled filter for fleet / mu-plugin
 * use. When disabled, the registered_<basename> filter returns false
 * (WPCA falls back to deny-all for consumer plugins) and
 * get_consent_type returns the empty string regardless of
 * regulations.
 *
 * First-detection admin notice: a one-shot site-wide notice surfaces
 * the behaviour change to admins the first time both plugins are
 * active together. Dismissal persists per-site (or per-network on
 * network-activated multisite, matching get_consent_type). Diverges
 * intentionally from the React notification engine (useNotifications)
 * because the notice must fire on every admin page, not only inside
 * the React Compliance UI.
 *
 * Compatibility since: 3.1.0
 *
 * @class Cookie_Notice_Modules_WP_Consent_API
 */
class Cookie_Notice_Modules_WP_Consent_API {

	/**
	 * Opt-in laws: explicit prior consent required before any
	 * non-strictly-necessary processing. Visiting the site is NOT
	 * implied consent under any of these regimes.
	 */
	const OPT_IN_LAWS = [ 'gdpr', 'ukpecr', 'lgpd', 'popia' ];

	/**
	 * Opt-out laws: processing permitted by default; visitor must
	 * actively decline. CCPA/CPRA + state analogues, plus PIPEDA
	 * (express-consent-but-implied-by-conduct).
	 */
	const OPT_OUT_LAWS = [ 'ccpa', 'otherus', 'pipeda' ];

	/**
	 * Notice dismissal flag name. Per-site, or per-network on
	 * network-activated multisite.
	 */
	const NOTICE_DISMISSED_OPTION = 'cookie_notice_wp_consent_api_notice_dismissed';

	/**
	 * One-shot transient name for the post-disable confirmation
	 * notice. Set by handle_disable(), consumed and deleted by
	 * maybe_render_notice() on the next admin page load.
	 */
	const POST_DISABLE_NOTICE_TRANSIENT = 'cookie_notice_wp_consent_api_disabled_notice';

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		add_filter( 'wp_consent_api_registered_' . COOKIE_NOTICE_BASENAME, [ $this, 'is_enabled' ] );
		add_filter( 'wp_get_consent_type', [ $this, 'get_consent_type' ] );

		add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
		add_action( 'network_admin_notices', [ $this, 'maybe_render_notice' ] );
		add_action( 'admin_post_cookie_notice_wpca_dismiss', [ $this, 'handle_dismiss' ] );
		add_action( 'admin_post_cookie_notice_wpca_disable', [ $this, 'handle_disable' ] );
	}

	/**
	 * Resolve whether the integration is enabled for this site.
	 *
	 * Sources, in precedence order:
	 *   1. cookie_notice_options[wp_consent_api] — settings toggle.
	 *      Defaults to enabled when the key is missing (covers sites
	 *      upgrading from a version that pre-dates the toggle).
	 *   2. cookie_notice_wp_consent_api_enabled filter — code-level
	 *      override for fleet / mu-plugin use. Runs last so it wins.
	 *
	 * Doubles as the wp_consent_api_registered_<basename> callback —
	 * returning false there signals to WP Consent API that we are
	 * NOT the active CMP for this site, and consumer plugins fall
	 * back to deny-all.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$options = Cookie_Notice()->is_plugin_network_active()
			? get_site_option( 'cookie_notice_options', [] )
			: get_option( 'cookie_notice_options', [] );

		$option_enabled = ! is_array( $options ) || ! isset( $options['wp_consent_api'] ) || (bool) $options['wp_consent_api'];

		return (bool) apply_filters( 'cookie_notice_wp_consent_api_enabled', $option_enabled );
	}

	/**
	 * Resolve the consent regime for this site.
	 *
	 * Reads cookie_notice_app_regulations (kept in sync with the
	 * Designer API by welcome-api.php). Opt-in wins over opt-out
	 * when both are present — strictest regime applies sitewide,
	 * matching how the banner itself behaves.
	 *
	 *   'optin'   — at least one opt-in law selected (GDPR/UKPECR/LGPD/POPIA)
	 *   'optout'  — at least one opt-out law selected (CCPA/OTHERUS/PIPEDA)
	 *   ''        — no regulations selected, OR integration is
	 *               disabled via toggle/filter. Empty-string semantics
	 *               make wp_has_consent() permissive across categories,
	 *               matching a site that explicitly opted out of CMP
	 *               participation.
	 *
	 * Return type is enforced as `string` to match WP Consent API's own
	 * wp_get_consent_type(): string contract — returning null here triggers
	 * a TypeError on PHP 8+.
	 *
	 * @param mixed $default Previous filter value (ignored).
	 * @return string
	 */
	public function get_consent_type( $default = '' ): string {
		if ( ! $this->is_enabled() )
			return '';

		$regulations = Cookie_Notice()->is_plugin_network_active()
			? get_site_option( 'cookie_notice_app_regulations', [] )
			: get_option( 'cookie_notice_app_regulations', [] );

		if ( ! is_array( $regulations ) || empty( $regulations ) )
			return '';

		if ( ! empty( array_intersect( $regulations, self::OPT_IN_LAWS ) ) )
			return 'optin';

		if ( ! empty( array_intersect( $regulations, self::OPT_OUT_LAWS ) ) )
			return 'optout';

		return '';
	}

	/**
	 * Render the first-detection admin notice when both plugins
	 * are active and the admin hasn't dismissed it. Also consumes
	 * the post-disable confirmation transient.
	 *
	 * Capability-gated so subscribers/editors don't see a
	 * "Disable" action they can't act on.
	 *
	 * @return void
	 */
	public function maybe_render_notice() {
		if ( ! current_user_can( 'manage_options' ) )
			return;

		// Post-disable confirmation — one-shot, consumed here.
		if ( $this->consume_post_disable_notice() ) {
			$reenable_snippet = "add_filter( 'cookie_notice_wp_consent_api_enabled', '__return_true' );";

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p><p><code>%s</code></p></div>',
				esc_html__( 'WP Consent API integration disabled. Re-enable it any time from Compliance settings (Technical Settings) or via this filter snippet:', 'cookie-notice' ),
				esc_html( $reenable_snippet )
			);
		}

		// Primary first-detection notice — only when WPCA is loaded,
		// the integration is enabled, and the admin hasn't dismissed.
		if ( ! function_exists( 'wp_has_consent' ) )
			return;

		if ( ! $this->is_enabled() )
			return;

		if ( $this->is_notice_dismissed() )
			return;

		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=cookie_notice_wpca_dismiss' ),
			'cookie_notice_wpca_dismiss'
		);
		$disable_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=cookie_notice_wpca_disable' ),
			'cookie_notice_wpca_disable'
		);
		$learn_more_url = 'https://wordpress.org/plugins/wp-consent-api/';

		$confirm_msg = esc_js( __( 'Disable the WP Consent API integration? Cooperative plugins will stop gating on banner consent.', 'cookie-notice' ) );

		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p><p><a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a> &nbsp;|&nbsp; <a href="%5$s" onclick="return confirm(\'%6$s\');">%7$s</a> &nbsp;|&nbsp; <a href="%8$s">%9$s</a></p></div>',
			esc_html__( 'WP Consent API integration active.', 'cookie-notice' ),
			esc_html__( 'Compliance by Hu-manity.co is now registered as the active Consent Management Platform for this site. Cooperative plugins that support WP Consent API (WooCommerce, Google Site Kit, Burst Statistics, WP Statistics, and others) will gate themselves on the consent state captured by the banner.', 'cookie-notice' ),
			esc_url( $learn_more_url ),
			esc_html__( 'Learn more about WP Consent API', 'cookie-notice' ),
			esc_url( $disable_url ),
			$confirm_msg,
			esc_html__( 'Disable integration', 'cookie-notice' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'cookie-notice' )
		);
	}

	/**
	 * Handle the Dismiss action. Persists the dismissed flag and
	 * redirects back to the referring admin page.
	 *
	 * @return void
	 */
	public function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) )
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'cookie-notice' ), '', [ 'response' => 403 ] );

		check_admin_referer( 'cookie_notice_wpca_dismiss' );

		$this->set_notice_dismissed();

		wp_safe_redirect( $this->get_safe_redirect_target() );
		exit;
	}

	/**
	 * Handle the Disable action. Flips the cookie_notice_options
	 * wp_consent_api flag to false, sets the dismissed flag, queues
	 * a one-shot confirmation notice, then redirects back.
	 *
	 * @return void
	 */
	public function handle_disable() {
		if ( ! current_user_can( 'manage_options' ) )
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'cookie-notice' ), '', [ 'response' => 403 ] );

		check_admin_referer( 'cookie_notice_wpca_disable' );

		$network = Cookie_Notice()->is_plugin_network_active();

		$options = $network
			? get_site_option( 'cookie_notice_options', [] )
			: get_option( 'cookie_notice_options', [] );

		if ( ! is_array( $options ) )
			$options = [];

		$options['wp_consent_api'] = false;

		if ( $network )
			update_site_option( 'cookie_notice_options', $options );
		else
			update_option( 'cookie_notice_options', $options );

		$this->set_notice_dismissed();

		set_transient( self::POST_DISABLE_NOTICE_TRANSIENT, 1, MINUTE_IN_SECONDS );

		wp_safe_redirect( $this->get_safe_redirect_target() );
		exit;
	}

	/**
	 * Whether the first-detection notice has been dismissed.
	 *
	 * @return bool
	 */
	private function is_notice_dismissed() {
		return (bool) ( Cookie_Notice()->is_plugin_network_active()
			? get_site_option( self::NOTICE_DISMISSED_OPTION, false )
			: get_option( self::NOTICE_DISMISSED_OPTION, false ) );
	}

	/**
	 * Persist the dismissed flag for the current site/network.
	 *
	 * @return void
	 */
	private function set_notice_dismissed() {
		if ( Cookie_Notice()->is_plugin_network_active() )
			update_site_option( self::NOTICE_DISMISSED_OPTION, true );
		else
			update_option( self::NOTICE_DISMISSED_OPTION, true, false );
	}

	/**
	 * Consume the one-shot post-disable transient. Returns true if
	 * the transient was set (and was therefore deleted), false otherwise.
	 *
	 * @return bool
	 */
	private function consume_post_disable_notice() {
		if ( ! get_transient( self::POST_DISABLE_NOTICE_TRANSIENT ) )
			return false;

		delete_transient( self::POST_DISABLE_NOTICE_TRANSIENT );

		return true;
	}

	/**
	 * Where to send the admin after a dismiss/disable. Prefer the
	 * page they came from (wp_get_referer), fall back to the admin
	 * dashboard. wp_safe_redirect refuses off-site URLs.
	 *
	 * @return string
	 */
	private function get_safe_redirect_target() {
		$referer = wp_get_referer();

		return $referer ? $referer : admin_url();
	}
}

new Cookie_Notice_Modules_WP_Consent_API();
