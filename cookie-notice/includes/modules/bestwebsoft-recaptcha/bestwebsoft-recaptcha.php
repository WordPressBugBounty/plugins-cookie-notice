<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie Notice Modules BestWebSoft reCaptcha class.
 *
 * Compatibility since: 3.1.8
 *
 * "reCaptcha by BestWebSoft" (google-captcha, and the paid Pro build sharing its
 * codebase) registers Google's api.js under the handle `gglcptch_api`, so autoblocking
 * holds it until consent covers Google Recaptcha. The plugin then never renders its
 * captcha, and the form cannot be submitted — the visitor is stopped by a checkbox that
 * is not on the page.
 *
 * The plugin does try to recover on its own. js/script.js starts a one-second interval
 * on DOM ready that calls gglcptch.prepare() as soon as `grecaptcha` appears — but it
 * gives up after ten tries. Measured against the real plugin on 2026-08-19: consent at
 * 3s renders the captcha with no help from us, consent at 15s leaves `grecaptcha`
 * loaded and the captcha permanently unrendered. Ten seconds is less time than reading
 * the banner takes, so the visitors who lose the form are the ones who read it.
 *
 * WHY THIS IS SMALL, WHERE THE GRAVITY FORMS MODULE IS NOT
 * That module has to hold and re-execute the add-on bundle because the bundle captures
 * `window.grecaptcha` by value the moment it runs, so a late grecaptcha is worthless to
 * it. BestWebSoft's script reads the global lazily inside gglcptch.prepare(), so a late
 * grecaptcha is perfectly usable. We hold nothing and rewrite no tags — we just call
 * their own public entry point again, once Google is genuinely there.
 *
 * There is also no server-side category check here, again unlike Gravity Forms. That
 * module needs one because holding a script when we should not have would break a form
 * that was working. This one only ever acts when it can see a script the widget has
 * actually blocked, so it is inert by construction on a site where reCAPTCHA is
 * essential, and the check would be duplicated ceremony.
 *
 * @class Cookie_Notice_Modules_BestWebSoftRecaptcha
 */
class Cookie_Notice_Modules_BestWebSoftRecaptcha {

	/** Our controller. */
	const HANDLE = 'cn-bestwebsoft-recaptcha';

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		add_filter( 'script_loader_tag', [ $this, 'protect_controller_script' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_controller' ], 22 );
	}

	/**
	 * Absolute path of the controller.
	 *
	 * @return string
	 */
	private static function controller_path() {
		return COOKIE_NOTICE_PATH . 'includes/modules/bestwebsoft-recaptcha/recaptcha.js';
	}

	/**
	 * Keep JS optimizers away from the controller.
	 *
	 * Combined, deferred or delayed, it misses the unblock event and the captcha stays
	 * unrendered for consented visitors too — the failure this module exists to remove.
	 *
	 * @param string $tag    Full <script> tag HTML.
	 * @param string $handle WordPress script handle.
	 * @return string
	 */
	public function protect_controller_script( $tag, $handle ) {
		if ( $handle !== self::HANDLE )
			return $tag;

		return preg_replace( '/<script/', '<script' . Cookie_Notice::optimizer_skip_attrs(), $tag, 1 );
	}

	/**
	 * Enqueue the controller.
	 *
	 * Deliberately not gated on the captcha appearing on this particular page: the
	 * plugin renders its markup while the form renders, which for a comment form is
	 * after wp_enqueue_scripts has run. The controller does nothing at all when it finds
	 * no blocked reCAPTCHA, so loading it costs one small request and no behaviour.
	 *
	 * @return void
	 */
	public function enqueue_controller() {
		if ( ! file_exists( self::controller_path() ) )
			return;

		wp_enqueue_script(
			self::HANDLE,
			COOKIE_NOTICE_URL . '/includes/modules/bestwebsoft-recaptcha/recaptcha.js',
			[],
			Cookie_Notice()->defaults['version'],
			true
		);

		wp_localize_script(
			self::HANDLE,
			'cn_bws_recaptcha',
			[
				// Shown on the form while we are still holding Google.
				'heldMessage' => __( 'Please accept cookies to use this form. It is protected by Google reCAPTCHA, which cannot load until you do.', 'cookie-notice' ),
				// Replaces the plugin's own "check your internet connection" wording,
				// which blames the visitor's network for a block that is ours.
				'timeoutMessage' => __( 'Google reCAPTCHA has not loaded because it needs your consent. Please accept cookies to use this form.', 'cookie-notice' ),
				// Replaces the tooltip on the submit button it disables while waiting.
				'waitMessage' => __( 'Accept cookies to load Google reCAPTCHA.', 'cookie-notice' ),
				'debug' => (bool) Cookie_Notice()->options['general']['debug_mode']
			]
		);
	}
}

new Cookie_Notice_Modules_BestWebSoftRecaptcha();
