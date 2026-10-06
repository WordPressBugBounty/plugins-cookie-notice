<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie_Notice_Settings class.
 *
 * @class Cookie_Notice_Settings
 */
class Cookie_Notice_Settings {

	private $tabs = [];
	private $current_tab = '';
	private $sections = [];
	private $current_section = '';
	public $parameters = [];
	public $operators = [];
	public $conditional_display_types = [];
	public $positions = [];
	public $styles = [];
	public $revoke_opts = [];
	public $links = [];
	public $link_targets = [];
	public $link_positions = [];
	public $colors = [];
	public $times = [];
	public $effects = [];
	public $script_placements = [];
	public $level_names = [];
	public $text_strings = [];
	private $analytics_app_data = [];

	/**
	 * True while store_options() or store_option_keys() writes cookie_notice_options itself.
	 *
	 * update_option() runs the registered sanitize callback, validate_options(), which
	 * fires cn_configuration_updated. Those two fire it once, after the write — this
	 * keeps validate_options() from firing a second one.
	 *
	 * @var bool
	 */
	private $internal_write = false;

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'admin_menu', [ $this, 'admin_menu_options' ] );
		add_action( 'network_admin_menu', [ $this, 'admin_menu_options' ] );
		add_action( 'after_setup_theme', [ $this, 'load_defaults' ] );
		add_action( 'plugins_loaded', [ $this, 'load_modules' ], 0 );
		add_action( 'admin_init', [ $this, 'validate_network_options' ], 9 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'check_notices' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
		add_action( 'admin_print_styles', [ $this, 'admin_print_styles' ] );
		add_action( 'wp_ajax_cn_purge_cache', [ $this, 'ajax_purge_cache' ] );
		add_action( 'wp_ajax_cn-get-group-rules-values', [ $this, 'get_group_rule_values' ] );
		add_action( 'admin_notices', [ $this, 'settings_errors' ] );
		add_action( 'network_admin_notices', [ $this, 'settings_errors' ] );
	}

	/**
	 * Check whether caching compatibility is enabled. Also just before saving settings.
	 *
	 * @return bool
	 */
	public function is_caching_compatibility() {
		// get current value from database
		$db_cc = Cookie_Notice()->options['general']['caching_compatibility'];

		// if it is enabled allow immediately
		if ( $db_cc )
			return true;

		// check caching compatibility before it is saved, needed when we change caching_compatibility from false to true
		if ( ! ( isset( $_POST['save_cookie_notice_options'], $_POST['action'], $_POST['_wpnonce'], $_POST['option_page'], $_POST['cookie_notice_options'] ) && $_POST['option_page'] === 'cookie_notice_options' && wp_verify_nonce( $_POST['_wpnonce'], 'cookie_notice_options-options' ) !== false ) )
			return false;

		// check availability of caching compatibility itself
		if ( ! isset( $_POST['cookie_notice_options']['caching_compatibility'] ) )
			return false;

		// get active caching plugins
		$active_plugins = cn_get_active_caching_plugins();

		// return caching compatibility on the fly
		return ! empty( $active_plugins );
	}

	/**
	 * Load additional modules.
	 *
	 * @return void
	 */
	public function load_modules() {
		// JS-EXCLUSION-capable optimizers load UNCONDITIONALLY (regardless of the
		// caching-compatibility toggle or connection status). Their JS-exclusion
		// FILTER must register even with caching-compat OFF: a combined/delayed
		// banner arms pre-consent script blocking too late (or not at all), which
		// is an N1 pre-consent-blocking failure — a compliance concern, not a
		// caching one. Each module self-gates its own persisted DB writes and
		// cache-purge actions behind is_caching_compatibility() (those ARE caching
		// concerns). Per-optimizer presence guards (cn_is_plugin_active) stay.
		// See DEC-006.

		// autoptimize
		if ( cn_is_plugin_active( 'autoptimize' ) )
			include_once( COOKIE_NOTICE_PATH . 'includes/modules/autoptimize/autoptimize.php' );

		// breeze
		if ( cn_is_plugin_active( 'breeze' ) )
			include_once( COOKIE_NOTICE_PATH . 'includes/modules/breeze/breeze.php' );

		// hummingbird
		if ( cn_is_plugin_active( 'hummingbird' ) )
			include_once( COOKIE_NOTICE_PATH . 'includes/modules/hummingbird/hummingbird.php' );

		// litespeed cache
		if ( cn_is_plugin_active( 'litespeed' ) )
			include_once( COOKIE_NOTICE_PATH . 'includes/modules/litespeed-cache/litespeed-cache.php' );

		// speed optimizer
		if ( cn_is_plugin_active( 'speedoptimizer' ) )
			include_once( COOKIE_NOTICE_PATH . 'includes/modules/speed-optimizer/speed-optimizer.php' );

		// wp rocket
		if ( cn_is_plugin_active( 'wprocket' ) )
			include_once( COOKIE_NOTICE_PATH . 'includes/modules/wp-rocket/wp-rocket.php' );

		// PURGE-ONLY optimizers stay fully gated behind the caching-compatibility
		// toggle + active status: they contribute no JS-exclusion filter (only
		// cache purging), so there is no pre-consent-blocking reason to load them
		// when caching compatibility is off. See DEC-006.
		if ( $this->is_caching_compatibility() && Cookie_Notice()->get_status() === 'active' ) {
			// speedycache
			if ( cn_is_plugin_active( 'speedycache' ) )
				include_once( COOKIE_NOTICE_PATH . 'includes/modules/speedycache/speedycache.php' );

			// wp fastest cache
			if ( cn_is_plugin_active( 'wpfastestcache' ) )
				include_once( COOKIE_NOTICE_PATH . 'includes/modules/wp-fastest-cache/wp-fastest-cache.php' );

			// wp-optimize
			if ( cn_is_plugin_active( 'wpoptimize' ) )
				include_once( COOKIE_NOTICE_PATH . 'includes/modules/wp-optimize/wp-optimize.php' );

			// wp super cache
			if ( cn_is_plugin_active( 'wpsupercache' ) )
				include_once( COOKIE_NOTICE_PATH . 'includes/modules/wp-super-cache/wp-super-cache.php' );
		}
	}

	/**
	 * Load plugin defaults.
	 *
	 * @return void
	 */
	public function load_defaults() {
		// set tabs
		$this->tabs = [
			'settings'			=> __( 'Cookie Consent', 'cookie-notice' ),
			'privacy-consent'	=> __( 'Privacy Consent', 'cookie-notice' ),
			'consent-logs'		=> __( 'Consent Logs', 'cookie-notice' )
		];

		// get first default tab
		$first_tab = array_key_first( $this->tabs );

		// sanitize current tab
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : $first_tab;

		// set current tab
		$this->current_tab = ! empty( $tab ) && array_key_exists( $tab, $this->tabs ) ? $tab : $first_tab;

		if ( $this->current_tab === 'consent-logs' ) {
			$this->sections = [
				'cookie'	=> __( 'Cookie Consent Logs', 'cookie-notice' ),
				'privacy'	=> __( 'Privacy Consent Logs', 'cookie-notice' )
			];

			// check section
			$section = isset( $_GET['section'] ) ? sanitize_key( $_GET['section'] ) : '';

			if ( ! $section || ! array_key_exists( $section, $this->sections ) )
				$section = array_key_first( $this->sections );

			$this->current_section = $section;
		}

		$this->parameters = [
			'page_type'			=> __( 'Page Type', 'cookie-notice' ),
			'page'				=> __( 'Page', 'cookie-notice' ),
			'post_type'			=> __( 'Post Type', 'cookie-notice' ),
			'post_type_archive'	=> __( 'Post Type Archive', 'cookie-notice' ),
			'user_type'			=> __( 'User Type', 'cookie-notice' ),
			'taxonomy_archive'	=> __( 'Taxonomy Archive', 'cookie-notice' )
		];

		$this->operators = [
			'equal'		=> __( 'is equal to', 'cookie-notice' ),
			'not_equal'	=> __( 'is not equal to', 'cookie-notice' )
		];

		$this->conditional_display_types = [
			'hide'	=> __( 'Hide the banner', 'cookie-notice' ),
			'show'	=> __( 'Show the banner', 'cookie-notice' )
		];

		$this->positions = [
			'top'		=> __( 'Top', 'cookie-notice' ),
			'bottom'	=> __( 'Bottom', 'cookie-notice' )
		];

		$this->styles = [
			'none'			=> __( 'None', 'cookie-notice' ),
			'wp-default'	=> __( 'Light', 'cookie-notice' ),
			'bootstrap'		=> __( 'Dark', 'cookie-notice' )
		];

		$this->revoke_opts = [
			'automatic'	=> __( 'Automatic', 'cookie-notice' ),
			'manual'	=> __( 'Manual', 'cookie-notice' )
		];

		$this->links = [
			'page'		=> __( 'Page link', 'cookie-notice' ),
			'custom'	=> __( 'Custom link', 'cookie-notice' )
		];

		$this->link_targets = [ '_blank', '_self' ];

		$this->link_positions = [
			'banner'	=> __( 'Banner', 'cookie-notice' ),
			'message'	=> __( 'Message', 'cookie-notice' )
		];

		$this->colors = [
			'text'		=> __( 'Text color', 'cookie-notice' ),
			'button'	=> __( 'Button color', 'cookie-notice' ),
			'bar'		=> __( 'Bar color', 'cookie-notice' )
		];

		$this->times = apply_filters(
			'cn_cookie_expiry',
			[
				'hour'		=> [ __( 'An hour', 'cookie-notice' ), HOUR_IN_SECONDS ],
				'day'		=> [ __( '1 day', 'cookie-notice' ), DAY_IN_SECONDS ],
				'week'		=> [ __( '1 week', 'cookie-notice' ), WEEK_IN_SECONDS ],
				'month'		=> [ __( '1 month', 'cookie-notice' ), MONTH_IN_SECONDS ],
				'3months'	=> [ __( '3 months', 'cookie-notice' ), 7862400 ],
				'6months'	=> [ __( '6 months', 'cookie-notice' ), 15811200 ],
				'year'		=> [ __( '1 year', 'cookie-notice' ), YEAR_IN_SECONDS ],
				'infinity'	=> [ __( 'infinity', 'cookie-notice' ), 2147483647 ]
			]
		);

		$this->effects = [
			'none'	=> __( 'None', 'cookie-notice' ),
			'fade'	=> __( 'Fade', 'cookie-notice' ),
			'slide'	=> __( 'Slide', 'cookie-notice' )
		];

		$this->script_placements = [
			'header'	=> __( 'Header', 'cookie-notice' ),
			'footer'	=> __( 'Footer', 'cookie-notice' )
		];

		$this->level_names = [
			1 => [
				1 => __( 'Private', 'cookie-notice' ),
				2 => __( 'Balanced', 'cookie-notice' ),
				3 => __( 'Personalized', 'cookie-notice' )
			],
			2 => [
				1 => __( 'Silver', 'cookie-notice' ),
				2 => __( 'Gold', 'cookie-notice' ),
				3 => __( 'Platinum', 'cookie-notice' )
			],
			3 => [
				1 => __( 'Reject All', 'cookie-notice' ),
				2 => __( 'Accept Some', 'cookie-notice' ),
				3 => __( 'Accept All', 'cookie-notice' )
			]
		];

		$this->text_strings = [
			'saveBtnText'		=> __( 'Save my preferences', 'cookie-notice' ),
			'privacyBtnText'	=> __( 'Privacy policy', 'cookie-notice' ),
			'dontSellBtnText'	=> __( 'Do Not Sell', 'cookie-notice' ),
			'customizeBtnText'	=> __( 'Preferences', 'cookie-notice' ),
			'headingText'		=> __( "Your data is your property and we support your right to privacy and transparency.", 'cookie-notice' ),
			'bodyText'			=> __( "To provide you the best experience on our website, we use cookies or similar technologies. Select a data access level to decide for which purposes we may use and share your data.", 'cookie-notice' ),
			'levelBodyText_1'	=> __( 'Highest level of privacy. Data accessed for necessary site operations only. Data shared with 3rd parties to ensure the site is secure and works on your device.', 'cookie-notice' ),
			'levelBodyText_2'	=> __( 'Balanced experience. Data accessed for content personalisation and site optimisation. Data shared with 3rd parties may be used to track and store your preferences for this site.', 'cookie-notice' ),
			'levelBodyText_3'	=> __( 'Highest level of personalisation. Data accessed to make ads and media more relevant. Data shared with 3rd parties may be use to track you on this site and other sites you visit.', 'cookie-notice' ),
			'levelNameText_1'	=> $this->level_names[1][1],
			'levelNameText_2'	=> $this->level_names[1][2],
			'levelNameText_3'	=> $this->level_names[1][3],
			'monthText'			=> __( 'month', 'cookie-notice' ),
			'monthsText'		=> __( 'months', 'cookie-notice' )
		];

		// get main instance
		$cn = Cookie_Notice();

		// set default text strings
		$cn->defaults['general']['message_text'] = __( 'We use cookies to ensure that we give you the best experience on our website. If you continue to use this site we will assume that you are happy with it.', 'cookie-notice' );
		$cn->defaults['general']['accept_text'] = __( 'Ok', 'cookie-notice' );
		$cn->defaults['general']['refuse_text'] = __( 'No', 'cookie-notice' );
		$cn->defaults['general']['revoke_message_text'] = __( 'You can change your consent any time using the Update consent button.', 'cookie-notice' );
		$cn->defaults['general']['revoke_text'] = __( 'Update consent', 'cookie-notice' );
		$cn->defaults['general']['see_more_opt']['text'] = __( 'Privacy policy', 'cookie-notice' );

		// ── Begin network defaults write gate ────────────────────────────────
		// This runs on after_setup_theme, which on a network admin page happens during the
		// bootstrap that page require_once's BEFORE it checks any capability —
		// wp-admin/network/index.php loads admin.php on line 11 and only wp_die()s the
		// unauthorised on line 16. So a subsite administrator who simply requests a network
		// admin URL reaches this write, and ungated it rewrote the network row's banner text
		// to the compiled English defaults for every site. Proven end to end: pre-fix that
		// request got HTTP 403 from core AND still rewrote the row.
		//
		// after_setup_theme is well past pluggable.php, so resolving the capability here is
		// safe — unlike at plugin-include time.
		//
		// GATED BEFORE THE IN-MEMORY WRITES, not just before the DB write. The write below
		// (update_general_option_keys()) also changes $cn->options['general'] for the rest of
		// the request, and any wholesale writer of that array later in the same request would
		// persist the defaults a refusal was supposed to prevent. A refusal has to leave no
		// trace.
		//
		// Refused outright rather than degraded to a site write: on a network admin request
		// get_option() resolves to the MAIN site's row, which is not this user's either. The
		// translate flag stays set, so the write happens on the next request made by someone
		// who may make it.
		// The translate test comes FIRST so the capability is only resolved when there is
		// actually a write to authorise — this is a one-shot activation flag, and resolving
		// the current user on every network-admin page load to guard it is the same cost the
		// choke point reorders its operands to avoid.
		if ( ! empty( $cn->options['general']['translate'] )
			&& ( ! $cn->is_network_admin() || $cn->can_write_at_scope( true ) ) ) {
			// only these keys, on a fresh read (Cookie_Notice::update_general_option_keys())
			$cn->update_general_option_keys( [
				'translate'           => false,
				'message_text'        => $cn->defaults['general']['message_text'],
				'accept_text'         => $cn->defaults['general']['accept_text'],
				'refuse_text'         => $cn->defaults['general']['refuse_text'],
				'revoke_message_text' => $cn->defaults['general']['revoke_message_text'],
				'revoke_text'         => $cn->defaults['general']['revoke_text'],
				'see_more_opt'        => [ 'text' => $cn->defaults['general']['see_more_opt']['text'] ],
			], $cn->is_network_admin() );
		}
		// ── End network defaults write gate ──────────────────────────────────

		// WPML >= 3.2
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && version_compare( ICL_SITEPRESS_VERSION, '3.2', '>=' ) ) {
			$this->register_wpml_strings();
		// WPML and Polylang compatibility
		} elseif ( function_exists( 'icl_register_string' ) ) {
			icl_register_string( 'Cookie Notice', 'Message in the notice', $cn->options['general']['message_text'] );
			icl_register_string( 'Cookie Notice', 'Button text', $cn->options['general']['accept_text'] );
			icl_register_string( 'Cookie Notice', 'Refuse button text', $cn->options['general']['refuse_text'] );
			icl_register_string( 'Cookie Notice', 'Revoke message text', $cn->options['general']['revoke_message_text'] );
			icl_register_string( 'Cookie Notice', 'Revoke button text', $cn->options['general']['revoke_text'] );
			icl_register_string( 'Cookie Notice', 'Privacy policy text', $cn->options['general']['see_more_opt']['text'] );
			icl_register_string( 'Cookie Notice', 'Custom link', $cn->options['general']['see_more_opt']['link'] );
		}
	}

	/**
	 * Add submenu.
	 *
	 * @return void
	 */
	public function admin_menu_options() {
		if ( current_action() === 'network_admin_menu' && ! Cookie_Notice()->is_plugin_network_active() )
			return;

		$cap = apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' );

		// The NETWORK screen saves settings every site on the network inherits — including
		// app_id and app_key, which decide whose Cookie Compliance account receives those
		// sites' consent records. manage_options is a site-level capability every subsite
		// administrator holds, so registering the network page with it put that screen in
		// front of people who cannot be allowed to save it.
		//
		// This filter governs VISIBILITY only. The save is gated separately by
		// Cookie_Notice::can_write_at_scope(), which is deliberately not filterable — so
		// restoring the menu for a delegated administrator shows them the screen and answers
		// a visible 403 on save, never a silent no-op.
		if ( current_action() === 'network_admin_menu' )
			$cap = apply_filters( 'cn_manage_network_cookie_notice_cap', 'manage_network_options' );

		add_menu_page( __( 'Cookie Compliance', 'cookie-notice' ), __( 'Compliance', 'cookie-notice' ), $cap, 'cookie-notice', [ $this, 'options_page' ], 'none', '99.300' );

		// React mode: no submenus — React owns in-page tab navigation.
		// Legacy mode: three submenus matching the PHP tab structure.
		if ( Cookie_Notice()->options['general']['ui_mode'] === 'legacy' ) {
			add_submenu_page( 'cookie-notice', __( 'Compliance - Cookie Consent', 'cookie-notice' ), __( 'Cookie Consent', 'cookie-notice' ), $cap, 'cookie-notice', [ $this, 'options_page' ] );
			add_submenu_page( 'cookie-notice', __( 'Compliance - Privacy Consent', 'cookie-notice' ), $this->mark_new( __( 'Privacy Consent', 'cookie-notice' ) ), $cap, 'cookie-notice&tab=privacy-consent', [ $this, 'options_page' ] );
			add_submenu_page( 'cookie-notice', __( 'Compliance - Consent Logs', 'cookie-notice' ), __( 'Consent Logs', 'cookie-notice' ), $cap, 'cookie-notice&tab=consent-logs', [ $this, 'options_page' ] );

			// highlight submenus
			add_filter( 'submenu_file', [ $this, 'submenu_file' ], 10, 2 );
		}
	}
	
	/**
	 * Adds an indicator to mark a new menu item.
	 *
	 * @return string
	 */
	private function mark_new( $title ) {
		return sprintf(
			'%s<span class="pvc-admin-menu-new">&nbsp;%s</span>',
			$title,
			__( 'NEW!', 'cookie-notice' )
		);
	}

	/**
	 * Highlight submenu items.
	 *
	 * @param string|null $submenu_file
	 * @param string $parent_file
	 * @return string|null
	 */
	public function submenu_file( $submenu_file, $parent_file ) {
		if ( $parent_file === 'cookie-notice' ) {
			$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';

			if ( $tab !== 'settings' )
				return 'cookie-notice&tab=' . $tab;
		}

		return $submenu_file;
	}

	/**
	 * Options page output.
	 *
	 * @return void
	 */
	public function options_page() {
		// get main instance
		$cn = Cookie_Notice();

		// get cookie compliance status
		$status = $cn->get_status();

		$ui_mode = $cn->options['general']['ui_mode'];

		echo '
		<div class="wrap">
			<h2>' . esc_html__( 'Cookie Compliance', 'cookie-notice' ) . '</h2>';

		if ( $ui_mode === 'react' ) {
			// Server-side fallback markup. React's createRoot().render() replaces
			// these children on mount, so users only ever see this content if the
			// JS bundle failed to load, was blocked, or threw before React could
			// take over. The help block fades in via pure-CSS animation after 5s
			// (no JS needed — survives a fully-failed bundle). Inline styles, so
			// a CSS-load failure doesn't make the fallback ugly.
			$support_url = esc_url( $cn->get_url( 'host' ) );

			echo '
			<style id="cn-react-fallback-styles">
				#cn-react-root .cn-react-fallback { padding: 32px 28px; max-width: 640px; margin: 20px 0; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; font-size: 14px; line-height: 1.5; color: #1d2327; display: flex; flex-direction: column; align-items: center; text-align: center; }
				#cn-react-root .cn-react-fallback__spinner { width: 36px; height: 36px; border: 3px solid #e0e0e0; border-top-color: #2271b1; border-radius: 50%; animation: cn-react-spin 0.7s linear infinite; margin-bottom: 14px; flex-shrink: 0; }
				#cn-react-root .cn-react-fallback__heading { margin: 0 0 4px; font-size: 15px; font-weight: 600; }
				#cn-react-root .cn-react-fallback__body { margin: 0; color: #50575e; }
				#cn-react-root .cn-react-fallback__help { width: 100%; margin: 20px 0 0; padding: 12px 14px; background: #f6f7f7; border-left: 3px solid #d63638; text-align: left; opacity: 0; animation: cn-react-fallback-reveal 0.4s ease-in 15s forwards; }
				#cn-react-root .cn-react-fallback__help-list { margin: 8px 0 0 18px; padding: 0; list-style: disc; }
				#cn-react-root .cn-react-fallback__help-list li { margin: 4px 0; }
				@keyframes cn-react-spin { to { transform: rotate( 360deg ); } }
				@keyframes cn-react-fallback-reveal { from { opacity: 0; transform: translateY( -4px ); } to { opacity: 1; transform: translateY( 0 ); } }
			</style>
			<div id="cn-react-root">
				<div class="cn-react-fallback" role="status" aria-live="polite">
					<div class="cn-react-fallback__spinner" aria-hidden="true"></div>
					<p class="cn-react-fallback__heading">' . esc_html__( 'Loading Compliance dashboard…', 'cookie-notice' ) . '</p>
					<p class="cn-react-fallback__body">' . esc_html__( 'Hang tight — this may take a few seconds on slower connections.', 'cookie-notice' ) . '</p>
					<noscript>
						<p class="cn-react-fallback__body"><strong>' . esc_html__( 'JavaScript is required for this admin page.', 'cookie-notice' ) . '</strong> ' . esc_html__( 'Please enable JavaScript in your browser settings.', 'cookie-notice' ) . '</p>
					</noscript>
					<div class="cn-react-fallback__help">
						<strong>' . esc_html__( 'Taking longer than expected?', 'cookie-notice' ) . '</strong>
						<ul class="cn-react-fallback__help-list">
							<li>' . esc_html__( 'A caching/optimizer plugin or CDN (Cloudflare Rocket Loader, WP Rocket, LiteSpeed, etc.) may be rewriting admin scripts.', 'cookie-notice' ) . '</li>
							<li>' . esc_html__( 'A browser extension (ad-blocker, privacy tool) may be blocking the script.', 'cookie-notice' ) . '</li>
							<li>' . esc_html__( 'Try opening this page in a private/incognito window with extensions disabled.', 'cookie-notice' ) . '</li>
						</ul>
						<p class="cn-react-fallback__body" style="margin-top:12px;text-align:left;">' . sprintf(
							/* translators: %s: link to the Hu-manity support dashboard */
							esc_html__( 'If the issue persists, %s.', 'cookie-notice' ),
							'<a href="' . $support_url . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'open the Hu-manity dashboard to contact support', 'cookie-notice' ) . '</a>'
						) . '</p>
					</div>
				</div>
			</div>';
		}

		// get current tab
		$tab = $this->current_tab;

		if ( $ui_mode === 'legacy' ) {
			$base_url = $cn->is_network_admin()
				? network_admin_url( 'admin.php?page=cookie-notice' )
				: admin_url( 'admin.php?page=cookie-notice' );

			echo '
			<h2 class="nav-tab-wrapper cn-nav-tab-wrapper">';

			foreach ( $this->tabs as $key => $label ) {
				$tab_url = add_query_arg( [ 'tab' => $key ], $base_url );

				echo '
			<a class="nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $tab_url ) . '">' . esc_html( $label ) . '</a>';
			}

			echo '
			</h2>
			<div class="cookie-notice-settings">';

		$this->display_options_sidebar();

		if ( $tab === 'consent-logs' ) {
			// multisite?
			if ( is_multisite() ) {
				// network admin?
				if ( $cn->is_network_admin() ) {
					$class = ( $cn->is_plugin_network_active() && ! $cn->options['general']['global_override'] ? 'cn-options-disabled' : '' );
				// single network site
				} else {
					$class = ( $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'] ? 'cn-options-disabled cn-options-submit-disabled' : '' );
				}
			// single site
			} else
				$class = '';

			echo '
				<form action="#"' . ( $class !== '' ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>
					<div class="cn-options" style="clear: both">';

			do_settings_sections( 'cookie_notice_consent_logs' );

			if ( ! $this->consent_logs_in_scope() ) {
				echo '<p class="description" style="margin-top: 20px;">' . $this->consent_logs_scope_message() . '</p>';
			} else {
				if ( $this->current_section === 'privacy' ) {
					if ( $status === 'active' ) {
						// include wp list table class if needed
						if ( ! class_exists( 'WP_List_Table' ) )
							include_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );

						// include privacy consent logs list table
						include_once( COOKIE_NOTICE_PATH . '/includes/privacy-consent-logs-list-table.php' );

						// initialize list table
						$list_table = new Cookie_Notice_Privacy_Consent_Logs_List_Table( [
							'plural'	=> 'cn-privacy-consent-logs',
							'singular'	=> 'cn-privacy-consent-log',
							'ajax'		=> false
						] );

						$list_table->cn_empty_init();
						$list_table->views();
						$list_table->prepare_items();

						echo '<div class="cn-privacy-consent-logs-data">';

						$list_table->display();

						echo '</div>';
					} else {
						$upgrade_url = cn_get_welcome_url();

						echo '
							<div id="cn-consent-logs-disabled">
								<img id="cn-consent-logs-bg" src="' . esc_url( COOKIE_NOTICE_URL ) . '/img/privacy-consent-logs.png" alt="Privacy Consent Logs" />
								<div id="cn-consent-logs-upgrade">
									<div id="cn-consent-logs-modal">
										<h2>' . esc_html__( 'Handle Privacy Consent Logs with Cookie Compliance', 'cookie-notice' ) . '</h2>
										<p>' . esc_html__( 'Integrate your website forms with Privacy Consent.', 'cookie-notice' ) . '</p>
										<p>' . esc_html__( 'Collect and export proof of consent of your users.', 'cookie-notice' ) . '</p>
										<p>' . esc_html__( 'Gain confidence that you are processing personal data legally.', 'cookie-notice' ) . '</p>
										<p><a href="' . esc_url( $upgrade_url ) . '" class="button button-primary button-hero cn-button">' . esc_html__( 'Connect Your Site', 'cookie-notice' ) . '</a></p>
									</div>
								</div>
							</div>';
					}
				} else {
					if ( $status === 'active' ) {
						// include wp list table class if needed
						if ( ! class_exists( 'WP_List_Table' ) )
							include_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );

						// include cookie consent logs date list table
						include_once( COOKIE_NOTICE_PATH . '/includes/consent-logs-date-list-table.php' );

						// make date column sorted
						if ( empty( $_GET['orderby'] ) )
							$_GET['orderby'] = 'date';

						if ( empty( $_GET['order'] ) )
							$_GET['order'] = 'desc';

						// initialize list table
						$list_table = new Cookie_Notice_Consent_Logs_Date_List_Table( [
							'plural'	=> 'cn-cookie-consent-logs',
							'singular'	=> 'cn-cookie-consent-log',
							'ajax'		=> false
						] );

						$list_table->views();
						$list_table->prepare_items();
						$list_table->display();
					} else {
						$upgrade_url = cn_get_welcome_url();

						echo '
							<div id="cn-consent-logs-disabled">
								<img id="cn-consent-logs-bg" src="' . esc_url( COOKIE_NOTICE_URL ) . '/img/consent-logs.png" alt="Cookie Consent Logs" />
								<div id="cn-consent-logs-upgrade">
									<div id="cn-consent-logs-modal">
										<h2>' . esc_html__( 'Record and view Cookie Consent Logs inside WordPress', 'cookie-notice' ) . '</h2>
										<p>' . esc_html__( 'Automatically collect each cookie consent log.', 'cookie-notice' ) . '</p>
										<p>' . esc_html__( 'Securely store and manage visitor consents.', 'cookie-notice' ) . '</p>
										<p>' . esc_html__( 'Monitor consent activity directly in your WordPress dashboard.', 'cookie-notice' ) . '</p>
										<p><a href="' . esc_url( $upgrade_url ) . '" class="button button-primary button-hero cn-button">' . esc_html__( 'Connect Your Site', 'cookie-notice' ) . '</a></p>
									</div>
								</div>
							</div>';
					}
				}
			}

			echo '
					</div>
				</form>';
		} elseif ( $tab === 'privacy-consent' ) {
			$this->display_options_page( $tab );
		} else
			$this->display_options_page( $tab );

		echo '
			</div>';
		} // end if ui_mode === 'legacy'

		echo '
			<div class="clear"></div>
		</div>';
	}

	/**
	 * Display options page.
	 *
	 * @return array
	 */
	private function display_options_page( $tab = '' ) {
		// get main instance
		$cn = Cookie_Notice();

		// multisite?
		if ( is_multisite() ) {
			// network admin?
			if ( $cn->is_network_admin() ) {
				$class = ( $cn->is_plugin_network_active() && ! $cn->options['general']['global_override'] ? 'cn-options-disabled' : ( $tab === 'privacy-consent' ? 'cn-options-privacy-disabled cn-options-submit-disabled' : '' ) );
				$page = 'admin.php?page=cookie-notice';
				$input = '<input type="hidden" name="cn-network-settings" value="true" />';
			// single network site
			} else {
				$class = ( $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'] ? ( $tab === 'privacy-consent' ? 'cn-options-compliance-disabled' : 'cn-options-disabled cn-options-submit-disabled' ) : '' );
				$page = 'options.php';
				$input = '';
			}
		// single site
		} else {
			$class = '';
			$page = 'options.php';
			$input = '';
		}

		if ( $tab === 'privacy-consent' )
			$option = 'cookie_notice_privacy_consent';
		elseif ( $tab === 'settings' )
			$option = 'cookie_notice_options';

		echo '
		<form action="' . esc_attr( $page ) . '" method="post"' . ( $class !== '' ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>';

		settings_fields( $option );

		if ( $input !== '' ) {
			echo wp_kses(
				$input,
				[
					'input'	=> [
						'type'	=> true,
						'name'	=> true,
						'value'	=> true
					]
				]
			);
		}

		echo '
			<div class="cn-options">';

		do_settings_sections( $option );
		
		$other_attributes = [];

		// get cookie compliance status
		$status = $cn->get_status();

		// disabled save / reset if compliance inactive
		if ( $tab === 'privacy-consent' && ( $status !== 'active' || $cn->is_network_admin() ) )
			$other_attributes = array( 'disabled' => true );

		echo '
			</div>
			<p class="submit">';
		submit_button( '', 'primary', 'save_' . $option, false, $other_attributes );

		echo ' ';

		submit_button( esc_html__( 'Reset to defaults', 'cookie-notice' ), 'secondary cn-reset-settings', 'reset_' . $option, false, $other_attributes );
		echo '
			</p>
		</form>';
	}

	/**
	 * The legacy sidebar's "new admin" card: a link to the confirmed switch to React.
	 *
	 * Shown on the legacy screen only, and only to a viewer who may switch the row the page
	 * renders from — the same predicate maybe_switch_ui_mode() enforces. cn_confirm=1 makes
	 * the link take the confirmation even under CN_DEV_MODE.
	 *
	 * @return string
	 */
	public function switch_to_react_card() {
		$cn = Cookie_Notice();

		if ( $cn->options['general']['ui_mode'] !== 'legacy' || ! $cn->can_switch_ui_mode() )
			return '';

		$base = $cn->is_network_admin() ? network_admin_url( 'admin.php?page=cookie-notice' ) : admin_url( 'admin.php?page=cookie-notice' );
		$url  = add_query_arg( [ 'ui_mode' => 'react', 'cn_confirm' => '1' ], $base );

		return '
			<div class="cookie-notice-credits cn-switch-admin">
				<div class="inside">
					<div class="inner">
						<h2>' . esc_html__( 'A new admin interface is available', 'cookie-notice' ) . '</h2>
						<p>' . esc_html__( 'The same settings, connection and banner, in a new layout.', 'cookie-notice' ) . '</p>
						<p><a href="' . esc_url( $url ) . '" class="button button-primary">' . esc_html__( 'Switch to the new interface', 'cookie-notice' ) . '</a></p>
					</div>
				</div>
			</div>';
	}

	/**
	 * Display options sidebar HTML.
	 *
	 * @return void
	 */
	public function display_options_sidebar() {
		// get main instance
		$cn = Cookie_Notice();

		// get cookie compliance status
		$status = $cn->get_status();

		// get subscription
		$subscription = $cn->get_subscription();

		echo '
		<div class="cookie-notice-sidebar">' . $this->switch_to_react_card() . '
			<div class="cookie-notice-credits">
				<div class="inside">
					<div class="inner">';

		// compliance enabled
		if ( $status === 'active' ) {
			echo '
						<div class="cn-pricing-info">
							<div class="cn-pricing-head">
								<p>' . esc_html__( 'Your Cookie Compliance plan:', 'cookie-notice' ) . '</p>
								<h2>' . esc_html( $subscription === 'pro' ? __( 'Professional', 'cookie-notice' ) : __( 'Free', 'cookie-notice' ) ) . '</h2>
							</div>
							<div class="cn-pricing-body">
								<p class="cn-active"><span class="cn-icon"></span>' . esc_html__( 'GDPR, CCPA, LGPD, PECR requirements', 'cookie-notice' ) . '</p>
								<p class="cn-active"><span class="cn-icon"></span>' . esc_html__( 'Consent Analytics Dashboard', 'cookie-notice' ) . '</p>
								<p class="' . ( $subscription === 'pro' ? 'cn-active' : 'cn-inactive' ) . '"><span class="cn-icon"></span>' . sprintf( esc_html__( '%sUnlimited%s visits', 'cookie-notice' ), '<b>', '</b>' ) . '</p>
								<p class="' . ( $subscription === 'pro' ? 'cn-active' : 'cn-inactive' ) . '"><span class="cn-icon"></span>' . sprintf( esc_html__( '%sFull%s privacy consent history', 'cookie-notice' ), '<b>', '</b>' ) . '</p>
								<p class="' . ( $subscription === 'pro' ? 'cn-active' : 'cn-inactive' ) . '"><span class="cn-icon"></span>' . sprintf( esc_html__( '%sConsent history%s beyond 7 days', 'cookie-notice' ), '<b>', '</b>' ) . '</p>
								<p class="' . ( $subscription === 'pro' ? 'cn-active' : 'cn-inactive' ) . '"><span class="cn-icon"></span>' . sprintf( esc_html__( '%sFacebook & Microsoft%s consent modes', 'cookie-notice' ), '<b>', '</b>' ) . '</p>
								<p class="' . ( $subscription === 'pro' ? 'cn-active' : 'cn-inactive' ) . '"><span class="cn-icon"></span>' . sprintf( esc_html__( '%sGeolocation%s support', 'cookie-notice' ), '<b>', '</b>' ) . '</p>
								<p class="' . ( $subscription === 'pro' ? 'cn-active' : 'cn-inactive' ) . '"><span class="cn-icon"></span>' . sprintf( esc_html__( '%sUnlimited%s languages', 'cookie-notice' ), '<b>', '</b>' ) . '</p>
								<p class="' . ( $subscription === 'pro' ? 'cn-active' : 'cn-inactive' ) . '"><span class="cn-icon"></span>' . sprintf( esc_html__( '%sPriority%s Support', 'cookie-notice' ), '<b>', '</b>' ) . '</p>
							</div>';

			if ( $subscription !== 'pro' ) {
				echo '
							<div class="cn-pricing-footer">
								<a href="' . esc_url( $cn->get_url( 'host', '?utm_campaign=upgrade+to+pro&utm_source=wordpress&utm_medium=textlink#/dashboard?app-id=' . $cn->options['general']['app_id'] . '&open-modal=payment' ) ) . '" class="button button-secondary button-hero cn-button" target="_blank">' . esc_html__( 'Upgrade to Pro', 'cookie-notice' ) . '</a>
							</div>';
			}

			echo '
						</div>';
		// compliance disabled
		} else {
				echo '
						<h1><b>' . esc_html__( 'Protect your business', 'cookie-notice' ) . '</b></h1>
						<h2>' . esc_html__( 'with Cookie Compliance', 'cookie-notice' ) . '</h2>
						<div class="cn-lead">
							<p>' . esc_html__( 'Make your website compatible with the latest cookie and privacy requirements. Comply with GDPR, CCPA and other data privacy laws effectively.', 'cookie-notice' ) . '</p>
						</div>
						<img alt="' . esc_html__( 'Cookie Compliance dashboard', 'cookie-notice' ) . '" src="' . esc_url( COOKIE_NOTICE_URL ) . '/img/screen-compliance.png">
						<p><a href="https://cookie-compliance.co/?utm_campaign=learn+more&utm_source=wordpress&utm_medium=banner" class="button button-secondary button-hero cn-button" target="_blank">' . esc_html__( 'Learn more', 'cookie-notice' ) . '</a></p>';
		}

		echo '
					</div>
				</div>
			</div>';

		echo '
			<div class="cookie-notice-faq">
				<h2>' . esc_html__( 'F.A.Q.', 'cookie-notice' ) . '</h2>
				<div class="cn-toggle-container">
					<label for="cn-faq-1" class="cn-toggle-item">
						<input id="cn-faq-1" type="checkbox" />
						<span class="cn-toggle-heading">' . esc_html__( 'Does Cookie Compliance make my site fully compliant with GDPR/CCPA and other privacy regulations?', 'cookie-notice' ) . '</span>
						<span class="cn-toggle-body">' . esc_html__( 'Not by itself — it gives you what you need to configure it that way. A WordPress plugin on its own cannot provide the required technical compliance features; connected to Cookie Compliance you get script blocking, consent purpose categories and consent record storage, covering requirements for over 100 countries and legal jurisdictions. Whether your site is fully compliant also depends on how you configure them and on how your site uses personal data.', 'cookie-notice' ) . '</span>
					</label>
					<label for="cn-faq-2" class="cn-toggle-item">
						<input id="cn-faq-2" type="checkbox" />
						<span class="cn-toggle-heading">' . esc_html__( 'Is Cookie Compliance free?', 'cookie-notice' ) . '</span>
						<span class="cn-toggle-body">' . esc_html__( 'Yes, but with limits. Cookie Compliance includes both free and paid plans to choose from depending on your needs and your website monthly traffic.', 'cookie-notice' ) . '</span>
					</label>
					<label for="cn-faq-3" class="cn-toggle-item">
						<input id="cn-faq-3" type="checkbox" />
						<span class="cn-toggle-heading">' . esc_html__( 'Where can I find pricing options?', 'cookie-notice' ) . '</span>
						<span class="cn-toggle-body">' . esc_html__( 'You can learn more about the features and pricing by visiting the Cookie Compliance website here:', 'cookie-notice' ) . ' <a href="https://cookie-compliance.co/?utm_campaign=pricing+options&utm_source=wordpress&utm_medium=textlink" target="_blank">https://cookie-compliance.co</a></span>
					</label>
					<label for="cn-faq-4" class="cn-toggle-item">
						<input id="cn-faq-4" type="checkbox" />
						<span class="cn-toggle-heading">' . esc_html__( 'Can I add Cookie Compliance with an AI assistant?', 'cookie-notice' ) . '</span>
						<span class="cn-toggle-body">' . esc_html__( 'Yes. Point an MCP-capable assistant (Claude Code, Cursor, and others) at the Cookie Compliance MCP server — no account is required to start. On WordPress, keep using this plugin for placement rather than pasting a snippet.', 'cookie-notice' ) . ' <a href="https://cookie-compliance.co/mcp/?utm_campaign=mcp+faq&utm_source=wordpress&utm_medium=textlink" target="_blank" rel="noopener noreferrer">https://cookie-compliance.co/mcp/</a></span>
					</label>
				</div>
			</div>';

		echo '
		</div>';
	}

	/**
	 * May consent logs be shown in the current admin scope?
	 *
	 * The one copy of the multisite rule for who sees consent records, used by the legacy
	 * Consent Logs screen and by every React log endpoint (cookie and privacy, view and
	 * export). On a network-activated multisite the records belong to whichever app the
	 * scope serves: under Global Settings Override that is the network's app, visible in
	 * the Network Admin only; without it each site has its own app, visible on that site only.
	 *
	 * Evaluated at call time, never cached: the React endpoints run on admin-ajax, where
	 * the scope is only known once the network claim has been vetted. Starts from deny;
	 * only a scope that matches one of the cases below is allowed.
	 *
	 * @return bool
	 */
	public function consent_logs_in_scope() {
		$cn = Cookie_Notice();

		$allowed = false;

		if ( ! is_multisite() || ! $cn->is_plugin_network_active() )
			$allowed = true;
		elseif ( $cn->is_network_admin() )
			$allowed = ! empty( $cn->network_options['general']['global_override'] );
		else
			$allowed = empty( $cn->network_options['general']['global_override'] );

		return $allowed;
	}

	/**
	 * Message shown where consent_logs_in_scope() is false.
	 *
	 * @return string
	 */
	public function consent_logs_scope_message() {
		if ( Cookie_Notice()->is_network_admin() )
			return __( 'Global network settings override is inactive. Consent records are available for each website of the multisite network separately.', 'cookie-notice' );

		return __( 'Global network settings override is active. Consent records are available for network administrators in the multisite admin only.', 'cookie-notice' );
	}

	/**
	 * Is this a site whose settings the network manages — a site of a network-activated
	 * multisite with Global Settings Override on, outside the Network Admin?
	 *
	 * Legacy greys that site's settings form out (display_options_page()); the React admin
	 * shows the same disabled state (cnReactData.networkOverride), its save and reset refuse,
	 * and its dashboard does not hand the network's app data to the site. Privacy Consent is
	 * not covered: legacy lets the site save it under the override.
	 *
	 * @return bool
	 */
	public function network_managed() {
		$cn = Cookie_Notice();

		return ! $cn->is_network_admin() && $cn->is_network_options();
	}

	/**
	 * Message shown where network_managed() is true.
	 *
	 * @return string
	 */
	public function network_managed_message() {
		return __( 'Global network settings override is active. Every site will use the same network settings. Please contact super administrator if you want to have more control over the settings.', 'cookie-notice' );
	}

	/**
	 * Refuse an AJAX write from a site whose settings the network manages (network_managed()).
	 *
	 * Such a request holds the NETWORK's row in $cn->options and the network's app_id, so a
	 * write there changes the network's options, its app config on the platform, or its
	 * connection — for a super administrator visiting the site too. Legacy only greys the
	 * form out. Every write handler calls this first, right after its nonce and capability
	 * checks and before any remote call or write. 403, as the scope refusals beside it.
	 *
	 * @return void
	 */
	public function verify_not_network_managed() {
		if ( $this->network_managed() )
			wp_send_json_error( [ 'error' => $this->network_managed_message(), 'code' => 'cn_network_managed' ], 403 );
	}

	/**
	 * The admin UI mode of the row the settings page renders from: the network row in the
	 * Network Admin, the site row everywhere else (cookie-notice.php "get options").
	 *
	 * Not $cn->options: on admin-ajax under Global Settings Override that is the NETWORK row
	 * for a site too, so a site's React write handlers were registered by the network's mode
	 * rather than by the screen the site's admin is looking at.
	 *
	 * @return string 'react' or 'legacy'
	 */
	public function rendered_ui_mode() {
		$row = Cookie_Notice_Store::get( 'cookie_notice_options', [], Cookie_Notice()->is_network_admin() );

		return is_array( $row ) && isset( $row['ui_mode'] ) && $row['ui_mode'] === 'react' ? 'react' : 'legacy';
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		// register settings
		register_setting( 'cookie_notice_options', 'cookie_notice_options', [ $this, 'validate_options' ] );

		// get main instance
		$cn = Cookie_Notice();

		$status = $cn->get_status();

		// this is no longer used
		if ( $cn->is_network_admin() )
			$cb = '';
		elseif ( $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'] )
			$cb = [ $this, 'cn_network_section' ];
		else
			$cb = '';

		add_settings_section( 'cookie_notice_consent_logs_status', esc_html__( 'Compliance Integration', 'cookie-notice' ), '', 'cookie_notice_consent_logs', [ 'before_section' => '<div class="%s">', 'after_section' => '</div>', 'section_class' => 'cn-section-container compliance-section' ] );

		add_settings_field( 'cn_consent_logs_status', esc_html__( 'Compliance Status', 'cookie-notice' ), [ $this, 'cn_consent_logs_status' ], 'cookie_notice_consent_logs', 'cookie_notice_consent_logs_status' );

		add_settings_section( 'cookie_notice_consent_logs', esc_html__( 'Consent Logs', 'cookie-notice' ), [ $this, 'cn_consent_logs_section' ], 'cookie_notice_consent_logs', [ 'before_section' => '<div class="%s">', 'after_section' => '</div>', 'section_class' => 'cn-section-container logs-section' ] );

		// multisite?
		if ( is_multisite() ) {
			// network admin?
			if ( $cn->is_network_admin() ) {
				// network section
				add_settings_section( 'cookie_notice_network', esc_html__( 'Network Settings', 'cookie-notice' ), '', 'cookie_notice_options', [ 'before_section' => '<div class="%s">', 'after_section' => '</div>', 'section_class' => 'cn-section-container network-section' ] );
				add_settings_field( 'cn_global_override', esc_html__( 'Global Settings Override', 'cookie-notice' ), [ $this, 'cn_global_override' ], 'cookie_notice_options', 'cookie_notice_network' );
				add_settings_field( 'cn_global_cookie', esc_html__( 'Global Cookie', 'cookie-notice' ), [ $this, 'cn_global_cookie' ], 'cookie_notice_options', 'cookie_notice_network' );
			} elseif ( $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'] ) {
				// network section
				add_settings_section( 'cookie_notice_network', esc_html__( 'Network Settings', 'cookie-notice' ), [ $this, 'cn_network_section' ], 'cookie_notice_options', [ 'before_section' => '<div class="%s">', 'after_section' => '</div>', 'section_class' => 'cn-section-container network-section' ] );
				add_settings_field( 'cn_dummy', '', '__return_empty_string', 'cookie_notice_options', 'cookie_notice_network' );

				// get default status data
				$default_data = $cn->defaults['data'];

				// get real status of current site, not network since global_override is on
				$status_data = get_option( 'cookie_notice_status', $default_data );

				// old status format?
				if ( ! is_array( $status_data ) ) {
					// old value saved as string
					if ( is_string( $status_data ) && $cn->check_status( $status_data ) ) {
						// update status
						$default_data['status'] = $status_data;
					}

					// set data
					$status_data = $default_data;
				}

				// get valid status
				$status = $cn->check_status( $status_data['status'] );
			}
		}

		// compliance enabled
		if ( $status === 'active' ) {
			// compliance section
			add_settings_section( 'cookie_notice_compliance', esc_html__( 'Compliance Integration', 'cookie-notice' ), '', 'cookie_notice_options', [ 'before_section' => '<div class="%s">', 'after_section' => '</div>', 'section_class' => 'cn-section-container compliance-section' ] );
			add_settings_field( 'cn_app_status', esc_html__( 'Compliance Status', 'cookie-notice' ), [ $this, 'cn_app_status' ], 'cookie_notice_options', 'cookie_notice_compliance' );
			add_settings_field( 'cn_app_id', esc_html__( 'App ID', 'cookie-notice' ), [ $this, 'cn_app_id' ], 'cookie_notice_options', 'cookie_notice_compliance' );
			add_settings_field( 'cn_app_key', esc_html__( 'App Secret Key', 'cookie-notice' ), [ $this, 'cn_app_key' ], 'cookie_notice_options', 'cookie_notice_compliance' );

			// configuration section
			add_settings_section( 'cookie_notice_configuration', esc_html__( 'Cookie Consent Settings', 'cookie-notice' ), '', 'cookie_notice_options', [ 'before_section' => '<div class="%s">', 'after_section' => '</div>', 'section_class' => 'cn-section-container misc-section' ] );
			// Engine first, then posture: the master switch reads before the thing it gates.
			add_settings_field( 'cn_app_blocking_engine', esc_html__( 'Script blocking engine', 'cookie-notice' ), [ $this, 'cn_app_blocking_engine' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_app_blocking', esc_html__( 'Autoblocking', 'cookie-notice' ), [ $this, 'cn_app_blocking' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_excluded_handles', esc_html__( 'Excluded Script Handles', 'cookie-notice' ), [ $this, 'cn_excluded_handles' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_sync_config', esc_html__( 'Pull latest settings', 'cookie-notice' ), [ $this, 'cn_sync_config' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_refuse_code', esc_html__( 'Scripts', 'cookie-notice' ), [ $this, 'cn_refuse_code' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_caching_compatibility', esc_html__( 'Caching Compatibility', 'cookie-notice' ), [ $this, 'cn_caching_compatibility' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_app_purge_cache', esc_html__( 'Purge Cache', 'cookie-notice' ), [ $this, 'cn_app_purge_cache' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_conditional_display', esc_html__( 'Conditional Display', 'cookie-notice' ), [ $this, 'cn_conditional_display' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_bot_detection', esc_html__( 'Bot Detection', 'cookie-notice' ), [ $this, 'cn_bot_detection' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_amp_support', esc_html__( 'AMP Support', 'cookie-notice' ), [ $this, 'cn_amp_support' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_wp_consent_api', esc_html__( 'WP Consent API', 'cookie-notice' ), [ $this, 'cn_wp_consent_api' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_debug_mode', esc_html__( 'Debug Mode', 'cookie-notice' ), [ $this, 'cn_debug_mode' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_deactivation_delete', esc_html__( 'Deactivation', 'cookie-notice' ), [ $this, 'cn_deactivation_delete' ], 'cookie_notice_options', 'cookie_notice_configuration' );
		// compliance disabled
		} else {
			// compliance section
			add_settings_section( 'cookie_notice_compliance', esc_html__( 'Compliance Integration', 'cookie-notice' ), '', 'cookie_notice_options', [ 'before_section' => '<div class="%s">', 'after_section' => '</div>', 'section_class' => 'cn-section-container compliance-section' ] );
			add_settings_field( 'cn_app_status', esc_html__( 'Compliance status', 'cookie-notice' ), [ $this, 'cn_app_status' ], 'cookie_notice_options', 'cookie_notice_compliance' );
			add_settings_field( 'cn_app_id', esc_html__( 'App ID', 'cookie-notice' ), [ $this, 'cn_app_id' ], 'cookie_notice_options', 'cookie_notice_compliance' );
			add_settings_field( 'cn_app_key', esc_html__( 'App Secret Key', 'cookie-notice' ), [ $this, 'cn_app_key' ], 'cookie_notice_options', 'cookie_notice_compliance' );

			// configuration section
			add_settings_section( 'cookie_notice_configuration', esc_html__( 'Notice Settings', 'cookie-notice' ), '', 'cookie_notice_options', [ 'before_section' => '<div class="%s">', 'after_section' => '</div>', 'section_class' => 'cn-section-container notice-section' ] );
			add_settings_field( 'cn_message_text', esc_html__( 'Message', 'cookie-notice' ), [ $this, 'cn_message_text' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_accept_text', esc_html__( 'Button text', 'cookie-notice' ), [ $this, 'cn_accept_text' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_see_more', esc_html__( 'Privacy policy', 'cookie-notice' ), [ $this, 'cn_see_more' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_refuse_opt', esc_html__( 'Refuse consent', 'cookie-notice' ), [ $this, 'cn_refuse_opt' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_revoke_opt', esc_html__( 'Update consent', 'cookie-notice' ), [ $this, 'cn_revoke_opt' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			// Engine first, then posture — same pair as the connected branch above, so
			// the two controls are never rendered apart. Both fields carry their own
			// sentinel, so a form that omits either one preserves its stored value.
			add_settings_field( 'cn_app_blocking_engine', esc_html__( 'Script blocking engine', 'cookie-notice' ), [ $this, 'cn_app_blocking_engine' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_app_blocking', esc_html__( 'Autoblocking', 'cookie-notice' ), [ $this, 'cn_app_blocking' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_refuse_code', esc_html__( 'Script blocking', 'cookie-notice' ), [ $this, 'cn_refuse_code' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_pro_features_locked', esc_html__( 'Pro Features', 'cookie-notice' ), [ $this, 'cn_pro_features_locked' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_redirection', esc_html__( 'Reloading', 'cookie-notice' ), [ $this, 'cn_redirection' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_on_scroll', esc_html__( 'On scroll', 'cookie-notice' ), [ $this, 'cn_on_scroll' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_on_click', esc_html__( 'On click', 'cookie-notice' ), [ $this, 'cn_on_click' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_time', esc_html__( 'Accepted expiry', 'cookie-notice' ), [ $this, 'cn_time' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_time_rejected', esc_html__( 'Rejected expiry', 'cookie-notice' ), [ $this, 'cn_time_rejected' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_conditional_display', esc_html__( 'Conditional display', 'cookie-notice' ), [ $this, 'cn_conditional_display' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_script_placement', esc_html__( 'Script placement', 'cookie-notice' ), [ $this, 'cn_script_placement' ], 'cookie_notice_options', 'cookie_notice_configuration' );
			add_settings_field( 'cn_deactivation_delete', esc_html__( 'Deactivation', 'cookie-notice' ), [ $this, 'cn_deactivation_delete' ], 'cookie_notice_options', 'cookie_notice_configuration' );

			// design section
			add_settings_section( 'cookie_notice_design', esc_html__( 'Notice Design', 'cookie-notice' ), '', 'cookie_notice_options', [ 'before_section' => '<div class="%s">', 'after_section' => '</div>', 'section_class' => 'cn-section-container design-section' ] );
			add_settings_field( 'cn_position', esc_html__( 'Position', 'cookie-notice' ), [ $this, 'cn_position' ], 'cookie_notice_options', 'cookie_notice_design' );
			add_settings_field( 'cn_hide_effect', esc_html__( 'Animation', 'cookie-notice' ), [ $this, 'cn_hide_effect' ], 'cookie_notice_options', 'cookie_notice_design' );
			add_settings_field( 'cn_colors', esc_html__( 'Colors', 'cookie-notice' ), [ $this, 'cn_colors' ], 'cookie_notice_options', 'cookie_notice_design' );
			add_settings_field( 'cn_css_class', esc_html__( 'Button class', 'cookie-notice' ), [ $this, 'cn_css_class' ], 'cookie_notice_options', 'cookie_notice_design' );
		}
	}

	/**
	 * Network settings override option.
	 *
	 * @return void
	 */
	public function cn_global_override() {
		echo '
		<div id="cn_global_override">
			<label><input type="checkbox" name="cookie_notice_options[global_override]" value="1" ' . checked( true, Cookie_Notice()->options['general']['global_override'], false ) . ' />' . esc_html__( 'Enable global network settings override.', 'cookie-notice' ) . '</label>
			<p class="description">' . esc_html__( 'Every site in the network will use the same settings. Site administrators will not be able to change them.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * Network cookie acceptance option.
	 *
	 * @return void
	 */
	public function cn_global_cookie() {
		$multi_folders = is_multisite() && ! is_subdomain_install();

		// multisite with path-based network?
		if ( $multi_folders )
			$desc = __( 'This option works only for domain-based networks.', 'cookie-notice' );
		else
			$desc = '';

		echo '
		<div id="cn_global_cookie">
			<label><input type="checkbox" name="cookie_notice_options[global_cookie]" value="1" ' . checked( true, Cookie_Notice()->options['general']['global_cookie'], false ) . ' ' . disabled( $multi_folders, true, false ) . ' />' . esc_html__( 'Enable global network cookie consent.', 'cookie-notice' ) . '</label>
			<p class="description">' . esc_html__( 'Cookie consent in one of the network sites results in a consent in all of the sites on the network.', 'cookie-notice' ) . ( $desc !== '' ? ' ' . esc_html( $desc ) : '' ) . '</p>
		</div>';
	}

	/**
	 * Network settings section.
	 *
	 * @return void
	 */
	public function cn_network_section() {
		echo '
		<p>' . esc_html( $this->network_managed_message() ) . '</p>';
	}
	
	/**
	 * Consent logs section.
	 *
	 * @return void
	 */
	public function cn_consent_logs_section() {
		if ( ! $this->consent_logs_in_scope() )
			return;

		echo '
			<ul class="subsubsub">';

			// get number of sections
			$nos = count( $this->sections );

			$i = 0;

			foreach ( $this->sections as $key => $name ) {
				if ( Cookie_Notice()->is_network_admin() )
					$url = network_admin_url( 'admin.php?page=cookie-notice&tab=consent-logs&section=' . $key );
				else
					$url = admin_url( 'admin.php?page=cookie-notice&tab=consent-logs&section=' . $key );

				echo '
				<li class="cn-link-' . esc_attr( $key ) . '"><a href="' . esc_url( $url ) . '"' . ( $key === $this->current_section ? ' class="current"' : '' ) . '>' . esc_html( $name ) . '</a>' . ( $nos === ++$i ? '' : ' |' ) . '</li>';
			}

		echo '
			</ul>
			<div class="clear"></div>';
	}

	/**
	 * Compliance status.
	 *
	 * @return void
	 */
	public function cn_app_status() {
		// get main instance
		$cn = Cookie_Notice();

		// get cookie compliance status
		$app_status = $cn->get_status();

		// get threshold status
		$threshold_exceeded = $cn->threshold_exceeded();

		// the Admin Portal, on this site's own app ( the portal reads app-id from the query inside its # route, as the upgrade links do )
		$app_id     = (string) $cn->options['general']['app_id'];
		$portal_url = $cn->get_url( 'host', '?utm_campaign=configure&utm_source=wordpress&utm_medium=button#/dashboard' . ( $app_id !== '' ? '?app-id=' . rawurlencode( $app_id ) : '' ) );

		// an App ID but no status: connected, the last configuration pull did not confirm the state
		if ( $app_status !== 'active' && $app_status !== 'pending' && $app_id !== '' )
			$app_status = 'unconfirmed';

		// ── Begin autoblocking status row (DEC-012)
		//
		// This row used to print "Active" on every connected site whatever the settings
		// said — it read only get_status() and threshold_exceeded(), never app_blocking —
		// and when the quota WAS exceeded it swapped the CSS class to cn-pending while
		// leaving the literal word "Active". So a site with autoblocking switched off was
		// told, on the settings page, that it was blocking.
		//
		// Report the effective state instead: the AND of both controls, quota included,
		// via the one accessor every other surface now uses.
		if ( $threshold_exceeded ) {
			// Quota cap — temporary, and the field's own description says why.
			$blocking_status_class = 'cn-pending';
			$blocking_status_label = __( 'Paused', 'cookie-notice' );
		} elseif ( $cn->blocking_is_active() ) {
			$blocking_status_class = 'cn-active';
			$blocking_status_label = __( 'Active', 'cookie-notice' );
		} else {
			// Either control off — the admin's own choice, not a fault.
			$blocking_status_class = 'cn-inactive';
			$blocking_status_label = __( 'Off', 'cookie-notice' );
		}
		// ── End autoblocking status row (DEC-012)

		switch ( $app_status ) {
			case 'active':
				echo '
				<div id="cn_app_status">
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Consent Banner', 'cookie-notice' ) . '</span>: <span class="cn-status cn-active"><span class="cn-icon"></span> ' . esc_html__( 'Active', 'cookie-notice' ) . '</span></div>
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Autoblocking', 'cookie-notice' ) . '</span>: <span class="cn-status ' . esc_attr( $blocking_status_class ) . '"><span class="cn-icon"></span> ' . esc_html( $blocking_status_label ) . '</span></div>
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Cookie Categories', 'cookie-notice' ) . '</span>: <span class="cn-status cn-active"><span class="cn-icon"></span> ' . esc_html__( 'Active', 'cookie-notice' ) . '</span></div>
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Cookie Consent Storage', 'cookie-notice' ) . '</span>: <span class="cn-status cn-active"><span class="cn-icon"></span> ' . esc_html__( 'Active', 'cookie-notice' ) . '</span></div>
				</div>
				<div id="cn_app_actions">
					<a href="' . esc_url( $portal_url ) . '" class="button button-primary button-hero cn-button" target="_blank">' . esc_html__( 'Open Admin Portal', 'cookie-notice' ) . '</a>
				</div>';
				break;

			case 'pending':
				echo '
				<div id="cn_app_status">
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Consent Banner', 'cookie-notice' ) . '</span>: <span class="cn-status cn-active"><span class="cn-icon"></span> ' . esc_html__( 'Active', 'cookie-notice' ) . '</span></div>
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Autoblocking', 'cookie-notice' ) . '</span>: <span class="cn-status cn-pending"><span class="cn-icon"></span> ' . esc_html__( 'Pending', 'cookie-notice' ) . '</span></div>
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Cookie Categories', 'cookie-notice' ) . '</span>: <span class="cn-status cn-pending"><span class="cn-icon"></span> ' . esc_html__( 'Pending', 'cookie-notice' ) . '</span></div>
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Cookie Consent Storage', 'cookie-notice' ) . '</span>: <span class="cn-status cn-pending"><span class="cn-icon"></span> ' . esc_html__( 'Pending', 'cookie-notice' ) . '</span></div>
				</div>
				<div id="cn_app_actions">
					<a href="' . esc_url( $portal_url ) . '" class="button button-primary button-hero cn-button" target="_blank">' . esc_html__( 'Open Admin Portal', 'cookie-notice' ) . '</a>
					<p class="description">' . esc_html__( 'Sign in to the Cookie Compliance Admin Portal and complete the setup process.', 'cookie-notice' ) . '</p>
				</div>';
				break;

			case 'unconfirmed':
				echo '
				<div id="cn_app_status">
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Connection', 'cookie-notice' ) . '</span>: <span class="cn-status cn-inactive"><span class="cn-icon"></span> ' . esc_html__( 'Not confirmed', 'cookie-notice' ) . '</span></div>
				</div>
				<div id="cn_app_actions">
					<a href="' . esc_url( $portal_url ) . '" class="button button-primary button-hero cn-button" target="_blank">' . esc_html__( 'Open Admin Portal', 'cookie-notice' ) . '</a>
				</div>';
				break;

			default:
				if ( $cn->is_network_admin() )
					$url = network_admin_url( 'admin.php?page=cookie-notice' );
				else
					$url = admin_url( 'admin.php?page=cookie-notice' );

				echo '
				<div id="cn_app_status">
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Consent Banner', 'cookie-notice' ) . '</span>: <span class="cn-status cn-active"><span class="cn-icon"></span> ' . esc_html__( 'Active', 'cookie-notice' ) . '</span></div>
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Autoblocking', 'cookie-notice' ) . '</span>: <span class="cn-status cn-inactive"><span class="cn-icon"></span> ' . esc_html__( 'Inactive', 'cookie-notice' ) . '</span></div>
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Cookie Categories', 'cookie-notice' ) . '</span>: <span class="cn-status cn-inactive"><span class="cn-icon"></span> ' . esc_html__( 'Inactive', 'cookie-notice' ) . '</span></div>
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Cookie Consent Storage', 'cookie-notice' ) . '</span>: <span class="cn-status cn-inactive"><span class="cn-icon"></span> ' . esc_html__( 'Inactive', 'cookie-notice' ) . '</span></div>
				</div>
				<div id="cn_app_actions">
					<a href="' . esc_url( $url ) . '" class="button button-primary button-hero cn-button cn-run-welcome">' . esc_html__( 'Connect Your Site', 'cookie-notice' ) . '</a>
					<p class="description">' . sprintf( esc_html__( 'Sign up to %s and add GDPR, CCPA and other international data privacy laws compliance features.', 'cookie-notice' ), '<a href="https://cookie-compliance.co/?utm_campaign=sign-up&utm_source=wordpress&utm_medium=textlink" target="_blank">Cookie Compliance</a>' ) . '</p>
				</div>';
				break;
		}
	}
	
	/**
	 * Compliance status.
	 *
	 * @return void
	 */
	public function cn_consent_logs_status() {
		// get main instance
		$cn = Cookie_Notice();

		// get cookie compliance status
		$app_status = $cn->get_status();

		if ( $cn->is_network_admin() )
			$url = network_admin_url( 'admin.php?page=cookie-notice' );
		else
			$url = admin_url( 'admin.php?page=cookie-notice' );

		// the Admin Portal, on this site's own app ( the portal reads app-id from the query inside its # route, as the upgrade links do )
		$app_id     = (string) $cn->options['general']['app_id'];
		$portal_url = $cn->get_url( 'host', '?utm_campaign=configure&utm_source=wordpress&utm_medium=button#/dashboard' . ( $app_id !== '' ? '?app-id=' . rawurlencode( $app_id ) : '' ) );

		// an App ID but no status: connected, the last configuration pull did not confirm the state
		if ( $app_status !== 'active' && $app_status !== 'pending' && $app_id !== '' )
			$app_status = 'unconfirmed';

		switch ( $app_status ) {
			case 'active':
				echo '
				<div id="cn_app_status">
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Cookie Consent Logs', 'cookie-notice' ) . '</span>: <span class="cn-status cn-active"><span class="cn-icon"></span> ' . esc_html__( 'Active', 'cookie-notice' ) . '</span></div>
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Privacy Consent Logs', 'cookie-notice' ) . '</span>: <span class="cn-status cn-active"><span class="cn-icon"></span> ' . esc_html__( 'Active', 'cookie-notice' ) . '</span></div>
				</div>
				<div id="cn_app_actions">
					<a href="' . esc_url( $portal_url ) . '" class="button button-primary button-hero cn-button" target="_blank">' . esc_html__( 'Open Admin Portal', 'cookie-notice' ) . '</a>
				</div>';
				break;

			case 'pending':
				echo '
				<div id="cn_app_status">
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Cookie Consent Logs', 'cookie-notice' ) . '</span>: <span class="cn-status cn-pending"><span class="cn-icon"></span> ' . esc_html__( 'Pending', 'cookie-notice' ) . '</span></div>
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Privacy Consent Logs', 'cookie-notice' ) . '</span>: <span class="cn-status cn-pending"><span class="cn-icon"></span> ' . esc_html__( 'Pending', 'cookie-notice' ) . '</span></div>
				</div>
				<div id="cn_app_actions">
					<a href="' . esc_url( $portal_url ) . '" class="button button-primary button-hero cn-button" target="_blank">' . esc_html__( 'Open Admin Portal', 'cookie-notice' ) . '</a>
					<p class="description">' . esc_html__( 'Sign in to the Cookie Compliance Admin Portal and complete the setup process.', 'cookie-notice' ) . '</p>
				</div>';
				break;

			case 'unconfirmed':
				echo '
				<div id="cn_app_status">
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Connection', 'cookie-notice' ) . '</span>: <span class="cn-status cn-inactive"><span class="cn-icon"></span> ' . esc_html__( 'Not confirmed', 'cookie-notice' ) . '</span></div>
				</div>
				<div id="cn_app_actions">
					<a href="' . esc_url( $portal_url ) . '" class="button button-primary button-hero cn-button" target="_blank">' . esc_html__( 'Open Admin Portal', 'cookie-notice' ) . '</a>
				</div>';
				break;

			default:
				echo '
				<div id="cn_app_status">
					<div class="cn_compliance_status"><span class="cn-status-label">' . '<span class="cn-status-label">' . esc_html__( 'Cookie Consent Logs', 'cookie-notice' ) . '</span>: <span class="cn-status cn-inactive"><span class="cn-icon"></span> ' . esc_html__( 'Inactive', 'cookie-notice' ) . '</span></div>
					<div class="cn_compliance_status"><span class="cn-status-label">' . esc_html__( 'Privacy Consent Logs', 'cookie-notice' ) . '</span>: <span class="cn-status cn-inactive"><span class="cn-icon"></span> ' . esc_html__( 'Inactive', 'cookie-notice' ) . '</span></div>
				</div>
				<div id="cn_app_actions">
					<a href="' . esc_url( $url ) . '" class="button button-primary button-hero cn-button cn-run-welcome">' . esc_html__( 'Connect Your Site', 'cookie-notice' ) . '</a>
					<p class="description">' . sprintf( esc_html__( 'Sign up to %s and enable Privacy Consent support.', 'cookie-notice' ), '<a href="https://cookie-compliance.co/?utm_campaign=sign-up&utm_source=wordpress&utm_medium=textlink" target="_blank">Cookie Compliance</a>' ) . '</p>
				</div>';
		}
	}

	/**
	 * App ID option.
	 *
	 * @return void
	 */
	public function cn_app_id() {
		echo '
		<div id="cn_app_id">
			<input type="text" class="regular-text" name="cookie_notice_options[app_id]" value="' . esc_attr( Cookie_Notice()->options['general']['app_id'] ) . '" />
			<p class="description">' . esc_html__( 'Enter your Cookie Compliance application ID.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * App key option.
	 *
	 * @return void
	 */
	public function cn_app_key() {
		echo '
		<div id="cn_app_key">
			<input type="password" class="regular-text" name="cookie_notice_options[app_key]" value="' . esc_attr( Cookie_Notice()->options['general']['app_key'] ) . '" />
			<p class="description">' . esc_html__( 'Enter your Cookie Compliance App Secret Key.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * Script blocking engine option — the master switch (DEC-012).
	 *
	 * Separate from cn_app_blocking() below, which is the POSTURE. This one answers
	 * whether Cookie Compliance may touch the site's scripts at all; the widget treats
	 * huOptions.blockingEngine as absolute, so off means nothing is held for anybody.
	 *
	 * The sentinel is emitted UNCONDITIONALLY, and that exactly matches this field
	 * having no disabled() condition — the Free-plan quota caps the posture, never the
	 * engine. Keeping "sentinel emitted" and "value submittable" the same predicate is
	 * the whole #2272 contract: a sentinel without a submittable field persists false
	 * on every save, and a submittable field without a sentinel is inert (a checked box
	 * posts the string '1', which multi_array_merge()'s type-strict test discards
	 * against a bool default).
	 *
	 * @return void
	 */
	public function cn_app_blocking_engine() {
		// get main instance
		$cn = Cookie_Notice();

		echo '
		<div id="cn_app_blocking_engine">
			<label>' .
			'<input type="hidden" name="cookie_notice_options[app_blocking_engine_rendered]" value="1" />' .
			'<input type="checkbox" name="cookie_notice_options[app_blocking_engine]" value="1" ' . checked( true, $cn->options['general']['app_blocking_engine'], false ) . ' />' . esc_html__( 'Allow Cookie Compliance to block scripts on this site.', 'cookie-notice' ) . '</label>
			<p class="description">' . esc_html__( 'The master switch. Turn this off and Cookie Compliance never touches your scripts — nothing is blocked for any visitor, in any region, regardless of privacy signals. Leave it on unless you handle script blocking yourself.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * App autoblocking option — the POSTURE (DEC-012).
	 *
	 * Deliberately NOT rendered disabled when the engine is off. A second disabled()
	 * condition without a matching condition on the sentinel above would recreate
	 * #2272 and destroy this stored preference on every save; the explanatory note
	 * says the same thing without touching what is stored.
	 *
	 * @return void
	 */
	public function cn_app_blocking() {
		// get main instance
		$cn = Cookie_Notice();

		$threshold_exceeded = $cn->threshold_exceeded();
		$engine_off         = empty( $cn->options['general']['app_blocking_engine'] );

		echo '
		<div id="cn_app_blocking"' . ( $threshold_exceeded ? ' class="cn-option-disabled"' : '' ) . '>
			<label>' .
			( ! $threshold_exceeded ? '<input type="hidden" name="cookie_notice_options[app_blocking_rendered]" value="1" />' : '' ) .
			'<input type="checkbox" name="cookie_notice_options[app_blocking]" value="1" ' . checked( true, $cn->options['general']['app_blocking'], false ) . ' ' . disabled( $threshold_exceeded, true, false ) . ' />' . esc_html__( 'Enable to automatically block 3rd party scripts before user consent is set.', 'cookie-notice' ) . '</label>
			<p class="description">' . esc_html__( 'Block before consent. Holds third-party scripts until the visitor makes a choice. With this off, scripts load immediately; once the visitor chooses, their choice is enforced either way.', 'cookie-notice' ) . '</p>' .
			( $engine_off ? '<p class="description"><span class="cn-warning">*</span> ' . esc_html__( 'No effect while the script blocking engine is off.', 'cookie-notice' ) . '</p>' : '' ) .
			( $threshold_exceeded ? '<p class="description"><span class="cn-warning">*</span> ' . esc_html__( 'This option has been temporarily disabled because your website has reached the usage limit for the Cookie Compliance Free Plan. It will become available again when the current visits cycle resets or you upgrade your website to a Professional plan.', 'cookie-notice' ) . '</p>' : '' ) .
		'</div>';
	}

	/**
	 * Excluded script handles textarea.
	 *
	 * @return void
	 */
	public function cn_excluded_handles() {
		$cn = Cookie_Notice();

		$value = ! empty( $cn->options['general']['excluded_handles'] )
			? implode( "\n", $cn->options['general']['excluded_handles'] )
			: '';

		echo '
		<div id="cn_excluded_handles">
			<textarea name="cookie_notice_options[excluded_handles]" class="large-text" rows="4" cols="50" placeholder="elementor-frontend&#10;my-analytics-init">'
				. esc_textarea( $value ) .
			'</textarea>
			<p class="description">' . esc_html__( 'Enter WordPress script handles to exclude from autoblocking, one per line. These scripts will be marked as Essential (Category 1) so they are never blocked.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * Sync configuration option.
	 *
	 * @return void
	 */
	public function cn_sync_config() {
		// get main instance
		$cn = Cookie_Notice();

		$network = $cn->is_network_options();

		// get last sync timestamp
		if ( $network )
			$blocking = get_site_option( 'cookie_notice_app_blocking', [] );
		else
			$blocking = get_option( 'cookie_notice_app_blocking', [] );

		$last_synced = ! empty( $blocking['lastUpdated'] ) ? $blocking['lastUpdated'] : '';
		$last_synced_display = $last_synced ? sprintf( esc_html__( 'Last synced (UTC): %s', 'cookie-notice' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_synced ) ) ) : esc_html__( 'Not synced yet', 'cookie-notice' );

		// Deliberately NOT gated on threshold_exceeded. Pulling the configuration is
		// how a wrong plan or a stale visit count gets corrected, so disabling it
		// while that state is set locked the one control that clears it — a domain
		// upgraded Free -> Pro had to wait for a cron it could not trigger.
		echo '
		<div id="cn_sync_config">
			<div class="cn-button-container">
				<button type="button" class="button button-secondary cn-sync-config-btn">
					<span class="dashicons dashicons-update"></span>
					' . esc_html__( 'Pull latest settings', 'cookie-notice' ) . '
				</button>
				<span class="cn-sync-spinner spinner"></span>
				<span class="description cn-sync-status">' . $last_synced_display . '</span>
			</div>
			<p class="description">' . esc_html__( 'Manually pull the latest configuration including autoblocking. Configuration also syncs automatically twice a day.', 'cookie-notice' ) . '</p>
			<div class="cn-sync-message" style="display: none;"></div>
		</div>';
	}

	/**
	 * Purge cache option.
	 *
	 * @return void
	 */
	public function cn_app_purge_cache() {
		echo '
		<div id="cn_app_purge_cache">
			<div class="cn-button-container">
				<a href="#" class="button button-secondary">' . esc_html__( 'Purge Cache', 'cookie-notice' ) . '</a>
			</div>
			<p class="description">' . esc_html__( 'Click the Purge Cache button to refresh the app configuration.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * Conditional display option.
	 *
	 * @return void
	 */
	public function cn_conditional_display() {
		// get main instance
		$cn = Cookie_Notice();

		echo '
		<fieldset id="cn_conditional_display">
			<label><input id="cn_conditional_display_opt" type="checkbox" name="cookie_notice_options[conditional_active]" value="1" ' . checked( true, $cn->options['general']['conditional_active'], false ) . ' />' . esc_html__( 'Enable conditional display of the banner.', 'cookie-notice' ) . '</label>
			<div id="cn_conditional_display_opt_container"' . ( empty( $cn->options['general']['conditional_active'] ) ? ' style="display: none"' : '' ) . ' class="cn_fieldset_content">
				<div>
					<select name="cookie_notice_options[conditional_display]">';

			foreach ( $this->conditional_display_types as $type => $label ) {
				echo '
						<option value="' . esc_attr( $type ) . '" ' . selected( $type, $cn->options['general']['conditional_display'] ) . '>' . esc_html( $label ) . '</option>';
			}

			echo '
					</select>
					<p class="description">' . esc_html__( 'Determine what should happen when the following conditions are met.', 'cookie-notice' ) . '</p>
				</div>';

		// get allowed html
		$allowed_html = wp_kses_allowed_html( 'post' );
		$allowed_html['select'] = [
			'name'	=> true,
			'class'	=> true
		];
		$allowed_html['option'] = [
			'value'		=> true,
			'selected'	=> true
		];
		$allowed_html['optgroup'] = [
			'label'		=> true
		];

		add_filter( 'safe_style_css', [ $this, 'allow_style_attributes' ] );

		echo wp_kses( $this->conditional_display( $cn->options['general']['conditional_rules'] ), $allowed_html );

		remove_filter( 'safe_style_css', [ $this, 'allow_style_attributes' ] );

		echo '
			</div>
		</fieldset>';
	}

	/**
	 * Debug mode option.
	 *
	 * @return void
	 */
	public function cn_debug_mode() {
		echo '
		<div id="cn_debug_mode">
			<label><input type="checkbox" name="cookie_notice_options[debug_mode]" value="1" ' . checked( true, Cookie_Notice()->options['general']['debug_mode'], false ) . ' />' . esc_html__( 'Enable to run the consent banner in debug mode.', 'cookie-notice' ) . '</label>
		</div>';
	}

	/**
	 * AMP support option.
	 *
	 * @return void
	 */
	public function cn_amp_support() {
		$amp_enabled = cn_is_plugin_active( 'amp' );

		echo '
		<div id="cn_amp_support">
			<label><input type="checkbox" name="cookie_notice_options[amp_support]" value="1" ' . checked( true, Cookie_Notice()->options['general']['amp_support'] && $amp_enabled, false ) . ' ' . disabled( ! $amp_enabled, true, false ) . ' />' . esc_html__( 'Enable to support AMP.', 'cookie-notice' ) . '</label>
			<p class="description">' . ( ! $amp_enabled ? esc_html__( 'No compatible Google AMP plugins found.', 'cookie-notice' ) : esc_html__( 'Allows you to activate consent banner support for Google AMP.', 'cookie-notice' ) ) . '</p>
		</div>';
	}

	/**
	 * WP Consent API option.
	 *
	 * @return void
	 */
	public function cn_wp_consent_api() {
		$wpca_active = function_exists( 'wp_has_consent' );

		echo '
		<div id="cn_wp_consent_api">
			<label><input type="checkbox" name="cookie_notice_options[wp_consent_api]" value="1" ' . checked( true, Cookie_Notice()->options['general']['wp_consent_api'] && $wpca_active, false ) . ' ' . disabled( ! $wpca_active, true, false ) . ' />' . esc_html__( 'Register as the active CMP under the WP Consent API.', 'cookie-notice' ) . '</label>
			<p class="description">' . ( ! $wpca_active ? esc_html__( 'The WP Consent API plugin is not active. Install and activate it to enable this integration.', 'cookie-notice' ) : esc_html__( 'Allows cooperative plugins (WooCommerce, Google Site Kit, Burst Statistics, WP Statistics, and others) to gate themselves on the consent state captured by the banner.', 'cookie-notice' ) ) . '</p>
		</div>';
	}

	/**
	 * Bot detection option.
	 *
	 * @return void
	 */
	public function cn_bot_detection() {
		echo '
		<div id="cn_bot_detection">
			<label><input type="checkbox" name="cookie_notice_options[bot_detection]" value="1" ' . checked( true, Cookie_Notice()->options['general']['bot_detection'], false ) . ' />' . esc_html__( 'Enable to activate bot detection and reduce the number of calculated website visits.', 'cookie-notice' ) . '</label>
		</div>';
	}

	/**
	 * Caching compatibility option.
	 *
	 * @return void
	 */
	public function cn_caching_compatibility() {
		// get main instance
		$cn = Cookie_Notice();

		$plugins_html = '';

		// get active caching plugins
		$active_plugins = cn_get_active_caching_plugins();

		if ( ! empty( $active_plugins ) ) {
			$active_plugins_html = [];

			$plugins_html .= '<p class="description">' . esc_html__( 'Currently detected active caching plugins', 'cookie-notice' ) . ': ';

			foreach ( $active_plugins as $plugin ) {
				$active_plugins_html[] = '<code>' . esc_html( $plugin ) . '</code>';
			}

			$plugins_html .= implode( ', ', $active_plugins_html ) . '.</p>';
		} else
			$plugins_html .= '<p class="description">' . esc_html__( 'No compatible cache plugins found.', 'cookie-notice' ) . '</p>';

		echo '
		<div id="cn_caching_compatibility">
			<label><input type="checkbox" name="cookie_notice_options[caching_compatibility]" value="1" ' . checked( true, $cn->options['general']['caching_compatibility'] && ! empty( $active_plugins ), false ) . ' ' . disabled( empty( $active_plugins ), true, false ) . ' />' . esc_html__( 'Enable to apply changes improving compatibility with caching plugins.', 'cookie-notice' ) . '</label>' . $plugins_html . '
		</div>';
	}

	/**
	 * Cookie notice message option.
	 *
	 * @return void
	 */
	public function cn_message_text() {
		echo '
		<div id="cn_message_text">
			<textarea name="cookie_notice_options[message_text]" class="large-text" cols="50" rows="5">' . esc_textarea( Cookie_Notice()->options['general']['message_text'] ) . '</textarea>
			<p class="description">' . esc_html__( 'Enter the cookie notice message.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * Accept cookie label option.
	 *
	 * @return void
	 */
	public function cn_accept_text() {
		echo '
		<div id="cn_accept_text">
			<input type="text" class="regular-text" name="cookie_notice_options[accept_text]" value="' . esc_attr( Cookie_Notice()->options['general']['accept_text'] ) . '" />
			<p class="description">' . esc_html__( 'The text of the option to accept the notice and make it disappear.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * Toggle third party non functional cookies option.
	 *
	 * @return void
	 */
	public function cn_refuse_opt() {
		// get main instance
		$cn = Cookie_Notice();

		echo '
		<fieldset>
			<label><input id="cn_refuse_opt" type="checkbox" name="cookie_notice_options[refuse_opt]" value="1" ' . checked( true, $cn->options['general']['refuse_opt'], false ) . ' />' . esc_html__( 'Enable to give to the user the possibility to refuse third party non functional cookies.', 'cookie-notice' ) . '</label>
			<div id="cn_refuse_opt_container"' . ( empty( $cn->options['general']['refuse_opt'] ) ? ' style="display: none"' : '' ) . ' class="cn_fieldset_content">
				<div id="cn_refuse_text">
					<input type="text" class="regular-text" name="cookie_notice_options[refuse_text]" value="' . esc_attr( $cn->options['general']['refuse_text'] ) . '" />
					<p class="description">' . esc_html__( 'The text of the button to refuse the consent.', 'cookie-notice' ) . '</p>
				</div>
			</div>
		</fieldset>';
	}

	/**
	 * Non functional cookies code option.
	 *
	 * @return void
	 */
	public function cn_refuse_code() {
		// get main instance
		$cn = Cookie_Notice();

		$active = ! empty( $cn->options['general']['refuse_code'] ) && empty( $cn->options['general']['refuse_code_head'] ) ? 'body' : 'head';

		echo '
		<div id="cn_refuse_code">
			<div id="cn_refuse_code_fields">
				<h2 class="nav-tab-wrapper">
					<a id="refuse_head-tab" class="nav-tab' . ( $active === 'head' ? ' nav-tab-active' : '' ) . '" href="#refuse_head">' . esc_html__( 'Head', 'cookie-notice' ) . '</a>
					<a id="refuse_body-tab" class="nav-tab' . ( $active === 'body' ? ' nav-tab-active' : '' ) . '" href="#refuse_body">' . esc_html__( 'Body', 'cookie-notice' ) . '</a>
				</h2>
				<div id="refuse_head" class="refuse-code-tab' . ( $active === 'head' ? ' active' : '' ) . '">
					<p class="description">' . esc_html__( 'The code to be used in your site header, before the closing head tag.', 'cookie-notice' ) . '</p>
					<textarea name="cookie_notice_options[refuse_code_head]" class="large-text" cols="50" rows="8">' . esc_textarea( html_entity_decode( trim( wp_kses( $cn->options['general']['refuse_code_head'], $cn->get_allowed_html( 'head' ) ) ) ) ) . '</textarea>
				</div>
				<div id="refuse_body" class="refuse-code-tab' . ( $active === 'body' ? ' active' : '' ) . '">
					<p class="description">' . esc_html__( 'The code to be used in your site footer, before the closing body tag.', 'cookie-notice' ) . '</p>
					<textarea name="cookie_notice_options[refuse_code]" class="large-text" cols="50" rows="8">' . esc_textarea( html_entity_decode( trim( wp_kses( $cn->options['general']['refuse_code'], $cn->get_allowed_html( 'body' ) ) ) ) ) . '</textarea>
				</div>
			</div>
			<p class="description">' . esc_html__( 'Enter non functional cookies Javascript code here (for e.g. Google Analitycs) to be used after the visitor consent is given.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * Pro features locked callout (inactive/BASIC tier).
	 * Shows Cookie Categories and Analytics as disabled with upgrade CTA.
	 *
	 * @return void
	 */
	public function cn_pro_features_locked() {
		$welcome_url = cn_get_welcome_url();

		echo '
		<div id="cn_pro_features_locked">
			<div class="cn-pro-feature-item">
				<label>' . esc_html__( 'Cookie Categories', 'cookie-notice' ) . ' <span class="cn-pro-lock">' . esc_html__( 'Pro', 'cookie-notice' ) . '</span></label>
				<p class="description">' . esc_html__( 'Group cookies by purpose and let users consent selectively.', 'cookie-notice' ) . '</p>
			</div>
			<div class="cn-pro-feature-item">
				<label>' . esc_html__( 'Analytics &amp; Consent Logs', 'cookie-notice' ) . ' <span class="cn-pro-lock">' . esc_html__( 'Pro', 'cookie-notice' ) . '</span></label>
				<p class="description">' . esc_html__( 'Track consent rates, view logs, and generate compliance reports.', 'cookie-notice' ) . '</p>
			</div>
			<p><a href="' . esc_url( $welcome_url ) . '" class="button button-secondary cn-pro-upgrade-btn">' . esc_html__( 'Connect your site to unlock &#8594;', 'cookie-notice' ) . '</a></p>
		</div>';
	}

	/**
	 * Revoke cookies option.
	 *
	 * @return void
	 */
	public function cn_revoke_opt() {
		// get main instance
		$cn = Cookie_Notice();

		echo '
		<fieldset id="cn_revoke_opt">
			<label><input id="cn_revoke_cookies" type="checkbox" name="cookie_notice_options[revoke_cookies]" value="1" ' . checked( true, $cn->options['general']['revoke_cookies'], false ) . ' />' . sprintf( esc_html__( 'Enable Update consent so visitors can reopen the banner and change their consent %s(requires "Refuse consent" option enabled)%s.', 'cookie-notice' ), '<i>', '</i>' ) . '</label>
			<div id="cn_revoke_opt_container"' . ( $cn->options['general']['revoke_cookies'] ? '' : ' style="display: none"' ) . ' class="cn_fieldset_content">
				<textarea name="cookie_notice_options[revoke_message_text]" class="large-text" cols="50" rows="2">' . esc_textarea( $cn->options['general']['revoke_message_text'] ) . '</textarea>
				<p class="description">' . esc_html__( 'Enter the Update consent message.', 'cookie-notice' ) . '</p>
				<input type="text" class="regular-text" name="cookie_notice_options[revoke_text]" value="' . esc_attr( $cn->options['general']['revoke_text'] ) . '" />
				<p class="description">' . esc_html__( 'The text of the Update consent button.', 'cookie-notice' ) . '</p>';

		foreach ( $this->revoke_opts as $value => $label ) {
			echo '
				<label><input id="cn_revoke_cookies-' . esc_attr( $value ) . '" type="radio" name="cookie_notice_options[revoke_cookies_opt]" value="' . esc_attr( $value ) . '" ' . checked( $value, $cn->options['general']['revoke_cookies_opt'], false ) . ' />' . esc_html( $label ) . '</label>';
		}

		echo '
				<p class="description">' . sprintf( esc_html__( 'Select how Update consent is shown — automatic (floating control) or manual using the %s[cookies_revoke]%s shortcode.', 'cookie-notice' ), '<code>', '</code>' ) . '</p>
			</div>
		</fieldset>';
	}

	/**
	 * Redirection on cookie accept option.
	 *
	 * @return void
	 */
	public function cn_redirection() {
		echo '
		<div id="cn_redirection">
			<label><input type="checkbox" name="cookie_notice_options[redirection]" value="1" ' . checked( true, Cookie_Notice()->options['general']['redirection'], false ) . ' />' . esc_html__( 'Enable to reload the page after the notice is accepted.', 'cookie-notice' ) . '</label>
		</div>';
	}

	/**
	 * Privacy policy link option.
	 *
	 * @global string $wp_version
	 *
	 * @return void
	 */
	public function cn_see_more() {
		// get main instance
		$cn = Cookie_Notice();

		// get published pages
		$pages = get_pages(
			[
				'sort_order'	=> 'ASC',
				'sort_column'	=> 'post_title',
				'hierarchical'	=> 0,
				'child_of'		=> 0,
				'parent'		=> -1,
				'offset'		=> 0,
				'post_type'		=> 'page',
				'post_status'	=> 'publish'
			]
		);

		echo '
		<fieldset>
			<label><input id="cn_see_more" type="checkbox" name="cookie_notice_options[see_more]" value="1" ' . checked( true, $cn->options['general']['see_more'], false ) . ' />' . esc_html__( 'Enable privacy policy link.', 'cookie-notice' ) . '</label>
			<div id="cn_see_more_opt"' . ( empty( $cn->options['general']['see_more'] ) ? ' style="display: none"' : '' ) . ' class="cn_fieldset_content">
				<input type="text" class="regular-text" name="cookie_notice_options[see_more_opt][text]" value="' . esc_attr( $cn->options['general']['see_more_opt']['text'] ) . '" />
				<p class="description">' . esc_html__( 'The text of the privacy policy button.', 'cookie-notice' ) . '</p>
				<div id="cn_see_more_opt_custom_link">';

		foreach ( $this->links as $value => $label ) {
			echo '
					<label><input id="cn_see_more_link-' . esc_attr( $value ) . '" type="radio" name="cookie_notice_options[see_more_opt][link_type]" value="' . esc_attr( $value ) . '" ' . checked( $value, $cn->options['general']['see_more_opt']['link_type'], false ) . ' />' . esc_html( $label ) . '</label>';
		}

		echo '
				</div>
				<p class="description">' . esc_html__( 'Select where to redirect user for more information.', 'cookie-notice' ) . '</p>
				<div id="cn_see_more_opt_page"' . ( $cn->options['general']['see_more_opt']['link_type'] === 'custom' ? ' style="display: none"' : '' ) . '>
					<select name="cookie_notice_options[see_more_opt][id]">
						<option value="0" ' . selected( 0, $cn->options['general']['see_more_opt']['id'], false ) . '>' . esc_html__( '-- select page --', 'cookie-notice' ) . '</option>';

		if ( $pages ) {
			foreach ( $pages as $page ) {
				echo '
						<option value="' . esc_attr( $page->ID ) . '" ' . selected( $page->ID, $cn->options['general']['see_more_opt']['id'], false ) . '>' . esc_html( $page->post_title ) . '</option>';
			}
		}

		echo '
					</select>
					<p class="description">' . esc_html__( 'Select from one of your site\'s pages.', 'cookie-notice' ) . '</p>';

		global $wp_version;

		if ( version_compare( $wp_version, '4.9.6', '>=' ) ) {
			echo '
						<label><input id="cn_see_more_opt_sync" type="checkbox" name="cookie_notice_options[see_more_opt][sync]" value="1" ' . checked( true, $cn->options['general']['see_more_opt']['sync'], false ) . ' />' . esc_html__( 'Synchronize with WordPress Privacy Policy page.', 'cookie-notice' ) . '</label>';
		}

		echo '
				</div>
				<div id="cn_see_more_opt_link"' . ( $cn->options['general']['see_more_opt']['link_type'] === 'page' ? ' style="display: none"' : '' ) . '>
					<input type="text" class="regular-text" name="cookie_notice_options[see_more_opt][link]" value="' . esc_attr( $cn->options['general']['see_more_opt']['link'] ) . '" />
					<p class="description">' . esc_html__( 'Enter the full URL starting with http(s)://', 'cookie-notice' ) . '</p>
				</div>
				<div id="cn_see_more_link_target">';

		foreach ( $this->link_targets as $target ) {
			echo '
					<label><input id="cn_see_more_link_target-' . esc_attr( $target ) . '" type="radio" name="cookie_notice_options[link_target]" value="' . esc_attr( $target ) . '" ' . checked( $target, $cn->options['general']['link_target'], false ) . ' />' . esc_html( $target ) . '</label>';
		}

		echo '
					<p class="description">' . esc_html__( 'Select the privacy policy link target.', 'cookie-notice' ) . '</p>
				</div>
				<div id="cn_see_more_link_position">';

		foreach ( $this->link_positions as $position => $label ) {
			echo '
					<label><input id="cn_see_more_link_position-' . esc_attr( $position ) . '" type="radio" name="cookie_notice_options[link_position]" value="' . esc_attr( $position ) . '" ' . checked( $position, $cn->options['general']['link_position'], false ) . ' />' . esc_html( $label ) . '</label>';
		}

		echo '
					<p class="description">' . esc_html__( 'Select the privacy policy link position.', 'cookie-notice' ) . '</p>
				</div>
			</div>
		</fieldset>';
	}

	/**
	 * Expiration time option.
	 *
	 * @return void
	 */
	public function cn_time() {
		echo '
		<div id="cn_time">
			<select name="cookie_notice_options[time]">';

		foreach ( $this->times as $time => $arr ) {
			echo '
				<option value="' . esc_attr( $time ) . '" ' . selected( $time, Cookie_Notice()->options['general']['time'] ) . '>' . esc_html( $arr[0] ) . '</option>';
		}

		echo '
			</select>
			<p class="description">' . esc_html__( 'The amount of time that the cookie should be stored for when user accepts the notice.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * Expiration time option.
	 *
	 * @return void
	 */
	public function cn_time_rejected() {
		echo '
		<div id="cn_time_rejected">
			<select name="cookie_notice_options[time_rejected]">';

		foreach ( $this->times as $time => $arr ) {
			echo '
				<option value="' . esc_attr( $time ) . '" ' . selected( $time, Cookie_Notice()->options['general']['time_rejected'] ) . '>' . esc_html( $arr[0] ) . '</option>';
		}

		echo '
			</select>
			<p class="description">' . esc_html__( 'The amount of time that the cookie should be stored for when the user doesn\'t accept the notice.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * Script placement option.
	 *
	 * @return void
	 */
	public function cn_script_placement() {
		echo '
		<div id="cn_script_placement">';

		foreach ( $this->script_placements as $value => $label ) {
			echo '
			<label><input id="cn_script_placement-' . esc_attr( $value ) . '" type="radio" name="cookie_notice_options[script_placement]" value="' . esc_attr( $value ) . '" ' . checked( $value, Cookie_Notice()->options['general']['script_placement'], false ) . ' />' . esc_html( $label ) . '</label>';
		}

		echo '
			<p class="description">' . esc_html__( 'Select where all the plugin scripts should be placed.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * Position option.
	 *
	 * @return void
	 */
	public function cn_position() {
		echo '
		<div id="cn_position">';

		foreach ( $this->positions as $value => $label ) {
			echo '
			<label><input id="cn_position-' . esc_attr( $value ) . '" type="radio" name="cookie_notice_options[position]" value="' . esc_attr( $value ) . '" ' . checked( $value, Cookie_Notice()->options['general']['position'], false ) . ' />' . esc_html( $label ) . '</label>';
		}

		echo '
			<p class="description">' . esc_html__( 'Select location for the notice.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * Animation effect option.
	 *
	 * @return void
	 */
	public function cn_hide_effect() {
		echo '
		<div id="cn_hide_effect">';

		foreach ( $this->effects as $value => $label ) {
			echo '
			<label><input id="cn_hide_effect-' . esc_attr( $value ) . '" type="radio" name="cookie_notice_options[hide_effect]" value="' . esc_attr( $value ) . '" ' . checked( $value, Cookie_Notice()->options['general']['hide_effect'], false ) . ' />' . esc_html( $label ) . '</label>';
		}

		echo '
			<p class="description">' . esc_html__( 'Select the animation style.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * On scroll option.
	 *
	 * @return void
	 */
	public function cn_on_scroll() {
		// get main instance
		$cn = Cookie_Notice();

		echo '
		<fieldset>
			<label><input id="cn_on_scroll" type="checkbox" name="cookie_notice_options[on_scroll]" value="1" ' . checked( true, $cn->options['general']['on_scroll'], false ) . ' />' . esc_html__( 'Enable to accept the notice when user scrolls.', 'cookie-notice' ) . '</label>
			<div id="cn_on_scroll_offset"' . ( empty( $cn->options['general']['on_scroll'] ) ? ' style="display: none"' : '' ) . ' class="cn_fieldset_content">
				<input type="number" min="0" class="small-text" name="cookie_notice_options[on_scroll_offset]" value="' . esc_attr( $cn->options['general']['on_scroll_offset'] ) . '" /> <span>px</span>
				<p class="description">' . esc_html__( 'Number of pixels user has to scroll to accept the notice and make it disappear.', 'cookie-notice' ) . '</p>
			</div>
		</fieldset>';
	}

	/**
	 * On click option.
	 *
	 * @return void
	 */
	public function cn_on_click() {
		echo '
		<div id="cn_on_click">
			<label><input type="checkbox" name="cookie_notice_options[on_click]" value="1" ' . checked( true, Cookie_Notice()->options['general']['on_click'], false ) . ' />' . esc_html__( 'Enable to accept the notice on any click on the page.', 'cookie-notice' ) . '</label>
		</div>';
	}

	/**
	 * Delete plugin data on deactivation option.
	 *
	 * @return void
	 */
	public function cn_deactivation_delete() {
		echo '
		<div id="cn_deactivation_delete">
			<label><input type="checkbox" name="cookie_notice_options[deactivation_delete]" value="1" ' . checked( true, Cookie_Notice()->options['general']['deactivation_delete'], false ) . '/>' . esc_html__( 'Enable if you want all plugin data to be deleted on deactivation.', 'cookie-notice' ) . '</label>
		</div>';
	}

	/**
	 * CSS style option.
	 *
	 * @return void
	 */
	public function cn_css_class() {
		echo '
		<div id="cn_css_class">
			<input type="text" class="regular-text" name="cookie_notice_options[css_class]" value="' . esc_attr( Cookie_Notice()->options['general']['css_class'] ) . '" />
			<p class="description">' . esc_html__( 'Enter additional button CSS classes separated by spaces.', 'cookie-notice' ) . '</p>
		</div>';
	}

	/**
	 * Colors option.
	 *
	 * @return void
	 */
	public function cn_colors() {
		// get main instance
		$cn = Cookie_Notice();

		echo '
		<fieldset>
			<div id="cn_colors">';

		foreach ( $this->colors as $value => $label ) {
			echo '
				<div id="cn_colors-' . esc_attr( $value ) . '"><label>' . esc_html( $label ) . '</label><br />
					<input class="cn_color" type="text" name="cookie_notice_options[colors][' . esc_attr( $value ) . ']" value="' . esc_attr( $cn->options['general']['colors'][$value] ) . '" />
				</div>';
		}

		echo '
				<div id="cn_colors-bar_opacity"><label>' . esc_html__( 'Bar opacity', 'cookie-notice' ) . '</label><br />
					<div><input id="cn_colors_bar_opacity_range" class="cn_range" type="range" min="50" max="100" step="1" name="cookie_notice_options[colors][bar_opacity]" value="' . (int) $cn->options['general']['colors']['bar_opacity'] . '" onchange="cn_colors_bar_opacity_text.value = cn_colors_bar_opacity_range.value" /><input id="cn_colors_bar_opacity_text" class="small-text" type="number" onchange="cn_colors_bar_opacity_range.value = cn_colors_bar_opacity_text.value" min="50" max="100" value="' . (int) $cn->options['general']['colors']['bar_opacity'] . '" /></div>
				</div>';

		echo '
			</div>
		</fieldset>';
	}

	/** Check whether htaccess file has Content Security Policy directives with hu-manity.co domain.
	 *
	 * @return bool
	 */
	private function check_htaccess() {
		if ( ! function_exists( 'get_home_path' ) )
			require_once ABSPATH . 'wp-admin/includes/file.php';

		$htaccess_file = get_home_path() . '.htaccess';

		// whether htaccess file is readable
		$file_readable = false;

		// whether csp fetch directives are valid
		$valid_directives = true;

		// empty string = not found, false = invalid, true = valid
		$fetch_directives = [
			'img'		=> '',
			'connect'	=> '',
			'script'	=> '',
			'style'		=> '',
			'default'	=> ''
		];

		// htaccess exists and its readable?
		if ( is_readable( $htaccess_file ) ) {
			// read file
			$file_data = file( $htaccess_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

			// operable file?
			if ( $file_data !== false ) {
				$file_readable = true;
				$default_directive = '';

				// check every line
				foreach ( $file_data as $file_line ) {
					$trimmed_line = trim( $file_line );

					// skip comment lines
					if ( substr( $trimmed_line, 0, 1 ) !== "#" ) {
						$csp_position = strpos( $trimmed_line, 'Content-Security-Policy' );

						// found csp?
						if ( $csp_position !== false ) {
							$after_csp_line = substr( $trimmed_line, $csp_position + 23 );

							// get directives
							$directives = explode( ';', trim( $after_csp_line ) );

							foreach ( $directives as $directive ) {
								// get source expression list values
								$values = explode( ' ', trim( $directive ), 2 );

								// img-src directive
								if ( strpos( $values[0], 'img-src' ) !== false ) {
									// expression list exists and its valid?
									if ( isset( $values[1] ) && strpos( $values[1], 'data:' ) !== false )
										$fetch_directives['img'] = true;
									else
										$fetch_directives['img'] = false;
								}

								// style-src directive
								if ( strpos( $values[0], 'style-src' ) !== false ) {
									// expression list exists and its valid?
									if ( isset( $values[1] ) && strpos( $values[1], '\'unsafe-inline\'' ) !== false )
										$fetch_directives['style'] = true;
									else
										$fetch_directives['style'] = false;
								}

								// connect-src directive
								if ( strpos( $values[0], 'connect-src' ) !== false ) {
									// expression list exists and its valid?
									if ( isset( $values[1] ) && strpos( $values[1], '*.hu-manity.co' ) !== false )
										$fetch_directives['connect'] = true;
									else
										$fetch_directives['connect'] = false;
								}

								// script-src directive
								if ( strpos( $values[0], 'script-src' ) !== false ) {
									// expression list exists and its valid?
									if ( isset( $values[1] ) && strpos( $values[1], '\'unsafe-inline\'' ) !== false && strpos( $values[1], '*.hu-manity.co' ) !== false )
										$fetch_directives['script'] = true;
									else
										$fetch_directives['script'] = false;
								}

								// default-src directive
								if ( strpos( $values[0], 'default-src' ) !== false ) {
									if ( isset( $values[1] ) )
										$default_directive = $values[1];
								}
							}
						}
					}
				}

				// check default-src directive as last
				if ( $default_directive ) {
					if ( ! $fetch_directives['img'] )
						$condition1 = strpos( $default_directive, 'data:' ) !== false;
					else
						$condition1 = true;

					if ( ! $fetch_directives['style'] )
						$condition2 = strpos( $default_directive, '\'unsafe-inline\'' ) !== false;
					else
						$condition2 = true;

					if ( ! $fetch_directives['connect'] )
						$condition3 = strpos( $default_directive, '*.hu-manity.co' ) !== false;
					else
						$condition3 = true;

					if ( ! $fetch_directives['script'] )
						$condition4 = $condition2 && $condition3;
					else
						$condition4 = true;

					if ( $condition1 && $condition2 && $condition3 && $condition4 )
						$fetch_directives['default'] = true;
					else
						$fetch_directives['default'] = false;
				}
			}
		}

		foreach ( $fetch_directives as $directive ) {
			// check whether directive is valid
			if ( is_bool( $directive ) && ! $directive ) {
				$valid_directives = false;

				break;
			}
		}

		return $file_readable && ! $valid_directives;
	}

	/**
	 * Refresh the stored csp_notice flag from a live .htaccess re-check, gated
	 * by a transient so the file is read at most once per hour per scope.
	 *
	 * Why: the React admin save path does not invoke check_htaccess(), so the
	 * stored flag goes stale once a customer fixes their .htaccess. Decoupling
	 * the check from save side effects — render-time covers passive refresh;
	 * on-demand callers (purge cache, sync config) pass force=true.
	 *
	 * @param bool $force Bypass the transient cache.
	 * @return bool       Current invalid-CSP state (true = warning should show).
	 */
	public function refresh_csp_notice( $force = false ) {
		$cn = Cookie_Notice();

		if ( $cn->get_status() !== 'active' ) {
			// only this key, on a fresh read (Cookie_Notice::update_general_option_keys())
			if ( ! empty( $cn->options['general']['csp_notice'] ) )
				$cn->update_general_option_keys( [ 'csp_notice' => false ], $cn->is_network_admin() );

			return false;
		}

		$network = $cn->is_network_admin();
		$cached  = Cookie_Notice_Store::get_transient( 'cookie_notice_csp_check', $network );

		if ( ! $force && $cached !== false )
			return $cached === '1';

		$invalid = $this->check_htaccess();

		if ( $network )
			set_site_transient( 'cookie_notice_csp_check', $invalid ? '1' : '0', HOUR_IN_SECONDS );
		else
			set_transient( 'cookie_notice_csp_check', $invalid ? '1' : '0', HOUR_IN_SECONDS );

		if ( $cn->options['general']['csp_notice'] !== $invalid )
			$cn->update_general_option_keys( [ 'csp_notice' => $invalid ], $network );

		return $invalid;
	}

	/**
	 * Check whether to display admin notices.
	 *
	 * @return void
	 */
	public function check_notices() {
		global $pagenow;

		// get main instance
		$cn = Cookie_Notice();

		$allow_notice = false;

		if ( is_multisite() && $cn->is_plugin_network_active() ) {
			if ( $cn->is_network_admin() ) {
				if ( $cn->network_options['general']['global_override'] )
					$allow_notice = true;
			} elseif ( ! $cn->network_options['general']['global_override'] )
				$allow_notice = true;
		} else
			$allow_notice = true;

		// display notice?
		if ( $allow_notice && $pagenow === 'admin.php' && isset( $_GET['page'] ) && $_GET['page'] === 'cookie-notice' && $cn->get_status() === 'active' && $this->refresh_csp_notice() ) {
			add_settings_error( 'cn_cookie_notice_options', 'cookie_notice_csp_warning', esc_html__( "It looks like some of the Consent Security Policy (CSP) records in your website's .htaccess file may be causing Cookie Compliance loading problems. Make sure you allow loading of Cookie Compliance resources by adding the following record:", 'cookie-notice') . '<br><code>' .  esc_html__( "img-src data:; style-src 'unsafe-inline'; connect-src *.hu-manity.co; script-src 'unsafe-inline' *.hu-manity.co" ) . '</code>', 'error' );
		}
	}

	// ── Shared save rules ─────────────────────────────────────────────────
	//
	// One rule per field, and one method per post-save effect, called from BOTH save
	// paths: the legacy form (validate_options) and the React admin (Cookie_Notice_React_
	// Admin_Ajax::save_options). The React save posts only the keys that changed and
	// merges them over the stored row, so it calls the field rules only for keys it was
	// sent and runs the effects on the merged row. validate_options() itself is never
	// reused there: it writes a default for every key it was not sent.

	/**
	 * Clean a banner message text (notice or Update consent message): trim, then
	 * wp_kses_post with the plugin's 'display' style allowance. Empty → the default text.
	 *
	 * @param string $field 'message_text' or 'revoke_message_text'
	 * @param string $value Unslashed value
	 * @return string
	 */
	public function sanitize_message_text( $field, $value ) {
		add_filter( 'safe_style_css', [ $this, 'allow_style_attributes' ] );

		$value = wp_kses_post( trim( $value ) );

		remove_filter( 'safe_style_css', [ $this, 'allow_style_attributes' ] );

		return $value === '' ? Cookie_Notice()->defaults['general'][$field] : $value;
	}

	/**
	 * Clean a button text (accept, refuse, Update consent). Empty → the default text.
	 *
	 * @param string $field 'accept_text', 'refuse_text' or 'revoke_text'
	 * @param string $value Unslashed value
	 * @return string
	 */
	public function sanitize_button_text( $field, $value ) {
		$value = sanitize_text_field( $value );

		return $value === '' ? Cookie_Notice()->defaults['general'][$field] : $value;
	}

	/**
	 * Clean the custom script code: trim, then keep only the tags allowed for its place.
	 *
	 * @param string $value Unslashed (or WAF-decoded) value — never unslash it again
	 * @param string $location 'body' or 'head'
	 * @return string
	 */
	public function sanitize_refuse_code( $value, $location ) {
		return wp_kses( trim( $value ), Cookie_Notice()->get_allowed_html( $location ) );
	}

	/**
	 * Clean the button CSS class list: each class through sanitize_html_class, duplicates
	 * dropped. Nothing valid left from several classes → the default.
	 *
	 * @param string $value Unslashed value
	 * @return string
	 */
	public function sanitize_css_class( $value ) {
		$value = trim( $value );

		if ( $value === '' )
			return $value;

		// single class
		if ( strpos( $value, ' ' ) === false )
			return sanitize_html_class( $value );

		// get unique valid html classes
		$classes = array_unique( array_filter( array_map( 'sanitize_html_class', explode( ' ', $value ) ) ) );

		return ! empty( $classes ) ? implode( ' ', $classes ) : Cookie_Notice()->defaults['general']['css_class'];
	}

	/**
	 * AMP support can be on only while the AMP plugin is active.
	 *
	 * @param bool $enabled
	 * @return bool
	 */
	public function sanitize_amp_support( $enabled ) {
		return $enabled && cn_is_plugin_active( 'amp' );
	}

	/**
	 * Caching compatibility can be on only while a supported caching plugin is active.
	 *
	 * @param bool $enabled
	 * @return bool
	 */
	public function sanitize_caching_compatibility( $enabled ) {
		// get active caching plugins
		$active_plugins = cn_get_active_caching_plugins();

		return $enabled && ! empty( $active_plugins );
	}

	/**
	 * The plugin options as the React admin sees them: the general row, with the STORED
	 * Autoblocking preference instead of the over-quota-forced runtime value set_status()
	 * leaves in $cn->options. The React toggle shows what the admin chose and a save reply
	 * reports the stored row, so the two agree and no reply flips the toggle.
	 *
	 * One builder for the page bootstrap (cnReactData.options) and its re-read
	 * (react-admin-ajax.php get_plugin_options()), so both expose exactly the same fields.
	 *
	 * The row is read here, by scope, and NOT taken from $cn->options: the constructor picks
	 * that row by request type, so on a network-activated multisite with global_override on
	 * the settings PAGE of a subsite loads the site row while admin-ajax.php loads the
	 * NETWORK row (network app_id / app_key included). Taken from $cn->options, the re-read
	 * handed a subsite screen the network's settings. Scope here is is_network_admin() —
	 * the network admin screen, or an AJAX cn_network claim vetted by
	 * Cookie_Notice::enforce_network_scope() — and the site row otherwise, which is what
	 * the settings page loads in every case.
	 *
	 * Read from the database, app_blocking is the stored preference: the Free-plan quota
	 * force only ever lands in memory, and preserve_app_blocking_preference() keeps it out
	 * of the row.
	 *
	 * For the same reason app_id is the STORED id, so under CN_DEV_MODE the ?cn_tier
	 * override (Cookie_Notice::maybe_apply_dev_tier_override(), in memory only) does not
	 * reach cnReactData.options.app_id — the bootstrap must equal its re-read. Its faked id
	 * is the top-level cnReactData.app_id (identical to options.app_id in production); React
	 * code that wants the tier's app identity reads that, the connection forms the stored id.
	 *
	 * @return array
	 */
	public function react_options() {
		$cn  = Cookie_Notice();
		$row = Cookie_Notice_Store::get( 'cookie_notice_options', $cn->defaults['general'], $cn->is_network_admin() );

		// The same merge the constructor applies to the row it loads.
		$options = $cn->multi_array_merge( $cn->defaults['general'], is_array( $row ) ? $row : [] );

		if ( ! isset( $options['see_more_opt']['sync'] ) )
			$options['see_more_opt']['sync'] = $cn->defaults['general']['see_more_opt']['sync'];

		$options['app_blocking'] = ! empty( $options['app_blocking'] );

		return $options;
	}

	/**
	 * Is Cookie Compliance active — will the front end print the widget that does the
	 * blocking? The same test as Cookie_Notice_Frontend::early_init(): below 'active'
	 * ('' or 'pending', e.g. after a pull the platform refused) no script is held, whatever
	 * the blocking switches say. For the React admin's blocking claim: the page bootstrap
	 * (cnReactData.complianceActive) and every reply that carries blocking_paused.
	 *
	 * get_status() reads the in-memory status data, which get_app_config() refreshes
	 * (set_status_data()) after it writes, so a reply built after a pull reports the status
	 * that pull left.
	 *
	 * @return bool
	 */
	public function compliance_active() {
		return Cookie_Notice()->get_status() === 'active';
	}

	/**
	 * Cookie expiry choices for the React admin, from the filtered (cn_cookie_expiry) list
	 * the legacy screen and both saves use.
	 *
	 * @return array [ [ 'value' => key, 'label' => label ], ... ]
	 */
	public function get_expiry_options() {
		$options = [];

		foreach ( $this->times as $key => $time ) {
			$options[] = [ 'value' => (string) $key, 'label' => $time[0] ];
		}

		return $options;
	}

	/**
	 * Point the WordPress privacy policy page at the banner's policy page, when synced.
	 *
	 * @param array $options Full (merged) options
	 * @return void
	 */
	public function sync_privacy_policy_page( $options ) {
		if ( $options['see_more_opt']['link_type'] === 'page' && $options['see_more_opt']['sync'] )
			update_option( 'wp_page_for_privacy_policy', $options['see_more_opt']['id'] );
	}

	/**
	 * Link position "message": the policy link lives in the message, as a shortcode.
	 *
	 * @param array $options Full (merged) options
	 * @return array
	 */
	public function append_policy_link_shortcode( $options ) {
		if ( $options['see_more'] && $options['link_position'] === 'message' && strpos( $options['message_text'], '[cookies_policy_link' ) === false )
			$options['message_text'] .= ' [cookies_policy_link]';

		return $options;
	}

	/**
	 * Register the saved texts with WPML (>= 3.2) for translation.
	 *
	 * @param array $options Full (merged) options
	 * @return void
	 */
	public function register_wpml_option_strings( $options ) {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) || ! version_compare( ICL_SITEPRESS_VERSION, '3.2', '>=' ) )
			return;

		do_action( 'wpml_register_single_string', 'Cookie Notice', 'Message in the notice', $options['message_text'] );
		do_action( 'wpml_register_single_string', 'Cookie Notice', 'Button text', $options['accept_text'] );
		do_action( 'wpml_register_single_string', 'Cookie Notice', 'Refuse button text', $options['refuse_text'] );
		do_action( 'wpml_register_single_string', 'Cookie Notice', 'Revoke message text', $options['revoke_message_text'] );
		do_action( 'wpml_register_single_string', 'Cookie Notice', 'Revoke button text', $options['revoke_text'] );
		do_action( 'wpml_register_single_string', 'Cookie Notice', 'Privacy policy text', $options['see_more_opt']['text'] );

		if ( $options['see_more_opt']['link_type'] === 'custom' )
			do_action( 'wpml_register_single_string', 'Cookie Notice', 'Custom link', $options['see_more_opt']['link'] );
	}

	/**
	 * Tell caching plugins (and anything else listening) that the settings changed.
	 *
	 * @param array $options
	 * @return void
	 */
	public function configuration_updated( $options ) {
		do_action( 'cn_configuration_updated', 'settings', $options );
	}

	/**
	 * Write cookie_notice_options for the React admin and fire cn_configuration_updated
	 * exactly once. The write still passes through sanitize_option(); validate_options()
	 * steps aside for it (see $internal_write).
	 *
	 * @param array $options Full options row
	 * @param bool $network Network row (true) or site row (false)
	 * @return void
	 */
	public function store_options( $options, $network ) {
		$this->internal_write = true;

		try {
			if ( $network )
				update_site_option( 'cookie_notice_options', $options );
			else
				update_option( 'cookie_notice_options', $options );
		} finally {
			$this->internal_write = false;
		}

		$this->configuration_updated( $options );
	}

	/**
	 * Write only $changes to cookie_notice_options for the React admin's save and fire
	 * cn_configuration_updated exactly once, as store_options() does.
	 *
	 * The row is read fresh and only $changes go over it
	 * (Cookie_Notice::update_general_option_keys()), so a setting another request saved
	 * after this save request started (when its cached copy was loaded) survives. Stored
	 * keys that are not plugin-owned fields are removed from that fresh row (#2264), as
	 * the React save always has.
	 *
	 * @param array $changes Option keys (nested settings by leaf) to set
	 * @param bool $network Network row (true) or site row (false)
	 * @return void
	 */
	public function store_option_keys( array $changes, $network ) {
		$this->internal_write = true;

		try {
			$row = Cookie_Notice()->update_general_option_keys( $changes, $network, true, true );
		} finally {
			$this->internal_write = false;
		}

		$this->configuration_updated( $row );
	}

	/**
	 * The options row a React "Reset to defaults" writes: the defaults, keeping the
	 * connection, the admin UI mode, the network switches, the Protection-tab blocking
	 * switches (frozen while the Free-plan limit is exceeded, #2272) and the plugin's own
	 * notice bookkeeping. Legacy's reset (validate_options) instead disconnects the site
	 * and returns it to the legacy screen; the React one does not (decision 2026-10-05).
	 *
	 * @param array $current The stored row
	 * @return array
	 */
	public function get_reset_options( $current ) {
		$options = Cookie_Notice()->defaults['general'];

		foreach ( self::$reset_keeps as $key ) {
			if ( array_key_exists( $key, $current ) )
				$options[$key] = $current[$key];
		}

		return $options;
	}

	/**
	 * Keys a React reset keeps from the stored row. See get_reset_options().
	 *
	 * @var string[]
	 */
	public static $reset_keeps = [
		'app_id',
		'app_key',
		'ui_mode',
		'global_override',
		'global_cookie',
		'app_blocking',
		'app_blocking_engine',
		'review_notice',
		'review_notice_delay',
		'update_version',
		'update_notice',
		'update_notice_diss',
		'update_delay_date',
		'update_threshold_date',
		'csp_notice'
	];
	// ── End shared save rules ─────────────────────────────────────────────

	/**
	 * Validate options.
	 *
	 * @param array $input
	 *
	 * @return array
	 */
	public function validate_options( $input ) {
		// written by store_options() / store_option_keys(), which fire cn_configuration_updated themselves
		if ( $this->internal_write )
			return $input;

		if ( ! current_user_can( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) ) )
			return $input;

		// get main instance
		$cn = Cookie_Notice();

		$is_network = $cn->is_network_admin();

		if ( isset( $_POST['save_cookie_notice_options'] ) ) {
			// app id
			$input['app_id'] = isset( $input['app_id'] ) ? sanitize_key( $input['app_id'] ) : $cn->defaults['general']['app_id'];

			// app key
			$input['app_key'] = isset( $input['app_key'] ) ? sanitize_key( $input['app_key'] ) : $cn->defaults['general']['app_key'];

			// set app status
			if ( ! empty( $input['app_id'] ) && ! empty( $input['app_key'] ) ) {
				$app_data = $cn->welcome_api->get_app_config( $input['app_id'], true, false );

				if ( is_array( $app_data ) && isset( $app_data['status'] ) && $cn->check_status( $app_data['status'] ) === 'active' && $cn->options['general']['app_id'] !== $input['app_id'] ) {
					// get_app_analytics requires fresh app data
					$this->analytics_app_data = [
						'id'	=> $input['app_id'],
						'key'	=> $input['app_key']
					];

					// update analytics data
					$cn->welcome_api->get_app_analytics( $input['app_id'], true, false );

					$this->analytics_app_data = [];
				}
			} else {
				if ( $is_network )
					update_site_option( 'cookie_notice_status', $cn->defaults['data'] );
				else
					update_option( 'cookie_notice_status', $cn->defaults['data'] );

				// …and the engine it ran: reconnecting, even to the same App ID, is a first connect.
				$cn->forget_banner_engine( $is_network );
			}

			// app blocking — checkbox: absent from POST when unchecked.
			// If not submitted at all, preserve the existing DB value instead of defaulting
			// to false. This prevents a legacy form save from zeroing out a value that was
			// set via the React path (field partition: app_blocking is plugin-owned but the
			// legacy form does not always render the checkbox, e.g. when connected). See #2272.
			if ( isset( $input['app_blocking_rendered'] ) ) {
				// Checkbox was rendered and enabled — honour the submitted value (absent = unchecked = false).
				$input['app_blocking'] = isset( $input['app_blocking'] ) && ! $cn->threshold_exceeded();
			} else {
				// Sentinel absent, which means one of two things. Either the field was
				// never rendered — the Cookie Consent Settings section is registered only
				// while the compliance status is active — or the quota suppressed it,
				// since cn_app_blocking() emits the sentinel only when the threshold is
				// not exceeded. Preserve the DB value via the preservation loop below.
				//
				// Not the network-admin or global_override screens: cn-options-disabled
				// is styling only ( opacity + pointer-events, css/admin.css:105-115 ), so
				// those forms render the sentinel and submit it like any other.
				unset( $input['app_blocking'] );
			}
			unset( $input['app_blocking_rendered'] );

			// ── Begin app_blocking_engine sentinel (DEC-012, #2272 pattern)
			//
			// Same contract as app_blocking above, and for the same reason: without a
			// sentinel this control is either inert or destructive, and destructive is
			// much the worse of the two here — blockingEngine: false is absolute in the
			// widget, with no post-boot writer to undo it.
			//
			// The one difference is the predicate. cn_app_blocking_engine() has no
			// disabled() condition (the Free-plan quota caps the posture, not the
			// engine), so it emits its sentinel unconditionally. A missing sentinel can
			// therefore only mean the field was not rendered at all: the Cookie Consent
			// Settings section is registered only while the compliance status is active,
			// and any future form that omits the field lands here too. Preserve the
			// stored value via the #2153 preservation loop in those cases.
			if ( isset( $input['app_blocking_engine_rendered'] ) ) {
				// Rendered and enabled — honour the submitted value (absent = unchecked = false).
				$input['app_blocking_engine'] = isset( $input['app_blocking_engine'] );
			} else {
				// Not rendered — preserve the DB value.
				unset( $input['app_blocking_engine'] );
			}
			unset( $input['app_blocking_engine_rendered'] );
			// ── End app_blocking_engine sentinel (DEC-012, #2272 pattern)

			// excluded script handles
			$input['excluded_handles'] = isset( $input['excluded_handles'] )
				? array_values( array_filter( array_map( 'sanitize_text_field', explode( "\n", $input['excluded_handles'] ) ) ) )
				: $cn->defaults['general']['excluded_handles'];

			// conditional display
			$input['conditional_active'] = isset( $input['conditional_active'] );

			$input['conditional_display'] = isset( $input['conditional_display'] ) ? sanitize_key( $input['conditional_display'] ) : $cn->defaults['general']['conditional_display'];

			if ( ! in_array( $input['conditional_display'], array_keys( $this->conditional_display_types ), true ) )
				$input['conditional_display'] = $cn->defaults['general']['conditional_display'];

			if ( ! empty( $input['conditional_rules'] ) && is_array( $input['conditional_rules'] ) ) {
				$group_id = $rule_id = 1;
				$rules = [];

				foreach ( $input['conditional_rules'] as $group_number => $group ) {
					// skips template data or empty groups
					if ( (int) $group_number <= 0 || empty( $group ) )
						continue;

					foreach ( $group as $rule ) {
						$param = sanitize_key( $rule['param'] );
						$operator = sanitize_key( $rule['operator'] );

						// do not sanitize value for taxonomy archive
						if ( $param === 'taxonomy_archive' )
							$value = $rule['value'];
						else
							$value = sanitize_key( $rule['value'] );

						if ( $this->check_rule( $param, $operator, $value ) ) {
							$rules[$group_id][$rule_id++] = [
								'param'		=> $param,
								'operator'	=> $operator,
								'value'		=> $value
							];
						}
					}

					$rule_id = 1;
					$group_id++;
				}

				$input['conditional_rules'] = $rules;
			} else
				$input['conditional_rules'] = [];

			// bot detection
			$input['bot_detection'] = isset( $input['bot_detection'] );

			// debug mode
			$input['debug_mode'] = isset( $input['debug_mode'] );

			// ui_mode is not processed here. The admin's switch is maybe_switch_ui_mode():
			// a confirmation page, then a POST with a nonce bound to the target mode. This
			// does not strip it: a cookie_notice_options[ui_mode] posted to this form stays
			// in $input as sent, and the #2153 preservation loop carries the stored value
			// through only when the key is absent. See #2155.

			// amp support
			$input['amp_support'] = $this->sanitize_amp_support( isset( $input['amp_support'] ) );

			// caching compatibility
			$input['caching_compatibility'] = $this->sanitize_caching_compatibility( isset( $input['caching_compatibility'] ) );

			// wp consent api — stored preference; not gated on function_exists( 'wp_has_consent' )
			// so an admin's intent survives WPCA being temporarily inactive.
			$input['wp_consent_api'] = isset( $input['wp_consent_api'] );

			// csp_notice is refreshed on render and on-demand via refresh_csp_notice();
			// preserve the existing stored value here so the legacy save path doesn't
			// stomp it with a stale read.
			$input['csp_notice'] = ! empty( $cn->options['general']['csp_notice'] );

			// position
			if ( isset( $input['position'] ) ) {
				$input['position'] = sanitize_key( $input['position'] );

				if ( ! array_key_exists( $input['position'], $this->positions ) )
					$input['position'] = $cn->defaults['general']['position'];
			} else
				$input['position'] = $cn->defaults['general']['position'];

			// text color
			if ( isset( $input['colors']['text'] ) ) {
				$input['colors']['text'] = sanitize_hex_color( $input['colors']['text'] );

				if ( empty( $input['colors']['text'] ) )
					$input['colors']['text'] = $cn->defaults['general']['colors']['text'];
			} else
				$input['colors']['text'] = $cn->defaults['general']['colors']['text'];

			// button color
			if ( isset( $input['colors']['button'] ) ) {
				$input['colors']['button'] = sanitize_hex_color( $input['colors']['button'] );

				if ( empty( $input['colors']['button'] ) )
					$input['colors']['button'] = $cn->defaults['general']['colors']['button'];
			} else
				$input['colors']['button'] = $cn->defaults['general']['colors']['button'];

			// bar color
			if ( isset( $input['colors']['bar'] ) ) {
				$input['colors']['bar'] = sanitize_hex_color( $input['colors']['bar'] );

				if ( empty( $input['colors']['bar'] ) )
					$input['colors']['bar'] = $cn->defaults['general']['colors']['bar'];
			} else
				$input['colors']['bar'] = $cn->defaults['general']['colors']['bar'];

			// bar opacity
			$input['colors']['bar_opacity'] = isset( $input['colors']['bar_opacity'] ) ? (int) $input['colors']['bar_opacity'] : $cn->defaults['general']['colors']['bar_opacity'];

			if ( $input['colors']['bar_opacity'] < 50 || $input['colors']['bar_opacity'] > 100 )
				$input['colors']['bar_opacity'] = $cn->defaults['general']['colors']['bar_opacity'];

			// message text
			if ( isset( $input['message_text'] ) )
				$input['message_text'] = $this->sanitize_message_text( 'message_text', $input['message_text'] );
			else
				$input['message_text'] = $cn->defaults['general']['message_text'];

			// accept button text
			if ( isset( $input['accept_text'] ) )
				$input['accept_text'] = $this->sanitize_button_text( 'accept_text', $input['accept_text'] );
			else
				$input['accept_text'] = $cn->defaults['general']['accept_text'];

			// refuse button text
			if ( isset( $input['refuse_text'] ) )
				$input['refuse_text'] = $this->sanitize_button_text( 'refuse_text', $input['refuse_text'] );
			else
				$input['refuse_text'] = $cn->defaults['general']['refuse_text'];

			// revoke message text
			if ( isset( $input['revoke_message_text'] ) )
				$input['revoke_message_text'] = $this->sanitize_message_text( 'revoke_message_text', $input['revoke_message_text'] );
			else
				$input['revoke_message_text'] = $cn->defaults['general']['revoke_message_text'];

			// revoke button text
			if ( isset( $input['revoke_text'] ) )
				$input['revoke_text'] = $this->sanitize_button_text( 'revoke_text', $input['revoke_text'] );
			else
				$input['revoke_text'] = $cn->defaults['general']['revoke_text'];

			// refuse consent
			$input['refuse_opt'] = isset( $input['refuse_opt'] );

			// revoke consent
			$input['revoke_cookies'] = isset( $input['revoke_cookies'] );

			// revoke consent type
			if ( isset( $input['revoke_cookies_opt'] ) ) {
				$input['revoke_cookies_opt'] = sanitize_key( $input['revoke_cookies_opt'] );

				if ( ! array_key_exists( $input['revoke_cookies_opt'], $this->revoke_opts ) )
					$input['revoke_cookies_opt'] = $cn->defaults['general']['revoke_cookies_opt'];
			} else
				$input['revoke_cookies_opt'] = $cn->defaults['general']['revoke_cookies_opt'];

			// body refuse code
			if ( isset( $input['refuse_code'] ) )
				$input['refuse_code'] = $this->sanitize_refuse_code( $input['refuse_code'], 'body' );
			else
				$input['refuse_code'] = $cn->defaults['general']['refuse_code'];

			// head refuse code
			if ( isset( $input['refuse_code_head'] ) )
				$input['refuse_code_head'] = $this->sanitize_refuse_code( $input['refuse_code_head'], 'head' );
			else
				$input['refuse_code_head'] = $cn->defaults['general']['refuse_code_head'];

			// css button class(es)
			if ( isset( $input['css_class'] ) )
				$input['css_class'] = $this->sanitize_css_class( $input['css_class'] );
			else
				$input['css_class'] = $cn->defaults['general']['css_class'];

			// accepted expiry
			if ( isset( $input['time'] ) ) {
				$input['time'] = sanitize_key( $input['time'] );

				if ( ! array_key_exists( $input['time'], $this->times ) )
					$input['time'] = $cn->defaults['general']['time'];
			} else
				$input['time'] = $cn->defaults['general']['time'];

			// rejected expiry
			if ( isset( $input['time_rejected'] ) ) {
				$input['time_rejected'] = sanitize_key( $input['time_rejected'] );

				if ( ! array_key_exists( $input['time_rejected'], $this->times ) )
					$input['time_rejected'] = $cn->defaults['general']['time_rejected'];
			} else
				$input['time_rejected'] = $cn->defaults['general']['time_rejected'];

			// script placement
			if ( isset( $input['script_placement'] ) ) {
				$input['script_placement'] = sanitize_key( $input['script_placement'] );

				if ( ! array_key_exists( $input['script_placement'], $this->script_placements ) )
					$input['script_placement'] = $cn->defaults['general']['script_placement'];
			} else
				$input['script_placement'] = $cn->defaults['general']['script_placement'];

			// hide effect
			if ( isset( $input['hide_effect'] ) ) {
				$input['hide_effect'] = sanitize_key( $input['hide_effect'] );

				if ( ! array_key_exists( $input['hide_effect'], $this->effects ) )
					$input['hide_effect'] = $cn->defaults['general']['hide_effect'];
			} else
				$input['hide_effect'] = $cn->defaults['general']['hide_effect'];

			// reloading
			$input['redirection'] = isset( $input['redirection'] );

			// on scroll
			$input['on_scroll'] = isset( $input['on_scroll'] );

			// on scroll offset
			$input['on_scroll_offset'] = isset( $input['on_scroll_offset'] ) ? (int) $input['on_scroll_offset'] : $cn->defaults['general']['on_scroll_offset'];

			if ( $input['on_scroll_offset'] < 0 )
				$input['on_scroll_offset'] = 0;

			// on click
			$input['on_click'] = isset( $input['on_click'] );

			// deactivation
			$input['deactivation_delete'] = isset( $input['deactivation_delete'] );

			// privacy policy
			$input['see_more'] = isset( $input['see_more'] );

			// privacy policy link text
			if ( isset( $input['see_more_opt']['text'] ) ) {
				$input['see_more_opt']['text'] = sanitize_text_field( $input['see_more_opt']['text'] );

				if ( $input['see_more_opt']['text'] === '' )
					$input['see_more_opt']['text'] = $cn->defaults['general']['see_more_opt']['text'];
			} else
				$input['see_more_opt']['text'] = $cn->defaults['general']['see_more_opt']['text'];

			// privacy policy link type
			if ( isset( $input['see_more_opt']['link_type'] ) ) {
				$input['see_more_opt']['link_type'] = sanitize_key( $input['see_more_opt']['link_type'] );

				if ( ! array_key_exists( $input['see_more_opt']['link_type'], $this->links ) )
					$input['see_more_opt']['link_type'] = $cn->defaults['general']['see_more_opt']['link_type'];
			} else
				$input['see_more_opt']['link_type'] = $cn->defaults['general']['see_more_opt']['link_type'];

			if ( $input['see_more_opt']['link_type'] === 'custom' )
				$input['see_more_opt']['link'] = $input['see_more'] && isset( $input['see_more_opt']['link'] ) ? esc_url_raw( $input['see_more_opt']['link'] ) : '';
			elseif ( $input['see_more_opt']['link_type'] === 'page' ) {
				$input['see_more_opt']['id'] = $input['see_more'] && isset( $input['see_more_opt']['id'] ) ? (int) $input['see_more_opt']['id'] : 0;
				$input['see_more_opt']['sync'] = isset( $input['see_more_opt']['sync'] );

				$this->sync_privacy_policy_page( $input );
			}

			// privacy policy link target
			if ( isset( $input['link_target'] ) ) {
				$input['link_target'] = sanitize_key( $input['link_target'] );

				if ( ! in_array( $input['link_target'], $this->link_targets, true ) )
					$input['link_target'] = $cn->defaults['general']['link_target'];
			} else
				$input['link_target'] = $cn->defaults['general']['link_target'];

			// policy policy link position
			if ( isset( $input['link_position'] ) ) {
				$input['link_position'] = sanitize_key( $input['link_position'] );

				if ( ! array_key_exists( $input['link_position'], $this->link_positions ) )
					$input['link_position'] = $cn->defaults['general']['link_position'];
			} else
				$input['link_position'] = $cn->defaults['general']['link_position'];

			// message link position?
			$input = $this->append_policy_link_shortcode( $input );

			// notice data
			$input['update_version'] = $cn->options['general']['update_version'];
			$input['update_notice'] = $cn->options['general']['update_notice'];
			$input['review_notice'] = $cn->options['general']['review_notice'];
			$input['review_notice_delay'] = $cn->options['general']['review_notice_delay'];

			$input['translate'] = false;

			// WPML >= 3.2
			$this->register_wpml_option_strings( $input );

			// Preserve any keys in the current DB options that validate_options() does not
			// explicitly process (e.g. displayType, ui_mode, update_delay_date,
			// update_threshold_date, update_notice_diss). Without this, WordPress's register_setting()
			// replaces the entire cookie_notice_options row with $input, silently dropping these fields
			// on every legacy form save. See #2153.
			// Read through the option API. Not a fresh database read: it returns the row as this
			// request loaded it (object cache), so React changes another tab saved after this
			// form was rendered are kept, but one landing while this request runs is not seen.
			// See #2181.
			$current_db_options = (array) Cookie_Notice_Store::get( 'cookie_notice_options', [], $is_network );
			foreach ( $current_db_options as $key => $value ) {
				if ( ! array_key_exists( $key, $input ) ) {
					$input[ $key ] = $value;
				}
			}

			add_settings_error( 'cn_cookie_notice_options', 'save_cookie_notice_options', esc_html__( 'Settings saved.', 'cookie-notice' ), 'updated' );
		} elseif ( isset( $_POST['reset_cookie_notice_options'] ) ) {
			$input = $cn->defaults['general'];

			add_settings_error( 'cn_cookie_notice_options', 'reset_cookie_notice_options', esc_html__( 'Settings restored to defaults.', 'cookie-notice' ), 'updated' );

			// set app data
			Cookie_Notice_Store::set( 'cookie_notice_status', $cn->defaults['data'], $is_network );

			// …and the engine it ran: reconnecting, even to the same App ID, is a first connect.
			$cn->forget_banner_engine( $is_network );
		}

		$this->configuration_updated( $input );

		return $input;
	}

	/**
	 * Validate network options.
	 *
	 * @return void
	 */
	public function validate_network_options() {
		if ( ! current_user_can( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) ) )
			return;

		// get main instance
		$cn = Cookie_Notice();

		// global network page?
		if ( $cn->is_network_admin() && isset( $_POST['cn-network-settings'] ) ) {
			// This branch update_site_option()s the whole network row below, from
			// $_POST['cookie_notice_options'] via validate_options() — app_id and app_key
			// included. The capability check above is the filtered manage_options, which
			// every subsite administrator holds, so it cannot be what guards this.
			//
			// A notice rather than a bare return: a settings form that silently does nothing
			// reads as a bug, and the obvious "fix" for a bug like that is to loosen the gate
			// again. A notice rather than wp_die(): this runs while the screen is being
			// assembled, so the refusal can be explained in place instead of on a white page.
			if ( ! $cn->can_write_at_scope( true ) ) {
				$cn->deny_network_scope_notice();

				return;
			}

			// network settings
			if ( ! empty( $_POST['cookie_notice_options'] ) && check_admin_referer( 'cookie_notice_options-options', '_wpnonce' ) !== false ) {
				if ( isset( $_POST['save_cookie_notice_options'] ) ) {
					// need to force it early for get_app_config and get_app_analytics
					$cn->network_options['general']['global_override'] = true;

					// validate options
					$data = $this->validate_options( $_POST['cookie_notice_options'] );

					// check network settings
					$data['global_override'] = isset( $_POST['cookie_notice_options']['global_override'] );
					$data['global_cookie'] = isset( $_POST['cookie_notice_options']['global_cookie'] );
					$data['update_notice_diss'] = $cn->options['general']['update_notice_diss'];

					// set real value
					$cn->network_options['general']['global_override'] = $data['global_override'];

					if ( $data['global_override'] && ! $cn->options['general']['update_notice_diss'] )
						$data['update_notice'] = true;
					else
						$data['update_notice'] = false;

					// update database
					update_site_option( 'cookie_notice_options', $data );

					// update settings
					$cn->options['general'] = $cn->network_options['general'] = $cn->multi_array_merge( $cn->defaults['general'], get_site_option( 'cookie_notice_options', $cn->defaults['general'] ) );
				} elseif ( isset( $_POST['reset_cookie_notice_options'] ) ) {
					$cn->defaults['general']['update_notice'] = false;
					$cn->defaults['general']['update_notice_diss'] = false;

					// silent options validation
					$this->validate_options( $cn->defaults['general'] );

					// update database
					update_site_option( 'cookie_notice_options', $cn->defaults['general'] );

					// update settings
					$cn->options['general'] = $cn->network_options['general'] = $cn->defaults['general'];
				}
			}

			// update status of cookie compliance
			$cn->set_status_data();
		}
	}

	/**
	 * Load scripts and styles - admin.
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts( $page ) {
		// get main instance
		$cn = Cookie_Notice();

		if ( $page === 'toplevel_page_cookie-notice' ) {
			$ui_mode = $cn->options['general']['ui_mode'];

			wp_enqueue_script( 'cookie-notice-admin', COOKIE_NOTICE_URL . '/js/admin' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.js', [ 'jquery', 'wp-color-picker' ], $cn->defaults['version'] );

			// prepare script data
			$script_data = [
				'ajaxURL'					=> admin_url( 'admin-ajax.php' ),
				'nonce'						=> wp_create_nonce( 'cn-purge-cache' ),
				'nonceSyncConfig'			=> wp_create_nonce( 'cookie-notice-welcome' ),
				'nonceConditional'			=> wp_create_nonce( 'cn-get-group-values' ),
				'nonceCookieConsentLogs'	=> wp_create_nonce( 'cn-get-cookie-consent-logs' ),
				'noncePrivacyConsentLogs'	=> wp_create_nonce( 'cn-get-privacy-consent-logs' ),
				'noncePrivacyConsent'		=> wp_create_nonce( 'cn-privacy-consent-set-form-status' ),
				'consentLogsTemplate'		=> $cn->consent_logs->get_single_row_template(),
				'consentLogsError'			=> $cn->consent_logs->get_error_template(),
				'settingsTab'				=> $this->current_tab,
				'settingsSection'			=> $this->current_section,
				'network'					=> $cn->is_network_admin(),
				'resetToDefaults'			=> esc_html__( 'Are you sure you want to reset these settings to defaults?', 'cookie-notice' ),
				'privacyConsentSources'		=> $cn->privacy_consent->get_sources()
			];

			wp_add_inline_script( 'cookie-notice-admin', 'var cnArgs = ' . wp_json_encode( $script_data ) . ";\n", 'before' );

			wp_enqueue_style( 'wp-color-picker' );

			// React admin bundle.
			// In CN_DEV_MODE use the file's mtime as the version so every deploy
			// busts the browser cache automatically (the plugin version string only
			// changes on official releases, causing stale bundles during testing).
			$react_js_path  = COOKIE_NOTICE_PATH . 'assets/react-admin/' . Cookie_Notice::REACT_ADMIN_BUNDLE_BASENAME;
			$react_css_path = COOKIE_NOTICE_PATH . 'assets/react-admin/cn-admin-react.css';
			$react_ver      = ( defined( 'CN_DEV_MODE' ) && CN_DEV_MODE && file_exists( $react_js_path ) )
				? filemtime( $react_js_path )
				: $cn->defaults['version'];

			if ( $ui_mode === 'react' ) {
				// 'wp-i18n' is a real dependency, not a convenience: src/admin-react/i18n.js
				// reads window.wp.i18n at call time, and wp_set_script_translations() below
				// loads this locale's strings into that same instance. Drop the dependency and
				// every label silently falls back to English with nothing in the console.
				wp_enqueue_script(
					Cookie_Notice::REACT_ADMIN_HANDLE,
					$cn->get_url( 'react-admin' ),
					[ 'wp-i18n' ],
					$react_ver,
					true
				);

				// Point WordPress at the JSON translation files for this handle. Core looks for
				// languages/cookie-notice-<locale>-<md5 of the bundle's relative path>.json,
				// generated by `wp i18n make-json` at release time from the .po files.
				wp_set_script_translations(
					Cookie_Notice::REACT_ADMIN_HANDLE,
					'cookie-notice',
					COOKIE_NOTICE_PATH . 'languages'
				);

				wp_enqueue_style(
					Cookie_Notice::REACT_ADMIN_HANDLE,
					COOKIE_NOTICE_URL . '/assets/react-admin/cn-admin-react.css',
					[],
					$react_ver
				);

				// Preload the React bundle so the browser starts fetching it while
				// parsing <head>, rather than discovering it at the footer <script> tag.
				// On a cold cache (e.g. first activation) this moves the download
				// earlier in the waterfall and reduces the blank-shell window.
				$react_preload_url = esc_url( $cn->get_url( 'react-admin' ) . '?ver=' . rawurlencode( $react_ver ) );
				add_action( 'admin_head', static function() use ( $react_preload_url ) {
					echo '<link rel="preload" as="script" href="' . $react_preload_url . '">' . "\n";
				} );

				// Stamp optimizer/CDN exclusion attributes on the React admin script
				// (main bundle + wp_localize_script inline 'before' block) so JS minifiers,
				// concatenators, deferers, and CDN script-rewriters skip our IIFE bundle.
				// Mirrors the banner-script protection in includes/frontend.php (commit 765e96b).
				// Without this, Cloudflare Rocket Loader (which processes admin pages) and
				// any "minify admin" toggles in optimizer plugins can break the bundle and
				// white-screen the admin page.
				add_filter( 'script_loader_tag', [ $this, 'add_react_admin_optimizer_attrs' ], 10, 2 );

				// NOTE: we deliberately do NOT pull fresh config from the Designer API
				// here. This runs before any output, and request() allows a 60s timeout
				// (welcome-api.php) so slow customer networks can complete the fetch —
				// on the render path that means a blank admin page for up to a minute,
				// and on hosts with a 30s max_execution_time the request dies before the
				// pull can finish. The generous timeout that exists to help slow networks
				// was defeating them.
				//
				// The React app performs the same refresh asynchronously on mount (see
				// App.jsx -> apiRequest('sync_config') + refetchConfig), so the page
				// renders instantly from cached options and the fetch keeps its full 60s
				// because nothing is waiting on it. Admins still get live data on every
				// visit without touching "Pull Configuration".

				// ── Begin React notification rule slot filter ────────────────
				// Read notification rules from shared JSON config, and ship React only
				// the slots React renders.
				//
				// notifications.json holds two DISJOINT rule languages under one roof,
				// told apart by `slot`. The topBar and sidebar rules condition on
				// tier / usagePercent / thresholdExceeded, which is what
				// useNotifications.js implements. The wpDashboard rules condition on
				// `state`, a key that evaluator does not implement at all — and an
				// unknown condition key there is not rejected, it is simply never
				// checked, so every wpDashboard rule passes every guard and the
				// max-priority fold hands back whichever has the highest number.
				//
				// Today that is dashboard-free-over, "Protection paused — limit
				// reached", resolved for every site on earth including a healthy Pro
				// one. It is inert only because the hook happens to return topBar and
				// sidebar and nothing reads the third key. That is one accidental
				// read away from being live, and this file was handing it the
				// ammunition by shipping all rules wholesale.
				//
				// Filtering at the wire is the fix that matches the real structure:
				// the PHP evaluator already narrows to wpDashboard on its side, so
				// after this each evaluator sees only the language it speaks. Adding
				// dashboard states — as the blocking-gap work does — is then no longer
				// a way to change what React resolves.
				$cn_rules_json = file_get_contents( COOKIE_NOTICE_PATH . 'includes/notifications.json' );
				$cn_rules_data = $cn_rules_json !== false ? json_decode( $cn_rules_json, true ) : null;
				$cn_all_rules  = is_array( $cn_rules_data ) ? ( $cn_rules_data['rules'] ?? [] ) : [];

				// ALLOWLIST, not a denylist. `!== 'wpDashboard'` would be fail-open: a
				// fourth slot added to notifications.json would ship to React by
				// default, where useNotifications.js skips condition keys it does not
				// implement and the rule passes every guard again — the same bug, via a
				// name nobody thought to exclude. Naming what React renders means a new
				// slot is inert until someone deliberately adds it here.
				$cn_react_slots = [ 'topBar', 'sidebar' ];

				$cn_notification_rules = array_values( array_filter(
					$cn_all_rules,
					function ( $rule ) use ( $cn_react_slots ) {
						return in_array( $rule['slot'] ?? '', $cn_react_slots, true );
					}
				) );
				// ── End React notification rule slot filter ──────────────────

				wp_localize_script( Cookie_Notice::REACT_ADMIN_HANDLE, Cookie_Notice::REACT_ADMIN_INLINE_KEYWORD, [
					'ajaxURL'            => admin_url( 'admin-ajax.php' ),
					'nonce'              => wp_create_nonce( 'cn_react_nonce' ),
					'welcomeNonce'       => wp_create_nonce( 'cookie-notice-welcome' ),
					'configureNonce'     => wp_create_nonce( 'cn_api_configure' ),
					'registerNonce'      => wp_create_nonce( 'cn_api_register' ),
					'loginNonce'         => wp_create_nonce( 'cn_api_login' ),
					'loginCodeNonce'     => wp_create_nonce( 'cn_api_login_code' ),
					'loginAppNonce'      => wp_create_nonce( 'cn_api_login_app' ),
					'paymentNonce'       => wp_create_nonce( 'cn_api_payment' ),
					'uiMode'             => $ui_mode,
					'network'            => $cn->is_network_admin(),
					// A site under Global Settings Override: settings disabled, no blocking claim.
					'networkOverride'    => $this->network_managed(),
					// Global Cookie works only on a domain-based network (cn_global_cookie()).
					'globalCookieAvailable' => is_multisite() && is_subdomain_install(),
					'status'             => $cn->get_status(),
					'subscription'       => $cn->get_subscription(),
					'app_id'             => $cn->options['general']['app_id'],
					'version'            => $cn->defaults['version'],
					// The stored row with the STORED Autoblocking preference (react_options());
					// blockingPaused says that preference is frozen and not in effect;
					// complianceActive whether the widget that blocks is printed at all.
					'options'            => $this->react_options(),
					'blockingPaused'     => (bool) $cn->threshold_exceeded(),
					'complianceActive'   => $this->compliance_active(),
					// The banner visitors get: engine, style, network-managed (get_banner_summary()).
					'banner'             => $cn->get_banner_summary(),
					// The filtered (cn_cookie_expiry) choices both saves validate against.
					'expiryOptions'      => $this->get_expiry_options(),
					'welcomeUrl'         => cn_get_welcome_url(),
					'siteUrl'            => home_url(),
					'devMode'            => defined( 'CN_DEV_MODE' ) && CN_DEV_MODE && current_user_can( 'manage_options' ),
					// The scope their React writers use (react-admin-ajax.php dismiss_welcome(),
					// complete_setup_wizard()): the network row in the Network Admin, where
					// get_option() would read the MAIN SITE's flag instead.
					'welcomeDismissedAt'      => Cookie_Notice_Store::get( 'cookie_notice_welcome_dismissed', '', $cn->is_network_admin() ),
					'setupWizardComplete'     => (bool) Cookie_Notice_Store::get( 'cookie_notice_setup_wizard_complete', false, $cn->is_network_admin() ),
					// is_network_options(), not is_network_admin() — this row's
					// writers and its other three readers all use it. See the regulations
					// optimistic-write scope note in welcome-api.php. Reading it under
					// is_network_admin() here is what made the split invisible: the law
					// save wrote the same wrong row, so a save-then-reload looked correct.
					'selectedLaws'       => Cookie_Notice_Store::get( 'cookie_notice_app_regulations', [], $cn->is_network_options() ),
					'wpPages'            => array_map( function( $p ) {
						return [ 'id' => $p->ID, 'title' => $p->post_title ];
					}, get_pages( [ 'sort_column' => 'post_title' ] ) ?: [] ),
				'siteLocale'         => get_locale(),
				'detectedPlugins'    => cn_detect_active_plugins(),
					// is_network_options(), not is_network_admin() — same reason as selectedLaws
					// above. cookie_notice_app_design is written by get_app_config() under
					// is_network_options(), so on network-activated multisite with
					// global_override off this read went to the network row the pull never
					// writes, and the React banner designer opened on defaults instead of
					// the customer's own design.
					'bannerDesign'       => Cookie_Notice_Store::get( 'cookie_notice_app_design', [], $cn->is_network_options() ),
				'displayType'        => $cn->options['general']['displayType'] ?? 'floating',
				'appUrl'             => Cookie_Notice()->get_url( 'host' ),
					// Likewise: cookie_notice_app_blocking is a get_app_config() row, so it
					// is read under is_network_options(). Under is_network_admin() this came back
					// empty on override-off multisite and "last synced" rendered blank on a
					// site that syncs normally.
				'lastSynced'         => ( function() use ( $cn ) {
					$blocking = Cookie_Notice_Store::get( 'cookie_notice_app_blocking', [], $cn->is_network_options() );
					return ! empty( $blocking['lastUpdated'] ) ? $blocking['lastUpdated'] : '';
				} )(),
				'purgeNonce'         => wp_create_nonce( 'cn-purge-cache' ),
				'notificationRules'  => $cn_notification_rules,
				'ruleParams'         => array_map( function( $label, $key ) {
					return [ 'value' => $key, 'label' => $label ];
				}, $this->parameters, array_keys( $this->parameters ) ),
				'ruleOperators'      => array_map( function( $label, $key ) {
					return [ 'value' => $key, 'label' => $label ];
				}, $this->operators, array_keys( $this->operators ) ),
				] );
			}
		}

		wp_enqueue_style( 'cookie-notice-admin', COOKIE_NOTICE_URL . '/css/admin' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.css', [], $cn->defaults['version'] );

		if ( $this->current_tab === 'consent-logs' ) {
			// pagination script
			wp_enqueue_script( 'cookie-notice-admin-pagination', COOKIE_NOTICE_URL . '/assets/pagination/pagination.js', [ 'jquery' ], '2.6.0' );
			wp_enqueue_style( 'cookie-notice-admin-pagination', COOKIE_NOTICE_URL . '/assets/pagination/pagination.css', [], '2.6.0' );
		}
	}

	/**
	 * Stamp optimizer/CDN exclusion attributes on the React admin script tags.
	 *
	 * WP's script_loader_tag filter receives the combined output for a handle
	 * (main <script src> tag plus any inline 'before'/'after' blocks added via
	 * wp_localize_script / wp_add_inline_script), so a single regex pass covers
	 * both the bundle and the cnReactData inline block.
	 *
	 * Mirrors banner protection in includes/frontend.php (commit 765e96b).
	 *
	 * @param string $tag    Combined script tag(s) for this handle.
	 * @param string $handle Script handle being filtered.
	 * @return string
	 */
	public function add_react_admin_optimizer_attrs( $tag, $handle ) {
		// React admin asset exclusions — see Cookie_Notice::REACT_ADMIN_*.
		if ( $handle !== Cookie_Notice::REACT_ADMIN_HANDLE )
			return $tag;

		$attrs = Cookie_Notice::optimizer_skip_attrs();

		// Stamp every <script> opening tag in the combined output that doesn't
		// already carry data-cfasync (idempotent if the filter runs twice).
		return preg_replace( '/(<script\b)(?![^>]*\bdata-cfasync\b)/i', '$1' . $attrs, $tag );
	}

	/**
	 * Load admin style inline, for menu icon only.
	 *
	 * @return void
	 */
	public function admin_print_styles() {
		echo '
		<style>
			a.toplevel_page_cookie-notice .wp-menu-image {
				background-image: url(data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiIHN0YW5kYWxvbmU9Im5vIj8+PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj48c3ZnIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCAzMjEgMzIxIiB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHhtbG5zOnNlcmlmPSJodHRwOi8vd3d3LnNlcmlmLmNvbS8iIHN0eWxlPSJmaWxsLXJ1bGU6ZXZlbm9kZDtjbGlwLXJ1bGU6ZXZlbm9kZDtzdHJva2UtbGluZWpvaW46cm91bmQ7c3Ryb2tlLW1pdGVybGltaXQ6MjsiPjxwYXRoIGQ9Ik0zMTcuMjc4LDEzMC40NTFjLTAuODEyLC00LjMwMiAtNC4zMDEsLTcuNTYyIC04LjY0MiwtOC4wODFjLTQuMzU0LC0wLjUyMiAtOC41MDYsMS44MjkgLTEwLjMwNyw1LjgyMmMtMy4xNyw3LjAwMyAtMTAuMTMzLDExLjg3MyAtMTguMjA1LDExLjg2NGMtOC45NTUsMC4wMjIgLTE2LjUxNywtNi4wMjEgLTE5LjAzOCwtMTQuMzE1Yy0xLjUyMSwtNS4wNjMgLTYuNzI0LC04LjA2NCAtMTEuODY1LC02Ljg2M2MtMy4xNjMsMC43NDEgLTYuMTU0LDEuMTcyIC05LjEyNSwxLjE3MmMtMjIuMDM5LC0wLjA0MyAtMzkuOTc2LC0xNy45NzkgLTQwLjAxNSwtNDAuMDE5Yy0wLC0yLjk3IDAuNDMsLTUuOTYyIDEuMTY5LC05LjExM2MxLjIxMiwtNS4xNDEgLTEuNzk5LC0xMC4zNTMgLTYuODYsLTExLjg3M2MtOC4yOTUsLTIuNTEzIC0xNC4zMzcsLTEwLjA3NSAtMTQuMzE5LC0xOS4wMjljLTAuMDA5LC04LjA4MiA0Ljg2NCwtMTUuMDM2IDExLjg2NywtMTguMjA4YzMuOTkxLC0xLjc5OCA2LjM0MSwtNS45NjMgNS44MjIsLTEwLjMwNGMtMC41MjIsLTQuMzUxIC0zLjc4MywtNy44NDMgLTguMDg0LC04LjY1MmMtOS41NDMsLTEuNzkyIC0xOS40MjYsLTIuODUyIC0yOS42MTEsLTIuODUyYy04OC4yOTUsMC4wMjIgLTE2MC4wNDMsNzEuNzcgLTE2MC4wNjUsMTYwLjA2NWMwLjAyMiw4OC4yOTUgNzEuNzcsMTYwLjA0MyAxNjAuMDY1LDE2MC4wNjVjODguMjk1LC0wLjAyMiAxNjAuMDQzLC03MS43NyAxNjAuMDY1LC0xNjAuMDY1Yy0wLC0xMC4xODQgLTEuMDYzLC0yMC4wNjcgLTIuODUyLC0yOS42MTRabS01OC4yMjMsMTI4LjYwNGMtMjUuNDAxLDI1LjM4IC02MC4zNTUsNDEuMDY2IC05OC45OSw0MS4wNjZjLTM4LjYzNSwwIC03My41ODgsLTE1LjY4NiAtOTguOTg5LC00MS4wNjZjLTI1LjM4LC0yNS40MDEgLTQxLjA2NiwtNjAuMzU1IC00MS4wNjYsLTk4Ljk5Yy0wLC0zOC42MzUgMTUuNjg2LC03My41ODggNDEuMDY2LC05OC45ODljMjUuNDAxLC0yNS4zOCA2MC4zNTQsLTQxLjA2NiA5OC45ODksLTQxLjA2NmMxLjgwMSwwIDMuNTYsMC4xODkgNS4zNTIsMC4yNjhjLTMuMzQzLDUuODIzIC01LjM0MywxMi41MjcgLTUuMzUyLDE5LjczOGMwLjAxOCwxNC45MzUgOC4zMDQsMjcuNzQyIDIwLjM3OSwzNC41NzVjLTAuMTkyLDEuNzggLTAuMzczLDMuNTYgLTAuMzczLDUuNDRjMC4wMjIsMzMuMTI1IDI2LjkwMyw2MC4wMDcgNjAuMDI1LDYwLjAyNWMxLjg4LDAgMy42NjQsLTAuMTggNS40NDMsLTAuMzY5YzYuODMzLDEyLjA2NSAxOS42MjgsMjAuMzU2IDM0LjU3MiwyMC4zNzhjNy4yMTUsLTAuMDA5IDEzLjkxNiwtMi4wMTEgMTkuNzQxLC01LjM1MmMwLjA4LDEuNzggMC4yNjksMy41NTEgMC4yNjksNS4zNTJjLTAsMzguNjM1IC0xNS42ODYsNzMuNTg5IC00MS4wNjYsOTguOTlabS01OC45NzQsLTE4Ljk1OWMtMCwxMS4wNTIgLTguOTU4LDIwLjAxIC0yMC4wMSwyMC4wMWMtMTEuMDQ4LC0wIC0yMC4wMDUsLTguOTU4IC0yMC4wMDUsLTIwLjAxYy0wLC0xMS4wNDkgOC45NTcsLTIwLjAwNiAyMC4wMDUsLTIwLjAwNmMxMS4wNTIsLTAgMjAuMDEsOC45NTcgMjAuMDEsMjAuMDA2Wm0tODAuMDMxLC0xMC4wMDVjMCw1LjUyNiAtNC40NzksMTAuMDA1IC0xMC4wMDUsMTAuMDA1Yy01LjUyNiwtMCAtMTAuMDA1LC00LjQ3OSAtMTAuMDA1LC0xMC4wMDVjMCwtNS41MjMgNC40NzksLTEwLjAwMSAxMC4wMDUsLTEwLjAwMWM1LjUyNiwtMCAxMC4wMDUsNC40NzggMTAuMDA1LDEwLjAwMVptMTQwLjA1NSwtMjAuMDA2YzAsNS41MjYgLTQuNDc5LDEwLjAwNSAtMTAuMDA1LDEwLjAwNWMtNS41MjUsMCAtMTAuMDA1LC00LjQ3OSAtMTAuMDA1LC0xMC4wMDVjMCwtNS41MjYgNC40OCwtMTAuMDA1IDEwLjAwNSwtMTAuMDA1YzUuNTI2LDAgMTAuMDA1LDQuNDc5IDEwLjAwNSwxMC4wMDVabS0xNjAuMDY0LC01MC4wMmMtMCwxMS4wNDggLTguOTU3LDIwLjAwNiAtMjAuMDEsMjAuMDA2Yy0xMS4wNDgsMCAtMjAuMDA1LC04Ljk1OCAtMjAuMDA1LC0yMC4wMDZjLTAsLTExLjA1MiA4Ljk1NywtMjAuMDEgMjAuMDA1LC0yMC4wMWMxMS4wNTMsMCAyMC4wMSw4Ljk1OCAyMC4wMSwyMC4wMVptODAuMDMsMTAuMDA1YzAsNS41MjMgLTQuNDc4LDEwLjAwMSAtMTAuMDAxLDEwLjAwMWMtNS41MjYsMCAtMTAuMDA1LC00LjQ3OCAtMTAuMDA1LC0xMC4wMDFjMCwtNS41MjYgNC40NzksLTEwLjAwNSAxMC4wMDUsLTEwLjAwNWM1LjUyMywwIDEwLjAwMSw0LjQ3OSAxMC4wMDEsMTAuMDA1Wm0xMTUuNDkzLC02OS40MDZjMCw1LjUyNiAtNC40NzksMTAuMDA1IC0xMC4wMDUsMTAuMDA1Yy01LjUyNiwtMCAtMTAuMDA1LC00LjQ3OSAtMTAuMDA1LC0xMC4wMDVjMCwtNS41MjYgNC40NzksLTEwLjAwNSAxMC4wMDUsLTEwLjAwNWM1LjUyNiwtMCAxMC4wMDUsNC40NzkgMTAuMDA1LDEwLjAwNVptLTM1LjUyMywtMTkuODc0Yy0wLDExLjUwMyAtOS4zMjUsMjAuODI4IC0yMC44MjgsMjAuODI4Yy0xMS41MDQsLTAgLTIwLjgyOSwtOS4zMjUgLTIwLjgyOSwtMjAuODI4Yy0wLC0xMS41MDMgOS4zMjUsLTIwLjgyOCAyMC44MjksLTIwLjgyOGMxMS41MDMsLTAgMjAuODI4LDkuMzI1IDIwLjgyOCwyMC44MjhabS0xMTkuOTg1LC0wLjc1OWMtMCwxMS4wNTIgLTguOTU3LDIwLjAxIC0yMC4wMDYsMjAuMDFjLTExLjA1MiwtMCAtMjAuMDA5LC04Ljk1OCAtMjAuMDA5LC0yMC4wMWMtMCwtMTEuMDQ4IDguOTU3LC0yMC4wMDYgMjAuMDA5LC0yMC4wMDZjMTEuMDQ5LC0wIDIwLjAwNiw4Ljk1OCAyMC4wMDYsMjAuMDA2WiIgc3R5bGU9ImZpbGw6I2ZmZjtmaWxsLXJ1bGU6bm9uemVybzsiLz48L3N2Zz4=);
				background-position: center center;
				background-repeat: no-repeat;
				background-size: 18px auto;
			}
			.toplevel_page_cookie-notice .pvc-admin-menu-new {
				font-size: 10px;
				vertical-align: super;
				color: #ffc107;
			}
		</style>
		';
	}

	/**
	 * Register WPML (>= 3.2) strings if needed.
	 *
	 * @global object $wpdb
	 *
	 * @return void
	 */
	private function register_wpml_strings() {
		// get main instance
		$cn = Cookie_Notice();

		global $wpdb;

		// prepare strings
		$strings = [
			'Message in the notice'	=> $cn->options['general']['message_text'],
			'Button text'			=> $cn->options['general']['accept_text'],
			'Refuse button text'	=> $cn->options['general']['refuse_text'],
			'Revoke message text'	=> $cn->options['general']['revoke_message_text'],
			'Revoke button text'	=> $cn->options['general']['revoke_text'],
			'Privacy policy text'	=> $cn->options['general']['see_more_opt']['text'],
			'Custom link'			=> $cn->options['general']['see_more_opt']['link']
		];

		// get query results
		$results = $wpdb->get_col( $wpdb->prepare( "SELECT name FROM " . $wpdb->prefix . "icl_strings WHERE context = %s", 'Cookie Notice' ) );

		// check results
		foreach( $strings as $string => $value ) {
			// string does not exist?
			if ( ! in_array( $string, $results, true ) ) {
				// register string
				do_action( 'wpml_register_single_string', 'Cookie Notice', $string, $value );
			}
		}
	}

	/**
	 * Display errors and notices.
	 *
	 * @global string $pagenow
	 *
	 * @return void
	 */
	public function settings_errors() {
		global $pagenow;

		// force display notices in top menu settings page
		if ( $pagenow === 'options-general.php' )
			return;

		settings_errors( 'cn_cookie_notice_options' );
	}

	/**
	 * Save compliance config caching.
	 *
	 * @return void
	 */
	public function ajax_purge_cache() {
		// valid nonce?
		if ( ! check_ajax_referer( 'cn-purge-cache', 'nonce' ) )
			exit;

		// The engine-changed notice's button (js/admin-notice.js): page caches purged too, and
		// an explicit JSON answer either way. Other callers keep the bare reply.
		$purge_pages = ! empty( $_POST['purge_pages'] );

		// check capability
		if ( ! current_user_can( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) ) ) {
			if ( $purge_pages )
				wp_send_json_error( [ 'error' => esc_html__( 'You do not have permission to perform this action.', 'cookie-notice' ) ], 403 );

			exit;
		}

		// The pull below rewrites the network's config rows on a site the network manages.
		$this->verify_not_network_managed();

		// ── Begin purge scope gate ───────────────────────────────────────────
		// Say so rather than succeeding at nothing. Under global_override the config this
		// purges and the transient the front end reads are BOTH network-scoped, so for an
		// administrator without the network capability every step below is a no-op:
		// get_app_config() returns early, and the site transient written instead is one
		// Cookie_Notice_Frontend never reads in that mode. Reporting success for that is the
		// silent-no-op this branch refuses everywhere else — it reads as a bug, and the
		// obvious "fix" for a bug like that is to loosen the gate.
		if ( Cookie_Notice()->is_network_options() && ! Cookie_Notice()->can_write_at_scope( true ) )
			wp_send_json_error( [ 'error' => Cookie_Notice()->network_scope_denied_message() ], 403 );
		// ── End purge scope gate ─────────────────────────────────────────────

		// request for new config data
		Cookie_Notice()->welcome_api->get_app_config( '', true );

		// re-evaluate CSP state now that the operator clicked Purge — the next
		// admin render should reflect any .htaccess change immediately.
		$this->refresh_csp_notice( true );

		// force new config on frontend
		if ( Cookie_Notice()->is_network_options() )
			set_site_transient( 'cookie_notice_config_update', current_time( 'timestamp', true ), 600 );
		else
			set_transient( 'cookie_notice_config_update', current_time( 'timestamp', true ), 600 );

		// ── Begin page cache purge on request ────────────────────────────────
		// The engine-changed notice's Purge Cache asks for it (purge_pages, js/admin-notice.js).
		// The pull that changed the engine has already run, so the pull above usually changes
		// nothing, and get_app_config() purges page caches only on a change: pages cached with
		// the old banner would stay. So purge on every such click, after the new-config stamp
		// so every page cached from now on carries it, with the scope's own row as every other
		// purge passes it, and say so. Callers without the flag keep the change-gated purge.
		if ( $purge_pages ) {
			$this->configuration_updated( (array) Cookie_Notice_Store::get( 'cookie_notice_options', [], Cookie_Notice()->is_network_options() ) );

			wp_send_json_success();
		}
		// ── End page cache purge on request ──────────────────────────────────

		exit;
	}

	/**
	 * Generate conditions.
	 *
	 * @param array $groups
	 * @return string
	 */
	public function conditional_display( $groups ) {
		$group_template = '
		<div%s>
			<table class="widefat">
				<tbody>
					%s
				</tbody>
			</table>
			<h4 class="or-rules">' . esc_html__( 'or', 'cookie-notice' ) . '</h4>
		</div>';

		$rule_template = '
		<tr data-rule-id="__RULE_ID__" %s>
			<td class="param">
				<select class="rule-type" name="cookie_notice_options[conditional_rules][__GROUP_ID__][__RULE_ID__][param]">
					%s
				</select>
			</td>
			<td class="operator">
				<select name="cookie_notice_options[conditional_rules][__GROUP_ID__][__RULE_ID__][operator]">
					%s
				</select>
			</td>
			<td class="value">
				<span class="spinner" style="display: none"></span>
				<select name="cookie_notice_options[conditional_rules][__GROUP_ID__][__RULE_ID__][value]">
					%s
				</select>
			</td>
			<td class="remove">
				<a href="#" class="dashicons dashicons-no-alt remove-rule"></a>
			</td>
		</tr>';

		$html = sprintf(
			$group_template,
			' class="rules-group" id="rules-group-template" style="display: none"',
			sprintf(
				$rule_template,
				' class="rule_template"',
				$this->prepare_parameters(),
				$this->prepare_operators(),
				$this->prepare_values( 'page_type' )
			)
		) . '
		<div id="cookie-notice-conditions">
			<table class="widefat">
				<tbody>
					<tr>
						<td>
							<div id="rules-groups">';

		if ( ! empty( $groups ) ) {
			foreach ( $groups as $group_id => $group ) {
				$html_rules = '';

				foreach ( $group as $rule_id => $rule ) {
					$html_rules .= sprintf(
						str_replace(
							[ '__GROUP_ID__', '__RULE_ID__' ],
							[ (int) $group_id, (int) $rule_id ],
							$rule_template
						),
						'',
						$this->prepare_parameters( $rule['param'] ),
						$this->prepare_operators( $rule['operator'] ),
						$this->prepare_values( $rule['param'], $rule['value'] )
					);
				}

				$html .= sprintf( str_replace( '__GROUP_ID__', $group_id, $group_template ), ' class="rules-group" id="rules-group-' . $group_id . '"', $html_rules );
			}
		}

		$html .= '			</div>
							<a class="add-rule-group button button-primary" href="#">' . esc_html__( '+ Add rule', 'cookie-notice' ) . '</a>
							<p class="description">' . esc_html__( 'Create a set of rules to define the exact conditions for displaying or hiding the banner.', 'cookie-notice' ) . '</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>';

		return $html;
	}

	/**
	 * Prepare condition parameters.
	 *
	 * @param string $selected
	 * @return string
	 */
	public function prepare_parameters( $selected = 'page_type' ) {
		$html = '';

		foreach ( $this->parameters as $id => $element ) {
			$html .= '<option value="' . esc_attr( $id ) . '" ' . selected( $id, $selected, false ) . '>' . esc_html( $element ) . '</option>';
		}

		return $html;
	}

	/**
	 * Prepare condition operators.
	 *
	 * @param string $selected
	 * @return string
	 */
	public function prepare_operators( $selected = 'equal' ) {
		$html = '';

		foreach ( $this->operators as $id => $operator ) {
			$html .= '<option value="' . esc_attr( $id ) . '" ' . selected( $id, $selected, false ) . '>' . esc_html( $operator ) . '</option>';
		}

		return $html;
	}

	/**
	 * Prepare condition values.
	 *
	 * @param string $type
	 * @param string $selected
	 * @return string
	 */
	public function prepare_values( $type = '', $selected = '' ) {
		$type = sanitize_key( $type );

		if ( $type !== 'taxonomy_archive' )
			$selected = sanitize_key( $selected );

		$html = '';

		switch ( sanitize_key( $type ) ) {
			case 'page':
				$pages = $this->get_pages();

				if ( ! empty( $pages ) ) {
					foreach ( $pages as $page_id => $page_title ) {
						$html .= '<option value="' . esc_attr( $page_id ) . '" ' . selected( $page_id, $selected, false ) . '>' . esc_html( $page_title ) . '</option>';
					}
				}
				break;

			case 'page_type':
				$page_types = $this->get_page_types();

				if ( ! empty( $page_types ) ) {
					foreach ( $page_types as $page_type => $label ) {
						$html .= '<option value="' . esc_attr( $page_type ) . '" ' . selected( $page_type, $selected, false ) . '>' . esc_html( $label ) . '</option>';
					}
				}
				break;

			case 'post_type':
				$post_types = $this->get_post_types();

				if ( ! empty( $post_types ) ) {
					foreach ( $post_types as $post_type => $label ) {
						$html .= '<option value="' . esc_attr( $post_type ) . '" ' . selected( $post_type, $selected, false ) . '>' . esc_html( $label ) . '</option>';
					}
				}
				break;

			case 'user_type':
				$user_types = $this->get_user_types();

				if ( ! empty( $user_types ) ) {
					foreach ( $user_types as $user_type => $username ) {
						$html .= '<option value="' . esc_attr( $user_type ) . '" ' . selected( $user_type, $selected, false ) . '>' . esc_html( $username ).'</option>';
					}
				}
				break;

			case 'post_type_archive':
				$post_type_archives = $this->get_post_type_archives();

				if ( ! empty( $post_type_archives ) ) {
					foreach ( $post_type_archives as $post_type => $archive_name ) {
						$html .= '<option value="' . esc_attr( $post_type ) . '" ' . selected( $post_type, $selected, false ) . '>' . esc_html( $archive_name ) . '</option>';
					}
				} else
					$html .= '<option value="__none__">' . esc_html__( '-- no public archives --', 'cookie-notice' ) . '</option>';
				break;

			case 'taxonomy_archive':
				$terms = $this->get_terms();

				if ( ! empty( $terms ) ) {
					foreach ( $terms as $taxonomy => $data ) {
						$html .= '<optgroup label="' . $data['label'] . '">';

						foreach ( $data['terms'] as $term_id => $term_name ) {
							$value = $term_id . '|' . $taxonomy;

							$html .= '<option value="' . esc_attr( $value ) . '" ' . selected( $value, $selected, false ) . '>' . esc_html( $term_name ) . '</option>';
						}

						$html .= '</optgroup>';
					}
				} else
					$html .= '<option value="__none__">' . esc_html__( '-- no public terms --', 'cookie-notice' ) . '</option>';
		}

		return $html;
	}

	/**
	 * Check condition rule.
	 *
	 * @param string $type
	 * @param string $operator
	 * @param string $value
	 * @return bool
	 */
	public function check_rule( $type = '', $operator = '', $value = '' ) {
		if ( ! isset( $this->operators[$operator] ) )
			return false;

		switch( $type ) {
			case 'page':
				$pages = $this->get_pages();

				$valid_rule = ! empty( $pages[$value] );
				break;

			case 'page_type':
				$page_types = $this->get_page_types();

				$valid_rule = ! empty( $page_types[$value] );
				break;

			case 'post_type':
				$post_types = $this->get_post_types();

				$valid_rule = ! empty( $post_types[$value] );
				break;

			case 'user_type':
				$user_types = $this->get_user_types();

				$valid_rule = ! empty( $user_types[$value] );
				break;

			case 'post_type_archive':
				$post_type_archives = $this->get_post_type_archives();

				$valid_rule = ! empty( $post_type_archives[$value] );
				break;

			case 'taxonomy_archive':
				// check value
				if ( strpos( $value, '|' ) !== false ) {
					// explode it
					$values = explode( '|', $value );

					// 2 chunks?
					if ( count( $values ) === 2 ) {
						// get term
						$term = get_term( (int) $values[0], $values[1] );

						$valid_rule = ! ( is_wp_error( $term ) || ! $term );
					} else
						$valid_rule = false;
				} else
					$valid_rule = false;
				break;

			default:
				$valid_rule = false;
		}

		return $valid_rule;
	}

	/**
	 * Get page types.
	 *
	 * @return array
	 */
	public function get_pages() {
		$pages = [];

		// default arguments
		$args = [
			'post_type'			=> 'page',
			'post__not_in'		=> [],
			'nopaging'			=> true,
			'posts_per_page'	=> -1,
			'orderby'			=> 'title',
			'order'				=> 'asc',
			'suppress_filters'	=> false,
			'no_found_rows'		=> true,
			'cache_results'		=> false,
			'post_status'		=> [ 'publish', 'private', 'future' ]
		];

		// get static home pages
		$homepage = (int) get_option( 'page_for_posts', 0 );
		$posts_page = (int) get_option( 'page_on_front', 0 );

		// check homepage
		if ( $homepage > 0 )
			$args['post__not_in'][] = $homepage;

		// check posts page
		if ( $posts_page > 0 )
			$args['post__not_in'][] = $posts_page;

		$query = new WP_Query( $args );

		if ( ! empty( $query->posts ) ) {
			foreach ( $query->posts as $page ) {
				$page_id = (int) $page->ID;

				$pages[$page_id] = trim( $page->post_title ) === '' ? sprintf( __( 'Untitled Page %d', 'cookie-notice' ), $page_id ) : $page->post_title;
			}
		}

		return $pages;
	}

	/**
	 * Get page types.
	 *
	 * @return array
	 */
	public function get_page_types() {
		return [
			'front'	=> __( 'Front Page', 'cookie-notice' ),
			'home'	=> __( 'Home Page', 'cookie-notice' )
		];
	}

	/**
	 * Get user types.
	 *
	 * @return array
	 */
	public function get_user_types() {
		return [
			'logged_in'	=> __( 'Logged in', 'cookie-notice' ),
			'guest'		=> __( 'Guest', 'cookie-notice' )
		];
	}

	/**
	 * Get public post types.
	 *
	 * @return array
	 */
	public function get_post_types() {
		// get public post types
		$post_types = get_post_types(
			[
				'public' => true
			],
			'objects',
			'and'
		);

		$data = [];

		if ( ! empty( $post_types ) ) {
			foreach ( $post_types as $key => $post_type ) {
				$data[$key] = $post_type->labels->singular_name;
			}
		}

		asort( $data, SORT_STRING );

		return $data;
	}

	/**
	 * Get public post type archives.
	 *
	 * @return array
	 */
	public function get_post_type_archives() {
		// get public post types with archives
		$post_types = get_post_types(
			[
				'has_archive'	=> true,
				'public'		=> true
			],
			'objects',
			'and'
		);

		$archives = [];

		if ( ! empty( $post_types ) ) {
			foreach ( $post_types as $key => $post_type ) {
				$archives[$key] = $post_type->labels->name;
			}
		}

		// sort archives alphabetically
		asort( $archives, SORT_STRING );

		return $archives;
	}

	/**
	 * Get public terms.
	 *
	 * @return array
	 */
	public function get_terms() {
		// get all public taxonomies
		$taxonomies = get_taxonomies(
			[
				'public'	=> true
			],
			'objects',
			'and'
		);

		// get all terms associated with specified taxonomies
		$terms = get_terms(
			[
				'taxonomy'		=> array_keys( $taxonomies ),
				'hide_empty'	=> false,
				'order'			=> 'asc',
				'orderby'		=> 'name'
			]
		);

		$sorted = [];

		foreach ( $taxonomies as $slug => $taxonomy ) {
			$sorted[$slug] = [
				'label'	=> $taxonomy->label,
				'terms'	=> []
			];
		}

		foreach ( $terms as $term ) {
			$sorted[$term->taxonomy]['terms'][$term->term_id] = $term->name;
		}

		return $sorted;
	}

	/**
	 * Get group rule values.
	 *
	 * @return void
	 */
	public function get_group_rule_values() {
		if (
			isset( $_POST['action'], $_POST['cn_param'], $_POST['cn_nonce'] )
			&& wp_verify_nonce( $_POST['cn_nonce'], 'cn-get-group-values' ) !== false
			&& current_user_can( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) )
		) {
			echo wp_json_encode(
				[
					'select'	=> $this->prepare_values( sanitize_key( $_POST['cn_param'] ) )
				]
			);
		}

		exit;
	}

	/**
	 * Get fresh cookie compliance credentials for analytics.
	 *
	 * @return array
	 */
	public function get_analytics_app_data() {
		return $this->analytics_app_data;
	}

	/**
	 * Set fresh cookie compliance credentials for analytics.
	 *
	 * Lets the React save path supply the just-saved credentials so get_app_analytics()
	 * authenticates against the new app id immediately after a connection change, instead
	 * of waiting for the hourly cron. Pass an empty array to clear.
	 *
	 * @param array $data
	 * @return void
	 */
	public function set_analytics_app_data( $data ) {
		$this->analytics_app_data = is_array( $data ) ? $data : [];
	}

	/**
	 * Add new properties to style safe list.
	 *
	 * @param array $styles
	 * @return array
	 */
	public function allow_style_attributes( $styles ) {
		$styles[] = 'display';

		return $styles;
	}
}
