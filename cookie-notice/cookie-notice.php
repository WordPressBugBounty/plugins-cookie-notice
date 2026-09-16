<?php
/*
Plugin Name: Cookie Compliance for WordPress – Cookie Consent, GDPR & CCPA
Description: Cookie Compliance for WordPress (formerly "Compliance by Hu-manity.co" / "Cookie Notice") — the WordPress component of Cookie Compliance, the consent management platform by Hu-manity.co. Cookie consent banner, pre-consent script blocking, Google Consent Mode v2, WP Consent API integration, and consent records for GDPR, CCPA and global data privacy laws.
Version: 3.1.10
Author: Hu-manity.co
Author URI: https://hu-manity.co/
Plugin URI: https://cookie-compliance.co/
License: MIT License
License URI: https://opensource.org/licenses/MIT
Text Domain: cookie-notice
Domain Path: /languages

Cookie Compliance for WordPress
Copyright (C) 2026, Hu-manity.co - info@hu-manity.co

NAMING NOTE (DEC-007, 2026-08-18): the product is "Cookie Compliance", by Hu-manity.co. The
"cookie-notice" slug, text domain, option keys (cookie_notice_*), CSS classes and cn_/cookie_notice_
hook prefixes are LOAD-BEARING and intentionally do NOT match the brand. Renaming the wordpress.org
slug creates a new listing and forfeits 900K+ installs and 3,000+ reviews; changing the text domain
orphans every translation. Do not "tidy" them for consistency.

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie Notice class.
 *
 * @class Cookie_Notice
 * @version	2.5.14
 */
class Cookie_Notice {

	/**
	 * React admin asset identifiers.
	 *
	 * Single source of truth for the bundle name, script handle, and inline
	 * data global emitted by wp_localize_script. Referenced by:
	 * - Cookie_Notice_Settings::admin_enqueue_scripts() — enqueue + localize
	 * - Cookie_Notice_Settings::add_react_admin_optimizer_attrs() — script_loader_tag filter
	 * - includes/modules/<vendor>/<vendor>.php — optimizer-exclusion filters
	 *
	 * Anything that grep-finds "REACT_ADMIN_" is a call site for these.
	 */
	const REACT_ADMIN_HANDLE          = 'cookie-notice-react-admin';
	const REACT_ADMIN_BUNDLE_BASENAME = 'cn-admin-react.js';
	const REACT_ADMIN_INLINE_KEYWORD  = 'cnReactData';

	private $status_data = [
		'status'				=> '',
		'subscription'			=> 'basic',
		'widget_version'		=> '',
		'threshold_exceeded'	=> false,
		'activation_datetime'	=> 0
	];
	private $x_api_key = 'hudft60djisdusdjwek';
	private $app_host_url = 'https://app.hu-manity.co';
	private $app_login_url = 'https://app.hu-manity.co/#/login';
	private $app_dashboard_url = 'https://app.hu-manity.co/#/';
	private $account_api_url = 'https://account-api.hu-manity.co';
	private $designer_api_url = 'https://designer-api.hu-manity.co';
	private $transactional_api_url = 'https://transactional-api.hu-manity.co';
	private $app_widget_url = '//cdn.hu-manity.co/hu-banner.min.js';
	private $deactivaion_url = '';
	private $network_admin = false;
	private $network_scope_claimed = false;
	private $plugin_network_active = false;
	/** Per-request memo for app_served_by_another_site(). Never persisted — see its docblock. */
	private $shared_app_memo = [];
	private static $_instance;
	private $notices = [];
	public $options = [];
	public $network_options = [];
	public $bot_detect;
	public $dashboard;
	public $frontend;
	public $settings;
	public $consent_logs;
	public $privacy_consent;
	public $privacy_consent_logs;
	public $welcome;
	public $welcome_api;
	public $welcome_frontend;
	public $db_version;

	/**
	 * The admin's STORED general.app_blocking preference, remembered before the
	 * Free-plan quota forces it off in memory for this request.
	 *
	 * null  = the quota force was not applied, so options['general']['app_blocking']
	 *         IS the stored value and nothing needs protecting.
	 * bool  = the pre-force stored value.
	 *
	 * Written in set_status(), which is the only writer that can ARM it (null → bool).
	 *
	 * Re-pointed — never armed — by the base posture sync in
	 * welcome-api.php::get_app_config(), when the Designer API delivers a new
	 * BannerConfigJSON.blocking while the force is already armed. That value IS the
	 * stored preference from that moment on, so the guard must protect it rather than
	 * the pre-pull one; without the re-point the guard would revert a legitimate
	 * backend `false` to `true`. It stays null if it was null, so a request with no
	 * quota force never gains an armed guard.
	 *
	 * Read by preserve_app_blocking_preference() and by the React save path.
	 * See #2272 and the guard's own docblock.
	 *
	 * @var bool|null
	 */
	public $app_blocking_stored = null;

	/**
	 * @var $defaults
	 */
	public $defaults = [
		'general'	=> [
			'global_override'		=> false,
			'global_cookie'			=> false,
			'app_id'				=> '',
			'app_key'				=> '',
			// Two independent controls, not one (DEC-012):
			//   app_blocking         POSTURE — hold third-party scripts BEFORE the
			//                        visitor chooses. Seeds huOptions.blocking.
			//                        Capped by the Free-plan visit quota.
			//   app_blocking_engine  CAPABILITY — the master switch. Seeds
			//                        huOptions.blockingEngine, which the widget treats
			//                        as absolute: off means no script is ever held, for
			//                        any visitor, whatever the privacy signal. Never
			//                        touched by the quota.
			// Blocking happens only when BOTH are on — see blocking_is_active().
			'app_blocking'			=> true,
			'app_blocking_engine'	=> true,
			'conditional_active'	=> false,
			'conditional_display'	=> 'hide',
			'conditional_rules'		=> [],
			'amp_support'			=> false,
			'bot_detection'			=> true,
			'caching_compatibility'	=> true,
			'debug_mode'			=> false,
			'wp_consent_api'		=> true,
			'excluded_handles'		=> [],
			'position'				=> 'bottom',
			'message_text'			=> '',
			'css_class'				=> '',
			'accept_text'			=> '',
			'refuse_text'			=> '',
			'refuse_opt'			=> false,
			'refuse_code'			=> '',
			'refuse_code_head'		=> '',
			'revoke_cookies'		=> false,
			'revoke_cookies_opt'	=> 'automatic',
			'revoke_message_text'	=> '',
			'revoke_text'			=> '',
			'redirection'			=> false,
			'see_more'				=> false,
			'link_target'			=> '_blank',
			'link_position'			=> 'banner',
			'time'					=> 'month',
			'time_rejected'			=> 'month',
			'hide_effect'			=> 'fade',
			'on_scroll'				=> false,
			'on_scroll_offset'		=> 100,
			'on_click'				=> false,
			'colors' => [
				'text'			=> '#fff',
				'button'		=> '#00a99d',
				'bar'			=> '#32323a',
				'bar_opacity'	=> 100
			],
			'see_more_opt' => [
				'text'		=> '',
				'link_type'	=> 'page',
				'id'		=> 0,
				'link'		=> '',
				'sync'		=> false
			],
			'script_placement'		=> 'header',
			'translate'				=> true,
			'deactivation_delete'	=> false,
			'review_notice'			=> true,
			'review_notice_delay'	=> 0,
			'update_version'		=> 8,
			'update_notice'			=> true,
			'update_notice_diss'	=> false,
			'update_delay_date'		=> 0,
			'update_threshold_date'	=> 0,
			'csp_notice'			=> false,
			'ui_mode'				=> 'legacy',
			// applied_template removed — now computed on the fly in React via matchTemplate().
			'displayType'			=> 'floating',
		],
		'privacy_consent' => [
			'wordpress_active'					=> true,
			'wordpress_active_type'				=> 'all',
			'contactform7_active'				=> false,
			'contactform7_active_type'			=> 'all',
			'mailchimp_active'					=> false,
			'mailchimp_active_type'				=> 'all',
			'wpforms_active'					=> false,
			'wpforms_active_type'				=> 'all',
			'woocommerce_active'				=> false,
			'woocommerce_active_type'			=> 'all',
			'formidableforms_active'			=> false,
			'formidableforms_active_type'		=> 'all',
			'easydigitaldownloads_active'		=> false,
			'easydigitaldownloads_active_type'	=> 'all'
		],
		'data'	=> [
			'status'				=> '',
			'subscription'			=> 'basic',
			'widget_version'		=> '',
			'threshold_exceeded'	=> false,
			'activation_datetime'	=> 0
		],
		'version'	=> '3.1.10'
	];

	/**
	 * Authoritative field-ownership partition (#2264).
	 *
	 * Only these keys may be written to cookie_notice_options by plugin code paths
	 * (save_options in react-admin-ajax.php, validate_options in settings.php).
	 *
	 * API-owned fields (bannerColor, primaryColor) are NEVER written here —
	 * cookie_notice_app_design is their exclusive store, populated by the
	 * Designer API via get_app_config().
	 *
	 * position and displayType are dual-homed: for connected sites the Designer
	 * API owns them (stored in cookie_notice_app_design); for disconnected sites
	 * there is no API path, so these must be writable here and are read from
	 * cookie_notice_options by the settings layer and React admin on load.
	 *
	 * @var string[]
	 */
	public static $plugin_owned_fields = [
		'message_text',
		'position',
		'displayType',
		'accept_text',
		'refuse_text',
		'revoke_text',
		'revoke_message_text',
		'css_class',
		'refuse_opt',
		'revoke_cookies',
		'revoke_cookies_opt',
		'on_scroll',
		'on_scroll_offset',
		'on_click',
		'redirection',
		'see_more',
		'see_more_opt',
		'link_target',
		'link_position',
		'time',
		'time_rejected',
		'hide_effect',
		'script_placement',
		'bot_detection',
		'amp_support',
		'caching_compatibility',
		'debug_mode',
		'wp_consent_api',
		'conditional_active',
		'conditional_display',
		'conditional_rules',
		'deactivation_delete',
		'app_blocking',
		'app_blocking_engine',
		'excluded_handles',
		'refuse_code',
		'refuse_code_head',
		'app_id',
		'app_key',
		'ui_mode',
		'global_override',
		'global_cookie',
		'colors',
		'redirect_delay',
		'review_notice',
		'review_notice_delay',
		'update_version',
		'update_notice',
		'update_notice_diss',
		'update_delay_date',
		'update_threshold_date',
		'csp_notice',
		'translate',
	];

	/**
	 * Disable object cloning.
	 *
	 * @return void
	 */
	public function __clone() {}

	/**
	 * Disable unserializing of the class.
	 *
	 * @return void
	 */
	public function __wakeup() {}

	/**
	 * Main plugin instance.
	 *
	 * @return object
	 */
	public static function instance() {
		if ( self::$_instance === null ) {
			self::$_instance = new self();

			add_action( 'init', [ self::$_instance, 'load_textdomain' ] );

			self::$_instance->includes();

			self::$_instance->bot_detect = new Cookie_Notice_Bot_Detect();
			self::$_instance->dashboard = new Cookie_Notice_Dashboard();
			self::$_instance->frontend = new Cookie_Notice_Frontend();
			self::$_instance->settings = new Cookie_Notice_Settings();
			new Cookie_Notice_React_Admin_Ajax();
			self::$_instance->consent_logs = new Cookie_Notice_Consent_Logs();
			self::$_instance->privacy_consent = new Cookie_Notice_Privacy_Consent();
			self::$_instance->privacy_consent_logs = new Cookie_Notice_Privacy_Consent_Logs();
			self::$_instance->welcome = new Cookie_Notice_Welcome();
			self::$_instance->welcome_api = new Cookie_Notice_Welcome_API();
			self::$_instance->welcome_frontend = new Cookie_Notice_Welcome_Frontend();
		}

		return self::$_instance;
	}

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// Allow wp-config.php overrides for staging/prod switching.
		// Usage: define( 'CN_ACCOUNT_API_URL', 'https://stage-api.hu-manity.co' );
		if ( defined( 'CN_ACCOUNT_API_URL' ) )       $this->account_api_url       = CN_ACCOUNT_API_URL;
		if ( defined( 'CN_DESIGNER_API_URL' ) )      $this->designer_api_url      = CN_DESIGNER_API_URL;
		if ( defined( 'CN_TRANSACTIONAL_API_URL' ) ) $this->transactional_api_url = CN_TRANSACTIONAL_API_URL;
		if ( defined( 'CN_X_API_KEY' ) )             $this->x_api_key             = CN_X_API_KEY;
		if ( defined( 'CN_APP_WIDGET_URL' ) )        $this->app_widget_url        = CN_APP_WIDGET_URL;
		if ( defined( 'CN_APP_HOST_URL' ) )          $this->app_host_url          = CN_APP_HOST_URL;

		// define plugin constants
		$this->define_constants();

		// activation hooks
		register_activation_hook( __FILE__, [ $this, 'activation' ] );
		register_deactivation_hook( __FILE__, [ $this, 'deactivation' ] );

		// set network data
		$this->set_network_data();

		$this->check_legacy_options();

		// get options
		if ( is_multisite() ) {
			// get network options
			$this->network_options['general'] = get_site_option( 'cookie_notice_options', $this->defaults['general'] );
			$this->network_options['privacy_consent'] = get_site_option( 'cookie_notice_privacy_consent', $this->defaults['privacy_consent'] );

			if ( $this->is_network_admin() ) {
				$general_options = $this->network_options['general'];
				$privacy_consent_options = $this->network_options['privacy_consent'];
			} else {
				$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

				// settings page?
				if ( is_admin() && $page === 'cookie-notice' ) {
					// get current url path
					$url_path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

					if ( is_string( $url_path ) && basename( $url_path ) === 'admin.php' ) {
						// get site options
						$general_options = get_option( 'cookie_notice_options', $this->defaults['general'] );
						$privacy_consent_options = get_option( 'cookie_notice_privacy_consent', $this->defaults['privacy_consent'] );
					}
				} else {
					if ( $this->is_plugin_network_active() && $this->network_options['general']['global_override'] ) {
						$general_options = $this->network_options['general'];
						$privacy_consent_options = $this->network_options['privacy_consent'];
					} else {
						$general_options = get_option( 'cookie_notice_options', $this->defaults['general'] );
						$privacy_consent_options = get_option( 'cookie_notice_privacy_consent', $this->defaults['privacy_consent'] );
					}
				}
			}
		} else {
			$general_options = get_option( 'cookie_notice_options', $this->defaults['general'] );
			$privacy_consent_options = get_option( 'cookie_notice_privacy_consent', $this->defaults['privacy_consent'] );
		}

		// merge old options with new ones
		$this->options['general'] = $this->multi_array_merge( $this->defaults['general'], $general_options );
		$this->options['privacy_consent'] = $this->multi_array_merge( $this->defaults['privacy_consent'], $privacy_consent_options );

		if ( ! isset( $this->options['general']['see_more_opt']['sync'] ) )
			$this->options['general']['see_more_opt']['sync'] = $this->defaults['general']['see_more_opt']['sync'];

		// ── Begin app_blocking guard registration (#2272)
		//
		// Registered here so the guard is in place before set_status_data()
		// (plugins_loaded:0) applies the force, and therefore before every write
		// that could carry it. See preserve_app_blocking_preference().
		//
		// Anchored for tests/unit/app-blocking-quota-preservation.php: the callback
		// is only a guard while something registers it, and without this region the
		// whole suite stays green with both lines deleted.
		add_filter( 'pre_update_option_cookie_notice_options', [ $this, 'preserve_app_blocking_preference' ] );
		add_filter( 'pre_update_site_option_cookie_notice_options', [ $this, 'preserve_app_blocking_preference' ] );
		// ── End app_blocking guard registration (#2272)

		// actions
		add_action( 'plugins_loaded', [ $this, 'set_database_version' ], 0 );
		add_action( 'plugins_loaded', [ $this, 'set_status_data' ], 0 );
		// ── Begin network scope hook ─────────────────────────────────
		add_action( 'plugins_loaded', [ $this, 'enforce_network_scope' ], PHP_INT_MIN );
		// ── End network scope hook ───────────────────────────────────
		add_action( 'init', [ $this, 'register_shortcodes' ] );
		add_action( 'init', [ $this, 'wpsc_add_cookie' ] );
		add_action( 'init', [ $this, 'maybe_apply_dev_tier_override' ] );
		add_action( 'init', [ $this, 'set_plugin_links' ] );
		add_action( 'admin_init', [ $this, 'update_notice' ] );
		add_action( 'admin_init', [ $this, 'maybe_redirect_after_activation' ] );
		add_action( 'admin_init', [ $this, 'maybe_show_license_assigned_notice' ] );
		add_action( 'admin_init', [ $this, 'maybe_switch_ui_mode' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
		add_action( 'admin_footer', [ $this, 'deactivate_plugin_template' ] );
		add_action( 'wp_ajax_cn_dismiss_notice', [ $this, 'ajax_dismiss_admin_notice' ] );
		add_action( 'wp_ajax_cn_review_notice', [ $this, 'ajax_review_notice' ] );
		add_action( 'wp_ajax_cn-deactivate-plugin', [ $this, 'deactivate_plugin' ] );
	}

	/**
	 * Set current plugin version from database.
	 *
	 * @return void
	 */
	public function set_database_version() {
		// get current version
		if ( $this->is_network_admin() )
			$this->db_version = get_site_option( 'cookie_notice_version', '1.0.0' );
		else
			$this->db_version = get_option( 'cookie_notice_version', '1.0.0' );
	}

	/**
	 * Check legacy options.
	 *
	 * @return void
	 */
	public function check_legacy_options() {
		// multisite?
		if ( is_multisite() ) {
			// get network options
			$site_options = get_site_option( 'cookie_notice_options', $this->defaults['general'] );

			// update legacy options
			$site_options = $this->update_legacy_options( $site_options );

			// any changes?
			if ( $site_options !== false )
				update_site_option( 'cookie_notice_options', $site_options );
		}

		// get options
		$options = get_option( 'cookie_notice_options', $this->defaults['general'] );

		// update legacy options
		$options = $this->update_legacy_options( $options );

		// any changes?
		if ( $options !== false )
			update_option( 'cookie_notice_options', $options );
	}

	/**
	 * Maybe change legacy options.
	 *
	 * @param array $options
	 * @return false|array
	 */
	public function update_legacy_options( $options ) {
		// bail out if options are missing or invalid to avoid PHP 8 fatal on non-array values
		if ( ! is_array( $options ) )
			return $this->defaults['general'];

		$options_changed = false;

		// check legacy parameters that were yes/no strings
		foreach ( [ 'refuse_opt', 'on_scroll', 'on_click', 'deactivation_delete', 'see_more' ] as $param ) {
			if ( array_key_exists( $param, $options ) && ! is_bool( $options[$param] ) ) {
				$options[$param] = $options[$param] === 'yes';

				$options_changed = true;
			}
		}

		// migrate banner_size → displayType (#2269)
		if ( array_key_exists( 'banner_size', $options ) ) {
			$options['displayType'] = $options['banner_size'];
			unset( $options['banner_size'] );

			$options_changed = true;
		} elseif ( ! array_key_exists( 'displayType', $options ) ) {
			$options['displayType'] = 'floating';

			$options_changed = true;
		}

		// check hide banner
		if ( isset( $options['hide_banner'] ) ) {
			if ( $options['hide_banner'] && ! isset( $options['conditional_active'] ) ) {
				$options['conditional_active'] = true;
				$options['conditional_display'] = 'hide';
				$options['conditional_rules'] = [
					1 => [
						1 => [
							'param'		=> 'user_type',
							'operator'	=> 'equal',
							'value'		=> 'logged_in'
						]
					]
				];
			}

			unset( $options['hide_banner'] );

			$options_changed = true;
		}

		if ( $options_changed )
			return $options;
		else
			return false;
	}

	/**
	 * Setup plugin constants.
	 *
	 * @return void
	 */
	private function define_constants() {
		define( 'COOKIE_NOTICE_URL', plugins_url( '', __FILE__ ) );
		define( 'COOKIE_NOTICE_PATH', plugin_dir_path( __FILE__ ) );
		define( 'COOKIE_NOTICE_BASENAME', plugin_basename( __FILE__ ) );
		define( 'COOKIE_NOTICE_REL_PATH', dirname( COOKIE_NOTICE_BASENAME ) );
	}

	/**
	 * Set cookie compliance status data.
	 *
	 * @return void
	 */
	public function set_status_data() {
		$default_data = $this->defaults['data'];

		if ( is_multisite() ) {
			if ( $this->is_plugin_network_active() ) {
				// network
				if ( $this->is_network_admin() ) {
					if ( $this->network_options['general']['global_override'] )
						$status_data = get_site_option( 'cookie_notice_status', $default_data );
					else
						$status_data = $default_data;
				// site
				} else {
					if ( $this->network_options['general']['global_override'] )
						$status_data = get_site_option( 'cookie_notice_status', $default_data );
					else
						$status_data = get_option( 'cookie_notice_status', $default_data );
				}
			} else {
				// network
				if ( $this->is_network_admin() )
					$status_data = $default_data;
				// site
				else
					$status_data = get_option( 'cookie_notice_status', $default_data );
			}
		} else
			$status_data = get_option( 'cookie_notice_status', $default_data );

		// old status format?
		if ( ! is_array( $status_data ) ) {
			// update config data
			$status_data = $this->welcome_api->get_app_config( '', true );
		} else {
			// merge database data with default data
			$status_data = array_merge( $default_data, $status_data );
		}

		// ── Begin app_blocking quota force (#2272)
		//
		// The Free-plan visit limit switches autoblocking off for the REST OF THIS
		// REQUEST — a runtime overlay, never a change to what the admin asked for.
		// Remember the stored value first, because every wholesale writer of
		// $this->options['general'] would otherwise persist the forced false over it:
		// there are nine of them, and the cheapest one needs no admin choice at all
		// (opening the settings page runs refresh_csp_notice(), which re-saves the
		// whole array). preserve_app_blocking_preference() is the guard that stops
		// them; this is where it learns what to restore.
		// Remembered ONCE. set_status_data() is re-entrant — the network save path and
		// the React connection-change refresh both call it a second time in the same
		// request — and by then options['general']['app_blocking'] is already the forced
		// false, so re-reading it here would quietly overwrite the real value with it.
		if ( $status_data['threshold_exceeded'] ) {
			if ( $this->app_blocking_stored === null )
				$this->app_blocking_stored = ! empty( $this->options['general']['app_blocking'] );

			$this->options['general']['app_blocking'] = false;
		}
		// ── End app_blocking quota force (#2272)

		// check status
		$status = $this->check_status( $status_data['status'] );

		// no activation timestamp?
		if ( empty( $status_data['activation_datetime'] ) ) {
			if ( $status === 'active' )
				$activation = time();
			else
				$activation = 0;
		} else
			$activation = (int) $status_data['activation_datetime'];

		// set status data
		$this->status_data = [
			'status'				=> $status,
			'subscription'			=> $this->check_subscription( $status_data['subscription'] ),
			'widget_version'		=> isset( $status_data['widget_version'] ) ? $status_data['widget_version'] : '',
			'threshold_exceeded'	=> (bool) $status_data['threshold_exceeded'],
			'activation_datetime'	=> $activation
		];

	}

	// ── Begin app_blocking preference guard (#2272)
	/**
	 * Keep the Free-plan quota from eating the admin's stored autoblocking preference.
	 *
	 * The quota force in set_status() is a RUNTIME overlay, but it lands in
	 * $this->options['general'] — the same array nine different call sites hand
	 * straight to update_option( 'cookie_notice_options', ... ). Any one of them
	 * therefore writes the forced false back over what the admin actually chose, and
	 * nothing restores it when the visits cycle resets. Reachable with no admin choice
	 * whatsoever: opening the settings page runs refresh_csp_notice()
	 * ( includes/settings.php ), which re-saves the whole array whenever the .htaccess
	 * state has drifted.
	 *
	 * Guarding the option rather than the call sites is deliberate — a tenth writer
	 * added later is covered for free, which patching nine of them would not be.
	 *
	 * Strictly protective, and narrow on purpose: it only ever turns a false back into
	 * a true, only while the force is active in THIS request, and only when the stored
	 * value really was true. So:
	 *
	 *   - "Restore defaults" (which writes app_blocking = true) is untouched.
	 *   - A site that is not over quota is untouched — the flag is null.
	 *   - An admin who deliberately unchecks the box WHILE over quota has the change
	 *     discarded. That is the same contract the classic form has had since #2272
	 *     (over quota it renders the field disabled and omits its sentinel, so
	 *     validate_options() preserves the DB value): while the quota caps the
	 *     feature, the stored preference is frozen, not editable.
	 *
	 * @param mixed $value Option value about to be written.
	 * @return mixed
	 */
	public function preserve_app_blocking_preference( $value ) {
		// Force not applied this request, or the stored preference was already false —
		// nothing to protect either way.
		if ( $this->app_blocking_stored !== true )
			return $value;

		// Only touch a write that actually carries the key. A payload without it
		// falls through to the defaults on the next load, which is already `true`.
		if ( is_array( $value ) && array_key_exists( 'app_blocking', $value ) && empty( $value['app_blocking'] ) )
			$value['app_blocking'] = true;

		return $value;
	}
	// ── End app_blocking preference guard (#2272)

	// ── Begin blocking_is_active accessor (DEC-012)
	/**
	 * Is script blocking actually going to happen on this site right now?
	 *
	 * DEC-012 split one overloaded checkbox into two independent controls, and every
	 * surface that answers "are we protected?" must read the AND of them — otherwise a
	 * site with the engine switched off is still reported as protected:
	 *
	 *   app_blocking         posture, and quota-capped: already forced false in memory
	 *                        by set_status() while the Free-plan limit is exceeded
	 *   app_blocking_engine  the master switch; the widget treats huOptions.blockingEngine
	 *                        as absolute, so off means nothing is held for anybody
	 *
	 * ONE accessor, deliberately, for both the render-time gates in
	 * includes/frontend.php and every admin display surface. Blocking behaviour and the
	 * reported state cannot then drift apart — which is the whole point of the split.
	 *
	 * Reads the in-memory options, so the quota cap is included: this answers "is
	 * blocking happening", not "what did the admin ask for". For the latter use
	 * $app_blocking_stored / the raw option.
	 *
	 * @return bool
	 */
	public function blocking_is_active() {
		return ! empty( $this->options['general']['app_blocking'] )
			&& ! empty( $this->options['general']['app_blocking_engine'] );
	}
	// ── End blocking_is_active accessor (DEC-012)

	/**
	 * Get cookie compliance status data.
	 *
	 * @return string
	 */
	public function get_status_data() {
		return $this->status_data;
	}

	/**
	 * Get cookie compliance status.
	 *
	 * @return string
	 */
	public function get_status() {
		return $this->status_data['status'];
	}

	/**
	 * Check cookie compliance status.
	 *
	 * @param string $status
	 * @return string
	 */
	public function check_status( $status ) {
		$status = sanitize_key( $status );

		return ! empty( $status ) && in_array( $status, [ 'active', 'pending' ], true ) ? $status : $this->defaults['data']['status'];
	}

	/**
	 * Get cookie compliance subscription.
	 *
	 * @return string
	 */
	/**
	 * CN_DEV_MODE: Apply ?cn_tier override.
	 *
	 * Hooked on 'init' so current_user_can() is safely resolvable. (It is available from
	 * plugins_loaded onward — pluggable.php is required just before that hook — but not at
	 * plugin include time, and 'init' is late enough for other plugins' auth filters.)
	 * Overrides status_data + app_id in-memory for the current request.
	 */
	public function maybe_apply_dev_tier_override() {
		if ( ! defined( 'CN_DEV_MODE' ) || ! CN_DEV_MODE )
			return;

		if ( ! current_user_can( 'manage_options' ) )
			return;

		$cn_tier = isset( $_GET['cn_tier'] ) ? sanitize_key( $_GET['cn_tier'] ) : '';

		// 'basic' still accepted as a legacy alias for the Banner Only state, so an
		// old dev bookmark keeps working. Note the VALUE written below stays
		// 'basic': that is the platform's wire label for the Free plan (minted from
		// the DB's 'free' by Designer API userDesignLive.controller.ts:78), so it
		// must match what a real response would store. The disconnected state is
		// expressed by clearing app_id, never by the subscription value.
		if ( $cn_tier === 'banner_only' || $cn_tier === 'basic' ) {
			$this->status_data['subscription'] = 'basic';
			$this->options['general']['app_id'] = '';
		} elseif ( $cn_tier === 'free' ) {
			$this->status_data['subscription'] = 'basic';
			if ( empty( $this->options['general']['app_id'] ) )
				$this->options['general']['app_id'] = 'cn-dev-free-plan';
		} elseif ( $cn_tier === 'pro' ) {
			$this->status_data['subscription'] = 'pro';
			if ( empty( $this->options['general']['app_id'] ) )
				$this->options['general']['app_id'] = 'cn-dev-pro-plan';
		}
	}

	public function get_subscription() {
		return $this->status_data['subscription'];
	}

	/**
	 * Resolve which Web Channel banner build to load.
	 *
	 * Backend-controlled: the server-fed WidgetVersion flag (Application table,
	 * delivered on the get_config response alongside SubscriptionType) is the
	 * SOLE source of the channel decision. There is no plugin UI and no
	 * tier-based fallback — cohorts are flipped in the database.
	 *
	 *   - 'v2'                 -> v2 build.
	 *   - anything else / ''   -> v1 (the current serving script; safe default).
	 *
	 * v1 by default means a fresh install — which has no widget_version until
	 * its first successful config pull — never ships v2 before the backend
	 * explicitly opts it in.
	 *
	 * @return string 'v1' | 'v2'
	 */
	public function get_banner_channel() {
		return $this->status_data['widget_version'] === 'v2' ? 'v2' : 'v1';
	}

	/**
	 * Canonical optimizer/CDN skip attributes for plugin-owned <script> tags.
	 *
	 * Single source of truth for the data-cfasync / nowprocket / noptimize /
	 * nitro-exclude / jetpack-boost / no-minify attribute set that tells caching
	 * and optimizer plugins to leave our scripts alone. Consumed by the
	 * script_loader_tag filters in Cookie_Notice_Settings and
	 * Cookie_Notice_Dashboard; the inline frontend banner tags
	 * ( frontend.php / welcome-frontend.php ) carry the same literal string —
	 * if this set changes, update those heredocs too.
	 *
	 * @return string Leading-space attribute string, ready to append after '<script'.
	 */
	public static function optimizer_skip_attrs() {
		return ' data-cfasync="false" data-nowprocket data-noptimize="1" data-no-optimize="1" nitro-exclude data-jetpack-boost="ignore" data-no-minify';
	}

	/**
	 * Insert a '/v2/' path segment before the filename of a widget URL.
	 *
	 * Splits any query string off first so it stays trailing
	 * ( …/v2/hu-banner.min.js?ver=1, not …/hu-banner.min.js/v2/?ver=1 ), and
	 * only injects when the URL actually has a path segment after the host —
	 * a host-only override ( '//cdn.example.com', 'https://cdn.example.com' )
	 * has no filename to sit beside, so it is returned unchanged rather than
	 * emit a broken 'https:/v2//…' URL. The contract for CN_APP_WIDGET_URL is
	 * therefore "must point at a file"; anything else stays on the v1 shape.
	 *
	 * @param string $url Base widget URL (no query string assumptions).
	 * @return string
	 */
	private function inject_v2_segment( $url ) {
		// peel off ?query / #fragment so the segment lands before the filename
		$suffix = '';
		$cut = strcspn( $url, '?#' );

		if ( $cut < strlen( $url ) ) {
			$suffix = substr( $url, $cut );
			$url    = substr( $url, 0, $cut );
		}

		$slash = strrpos( $url, '/' );

		// Need a real filename to sit the segment beside: bail if there is no
		// slash, if the only slash(es) are the scheme's '//' (host-only, e.g.
		// 'https://cdn.example.com'), or if nothing follows the final slash
		// (trailing-slash host, e.g. 'https://cdn.example.com/'). Any of these
		// stays on the v1 shape rather than emit a broken or file-less URL.
		$filename = $slash === false ? '' : substr( $url, $slash + 1 );

		if ( $slash === false || $slash === strpos( $url, '//' ) + 1 || $filename === '' )
			return $url . $suffix;

		return substr( $url, 0, $slash + 1 ) . 'v2/' . $filename . $suffix;
	}

	/**
	 * Check cookie compliance subscription.
	 *
	 * @param string $subscription
	 * @return string
	 */
	public function check_subscription( $subscription ) {
		$subscription = sanitize_key( $subscription );

		return ! empty( $subscription ) && in_array( $subscription, [ 'basic', 'pro' ], true ) ? $subscription : $this->defaults['data']['subscription'];
	}

	/**
	 * Check whether the current threshold is exceeded.
	 *
	 * @return bool
	 */
	public function threshold_exceeded() {
		return $this->status_data['threshold_exceeded'];
	}

	/**
	 * Get cookie compliance activation timestamp.
	 *
	 * @return int
	 */
	public function get_cc_activation_datetime() {
		return (int) $this->status_data['activation_datetime'];
	}

	/**
	 * Get endpoint URL.
	 *
	 * @param string $type
	 * @param string $query
	 * @return string
	 */
	public function get_url( $type, $query = '' ) {
		if ( $type === 'login' )
			$url = $this->app_login_url;
		elseif ( $type === 'dashboard' )
			$url = $this->app_dashboard_url;
		elseif ( $type === 'widget' ) {
			$url = $this->app_widget_url;

			// Inject a '/v2/' path segment before the filename when the resolved
			// channel is v2 (so //cdn.hu-manity.co/hu-banner.min.js becomes
			// //cdn.hu-manity.co/v2/hu-banner.min.js, and any CN_APP_WIDGET_URL
			// override carries to v2 too). v1 returns the base unchanged.
			if ( $this->get_banner_channel() === 'v2' )
				$url = $this->inject_v2_segment( $url );
		}
		elseif ( $type === 'react-admin' )
			$url = COOKIE_NOTICE_URL . '/assets/react-admin/' . self::REACT_ADMIN_BUNDLE_BASENAME;
		elseif ( $type === 'host' )
			$url = $this->app_host_url;
		elseif ( $type === 'account_api' )
			$url = $this->account_api_url;
		elseif ( $type === 'designer_api' )
			$url = $this->designer_api_url;
		elseif ( $type === 'transactional_api' )
			$url = $this->transactional_api_url;

		return $url . ( $query !== '' ? $query : '' );
	}

	/**
	 * Get API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		return $this->x_api_key;
	}

	/**
	 * Check whether the current request is for the network administrative interface.
	 *
	 * @return bool
	 */
	public function is_network_admin() {
		return $this->network_admin;
	}

	/**
	 * May the current user make a write at this scope?
	 *
	 * The one definition of "a network-wide write needs a network-wide capability". Call
	 * sites across the plugin reached one behind manage_options — a SITE-level capability
	 * every subsite administrator holds — so each asks this instead of carrying its own rule.
	 * None is reached by forging anything; the scope is derived honestly and the capability
	 * in front of it was simply too low. UNFORGEABLE IS NOT AUTHORISED: several were cleared
	 * in earlier review on the grounds that their scope could not be faked, which answers
	 * integrity and says nothing about who is allowed to write.
	 *
	 * Do not enumerate the call sites, here or anywhere. Two wrong enumerations in earlier
	 * revisions each let a door survive a review round, and a third was wrong in the very
	 * paragraph warning against them. State the rule; let the tests count.
	 *
	 * DELIBERATELY NOT FILTERABLE. The plugin filters cn_manage_cookie_notice_cap to let an
	 * integrator delegate who ADMINISTERS Cookie Notice; that is a surface decision. This is
	 * a security gate, and one an integrator could filter down to a site capability would be
	 * advisory. Cookie_Notice_Welcome_API::may_push_base_posture() hardcodes the same
	 * capability for the same reason.
	 *
	 * NEVER CALL THIS AT PLUGIN INCLUDE TIME. current_user_can() resolves through
	 * wp_get_current_user(), which is pluggable and not loaded while plugin files are still
	 * being included — see enforce_network_scope()'s docblock for the full ordering.
	 *
	 * Callers today are admin_init or AJAX dispatch, both safely after it, except the gates
	 * in welcome-api.php get_app_config() and get_app_analytics(), which sit on paths
	 * reachable from set_status_data() on plugins_loaded:0. Those guard themselves with
	 * did_action( 'admin_init' ) precisely so they never resolve the current user that early
	 * — see their own comments.
	 *
	 * @param bool $network Whether the write being guarded is network-scoped.
	 * @return bool
	 */
	public function can_write_at_scope( $network ) {
		return ! $network || current_user_can( 'manage_network_options' );
	}

	/**
	 * Is this app the one shared by every site on the network?
	 *
	 * AUTHORITY FOLLOWS THE APP, NOT THE ROW. is_network_options() answers "was the options
	 * array read from the network row", which is a property of THIS request. It is not the
	 * question a write has to ask, because the two come apart — and when they do, the remote
	 * record every site pulls is the thing that changes.
	 *
	 * How they come apart, reproduced on a real multisite 2026-09-15:
	 *
	 *   1. global_override on. Settings::load_defaults() runs on after_setup_theme and, when
	 *      the translate flag is set (the compiled default), writes $cn->options['general']
	 *      into the SITE row. Under the override that array IS the network row, and app_id
	 *      and app_key are plugin-owned fields that survive every allowlist — so every
	 *      subsite's own row ends up naming the network's app on its FIRST request, with no
	 *      administrator having touched anything.
	 *   2. The override is later switched off, or the plugin is network-deactivated and
	 *      activated per site. is_network_options() goes false.
	 *   3. The site row still names the network's app. A subsite administrator's PATCH now
	 *      lands on the record every other site still serves, and a gate asking
	 *      is_network_options() waves it through.
	 *
	 * So ask the app. A write targeting an app OTHER SITES SERVE takes network authority,
	 * whatever row this request happened to read.
	 *
	 * AND ASK IT OF REALITY, NOT OF A ROW. An earlier revision of this method asked whether
	 * the NETWORK row named the app. That is still a row question — just a different row —
	 * and it fails OPEN one move further on, reproduced on a real multisite 2026-09-15:
	 * a super admin re-points or disconnects the network connection (app_id and app_key are
	 * plugin-owned fields, so this is a first-class supported action), every site carries on
	 * serving the OLD app, and the predicate goes blind on all of them at once.
	 *
	 * The only answer that cannot drift is the one taken from the sites themselves: does any
	 * site OTHER THAN THIS ONE serve this app? The network row is kept as a fast path because
	 * when it does name the app the answer is certainly yes, but it is never the whole answer.
	 *
	 * This is the one definition of that rule; may_push_base_posture() held a private second
	 * copy and was the only thing implementing it correctly, which is how the gates that
	 * cited it came to disagree with it.
	 *
	 * @param string $app_id The app the write is about to change.
	 * @return bool
	 */
	public function is_network_shared_app( $app_id ) {
		// ── Begin shared app rule ────────────────────────────────────────────
		if ( ! is_multisite() )
			return false;

		$app_id = (string) $app_id;

		if ( $app_id === '' )
			return false;

		// Fast path. If the network row names it, it is shared by definition — every site
		// reads that row under global_override, and the row outlives the override.
		$network_row = get_site_option( 'cookie_notice_options', [] );

		if ( is_array( $network_row ) && isset( $network_row['app_id'] )
			&& (string) $network_row['app_id'] === $app_id )
			return true;

		return $this->app_served_by_another_site( $app_id );
		// ── End shared app rule ──────────────────────────────────────────────
	}

	/**
	 * Does any site OTHER THAN THIS ONE serve this app?
	 *
	 * Asked of the sites themselves, because every stored answer to this question has drifted:
	 * the request's own options row drifted when global_override was switched off, and the
	 * network row drifted when the network connection was re-pointed. Sites are the only place
	 * the truth cannot be stale, because they ARE what is served.
	 *
	 * NOT cached across requests, and memoised WITHIN one. A stored "no" is a window in which
	 * the gate stands open, so nothing is persisted; but repeating the walk several times in a
	 * single request buys nothing, so the answer is remembered for that request only.
	 *
	 * Do not take this as "it only runs on a mutating admin action" — an earlier revision of
	 * this docblock claimed exactly that and it was false. Settings::load_defaults() writes the
	 * options row on after_setup_theme, which stages a posture change, which asks this — on the
	 * FIRST front-end request of every site. It is one-shot per site row, but it is not admin-
	 * only, and the cost argument has to survive that.
	 *
	 * FAILS CLOSED above the scan limit. On a network too large to walk, every such app is
	 * treated as shared and the change needs a super admin. That is the safe direction: a
	 * thousand-site network is exactly where one administrator's mistake reaches furthest.
	 * The limit is filterable, and a non-positive value falls back to the DEFAULT rather than
	 * being honoured. Clamping it to 1 — which an earlier revision did, while claiming that
	 * stopped a lockout — does not: with a limit of 1, any network of two or more sites is
	 * still "too large", so every app reads as shared and every site administrator loses
	 * control of their own. A filter returning nonsense should be ignored, not obeyed at its
	 * least useful value.
	 *
	 * INSTALL-SCOPED, deliberately not network-scoped. An earlier revision pinned the site list
	 * to the current network because the fast path reads a network option — but the question
	 * this answers is "does any other SITE serve this app", and a site on a sibling network of
	 * the same install serves it just as really. Pinning made that case answer false, which is
	 * fail-OPEN, and it is the same mistake as the two predicates before it: asking about the
	 * set that is convenient rather than the set that serves the record.
	 *
	 * Trashed sites are excluded. WP_Site_Query returns archived, spam and deleted sites by
	 * default, and counting one as a sharer permanently refuses the app's only live owner with
	 * no way to clear it.
	 *
	 * @param string $app_id Non-empty app id, already cast by the caller.
	 * @return bool
	 */
	private function app_served_by_another_site( $app_id ) {
		// ── Begin shared app scan ────────────────────────────────────────────
		$current = get_current_blog_id();
		$memo    = $app_id . '|' . $current;

		if ( isset( $this->shared_app_memo[ $memo ] ) )
			return $this->shared_app_memo[ $memo ];

		$default  = 200;
		$filtered = apply_filters( 'cn_shared_app_scan_limit', $default );

		// is_numeric() before the cast, or (int) quietly turns true, an array or "abc" into 1
		// — the one value that locks out every network of two or more sites.
		$limit    = is_numeric( $filtered ) ? (int) $filtered : $default;

		if ( $limit < 1 )
			$limit = $default;
		$query = [ 'archived' => 0, 'spam' => 0, 'deleted' => 0 ];
		$total = (int) get_sites( array_merge( $query, [ 'count' => true ] ) );

		if ( $total > $limit )
			return $this->shared_app_memo[ $memo ] = true;

		$shared = false;

		foreach ( get_sites( array_merge( $query, [ 'fields' => 'ids', 'number' => $limit ] ) ) as $blog_id ) {
			if ( (int) $blog_id === (int) $current )
				continue;

			$row = get_blog_option( $blog_id, 'cookie_notice_options', [] );

			if ( is_array( $row ) && isset( $row['app_id'] ) && (string) $row['app_id'] === $app_id ) {
				$shared = true;
				break;
			}
		}

		return $this->shared_app_memo[ $memo ] = $shared;
		// ── End shared app scan ──────────────────────────────────────────────
	}

	/**
	 * Why a network-scoped write was refused, and who can make it.
	 *
	 * "Insufficient permissions" tells someone nothing they can act on. This names the
	 * reason (the setting is shared by every site) and the person who can help.
	 *
	 * Callable only after 'init' — it translates, and the textdomain loads on init (:319).
	 * enforce_network_scope() runs earlier, on plugins_loaded, and carries an untranslated
	 * copy of this wording rather than triggering WP's just-in-time textdomain notice.
	 *
	 * @return string
	 */
	public function network_scope_denied_message() {
		return __( 'This setting applies to every site on the network, so only a Network Administrator (Super Admin) can change it. Please ask your network administrator to make this change.', 'cookie-notice' );
	}

	/**
	 * Refuse a network-scoped write on screen, rather than on a blank error page.
	 *
	 * For a refusal that happens while an admin screen is being assembled, this is the right
	 * shape: the screen still renders, with a standard WordPress error notice explaining why
	 * the save did not happen and who can make it. wp_die() would be a white page for what is
	 * an ordinary permissions outcome, and a bare return would be a settings form that
	 * silently does nothing — which reads as a bug, and invites "fixing" the gate.
	 *
	 * Refusals with no screen to render into (an AJAX handler, an admin_post endpoint) use
	 * their own idiom and carry the same wording.
	 *
	 * @return void
	 */
	public function deny_network_scope_notice() {
		// 'error', not 'notice-error': display_notice() hardcodes "notice notice-info" and
		// appends this, and .notice-error loses to the later .notice-info at equal specificity
		// — so the refusal would render BLUE. div.error outranks it and renders red, which is
		// why 'error' is what renders red. (Callers pass a mix — 'notice-success
		// is-dismissible', 'cn-threshold error is-dismissible' — so do not read this as a
		// house style; it is a specific CSS outcome.)
		$this->add_notice( esc_html( $this->network_scope_denied_message() ), 'error' );
	}

	/**
	 * Check whether the plugin is active for the entire network.
	 *
	 * @return bool
	 */
	public function is_plugin_network_active() {
		return $this->plugin_network_active;
	}

	/**
	 * Check whether network-wide options should be used.
	 *
	 * Returns true when the plugin is network-active with global_override enabled,
	 * meaning all sites share the network-level configuration.
	 *
	 * @return bool
	 */
	public function is_network_options() {
		return is_multisite() && $this->is_plugin_network_active() && $this->network_options['general']['global_override'];
	}

	/**
	 * Refuse a network-scope claim the current user cannot back.
	 *
	 * Hooked on 'plugins_loaded' at PHP_INT_MIN — the EARLIEST point at which the capability
	 * can be resolved at all. All line numbers are wp-settings.php:
	 *
	 *   516 / 574  plugin files included — the constructor, and so the claim, runs here
	 *   604        pluggable.php: wp_get_current_user() exists only from here on
	 *   622        plugins_loaded  ← this hook, at PHP_INT_MIN
	 *   697 / 749  setup_theme / after_setup_theme
	 *   771        init
	 *
	 * Late enough: pluggable.php (604) precedes plugins_loaded (622), so current_user_can()
	 * resolves. Every plugin FILE is loaded by then too (516/574), so a determine_current_user
	 * filter registered at file-load — which is where auth plugins normally register it, and
	 * where core's cookie auth lives via default-filters.php — is already in place.
	 *
	 * Early enough: PHP_INT_MIN precedes every higher-priority callback on the hook, and every
	 * same-priority one registered later. Strictly it is not "first": a network-activated
	 * plugin is included at 516, before a site-activated cookie-notice at 574, so one
	 * registering PHP_INT_MIN too would run ahead of this — which only matters if it consumed
	 * Cookie_Notice()->is_network_admin(), and nothing can, since the class does not exist
	 * until 574.
	 *
	 * Early enough, and the priority is doing real work here. Consumers of
	 * is_network_admin() begin at plugins_loaded priority 0: this class's own
	 * set_database_version() (:451) reads it, and its result feeds legacy-upgrade branches
	 * on the same hook that WRITE network-scoped state — includes/privacy-consent.php:243
	 * (add_site_option) and the cache modules at priority 11, one of which
	 * (includes/modules/breeze/breeze.php:107) writes a third-party network option.
	 * Cookie_Notice_Settings::load_defaults() (includes/settings.php:320, guarding the
	 * update_site_option() at :321, on after_setup_theme) is a later one. An earlier revision of this fix sat on 'init' and then on
	 * plugins_loaded:PHP_INT_MAX, and both ran after consumers that had already written.
	 *
	 * SO: nothing may read is_network_admin() before this hook. At PHP_INT_MIN the only code
	 * that can is the constructor itself, and it reads the claim solely to pick which options
	 * array to hold in memory. It is NOT write-free — check_legacy_options() (:364, writing
	 * at :473) runs there — but that write is a shape migration of existing DB state and never
	 * consults the scope, so it is unaffected either way. A scope-DEPENDENT write added to the
	 * constructor would be unvettable, and that is the invariant a new caller has to respect.
	 *
	 * The residual trade-off, accepted: resolving the user here both caches $current_user for
	 * the request and fires the set_current_user action (pluggable.php:48) before any other
	 * plugin's plugins_loaded callback. So a determine_current_user filter registered later is
	 * not consulted; a membership or role plugin's set_current_user handler may run before its
	 * own bootstrap; and a user_has_cap / map_meta_cap filter registered on plugins_loaded or
	 * init is not in place either. For user_has_cap that cannot affect a genuine super admin —
	 * WP_User::has_cap short-circuits on is_super_admin() BEFORE applying it — but map_meta_cap
	 * runs FIRST, ahead of that short-circuit, so a filter there injecting do_not_allow would
	 * deny even a super admin. Either way the exposure is a missing filter DENYING someone, not
	 * granting them: a spurious 403, never a bypass. All of it is confined to claiming requests, which only this plugin's own
	 * cookie-authenticated network-admin JS sends, and the failure direction is a visible
	 * refusal rather than a silent bypass.
	 *
	 * The capability is hardcoded, deliberately. The FILTERED form
	 * ( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) ) guards the admin
	 * SURFACE — settings.php:350 and :2605, react-admin-ajax.php:98, welcome-api.php:60 — so
	 * an integrator can delegate who administers Cookie Notice. This is not that: a security
	 * gate an integrator could filter down to a site capability would be advisory. Hardcoding
	 * the capability is the norm rather than an exception here; may_push_base_posture() hardcodes
	 * manage_network_options the same way.
	 *
	 * Refusing outright rather than quietly downgrading to site scope is deliberate. A
	 * downgrade would leave the constructor's already-loaded NETWORK options being written
	 * into a SITE row (react-admin-ajax.php save_options() seeds from $cn->options), i.e.
	 * config bleed in the opposite direction, and it would fail silently for a super admin
	 * whose user was somehow not resolved.
	 *
	 * @return void
	 */
	public function enforce_network_scope() {
		// ── Begin network scope enforcement ──────────────────────────────────────
		if ( ! $this->network_scope_claimed )
			return;

		if ( current_user_can( 'manage_network_options' ) )
			return;

		// Untranslated on purpose: this runs on plugins_loaded and the textdomain does not
		// load until init (:319), so __() here would trip WP's just-in-time textdomain
		// notice. Same wording as network_scope_denied_message(), which the later-running
		// refusals use translated — keep the two in step.
		wp_send_json_error( [ 'error' => 'This setting applies to every site on the network, so only a Network Administrator (Super Admin) can change it. Please ask your network administrator to make this change.' ], 403 );
		// ── End network scope enforcement ────────────────────────────────────────
	}

	/**
	 * Set network data.
	 *
	 * @return void
	 */
	private function set_network_data() {
		// load plugin.php file
		if ( ! function_exists( 'is_plugin_active_for_network' ) )
			require_once( ABSPATH . '/wp-admin/includes/plugin.php' );

		// ── Begin network scope claim ────────────────────────────────────────────
		// Where "this request writes network-wide" is decided. Everything downstream reads
		// is_network_admin() and inherits the answer: the app id and bearer token in
		// welcome-api.php, the options row in react-admin-ajax.php, the admin-notice
		// handlers below.
		//
		// admin-ajax.php never sets WP_NETWORK_ADMIN, so is_network_admin() is false even
		// for a super admin on the Network Admin screen — hence the cn_network bypass. But
		// cn_network is a plain POST field the browser controls, and the permission check
		// in front of the handlers that honour it is manage_options (react-admin-ajax.php
		// verify_request(), welcome-api.php api_request()), which every SUBSITE admin
		// holds. Unauthorised, that let a subsite admin rewrite the network's app id and
		// key, pointing every site on the network at their own account.
		//
		// THE CAPABILITY IS NOT CHECKED HERE, AND MUST NOT BE. This runs from the
		// constructor, while wp-settings.php is still including plugin files, and
		// current_user_can() resolves through wp_get_current_user(), which is pluggable and
		// not loaded yet — so calling it here is a fatal "undefined function" on exactly the
		// legitimate super-admin save it means to allow. enforce_network_scope()'s docblock
		// carries the full load order; it is the ONE copy, so corrections land in one place.
		// (maybe_apply_dev_tier_override() is deferred for the same family of reason.)
		//
		// So this only records the CLAIM. enforce_network_scope() — plugins_loaded at
		// PHP_INT_MIN — is what refuses a claim the user cannot back.
		//
		// Non-AJAX network-admin page loads are not claims, and are not vetted here. WP's
		// menu check does wp_die() before admin_init — but it enforces the capability the
		// PLUGIN registered. That used to be manage_options, so a main-site administrator who
		// is not a super admin reached the network settings screen and validate_network_options()
		// wrote the network row. Closed separately: includes/settings.php:363 registers the
		// network menu with manage_network_options, and :2622 refuses the save through
		// can_write_at_scope().
		//
		// ADDING A HANDLER THAT WRITES NETWORK-WIDE? Read is_network_admin(), never the raw
		// POST field again — a second copy of this decision is a second hole, and three of
		// them were exactly that. is_network_admin() carries a VETTED answer from
		// plugins_loaded:PHP_INT_MIN onward — which, since nothing on plugins_loaded can run
		// earlier, means every hook there and after. The only unvetted window left is plugin
		// include time: muplugins_loaded, and the constructor itself.
		//
		// Other network-scope deciders do not route through here, and UNFORGEABLE IS NOT
		// AUTHORISED — each of them derived its scope honestly and still put a site-level
		// capability in front of a network-wide write. All are now closed by
		// can_write_at_scope(), which asks the question this gate does not:
		//
		//   - is_network_options() (network-active && global_override) takes no request input,
		//     but resolves to network scope for EVERY caller while verify_request() (:98) asks
		//     only for manage_options — includes/react-admin-ajax.php:430, :533, :585.
		//   - handle_disable() and handle_dismiss(), includes/modules/wp-consent-api/
		//     wp-consent-api.php:258 and :239.
		//   - validate_network_options(), described above.
		$cn_network = isset( $_POST['cn_network'] ) ? (int) $_POST['cn_network'] : false;

		$this->network_scope_claimed = is_multisite() && wp_doing_ajax() && $cn_network === 1;

		$this->network_admin = is_multisite() && ( is_network_admin() || $this->network_scope_claimed );
		// ── End network scope claim ──────────────────────────────────────────────

		// check whether the plugin is active for the entire network.
		$this->plugin_network_active = is_plugin_active_for_network( COOKIE_NOTICE_BASENAME );
	}

	/**
	 * Include required files.
	 *
	 * @return void
	 */
	private function includes() {
		include_once( COOKIE_NOTICE_PATH . 'includes/bot-detect.php' );
		include_once( COOKIE_NOTICE_PATH . 'includes/dashboard.php' );
		include_once( COOKIE_NOTICE_PATH . 'includes/frontend.php' );
		include_once( COOKIE_NOTICE_PATH . 'includes/functions.php' );
		include_once( COOKIE_NOTICE_PATH . 'includes/settings.php' );
		include_once( COOKIE_NOTICE_PATH . 'includes/react-admin-ajax.php' );
		include_once( COOKIE_NOTICE_PATH . 'includes/consent-logs.php' );
		include_once( COOKIE_NOTICE_PATH . 'includes/privacy-consent.php' );
		include_once( COOKIE_NOTICE_PATH . 'includes/privacy-consent-logs.php' );
		include_once( COOKIE_NOTICE_PATH . 'includes/welcome.php' );
		include_once( COOKIE_NOTICE_PATH . 'includes/welcome-api.php' );
		include_once( COOKIE_NOTICE_PATH . 'includes/welcome-frontend.php' );
		include_once( COOKIE_NOTICE_PATH . 'includes/modules/wp-consent-api/wp-consent-api.php' );
	}

	/**
	 * Load textdomain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'cookie-notice', false, dirname( COOKIE_NOTICE_BASENAME ) . '/languages/' );
	}

	/**
	 * Plugin activation.
	 *
	 * @global object $wpdb
	 *
	 * @param bool $network
	 * @return void
	 */
	public function activation( $network ) {
		// New installs start with React UI. We keep the compile-time default as
		// 'legacy' so existing sites (which may lack ui_mode in the DB) continue
		// to see the legacy interface through the multi_array_merge() fallback.
		$activation_defaults = $this->defaults['general'];
		$activation_defaults['ui_mode'] = 'react';

		// network activation?
		if ( is_multisite() && $network ) {
			// add network options
			add_site_option( 'cookie_notice_options', $activation_defaults );
			add_site_option( 'cookie_notice_privacy_consent', $this->defaults['privacy_consent'] );
			add_site_option( 'cookie_notice_status', $this->defaults['data'] );
			add_site_option( 'cookie_notice_version', $this->defaults['version'] );

			// Reactivations: switch network-level option to React UI.
			$net_options = get_site_option( 'cookie_notice_options', [] );

			if ( is_array( $net_options ) && ( ! isset( $net_options['ui_mode'] ) || $net_options['ui_mode'] !== 'react' ) ) {
				$net_options['ui_mode'] = 'react';
				update_site_option( 'cookie_notice_options', $net_options );
			}

			global $wpdb;

			// get all available sites
			$blogs_ids = $wpdb->get_col( 'SELECT blog_id FROM ' . $wpdb->blogs );

			foreach ( $blogs_ids as $blog_id ) {
				// change to another site
				switch_to_blog( (int) $blog_id );

				// run current site activation process
				$this->activate_site();

				restore_current_blog();
			}
		} else {
			$this->activate_site();
			// Set transient so maybe_redirect_after_activation() fires on the next admin_init.
			// Single-site only — network activation handled above (no per-site redirect).
			set_transient( 'cn_activation_redirect', 1, 30 );
		}
	}

	/**
	 * Single site activation.
	 *
	 * @return void
	 */
	public function activate_site() {
		// New installs start with React UI via activation_defaults.
		$activation_defaults = $this->defaults['general'];
		$activation_defaults['ui_mode'] = 'react';

		add_option( 'cookie_notice_options', $activation_defaults, null, false );
		add_option( 'cookie_notice_privacy_consent', $this->defaults['privacy_consent'], null, false );
		add_option( 'cookie_notice_status', $this->defaults['data'], null, false );
		add_option( 'cookie_notice_version', $this->defaults['version'], null, false );

		// Reactivations: add_option above is a no-op when the key exists,
		// so explicitly switch existing sites to React UI on activation.
		$options = get_option( 'cookie_notice_options', [] );

		if ( is_array( $options ) && ( ! isset( $options['ui_mode'] ) || $options['ui_mode'] !== 'react' ) ) {
			$options['ui_mode'] = 'react';
			update_option( 'cookie_notice_options', $options );
		}
	}

	/**
	 * Redirect to the React admin welcome screen after single-site activation.
	 *
	 * Fires on admin_init. Reads a short-lived transient set by activation().
	 * Guards against: network admin, bulk activation, and insufficient caps.
	 *
	 * ⚠️ Uses cn_react_welcome=1 (NOT welcome=1) — admin-welcome.js intercepts
	 *    the ?welcome=1 param and opens the old PHP modal simultaneously if used.
	 *
	 * @return void
	 */
	public function maybe_redirect_after_activation() {
		if ( ! get_transient( 'cn_activation_redirect' ) ) {
			return;
		}

		// Never redirect inside the network admin screen.
		if ( is_network_admin() ) {
			return;
		}

		// Bulk-activate (wp-admin/plugins.php?activate-multi=true) — skip redirect.
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		// Only admins should be redirected.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		delete_transient( 'cn_activation_redirect' );

		wp_safe_redirect( admin_url( 'admin.php?page=cookie-notice&cn_react_welcome=1' ) );
		exit;
	}

	/**
	 * Show a one-time success notice after a React modal license assignment.
	 *
	 * Triggered by ?license_assigned=1 (set by LicenseSelectStep on success).
	 * Reads optional ?slots_remaining=N for copy personalisation.
	 * The param disappears on the next page load automatically — no transient needed.
	 *
	 * @return void
	 */
	public function maybe_show_license_assigned_notice() {
		if ( ! is_admin() )
			return;

		if ( empty( $_GET['license_assigned'] ) || $_GET['license_assigned'] !== '1' )
			return;

		if ( ! current_user_can( 'manage_options' ) )
			return;

		$slots = isset( $_GET['slots_remaining'] ) ? (int) $_GET['slots_remaining'] : null;

		if ( $slots !== null && $slots > 0 ) {
			/* translators: %d: number of remaining domains on the plan */
			$slots_text = ' ' . sprintf(
				_n( '%d domain remaining on your plan.', '%d domains remaining on your plan.', $slots, 'cookie-notice' ),
				$slots
			);
		} elseif ( $slots === 0 ) {
			$slots_text = ' ' . esc_html__( 'No domains remaining on this plan.', 'cookie-notice' );
		} else {
			$slots_text = '';
		}

		$message = esc_html__( 'Cookie Compliance — Pro is now active on this site.', 'cookie-notice' ) . $slots_text;

		$this->add_notice(
			'<p>' . $message . '</p>',
			'notice-success is-dismissible'
		);
	}

	/**
	 * Switch UI mode via ?ui_mode=react|legacy query param.
	 *
	 * Persists the choice to the DB so it sticks across page loads.
	 * Admin-only (manage_options). Works in production — no CN_DEV_MODE required.
	 *
	 * @return void
	 */
	public function maybe_switch_ui_mode() {
		if ( ! isset( $_GET['ui_mode'] ) )
			return;

		// Only process ui_mode switches on the plugin's own admin page.
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'cookie-notice' )
			return;

		if ( ! current_user_can( 'manage_options' ) )
			return;

		// ── Begin network ui_mode write gate ─────────────────────────────────
		// In network admin this persists to the NETWORK row, which every site reads under
		// global_override — so it needs the network capability like any other network write.
		//
		// is_network_admin() is true for ANY /wp-admin/network/* request (core defines
		// WP_NETWORK_ADMIN before the bootstrap), with no cn_network claim involved, so
		// enforce_network_scope() never sees this one.
		//
		// Closed today by the network menu requiring manage_network_options — core's
		// user_can_access_admin_page() denies before admin_init. But that is the MENU's
		// capability, which cn_manage_network_cookie_notice_cap exists to let an integrator
		// lower, and that filter is documented as governing VISIBILITY only, with saves gated
		// separately. This write had no separate gate; now it does, so the documented promise
		// is true of it too.
		if ( $this->is_network_admin() && ! $this->can_write_at_scope( true ) )
			return;
		// ── End network ui_mode write gate ───────────────────────────────────

		$requested = sanitize_key( $_GET['ui_mode'] );

		if ( ! in_array( $requested, [ 'react', 'legacy' ], true ) )
			return;

		$current = $this->options['general']['ui_mode'];

		// Update DB only if the value actually changed.
		if ( $current !== $requested ) {
			$this->options['general']['ui_mode'] = $requested;

			if ( $this->is_network_admin() ) {
				$db_options = get_site_option( 'cookie_notice_options', [] );
			} else {
				$db_options = get_option( 'cookie_notice_options', [] );
			}

			$db_options['ui_mode'] = $requested;

			if ( $this->is_network_admin() ) {
				update_site_option( 'cookie_notice_options', $db_options );
			} else {
				update_option( 'cookie_notice_options', $db_options );
			}
		}

		// Always set in-memory so the current request renders the correct view.
		$this->options['general']['ui_mode'] = $requested;
	}

	/**
	 * Plugin deactivation.
	 *
	 * @global object $wpdb
	 *
	 * @param bool $network
	 * @return void
	 */
	public function deactivation( $network ) {
		// network deactivation?
		if ( is_multisite() && $network ) {
			$delete = $this->options['general']['global_override'] && $this->options['general']['deactivation_delete'];

			// delete network options?
			if ( $delete ) {
				delete_site_option( 'cookie_notice_options' );
				delete_site_option( 'cookie_notice_privacy_consent' );
				delete_site_option( 'cookie_notice_status' );
				delete_site_option( 'cookie_notice_app_analytics' );
				delete_site_option( 'cookie_notice_app_blocking' );
				delete_site_option( 'cookie_notice_blocking_push_pending' );
				// Network-scoped, so it needs its own delete: the per-site sweep below only
				// reaches delete_transient(), and the network retry cooldown is written with
				// set_site_transient(). Left behind, a reinstall inside the cooldown window
				// has its first network retry gated by the previous install's timer.
				delete_site_transient( 'cookie_notice_posture_push_retry' );
				delete_site_option( 'cookie_notice_version' );
			}

			global $wpdb;

			// get all available sites
			$blogs_ids = $wpdb->get_col( 'SELECT blog_id FROM ' . $wpdb->blogs );

			foreach ( $blogs_ids as $blog_id ) {
				// change to another site
				switch_to_blog( (int) $blog_id );

				// run current site deactivation process
				$this->deactivate_site( $delete );

				restore_current_blog();
			}
		} else
			$this->deactivate_site();
	}

	/**
	 * Single site deactivation.
	 *
	 * @param bool $force_deletion
	 * @return void
	 */
	public function deactivate_site( $force_deletion = false ) {
		// delete settings?
		if ( $force_deletion || $this->options['general']['deactivation_delete'] ) {
			// delete options
			delete_option( 'cookie_notice_options' );
			delete_option( 'cookie_notice_privacy_consent' );
			delete_option( 'cookie_notice_status' );
			delete_option( 'cookie_notice_app_analytics' );
			delete_option( 'cookie_notice_app_blocking' );
			delete_option( 'cookie_notice_blocking_push_pending' );
			delete_option( 'cookie_notice_version' );

			// delete transients if any
			delete_transient( 'cookie_notice_posture_push_retry' );
			delete_transient( 'cookie_notice_app_token' );
			delete_transient( 'cookie_notice_app_quick_config' );
			delete_transient( 'cookie_notice_app_subscriptions' );
		}

		// remove wp super cache cookie
		$this->wpsc_delete_cookie();
	}

	/**
	 * Update notice.
	 *
	 * @return void
	 */
	public function update_notice() {
		if ( ! current_user_can( 'install_plugins' ) )
			return;

		// bail an ajax
		if ( wp_doing_ajax() )
			return;

		$network = $this->is_network_admin();

		// get cookie compliance status
		$status = $this->get_status();

		// get subscription
		$subscription = $this->get_subscription();

		// update number
		$current_update = 14;

		// new version?
		if ( version_compare( $this->db_version, $this->defaults['version'], '<' ) ) {
			if ( $this->options['general']['update_version'] < $current_update ) {
				// check version, if update version is lower than plugin version, set update notice to true
				$this->options['general']['update_version'] = $current_update;
				$this->options['general']['update_notice'] = true;

				// update options
				if ( $network ) {
					$this->options['general']['update_notice_diss'] = false;

					update_site_option( 'cookie_notice_options', $this->options['general'] );
				} else
					update_option( 'cookie_notice_options', $this->options['general'] );
			}

			// update 2.4.17+
			if ( version_compare( $this->db_version, '2.4.17', '<' ) ) {
				// get cookie compliance activation timestamp
				$activation_date = $this->get_cc_activation_datetime();

				// get status data
				$data = $this->status_data;

				// no activation timestamp?
				if ( empty( $activation_date ) ) {
					if ( $status === 'active' )
						$activation = time();
					else
						$activation = 0;
				} else
					$activation = (int) $data['activation_datetime'];

				// update activation timestamp
				$data['activation_datetime'] = $activation;

				if ( $network )
					update_site_option( 'cookie_notice_status', $data );
				else
					update_option( 'cookie_notice_status', $data, false );
			}

			// update plugin version
			if ( $network )
				update_site_option( 'cookie_notice_version', $this->defaults['version'] );
			else
				update_option( 'cookie_notice_version', $this->defaults['version'], false );
		}

		// check page
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

		// if visiting settings, mark notice as read
		if ( $page === 'cookie-notice' && ! empty( $_GET['welcome'] ) ) {
			$this->options['general']['update_notice'] = false;

			if ( $network ) {
				$this->options['general']['update_notice_diss'] = true;

				update_site_option( 'cookie_notice_options', $this->options['general'] );
			} else
				update_option( 'cookie_notice_options', $this->options['general'] );
		}

		if ( is_multisite() && ( ( $this->is_plugin_network_active() && ! $network && $this->network_options['general']['global_override'] ) || ( $network && ! $this->is_plugin_network_active() ) ) )
			$this->options['general']['update_notice'] = false;

		// compliance only
		if ( $status === 'active' ) {
			// get analytics data options
			if ( $network )
				$analytics = get_site_option( 'cookie_notice_app_analytics', [] );
			else
				$analytics = get_option( 'cookie_notice_app_analytics', [] );

			if ( is_multisite() && ( ( $network && ! $this->is_plugin_network_active() && ! $this->network_options['general']['global_override'] ) || ( ! $network && $this->is_plugin_network_active() && $this->network_options['general']['global_override'] ) ) )
				$allow_notice = false;
			else
				$allow_notice = true;

			// show threshold limit warning
			if ( ! empty( $analytics ) && $allow_notice ) {
				// cycle usage data
				$cycle_usage = [
					'threshold'		=> ! empty( $analytics['cycleUsage']->threshold ) ? (int) $analytics['cycleUsage']->threshold : 0,
					'end_date'		=> ! empty( $analytics['cycleUsage']->endDate ) ? date_create_from_format( '!Y-m-d', $analytics['cycleUsage']->endDate ) : date_create_from_format( 'Y-m-d H:i:s', current_time( 'mysql', true ) )
				];

				// if threshold in use
				if ( $cycle_usage['threshold'] ) {
					// Gate on the single derived flag rather than re-deriving from raw
					// visits here. This branch used to compare visits >= threshold with no
					// plan check at all, so a Pro app whose cached blob still held a free
					// app's counters was told its compliance services had been deactivated
					// while everything was in fact running (HS#47302). threshold_exceeded()
					// is plan-aware and refuses to fire on a snapshot it cannot prove is
					// current. The remaining term keeps a dismissed notice dismissed for
					// the rest of the cycle.
					if ( $this->threshold_exceeded() && $this->options['general']['update_threshold_date'] < $cycle_usage['end_date']->getTimestamp() ) {
						$date_format = get_option( 'date_format' );

						$upgrade_link = $this->get_url( 'dashboard', '?app-id=' . $this->options['general']['app_id'] . '&open-modal=payment' );
						$threshold = $cycle_usage['threshold'];
						$cycle_date = date_i18n( $date_format, $cycle_usage['end_date']->getTimestamp() );

						$this->add_notice( '<div class="cn-notice-text" data-delay="' . esc_attr( $cycle_usage['end_date']->getTimestamp() ) . '"><h2>' . esc_html__( 'Cookie Compliance Warning', 'cookie-notice') . '</h2><p>' . sprintf( __( 'Your website has reached the <b>%1$s visits usage limit for the Cookie Compliance Free Plan</b>. Compliance services such as Consent Record Storage, Autoblocking, and Consent Analytics have been deactivated until current usage cycle ends on %2$s.', 'cookie-notice' ), $threshold, $cycle_date ) . '<br>' . sprintf( __( 'To reactivate compliance services now, <a href="%s" target="_blank">upgrade your domain to a Pro plan.</a>', 'cookie-notice' ) . '</p></div>', $upgrade_link ), 'cn-threshold error is-dismissible', 'div' );
					}
				}
			}

			// display review notice, for multisite only for network admin area
			if ( ! empty( $this->options['general']['review_notice'] ) && ( ! is_multisite() || ( $network && $this->is_plugin_network_active() ) ) ) {
				// get current time
				$current_time = time();

				// get cookie compliance activation timestamp
				$activation_date = $this->get_cc_activation_datetime();

				// get delay timestamp
				$delay_timestamp = (int) $this->options['general']['review_notice_delay'];

				// no delay?
				if ( $delay_timestamp === 0 )
					$compare_timestamp = $activation_date + 2 * WEEK_IN_SECONDS;
				else
					$compare_timestamp = $delay_timestamp;

				// display notice?
				if ( $compare_timestamp < $current_time )
					$this->add_notice( '<div class="cn-notice-text cn-review"><h2>' . esc_html__( 'We Value Your Feedback', 'cookie-notice' ) . '</h2><p>' . sprintf( __( "Hi, you've been using <strong>Cookie Compliance</strong> for more than %s. We hope it has been a valuable addition to your WordPress site. We would be grateful if you could take a few minutes to share your thoughts by leaving a review.", 'cookie-notice' ), human_time_diff( $activation_date, $current_time ) ) . '<br>' . esc_html__( 'Thank you for helping us improve and grow!', 'cookie-notice' ) . '</p><p class="cn-notice-actions"><a href="https://wordpress.org/support/plugin/cookie-notice/reviews/?filter=5#new-post" class="button-link cn-notice-review" target="_blank" rel="noopener">' . esc_html__( 'Review', 'cookie-notice' ) . '</a><a href="#" class="button-link cn-notice-delay">' . esc_html__( 'Delay', 'cookie-notice' ) . '</a><a href="#" class="button-link cn-notice-dismiss">' . esc_html__( 'Dismiss', 'cookie-notice' ) . '</a></p></div>', 'error', 'div' );
			}
		}
	}

	/**
	 * Add admin notice.
	 *
	 * @param string $html
	 * @param string $status
	 * @param string $container
	 * @return void
	 */
	private function add_notice( $html = '', $status = 'error', $container = '' ) {
		$this->notices[] = [
			'html'		=> $html,
			'status'	=> $status,
			'container'	=> ( ! empty( $container ) && in_array( $container, [ 'p', 'div' ] ) ? $container : '' )
		];

		add_action( 'admin_notices', [ $this, 'display_notice' ], 0 );
		add_action( 'network_admin_notices', [ $this, 'display_notice' ], 0 );
	}

	/**
	 * Print admin notices.
	 *
	 * @return void
	 */
	public function display_notice() {
		foreach( $this->notices as $notice ) {
			echo '
			<div id="cn-admin-notice" class="cn-notice notice notice-info ' . esc_attr( $notice['status'] ) . '">
				' . ( ! empty( $notice['container'] ) ? '<' . esc_attr( $notice['container'] ) . ' class="cn-notice-container">' : '' ) . '
				' . wp_kses_post( $notice['html'] ) . '
				' . ( ! empty( $notice['container'] ) ? '</' . esc_attr( $notice['container'] ) . ' class="cn-notice-container">' : '' ) . '
			</div>';
		}
	}

	/**
	 * Dismiss admin notice.
	 *
	 * @return void
	 */
	public function ajax_dismiss_admin_notice() {
		if ( ! current_user_can( 'install_plugins' ) )
			exit;

		if ( ! isset( $_POST['nonce'], $_POST['notice_action'] ) )
			exit;

		if ( wp_verify_nonce( $_POST['nonce'], 'cn_dismiss_notice' ) ) {
			// get notice action
			$notice_action = ! empty( $_POST['notice_action'] ) ? sanitize_key( $_POST['notice_action'] ) : 'dismiss';

			// network? — from the claim in set_network_data(), never from $_POST directly,
			// so this handler cannot disagree with the scope the rest of the request used.
			// (install_plugins above already limits this handler to super admins on
			// multisite — WP maps it that way in capabilities.php — so this is consistency,
			// not the fix itself.)
			$network = $this->is_network_admin();

			switch ( $notice_action ) {
				// threshold notice
				case 'threshold':
					// set delay period last cycle day
					$delay = isset( $_POST['param'] ) ? (int) $_POST['param'] : 0;

					$this->options['general']['update_threshold_date'] = $delay + DAY_IN_SECONDS;

					// update options
					if ( $network )
						update_site_option( 'cookie_notice_options', $this->options['general'] );
					else
						update_option( 'cookie_notice_options', $this->options['general'] );
					break;

				// delay notice
				case 'delay':
					// set delay period to 2 weeks from now
					$this->options['general']['update_delay_date'] = time() + 2 * WEEK_IN_SECONDS;

					// update options
					if ( $network )
						update_site_option( 'cookie_notice_options', $this->options['general'] );
					else
						update_option( 'cookie_notice_options', $this->options['general'] );
					break;

				// hide notice
				case 'approve':
				default:
					$this->options['general']['update_notice'] = false;
					$this->options['general']['update_delay_date'] = 0;

					// update options
					if ( $network ) {
						$this->options['general']['update_notice_diss'] = true;

						update_site_option( 'cookie_notice_options', $this->options['general'] );
					} else
						update_option( 'cookie_notice_options', $this->options['general'] );
			}
		}

		exit;
	}

	/**
	 * Dismiss review admin notice.
	 *
	 * @return void
	 */
	public function ajax_review_notice() {
		if ( ! current_user_can( 'install_plugins' ) )
			exit;

		if ( ! isset( $_POST['nonce'], $_POST['notice_action'] ) )
			exit;

		if ( wp_verify_nonce( $_POST['nonce'], 'cn_review_notice' ) ) {
			// get notice action
			$notice_action = ! empty( $_POST['notice_action'] ) ? sanitize_key( $_POST['notice_action'] ) : 'dismiss';

			// network? — from the claim in set_network_data(), never from $_POST directly,
			// so this handler cannot disagree with the scope the rest of the request used.
			// (install_plugins above already limits this handler to super admins on
			// multisite — WP maps it that way in capabilities.php — so this is consistency,
			// not the fix itself.)
			$network = $this->is_network_admin();

			switch ( $notice_action ) {
				// delay notice
				case 'delay':
					$this->options['general']['review_notice'] = true;
					$this->options['general']['review_notice_delay'] = time() + 2 * WEEK_IN_SECONDS;

					// update options
					if ( $network )
						update_site_option( 'cookie_notice_options', $this->options['general'] );
					else
						update_option( 'cookie_notice_options', $this->options['general'] );
					break;

				// hide notice
				case 'dismiss':
				case 'review':
				default:
					$this->options['general']['review_notice'] = false;
					$this->options['general']['review_notice_delay'] = 0;

					// update options
					if ( $network ) {
						$this->options['general']['update_notice_diss'] = true;

						update_site_option( 'cookie_notice_options', $this->options['general'] );
					} else
						update_option( 'cookie_notice_options', $this->options['general'] );
			}
		}

		exit;
	}

	/**
	 * Register shortcode.
	 *
	 * @return void
	 */
	public function register_shortcodes() {
		add_shortcode( 'cookies_accepted', [ $this, 'cookies_accepted_shortcode' ] );
		add_shortcode( 'cookies_revoke', [ $this, 'cookies_revoke_shortcode' ] );
		add_shortcode( 'cookies_policy_link', [ $this, 'cookies_policy_link_shortcode' ] );
	}

	/**
	 * Register cookies accepted shortcode.
	 *
	 * @param array $args
	 * @param string $content
	 * @return string
	 */
	public function cookies_accepted_shortcode( $args, $content ) {
		if ( $this->cookies_accepted() ) {
			// Only sanitize with wp_kses - do not decode entities from user-generated content
			$scripts = trim( wp_kses( $content, $this->get_allowed_html( 'body' ) ) );

			if ( ! empty( $scripts ) ) {
				if ( preg_match_all( '/' . get_shortcode_regex() . '/', $content ) )
					$scripts = do_shortcode( $scripts );

				return $scripts;
			}
		}

		return '';
	}

	/**
	 * Register cookies revoke shortcode.
	 *
	 * @param array $args
	 * @param string $content
	 * @return string
	 */
	public function cookies_revoke_shortcode( $args, $content ) {
		// get options
		$options = $this->options['general'];

		// WPML >= 3.2
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && version_compare( ICL_SITEPRESS_VERSION, '3.2', '>=' ) )
			$options['revoke_text'] = apply_filters( 'wpml_translate_single_string', $options['revoke_text'], 'Cookie Notice', 'Revoke button text' );
		// WPML and Polylang compatibility
		elseif ( function_exists( 'icl_t' ) )
			$options['revoke_text'] = icl_t( 'Cookie Notice', 'Revoke button text', $options['revoke_text'] );

		// defaults
		$defaults = [
			'title'	=> $options['revoke_text'],
			'class'	=> $options['css_class']
		];

		// combine shortcode arguments
		$args = shortcode_atts( $defaults, $args );

		if ( Cookie_Notice()->get_status() === 'active' )
			$shortcode = '<a href="#" class="cn-revoke-cookie cn-button-inline cn-revoke-inline' . esc_attr( $args['class'] !== '' ? ' ' . $args['class'] : '' ) . '" title="' . esc_attr( $args['title'] ) . '" data-hu-action="cookies-notice-revoke">' . esc_html( $args['title'] ) . '</a>';
		else
			$shortcode = '<a href="#" class="cn-revoke-cookie cn-button-inline cn-revoke-inline' . esc_attr( $args['class'] !== '' ? ' ' . $args['class'] : '' ) . '" title="' . esc_attr( $args['title'] ) . '">' . esc_html( $args['title'] ) . '</a>';

		return $shortcode;
	}

	/**
	 * Register cookies policy link shortcode.
	 *
	 * @param array $args
	 * @param string $content
	 * @return string
	 */
	public function cookies_policy_link_shortcode( $args, $content ) {
		// get options
		$options = $this->options['general'];

		// WPML >= 3.2
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && version_compare( ICL_SITEPRESS_VERSION, '3.2', '>=' ) ) {
			$options['see_more_opt']['text'] = apply_filters( 'wpml_translate_single_string', $options['see_more_opt']['text'], 'Cookie Notice', 'Privacy policy text' );
			$options['see_more_opt']['link'] = apply_filters( 'wpml_translate_single_string', $options['see_more_opt']['link'], 'Cookie Notice', 'Custom link' );
		// WPML and Polylang compatibility
		} elseif ( function_exists( 'icl_t' ) ) {
			$options['see_more_opt']['text'] = icl_t( 'Cookie Notice', 'Privacy policy text', $options['see_more_opt']['text'] );
			$options['see_more_opt']['link'] = icl_t( 'Cookie Notice', 'Custom link', $options['see_more_opt']['link'] );
		}

		if ( $options['see_more_opt']['link_type'] === 'page' ) {
			// multisite with global override?
			if ( is_multisite() && $this->is_plugin_network_active() && $this->network_options['general']['global_override'] ) {
				// get main site id
				$main_site_id = get_main_site_id();

				// switch to main site
				switch_to_blog( $main_site_id );

				// update page id for current language if needed
				if ( function_exists( 'icl_object_id' ) )
					$options['see_more_opt']['id'] = icl_object_id( $options['see_more_opt']['id'], 'page', true );

				// get main site privacy policy link
				$permalink = get_permalink( $options['see_more_opt']['id'] );

				// restore current site
				restore_current_blog();
			} else {
				// update page id for current language if needed
				if ( function_exists( 'icl_object_id' ) )
					$options['see_more_opt']['id'] = icl_object_id( $options['see_more_opt']['id'], 'page', true );

				// get privacy policy link
				$permalink = get_permalink( $options['see_more_opt']['id'] );
			}
		}

		// defaults
		$defaults = [
			'title'	=> $options['see_more_opt']['text'] !== '' ? $options['see_more_opt']['text'] : '&#x279c;',
			'link'	=> $options['see_more_opt']['link_type'] === 'custom' ? $options['see_more_opt']['link'] : $permalink,
			'class'	=> $options['css_class']
		];

		// combine shortcode arguments
		$args = shortcode_atts( $defaults, $args );

		$shortcode = '<a href="' . esc_url( $args['link'] ) . '" target="' . esc_attr( $options['link_target'] ) . '" id="cn-more-info" class="cn-privacy-policy-link cn-link' . esc_attr( $args['class'] !== '' ? ' ' . $args['class'] : '' ) . '" data-link-url="' . esc_url( $args['link'] ) . '" data-link-target="' . esc_attr( $options['link_target'] ) . '">' . esc_html( $args['title'] ) . '</a>';

		return $shortcode;
	}

	/**
	 * Check if cookies are accepted.
	 *
	 * @return bool
	 */
	public static function cookies_accepted() {
		if ( Cookie_Notice()->get_status() === 'active' ) {
			// get cookie
			$cookies = isset( $_COOKIE['hu-consent'] ) ? json_decode( stripslashes( $_COOKIE['hu-consent'] ), true ) : [];

			// valid cookie?
			if ( json_last_error() === JSON_ERROR_NONE && ! empty( $cookies ) && is_array( $cookies ) && isset( $cookies['consent'] ) )
				$result = (bool) $cookies['consent'];
			else
				$result = false;
		} else
			$result = isset( $_COOKIE['cookie_notice_accepted'] ) && $_COOKIE['cookie_notice_accepted'] === 'true';

		return (bool) apply_filters( 'cn_is_cookie_accepted', $result );
	}

	/**
	 * Check if cookies are set.
	 *
	 * @return bool
	 */
	public static function cookies_set() {
		if ( Cookie_Notice()->get_status() === 'active' )
			$result = isset( $_COOKIE['hu-consent'] );
		else
			$result = isset( $_COOKIE['cookie_notice_accepted'] );

		return (bool) apply_filters( 'cn_is_cookie_set', $result );
	}

	/**
	 * Add WP Super Cache cookie.
	 *
	 * @return void
	 */
	public function wpsc_add_cookie() {
		if ( $this->get_status() !== 'active' )
			do_action( 'wpsc_add_cookie', 'cookie_notice_accepted' );
	}

	/**
	 * Delete WP Super Cache cookie.
	 *
	 * @return void
	 */
	public function wpsc_delete_cookie() {
		if ( $this->get_status() !== 'active' )
			do_action( 'wpsc_delete_cookie', 'cookie_notice_accepted' );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $page
	 * @return void
	 */
	public function admin_enqueue_scripts( $page ) {
		// plugins page?
		if ( $page === 'plugins.php' ) {
			add_thickbox();

			wp_enqueue_script( 'cookie-notice-admin-plugins', COOKIE_NOTICE_URL . '/js/admin-plugins.js', [ 'jquery' ], $this->defaults['version'] );

			wp_enqueue_style( 'cookie-notice-admin-plugins', COOKIE_NOTICE_URL . '/css/admin-plugins.css', [], $this->defaults['version'] );

			// prepare script data
			$script_data = [
				'deactivate'	=> esc_html__( 'Cookie Compliance - Deactivation survey', 'cookie-notice' ),
				'nonce'			=> wp_create_nonce( 'cn-deactivate-plugin' )
			];

			wp_add_inline_script( 'cookie-notice-admin-plugins', 'var cnArgsPlugins = ' . wp_json_encode( $script_data ) . ";\n", 'before' );
		}

		// notice js and css
		wp_enqueue_script( 'cookie-notice-admin-notice', COOKIE_NOTICE_URL . '/js/admin-notice.js', [], $this->defaults['version'] );

		// prepare script data
		$script_data = [
			'ajaxURL'		=> admin_url( 'admin-ajax.php' ),
			'nonce'			=> wp_create_nonce( 'cn_dismiss_notice' ),
			'reviewNonce'	=> wp_create_nonce( 'cn_review_notice' ),
			'network'		=> $this->is_network_admin()
		];

		wp_add_inline_script( 'cookie-notice-admin-notice', 'var cnArgsNotice = ' . wp_json_encode( $script_data ) . ";\n", 'before' );

		wp_enqueue_style( 'cookie-notice-admin-notice', COOKIE_NOTICE_URL . '/css/admin-notice.css', [], $this->defaults['version'] );
	}

	/**
	 * Set plugin links.
	 *
	 * @return void
	 */
	public function set_plugin_links() {
		// filters
		add_filter( 'plugin_action_links', [ $this, 'plugin_action_links' ], 10, 2 );
		add_filter( 'network_admin_plugin_action_links', [ $this, 'plugin_action_links' ], 10, 2 );
	}

	/**
	 * Add links to settings page.
	 *
	 * @param array $links
	 * @param string $file
	 * @return array
	 */
	public function plugin_action_links( $links, $file ) {
		if ( ! current_user_can( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) ) )
			return $links;

		if ( $file === COOKIE_NOTICE_BASENAME ) {
			if ( ! empty( $links['deactivate'] ) ) {
				// link already contains class attribute?
				if ( preg_match( '/<a.*?class=(\'|")(.*?)(\'|").*?>/is', $links['deactivate'], $result ) === 1 )
					$links['deactivate'] = preg_replace( '/(<a.*?class=(?:\'|").*?)((?:\'|").*?>)/s', '$1 cn-deactivate-plugin-modal$2', $links['deactivate'] );
				else
					$links['deactivate'] = preg_replace( '/(<a.*?)>/s', '$1 class="cn-deactivate-plugin-modal">', $links['deactivate'] );

				// link already contains href attribute?
				if ( preg_match( '/<a.*?href=(\'|")(.*?)(\'|").*?>/is', $links['deactivate'], $result ) === 1 ) {
					if ( ! empty( $result[2] ) )
						$this->deactivaion_url = $result[2];
				}
			}

			// skip settings link if plugin is activated from main site
			if ( ! ( $this->is_network_admin() && ! $this->is_plugin_network_active() ) ) {
				$url = $this->is_network_admin() ? network_admin_url( 'admin.php?page=cookie-notice' ) : admin_url( 'admin.php?page=cookie-notice' );

				// put settings link at start
				array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'cookie-notice' ) ) );
			}

			// get cookie compliance status
			$status = $this->get_status();

			if ( is_multisite() ) {
				$check_status = empty( $status ) && ( ( $this->is_network_admin() && $this->is_plugin_network_active() && $this->network_options['general']['global_override'] ) || ( ! $this->is_network_admin() && ( ( $this->is_plugin_network_active() && ! $this->network_options['general']['global_override'] ) || ! $this->is_plugin_network_active() ) ) );
			} else
				$check_status = empty( $status );

			// add upgrade link
			if ( $check_status ) {
				$url = $this->is_network_admin() ? network_admin_url( 'admin.php?page=cookie-notice&welcome=1' ) : admin_url( 'admin.php?page=cookie-notice&welcome=1' );

				$links[] = sprintf( '<a href="%s" style="color: #20C19E; font-weight: bold">%s</a>', esc_url( $url ), esc_html__( 'Try Cookie Compliance free', 'cookie-notice' ) );
			}
		}

		return $links;
	}

	/**
	 * Deactivation modal HTML template.
	 *
	 * @global string $pagenow
	 *
	 * @return void
	 */
	public function deactivate_plugin_template() {
		global $pagenow;

		// display only for plugins page
		if ( $pagenow !== 'plugins.php' )
			return;

		echo '
		<div id="cn-deactivation-modal" style="display: none">
			<div id="cn-deactivation-container">
				<div id="cn-deactivation-body">
					<div class="cn-deactivation-options">
						<p><em>' . esc_html__( "We're sorry to see you go. Could you please tell us what happened?", 'cookie-notice' ) . '</em></p>
						<ul>';

		foreach ( [
				'1'	=> esc_html__( "I couldn't figure out how to make it work.", 'cookie-notice' ),
				'2'	=> esc_html__( 'I found another plugin to use for the same task.', 'cookie-notice' ),
				'3'	=> esc_html__( 'The Cookie Compliance banner is too big.', 'cookie-notice' ),
				'4'	=> esc_html__( 'The Cookie Compliance consent choices (Silver, Gold, Platinum) are confusing.', 'cookie-notice' ),
				'5'	=> esc_html__( 'The Cookie Compliance default settings are too strict.', 'cookie-notice' ),
				'6'	=> esc_html__( 'The web application user interface is not clear to me.', 'cookie-notice' ),
				'7'	=> esc_html__( "Support isn't timely.", 'cookie-notice' ),
				'8'	=> esc_html__( 'Other', 'cookie-notice' )
		] as $option => $text ) {
			echo '
							<li><label><input type="radio" name="cn_deactivation_option" value="' . esc_attr( $option ) . '" ' . checked( '8', $option, false ) . ' />' . esc_html( $text ) . '</label></li>';
			}

		echo '
						</ul>
					</div>
					<div class="cn-deactivation-textarea">
						<textarea name="cn_deactivation_other"></textarea>
					</div>
				</div>
				<div id="cn-deactivation-footer">
					<a href="" class="button cn-deactivate-plugin-cancel">' . esc_html__( 'Cancel', 'cookie-notice' ) . '</a>
					<a href="' . esc_url( $this->deactivaion_url ) . '" class="button button-secondary cn-deactivate-plugin-simple">' . esc_html__( 'Deactivate', 'cookie-notice' ) . '</a>
					<a href="' . esc_url( $this->deactivaion_url ) . '" class="button button-primary right cn-deactivate-plugin-data">' . esc_html__( 'Deactivate & Submit', 'cookie-notice' ) . '</a>
					<span class="spinner"></span>
				</div>
			</div>
		</div>';
	}

	/**
	 * Send data about deactivation of the plugin.
	 *
	 * @return void
	 */
	public function deactivate_plugin() {
		// check permissions
		if ( ! current_user_can( 'install_plugins' ) || wp_verify_nonce( $_POST['nonce'], 'cn-deactivate-plugin' ) === false )
			return;

		if ( isset( $_POST['option_id'] ) ) {
			$option_id = (int) $_POST['option_id'];

			// avoid fake submissions
			if ( $option_id === 8 ) {
				$other = isset( $_POST['other'] ) ? sanitize_textarea_field( $_POST['other'] ) : '';

				// no reason?
				if ( $other === '' )
					wp_send_json_success();
			}

			wp_remote_post(
				'https://hu-manity.co/wp-json/api/v1/forms/',
				[
					'timeout'		=> 15,
					'blocking'		=> true,
					'headers'		=> [],
					'body'			=> [
						'id'		=> 1,
						'option'	=> $option_id,
						'other'		=> $other,
						'referrer'	=> get_site_url()
					]
				]
			);

			wp_send_json_success();
		}

		wp_send_json_error();
	}

	/**
	 * Get allowed script blocking HTML.
	 *
	 * @param string $type
	 * @return array
	 */
	public function get_allowed_html( $type = 'head' ) {
		// default allowed html for both types
		$allowed_html = [
			'script'	=> [
				'type'				=> true,
				'src'				=> true,
				'charset'			=> true,
				'async'				=> true,
				'defer'				=> true,
				'crossorigin'		=> true,
				'fetchpriority'		=> true,
				'referrerpolicy'	=> true,
				'nomodule'			=> true,
				'nonce'				=> true,
				'integrity'			=> true,
				'class'				=> true,
				'id'				=> true
			],
			'noscript'	=> [
				'class'	=> true,
				'id'	=> true
			],
			'style'		=> [
				'type'	=> true,
				'media'	=> true,
				'nonce'	=> true,
				'class'	=> true,
				'id'	=> true
			]
		];

		if ( $type === 'head' ) {
			// allow links for head
			$allowed_html['link'] = [
				'as'				=> true,
				'crossorigin'		=> true,
				'fetchpriority'		=> true,
				'imagesizes'		=> true,
				'imagesrcset'		=> true,
				'referrerpolicy'	=> true,
				'sizes'				=> true,
				'integrity'			=> true,
				'href'				=> true,
				'hreflang'			=> true,
				'rel'				=> true,
				'type'				=> true,
				'title'				=> true,
				'media'				=> true,
				'class'				=> true,
				'id'				=> true
			];
		} elseif ( $type === 'body' ) {
			// allow ifarmes for body
			$allowed_html['iframe'] = [
				'src'				=> true,
				'srcdoc'			=> true,
				'height'			=> true,
				'width'				=> true,
				'class'				=> true,
				'id'				=> true,
				'allow'				=> true,
				'loading'			=> true,
				'name'				=> true,
				'title'				=> true,
				'referrerpolicy'	=> true,
				'sandbox'			=> true,
				'allowfullscreen'	=> true
			];
		}

		// combine allowed tags with default post allowed tags
		return apply_filters( 'cn_refuse_code_allowed_html', array_merge( wp_kses_allowed_html( 'post' ), $allowed_html ), $type );
	}

	/**
	 * Merge multidimensional associative arrays.
	 * Works only with strings, integers and arrays as keys. Values can be any type but they have to have same type to be kept in the final array.
	 * Every array should have the same type of elements. Only keys from $defaults array will be kept in the final array unless $siblings are not empty.
	 * $siblings examples: array( '=>', 'only_first_level', 'first_level=>second_level', 'first_key=>next_key=>sibling' ) and so on.
	 * Single '=>' means that all siblings of the highest level will be kept in the final array.
	 *
	 * @param array $defaults Array with defaults values
	 * @param array $array Array to merge
	 * @param bool|array $siblings Whether to allow "string" siblings to copy from $array if they do not exist in $defaults, false otherwise
	 * @return array
	 */
	public function multi_array_merge( $defaults, $array, $siblings = false ) {
		// make a copy for better performance and to prevent $default override in foreach
		$copy = $defaults;

		// prepare siblings for recursive deeper level
		$new_siblings = [];

		// allow siblings?
		if ( ! empty( $siblings ) && is_array( $siblings ) ) {
			foreach ( $siblings as $sibling ) {
				// highest level siblings
				if ( $sibling === '=>' ) {
					// copy all non-existent string siblings
					foreach( $array as $key => $value ) {
						if ( is_string( $key ) && ! array_key_exists( $key, $defaults ) ) {
							$defaults[$key] = null;
						}
					}
				// sublevel siblings
				} else {
					// explode siblings
					$ex = explode( '=>', $sibling );

					// copy all non-existent siblings
					foreach ( array_keys( $array[$ex[0]] ) as $key ) {
						if ( ! array_key_exists( $key, $defaults[$ex[0]] ) )
							$defaults[$ex[0]][$key] = null;
					}

					// more than one sibling child?
					if ( count( $ex ) > 1 )
						$new_siblings[$ex[0]] = [ substr_replace( $sibling, '', 0, strlen( $ex[0] . '=>' ) ) ];
					// no more sibling children
					else
						$new_siblings[$ex[0]] = false;
				}
			}
		}

		// loop through first array
		foreach ( $defaults as $key => $value ) {
			// integer key?
			if ( is_int( $key ) ) {
				$copy = array_unique( array_merge( $defaults, $array ), SORT_REGULAR );

				break;
			// string key?
			} elseif ( is_string( $key ) && isset( $array[$key] ) ) {
				// string, boolean, integer or null values?
				if ( ( is_string( $value ) && is_string( $array[$key] ) ) || ( is_bool( $value ) && is_bool( $array[$key] ) ) || ( is_int( $value ) && is_int( $array[$key] ) ) || is_null( $value ) )
					$copy[$key] = $array[$key];
				// arrays
				elseif ( is_array( $value ) && isset( $array[$key] ) && is_array( $array[$key] ) ) {
					if ( empty( $value ) )
						$copy[$key] = $array[$key];
					else
						$copy[$key] = $this->multi_array_merge( $defaults[$key], $array[$key], ( isset( $new_siblings[$key] ) ? $new_siblings[$key] : false ) );
				}
			}
		}

		return $copy;
	}

	/**
	 * Indicate if current page is the Cookie Policy page.
	 *
	 * @return bool
	 */
	public function is_cookie_policy_page() {
		// get privacy policy options
		$see_more = $this->options['general']['see_more_opt'];

		// custom link?
		if ( $see_more['link_type'] !== 'page' )
			return false;

		// get current object
		$current_page = sanitize_post( $GLOBALS['wp_the_query']->get_queried_object() );

		// check if current page is privacy policy page
		return $current_page->post_name === get_post_field( 'post_name', $see_more['id'] );
	}
}

/**
 * Initialize Cookie Notice.
 *
 * @return object
 */
function Cookie_Notice() {
	static $instance;

	// first call to instance() initializes the plugin
	if ( $instance === null || ! ( $instance instanceof Cookie_Notice ) )
		$instance = Cookie_Notice::instance();

	return $instance;
}

Cookie_Notice();
