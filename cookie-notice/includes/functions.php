<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Check if cookies are accepted.
 *
 * @return bool Whether cookies are accepted
 */
if ( ! function_exists( 'cn_cookies_accepted' ) ) {
	function cn_cookies_accepted() {
		return (bool) Cookie_Notice::cookies_accepted();
	}
}

/**
 * Check if cookies are set.
 *
 * @return bool Whether cookies are set
 */
if ( ! function_exists( 'cn_cookies_set' ) ) {
	function cn_cookies_set() {
		return (bool) Cookie_Notice::cookies_set();
	}
}

/**
 * Get active caching plugins.
 *
 * @param array $args
 * @return array
 */
function cn_get_active_caching_plugins( $args = [] ) {
	if ( isset( $args['versions'] ) && $args['versions'] === true )
		$version = true;
	else
		$version = false;

	$active_plugins = [];

	// autoptimize
	if ( cn_is_plugin_active( 'autoptimize' ) ) {
		if ( $version )
			$active_plugins['Autoptimize'] = '2.4.0';
		else
			$active_plugins[] = 'Autoptimize';
	}

	// wp-optimize
	if ( cn_is_plugin_active( 'wpoptimize' ) ) {
		if ( $version )
			$active_plugins['WP-Optimize'] = '3.0.12';
		else
			$active_plugins[] = 'WP-Optimize';
	}

	// litespeed
	if ( cn_is_plugin_active( 'litespeed' ) ) {
		if ( $version )
			$active_plugins['LiteSpeed Cache'] = '3.0.0';
		else
			$active_plugins[] = 'LiteSpeed Cache';
	}

	// speed optimizer
	if ( cn_is_plugin_active( 'speedoptimizer' ) ) {
		if ( $version )
			$active_plugins['Speed Optimizer'] = '5.5.0';
		else
			$active_plugins[] = 'Speed Optimizer';
	}

	// wp fastest cache
	if ( cn_is_plugin_active( 'wpfastestcache' ) ) {
		if ( $version )
			$active_plugins['WP Fastest Cache'] = '1.0.0';
		else
			$active_plugins[] = 'WP Fastest Cache';
	}

	// wp rocket
	if ( cn_is_plugin_active( 'wprocket' ) ) {
		if ( $version )
			$active_plugins['WP Rocket'] = '3.8.0';
		else
			$active_plugins[] = 'WP Rocket';
	}

	// hummingbird
	if ( cn_is_plugin_active( 'hummingbird' ) ) {
		if ( $version )
			$active_plugins['Hummingbird'] = '2.1.0';
		else
			$active_plugins[] = 'Hummingbird';
	}

	// wp super cache
	if ( cn_is_plugin_active( 'wpsupercache' ) ) {
		if ( $version )
			$active_plugins['WP Super Cache'] = '1.6.9';
		else
			$active_plugins[] = 'WP Super Cache';
	}

	// breeze
	if ( cn_is_plugin_active( 'breeze' ) ) {
		if ( $version )
			$active_plugins['Breeze'] = '1.1.0';
		else
			$active_plugins[] = 'Breeze';
	}

	// speedycache
	if ( cn_is_plugin_active( 'speedycache' ) ) {
		if ( $version )
			$active_plugins['SpeedyCache'] = '1.0.0';
		else
			$active_plugins[] = 'SpeedyCache';
	}

	return $active_plugins;
}

/**
 * Check whether specified plugin is active.
 *
 * @global object $siteground_optimizer_loader
 * @global int $wpsc_version
 *
 * @param string $plugin
 * @param string $module
 * @return bool
 */
function cn_is_plugin_active( $plugin = '', $module = 'caching' ) {
	// no valid plugin?
	if ( ! in_array( $plugin, [
		'amp',
		'autoptimize',
		'bestwebsoftrecaptcha',
		'breeze',
		'contactform7',
		'divi',
		'easydigitaldownloads',
		'elementor',
		'formidableforms',
		'gravityforms',
		'hummingbird',
		'litespeed',
		'mailchimp',
		'speedoptimizer',
		'speedycache',
		'woocommerce',
		'wpfastestcache',
		'wpforms',
		'wpoptimize',
		'wprocket',
		'wpsupercache'
	], true ) )
		return false;

	// set default flag
	$is_plugin_active = false;

	switch ( $plugin ) {
		// amp
		case 'amp':
			if ( $module === 'caching' && function_exists( 'amp_is_enabled' ) && defined( 'AMP__VERSION' ) && version_compare( AMP__VERSION, '2.0', '>=' ) )
				$is_plugin_active = true;
			break;

		// autoptimize
		case 'autoptimize':
			if ( $module === 'caching' && function_exists( 'autoptimize' ) && defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) && version_compare( AUTOPTIMIZE_PLUGIN_VERSION, '2.4', '>=' ) )
				$is_plugin_active = true;
			break;

		// bestwebsoft recaptcha
		case 'bestwebsoftrecaptcha':
			// "reCaptcha by BestWebSoft" (google-captcha) and its paid Pro build define
			// no class and no version constant — only prefixed functions — so there is
			// nothing stabler to key on and no version to compare. That is tolerable
			// here because the module never rewrites their markup or holds their
			// scripts: it calls one public global, gglcptch.prepare(), and only when it
			// can see a reCAPTCHA the widget has actually blocked. A build that renamed
			// that global would make the module inert, not harmful.
			if ( $module === 'captcha' && function_exists( 'gglcptch_display' ) && function_exists( 'gglcptch_add_scripts' ) )
				$is_plugin_active = true;
			break;

		// breeze
		case 'breeze':
			if ( $module === 'caching' && class_exists( 'Breeze_PurgeCache' ) && class_exists( 'Breeze_Options_Reader' ) && function_exists( 'breeze_get_option' ) && function_exists( 'breeze_update_option' ) && defined( 'BREEZE_VERSION' ) && version_compare( BREEZE_VERSION, '1.1.0', '>=' ) )
				$is_plugin_active = true;
			break;

		// contact form 7
		case 'contactform7':
			if ( $module === 'captcha' && class_exists( 'WPCF7' ) && class_exists( 'WPCF7_RECAPTCHA' ) && defined( 'WPCF7_VERSION' ) && version_compare( WPCF7_VERSION, '5.1', '>=' ) )
				$is_plugin_active = true;
			elseif ( $module === 'privacy-consent' && class_exists( 'WPCF7' ) && defined( 'WPCF7_VERSION' ) && version_compare( WPCF7_VERSION, '5.3', '>=' ) )
				$is_plugin_active = true;
			break;

		// divi
		case 'divi':
			if ( $module === 'theme' && function_exists( 'is_et_pb_preview' ) && defined( 'ET_CORE_VERSION' ) )
				$is_plugin_active = true;
			break;

		// easy digital downloads
		case 'easydigitaldownloads':
			if ( $module === 'privacy-consent' && class_exists( 'Easy_Digital_Downloads' ) && function_exists( 'EDD' ) && defined( 'EDD_VERSION' ) && version_compare( EDD_VERSION, '3.0.0', '>=' ) )
				$is_plugin_active = true;
			break;

		// elementor
		case 'elementor':
			if ( $module === 'caching' && did_action( 'elementor/loaded' ) && defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, '1.3', '>=' ) )
				$is_plugin_active = true;
			break;

		// formidable forms
		case 'formidableforms':
			if ( $module === 'privacy-consent' && class_exists( 'FrmAppHelper' ) && method_exists( 'FrmAppHelper', 'plugin_version' ) && version_compare( FrmAppHelper::plugin_version(), '2.0', '>=' ) )
				$is_plugin_active = true;
			break;

		// gravity forms
		case 'gravityforms':
			// GFForms is Gravity Forms core's main class. Note the reCAPTCHA ADD-ON is
			// deliberately not detected here — it is a paid bundle whose class name we
			// have no copy of, and guessing one that never matches would silently do
			// nothing. The module keys off the add-on's script handle instead, so it is
			// simply inert when the add-on is absent.
			if ( $module === 'captcha' && class_exists( 'GFForms' ) )
				$is_plugin_active = true;
			break;

		// hummingbird
		case 'hummingbird':
			if ( $module === 'caching' && class_exists( 'Hummingbird\\WP_Hummingbird' ) && defined( 'WPHB_VERSION' ) && version_compare( WPHB_VERSION, '2.1.0', '>=' ) )
				$is_plugin_active = true;
			break;

		// litespeed
		case 'litespeed':
			if ( $module === 'caching' && class_exists( 'LiteSpeed\Core' ) && defined( 'LSCWP_CUR_V' ) && version_compare( LSCWP_CUR_V, '3.0', '>=' ) )
				$is_plugin_active = true;
			break;

		// mailchimp
		case 'mailchimp':
			if ( $module === 'privacy-consent' && class_exists( 'MC4WP_Form_Manager' ) && defined( 'MC4WP_VERSION' ) && version_compare( MC4WP_VERSION, '4.0', '>=' ) )
				$is_plugin_active = true;
			break;

		// speed optimizer
		case 'speedoptimizer':
			global $siteground_optimizer_loader;

			if ( $module === 'caching' && ! empty( $siteground_optimizer_loader ) && is_object( $siteground_optimizer_loader ) && is_a( $siteground_optimizer_loader, 'SiteGround_Optimizer\Loader\Loader' ) && defined( '\SiteGround_Optimizer\VERSION' ) && version_compare( \SiteGround_Optimizer\VERSION, '5.5', '>=' ) )
				$is_plugin_active = true;
			break;

		// speedycache
		case 'speedycache':
			if ( $module === 'caching' && class_exists( 'SpeedyCache' ) && defined( 'SPEEDYCACHE_VERSION' ) && function_exists( 'speedycache_delete_cache' ) && version_compare( SPEEDYCACHE_VERSION, '1.0.0', '>=' ) )
				$is_plugin_active = true;
			break;

		// woocommerce
		case 'woocommerce':
			if ( $module === 'privacy-consent' && class_exists( 'WooCommerce' ) && defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '4.0.4', '>=' ) )
				$is_plugin_active = true;
			break;

		// wp fastest cache
		case 'wpfastestcache':
			if ( $module === 'caching' && function_exists( 'wpfc_clear_all_cache' ) )
				$is_plugin_active = true;
			break;

		// wpforms
		case 'wpforms':
			if ( $module === 'privacy-consent' && function_exists( 'wpforms' ) && defined( 'WPFORMS_VERSION' ) && version_compare( WPFORMS_VERSION, '1.6.0', '>=' ) )
				$is_plugin_active = true;
			break;

		// wp-optimize
		case 'wpoptimize':
			if ( $module === 'caching' && function_exists( 'WP_Optimize' ) && defined( 'WPO_VERSION' ) && version_compare( WPO_VERSION, '3.0.12', '>=' ) )
				$is_plugin_active = true;
			break;

		// wp rocket
		case 'wprocket':
			if ( $module === 'caching' && function_exists( 'rocket_init' ) && defined( 'WP_ROCKET_VERSION' ) && version_compare( WP_ROCKET_VERSION, '3.8', '>=' ) )
				$is_plugin_active = true;
			break;

		// wp super cache
		case 'wpsupercache':
			if ( $module === 'caching' ) {
				$plugin_name = 'wp-super-cache/wp-cache.php';
				$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_name;

				if ( file_exists( $plugin_path ) && is_plugin_active( $plugin_name ) ) {
					$plugin = get_plugin_data( $plugin_path, false, false );

					if ( version_compare( $plugin['Version'], '1.6.3', '>=' ) && function_exists( 'wp_cache_is_enabled' ) && function_exists( 'wp_cache_clean_cache' ) && function_exists( 'wpsc_add_cookie' ) && function_exists( 'wpsc_delete_cookie' ) )
						$is_plugin_active = true;
				}
			}
			break;
	}

	return $is_plugin_active;
}

/**
 * Detect active analytics/tracking plugins for consent mode suggestions.
 *
 * @return array List of detected plugin categories: 'google', 'facebook', 'microsoft'.
 */
function cn_detect_active_plugins() {
	$checks = [
		'google'    => [
			'google-analytics-for-wordpress/googleanalytics.php',
			'google-site-kit/google-site-kit.php',
			'wp-google-analytics/wp-google-analytics.php',
			'gtm4wp/gtm4wp.php',
		],
		'facebook'  => [
			'official-facebook-pixel/facebook-for-wordpress.php',
			'pixel-caffeine/pixel-caffeine.php',
		],
		'microsoft' => [
			'clarity-analytics/clarity-analytics.php',
		],
	];

	$detected = [];

	foreach ( $checks as $mode => $plugins ) {
		foreach ( $plugins as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				$detected[] = $mode;
				break;
			}
		}
	}

	return array_values( array_unique( $detected ) );
}

/**
 * Feature flags reported in integration telemetry, as option key => short name.
 *
 * A function rather than an inlined literal so the builder and its test read
 * one list. Short names travel in an HTTP header, hence the length.
 *
 * @return array
 */
function cn_get_telemetry_flag_map() {
	return [
		'app_blocking'			=> 'blocking',
		'caching_compatibility'	=> 'caching',
		'wp_consent_api'		=> 'consentapi',
		'amp_support'			=> 'amp',
		'bot_detection'			=> 'botdetect',
		'conditional_active'	=> 'conditional',
		'debug_mode'			=> 'debug',
		'global_override'		=> 'globaloverride'
	];
}

/**
 * Integration telemetry for connected-site platform requests.
 *
 * Every value here is either something the plugin ALREADY computes for the
 * service to work (which optimizer is active, which tracker plugins are
 * present) or a scalar describing this integration's own state. Nothing here
 * describes the site's content, its visitors, or its plugin inventory at
 * large: readme.txt's "Data the plugin does not send" is the boundary, and its
 * disclosed "Integration telemetry" category is the licence for what is here.
 *
 * CONNECTED MODE ONLY, and this function must never be the reason a request
 * happens. readme.txt states without qualification that a plugin-only install
 * "does not initiate calls to Hu-manity.co services"; that promise is stricter
 * than wordpress.org Guideline 7 and it is the binding one. This only ever
 * rides a request the plugin was already making — see
 * Cookie_Notice_Welcome_API::request(), which is reached only from connected
 * paths.
 *
 * @return array Flat map of scalars. Callers encode via cn_encode_integration_telemetry().
 */
function cn_get_integration_telemetry() {
	$cn = Cookie_Notice();

	$options = isset( $cn->options['general'] ) && is_array( $cn->options['general'] ) ? $cn->options['general'] : [];

	// cn_is_plugin_active()'s wpsupercache branch and cn_detect_active_plugins()
	// both call is_plugin_active(), which lives in an admin-only include. The
	// constructor's set_network_data() already loads it on every boot, but that
	// is a distant side effect to depend on — mirror its guard rather than
	// inherit a fatal if it ever moves.
	if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) )
		require_once( ABSPATH . '/wp-admin/includes/plugin.php' );

	$telemetry = [];

	// The version of the code that is RUNNING. Deliberately NOT $cn->db_version:
	// that is the cookie_notice_version option, i.e. the version at the last
	// COMPLETED upgrade routine, which lags the running code on any site whose
	// upgrade path has not fired. Reporting db_version is why the platform
	// records 2.5.x for sites demonstrably running current code, and it makes
	// the only patch-level adoption signal we have wrong rather than merely
	// partial.
	$telemetry['version'] = isset( $cn->defaults['version'] ) ? (string) $cn->defaults['version'] : '';

	// A lagging db_version is itself worth knowing: it means the upgrade routine
	// has not completed here, which is a support answer rather than a version.
	if ( ! empty( $cn->db_version ) && $telemetry['version'] !== '' && version_compare( (string) $cn->db_version, $telemetry['version'], '<' ) )
		$telemetry['dbversion'] = (string) $cn->db_version;

	// Admin language. Tells us which translations are worth funding now that the
	// admin is translatable; disclosed as operational metadata already.
	if ( function_exists( 'get_locale' ) )
		$telemetry['locale'] = (string) get_locale();

	// Which JS-exclusion-capable optimizer is in play. This is the single
	// largest cause of a banner that "disappeared": an optimizer that combines
	// or defers our script arms pre-consent blocking too late, or not at all.
	// Detection is cn_is_plugin_active()'s, which the plugin already runs on
	// every load to register those exclusion filters (see DEC-006) — no new
	// detection, and nothing here the service was not already looking at.
	if ( function_exists( 'is_plugin_active' ) ) {
		$optimizers = [];

		foreach ( [ 'autoptimize', 'breeze', 'hummingbird', 'litespeed', 'speedoptimizer', 'speedycache', 'wpfastestcache', 'wpoptimize', 'wprocket', 'wpsupercache' ] as $optimizer ) {
			if ( cn_is_plugin_active( $optimizer ) )
				$optimizers[] = $optimizer;
		}

		if ( ! empty( $optimizers ) )
			$telemetry['optimizers'] = implode( ',', $optimizers );

		// Tracker plugins, as already resolved for Consent Mode seeding.
		$trackers = cn_detect_active_plugins();

		if ( ! empty( $trackers ) )
			$telemetry['trackers'] = implode( ',', $trackers );
	}

	// Which of our own features are switched on. Always emitted — "none" rather
	// than an absent key — so a reader can tell "every flag off" from "a plugin
	// version that does not report flags".
	$flags = [];

	foreach ( cn_get_telemetry_flag_map() as $option => $flag ) {
		if ( ! empty( $options[ $option ] ) )
			$flags[] = $flag;
	}

	if ( function_exists( 'is_multisite' ) && is_multisite() )
		$flags[] = 'multisite';

	$telemetry['flags'] = ! empty( $flags ) ? implode( ',', $flags ) : 'none';

	// COUNT, never the handles themselves: a handle names something the site
	// runs, which is site detail we have no reason to hold.
	if ( ! empty( $options['excluded_handles'] ) )
		$telemetry['handles'] = (string) count( (array) $options['excluded_handles'] );

	// Cron health, reported only when UNHEALTHY — absence means fine. Between
	// them these explain a whole class of "I bought Pro but still see the Free
	// limit" tickets: config never refreshes, so the local cache stays stale.
	$cron = [];

	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
		$cron[] = 'disabled';

	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'cookie_notice_get_app_config' ) )
		$cron[] = 'unscheduled';

	if ( ! empty( $cron ) )
		$telemetry['cron'] = implode( ',', $cron );

	return $telemetry;
}

/**
 * Encode integration telemetry for transport in a single HTTP header.
 *
 * `key=value;key=value`. Values are restricted to a conservative charset so a
 * stray character in a locale or option value can never break the header or
 * smuggle anything into it — anything else is dropped, and an empty value
 * drops its key entirely. Length is capped for the same reason.
 *
 * @param array $telemetry
 * @return string Empty string when there is nothing safe to send.
 */
function cn_encode_integration_telemetry( $telemetry ) {
	if ( ! is_array( $telemetry ) )
		return '';

	$pairs = [];

	foreach ( $telemetry as $key => $value ) {
		$key = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $key ) );
		$value = preg_replace( '/[^A-Za-z0-9._,-]/', '', (string) $value );

		if ( $key === '' || $value === '' )
			continue;

		$pairs[] = $key . '=' . $value;
	}

	if ( empty( $pairs ) )
		return '';

	return substr( implode( ';', $pairs ), 0, 512 );
}