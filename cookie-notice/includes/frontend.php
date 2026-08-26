<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie_Notice_Frontend class.
 *
 * @class Cookie_Notice_Frontend
 */
class Cookie_Notice_Frontend {

	private $compliance = false;
	private $matched_handles = [];

	/** Memoised banner-admin capability for this request. null = not yet asked. See is_banner_admin(). */
	private $banner_admin = null;

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// general actions
		add_action( 'init', [ $this, 'early_init' ], 9 );
		add_action( 'wp', [ $this, 'init' ] );
		add_action( 'rest_api_init', [ $this, 'register_purge_route' ] );
		add_action( 'wp_head', [ $this, 'wp_print_header_scripts' ] );
		add_action( 'wp_print_footer_scripts', [ $this, 'wp_print_footer_scripts' ] );

		// compliance actions
		add_action( 'wp_head', [ $this, 'add_dns_prefetch' ], -1 );
		add_action( 'wp_head', [ $this, 'add_cookie_compliance' ], 0 );
		add_action( 'login_head', [ $this, 'add_cookie_compliance' ], 0 );

		// notice actions
		add_action( 'wp_footer', [ $this, 'add_cookie_notice' ], 1000 );
		add_action( 'login_footer', [ $this, 'add_cookie_notice' ], 1000 );
		add_action( 'wp_enqueue_scripts', [ $this, 'wp_enqueue_notice_scripts' ] );
		add_action( 'login_enqueue_scripts', [ $this, 'wp_enqueue_notice_scripts' ] );

		// filters
		add_filter( 'body_class', [ $this, 'change_body_class' ] );
		add_filter( 'cn_is_bot', [ $this, 'wp_cache_check' ] );
	}

	/**
	 * Early initialization.
	 *
	 * @return void
	 */
	public function early_init() {
		// get main instance
		$cn = Cookie_Notice();

		// set compliance status
		$this->compliance = ( $cn->get_status() === 'active' );

		// ── Begin admin cache-bypass
		//
		// huOptions carries TWO values that differ for whoever administers the banner —
		// `blocking` (autoblocking switched off so they can work on the site) and
		// `isAdmin` — and it is printed inline in the page HTML. So an admin's page and a
		// visitor's page are different documents at the same URL.
		//
		// If a full-page cache stores the admin's copy and later serves it to the public,
		// every visitor receives `blocking: false`: autoblocking off for the whole site,
		// trackers running before anyone has answered the banner. There is no error, no
		// console warning and nothing visible in the admin screens — the owner could only
		// find it with a network trace.
		//
		// Most caching plugins skip logged-in users by default, so this needs a particular
		// setup to bite; the realistic one is a reverse proxy or CDN caching by URL while
		// ignoring cookies. This plugin's users are, definitionally, people running
		// aggressive caching — it ships compatibility modules for ten such plugins.
		//
		// ⚠️ This NARROWS the hole, it does not close it, and it is worth being precise
		// about how far it reaches:
		//
		//   - It stops the cache being WRITTEN. It cannot evict a copy already stored,
		//     because a page cache serves that copy without ever running PHP. A site that
		//     has been poisoned stays poisoned until someone purges it — which is why the
		//     changelog entry tells them to.
		//   - An edge cache told to ignore origin rules will still ignore this.
		//
		// Headers are deliberately NOT sent here. WP::send_headers() already merges
		// wp_get_nocache_headers() for every logged-in user before its own action fires
		// (class-wp.php, and unchanged since well before this plugin's minimum supported
		// version), so a callback of ours would re-send what is already on the wire and,
		// on a feed, strip the Last-Modified core had just set. DONOTCACHEPAGE is the only
		// signal this genuinely adds.
		//
		// Gated on compliance because that is what makes get_cc_options() run at all.
		// NOTE that is narrower than "the documents are otherwise identical": the
		// user_type conditional-display rule keys on is_user_logged_in(), so a site using
		// it varies the banner's PRESENCE for a much wider set than banner admins, with a
		// worse failure mode. Pre-existing and not addressed here.
		if ( ! is_admin() && $this->compliance && $this->is_banner_admin() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) )
				define( 'DONOTCACHEPAGE', true );
		}
		// ── End admin cache-bypass

		// cookie compliance initialization
		if ( $this->compliance ) {
			// amp compatibility
			if ( $cn->options['general']['amp_support'] && cn_is_plugin_active( 'amp' ) )
				include_once( COOKIE_NOTICE_PATH . 'includes/modules/amp/amp.php' );

			// excluded script handles — stamp data-hu-category="1" so the widget never blocks them
			if ( $cn->options['general']['app_blocking'] && ! empty( $cn->options['general']['excluded_handles'] ) ) {
				add_filter( 'script_loader_tag', [ $this, 'exclude_handles_from_blocking' ], 10, 2 );

				if ( $cn->options['general']['debug_mode'] )
					add_action( 'wp_footer', [ $this, 'debug_excluded_handles' ], 999 );
			}
		}
	}

	/**
	 * Whether the current user administers the banner.
	 *
	 * The single source for that question. It decides two things that must never disagree:
	 * whether autoblocking is switched off for this request (huOptions.blocking), and
	 * whether this request's HTML may be cached and served to somebody else.
	 *
	 * cn_manage_cookie_notice_cap is the escape hatch for a site whose banner is managed by
	 * a role without manage_options — an agency editor, a shop manager. Widening it widens
	 * the exemption AND the cache bypass together, which is the point: they are the same
	 * decision.
	 *
	 * MEMOISED, and that is load-bearing rather than a micro-optimisation. The two callers
	 * ask at different times — early_init() on `init` priority 9, get_cc_options() at
	 * wp_head — so a plugin registering cn_manage_cookie_notice_cap in its own `init`
	 * callback at the default priority 10 would land between them. One expression, two
	 * answers: the gate would decide "not an admin, cacheable" while get_cc_options() wrote
	 * blocking=false, which is precisely the silent drift this method exists to prevent.
	 * Caching makes whichever caller asks first bind the answer for the whole request, so a
	 * late-registered filter costs that user the exemption (they see blocking on — the safe
	 * direction) instead of producing a cacheable unblocked page.
	 *
	 * @return bool
	 */
	public function is_banner_admin() {
		if ( $this->banner_admin === null )
			$this->banner_admin = current_user_can( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) );

		return $this->banner_admin;
	}

	/**
	 * Stamp excluded script handles with data-hu-category="1" (Essential).
	 *
	 * @param string $tag    Full <script> tag HTML.
	 * @param string $handle WordPress script handle.
	 * @return string
	 */
	public function exclude_handles_from_blocking( $tag, $handle ) {
		$excluded = Cookie_Notice()->options['general']['excluded_handles'];

		if ( in_array( $handle, $excluded, true ) && strpos( $tag, 'data-hu-category' ) === false ) {
			$tag = str_replace( ' src=', ' data-hu-category="1" src=', $tag );
			$this->matched_handles[] = $handle;
		}

		return $tag;
	}

	/**
	 * Output debug console.warn lines listing matched / unmatched excluded handles.
	 *
	 * @return void
	 */
	public function debug_excluded_handles() {
		$configured = Cookie_Notice()->options['general']['excluded_handles'];
		$matched    = $this->matched_handles;
		$unmatched  = array_values( array_diff( $configured, $matched ) );

		echo '<script>' .
			'console.warn("CC Banner: Excluded script handles — stamped (' . count( $matched ) . '): " + ' . wp_json_encode( $matched ) . ');' .
			( ! empty( $unmatched ) ? 'console.warn("CC Banner: Excluded script handles — not found on this page (' . count( $unmatched ) . '): " + ' . wp_json_encode( $unmatched ) . ');' : '' ) .
		'</script>' . "\n";
	}

	/**
	 * Initialize plugin.
	 *
	 * @return void
	 */
	public function init() {
		if ( is_admin() )
			return;

		// Note: the legacy `?hu_purge_cache=1&_wpnonce=` URL trigger was removed in
		// 3.1.3 — config purges now arrive server-to-server via the authenticated
		// REST route (see register_purge_route()). The admin settings "Purge Cache"
		// button still works via its own AJAX action (settings.php ajax_purge_cache()).

		// get main instance
		$cn = Cookie_Notice();

		// compatibility fixes
		if ( $this->compliance ) {
			// is blocking active?
			if ( $cn->options['general']['app_blocking'] ) {
				// contact form 7 compatibility
				if ( cn_is_plugin_active( 'contactform7', 'captcha' ) )
					include_once( COOKIE_NOTICE_PATH . 'includes/modules/contact-form-7/contact-form-7.php' );

				// gravity forms compatibility
				if ( cn_is_plugin_active( 'gravityforms', 'captcha' ) )
					include_once( COOKIE_NOTICE_PATH . 'includes/modules/gravity-forms/gravity-forms.php' );

				// bestwebsoft recaptcha compatibility
				if ( cn_is_plugin_active( 'bestwebsoftrecaptcha', 'captcha' ) )
					include_once( COOKIE_NOTICE_PATH . 'includes/modules/bestwebsoft-recaptcha/bestwebsoft-recaptcha.php' );
			}
		}
	}

	/**
	 * Whether banner is allowed to display.
	 *
	 * @param array $args
	 * @return bool
	 */
	public function maybe_display_banner( $args = [] ) {
		$defaults = [
			'skip_amp' => false
		];

		if ( is_array( $args ) )
			$args = wp_parse_args( $args, $defaults );
		else
			$args = $defaults;

		// get main instance
		$cn = Cookie_Notice();

		// is cookie compliance active?
		if ( $this->compliance ) {
			// elementor compatibility, needed early for is_preview_mode
			if ( cn_is_plugin_active( 'elementor' ) )
				include_once( COOKIE_NOTICE_PATH . 'includes/modules/elementor/elementor.php' );

			// divi builder compatibility
			if ( cn_is_plugin_active( 'divi', 'theme' ) )
				include_once( COOKIE_NOTICE_PATH . 'includes/modules/divi/divi.php' );
		}

		// is it preview mode?
		if ( $this->is_preview_mode() )
			return false;

		// is bot detection enabled and it's a bot?
		if ( $cn->options['general']['bot_detection'] && apply_filters( 'cn_is_bot', $cn->bot_detect->is_crawler() ) )
			return false;

		// check amp
		if ( ! $args['skip_amp'] ) {
			if ( $cn->options['general']['amp_support'] && cn_is_plugin_active( 'amp' ) && function_exists( 'amp_is_request' ) && amp_is_request() )
				return false;
		}

		// final check for conditional display
		return $this->check_conditions();
	}

	/**
	* Check if WP_CACHE is active.
	 *
	 * @return bool
	 */
	public function wp_cache_check( $result ) {
		if ( defined( 'WP_CACHE' ) && WP_CACHE === true )
			$result = false;

		return $result;
	}

	/**
	 * Whether preview mode is active.
	 *
	 * @return bool
	 */
	public function is_preview_mode() {
		return isset( $_GET['cn_preview_mode'] ) || is_preview() || is_customize_preview() || defined( 'IFRAME_REQUEST' ) || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) || isset( $_GET[ 'fl_builder' ] ) || apply_filters( 'cn_is_preview_mode', false );
	}

	/**
	 * Check whether banner should be displayed based on specified conditions.
	 *
	 * @return bool
	 */
	public function check_conditions() {
		// get main instance
		$cn = Cookie_Notice();

		if ( ! $cn->options['general']['conditional_active'] )
			return true;

		// get conditions
		$rules = $cn->options['general']['conditional_rules'];

		// set access type
		$access_type = $cn->options['general']['conditional_display'] === 'show';

		// get object
		$object = get_queried_object();

		// no rules?
		if ( empty( $rules ) )
			$final_access = true;
		else {
			// check the rules
			foreach( $rules as $index => $group ) {
				$give_group_access = true;

				foreach ( $group as $rule ) {
					$give_rule_access = false;

					switch ( $rule['param'] ) {
						case 'page_type':
							if ( ( $rule['operator'] === 'equal' && $rule['value'] === 'front' && is_front_page() ) || ( $rule['operator'] === 'not_equal' && $rule['value'] === 'front' && ! is_front_page() ) || ( $rule['operator'] === 'equal' && $rule['value'] === 'home' && is_home() ) || ( $rule['operator'] === 'not_equal' && $rule['value'] === 'home' && ! is_home() ) || ( $rule['operator'] === 'equal' && $rule['value'] === 'login' && $this->is_login() ) || ( $rule['operator'] === 'not_equal' && $rule['value'] === 'login' && ! $this->is_login() ) )
								$give_rule_access = true;
							break;

						case 'page':
							if ( ( $rule['operator'] === 'equal' && ! empty( $object ) && is_a( $object, 'WP_Post' ) && property_exists( $object, 'ID' ) && is_page( $object->ID ) && (int) $object->ID === (int) $rule['value'] ) || ( $rule['operator'] === 'not_equal' && ( empty( $object ) || ! is_page() || ( is_page() && ! empty( $object ) && is_a( $object, 'WP_Post' ) && property_exists( $object, 'ID' ) && $object->ID !== (int) $rule['value'] ) ) ) )
								$give_rule_access = true;
							break;

						case 'post_type':
							if ( ( $rule['operator'] === 'equal' && is_singular( $rule['value'] ) ) || ( $rule['operator'] === 'not_equal' && ! is_singular( $rule['value'] ) ) )
								$give_rule_access = true;
							break;

						case 'post_type_archive':
							if ( ( $rule['operator'] === 'equal' && is_post_type_archive( $rule['value'] ) ) || ( $rule['operator'] === 'not_equal' && ! is_post_type_archive( $rule['value'] ) ) )
								$give_rule_access = true;
							break;

						case 'user_type':
							if ( ( $rule['operator'] === 'equal' && $rule['value'] === 'logged_in' && is_user_logged_in() ) || ( $rule['operator'] === 'equal' && $rule['value'] === 'guest' && ! is_user_logged_in() ) || ( $rule['operator'] === 'not_equal' && $rule['value'] === 'logged_in' && ! is_user_logged_in() ) || ( $rule['operator'] === 'not_equal' && $rule['value'] === 'guest' && is_user_logged_in() ) )
								$give_rule_access = true;
							break;

						case 'taxonomy_archive':
							// check value
							if ( strpos( $rule['value'], '|' ) !== false ) {
								// explode it
								$values = explode( '|', $rule['value'] );

								// 2 chunks?
								if ( count( $values ) === 2 ) {
									$term_id = (int) $values[0];

									if ( $values[1] === 'category' && ( ( $rule['operator'] === 'equal' && is_category( $term_id ) ) || ( $rule['operator'] === 'not_equal' && ! is_category( $term_id ) ) ) )
										$give_rule_access = true;
									elseif ( $values[1] === 'post_tag' && ( ( $rule['operator'] === 'equal' && is_tag( $term_id ) ) || ( $rule['operator'] === 'not_equal' && ! is_tag( $term_id ) ) ) )
										$give_rule_access = true;
									elseif ( ( $rule['operator'] === 'equal' && is_tax( $values[1], $term_id ) ) || ( $rule['operator'] === 'not_equal' && ! is_tax( $values[1], $term_id ) ) )
										$give_rule_access = true;
								}
							}
							break;
					}

					// condition failed?
					if ( ! $give_rule_access ) {
						// group failed
						$give_group_access = false;

						// finish group checking
						break;
					}
				}

				// whole group successful?
				if ( $give_group_access ) {
					// set final access
					$final_access = $access_type;

					// finish rules checking
					break;
				} else
					$final_access = ! $access_type;
			}
		}

		return (bool) apply_filters( 'cn_conditional_display', $final_access, $object );
	}

	/**
	 * Determine whether the current request is for the login screen.
	 *
	 * @return bool
	 */
	public function is_login() {
		return ( function_exists( 'is_login' ) ? is_login() : ( stripos( wp_login_url(), $_SERVER['SCRIPT_NAME'] ) !== false ) );
	}

	/**
	 * Get Cookie Compliance options.
	 *
	 * @return array
	 */
	public function get_cc_options() {
		// get main instance
		$cn = Cookie_Notice();

		// get site language
		$locale = get_locale();
		$locale_code = explode( '_', $locale );

		// exceptions, norwegian
		if ( is_array( $locale_code ) && in_array( $locale_code[0], [ 'nb', 'nn' ] ) )
			$locale_code[0] = 'no';

		// get active sources
		$sources = $cn->privacy_consent->get_active_sources();

		// Autoblocking is switched off for whoever administers the banner, so they can
		// work on the site without scripts being held. That exemption used to cover EVERY
		// logged-in user (is_user_logged_in()), which on a membership, LMS or shop site
		// means ordinary customers — people whose consent we are required to obtain, and
		// who were getting no script blocking at all before they answered the banner
		// (HelpScout #47786). It is now scoped to the same capability the admin screens
		// use; widen it with the cn_manage_cookie_notice_cap filter if a site genuinely
		// needs a broader exemption.
		//
		// Asked through is_banner_admin() rather than inline, because early_init() asks the
		// same question to decide whether this request's HTML may be cached. Two copies of
		// that capability check could drift, and the drift is silent: the page would carry
		// blocking=false while being cacheable, i.e. served to visitors.
		$is_admin = $this->is_banner_admin();

		// prepare huOptions
		$options = [
			'appID'				=> $cn->options['general']['app_id'],
			'currentLanguage'	=> $locale_code[0],
			'blocking'			=> ! $is_admin ? $cn->options['general']['app_blocking'] : false,
			'globalCookie'		=> is_multisite() && $cn->options['general']['global_cookie'] && is_subdomain_install(),
			'isAdmin'			=> $is_admin,
			'privacyConsent'	=> ! empty( $sources )
		];

		// any active source?
		if ( ! empty( $sources ) )
			$options['forms'] = [];

		// filter options
		$options = apply_filters( 'cn_cookie_compliance_args', $options );

		// get config timestamp
		if ( is_multisite() && $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'] )
			$timestamp = (int) get_site_transient( 'cookie_notice_config_update' );
		else
			$timestamp = (int) get_transient( 'cookie_notice_config_update' );

		// update config?
		if ( $timestamp > 0 ) {
			$options['cachePurge'] = true;
			$options['cacheTimestamp'] = $timestamp;
		}

		// debug mode
		if ( $cn->options['general']['debug_mode'] )
			$options['debugMode'] = true;

		// WP Consent API integration toggle. Mirrors the PHP-side gate in
		// includes/modules/wp-consent-api/wp-consent-api.php::is_enabled() so the
		// banner's JS bridge (Web Channel src/thirdparty.js) can short-circuit
		// wp_set_consent calls when the integration is disabled. Without this,
		// the bridge would keep pushing per-category state into WPCA even after
		// the PHP-side filters returned false, leaving consumer plugins gated
		// on banner consent while we no longer claim to be the active CMP.
		// Default true when missing — matches is_enabled() upgrade semantics.
		$options['wpConsentApiEnabled'] = ! isset( $cn->options['general']['wp_consent_api'] ) || (bool) $cn->options['general']['wp_consent_api'];

		// blocking data (custom patterns, providers, consent mode defaults)
		// always include in huOptions so the widget has the full configuration;
		// the huOptions.blocking flag controls whether scripts are actually blocked
		if ( $cn->is_network_options() )
			$blocking = get_site_option( 'cookie_notice_app_blocking' );
		else
			$blocking = get_option( 'cookie_notice_app_blocking' );

		// Consent Mode signals live in $options['config'] (see the three seeding blocks
		// below — Google, Facebook, Microsoft), because that is where the widget reads
		// them: buildGoogleConsentFlags takes options.config, and the Facebook and
		// Microsoft paths read options.config.*ConsentMap* directly.
		// The former top-level googleConsentDefault / facebookConsentDefault
		// / microsoftConsentDefault keys were declared in the widget's option schema and
		// never read by it, so they had no effect on the banner at all; they are gone rather
		// than left alongside a live sibling that differs from them by one nesting level.
		// Do NOT reintroduce them: a plan or quota gate applied there changes nothing on the
		// frontend, and the shape that does work is config.googleConsentMap*.
		if ( ! empty( $blocking ) && is_array( $blocking ) ) {
			$options['customProviders'] = ! empty( $blocking['providers'] ) && is_array( $blocking['providers'] ) ? $blocking['providers'] : [];
			$options['customPatterns'] = ! empty( $blocking['patterns'] ) && is_array( $blocking['patterns'] ) ? $blocking['patterns'] : [];

			// Seed the Google Consent Mode signals into huOptions.config so they are on the
			// page at byte zero, instead of only after the widget's own config request lands.
			//
			// Why this is the ONLY place it can be fixed: the single code path that writes
			// gtag('consent','default',...) is guarded on flags.configLoadedAfterObserver
			// (Web Channel src/blocking.js:3421), i.e. it runs EXCLUSIVELY while the remote
			// config has not arrived. It is structurally incapable of ever reading API
			// values, so the map has to already be on the page or the first pageview of a
			// session emits the widget's built-in fallbacks instead of the site's settings.
			// Concretely: a site that mapped security_storage to 1 ("Basic Operations") got
			// security_storage=denied on pageview 1 and granted thereafter.
			//
			// googleConsentMode travels with the map because it is what makes the map
			// authoritative rather than provisional. blocking.js:1046 and :3566 stash a
			// dataset.orgscript whenever !googleConsentMode — the comment there reads
			// "consent mode is false or unknown" — so without the flag the widget cannot
			// tell "this site has it switched off" from "we have not been told yet", and
			// marks its own consent default as replaceable even for a site that has it on.
			//
			// No new sync is involved: welcome-api.php already stores these seven integers,
			// pulled verbatim from BannerConfigJSON.googleConsentMap*, and populates
			// google_consent_default ONLY when googleConsentMode is 1 — so a non-empty map
			// IS the enabled flag. All eight keys are already declared in the widget's
			// option schema (defaultParamTypes.config), so nothing changes widget-side.
			//
			// These are written AFTER the cn_cookie_compliance_args filter deliberately.
			// The values mirror what the API is about to send, so letting them win keeps
			// the cold pageview in agreement with every later one, and keeps consent
			// signals out of reach of site-level overrides.
			// ── Begin Google Consent Mode seeding (huOptions.config)
			if ( ! empty( $blocking['google_consent_default'] ) && is_array( $blocking['google_consent_default'] ) ) {
				// storage name as stored by welcome-api.php => widget config key
				$gcm_signals = [
					'ad_storage'				=> 'googleConsentMapAdStorage',
					'analytics_storage'			=> 'googleConsentMapAnalytics',
					'functionality_storage'		=> 'googleConsentMapFunctionality',
					'personalization_storage'	=> 'googleConsentMapPersonalization',
					'security_storage'			=> 'googleConsentMapSecurity',
					'ad_personalization'		=> 'googleConsentMapAdPersonalization',
					'ad_user_data'				=> 'googleConsentMapAdUserData'
				];

				$seeded = [];

				foreach ( $gcm_signals as $storage => $option_key ) {
					if ( isset( $blocking['google_consent_default'][$storage] ) )
						$seeded[$option_key] = (int) $blocking['google_consent_default'][$storage];
				}

				if ( ! empty( $seeded ) ) {
					$seeded['googleConsentMode'] = true;

					$options['config'] = ! empty( $options['config'] ) && is_array( $options['config'] ) ? array_merge( $options['config'], $seeded ) : $seeded;
				}
			}
			// ── End Google Consent Mode seeding (huOptions.config)

			// Facebook and Microsoft are seeded for the same reason and on the same
			// evidence as Google above — read that comment first; only the differences are
			// repeated here.
			//
			// Same as Google: welcome-api.php already stores these integers and populates
			// facebook_consent_default / microsoft_consent_default ONLY when the API says
			// the mode is on, so a non-empty map IS the enabled flag. No plan gate belongs
			// here either — both are Pro-only, but Designer API logic.service.ts::
			// downgradeLiveDefaults has already reset them for a Free-plan app before the
			// response we stored was built. Every key is already declared in the widget's
			// option schema, so nothing changes widget-side.
			//
			// WHAT THESE FLAGS DO NOT DO — measured, because reading the source suggests
			// otherwise. Unlike Google, whose re-block gate gets no input from
			// googleConsentMode, both of these mode flags appear in a block decision:
			// `if ( facebookConsentMode ) doNotBlock = true; else { …re-block… }`
			// (Web Channel src/blocking.js:744) and `!flags.msDelayExecution &&
			// !microsoftConsentMode` (:824, and :1041 for the main inline uet script).
			// Read alone, those say seeding makes fbevents.js and bat.js load on the first
			// pageview of a session where they were blocked before.
			//
			// They do not, in either install shape. Both branches are additionally gated on
			// consentModeData.facebook.pixel / .microsoft.uet, which ONLY the inline snippet
			// handler sets — and that handler's own re-block reads
			// flags.configLoadedAfterObserver, not the mode flag, so the snippet stays
			// blocked whether or not we seed. A blocked snippet never injects the vendor
			// file. Measured 2026-08-20 across install shape x seeding, each arm a fresh
			// session with the config response held past the observer
			// (Web Channel tests/e2e/cold-window-vendor-consent.spec.js):
			//
			//   snippet-injected  unseeded -> no vendor request   seeded -> no vendor request
			//   hard-coded <src>  unseeded -> request fires       seeded -> request fires
			//
			// The vendor-request columns are identical. The hard-coded row fires with
			// seeding OFF, so that request is pre-existing and NOT caused by this — but it
			// is a real pre-consent vendor call on the first pageview of every session for
			// sites installed that way, cause not yet established (preload-scanner race, or
			// blocking patterns not yet loaded). Worth its own investigation; do not read
			// this block as having cleared it.
			//
			// The MAP values likewise cannot loosen anything for a visitor who has not
			// consented: both vendors route through mapConsentSignal WITHOUT the `exempt`
			// argument (blocking.js:3110, :3179), so index 1 returns 'denied' and indices
			// 2/3/4 are all false pre-consent. They change only what a returning,
			// already-consented visitor gets on their cold pageview — toward the site's own
			// mapping.
			//
			// So the whole observable effect of this block is: correct map values for a
			// returning consented visitor, and the authored consent value surviving instead
			// of being replaced. Nothing gets looser.
			//
			// Seeding the maps WITHOUT the flags was rejected as inert: while the flag is
			// falsy the widget stashes the untouched script in data-orgscript
			// (blocking.js:1002, :1093, :1160) and events.js:969 puts it back once the config
			// lands, discarding whatever we authored.
			// ── Begin Facebook Consent Mode seeding (huOptions.config)
			if ( ! empty( $blocking['facebook_consent_default'] ) && is_array( $blocking['facebook_consent_default'] ) && isset( $blocking['facebook_consent_default']['consent'] ) ) {
				// one signal, so no table: 'consent' as stored by welcome-api.php =>
				// facebookConsentMapConsent as read by the widget
				$seeded = [
					'facebookConsentMapConsent'	=> (int) $blocking['facebook_consent_default']['consent'],
					'facebookConsentMode'		=> true
				];

				$options['config'] = ! empty( $options['config'] ) && is_array( $options['config'] ) ? array_merge( $options['config'], $seeded ) : $seeded;
			}
			// ── End Facebook Consent Mode seeding (huOptions.config)

			// ── Begin Microsoft Consent Mode seeding (huOptions.config)
			if ( ! empty( $blocking['microsoft_consent_default'] ) && is_array( $blocking['microsoft_consent_default'] ) ) {
				// storage name as stored by welcome-api.php => widget config key. Note the
				// analytics key is microsoftConsentMapAnalyticsStorage — Google's is
				// googleConsentMapAnalytics, with no suffix. A wrong name is dropped by the
				// widget's schema filter in silence.
				$mcm_signals = [
					'ad_storage'		=> 'microsoftConsentMapAdStorage',
					'analytics_storage'	=> 'microsoftConsentMapAnalyticsStorage'
				];

				$seeded = [];

				foreach ( $mcm_signals as $storage => $option_key ) {
					if ( isset( $blocking['microsoft_consent_default'][$storage] ) )
						$seeded[$option_key] = (int) $blocking['microsoft_consent_default'][$storage];
				}

				if ( ! empty( $seeded ) ) {
					$seeded['microsoftConsentMode'] = true;

					// Pixie and Clarity are per-script switches under the same mode, and each
					// gates its own data-orgscript stash (blocking.js:1056, :1160) — left unseeded
					// they keep, for those two scripts, exactly the bug this block removes for
					// uet. The only plugin-side copy of them is the raw BannerConfigJSON:
					// welcome-api.php never cherry-picks them into microsoft_consent_default.
					// (That is also why ConsentModesPanel.jsx:221-222 reads mscm.pixie /
					// mscm.clarity and always gets undefined — a separate bug, not fixed here.)
					// On an install whose stored blocking data predates banner_config they stay
					// absent and the widget keeps its own false defaults, i.e. today's behaviour.
					$banner_config = ! empty( $blocking['banner_config'] ) && is_array( $blocking['banner_config'] ) ? $blocking['banner_config'] : [];

					foreach ( ['microsoftConsentModePixie', 'microsoftConsentModeClarity'] as $mode_key ) {
						if ( isset( $banner_config[$mode_key] ) )
							$seeded[$mode_key] = (bool) $banner_config[$mode_key];
					}

					$options['config'] = ! empty( $options['config'] ) && is_array( $options['config'] ) ? array_merge( $options['config'], $seeded ) : $seeded;
				}
			}
			// ── End Microsoft Consent Mode seeding (huOptions.config)

			// Seed the browser opt-out signal settings so GPC/DNT enforcement does not
			// have to wait for the widget's own config request.
			//
			// The gap: forceBlocking is decided in the geolocation-update handler from
			// config.gpcSupportMode / config.doNotTrackMode (Web Channel src/events.js,
			// evaluateGpcDntEnforcement). Both default to FALSE in the widget, so on the
			// first geolocation-update of a cold pageview — the one the save-session
			// response fires — a GPC visitor gets no enforcement at all. The widget's own cache-path
			// comment already names this failure ("without this re-fire, GPC/DNT
			// enforcement and the new gpcBannerMode silent path silently miss any
			// visitor whose config came from cache"); the same hole exists on the
			// network path, and seeding is what puts the setting in place for it.
			//
			// ⚠️ Be precise about what that buys, because an earlier draft of this comment
			// overstated it: seeding does NOT stop trackers already on the page. Raising the
			// widget's blocking flag is not retroactive, so scripts scanned at byte zero were
			// already let through — measured, both seeded and unseeded pages request gtag/js
			// and analytics.js on a cold pageview. What the seed fixes is that the enforcement
			// STATE is right earlier, which governs scripts injected after that point, banner
			// suppression, and the suppression of a region-scoped consent grant for someone who
			// has already opted out. gpcSupportMode is also read
			// synchronously where the Google consent default is authored, to suppress
			// region scoping for a visitor who has already opted out.
			//
			// ── Why ONLY these three, when geolocation / geolocationRules / regulations
			// / consentLevel are read in the same window and would fix more ──
			//
			// Those four can WIDEN a consent grant, and banner_config is a snapshot that
			// can be stale: the config pull is a twicedaily WP-Cron event, and the
			// server-to-server purge that normally refreshes it on publish
			// (rest_purge_cache, below) does not exist before plugin 3.1.3 and is
			// best-effort even after. A customer who tightens a rule — say lgpd from
			// blocking:false to blocking:true, which our own Admin Portal flags crit —
			// would keep serving the OLD posture on pageview 1 until the snapshot
			// catches up, emitting a gtag consent default that grants analytics for
			// their region. That command cannot be retracted, and the widget replays it
			// verbatim after the live config has already landed. Neither window emits
			// that grant today. So those four wait for a freshness bound.
			//
			// These three have no such direction: there is no value of them that releases a
			// script or upgrades a signal. A stale `false` or an absent key is exactly
			// today's behaviour.
			//
			// A stale `true` over-suppresses, which is the safe direction — but do NOT read
			// that as "confined to the cold window". When the silent path fires it calls
			// saveConsent(), persisting a level-1 `source:'gpc'` record and cookie. So an
			// owner who switches GPC OFF in the portal keeps auto-recording consent for every
			// GPC visitor until the snapshot refreshes (up to ~12h on the WP-Cron pull, longer
			// if cron never fires), and
			// each of those visitors then sees no banner for the life of that cookie (unless the
			// site uses resetConsent) on a site that no longer honours GPC. Still fail-closed,
			// still the right trade — but the effect outlives the staleness that caused it.
			//
			// Known, measured, accepted: seeding gpcSupportMode lets the GPC silent path
			// run before the remote config, where the session record still carries the
			// widget's built-in expiry (defaults.config.expiry, 30) and version.config 0
			// rather than the site's own consentExpiry and config version. Across live
			// apps ZERO have consentExpiry[0] below 30, so this can only ever shorten a
			// consent window, never lengthen one; 25 apps see the shorter window and the
			// audit field reads lastVersion 0. Both are widget-side quirks this exposes
			// rather than creates.
			//
			// Per-key presence, deliberately: an absent key here means the widget
			// receives nothing for it and falls back to its own default, because
			// banner_config is a snapshot of the same /user-design-live response the
			// widget itself fetches. gpcSupportMode is absent from most stored configs
			// precisely because most sites do not use it — gating the block on it would
			// disable the seed for the majority over a field they do not have.
			// ── Begin Browser Signal seeding (huOptions.config)
			$signal_config = ! empty( $blocking['banner_config'] ) && is_array( $blocking['banner_config'] ) ? $blocking['banner_config'] : [];

			if ( ! empty( $signal_config ) ) {
				$seeded = [];

				foreach ( ['gpcSupportMode', 'doNotTrackMode'] as $signal_key ) {
					if ( isset( $signal_config[$signal_key] ) )
						$seeded[$signal_key] = (bool) $signal_config[$signal_key];
				}

				// Enum, not a boolean: 'banner' | 'hidden' | 'passive'. Seeded verbatim
				// and NOT validated here — the widget resolves anything it does not
				// recognise to 'passive', which is the suppressing choice, so a value
				// added on the platform side keeps working without a plugin release.
				// Inert while gpcSupportMode is falsy (only the GPC branch reads it), so
				// it needs no gate of its own.
				if ( isset( $signal_config['gpcBannerMode'] ) && is_string( $signal_config['gpcBannerMode'] ) && $signal_config['gpcBannerMode'] !== '' )
					$seeded['gpcBannerMode'] = (string) $signal_config['gpcBannerMode'];

				if ( ! empty( $seeded ) )
					$options['config'] = ! empty( $options['config'] ) && is_array( $options['config'] ) ? array_merge( $options['config'], $seeded ) : $seeded;
			}
			// ── End Browser Signal seeding (huOptions.config)
		}

		if ( isset( $_GET['cn_preview'] ) && $_GET['cn_preview'] === '1' && current_user_can( 'manage_options' ) ) {
			$options['forceShow'] = true;
		}

		return $options;
	}

	/**
	 * Get Cookie Compliance output.
	 *
	 * @param array $options
	 * @return string
	 */
	public function get_cc_output( $options ) {
		// The optimizer/CDN skip attributes below are the literal twin of
		// Cookie_Notice::optimizer_skip_attrs() — kept inline here for the heredoc.
		// If that set changes, change these tags too.
		$output = '
		<!-- Cookie Compliance -->
		<script type="text/javascript" id="hu-banner-options" data-cfasync="false" data-nowprocket data-noptimize="1" data-no-optimize="1" nitro-exclude data-jetpack-boost="ignore" data-no-minify>var huOptions = ' . wp_json_encode( $options, JSON_UNESCAPED_SLASHES ) . '; // nowprocket</script>
		<script type="text/javascript" id="hu-banner-js" data-cfasync="false" data-nowprocket data-noptimize="1" data-no-optimize="1" nitro-exclude data-jetpack-boost="ignore" data-no-minify src="' . esc_url( ( is_ssl() ? 'https:' : 'http:' ) . Cookie_Notice()->get_url( 'widget' ) ) . '"></script>';

		return apply_filters( 'cn_cookie_compliance_output', $output, $options );
	}

	/**
	 * Add DNS Prefetch.
	 *
	 * @return void
	 */
	public function add_dns_prefetch() {
		if ( ! $this->compliance )
			return;

		// is banner allowed to display?
		if ( ! $this->maybe_display_banner() )
			return;

		// Derive prefetch host from widget URL so CN_APP_WIDGET_URL overrides are honoured.
		$widget_url = Cookie_Notice()->get_url( 'widget' );
		$prefetch_host = '//' . wp_parse_url( 'https:' . $widget_url, PHP_URL_HOST );
		echo '<link rel="dns-prefetch" href="' . esc_attr( $prefetch_host ) . '" />';
	}

	/**
	 * Run Cookie Compliance.
	 *
	 * @return void
	 */
	public function add_cookie_compliance() {
		// skip modal login iframe
		if ( current_filter() === 'login_head' && ! empty( $_REQUEST['interim-login'] ) )
			return;

		// allow only for compliance
		if ( ! $this->compliance )
			return;

		// is banner allowed to display?
		if ( ! $this->maybe_display_banner() )
			return;

		// get options
		$options = $this->get_cc_options();

		// display output
		echo $this->get_cc_output( $options );
	}

	/**
	 * Cookie notice output.
	 *
	 * @return void
	 */
	public function add_cookie_notice() {
		// skip modal login iframe
		if ( current_filter() === 'login_footer' && ! empty( $_REQUEST['interim-login'] ) )
			return;

		if ( $this->compliance )
			return;

		// is banner allowed to display?
		if ( ! $this->maybe_display_banner() )
			return;

		// get main instance
		$cn = Cookie_Notice();

		// WPML >= 3.2
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && version_compare( ICL_SITEPRESS_VERSION, '3.2', '>=' ) ) {
			$cn->options['general']['message_text'] = apply_filters( 'wpml_translate_single_string', $cn->options['general']['message_text'], 'Cookie Notice', 'Message in the notice' );
			$cn->options['general']['accept_text'] = apply_filters( 'wpml_translate_single_string', $cn->options['general']['accept_text'], 'Cookie Notice', 'Button text' );
			$cn->options['general']['refuse_text'] = apply_filters( 'wpml_translate_single_string', $cn->options['general']['refuse_text'], 'Cookie Notice', 'Refuse button text' );
			$cn->options['general']['revoke_message_text'] = apply_filters( 'wpml_translate_single_string', $cn->options['general']['revoke_message_text'], 'Cookie Notice', 'Revoke message text' );
			$cn->options['general']['revoke_text'] = apply_filters( 'wpml_translate_single_string', $cn->options['general']['revoke_text'], 'Cookie Notice', 'Revoke button text' );
			$cn->options['general']['see_more_opt']['text'] = apply_filters( 'wpml_translate_single_string', $cn->options['general']['see_more_opt']['text'], 'Cookie Notice', 'Privacy policy text' );
			$cn->options['general']['see_more_opt']['link'] = apply_filters( 'wpml_translate_single_string', $cn->options['general']['see_more_opt']['link'], 'Cookie Notice', 'Custom link' );
		// WPML and Polylang compatibility
		} elseif ( function_exists( 'icl_t' ) ) {
			$cn->options['general']['message_text'] = icl_t( 'Cookie Notice', 'Message in the notice', $cn->options['general']['message_text'] );
			$cn->options['general']['accept_text'] = icl_t( 'Cookie Notice', 'Button text', $cn->options['general']['accept_text'] );
			$cn->options['general']['refuse_text'] = icl_t( 'Cookie Notice', 'Refuse button text', $cn->options['general']['refuse_text'] );
			$cn->options['general']['revoke_message_text'] = icl_t( 'Cookie Notice', 'Revoke message text', $cn->options['general']['revoke_message_text'] );
			$cn->options['general']['revoke_text'] = icl_t( 'Cookie Notice', 'Revoke button text', $cn->options['general']['revoke_text'] );
			$cn->options['general']['see_more_opt']['text'] = icl_t( 'Cookie Notice', 'Privacy policy text', $cn->options['general']['see_more_opt']['text'] );
			$cn->options['general']['see_more_opt']['link'] = icl_t( 'Cookie Notice', 'Custom link', $cn->options['general']['see_more_opt']['link'] );
		}

		if ( $cn->options['general']['see_more_opt']['link_type'] === 'page' ) {
			// multisite with global override?
			if ( is_multisite() && $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'] ) {
				// get main site id
				$main_site_id = get_main_site_id();

				// switch to main site
				switch_to_blog( $main_site_id );

				// update page id for current language if needed
				if ( function_exists( 'icl_object_id' ) )
					$cn->options['general']['see_more_opt']['id'] = icl_object_id( $cn->options['general']['see_more_opt']['id'], 'page', true );

				// get main site privacy policy link
				$permalink = get_permalink( $cn->options['general']['see_more_opt']['id'] );

				// restore current site
				restore_current_blog();
			} else {
				// update page id for current language if needed
				if ( function_exists( 'icl_object_id' ) )
					$cn->options['general']['see_more_opt']['id'] = icl_object_id( $cn->options['general']['see_more_opt']['id'], 'page', true );

				// get privacy policy link
				$permalink = get_permalink( $cn->options['general']['see_more_opt']['id'] );
			}
		}

		// #2266: position is API-owned — read from cookie_notice_app_design for connected sites.
		// Falls back to cookie_notice_options["general"]["position"] for disconnected/legacy-only installs.
		$app_design      = $cn->is_network_options()
			? get_site_option( 'cookie_notice_app_design', [] )
			: get_option( 'cookie_notice_app_design', [] );
		$banner_position = ! empty( $app_design['position'] )
			? sanitize_key( $app_design['position'] )
			: ( $cn->options['general']['position'] ?? 'bottom' );

		// get cookie container args
		$options = apply_filters( 'cn_cookie_notice_args', [
			'position'				=> $banner_position,
			'css_class'				=> $cn->options['general']['css_class'],
			'button_class'			=> 'cn-button',
			'colors'				=> $cn->options['general']['colors'],
			'message_text'			=> $cn->options['general']['message_text'],
			'accept_text'			=> $cn->options['general']['accept_text'],
			'refuse_text'			=> $cn->options['general']['refuse_text'],
			'revoke_message_text'	=> $cn->options['general']['revoke_message_text'],
			'revoke_text'			=> $cn->options['general']['revoke_text'],
			'refuse_opt'			=> $cn->options['general']['refuse_opt'],
			'revoke_cookies'		=> $cn->options['general']['revoke_cookies'],
			'see_more'				=> $cn->options['general']['see_more'],
			'see_more_opt'			=> $cn->options['general']['see_more_opt'],
			'link_target'			=> $cn->options['general']['link_target'],
			'link_position'			=> $cn->options['general']['link_position'],
			'aria_label'			=> 'Cookie Compliance'
		] );

		// message output
		$output = '
		<!-- Cookie Compliance for WordPress (formerly Compliance by Hu-manity.co) plugin v' . esc_attr( $cn->defaults['version'] ) . ' https://cookie-compliance.co/ -->
		<div id="cookie-notice" role="dialog" class="cookie-notice-hidden cookie-revoke-hidden cn-position-' . esc_attr( $options['position'] ) . '" aria-label="' . esc_attr( $options['aria_label'] ) . '" style="background-color: __CN_BG_COLOR__">'
			. '<div class="cookie-notice-container" style="color: ' . esc_attr( $options['colors']['text'] ) . '">'
			. '<span id="cn-notice-text" class="cn-text-container">'. ( $options['see_more'] ? do_shortcode( $options['message_text'] ) : $options['message_text'] ) . '</span>'
			. '<span id="cn-notice-buttons" class="cn-buttons-container"><button id="cn-accept-cookie" data-cookie-set="accept" class="cn-set-cookie ' . esc_attr( $options['button_class'] ) . ( $options['css_class'] !== '' ? ' cn-button-custom ' . esc_attr( $options['css_class'] ) : '' ) . '" aria-label="' . esc_attr( $options['accept_text'] ) . '"' . ( $options['css_class'] == '' ? ' style="background-color: ' . esc_attr( $options['colors']['button'] ) . '"' : '' ) . '>' . esc_html( $options['accept_text'] ) . '</button>'
			. ( $options['refuse_opt'] ? '<button id="cn-refuse-cookie" data-cookie-set="refuse" class="cn-set-cookie ' . esc_attr( $options['button_class'] ) . ( $options['css_class'] !== '' ? ' cn-button-custom ' . esc_attr( $options['css_class'] ) : '' ) . '" aria-label="' . esc_attr( $options['refuse_text'] ) . '"' . ( $options['css_class'] == '' ? ' style="background-color: ' . esc_attr( $options['colors']['button'] ) . '"' : '' ) . '>' . esc_html( $options['refuse_text'] ) . '</button>' : '' )
			. ( $options['see_more'] && $options['link_position'] === 'banner' ? '<button data-link-url="' . esc_url( $options['see_more_opt']['link_type'] === 'custom' ? $options['see_more_opt']['link'] : $permalink ) . '" data-link-target="' . esc_attr( $options['link_target'] ) . '" id="cn-more-info" class="cn-more-info ' . esc_attr( $options['button_class'] ) . ( $options['css_class'] !== '' ? ' cn-button-custom ' . esc_attr( $options['css_class'] ) : '' ) . '" aria-label="' . esc_attr( $options['see_more_opt']['text'] ) . '"' . ( $options['css_class'] == '' ? ' style="background-color: ' . esc_attr( $options['colors']['button'] ) . '"' : '' ) . '>' . esc_html( $options['see_more_opt']['text'] ) . '</button>' : '' )
			. '</span><button type="button" id="cn-close-notice" data-cookie-set="accept" class="cn-close-icon" aria-label="' . esc_attr( $options['refuse_text'] ) . '" tabindex="0"></button>'
			. '</div>
			' . ( $options['refuse_opt'] && $options['revoke_cookies'] ?
			'<div class="cookie-revoke-container" style="color: ' . esc_attr( $options['colors']['text'] ) . '">'
			. ( ! empty( $options['revoke_message_text'] ) ? '<span id="cn-revoke-text" class="cn-text-container">' . $options['revoke_message_text'] . '</span>' : '' )
			. '<span id="cn-revoke-buttons" class="cn-buttons-container"><button id="cn-revoke-cookie" class="cn-revoke-cookie ' . esc_attr( $options['button_class'] ) . ( $options['css_class'] !== '' ? ' cn-button-custom ' . esc_attr( $options['css_class'] ) : '' ) . '" aria-label="' . esc_attr( $options['revoke_text'] ) . '"' . ( $options['css_class'] == '' ? ' style="background-color: ' . esc_attr( $options['colors']['button'] ) . '"' : '' ) . '>' . esc_html( $options['revoke_text'] ) . '</button></span>
			</div>' : '' ) . '
		</div>
		<!-- / Cookie Compliance for WordPress plugin -->';

		add_filter( 'safe_style_css', [ $this, 'allow_style_attributes' ] );

		$output = apply_filters( 'cn_cookie_notice_output', wp_kses_post( $output ), $options );

		remove_filter( 'safe_style_css', [ $this, 'allow_style_attributes' ] );

		// convert rgb color to hex
		$bg_rgb_color = $this->hex2rgb( $options['colors']['bar'] );

		// invalid color? use default
		if ( $bg_rgb_color === false )
			$bg_rgb_color = $this->hex2rgb( $cn->defaults['general']['colors']['bar'] );

		// allow rgba background
		echo str_replace( '__CN_BG_COLOR__', esc_attr( 'rgba(' . implode( ',', $bg_rgb_color ) . ',' . ( (int) $options['colors']['bar_opacity'] ) * 0.01 . ');' ), $output );

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

	/**
	 * Convert HEX to RGB color.
	 *
	 * @param string $color
	 * @return bool|array
	 */
	public function hex2rgb( $color ) {
		if ( ! is_string( $color ) )
			return false;

		// with hash?
		if ( $color[0] === '#' )
			$color = substr( $color, 1 );

		if ( sanitize_hex_color_no_hash( $color ) !== $color )
			return false;

		// 6 hex digits?
		if ( strlen( $color ) === 6 )
			list( $r, $g, $b ) = [ $color[0] . $color[1], $color[2] . $color[3], $color[4] . $color[5] ];
		// 3 hex digits?
		elseif ( strlen( $color ) === 3 )
			list( $r, $g, $b ) = [ $color[0] . $color[0], $color[1] . $color[1], $color[2] . $color[2] ];
		else
			return false;

		return [ 'r' => hexdec( $r ), 'g' => hexdec( $g ), 'b' => hexdec( $b ) ];
	}

	/**
	 * Add blocking class to scripts, iframes and links.
	 *
	 * @param string $type
	 * @param string $code
	 * @return string
	 */
	public function add_block_class( $type, $code ) {
		// clear and disable libxml errors and allow user to fetch error information as needed
		libxml_use_internal_errors( true );

		// create new dom object
		$document = new DOMDocument( '1.0', 'UTF-8' );

		// set attributes
		$document->formatOutput = true;
		$document->preserveWhiteSpace = false;

		// load code
		$document->loadHTML( '<div>' . wp_kses( trim( $code ), Cookie_Notice()->get_allowed_html( $type ) ) . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		$container = $document->getElementsByTagName( 'div' )->item( 0 );
		$container = $container->parentNode->removeChild( $container );

		while ( $document->firstChild ) {
			$document->removeChild( $document->firstChild );
		}

		while ( $container->firstChild ) {
			$document->appendChild( $container->firstChild );
		}

		// set blocked tags
		if ( $type === 'body' )
			$blocked_tags = [ 'script', 'iframe' ];
		elseif ( $type === 'head' )
			$blocked_tags = [ 'script', 'link' ];

		foreach ( $blocked_tags as $blocked_tag ) {
			$tags = $document->getElementsByTagName( $blocked_tag );

			// any tags?
			if ( ! empty( $tags ) && is_object( $tags ) ) {
				foreach ( $tags as $tag ) {
					$tag->setAttribute( 'class', 'hu-block' );
				}
			}
		}

		// save new HTML
		$output = $document->saveHTML();

		// reenable libxml errors
		libxml_use_internal_errors( false );

		return $output;
	}

	/**
	 * Load notice scripts and styles - frontend.
	 *
	 * @return void
	 */
	public function wp_enqueue_notice_scripts() {
		// skip modal login iframe
		if ( current_filter() === 'login_enqueue_scripts' && ! empty( $_REQUEST['interim-login'] ) )
			return;

		// force script if a reopen shortcode is present on the page
		$force_enqueue = $this->has_reopen_shortcode();

		if ( $this->compliance && ! $force_enqueue )
			return;

		// is banner allowed to display?
		if ( ! $force_enqueue && ! $this->maybe_display_banner() )
			return;

		// get main instance
		$cn = Cookie_Notice();

		wp_enqueue_script( 'cookie-notice-front', COOKIE_NOTICE_URL . '/js/front' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.js', [], $cn->defaults['version'], isset( $cn->options['general']['script_placement'] ) && $cn->options['general']['script_placement'] === 'footer' );

		// not array? changeable by cn_cookie_expiry filter
		if ( is_array( $cn->settings->times ) ) {
			// check cookie expiration time
			if ( array_key_exists( $cn->options['general']['time'], $cn->settings->times ) && array_key_exists( 1, $cn->settings->times[$cn->options['general']['time']] ) )
				$cookie_time = (int) $cn->settings->times[$cn->options['general']['time']][1];
			else {
				// fallback to default length of month
				$cookie_time = MONTH_IN_SECONDS;
			}

			// check cookie rejection expiration time
			if ( array_key_exists( $cn->options['general']['time_rejected'], $cn->settings->times ) && array_key_exists( 1, $cn->settings->times[$cn->options['general']['time_rejected']] ) )
				$cookie_time_rejected = (int) $cn->settings->times[$cn->options['general']['time_rejected']][1];
			else {
				// fallback to default length of month
				$cookie_time_rejected = MONTH_IN_SECONDS;
			}
		} else {
			// fallback to default length of month
			$cookie_time = $cookie_time_rejected = MONTH_IN_SECONDS;
		}

		// #2266: position is API-owned — read from cookie_notice_app_design for connected sites.
		// Falls back to cookie_notice_options["general"]["position"] for disconnected/legacy-only installs.
		// (Same resolution as add_cookie_notice() — duplicated here because this is a separate WP hook.)
		$app_design      = $cn->is_network_options()
			? get_site_option( 'cookie_notice_app_design', [] )
			: get_option( 'cookie_notice_app_design', [] );
		$banner_position = ! empty( $app_design['position'] )
			? sanitize_key( $app_design['position'] )
			: ( $cn->options['general']['position'] ?? 'bottom' );

		// prepare script data
		$script_data = [
			'ajaxUrl'				=> admin_url( 'admin-ajax.php' ),
			'nonce'					=> wp_create_nonce( 'cn_save_cases' ),
			'hideEffect'			=> $cn->options['general']['hide_effect'],
			'position'				=> $banner_position,
			'onScroll'				=> $cn->options['general']['on_scroll'],
			'onScrollOffset'		=> (int) $cn->options['general']['on_scroll_offset'],
			'onClick'				=> $cn->options['general']['on_click'],
			'cookieName'			=> 'cookie_notice_accepted',
			'cookieTime'			=> $cookie_time,
			'cookieTimeRejected'	=> $cookie_time_rejected,
			'globalCookie'			=> is_multisite() && $cn->options['general']['global_cookie'] && is_subdomain_install(),
			'redirection'			=> $cn->options['general']['redirection'],
			'cache'					=> defined( 'WP_CACHE' ) && WP_CACHE,
			'revokeCookies'			=> $cn->options['general']['revoke_cookies'],
			'revokeCookiesOpt'		=> $cn->options['general']['revoke_cookies_opt']
		];

		wp_add_inline_script( 'cookie-notice-front', 'var cnArgs = ' . wp_json_encode( $script_data ) . ";\n", 'before' );

		wp_enqueue_style( 'cookie-notice-front', COOKIE_NOTICE_URL . '/css/front' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.css', [], $cn->defaults['version'] );
	}

	/**
	 * Print non functional JavaScript in body.
	 *
	 * @return void
	 */
	public function wp_print_footer_scripts() {
		// get main instance
		$cn = Cookie_Notice();

		if ( $cn->cookies_accepted() || $this->compliance ) {
			$scripts = apply_filters( 'cn_refuse_code_scripts_html', $cn->options['general']['refuse_code'], 'body' );

			if ( ! empty( $scripts ) )
				echo html_entity_decode( wp_kses( $scripts, $cn->get_allowed_html( 'body' ) ) );
		}
	}

	/**
	 * Print non functional JavaScript in header.
	 *
	 * @return void
	 */
	public function wp_print_header_scripts() {
		// get main instance
		$cn = Cookie_Notice();

		if ( $cn->cookies_accepted() || $this->compliance ) {
			$scripts = apply_filters( 'cn_refuse_code_scripts_html', $cn->options['general']['refuse_code_head'], 'head' );

			if ( ! empty( $scripts ) )
				echo html_entity_decode( wp_kses( $scripts, $cn->get_allowed_html( 'head' ) ) );
		}
	}

	/**
	 * Add new body classes.
	 *
	 * @param array $classes Body classes
	 * @return array
	 */
	public function change_body_class( $classes ) {
		if ( is_admin() )
			return $classes;

		if ( Cookie_Notice()->cookies_set() ) {
			$classes[] = 'cookies-set';

			if ( Cookie_Notice()->cookies_accepted() )
				$classes[] = 'cookies-accepted';
			else
				$classes[] = 'cookies-refused';
		} else
			$classes[] = 'cookies-not-set';

		return $classes;
	}

	/**
	 * Detect if reopen shortcode is present on the current singular content.
	 *
	 * @return bool
	 */
	private function has_reopen_shortcode() {
		if ( ! is_singular() )
			return false;

		global $post;

		if ( empty( $post ) || ! property_exists( $post, 'post_content' ) )
			return false;

		return has_shortcode( $post->post_content, 'cookies_revoke' );
	}

	/**
	 * Resolve the active app credentials (network-aware), mirroring purge_cache().
	 *
	 * @return array { app_id, app_key, network } — empty strings when unpaired.
	 */
	private function get_app_credentials() {
		$cn = Cookie_Notice();

		if ( is_multisite() && $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'] ) {
			return [
				'app_id'  => $cn->network_options['general']['app_id'],
				'app_key' => $cn->network_options['general']['app_key'],
				'network' => true,
			];
		}

		return [
			'app_id'  => $cn->options['general']['app_id'],
			'app_key' => $cn->options['general']['app_key'],
			'network' => false,
		];
	}

	/**
	 * Register the authenticated server-to-server cache-purge REST route.
	 *
	 * Added in 3.1.3. Lets our backend (Designer API on publish, Account API on
	 * plan change) force a config + tier re-pull immediately, instead of waiting
	 * on the WP-Cron pull (daily active / hourly inactive). Authentication is the
	 * shared app secret (app-secret-key header) — no WP login / nonce, by design.
	 *
	 * @return void
	 */
	public function register_purge_route() {
		register_rest_route(
			'cookie-notice/v1',
			'/purge',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_purge_cache' ],
				'permission_callback' => [ $this, 'rest_purge_permission_check' ],
			]
		);
	}

	/**
	 * Permission callback for the purge route.
	 *
	 * Fails closed: rejects unpaired sites, non-TLS requests, app-id mismatch, and
	 * any secret mismatch (constant-time). Returns bool only — no detail leaks to
	 * the caller on denial.
	 *
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	public function rest_purge_permission_check( $request ) {
		$creds = $this->get_app_credentials();

		// unpaired site — no credentials to verify against
		if ( $creds['app_id'] === '' || $creds['app_key'] === '' )
			return false;

		// the secret travels in the header — require TLS
		if ( ! is_ssl() )
			return false;

		$req_app_id = (string) $request->get_header( 'app-id' );
		$req_secret = (string) $request->get_header( 'app-secret-key' );

		if ( $req_app_id === '' || $req_secret === '' )
			return false;

		// app-id must match the paired app
		if ( ! hash_equals( (string) $creds['app_id'], $req_app_id ) )
			return false;

		// constant-time secret compare
		return hash_equals( (string) $creds['app_key'], $req_secret );
	}

	/**
	 * Handle an authenticated purge request.
	 *
	 * Mirrors ajax_purge_cache() (settings.php) so the server path and the admin
	 * "Purge Cache" button behave identically. A short per-site cooldown bounds
	 * forced re-pull amplification toward our own Designer API.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function rest_purge_cache( $request ) {
		$cn    = Cookie_Notice();
		$creds = $this->get_app_credentials();

		// per-site cooldown (2 min) — reject rapid repeats with 429
		$cooldown    = 120;
		$cooldown_at = $creds['network'] ? get_site_transient( 'cookie_notice_purge_cooldown' ) : get_transient( 'cookie_notice_purge_cooldown' );

		if ( $cooldown_at !== false )
			return new WP_REST_Response( [ 'purged' => false, 'reason' => 'cooldown' ], 429 );

		if ( $creds['network'] )
			set_site_transient( 'cookie_notice_purge_cooldown', current_time( 'timestamp', true ), $cooldown );
		else
			set_transient( 'cookie_notice_purge_cooldown', current_time( 'timestamp', true ), $cooldown );

		// force a config + tier re-pull (bypasses the 1h throttle)
		$cn->welcome_api->get_app_config( $creds['app_id'], true );

		// re-evaluate CSP state (parity with the admin Purge button)
		$cn->settings->refresh_csp_notice( true );

		// tell the frontend JS widget to bust its client cache
		if ( $cn->is_network_options() )
			set_site_transient( 'cookie_notice_config_update', current_time( 'timestamp', true ), 600 );
		else
			set_transient( 'cookie_notice_config_update', current_time( 'timestamp', true ), 600 );

		return new WP_REST_Response( [ 'purged' => true ], 200 );
	}

}
