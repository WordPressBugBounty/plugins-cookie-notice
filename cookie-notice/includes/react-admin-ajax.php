<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie_Notice_React_Admin_Ajax class.
 *
 * Provides the PHP AJAX backend for the React admin UI.
 * Registers the wp_ajax_ actions consumed by the React admin bundle.
 *
 * @class   Cookie_Notice_React_Admin_Ajax
 * @package Cookie_Notice
 */
class Cookie_Notice_React_Admin_Ajax {

	/**
	 * Sentinel prefix for WAF-safe base64-encoded save fields.
	 *
	 * The React admin base64-encodes code/markup-bearing fields (refuse_code,
	 * refuse_code_head, conditional_rules) and prefixes this marker so a WAF
	 * (WordFence &c.) can't 403 the POST for carrying a raw <script>.
	 *
	 * The marker is PRINTABLE ASCII containing none of the characters
	 * wp_magic_quotes() (addslashes) escapes on $_POST — no NUL, no single/
	 * double quote, no backslash. That is load-bearing: WordPress runs
	 * wp_magic_quotes() on every request BEFORE admin-ajax dispatch, so by the
	 * time save_options() reads $_POST the value is already addslashes()'d. A
	 * slash-safe marker survives that untouched, so the raw-value strncmp
	 * detection below still matches. (An earlier "\x00CNB64\x00" marker was
	 * silently broken: addslashes turns NUL into backslash-zero, so the leading
	 * bytes no longer matched and every encoded save fell to the passthrough
	 * branch — storing the literal marker+base64 text instead of the script.)
	 *
	 * It also can't begin a real pasted value: a legacy payload is a <script…>,
	 * an <!-- comment -->, or a JSON array "[…]" — none start with "--", so a
	 * non-sentinel value is unambiguously a legacy (non-encoded) payload.
	 *
	 * MUST match the JS side byte-for-byte:
	 * WAF_B64_SENTINEL in src/admin-react/api/index.js ("--CNWAF-B64--").
	 */
	const WAF_B64_SENTINEL = "--CNWAF-B64--";

	/**
	 * React's onboarding flags: written to the network row in the Network Admin and the site
	 * row on a site (dismiss_welcome(), complete_setup_wizard()), and read back the same way
	 * (settings.php cnReactData.welcomeDismissedAt / setupWizardComplete).
	 */
	const SCOPED_ONBOARDING_FLAGS = [ 'cookie_notice_welcome_dismissed', 'cookie_notice_setup_wizard_complete' ];

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// Read-only hooks — always available regardless of ui_mode.
		add_action( 'wp_ajax_cn_react_dashboard',           [ $this, 'get_dashboard' ] );
		add_action( 'wp_ajax_cn_react_config',              [ $this, 'get_config' ] );
		add_action( 'wp_ajax_cn_react_consent_logs',        [ $this, 'get_consent_logs' ] );
		add_action( 'wp_ajax_cn_react_export_consent_logs', [ $this, 'export_consent_logs' ] );
		add_action( 'wp_ajax_cn_get_api_environment',         [ $this, 'get_api_environment' ] );
		add_action( 'wp_ajax_cn_react_privacy_consent',      [ $this, 'get_privacy_consent' ] );
		add_action( 'wp_ajax_cn_react_privacy_consent_logs', [ $this, 'get_privacy_consent_logs' ] );
		add_action( 'wp_ajax_cn_react_plugin_options',       [ $this, 'get_plugin_options' ] );

		// Write hooks — only register when ui_mode is "react" (#2267).
		// In legacy mode the PHP form path handles writes; registering these
		// would allow stale React JS (cached by a CDN or browser) to race
		// against the legacy form submit. The mode of the row the page renders from, which
		// under Global Settings Override is not $cn->options on admin-ajax.
		if ( Cookie_Notice()->settings->rendered_ui_mode() === 'react' ) {
			add_action( 'wp_ajax_cn_react_save_options',        [ $this, 'save_options' ] );
			add_action( 'wp_ajax_cn_react_reset_options',       [ $this, 'reset_options' ] );
			add_action( 'wp_ajax_cn_react_save_network_options', [ $this, 'save_network_options' ] );
			add_action( 'wp_ajax_cn_react_rule_values',         [ $this, 'get_rule_values' ] );
			add_action( 'wp_ajax_cn_react_save_privacy_consent', [ $this, 'save_privacy_consent' ] );
			add_action( 'wp_ajax_cn_react_privacy_consent_form_status', [ $this, 'set_privacy_consent_form_status' ] );
		}

		// Mode-agnostic state hooks — welcome dismissal and setup wizard
		// completion must work regardless of ui_mode.
		add_action( 'wp_ajax_cn_react_dismiss_welcome',        [ $this, 'dismiss_welcome' ] );
		add_action( 'wp_ajax_cn_react_complete_setup_wizard', [ $this, 'complete_setup_wizard' ] );

		// Dev harness only — CN_DEV_MODE is NOT an environment switch (it does not control
		// which API environment the plugin targets). Use CN_APP_HOST_URL, CN_APP_WIDGET_URL,
		// CN_ACCOUNT_API_URL etc. for that. CN_DEV_MODE enables developer-only UI tooling
		// (usage override, dev_reset) and should never be set on production or staging servers.
		if ( defined( 'CN_DEV_MODE' ) && CN_DEV_MODE ) {
			add_action( 'wp_ajax_cn_react_dev_reset',        [ $this, 'dev_reset' ] );
			add_action( 'wp_ajax_cn_react_test_set_option',  [ $this, 'test_set_option' ] );
			add_action( 'wp_ajax_cn_react_test_get_option',  [ $this, 'test_get_option' ] );
		}

	}

	/**
	 * Verify request nonce and capability.
	 *
	 * Sends a JSON error and exits when the check fails, so handlers can call
	 * this at the top without needing to check the return value.
	 *
	 * @return void
	 */
	private function verify_request() {
		check_ajax_referer( 'cn_react_nonce', 'nonce' );

		if ( ! current_user_can( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) ) ) {
			wp_send_json_error( [ 'error' => 'Insufficient permissions.' ] );
		}
	}

	/**
	 * Refuse a consent-log request outside the scope legacy shows consent logs in.
	 *
	 * Server-side, on every log endpoint: on a network-activated multisite the records
	 * belong to the app the scope serves (Cookie_Notice_Settings::consent_logs_in_scope()),
	 * and hiding the screen in React alone would leave the endpoint open.
	 *
	 * @return void
	 */
	private function verify_consent_logs_scope() {
		$settings = Cookie_Notice()->settings;

		if ( ! $settings->consent_logs_in_scope() ) {
			wp_send_json_error( [ 'error' => $settings->consent_logs_scope_message(), 'code' => 'cn_consent_logs_scope' ] );
		}
	}

	/**
	 * Return dashboard data for the Protection tab.
	 *
	 * @return void
	 */
	public function get_dashboard() {
		$this->verify_request();

		$cn = Cookie_Notice();

		// A site under Global Settings Override serves the NETWORK's app, and every row read
		// below would be the network's: its visits, consent counts and account email. Legacy
		// shows a site no dashboard widget then (dashboard.php wp_dashboard_setup()), so the
		// site gets no consent data or account, and nothing is pulled. The configuration it
		// runs under (laws, languages, banner design) is shown, as legacy shows the network's
		// settings greyed out.
		if ( $cn->settings->network_managed() ) {
			$this->send_empty_dashboard();
			return;
		}

		// --- Read cached analytics option ---
		// Single source: cookie_notice_app_analytics (refreshed hourly via welcome-api.php cron).
		// ⚠️ Multisite pattern: use site_option ONLY when network-active with global_override.
		// Do NOT simplify to is_multisite() alone — pattern matches welcome-api.php get_app_config().
		$network       = $cn->is_network_options();

		// The Network Admin with Global Settings Override off: the rows below are the MAIN
		// SITE's (its visits, consents and account email), and get_app_analytics() would write
		// the network app's analytics and status over them. No site's data, nothing pulled —
		// the React admin shows its per-site line here. In-memory predicate, as in
		// welcome-api.php get_app_config() / get_app_analytics().
		if ( $cn->is_network_admin() && ! $network ) {
			$this->send_empty_dashboard();
			return;
		}

		$analytics_raw = Cookie_Notice_Store::get( 'cookie_notice_app_analytics', [], $network );

		// --- Cycle usage (visits vs threshold) ---
		// Read from cached analytics option; CN_DEV_MODE overrides for UI testing.
		// Object vs array vs root-only threshold: see read_cycle_usage_counters().
		$counters  = $cn->welcome_api->read_cycle_usage_counters( $analytics_raw );
		$visits    = $counters['visits'];
		$threshold = $counters['threshold'];

		// Free plan with no readable cap: empty cache, a Pro leftover after
		// paid→free (VisitThreshold NULL sanitises to 0), or a reconnect whose
		// analytics cron has not run. App-id changes already force a pull in
		// save_options(); plan changes do not. Skip when CN_DEV_MODE is driving
		// the counters, so ?cn_usage=0 stays a real zero for UI testing.
		//
		// get_app_analytics() writes cookie_notice_status['threshold_exceeded'], which pauses
		// blocking on the live site, so the React admin treats this request as a config pull
		// (src/admin-react/api/index.js PULL_ACTIONS): no protection claim while it runs, none
		// for the page if its reply is lost. Removing this call? Keep that list in step.
		$dev_usage = defined( 'CN_DEV_MODE' ) && CN_DEV_MODE && isset( $_POST['cn_usage'] );
		$app_id    = isset( $cn->options['general']['app_id'] ) ? $cn->options['general']['app_id'] : '';

		if ( ! $dev_usage && $cn->get_subscription() === 'basic' && $app_id !== '' && $threshold <= 0 ) {
			$cn->welcome_api->get_app_analytics( $app_id, true, false );
			$analytics_raw = Cookie_Notice_Store::get( 'cookie_notice_app_analytics', [], $network );
			$counters      = $cn->welcome_api->read_cycle_usage_counters( $analytics_raw );
			$visits        = $counters['visits'];
			$threshold     = $counters['threshold'];
		}

		// CN_DEV_MODE: honour cn_usage=0-100 (forwarded as POST field by fetchDashboard
		// since admin-ajax.php is a POST endpoint and $_GET params from the page URL
		// are not available here).
		if ( defined( 'CN_DEV_MODE' ) && CN_DEV_MODE && isset( $_POST['cn_usage'] ) ) {
			$pct       = max( 0, min( 100, (int) $_POST['cn_usage'] ) );
			$threshold = $threshold > 0 ? $threshold : 1000;
			$visits    = (int) round( $threshold * ( $pct / 100 ) );
		}

		// ── Begin dashboard threshold verdict (DEC-012)
		//
		// The plugin's verdict, not a re-derivation of visits >= threshold. React used to
		// recompute the lockout from the two numbers above, which silently dropped every
		// guard evaluate_threshold_exceeded() applies ( welcome-api.php ): a Pro plan
		// carries no threshold and must never arm, and a snapshot the plugin cannot prove
		// current fails OPEN rather than locking on stale counters. Recomputing also
		// re-opens HS#47302, where a domain moved Free -> Pro kept enforcing the old app's
		// threshold until the cron caught up.
		//
		// It matters because this flag decides whether the admin sees a live control or a
		// greyed-out "Paused" one, while validate_options() and the React save path decide
		// whether the change sticks — and those read threshold_exceeded(). Two different
		// answers means the screen promises something the save will not honour.
		$threshold_exceeded = (bool) $cn->threshold_exceeded();

		// CN_DEV_MODE drives the lock from the forced usage above so the UI can be
		// exercised at any percentage without touching the stored status.
		if ( defined( 'CN_DEV_MODE' ) && CN_DEV_MODE && isset( $_POST['cn_usage'] ) ) {
			$threshold_exceeded = $threshold > 0 && $visits >= $threshold;
		}
		// ── End dashboard threshold verdict (DEC-012)

		// --- ConsentStats breakdown ---

		$level_totals = [ 1 => 0, 2 => 0, 3 => 0 ];

		if ( ! empty( $analytics_raw['consentActivities'] ) && is_array( $analytics_raw['consentActivities'] ) ) {
			foreach ( $analytics_raw['consentActivities'] as $entry ) {
				$lvl = (int) $entry->consentlevel;
				if ( isset( $level_totals[ $lvl ] ) ) {
					$level_totals[ $lvl ] += (int) $entry->totalrecd;
				}
			}
		}

		$consent_breakdown = $this->compute_consent_breakdown( $level_totals );

		// Platform account email from login token (#2168).
		// Stored in cookie_notice_app_token transient as ->email after successful login.
		// Used in PortalBridgeModal to tell the user which email to sign in with.
		// Returns empty string when not connected (token not set or expired).
		$data_token    = Cookie_Notice_Store::get_transient( 'cookie_notice_app_token', $network );
		$account_email = ! empty( $data_token->email ) ? sanitize_email( $data_token->email ) : '';

		// The WP dashboard widget's verdict, through the same call the widget renders
		// (Cookie_Notice_Dashboard::get_scorecard()), so Overview and the widget agree.
		// Built HERE, after the analytics pull above: that pull can clear the quota
		// verdict mid-request, and the scorecard must judge the site as it now stands.
		$scorecard = $cn->dashboard->get_scorecard();

		wp_send_json_success( [
			'analytics'        => [
				'cycleUsage' => [
					'visits'    => $visits,
					'threshold' => $threshold,
				],
				// Sibling of cycleUsage, not a field inside it: the counters are the
				// vendor's data, this is the plugin's verdict about them.
				'thresholdExceeded' => $threshold_exceeded,
			],
			'consentBreakdown' => $consent_breakdown,
			'domainUrl'        => home_url(),
			'appId'            => $cn->options['general']['app_id'],
			'activatedAt'      => isset( $cn->status_data['activation_datetime'] ) ? $cn->status_data['activation_datetime'] : 0,
			'consentCount'     => $consent_breakdown['total'],
			'accountEmail'     => $account_email,
			'appConfig'        => $this->dashboard_app_config(),
			// The banner as stored after any pull above (React's store adopts it: api/index.js PULL_ACTIONS).
			'banner'           => $cn->get_banner_summary(),
			// Normal reply only — the empty replies (send_empty_dashboard()) make no claim.
			'scorecard'        => $scorecard,
			'consents7d'       => $this->compute_consents_7d( isset( $analytics_raw['consentActivities'] ) ? $analytics_raw['consentActivities'] : [] ),
			// When THIS SITE last fetched the analytics row ('Y-m-d H:i:s', GMT, no zone),
			// not how old the figures are. Label it "fetched", never "as of"; '' = never.
			'analyticsFetchedAt' => isset( $analytics_raw['lastUpdated'] ) ? (string) $analytics_raw['lastUpdated'] : '',
		] );
	}

	/**
	 * Consents recorded in the last 7 days, from the cached consentActivities rows.
	 *
	 * No remote call: the rows are the daily series cookie_notice_app_analytics already
	 * holds (one row per day and consent level; eventdt is the day). The window is the
	 * 7 COMPLETE UTC days before today — today is excluded, as the WP dashboard widget's
	 * 30-day chart excludes it, because a partial day would be counted as a whole one.
	 * Same level mapping as consentBreakdown (compute_consent_breakdown()).
	 *
	 * @param mixed    $activities consentActivities rows (objects or arrays).
	 * @param int|null $now        Unix time; defaults to now.
	 * @return array { total, acceptRate, customRate, rejectRate, levelLabels }
	 */
	private function compute_consents_7d( $activities, $now = null ) {
		$now   = $now === null ? time() : (int) $now;
		$today = gmdate( 'Y-m-d', $now );
		$from  = gmdate( 'Y-m-d', $now - 7 * DAY_IN_SECONDS );

		$level_totals = [ 1 => 0, 2 => 0, 3 => 0 ];

		if ( is_array( $activities ) ) {
			foreach ( $activities as $entry ) {
				$entry = (object) $entry;

				if ( ! isset( $entry->eventdt, $entry->consentlevel, $entry->totalrecd ) )
					continue;

				// The day, whatever the serialisation ("2026-10-06" or an ISO timestamp).
				// Y-m-d strings compare correctly as strings.
				$day = substr( (string) $entry->eventdt, 0, 10 );

				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || $day < $from || $day >= $today )
					continue;

				$lvl = (int) $entry->consentlevel;

				if ( isset( $level_totals[ $lvl ] ) )
					$level_totals[ $lvl ] += (int) $entry->totalrecd;
			}
		}

		return $this->compute_consent_breakdown( $level_totals );
	}

	/**
	 * A dashboard with no consent data, account or pull: a site under Global Settings Override
	 * (the network's data is not its own) and the Network Admin with the override off (the
	 * main site's data is not the network's). The configuration is dashboard_app_config()'s.
	 *
	 * @return void
	 */
	private function send_empty_dashboard() {
		$consent_breakdown = $this->compute_consent_breakdown( [ 1 => 0, 2 => 0, 3 => 0 ] );

		wp_send_json_success( [
			'analytics'        => [
				'cycleUsage'        => [ 'visits' => 0, 'threshold' => 0 ],
				'thresholdExceeded' => false,
			],
			'consentBreakdown' => $consent_breakdown,
			'domainUrl'        => home_url(),
			'appId'            => '',
			'activatedAt'      => 0,
			'consentCount'     => $consent_breakdown['total'],
			'accountEmail'     => '',
			'appConfig'        => $this->dashboard_app_config(),
			'banner'           => Cookie_Notice()->get_banner_summary(),
		] );
	}

	/**
	 * The app's configuration as the dashboard reports it, from get_app_config()'s local
	 * copies — on a site under Global Settings Override, the network's.
	 *
	 * @return array
	 */
	private function dashboard_app_config() {
		$cn      = Cookie_Notice();
		$network = $cn->is_network_options();

		// Regulations saved locally by cn_api_request?configure action.
		// Exposed here so Protection.jsx LAWS card can display them without a
		// Designer API round-trip. (#1897)
		$reg_keys = Cookie_Notice_Store::get( 'cookie_notice_app_regulations', [], $network );

		// Language codes saved locally by react_apply_languages() on successful API write. (#1966)
		// Always includes 'en' (default) + any additional codes the user configured.
		$saved_languages = Cookie_Notice_Store::get( 'cookie_notice_app_languages', [], $network );

		return [
			'regulations' => array_fill_keys( (array) $reg_keys, true ),
			'language'    => array_values( array_unique( array_merge( [ 'en' ], (array) $saved_languages ) ) ),
			// Banner design fields cached by get_app_config(): the "Your banner" drawing,
			// and the Do Not Sell link the law editors prefill.
			'design'      => Cookie_Notice_Store::get( 'cookie_notice_app_design', [], $network ),
		];
	}

	/**
	 * Return blocking/consent configuration data.
	 *
	 * Reads the cached Designer API config from the cookie_notice_app_blocking WP option
	 * (populated by welcome-api.php get_app_config() on admin page load, on the
	 * 24h cron, or via "Pull Configuration" button). Falls back to an empty stub
	 * for new installs.
	 *
	 * @return void
	 */
	public function get_config() {
		$this->verify_request();

		$cn = Cookie_Notice();

		// ⚠️ Same multisite pattern as get_dashboard() — see comment there.
		$network  = $cn->is_network_options();
		$blocking = Cookie_Notice_Store::get( 'cookie_notice_app_blocking', [], $network );

		wp_send_json_success( $this->build_blocking_response( $blocking ) );
	}

	/**
	 * Return paginated consent log entries.
	 *
	 * Calls the Transactional API via welcome-api.php for the requested date,
	 * maps each record to the shape expected by ConsentLogTable.jsx, then
	 * applies in-PHP pagination (10 records per page).
	 *
	 * POST params accepted:
	 *   page       int     Page number (1-based, default 1)
	 *   start_date string  Date to fetch logs for (Y-m-d, default today)
	 *   sort       string  Sort column key (ignored server-side — API returns ordered data)
	 *   order      string  'asc' | 'desc' (ignored server-side)
	 *
	 * @return void
	 */
	public function get_consent_logs() {
		$this->verify_request();
		$this->verify_consent_logs_scope();

		$page       = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : date( 'Y-m-d' );
		$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : $start_date;
		$per_page   = 10;

		// Validate date formats (Y-m-d).
		$dt = DateTime::createFromFormat( 'Y-m-d', $start_date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $start_date ) {
			$start_date = date( 'Y-m-d' );
		}

		$dt_end = DateTime::createFromFormat( 'Y-m-d', $end_date );
		if ( ! $dt_end || $dt_end->format( 'Y-m-d' ) !== $end_date || $end_date < $start_date ) {
			$end_date = $start_date;
		}

		$cn = Cookie_Notice();

		// Server-side range cap — free = 7 days, pro = 90 days.
		$max_range = ( $cn->get_subscription() === 'pro' ) ? 90 : 7;
		$range     = (int) ( ( new DateTime( $end_date ) )->diff( new DateTime( $start_date ) )->days );

		if ( $range > $max_range ) {
			$end_date = ( new DateTime( $start_date ) )->modify( "+{$max_range} days" )->format( 'Y-m-d' );
		}

		$empty_breakdown = [ 'total' => 0, 'acceptRate' => 0, 'customRate' => 0, 'rejectRate' => 0, 'levelLabels' => $this->get_level_labels() ];

		// Compliance not active: legacy shows no records, only its "not active" state.
		if ( $cn->get_status() !== 'active' ) {
			wp_send_json_success( [
				'logs'             => [],
				'total'            => 0,
				'page'             => $page,
				'totalPages'       => 0,
				'consentBreakdown' => $empty_breakdown,
				'inactive'         => true,
			] );
			return;
		}

		// No app_id means not connected — return empty gracefully.
		if ( empty( $cn->options['general']['app_id'] ) ) {
			wp_send_json_success( [
				'logs'             => [],
				'total'            => 0,
				'page'             => $page,
				'totalPages'       => 0,
				'consentBreakdown' => $empty_breakdown,
			] );
			return;
		}

		// Single API call for the full date range (Transactional API handles range via EndDate).
		$raw = $cn->welcome_api->get_cookie_consent_logs( $start_date, $end_date );

		// An unreachable platform, or an error message from it, is NOT an empty log.
		// Both used to land in the branch below and answer wp_send_json_success with
		// zero records — on the screen an admin uses to demonstrate consent to a
		// regulator.
		//
		// Phrased as "require the SAFE shape", not "reject the known-bad ones". An
		// is_wp_error||is_string list is only as complete as the shapes someone thought
		// of, and the producer assigns $result->error and $result->data verbatim, so an
		// object in either field would walk straight past a denylist into the success
		// branch. A log is an array or it is not a log.
		if ( ! is_array( $raw ) ) {
			if ( is_wp_error( $raw ) )
				$message = $raw->get_error_message();
			elseif ( is_string( $raw ) && $raw !== '' )
				$message = $raw;
			else
				$message = __( 'We could not load your consent records. Please try again in a moment.', 'cookie-notice' );

			wp_send_json_error( [ 'error' => $message ] );
			return;
		}

		if ( empty( $raw ) ) {
			wp_send_json_success( [
				'logs'             => [],
				'total'            => 0,
				'page'             => $page,
				'totalPages'       => 0,
				'consentBreakdown' => $empty_breakdown,
			] );
			return;
		}

		// Transform raw API records into UI-ready log entries.
		$result = $this->transform_consent_logs( $raw, $cn );
		$logs   = $result['logs'];

		$total      = count( $logs );
		$total_pages = (int) ceil( $total / $per_page );
		$offset     = ( $page - 1 ) * $per_page;
		$paged      = array_slice( $logs, $offset, $per_page );

		wp_send_json_success( [
			'logs'             => $paged,
			'total'            => $total,
			'page'             => $page,
			'totalPages'       => $total_pages,
			'consentBreakdown' => $result['consent_breakdown'],
		] );
	}

	/**
	 * Transform raw API consent log records into structured log entries.
	 *
	 * Shared by get_consent_logs() (paginated table) and export_consent_logs() (CSV).
	 * Returns both the transformed log entries and the consent breakdown stats.
	 *
	 * @param array              $raw Raw records from the Transactional API.
	 * @param Cookie_Notice_Main $cn  Plugin instance.
	 * @return array { 'logs' => array, 'consent_breakdown' => array }
	 */
	private function transform_consent_logs( $raw, $cn ) {
		// Compute consent breakdown from real-time data.
		$level_counts = [ 1 => 0, 2 => 0, 3 => 0 ];

		foreach ( $raw as $record ) {
			$lvl = isset( $record->ev_consentlevel ) ? (int) $record->ev_consentlevel : 0;
			if ( isset( $level_counts[ $lvl ] ) ) {
				$level_counts[ $lvl ]++;
			}
		}

		$consent_breakdown = $this->compute_consent_breakdown( $level_counts );

		// Consent level integer → human label (matches ConsentLogTable pill styles).
		$labels = $this->get_level_labels();
		$level_map = [
			1 => $labels['level1'],
			2 => $labels['level2'],
			3 => $labels['level3'],
		];

		$logs = [];

		foreach ( $raw as $record ) {
			$categories = [];

			if ( ! empty( $record->ev_essential ) )
				$categories[] = 'Essential';

			if ( ! empty( $record->ev_analytics ) )
				$categories[] = 'Analytics';

			if ( ! empty( $record->ev_marketing ) )
				$categories[] = 'Marketing';

			if ( ! empty( $record->ev_functional ) )
				$categories[] = 'Functional';

			$level = isset( $record->ev_consentlevel ) ? (int) $record->ev_consentlevel : 0;

			// Format timestamp to readable date/time.
			$date_str = '';
			if ( ! empty( $record->timestamp ) ) {
				try {
					$ts       = new DateTime( $record->timestamp );
					$date_str = $ts->format( 'Y-m-d H:i' ) . ' GMT';
				} catch ( Exception $e ) {
					$date_str = $record->timestamp;
				}
			}

			$logs[] = [
				'id'         => isset( $record->ev_eventdetails_consentid ) ? $record->ev_eventdetails_consentid : '',
				'level'      => isset( $level_map[ $level ] ) ? $level_map[ $level ] : $labels['level2'],
				'levelNum'   => $level,
				'categories' => $categories,
				'date'       => $date_str,
				'ip'         => isset( $record->rj_ip ) ? $record->rj_ip : '',
			];
		}

		return [
			'logs'              => $logs,
			'consent_breakdown' => $consent_breakdown,
		];
	}

	/**
	 * Export consent logs as a downloadable CSV.
	 *
	 * Reuses transform_consent_logs() for data transformation, then formats
	 * the result as CSV and returns it as a string for browser download.
	 * Pro-only: enforced server-side (client-side TierGate is not sufficient).
	 *
	 * POST params accepted:
	 *   start_date string  Range start (Y-m-d, default today)
	 *   end_date   string  Range end   (Y-m-d, default start_date)
	 *
	 * @return void
	 */
	public function export_consent_logs() {
		$this->verify_request();
		$this->verify_consent_logs_scope();

		$cn = Cookie_Notice();

		// Compliance not active: legacy shows no records, only its "not active" state.
		if ( $cn->get_status() !== 'active' ) {
			wp_send_json_success( [ 'csv' => '', 'count' => 0, 'inactive' => true ] );
			return;
		}

		// Server-side Pro gate — TierGate in React is client-only.
		if ( $cn->get_subscription() !== 'pro' ) {
			wp_send_json_error( [ 'error' => 'CSV export requires a Pro subscription.' ] );
			return;
		}

		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : date( 'Y-m-d' );
		$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : $start_date;

		// Validate date formats (Y-m-d).
		$dt = DateTime::createFromFormat( 'Y-m-d', $start_date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $start_date ) {
			$start_date = date( 'Y-m-d' );
		}

		$dt_end = DateTime::createFromFormat( 'Y-m-d', $end_date );
		if ( ! $dt_end || $dt_end->format( 'Y-m-d' ) !== $end_date || $end_date < $start_date ) {
			$end_date = $start_date;
		}

		// Server-side range cap — Pro = 90 days.
		$range = (int) ( ( new DateTime( $end_date ) )->diff( new DateTime( $start_date ) )->days );

		if ( $range > 90 ) {
			$end_date = ( new DateTime( $start_date ) )->modify( '+90 days' )->format( 'Y-m-d' );
		}

		// No app_id means not connected — return empty.
		if ( empty( $cn->options['general']['app_id'] ) ) {
			wp_send_json_success( [ 'csv' => '', 'count' => 0 ] );
			return;
		}

		$raw = $cn->welcome_api->get_cookie_consent_logs( $start_date, $end_date );

		// Same on export, and worse: a CSV of zero rows handed to someone answering a
		// DSAR looks like an answer. Same safe-shape phrasing as the table above.
		if ( ! is_array( $raw ) ) {
			if ( is_wp_error( $raw ) )
				$message = $raw->get_error_message();
			elseif ( is_string( $raw ) && $raw !== '' )
				$message = $raw;
			else
				$message = __( 'We could not load your consent records. Please try again in a moment.', 'cookie-notice' );

			wp_send_json_error( [ 'error' => $message ] );
			return;
		}

		if ( empty( $raw ) ) {
			wp_send_json_success( [ 'csv' => '', 'count' => 0 ] );
			return;
		}

		$result = $this->transform_consent_logs( $raw, $cn );
		$logs   = $result['logs'];

		// Build CSV string.
		$csv_lines   = [];
		$csv_lines[] = 'Consent ID,Level,Date,IP,Categories';

		foreach ( $logs as $log ) {
			$csv_lines[] = sprintf(
				'"%s","%s","%s","%s","%s"',
				str_replace( '"', '""', $log['id'] ),
				str_replace( '"', '""', $log['level'] ),
				str_replace( '"', '""', $log['date'] ),
				str_replace( '"', '""', $log['ip'] ),
				str_replace( '"', '""', implode( '; ', $log['categories'] ) )
			);
		}

		wp_send_json_success( [
			'csv'   => implode( "\n", $csv_lines ),
			'count' => count( $logs ),
		] );
	}

	/**
	 * Return the Privacy Consent tab: compliance status, sources and their forms.
	 *
	 * Each source's on/off and all/selected come from the SITE row the write endpoints
	 * save to (Cookie_Notice_Privacy_Consent::get_settings_row()); only the descriptor —
	 * id, name, type, id type, availability — comes from get_sources(), whose own status
	 * fields are read from $cn->options and so from the network row on admin-ajax under
	 * Global Settings Override.
	 *
	 * POST params accepted:
	 *   source  string  Only this source (paging a dynamic source's forms)
	 *   page    int     Forms page for that source (1-based, default 1)
	 *   order   string  'asc' | 'desc' title order for dynamic sources (default 'asc')
	 *
	 * @return void
	 */
	public function get_privacy_consent() {
		$this->verify_request();

		$cn = Cookie_Notice();

		$only  = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
		$page  = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$order = isset( $_POST['order'] ) && sanitize_key( $_POST['order'] ) === 'desc' ? 'desc' : 'asc';

		$row     = $cn->privacy_consent->get_settings_row();
		$sources = [];

		foreach ( $cn->privacy_consent->get_sources() as $source_id => $source ) {
			if ( $only !== '' && $only !== $source_id )
				continue;

			$state          = $this->privacy_consent_source_state( $source, $row );
			$state['forms'] = $this->privacy_consent_forms( $source, $page, $order );
			$sources[]      = $state;
		}

		if ( $only !== '' && empty( $sources ) ) {
			wp_send_json_error( [ 'error' => __( 'This form source is not available.', 'cookie-notice' ) ] );
		}

		wp_send_json_success( [
			'status'   => $cn->get_status(),
			'readOnly' => $cn->is_network_admin(),
			'sources'  => $sources,
		] );
	}

	/**
	 * Save Privacy Consent source settings (on/off, all/selected forms).
	 *
	 * POST params accepted:
	 *   sources[<id>][active]       '1' | '0'      Only for sources the admin changed
	 *   sources[<id>][active_type]  'all' | 'selected'
	 *
	 * @return void
	 */
	public function save_privacy_consent() {
		$this->verify_request();

		$cn = Cookie_Notice();

		$refusal = $this->privacy_consent_write_refusal();

		if ( $refusal !== '' ) {
			wp_send_json_error( [ 'error' => $refusal ] );
		}

		$submitted = isset( $_POST['sources'] ) && is_array( $_POST['sources'] ) ? wp_unslash( $_POST['sources'] ) : [];
		$row       = $cn->privacy_consent->save_source_settings( $submitted );

		if ( is_wp_error( $row ) ) {
			wp_send_json_error( [ 'error' => $row->get_error_message() ] );
		}

		$sources = [];

		foreach ( $cn->privacy_consent->get_sources() as $source ) {
			$sources[] = $this->privacy_consent_source_state( $source, $row );
		}

		wp_send_json_success( [ 'sources' => $sources ] );
	}

	/**
	 * Set the status of one form (the per-form toggle).
	 *
	 * Same rules as the legacy screen: both go through
	 * Cookie_Notice_Privacy_Consent::update_form_status().
	 *
	 * POST params accepted:
	 *   source   string
	 *   form_id  int|string
	 *   status   '1' | '0'
	 *
	 * @return void
	 */
	public function set_privacy_consent_form_status() {
		$this->verify_request();

		$cn = Cookie_Notice();

		$refusal = $this->privacy_consent_write_refusal();

		if ( $refusal !== '' ) {
			wp_send_json_error( [ 'error' => $refusal ] );
		}

		if ( ! isset( $_POST['source'], $_POST['form_id'], $_POST['status'] ) ) {
			wp_send_json_error( [ 'error' => __( 'This form does not exist.', 'cookie-notice' ) ] );
		}

		$result = $cn->privacy_consent->update_form_status( wp_unslash( $_POST['source'] ), wp_unslash( $_POST['form_id'] ), (bool) (int) $_POST['status'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'error' => $result->get_error_message() ] );
		}

		wp_send_json_success( [
			'formId' => $result['form_id'],
			'status' => $result['status'],
			'source' => [
				'id'         => $result['source'],
				'active'     => $result['active'],
				'activeType' => $result['active_type'],
			],
		] );
	}

	/**
	 * Return the latest privacy (form) consent records, paged in PHP.
	 *
	 * Each row is built from an allowlist of fields (map_privacy_consent_log()); values
	 * are shown as the platform returns them, masked there when the customer's
	 * anonymisation setting is on.
	 *
	 * POST params accepted:
	 *   page  int  Page number (1-based, default 1)
	 *
	 * @return void
	 */
	public function get_privacy_consent_logs() {
		$this->verify_request();
		$this->verify_consent_logs_scope();

		$cn       = Cookie_Notice();
		$page     = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$per_page = 20;
		$empty    = [ 'logs' => [], 'total' => 0, 'page' => $page, 'totalPages' => 0 ];

		// Compliance not active: legacy shows no records, only its "not active" state.
		if ( $cn->get_status() !== 'active' ) {
			wp_send_json_success( $empty + [ 'inactive' => true ] );
			return;
		}

		if ( empty( $cn->options['general']['app_id'] ) ) {
			wp_send_json_success( $empty );
			return;
		}

		$records = $cn->welcome_api->get_privacy_consent_logs();

		// Unreachable, or an error message from the platform, is not an empty log.
		if ( ! is_array( $records ) ) {
			if ( is_wp_error( $records ) )
				$message = $records->get_error_message();
			elseif ( is_string( $records ) && $records !== '' )
				$message = $records;
			else
				$message = __( 'We could not load your consent records. Please try again in a moment.', 'cookie-notice' );

			wp_send_json_error( [ 'error' => $message ] );
			return;
		}

		$logs = [];

		foreach ( $records as $record ) {
			if ( is_object( $record ) || is_array( $record ) )
				$logs[] = $this->map_privacy_consent_log( $record );
		}

		$total = count( $logs );

		wp_send_json_success( [
			'logs'       => array_slice( $logs, ( $page - 1 ) * $per_page, $per_page ),
			'total'      => $total,
			'page'       => $page,
			'totalPages' => (int) ceil( $total / $per_page ),
		] );
	}

	/**
	 * Build one privacy consent log row for the React admin.
	 *
	 * ALLOWLIST: the row carries the six fields legacy's table shows and nothing else.
	 * The platform's record also holds the subject's details, the raw request, the proof,
	 * and user, session and consent ids — none of which this screen displays, so none of
	 * which leave the server.
	 *
	 * @param object|array $record
	 *
	 * @return array
	 */
	public function map_privacy_consent_log( $record ) {
		$r = is_object( $record ) ? get_object_vars( $record ) : (array) $record;

		$text = function ( $key ) use ( $r ) {
			return isset( $r[ $key ] ) && is_scalar( $r[ $key ] ) ? (string) $r[ $key ] : '';
		};

		// source id -> source name, as legacy's Source column
		$source_name = '';
		$source_id   = $text( 'source' );

		if ( $source_id !== '' && $source_id !== 'unknown' ) {
			$source = Cookie_Notice()->privacy_consent->get_source( $source_id );

			if ( ! empty( $source['name'] ) )
				$source_name = (string) $source['name'];
		}

		// preference key names only, as legacy's Preferences column
		$preferences = isset( $r['preferences'] ) ? array_map( 'strval', array_keys( (array) $r['preferences'] ) ) : [];

		$date = $text( 'created_at' );

		if ( $date !== '' ) {
			try {
				$datetime = new DateTime( $date );
				$date     = $datetime->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) . ' ' . __( 'GMT', 'cookie-notice' );
			} catch ( Exception $e ) {
				// keep the value as the platform sent it
			}
		}

		return [
			'subject'     => $text( 'subject_id' ),
			'preferences' => $preferences,
			'source'      => $source_name,
			'form'        => $text( 'form_title' ),
			'date'        => $date,
			'ip'          => $text( 'ip_address' ),
		];
	}

	/**
	 * Why a Privacy Consent write must be refused, or '' when it may proceed.
	 *
	 * Legacy has no network save for privacy consent, and disables saving while the
	 * compliance status is not active.
	 *
	 * @return string
	 */
	private function privacy_consent_write_refusal() {
		$cn = Cookie_Notice();

		if ( $cn->is_network_admin() )
			return __( 'Privacy Consent settings are managed on each site of the network.', 'cookie-notice' );

		if ( $cn->get_status() !== 'active' )
			return __( 'Privacy Consent requires an active Cookie Compliance connection.', 'cookie-notice' );

		return '';
	}

	/**
	 * A source's descriptor and its settings from the site row.
	 *
	 * @param array $source
	 * @param array $row
	 *
	 * @return array
	 */
	private function privacy_consent_source_state( $source, $row ) {
		$cn = Cookie_Notice();
		$id = $source['id'];

		$available = ! empty( $source['availability'] );
		$active    = $available && ! empty( $row[ $id . '_active' ] );
		$type      = isset( $row[ $id . '_active_type' ] ) && is_string( $row[ $id . '_active_type' ] ) && array_key_exists( $row[ $id . '_active_type' ], $cn->privacy_consent->form_active_types ) ? $row[ $id . '_active_type' ] : 'all';

		// legacy shows every source off in the Network Admin of a network-activated plugin
		if ( is_multisite() && $cn->is_network_admin() && $cn->is_plugin_network_active() )
			$active = false;

		return [
			'id'           => $id,
			'name'         => $source['name'],
			'type'         => $source['type'],
			'idType'       => $source['id_type'],
			'availability' => $available,
			'active'       => $active,
			'activeType'   => $type,
		];
	}

	/**
	 * One page of a source's forms with their stored statuses.
	 *
	 * Static sources list their fixed forms; dynamic sources are queried through the
	 * module, ten per page in title order, as the legacy table.
	 *
	 * @param array  $source
	 * @param int    $page
	 * @param string $order
	 *
	 * @return array
	 */
	private function privacy_consent_forms( $source, $page, $order ) {
		$cn    = Cookie_Notice();
		$empty = [ 'items' => [], 'total' => 0, 'page' => 1, 'maxPages' => 0 ];

		// an unavailable source's plugin is not loaded, so neither are its forms
		if ( empty( $source['availability'] ) )
			return $empty;

		$dynamic = $source['type'] === 'dynamic';

		if ( $dynamic ) {
			$instance = $cn->privacy_consent->get_instance( $source['id'] );

			if ( ! $instance )
				return $empty;

			$result = $instance->get_forms( [
				'source'  => $source['id'],
				'order'   => $order,
				'orderby' => 'title',
				'page'    => $page,
				'search'  => ''
			] );

			$forms     = isset( $result['forms'] ) && is_array( $result['forms'] ) ? $result['forms'] : [];
			$total     = isset( $result['total'] ) ? (int) $result['total'] : 0;
			$max_pages = isset( $result['max_pages'] ) ? (int) $result['max_pages'] : 0;
		} else {
			$forms     = isset( $source['forms'] ) && is_array( $source['forms'] ) ? array_values( $source['forms'] ) : [];
			$total     = count( $forms );
			$max_pages = $total > 0 ? 1 : 0;
			$page      = 1;
		}

		$statuses = $cn->privacy_consent->get_form_statuses( $source['id'] );
		$items    = [];

		foreach ( $forms as $form ) {
			$items[] = [
				'id'         => $form['id'],
				'title'      => $dynamic ? $form['title'] : $form['name'],
				'date'       => $dynamic ? $form['date'] : '',
				'fieldCount' => isset( $form['fields'] ) && is_array( $form['fields'] ) ? count( $form['fields'] ) : 0,
				'status'     => array_key_exists( $form['id'], $statuses ) && is_array( $statuses[ $form['id'] ] ) && ! empty( $statuses[ $form['id'] ]['status'] ),
			];
		}

		return [ 'items' => $items, 'total' => $total, 'page' => $page, 'maxPages' => $max_pages ];
	}

	/**
	 * Save welcome modal dismissal timestamp.
	 *
	 * Called when the user closes the modal or clicks "Don't protect my business".
	 * Stores the timestamp so the modal won't re-appear for 30 days.
	 *
	 * @return void
	 */
	public function dismiss_welcome() {
		$this->verify_request();

		// The scope cnReactData.welcomeDismissedAt reads (settings.php): network row in the
		// Network Admin, site row on a site.
		Cookie_Notice_Store::set( 'cookie_notice_welcome_dismissed', current_time( 'mysql' ), Cookie_Notice()->is_network_admin() );

		wp_send_json_success();
	}

	/**
	 * Mark the setup wizard as complete.
	 *
	 * Called when the user finishes (or skips) the FirstRunSetup wizard on the
	 * Settings tab. Persists a flag so the wizard doesn't re-appear.
	 *
	 * @return void
	 */
	public function complete_setup_wizard() {
		$this->verify_request();

		// The scope cnReactData.setupWizardComplete reads (settings.php).
		Cookie_Notice_Store::set( 'cookie_notice_setup_wizard_complete', true, Cookie_Notice()->is_network_admin() );

		wp_send_json_success();
	}

	/**
	 * DEV ONLY: Reset all plugin onboarding state to simulate a fresh activation.
	 * Only registered as an AJAX action when CN_DEV_MODE is true.
	 */
	public function dev_reset() {
		if ( ! defined( 'CN_DEV_MODE' ) || ! CN_DEV_MODE ) {
			wp_send_json_error( [ 'error' => 'Not available outside CN_DEV_MODE.' ] );
		}

		$this->verify_request();

		$cn = Cookie_Notice();

		// --- Step 1: Delete the API-side app record BEFORE clearing WP options. (#1956)
		//
		// After a successful use_license or register+configure flow, the Account API creates
		// an Application row for this domain. Deleting WP options alone does NOT remove it:
		// - The app record consumes a subscription slot (distorts availablelicense counts)
		// - Orphan apps accumulate across test runs
		//
		// We capture the current app_id from WP options, authenticate as the test account
		// (whose credentials are defined via CN_DEV_TEST_EMAIL + CN_DEV_TEST_PASSWORD
		// constants, falling back to env vars), then call POST /api/account/app/delete.
		//
		// This is best-effort: login or delete failures are logged but do NOT block the
		// WP options reset — the reset must always succeed regardless of API availability.
		$current_app_id = ! empty( $cn->options['general']['app_id'] ) ? $cn->options['general']['app_id'] : '';
		$app_delete_error = null;

		if ( ! empty( $current_app_id ) ) {
			$test_email    = defined( 'CN_DEV_TEST_EMAIL' )    ? CN_DEV_TEST_EMAIL    : getenv( 'CN_DEV_TEST_EMAIL' );
			$test_password = defined( 'CN_DEV_TEST_PASSWORD' ) ? CN_DEV_TEST_PASSWORD : getenv( 'CN_DEV_TEST_PASSWORD' );

			if ( ! empty( $test_email ) && ! empty( $test_password ) ) {
				// Login to get a Bearer token, then delete the app.
				// ->welcome_api, not ->welcome: the latter is Cookie_Notice_Welcome and has
				// no request(). And dev_request(), not request(), which is private. Both
				// faults were fatals, so this cleanup never ran.
				$welcome_api = Cookie_Notice()->welcome_api;
				$login_result = $welcome_api->dev_request( 'login', [
					'AdminID'  => $test_email,
					'Password' => $test_password,
				] );

				// A first-factor-only answer ( the test account has two-step verification ) is not a
				// session: the same rule as sign-in and sign-up, one helper. It is not stored and not
				// used, so app_delete is skipped like any failed login — the reset below still runs.
				$partial_login = ! empty( $login_result->data->token ) && $welcome_api->is_partial_login_response( $login_result->data );

				if ( $partial_login ) {
					$app_delete_error = 'The test account answered with a partial (first-factor-only) token; the app was not deleted. Use a test account without two-step verification.';

					error_log( '[Cookie Notice] dev_reset - login for ' . $test_email . ' returned a partial token; token not stored, skipping app_delete.' );
				} elseif ( ! empty( $login_result->data->token ) ) {
					// Store the full data object (not just the token string) — request() reads
					// $data_token->token so the shape must match what login normally stores.
					set_transient( 'cookie_notice_app_token', $login_result->data, HOUR_IN_SECONDS );

					$delete_result = $welcome_api->dev_request( 'app_delete', [
						'AppID' => $current_app_id,
					] );

					if ( $cn->options['general']['debug_mode'] ) {
						error_log( '[Cookie Notice] dev_reset - app_delete result for ' . $current_app_id . ': ' . wp_json_encode( $delete_result ) );
					}
				} else {
					if ( $cn->options['general']['debug_mode'] ) {
						error_log( '[Cookie Notice] dev_reset - login failed for ' . $test_email . ', skipping app_delete.' );
					}
				}
			}
		}

		// --- Step 2: Clear WP options (always runs regardless of API result above).
		// The onboarding flags in every scope this request may have written them: the site row,
		// and in the Network Admin the network row too (dismiss_welcome(), complete_setup_wizard()).
		foreach ( self::SCOPED_ONBOARDING_FLAGS as $flag ) {
			delete_option( $flag );

			if ( $cn->is_network_admin() )
				delete_site_option( $flag );
		}

		$options = $cn->options['general'];
		$options['app_id']  = '';
		$options['app_key'] = '';

		if ( is_multisite() ) {
			update_site_option( 'cookie_notice_options', $options );
		} else {
			update_option( 'cookie_notice_options', $options );
		}

		$default_data = $cn->defaults['data'];

		if ( is_multisite() ) {
			update_site_option( 'cookie_notice_status', $default_data );
		} else {
			update_option( 'cookie_notice_status', $default_data );
		}

		// Fresh-activation state: no engine remembered (the next pull is a first connect).
		$cn->forget_banner_engine( is_multisite() );

		// Clear transient caches
		delete_transient( 'cookie_notice_app_quick_config' );
		delete_site_transient( 'cookie_notice_app_quick_config' );
		delete_transient( 'cookie_notice_app_token' );
		delete_site_transient( 'cookie_notice_app_token' );

		$deleted_app = ! empty( $current_app_id ) ? $current_app_id : null;
		wp_send_json_success( [
			'message'          => 'Plugin reset to fresh-activation state.',
			'deleted_app'      => $deleted_app,
			'app_delete_error' => $app_delete_error,
		] );
	}

	/**
	 * DEV ONLY: Set a single allowlisted WP option by name.
	 * Used by Playwright tests to set fixture state without Docker/WP-CLI.
	 * Only registered as an AJAX action when CN_DEV_MODE is true.
	 *
	 * POST fields:
	 *   option_name  — one of the allowlisted option names below
	 *   option_value — string value to store
	 */
	public function test_set_option() {
		if ( ! defined( 'CN_DEV_MODE' ) || ! CN_DEV_MODE ) {
			wp_send_json_error( [ 'error' => 'Not available outside CN_DEV_MODE.' ] );
		}

		$this->verify_request();

		// Allowlist — only options the test suite legitimately needs to set.
		$allowed = [
			'cookie_notice_ui_mode',
			'cookie_notice_status',
			'cookie_notice_setup_wizard_complete',
			'cookie_notice_welcome_dismissed',
			'cookie_notice_options',
		];

		$option_name = isset( $_POST['option_name'] ) ? sanitize_key( $_POST['option_name'] ) : '';

		if ( ! in_array( $option_name, $allowed, true ) ) {
			wp_send_json_error( [ 'error' => 'Option not in allowlist: ' . $option_name ] );
		}

		// cookie_notice_options is stored as a PHP array — decode JSON input.
		$raw_value = isset( $_POST['option_value'] ) ? wp_unslash( $_POST['option_value'] ) : '';

		if ( $option_name === 'cookie_notice_options' ) {
			$option_value = json_decode( $raw_value, true );
			if ( ! is_array( $option_value ) ) {
				wp_send_json_error( [ 'error' => 'cookie_notice_options must be valid JSON object.' ] );
			}
		} else {
			$option_value = sanitize_text_field( $raw_value );
		}

		// The onboarding flags live where their React writers put them (network row in the
		// Network Admin); everything else here is site-scoped.
		Cookie_Notice_Store::set( $option_name, $option_value, in_array( $option_name, self::SCOPED_ONBOARDING_FLAGS, true ) && Cookie_Notice()->is_network_admin() );

		wp_send_json_success( [ 'option' => $option_name, 'value' => $option_value ] );
	}

	/**
	 * DEV ONLY: Read a single allowlisted WP option by name.
	 * Used by Playwright tests to inspect persisted state without Docker/WP-CLI.
	 * Only registered as an AJAX action when CN_DEV_MODE is true.
	 *
	 * POST fields:
	 *   option_name — one of the allowlisted option names below
	 */
	public function test_get_option() {
		if ( ! defined( 'CN_DEV_MODE' ) || ! CN_DEV_MODE ) {
			wp_send_json_error( [ 'error' => 'Not available outside CN_DEV_MODE.' ] );
		}

		$this->verify_request();

		// Allowlist — only options the test suite legitimately needs to read.
		$allowed = [
			'cookie_notice_options',
			'cookie_notice_status',
			'cookie_notice_ui_mode',
			'cookie_notice_setup_wizard_complete',
			'cookie_notice_welcome_dismissed',
			'cookie_notice_app_blocking',
			'cookie_notice_app_design',
		];

		$option_name = isset( $_POST['option_name'] ) ? sanitize_key( $_POST['option_name'] ) : '';

		if ( ! in_array( $option_name, $allowed, true ) ) {
			wp_send_json_error( [ 'error' => 'Option not in allowlist: ' . $option_name ] );
		}

		$value = Cookie_Notice_Store::get( $option_name, false, in_array( $option_name, self::SCOPED_ONBOARDING_FLAGS, true ) && Cookie_Notice()->is_network_admin() );

		// Serialize arrays/objects so the test can inspect them as a string.
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value );
		}

		wp_send_json_success( [ 'option' => $option_name, 'value' => (string) $value ] );
	}

	/**
	 * Decode a WAF-safe save field: strict-decode-or-reject with legacy passthrough.
	 *
	 * The React admin base64-encodes code/markup-bearing fields behind
	 * self::WAF_B64_SENTINEL so a WAF can't 403 the POST. This reverses that:
	 *
	 *  - No sentinel  → legacy (non-encoded) payload. Sets $ok = true and returns
	 *    the raw value UNCHANGED; the caller then runs today's exact passthrough
	 *    (wp_unslash etc.). The sentinel is checked on the RAW $_POST value (post
	 *    wp_magic_quotes, pre wp_unslash): this works because the marker is
	 *    printable ASCII with none of the characters addslashes escapes (no NUL,
	 *    quote, or backslash), so wp_magic_quotes leaves the prefix byte-identical
	 *    and the strncmp match holds. The base64 body is likewise slash-free.
	 *  - Sentinel present → strip it, strict base64_decode (4th arg true), then
	 *    require valid UTF-8. On any failure sets $ok = false and returns '' so the
	 *    caller can reject the whole save (no partial store). On success sets
	 *    $ok = true and returns the decoded bytes VERBATIM — the caller MUST store
	 *    them directly, with NO second wp_unslash (a second unslash corrupts
	 *    backslash-bearing scripts). An empty field (sentinel + '') round-trips to ''.
	 *
	 * Kept static + pure (no $this) so it is unit-testable in isolation.
	 *
	 * @param  string $raw The raw $_POST value (not yet wp_unslash'd).
	 * @param  bool   $ok  Out-param: true on success/passthrough, false on decode failure.
	 * @return string      Decoded value, unchanged passthrough value, or '' on failure.
	 */
	private static function decode_waf_field( $raw, &$ok ) {
		$sentinel = self::WAF_B64_SENTINEL;
		$len      = strlen( $sentinel );

		// Not sentinel-tagged → legacy passthrough, unchanged.
		if ( strncmp( (string) $raw, $sentinel, $len ) !== 0 ) {
			$ok = true;
			return $raw;
		}

		$body    = substr( (string) $raw, $len );
		$decoded = base64_decode( $body, true ); // strict: false on any non-base64 input

		if ( $decoded === false || ! mb_check_encoding( $decoded, 'UTF-8' ) ) {
			$ok = false;
			return '';
		}

		$ok = true;
		return $decoded;
	}

	/**
	 * Save plugin options submitted from the React admin UI.
	 *
	 * Reads each recognized POST field and sanitizes it into a change set: the posted
	 * keys (nested settings by leaf) plus message_text when the policy-link shortcode
	 * changes it. Only those keys are stored, over a fresh read of the row
	 * (Cookie_Notice::update_general_option_keys(), via Settings::store_option_keys()),
	 * so a setting another request saved while this one ran is not undone. (The row this
	 * request holds was loaded when the save request started, not when the page loaded.)
	 *
	 * @return void
	 */
	public function save_options() {
		$this->verify_request();
		Cookie_Notice()->settings->verify_not_network_managed();

		$cn      = Cookie_Notice();
		$network = Cookie_Notice()->is_network_admin();
		$changes = [];

		// Capture the connected app id before the save, so a connection change can be
		// detected after persist (see the refresh block at the end).
		$old_app_id = isset( $cn->options['general']['app_id'] ) ? $cn->options['general']['app_id'] : '';

		// WAF-safe decode gate (#47585, #47616). The React admin base64-encodes the
		// code/markup-bearing fields (refuse_code, refuse_code_head, conditional_rules)
		// behind self::WAF_B64_SENTINEL so a firewall can't 403 the POST for carrying a
		// raw <script>. Decode-or-reject ALL of them up-front, before any $changes
		// mutation for these fields, so a corrupt encoded payload rejects the whole save
		// (no partial store). Legacy (non-sentinel) values pass through unchanged and the
		// existing per-field passthrough below still applies.
		//
		// array_key_exists( '<field>', $waf )  → field was sentinel-decoded; store the
		//     decoded value DIRECTLY (NO second wp_unslash — that corrupts backslash-bearing
		//     scripts). Key-presence (not isset) is the test, so an empty decoded value counts.
		// key absent  → field is a legacy passthrough; keep today's exact wp_unslash below.
		$waf              = [];
		$waf_fields       = [ 'refuse_code', 'refuse_code_head', 'conditional_rules' ];
		$sentinel         = self::WAF_B64_SENTINEL;
		$sentinel_len     = strlen( $sentinel );

		foreach ( $waf_fields as $waf_field ) {
			if ( ! isset( $_POST[ $waf_field ] ) ) {
				continue;
			}

			// Was this value sentinel-tagged (encoded) or a legacy passthrough? Match
			// the marker on the RAW value (post wp_magic_quotes, pre wp_unslash). The
			// marker is printable ASCII with no addslashes-escaped chars (no NUL, quote,
			// or backslash), so wp_magic_quotes leaves it intact and this strncmp holds.
			$is_encoded = strncmp( (string) $_POST[ $waf_field ], $sentinel, $sentinel_len ) === 0;

			$ok      = false;
			$decoded = self::decode_waf_field( $_POST[ $waf_field ], $ok );

			if ( ! $ok ) {
				// Reject the entire save — no store, no partial write.
				wp_send_json_error( [ 'error' => __( 'Could not save: the settings payload could not be decoded. Please retry.', 'cookie-notice' ) ] );
			}

			// Record the decoded value only for the sentinel branch; a legacy
			// passthrough is left absent so it keeps today's exact wp_unslash below.
			if ( $is_encoded ) {
				$waf[ $waf_field ] = $decoded;
			}
		}

		// Boolean fields.
		$bool_fields = [
			'refuse_opt',
			'revoke_cookies',
			'on_scroll',
			'on_click',
			'redirection',
			'see_more',
			'bot_detection',
			'amp_support',
			'caching_compatibility',
			'debug_mode',
			'wp_consent_api',
			'conditional_active',
			'deactivation_delete',
			'app_blocking',
			// The engine is NOT quota-capped (DEC-012) — only the posture below is.
			'app_blocking_engine',
		];

		foreach ( $bool_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$changes[ $field ] = (bool) $_POST[ $field ];
			}
		}

		// Same rule as legacy (Settings::sanitize_amp_support / _caching_compatibility):
		// on only while the plugin it serves is active. Only when posted — a stored value
		// the admin did not touch is left as it is.
		if ( isset( $_POST['amp_support'] ) ) {
			$changes['amp_support'] = $cn->settings->sanitize_amp_support( $changes['amp_support'] );
		}

		if ( isset( $_POST['caching_compatibility'] ) ) {
			$changes['caching_compatibility'] = $cn->settings->sanitize_caching_compatibility( $changes['caching_compatibility'] );
		}

		// ── Begin app_blocking quota freeze (#2272)
		//
		// While the Free-plan visit limit is exceeded the stored preference is FROZEN,
		// not editable and not overwritable — the same contract the classic form has
		// had since #2272 (it renders the checkbox disabled, omits its sentinel, and
		// validate_options() then preserves the DB value).
		//
		// This path has no sentinel. The React settings save posts only the keys changed
		// since its last successful save, but any app_blocking value it does post started
		// from the quota-forced false it was handed in cnReactData.options — and nothing
		// stops another caller posting the key. So without this, a save while over quota
		// could destroy the admin's autoblocking preference permanently — a cycle reset
		// does not bring it back.
		//
		// So over quota app_blocking is left out of the save, posted or not. Only the
		// change set is written, over a fresh read of the row, so the row keeps the
		// app_blocking it holds at that moment — not a value this request remembered at
		// its start, which another request may have replaced since. And
		// Cookie_Notice::update_general_option_keys() points the #2272 guard at that
		// stored value, so the guard does not turn a stored false back into true.
		if ( $cn->threshold_exceeded() )
			unset( $changes['app_blocking'] );
		// ── End app_blocking quota freeze (#2272)

		// ── Begin React text-field sanitise ──
		//
		// Every value is wp_unslash'ed first: WordPress addslashes() all of $_POST
		// (wp_magic_quotes), so without it "Don't" is stored as "Don\'t".
		//
		// Each field is cleaned by the same Settings method the legacy save uses:
		//  - the two message texts carry admin HTML (links, bold, line breaks): trim +
		//    wp_kses_post with the plugin's 'display' style allowance. sanitize_text_field
		//    here once erased that HTML for good on any React save. The frontend re-runs
		//    wp_kses_post at render, as it does for legacy values;
		//  - the button texts: sanitize_text_field;
		//  - the button CSS class: sanitize_html_class per class.
		// Nothing left after cleaning → the default text, as legacy does.
		foreach ( [ 'message_text', 'revoke_message_text' ] as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$changes[ $field ] = $cn->settings->sanitize_message_text( $field, wp_unslash( $_POST[ $field ] ) );
			}
		}

		foreach ( [ 'accept_text', 'refuse_text', 'revoke_text' ] as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$changes[ $field ] = $cn->settings->sanitize_button_text( $field, wp_unslash( $_POST[ $field ] ) );
			}
		}

		if ( isset( $_POST['css_class'] ) ) {
			$changes['css_class'] = $cn->settings->sanitize_css_class( wp_unslash( $_POST['css_class'] ) );
		}
		// ── End React text-field sanitise ──

		// Connection credential fields — sanitize_key strips to lowercase alphanumeric + dashes/underscores.
		if ( isset( $_POST['app_id'] ) ) {
			$changes['app_id'] = sanitize_key( wp_unslash( $_POST['app_id'] ) );
		}

		if ( isset( $_POST['app_key'] ) ) {
			$changes['app_key'] = sanitize_key( wp_unslash( $_POST['app_key'] ) );
		}

		// Script blocking code fields — these can contain <script> tags.
		// Sentinel-decoded (WAF-safe) values are already the exact bytes the admin
		// typed, so they are NEVER wp_unslash'ed (a second unslash corrupts any
		// backslash-bearing script/regex); a legacy (non-encoded) value is unslashed
		// once. Either is then cleaned by the legacy rule — trim + wp_kses with the
		// tags allowed for its place (Settings::sanitize_refuse_code).
		foreach ( [ 'refuse_code' => 'body', 'refuse_code_head' => 'head' ] as $field => $location ) {
			if ( array_key_exists( $field, $waf ) ) {
				$changes[ $field ] = $cn->settings->sanitize_refuse_code( $waf[ $field ], $location );
			} elseif ( isset( $_POST[ $field ] ) ) {
				$changes[ $field ] = $cn->settings->sanitize_refuse_code( wp_unslash( $_POST[ $field ] ), $location );
			}
		}

		// Excluded script handles — newline-separated string from React textarea → stored as array.
		if ( isset( $_POST['excluded_handles'] ) ) {
			$changes['excluded_handles'] = array_values( array_filter( array_map( 'sanitize_text_field', explode( "\n", wp_unslash( $_POST['excluded_handles'] ) ) ) ) );
		}

		// Conditional rules — JSON string from React → validated nested array.
		// Sentinel-decoded values are already the exact JSON bytes (no wp_unslash);
		// legacy values keep today's wp_unslash. The per-rule validation loop below
		// is unchanged for both paths.
		if ( isset( $_POST['conditional_rules'] ) ) {
			$raw_rules = array_key_exists( 'conditional_rules', $waf )
				? json_decode( $waf['conditional_rules'], true )
				: json_decode( wp_unslash( $_POST['conditional_rules'] ), true );

			if ( is_array( $raw_rules ) ) {
				$settings  = Cookie_Notice()->settings;
				$group_id  = 1;
				$rules     = [];

				foreach ( $raw_rules as $group ) {
					if ( ! is_array( $group ) || empty( $group ) ) {
						continue;
					}

					$rule_id = 1;

					foreach ( $group as $rule ) {
						if ( ! is_array( $rule ) ) {
							continue;
						}

						$param    = sanitize_key( $rule['param'] ?? '' );
						$operator = sanitize_key( $rule['operator'] ?? '' );
						$value    = $param === 'taxonomy_archive'
							? ( $rule['value'] ?? '' )
							: sanitize_key( $rule['value'] ?? '' );

						if ( $param && $operator && $value !== '' && $settings->check_rule( $param, $operator, $value ) ) {
							$rules[ $group_id ][ $rule_id++ ] = [
								'param'    => $param,
								'operator' => $operator,
								'value'    => $value,
							];
						}
					}

					if ( ! empty( $rules[ $group_id ] ) ) {
						$group_id++;
					}
				}

				$changes['conditional_rules'] = $rules;
			} else {
				$changes['conditional_rules'] = [];
			}
		}

		// Select fields — value must be one of the allowed options. Cookie expiry choices
		// are the filtered (cn_cookie_expiry) list legacy offers and validates against.
		$expiry_choices = array_map( 'strval', array_keys( $cn->settings->times ) );

		$select_fields = [
			'revoke_cookies_opt' => [ 'automatic', 'manual' ],
			'time'               => $expiry_choices,
			'time_rejected'      => $expiry_choices,
			'link_target'        => [ '_blank', '_self' ],
			'link_position'      => [ 'banner', 'message' ],
			'position'           => [ 'top', 'bottom', 'left', 'right', 'popup' ],
			'displayType'        => [ 'fixed', 'floating' ],
			'hide_effect'        => [ 'none', 'fade', 'slide' ],
			'script_placement'   => [ 'header', 'footer' ],
			'conditional_display' => [ 'hide', 'show' ],
			'ui_mode'             => [ 'react', 'legacy' ],
		];

		foreach ( $select_fields as $field => $allowed ) {
			if ( isset( $_POST[ $field ] ) ) {
				$value = sanitize_text_field( $_POST[ $field ] );
				if ( in_array( $value, $allowed, true ) ) {
					$changes[ $field ] = $value;
				}
			}
		}

		// Number fields.
		if ( isset( $_POST['on_scroll_offset'] ) ) {
			$changes['on_scroll_offset'] = absint( $_POST['on_scroll_offset'] );
		}

		// Nested colors array — text, button, bar, bar_opacity.
		$color_fields = [ 'text', 'button', 'bar' ];
		foreach ( $color_fields as $color_field ) {
			$post_key = 'color_' . $color_field;
			if ( isset( $_POST[ $post_key ] ) ) {
				$val = sanitize_hex_color( $_POST[ $post_key ] );
				if ( $val ) {
					$changes['colors'][ $color_field ] = $val;
				}
			}
		}

		// bar_opacity lives inside the nested colors array; clamp to 50–100.
		if ( isset( $_POST['bar_opacity'] ) ) {
			$bar_opacity = absint( $_POST['bar_opacity'] );
			$bar_opacity = max( 50, min( 100, $bar_opacity ) );
			$changes['colors']['bar_opacity'] = $bar_opacity;
		}

		// Nested see_more_opt array.
		if ( isset( $_POST['see_more_opt'] ) && is_array( $_POST['see_more_opt'] ) ) {
			$raw = wp_unslash( $_POST['see_more_opt'] );

			if ( isset( $raw['text'] ) ) {
				$changes['see_more_opt']['text'] = sanitize_text_field( $raw['text'] );
			}

			if ( isset( $raw['link_type'] ) ) {
				$link_type = sanitize_text_field( $raw['link_type'] );
				if ( in_array( $link_type, [ 'page', 'custom' ], true ) ) {
					$changes['see_more_opt']['link_type'] = $link_type;
				}
			}

			if ( isset( $raw['id'] ) ) {
				$changes['see_more_opt']['id'] = absint( $raw['id'] );
			}

			if ( isset( $raw['link'] ) ) {
				$changes['see_more_opt']['link'] = esc_url_raw( $raw['link'] );
			}

			if ( isset( $raw['sync'] ) ) {
				$changes['see_more_opt']['sync'] = (bool) $raw['sync'];
			}
		}

		// Only plugin-owned fields (#2264). Every key set above is one; this keeps request
		// bookkeeping (action, nonce, cn_network) out of the row whatever is added later.
		$changes = array_intersect_key( $changes, array_flip( Cookie_Notice::$plugin_owned_fields ) );

		// Post-save effects, as legacy runs them, on the row as it will be stored — the row
		// read fresh, with this save's changes over it — so they see the stored values of
		// keys this partial save did not carry, including any another request saved since
		// this save request started (its cached copy is from that moment).
		$cn->drop_cached_general_options( $network );

		$fresh = Cookie_Notice_Store::get( 'cookie_notice_options', [], $network );
		$row   = $cn->merge_general_options( $cn->multi_array_merge( $cn->defaults['general'], is_array( $fresh ) ? $fresh : [] ), $changes );

		// ── Begin disconnect status reset
		//
		// A save that leaves no complete connection (App ID or App Key empty) resets the
		// connection status to the defaults, as the classic form does
		// (Settings::validate_options()). Without it a React disconnect left the status
		// 'active', so the front end kept printing the Cookie Compliance widget with no App ID,
		// and the config cron, unscheduled without an App ID, never corrected it.
		//
		// Decided on $row, the row as this save will store it, never on $old_app_id or
		// $cn->options (this request's copy from its start): a connection another request
		// stored since then is not reset. Written here, before store_option_keys() purges
		// page caches, so the purge comes after the status the front end prints from. Runs
		// on every save that leaves no connection, so a site already stuck 'active' without
		// one is repaired by its next save; a status that is already the defaults is unchanged.
		if ( empty( $row['app_id'] ) || empty( $row['app_key'] ) ) {
			Cookie_Notice_Store::set( 'cookie_notice_status', $cn->defaults['data'], $network );

			// …and the engine it ran: reconnecting, even to the same App ID, is a first connect.
			$cn->forget_banner_engine( $network );

			// This request's status, read back from the row just written.
			$cn->set_status_data();
		}
		// ── End disconnect status reset

		$saved = $cn->settings->append_policy_link_shortcode( $row );

		// The shortcode is appended to message_text: stored with this save when it was.
		if ( $saved['message_text'] !== $row['message_text'] )
			$changes['message_text'] = $saved['message_text'];

		$cn->settings->sync_privacy_policy_page( $saved );
		$cn->settings->register_wpml_option_strings( $saved );

		// The connection this save leaves (the refresh block below compares it).
		$new_app_id  = isset( $changes['app_id'] ) ? $changes['app_id'] : $old_app_id;
		$new_app_key = isset( $changes['app_key'] ) ? $changes['app_key'] : ( isset( $cn->options['general']['app_key'] ) ? $cn->options['general']['app_key'] : '' );

		// Persist — network vs. single-site.
		//
		// Scope comes from the claim recorded in Cookie_Notice::set_network_data() and
		// vetted by Cookie_Notice::enforce_network_scope() on plugins_loaded, never from
		// $_POST['cn_network'] directly. verify_request() above proves only manage_options,
		// a site-level capability every subsite administrator holds, and the plugin-owned
		// fields include app_id and app_key — so reading the raw field here let a subsite
		// admin point every site on the network at their own Cookie Compliance account.
		//
		// $cn->options['general'] was loaded by the constructor using the same claim, so
		// the in-memory copy and the row written cannot disagree about scope.
		//
		// store_option_keys() writes only $changes, removes any stored key that is not a
		// plugin-owned field (#2264), and fires cn_configuration_updated once (caching
		// plugins purge). A connection change purges a second time, after its pull (below).
		$cn->settings->store_option_keys( $changes, $network );

		// Connection changed — refresh cached app data for the new app id.
		//
		// The cached analytics ( cookie_notice_app_analytics ) and derived status
		// ( cookie_notice_status['threshold_exceeded'] ) are otherwise refreshed only
		// hourly via the cookie_notice_get_app_analytics cron. Without this, a domain
		// reconnected from a Free to a Pro app keeps showing the Free-plan visit-limit
		// notice -- and the app_blocking cap applied above -- until the cron next runs.
		// Mirrors the legacy form path in Settings::validate_options().
		//
		// get_app_config() can rewrite app_blocking (the posture sync), so the React admin
		// must treat this save as a config pull: src/admin-react/api/index.js PULL_FIELDS
		// lists the keys that trigger it. Changing what triggers a pull here? Update that list.
		if ( $new_app_id !== '' && ! empty( $new_app_key ) && $new_app_id !== $old_app_id ) {
			// Mirror the just-persisted credentials into the in-memory options so the
			// config/token requests below authenticate as the new app, exactly as the
			// legacy form path does after register_setting() writes the new options.
			$cn->options['general']['app_id']  = $new_app_id;
			$cn->options['general']['app_key'] = $new_app_key;

			$app_data = $cn->welcome_api->get_app_config( $new_app_id, true, false );

			// get_app_config() returns the status_data array normally, but can
			// return null (its one-cron-per-hour throttle branch). Guard the shape
			// before reading 'status' so a null/partial return can't fatal the save.
			if ( is_array( $app_data ) && isset( $app_data['status'] ) && $cn->check_status( $app_data['status'] ) === 'active' ) {
				// get_app_analytics authenticates with the just-saved credentials via the
				// analytics_app_data shim ( welcome-api.php 'get_analytics' request branch ).
				$cn->settings->set_analytics_app_data( [ 'id' => $new_app_id, 'key' => $new_app_key ] );
				$cn->welcome_api->get_app_analytics( $new_app_id, true, false );
				$cn->settings->set_analytics_app_data( [] );
			}

			// Purge page caches again, after the whole refresh. store_option_keys() purged
			// before the pull, so a page cached while it ran would keep the old app's banner.
			// Unconditional: with force_action false the pull purges at most early (its
			// Autoblocking write comes before its status write), get_app_analytics() rewrites
			// the status after it, and a pull that returns null still needs this. As the
			// classic form, which purges after its pull.
			$cn->settings->configuration_updated( (array) Cookie_Notice_Store::get( 'cookie_notice_options', [], $network ) );
		}

		// What is stored now, read back: the freeze above, the preference guard on the
		// option write and the connection refresh can each leave a value other than the one
		// posted. Always the two blocking switches, so the toggles show the stored row.
		$stored = (array) Cookie_Notice_Store::get( 'cookie_notice_options', [], $network );

		wp_send_json_success( [
			'message'           => __( 'Settings saved.', 'cookie-notice' ),
			'options'           => array_intersect_key( $stored, array_flip( array_merge( self::posted_option_keys(), [ 'message_text', 'app_blocking', 'app_blocking_engine' ] ) ) ),
			'blocking_paused'   => (bool) $cn->threshold_exceeded(),
			'compliance_active' => $cn->settings->compliance_active(),
			// The 3-way status ('' | 'pending' | 'active'), so the admin's not-live wording is current.
			'status'            => $cn->get_status(),
		] );
	}

	/**
	 * Return the stored plugin options (read-only), for the React store to re-read what is
	 * stored after a write whose outcome it could not confirm, or after a config sync that
	 * may have rewritten app_blocking (welcome-api.php base posture sync).
	 *
	 * Exactly what the page bootstrap exposes as cnReactData.options (react_options()),
	 * plus whether the stored Autoblocking preference is paused by the visit limit,
	 * whether Cookie Compliance is active (Settings::compliance_active()) and its status.
	 *
	 * @return void
	 */
	public function get_plugin_options() {
		$this->verify_request();

		$cn = Cookie_Notice();

		wp_send_json_success( [
			'options'           => $cn->settings->react_options(),
			'blocking_paused'   => (bool) $cn->threshold_exceeded(),
			'compliance_active' => $cn->settings->compliance_active(),
			// The 3-way status ('' | 'pending' | 'active'), so the admin's not-live wording is current.
			'status'            => $cn->get_status(),
			// The banner (engine, style, managed): this re-read follows every config pull, so the
			// React store's copy is current after a pull of any kind (store/pluginOptions.js).
			'banner'            => $cn->get_banner_summary(),
		] );
	}

	/**
	 * Top-level option keys a React save POST carried (color_* / bar_opacity → colors).
	 *
	 * @return string[]
	 */
	private static function posted_option_keys() {
		$keys = [];

		foreach ( array_keys( $_POST ) as $key ) {
			if ( strpos( $key, 'color_' ) === 0 || $key === 'bar_opacity' ) {
				$key = 'colors';
			}

			$keys[] = $key;
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Reset the banner and plugin settings to their defaults (React "Reset to defaults").
	 *
	 * Keeps the connection, the React admin, the network and blocking switches and the
	 * plugin's notice bookkeeping (Settings::get_reset_options()); the connection status
	 * and the Privacy Consent settings are separate rows and are not touched. Unlike the
	 * legacy reset, which also disconnects the site and returns it to the legacy screen.
	 *
	 * @return void
	 */
	public function reset_options() {
		$this->verify_request();
		Cookie_Notice()->settings->verify_not_network_managed();

		$cn      = Cookie_Notice();
		$network = Cookie_Notice()->is_network_admin();

		// The kept keys come from the row as stored NOW, not this request's cached copy of
		// it (loaded when this reset request started): otherwise a connection or blocking
		// switch another request saved in between is reset to what it was then. The row is
		// still written whole — a reset must empty lists and drop keys, which writing only
		// changed keys cannot.
		$cn->drop_cached_general_options( $network );

		$current = (array) Cookie_Notice_Store::get( 'cookie_notice_options', [], $network );
		$options = $cn->settings->get_reset_options( $current );

		// The kept app_blocking is the stored one, so the #2272 guard is pointed at it:
		// over quota it would otherwise turn a stored false (the Portal's "off", pulled by
		// another request) back into the true this request started with, and push that.
		// store_options() fires cn_configuration_updated once (caching plugins purge).
		$cn->write_with_stored_posture( $options, $network, function () use ( $cn, $options, $network ) {
			$cn->settings->store_options( $options, $network );
		} );

		$cn->options['general'] = $options;

		// The whole stored row, read back (incl. the kept connection and blocking switches)
		// by the builder of cnReactData.options: React replaces its state with it, so it
		// has exactly the bootstrap's shape.
		wp_send_json_success( [
			'message'           => __( 'Settings restored to defaults.', 'cookie-notice' ),
			'options'           => $cn->settings->react_options(),
			'blocking_paused'   => (bool) $cn->threshold_exceeded(),
			'compliance_active' => $cn->settings->compliance_active(),
			// The 3-way status ('' | 'pending' | 'active'), so the admin's not-live wording is current.
			'status'            => $cn->get_status(),
		] );
	}

	/**
	 * Save the Network Admin's two network settings, Global Settings Override and Global
	 * Cookie — the React counterpart of the legacy network form's save
	 * (Settings::validate_network_options()), which it mirrors step by step.
	 *
	 * Writes those keys (and update_notice, as legacy) to the network row and nothing else;
	 * the rest of the network row is saved by save_options(). Runs get_app_config(), so the
	 * React admin treats it as a config pull (src/admin-react/api/index.js PULL_ACTIONS).
	 *
	 * @return void
	 */
	public function save_network_options() {
		$this->verify_request();

		$cn = Cookie_Notice();

		if ( ! $cn->is_network_admin() || ! $cn->can_write_at_scope( true ) )
			wp_send_json_error( [ 'error' => $cn->network_scope_denied_message() ], 403 );

		$override = ! empty( $_POST['global_override'] );

		// legacy disables Global Cookie on a path-based network (Settings::cn_global_cookie())
		$global_cookie = ! empty( $_POST['global_cookie'] ) && is_subdomain_install();

		// the network row as loaded, not merged with the defaults
		$app_id  = isset( $cn->network_options['general']['app_id'] ) ? (string) $cn->network_options['general']['app_id'] : '';
		$app_key = isset( $cn->network_options['general']['app_key'] ) ? (string) $cn->network_options['general']['app_key'] : '';

		// As legacy: a connected network pulls its config with the override forced on, so the
		// pull reads and writes the network rows. Legacy also refreshes the analytics when the
		// app id CHANGES, which this save never does.
		if ( $app_id !== '' && $app_key !== '' ) {
			$cn->network_options['general']['global_override'] = true;

			$cn->welcome_api->get_app_config( $app_id, true, false );
		} else {
			Cookie_Notice_Store::set( 'cookie_notice_status', $cn->defaults['data'], true );

			// …and the engine it ran: reconnecting, even to the same App ID, is a first connect.
			$cn->forget_banner_engine( true );
		}

		$cn->network_options['general']['global_override'] = $override;

		// Fresh read, these keys only. The write passes through validate_options(), which
		// fires cn_configuration_updated once.
		$cn->update_general_option_keys( [
			'global_override' => $override,
			'global_cookie'   => $global_cookie,
			'update_notice'   => $override && ! $cn->options['general']['update_notice_diss'],
		], true );

		// The network row as stored now (the pull may have written it too), then the status
		// it selects.
		$cn->options['general'] = $cn->network_options['general'] = $cn->multi_array_merge( $cn->defaults['general'], (array) Cookie_Notice_Store::get( 'cookie_notice_options', $cn->defaults['general'], true ) );

		$cn->set_status_data();

		wp_send_json_success( [
			'message' => __( 'Settings saved.', 'cookie-notice' ),
			'options' => [
				'global_override' => (bool) $cn->network_options['general']['global_override'],
				'global_cookie'   => (bool) $cn->network_options['general']['global_cookie'],
			],
			'status'  => $cn->get_status(),
			'banner'  => $cn->get_banner_summary(),
		] );
	}

	/**
	 * Return the active API environment URLs.
	 *
	 * Used by integration tests to verify that the WP instance is targeting
	 * stage APIs before any live API calls are made. Always registered —
	 * does not require CN_DEV_MODE.
	 *
	 * @return void
	 */
	/**
	 * Return conditional display rule values for a given parameter type.
	 *
	 * Called when the user changes the param dropdown in the rule builder.
	 * Returns a flat array of { value, label } objects (and optionally grouped).
	 *
	 * @return void
	 */
	public function get_rule_values() {
		$this->verify_request();

		$param = isset( $_POST['param'] ) ? sanitize_key( $_POST['param'] ) : '';

		if ( ! $param ) {
			wp_send_json_error( [ 'message' => 'Missing param' ] );
		}

		$values = [];

		switch ( $param ) {
			case 'page_type':
				$values = [
					[ 'value' => 'front', 'label' => __( 'Front Page', 'cookie-notice' ) ],
					[ 'value' => 'home', 'label' => __( 'Home Page', 'cookie-notice' ) ],
				];
				break;

			case 'page':
				$pages = get_pages( [ 'post_status' => [ 'publish', 'private', 'future' ] ] );
				$front = (int) get_option( 'page_on_front' );
				$blog  = (int) get_option( 'page_for_posts' );

				foreach ( $pages as $page ) {
					if ( $page->ID === $front || $page->ID === $blog ) {
						continue;
					}
					$values[] = [ 'value' => (string) $page->ID, 'label' => $page->post_title ];
				}
				break;

			case 'post_type':
				$types = get_post_types( [ 'public' => true ], 'objects' );

				foreach ( $types as $type ) {
					$values[] = [ 'value' => $type->name, 'label' => $type->labels->singular_name ];
				}
				break;

			case 'post_type_archive':
				$types = get_post_types( [ 'public' => true, 'has_archive' => true ], 'objects' );

				foreach ( $types as $type ) {
					$values[] = [ 'value' => $type->name, 'label' => $type->labels->singular_name ];
				}
				break;

			case 'user_type':
				$values = [
					[ 'value' => 'logged_in', 'label' => __( 'Logged in', 'cookie-notice' ) ],
					[ 'value' => 'guest', 'label' => __( 'Guest', 'cookie-notice' ) ],
				];
				break;

			case 'taxonomy_archive':
				$taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );

				foreach ( $taxonomies as $taxonomy ) {
					$terms = get_terms( [ 'taxonomy' => $taxonomy->name, 'hide_empty' => false ] );

					if ( is_wp_error( $terms ) || empty( $terms ) ) {
						continue;
					}

					$group = [
						'group' => $taxonomy->labels->name,
						'items' => [],
					];

					foreach ( $terms as $term ) {
						$group['items'][] = [
							'value' => $term->term_id . '|' . $taxonomy->name,
							'label' => $term->name,
						];
					}

					$values[] = $group;
				}
				break;
		}

		wp_send_json_success( [ 'values' => $values ] );
	}

	public function get_api_environment() {
		$this->verify_request();

		$cn = Cookie_Notice();

		wp_send_json_success( [
			'host'              => $cn->get_url( 'host' ),
			'account_api'       => $cn->get_url( 'account_api' ),
			'designer_api'      => $cn->get_url( 'designer_api' ),
			'transactional_api' => $cn->get_url( 'transactional_api' ),
			'widget'            => $cn->get_url( 'widget' ),
		] );
	}

	/**
	 * Build the standardised blocking + config response shape.
	 *
	 * The 7-key blocking object get_config() answers with.
	 *
	 * @param array $blocking Raw blocking option (cookie_notice_app_blocking).
	 * @return array { 'blocking' => [...], 'config' => object }
	 */
	private function build_blocking_response( $blocking ) {
		if ( empty( $blocking ) ) {
			return [
				'blocking' => [
					'providers'                  => [],
					'patterns'                   => [],
					'google_consent_default'     => null,
					'facebook_consent_default'   => null,
					'microsoft_consent_default'  => null,
					'gpc_support'                => false,
					'do_not_track'               => false,
				],
				'config' => new stdClass(),
			];
		}

		$config = isset( $blocking['banner_config'] ) && is_array( $blocking['banner_config'] )
			? $blocking['banner_config']
			: new stdClass();

		return [
			'blocking' => [
				'providers'                  => isset( $blocking['providers'] ) ? $blocking['providers'] : [],
				'patterns'                   => isset( $blocking['patterns'] ) ? $blocking['patterns'] : [],
				'google_consent_default'     => isset( $blocking['google_consent_default'] ) ? $blocking['google_consent_default'] : null,
				'facebook_consent_default'   => isset( $blocking['facebook_consent_default'] ) ? $blocking['facebook_consent_default'] : null,
				'microsoft_consent_default'  => isset( $blocking['microsoft_consent_default'] ) ? $blocking['microsoft_consent_default'] : null,
				'gpc_support'                => ! empty( $blocking['gpc_support'] ),
				'do_not_track'               => ! empty( $blocking['do_not_track'] ),
				'lastUpdated'                => isset( $blocking['lastUpdated'] ) ? $blocking['lastUpdated'] : '',
			],
			'config' => $config,
		];
	}

	/**
	 * Compute consent breakdown (accept/custom/reject rates) from level totals.
	 *
	 * Shared by get_dashboard() and transform_consent_logs().
	 *
	 * @param array $level_totals Associative [ 1 => reject_count, 2 => custom_count, 3 => accept_count ].
	 * @return array { 'total' => int, 'acceptRate' => int, 'customRate' => int, 'rejectRate' => int, 'levelLabels' => array }
	 */
	private function compute_consent_breakdown( $level_totals ) {
		$total = array_sum( $level_totals );

		return [
			'total'      => $total,
			'acceptRate' => $total > 0 ? round( $level_totals[3] / $total * 100 ) : 0,
			'customRate' => $total > 0 ? round( $level_totals[2] / $total * 100 ) : 0,
			'rejectRate' => $total > 0 ? round( $level_totals[1] / $total * 100 ) : 0,
			'levelLabels' => $this->get_level_labels(),
		];
	}

	/**
	 * Read customer-configured consent level labels from cached Designer API data.
	 *
	 * Labels are cached in cookie_notice_app_design by get_app_config() from
	 * DefaultUserTextJSON. Falls back to platform defaults if not yet cached.
	 *
	 * @return array { 'level1' => string, 'level2' => string, 'level3' => string }
	 */
	private function get_level_labels() {
		$cn      = Cookie_Notice();
		$network = $cn->is_network_options();
		$design  = Cookie_Notice_Store::get( 'cookie_notice_app_design', [], $network );

		return [
			'level1' => ! empty( $design['levelNameText_1'] ) ? $design['levelNameText_1'] : 'Private',
			'level2' => ! empty( $design['levelNameText_2'] ) ? $design['levelNameText_2'] : 'Balanced',
			'level3' => ! empty( $design['levelNameText_3'] ) ? $design['levelNameText_3'] : 'Personalized',
		];
	}
}
