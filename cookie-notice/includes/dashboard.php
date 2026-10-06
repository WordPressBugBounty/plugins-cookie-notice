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
 * @param string $state Scorecard state: banner_only|engine_off|posture_gap|free_under|free_near|free_over|pro.
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
		add_filter( 'debug_information', [ $this, 'add_debug_information' ] );
	}

	/**
	 * Add a Compliance section to Site Health → Info.
	 *
	 * Exists for one question support keeps having to answer from guesswork: "is the
	 * usage number this site is acting on actually current?" The visit counter is
	 * materialised once a day server-side and then pulled on WP pseudo-cron, which
	 * only fires when somebody loads the site — so on a quiet site the figure driving
	 * the quota can be well over a day old. Nothing previously exposed that: the
	 * plugin's own "last synced" records when WE pulled, never how old the number was
	 * when we got it. cycleUsage.fetch_time is the producer's clock and is what these
	 * rows report.
	 *
	 * Site Health rather than the dashboard widget on purpose — this is diagnostic
	 * copy for a support conversation, not customer-facing UI, and it needs no design.
	 *
	 * @param array $info Site Health debug information.
	 * @return array
	 */
	public function add_debug_information( $info ) {
		// get main instance
		$cn = Cookie_Notice();

		// is_network_options(), like every other reader of a get_app_config() row —
		// see the analytics row scope note in get_signals(). The hand-spelled
		// predicate here carried a surplus is_network_admin() conjunct, so a subsite
		// read a row the pull never writes and every counter came back 0.
		$analytics = Cookie_Notice_Store::get( 'cookie_notice_app_analytics', [], $cn->is_network_options() );

		$cycle_usage = ! empty( $analytics['cycleUsage'] ) ? $analytics['cycleUsage'] : null;
		$age_hours   = $cn->welcome_api->cycle_usage_age_hours( $cycle_usage );
		$is_fresh    = $cn->welcome_api->cycle_usage_is_fresh( $cycle_usage );
		$counters    = $cn->welcome_api->read_cycle_usage_counters( $analytics );

		if ( $age_hours === null )
			$age_label = esc_html__( 'unknown — payload carried no fetch_time', 'cookie-notice' );
		else
			$age_label = sprintf(
				/* translators: %s: number of hours */
				esc_html__( '%s hours old', 'cookie-notice' ),
				number_format_i18n( $age_hours, 1 )
			);

		$fields = [
			'cn_status' => [
				'label'	=> esc_html__( 'Compliance status', 'cookie-notice' ),
				'value'	=> $cn->get_status()
			],
			'cn_app_id' => [
				'label'		=> esc_html__( 'App ID', 'cookie-notice' ),
				'value'		=> ! empty( $cn->options['general']['app_id'] ) ? $cn->options['general']['app_id'] : esc_html__( 'not connected', 'cookie-notice' ),
				'private'	=> true
			],
			'cn_subscription' => [
				'label'	=> esc_html__( 'Plan', 'cookie-notice' ),
				'value'	=> $cn->get_subscription()
			],
			'cn_usage_visits' => [
				'label'	=> esc_html__( 'Cycle visits / threshold', 'cookie-notice' ),
				'value'	=> sprintf(
					'%s / %s',
					$counters['visits'],
					$counters['threshold'] > 0 ? $counters['threshold'] : esc_html__( 'unlimited', 'cookie-notice' )
				)
			],
			'cn_usage_age' => [
				'label'			=> esc_html__( 'Usage data age', 'cookie-notice' ),
				'value'			=> $age_label,
				'description'	=> esc_html__( 'Measured from the reported computation time of the figures, not from when this site last pulled them.', 'cookie-notice' )
			],
			'cn_usage_fresh' => [
				'label'			=> esc_html__( 'Usage data trusted for the limit', 'cookie-notice' ),
				'value'			=> $is_fresh ? esc_html__( 'yes', 'cookie-notice' ) : esc_html__( 'no — limit not enforced from this data', 'cookie-notice' ),
				'description'	=> esc_html__( 'Figures are only used to apply the plan limit while they can be shown to belong to the current cycle and to be recent. Otherwise the limit is left off.', 'cookie-notice' )
			],
			'cn_threshold_exceeded' => [
				'label'	=> esc_html__( 'Plan limit currently applied', 'cookie-notice' ),
				'value'	=> $cn->threshold_exceeded() ? esc_html__( 'yes', 'cookie-notice' ) : esc_html__( 'no', 'cookie-notice' )
			]
		];

		$info['cookie-notice'] = [
			'label'		=> esc_html__( 'Cookie Compliance', 'cookie-notice' ),
			'fields'	=> $fields
		];

		return $info;
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
		wp_add_dashboard_widget( $widget_key, __( 'Cookie Compliance', 'cookie-notice' ), [ $this, 'dashboard_widget' ] );

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
			// is_network_options(), like every other reader of a get_app_config() row —
			// see the analytics row scope note in get_signals(). The hand-spelled
			// predicate here carried a surplus is_network_admin() conjunct, so a subsite
			// read a row the pull never writes and every counter came back 0.
			$analytics = Cookie_Notice_Store::get( 'cookie_notice_app_analytics', [], $cn->is_network_options() );

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

		// ── Begin analytics row scope ────────────────────────────────────────
		// is_network_options(), like every other reader of a get_app_config() row.
		//
		// This used to spell the predicate out by hand AND add an is_network_admin()
		// conjunct that does not belong: `is_multisite() && is_network_admin() &&
		// is_plugin_network_active() && global_override`. The surplus term is the bug —
		// is_network_options() is the other three — and it made the answer depend on
		// WHICH SCREEN was being rendered rather than on how the plugin is configured.
		//
		// cookie_notice_app_analytics is written by get_app_config() and
		// get_app_analytics() under is_network_options(). So on a network-activated
		// multisite with global_override ON, this read from a SUBSITE dashboard
		// (wp_dashboard_setup fires there too) went to the site row, which the pull
		// never writes: threshold 0, visits 0, threshold_used 0. Box 6 then rendered
		// "0 / 0 visits" with a "Go unlimited →" CTA — the regression 8265ec5 exists to
		// keep out — and free_near became unreachable, so a subsite at 85% of its quota
		// got no warning at all.
		//
		// The blocking row four lines below always used is_network_options() correctly,
		// which is what made this readable as deliberate.
		$analytics = Cookie_Notice_Store::get( 'cookie_notice_app_analytics', [], $cn->is_network_options() );
		// ── End analytics row scope ──────────────────────────────────────────

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
		// Effective state, NOT the raw posture option: the AND of app_blocking and
		// app_blocking_engine (DEC-012), with the Free-plan quota cap already applied.
		// Feeds the "Script blocking" scorecard box and the gap count, so a site whose
		// engine is off must not be graded as protected.
		//
		// Named blocking_active, not app_blocking. It used to carry the option's name
		// while holding the AND of two options, so the key that looked like the posture
		// setting was the only one that wasn't it — which is exactly the confusion the
		// DEC-012 split exists to end. app_blocking means posture and nothing else now,
		// and it travels below under that meaning.
		$blocking_active = $cn->blocking_is_active();

		// ── Begin regime-aware blocking signals ──────────────────────────────
		// The AND above answers "is blocking happening". It cannot answer "is that a
		// PROBLEM", and since DEC-012 those are different questions:
		//
		//   engine off   nothing is held for anybody, whatever the geo rule or privacy
		//                signal (the widget treats blockingEngine as absolute). A gap
		//                under every regime.
		//   posture off  default-allow before the visitor chooses. A gap only where the
		//                site's own selected laws require prior consent — under
		//                CCPA/OTHERUS/PIPEDA this is the INTENDED model, and reporting
		//                it as exposure tells a lawful site it is broken.
		//
		// So the two are carried separately alongside the regime. Do not collapse them
		// back into the AND for anything that renders a verdict.
		//
		// NOTE these are the IN-MEMORY options, which is not the same as the admin's
		// stored preference: set_status() rewrites options['general']['app_blocking']
		// to false for the rest of the request whenever the Free quota is exceeded (the
		// app_blocking quota force in cookie-notice.php). So on a Free site at its
		// limit, $blocking_posture reads false even though the admin never touched it.
		//
		// Every consumer below therefore tests $s['exceeded'] BEFORE reaching a posture
		// branch, so the quota case is claimed by the quota message and never blamed on
		// a setting. If you need what the admin actually chose, the unforced value is
		// $cn->app_blocking_stored — not this.
		//
		// The engine is not quota-capped (DEC-011: it is the master switch, and the
		// quota pauses protection rather than revoking the capability), so
		// $blocking_engine is the stored value either way.
		$blocking_engine  = ! empty( $cn->options['general']['app_blocking_engine'] );
		$blocking_posture = ! empty( $cn->options['general']['app_blocking'] );

		// 'optin' | 'optout' | '' — and '' means NO BASIS TO JUDGE, not "no law
		// applies". Everything downstream must render it as silence.
		$regime = $cn->get_consent_regime();
		// ── End regime-aware blocking signals ────────────────────────────────

		// consent modes are ON only when configured as a non-empty array
		$google_cm    = ! empty( $blocking['google_consent_default'] )    && is_array( $blocking['google_consent_default'] );
		$facebook_cm  = ! empty( $blocking['facebook_consent_default'] )  && is_array( $blocking['facebook_consent_default'] );
		$microsoft_cm = ! empty( $blocking['microsoft_consent_default'] ) && is_array( $blocking['microsoft_consent_default'] );
		$gpc          = ! empty( $blocking['gpc_support'] );

		// usage — object vs array vs root-only threshold: see read_cycle_usage_counters()
		$counters  = $cn->welcome_api->read_cycle_usage_counters( $analytics );
		$threshold = $counters['threshold'];
		$visits    = $counters['visits'];

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
							// Both, so the demo matches what the quota force really does:
						// it zeroes the POSTURE option in memory and leaves the engine
						// alone. Setting only the derived value would demo a shape that
						// cannot occur. (This read $app_blocking until the signal was
						// renamed to $blocking_active, at which point it silently
						// assigned to a variable nothing consumed and ?cn_usage=100
						// stopped demoing blocking-off at all.)
						$blocking_active  = false;
						$blocking_posture = false;
					}
				}
			}
		}

		return [
			'connected'      => $connected,
			'app_id'         => $app_id,
			'tier'           => $tier,
			'exceeded'       => $exceeded,
			// blocking_active = is blocking happening (posture AND engine, quota applied)
			// blocking_posture = the app_blocking option, which since DEC-012 means
			//                    posture and only posture
			// blocking_engine  = the app_blocking_engine option, the master switch
			'blocking_active'   => $blocking_active,
			'blocking_posture'  => $blocking_posture,
			'blocking_engine'   => $blocking_engine,
			'regime'            => $regime,
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
	 * @return string banner_only|engine_off|posture_gap|free_under|free_near|free_over|pro
	 */
	protected function derive_state( $s ) {
		// ── Begin dashboard lifecycle state (DEC-012) ────────────────────────
		if ( ! $s['connected'] || $s['app_id'] === '' )
			return 'banner_only';

		// ── Begin blocking-gap states ────────────────────────────────────────
		// These exist because the card used to headline "Fully protected — Everything's
		// on. Nothing to do." directly above a Script blocking box reading "Off ·
		// Exposed". It contradicted itself on one screen.
		//
		// TWO states, not one, because the causes are not equally bad and one message
		// cannot be true for both:
		//
		//   engine off   nothing is held for anybody, whatever the geo rule or privacy
		//                signal. A gap under every regime and on every plan.
		//   posture off  default-allow before the visitor chooses. A gap ONLY where the
		//                site's own selected laws require prior consent.
		//
		// The regime test is `=== 'optin'`, never `!== 'optout'`. get_consent_regime()
		// returns '' for a site that has picked no laws, and '' means we have no basis
		// to judge — a negated test would invent a verdict about a site that has simply
		// not finished setting up.
		//
		// ENGINE OFF IS CHECKED FIRST, ABOVE TIER. An earlier revision gated both of
		// these on tier === 'pro', on the reasoning that Free sites "fall through to the
		// quota states, which already headline the loss of protection". That is true of
		// free_over and false of the other two: a Free site with the engine off and low
		// usage returned free_under, whose headline reads "Blocking is on, with gaps to
		// close" — directly above a box reading "Off · Nothing is held". Same
		// self-contradiction, one plan over.
		//
		// It also outranks free_over deliberately. When the admin has switched the
		// engine off, "Protection paused — limit reached · Upgrade to restore
		// protection" is worse than merely redundant: upgrading would not restore
		// anything, because the quota is not why blocking stopped.
		if ( ! $s['blocking_engine'] )
			return 'engine_off';

		// Pro is decided on tier and must not reach the quota states below — a Pro plan
		// has no threshold, so any exceeded/usage shape reaching here is spurious.
		if ( $s['tier'] === 'pro' ) {
			if ( ! $s['blocking_posture'] && $s['regime'] === 'optin' )
				return 'posture_gap';

			return 'pro';
		}
		// ── End blocking-gap states ──────────────────────────────────────────

		// The PLUGIN'S VERDICT, never the counters. threshold_exceeded() refuses to arm
		// without a real threshold — a Pro plan has none — and fails OPEN when it cannot
		// prove the usage snapshot current, which is common on a low-traffic install where
		// pseudo-cron is irregular. Re-deriving visits >= threshold here drops both rules,
		// and this card then headlines "Protection paused — limit reached" over a Script
		// blocking box still reading "On · Compliant", about a site that is blocking
		// normally. The React top bar was corrected for exactly this; the same rule file
		// feeds both, so the two must agree.
		if ( $s['exceeded'] )
			return 'free_over';

		// EXCLUSIVE upper bound, matching threshold-warning's [70, 100) in
		// notifications.json — the file's own rangeConvention. At exactly 100% with the
		// verdict open the site still has its protection, so "protection switches off at
		// 100%" would be wrong in the other direction. That state is neutral here, as it
		// is in the top bar, where it falls through to the plain upsell.
		if ( $s['threshold_used'] >= 70 && $s['threshold_used'] < 100 )
			return 'free_near';

		// Free sites get the posture gap too — the law does not care which plan you are
		// on. Placed AFTER the quota states on purpose, and that ordering is the whole
		// safety of it: the Free quota force rewrites in-memory app_blocking to false
		// (see the app_blocking quota force in cookie-notice.php), so a site that is
		// merely over its limit reads as posture-off here. Checking this first would
		// blame the admin for a setting they never touched.
		if ( ! $s['blocking_posture'] && $s['regime'] === 'optin' )
			return 'posture_gap';

		return 'free_under';
		// ── End dashboard lifecycle state (DEC-012) ──────────────────────────
	}

	/**
	 * Build the six status boxes for the given state.
	 *
	 * @param string $state
	 * @param array  $s
	 * @return array
	 */
	protected function build_boxes( $state, $s ) {
		$welcome_url = cn_get_welcome_url();

		$pro_cta = [ 'label' => __( 'Turn on with Pro →', 'cookie-notice' ), 'url' => $welcome_url ];

		$boxes = [];

		// 1. Banner — the plugin shows a notice in every state
		$boxes[] = [
			'title'  => __( 'Banner', 'cookie-notice' ),
			'status' => 'ok',
			'value'  => __( 'Showing', 'cookie-notice' ),
			'pill'   => [ 'label' => __( 'Active', 'cookie-notice' ), 'cls' => 'ok' ]
		];

		// ── Begin script blocking box ────────────────────────────────────────
		// 2. Script blocking
		//
		// The pill states what WE observe, never what it means for the customer legally.
		// It used to read "Compliant" / "Exposed", which certified a legal position off
		// a checkbox — on a row that, elsewhere on this card, also appears next to
		// "Visit limit · Unlimited". We cannot know their theme, their other plugins,
		// their jurisdiction or whether one of their own scripts breaks ours, so the
		// verdict was never ours to issue. The headline carries the warning; this box
		// carries the fact.
		//
		// Three distinct off-shapes, because one message cannot be true for all of them:
		// not connected at all, engine off (nothing held for anybody), and posture
		// default-allow with the engine still enforcing after a decline or a GPC signal.
		// The old single branch called all three "Off · Firing before consent", which is
		// wrong for the third — and on an opt-out site that third shape is the LAWFUL
		// model, so the card was telling a correctly-configured customer they were
		// exposed.
		if ( $state === 'banner_only' || ! $s['blocking_active'] ) {
			if ( $state === 'banner_only' ) {
				$value  = __( 'Off', 'cookie-notice' );
				$sub    = esc_html__( 'Not connected', 'cookie-notice' );
				$pill   = __( 'Not blocking', 'cookie-notice' );
				$status = 'crit';
			} elseif ( ! $s['blocking_engine'] ) {
				$value  = __( 'Off', 'cookie-notice' );
				$sub    = esc_html__( 'Nothing is held', 'cookie-notice' );
				$pill   = __( 'Not blocking', 'cookie-notice' );
				$status = 'crit';
			} elseif ( $s['tier'] !== 'pro' && $s['exceeded'] ) {
				// QUOTA, NOT A CHOICE — and this branch MUST stay above the posture one.
				// The Free quota force rewrites in-memory app_blocking to false (see the
				// app_blocking quota force in cookie-notice.php), so a site that has
				// merely hit its limit is indistinguishable from one whose admin set a
				// default-allow posture. An earlier revision had no such branch, and a
				// Free site over its limit therefore fell into the posture case and
				// rendered a GREEN box — "Default allow", green dot, green pill — under
				// a red "Protection paused — limit reached" hero.
				$value  = __( 'Off', 'cookie-notice' );
				$sub    = esc_html__( 'Limit reached', 'cookie-notice' );
				$pill   = __( 'Paused', 'cookie-notice' );
				$status = 'crit';
			} else {
				// Posture, by the admin's own choice. Severity follows the site's OWN
				// selected laws: a gap under an opt-in regime, the intended behaviour
				// under an opt-out one. An unset regime ('') is not graded — we have no
				// basis to judge, and inventing one is the same overclaim inverted.
				//
				// The pill differs here too. "Not blocking" is false in this shape: the
				// engine is on and scripts ARE held once someone declines or sends a GPC
				// signal, which is exactly what the sub-label says one line down.
				$value  = __( 'Default allow', 'cookie-notice' );
				$sub    = esc_html__( 'Held after a decline', 'cookie-notice' );
				$pill   = __( 'Default allow', 'cookie-notice' );
				$status = $s['regime'] === 'optin' ? 'warn' : 'ok';
			}

			$boxes[] = [
				'title'  => __( 'Script blocking', 'cookie-notice' ),
				'status' => $status,
				'value'  => $value,
				'value_cls' => $status === 'crit' ? 'crit' : 'off',
				'sub'    => $sub,
				'pill'   => [ 'label' => $pill, 'cls' => $status ]
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
				'pill'   => [ 'label' => __( 'On', 'cookie-notice' ), 'cls' => 'ok' ]
			];
		}
		// ── End script blocking box ──────────────────────────────────────────

		// ── Begin google consent mode box ────────────────────────────────────
		// 3. Google Consent Mode — reports whether a consent map is CONFIGURED, and
		// nothing else. Two things were wrong here and they compounded.
		//
		// It never read $s['google_cm'], the signal computed for it, so it reported
		// "v2 active" to every connected site including one that has configured no map
		// at all. frontend.php only emits the signals when google_consent_default is a
		// non-empty array, which is exactly what google_cm says, so the box was
		// certifying a feature that was not running.
		//
		// And it keyed severity off $state. derive_state() returns engine_off BEFORE
		// free_over, so a Free site both over quota and with the blocking engine off
		// never reported free_over and fell through to the green branch: turning the
		// master switch OFF moved this box from crit to ok. "Greener as it gets worse",
		// the same inversion the visit-limit box carries a comment about, one box over.
		//
		// The quota branches are gone rather than re-keyed to $s['exceeded'], because
		// their claim was false in the other direction too. Google Consent Mode is a
		// Free-tier feature and is NOT quota-gated — welcome-api.php says so at the
		// write, citing Designer API logic.service.ts::downgradeLiveDefaults: only
		// Facebook and Microsoft are Pro-only, and signals cost nothing to serve, so a
		// site over its visit quota keeps telling Google what the visitor chose. So
		// "Signals stopped" and "Stops at limit" described something that does not
		// happen.
		//
		// Nor does this box follow the blocking engine, unlike the GPC box below. The
		// engine decides whether scripts are HELD; Consent Mode reports what the visitor
		// chose to whatever does run. Those are different questions and the seeding in
		// frontend.php gates on neither the engine nor the quota.
		if ( $state === 'banner_only' ) {
			$boxes[] = [
				'title'  => __( 'Google Consent Mode', 'cookie-notice' ),
				'status' => 'off',
				'value'  => __( 'Off', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Inactive', 'cookie-notice' ), 'cls' => 'off' ]
			];
		} elseif ( ! $s['google_cm'] ) {
			// Not configured is not a failure — plenty of sites run no Google tags at
			// all — so this is neutral, not a warning.
			$boxes[] = [
				'title'  => __( 'Google Consent Mode', 'cookie-notice' ),
				'status' => 'off',
				'value'  => __( 'Off', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Not configured', 'cookie-notice' ), 'cls' => 'off' ]
			];
		} else {
			$boxes[] = [
				'title'  => __( 'Google Consent Mode', 'cookie-notice' ),
				'status' => 'ok',
				'value'  => __( 'v2 active', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'On', 'cookie-notice' ), 'cls' => 'ok' ]
			];
		}
		// ── End google consent mode box ──────────────────────────────────────

		// ── Begin plan-scoped boxes ──────────────────────────────────────────
		// Boxes 4 and 6 ask a PLAN question — "is this customer paying?" — so they read
		// the tier, never the state. $state was a faithful plan proxy only while 'pro'
		// was the single Pro state; DEC-012 added engine_off and posture_gap, both of
		// which a Pro customer reaches, and then `$state === 'pro'` started answering
		// "no" about people who pay. build_hero() was given is_pro for exactly this and
		// these were missed, so a Pro site with the blocking engine off rendered an
		// upgrade pitch here and a "0 / 0 visits" bar in box 6.
		//
		// Do NOT repair this by adding the two new names to the comparison. That is the
		// patch this class already survived once — 8265ec5 fixed "never show 0 as the
		// visit cap" in the Site Health row and the React bar, and four days later new
		// states re-opened it in the scorecard — and it breaks again on state number
		// eight. The tier is the question; ask the tier.

		// 4. Meta & Microsoft — green only for a paying plan with a mode configured.
		// Consent-mode signalling reports what the visitor chose; it does not depend on
		// the blocking engine, so this stays green with the engine off.
		if ( $s['tier'] === 'pro' && ( $s['facebook_cm'] || $s['microsoft_cm'] ) ) {
			$boxes[] = [
				'title'  => __( 'Meta & Microsoft', 'cookie-notice' ),
				'status' => 'ok',
				'value'  => __( 'On', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'On', 'cookie-notice' ), 'cls' => 'ok' ]
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
		} elseif ( ! $s['blocking_engine'] || ( $s['tier'] !== 'pro' && $s['exceeded'] ) ) {
			// ── Begin GPC box engine gate ────────────────────────────────────
			// This box is NOT plan-scoped, and that is the difference between it and
			// boxes 4 and 6. Honouring Global Privacy Control means HOLDING scripts for
			// a visitor who sends the signal, and app_blocking_engine off means nothing
			// is held for anybody, in any region, whatever the visitor sends. So the
			// answer here follows the engine and not the tier: a Pro site with the
			// engine off is not honouring GPC, and printing "Honored · On" over it
			// asserts we are doing something legally required that we are not doing.
			//
			// BOTH conditions in one branch, and neither may be `$state`. An earlier
			// revision had the quota case above as `$state === 'free_over'` and the
			// engine case below it. derive_state() returns the FIRST state that applies
			// and engine_off outranks free_over, so a Free site that was over quota AND
			// had the engine off never reported free_over and was answered by the engine
			// branch instead. Be precise about what that was: the severity it produced
			// was the SAME warn this branch produces, so no site ever rendered wrongly
			// because of it — the fix is that the box now says what it means. Asking
			// $state is asking a lossy summary to re-answer a question it was derived
			// from, and it only stays correct while derive_state()'s ordering does. Ask
			// the signals: $s['exceeded'] is the quota verdict, $s['blocking_engine'] the
			// master switch.
			//
			// The quota half is tier-guarded, exactly as the script-blocking box above
			// is. threshold_exceeded() is a raw read of the stored platform row and is
			// not plan-gated anywhere in the plugin, so a Pro site carrying a stale or
			// spurious flag — left over from a Free period, or a platform quirk — would
			// otherwise be told "Not honored · Off" about a plan that has no threshold
			// to exceed. derive_state() is careful about this for the same reason (see
			// its "Pro is decided on tier" note); reading the signal directly means
			// carrying that care here rather than inheriting it.
			//
			// Severity follows WHY nothing is held, because the two have different
			// remedies and the hero already carries the headline. Quota is crit: it is
			// the harsher message and the one with an upgrade path. Engine-off is warn,
			// since the hero is already crit in that state (engine_off in $pres) and a
			// second red box repeats it without adding anything.
			// ── End GPC box engine gate ──────────────────────────────────────
			$engine_off = ! $s['blocking_engine'];

			$boxes[] = [
				'title'  => __( 'GPC signal', 'cookie-notice' ),
				'status' => $engine_off ? 'warn' : 'crit',
				'value'  => __( 'Off', 'cookie-notice' ),
				'sub'    => $engine_off
					? esc_html__( 'Nothing is held', 'cookie-notice' )
					: esc_html__( 'Not honored', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'Off', 'cookie-notice' ), 'cls' => $engine_off ? 'warn' : 'crit' ]
			];
		} elseif ( $s['tier'] === 'pro' || $s['gpc'] ) {
			$boxes[] = [
				'title'  => __( 'GPC signal', 'cookie-notice' ),
				'status' => 'ok',
				'value'  => __( 'Honored', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'On', 'cookie-notice' ), 'cls' => 'ok' ]
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
		} elseif ( $s['tier'] === 'pro' ) {
			// Plan-scoped, per the plan-scoped boxes note above box 4. A Pro plan carries
			// no threshold, so $s['threshold'] is 0 here — reaching the else branch would
			// render "0 / 0 visits" under a "Go unlimited →" CTA to someone who already is.
			$boxes[] = [
				'title'  => __( 'Visit limit', 'cookie-notice' ),
				'status' => 'ok',
				'value'  => __( 'Unlimited', 'cookie-notice' ),
				'pill'   => [ 'label' => __( 'On', 'cookie-notice' ), 'cls' => 'ok' ]
			];
		} else {
			// ── Begin visit-limit box severity ───────────────────────────────
			// Crit follows the VERDICT; warn and ok follow the counters. Both halves are
			// load-bearing and neither can do the other's job.
			//
			// Counters alone would invert at the boundary: a site whose snapshot reads
			// 100% while the verdict is still open would go GREEN at 100% having been
			// amber at 99.99%. Greener as it gets worse.
			//
			// The verdict half must read $s['exceeded'] and NOT `$state === 'free_over'`.
			// derive_state() returns the FIRST state that applies and engine_off outranks
			// free_over deliberately, so a Free site that is both over its limit and has
			// the engine off never reports free_over — and this line rendered amber at
			// 100%. The state answers "what is the single worst thing about this site";
			// this box asks "is the quota verdict armed", which is a different question
			// with its own signal.
			$status = $s['exceeded'] ? 'crit' : ( $s['threshold_used'] >= 70 ? 'warn' : 'ok' );
			// ── End visit-limit box severity ─────────────────────────────────
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
		$welcome_url = cn_get_welcome_url();

		// Presentation (visual severity) per state — copy itself lives in notifications.json.
		$pres = [
			'banner_only' => [ 'hero' => 'crit', 'gap' => 'crit' ],
			'free_under'  => [ 'hero' => 'ok',   'gap' => 'neutral' ],
			'free_near'   => [ 'hero' => 'warn', 'gap' => 'warn' ],
			'free_over'   => [ 'hero' => 'crit', 'gap' => 'crit', 'danger' => true ],
			'pro'         => [ 'hero' => 'ok',   'gap' => 'good', 'is_pro' => true ],
			// Reachable on ANY plan, so no is_pro here — it is resolved from the tier
			// below instead. 'crit' for engine_off: nothing is held for anybody, which
			// is the same loss of protection as free_over. 'warn' for posture_gap: the
			// engine is still enforcing after a decline or a GPC signal, so it is a
			// narrower gap, and it is a setting the admin chose rather than a limit
			// imposed on them.
			'engine_off'  => [ 'hero' => 'crit', 'gap' => 'crit' ],
			'posture_gap' => [ 'hero' => 'warn', 'gap' => 'warn' ]
		];
		$p = isset( $pres[ $state ] ) ? $pres[ $state ] : $pres['free_under'];

		// is_pro gates the upsell block, so for the two states that are reachable on
		// EITHER plan it must follow the plan rather than the state — otherwise a Pro
		// customer whose engine is off is shown an upgrade pitch.
		//
		// SCOPED TO THOSE TWO STATES, not applied globally. banner_only fires on
		// `! connected || app_id === ''`, while tier comes from the PERSISTED
		// subscription, which the failed-pull guard deliberately keeps. So a Pro
		// customer whose platform answered status='' is banner_only AND tier=pro, and a
		// blanket rule would demote that card's prominent "Connect free to activate
		// protection" button to a small footer link — on the one card whose headline is
		// "Your banner shows — but nothing is blocked".
		if ( in_array( $state, [ 'engine_off', 'posture_gap' ], true ) )
			$p['is_pro'] = $s['tier'] === 'pro';

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
			'label'	=> esc_html__( 'Cookie Compliance Status', 'cookie-notice' ),
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
				'label'			=> esc_html__( 'Your site does not have Cookie Compliance', 'cookie-notice' ),
				'status'		=> 'recommended',
				'description'	=> esc_html__( "Run Compliance Check to determine your site's compliance with updated data processing and consent rules under GDPR, CCPA and other international data privacy laws.", 'cookie-notice' ),
				'actions'		=> sprintf( '<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>', esc_url( cn_get_welcome_url() ), esc_html__( 'Run Compliance Check', 'cookie-notice' ) ),
				'test'			=> 'cookie_compliance_status',
				'badge'			=> [
					'label'	=> esc_html__( 'Compliance', 'cookie-notice' ),
					'color'	=> 'blue'
				]
			];
		} else {
			return [
				'label'			=> esc_html__( 'Cookie Compliance is active', 'cookie-notice' ),
				'status'		=> 'good',
				'description'	=> esc_html__( 'Cookie Compliance is configured with active Cookie Compliance protection. Your site is collecting consent in accordance with GDPR, CCPA, and other applicable privacy laws.', 'cookie-notice' ),
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
