<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie Notice Modules Gravity Forms class.
 *
 * Compatibility since: 3.1.5 (recaptcha v3; v2 checkbox best-effort — see below)
 *
 * Gravity Forms' reCAPTCHA add-on bundle ends with
 *
 *     }( window.gform || {}, window.grecaptcha || {}, gforms_recaptcha_recaptcha_strings )
 *
 * so it captures window.grecaptcha BY VALUE when it executes. While autoblocking is
 * holding google.com/recaptcha there is no grecaptcha, the add-on captures {} for the
 * life of the page, and its submit handler calls execute() on that empty object. The
 * resulting TypeError happens inside an async function, so GF's submission pipeline is
 * handed a rejected promise and simply never settles — the submit button spins forever
 * and no request is ever made.
 *
 * That is why re-initialising the add-on after consent cannot work: the empty object is
 * already captured. The bundle itself has to run after grecaptcha exists. So we hold it
 * inert and let recaptcha.js release it once grecaptcha is genuinely present, which
 * gives the visitor a working form on the same pageview they consent on — without
 * loading anything from Google before that consent.
 *
 * We deliberately couple only to the add-on's public script handle and, for the v2
 * checkbox flow, its global render function. The add-on is a paid, minified, versioned
 * bundle we cannot fork the way we fork Contact Form 7's small open script, so anything
 * reaching into its internals would break on their next release.
 *
 * Both were read off a live page carrying the add-on (2026-08-12): the tag id
 * `gforms_recaptcha_frontend-js` gives the handle, and the bundle assigns
 * `window.gravityformsrecaptchaRenderCheckboxes`.
 *
 * The v3 path is verified end to end. The v2 checkbox path is best-effort: it depends on
 * that render global existing by the time the released bundle fires onload, which has not
 * been confirmed against a real v2 form. If it is not there we skip it, so a v2 site is no
 * worse off than it is today — but it is not a proven fix either.
 *
 * @class Cookie_Notice_Modules_GravityForms
 */
class Cookie_Notice_Modules_GravityForms {

	/**
	 * Script handle of the add-on's own frontend bundle — the one we hold.
	 *
	 * NOT gforms_recaptcha_recaptcha, which is Google's api.js; the widget already
	 * holds that one via the autoblocking pattern table.
	 */
	const HANDLE_ADDON = 'gforms_recaptcha_frontend';

	/** Our release controller. */
	const HANDLE_CONTROLLER = 'cn-gravity-forms-recaptcha';

	/** Google Recaptcha in the autoblocking provider table. */
	const PROVIDER_ID = 3;

	/** Category Google Recaptcha ships in — non-essential, so held until consent. */
	const DEFAULT_CATEGORY = 2;

	/**
	 * What happened to the add-on bundle on this request.
	 *
	 * One of: 'absent' (the handle never rendered), 'held' (rewritten inert, release
	 * pending), 'no-controller' (found, but recaptcha.js is missing from disk so we
	 * deliberately did not hold it), 'already-held' (a second filter pass), 'no-src'
	 * (the tag carried no src to move).
	 *
	 * Deliberately NOT a boolean. It used to be `$filtered !== $tag`, which collapsed
	 * every not-held reason into one message — "not found on this page" — including
	 * 'no-controller', i.e. a partial or stripped deploy. That is the failure most worth
	 * shouting about, and it was reported as "you have not got the add-on installed".
	 */
	private $state = 'absent';

	/** Consent category Google Recaptcha is in on this site. See __construct(). */
	private $category = self::DEFAULT_CATEGORY;

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// Switched off for this site? Bail before anything is hooked. See is_enabled().
		if ( ! self::is_enabled() )
			return;

		// Already essential on this site? Then the widget never holds reCAPTCHA, the
		// add-on captures a real grecaptcha, and there is nothing to fix. Bail so we
		// cannot regress a site that has already worked around this by hand.
		//
		// Kept rather than recomputed: recaptcha.js needs the same number, because the
		// question "may this visitor run reCAPTCHA yet" is `categories[$category] === true`
		// against THIS site's category, not a hardcoded 2.
		$this->category = self::recaptcha_category( self::get_blocking_data() );

		if ( $this->category === 1 )
			return;

		add_filter( 'script_loader_tag', [ $this, 'hold_addon_script' ], 10, 2 );
		add_filter( 'script_loader_tag', [ $this, 'protect_controller_script' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_controller' ], 22 );

		// Debug mode answers the one question that is otherwise invisible: did the add-on
		// handle we key off actually appear on this page? Without it, a version of the
		// add-on that renames the handle looks identical to a site with no reCAPTCHA —
		// silently unfixed. Mirrors Cookie_Notice_Frontend::debug_excluded_handles().
		if ( Cookie_Notice()->options['general']['debug_mode'] )
			add_action( 'wp_footer', [ $this, 'debug_held_state' ], 999 );
	}

	/**
	 * Whether this compatibility module runs on this site.
	 *
	 * A developer-level off-switch, deliberately a filter and not a setting. Agencies run
	 * this plugin across whole portfolios, so the useful shape is one mu-plugin applied
	 * fleet-wide rather than a checkbox to find on every site (#47928). It mirrors
	 * cookie_notice_wp_consent_api_enabled.
	 *
	 *     add_filter( 'cookie_notice_gravity_forms_recaptcha_enabled', '__return_false' );
	 *
	 * IMPORTANT — what this does NOT do. It disables only the hold-and-release controller.
	 * Google reCAPTCHA is still held until the visitor's consent covers its category,
	 * because that is the widget's job and is decided by the Autoblocking configuration,
	 * not here. So switching this off cannot let reCAPTCHA run before consent; it returns
	 * the site to the earlier behaviour, where the add-on captures an empty grecaptcha and
	 * the submit button waits without explaining itself. Never widen this filter to reach
	 * the blocking flag or a provider's category — that would make it a pre-consent leak.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'cookie_notice_gravity_forms_recaptcha_enabled', true );
	}

	/**
	 * Absolute path of the release controller.
	 *
	 * @return string
	 */
	private static function controller_path() {
		return COOKIE_NOTICE_PATH . 'includes/modules/gravity-forms/recaptcha.js';
	}

	/**
	 * Read the cached portal autoblocking config.
	 *
	 * @return array
	 */
	private static function get_blocking_data() {
		$blocking = Cookie_Notice()->is_network_options()
			? get_site_option( 'cookie_notice_app_blocking' )
			: get_option( 'cookie_notice_app_blocking' );

		return is_array( $blocking ) ? $blocking : [];
	}

	/**
	 * Effective consent category of Google Recaptcha for this site.
	 *
	 * The portal only sends providers the customer has customised, so an absent entry
	 * means "still on the shipped default" — category 2, held until consent. Values
	 * arrive as strings from the API, hence the integer casts.
	 *
	 * @param array $blocking Cached portal config (cookie_notice_app_blocking).
	 * @return int
	 */
	public static function recaptcha_category( $blocking ) {
		$providers = isset( $blocking['providers'] ) && is_array( $blocking['providers'] )
			? $blocking['providers']
			: [];

		foreach ( $providers as $provider ) {
			// Providers are stored as stdClass — welcome-api.php writes them with
			// `$provider->CategoryID = (int) ...`. Arrays are accepted too because
			// react-admin-ajax.php reads both shapes, so a hand-edited or older option
			// can be either. Reading only arrays made this whole guard dead code.
			if ( is_object( $provider ) ) {
				$id       = isset( $provider->ProviderID ) ? $provider->ProviderID : null;
				$category = isset( $provider->CategoryID ) ? $provider->CategoryID : null;
			} elseif ( is_array( $provider ) ) {
				$id       = isset( $provider['ProviderID'] ) ? $provider['ProviderID'] : null;
				$category = isset( $provider['CategoryID'] ) ? $provider['CategoryID'] : null;
			} else
				continue;

			if ( $id === null || $category === null )
				continue;

			// Cast rather than compare loosely: the API stringifies its integers, and a
			// custom provider's ProviderID is a non-numeric string we must not match.
			if ( (string) $id === (string) self::PROVIDER_ID )
				return (int) $category;
		}

		return self::DEFAULT_CATEGORY;
	}

	/**
	 * Hold the add-on bundle: make the tag inert and stash its URL for recaptcha.js.
	 *
	 * $reason reports WHICH branch was taken, so debug_held_state() can name it instead of
	 * guessing from whether the string changed. Every bail below returns the tag untouched
	 * and is therefore indistinguishable from outside — including the one that matters
	 * most, a controller missing from disk. An out-param rather than a second method so
	 * the reason cannot drift from the branch that produced it.
	 *
	 * @param string      $tag    Full <script> tag HTML.
	 * @param string      $handle WordPress script handle.
	 * @param string|null $reason Out: 'absent'|'no-controller'|'already-held'|'no-src'|'held'.
	 * @return string
	 */
	public static function hold_tag( $tag, $handle, &$reason = null ) {
		$reason = 'absent';

		if ( $handle !== self::HANDLE_ADDON )
			return $tag;

		// Never hold what we cannot release. If the controller is missing from disk — a
		// partial update, a stripped deploy — holding would leave every visitor,
		// including consented ones, with a form that submits without a token. Falling
		// through leaves the current behaviour instead of inventing a worse one.
		if ( ! file_exists( self::controller_path() ) ) {
			$reason = 'no-controller';

			return $tag;
		}

		// already held
		if ( strpos( $tag, 'data-cn-gf-recaptcha-src' ) !== false ) {
			$reason = 'already-held';

			return $tag;
		}

		if ( ! preg_match( '/\ssrc=(["\'])(.*?)\1/', $tag, $m ) ) {
			$reason = 'no-src';

			return $tag;
		}

		// Drop the executable src and strip any type, then mark it inert. text/plain
		// means no browser executes it and — unlike the widget's own
		// javascript/blocked marker — the widget will not adopt it either, so its
		// release stays ours to time against grecaptcha actually being there.
		$tag = str_replace( $m[0], '', $tag );
		$tag = preg_replace( '/\stype=(["\']).*?\1/', '', $tag );
		$tag = preg_replace(
			'/<script/',
			'<script type="text/plain" data-cn-gf-recaptcha-src="' . esc_url( $m[2] ) . '"',
			$tag,
			1
		);

		$reason = 'held';

		return $tag;
	}

	/**
	 * Filter callback wrapper — keeps hold_tag() pure and unit-testable.
	 *
	 * @param string $tag    Full <script> tag HTML.
	 * @param string $handle WordPress script handle.
	 * @return string
	 */
	public function hold_addon_script( $tag, $handle ) {
		$reason   = null;
		$filtered = self::hold_tag( $tag, $handle, $reason );

		// Taken from hold_tag() rather than re-derived here. Re-deriving worked, but the
		// two condition lists could drift apart silently and the console would then name
		// the wrong reason — which is the exact defect this reporting exists to fix.
		if ( $handle === self::HANDLE_ADDON )
			$this->state = $reason;

		return $filtered;
	}

	/**
	 * Report whether the add-on bundle was found and held on this page.
	 *
	 * @return void
	 */
	public function debug_held_state() {
		$handle = esc_js( self::HANDLE_ADDON );

		switch ( $this->state ) {
			case 'held':
				$message = 'held ' . $handle . ', release waits for grecaptcha';
				break;

			// The one worth shouting about: the handle IS on the page, so the add-on is
			// installed and this site needs the fix — we simply cannot deliver it, because
			// the file that releases the hold is not on disk. A partial update, a stripped
			// deploy, an incomplete upload. Forms keep working as they did before 3.1.5.
			case 'no-controller':
				$message = $handle . ' found but recaptcha.js is MISSING from disk — not held, '
					. 'and this site is not getting the fix. Check the plugin files are complete';
				break;

			case 'already-held':
				$message = $handle . ' was already held by an earlier pass, left alone';
				break;

			case 'no-src':
				$message = $handle . ' found but its tag carried no src to hold — an optimizer '
					. 'may have inlined or rewritten it';
				break;

			default:
				$message = $handle . ' not found on this page, nothing held';
		}

		echo '<script>console.warn("CC Banner: Gravity Forms reCAPTCHA — ' . $message . '");</script>' . "\n";
	}

	/**
	 * Keep JS optimizers away from the controller.
	 *
	 * If an optimizer combines, defers or delays this file, the bundle we held is never
	 * released and the form breaks for consented visitors too — the same class of
	 * failure the optimizer modules exist to prevent for the banner itself.
	 *
	 * @param string $tag    Full <script> tag HTML.
	 * @param string $handle WordPress script handle.
	 * @return string
	 */
	public function protect_controller_script( $tag, $handle ) {
		if ( $handle !== self::HANDLE_CONTROLLER )
			return $tag;

		return preg_replace( '/<script/', '<script' . Cookie_Notice::optimizer_skip_attrs(), $tag, 1 );
	}

	/**
	 * Enqueue the controller that releases the held bundle after consent.
	 *
	 * @return void
	 */
	public function enqueue_controller() {
		// Deliberately NOT gated on wp_script_is( HANDLE_ADDON ): Gravity Forms enqueues
		// a form's scripts while the form renders, which is after wp_enqueue_scripts has
		// run. Gating here would frequently miss, the controller would never load, and
		// the bundle we held would never be released — a form that is worse off than
		// before. The controller is inert when it finds nothing held, so loading it on
		// a page without a form costs one small request and nothing else.
		if ( ! file_exists( self::controller_path() ) )
			return;

		wp_enqueue_script(
			self::HANDLE_CONTROLLER,
			COOKIE_NOTICE_URL . '/includes/modules/gravity-forms/recaptcha.js',
			[],
			Cookie_Notice()->defaults['version'],
			true
		);

		// Three messages, because there are three states — see showNotice() in recaptcha.js.
		// loadingMessage covers the one that had no wording of its own: a visitor whose
		// consent DOES cover reCAPTCHA, on the second or two before Google's api.js defines
		// grecaptcha. They used to be told to accept cookies they had already accepted.
		//
		// `category` is what lets the controller ask the right question. Without it the JS
		// can only see THAT a consent record exists, which is true for someone who declined
		// — and that visitor is still held, so they must keep getting the accept-cookies
		// wording rather than being told Google failed.
		wp_localize_script(
			self::HANDLE_CONTROLLER,
			'cn_gf_recaptcha',
			[
				'category'           => $this->category,
				'blockedMessage'     => __( 'Please accept cookies to submit this form. This form is protected by Google reCAPTCHA, which needs your consent before it can run.', 'cookie-notice' ),
				'unavailableMessage' => __( 'This form could not be submitted because Google reCAPTCHA did not load. Please reload the page and try again.', 'cookie-notice' ),
				'loadingMessage'     => __( 'This form is still loading. Please try again in a moment.', 'cookie-notice' ),
			]
		);
	}
}

new Cookie_Notice_Modules_GravityForms();
