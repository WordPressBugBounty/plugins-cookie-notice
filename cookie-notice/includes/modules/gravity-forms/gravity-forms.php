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

	/** Whether the add-on bundle was actually found and held on this request. */
	private $held = false;

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// Already essential on this site? Then the widget never holds reCAPTCHA, the
		// add-on captures a real grecaptcha, and there is nothing to fix. Bail so we
		// cannot regress a site that has already worked around this by hand.
		if ( self::recaptcha_category( self::get_blocking_data() ) === 1 )
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
	 * @param string $tag    Full <script> tag HTML.
	 * @param string $handle WordPress script handle.
	 * @return string
	 */
	public static function hold_tag( $tag, $handle ) {
		if ( $handle !== self::HANDLE_ADDON )
			return $tag;

		// Never hold what we cannot release. If the controller is missing from disk — a
		// partial update, a stripped deploy — holding would leave every visitor,
		// including consented ones, with a form that submits without a token. Falling
		// through leaves the current behaviour instead of inventing a worse one.
		if ( ! file_exists( self::controller_path() ) )
			return $tag;

		// already held
		if ( strpos( $tag, 'data-cn-gf-recaptcha-src' ) !== false )
			return $tag;

		if ( ! preg_match( '/\ssrc=(["\'])(.*?)\1/', $tag, $m ) )
			return $tag;

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
		$filtered = self::hold_tag( $tag, $handle );

		if ( $handle === self::HANDLE_ADDON )
			$this->held = $filtered !== $tag;

		return $filtered;
	}

	/**
	 * Report whether the add-on bundle was found and held on this page.
	 *
	 * @return void
	 */
	public function debug_held_state() {
		echo '<script>console.warn("CC Banner: Gravity Forms reCAPTCHA — '
			. ( $this->held
				? 'held ' . esc_js( self::HANDLE_ADDON ) . ', release waits for grecaptcha'
				: esc_js( self::HANDLE_ADDON ) . ' not found on this page, nothing held' )
			. '");</script>' . "\n";
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

		wp_localize_script(
			self::HANDLE_CONTROLLER,
			'cn_gf_recaptcha',
			[
				'blockedMessage'     => __( 'Please accept cookies to submit this form. This form is protected by Google reCAPTCHA, which needs your consent before it can run.', 'cookie-notice' ),
				'unavailableMessage' => __( 'This form could not be submitted because Google reCAPTCHA did not load. Please reload the page and try again.', 'cookie-notice' ),
			]
		);
	}
}

new Cookie_Notice_Modules_GravityForms();
