<?php

// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Fetch the wpDashboard notification rule for a given scorecard state.
 *
 * Copy for the dashboard widget lives in includes/notifications.json so it
 * stays centrally editable (same source the React topBar/sidebar use).
 *
 * @param string $state Scorecard state: banner_only|free_under|free_near|free_over|pro.
 * @return array|null Highest-priority matching wpDashboard rule, or null.
 */
function cn_get_dashboard_notification( $state ) {
	$rules_json = file_get_contents( COOKIE_NOTICE_PATH . 'includes/notifications.json' );
	$rules_data = $rules_json !== false ? json_decode( $rules_json, true ) : null;

	if ( ! is_array( $rules_data ) || empty( $rules_data['rules'] ) ) {
		return null;
	}

	$best = null;

	foreach ( $rules_data['rules'] as $rule ) {
		if ( ( $rule['slot'] ?? '' ) !== 'wpDashboard' ) {
			continue;
		}

		// Match on the scorecard state.
		if ( ( $rule['condition']['state'] ?? '' ) !== $state ) {
			continue;
		}

		if ( ! $best || ( $rule['priority'] ?? 0 ) > ( $best['priority'] ?? 0 ) ) {
			$best = $rule;
		}
	}

	return $best;
}

/**
 * Cookie_Notice_Dashboard class.
 *
 * @class Cookie_Notice_Dashboard
 */
class Cookie_Notice_Dashboard {

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'wp_dashboard_setup', [ $this, 'wp_dashboard_setup' ], 11 );
		add_action( 'wp_network_dashboard_setup', [ $this, 'wp_dashboard_setup' ], 11 );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_scripts_styles' ] );

		// site status
		add_filter( 'site_status_tests', [ $this, 'add_tests' ] );
	}

	/**
	 * Initialize widget.
	 *
	 * @global array $wp_meta_boxes
	 *
	 * @return void
	 */
	public function wp_dashboard_setup() {
		// filter user_can_see_stats
		if ( ! current_user_can( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) ) )
			return;

		// get main instance
		$cn = Cookie_Notice();

		// check when to hide widget
		if ( is_multisite() ) {
			// site dashboard
			if ( current_action() === 'wp_dashboard_setup' && $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'] )
				return;

			// network dashboard
			if ( current_action() === 'wp_network_dashboard_setup' ) {
				if ( $cn->is_plugin_network_active() ) {
					if ( ! $cn->network_options['general']['global_override'] )
						return;
				} else
					return;
			}
		}

		// check is it network admin
		if ( $cn->is_network_admin() )
			$dashboard_key = 'dashboard-network';
		else
			$dashboard_key = 'dashboard';

		global $wp_meta_boxes;

		// set widget key
		$widget_key = 'cn_dashboard_stats';

		// add dashboard scorecard widget
		wp_add_dashboard_widget( $widget_key, __( 'Compliance by Hu-manity.co', 'cookie-notice' ), [ $this, 'dashboard_widget' ] );

		// get widgets
		$normal_dashboard = $wp_meta_boxes[$dashboard_key]['normal']['core'];

		// attempt to place the widget at the top
		$widget_instance = [
			$widget_key	=> $normal_dashboard[ $widget_key ]
		];

		// remove new widget
		unset( $normal_dashboard[ $widget_key ] );

		// merge widgets
		$sorted_dashboard = array_merge( $widget_instance, $normal_dashboard );

		// update widgets
		$wp_meta_boxes[$dashboard_key]['normal']['core'] = $sorted_dashboard;
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * The scorecard renders in every status, so CSS + the dashboard JS (which
	 * also sets the usage-bar widths) load unconditionally. Chart.js and the
	 * activity chart data only load when compliance is active.
	 *
	 * @param string $pagenow
	 * @return void
	 */
	public function admin_scripts_styles( $pagenow ) {
		if ( $pagenow !== 'index.php' )
			return;

		// filter user_can_see_stats
		if ( ! current_user_can( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) ) )
			return;

		// get main instance
		$cn = Cookie_Notice();

		// localized asset version so the redesign busts browser/CDN caches without touching the global version constant
		$assets_ver = $cn->defaults['version'] . '-sc4';
		$active      = ( $cn->get_status() === 'active' );

		// styles (always)
		wp_enqueue_style( 'cookie-notice-admin-dashboard', COOKIE_NOTICE_URL . '/css/admin-dashboard.css', [], $assets_ver );

		// dashboard script (always — drives bar widths + charts when present)
		$dash_deps = [ 'jquery' ];

		if ( $active ) {
			wp_register_script( 'cookie-notice-admin-chartjs', COOKIE_NOTICE_URL . '/assets/chartjs/chart.min.js', [ 'jquery' ], '4.5.1', true );
			wp_enqueue_script( 'cookie-notice-admin-chartjs' );
			$dash_deps[] = 'cookie-notice-admin-chartjs';
		}

		wp_register_script( 'cookie-notice-admin-dashboard', COOKIE_NOTICE_URL . '/js/admin-dashboard.js', $dash_deps, $assets_ver, true );
		wp_enqueue_script( 'cookie-notice-admin-dashboard' );

		add_filter( 'script_loader_tag', [ $this, 'add_dashboard_optimizer_attrs' ], 10, 2 );

		$chartdata = [];

		if ( $active ) {
			// analytics scope (network-aware)
			if ( is_multisite() && $cn->is_network_admin() && $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'] )
				$analytics = get_site_option( 'cookie_notice_app_analytics', [] );
			else
				$analytics = get_option( 'cookie_notice_app_analytics', [] );

			$line_options = [
				'maintainAspectRatio'	=> false,
				'responsive'			=> true,
				'scales'				=> [
					'x'	=> [
						'display'	=> true,
						'title'		=> [ 'display' => false ]
					],
					'y'	=> [
						'display'		=> true,
						'grace'			=> 0,
						'beginAtZero'	=> true,
						'title'			=> [ 'display' => false ],
						'ticks'			=> [
							'precision'		=> 0,
							'maxTicksLimit'	=> 12
						]
					]
				],
				'plugins' => [ 'legend' => [ 'display' => false ] ]
			];

			$chartdata = [
				'consent-activity'				=> [ 'type' => 'line', 'options' => $line_options ],
				'privacy-consent-logs-activity'	=> [ 'type' => 'line', 'options' => $line_options ]
			];

			// consent activity dataset (3 levels)
			$consent_activity_data = [
				'labels' => [],
				'datasets' => [
					0 => [
						'label'					=> sprintf( __( 'Level %s', 'cookie-notice' ), 1 ),
						'data'					=> [],
						'fill'					=> true,
						'backgroundColor'		=> 'rgba(196, 196, 196, 0.3)',
						'borderColor'			=> 'rgba(196, 196, 196, 1)',
						'borderWidth'			=> 1.2,
						'borderDash'			=> [],
						'pointBorderColor'		=> 'rgba(196, 196, 196, 1)',
						'pointBackgroundColor'	=> 'rgba(255, 255, 255, 1)',
						'pointBorderWidth'		=> 1.2
					],
					1 => [
						'label'					=> sprintf( __( 'Level %s', 'cookie-notice' ), 2 ),
						'data'					=> [],
						'fill'					=> true,
						'backgroundColor'		=> 'rgba(213, 181, 101, 0.3)',
						'borderColor'			=> 'rgba(213, 181, 101, 1)',
						'borderWidth'			=> 1.2,
						'borderDash'			=> [],
						'pointBorderColor'		=> 'rgba(213, 181, 101, 1)',
						'pointBackgroundColor'	=> 'rgba(255, 255, 255, 1)',
						'pointBorderWidth'		=> 1.2
					],
					2 => [
						'label'					=> sprintf( __( 'Level %s', 'cookie-notice' ), 3 ),
						'data'					=> [],
						'fill'					=> true,
						'backgroundColor'		=> 'rgba(152, 145, 177, 0.3)',
						'borderColor'			=> 'rgba(152, 145, 177, 1)',
						'borderWidth'			=> 1.2,
						'borderDash'			=> [],
						'pointBorderColor'		=> 'rgba(152, 145, 177, 1)',
						'pointBackgroundColor'	=> 'rgba(255, 255, 255, 1)',
						'pointBorderWidth'		=> 1.2
					]
				]
			];

			$chart_date_format = 'j/m';

			for ( $i = 29; $i >= 0; $i-- ) {
				$consent_activity_data['labels'][] = date( $chart_date_format, strtotime( '-'. ( $i + 1 ) .' days' ) );
				$consent_activity_data['datasets'][0]['data'][] = 0;
				$consent_activity_data['datasets'][1]['data'][] = 0;
				$consent_activity_data['datasets'][2]['data'][] = 0;
			}

			if ( ! empty( $analytics['consentActivities'] ) && is_array( $analytics['consentActivities'] ) ) {
				foreach ( $analytics['consentActivities'] as $index => $entry ) {
					$time = date_i18n( $chart_date_format, strtotime( $entry->eventdt ) );
					$i = array_search( $time, $consent_activity_data['labels'] );

					if ( $i !== false )
						$consent_activity_data['datasets'][(int) $entry->consentlevel - 1]['data'][$i] = (int) $entry->totalrecd;
				}
			}

			$chartdata['consent-activity']['data'] = $consent_activity_data;

			// privacy consent logs dataset
			$privacy_consent_logs_activity_data = [
				'labels' => [],
				'datasets' => [
					0 => [
						'label'					=> __( 'Privacy Content Logs', 'cookie-notice' ),
						'data'					=> [],
						'fill'					=> true,
						'backgroundColor'		=> 'rgba(32, 193, 158, 0.3)',
						'borderColor'			=> 'rgba(32, 193, 158, 1)',
						'borderWidth'			=> 1.2,
						'borderDash'			=> [],
						'pointBorderColor'		=> 'rgba(32, 193, 158, 1)',
						'pointBackgroundColor'	=> 'rgba(255, 255, 255, 1)',
						'pointBorderWidth'		=> 1.2
					]
				]
			];

			for ( $i = 29; $i >= 0; $i-- ) {
				$privacy_consent_logs_activity_data['labels'][] = date( $chart_date_format, strtotime( '-'. ( $i + 1 ) .' days' ) );
				$privacy_consent_logs_activity_data['datasets'][0]['data'][] = 0;
			}

			if ( ! empty( $analytics['privacyActivities'] ) && is_array( $analytics['privacyActivities'] ) ) {
				foreach ( $analytics['privacyActivities'] as $index => $entry ) {
					$time = date_i18n( $chart_date_format, strtotime( $entry->date ) );
					$i = array_search( $time, $privacy_consent_logs_activity_data['labels'] );

					if ( $i !== false )
						$privacy_consent_logs_activity_data['datasets'][0]['data'][$i] = (int) $entry->count;
				}
			}

			$chartdata['privacy-consent-logs-activity']['data'] = $privacy_consent_logs_activity_data;
		}

		// prepare script data
		$script_data = [
			'ajaxURL'	=> admin_url( 'admin-ajax.php' ),
			'charts'	=> $chartdata
		];

		wp_add_inline_script( 'cookie-notice-admin-dashboard', 'var cnDashboardArgs = ' . wp_json_encode( $script_data ) . ";\n", 'before' );
	}

	/**
	 * Stamp optimizer/CDN exclusion attributes on the dashboard script tags.
	 *
	 * Mirrors the pattern in Cookie_Notice_Settings::add_react_admin_optimizer_attrs().
	 *
	 * @param string $tag    Combined script tag(s) for this handle.
	 * @param string $handle Script handle being filtered.
	 * @return string
	 */
	public function add_dashboard_optimizer_attrs( $tag, $handle ) {
		if ( $handle !== 'cookie-notice-admin-dashboard' && $handle !== 'cookie-notice-admin-chartjs' )
			return $tag;

		$attrs = Cookie_Notice::optimizer_skip_attrs();

		return preg_replace( '/(<script\b)(?![^>]*\bdata-cfasync\b)/i', '$1' . $attrs, $tag );
	}

	/**
	 * Gather every signal the scorecard reads, in one place.
	 *
	 * Applies the CN_DEV_MODE ?cn_usage / ?cn_tier overrides so all five
	 * lifecycle states are demoable on a dev install.
	 *
	 * @return array
	 */
	protected function get_signals() {
		$cn = Cookie_Notice();

		// analytics scope (network-aware, mirrors enqueue logic)
		if ( is_multisite() && $cn->is_network_admin() && $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'] )
			$analytics = get_site_option( 'cookie_notice_app_analytics', [] );
		else
			$analytics = get_option( 'cookie_notice_app_analytics', [] );

		// blocking option scope (mirrors frontend.php is_network_options)
		if ( method_exists( $cn, 'is_network_options' ) && $cn->is_network_options() )
			$blocking = get_site_option( 'cookie_notice_app_blocking' );
		else
			$blocking = get_option( 'cookie_notice_app_blocking' );

		if ( ! is_array( $blocking ) )
			$blocking = [];

		$status       = $cn->get_status();
		$connected    = ( $status === 'active' );
		$app_id       = ! empty( $cn->options['general']['app_id'] ) ? $cn->options['general']['app_id'] : '';
		$tier         = $cn->get_subscription();
		$exceeded     = (bool) $cn->threshold_exceeded();
		$app_blocking = ! empty( $cn->options['general']['app_blocking'] ); // already forced false when threshold exceeded

		// consent modes are ON only when configured as a non-empty array
		$google_cm    = ! empty( $blocking['google_consent_default'] )    && is_array( $blocking['google_consent_default'] );
		$facebook_cm  = ! empty( $blocking['facebook_consent_default'] )  && is_array( $blocking['facebook_consent_default'] );
		$microsoft_cm = ! empty( $blocking['microsoft_consent_default'] ) && is_array( $blocking['microsoft_consent_default'] );
		$gpc          = ! empty( $blocking['gpc_support'] );

		// usage
		$threshold = ! empty( $analytics['cycleUsage']->threshold ) ? (int) $analytics['cycleUsage']->threshold : 0;
		$visits    = ! empty( $analytics['cycleUsage']->visits ) ? (int) $analytics['cycleUsage']->visits : 0;

		if ( $threshold > 0 && $visits > $threshold )
			$visits = $threshold;

		$threshold_used = $threshold > 0 ? ( $visits / $threshold ) * 100 : 0;

		if ( $threshold_used > 100 )
			$threshold_used = 100;

		$days_to_go = ! empty( $analytics['cycleUsage']->daysToGo ) ? (int) $analytics['cycleUsage']->daysToGo : 0;

		// thirty days summary
		$td_visits = ! empty( $analytics['thirtyDaysUsage']->visits ) ? (int) $analytics['thirtyDaysUsage']->visits : 0;

		$consents = 0;

		if ( ! empty( $analytics['consentActivities'] ) && is_array( $analytics['consentActivities'] ) ) {
			foreach ( $analytics['consentActivities'] as $entry ) {
				$consents += (int) $entry->totalrecd;
			}
		}

		// CN_DEV_MODE overrides — admin-only, constant-gated. Make all 5 states demoable.
		if ( defined( 'CN_DEV_MODE' ) && CN_DEV_MODE && current_user_can( 'manage_options' ) ) {
			// ?cn_tier=free|pro should behave as a connected site even if status isn't active yet
			if ( isset( $_GET['cn_tier'] ) ) {
				$cn_tier = sanitize_key( $_GET['cn_tier'] );

				if ( $cn_tier === 'free' || $cn_tier === 'pro' )
					$connected = true;
			}

			// ?cn_usage=0-100
			if ( isset( $_GET['cn_usage'] ) ) {
				$ov = (int) $_GET['cn_usage'];

				if ( $ov >= 0 && $ov <= 100 ) {
					$threshold_used = $ov;

					if ( $threshold <= 0 )
						$threshold = 10000;

					$visits = (int) round( $threshold * ( $ov / 100 ) );

					if ( $ov >= 100 ) {
						$exceeded     = true;
						$app_blocking = false;
					}
				}
			}
		}

		return [
			'connected'      => $connected,
			'app_id'         => $app_id,
			'tier'           => $tier,
			'exceeded'       => $exceeded,
			'app_blocking'   => $app_blocking,
			'google_cm'      => $google_cm,
			'facebook_cm'    => $facebook_cm,
			'microsoft_cm'   => $microsoft_cm,
			'gpc'            => $gpc,
			'threshold'      => $threshold,
			'visits'         => $visits,
			'threshold_used' => $threshold_used,
			'days_to_go'     => $days_to_go,
			'td_visits'      => $td_visits,
			'consents'       => $consents
		];
	}

	/**
	 * Derive the single lifecycle state from the signals.
	 *
	 * @param array $s
	 * @return string banner_only|free_under|free_near|free_over|pro
	 */
	protected function derive_state( $s ) {
		if ( ! $s['connected'] || $s['app_id'] === '' )
			return 'banner_only';

		if ( $s['tier'] === 'pro' )
			return 'pro';

		if ( $s['exceeded'] || $s['threshold_used'] >= 100 )
			return 'free_over';

		if ( $s['threshold_used'] >= 70 )
			return 'free_near';

		return 'free_under';
	}

	/**
	 * Build the six status boxes for the given state.
	 *
	 * @param string $state
	 * @param array  $s
	 * @return array
	 */
	protected function build_boxes( $state, $s ) {
		$cn = Cookie_Notice();

		$welcome_url = $cn->is_network_admin()
			? network_admin_url( 'admin.php?page=cookie-notice&cn_react_welcome=1' )
			: admin_url( 'admin.php?page=cookie-notice&cn_react_welcome=1' );

		$pro_cta = [ 'label' => __( 'Turn on with Pro →', 'cookie-notice' ), 'url' => $welcome_url ];

		$boxes = [];

		// 1. Banner — the plugin shows a notice in every state
		$boxes[] = [
			'title'  => __( 'Banner', 'cookie-notice' ),
			'status' => 'ok',
			'value'  => __( 'Showing', 'cookie-notice' ),
			'pill'   => [ 'label' => __( 'Active', 'cookie-notice' ), 'cls' => 'ok' ]
		];

		// 2. Script blocking
		if ( $state === 'banner_only' || ! $s['app_blocking'] ) {
			$boxes[] = [
				'title'  => __( 'Script blocking', 'cookie-notice' ),
				'status' => 'crit',
				'value'  => __( 'Off', 'cookie-notice' ),
				'value_cls' => $state === 'free_over' ? 'crit' : 'off',
				'sub'    => esc_html__( 'Firing before consent', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Exposed', 'cookie-notice' ), 'cls' => 'crit' ]
			];
		} elseif ( $state === 'free_near' ) {
			$boxes[] = [
				'title'  => __( 'Script blocking', 'cookie-notice' ),
				'status' => 'warn',
				'value'  => __( 'On · at risk', 'cookie-notice' ),
				'sub'    => esc_html__( 'Off at 100%', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Ends soon', 'cookie-notice' ), 'cls' => 'warn' ]
			];
		} else {
			$boxes[] = [
				'title'  => __( 'Script blocking', 'cookie-notice' ),
				'status' => 'ok',
				'value'  => __( 'On', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Compliant', 'cookie-notice' ), 'cls' => 'ok' ]
			];
		}

		// 3. Google Consent Mode
		if ( $state === 'banner_only' ) {
			$boxes[] = [
				'title'  => __( 'Google Consent Mode', 'cookie-notice' ),
				'status' => 'off',
				'value'  => __( 'Off', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Inactive', 'cookie-notice' ), 'cls' => 'off' ]
			];
		} elseif ( $state === 'free_over' ) {
			$boxes[] = [
				'title'  => __( 'Google Consent Mode', 'cookie-notice' ),
				'status' => 'crit',
				'value'  => __( 'Off', 'cookie-notice' ),
				'sub'    => esc_html__( 'Signals stopped', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Stopped', 'cookie-notice' ), 'cls' => 'crit' ]
			];
		} elseif ( $state === 'free_near' ) {
			$boxes[] = [
				'title'  => __( 'Google Consent Mode', 'cookie-notice' ),
				'status' => 'warn',
				'value'  => __( 'v2 · at risk', 'cookie-notice' ),
				'sub'    => esc_html__( 'Stops at limit', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Ends soon', 'cookie-notice' ), 'cls' => 'warn' ]
			];
		} else {
			$boxes[] = [
				'title'  => __( 'Google Consent Mode', 'cookie-notice' ),
				'status' => 'ok',
				'value'  => __( 'v2 active', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Compliant', 'cookie-notice' ), 'cls' => 'ok' ]
			];
		}

		// 4. Meta & Microsoft — green only for Pro with a mode configured
		if ( $state === 'pro' && ( $s['facebook_cm'] || $s['microsoft_cm'] ) ) {
			$boxes[] = [
				'title'  => __( 'Meta & Microsoft', 'cookie-notice' ),
				'status' => 'ok',
				'value'  => __( 'On', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Compliant', 'cookie-notice' ), 'cls' => 'ok' ]
			];
		} elseif ( $state === 'banner_only' ) {
			$boxes[] = [
				'title'  => __( 'Meta & Microsoft', 'cookie-notice' ),
				'status' => 'off',
				'value'  => __( 'Off', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Inactive', 'cookie-notice' ), 'cls' => 'off' ]
			];
		} else {
			$boxes[] = [
				'title'  => __( 'Meta & Microsoft', 'cookie-notice' ),
				'status' => 'crit',
				'value'  => __( 'Off', 'cookie-notice' ),
				'value_cls' => 'off',
				'sub'    => esc_html__( 'Pixels uncovered', 'cookie-notice' ),
				'cta'    => $pro_cta
			];
		}

		// 5. GPC signal (Global Privacy Control)
		if ( $state === 'banner_only' ) {
			$boxes[] = [
				'title'  => __( 'GPC signal', 'cookie-notice' ),
				'status' => 'off',
				'value'  => __( 'Off', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Inactive', 'cookie-notice' ), 'cls' => 'off' ]
			];
		} elseif ( $state === 'free_over' ) {
			$boxes[] = [
				'title'  => __( 'GPC signal', 'cookie-notice' ),
				'status' => 'crit',
				'value'  => __( 'Off', 'cookie-notice' ),
				'sub'    => esc_html__( 'Not honored', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Off', 'cookie-notice' ), 'cls' => 'crit' ]
			];
		} elseif ( $state === 'pro' || $s['gpc'] ) {
			$boxes[] = [
				'title'  => __( 'GPC signal', 'cookie-notice' ),
				'status' => 'ok',
				'value'  => __( 'Honored', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Compliant', 'cookie-notice' ), 'cls' => 'ok' ]
			];
		} else {
			$boxes[] = [
				'title'  => __( 'GPC signal', 'cookie-notice' ),
				'status' => 'warn',
				'value'  => __( 'Off', 'cookie-notice' ),
				'sub'    => esc_html__( 'Not honored', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Recommended', 'cookie-notice' ), 'cls' => 'warn' ]
			];
		}

		// 6. Visit limit
		$pct = (int) round( $s['threshold_used'] );

		if ( $state === 'banner_only' ) {
			$boxes[] = [
				'title'  => __( 'Visits', 'cookie-notice' ),
				'status' => 'off',
				'value'  => '—',
				'pill'   => [ 'label' => __( 'Inactive', 'cookie-notice' ), 'cls' => 'off' ]
			];
		} elseif ( $state === 'pro' ) {
			$boxes[] = [
				'title'  => __( 'Visit limit', 'cookie-notice' ),
				'status' => 'ok',
				'value'  => __( 'Unlimited', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Compliant', 'cookie-notice' ), 'cls' => 'ok' ]
			];
		} else {
			$status   = $state === 'free_over' ? 'crit' : ( $state === 'free_near' ? 'warn' : 'ok' );
			$usage_str = sprintf(
				/* translators: 1: visits used, 2: visit threshold */
				esc_html__( '%1$s / %2$s visits', 'cookie-notice' ),
				number_format_i18n( $s['visits'] ),
				number_format_i18n( $s['threshold'] )
			);

			$boxes[] = [
				'title'  => __( 'Visit limit', 'cookie-notice' ),
				'status' => $status,
				'value'  => $pct . '%',
				'bar'    => [ 'pct' => $pct, 'cls' => $status ],
				'sub'    => $usage_str,
				'cta'    => [ 'label' => __( 'Go unlimited →', 'cookie-notice' ), 'url' => $welcome_url ]
			];
		}

		return $boxes;
	}

	/**
	 * Build the hero headline, gap chip and primary CTA for the state.
	 *
	 * @param string $state
	 * @param array  $s
	 * @param int    $gap_count
	 * @return array
	 */
	protected function build_hero( $state, $s, $gap_count ) {
		$cn = Cookie_Notice();

		$welcome_url = $cn->is_network_admin()
			? network_admin_url( 'admin.php?page=cookie-notice&cn_react_welcome=1' )
			: admin_url( 'admin.php?page=cookie-notice&cn_react_welcome=1' );

		// Presentation (visual severity) per state — copy itself lives in notifications.json.
		$pres = [
			'banner_only' => [ 'hero' => 'crit', 'gap' => 'crit' ],
			'free_under'  => [ 'hero' => 'ok',   'gap' => 'neutral' ],
			'free_near'   => [ 'hero' => 'warn', 'gap' => 'warn' ],
			'free_over'   => [ 'hero' => 'crit', 'gap' => 'crit', 'danger' => true ],
			'pro'         => [ 'hero' => 'ok',   'gap' => 'good', 'is_pro' => true ]
		];
		$p = isset( $pres[ $state ] ) ? $pres[ $state ] : $pres['free_under'];

		// Copy from notifications.json (wpDashboard slot), with token interpolation.
		$rule = cn_get_dashboard_notification( $state );

		$repl = [
			'{usagePercent}' => number_format_i18n( (int) round( $s['threshold_used'] ) ),
			'{sessionUsed}'  => number_format_i18n( $s['visits'] ),
			'{sessionTotal}' => number_format_i18n( $s['threshold'] )
		];

		if ( $rule ) {
			$grade     = strtr( (string) ( $rule['title'] ?? '' ), $repl );
			$gap_label = strtr( (string) ( $rule['gapLabel'] ?? '' ), $repl );
			$intro     = strtr( (string) ( $rule['description'] ?? '' ), $repl );
			$cta_label = strtr( (string) ( $rule['cta']['label'] ?? '' ), $repl );
			$cta_small = strtr( (string) ( $rule['ctaSmall'] ?? '' ), $repl );
		} else {
			// defensive fallback if notifications.json is missing/unreadable
			$grade     = esc_html__( 'Compliance status', 'cookie-notice' );
			$gap_label = '';
			$intro     = '';
			$cta_label = esc_html__( 'Upgrade to Pro →', 'cookie-notice' );
			$cta_small = '';
		}

		return [
			'hero_cls'   => $p['hero'],
			'grade'      => $grade,
			'gap_label'  => $gap_label,
			'gap_cls'    => $p['gap'],
			'intro'      => $intro,
			'cta_label'  => $cta_label,
			'cta_small'  => $cta_small,
			'cta_url'    => $welcome_url,
			'cta_danger' => ! empty( $p['danger'] ),
			'is_pro'     => ! empty( $p['is_pro'] )
		];
	}

	/**
	 * Render a single status box.
	 *
	 * @param array $b
	 * @return string
	 */
	protected function render_box( $b ) {
		$vcls = isset( $b['value_cls'] ) ? $b['value_cls'] : $b['status'];

		$card_cls = 'cn-card' . ( $b['status'] === 'crit' ? ' cn-card--crit' : '' );

		$html  = '<div class="' . esc_attr( $card_cls ) . '">';
		$html .= '<div class="cn-card__top"><span class="cn-card__label">' . esc_html( $b['title'] ) . '</span><span class="cn-card__dot dot--' . esc_attr( $b['status'] ) . '"></span></div>';
		$html .= '<div class="cn-card__main main--' . esc_attr( $vcls ) . '">' . esc_html( $b['value'] ) . '</div>';

		if ( ! empty( $b['bar'] ) ) {
			$html .= '<div class="cn-card__bar-wrap"><div class="cn-card__bar bar--' . esc_attr( $b['bar']['cls'] ) . '" data-pct="' . esc_attr( (int) $b['bar']['pct'] ) . '"></div></div>';
		}

		// $b['sub'] is pre-escaped copy that may contain a literal <b> wrapper
		if ( ! empty( $b['sub'] ) )
			$html .= '<div class="cn-card__sub">' . $b['sub'] . '</div>';

		if ( ! empty( $b['pill'] ) ) {
			$html .= '<span class="cn-card__pill pill--' . esc_attr( $b['pill']['cls'] ) . '">' . esc_html( $b['pill']['label'] ) . '</span>';
		}

		if ( ! empty( $b['cta'] ) ) {
			$html .= '<div class="cn-card__foot"><a href="' . esc_url( $b['cta']['url'] ) . '">' . esc_html( $b['cta']['label'] ) . '</a></div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Render the full protection scorecard.
	 *
	 * @return string
	 */
	protected function render_scorecard() {
		$s         = $this->get_signals();
		$state     = $this->derive_state( $s );
		$boxes     = $this->build_boxes( $state, $s );

		$gap_count = 0;
		foreach ( $boxes as $b ) {
			if ( in_array( $b['status'], [ 'warn', 'crit' ], true ) )
				$gap_count++;
		}

		$hero = $this->build_hero( $state, $s, $gap_count );

		$html  = '<div id="cn-scorecard" class="cn-sc cn-sc--' . esc_attr( $state ) . '">';

		// hero
		$html .= '<div class="cn-sc-hero hero--' . esc_attr( $hero['hero_cls'] ) . '">';
		$html .= '<div class="cn-sc-hero__top"><span class="cn-sc-hero__grade">' . esc_html( $hero['grade'] ) . '</span><span class="cn-sc-hero__gap gap--' . esc_attr( $hero['gap_cls'] ) . '">' . esc_html( $hero['gap_label'] ) . '</span></div>';
		$html .= '<p>' . esc_html( $hero['intro'] ) . '</p>';
		$html .= '</div>';

		// boxes
		$html .= '<div class="cn-sc-grid">';
		foreach ( $boxes as $b ) {
			$html .= $this->render_box( $b );
		}
		$html .= '</div>';

		// primary CTA (or reassurance footer link for pro)
		if ( empty( $hero['is_pro'] ) ) {
			$btn_cls = 'cn-sc-cta__btn' . ( ! empty( $hero['cta_danger'] ) ? ' is-danger' : '' );

			$html .= '<div class="cn-sc-cta">';
			$html .= '<a class="' . esc_attr( $btn_cls ) . '" href="' . esc_url( $hero['cta_url'] ) . '">' . esc_html( $hero['cta_label'] ) . '</a>';

			if ( ! empty( $hero['cta_small'] ) )
				$html .= '<small>' . esc_html( $hero['cta_small'] ) . '</small>';

			$html .= '</div>';
		} else {
			$html .= '<div class="cn-sc-foot"><a href="' . esc_url( $hero['cta_url'] ) . '">' . esc_html( $hero['cta_label'] ) . '</a></div>';
		}

		// analytics charts (connected states only)
		if ( $s['connected'] ) {
			$html .= '<details class="cn-sc-analytics">';
			$html .= '<summary class="cn-sc-analytics__summary">' . esc_html__( 'Consent & traffic analytics', 'cookie-notice' ) . '</summary>';
			$html .= '<div class="cn-sc-analytics__body">';
			$html .= '<div class="cn-legend">';
			$html .= '<span><i class="lvl1"></i>' . esc_html( sprintf( __( 'Level %s', 'cookie-notice' ), 1 ) ) . '</span>';
			$html .= '<span><i class="lvl2"></i>' . esc_html( sprintf( __( 'Level %s', 'cookie-notice' ), 2 ) ) . '</span>';
			$html .= '<span><i class="lvl3"></i>' . esc_html( sprintf( __( 'Level %s', 'cookie-notice' ), 3 ) ) . '</span>';
			$html .= '</div>';
			$html .= '<div class="cn-chart-wrap"><canvas id="cn-consent-activity-chart"></canvas></div>';
			$html .= '<div class="cn-chart-wrap"><canvas id="cn-privacy-consent-logs-activity-chart"></canvas></div>';
			$html .= '</div>';
			$html .= '</details>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Render dashboard widget.
	 *
	 * @return void
	 */
	public function dashboard_widget() {
		$html = $this->render_scorecard();

		// allow the scorecard markup: post tags + canvas/details/summary + data-pct
		$allowed_html = wp_kses_allowed_html( 'post' );
		$allowed_html['canvas']  = [ 'id' => true ];
		$allowed_html['details'] = [ 'class' => true, 'open' => true ];
		$allowed_html['summary'] = [ 'class' => true ];

		foreach ( [ 'div', 'span', 'a', 'i', 'small', 'b', 'p' ] as $tag ) {
			if ( ! isset( $allowed_html[$tag] ) || ! is_array( $allowed_html[$tag] ) )
				$allowed_html[$tag] = [];

			$allowed_html[$tag]['class']    = true;
			$allowed_html[$tag]['data-pct'] = true;
		}

		$allowed_html['a']['href'] = true;

		echo wp_kses( $html, $allowed_html );
	}

	/**
	 * Add site test.
	 *
	 * @param array $tests
	 * @return array
	 */
	public function add_tests( $tests ) {
		$tests['direct']['cookie_compliance_status'] = [
			'label'	=> esc_html__( 'Compliance by Hu-manity.co Status', 'cookie-notice' ),
			'test'	=> [ $this, 'test_cookie_compliance' ]
		];

		return $tests;
	}

	/**
	 * Test for Cookie Compliance.
	 *
	 * @return array|void
	 */
	public function test_cookie_compliance() {
		if ( Cookie_Notice()->get_status() !== 'active' ) {
			return [
				'label'			=> esc_html__( 'Your site does not have Compliance by Hu-manity.co', 'cookie-notice' ),
				'status'		=> 'recommended',
				'description'	=> esc_html__( "Run Compliance Check to determine your site's compliance with updated data processing and consent rules under GDPR, CCPA and other international data privacy laws.", 'cookie-notice' ),
				'actions'		=> sprintf( '<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>', admin_url( 'admin.php?page=cookie-notice&welcome=1' ), esc_html__( 'Run Compliance Check', 'cookie-notice' ) ),
				'test'			=> 'cookie_compliance_status',
				'badge'			=> [
					'label'	=> esc_html__( 'Compliance', 'cookie-notice' ),
					'color'	=> 'blue'
				]
			];
		} else {
			return [
				'label'			=> esc_html__( 'Compliance by Hu-manity.co is active', 'cookie-notice' ),
				'status'		=> 'good',
				'description'	=> esc_html__( 'Compliance by Hu-manity.co is configured with active Compliance by Hu-manity.co protection. Your site is collecting consent in accordance with GDPR, CCPA, and other applicable privacy laws.', 'cookie-notice' ),
				'actions'		=> sprintf( '<p><a href="%s">%s</a></p>', admin_url( 'admin.php?page=cookie-notice' ), esc_html__( 'View compliance dashboard', 'cookie-notice' ) ),
				'test'			=> 'cookie_compliance_status',
				'badge'			=> [
					'label'	=> esc_html__( 'Compliance', 'cookie-notice' ),
					'color'	=> 'green'
				]
			];
		}
	}

	/**
	 * Retrieve the timezone of the site as a string.
	 *
	 * @return string
	 */
	public function timezone_string() {
		if ( function_exists( 'wp_timezone_string' ) )
			return wp_timezone_string();

		$timezone_string = get_option( 'timezone_string' );

		if ( $timezone_string )
			return $timezone_string;

		$offset = (float) get_option( 'gmt_offset' );
		$hours = (int) $offset;
		$minutes = ( $offset - $hours );
		$sign = ( $offset < 0 ) ? '-' : '+';
		$abs_hour = abs( $hours );
		$abs_mins = abs( $minutes * 60 );
		$tz_offset = sprintf( '%s%02d:%02d', $sign, $abs_hour, $abs_mins );

		return $tz_offset;
	}
}
