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

		// React write hooks — only register when ui_mode is "react" (#2267).
		$ui_mode = Cookie_Notice()->options['general']['ui_mode'] ?? 'legacy';

		if ( $ui_mode === 'react' ) {
			add_action( 'wp_ajax_cn_react_update_design', [ $this, 'react_update_design' ] );
			add_action( 'wp_ajax_cn_react_apply_template', [ $this, 'react_apply_template' ] );
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
		if ( ! in_array( $request, [ 'register', 'login', 'configure', 'select_plan', 'payment', 'get_bt_init_token', 'use_license', 'sync_config' ], true ) )
			wp_die( __( 'You do not have permission to access this page.', 'cookie-notice' ) );

		$special_actions = [ 'register', 'login', 'configure', 'payment' ];

		// payment nonce
		if ( $request === 'payment' )
			$nonce = isset( $_POST['cn_payment_nonce'] ) ? sanitize_key( $_POST['cn_payment_nonce'] ) : '';
		// special nonce
		elseif ( in_array( $request, $special_actions, true ) )
			$nonce = isset( $_POST['cn_nonce'] ) ? sanitize_key( $_POST['cn_nonce'] ) : '';

		// check additional nonce
		if ( in_array( $request, $special_actions, true ) && ! wp_verify_nonce( $nonce, 'cn_api_' . $request ) )
			wp_die( __( 'You do not have permission to access this page.', 'cookie-notice' ) );

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
				$allowed_subs = $network
					? get_site_transient( 'cookie_notice_app_subscriptions' )
					: get_transient( 'cookie_notice_app_subscriptions' );

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

				if ( $network ) {
					$status_data = get_site_option( 'cookie_notice_status', $status_data );
					$status_data['subscription'] = 'pro';

					// get activation timestamp
					$timestamp = $cn->get_cc_activation_datetime();

					// update activation timestamp only for new cookie compliance activations
					$status_data['activation_datetime'] = $timestamp === 0 ? time() : $timestamp;

					update_site_option( 'cookie_notice_status', $status_data );
				} else {
					$status_data = get_option( 'cookie_notice_status', $status_data );
					$status_data['subscription'] = 'pro';

					// get activation timestamp
					$timestamp = $cn->get_cc_activation_datetime();

					// update activation timestamp only for new cookie compliance activations
					$status_data['activation_datetime'] = $timestamp === 0 ? time() : $timestamp;

					update_option( 'cookie_notice_status', $status_data );
				}

				// License assignment (use_license): do not CLEAR setup_wizard_complete on
				// existing sites — that would send already-configured domains back to
				// FirstRunSetup and make them appear as Free on reload (#1893).
				//
				// For brand-new domains the option was never written, so the wizard would
				// fire unnecessarily for existing subscribers assigning a new slot.
				// Set the flag only if it hasn't been set before — new domain case.
				if ( $network ) {
					if ( ! get_site_option( 'cookie_notice_setup_wizard_complete', false ) ) {
						update_site_option( 'cookie_notice_setup_wizard_complete', true );
					}
				} else {
					if ( ! get_option( 'cookie_notice_setup_wizard_complete', false ) ) {
						update_option( 'cookie_notice_setup_wizard_complete', true );
					}
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

				// subscription assigned?
				if ( ! empty( $subscription->error ) ) {
					$response = $subscription->error;
					break;
				}

				$status_data = $cn->defaults['data'];

				// update app status
				if ( $network ) {
					$status_data = get_site_option( 'cookie_notice_status', $status_data );
					$status_data['subscription'] = 'pro';

					// get activation timestamp
					$timestamp = $cn->get_cc_activation_datetime();

					// update activation timestamp only for new cookie compliance activations
					$status_data['activation_datetime'] = $timestamp === 0 ? time() : $timestamp;

					update_site_option( 'cookie_notice_status', $status_data );
				} else {
					$status_data = get_option( 'cookie_notice_status', $status_data );
					$status_data['subscription'] = 'pro';

					// get activation timestamp
					$timestamp = $cn->get_cc_activation_datetime();

					// update activation timestamp only for new cookie compliance activations
					$status_data['activation_datetime'] = $timestamp === 0 ? time() : $timestamp;

					update_option( 'cookie_notice_status', $status_data );
				}

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

				// update options: app id and secret key
				$cn->options['general'] = wp_parse_args( [ 'app_id' => $app_id, 'app_key' => $secret_key ], $cn->options['general'] );

				if ( $network ) {
					$cn->options['general']['global_override'] = true;

					update_site_option( 'cookie_notice_options', $cn->options['general'] );

					// get options
					$app_config = get_site_transient( 'cookie_notice_app_quick_config' );
				} else {
					update_option( 'cookie_notice_options', $cn->options['general'] );

					// get options
					$app_config = get_transient( 'cookie_notice_app_quick_config' );
				}

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

				if ( $response->status === 200 ) {
					// notify publish app
					$params = [
						'AppID'	=> $app_id
					];

					$response = $this->request( 'notify_app', $params );

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

				// set token
				if ( $network )
					set_site_transient( 'cookie_notice_app_token', $response->data, DAY_IN_SECONDS );
				else
					set_transient( 'cookie_notice_app_token', $response->data, DAY_IN_SECONDS );

				// get apps and check if one for the current domain already exists
				$response = $this->request( 'list_apps', [] );

				// errors?
				if ( ! empty( $response->message ) ) {
					$response->error = $response->message;
					break;
				}

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

				// track whether this domain already existed before login
				$app_was_preexisting = (bool) $app_exists;

				// if no app, create one
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
						break;
					}

					$app_exists = $response->data;
				}

				// check if we have the valid app data
				if ( empty( $app_exists->AppID ) || empty( $app_exists->SecretKey ) ) {
					$response = [ 'error' => esc_html__( 'Unexpected error occurred. Please try again later.', 'cookie-notice' ) ];
					break;
				}

				// get subscriptions
				$subscriptions = [];

				$params = [
					'AppID' => $app_exists->AppID
				];

				$response = $this->request( 'get_subscriptions', $params );

				// errors?
				if ( ! empty( $response->error ) ) {
					$response->error = $response->error;
					break;
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
				if ( $app_was_preexisting ) {
					$existing_status = $network
						? get_site_option( 'cookie_notice_status', $cn->defaults['data'] )
						: get_option( 'cookie_notice_status', $cn->defaults['data'] );

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

				// update options: app ID and secret key
				$cn->options['general'] = wp_parse_args( [ 'app_id' => $app_exists->AppID, 'app_key' => $app_exists->SecretKey ], $cn->options['general'] );

				if ( $network ) {
					$cn->options['general']['global_override'] = true;

					update_site_option( 'cookie_notice_options', $cn->options['general'] );
				} else {
					update_option( 'cookie_notice_options', $cn->options['general'] );
				}

				// Pre-existing domains already have their configuration in the Designer API.
				// Only call quick_config for new domains to avoid overwriting existing
				// regulations and settings with defaults.
				$status_data = $cn->defaults['data'];
				$status_data['subscription'] = $subscription_tier;

				if ( ! $app_was_preexisting ) {
					// Apply pre-configure settings from transient (mirrors register flow).
					// Transient is set by the configure wizard when the user hasn't yet connected.
					$app_config = $network ? get_site_transient( 'cookie_notice_app_quick_config' ) : get_transient( 'cookie_notice_app_quick_config' );

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

					if ( $response->status !== 200 ) {
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
				}

				// Notify / activate the app (both new and pre-existing domains)
				$params = [
					'AppID' => $app_exists->AppID
				];

				$response = $this->request( 'notify_app', $params );

				// Idempotent: "App was already active" means the API app record is already Active
				// (StatusID != Inactive). This happens when WP options were cleared but the API-side
				// app persists from a prior login. Treat it as success — the app IS active.
				$notify_already_active = ! empty( $response->message )
					&& strpos( $response->message, 'already active' ) !== false;

				if ( $response->status === 200 || $notify_already_active ) {
					$response = true;
					$status_data['status'] = 'active';

					// get activation timestamp
					$timestamp = $cn->get_cc_activation_datetime();

					// update activation timestamp only for new cookie compliance activations
					$status_data['activation_datetime'] = $timestamp === 0 ? time() : $timestamp;

					// update app status
					if ( $network )
						update_site_option( 'cookie_notice_status', $status_data );
					else
						update_option( 'cookie_notice_status', $status_data );

					// Sync config from Designer API for all domains (new + pre-existing)
					// so the Protection tab shows current tracker data (#2130, #2186).
					$this->get_app_config( $app_exists->AppID, true, true );
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
				break;

			case 'configure':
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
							$saved_law_keys = array_keys( $new_options );
							if ( $network )
								update_site_option( 'cookie_notice_app_regulations', $saved_law_keys );
							else
								update_option( 'cookie_notice_app_regulations', $saved_law_keys );

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
								$existing_blocking = $network
									? get_site_option( 'cookie_notice_app_blocking', [] )
									: get_option( 'cookie_notice_app_blocking', [] );
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
							// Use $cn->options['general'] (already loaded + merged with defaults)
							// to avoid clobbering multi_array_merge'd sub-keys.
							if ( $has_opt_in || $has_ccpa_us || $has_pipeda ) {
								$wp_options = $cn->options['general'];

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

								if ( $network )
									update_site_option( 'cookie_notice_options', $wp_options );
								else
									update_option( 'cookie_notice_options', $wp_options );
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

		echo wp_json_encode( $response );
		exit;
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
			'timeout'	=> 60,
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
			// Used by react_update_design(), react_apply_template(), react_apply_languages(),
			// and the configure (laws/wizard) flow — replaces quick_config for existing apps.
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

			// get default status data
			$status_data = $cn->defaults['data'];

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
	 * the two come apart: under global_override cookie-notice.php loads
	 * $cn->options['general'] FROM the network row, app_id and app_key are plugin-owned
	 * fields so they survive every allowlist, and the writers that persist that array
	 * verbatim put it in the SITE row — includes/react-admin-ajax.php reads
	 * $cn->options['general'] and calls update_option() whenever is_network_admin() is false,
	 * which it is for an ordinary subsite administrator saving an ordinary setting. The
	 * site action then fires carrying the NETWORK app's credentials.
	 *
	 * No forgery is involved and it is not an attack: it is what the Autoblocking
	 * checkbox does on a subsite under global_override. But the record about to change is
	 * the one every site on the network pulls, so authority has to follow the app whose
	 * record changes, not only the row that was written.
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
				// meaningless, and because several writers persist the network array
				// verbatim into the site row it is also how a stale site copy can undo a
				// change made at network level. Refused outright rather than escalated — a
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
		$scope   = $network ? 'network' : 'site';

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
		$record = $network ? get_site_option( self::POSTURE_PUSH_PENDING ) : get_option( self::POSTURE_PUSH_PENDING );

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
			if ( $network ? get_site_transient( self::POSTURE_PUSH_RETRY ) : get_transient( self::POSTURE_PUSH_RETRY ) )
				continue;

			$row    = $network ? get_site_option( 'cookie_notice_options', [] ) : get_option( 'cookie_notice_options', [] );
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

		// ── Begin network config-write gate ──────────────────────────────────────
		// Everything below writes network-wide when $network is true —
		// cookie_notice_app_blocking (the pre-consent blocking catalogue),
		// cookie_notice_app_regulations, cookie_notice_options, cookie_notice_app_design and
		// cookie_notice_status — and the cookie_notice_options write fires
		// update_site_option_cookie_notice_options, i.e. it STAGES A NETWORK POSTURE PUSH.
		//
		// Several user-driven entry points reach here holding only manage_options, a
		// site-level capability every subsite administrator has: ajax_purge_cache()
		// (includes/settings.php:2965), rescan_scripts() (includes/react-admin-ajax.php:770),
		// save_options() (:1404), validate_options() (includes/settings.php:2147),
		// react_update_design(), react_apply_template(), and api_request()'s configure and
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

		// debug: log raw Designer API response
		if ( $cn->options['general']['debug_mode'] ) {
			error_log( '[Cookie Notice] get_app_config - AppID: ' . $app_id );
			error_log( '[Cookie Notice] get_app_config - Designer API response: ' . wp_json_encode( $response ) );
		}

		// get status data
		$status_data = $cn->defaults['data'];

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

			// Backend-controlled banner build selector (rides the same get_config
			// response as SubscriptionType). 'v2' selects the v2 build; anything
			// else (incl. absent/null) resolves to v1 in get_banner_channel().
			$status_data['widget_version'] = ! empty( $result_raw['WidgetVersion'] ) ? sanitize_key( $result_raw['WidgetVersion'] ) : '';

			if ( $status_data['subscription'] === 'basic' ) {
				// Usage rides the SAME response as SubscriptionType above, so read it
				// from there. Reading it instead from the separately-refreshed
				// cookie_notice_app_analytics option is what let the two disagree: the
				// plan came back fresh from this call while the visit count came from a
				// cache the hourly cron had not caught up on, so a domain moved Free ->
				// Pro kept enforcing the old app's threshold (HS#47302). One response
				// cannot contradict itself.
				$analytics = isset( $result_raw['AnalyticsData'] ) ? $result_raw['AnalyticsData'] : null;
				$cycle_usage = is_object( $analytics ) && isset( $analytics->cycleUsage ) ? $analytics->cycleUsage : null;

				if ( $cycle_usage !== null )
					$status_data['threshold_exceeded'] = $this->evaluate_threshold_exceeded( $cycle_usage );
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
				$wp_options   = $network ? get_site_option( 'cookie_notice_options', [] ) : get_option( 'cookie_notice_options', [] );

				// Only write on a real change — a config pull runs on cron and on every admin
				// visit, and an unconditional update_option() would fire the #2272 guard and
				// every other pre_update filter on every pull for no reason.
				if ( is_array( $wp_options ) && ( ! array_key_exists( 'app_blocking', $wp_options ) || (bool) $wp_options['app_blocking'] !== $api_blocking ) ) {
					$wp_options['app_blocking'] = $api_blocking;

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
						// overlay outranks both the admin and the portal for this request.
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
					$this->syncing_base_posture = true;

					try {
						if ( $network )
							update_site_option( 'cookie_notice_options', $wp_options );
						else
							update_option( 'cookie_notice_options', $wp_options );
					} finally {
						$this->syncing_base_posture = false;
					}
				}
			}
			// ── End base posture sync (BannerConfigJSON.blocking → app_blocking)

			// Cache visual design fields from Designer API response.
			// position, bannerColor, primaryColor live in UserDesignJSON (visual design),
			// NOT BannerConfigJSON (behavioral config). Reading from BannerConfigJSON
			// returned empty strings and caused "No template" on cron refresh (#2261).
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
				error_log( '[Cookie Notice] get_app_config - Stored blocking data: ' . wp_json_encode( $result ) );
			}
		} else {
			if ( $cn->options['general']['debug_mode'] ) {
				error_log( '[Cookie Notice] get_app_config - No data in response. Error: ' . ( ! empty( $response->error ) ? $response->error : 'unknown' ) );
			}

			if ( ! empty( $response->error ) ) {
				if ( $response->error == 'App is not published yet' )
					$status_data['status'] = 'pending';
				else
					$status_data['status'] = '';
			}
		}

		if ( $network )
			update_site_option( 'cookie_notice_status', $status_data );
		else
			update_option( 'cookie_notice_status', $status_data, false );

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
	 * AJAX: Apply a design template preset via quick_config.
	 *
	 * POST fields: template (minimal|standard|bold|popup|panel|compact)
	 * Syncs full design schema to portal + saves position/displayType to WP options.
	 *
	 * @return void
	 */
	public function react_apply_template() {
		$this->verify_react_request();

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

		$template = isset( $_POST['template'] ) ? sanitize_key( $_POST['template'] ) : '';

		// Full design presets — position/color/typography synced to portal.
		// Colors match TemplatePresets.jsx PRESETS array.
		$presets = [
			'minimal' => [
				'position'         => 'left',
				'displayType'      => 'floating',
				'bannerColor'      => '#f0f0f0',
				'primaryColor'     => '#20c19e',
				'textColor'        => '#434f58',
				'headingColor'     => '#434f58',
				'btnTextColor'     => '#ffffff',
				'btnBorderRadius'  => '25px',
				'animation'        => 'fade',
				'bannerOpacity'    => 0.97,
				'revokePosition'   => 'bottom-left',
				'showBulletPoints' => true,
			],
			'standard' => [
				'position'         => 'bottom',
				'displayType'      => 'floating',
				'bannerColor'      => '#2d3436',
				'primaryColor'     => '#20c19e',
				'textColor'        => '#ffffff',
				'headingColor'     => '#ffffff',
				'btnTextColor'     => '#ffffff',
				'btnBorderRadius'  => '25px',
				'animation'        => 'fade',
				'bannerOpacity'    => 0.97,
				'revokePosition'   => 'bottom-left',
				'showBulletPoints' => true,
			],
			'bold' => [
				'position'         => 'top',
				'displayType'      => 'fixed',
				'bannerColor'      => '#1a1a2e',
				'primaryColor'     => '#20c19e',
				'textColor'        => '#ffffff',
				'headingColor'     => '#ffffff',
				'btnTextColor'     => '#ffffff',
				'btnBorderRadius'  => '6px',
				'animation'        => 'slide',
				'bannerOpacity'    => 1.0,
				'revokePosition'   => 'bottom-right',
				'showBulletPoints' => false,
			],
			'popup' => [
				'position'         => 'center',
				'displayType'      => 'floating',
				'bannerColor'      => '#2c3e50',
				'primaryColor'     => '#20c19e',
				'textColor'        => '#ffffff',
				'headingColor'     => '#ffffff',
				'btnTextColor'     => '#ffffff',
				'btnBorderRadius'  => '25px',
				'animation'        => 'fade',
				'bannerOpacity'    => 0.97,
				'revokePosition'   => 'bottom-left',
				'showBulletPoints' => true,
			],
			'panel' => [
				'position'         => 'right',
				'displayType'      => 'floating',
				'bannerColor'      => '#34495e',
				'primaryColor'     => '#3498db',
				'textColor'        => '#ffffff',
				'headingColor'     => '#ffffff',
				'btnTextColor'     => '#ffffff',
				'btnBorderRadius'  => '25px',
				'animation'        => 'fade',
				'bannerOpacity'    => 0.97,
				'revokePosition'   => 'bottom-left',
				'showBulletPoints' => true,
			],
			'compact' => [
				'position'         => 'top',
				'displayType'      => 'floating',
				'bannerColor'      => '#1a1a2e',
				'primaryColor'     => '#e67e22',
				'textColor'        => '#ffffff',
				'headingColor'     => '#ffffff',
				'btnTextColor'     => '#ffffff',
				'btnBorderRadius'  => '6px',
				'animation'        => 'slide',
				'bannerOpacity'    => 1.0,
				'revokePosition'   => 'bottom-right',
				'showBulletPoints' => false,
			],
		];

		if ( ! isset( $presets[ $template ] ) ) {
			wp_send_json_error( [ 'error' => 'Invalid template name.' ] );
		}

		$preset = $presets[ $template ];

		// Build design object for quick_config (exclude displayType — WP option, not portal field)
		$design = new stdClass();

		foreach ( $preset as $key => $value ) {
			if ( $key === 'displayType' )
				continue;

			$design->{$key} = $value;
		}

		$params = [
			'AppID'           => $app_id,
			'DefaultLanguage' => 'en',
			'text'            => (object) [ 'privacyPolicyUrl' => get_privacy_policy_url() ],
			'design'          => $design,
		];

		$write_type = $this->get_write_request_type( $app_id );

		// PATCH /by-app endpoint does not accept DefaultLanguage -- strip it.
		if ( $write_type === 'patch_by_app' ) {
			unset( $params['DefaultLanguage'] );
		}
		// DevMode mock ID — return synthetic success so the UI can be tested without a real API.
		if ( $write_type === 'devmode' ) {
			$network = $cn->is_network_admin();

			// Merge visual design fields (position, displayType, colors) from preset.
			// #2265: API-owned fields write to cookie_notice_app_design only — never cookie_notice_options.
			$existing_design = $network
				? get_site_option( 'cookie_notice_app_design', [] )
				: get_option( 'cookie_notice_app_design', [] );

			$updated_design = array_merge( $existing_design, [
				'position'     => $preset['position'],
				'displayType'  => $preset['displayType'],
				'bannerColor'  => $preset['bannerColor'],
				'primaryColor' => $preset['primaryColor'],
			] );

			if ( $network ) {
				update_site_option( 'cookie_notice_app_design', $updated_design );
			} else {
				update_option( 'cookie_notice_app_design', $updated_design, false );
			}

			wp_send_json_success( [ 'status' => 200, 'template' => $template, 'dev_mode' => true ] );
			return;
		}

		$result = $this->request( $write_type, $params );

		// Design record not yet created — fall back to quick_config to seed it.
		// The API returns { i18n_msg: 'user_design_update_id_not_found', status: 400 } (HTTP 200)
		// when no record exists, so check i18n_msg — not statusCode/404.
		// Also restore DefaultLanguage which patch_by_app doesn't accept but quick_config requires.
		if ( is_object( $result ) && isset( $result->i18n_msg ) && $result->i18n_msg === 'user_design_update_id_not_found' ) {
			$params['DefaultLanguage'] = 'en';
			$result = $this->request( 'quick_config', $params );
		}

		if ( is_object( $result ) && isset( $result->status ) && $result->status === 200 ) {
			// #2265: API-owned fields write to cookie_notice_app_design only — never cookie_notice_options.
			$network = $cn->is_network_admin();

			// Merge visual design fields (position, displayType, colors) from preset.
			$existing_design = $network
				? get_site_option( 'cookie_notice_app_design', [] )
				: get_option( 'cookie_notice_app_design', [] );

			$updated_design = array_merge( $existing_design, [
				'position'     => $preset['position'],
				'displayType'  => $preset['displayType'],
				'bannerColor'  => $preset['bannerColor'],
				'primaryColor' => $preset['primaryColor'],
			] );

			if ( $network ) {
				update_site_option( 'cookie_notice_app_design', $updated_design );
			} else {
				update_option( 'cookie_notice_app_design', $updated_design, false );
			}

			// Pull confirmed state from portal — makes portal unambiguous SoT.
			// Updates cookie_notice_app_blocking, cookie_notice_app_regulations,
			// cookie_notice_app_design, cookie_notice_status.
			// Fires cn_configuration_updated → clears page caches (WP Rocket etc).
			// Does NOT set cookie_notice_config_update transient (widget CDN cache).
			$this->get_app_config( $app_id, true, true );

			// Re-assert preset design values after get_app_config() — the portal may return
			// empty position/color fields (BannerConfigJSON cherry-picks) which would
			// overwrite our just-saved preset and cause matchTemplate() to return null
			// on the next page load ("No template" false negative — #2261).
			// This write is authoritative: we know what template was just applied.
			if ( $network )
				update_site_option( 'cookie_notice_app_design', $updated_design );
			else
				update_option( 'cookie_notice_app_design', $updated_design, false );

			wp_send_json_success( [ 'status' => 200, 'template' => $template ] );
		} else {
			$error = 'Template apply failed.';

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
	 * POST fields: design[position], design[displayType], design[bannerColor], etc.
	 * Requires connected app (app_id in WP options).
	 *
	 * @return void
	 */
	public function react_update_design() {
		$this->verify_react_request();

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

		if ( empty( $design_raw ) && empty( $config_raw ) && empty( $consent_raw ) ) {
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
				$existing_blocking = $cn->is_network_options()
					? get_site_option( 'cookie_notice_app_blocking', [] )
					: get_option( 'cookie_notice_app_blocking', [] );
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
			'text'            => (object) [ 'privacyPolicyUrl' => get_privacy_policy_url() ],
		];
		if ( ! empty( (array) $design ) )
			$params['design'] = $design;
		if ( ! empty( (array) $config ) )
			$params['config'] = $config;

		$write_type = $this->get_write_request_type( $app_id );

		// PATCH /by-app endpoint does not accept DefaultLanguage -- strip it.
		if ( $write_type === 'patch_by_app' ) {
			unset( $params['DefaultLanguage'] );
		}
		// DevMode mock ID — return synthetic success so the UI can be tested without a real API.
		if ( $write_type === 'devmode' ) {
			wp_send_json_success( [ 'status' => 200, 'dev_mode' => true ] );
			return;
		}

		$result = $this->request( $write_type, $params );

		// debug: log raw API response for consent mode debugging.
		if ( $cn->options['general']['debug_mode'] ) {
			error_log( 'react_update_design API result: ' . var_export( $result, true ) );
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

			wp_send_json_success( [ 'status' => 200 ] );
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
		if ( is_object( $result ) && isset( $result->i18n_msg ) && $result->i18n_msg === 'user_design_update_id_not_found' ) {
			$params['DefaultLanguage'] = 'en';
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
