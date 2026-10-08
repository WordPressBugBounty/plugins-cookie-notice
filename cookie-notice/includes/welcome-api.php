<?php

// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie_Notice_Welcome_API class.
 *
 * @class Cookie_Notice_Welcome_API
 */
class Cookie_Notice_Welcome_API {

	/** Seconds a sign-in waits for its second step. */
	const LOGIN_PENDING_TTL = 600;

	/** Wrong codes allowed before the account is locked out of this plugin's sign-in. */
	const LOGIN_MAX_ATTEMPTS = 5;

	/** Seconds the lock-out lasts, and the window the wrong-code count is kept over. */
	const LOGIN_LOCKOUT_TTL = 900;

	/** Seconds between two emailed codes. */
	const LOGIN_RESEND_INTERVAL = 60;

	/** Seconds request() waits for the platform; the longest one call can hold a verification lock. */
	const REQUEST_TIMEOUT = 60;

	/**
	 * Seconds a verification holds its lock; a request that died cannot keep it longer.
	 *
	 * LONGER than the request it waits on: a lock that expired while its own call was still
	 * running would let a second verification in, and both would pass the wrong-code count.
	 * The margin covers redirects and the work either side of the call.
	 */
	const LOGIN_LOCK_TTL = self::REQUEST_TIMEOUT + 30;

	/** What 'login_app' is sent to create a new app for this site instead of using one of the account's. */
	const LOGIN_NEW_APP = '__new__';

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'init', [ $this, 'check_cron' ] );
		add_action( 'cookie_notice_get_app_analytics', [ $this, 'get_app_analytics' ] );
		add_action( 'cookie_notice_get_app_config', [ $this, 'get_app_config' ] );
		add_action( 'wp_ajax_cn_api_request', [ $this, 'api_request' ] );

		// ── Begin base posture push registration
		//
		// Registered on the option WRITE so that the hook which fires is itself the target
		// derivation — see the block comment above stage_base_posture_site(). Which method
		// is bound to which hook IS the scope decision, so this pairing is extracted and
		// driven by tests/unit/base-posture-push.php rather than restated there: a test that
		// hardcoded it would keep passing with the two swapped, and a swap is the escalation.
		//
		// The accepted-argument counts differ because core emits the two actions with
		// different signatures — ( $old, $value, $option ) and ( $option, $value, $old,
		// $network_id ). Registering either with the default of 1 would hand the callback a
		// null $value.
		add_action( 'update_option_cookie_notice_options', [ $this, 'stage_base_posture_site' ], 10, 2 );
		add_action( 'update_site_option_cookie_notice_options', [ $this, 'stage_base_posture_network' ], 10, 3 );
		add_action( 'admin_init', [ $this, 'retry_base_posture_push' ] );
		// ── End base posture push registration

		// React write hooks — only register when ui_mode is "react" (#2267), read from the
		// row the page renders from (Settings::rendered_ui_mode()).
		if ( Cookie_Notice()->settings->rendered_ui_mode() === 'react' ) {
			add_action( 'wp_ajax_cn_react_update_design', [ $this, 'react_update_design' ] );
			add_action( 'wp_ajax_cn_react_save_banner_style', [ $this, 'react_save_banner_style' ] );
			add_action( 'wp_ajax_cn_react_apply_languages', [ $this, 'react_apply_languages' ] );
		}
	}

	/**
	 * Ajax API request.
	 *
	 * @return void
	 */
	public function api_request() {
		// check capabilities
		if ( ! current_user_can( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) ) )
			wp_die( __( 'You do not have permission to access this page.', 'cookie-notice' ) );

		// check main nonce
		if ( ! check_ajax_referer( 'cookie-notice-welcome', 'nonce' ) )
			wp_die( __( 'You do not have permission to access this page.', 'cookie-notice' ) );

		// get request
		$request = isset( $_POST['request'] ) ? sanitize_key( $_POST['request'] ) : '';

		// no valid request?
		if ( ! in_array( $request, [ 'register', 'login', 'configure', 'select_plan', 'payment', 'get_bt_init_token', 'use_license', 'sync_config', 'login_code', 'login_code_resend', 'login_app' ], true ) )
			wp_die( __( 'You do not have permission to access this page.', 'cookie-notice' ) );

		$special_actions = [ 'register', 'login', 'login_code', 'login_app', 'configure', 'payment' ];

		// payment nonce
		if ( $request === 'payment' )
			$nonce = isset( $_POST['cn_payment_nonce'] ) ? sanitize_key( $_POST['cn_payment_nonce'] ) : '';
		// special nonce
		elseif ( in_array( $request, $special_actions, true ) )
			$nonce = isset( $_POST['cn_nonce'] ) ? sanitize_key( $_POST['cn_nonce'] ) : '';

		// check additional nonce
		if ( in_array( $request, $special_actions, true ) && ! wp_verify_nonce( $nonce, 'cn_api_' . $request ) )
			wp_die( __( 'You do not have permission to access this page.', 'cookie-notice' ) );

		// A site whose settings the network manages: every request here connects (register,
		// login), pays for (payment, use_license, get_bt_init_token, select_plan) or rewrites
		// (configure, sync_config) the NETWORK's app, whose id this request holds. Legacy greys
		// that form and loads no welcome flow there (Cookie_Notice_Welcome::welcome()), so
		// nothing legitimate reaches this. Ahead of every case, before any remote call or write.
		Cookie_Notice()->settings->verify_not_network_managed();

		$errors = [];
		$response = false;

		// get main instance
		$cn = Cookie_Notice();

		// get site language
		$locale = get_locale();
		$locale_code = explode( '_', $locale );

		// check network
		$network = $cn->is_network_admin();

		// get app token data
		if ( $network )
			$data_token = get_site_transient( 'cookie_notice_app_token' );
		else
			$data_token = get_transient( 'cookie_notice_app_token' );

		$admin_email = ! empty( $data_token->email ) ? $data_token->email : '';
		$app_id = $cn->options['general']['app_id'];

		// ── Begin shared-app request gate ────────────────────────────────────────
		// The capability proved above is the filtered manage_options, which every subsite
		// administrator holds. $app_id above may name the network's shared app — see
		// Cookie_Notice::is_network_shared_app() for the two ways that happens, only one of
		// which is global_override being on right now.
		//
		// These requests MUTATE that record. 'configure' PATCHes it with config taken
		// straight from $_POST (cn_laws[], uiBlocking, onScroll/onClick implied consent, the
		// gpc modes), authenticating with the app's own key, so it succeeds: a subsite admin
		// clicking Protection → Privacy Laws → Save laws could deselect gdpr for every site
		// on the network. The subscription three are gated on the same footing; 'select_plan'
		// is currently a bare break and changes nothing, and is listed so that stays true by
		// gate rather than by accident.
		//
		// GATED HERE, ABOVE EVERY CASE, because the PATCH is the network-visible change: a
		// gate after it would leave the platform changed and only refuse the local mirror,
		// which is worse than none — the admin UI then shows stale config while the live
		// banner serves the new one. An earlier revision of this fix made exactly that
		// mistake in the react handlers.
		//
		// 'register' and 'login' are deliberately NOT here: they create or attach a NEW app
		// rather than mutating the shared one, and a subsite administrator's result is
		// written site-scoped because is_network_admin() is vetted.
		//
		// This list is a convenience, not the guarantee. request() gates every mutating call
		// against the shared app at the point it is issued, so a request type added here and
		// forgotten is still refused — which is the whole reason that choke point exists.
		if ( in_array( $request, [ 'configure', 'select_plan', 'payment', 'use_license' ], true )
			&& $cn->is_network_shared_app( $app_id ) && ! $cn->can_write_at_scope( true ) )
			wp_send_json_error( [ 'error' => $cn->network_scope_denied_message() ], 403 );
		// ── End shared-app request gate ──────────────────────────────────────────

		$params = [];

		switch ( $request ) {
			case 'use_license':
				$subscriptionID = isset( $_POST['subscriptionID'] ) ? (int) $_POST['subscriptionID'] : 0;

				// security: validate subscriptionID is in the session allowlist set during login
				$allowed_subs = Cookie_Notice_Store::get_transient( 'cookie_notice_app_subscriptions', $network );

				$allowed_ids = is_array( $allowed_subs ) ? array_column( $allowed_subs, 'subscriptionid' ) : [];

				if ( ! in_array( $subscriptionID, array_map( 'intval', $allowed_ids ), true ) ) {
					$response = [ 'error' => esc_html__( 'Invalid subscription.', 'cookie-notice' ) ];
					break;
				}

				$result = $this->request(
					'assign_subscription',
					[
						'AppID'				=> $app_id,
						'subscriptionID'	=> $subscriptionID
					]
				);

				// require an explicit success signal; anything else is an error
				if ( empty( $result->success ) || $result->success !== true ) {
					$response = [ 'error' => ! empty( $result->message ) ? $result->message : esc_html__( 'License assignment failed.', 'cookie-notice' ) ];
					break;
				}

				// update WP subscription tier to 'pro' (mirrors the payment case)
				$status_data = $cn->defaults['data'];

				$status_data = Cookie_Notice_Store::get( 'cookie_notice_status', $status_data, $network );
				$status_data['subscription'] = 'pro';

				// get activation timestamp
				$timestamp = $cn->get_cc_activation_datetime();

				// update activation timestamp only for new cookie compliance activations
				$status_data['activation_datetime'] = $timestamp === 0 ? time() : $timestamp;

				Cookie_Notice_Store::set( 'cookie_notice_status', $status_data, $network );

				// License assignment (use_license): do not CLEAR setup_wizard_complete on
				// existing sites — that would send already-configured domains back to
				// FirstRunSetup and make them appear as Free on reload (#1893).
				//
				// For brand-new domains the option was never written, so the wizard would
				// fire unnecessarily for existing subscribers assigning a new slot.
				// Set the flag only if it hasn't been set before — new domain case.
				if ( ! Cookie_Notice_Store::get( 'cookie_notice_setup_wizard_complete', false, $network ) ) {
					Cookie_Notice_Store::set( 'cookie_notice_setup_wizard_complete', true, $network );
				}

				$response = $result;

				break;

			case 'get_bt_init_token':
				// The braintree client-token route authenticates with the Bearer JWT
				// held in the cookie_notice_app_token transient (set only at
				// register/login, DAY_IN_SECONDS TTL, never refreshed). When that
				// transient has expired/vanished, the upstream call returns a
				// guaranteed 401 and $response would fall through as bare false —
				// which the React PaymentStep shows as a dead-end error. Detect the
				// missing session here and signal it so the UI can recover via
				// re-login instead. Non-error shape ('status', not 'error') so it
				// does not trip the frontend apiRequest() error-throw channel.
				if ( empty( $data_token->token ) ) {
					$response = [ 'status' => 'session_expired' ];
					break;
				}

				$result = $this->request( 'get_token' );

				// is token available?
				if ( ! empty( $result->token ) )
					$response = [ 'token' => $result->token ];
				// token present but upstream returned none — could be an expired
				// JWT (rejected by the API) rather than an outright-missing one.
				elseif ( ! empty( $result->message ) && stripos( $result->message, 'token' ) !== false )
					$response = [ 'status' => 'session_expired' ];
				// any other upstream failure is a genuine error, not a session issue.
				else
					$response = [ 'error' => esc_html__( 'Unable to initialize payment. Please try again later.', 'cookie-notice' ) ];
				break;

			case 'payment':
				$error = [ 'error' => esc_html__( 'Unexpected error occurred. Please try again later.', 'cookie-notice' ) ];

				// empty data?
				if ( empty( $_POST['payment_nonce'] ) || empty( $_POST['plan'] ) || empty( $_POST['method'] ) ) {
					$response = $error;
					break;
				}

				// validate plan and payment method
				$available_plans = [
					'compliance_monthly_notrial',
					'compliance_monthly_5',
					'compliance_monthly_10',
					'compliance_monthly_20',
					'compliance_yearly_notrial',
					'compliance_yearly_5',
					'compliance_yearly_10',
					'compliance_yearly_20'
				];

				$available_payment_methods = [
					'credit_card',
					'paypal'
				];

				$plan = sanitize_key( $_POST['plan'] );

				if ( ! in_array( $_POST['plan'], $available_plans, true ) )
					$plan = false;

				$method = sanitize_key( $_POST['method'] );

				if ( ! in_array( $_POST['method'], $available_payment_methods, true ) )
					$method = false;

				// valid plan and payment method?
				if ( empty( $plan ) || empty( $method ) ) {
					$response = [ 'error' => esc_html__( 'Empty plan or payment method data.', 'cookie-notice' ) ];
					break;
				}

				$result = $this->request(
					'get_customer',
					[
						'AppID'		=> $app_id,
						'PlanId'	=> $plan
					]
				);

				// ── Begin get_customer unreachable guard ─────────────────────────
				// Same family as the list_apps -> app_create defect. `! empty( $result->id )`
				// is false on an array, so an UNREACHABLE platform was read as "this
				// customer does not exist" and the else branch created one — a second
				// Braintree customer record against the account, non-idempotent and not
				// undoable from here, off a transient blip.
				//
				// "Did not answer" and "answered no" are different, and only one of them
				// justifies a create.
				if ( ! is_object( $result ) ) {
					$response = [
						'error' => __( 'We could not reach Cookie Compliance to look up your billing details. Please try again.', 'cookie-notice' )
					];
					break;
				}
				// ── End get_customer unreachable guard ───────────────────────────

				// user found?
				if ( ! empty( $result->id ) ) {
					$customer = $result;
				// create user
				} else {
					$result = $this->request(
						'create_customer',
						[
							'AppID'					=> $app_id,
							'AdminID'				=> $admin_email, // remove later - AdminID from API response
							'PlanId'				=> $plan,
							'paymentMethodNonce'	=> sanitize_key( $_POST['payment_nonce'] )
						]
					);

					if ( ! empty( $result->success ) )
						$customer = $result->customer;
					else
						$customer = $result;
				}

				// user created/received?
				if ( empty( $customer->id ) ) {
					$response = [ 'error' => esc_html__( 'Unable to create customer data.', 'cookie-notice' ) ];
					break;
				}

				// selected payment method
				$payment_method = false;

				// get payment identifier (email or 4 digits)
				$identifier = isset( $_POST['cn_payment_identifier'] ) ? sanitize_text_field( $_POST['cn_payment_identifier'] ) : '';

				// customer available payment methods
				$payment_methods = ! empty( $customer->paymentMethods ) ? $customer->paymentMethods : [];

				// try to find payment method
				if ( ! empty( $payment_methods ) && is_array( $payment_methods ) ) {
					foreach ( $payment_methods as $pm ) {
						// paypal
						if ( isset( $pm->email ) && $pm->email === $identifier )
							$payment_method = $pm;
						// credit card
						elseif ( isset( $pm->last4 ) && $pm->last4 === $identifier )
							$payment_method = $pm;
					}
				}

				// if payment method was not identified, create it
				if ( ! $payment_method ) {
					$result = $this->request(
						'create_payment_method',
						[
							'AppID'					=> $app_id,
							'paymentMethodNonce'	=> sanitize_key( $_POST['payment_nonce'] )
						]
					);

					// payment method created successfully?
					if ( ! empty( $result->success ) ) {
						$payment_method = $result->paymentMethod;
					} else {
						$response = [ 'error' => esc_html__( 'Unable to create payment mehotd.', 'cookie-notice' ) ];
						break;
					}
				}

				if ( ! isset( $payment_method->token ) ) {
					$response = [ 'error' => esc_html__( 'No payment method token.', 'cookie-notice' ) ];
					break;
				}

				// @todo: check if subscription exists
				$subscription = $this->request(
					'create_subscription',
					[
						'AppID'					=> $app_id,
						'PlanId'				=> $plan,
						'paymentMethodToken'	=> $payment_method->token
					]
				);

				// ── Begin create_subscription success gate ───────────────────────
				// THE MONEY PATH. Nothing here may report an upgrade this code did not
				// watch the platform confirm, and nothing here may tell the customer what
				// happened to their card — because this is the call that moves it.
				//
				// Which call takes the money: create_customer and create_payment_method
				// only VAULT (Account API braintree.controller.ts — gateway.customer.create
				// and gateway.paymentMethod.create, neither with verifyCard). It is
				// gateway.subscription.create, the call above, that bills — its result
				// carries subscription.transactions[0], which licensePayment.service.ts
				// persists as the transaction id and amount.
				//
				// ONE POSITIVE GATE, not a list of negative ones. Every failure shape this
				// endpoint can produce fails a `success !== true` test; each of them slips
				// past a `! empty( ->error )` test, and each used to arrive here as a
				// completed upgrade:
				//
				//   array ['error'=>…]      transport error, 502, Cloudflare page, WAF
				//                           block, any text/html body. Property reads NULL.
				//   Braintree ErrorResult   A DECLINED CARD. The controller's failure arm
				//                           is res.send(result) — the RAW Braintree result,
				//                           which carries success:false, errors, message
				//                           and NO error property at all. Insufficient
				//                           funds or do-not-honor is the ordinary case here
				//                           and it is far more common than any outage.
				//   thrown gateway error    .catch(err => res.send(err)); an Error
				//                           serialises to {}.
				//
				// The three sibling Braintree calls in this same case — assign_subscription,
				// create_customer, create_payment_method — all already gate on ->success.
				// This was the only one left asking whether it had failed instead.
				$subscription_ok = is_object( $subscription ) && ! empty( $subscription->success ) && $subscription->success === true;

				if ( ! $subscription_ok ) {
					// Prefer what the platform said, when it said anything. An ErrorResult
					// carries ->message; the framework's own errors carry ->error.
					if ( is_object( $subscription ) && ! empty( $subscription->message ) )
						$error = $subscription->message;
					elseif ( is_object( $subscription ) && ! empty( $subscription->error ) )
						$error = $subscription->error;
					else
						// We never got an answer, so we do not have one to give. Do NOT say the
						// card was not charged: subscription.create bills immediately, and a
						// timeout AFTER the gateway wrote is exactly the case this branch
						// catches — the charge may well exist. And do NOT say "try again":
						// the endpoint has no idempotency key and no existing-subscription
						// lookup (see the standing @todo above this request), so a retry is a
						// SECOND subscription and a SECOND charge, neither undoable from here.
						$error = __( 'We could not confirm your upgrade with Cookie Compliance — the connection failed while we were waiting for an answer, so we do not know whether the payment went through. Please check your email for a receipt and contact support before trying again, so you are not charged twice.', 'cookie-notice' );

					$response = [ 'error' => $error ];
					break;
				}
				// ── End create_subscription success gate ─────────────────────────

				$status_data = $cn->defaults['data'];

				// update app status
				$status_data = Cookie_Notice_Store::get( 'cookie_notice_status', $status_data, $network );
				$status_data['subscription'] = 'pro';

				// get activation timestamp
				$timestamp = $cn->get_cc_activation_datetime();

				// update activation timestamp only for new cookie compliance activations
				$status_data['activation_datetime'] = $timestamp === 0 ? time() : $timestamp;

				Cookie_Notice_Store::set( 'cookie_notice_status', $status_data, $network );

				// Only show FirstRunSetup if the user has never completed it.
				// Free→Pro upgrades: the wizard was already done — don't clear the flag
				// or they'll see FirstRunSetup and remain appearing as Free on reload.
				// New activations (flag not set): leave it unset so the wizard fires.
				// (no-op: delete_option is intentionally removed for the upgrade path)

				$response = $app_id;
				break;

			case 'register':
				// check terms
				$terms = isset( $_POST['terms'] );

				// no terms?
				if ( ! $terms ) {
					$response = [ 'error' => esc_html__( 'Please accept the Terms of Service to proceed.', 'cookie-notice' ) ];
					break;
				}

				// check email
				$email = isset( $_POST['email'] ) ? is_email( $_POST['email'] ) : false;

				// empty email?
				if ( ! $email ) {
					$response = [ 'error' => esc_html__( 'Email is not allowed to be empty.', 'cookie-notice' ) ];
					break;
				}

				// check passwords
				$pass = ! empty( $_POST['pass'] ) ? stripslashes( $_POST['pass'] ) : '';
				$pass2 = ! empty( $_POST['pass2'] ) ? stripslashes( $_POST['pass2'] ) : '';

				// empty password?
				if ( ! $pass || ! is_string( $pass ) ) {
					$response = [ 'error' => esc_html__( 'Password is not allowed to be empty.', 'cookie-notice' ) ];
					break;
				}

				// invalid password?
				if ( preg_match( '/^(?=.*[A-Z])(?=.*\d)[\w !"#$%&\'()*\+,\-.\/:;<=>?@\[\]^\`\{\|\}\~\\\\]{8,}$/', $pass ) !== 1 ) {
					$response = [ 'error' => esc_html__( 'The password contains illegal characters or does not meet the conditions.', 'cookie-notice' ) ];
					break;
				}

				// no match?
				if ( $pass !== $pass2 ) {
					$response = [ 'error' => esc_html__( 'Passwords do not match.', 'cookie-notice' ) ];
					break;
				}

				$params = [
					'AdminID'	=> $email,
					'Password'	=> $pass,
					'Language'	=> ! empty( $_POST['language'] ) ? sanitize_key( $_POST['language'] ) : 'en'
				];

				$response = $this->request( 'register', $params );

				// ── Begin register request unreachable guard ─────────────────────
				// Neither check below fires on an array, so control reached the login
				// POST ~20 lines down and sent the customer's email and password for an
				// account we have no idea whether we created. Two bad endings: the account
				// DID get made (the gateway timed out after the write) and the flow
				// continues as though register had answered, or it did not and the
				// customer is shown login's credentials error for what was a register
				// transport failure.
				//
				// This is the one of the six earlier-audited sites whose "reaches an
				// array-safe bail and writes nothing" reading was wrong: it makes a
				// further outbound request before any bail.
				if ( ! is_object( $response ) ) {
					$response = (object) [
						'error' => __( 'We could not reach Cookie Compliance to create your account. Please try again.', 'cookie-notice' )
					];
					break;
				}
				// ── End register request unreachable guard ───────────────────────

				// errors?
				if ( ! empty( $response->error ) )
					break;

				// errors?
				if ( ! empty( $response->message ) ) {
					// normalize duplicate-email to machine-readable key for React recovery UI
					if ( ! empty( $response->i18n_msg ) && strpos( $response->i18n_msg, 'api_account_status_' ) === 0 )
						$response = [ 'error' => 'email_exists' ];
					else
						$response->error = $response->message;

					break;
				}

				// ok, so log in now
				$params = [
					'AdminID'	=> $email,
					'Password'	=> $pass
				];

				$response = $this->request( 'login', $params );

				// errors?
				if ( ! empty( $response->error ) )
					break;

				// errors?
				if ( ! empty( $response->message ) ) {
					$response->error = $response->message;
					break;
				}

				// token in response?
				if ( empty( $response->data->token ) ) {
					$response = [ 'error' => esc_html__( 'Unexpected error occurred. Please try again later.', 'cookie-notice' ) ];
					break;
				}

				// A first-factor-only token is not a session: never stored, never used. A new
				// account has no second step, so this is not expected here; it is refused all the
				// same, because this answer is the only thing standing between a password and an
				// account's apps.
				if ( $this->is_partial_login_response( $response->data ) ) {
					$this->forget_app_token( $network );
					$response = [ 'error' => esc_html__( 'Unexpected error occurred. Please try again later.', 'cookie-notice' ) ];
					break;
				}

				// set token
				if ( $network )
					set_site_transient( 'cookie_notice_app_token', $response->data, DAY_IN_SECONDS );
				else
					set_transient( 'cookie_notice_app_token', $response->data, DAY_IN_SECONDS );

				// multisite?
				if ( is_multisite() ) {
					switch_to_blog( 1 );
					$site_title = get_bloginfo( 'name' );
					$site_url = network_site_url();
					$site_description = get_bloginfo( 'description' );
					restore_current_blog();
				} else {
					$site_title = get_bloginfo( 'name' );
					$site_url = get_home_url();
					$site_description = get_bloginfo( 'description' );
				}

				// create new app, no need to check existing
				$params = [
					'DomainName'	=> $site_title,
					'DomainUrl'		=> $site_url
				];

				if ( ! empty( $site_description ) )
					$params['DomainDescription'] = $site_description;

				$response = $this->request( 'app_create', $params );

				// ── Begin register created-here flag ─────────────────────────────
				// DEC-022: only an app THIS request created starts on the New engine. Read off
				// the RAW create response, before the reuse branch below swaps an existing app
				// into $response as though it had just been created — switching that one would
				// move a live site's engine without its owner asking. The AppID is carried, not
				// re-read later, so the switch can only ever name the app created here.
				$created_app_id = is_object( $response ) && empty( $response->error ) && empty( $response->message ) && ! empty( $response->data->AppID ) ? (string) $response->data->AppID : '';
				// ── End register created-here flag ───────────────────────────────

				// If domain already registered, fetch existing app via list_apps and reuse it.
				if ( ! empty( $response->i18n_msg ) && $response->i18n_msg === 'domain_url_already_exist' ) {
					$list_response = $this->request( 'list_apps' );

					$existing_app = null;
					$site_normalized = strtolower( preg_replace( '/^www\./', '', trim( str_replace( [ 'http://', 'https://' ], '', $site_url ), '/' ) ) );

					if ( ! empty( $list_response->data ) && is_array( $list_response->data ) ) {
						foreach ( $list_response->data as $app ) {
							$app_normalized = strtolower( preg_replace( '/^www\./', '', trim( str_replace( [ 'http://', 'https://' ], '', $app->DomainUrl ?? '' ), '/' ) ) );
							if ( $app_normalized === $site_normalized ) {
								$existing_app = $app;
								break;
							}
						}

						// A site in a subfolder is stored by its host alone: the same match, and the
						// same refusals, as sign-in ( find_app_on_site_host() ).
						if ( ! $existing_app )
							$existing_app = $this->find_app_on_site_host( $list_response->data, $site_url );
					}

					if ( ! empty( $existing_app->AppID ) && ! empty( $existing_app->SecretKey ) ) {
						$response = (object) [ 'data' => $existing_app ];
					} else {
						$response->error = $response->message;
						break;
					}
				}

				// errors?
				if ( ! empty( $response->error ) || ( ! empty( $response->message ) && empty( $response->data ) ) ) {
					if ( empty( $response->error ) ) $response->error = $response->message;
					break;
				}

				// data in response?
				if ( empty( $response->data->AppID ) || empty( $response->data->SecretKey ) ) {
					$response = [ 'error' => esc_html__( 'Unexpected error occurred. Please try again later.', 'cookie-notice' ) ];
					break;
				} else {
					$app_id = $response->data->AppID;
					$secret_key = $response->data->SecretKey;
				}

				// DEC-022: a brand-new app starts on the New engine. Before the credentials below
				// are stored and before the first publish, so every config pull of this app —
				// the first one included — already reads the New engine. Never fails signup.
				if ( $created_app_id !== '' )
					$this->start_new_app_on_v2( $created_app_id );

				// update options: app id and secret key. In memory first: request() signs the
				// calls below with $cn->options['general']'s credentials. Stored as these keys
				// only, over a fresh read of the row (update_general_option_keys()).
				$credentials = [ 'app_id' => $app_id, 'app_key' => $secret_key ] + ( $network ? [ 'global_override' => true ] : [] );

				$cn->options['general'] = wp_parse_args( $credentials, $cn->options['general'] );

				$cn->update_general_option_keys( $credentials, $network );

				// get options
				if ( $network )
					$app_config = get_site_transient( 'cookie_notice_app_quick_config' );
				else
					$app_config = get_transient( 'cookie_notice_app_quick_config' );

				// create quick config
				$params = ! empty( $app_config ) && is_array( $app_config ) ? $app_config : [];

				// cast to objects
				if ( $params ) {
					$new_params = [];

					foreach ( $params as $key => $array ) {
						$object = new stdClass();

						foreach ( $array as $subkey => $value ) {
							$new_params[$key] = $object;
							$new_params[$key]->{$subkey} = $value;
						}
					}

					$params = $new_params;
				}

				$params['AppID'] = $app_id;

				// @todo When mutliple default languages are supported
				$params['DefaultLanguage'] = 'en';

				if ( ! array_key_exists( 'text', $params ) )
					$params['text'] = new stdClass();

				// add privacy policy url
				$params['text']->privacyPolicyUrl = get_privacy_policy_url();

				// add translations if needed
				if ( $locale_code[0] !== 'en' )
					$params['Languages'] = [ $locale_code[0] ];

				$response = $this->request( 'quick_config', $params );
				$status_data = $cn->defaults['data'];

				// ── Begin register unreachable guard ─────────────────────────────
				// The same array-vs-object discriminator the login path carries, on the
				// sibling flow. The consequence here is milder and worth stating plainly
				// rather than overstating: unlike login, register does NOT fall through
				// to a success return — the error array survives to wp_json_encode() at
				// the end of this handler, so the customer is told something failed.
				// What the guard buys is that the two failure kinds stop being told
				// apart by accident, and that PHP 8 stops emitting "attempt to read
				// property on array" on every blip. Registration writes over a row that
				// predates any connection, so there is no healthy status to clobber.
				if ( ! is_object( $response ) ) {
					$response = (object) [
						'error' => __( 'We could not reach Cookie Compliance to finish setting up this site. Please try again.', 'cookie-notice' )
					];
					break;
				}
				// ── End register unreachable guard ───────────────────────────────

				if ( $response->status === 200 ) {
					// notify publish app
					$params = [
						'AppID'	=> $app_id
					];

					$response = $this->request( 'notify_app', $params );

					// ── Begin register notify_app guard ──────────────────────
					// Its own request, so its own guard — quick_config succeeding
					// says nothing about this one reaching the platform, and this
					// is the branch that writes status='active'.
					if ( ! is_object( $response ) ) {
						$response = (object) [
							'error' => __( 'We could not reach Cookie Compliance to activate this site. Please try again.', 'cookie-notice' )
						];
						break;
					}
					// ── End register notify_app guard ────────────────────────

					if ( $response->status === 200 ) {
						$response = true;
						$status_data['status'] = 'active';
						$status_data['activation_datetime'] = time();

						// update app status
						if ( $network )
							update_site_option( 'cookie_notice_status', $status_data );
						else
							update_option( 'cookie_notice_status', $status_data );

						// Auto-populate tracker/blocking config from Designer API (#2130).
						$this->get_app_config( $app_id, true, true );
					} else {
						$status_data['status'] = 'pending';

						// update app status
						if ( $network )
							update_site_option( 'cookie_notice_status', $status_data );
						else
							update_option( 'cookie_notice_status', $status_data );

						// errors?
						if ( ! empty( $response->error ) )
							break;

						// errors?
						if ( ! empty( $response->message ) ) {
							$response->error = $response->message;
							break;
						}
					}
				} else {
					$status_data['status'] = 'pending';

					// update app status
					if ( $network )
						update_site_option( 'cookie_notice_status', $status_data );
					else
						update_option( 'cookie_notice_status', $status_data );

					// errors?
					if ( ! empty( $response->error ) ) {
						$response->error = $response->error;
						break;
					}

					// errors?
					if ( ! empty( $response->message ) ) {
						$response->error = $response->message;
						break;
					}
				}

				break;

			case 'login':
				// check email
				$email = isset( $_POST['email'] ) ? is_email( $_POST['email'] ) : false;

				// invalid email?
				if ( ! $email ) {
					$response = [ 'error' => esc_html__( 'Email is not allowed to be empty.', 'cookie-notice' ) ];
					break;
				}

				// check password
				$pass = ! empty( $_POST['pass'] ) ? preg_replace( '/[^\w !"#$%&\'()*\+,\-.\/:;<=>?@\[\]^\`\{\|\}\~\\\\]/', '', $_POST['pass'] ) : '';

				// empty password?
				if ( ! $pass ) {
					$response = [ 'error' => esc_html__( 'Password is not allowed to be empty.', 'cookie-notice' ) ];
					break;
				}

				$params = [
					'AdminID'	=> $email,
					'Password'	=> $pass
				];

				$response = $this->request( $request, $params );

				// errors?
				if ( ! empty( $response->error ) )
					break;

				// errors?
				if ( ! empty( $response->message ) ) {
					$response->error = $response->message;
					break;
				}

				// token in response?
				if ( empty( $response->data->token ) ) {
					$response = [ 'error' => esc_html__( 'Unexpected error occurred. Please try again later.', 'cookie-notice' ) ];
					break;
				}

				// ── Begin partial login token refusal ────────────────────────────
				// An account with two-step verification answers the password with a PARTIAL token:
				// data.partial is true and the JWT carries Partial: true. It proves the password
				// and nothing else, yet it is a valid Bearer for app/list and app/add, so storing
				// and using it connected a site — and created apps — on the password alone.
				// Nothing below runs for it: not stored, not used for a single call, and any full
				// token already held is dropped so none from an earlier sign-in outlives this one.
				// The partial token is kept server-side for the code step and never sent on.
				if ( $this->is_partial_login_response( $response->data ) ) {
					$this->forget_app_token( $network );
					$response = $this->begin_login_code( $response->data, $network );
					break;
				}
				// ── End partial login token refusal ──────────────────────────────

				// set token
				if ( $network )
					set_site_transient( 'cookie_notice_app_token', $response->data, DAY_IN_SECONDS );
				else
					set_transient( 'cookie_notice_app_token', $response->data, DAY_IN_SECONDS );

				$response = $this->finish_login( $network );
				break;

			// The second step of a sign-in: the code the account's second factor produced. Its own
			// nonce ( special action ), on top of the main one. Everything it does is in
			// login_code(); the browser never holds the partial token.
			case 'login_code':
				$response = $this->login_code( $network );
				break;

			// Send the email code again.
			case 'login_code_resend':
				$response = $this->login_code_resend( $network );
				break;

			// The owner's choice of app, when the account has apps and none of them is this site's.
			// Its own nonce ( special action ). Everything it does is in login_app().
			case 'login_app':
				$response = $this->login_app( $network );
				break;

			case 'configure':
				// ── Begin Do Not Sell link ───────────────────────────────────────
				// cn_dont_sell_url: the banner's Do Not Sell link (text.dontSellUrl), sent by
				// the law editors only when the admin changed it. Read only while CCPA or
				// Other U.S. State Laws is among the posted laws; empty leaves the platform's
				// link as it is. Validated HERE, before anything below writes (the laws, the
				// WordPress options, the transient, the PATCH), so a bad address saves nothing.
				$posted_laws = isset( $_POST['cn_laws'] ) && is_array( $_POST['cn_laws'] ) ? array_map( 'sanitize_text_field', $_POST['cn_laws'] ) : [];
				$dns_raw     = isset( $_POST['cn_dont_sell_url'] ) && is_string( $_POST['cn_dont_sell_url'] ) ? trim( wp_unslash( $_POST['cn_dont_sell_url'] ) ) : '';
				$dns_url     = '';

				if ( $dns_raw !== '' && array_intersect( [ 'ccpa', 'otherus' ], $posted_laws ) ) {
					// Checked on what was TYPED: esc_url_raw() puts http:// in front of text with no
					// scheme ('do not sell' -> 'http://do%20not%20sell'), so its output can pass for an address.
					$dns_host = strtolower( (string) wp_parse_url( $dns_raw, PHP_URL_HOST ) );

					if ( preg_match( '~^https?://~i', $dns_raw ) && ! preg_match( '~\s~', $dns_raw ) && ( $dns_host === 'localhost' || strpos( $dns_host, '.' ) !== false ) )
						$dns_url = esc_url_raw( $dns_raw, [ 'http', 'https' ] );

					if ( $dns_url === '' ) {
						$response = [
							'error' => __( 'The Do Not Sell link must be a full web address starting with https:// or http://. Nothing was saved.', 'cookie-notice' ),
							'field' => 'cn_dont_sell_url',
						];
						break;
					}
				}
				// ── End Do Not Sell link (merged into $options['text'] after the field loop) ──

				$fields = [
					'cn_position',
					'cn_color_primary',
					'cn_color_background',
					'cn_color_border',
					'cn_color_text',
					'cn_color_heading',
					'cn_color_button_text',
					'cn_laws',
					'cn_naming',
					'cn_on_scroll',
					'cn_on_click',
					'cn_ui_blocking',
					'cn_revoke_consent'
				];

				$options = [];

				// loop through potential config form fields
				foreach ( $fields as $field ) {
					switch ( $field ) {
						case 'cn_position':
							// sanitize position
							$position = isset( $_POST[$field] ) ? sanitize_key( $_POST[$field] ) : '';

							// valid position? Only include if explicitly provided — omitting lets
							// patch_by_app deep-merge preserve the portal's current value (#ISSUE-1).
							if ( in_array( $position, [ 'bottom', 'top', 'left', 'right', 'center' ], true ) )
								$options['design']['position'] = $position;
							break;

						case 'cn_color_primary':
							$color = isset( $_POST[$field] ) ? sanitize_hex_color( $_POST[$field] ) : '';

							if ( ! empty( $color ) )
								$options['design']['primaryColor'] = $color;
							break;

						case 'cn_color_background':
							$color = isset( $_POST[$field] ) ? sanitize_hex_color( $_POST[$field] ) : '';

							if ( ! empty( $color ) )
								$options['design']['bannerColor'] = $color;
							break;

						case 'cn_color_border':
							$color = isset( $_POST[$field] ) ? sanitize_hex_color( $_POST[$field] ) : '';

							if ( ! empty( $color ) )
								$options['design']['borderColor'] = $color;
							break;

						case 'cn_color_text':
							$color = isset( $_POST[$field] ) ? sanitize_hex_color( $_POST[$field] ) : '';

							if ( ! empty( $color ) )
								$options['design']['textColor'] = $color;
							break;

						case 'cn_color_heading':
							$color = isset( $_POST[$field] ) ? sanitize_hex_color( $_POST[$field] ) : '';

							if ( ! empty( $color ) )
								$options['design']['headingColor'] = $color;
							break;

						case 'cn_color_button_text':
							$color = isset( $_POST[$field] ) ? sanitize_hex_color( $_POST[$field] ) : '';

							if ( ! empty( $color ) )
								$options['design']['btnTextColor'] = $color;
							break;

						case 'cn_laws':
							$new_options = [];

							// any data?
							if ( ! empty( $_POST[$field] ) && is_array( $_POST[$field] ) ) {
								$options['regulations'] = array_map( 'sanitize_text_field', $_POST[$field] );

								foreach ( $options['regulations'] as $law ) {
									if ( in_array( $law, [ 'gdpr', 'ccpa', 'otherus', 'ukpecr', 'lgpd', 'pipeda', 'popia' ], true ) )
										$new_options[$law] = true;
								}
							}

							$options['regulations'] = $new_options;

							// Persist selected law keys to a dedicated WP option so
							// get_dashboard() and cnReactData can expose them to the
							// Protection tab LAWS card without a Designer API round-trip.
							// (#1897 — LAWS card always showed "No laws selected")
							//
							// ── Begin regulations optimistic-write scope ─────────────
							// is_network_options(), NOT the $network of this method, which
							// is is_network_admin() (:98). Those are different questions
							// and this row is not this write's alone:
							//
							//   this write        optimistic, so the LAWS card updates
							//                     without waiting for the pull below
							//   get_app_config()  AUTHORITATIVE, runs synchronously ~30
							//                     lines down, writes the same key under
							//                     is_network_options()
							//
							// All four readers — get_consent_regime() (cookie-notice.php),
							// the LAWS card (react-admin-ajax.php), the selectedLaws
							// bootstrap (settings.php) and the WP Consent API via the
							// resolver — read is_network_options() too. Writing this one
							// under is_network_admin() put it in the network row on a
							// network-activated multisite with global_override OFF, where
							// the pull then wrote the site row: the optimistic value
							// became a stale orphan nothing reads, and the laws a super
							// admin had just selected were invisible to every consumer.
							//
							// It survived because it only splits on that one multisite
							// shape — on single-site all three predicates give the same
							// answer, and the bootstrap read the same wrong row back, so
							// a save looked like it worked.
							$law_scope      = $cn->is_network_options();
							$saved_law_keys = array_keys( $new_options );

							if ( $law_scope )
								update_site_option( 'cookie_notice_app_regulations', $saved_law_keys );
							else
								update_option( 'cookie_notice_app_regulations', $saved_law_keys );
							// ── End regulations optimistic-write scope ───────────────

							// GDPR & others
							$options['config']['privacyPolicyLink'] = true;

							// CCPA & Other US
							if ( array_key_exists( 'ccpa', $options['regulations'] ) || array_key_exists( 'otherus', $options['regulations'] ) )
								$options['config']['dontSellLink'] = true;
							else
								$options['config']['dontSellLink'] = false;

							// geolocationRules is intentionally NOT written here (OBS-28).
							// Per-jurisdiction geolocation rules are owned by the Admin Portal.
							// The plugin's flat law selection deliberately does not drive
							// geolocationRules: the by-app PATCH endpoint deep-merges and
							// preserves the stored (portal-tuned) rules when this key is
							// omitted (Designer API userDesign.controller.ts merge + noDefaults
							// schema). Writing a hardcoded matrix here previously clobbered
							// Admin-Portal-tuned per-jurisdiction blocking rules on every law save.

							// ── Auto-set compliance settings based on selected laws (#2143) ──────────
							//
							// Opt-in consent laws (GDPR, UKPECR, LGPD, POPIA) require prior explicit
							// consent — implied consent via scroll/click/close is not valid under any
							// of these frameworks. Apply the strictest safe defaults when any are selected.
							$opt_in_laws  = [ 'gdpr', 'ukpecr', 'lgpd', 'popia' ];
							$has_opt_in   = ! empty( array_intersect( array_keys( $options['regulations'] ), $opt_in_laws ) );
							$has_ccpa_us  = array_key_exists( 'ccpa', $options['regulations'] ) || array_key_exists( 'otherus', $options['regulations'] );
							$has_pipeda   = array_key_exists( 'pipeda', $options['regulations'] );

							// Designer API config keys — sent via the existing patch_by_app PATCH call below.
							if ( $has_opt_in ) {
								// Scroll/click/close are not valid consent signals under GDPR, UKPECR, LGPD, POPIA.
								$options['config']['onScroll']      = false;
								$options['config']['onClick']       = false;
								// onClose: net-new key — no cn_on_close handler exists; written directly to config.
								$options['config']['onClose']       = false;
								$options['config']['revokeConsent'] = true;
							}

							// GDPR only: cookie walls (uiBlocking) are non-compliant per EDPB guidance.
							if ( array_key_exists( 'gdpr', $options['regulations'] ) ) {
								$options['config']['uiBlocking'] = false;
							}

							// CCPA/OTHERUS: CPRA mandates honoring GPC browser signals.
							// gpcSupportMode is Pro-gated with grandfather (see control-room/
							// solution/knowledge/decisions.md gpc-pro-gating-with-grandfather). Auto-set fires
							// only when the site can actually enable GPC — Pro tier OR an app
							// that already has gpcSupportMode=true persisted (grandfathered).
							// For Free non-grandfathered + CCPA, the existing 'crit' red state
							// in ComplianceBehavior.jsx surfaces the compliance gap and the
							// upgrade CTA points the customer to Pro.
							if ( $has_ccpa_us ) {
								$existing_blocking = Cookie_Notice_Store::get( 'cookie_notice_app_blocking', [], $network );
								$existing_gpc = ! empty( $existing_blocking['banner_config']['gpcSupportMode'] );
								$is_pro       = $cn->get_subscription() === 'pro';

								if ( $is_pro || $existing_gpc ) {
									$options['config']['gpcSupportMode'] = true;
									// gpcBannerMode = 'passive' surfaces a brief, non-blocking notice
									// when GPC is honored. Set explicitly so legacy apps whose
									// persisted value is the old 'banner' default get reset.
									$options['config']['gpcBannerMode'] = 'passive';
								}
							}

							// PIPEDA: express consent requires ability to revoke — send revokeConsent to Designer API
							// to match the WP-side revoke_cookies=true set below (#2146).
							if ( $has_pipeda ) {
								$options['config']['revokeConsent'] = true;
							}

							// ── WP-side options (cookie_notice_options) ─────────────────────────────
							// The configure case does not normally touch WP options — this is new.
							// Only these keys are stored, over a fresh read of the row
							// (update_general_option_keys()), so nothing else in it is clobbered.
							if ( $has_opt_in || $has_ccpa_us || $has_pipeda ) {
								$wp_options = [];

								if ( $has_opt_in ) {
									// Disable implied consent toggles; enable refuse + revoke + policy link.
									$wp_options['on_scroll']      = false;
									$wp_options['on_click']       = false;
									$wp_options['refuse_opt']     = true;
									$wp_options['revoke_cookies'] = true;
									$wp_options['see_more']       = true;
									// Cap cookie expiry to max allowed: 12–13 months (GDPR/UKPECR EDPB guidance).
									$wp_options['time']           = 'year';
									$wp_options['time_rejected']  = '6months';
								} elseif ( $has_ccpa_us || $has_pipeda ) {
									// Opt-out / express-consent laws: revoke + privacy link minimum.
									$wp_options['revoke_cookies'] = true;
									$wp_options['see_more']       = true;
									// PIPEDA also requires a refuse option (express consent implies ability to decline).
									if ( $has_pipeda )
										$wp_options['refuse_opt'] = true;
								}

								$cn->update_general_option_keys( $wp_options, $network );
							}
							// ── End auto-set compliance settings (#2143) ─────────────────────────

							break;

						case 'cn_naming':
							if ( ! isset( $_POST[$field] ) )
								break;

							$naming = (int) $_POST[$field];
							$naming = in_array( $naming, [ 1, 2, 3 ] ) ? $naming : 1;

							// english only for now
							$level_names = [
								1 => [
									1 => 'Private',
									2 => 'Balanced',
									3 => 'Personalized'
								],
								2 => [
									1 => 'Silver',
									2 => 'Gold',
									3 => 'Platinum'
								],
								3 => [
									1 => 'Reject All',
									2 => 'Accept Some',
									3 => 'Accept All'
								]
							];

							$options['text'] = [
								'levelNameText_1'	=> $level_names[$naming][1],
								'levelNameText_2'	=> $level_names[$naming][2],
								'levelNameText_3'	=> $level_names[$naming][3]
							];
							break;

						case 'cn_on_scroll':
							if ( isset( $_POST[$field] ) )
								$options['config']['onScroll'] = true;
							break;

						case 'cn_on_click':
							if ( isset( $_POST[$field] ) )
								$options['config']['onClick'] = true;
							break;

						case 'cn_ui_blocking':
							if ( isset( $_POST[$field] ) )
								$options['config']['uiBlocking'] = true;
							break;
						
						case 'cn_revoke_consent':
							$options['config']['revokeConsent'] = isset( $_POST[$field] );
							break;
					}
				}

				// Normalise regulations: move into config with explicit false for
				// every deselected law.  Both patch_by_app (mergeWith deep-merge)
				// and quick_config (dto.config?.regulations) read it from config.
				// Top-level regulations is kept in the quick schema for backward
				// compat with legacy callers, but new code only sends via config.
				$all_laws = [ 'gdpr', 'ccpa', 'otherus', 'ukpecr', 'lgpd', 'pipeda', 'popia' ];
				$selected = isset( $options['regulations'] ) ? $options['regulations'] : [];
				$full_regs = [];
				foreach ( $all_laws as $law ) {
					$full_regs[ $law ] = ! empty( $selected[ $law ] );
				}
				$options['config']['regulations'] = $full_regs;
				unset( $options['regulations'] );

				// The Do Not Sell link, validated above. After the field loop: cn_naming
				// replaces the whole text array. The by-app PATCH merges text keys, so only
				// this key changes (the banner's default language).
				if ( $dns_url !== '' )
					$options['text']['dontSellUrl'] = $dns_url;

				// set options
				if ( $network )
					set_site_transient( 'cookie_notice_app_quick_config', $options, DAY_IN_SECONDS );
				else
					set_transient( 'cookie_notice_app_quick_config', $options, DAY_IN_SECONDS );

				// For connected apps: PATCH the Designer API immediately (#1913 — #1917).
				// The transient is retained for the register/login initial-creation path.
				// DevMode mock IDs are skipped — get_write_request_type() returns 'devmode'.
				if ( ! empty( $app_id ) ) {
					$write_type = $this->get_write_request_type( $app_id );

					if ( $write_type !== 'devmode' ) {
						// Cast transient arrays to stdClass objects for JSON encoding.
						$patch_params = [ 'AppID' => $app_id ];

						foreach ( $options as $key => $value ) {
							if ( is_array( $value ) ) {
								$obj = new stdClass();
								foreach ( $value as $sub_key => $sub_val ) {
									$obj->{$sub_key} = $sub_val;
								}
								$patch_params[ $key ] = $obj;
							} else {
								$patch_params[ $key ] = $value;
							}
						}

						$patch_result = $this->request( 'patch_by_app', $patch_params );

						// Design record not yet created — fall back to quick_config to seed it.
						// The API returns { i18n_msg: 'user_design_update_id_not_found', status: 400 } (HTTP 200)
						// when no record exists, so check i18n_msg — not statusCode/404.
						// Also restore DefaultLanguage which patch_by_app doesn't accept but quick_config requires.
						if ( is_object( $patch_result ) && isset( $patch_result->i18n_msg ) && $patch_result->i18n_msg === 'user_design_update_id_not_found' ) {
							$patch_params['DefaultLanguage'] = 'en';
							$patch_result = $this->request( 'quick_config', $patch_params );
						}

						// #2160: Surface API errors back to the caller
						$api_error = '';
						if ( is_object( $patch_result ) && isset( $patch_result->error ) ) {
							$api_error = $patch_result->error;
						} elseif ( is_array( $patch_result ) && isset( $patch_result['error'] ) ) {
							$api_error = $patch_result['error'];
						}

						if ( ! empty( $api_error ) ) {
							$response = [ 'error' => __( 'Your laws were saved locally but could not be applied to your live site. Please try again or visit the portal.', 'cookie-notice' ), 'apiSync' => false ];
							break;
						}

						// Pull confirmed state from portal — portal is SoT.
						// Updates cookie_notice_app_blocking, cookie_notice_app_regulations,
						// cookie_notice_app_design, cookie_notice_status.
						// Do NOT assign return value to $response — configure success
						// intentionally returns $response = false (initial value).
						// LawSelectorPanel checks only for json.error; false has none.
						$this->get_app_config( $app_id, true, true );
					}
				}

				break;

			case 'select_plan':
				break;

			case 'sync_config':
				// force update configuration from Designer API
				$status_data = $this->get_app_config( $app_id, true, true );

				// use global_override-aware check for data operations (not is_network_admin)
				$network_options = $cn->is_network_options();

				// get the blocking data with timestamp
				if ( $network_options )
					$blocking = get_site_option( 'cookie_notice_app_blocking', [] );
				else
					$blocking = get_option( 'cookie_notice_app_blocking', [] );

				// debug: include blocking data in response when debug mode is enabled
				$debug = $cn->options['general']['debug_mode'] ? [
					'app_id' => $app_id,
					'status_data' => $status_data,
					'blocking' => $blocking,
					'providers_count' => ! empty( $blocking['providers'] ) ? count( $blocking['providers'] ) : 0,
					'patterns_count' => ! empty( $blocking['patterns'] ) ? count( $blocking['patterns'] ) : 0,
				] : null;

				// check if sync was successful
				if ( ! empty( $status_data ) && is_array( $status_data ) && ! empty( $status_data['status'] ) && $status_data['status'] === 'active' ) {
					// set cache purge transient to force widget to refresh
					//
					// Network-wide only when the caller may write at that scope: api_request()
					// is gated on manage_options, so otherwise a subsite administrator's Sync
					// Config busts the widget cache for every site. Churn rather than
					// escalation — the value is a timestamp and no configuration changes — but
					// it is the same predicate in the same class.
					if ( $network_options && $cn->can_write_at_scope( true ) )
						set_site_transient( 'cookie_notice_config_update', time(), DAY_IN_SECONDS );
					else
						set_transient( 'cookie_notice_config_update', time(), DAY_IN_SECONDS );

					// re-evaluate CSP state on-demand — pairs with the same call
					// in ajax_purge_cache() so both refresh buttons clear stale flags.
					if ( isset( $cn->settings ) )
						$cn->settings->refresh_csp_notice( true );

					$response = [
						'success' => true,
						'message' => esc_html__( 'Configuration synced successfully.', 'cookie-notice' ),
						'timestamp' => ! empty( $blocking['lastUpdated'] ) ? $blocking['lastUpdated'] : ''
					];
				} else {
					$response = [
						'error' => esc_html__( 'Failed to sync configuration. Please check your app ID and try again.', 'cookie-notice' )
					];
				}

				if ( $debug )
					$response['debug'] = $debug;
				break;
		}

		echo wp_json_encode( $this->reply_text_as_text( $response ) );
		exit;
	}

	/**
	 * The reply's `error` and `message` as text, never markup.
	 *
	 * Both legacy sinks write them with jQuery .html(): cnDisplayError() in admin-welcome.js and
	 * the sync_config notice in admin.js. Many are the platform's own words: a license refusal,
	 * a declined card's Braintree message, every sign-in refusal passed on as the raw API object.
	 * Escaped once, where the reply leaves api_request(), so a branch added later cannot forget.
	 *
	 * esc_html() does not double-encode, so a message built with esc_html__() comes out the same,
	 * and 'email_exists' has nothing to escape. Only strings change: a non-string error is walked,
	 * every other field is left alone. React renders these as text, so a platform message holding
	 * & ' " < or > shows the entity there, as every esc_html__() message already does.
	 *
	 * @param mixed $response The reply api_request() is about to print.
	 * @return mixed
	 */
	private function reply_text_as_text( $response ) {
		$escape = function ( $value ) {
			return is_string( $value ) ? esc_html( $value ) : $value;
		};

		foreach ( [ 'error', 'message' ] as $key ) {
			if ( is_array( $response ) && isset( $response[ $key ] ) )
				$response[ $key ] = map_deep( $response[ $key ], $escape );
			elseif ( is_object( $response ) && isset( $response->$key ) )
				$response->$key = map_deep( $response->$key, $escape );
		}

		return $response;
	}

	/**
	 * What a debug log may say about a platform reply: its status and how much came back.
	 *
	 * Never the body. The bodies these logs used to dump carry the app's whole config, the
	 * platform's own error text and, after a write, what the admin sent. The debug log is a file
	 * on the site's disk, which hosts, support tools and backups copy around.
	 *
	 * @param mixed $reply What request() returned.
	 * @return string
	 */
	private function debug_reply_summary( $reply ) {
		if ( ! is_object( $reply ) )
			return 'no answer (transport error or non-JSON reply)';

		$status = isset( $reply->status ) && is_numeric( $reply->status ) ? (int) $reply->status : 'none';
		$fields = isset( $reply->data ) && ( is_array( $reply->data ) || is_object( $reply->data ) ) ? count( (array) $reply->data ) : 0;

		return 'status ' . $status . ', ' . $fields . ' data fields';
	}

	/**
	 * The password was right and the account has a second step: keep what the code step needs,
	 * server-side, and tell the browser to ask for it.
	 *
	 * What is kept ( a transient per WordPress user ): the partial token, the account's email AS
	 * THE PLATFORM ANSWERED IT, the methods the token's claims name, and when it began. The browser
	 * is sent the email and the methods only — never the token, and nothing it sends later is
	 * trusted for who the account is.
	 *
	 * @param object $data    The sign-in answer's data ( a partial token, already established ).
	 * @param bool   $network Network scope.
	 * @return array
	 */
	private function begin_login_code( $data, $network ) {
		$claims = $this->jwt_claims( isset( $data->token ) && is_string( $data->token ) ? $data->token : '' );
		$claims = is_array( $claims ) ? $claims : [];

		// The account, from the answer or the token's own claim — never from what the form posted.
		$email = isset( $data->email ) && is_string( $data->email ) ? is_email( $data->email ) : false;

		if ( ! $email && isset( $claims['AdminID'] ) && is_string( $claims['AdminID'] ) )
			$email = is_email( $claims['AdminID'] );

		$code_sent = ! empty( $data->authCodeSent );
		$methods   = $this->login_methods( $claims, $code_sent );

		if ( ! $email || ! $methods )
			return [ 'error' => esc_html__( 'Two-step verification is on for this account, but this plugin could not tell how to ask for the code. Connect with your App ID and App Secret Key from the Admin Portal.', 'cookie-notice' ) ];

		// Signing in again must not be a way round the wrong-code limit.
		if ( $this->login_locked_out( $email, $network ) ) {
			Cookie_Notice_Store::delete_transient( $this->login_pending_key(), $network );

			return [ 'error' => $this->login_locked_message() ];
		}

		Cookie_Notice_Store::set_transient(
			$this->login_pending_key(),
			[
				'stage'        => 'code',
				'partial_token' => $data->token,
				'email'        => $email,
				'methods'      => $methods,
				'code_sent'    => $code_sent,
				'issued_at'    => time(),
				'last_resend'  => 0
			],
			self::LOGIN_PENDING_TTL,
			$network
		);

		return [ 'needs_code' => true, 'email' => $email, 'methods' => $methods, 'code_sent' => $code_sent ];
	}

	/**
	 * The second-step methods an account has, the one to ask for first at the front.
	 *
	 * From the partial token's claims: email ( Is2faEmailEnabled ), authenticator app ( Is2faEnabled
	 * and TwofaVerified ), backup code ( Is2faBackupCodeEnabled ). The platform has already emailed
	 * a code when it says so ( authCodeSent ), and that is what to ask for first; otherwise the
	 * account's own preference.
	 *
	 * @param array $claims    The partial token's claims.
	 * @param bool  $code_sent Whether the platform already emailed a code.
	 * @return string[] Any of 'email', 'totp', 'backup'; empty when the claims name none.
	 */
	private function login_methods( array $claims, $code_sent ) {
		$methods = [];

		if ( ! empty( $claims['Is2faEmailEnabled'] ) )
			$methods[] = 'email';

		if ( ! empty( $claims['Is2faEnabled'] ) && ! empty( $claims['TwofaVerified'] ) )
			$methods[] = 'totp';

		if ( ! empty( $claims['Is2faBackupCodeEnabled'] ) )
			$methods[] = 'backup';

		$first = '';

		if ( $code_sent )
			$first = 'email';
		elseif ( isset( $claims['Preferred2faMethod'] ) && $claims['Preferred2faMethod'] === 'TOTP' )
			$first = 'totp';

		if ( $first !== '' && in_array( $first, $methods, true ) )
			$methods = array_merge( [ $first ], array_values( array_diff( $methods, [ $first ] ) ) );

		return $methods;
	}

	/**
	 * The second step: check the code, and on success finish the sign-in.
	 *
	 * Order matters here, and each step is a security property:
	 *  - a pending sign-in must exist, be a `code` one and be fresh; the account is the one the
	 *    PLATFORM named when it was begun ( AdminID is never read from the browser );
	 *  - the method must be one the account has; the code is cleaned to what a code can contain;
	 *  - the account must not be locked out, and the attempt is COUNTED BEFORE it is made, under a
	 *    lock, so neither a dropped reply nor a burst of parallel requests buys a free guess;
	 *  - only `success === true` with status 200, a full ( not partial ) token and the pending
	 *    account's email counts as a pass; anything else is a failed attempt;
	 *  - the pending record is deleted BEFORE the shared tail runs, so a sign-in that goes on to
	 *    write a record of its own cannot have this one outlive it.
	 *
	 * The Account API has NO rate limit on login-authcode / login-totp / login-backupcode ( a known
	 * server-side gap, reported separately ), so this limit protects only what goes through this
	 * plugin; it cannot stop someone holding a partial token from calling the API directly.
	 *
	 * @param bool $network Network scope.
	 * @return object|array
	 */
	private function login_code( $network ) {
		$pending = $this->login_pending( $network );

		// Only a sign-in waiting for its code can be answered here; one in another stage is left as it is.
		if ( ! $pending || $pending['stage'] !== 'code' )
			return $this->login_expired();

		$method = isset( $_POST['method'] ) && is_string( $_POST['method'] ) ? sanitize_key( $_POST['method'] ) : '';

		if ( ! in_array( $method, [ 'email', 'totp', 'backup' ], true ) || ! in_array( $method, $pending['methods'], true ) )
			return [ 'error' => esc_html__( 'Choose how you want to verify.', 'cookie-notice' ) ];

		// A code holds digits; a backup code letters, digits and dashes.
		$raw  = isset( $_POST['code'] ) && is_string( $_POST['code'] ) ? $_POST['code'] : '';
		$code = $method === 'backup' ? preg_replace( '/[^A-Za-z0-9-]/', '', $raw ) : preg_replace( '/\D/', '', $raw );

		if ( $code === '' )
			return [ 'error' => esc_html__( 'Enter the verification code.', 'cookie-notice' ) ];

		$email = $pending['email'];

		if ( $this->login_locked_out( $email, $network ) ) {
			Cookie_Notice_Store::delete_transient( $this->login_pending_key(), $network );

			return [ 'error' => $this->login_locked_message() ];
		}

		$lock = $this->acquire_login_lock( $email, $network );

		if ( ! $lock )
			return [ 'error' => esc_html__( 'Another verification is in progress. Wait a moment and try again.', 'cookie-notice' ) ];

		try {
			// Read again now that nothing else can write: a parallel attempt may have used it up.
			$pending = $this->login_pending( $network );

			if ( ! $pending || $pending['stage'] !== 'code' || strcasecmp( $pending['email'], $email ) !== 0 )
				return $this->login_expired();

			$guard = $this->login_guard( $email, $network );

			if ( $guard['locked_until'] > time() || $guard['attempts'] >= self::LOGIN_MAX_ATTEMPTS ) {
				Cookie_Notice_Store::delete_transient( $this->login_pending_key(), $network );

				return [ 'error' => $this->login_locked_message() ];
			}

			// Counted before the call. Success clears it again.
			$attempts = $guard['attempts'] + 1;

			$this->login_guard_write( $email, [ 'attempts' => $attempts, 'locked_until' => 0 ], $network );

			$types  = [ 'email' => 'login_authcode', 'totp' => 'login_totp', 'backup' => 'login_backupcode' ];
			$result = $this->request( $types[ $method ], [ 'AdminID' => $pending['email'], 'AuthCode' => $code, 'PartialToken' => $pending['partial_token'] ] );

			// The platform answers a wrong code with HTTP 200 and success false, so the body is the
			// verdict. Anything that is not exactly a success — a transport error, a page, an empty
			// body — is a failed attempt.
			$passed = is_object( $result )
				&& isset( $result->success ) && $result->success === true
				&& isset( $result->status ) && $result->status === 200
				&& isset( $result->data ) && is_object( $result->data )
				&& ! empty( $result->data->token ) && is_string( $result->data->token );

			// It must be a FULL token, for the account this sign-in began with. One that is not is
			// not a typo of the code: the sign-in ends.
			$end_sign_in = false;

			if ( $passed ) {
				if ( $this->is_partial_login_response( $result->data ) || ! isset( $result->data->email ) || ! is_string( $result->data->email ) || strcasecmp( $result->data->email, $pending['email'] ) !== 0 ) {
					$passed      = false;
					$end_sign_in = true;
				}
			}

			if ( ! $passed ) {
				if ( $end_sign_in || $attempts >= self::LOGIN_MAX_ATTEMPTS )
					Cookie_Notice_Store::delete_transient( $this->login_pending_key(), $network );

				if ( $attempts >= self::LOGIN_MAX_ATTEMPTS ) {
					$this->login_guard_write( $email, [ 'attempts' => $attempts, 'locked_until' => time() + self::LOGIN_LOCKOUT_TTL ], $network );

					return [ 'error' => $this->login_locked_message() ];
				}

				return [ 'error' => esc_html__( 'That code did not work. Check it and try again.', 'cookie-notice' ) ];
			}

			// Passed: the count is cleared, the pending record deleted and the token stored as the session.
			Cookie_Notice_Store::delete_transient( $this->login_guard_key( $email ), $this->login_guard_scope( $network ) );
			Cookie_Notice_Store::delete_transient( $this->login_pending_key(), $network );
			Cookie_Notice_Store::set_transient( 'cookie_notice_app_token', $result->data, DAY_IN_SECONDS, $network );
		} finally {
			$this->release_login_lock( $lock, $network );
		}

		// Verified, and nothing of this step is left to be reused. The rest is a sign-in.
		return $this->finish_login( $network );
	}

	/**
	 * Send the emailed code again.
	 *
	 * Email only, at most once every LOGIN_RESEND_INTERVAL seconds ( the time is kept before the
	 * request, so a send that fails or times out still counts ), never while locked out. The
	 * platform answers { message, status: 200, success: true } — that `message` is not an error.
	 *
	 * @param bool $network Network scope.
	 * @return array
	 */
	private function login_code_resend( $network ) {
		$pending = $this->login_pending( $network );

		if ( ! $pending || $pending['stage'] !== 'code' )
			return $this->login_expired();

		if ( ! in_array( 'email', $pending['methods'], true ) )
			return [ 'error' => esc_html__( 'A code cannot be sent by email for this account.', 'cookie-notice' ) ];

		$email = $pending['email'];

		if ( $this->login_locked_out( $email, $network ) ) {
			Cookie_Notice_Store::delete_transient( $this->login_pending_key(), $network );

			return [ 'error' => $this->login_locked_message() ];
		}

		$lock = $this->acquire_login_lock( $email, $network );

		if ( ! $lock )
			return [ 'error' => esc_html__( 'Another verification is in progress. Wait a moment and try again.', 'cookie-notice' ) ];

		try {
			$pending = $this->login_pending( $network );

			if ( ! $pending || $pending['stage'] !== 'code' || strcasecmp( $pending['email'], $email ) !== 0 )
				return $this->login_expired();

			$wait = (int) $pending['last_resend'] + self::LOGIN_RESEND_INTERVAL - time();

			if ( $wait > 0 )
				return [ 'error' => esc_html__( 'Please wait a minute before requesting another code.', 'cookie-notice' ), 'retry_after' => $wait ];

			// Written back with the time that is left, so a resend never lengthens the sign-in.
			$pending['last_resend'] = time();

			Cookie_Notice_Store::set_transient( $this->login_pending_key(), $pending, max( 1, self::LOGIN_PENDING_TTL - ( time() - (int) $pending['issued_at'] ) ), $network );

			$result = $this->request( 'send_authcode', [ 'AdminID' => $pending['email'], 'PartialToken' => $pending['partial_token'] ] );

			if ( is_object( $result ) && isset( $result->success ) && $result->success === true && isset( $result->status ) && $result->status === 200 )
				return [ 'code_sent' => true, 'retry_after' => self::LOGIN_RESEND_INTERVAL ];

			return [ 'error' => esc_html__( 'We could not send a new code. Please try again.', 'cookie-notice' ) ];
		} finally {
			$this->release_login_lock( $lock, $network );
		}
	}

	/**
	 * Ask the owner which app this site should use.
	 *
	 * Reached when the account has apps, none is on this site's host, and so nothing may be assumed.
	 * What is kept ( the same per-user record the code step uses, in its `app` stage ): the AppIDs
	 * that were offered, the account the session token belongs to, and when. What the browser is
	 * sent is each app's id, name and domain and nothing else — no SecretKey, no SubscriptionID, no
	 * token; the key is read again, from the account, when the choice comes back ( login_app() ).
	 * `current_app_id` is the AppID this site is connected to now ( the admin already holds it ),
	 * only so the screen can mark it.
	 *
	 * @param array $apps     The account's apps ( the app list's rows ).
	 * @param string $site_url This site's home URL.
	 * @param bool  $network  Network scope.
	 * @return array|null The reply for the browser; null when no row names an app ( nothing to choose ).
	 */
	private function begin_app_choice( array $apps, $site_url, $network ) {
		$choices = [];
		$ids     = [];

		foreach ( $apps as $app ) {
			if ( ! is_object( $app ) || ! isset( $app->AppID ) || ! is_scalar( $app->AppID ) || (string) $app->AppID === '' )
				continue;

			$domain = isset( $app->DomainUrl ) && is_string( $app->DomainUrl ) ? $app->DomainUrl : '';
			$name   = isset( $app->DomainName ) && is_string( $app->DomainName ) && trim( $app->DomainName ) !== '' ? $app->DomainName : $domain;

			$ids[]     = (string) $app->AppID;
			$choices[] = [
				'id'     => (string) $app->AppID,
				'name'   => sanitize_text_field( $name ),
				'domain' => sanitize_text_field( $domain )
			];
		}

		if ( ! $choices )
			return null;

		// Bound to the account that signed in: the stored token is shared by every administrator, so
		// another sign-in in between must not let this choice be used against it.
		$identity = $this->login_identity( Cookie_Notice_Store::get_transient( 'cookie_notice_app_token', $network ) );

		if ( $identity === '' )
			return [ 'error' => esc_html__( 'Unexpected error occurred. Please try again later.', 'cookie-notice' ) ];

		Cookie_Notice_Store::set_transient(
			$this->login_pending_key(),
			[
				'stage'     => 'app',
				'app_ids'   => $ids,
				'identity'  => $identity,
				'issued_at' => time()
			],
			self::LOGIN_PENDING_TTL,
			$network
		);

		return [
			'choose_app'     => true,
			'apps'           => $choices,
			'site_domain'    => sanitize_text_field( trim( preg_replace( '#^https?://#i', '', (string) $site_url ), '/' ) ),
			'current_app_id' => (string) Cookie_Notice()->options['general']['app_id']
		];
	}

	/**
	 * The owner's answer: one of the offered apps, or a new one for this site.
	 *
	 * Each step is a security property:
	 *  - a pending record in the `app` stage must exist and be fresh; a `code` one is not read as this
	 *    one ( and is left as it is );
	 *  - the choice must be one of the AppIDs that record holds, or the new-app marker — nothing the
	 *    browser sends is an app on its own;
	 *  - the session token must still be the one of the account the record was made for;
	 *  - the record is used up ( deleted ) under the account's lock, so a second request with the same
	 *    choice finds nothing — one new app, not two — and before the sign-in goes on;
	 *  - the app's row and SecretKey are read from the account again ( finish_login() ), and a row that
	 *    is not there ends the sign-in.
	 *
	 * @param bool $network Network scope.
	 * @return object|array
	 */
	private function login_app( $network ) {
		$pending = $this->login_pending( $network );

		if ( ! $this->app_choice_readable( $pending ) )
			return $this->login_expired();

		$choice = isset( $_POST['app_id'] ) && is_string( $_POST['app_id'] ) ? wp_unslash( $_POST['app_id'] ) : '';

		if ( $choice !== self::LOGIN_NEW_APP && ( $choice === '' || ! in_array( $choice, $pending['app_ids'], true ) ) )
			return [ 'error' => esc_html__( 'Choose one of your apps, or create a new app for this site.', 'cookie-notice' ) ];

		$lock = $this->acquire_login_lock( $pending['identity'], $network );

		if ( ! $lock )
			return [ 'error' => esc_html__( 'Another sign-in step is in progress. Wait a moment and try again.', 'cookie-notice' ) ];

		try {
			// Read again now that nothing else can write: a parallel request may have used it up.
			$pending = $this->login_pending( $network );

			if ( ! $this->app_choice_readable( $pending ) || ( $choice !== self::LOGIN_NEW_APP && ! in_array( $choice, $pending['app_ids'], true ) ) )
				return $this->login_expired();

			$token = Cookie_Notice_Store::get_transient( 'cookie_notice_app_token', $network );

			// Used up whatever happens next; a sign-in that cannot go on starts again from the password.
			Cookie_Notice_Store::delete_transient( $this->login_pending_key(), $network );

			if ( ! is_object( $token ) || $this->is_partial_login_response( $token ) || $this->login_identity( $token ) !== $pending['identity'] )
				return $this->login_expired();
		} finally {
			$this->release_login_lock( $lock, $network );
		}

		return $this->finish_login( $network, $choice );
	}

	/**
	 * Whether $pending is a usable record of the app choice.
	 *
	 * @param array|null $pending What login_pending() returned.
	 * @return bool
	 */
	private function app_choice_readable( $pending ) {
		return is_array( $pending )
			&& $pending['stage'] === 'app'
			&& isset( $pending['app_ids'] ) && is_array( $pending['app_ids'] )
			&& isset( $pending['identity'] ) && is_string( $pending['identity'] ) && $pending['identity'] !== '';
	}

	/**
	 * Who a session token belongs to: its email, else its JWT AccountID claim; '' when neither.
	 *
	 * @param mixed $data The stored token data ( the sign-in answer's data ).
	 * @return string
	 */
	private function login_identity( $data ) {
		if ( ! is_object( $data ) )
			return '';

		if ( isset( $data->email ) && is_string( $data->email ) && trim( $data->email ) !== '' )
			return strtolower( trim( $data->email ) );

		$claims = $this->jwt_claims( isset( $data->token ) && is_string( $data->token ) ? $data->token : '' );

		if ( is_array( $claims ) && isset( $claims['AccountID'] ) && is_scalar( $claims['AccountID'] ) && (string) $claims['AccountID'] !== '' )
			return 'account:' . $claims['AccountID'];

		return '';
	}

	/**
	 * The sign-in waiting on its second step, or null.
	 *
	 * One record per WordPress user, with a stage ( `code`; `app` for the app choice ) so a record
	 * kept for another step is never read as this one, and a start time checked here as well as by
	 * the transient's own expiry. A record that is unreadable or out of date is deleted.
	 *
	 * @param bool $network Network scope.
	 * @return array|null
	 */
	private function login_pending( $network ) {
		$pending = Cookie_Notice_Store::get_transient( $this->login_pending_key(), $network );

		if ( ! is_array( $pending ) )
			return null;

		$readable = isset( $pending['stage'], $pending['issued_at'] )
			&& is_string( $pending['stage'] )
			&& time() - (int) $pending['issued_at'] <= self::LOGIN_PENDING_TTL;

		if ( $readable && $pending['stage'] === 'code' )
			$readable = ! empty( $pending['partial_token'] ) && is_string( $pending['partial_token'] )
				&& ! empty( $pending['email'] ) && is_string( $pending['email'] )
				&& isset( $pending['methods'] ) && is_array( $pending['methods'] );

		if ( ! $readable ) {
			Cookie_Notice_Store::delete_transient( $this->login_pending_key(), $network );

			return null;
		}

		$pending['last_resend'] = isset( $pending['last_resend'] ) ? (int) $pending['last_resend'] : 0;

		return $pending;
	}

	/**
	 * @return array
	 */
	private function login_expired() {
		return [ 'error' => esc_html__( 'Your sign-in session has expired. Please sign in again.', 'cookie-notice' ), 'expired' => true ];
	}

	/**
	 * @return string
	 */
	private function login_locked_message() {
		return esc_html__( 'Too many attempts. Try again in 15 minutes.', 'cookie-notice' );
	}

	/**
	 * @return string
	 */
	private function login_pending_key() {
		return 'cookie_notice_login_pending_' . get_current_user_id();
	}

	/**
	 * The wrong-code record is per ACCOUNT, not per sign-in: it is not the pending record, so
	 * deleting that ( a fresh password sign-in does ) cannot reset it.
	 *
	 * Keyed by the lower-cased email alone, with no site or user part: the guard protects the
	 * Account API account, and the account is the same whichever site, user or scope tries it.
	 * Where it is STORED is login_guard_scope().
	 *
	 * @param string $email The account's email.
	 * @return string
	 */
	private function login_guard_key( $email ) {
		return 'cookie_notice_login_guard_' . md5( strtolower( $email ) );
	}

	/**
	 * Where the account's wrong-code record and verification lock are kept.
	 *
	 * On a multisite network: the NETWORK ( a site transient / site option, one row for the whole
	 * network ), whatever scope the request came in at. A site-scoped record would give every
	 * subsite its own five tries at the same account, and the five-tries limit would be five times
	 * the number of subsites. The lock goes with it: it protects that record's read-then-write, so
	 * it must be seen by every writer of it. On a single site there is one scope and this returns
	 * $network unchanged ( site transients and options are used exactly as before ).
	 *
	 * @param bool $network Network scope of the request.
	 * @return bool
	 */
	private function login_guard_scope( $network ) {
		return $network || is_multisite();
	}

	/**
	 * @param string $email   The account's email.
	 * @param bool   $network Network scope.
	 * @return array { attempts: int, locked_until: int }
	 */
	private function login_guard( $email, $network ) {
		$guard = Cookie_Notice_Store::get_transient( $this->login_guard_key( $email ), $this->login_guard_scope( $network ) );

		return [
			'attempts'     => is_array( $guard ) && isset( $guard['attempts'] ) ? (int) $guard['attempts'] : 0,
			'locked_until' => is_array( $guard ) && isset( $guard['locked_until'] ) ? (int) $guard['locked_until'] : 0
		];
	}

	/**
	 * Kept for the lock-out window from the LAST wrong code, so a slow guesser is counted too.
	 *
	 * @param string $email   The account's email.
	 * @param array  $guard   { attempts, locked_until }
	 * @param bool   $network Network scope.
	 * @return void
	 */
	private function login_guard_write( $email, array $guard, $network ) {
		Cookie_Notice_Store::set_transient( $this->login_guard_key( $email ), $guard, self::LOGIN_LOCKOUT_TTL, $this->login_guard_scope( $network ) );
	}

	/**
	 * @param string $email   The account's email.
	 * @param bool   $network Network scope.
	 * @return bool
	 */
	private function login_locked_out( $email, $network ) {
		$guard = $this->login_guard( $email, $network );

		return $guard['locked_until'] > time() || $guard['attempts'] >= self::LOGIN_MAX_ATTEMPTS;
	}

	/**
	 * Take the verification lock for an account: an option that exists only while a verification
	 * runs, so two requests cannot both read the same count and both write count + 1.
	 *
	 * add_option() refuses a key that is there, which is the whole mechanism; it is as strong as
	 * the database's handling of that insert. A lock older than LOGIN_LOCK_TTL ( longer than the
	 * request it waits on ) belongs to a request that died and is taken over. The value is
	 * "<time>:<owner token>": the token is what makes release_login_lock() give up only OUR lock.
	 *
	 * @param string $email   The account's email.
	 * @param bool   $network Network scope of the request ( see login_guard_scope() ).
	 * @return array|false The lock, to hand back to release_login_lock(); false when it is held.
	 */
	private function acquire_login_lock( $email, $network ) {
		$scope = $this->login_guard_scope( $network );
		$key   = 'cookie_notice_login_lock_' . md5( strtolower( $email ) );
		$value = time() . ':' . wp_generate_password( 16, false );
		$lock  = [ 'key' => $key, 'value' => $value ];

		if ( Cookie_Notice_Store::add( $key, $value, $scope, false ) )
			return $lock;

		$held = Cookie_Notice_Store::get( $key, '', $scope );

		// ( int ) reads the time out of "<time>:<token>", and out of a bare time left by an older version.
		if ( (int) $held > 0 && time() - (int) $held > self::LOGIN_LOCK_TTL ) {
			// Removed only while it is still the stale one we read, so a lock that another
			// request has just replaced it with is not removed from under that request.
			$this->delete_login_lock_if_held( $key, (string) $held, $scope );

			if ( Cookie_Notice_Store::add( $key, $value, $scope, false ) )
				return $lock;
		}

		return false;
	}

	/**
	 * Give the lock up, if it is still ours.
	 *
	 * A request that ran past LOGIN_LOCK_TTL may find its lock taken over; deleting by key alone
	 * would then release the OTHER request's lock.
	 *
	 * @param array $lock    What acquire_login_lock() returned.
	 * @param bool  $network Network scope of the request.
	 * @return void
	 */
	private function release_login_lock( $lock, $network ) {
		$this->delete_login_lock_if_held( $lock['key'], $lock['value'], $this->login_guard_scope( $network ) );
	}

	/**
	 * Compare-then-delete. Not atomic ( WordPress has no compare-and-delete for an option ): the
	 * window is the gap between the read and the delete, and what it can do is bounded to
	 * releasing a lock that was taken over in that gap.
	 *
	 * @param string $key     The lock's option name.
	 * @param string $value   The value the lock must still have.
	 * @param bool   $network Scope the lock is stored in.
	 * @return void
	 */
	private function delete_login_lock_if_held( $key, $value, $network ) {
		if ( (string) Cookie_Notice_Store::get( $key, '', $network ) === $value )
			Cookie_Notice_Store::delete( $key, $network );
	}

	/**
	 * Everything a sign-in does once it holds a FULL session token: find or create this site's app,
	 * read its plan, store the credentials, publish, pull the configuration.
	 *
	 * Shared by every way of getting that token: the password sign-in ( 'login' ) and the
	 * second-step code ( 'login_code' ). It starts from the stored token — request() reads it —
	 * so it must only be called once that token has been stored, and the caller has already ruled
	 * out a partial one. The sign-in's own steps are all in here ( the account's app list, the
	 * match on this site, app/add, the New-engine switch, the plan, the status row ), so a later
	 * entry point reuses them as they are rather than copying them.
	 *
	 * Where the old inline tail stopped with `break`, this returns: the value is what api_request()
	 * sends to the browser, an error object or array on a stop, and on success the
	 * { subscriptions, fresh_nonce, app_has_subscription } object.
	 *
	 * It is also where the owner is asked which app to use, when the account has apps and none is this
	 * site's ( begin_app_choice() ); 'login_app' comes back here with $pick, the app they chose.
	 *
	 * @param bool   $network Network scope, as api_request() resolved it.
	 * @param string $pick    '' for a sign-in ( match this site, or ask ); an AppID the owner chose from
	 *                        the account's apps; or LOGIN_NEW_APP for a new app for this site.
	 * @return object|array
	 */
	private function finish_login( $network, $pick = '' ) {
		$cn          = Cookie_Notice();
		$locale_code = explode( '_', get_locale() );

		// get apps and check if one for the current domain already exists
		$response = $this->request( 'list_apps', [] );

		// ── Begin list_apps unreachable guard ────────────────────────────
		// request() returns an ARRAY, ['error' => …], for a WP transport error
		// AND for any text/html body — a 502, a Cloudflare page, a WAF block. On
		// an array every property read below is NULL, so BOTH error checks pass
		// and the apps loop finds nothing. $app_exists stays false, and the very
		// next step reads that as "this domain has no app yet" and calls
		// app_create — registering a SECOND application on the customer's account
		// for a domain that already has one, off a transient blip. That is an
		// external, non-idempotent side effect; it cannot be undone from here and
		// the customer is left to notice the duplicate themselves.
		//
		// The auth request just above is safe only by accident: its token check
		// (empty( $response->data->token )) happens to catch the array. Nothing
		// here did.
		if ( ! is_object( $response ) ) {
			$response = (object) [
				'error' => __( 'We could not reach Cookie Compliance to list your sites. Please try again.', 'cookie-notice' )
			];
			return $response;
		}
		// ── End list_apps unreachable guard ──────────────────────────────

		// ── Begin account with no apps ───────────────────────────────────
		// An account that has no app yet — a brand-new one, or one whose apps were all
		// deleted — is answered by the Account API's app/list as an ERROR:
		// { success: false, status: 400, message: 'No apps found', i18n_msg: 'no_apps_found' }
		// ( HTTP 200; Account API app.service.ts listApp ), not as an empty list. Read as
		// fatal, such an account could never sign in from the plugin. That one answer is an
		// empty list, and the app is created for this site below; any other message is
		// still fatal.
		$no_apps = ! empty( $response->i18n_msg ) && $response->i18n_msg === 'no_apps_found' && empty( $response->data );

		// errors?
		if ( ! empty( $response->message ) && ! $no_apps ) {
			$response->error = $response->message;
			return $response;
		}
		// ── End account with no apps ─────────────────────────────────────

		$apps_list = [];
		$app_exists = false;

		// multisite?
		if ( is_multisite() ) {
			switch_to_blog( 1 );
			$site_title = get_bloginfo( 'name' );
			$site_url = network_site_url();
			$site_description = get_bloginfo( 'description' );
			restore_current_blog();
		} else {
			$site_title = get_bloginfo( 'name' );
			$site_url = get_home_url();
			$site_description = get_bloginfo( 'description' );
		}

		// apps added, check if current one exists
		if ( ! empty( $response->data ) ) {
			$apps_list = (array) $response->data;

			// A sign-in that goes on from the owner's choice ( $pick ) takes the app they chose, so
			// nothing here looks for a match.
			if ( $pick === '' ) {
				// normalize site URL once before the loop: lowercase, strip protocol, strip www, strip trailing slash
				$site_normalized = strtolower( preg_replace( '/^www\./', '', trim( str_replace( [ 'http://', 'https://' ], '', $site_url ), '/' ) ) );

				foreach ( $apps_list as $index => $app ) {
					$app_domain = strtolower( preg_replace( '/^www\./', '', trim( str_replace( [ 'http://', 'https://' ], '', $app->DomainUrl ), '/' ) ) );

					if ( $app_domain === $site_normalized ) {
						$app_exists = $app;

						break;
					}
				}
			}
		}

		// ── Begin app choice ─────────────────────────────────────────────
		// The Account API keeps only the HOST of a domain ( https://example.com/blog is stored as
		// example.com ) and allows one app per host, so for a site in a subfolder the exact match
		// above never finds its app. The one app on this site's host is it ( see
		// find_app_on_site_host() ): looked for BEFORE the owner is asked anything, and before
		// app/add, which would only answer domain_url_already_exist.
		//
		// Then, when the account has apps and none is this site's, the owner chooses: one of them,
		// or a new app for this site. Nothing is created and nothing stored until they do — the
		// answer to ask is returned and the choice comes back as 'login_app', which runs this same
		// method with $pick. An account with no app at all has nothing to choose from: the app is
		// created below, as it always was.
		if ( $pick === '' ) {
			if ( ! $app_exists && $apps_list ) {
				$host_app = $this->find_app_on_site_host( $apps_list, $site_url );

				if ( $host_app )
					$app_exists = $host_app;
				else {
					$choice = $this->begin_app_choice( $apps_list, $site_url, $network );

					if ( $choice )
						return $choice;
				}
			}
		} elseif ( $pick !== self::LOGIN_NEW_APP ) {
			// The chosen app must be on the account NOW ( the list was just read again ): an app
			// that was removed since, or an id that never was, ends the sign-in. Its SecretKey is
			// this row's, never one the browser could have carried.
			foreach ( $apps_list as $app ) {
				if ( is_object( $app ) && isset( $app->AppID ) && is_scalar( $app->AppID ) && (string) $app->AppID === $pick ) {
					$app_exists = $app;

					break;
				}
			}

			if ( ! $app_exists )
				return [ 'error' => esc_html__( 'That app is no longer on your account. Please sign in again.', 'cookie-notice' ) ];
		}
		// ── End app choice ───────────────────────────────────────────────

		// track whether this domain already existed before login
		$app_was_preexisting = (bool) $app_exists;

		// DEC-022: the app this request creates, if it creates one ('' otherwise). Set
		// only inside the create branch below, from that create response — never for a
		// domain that already had an app, and never from the stored credentials.
		$created_app_id = '';

		// if no app, create one ( no app on the account, or the owner chose a new one )
		if ( ! $app_exists ) {
			// create new app
			$params = [
				'DomainName'	=> $site_title,
				'DomainUrl'		=> $site_url,
			];

			if ( ! empty( $site_description ) )
				$params['DomainDescription'] = $site_description;

			$response = $this->request( 'app_create', $params );

			// errors?
			if ( ! empty( $response->message ) ) {
				$response->error = $response->message;
				return $response;
			}

			$app_exists = $response->data;

			if ( is_object( $response ) && empty( $response->error ) && ! empty( $response->data->AppID ) )
				$created_app_id = (string) $response->data->AppID;
		}

		// check if we have the valid app data
		if ( empty( $app_exists->AppID ) || empty( $app_exists->SecretKey ) ) {
			$response = [ 'error' => esc_html__( 'Unexpected error occurred. Please try again later.', 'cookie-notice' ) ];
			return $response;
		}

		// DEC-022: a brand-new app starts on the New engine — straight after it is
		// created, before the credentials are stored and before its first publish (see
		// the register flow). Never fails the login.
		if ( $created_app_id !== '' )
			$this->start_new_app_on_v2( $created_app_id );

		// get subscriptions
		$subscriptions = [];

		$params = [
			'AppID' => $app_exists->AppID
		];

		$response = $this->request( 'get_subscriptions', $params );

		// ── Begin get_subscriptions unreachable guard ────────────────────
		// Same array-vs-object shape. Unguarded, the error check below reads NULL
		// off the array and passes, (array) NULL yields [], and that empty list is
		// written to the subscriptions transient for a FULL DAY — so the tier is
		// resolved from nothing and stays resolved from nothing until the cache
		// expires, long after the network recovered.
		if ( ! is_object( $response ) ) {
			$response = (object) [
				'error' => __( 'We could not reach Cookie Compliance to read your plan. Please try again.', 'cookie-notice' )
			];
			return $response;
		}
		// ── End get_subscriptions unreachable guard ──────────────────────

		// errors?
		if ( ! empty( $response->error ) ) {
			return $response;
		} else
			$subscriptions = map_deep( (array) $response->data, [ $this, 'sanitize_preserve_bools' ] );

		// set subscriptions data
		if ( $network )
			set_site_transient( 'cookie_notice_app_subscriptions', $subscriptions, DAY_IN_SECONDS );
		else
			set_transient( 'cookie_notice_app_subscriptions', $subscriptions, DAY_IN_SECONDS );

		// determine subscription tier:
		// - pre-existing domain: preserve its current tier from WP options (Designer API is authoritative)
		//   availablelicense reflects account-level available slots, NOT this domain's plan
		//   If WP options were cleared (e.g. reset), fall back to API-side SubscriptionType
		// - brand-new domain: always starts as 'basic' (free by default, payment upgrades it)
		if ( $app_was_preexisting && $pick !== '' ) {
			// The app the owner chose: its plan is what the ACCOUNT says about THAT app. The tier kept
			// locally belongs to whatever app this site was connected to before.
			$subscription_tier = ! empty( $app_exists->SubscriptionID ) ? 'pro' : 'basic';
		} elseif ( $app_was_preexisting ) {
			$existing_status = Cookie_Notice_Store::get( 'cookie_notice_status', $cn->defaults['data'], $network );

			$subscription_tier = ! empty( $existing_status['subscription'] ) && in_array( $existing_status['subscription'], [ 'basic', 'pro' ], true )
				? $existing_status['subscription']
				: 'basic';

			// WP options cleared but API knows the domain has a subscription — derive tier from API
			if ( $subscription_tier === 'basic' && ! empty( $app_exists->SubscriptionID ) ) {
				$subscription_tier = 'pro';
			}
		} else {
			$subscription_tier = 'basic';
		}

		// update options: app ID and secret key. In memory first: request() signs the
		// calls below with $cn->options['general']'s credentials. Stored as these keys
		// only, over a fresh read of the row (update_general_option_keys()).
		$previous_app_id = (string) $cn->options['general']['app_id'];

		$credentials = [ 'app_id' => $app_exists->AppID, 'app_key' => $app_exists->SecretKey ] + ( $network ? [ 'global_override' => true ] : [] );

		$cn->options['general'] = wp_parse_args( $credentials, $cn->options['general'] );

		$cn->update_general_option_keys( $credentials, $network );

		// Pre-existing domains already have their configuration in the Designer API.
		// Only call quick_config for new domains to avoid overwriting existing
		// regulations and settings with defaults.
		//
		// ── Begin login status seed ──────────────────────────────────────
		// Seeded from what is STORED, not from the defaults, for the same reason
		// the analytics path is: every write below persists the WHOLE
		// cookie_notice_status row while this flow only knows about three of its
		// fields, so a defaults seed silently reset widget_version and
		// activation_datetime on a reconnect that touched neither.
		$status_data = array_merge(
			$cn->defaults['data'],
			(array) Cookie_Notice_Store::get( 'cookie_notice_status', $cn->defaults['data'], $network )
		);
		$status_data['subscription'] = $subscription_tier;
		// ── End login status seed ────────────────────────────────────────

		// An app the owner chose that is NOT the one stored starts from the defaults instead: the
		// stored row describes another app, and its widget_version would survive a config pull that
		// fails ( the pull is what replaces it ), leaving this site on the wrong engine.
		//
		// Past this point the credentials ARE the new app's, so every way out that is not a finished
		// sign-in has to leave the site not live on it ( park_switched_app() ), never on the stored
		// row of the app it left.
		$app_switched = $pick !== '' && (string) $app_exists->AppID !== $previous_app_id;

		if ( $app_switched )
			$status_data = array_merge( $cn->defaults['data'], [ 'subscription' => $subscription_tier ] );

		if ( ! $app_was_preexisting ) {
			// Apply pre-configure settings from transient (mirrors register flow).
			// Transient is set by the configure wizard when the user hasn't yet connected.
			$app_config = Cookie_Notice_Store::get_transient( 'cookie_notice_app_quick_config', $network );

			// create quick config
			$params = ! empty( $app_config ) && is_array( $app_config ) ? $app_config : [];

			// cast arrays to objects
			if ( $params ) {
				$new_params = [];

				foreach ( $params as $key => $array ) {
					$object = new stdClass();

					foreach ( $array as $subkey => $value ) {
						$new_params[$key] = $object;
						$new_params[$key]->{$subkey} = $value;
					}
				}

				$params = $new_params;
			}

			$params['AppID']           = $app_exists->AppID;
			$params['DefaultLanguage'] = 'en';

			if ( ! array_key_exists( 'text', $params ) )
				$params['text'] = new stdClass();

			// add privacy policy url
			$params['text']->privacyPolicyUrl = get_privacy_policy_url();

			// add translations if needed
			if ( $locale_code[0] !== 'en' )
				$params['Languages'] = [ $locale_code[0] ];

			$response = $this->request( 'quick_config', $params );

			// ── Begin quick_config unreachable guard ─────────────────
			// See the login unreachable guard below notify_app for why
			// is_object() is the discriminator and why an unreachable platform
			// must not write this row.
			if ( ! is_object( $response ) ) {
				if ( $app_switched ) {
					$this->park_switched_app( $status_data, $network );

					return $this->switched_app_not_loaded_reply();
				}

				$response = (object) [
					'error' => __( 'We could not reach Cookie Compliance to finish setting up this site. Your settings are unchanged — please try again.', 'cookie-notice' )
				];

				return $response;
			}
			// ── End quick_config unreachable guard ───────────────────

			if ( $response->status !== 200 ) {
				$status_data['status'] = 'pending';

				// update app status
				if ( $network )
					update_site_option( 'cookie_notice_status', $status_data );
				else
					update_option( 'cookie_notice_status', $status_data );

				if ( $app_switched )
					$this->clear_cached_app_config( $network );

				// errors?
				if ( ! empty( $response->error ) )
					return $response;

				// errors?
				if ( ! empty( $response->message ) ) {
					$response->error = $response->message;
					return $response;
				}
			}
		}

		// Notify / activate the app (both new and pre-existing domains)
		$params = [
			'AppID' => $app_exists->AppID
		];

		$response = $this->request( 'notify_app', $params );

		// ── Begin login unreachable guard ────────────────────────────────
		// "The platform told us something" and "we could not reach the platform"
		// are different answers and only the first may write this row.
		//
		// request() returns an ARRAY, ['error' => …], for BOTH a WP transport
		// error and any text/html response — an nginx 502, a Cloudflare page, a
		// WAF block. A real answer is the json_decode()d object. So is_object()
		// is the discriminator, exactly as in get_app_config()'s last-known-good
		// guard; `$response->status` and `empty( $response->error )` are not.
		//
		// Both read as a FAILURE on an array and then swallow it:
		//
		//   $response->status === 200   NULL === 200   false  -> else branch
		//   empty( $response->error )   true                  -> no break
		//
		// so a blip wrote status='pending' over a healthy row AND fell through to
		// the success return below, handing React subscriptions and a fresh nonce.
		// The customer saw a successful reconnect; their site had just been
		// downgraded to the legacy cookie bar — frontend.php gates the real widget
		// on get_status() === 'active' — with no blocking and no consent records
		// until the next successful pull, up to 12 hours later.
		//
		// This is the same defect as the config-pull P0, on the path a customer
		// reaches when something is ALREADY wrong and they are trying to fix it.
		//
		// Not writing the row at all is the whole point: 'pending' is a claim
		// about what the platform says, and here the platform said nothing.
		if ( ! is_object( $response ) ) {
			// A switch to another app is the exception: its credentials are already stored, so
			// "unchanged" would be false, and the row still describes the app that was left.
			if ( $app_switched ) {
				$this->park_switched_app( $status_data, $network );

				return $this->switched_app_not_loaded_reply();
			}

			$response = (object) [
				'error' => __( 'We could not reach Cookie Compliance to activate this site. Your settings are unchanged — please try again.', 'cookie-notice' )
			];

			return $response;
		}
		// ── End login unreachable guard ──────────────────────────────────

		// Idempotent: "App was already active" means the API app record is already Active
		// (StatusID != Inactive). This happens when WP options were cleared but the API-side
		// app persists from a prior login. Treat it as success — the app IS active.
		$notify_already_active = ! empty( $response->message )
			&& strpos( $response->message, 'already active' ) !== false;

		if ( $response->status === 200 || $notify_already_active ) {
			$response = true;
			$status_data['status'] = 'active';

			// get activation timestamp: a switch to another app starts it over, the one in memory is
			// the app that was left's
			$timestamp = $app_switched ? 0 : $cn->get_cc_activation_datetime();

			// update activation timestamp only for new cookie compliance activations
			$status_data['activation_datetime'] = $timestamp === 0 ? time() : $timestamp;

			// A switch is live only once its own config is pulled: until then the engine and the
			// cached settings are not known, so the row is pending and the pull below makes it active.
			if ( $app_switched )
				$status_data['status'] = 'pending';

			// update app status
			if ( $network )
				update_site_option( 'cookie_notice_status', $status_data );
			else
				update_option( 'cookie_notice_status', $status_data );

			// the pull reads the activation time from memory: it has to be this row's
			if ( $app_switched )
				$cn->set_status_data();

			// Sync config from Designer API for all domains (new + pre-existing)
			// so the Protection tab shows current tracker data (#2130, #2186).
			$pulled = $this->get_app_config( $app_exists->AppID, true, true );

			// A pull that did not make the switched app active left the previous app's cached
			// settings in place, and the site has not got the new app's.
			if ( $app_switched && ( ! is_array( $pulled ) || ( $pulled['status'] ?? '' ) !== 'active' ) ) {
				$this->clear_cached_app_config( $network );

				return $this->switched_app_not_loaded_reply();
			}
		} else {
			$status_data['status'] = 'pending';

			// update app status
			if ( $network )
				update_site_option( 'cookie_notice_status', $status_data );
			else
				update_option( 'cookie_notice_status', $status_data );

			if ( $app_switched )
				$this->clear_cached_app_config( $network );

			// errors?
			if ( ! empty( $response->error ) )
				return $response;

			// errors?
			if ( ! empty( $response->message ) ) {
				$response->error = $response->message;
				return $response;
			}
		}

		// all ok, return subscriptions + fresh nonce
		// A fresh nonce is generated here (after authentication completes) so React
		// can use it for subsequent AJAX calls (e.g. use_license). The welcomeNonce
		// in cnReactData was generated at page load, before login state changed —
		// WP nonces are seeded by user identity so the original may no longer verify.
		$response = (object) [];
		$response->subscriptions = $subscriptions;
		$response->fresh_nonce   = wp_create_nonce( 'cookie-notice-welcome' );

		// Tell React whether this domain already has a subscription assigned
		// so it can skip the LicenseSelectStep for already-subscribed domains.
		$response->app_has_subscription = $app_was_preexisting && ! empty( $app_exists->SubscriptionID );

		return $response;
	}

	/**
	 * A sign-in that moved the site to ANOTHER app did not finish: the credentials are the new
	 * app's, so the site must not stay live on the row of the app it left. Writes the pending row
	 * ( the 'App is not published yet' state: not live, and not claiming an engine or an
	 * activation ) and drops the cached config of the app that was left.
	 *
	 * @param array $status_data The row seeded for the new app.
	 * @param bool  $network     The sign-in's scope.
	 *
	 * @return void
	 */
	private function park_switched_app( array $status_data, $network ) {
		$status_data['status'] = 'pending';

		if ( $network )
			update_site_option( 'cookie_notice_status', $status_data );
		else
			update_option( 'cookie_notice_status', $status_data );

		$this->clear_cached_app_config( $network );
	}

	/**
	 * Drop the cached config pulled for the previous app: the blocking catalogue ( which also
	 * carries the plan-grandfather flags ), the banner design and the regulations. Each is a cache
	 * of the platform's answer that the next successful pull rewrites, and every reader treats
	 * an absent row as 'not loaded yet', as on a site's first connection. The wizard's quick-config
	 * transient is not one of them: it is the owner's own pre-connect choices.
	 *
	 * @param bool $network The sign-in's scope.
	 *
	 * @return void
	 */
	private function clear_cached_app_config( $network ) {
		foreach ( [ 'cookie_notice_app_blocking', 'cookie_notice_app_design', 'cookie_notice_app_regulations' ] as $key )
			Cookie_Notice_Store::delete( $key, $network );
	}

	/**
	 * The reply when the site now uses the chosen app but its config has not come in.
	 *
	 * @return object
	 */
	private function switched_app_not_loaded_reply() {
		return (object) [
			'error' => __( 'Your site now uses that app, but its banner settings have not loaded yet. Reopen this page, or use Pull latest settings, to try again.', 'cookie-notice' )
		];
	}

	/**
	 * Whether a sign-in answer proves the FIRST factor only.
	 *
	 * Partial when the answer says so ( data.partial ) or the token does ( the JWT payload's
	 * Partial claim, read WITHOUT checking the signature: it is only read to refuse, so a forged
	 * claim can make us turn a token down and can never make us accept one ). Fails CLOSED in one
	 * case: a token with the three segments of a JWT whose payload cannot be read. A token that is
	 * not JWT-shaped at all and carries no partial field is an ordinary session token.
	 *
	 * Public only so the dev-only reset in react-admin-ajax.php applies the same rule to the
	 * token it stores; it reads no state and changes none.
	 *
	 * @param mixed $data The data of the sign-in answer.
	 * @return bool
	 */
	public function is_partial_login_response( $data ) {
		// Nothing readable, so nothing that may be used.
		if ( ! is_object( $data ) )
			return true;

		if ( ! empty( $data->partial ) )
			return true;

		$claims = $this->jwt_claims( isset( $data->token ) && is_string( $data->token ) ? $data->token : '' );

		// not JWT-shaped, and the answer did not say partial
		if ( $claims === false )
			return false;

		// JWT-shaped but unreadable: cannot be shown to be a full token
		if ( $claims === null )
			return true;

		return ! empty( $claims['Partial'] );
	}

	/**
	 * The claims of a JWT, with no signature check.
	 *
	 * @param string $token The token.
	 * @return array|false|null The claims; false when the token is not JWT-shaped ( not exactly
	 *                          three dot-separated segments ); null when it is but the payload is
	 *                          not base64url JSON describing an object.
	 */
	private function jwt_claims( $token ) {
		$parts = explode( '.', (string) $token );

		if ( count( $parts ) !== 3 )
			return false;

		// base64url, with the padding a JWT leaves off
		$payload = strtr( $parts[1], '-_', '+/' );
		$payload = $payload . str_repeat( '=', ( 4 - strlen( $payload ) % 4 ) % 4 );
		$json    = base64_decode( $payload, true );

		if ( $json === false )
			return null;

		$claims = json_decode( $json );

		return is_object( $claims ) ? (array) $claims : null;
	}

	/**
	 * Drop the stored session token.
	 *
	 * @param bool $network Network scope.
	 * @return void
	 */
	private function forget_app_token( $network ) {
		Cookie_Notice_Store::delete_transient( 'cookie_notice_app_token', $network );
	}

	/**
	 * The one app in $apps on this site's host, or null.
	 *
	 * The Account API stores a domain as its host, lower-cased and in its ASCII ( punycode ) form,
	 * with a port only when it is not the scheme's default: https://example.com/blog is
	 * example.com. Compares the site's host ( with the same port rule, ignoring www. like the
	 * exact match does, both sides in punycode ) with each app's stored domain. Null unless
	 * EXACTLY ONE app is on it — two could be different customers' sites and nothing here may
	 * pick between them; a lookalike ( notexample.com, a sub-domain, another port, a
	 * homograph ) is not the host.
	 *
	 * @param array|object $apps     Apps from the account's app list.
	 * @param string       $site_url Home URL of this site.
	 * @return object|null
	 */
	private function find_app_on_site_host( $apps, $site_url ) {
		$parts = wp_parse_url( $site_url );

		if ( empty( $parts['host'] ) )
			return null;

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$host   = $parts['host'];

		if ( $port && ! ( ( $scheme === 'http' && $port === 80 ) || ( $scheme === 'https' && $port === 443 ) ) )
			$host .= ':' . $port;

		$host  = $this->host_match_key( $host );
		$found = [];

		foreach ( (array) $apps as $app ) {
			$domain = $this->host_match_key( trim( str_replace( [ 'http://', 'https://' ], '', (string) ( $app->DomainUrl ?? '' ) ), '/' ) );

			if ( $domain === $host )
				$found[] = $app;
		}

		return count( $found ) === 1 ? $found[0] : null;
	}

	/**
	 * A host ( with its port, if any ) in the form find_app_on_site_host() compares: lower-case,
	 * without a leading www., the host part in punycode.
	 *
	 * The Account API keeps the host of a domain as the WHATWG URL parser writes it, so an
	 * internationalised host is stored as xn--mnchen-3ya.de while WordPress may hold
	 * münchen.de. Converts with idn_to_ascii() when the intl extension has it; without it the
	 * lower-cased host is compared as it is, so an internationalised host that is not already
	 * punycode simply does not match ( the API's error is shown, as before ).
	 *
	 * @param string $domain Host, optionally followed by :port.
	 * @return string
	 */
	private function host_match_key( $domain ) {
		$domain = preg_replace( '/^www\./', '', strtolower( $domain ) );

		// not a plain host[:port] ( a path, an IPv6 literal ): compared as it is
		if ( ! preg_match( '/^([^:\/]+)(:\d+)?$/', $domain, $match ) )
			return $domain;

		$host = $match[1];

		if ( preg_match( '/[^\x20-\x7e]/', $host ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host );

			if ( is_string( $ascii ) && $ascii !== '' )
				$host = strtolower( $ascii );
		}

		return $host . ( isset( $match[2] ) ? $match[2] : '' );
	}

	/**
	 * Start an app this site has JUST created on the New engine (DEC-022).
	 *
	 * Best effort and invisible to the owner: any failure is logged and nothing else, and
	 * the signup or login carries on exactly as it would have without this call. A
	 * failure — transport error, an error page, a refusal in the body (plugin_below_floor,
	 * app_not_found) — leaves the app on the Classic engine, which works. A timeout is the
	 * exception: the outcome is unknown, as the platform may still complete the switch
	 * after we stop waiting. A later config pull then reads the New engine and the owner
	 * may see the one-time "engine changed" notice — accepted, rare (Designer slower than
	 * 10 s).
	 *
	 * Called only with the AppID of the create response the caller holds. Never pass a
	 * stored AppID: a site re-connecting to another account still has its PREVIOUS app
	 * stored, and that app must keep its engine.
	 *
	 * @param string $app_id AppID of the app just created.
	 * @return bool Whether the platform confirmed the switch.
	 */
	private function start_new_app_on_v2( $app_id ) {
		// 10s, not the shared 60s: signup waits on this, and a slow answer costs only the
		// New engine, never the app.
		$result = $this->request( 'widget_version_self', [ 'AppID' => (string) $app_id, 'WidgetVersion' => 'v2' ], [ 'timeout' => 10 ] );

		// Asks "did it succeed?", so an array (transport error, text/html page) fails closed.
		if ( is_object( $result ) && isset( $result->status ) && $result->status === 200 && isset( $result->data->WidgetVersion ) && $result->data->WidgetVersion === 'v2' )
			return true;

		if ( Cookie_Notice()->options['general']['debug_mode'] )
			error_log( '[Cookie Notice] new app ' . $app_id . ': switch to the New engine not confirmed (app stays Classic unless the platform completed it late): ' . $this->debug_reply_summary( $result ) );

		return false;
	}

	/**
	 * Callback for map_deep that leaves booleans (and null) untouched.
	 * sanitize_text_field casts non-strings to string first, which turns
	 * true → "1" and false → "", corrupting BannerConfigJSON booleans like
	 * gpcSupportMode on every get_app_config() round-trip. Use this callback
	 * whenever the decoded payload contains real booleans we need to preserve.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public function sanitize_preserve_bools( $value ) {
		if ( is_bool( $value ) || is_null( $value ) ) {
			return $value;
		}
		return sanitize_text_field( $value );
	}

	/**
	 * Dev-only proxy to request(), for the test-reset helper.
	 *
	 * react-admin-ajax.php's dev_reset deletes the orphan app it created, which needs
	 * request(). It called `Cookie_Notice()->welcome->request( … )` — two faults at once:
	 * ->welcome is Cookie_Notice_Welcome, which has no request() at all, and this class's
	 * request() is private. Either one is a fatal, so the app deletion has never run and
	 * every dev reset has leaked an orphan app.
	 *
	 * Exposed through a named dev-only door rather than by making request() public: the
	 * reason it is private is that callers must not choose arbitrary endpoints, and that
	 * reason does not stop being true because a test helper is convenient. The guards
	 * below mean this does not exist on a production site.
	 *
	 * @param string $request
	 * @param array  $params
	 * @return object|array
	 */
	public function dev_request( $request = '', $params = [] ) {
		if ( ! defined( 'CN_DEV_MODE' ) || ! CN_DEV_MODE )
			return [ 'error' => 'dev_request is unavailable.' ];

		if ( ! current_user_can( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) ) )
			return [ 'error' => 'Insufficient permissions.' ];

		// Assigned rather than returned inline, so the census in
		// tests/unit/request-array-response-guards.php can classify it. That check
		// caught this call the moment it was added, which is the check working.
		$dev_result = $this->request( $request, $params );

		return $dev_result;
	}

	/**
	 * API request.
	 *
	 * @param string $request The requested action.
	 * @param array $params Parameters for the API action.
	 * @return string|array
	 */
	private function request( $request = '', $params = [], $args_override = [] ) {
		// get main instance
		$cn = Cookie_Notice();

		// ── Begin shared-app choke point ─────────────────────────────────────────
		// THE GATE THAT CANNOT BE FORGOTTEN. Every call this plugin makes to the Cookie
		// Compliance platform funnels through this one method, so a mutating request against
		// an app other sites serve is refused here whatever handler issued it — including one
		// written next year that nobody thought to gate.
		//
		// (One wp_remote_post() lives outside this method, in Cookie_Notice::deactivate_plugin()
		// — a feedback form that touches no app record and is gated on install_plugins, which
		// is super-admin-only on multisite. It is the only one; verified by sweep.)
		//
		// This exists because per-handler gates were the wrong structure, not merely an
		// incomplete set: four separate review rounds each found another handler reaching the
		// same shared Designer record, and a fifth found that a brand-new ungated handler
		// passed the whole test suite. Enumerating the callers is what failed.
		//
		// SO THE LIST BELOW IS OF WHAT IS SAFE, NOT OF WHAT IS DANGEROUS, and everything else
		// is gated. A denylist of "the mutating types" is the same enumeration bug one level
		// down — an earlier revision listed three and missed five, including the subscription
		// and payment types that bind a paid plan to a named AppID. Inverted, a request type
		// added to the switch below and forgotten here is REFUSED rather than waved through,
		// which is the failure direction we can afford.
		//
		// is_user_logged_in() is the discriminator, NOT did_action( 'admin_init' ): this
		// method also runs on cron and on the app-secret-authenticated REST purge route, where
		// there is no user at all and current_user_can() would refuse a legitimate push.
		// Every path an ordinary administrator can reach is a logged-in one. It is the RIGHT
		// operand so the cheap array test short-circuits first — is_user_logged_in() resolves
		// the current user, and doing that on every read would reintroduce the very cost
		// get_app_config()'s gate rejected it for.
		//
		// Refusing BEFORE the HTTP call is the point — a refusal afterwards would leave the
		// platform already changed.
		$cn_non_mutating = [
			// reads
			'get_config', 'get_analytics', 'get_cookie_consent_logs', 'get_privacy_consent_logs',
			'list_apps', 'get_token', 'get_customer', 'get_subscriptions',
			// bring a NEW app or session into existence rather than mutating a shared one
			'register', 'login', 'app_create',
			// the second step of a sign-in: no Bearer, no app, no change to anything shared
			'login_authcode', 'login_totp', 'login_backupcode', 'send_authcode',
		];

		if ( ! in_array( $request, $cn_non_mutating, true ) && is_user_logged_in() ) {
			$target_app_id = isset( $params['AppID'] ) && $params['AppID'] !== ''
				? (string) $params['AppID']
				: (string) ( isset( $cn->options['general']['app_id'] ) ? $cn->options['general']['app_id'] : '' );

			if ( $cn->is_network_shared_app( $target_app_id ) && ! $cn->can_write_at_scope( true ) )
				return (object) [
					'status'	=> 403,
					'message'	=> $cn->network_scope_denied_message(),
					'error'		=> $cn->network_scope_denied_message()
				];
		}
		// ── End shared-app choke point ───────────────────────────────────────────

		// Self-reported client metadata — lets backend correlate cancellation
		// with integration client (WordPress plugin, future Shopify app, etc.)
		// and with the UI mode in use. Banner (JS widget) does NOT send these.
		//
		// Cn-Client-Version now carries the version of the code that is RUNNING.
		// It previously carried $cn->db_version — the version at the last
		// COMPLETED upgrade routine — which lags the running code and is why the
		// platform recorded 2.5.x for sites on current code. See
		// cn_get_integration_telemetry().
		$cn_ui_mode = isset( $cn->options['general']['ui_mode'] ) ? $cn->options['general']['ui_mode'] : 'legacy';
		$cn_telemetry = cn_get_integration_telemetry();
		$cn_plugin_version = isset( $cn_telemetry['version'] ) ? $cn_telemetry['version'] : '';

		// request arguments
		$api_args = [
			'timeout'	=> self::REQUEST_TIMEOUT,
			'headers'	=> [
				'x-api-key'			=> $cn->get_api_key(),
				'Cn-Client'			=> 'wordpress',
				'Cn-Client-Version'	=> $cn_plugin_version,
				'Cn-Client-Ui-Mode'	=> $cn_ui_mode,
			]
		];

		// The rest of the telemetry rides ONE further header rather than a header
		// per field, so a later field is a value change instead of a new header
		// for the backend to learn. Omitted entirely when there is nothing safe
		// to send.
		$cn_env = cn_encode_integration_telemetry( $cn_telemetry );

		if ( $cn_env !== '' )
			$api_args['headers']['Cn-Client-Env'] = $cn_env;

		// request parameters
		$api_params = [];

		// whether data should be send in json
		$json = false;

		// whether application id is required
		$require_app_id = false;

		// is it network admin area
		$network = $cn->is_network_admin();

		// get app token data
		if ( $network )
			$data_token = get_site_transient( 'cookie_notice_app_token' );
		else
			$data_token = get_transient( 'cookie_notice_app_token' );

		// check api token
		$api_token = ! empty( $data_token->token ) ? $data_token->token : '';

		switch ( $request ) {
			case 'register':
				$api_url = $cn->get_url( 'account_api', '/api/account/account/registration' );
				$api_args['method'] = 'POST';
				break;

			case 'login':
				$api_url = $cn->get_url( 'account_api', '/api/account/account/login' );
				$api_args['method'] = 'POST';
				break;

			// The second step of a sign-in. POST under account/account, no Bearer: the partial token
			// travels in the body, as the Admin Portal sends it.
			case 'login_authcode':
				$api_url = $cn->get_url( 'account_api', '/api/account/account/login-authcode' );
				$api_args['method'] = 'POST';
				break;

			case 'login_totp':
				$api_url = $cn->get_url( 'account_api', '/api/account/account/login-totp' );
				$api_args['method'] = 'POST';
				break;

			case 'login_backupcode':
				$api_url = $cn->get_url( 'account_api', '/api/account/account/login-backupcode' );
				$api_args['method'] = 'POST';
				break;

			case 'send_authcode':
				$api_url = $cn->get_url( 'account_api', '/api/account/account/send-authcode' );
				$api_args['method'] = 'POST';
				break;

			case 'list_apps':
				$api_url = $cn->get_url( 'account_api', '/api/account/app/list' );
				$api_args['method'] = 'GET';
				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'Authorization' => 'Bearer ' . $api_token
					]
				);
				break;

			case 'app_create':
				$api_url = $cn->get_url( 'account_api', '/api/account/app/add' );
				$api_args['method'] = 'POST';
				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'Authorization' => 'Bearer ' . $api_token
					]
				);
				break;

			// DEV ONLY: Delete an app record from the Account API by AppID.
			// Used by dev_reset() (CN_DEV_MODE only) so test runs don't orphan app slots.
			// Requires a Bearer token (obtained via login) to pass the auth middleware.
			case 'app_delete':
				$api_url = $cn->get_url( 'account_api', '/api/account/app/delete' );
				$api_args['method'] = 'POST';
				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'Authorization' => 'Bearer ' . $api_token
					]
				);
				break;

			case 'get_analytics':
				$require_app_id = true;
				$api_url = $cn->get_url( 'transactional_api', '/api/transactional/analytics/analytics-data' );
				$api_args['method'] = 'GET';

				$diff_data = $cn->settings->get_analytics_app_data();

				if ( ! empty( $diff_data ) ) {
					$app_data = [
						'app-id'			=> $diff_data['id'],
						'app-secret-key'	=> $diff_data['key']
					];
				} else {
					$app_data = [
						'app-id'			=> $cn->options['general']['app_id'],
						'app-secret-key'	=> $cn->options['general']['app_key']
					];
				}

				$api_args['headers'] = array_merge( $api_args['headers'], $app_data );
				break;

			case 'get_cookie_consent_logs':
				$require_app_id = true;
				$api_url = $cn->get_url( 'transactional_api', '/api/transactional/analytics/consent-logs' );
				$api_args['method'] = 'POST';
				$api_args['headers']['app-id'] = $cn->options['general']['app_id'];
				$api_args['headers']['app-secret-key'] = $cn->options['general']['app_key'];
				break;

			case 'get_privacy_consent_logs':
				$require_app_id = true;
				$api_url = $cn->get_url( 'transactional_api', '/api/transactional/privacy/consent-logs' );
				$api_args['method'] = 'POST';
				$api_args['headers']['app-id'] = $cn->options['general']['app_id'];
				$api_args['headers']['app-secret-key'] = $cn->options['general']['app_key'];
				break;

			// GET /user-design-live — the PUBLISHED record, never the draft. Anything the
			// customer has saved in the Portal but not published is deliberately not here,
			// so a value this plugin pushed is only reflected back once it is live. See the
			// draft/published note on patch_by_app below.
			case 'get_config':
				$require_app_id = true;
				$api_url = $cn->get_url( 'designer_api', '/api/designer/user-design-live' );
				$api_args['method'] = 'GET';
				break;

			case 'quick_config':
				$require_app_id = true;
				$json = true;
				$api_url = $cn->get_url( 'designer_api', '/api/designer/user-design/quick' );
				$api_args['method'] = 'POST';
				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'Authorization'	=> 'Bearer ' . $api_token,
						'Content-Type'	=> 'application/json; charset=utf-8'
					]
				);
				break;

			// PATCH /application/widget-version/self — the customer's own engine switch. The
			// platform enforces its version floor and upgrade-only rule (DEC-021). Called only
			// by start_new_app_on_v2() for an app this site has just created (DEC-022).
			// Bearer-authenticated like user-design/quick above: the route takes a login token,
			// never app-id/secret. NOT on the safe list: it changes the app.
			case 'widget_version_self':
				$require_app_id = true;
				$json = true;
				$api_url = $cn->get_url( 'designer_api', '/api/designer/application/widget-version/self' );
				$api_args['method'] = 'PATCH';
				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'Authorization'	=> 'Bearer ' . $api_token,
						'Content-Type'	=> 'application/json; charset=utf-8'
					]
				);
				break;

			// PATCH /user-design/by-app/:AppID — partial update for connected apps (#1913).
			// AppID is pulled from params and placed in the URL; remaining params go in the body.
			//
			// DRAFT vs PUBLISHED — the model this plugin has to hold, because it holds none
			// of its own. The Designer API keeps two records per app: UserDesign, a DRAFT
			// the customer edits in the Portal, and UserDesignLive, the PUBLISHED snapshot.
			// The widget, and get_config below, read the PUBLISHED one only.
			//
			// A write here lands in the draft AND is applied to the published record — but
			// only the keys this request sent, merged onto what is already live. It does
			// NOT publish the draft. That distinction is load-bearing: the Portal has
			// draft-only saves (custom cookies and providers on Autoblocking; plain "Save"
			// on Languages, which sits next to its own "Save and Publish") and tells the
			// customer in as many words that they are not served to visitors until they
			// press Publish Now. Publishing the draft from here, which is what this
			// endpoint used to do, took that work live behind their back — and a pending
			// REMOVAL of a blocking rule went live as a tracker firing before consent.
			//
			// One exception: an app that has never been published has no live record to
			// apply to, so the API falls back to a full publish there. Nothing can be taken
			// live prematurely in that case because nothing was ever live.
			//
			// So: send only keys this plugin owns, and do not assume a write here publishes
			// anything else. Whatever else sits in the customer's draft stays there.
			// Used by react_update_design(), react_apply_languages(),
			// react_save_banner_style() and the configure (laws/wizard) flow — replaces
			// quick_config for existing apps.
			case 'patch_by_app':
				$patch_app_id = isset( $params['AppID'] ) ? $params['AppID'] : '';
				unset( $params['AppID'] );  // AppID goes in URL, not body.
				$require_app_id = false;    // We handle the empty-check ourselves below.
				$json = true;
				$api_url = $cn->get_url( 'designer_api', '/api/designer/user-design/by-app/' . rawurlencode( $patch_app_id ) );
				$api_args['method'] = 'PATCH';

				// Designer API /by-app endpoint uses authenticateApp middleware —
				// expects app-id + app-secret-key headers (NOT Bearer token).
				// Both are stored in WP options from the register/login flow.
				//
				// A caller that knows which row its AppID came from passes the matching
				// key and it wins. Deriving the key from is_network_admin() while the id
				// came from somewhere else is how the two used to disagree; the 403 that
				// produced looked like an authorisation control and was not one.
				if ( isset( $params['AppSecretKey'] ) ) {
					$patch_app_key = (string) $params['AppSecretKey'];

					unset( $params['AppSecretKey'] );	// header, not body.
				} else {
					$network = $cn->is_network_admin();
					$patch_app_key = $network
						? $cn->network_options['general']['app_key']
						: $cn->options['general']['app_key'];
				}

				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'app-id'         => $patch_app_id,
						'app-secret-key' => $patch_app_key,
						'Content-Type'   => 'application/json; charset=utf-8',
					]
				);
				break;

			case 'notify_app':
				$require_app_id = true;
				$json = true;
				$api_url = $cn->get_url( 'account_api', '/api/account/app/notifyAppPublished' );
				$api_args['method'] = 'POST';
				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'Authorization'	=> 'Bearer ' . $api_token,
						'Content-Type'	=> 'application/json; charset=utf-8'
					]
				);
				break;

			// braintree init token
			case 'get_token':
				$api_url = $cn->get_url( 'account_api', '/api/account/braintree' );
				$api_args['method'] = 'GET';
				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'Authorization' => 'Bearer ' . $api_token
					]
				);
				break;

			// braintree get customer
			case 'get_customer':
				$require_app_id = true;
				$json = true;
				$api_url = $cn->get_url( 'account_api', '/api/account/braintree/findcustomer' );
				$api_args['method'] = 'POST';
				$api_args['data_format'] = 'body';
				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'Authorization'	=> 'Bearer ' . $api_token,
						'Content-Type'	=> 'application/json; charset=utf-8'
					]
				);
				break;

			// braintree create customer in vault
			case 'create_customer':
				$require_app_id = true;
				$json = true;
				$api_url = $cn->get_url( 'account_api', '/api/account/braintree/createcustomer' );
				$api_args['method'] = 'POST';
				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'Authorization'	=> 'Bearer ' . $api_token,
						'Content-Type'	=> 'application/json; charset=utf-8'
					]
				);
				break;

			// braintree get subscriptions
			case 'get_subscriptions':
				$require_app_id = true;
				$json = true;
				$api_url = $cn->get_url( 'account_api', '/api/account/braintree/subscriptionlists' );
				$api_args['method'] = 'POST';
				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'Authorization'	=> 'Bearer ' . $api_token,
						'Content-Type'	=> 'application/json; charset=utf-8'
					]
				);
				break;

			// braintree create subscription
			case 'create_subscription':
				$require_app_id = true;
				$json = true;
				$api_url = $cn->get_url( 'account_api', '/api/account/braintree/createsubscription' );
				$api_args['method'] = 'POST';
				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'Authorization'	=> 'Bearer ' . $api_token,
						'Content-Type'	=> 'application/json; charset=utf-8'
					]
				);
				break;

			// braintree assign subscription
			case 'assign_subscription':
				$require_app_id = true;
				$json = true;
				$api_url = $cn->get_url( 'account_api', '/api/account/braintree/assignsubscription' );
				$api_args['method'] = 'POST';
				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'Authorization'	=> 'Bearer ' . $api_token,
						'Content-Type'	=> 'application/json; charset=utf-8'
					]
				);
				break;

			// braintree create payment method
			case 'create_payment_method':
				$require_app_id = true;
				$json = true;
				$api_url = $cn->get_url( 'account_api', '/api/account/braintree/createpaymentmethod' );
				$api_args['method'] = 'POST';
				$api_args['headers'] = array_merge(
					$api_args['headers'],
					[
						'Authorization'	=> 'Bearer ' . $api_token,
						'Content-Type'	=> 'application/json; charset=utf-8'
					]
				);
				break;
		}

		// check if app id is required to avoid unneeded requests
		if ( $require_app_id ) {
			$empty_app_id = false;

			// check app id
			if ( array_key_exists( 'AppID', $params ) && is_string( $params['AppID'] ) ) {
				$app_id = trim( $params['AppID'] );

				// empty app id?
				if ( $app_id === '' )
					$empty_app_id = true;
			} else
				$empty_app_id = true;

			if ( $empty_app_id )
				return [ 'error' => esc_html__( '"AppID" is not allowed to be empty.', 'cookie-notice' ) ];
		}

		if ( ! empty( $params ) && is_array( $params ) ) {
			foreach ( $params as $key => $param ) {
				if ( is_object( $param ) )
					$api_params[$key] = $param;
				elseif ( is_array( $param ) )
					$api_params[$key] = array_map( 'sanitize_text_field', $param );
				elseif ( $key === 'Password' && ( $request === 'register' || $request === 'login' ) )
					$api_params[$key] = preg_replace( '/[^\w !"#$%&\'()*\+,\-.\/:;<=>?@\[\]^\`\{\|\}\~\\\\]/', '', $param );
				else
					$api_params[$key] = sanitize_text_field( $param );
			}

			// for GET requests, append params as query string instead of body
			if ( $api_args['method'] === 'GET' )
				$api_url = add_query_arg( $api_params, $api_url );
			elseif ( $json )
				$api_args['body'] = wp_json_encode( $api_params );
			else
				$api_args['body'] = $api_params;
		}

		// ── Begin per-call transport override
		// Applied last, and with $args_override as the RIGHT operand, so a caller with a
		// tighter budget than the shared 60s cannot have it undone by a case above. The
		// operand order is the whole behaviour: swapped, every override silently loses.
		if ( ! empty( $args_override ) )
			$api_args = array_merge( $api_args, $args_override );
		// ── End per-call transport override

		$response = wp_remote_request( $api_url, $api_args );

		if ( is_wp_error( $response ) )
			$result = [ 'error' => $response->get_error_message() ];
		else {
			$content_type = wp_remote_retrieve_header( $response, 'Content-Type' );

			// html response, means error
			if ( $content_type == 'text/html' )
				$result = [ 'error' => esc_html__( 'Unexpected error occurred. Please try again later.', 'cookie-notice' ) ];
			else {
				$result = wp_remote_retrieve_body( $response );

				// detect json or array
				$result = is_array( $result ) ? $result : json_decode( $result );
			}
		}

		return $result;
	}

	/**
	 * Check whether WP Cron needs to add new task.
	 *
	 * @return void
	 */
	public function check_cron() {
		// get main instance
		$cn = Cookie_Notice();

		if ( is_multisite() && $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'] ) {
			$app_id = $cn->network_options['general']['app_id'];
			$app_key = $cn->network_options['general']['app_key'];
		} else {
			$app_id = $cn->options['general']['app_id'];
			$app_key = $cn->options['general']['app_key'];
		}

		// compliance active only
		if ( $app_id !== '' && $app_key !== '' ) {
			// ── Begin config pull scheduling
			// twicedaily, not daily: this pull is what refreshes the stored config
			// snapshot, and that snapshot is what the admin screens report, what the
			// quota/threshold state is read from, and what frontend.php seeds into
			// huOptions on a cold pageview. Halving the interval halves how long any of
			// them can disagree with the platform. The publish-time
			// purge (rest_purge_cache) is the primary freshness mechanism and reaches a
			// healthy 3.1.3+ site in seconds; this cron is the fallback for the tail it
			// misses, so halving its interval halves the worst case for those sites.
			// A custom 6-hour schedule was considered and rejected — twicedaily is a core
			// built-in and needs no cron_schedules registration to carry.
			if ( $cn->get_status() === 'active' )
				$recurrence = 'twicedaily';
			else
				$recurrence = 'hourly';

			// set schedule if needed
			if ( ! wp_next_scheduled( 'cookie_notice_get_app_analytics' ) )
				wp_schedule_event( time(), 'hourly', 'cookie_notice_get_app_analytics' );

			// A wp_next_scheduled() guard ALONE pins an install to whatever recurrence it
			// was FIRST registered with: the guard stays true forever after, so changing
			// $recurrence above would reach new installs only and leave every existing
			// site on its original cadence indefinitely. Compare the registered schedule
			// and re-register when it differs. check_cron() is hooked on `init`, so an
			// upgraded install self-heals on its first page load — no migration routine,
			// and nothing to run for sites that upgrade while nobody is looking.
			//
			// Re-registering at time() makes that first pull happen immediately rather
			// than at the old schedule's next due time. Deliberate: an install upgrading
			// with a snapshot already 20+ hours old would otherwise keep serving it for
			// most of another day, which is the state the shorter cadence exists to end.
			// Cost is one extra pull per install, spread over however long the release
			// takes to roll out — and the analytics job on the same installs already runs
			// hourly, so this cadence is not new load for the endpoint.
			//
			// get_status() is read from stored status data and only moves when the API
			// says so, so this cannot thrash on ordinary page loads.
			$scheduled = wp_get_schedule( 'cookie_notice_get_app_config' );

			if ( $scheduled === false )
				wp_schedule_event( time(), $recurrence, 'cookie_notice_get_app_config' );
			elseif ( $scheduled !== $recurrence ) {
				wp_clear_scheduled_hook( 'cookie_notice_get_app_config' );
				wp_schedule_event( time(), $recurrence, 'cookie_notice_get_app_config' );
			}
			// ── End config pull scheduling
		} else {
			// remove schedule if needed
			if ( wp_next_scheduled( 'cookie_notice_get_app_analytics' ) )
				wp_clear_scheduled_hook( 'cookie_notice_get_app_analytics' );

			// remove schedule if needed
			if ( wp_next_scheduled( 'cookie_notice_get_app_config' ) )
				wp_clear_scheduled_hook( 'cookie_notice_get_app_config' );
		}
	}

	/**
	 * Get privacy consent logs.
	 *
	 * @return string|array
	 */
	public function get_privacy_consent_logs() {
		// get main instance
		$cn = Cookie_Notice();

		// Consent-log access is NOT quota-gated (#1851 reversed, user decision
		// 2026-08-17). Returning [] here off a locally cached flag told customers whose
		// records exist perfectly well that they had none — the exact opposite of being
		// able to demonstrate consent, and wrong for Pro apps too whenever the cached
		// plan had gone stale. If a usage limit on this is ever wanted again it belongs
		// server-side next to the authoritative plan, not in a cached client flag.

		// get consent logs for specific date
		$result = $this->request(
			'get_privacy_consent_logs',
			[
				'AppID'			=> $cn->options['general']['app_id'],
				'AppSecretKey'	=> $cn->options['general']['app_key'],
				'Latest'		=> 100
			]
		);

		// ── Begin consent-log unreachable guard ──────────────────────────────
		// "We could not reach the platform" and "you have no consent records" are
		// opposite answers, and this method used to give the same one for both: the
		// chain below is all-negative, so on an array every arm is false and it fell
		// to `$result = []`. react-admin-ajax.php then answered wp_send_json_success
		// with zero logs.
		//
		// An admin pulling consent records for a DSAR or a regulator during a
		// Transactional API blip was shown an EMPTY LOG AS A SUCCESS — told, on the
		// one screen whose whole purpose is demonstrating consent, that they had
		// none. That is the failure mode this feature exists to prevent, and it is
		// indistinguishable from the real thing unless we say so here.
		//
		// WP_Error, not a string: the two legacy consumers already route a non-array
		// to wp_send_json_error, but the two React ones test `! is_array( $raw ) ||
		// empty( $raw )` and answer success either way, so a plain string was ALSO
		// rendered as "0 records". A type the callers must handle explicitly is the
		// only shape that cannot be mistaken for emptiness.
		if ( ! is_object( $result ) ) {
			return new WP_Error(
				'cn_consent_logs_unreachable',
				__( 'We could not reach Cookie Compliance to load your consent records. This does not mean there are none on file — please try again in a moment.', 'cookie-notice' )
			);
		}
		// ── End consent-log unreachable guard ────────────────────────────────
		// message?
		if ( ! empty( $result->message ) )
			$result = $result->message;
		// error?
		elseif ( ! empty( $result->error ) )
			$result = $result->error;
		// valid data?
		elseif ( ! empty( $result->data ) )
			$result = $result->data;
		else
			$result = [];
		return $result;
	}

	/**
	 * Get cookie consent logs.
	 *
	 * @param string $date     Start date (Y-m-d).
	 * @param string $end_date Optional end date (Y-m-d). Omit for single-day query.
	 *
	 * @return string|array
	 */
	public function get_cookie_consent_logs( $date, $end_date = '' ) {
		// get main instance
		$cn = Cookie_Notice();

		// not quota-gated — see get_privacy_consent_logs() for why the local gate went

		$params = [
			'AppID'			=> $cn->options['general']['app_id'],
			'AppSecretKey'	=> $cn->options['general']['app_key'],
			'Date'			=> $date,
		];

		if ( $end_date !== '' && $end_date !== $date ) {
			$params['EndDate'] = $end_date;
		}

		$result = $this->request( 'get_cookie_consent_logs', $params );

		// ── Begin consent-log unreachable guard ──────────────────────────────
		// "We could not reach the platform" and "you have no consent records" are
		// opposite answers, and this method used to give the same one for both: the
		// chain below is all-negative, so on an array every arm is false and it fell
		// to `$result = []`. react-admin-ajax.php then answered wp_send_json_success
		// with zero logs.
		//
		// An admin pulling consent records for a DSAR or a regulator during a
		// Transactional API blip was shown an EMPTY LOG AS A SUCCESS — told, on the
		// one screen whose whole purpose is demonstrating consent, that they had
		// none. That is the failure mode this feature exists to prevent, and it is
		// indistinguishable from the real thing unless we say so here.
		//
		// WP_Error, not a string: the two legacy consumers already route a non-array
		// to wp_send_json_error, but the two React ones test `! is_array( $raw ) ||
		// empty( $raw )` and answer success either way, so a plain string was ALSO
		// rendered as "0 records". A type the callers must handle explicitly is the
		// only shape that cannot be mistaken for emptiness.
		if ( ! is_object( $result ) ) {
			return new WP_Error(
				'cn_consent_logs_unreachable',
				__( 'We could not reach Cookie Compliance to load your consent records. This does not mean there are none on file — please try again in a moment.', 'cookie-notice' )
			);
		}
		// ── End consent-log unreachable guard ────────────────────────────────
		// message?
		if ( ! empty( $result->message ) )
			$result = $result->message;
		// error?
		elseif ( ! empty( $result->error ) )
			$result = $result->error;
		// valid data?
		elseif ( ! empty( $result->data ) )
			$result = $result->data;
		else
			$result = [];

		return $result;
	}

	/**
	 * Normalize a cycleUsage node to an object.
	 *
	 * It reaches us as a stdClass from get_config (the whole AnalyticsData branch
	 * keeps its object shape through map_deep) but as an array element from
	 * get_analytics (which casts $response->data to an array first). Both callers
	 * want the same reads, so flatten the difference once, here.
	 *
	 * @param object|array|null $cycle_usage
	 * @return object
	 */
	private function normalize_cycle_usage( $cycle_usage ) {
		if ( is_object( $cycle_usage ) )
			return $cycle_usage;

		return (object) ( is_array( $cycle_usage ) ? $cycle_usage : [] );
	}

	/**
	 * Pull a cycleUsage node out of an AnalyticsData blob, whatever shape it arrived in.
	 *
	 * get_config keeps AnalyticsData as an object through map_deep; some caches and
	 * the get_analytics option round-trip hand it over as an array. Callers that
	 * only test is_object() silently drop a perfectly good snapshot.
	 *
	 * @param object|array|null $analytics
	 * @return object|null  Normalized cycleUsage, or null when the node is absent.
	 */
	private function cycle_usage_from_analytics_data( $analytics ) {
		if ( is_array( $analytics ) )
			$analytics = (object) $analytics;

		if ( ! is_object( $analytics ) || ! isset( $analytics->cycleUsage ) )
			return null;

		return $this->normalize_cycle_usage( $analytics->cycleUsage );
	}

	/**
	 * Read visit counters from a stored analytics option (or an AnalyticsData blob).
	 *
	 * Three shapes all occur in production and previously collapsed to 0:
	 *
	 *   1. cycleUsage as a stdClass (get_config / a PHP-serialized option)
	 *   2. cycleUsage as an array (get_analytics casts $response->data to array
	 *      first; Redis/JSON object-caches do the same on the way back out)
	 *   3. cycleUsage absent, VisitThreshold stamped only at the blob root
	 *      (`AnalyticsData.threshold`) — Designer API does this when the Daywise
	 *      job has not yet materialised a cycleUsage node
	 *
	 * React interpolates `{sessionTotal}` from this number. Returning 0 for a
	 * Free plan is what painted "Free protects up to 0 visits/month" until a
	 * later refresh happened to hit a readable snapshot.
	 *
	 * Does NOT invent a Free-plan default. A real 0/null threshold is Pro
	 * (unlimited) and must stay 0 so nothing arms.
	 *
	 * @param array|object|null $analytics  cookie_notice_app_analytics value
	 * @return array{visits:int,threshold:int}
	 */
	public function read_cycle_usage_counters( $analytics ) {
		if ( is_object( $analytics ) )
			$analytics = get_object_vars( $analytics );

		if ( ! is_array( $analytics ) )
			$analytics = [];

		$usage = $this->normalize_cycle_usage( isset( $analytics['cycleUsage'] ) ? $analytics['cycleUsage'] : null );

		// isset, not empty(): visits arrives as the string "0" (live payload) and
		// empty("0") is true in PHP, which would throw a real zero away if we
		// ever needed to tell "zero" from "missing". (int) "0" is 0 either way.
		$visits = isset( $usage->visits ) ? (int) $usage->visits : 0;

		$threshold = 0;

		if ( isset( $usage->threshold ) && $usage->threshold !== null && $usage->threshold !== '' )
			$threshold = (int) $usage->threshold;

		// Designer API stamps plan.VisitThreshold on the blob root even when
		// cycleUsage itself is missing. See userDesignLive.controller.ts.
		if ( $threshold <= 0 && isset( $analytics['threshold'] ) && $analytics['threshold'] !== null && $analytics['threshold'] !== '' )
			$threshold = (int) $analytics['threshold'];

		return [
			'visits'    => $visits,
			'threshold' => $threshold,
		];
	}

	/**
	 * Age of a cycleUsage snapshot, in hours, from the payload's own clock.
	 *
	 * Reads cycleUsage.fetch_time — when the number was COMPUTED — not our
	 * lastUpdated, which only records when we pulled it. Returns null when the
	 * payload carries no usable fetch_time, i.e. when the age is unknowable.
	 *
	 * @param object|array|null $cycle_usage
	 * @return float|null
	 */
	public function cycle_usage_age_hours( $cycle_usage ) {
		$usage = $this->normalize_cycle_usage( $cycle_usage );

		if ( empty( $usage->fetch_time ) )
			return null;

		$fetched = strtotime( (string) $usage->fetch_time . ' UTC' );

		if ( $fetched === false )
			return null;

		return ( current_time( 'timestamp', true ) - $fetched ) / HOUR_IN_SECONDS;
	}

	/**
	 * Is a cycleUsage snapshot fresh enough to enforce a quota on?
	 *
	 * The visit counter is materialised once a day by the Daywise Analytics job
	 * (measured in prod: 01:00 UTC, ~2 min, every day), and the plugin then pulls
	 * it on WP pseudo-cron, which only fires when somebody loads the site. Two
	 * hops, the second unbounded on a low-traffic site — and the blob we cache
	 * records neither, because lastUpdated is stamped when WE pulled rather than
	 * when the number was computed. So the snapshot has to prove itself:
	 *
	 *   1. fetch_time must fall inside the cycle it claims to describe. A blob
	 *      carried across a cycle rollover still holds last cycle's total, which
	 *      is the one way a stale number reads HIGH rather than low (HS#47302:
	 *      a reconnect left visits=1261 against a fresh cycle).
	 *   2. fetch_time must be no older than twice the job interval.
	 *
	 * Anything we cannot prove fresh fails OPEN. Under-enforcing a free quota for
	 * a day costs a rounding error of usage; over-enforcing on a paying customer
	 * costs pre-consent tracker leakage and a support ticket. This is the same bar
	 * the backend already applies before firing a threshold email — Node Cron Job
	 * app.logic.ts::findAppsForThresholdAction requires fetch_time::date = current_date.
	 *
	 * @param object|array|null $cycle_usage
	 * @return bool
	 */
	public function cycle_usage_is_fresh( $cycle_usage ) {
		$usage = $this->normalize_cycle_usage( $cycle_usage );
		$age   = $this->cycle_usage_age_hours( $usage );

		// no fetch_time means we cannot establish the age at all
		if ( $age === null )
			return false;

		// twice the 24h materialisation interval
		if ( $age < 0 || $age > 48 )
			return false;

		$fetched = strtotime( (string) $usage->fetch_time . ' UTC' );

		// snapshot must belong to the cycle it describes
		if ( ! empty( $usage->startDate ) ) {
			$start = strtotime( (string) $usage->startDate . ' 00:00:00 UTC' );

			if ( $start !== false && $fetched < $start )
				return false;
		}

		if ( ! empty( $usage->endDate ) ) {
			// endDate is inclusive, so allow the whole of that day
			$end = strtotime( (string) $usage->endDate . ' 23:59:59 UTC' );

			if ( $end !== false && $fetched > $end )
				return false;
		}

		return true;
	}

	/**
	 * Derive threshold_exceeded from a cycleUsage snapshot.
	 *
	 * A quota is only enforced against a snapshot we can prove current; see
	 * cycle_usage_is_fresh() for why that fails open. Pro plans carry
	 * VisitThreshold = NULL, which sanitizes to 0 here and never arms.
	 *
	 * @param object|array|null $cycle_usage
	 * @return bool
	 */
	public function evaluate_threshold_exceeded( $cycle_usage ) {
		$usage = $this->normalize_cycle_usage( $cycle_usage );

		$threshold = ! empty( $usage->threshold ) ? (int) $usage->threshold : 0;
		$visits    = ! empty( $usage->visits ) ? (int) $usage->visits : 0;

		if ( $threshold <= 0 )
			return false;

		if ( ! $this->cycle_usage_is_fresh( $usage ) )
			return false;

		return $visits >= $threshold;
	}

	/**
	 * Get app analytics.
	 *
	 * @param string $app_id
	 * @param bool $force_update
	 * @param bool $force_action
	 *
	 * @return void
	 */
	public function get_app_analytics( $app_id = '', $force_update = false, $force_action = true ) {
		// Same shape as get_app_config()'s gate, for the same reason — this writes
		// cookie_notice_app_analytics and cookie_notice_status network-wide off the same
		// unforgeable predicate, and cookie_notice_status carries threshold_exceeded, which
		// the #2272 force reads to switch autoblocking off. Today it is unreachable by a
		// manage_options-only actor only TRANSITIVELY (both non-cron callers sit behind an
		// is_array( $app_data ) check that now fails when get_app_config() refuses), which is
		// not a property to rely on.
		if ( is_multisite() && Cookie_Notice()->is_network_options() && did_action( 'admin_init' ) && ! Cookie_Notice()->can_write_at_scope( true ) )
			return;

		// get main instance
		$cn = Cookie_Notice();

		$allow_one_cron_per_hour = false;

		if ( is_multisite() && $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'] ) {
			if ( empty( $app_id ) )
				$app_id = $cn->network_options['general']['app_id'];

			$network = true;
			$allow_one_cron_per_hour = true;
		} else {
			if ( empty( $app_id ) )
				$app_id = $cn->options['general']['app_id'];

			$network = false;
		}

		// ── Begin Network Admin per-site guard ───────────────────────────────────
		// As get_app_config(): with the override off the Network Admin would write the network
		// app's analytics and status ('' / activation 0) into the MAIN SITE's rows, and the
		// main site would stop printing its widget. Same in-memory predicate, same reason.
		if ( $cn->is_network_admin() && ! $network )
			return;
		// ── End Network Admin per-site guard ─────────────────────────────────────

		// in global override mode allow only one cron per hour
		if ( $allow_one_cron_per_hour && ! $force_update ) {
			$analytics = get_site_option( 'cookie_notice_app_analytics', [] );

			// analytics data?
			if ( ! empty( $analytics ) ) {
				$updated = strtotime( $analytics['lastUpdated'] );

				// last updated less than an hour?
				if ( $updated !== false && current_time( 'timestamp', true ) - $updated < 3600 )
					return;
			}
		}

		$response = $this->request(
			'get_analytics',
			[
				'AppID' => $app_id
			]
		);

		// get analytics
		if ( ! empty( $response->data ) ) {
			$result = map_deep( (array) $response->data, [ $this, 'sanitize_preserve_bools' ] );

			// add time updated
			$result['lastUpdated'] = date( 'Y-m-d H:i:s', current_time( 'timestamp', true ) );
			// Lets a later config pull refuse to merge this blob onto a different app
			// (reconnect / app-id swap). Absent on blobs written before this stamp.
			$result['appId'] = $app_id;

			// ── Begin analytics status seed ──────────────────────────────────
			// Seed from what is STORED, not from the defaults.
			//
			// This function writes the WHOLE cookie_notice_status row below but only
			// knows about four of its fields. Seeded from defaults, every field it does
			// not speak to was silently reset on write — and this runs on an HOURLY
			// cron (wp_schedule_event 'hourly', :1905), so the reset was not a rare
			// race, it was the steady state.
			//
			// widget_version was the live casualty: the config pull would set 'v2', the
			// next analytics tick would blank it, get_banner_channel() would resolve v1
			// (cookie-notice.php), and the site would drop off the v2 bundle within the
			// hour — with Application.WidgetVersion still reading 'v2' on the platform,
			// which is what made it unfindable from the backend. The partial-response
			// guard added to get_app_config does not help here: that guards the config
			// path, and this is a different writer of the same row.
			//
			// Seeding from the stored row fixes the whole class rather than that one
			// field, so a field added to the row later does not have to remember to
			// come back and edit this function. array_merge against the defaults keeps
			// the shape complete if the stored row predates a field.
			$status_data = array_merge(
				$cn->defaults['data'],
				(array) Cookie_Notice_Store::get( 'cookie_notice_status', $cn->defaults['data'], $network )
			);
			// ── End analytics status seed ────────────────────────────────────

			// update status
			$status_data['status'] = $cn->get_status();

			// update subscription
			$status_data['subscription'] = $cn->get_subscription();

			// update activation timestamp
			$status_data['activation_datetime'] = $cn->get_cc_activation_datetime();

			if ( $status_data['status'] === 'active' && $status_data['subscription'] === 'basic' ) {
				// same freshness bar as the get_config path — see
				// cycle_usage_is_fresh() for why an unprovable snapshot fails open
				$status_data['threshold_exceeded'] = $this->evaluate_threshold_exceeded(
					isset( $result['cycleUsage'] ) ? $result['cycleUsage'] : null
				);
			} else {
				// EXPLICIT, because the seed above changed what "no branch taken" means.
				// While $status_data came from the defaults, falling past this if left
				// threshold_exceeded false; seeded from the stored row it would now
				// inherit whatever was there. That matters on exactly one path and it is
				// a paying one: a Free site that was over its limit upgrades to Pro, the
				// subscription is no longer 'basic', and the stale true would persist —
				// leaving app_blocking forced off (the quota force in cookie-notice.php)
				// on a site that just paid to have it on.
				//
				// A plan with no threshold cannot exceed one, so false is not a reset
				// here, it is the answer.
				$status_data['threshold_exceeded'] = false;
			}

			if ( $network ) {
				update_site_option( 'cookie_notice_app_analytics', $result );
				update_site_option( 'cookie_notice_status', $status_data );
			} else {
				update_option( 'cookie_notice_app_analytics', $result, false );
				update_option( 'cookie_notice_status', $status_data, false );
			}

			// get current status data
			$status_data_old = $cn->get_status_data();

			// update status data
			$cn->set_status_data();

			// only when status data changed
			if ( $force_action && $status_data_old !== $status_data ) {
				do_action( 'cn_configuration_updated', 'analytics', [
					'status' => $status_data
				] );
			}
		}
	}

	/**
	 * True while the base posture sync is inside its own update_option() call.
	 *
	 * Re-entrancy guard, not an optimisation. The sync's write re-enters get_app_config()
	 * through validate_options() — register_setting() hooks it onto
	 * sanitize_option_cookie_notice_options, and core runs sanitize_option() BEFORE it
	 * reads $old_value — so the nested pass still sees the pre-write option and, without
	 * this, recurses until memory_limit with a live Designer API GET per level.
	 *
	 * @var bool
	 */
	public $syncing_base_posture = false;

	// ── Begin base posture push (plugin → Designer API)

	/**
	 * Base posture changes staged by this request, keyed by scope ( 'site' | 'network' ).
	 *
	 * @var array
	 */
	private $staged_base_posture = [];

	/**
	 * Option holding the posture change that has NOT reached the Designer API.
	 *
	 * Shape: [ 'app_id' => string, 'tries' => int, 'since' => int ]. While one is
	 * outstanding get_app_config() skips the backend→plugin sync for that app, so the
	 * authoritative pull cannot revert a local change that never landed.
	 *
	 * It records WHICH app is out of step, not merely that something is. A bare flag
	 * latches across a disconnect and a reconnect: an unattended retry would then stamp
	 * one app's posture onto whichever app the row named later, and the pull it holds off
	 * would never adopt that app's own value.
	 *
	 * @var string
	 */
	const POSTURE_PUSH_PENDING = 'cookie_notice_blocking_push_pending';

	/**
	 * How long to wait before retrying a push the API refused or could not answer.
	 *
	 * @var int
	 */
	const POSTURE_PUSH_RETRY_COOLDOWN = 300;

	/**
	 * Transient holding that cooldown.
	 *
	 * @var string
	 */
	const POSTURE_PUSH_RETRY = 'cookie_notice_posture_push_retry';

	/**
	 * How many attempts before the backend gets its authority back.
	 *
	 * Retrying for ever is worse than losing the change. While a push is outstanding the
	 * pull stops applying the platform's posture, so a site whose PATCH can never succeed
	 * — app deleted in the Portal, key rotated, egress blocked — would ignore the backend
	 * indefinitely and make an outbound request every cooldown until someone noticed.
	 *
	 * @var int
	 */
	const POSTURE_PUSH_MAX_TRIES = 10;

	/**
	 * How long a record may hold the pull's authority off, in seconds, whatever the cause.
	 *
	 * The attempt count bounds a failing API, but it only advances when an attempt is
	 * actually MADE — and an attempt needs authority. A record left on a subsite whose
	 * administrator lacks the capability to push it, and which no super admin visits
	 * again, would otherwise freeze its count and suppress the backend for ever. This
	 * bound does not care why nothing happened, which is the whole point of it. Six hours.
	 *
	 * @var int
	 */
	const POSTURE_PUSH_MAX_AGE = 21600;

	/**
	 * Transport budget for a posture push, in seconds.
	 *
	 * Short of the shared 60s, because this call runs on `shutdown` and WordPress does not
	 * finish the FastCGI request before that fires — so with the Designer API unreachable
	 * the admin's save spins for the whole budget AFTER their page has been produced.
	 *
	 * NOT as short as it could be, though, and the difference matters. The retry ladder
	 * protects a push against an API that is DOWN; it does nothing for one that is merely
	 * SLOW. Against a healthy endpoint whose p95 sits above the budget — a large design
	 * record, a distant region — every attempt times out alike, and after
	 * POSTURE_PUSH_MAX_TRIES the record is dropped and the admin's change is silently
	 * replaced by the platform's. So this is set well above a normal patch_by_app and only
	 * far enough below 60s to keep the save from hanging: a compromise, not a floor.
	 *
	 * @var int
	 */
	const POSTURE_PUSH_TIMEOUT = 15;

	//
	// The two callbacks below are registered on the option WRITE, not on any save path,
	// and that is the whole design. Which of them fires IS the target derivation: it is
	// the database write core actually performed, so the app row to PATCH and the
	// capability to demand both follow from it. Never derive either from $cn->options,
	// is_network_admin() or global_override — all three are request-shaped, and deriving
	// the target from them is what produced three separate privilege escalations before
	// this push was cut ( 5456b3c ). The forged cn_network=1 route this used to describe is
	// closed — Cookie_Notice::enforce_network_scope() refuses the claim on plugins_loaded —
	// but the reasoning stands on its own and is what keeps the remaining doors safe: a
	// write that reaches update_site_option() by ANY route fires the NETWORK action and so
	// demands manage_network_options, with global_override on or off. Those doors are real;
	// see the "network scope claim" block in cookie-notice.php for the current list.
	//
	// Two callbacks rather than one shared: core emits these actions with DIFFERENT
	// argument orders, so a single signature silently reads the wrong variable.

	/**
	 * Stage a base posture change written to this site's own row.
	 *
	 * Core fires update_option_{$option} as ( $old_value, $value, $option ).
	 *
	 * @param mixed $old_value
	 * @param mixed $value
	 *
	 * @return void
	 */
	public function stage_base_posture_site( $old_value, $value ) {
		$this->stage_base_posture( false, $old_value, $value );
	}

	/**
	 * Stage a base posture change written to the network row.
	 *
	 * Core fires update_site_option_{$option} as ( $option, $value, $old_value, $network_id )
	 * — a different order from the site action above.
	 *
	 * @param string $option
	 * @param mixed  $value
	 * @param mixed  $old_value
	 *
	 * @return void
	 */
	public function stage_base_posture_network( $option, $value, $old_value ) {
		$this->stage_base_posture( true, $old_value, $value );
	}

	/**
	 * Whether the current user may push this app's posture from this scope.
	 *
	 * The hook that fired names the ROW that was written. It does NOT name the APP, and
	 * the two come apart: a SITE row can carry the NETWORK app's credentials. app_id and
	 * app_key are plugin-owned fields, so they survive every allowlist, and credentials a
	 * site row was given while global_override was on outlive the override. Any write of
	 * that site row — an ordinary setting saved by an ordinary subsite administrator —
	 * then fires the site action carrying the network app's credentials.
	 *
	 * No forgery is involved and it is not an attack: it is an ordinary save on such a
	 * site. But the record about to change is the one every site on the network pulls, so
	 * authority has to follow the app whose record changes, not only the row that was
	 * written.
	 *
	 * @param bool   $network
	 * @param string $app_id
	 *
	 * @return bool
	 */
	private function may_push_base_posture( $network, $app_id ) {
		if ( ! $network && is_multisite() ) {
			$network_row = get_site_option( 'cookie_notice_options', [] );

			if ( is_array( $network_row ) ) {
				// While global_override is on the network owns configuration for every
				// site: cookie-notice.php loads the options array FROM the network row, so
				// a site row's posture is not even what this site serves. Pushing it is
				// meaningless, and it could undo a change made at network level with a
				// site value nothing serves. Refused outright rather than escalated — a
				// super admin changing network posture writes the NETWORK row, which fires
				// the network action and pushes from there.
				// Both halves, the way the constructor's options load and is_network_options() ask
				// it. The flag alone governs nothing: a network row can still carry it
				// after the plugin was network-deactivated and activated per site, and
				// every site then serves its OWN row. Refusing on the flag alone made an
				// ordinary administrator's toggle silently inert there — no push and no
				// pending record, so the next pull quietly reinstated the backend value.
				//
				// The pairing belongs HERE and not on the block above. Hoisted, it also
				// switched off the shared-app check below, which is the only thing
				// standing between a subsite administrator and a record that every site
				// serving that AppID pulls — the credentials outlive the network
				// activation that put them in the row.
				if ( ! empty( $network_row['global_override'] ) && Cookie_Notice()->is_plugin_network_active() )
					return false;

				// Override is off, but the site row can still NAME the network's app from a
				// period when it was on. That record is shared with every site on the
				// network, so changing it takes network authority.
				//
				// One definition, in Cookie_Notice::is_network_shared_app(). This used to
				// carry a private second copy of the rule, and the gates in front of the
				// remote PATCHes asked is_network_options() instead — so the only correct
				// implementation of "authority follows the app" was the one nothing else
				// called, while the comments on those gates cited it by name.
				if ( Cookie_Notice()->is_network_shared_app( $app_id ) )
					return current_user_can( 'manage_network_options' );
			}
		}

		return current_user_can( $network ? 'manage_network_options' : apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) );
	}

	/**
	 * Stage a posture change for the shutdown push.
	 *
	 * @param bool  $network
	 * @param mixed $old_value
	 * @param mixed $value
	 *
	 * @return void
	 */
	private function stage_base_posture( $network, $old_value, $value ) {
		// A config pull's own write must never echo straight back to the API that sent it.
		if ( $this->syncing_base_posture )
			return;

		if ( ! is_array( $value ) )
			return;

		// A change can only be claimed when the PREVIOUS posture was known. A row that
		// predates this key — or is not an array at all — says nothing about what the
		// customer chose, and reading that as `false` would push a plugin default over a
		// posture set in the Portal. Same absent-never-overwrites rule the pull keeps in
		// the other direction, for the same reason.
		if ( ! is_array( $old_value ) || ! array_key_exists( 'app_blocking', $old_value ) ) {
			if ( Cookie_Notice()->options['general']['debug_mode'] )
				error_log( '[Cookie Notice] base posture push skipped: no stored posture to compare against, so this change is not claimed as one' );

			return;
		}

		$scope = $network ? 'network' : 'site';

		// Posture unchanged by this write, and no earlier write in this request staged one:
		// nothing to push, so stop before the authority check — on a network,
		// may_push_base_posture() can walk up to every site (app_served_by_another_site()),
		// and every write of this row lands here, a notice dismissal as much as a toggle.
		// Once a change is staged, an unchanged write still runs below so the staged target
		// stays the latest row's.
		if ( ! array_key_exists( $scope, $this->staged_base_posture ) && ! empty( $old_value['app_blocking'] ) === ! empty( $value['app_blocking'] ) )
			return;

		// Target and value come from the SAME array, the one core is about to persist.
		// Splitting them is how the AppID and the app-secret-key used to disagree, and
		// the 403 that produced was mistaken for an authorisation control. Reading the
		// row back later would be wrong for a second reason: a disconnect clears the
		// credentials in this same request.
		$app_id  = isset( $value['app_id'] )  ? (string) $value['app_id']  : '';
		$app_key = isset( $value['app_key'] ) ? (string) $value['app_key'] : '';

		// Authority is resolved from the hook's scope and then from the app itself —
		// never from a branch inside the request.
		if ( ! $this->may_push_base_posture( $network, $app_id ) )
			return;

		// $value is post-filter: preserve_app_blocking_preference() runs on
		// pre_update_option_* / pre_update_site_option_*, so the #2272 quota force has
		// already been restored to the admin's stored preference by the time this reads
		// it. That filter is therefore what keeps a forced false out of the backend
		// record, and there is deliberately no second quota gate here to obscure it.
		$posture = ! empty( $value['app_blocking'] );

		if ( isset( $this->staged_base_posture[ $scope ] ) ) {
			// Several writes in one request: keep the first 'from' and the last 'to', so
			// a value that lands back where it started is never sent.
			$this->staged_base_posture[ $scope ]['to']      = $posture;
			$this->staged_base_posture[ $scope ]['app_id']  = $app_id;
			$this->staged_base_posture[ $scope ]['app_key'] = $app_key;

			return;
		}

		$this->staged_base_posture[ $scope ] = [
			'from'    => ! empty( $old_value['app_blocking'] ),
			'to'      => $posture,
			'app_id'  => $app_id,
			'app_key' => $app_key
		];

		add_action( 'shutdown', [ $this, 'flush_base_posture_push' ] );
	}

	/**
	 * Send every posture change staged by this request.
	 *
	 * @return void
	 */
	public function flush_base_posture_push() {
		$staged = $this->staged_base_posture;

		// Cleared first: a failure must not leave the request able to re-enter here.
		$this->staged_base_posture = [];

		foreach ( $staged as $scope => $change ) {
			// Unchanged across every write in this request.
			if ( $change['from'] === $change['to'] )
				continue;

			$this->push_base_posture( $scope === 'network', $change['app_id'], $change['app_key'], $change['to'] );
		}
	}

	/**
	 * PATCH one app's base posture to the Designer API.
	 *
	 * @param bool   $network
	 * @param string $app_id
	 * @param string $app_key
	 * @param bool   $posture
	 * @param bool   $is_retry  true when re-sending the outstanding record, false for a new change
	 *
	 * @return bool  whether the API accepted it
	 */
	private function push_base_posture( $network, $app_id, $app_key, $posture, $is_retry = false ) {
		if ( $app_id === '' || $app_key === '' )
			return false;

		// DevMode mock IDs never reach the real API.
		if ( $this->get_write_request_type( $app_id ) === 'devmode' )
			return false;

		$config = new stdClass();
		$config->blocking = (bool) $posture;

		$result = $this->request(
			'patch_by_app',
			[
				'AppID'			=> $app_id,
				'AppSecretKey'	=> $app_key,
				'config'		=> $config
			],
			[ 'timeout' => self::POSTURE_PUSH_TIMEOUT ]
		);

		// request() ends in json_decode() with no assoc flag, so every real response is a
		// stdClass and the only arrays are its two synthesized transport failures — an
		// is_array() test here reports failure on success. A successful by-app PATCH is
		// responseHelper.success(), { data, status: 200 }, carrying no i18n_msg at all, so
		// the status is the only signal there is.
		$sent = is_object( $result ) && isset( $result->status ) && (int) $result->status === 200;

		// No design record for this app yet. The pull cannot have delivered a posture
		// either, so there is nothing to keep in step and nothing to retry — seeding a
		// whole design from a posture change is not this code's job.
		if ( ! $sent && is_object( $result ) && isset( $result->i18n_msg ) && $result->i18n_msg === 'user_design_update_id_not_found' )
			$sent = true;

		if ( $sent ) {
			$this->set_posture_push_pending( $network, null );

			return true;
		}

		$outstanding = $this->posture_push_pending( $network );

		// A RETRY continues the record it is retrying: the attempt count and the age both go
		// on advancing, so both bounds actually arrive. A NEW change is a new intent and
		// starts its own.
		//
		// Continuing unconditionally is what the previous round got wrong. The record is no
		// longer filtered by age here — that moved to the pull's gate — so a change made
		// seconds ago inherited a timestamp hours old and was born past the bound, with the
		// very next config pull free to write the backend's stale value over it. Clearing
		// expired records in retry_base_posture_push() does not cover it: that path returns
		// early on wp_doing_ajax(), and the React admin's save IS an admin-ajax request, so
		// on the plugin's primary UI the housekeeping never runs at all.
		$continuing = ( $is_retry && $outstanding && $outstanding['app_id'] === $app_id );

		$this->set_posture_push_pending( $network, [
			'app_id'	=> $app_id,
			// The VALUE this record owes, captured from the write that was authorised —
			// never re-derived from the row when the retry finally runs.
			//
			// Re-reading the row was a privilege escalation. Staging refuses a caller
			// without the capability, but the retry runs under whoever happens to be
			// visiting: a subsite administrator gets a network-row write in, their own push
			// is correctly refused, and then the next super admin to load an admin page
			// couriers their value to the platform under super-admin authority. (Every route
			// that made that first step possible — the forged $_POST['cn_network'], and the
			// site-capability doors in react-admin-ajax.php, settings.php, wp-consent-api.php
			// and get_app_config() — is now closed. This stays because the escalation shape
			// does not depend on which door was used.) The pull cannot correct it in the
			// meantime — this very record is holding it off — and the Designer API writes a
			// by-app PATCH straight through to the PUBLISHED record, so the whole network
			// stops blocking pre-consent and stays that way.
			//
			// The local write is pre-existing and stays out of scope here; before this
			// subsystem it was local-only and the next pull reverted it. This is what stops
			// it reaching the platform, and it still earns its keep now that those doors are
			// shut: it defends the shape, not one particular way in.
			'posture'	=> (bool) $posture,
			'tries'		=> ( $continuing && isset( $outstanding['tries'] ) ? (int) $outstanding['tries'] : 0 ) + 1,
			// Kept from the FIRST failure of THIS record, so the age bound measures how long
			// the pull has been held off rather than restarting on every attempt.
			'since'		=> $continuing && ! empty( $outstanding['since'] ) ? (int) $outstanding['since'] : time()
		] );

		return false;
	}

	/**
	 * The outstanding posture change for one scope, or null.
	 *
	 * @param bool $network
	 *
	 * @return array|null
	 */
	private function posture_push_pending( $network ) {
		$record = Cookie_Notice_Store::get( self::POSTURE_PUSH_PENDING, false, $network );

		return ( is_array( $record ) && ! empty( $record['app_id'] ) ) ? $record : null;
	}

	/**
	 * Whether a record has stopped being allowed to hold the pull's authority off.
	 *
	 * Ages the record rather than the attempt count, because the count only advances when
	 * an attempt is actually MADE and an attempt needs authority — so a record on a
	 * subsite whose administrator cannot push it would otherwise freeze and suppress that
	 * site for ever.
	 *
	 * A record with no timestamp cannot be aged, and one dated in the FUTURE would never
	 * reach the bound — a clock stepped backwards by NTP or a restored snapshot is enough.
	 * Both count as expired: failing toward "nothing pending" costs one local change
	 * against a site that ignores the platform indefinitely.
	 *
	 * @param array $record
	 *
	 * @return bool
	 */
	private function posture_push_expired( $record ) {
		if ( empty( $record['since'] ) )
			return true;

		$age = time() - (int) $record['since'];

		return ( $age < 0 || $age >= self::POSTURE_PUSH_MAX_AGE );
	}

	/**
	 * Record — or, with null, clear — the outstanding posture change for one scope.
	 *
	 * @param bool       $network
	 * @param array|null $record
	 *
	 * @return void
	 */
	private function set_posture_push_pending( $network, $record ) {
		if ( $record === null ) {
			if ( $network )
				delete_site_option( self::POSTURE_PUSH_PENDING );
			else
				delete_option( self::POSTURE_PUSH_PENDING );

			return;
		}

		if ( $network )
			update_site_option( self::POSTURE_PUSH_PENDING, $record );
		else
			update_option( self::POSTURE_PUSH_PENDING, $record, false );
	}

	/**
	 * Whether THIS app has a posture change that has not reached the API.
	 *
	 * Asked of the app rather than of a scope, because the two do not line up: the push's
	 * scope is the row that was written, the pull's is global_override, and under
	 * global_override a React save persists the network array into the SITE row. Keyed by
	 * scope, a pull could miss the very record meant to hold its authority off.
	 *
	 * @param string $app_id
	 *
	 * @return bool
	 */
	public function is_posture_push_pending_for( $app_id ) {
		$app_id = (string) $app_id;

		if ( $app_id === '' )
			return false;

		foreach ( [ false, true ] as $network ) {
			$record = $this->posture_push_pending( $network );

			// isset( posture ) as well: a record from before that field cannot say what it
			// owes and the retry will drop it on sight, so it must not go on holding the
			// pull off in the meantime — which, on the ajax path, could be a long while.
			if ( $record && (string) $record['app_id'] === $app_id && isset( $record['posture'] ) && ! $this->posture_push_expired( $record ) )
				return true;
		}

		return false;
	}

	/**
	 * Retry a push that failed in an earlier request.
	 *
	 * Without this the flag latches: the pull stops reverting the local value, but the
	 * backend stays behind until the admin happens to toggle the setting again.
	 *
	 * @return void
	 */
	public function retry_base_posture_push() {
		// admin_init fires on admin-ajax too, and this makes a blocking outbound call.
		// Attaching that to a Heartbeat tick or an autosave stalls a request the user
		// never associated with saving anything.
		if ( wp_doing_ajax() )
			return;

		// ── Begin retry actor gate ───────────────────────────────────────
		// admin_init fires for EVERY logged-in user who loads any admin page — a Subscriber
		// opening /wp-admin/profile.php included. This handler can reach
		// may_push_base_posture(), and from there Cookie_Notice::is_network_shared_app(),
		// which walks the network's sites. A user who could never push anything was driving
		// that walk on every page load, and because the refusal path below skips the
		// cooldown, it repeated indefinitely — roughly two queries per site, every load,
		// loopable at will. That is a denial-of-service the scan made possible and this
		// handler made reachable.
		//
		// Nobody below manage_options can cause or authorise a posture push, so there is
		// nothing here for them to do and no reason to spend their request finding out.
		if ( ! current_user_can( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) ) )
			return;
		// ── End retry actor gate ─────────────────────────────────────────

		foreach ( [ true, false ] as $network ) {
			// A single site HAS no network scope, and pretending it does is not harmless:
			// core's get_network_option() delegates to get_option() there, so both passes
			// address the same row, and the network pass — which can never hold
			// manage_network_options outside multisite — would reach the expired-drop
			// below and destroy the record before the site pass could send it.
			if ( $network && ! is_multisite() )
				continue;

			$record = $this->posture_push_pending( $network );

			if ( ! $record )
				continue;

			// Outbound, on a hot path: without this an API that is down turns every admin
			// page load into a failing request for as long as it stays down. Per scope,
			// and a network-wide transient ONLY for the network scope — set_site_transient()
			// is network-global on multisite, so one shared cooldown would let a single
			// busy subsite gate the retries of every other site on the network.
			if ( Cookie_Notice_Store::get_transient( self::POSTURE_PUSH_RETRY, $network ) )
				continue;

			$row    = Cookie_Notice_Store::get( 'cookie_notice_options', [], $network );
			$app_id = is_array( $row ) && isset( $row['app_id'] ) ? (string) $row['app_id'] : '';

			// The row names a different app now — disconnected, or reconnected elsewhere.
			// The outstanding change belonged to an app this row no longer points at, so
			// it is moot; keeping it would stamp the old posture onto the new app AND go
			// on holding the pull off from adopting that app's own value. Or it has simply
			// failed often enough. Either way clear it: that is what hands the backend
			// its authority back, and there is no other path that does.
			$tries   = isset( $record['tries'] ) ? (int) $record['tries'] : 0;
			$expired = $this->posture_push_expired( $record );

			// A record with no posture predates that field — written by an older build and
			// carried across the upgrade. There is no way to tell now what was authorised,
			// and the row is exactly the source that must not be trusted, so it is dropped:
			// that releases the pull, and the backend's own value is reinstated.
			if ( $app_id === '' || $app_id !== $record['app_id'] || ! isset( $record['posture'] ) || $tries >= self::POSTURE_PUSH_MAX_TRIES ) {
				if ( $tries >= self::POSTURE_PUSH_MAX_TRIES && Cookie_Notice()->options['general']['debug_mode'] )
					error_log( '[Cookie Notice] base posture push gave up after ' . $tries . ' attempts for AppID: ' . $record['app_id'] . ' — the next config pull reinstates the stored posture' );

				$this->set_posture_push_pending( $network, null );

				continue;
			}

			if ( ! $this->may_push_base_posture( $network, $app_id ) ) {
				// No authority in THIS request — a subsite administrator, say, on a record
				// only a super admin could send. The pull is already released by the age
				// bound, so nothing is being held off; drop the row once it is past that
				// age so it does not sit there for ever waiting for a visitor who has the
				// capability and may never arrive.
				if ( $expired )
					$this->set_posture_push_pending( $network, null );

				continue;
			}

			// Armed BEFORE the call, not after. A request that dies inside the outbound
			// timeout would otherwise leave the cooldown unset and repeat this on the very
			// next admin page load — the thing it exists to prevent.
			if ( $network )
				set_site_transient( self::POSTURE_PUSH_RETRY, 1, self::POSTURE_PUSH_RETRY_COOLDOWN );
			else
				set_transient( self::POSTURE_PUSH_RETRY, 1, self::POSTURE_PUSH_RETRY_COOLDOWN );

			// The RECORD's posture, not the row's. See where it is written for why.
			// Credentials still come from the row: they are how this install authenticates
			// as the app, not part of the change being delivered, and the app itself is
			// pinned by the $app_id === $record['app_id'] test above.
			$sent = $this->push_base_posture(
				$network,
				$app_id,
				isset( $row['app_key'] ) ? (string) $row['app_key'] : '',
				(bool) $record['posture'],
				true
			);

			// That was an expired record's LAST attempt, so it stops here. A success has
			// already cleared it; a failure wrote it back carrying the same stale timestamp,
			// which would leave it holding nothing off and never ageing out.
			if ( $expired && ! $sent )
				$this->set_posture_push_pending( $network, null );
		}
	}
	// ── End base posture push (plugin → Designer API)

	/**
	 * Get app config.
	 *
	 * @param string $app_id
	 * @param bool $force_update
	 * @param bool $force_action
	 *
	 * @return void|array
	 */
	public function get_app_config( $app_id = '', $force_update = false, $force_action = true ) {
		// get main instance
		$cn = Cookie_Notice();

		$allow_one_cron_per_hour = false;

		if ( is_multisite() && $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'] ) {
			if ( empty( $app_id ) )
				$app_id = $cn->network_options['general']['app_id'];

			$network = true;
			$allow_one_cron_per_hour = true;
		} else {
			if ( empty( $app_id ) )
				$app_id = $cn->options['general']['app_id'];

			$network = false;
		}

		// ── Begin Network Admin per-site guard ───────────────────────────────────
		// The Network Admin with Global Settings Override off: $network is false, so the pull
		// below would fetch the NETWORK row's app (whose id the request holds) and write it into
		// the MAIN SITE's rows — its blocking catalogue, regulations, design, options and status
		// — overwriting the main site's own app. Legacy never pulls here. Keyed on the
		// in-memory override, never the saved row: switching the override ON forces it true in
		// memory before its pull, and must still pull (save_network_options(), legacy
		// validate_network_options()). Bare return, as the gate below.
		if ( $cn->is_network_admin() && ! $network )
			return;
		// ── End Network Admin per-site guard ─────────────────────────────────────

		// ── Begin network config-write gate ──────────────────────────────────────
		// Everything below writes network-wide when $network is true —
		// cookie_notice_app_blocking (the pre-consent blocking catalogue),
		// cookie_notice_app_regulations, cookie_notice_options, cookie_notice_app_design and
		// cookie_notice_status — and the cookie_notice_options write fires
		// update_site_option_cookie_notice_options, i.e. it STAGES A NETWORK POSTURE PUSH.
		//
		// Several user-driven entry points reach here holding only manage_options, a
		// site-level capability every subsite administrator has: ajax_purge_cache()
		// (includes/settings.php), save_options() (includes/react-admin-ajax.php),
		// validate_options() (includes/settings.php),
		// react_update_design(), react_save_banner_style(), and api_request()'s configure and
		// sync_config. Each passes $force_update = true, which also skips the once-per-hour
		// throttle below. react_apply_languages() does NOT reach here and is guarded at its
		// own write instead — do not read this list as the set of things needing cover. The
		// value written is the platform's own published config rather than anything the
		// caller supplies, so this is a forced clobber and a staged push, not an injection —
		// but it is still a site-level actor causing a network-wide write.
		//
		// Guarded HERE rather than at those call sites: get_app_config() has twelve of them
		// plus a cron hook, the list grows, and a per-caller gate is one someone forgets.
		//
		// THE did_action( 'admin_init' ) CARVE-OUT IS LOAD-BEARING, not laziness. Every entry
		// point an ORDINARY ADMINISTRATOR can drive runs at admin_init or later —
		// admin-ajax.php fires admin_init before dispatching wp_ajax_{$action}, and admin.php
		// fires it before any admin page body. What reaches here without it has no actor to
		// escalate: set_status_data() during the plugins_loaded bootstrap, cron, and the REST
		// purge route in includes/frontend.php, which is authenticated by the app secret
		// rather than by a user and therefore has no capability to test in the first place.
		// Refusing those would leave the network serving stale config forever.
		//
		// Note the carve-out is NOT "nothing after admin_init is unauthenticated" — the REST
		// route above is a counter-example, and an earlier revision of this comment claimed
		// otherwise. It is: where there is no user, there is nothing for a capability check to
		// decide, and the caller is authenticated some other way.
		//
		// Preferred over is_user_logged_in() for a second reason: that would resolve the
		// current user at plugins_loaded:0 on ordinary admin page loads, ahead of any
		// determine_current_user filter registered later on that hook, and core caches the
		// result for the whole request.
		//
		// A bare return matches the throttle's existing early exit directly below and the
		// documented @return void|array — but NOT every caller tolerated it in practice: the
		// throttle only fires when $force_update is false, and these callers all pass true,
		// so several had never seen null. includes/settings.php:2147 needed an is_array()
		// guard added for exactly that. The bootstrap caller in cookie-notice.php index-reads
		// the return unguarded, and is safe because it runs before admin_init and so is never
		// refused — if that carve-out ever changes, that caller has to be guarded first.
		//
		// The other set_status_data() callers (in settings.php and twice in this file) DO run
		// at or after admin_init. They are safe for a different reason, not this one: each
		// already sits behind a gate that proved can_write_at_scope( true ). Do not read the
		// sentence above as covering them.
		if ( $network && did_action( 'admin_init' ) && ! $cn->can_write_at_scope( true ) )
			return;
		// ── End network config-write gate ────────────────────────────────────────
		// in global override mode allow only one cron per hour
		if ( $allow_one_cron_per_hour && ! $force_update ) {
			$blocking = get_site_option( 'cookie_notice_app_blocking', [] );

			// analytics data?
			if ( ! empty( $blocking ) ) {
				$updated = strtotime( $blocking['lastUpdated'] );

				// last updated less than an hour?
				if ( $updated !== false && current_time( 'timestamp', true ) - $updated < 3600 )
					return;
			}
		}

		// get config
		$response = $this->request(
			'get_config',
			[
				'AppID' => $app_id
			]
		);

		// debug: the Designer API's status, never the body
		if ( $cn->options['general']['debug_mode'] ) {
			error_log( '[Cookie Notice] get_app_config - AppID: ' . $app_id );
			error_log( '[Cookie Notice] get_app_config - Designer API response: ' . $this->debug_reply_summary( $response ) );
		}

		// get status data
		$status_data = $cn->defaults['data'];

		// ── Begin last-known-good guard ──────────────────────────────────────────
		//
		// Distinguishes "the platform told us something" from "we could not reach the
		// platform". Only the first is allowed to overwrite what we already know.
		//
		// request() builds an ARRAY itself — [ 'error' => … ] — for a WP transport
		// failure (is_wp_error, :1849) and for ANY text/html response (:1855), which is
		// what an nginx 502, a Cloudflare error page and a WAF block all produce. A real
		// platform answer is the json_decode()d OBJECT from :1861. So `is_object()` is
		// the discriminator, and an invalid-JSON body (json_decode → null) correctly
		// lands on the unreachable side too.
		//
		// This existed as a P0: $status_data is seeded from the DEFAULTS above and was
		// then written unconditionally below, so one transient blip persisted
		// status='', subscription='basic', widget_version='' over good values. Because
		// frontend.php:59 computes compliance from status === 'active', that took the
		// site off the Cookie Compliance widget entirely and onto the legacy cookie bar
		// — no pre-consent autoblocking, no consent records — until the next SUCCESSFUL
		// pull, with nothing telling the customer. Do not collapse this back into an
		// unconditional write.
		$platform_answered = is_object( $response );
		$store_status      = true;

		// The row as it stands before this pull. The failure branch below reloads it
		// wholesale; the success branch needs it too, for fields a SUCCESSFUL response
		// can omit — see the widget_version partial-response guard. Read once, here, so
		// both branches are looking at the same snapshot.
		$stored_status_data = array_merge(
			$cn->defaults['data'],
			(array) Cookie_Notice_Store::get( 'cookie_notice_status', $cn->defaults['data'], $network )
		);
		// ── End last-known-good guard ────────────────────────────────────────────

		// get config
		if ( ! empty( $response->data ) ) {
			// sanitize data
			foreach ( (array) $response->data as $index => $value ) {
				// custom patterns
				if ( $index === 'DefaultCookieJSON' ) {
					foreach ( $value as $p_index => $pattern ) {
						$pattern->IsCustom = (bool) $pattern->IsCustom;
						$pattern->CookieID = is_int( $pattern->CookieID ) ? $pattern->CookieID : sanitize_text_field( $pattern->CookieID );
						$pattern->CategoryID = (int) $pattern->CategoryID;
						$pattern->ProviderID = is_int( $pattern->ProviderID ) ? $pattern->ProviderID : sanitize_text_field( $pattern->ProviderID );
						$pattern->PatternType = sanitize_text_field( $pattern->PatternType );
						$pattern->PatternFormat = sanitize_text_field( $pattern->PatternFormat );
						$pattern->Pattern = stripslashes( sanitize_text_field( $pattern->Pattern ) );

						// add pattern
						$result_raw[$index][$p_index] = $pattern;
					}
				// custom providers
				} elseif ( $index === 'DefaultProviderJSON' ) {
					foreach ( $value as $p_index => $provider ) {
						$provider->IsCustom = (bool) $provider->IsCustom;
						$provider->CategoryID = (int) $provider->CategoryID;
						$provider->ProviderID = is_int( $provider->ProviderID ) ? $provider->ProviderID : sanitize_text_field( $provider->ProviderID );
						$provider->ProviderURL = stripslashes( sanitize_text_field( $provider->ProviderURL ) );
						$provider->ProviderName = sanitize_text_field( $provider->ProviderName );

						// add provider
						$result_raw[$index][$p_index] = $provider;
					}
				} else
					$result_raw[$index] = map_deep( $value, [ $this, 'sanitize_preserve_bools' ] );
			}

			// set status
			$status_data['status'] = 'active';

			// get activation timestamp
			$timestamp = $cn->get_cc_activation_datetime();

			// update activation timestamp only for new cookie compliance activations
			$status_data['activation_datetime'] = $timestamp === 0 ? time() : $timestamp;

			// check subscription
			if ( ! empty( $result_raw['SubscriptionType'] ) )
				$status_data['subscription'] = $cn->check_subscription( strtolower( $result_raw['SubscriptionType'] ) );

			// ── Begin widget_version partial-response guard ──────────────────
			// Backend-controlled banner build selector (rides the same get_config
			// response as SubscriptionType). 'v2' selects the v2 build; an explicit
			// null or empty value means v1, and get_banner_channel() resolves
			// anything that is not 'v2' to v1.
			//
			// KEYED ON array_key_exists, NOT on the value — matching SubscriptionType
			// directly above, which this used to be the only field near here NOT to
			// guard. The distinction is between "the platform told us this app is on
			// v1" (key present, value null/'') and "the platform did not mention the
			// field at all" (key absent). Only the first is an answer. Treating the
			// second as one silently reverted every v2 customer to v1 the moment the
			// field stopped being serialised — no error, no signal, and the flag
			// staying 'v2' in the database the whole time, which is precisely what
			// makes it unfindable.
			//
			// Not hypothetical: the field is a recent addition to the live-serve
			// query, so every response predating that deploy omitted it.
			if ( array_key_exists( 'WidgetVersion', $result_raw ) )
				$status_data['widget_version'] = ! empty( $result_raw['WidgetVersion'] ) ? sanitize_key( $result_raw['WidgetVersion'] ) : '';
			else
				$status_data['widget_version'] = $stored_status_data['widget_version'] ?? $status_data['widget_version'];
			// ── End widget_version partial-response guard ────────────────────

			// ── Begin engine change notice ───────────────────────────────────
			// Only when the platform named this app's engine: a response without the key
			// keeps the stored value above, which can be a previous app's.
			if ( array_key_exists( 'WidgetVersion', $result_raw ) )
				$this->note_engine_change( $app_id, $status_data['widget_version'], $network );
			// ── End engine change notice ─────────────────────────────────────

			// Usage rides the SAME response as SubscriptionType above. Reading it
			// instead from the separately-refreshed cookie_notice_app_analytics
			// option is what let the two disagree: the plan came back fresh from
			// this call while the visit count came from a cache the hourly cron
			// had not caught up on, so a domain moved Free -> Pro kept enforcing
			// the old app's threshold (HS#47302). One response cannot contradict
			// itself.
			$analytics   = isset( $result_raw['AnalyticsData'] ) ? $result_raw['AnalyticsData'] : null;
			$cycle_usage = $this->cycle_usage_from_analytics_data( $analytics );

			if ( $status_data['subscription'] === 'basic' && $cycle_usage !== null )
				$status_data['threshold_exceeded'] = $this->evaluate_threshold_exceeded( $cycle_usage );

			// The React dashboard still reads cookie_notice_app_analytics, which
			// is otherwise filled only by the hourly get_app_analytics cron (WP
			// pseudo-cron — unbounded on a quiet site). This call already has
			// cycleUsage.threshold stamped from the live plan, so merge it into
			// that option without wiping consentActivities / thirtyDaysUsage.
			// Otherwise the first admin paint interpolates threshold 0 ("Free
			// protects up to 0 visits/month") until a later refresh happens to
			// land after the cron.
			if ( $cycle_usage !== null && class_exists( 'Cookie_Notice_Store' ) ) {
				$analytics_opt = Cookie_Notice_Store::get( 'cookie_notice_app_analytics', [], $network );

				if ( ! is_array( $analytics_opt ) )
					$analytics_opt = [];

				// Reconnect / app-id swap: the stored blob (consentActivities,
				// thirtyDaysUsage) belongs to the previous app. Merge would show
				// the new plan's cap against the old app's visit counts. A blob
				// with no appId predates the stamp — treat as same-app so the
				// first pull after upgrade does not wipe a healthy cache.
				$stored_app = isset( $analytics_opt['appId'] ) ? (string) $analytics_opt['appId'] : '';

				if ( $stored_app !== '' && $stored_app !== (string) $app_id )
					$analytics_opt = [];

				$analytics_opt['appId']      = $app_id;
				$analytics_opt['cycleUsage'] = $cycle_usage;

				$root_threshold = null;

				if ( is_object( $analytics ) && isset( $analytics->threshold ) )
					$root_threshold = $analytics->threshold;
				elseif ( is_array( $analytics ) && isset( $analytics['threshold'] ) )
					$root_threshold = $analytics['threshold'];

				if ( $root_threshold !== null )
					$analytics_opt['threshold'] = $root_threshold;

				Cookie_Notice_Store::set( 'cookie_notice_app_analytics', $analytics_opt, $network, false );
			}

			// process blocking data
			$result = [
				'categories'				=> ! empty( $result_raw['DefaultCategoryJSON'] ) && is_array( $result_raw['DefaultCategoryJSON'] ) ? $result_raw['DefaultCategoryJSON'] : [],
				'providers'					=> ! empty( $result_raw['DefaultProviderJSON'] ) && is_array( $result_raw['DefaultProviderJSON'] ) ? $result_raw['DefaultProviderJSON'] : [],
				'patterns'					=> ! empty( $result_raw['DefaultCookieJSON'] ) && is_array( $result_raw['DefaultCookieJSON'] ) ? $result_raw['DefaultCookieJSON'] : [],
				'google_consent_default'	=> [],
				'lastUpdated'				=> date( 'Y-m-d H:i:s', current_time( 'timestamp', true ) )
			];

			if ( ! empty( $result_raw['BannerConfigJSON'] ) && is_object( $result_raw['BannerConfigJSON'] ) ) {
				$gcm = isset( $result_raw['BannerConfigJSON']->googleConsentMode ) ? (int) $result_raw['BannerConfigJSON']->googleConsentMode : 0;

				// Is Google consent mode enabled? Free-tier feature, and NOT quota-gated.
				// Designer API logic.service.ts::downgradeLiveDefaults is explicit that
				// Google CM, GPC and DNT survive the Free plan; only Facebook and Microsoft
				// are Pro-only. Consent SIGNALS cost us nothing to serve — storage is the
				// metered resource — so a site over its visit quota keeps telling Google
				// what the visitor actually chose rather than silently losing its consent
				// signalling. The old gate also read the PREVIOUSLY persisted flag, not the
				// one being computed a few lines above, so it could disagree with itself.
				if ( $gcm === 1 ) {
					$result['google_consent_default']['ad_storage'] = isset( $result_raw['BannerConfigJSON']->googleConsentMapAdStorage ) ? (int) $result_raw['BannerConfigJSON']->googleConsentMapAdStorage : 4;
					$result['google_consent_default']['analytics_storage'] = isset( $result_raw['BannerConfigJSON']->googleConsentMapAnalytics ) ? (int) $result_raw['BannerConfigJSON']->googleConsentMapAnalytics : 3;
					$result['google_consent_default']['functionality_storage'] = isset( $result_raw['BannerConfigJSON']->googleConsentMapFunctionality ) ? (int) $result_raw['BannerConfigJSON']->googleConsentMapFunctionality : 2;
					$result['google_consent_default']['personalization_storage'] = isset( $result_raw['BannerConfigJSON']->googleConsentMapPersonalization ) ? (int) $result_raw['BannerConfigJSON']->googleConsentMapPersonalization : 2;
					$result['google_consent_default']['security_storage'] = isset( $result_raw['BannerConfigJSON']->googleConsentMapSecurity ) ? (int) $result_raw['BannerConfigJSON']->googleConsentMapSecurity : 2;
					$result['google_consent_default']['ad_personalization'] = isset( $result_raw['BannerConfigJSON']->googleConsentMapAdPersonalization ) ? (int) $result_raw['BannerConfigJSON']->googleConsentMapAdPersonalization : 4;
					$result['google_consent_default']['ad_user_data'] = isset( $result_raw['BannerConfigJSON']->googleConsentMapAdUserData ) ? (int) $result_raw['BannerConfigJSON']->googleConsentMapAdUserData : 4;
				}

				$fcm = isset( $result_raw['BannerConfigJSON']->facebookConsentMode ) ? (int) $result_raw['BannerConfigJSON']->facebookConsentMode : 0;

				// Is Facebook consent mode enabled? Pro-only — but enforced by the BACKEND,
				// which is the only party holding the authoritative plan. Designer API
				// logic.service.ts::downgradeLiveDefaults resets facebookConsentMode to its
				// default for a Free-plan app before this response is ever built, so $fcm is
				// already 0 for a free plan. Re-deciding it here off a locally cached
				// subscription added no enforcement and one failure mode: a Pro app whose
				// cache still said 'basic' silently lost the feature it had paid for.
				if ( $fcm === 1 ) {
					$result['facebook_consent_default']['consent'] = isset( $result_raw['BannerConfigJSON']->facebookConsentMapConsent ) ? (int) $result_raw['BannerConfigJSON']->facebookConsentMapConsent : 4;
				}

				$mcm = isset( $result_raw['BannerConfigJSON']->microsoftConsentMode ) ? (int) $result_raw['BannerConfigJSON']->microsoftConsentMode : 0;

				// Is Microsoft consent mode enabled? Pro-only, enforced by the backend for
				// the same reason as Facebook above — downgradeLiveDefaults already reset
				// microsoftConsentMode* for a Free-plan app.
				if ( $mcm === 1 ) {
					$result['microsoft_consent_default']['ad_storage'] = isset( $result_raw['BannerConfigJSON']->microsoftConsentMapAdStorage ) ? (int) $result_raw['BannerConfigJSON']->microsoftConsentMapAdStorage : 4;
					$result['microsoft_consent_default']['analytics_storage'] = isset( $result_raw['BannerConfigJSON']->microsoftConsentMapAnalyticsStorage ) ? (int) $result_raw['BannerConfigJSON']->microsoftConsentMapAnalyticsStorage : 3;
				}

				// Browser signal modes (cherry-picked for backward compat with Protection tab components).
				$result['gpc_support']  = ! empty( $result_raw['BannerConfigJSON']->gpcSupportMode );
				$result['do_not_track'] = ! empty( $result_raw['BannerConfigJSON']->doNotTrackMode );

				// Raw BannerConfigJSON — full behavioral config from Designer API.
				// React admin reads all fields directly from this (camelCase, same keys as API).
				// Eliminates per-field extraction: new Designer API fields are automatically
				// available in the plugin UI after a config pull.
				$result['banner_config'] = json_decode( wp_json_encode( $result_raw['BannerConfigJSON'] ), true );
			}

			if ( $network ) {
				$blocking_data = get_site_option( 'cookie_notice_app_blocking', [] );

				update_site_option( 'cookie_notice_app_blocking', $result );
			} else {
				$blocking_data = get_option( 'cookie_notice_app_blocking', [] );

				update_option( 'cookie_notice_app_blocking', $result, false );
			}

			// Sync regulations from Designer API to local WP option (#2186).
			// Keeps the Protection tab's law card in sync with what the Admin Portal shows.
			if ( ! empty( $result_raw['BannerConfigJSON'] ) && isset( $result_raw['BannerConfigJSON']->regulations ) ) {
				$api_regs = (array) $result_raw['BannerConfigJSON']->regulations;
				$active_reg_keys = array_keys( array_filter( $api_regs ) );

				if ( $network )
					update_site_option( 'cookie_notice_app_regulations', $active_reg_keys );
				else
					update_option( 'cookie_notice_app_regulations', $active_reg_keys );
			}

			// ── Begin base posture sync (BannerConfigJSON.blocking → app_blocking)
			//
			// The backend's top-level `blocking` and the WP option `app_blocking` are ONE
			// value in two stores, not two competing sources: the option is the local
			// materialization and this is the mapping that keeps it in step. Same shape as
			// the regulations sync directly above (#2186), for the same reason — so the
			// plugin and the Admin Portal stop disagreeing about a value they both own.
			//
			// ABSENT MUST NEVER OVERWRITE. `blocking` is net-new, so every app in the fleet
			// sends nothing for it today; treating a missing key as `false` would switch
			// autoblocking off site-wide on the first config pull after upgrade. isset()
			// rejects an absent key AND an explicit null in one test, and is_bool() rejects a
			// stringy '0'/'1' that would otherwise cast to a posture nobody chose. Only a
			// real boolean is authoritative.
			//
			// The render path is deliberately NOT touched: frontend.php keeps seeding
			// huOptions.blocking from app_blocking, which is now this synced value. That is
			// what keeps the wire key a definite boolean on every request — it can never
			// carry null, and no render-time read of a possibly-stale snapshot is introduced.
			//
			// RE-ENTRANCY. This write can re-enter itself, and unbounded. register_setting()
			// hooks validate_options() onto sanitize_option_cookie_notice_options, and core
			// runs sanitize_option() BEFORE it reads $old_value (wp-includes/option.php:884-885).
			// So on a classic settings save: update_option → validate_options →
			// get_app_config( $app_id, true, false ) → this sync → update_option → … and the
			// nested get_option() below still returns the PRE-write value, so the change test
			// passes again every time. $force_update = true also bypasses the one-pull-per-hour
			// throttle, so each level makes a live Designer API GET before dying on
			// memory_limit. Nothing in the payload terminates it: at depth 2 $input is the full
			// options array, so app_id/app_key are still set and so is the POST sentinel.
			//
			// It triggers on exactly the case this feature exists for — a connected legacy-UI
			// site whose Portal posture differs from the stored app_blocking, saving the classic
			// form. The unit tests could not see it because they drive the extracted region
			// directly rather than through update_option(); see tests/unit/base-posture-sync.php.
			//
			// NEVER write this option from inside its own sanitize filter. The re-entrancy
			// flag below bounds the recursion, but bounding it is not enough: the depth-2
			// validate_options() pass still runs IN FULL, with the stored DB row as $input,
			// and that pass is destructive. Its checkbox idioms are `$input[x] = isset(
			// $input[x] )`, and isset(false) is TRUE — so a stored `see_more_opt['sync'] =>
			// false` flips on and fires update_option( 'wp_page_for_privacy_policy', … ),
			// overwriting the SITE'S WordPress privacy-policy page with the plugin's stored
			// id. Eleven more stored false values flip true for the rest of the request.
			//
			// And on that same path the sync does not even land: the stored row carries no
			// app_blocking_rendered sentinel, so settings.php unsets app_blocking and the
			// preservation loop restores the pre-sync value.
			//
			// doing_filter() alone does NOT cover every save: validate_network_options()
			// (admin_init priority 9) calls validate_options() DIRECTLY rather than through
			// the filter, so the network settings page reaches here with the hook off the
			// stack — hence the cn-network-settings test alongside it. On that path the
			// destructive pass above does not currently fire, but only because
			// register_settings() runs at priority 10 and has not yet added validate_options
			// to the sanitize filter; nothing pins that ordering, so do not rely on it.
			//
			// So skip entirely while a save of this option is in flight. Nothing is lost —
			// the save is about to write the option anyway, and the posture lands on the
			// next pull that is not inside a save: the twicedaily cron, the React admin's
			// sync_config on mount, the purge endpoint, or the manual Pull Configuration.
			// This is a pure narrowing; it can only ever write less, never more.
			//
			// The pending test is the other half of that narrowing. This sync is
			// authoritative, so without it a push that failed to reach the API would be
			// silently undone here: the admin's change would stand locally only until the
			// next pull, which would reinstate the stale backend value for good. While the
			// flag is set the local value is the newer one and the backend is the one that
			// is behind, so the direction of authority is inverted until the retry lands.
			if ( ! doing_filter( 'sanitize_option_cookie_notice_options' ) && ! isset( $_POST['cn-network-settings'] ) && ! $this->syncing_base_posture && ! $this->is_posture_push_pending_for( $app_id ) && ! empty( $result_raw['BannerConfigJSON'] ) && isset( $result_raw['BannerConfigJSON']->blocking ) && is_bool( $result_raw['BannerConfigJSON']->blocking ) ) {
				$api_blocking = $result_raw['BannerConfigJSON']->blocking;
				$wp_options   = Cookie_Notice_Store::get( 'cookie_notice_options', [], $network );

				// Only write on a real change — a config pull runs on cron and on every admin
				// visit, and an unconditional update_option() would fire the #2272 guard and
				// every other pre_update filter on every pull for no reason. $wp_options is the
				// request's cached copy: if another request changed app_blocking since, either
				// the fresh write below lands the backend value or core skips it as identical; a
				// change the cache hides is left standing, to the request that made it.
				if ( is_array( $wp_options ) && ( ! array_key_exists( 'app_blocking', $wp_options ) || (bool) $wp_options['app_blocking'] !== $api_blocking ) ) {
					// #2272 COLLISION, handled here rather than discovered later.
					// preserve_app_blocking_preference() is registered on
					// pre_update_option_cookie_notice_options and rewrites app_blocking back
					// to `true` on any write carrying the key while the Free-plan quota force
					// is armed. It exists to stop the forced `false` leaking into storage, and
					// it cannot tell that apart from a genuine new preference arriving here —
					// so without this, a backend `false` on an over-quota site would be
					// silently reverted to `true`.
					//
					// Re-point the guard at the value the backend just delivered: that IS the
					// stored preference from now on, so the write survives and the guard goes
					// on protecting the right value for the rest of the request. Guarded on
					// "armed" because setting it from null would arm a guard that should stay
					// inert and could then flip a later, legitimate save in the same request.
					$force_armed = ( $cn->app_blocking_stored !== null );

					if ( $force_armed )
						$cn->app_blocking_stored = $api_blocking;
					else
						// Not armed: keep the in-memory copy consistent for the rest of this
						// request. While the force IS armed we must leave it alone — the quota
						// overlay outranks both the admin and the portal for this request. So the
						// write below is told not to touch the in-memory copy ($sync_memory false):
						// this line is the only in-memory change the sync makes.
						$cn->options['general']['app_blocking'] = $api_blocking;

					// Stops the re-entrancy described above: the nested pass reaches this region
					// and skips it, so the recursion terminates at depth 2. Reset in a finally so
					// an exception inside update_option cannot leave the sync disabled for the
					// rest of the request.
					//
					// Note for whoever adds the plugin→backend push back: validate_options() IS
					// hooked to sanitize_option_cookie_notice_options, so this write re-enters it.
					// Any push called from there must refuse while this flag is raised, or a pull
					// will echo itself straight back to the API that sent it.
					//
					// Only app_blocking is written, over a fresh read of the row
					// (update_general_option_keys()): $wp_options is this request's cached copy,
					// and storing it whole undid any change another request made since — with
					// core's old value just as stale, so the reverted posture staged no push.
					$this->syncing_base_posture = true;

					try {
						$cn->update_general_option_keys( [ 'app_blocking' => $api_blocking ], $network, false );
					} finally {
						$this->syncing_base_posture = false;
					}
				}
			}
			// ── End base posture sync (BannerConfigJSON.blocking → app_blocking)

			// Cache visual design fields from Designer API response.
			// position, bannerColor, primaryColor live in UserDesignJSON (visual design),
			// NOT BannerConfigJSON (behavioral config). Reading from BannerConfigJSON
			// returned empty strings and lost the design on cron refresh (#2261).
			if ( ! empty( $result_raw['UserDesignJSON'] ) && is_object( $result_raw['UserDesignJSON'] ) ) {
				$udj = $result_raw['UserDesignJSON'];

				$design = [
					'position'        => isset( $udj->position )        ? (string) $udj->position        : '',
					'displayType'     => isset( $udj->displayType )     ? (string) $udj->displayType     : '',
					'bannerColor'     => isset( $udj->bannerColor )     ? (string) $udj->bannerColor     : '',
					'primaryColor'    => isset( $udj->primaryColor )    ? (string) $udj->primaryColor    : '',
				];

				// Cache consent level labels from DefaultUserTextJSON so the React
				// admin can show the customer-configured names on ConsentStats cards
				// and audit log pills instead of hardcoded Accept/Custom/Reject.
				if ( ! empty( $result_raw['DefaultUserTextJSON'] ) && is_object( $result_raw['DefaultUserTextJSON'] ) ) {
					$utj = $result_raw['DefaultUserTextJSON'];

					$design['levelNameText_1'] = isset( $utj->levelNameText_1 ) ? (string) $utj->levelNameText_1 : '';
					$design['levelNameText_2'] = isset( $utj->levelNameText_2 ) ? (string) $utj->levelNameText_2 : '';
					$design['levelNameText_3'] = isset( $utj->levelNameText_3 ) ? (string) $utj->levelNameText_3 : '';

					// The banner's Do Not Sell link (default language), read only: the law
					// editors prefill their field with it. Display data for the admin, never
					// read by the front end.
					$design['dontSellUrl'] = isset( $utj->dontSellUrl ) && is_string( $utj->dontSellUrl ) ? $utj->dontSellUrl : '';
				}

				if ( $network )
					update_site_option( 'cookie_notice_app_design', $design );
				else
					update_option( 'cookie_notice_app_design', $design, false );
			}

			// debug: log what gets stored
			if ( $cn->options['general']['debug_mode'] ) {
				error_log( '[Cookie Notice] get_app_config - Stored providers count: ' . count( $result['providers'] ) );
				error_log( '[Cookie Notice] get_app_config - Stored patterns count: ' . count( $result['patterns'] ) );
				error_log( '[Cookie Notice] get_app_config - Stored blocking fields: ' . count( $result ) );
			}
		} else {
			if ( $cn->options['general']['debug_mode'] ) {
				error_log( '[Cookie Notice] get_app_config - No data in response. Error: ' . ( ! empty( $response->error ) ? $response->error : 'unknown' ) );
			}

			// ── Begin failed-pull status resolution ──────────────────────────
			// Start from what is STORED, not from the defaults: neither branch below
			// learned anything about subscription / widget_version / activation, so
			// those must survive. The snapshot was taken before the pull and already
			// merged against the defaults, so the shape is complete even if the stored
			// option predates a field.
			$status_data = $stored_status_data;

			if ( $platform_answered && ! empty( $response->error ) ) {
				// Authoritative: the platform answered, and it answered about this
				// app's status specifically. 'App is not published yet' is a real
				// signal and must still land.
				if ( $response->error == 'App is not published yet' )
					$status_data['status'] = 'pending';
				else
					$status_data['status'] = '';
			} else {
				// Unreachable platform — we learned nothing. Keep every stored value
				// and write nothing at all.
				$store_status = false;
			}
			// ── End failed-pull status resolution ────────────────────────────
		}

		// ── Begin guarded status write ───────────────────────────────────────────
		// The guard is the P0 fix: this write used to be unconditional, so a failed
		// pull persisted the defaults seeded above over good values.
		if ( $store_status ) {
			if ( $network )
				update_site_option( 'cookie_notice_status', $status_data );
			else
				update_option( 'cookie_notice_status', $status_data, false );
		}
		// ── End guarded status write ─────────────────────────────────────────────

		// get current status data
		$status_data_old = $cn->get_status_data();

		// update status data
		$cn->set_status_data();

		// check blocking data
		if ( isset( $blocking_data, $result ) ) {
			// do not compare dates
			unset( $blocking_data['lastUpdated'] );
			unset( $result['lastUpdated'] );

			// simple comparing, objects inside
			$blocking_data_updated = $blocking_data != $result;
		} else
			$blocking_data_updated = false;

		// only when status data or blocking data changed
		if ( $force_action && ( $status_data_old !== $status_data || $blocking_data_updated ) ) {
			do_action( 'cn_configuration_updated', 'config', [
				'status'	=> $status_data,
				'blocking'	=> empty( $result ) ? [] : $result
			] );
		}

		return $status_data;
	}

	/**
	 * Remember which engine the config pull named for which app; when a pull for the SAME app
	 * names another engine, store the one-time notice Cookie_Notice::display_engine_notice()
	 * shows: cookie_notice_engine_changed = { from, to, at }.
	 *
	 * The App ID is remembered here because the status row carries none, and connecting a site
	 * (or reconnecting it to another app) changes the engine too without being news — the
	 * connect confirmation names the banner. So the first pull with nothing remembered and a
	 * pull for another app only remember; a notice still waiting from the previous app is
	 * dropped, since it describes that app's banner. Both rows live in the pull's scope.
	 *
	 * @param string $app_id         The app the pull read.
	 * @param string $widget_version The engine the platform named ('v2' = New, anything else Classic).
	 * @param bool   $network        The pull's scope (get_app_config()'s $network).
	 * @return void
	 */
	public function note_engine_change( $app_id, $widget_version, $network ) {
		$app_id = (string) $app_id;
		$engine = $widget_version === 'v2' ? 'new' : 'classic';
		$seen   = Cookie_Notice_Store::get( 'cookie_notice_engine_seen', [], $network );
		$known  = is_array( $seen ) && isset( $seen['app_id'], $seen['engine'] );

		// Nothing new: no write on the routine pull (cron, page visits).
		if ( $known && $seen['app_id'] === $app_id && $seen['engine'] === $engine )
			return;

		if ( $known && $seen['app_id'] === $app_id )
			Cookie_Notice_Store::set( 'cookie_notice_engine_changed', [ 'from' => $seen['engine'], 'to' => $engine, 'at' => time() ], $network, false );
		elseif ( $known )
			Cookie_Notice_Store::delete( 'cookie_notice_engine_changed', $network );

		Cookie_Notice_Store::set( 'cookie_notice_engine_seen', [ 'app_id' => $app_id, 'engine' => $engine ], $network, false );
	}

	/**
	 * AJAX: Save the banner style — Standard or Compact — of the connected app.
	 *
	 * POST fields: style (standard|compact)
	 *
	 * A look-only field of the New engine: web-channel-v2_4 reads config.bannerStyle, and the
	 * Classic engine has no styles, so it is refused there. Written as the Admin Portal writes
	 * it, PATCH /by-app with config.bannerStyle and NOTHING else: that merges the one key onto
	 * the published record and leaves the customer's draft unpublished (request(),
	 * 'patch_by_app'). No design, text, DefaultLanguage or autoPublish. Not plan-gated (DEC-013).
	 *
	 * NEVER quick_config, not even as the fallback react_update_design() takes for an app with
	 * no design record: quick_config does not carry bannerStyle (designer-api
	 * userDesign.controller.ts, quick config), so it would answer 200 for a style it dropped.
	 * That app is told to publish first instead.
	 *
	 * Then the forced pull, as react_update_design(): the stored config holds the new value
	 * before cn_configuration_updated purges page caches; and the reply carries the banner block
	 * as that pull left it (React's PULL_ACTIONS adopt it).
	 *
	 * @return void
	 */
	public function react_save_banner_style() {
		$this->verify_react_request();
		Cookie_Notice()->settings->verify_not_network_managed();

		$cn = Cookie_Notice();

		// ── Begin Network Admin per-site guard ───────────────────────────────────
		// The Network Admin with Global Settings Override off: every site runs on its own app,
		// so the network row's app serves no visitor, and get_app_config() would not pull it.
		// The vetted scope (is_network_admin()), never $_POST['cn_network'].
		if ( $cn->is_network_admin() && ! $cn->is_network_options() )
			wp_send_json_error( [ 'error' => __( 'Shared settings are off. Each site uses its own settings — manage them from each site\'s dashboard.', 'cookie-notice' ) ] );
		// ── End Network Admin per-site guard ─────────────────────────────────────

		$app_id  = $cn->options['general']['app_id'];
		$app_key = $cn->options['general']['app_key'];

		if ( empty( $app_id ) || empty( $app_key ) )
			wp_send_json_error( [ 'error' => __( 'Connect this site to Cookie Compliance first, then choose a style.', 'cookie-notice' ) ] );

		// Shared-app write gate — above the PATCH, as in react_update_design().
		if ( $cn->is_network_shared_app( $app_id ) && ! $cn->can_write_at_scope( true ) )
			wp_send_json_error( [ 'error' => $cn->network_scope_denied_message() ], 403 );

		$style = isset( $_POST['style'] ) && is_string( $_POST['style'] ) ? wp_unslash( $_POST['style'] ) : '';

		if ( ! in_array( $style, [ 'standard', 'compact' ], true ) )
			wp_send_json_error( [ 'error' => __( 'Choose Standard or Compact.', 'cookie-notice' ) ] );

		// ── Begin engine guard ───────────────────────────────────────────────────
		// The engine this site loads (get_banner_channel(): status widget_version 'v2'), the one
		// the screens name. Classic has no banner styles.
		if ( $cn->get_banner_channel() !== 'v2' )
			wp_send_json_error( [ 'error' => __( 'Banner style is available once this site is on the New engine.', 'cookie-notice' ) ] );
		// ── End engine guard ─────────────────────────────────────────────────────

		$result = $this->request( 'patch_by_app', [
			'AppID'  => $app_id,
			'config' => (object) [ 'bannerStyle' => $style ],
		] );

		// No design record: answered HTTP 200 with { status: 400, i18n_msg } — see above for why
		// this does not fall back to quick_config.
		if ( is_object( $result ) && isset( $result->i18n_msg ) && $result->i18n_msg === 'user_design_update_id_not_found' )
			wp_send_json_error( [ 'error' => __( 'Publish your banner in the Admin Portal first, then choose a style.', 'cookie-notice' ) ] );

		if ( ! is_object( $result ) || ! isset( $result->status ) || $result->status !== 200 ) {
			$error = __( 'The banner style could not be saved. Please try again.', 'cookie-notice' );

			if ( is_array( $result ) && ! empty( $result['error'] ) && is_string( $result['error'] ) )
				$error = $result['error'];
			elseif ( is_object( $result ) && ! empty( $result->message ) && is_string( $result->message ) )
				$error = $result->message;
			elseif ( is_object( $result ) && ! empty( $result->error ) && is_string( $result->error ) )
				$error = $result->error;
			elseif ( is_object( $result ) && ! empty( $result->i18n_msg ) && is_string( $result->i18n_msg ) )
				$error = 'API error: ' . $result->i18n_msg;

			wp_send_json_error( [ 'error' => $error ] );
		}

		// Pull confirmed state from the portal (forced), as react_update_design().
		$this->get_app_config( $app_id, true, true );

		// After the pull: the banner as the pull left it (React's store adopts it, api/index.js).
		wp_send_json_success( [ 'status' => 200, 'style' => $style, 'banner' => $cn->get_banner_summary() ] );
	}

	/**
	 * Verify React admin AJAX request (nonce + capability).
	 *
	 * @return void Dies on failure.
	 */
	private function verify_react_request() {
		check_ajax_referer( 'cn_react_nonce', 'nonce' );

		if ( ! current_user_can( apply_filters( 'cn_manage_cookie_notice_cap', 'manage_options' ) ) )
			wp_send_json_error( [ 'error' => 'Insufficient permissions.' ] );
	}

	/**
	 * Determine whether a write should use PATCH /by-app/:AppID (connected app, existing design)
	 * or fall back to quick_config (initial creation).
	 *
	 * Returns 'patch_by_app' for connected FREE/PRO users with a real AppID.
	 * Returns 'quick_config' for BASIC/unconnected, or DevMode mock IDs (cn-dev-*).
	 * DevMode mock IDs bypass the API entirely — AJAX returns synthetic success so the UI
	 * can be tested without hitting a real API.
	 *
	 * @param string $app_id The AppID being written.
	 * @return string 'patch_by_app' | 'quick_config' | 'devmode'
	 */
	private function get_write_request_type( $app_id ) {
		// DevMode mock IDs — never hit the real API.
		if ( defined( 'CN_DEV_MODE' ) && CN_DEV_MODE && strpos( $app_id, 'cn-dev-' ) === 0 )
			return 'devmode';

		// Connected app with a real ID — use the PATCH update endpoint.
		return 'patch_by_app';
	}

	/**
	 * AJAX: Push design updates to Designer API via quick_config.
	 *
	 * POST fields: design[position], design[displayType], design[bannerColor], etc.;
	 * sync_privacy_policy=1 alone refreshes the banner's privacy policy link and nothing else
	 * (the connected Settings save, PluginSettings.jsx).
	 * Requires connected app (app_id in WP options).
	 *
	 * Every write carries text.privacyPolicyUrl = get_privacy_policy_url(), but only when
	 * WordPress has a privacy policy page: an empty value would wipe the link set in the
	 * Admin Portal. A request left with nothing to send makes no request.
	 *
	 * @return void
	 */
	public function react_update_design() {
		$this->verify_react_request();
		Cookie_Notice()->settings->verify_not_network_managed();

		$cn = Cookie_Notice();
		$app_id = $cn->options['general']['app_id'];

		if ( empty( $app_id ) ) {
			wp_send_json_error( [ 'error' => 'No app connected.' ] );
		}

		// ── Begin shared-app write gate ──────────────────────────────────────────
		// THIS MUST STAY ABOVE THE PATCH. Everything below — the remote PATCH and the local
		// mirror both — lands on the record named by $app_id, and when that is the network's
		// app every site on the network serves the result. verify_react_request() proves only
		// manage_options, which every subsite administrator holds.
		//
		// A gate placed after the remote call is worse than none: the platform has already
		// changed, and refusing only the local write leaves the admin UI showing stale config
		// while the live banner serves the new one. An earlier revision of this fix did
		// exactly that.
		//
		// ASK THE APP, NOT THE ROW. An earlier revision asked is_network_options() here, which
		// is false as soon as global_override is switched off — while every subsite's row
		// still names the network's app, because load_defaults() put it there. Reproduced on
		// a real multisite: same user, same app, this gate said allow and
		// may_push_base_posture() said refuse. See Cookie_Notice::is_network_shared_app().
		if ( $cn->is_network_shared_app( $app_id ) && ! $cn->can_write_at_scope( true ) )
			wp_send_json_error( [ 'error' => $cn->network_scope_denied_message() ], 403 );
		// ── End shared-app write gate ────────────────────────────────────────────

		$design_raw  = isset( $_POST['design'] )        && is_array( $_POST['design'] )        ? $_POST['design']        : [];
		$config_raw  = isset( $_POST['config'] )        && is_array( $_POST['config'] )        ? $_POST['config']        : [];
		$consent_raw = isset( $_POST['consentConfig'] ) && is_array( $_POST['consentConfig'] ) ? $_POST['consentConfig'] : [];
		$sync_policy = ! empty( $_POST['sync_privacy_policy'] );

		if ( empty( $design_raw ) && empty( $config_raw ) && empty( $consent_raw ) && ! $sync_policy ) {
			wp_send_json_error( [ 'error' => 'No update data provided.' ] );
		}

		// Allowed design fields with sanitization
		$allowed_fields = [
			'position'             => 'sanitize_key',
			'displayType'          => 'sanitize_key',
			'bannerColor'          => 'sanitize_hex_color',
			'primaryColor'         => 'sanitize_hex_color',
			'textColor'            => 'sanitize_hex_color',
			'headingColor'         => 'sanitize_hex_color',
			'btnTextColor'         => 'sanitize_hex_color',
			'btnBorderRadius'      => 'sanitize_text_field',
			'animation'            => 'sanitize_key',
			'bannerOpacity'        => 'sanitize_text_field',
			'revokePosition'       => 'sanitize_key',
			'showBulletPoints'     => null, // boolean
		];
		// Note: googleConsentMode / facebookConsentMode / microsoftConsentMode are NOT design fields.
		// The PATCH /by-app endpoint rejects them in design{}. They belong in config{} as booleans.
		// They are handled below alongside gpcSupportMode / doNotTrackMode.

		$design = new stdClass();

		foreach ( $allowed_fields as $field => $sanitizer ) {
			if ( ! array_key_exists( $field, $design_raw ) )
				continue;

			if ( $field === 'showBulletPoints' ) {
				$design->{$field} = filter_var( $design_raw[ $field ], FILTER_VALIDATE_BOOLEAN );
			} elseif ( $sanitizer ) {
				$design->{$field} = call_user_func( $sanitizer, $design_raw[ $field ] );
			}
		}

		// Validate position — translate 'popup' → 'center' (portal label vs CSS class)
		if ( isset( $design->position ) ) {
			if ( $design->position === 'popup' ) {
				$design->position = 'center';
			} elseif ( ! in_array( $design->position, [ 'bottom', 'top', 'left', 'right', 'center' ], true ) ) {
				$design->position = 'bottom';
			}
		}

		// Validate displayType
		if ( isset( $design->displayType ) && ! in_array( $design->displayType, [ 'floating', 'fixed' ], true ) )
			$design->displayType = 'floating';

		// Validate animation
		if ( isset( $design->animation ) && ! in_array( $design->animation, [ 'fade', 'slide', 'none' ], true ) )
			$design->animation = 'fade';

		// Validate bannerOpacity
		if ( isset( $design->bannerOpacity ) ) {
			$opacity = (float) $design->bannerOpacity;
			$design->bannerOpacity = max( 0.0, min( 1.0, $opacity ) );
		}

		// Build config object from allowed behavior fields
		$config_allowed = [ 'revokeConsent', 'revokeMethod', 'onScroll', 'onScrollOffset', 'onClick', 'reloading' ];
		$config = new stdClass();
		foreach ( $config_allowed as $f ) {
			if ( isset( $config_raw[ $f ] ) )
				$config->$f = sanitize_text_field( $config_raw[ $f ] );
		}

		// Merge consent mode fields into config{} — the Designer API stores all of these
		// under BannerConfigJSON, not a separate consentConfig key. The PATCH /by-app endpoint
		// rejects a top-level consentConfig key entirely.
		//
		// Field name mapping (JS POST key → API config key):
		//   gpcSupport  → gpcSupportMode  (boolean)
		//   doNotTrack  → doNotTrackMode  (boolean)
		//   All GCM/Facebook/Microsoft map fields keep their names, as integers.
		// Map/level fields: must be integers (0–4).
		$consent_int_fields = [
			'googleConsentMapAdStorage', 'googleConsentMapAnalytics', 'googleConsentMapFunctionality',
			'googleConsentMapPersonalization', 'googleConsentMapSecurity', 'googleConsentMapAdPersonalization',
			'googleConsentMapAdUserData', 'facebookConsentMapConsent', 'microsoftConsentMapAdStorage',
			'microsoftConsentMapAnalyticsStorage',
		];
		foreach ( $consent_int_fields as $f ) {
			if ( isset( $consent_raw[ $f ] ) )
				$config->$f = (int) $consent_raw[ $f ];
		}
		// IMPORTANT: Toggle fields MUST use (bool)(int) — NOT bare (int).
		// wp_json_encode((int)1) = JSON 1 (integer) — API silently drops it.
		// wp_json_encode((bool)true) = JSON true (boolean) — API persists it.
		// See commit 8ff1432 for the original fix. Do NOT revert to (int).
		$consent_bool_fields = [ 'microsoftConsentModePixie', 'microsoftConsentModeClarity' ];
		foreach ( $consent_bool_fields as $f ) {
			if ( isset( $consent_raw[ $f ] ) )
				$config->$f = (bool) (int) $consent_raw[ $f ];
		}
		// gpcSupport → gpcSupportMode (bool). Pro-gated with grandfather:
		// Free apps cannot set gpcSupportMode=true unless it's already true
		// (grandfathered). Disabling is always allowed; once disabled on Free,
		// the app loses its grandfather and cannot re-enable. See control-room/
		// solution/knowledge/decisions.md (gpc-pro-gating-with-grandfather).
		if ( isset( $consent_raw['gpcSupport'] ) ) {
			$incoming_gpc = (bool) (int) $consent_raw['gpcSupport'];
			$is_pro       = $cn->get_subscription() === 'pro';

			if ( $is_pro || ! $incoming_gpc ) {
				// Pro: anything goes. Free + setting to false: always allowed.
				$config->gpcSupportMode = $incoming_gpc;
			} else {
				// Free + setting to true: only honor if already true (grandfather).
				$existing_blocking = Cookie_Notice_Store::get( 'cookie_notice_app_blocking', [], $cn->is_network_options() );
				if ( ! empty( $existing_blocking['banner_config']['gpcSupportMode'] ) )
					$config->gpcSupportMode = true;
				// else: silently strip — UI gate should have prevented this anyway.
			}
		}
		// gpcBannerMode → gpcBannerMode (string enum). Not Pro-gated directly:
		// it's only consulted when gpcSupportMode is true, so transitive gating
		// via the parent toggle is sufficient. Validate the enum here and let
		// stray values fall through to the persisted/default value.
		if ( isset( $consent_raw['gpcBannerMode'] ) ) {
			$mode = sanitize_key( $consent_raw['gpcBannerMode'] );
			if ( in_array( $mode, [ 'banner', 'hidden', 'passive' ], true ) )
				$config->gpcBannerMode = $mode;
		}
		// doNotTrack → doNotTrackMode (bool)
		if ( isset( $consent_raw['doNotTrack'] ) )
			$config->doNotTrackMode = (bool) (int) $consent_raw['doNotTrack'];
		// Consent mode flags (google/facebook/microsoft) — sent in design_raw from the React POST
		// but must be placed in config{} as booleans. The PATCH /by-app endpoint rejects them in design{}.
		foreach ( [ 'googleConsentMode', 'facebookConsentMode', 'microsoftConsentMode' ] as $mode_field ) {
			if ( isset( $design_raw[ $mode_field ] ) )
				$config->$mode_field = (bool) (int) $design_raw[ $mode_field ];
		}

		// Build params — only include non-empty objects
		$params = [
			'AppID'           => $app_id,
			'DefaultLanguage' => 'en',
		];
		// The WordPress privacy policy page, never an empty one (see the docblock).
		$privacy_policy_url = (string) get_privacy_policy_url();
		if ( $privacy_policy_url !== '' )
			$params['text'] = (object) [ 'privacyPolicyUrl' => $privacy_policy_url ];
		if ( ! empty( (array) $design ) )
			$params['design'] = $design;
		if ( ! empty( (array) $config ) )
			$params['config'] = $config;

		// Nothing to send (e.g. the privacy policy refresh on a site with no privacy policy
		// page): no request, no pull.
		if ( ! isset( $params['text'] ) && ! isset( $params['design'] ) && ! isset( $params['config'] ) ) {
			wp_send_json_success( [ 'status' => 200, 'skipped' => true, 'banner' => $cn->get_banner_summary() ] );
			return;
		}

		$write_type = $this->get_write_request_type( $app_id );

		// PATCH /by-app endpoint does not accept DefaultLanguage -- strip it.
		if ( $write_type === 'patch_by_app' ) {
			unset( $params['DefaultLanguage'] );
		}
		// DevMode mock ID — return synthetic success so the UI can be tested without a real API.
		if ( $write_type === 'devmode' ) {
			wp_send_json_success( [ 'status' => 200, 'dev_mode' => true, 'banner' => $cn->get_banner_summary() ] );
			return;
		}

		$result = $this->request( $write_type, $params );

		// debug: the API's status for consent mode debugging, never the body
		if ( $cn->options['general']['debug_mode'] ) {
			error_log( 'react_update_design API result: ' . $this->debug_reply_summary( $result ) );
		}

		// Design record not yet created — fall back to quick_config to seed it.
		// The API returns { i18n_msg: 'user_design_update_id_not_found', status: 400 } (HTTP 200)
		// when no record exists, so check i18n_msg — not statusCode/404.
		// Also restore DefaultLanguage which patch_by_app doesn't accept but quick_config requires.
		if ( is_object( $result ) && isset( $result->i18n_msg ) && $result->i18n_msg === 'user_design_update_id_not_found' ) {
			$params['DefaultLanguage'] = 'en';
			$result = $this->request( 'quick_config', $params );
		}

		if ( is_object( $result ) && isset( $result->status ) && $result->status === 200 ) {
			// Pull confirmed state from portal — makes portal unambiguous SoT.
			// Updates cookie_notice_app_blocking (GCM/GPC signal maps),
			// fires cn_configuration_updated → clears page caches.
			// Does NOT set cookie_notice_config_update transient (widget CDN cache).
			$this->get_app_config( $app_id, true, true );

			// After the pull: the banner as the pull left it (React's store adopts it, api/index.js).
			wp_send_json_success( [ 'status' => 200, 'banner' => $cn->get_banner_summary() ] );
		} else {
			$error = 'Design update failed.';

			if ( is_array( $result ) && ! empty( $result['error'] ) )
				$error = $result['error'];
			elseif ( is_object( $result ) && ! empty( $result->message ) )
				$error = $result->message;
			elseif ( is_object( $result ) && ! empty( $result->error ) )
				$error = $result->error;
			elseif ( is_object( $result ) && ! empty( $result->i18n_msg ) )
				$error = 'API error: ' . $result->i18n_msg;
			elseif ( $result === null )
				$error = 'No response from API — check connection.';

			wp_send_json_error( [ 'error' => $error, 'apiSync' => false ] );
		}
	}

	/**
	 * AJAX: Apply languages via quick_config.
	 *
	 * POST fields: languages[] (array of language codes)
	 * Server-side enforcement of free plan 1-language limit.
	 *
	 * @return void
	 */
	public function react_apply_languages() {
		$this->verify_react_request();
		Cookie_Notice()->settings->verify_not_network_managed();

		$cn = Cookie_Notice();
		$app_id = $cn->options['general']['app_id'];

		if ( empty( $app_id ) ) {
			wp_send_json_error( [ 'error' => 'No app connected.' ] );
		}

		// ── Begin shared-app write gate ──────────────────────────────────────────
		// THIS MUST STAY ABOVE THE PATCH. Everything below — the remote PATCH and the local
		// mirror both — lands on the record named by $app_id, and when that is the network's
		// app every site on the network serves the result. verify_react_request() proves only
		// manage_options, which every subsite administrator holds.
		//
		// A gate placed after the remote call is worse than none: the platform has already
		// changed, and refusing only the local write leaves the admin UI showing stale config
		// while the live banner serves the new one. An earlier revision of this fix did
		// exactly that.
		//
		// ASK THE APP, NOT THE ROW. An earlier revision asked is_network_options() here, which
		// is false as soon as global_override is switched off — while every subsite's row
		// still names the network's app, because load_defaults() put it there. Reproduced on
		// a real multisite: same user, same app, this gate said allow and
		// may_push_base_posture() said refuse. See Cookie_Notice::is_network_shared_app().
		if ( $cn->is_network_shared_app( $app_id ) && ! $cn->can_write_at_scope( true ) )
			wp_send_json_error( [ 'error' => $cn->network_scope_denied_message() ], 403 );
		// ── End shared-app write gate ────────────────────────────────────────────

		$languages_raw = isset( $_POST['languages'] ) && is_array( $_POST['languages'] ) ? $_POST['languages'] : [];

		// Sanitize and validate language codes (2-letter ISO 639-1)
		$allowed_languages = [ 'fr', 'es', 'de', 'it', 'el', 'nl', 'pt', 'pl', 'sv' ];
		$languages = [];

		foreach ( $languages_raw as $lang ) {
			$lang = sanitize_key( $lang );

			if ( in_array( $lang, $allowed_languages, true ) )
				$languages[] = $lang;
		}

		// Free plan: enforce 1-language limit
		$subscription = $cn->get_subscription();
		$status = $cn->get_status();
		$is_free = ( $status === 'active' && $subscription === 'basic' );

		if ( $is_free && count( $languages ) > 1 )
			$languages = array_slice( $languages, 0, 1 );

		$params = [
			'AppID'           => $app_id,
			'DefaultLanguage' => 'en',
			'languages'       => $languages,
		];

		$write_type = $this->get_write_request_type( $app_id );

		// PATCH /by-app endpoint does not accept DefaultLanguage -- strip it.
		if ( $write_type === 'patch_by_app' ) {
			unset( $params['DefaultLanguage'] );
		}
		// DevMode mock ID — return synthetic success so the UI can be tested without a real API.
		if ( $write_type === 'devmode' ) {
			wp_send_json_success( [ 'status' => 200, 'languages' => $languages, 'dev_mode' => true ] );
			return;
		}

		$result = $this->request( $write_type, $params );

		// Design record not yet created — fall back to quick_config to seed it.
		// The API returns { i18n_msg: 'user_design_update_id_not_found', status: 400 } (HTTP 200)
		// when no record exists, so check i18n_msg — not statusCode/404.
		// Also restore DefaultLanguage which patch_by_app doesn't accept but quick_config requires.
		// And the languages key: the by-app body names it `languages`, the quick body `Languages`
		// ( Designer API userDesign.schema.ts `quick` ), and the quick body rejects unknown keys
		// ( bodyValidator, Joi allowUnknown off ) — sent as `languages` the whole request is refused.
		if ( is_object( $result ) && isset( $result->i18n_msg ) && $result->i18n_msg === 'user_design_update_id_not_found' ) {
			$params['DefaultLanguage'] = 'en';
			$params['Languages']       = $params['languages'];

			unset( $params['languages'] );

			$result = $this->request( 'quick_config', $params );
		}

		if ( is_object( $result ) && isset( $result->status ) && $result->status === 200 ) {
			// Persist applied languages locally so the dashboard can reflect the real count.
			$network = is_multisite() && $cn->is_plugin_network_active() && $cn->network_options['general']['global_override'];

			// Belt to the shared-app gate at the top of this handler, which is the one that
			// matters (it precedes the PATCH). Kept so a future path reaching this write
			// without going through the handler entry still refuses. This handler does NOT
			// route through get_app_config(), so that gate never covered it — an earlier
			// revision of this fix claimed it did, which is how this write survived a round.
			if ( $network && ! $cn->can_write_at_scope( true ) )
				wp_send_json_error( [ 'error' => $cn->network_scope_denied_message() ], 403 );

			if ( $network )
				update_site_option( 'cookie_notice_app_languages', $languages );
			else
				update_option( 'cookie_notice_app_languages', $languages, false );

			wp_send_json_success( [ 'status' => 200, 'languages' => $languages ] );
		} else {
			$error = 'Language update failed.';

			if ( is_array( $result ) && ! empty( $result['error'] ) )
				$error = $result['error'];
			elseif ( is_object( $result ) && ! empty( $result->message ) )
				$error = $result->message;

			wp_send_json_error( [ 'error' => $error, 'apiSync' => false ] );
		}
	}
}
