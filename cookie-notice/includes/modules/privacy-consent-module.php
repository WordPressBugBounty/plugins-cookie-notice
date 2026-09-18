<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Base class for a privacy-consent form-integration module.
 *
 * WHY THIS EXISTS
 * ---------------
 * The seven integrations (WordPress, Contact Form 7, Mailchimp, WooCommerce,
 * WPForms, Formidable Forms, Easy Digital Downloads) were written as seven
 * standalone classes that inherit nothing. Roughly half of each file was the
 * same code with a different slug substituted: the constructor's registration
 * sequence, register_source(), and validate() were textually identical across
 * all seven, and form_exists()/get_forms() identical across the three backed by
 * a custom post type.
 *
 * The cost of that is not the duplicated lines, it is the multiplier on every
 * change: a fix to validate() had to be made seven times and was only correct
 * if all seven were found. The same multiplier applied to the module contract
 * itself — adding a new integration meant copying ~230 lines of scaffolding to
 * get to the ~30 that actually describe the integration.
 *
 * WHAT A SUBCLASS STILL OWNS
 * --------------------------
 * Exactly the two things that genuinely differ per integration:
 *
 *   define_source()   the $source descriptor — name, id, availability, and for
 *                     a static integration its fixed form list.
 *   register_hooks()  the plugin's own hooks, which have nothing in common
 *                     between a shortcode filter, a checkout action and a
 *                     comment form.
 *
 * Both are abstract, so a new module cannot silently skip either.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO
 * ----------------------------------------
 * It does not touch get_form(): every integration returns a different field
 * map, and a base implementation would be a hook with one caller per subclass
 * — indirection without shared behaviour.
 *
 * @class Cookie_Notice_Privacy_Consent_Module
 */
abstract class Cookie_Notice_Privacy_Consent_Module {

	/**
	 * The source descriptor this module registers.
	 *
	 * Protected rather than private: subclasses read $this->source['id'] when
	 * building form payloads and when asking is_form_active().
	 *
	 * @var array
	 */
	protected $source = [];

	/**
	 * Register the module with the privacy-consent coordinator, then let the
	 * subclass attach its own hooks.
	 *
	 * The compliance-status gate sits BETWEEN the two on purpose, and that
	 * ordering is load-bearing: admin_init => register_source() is attached
	 * unconditionally, so the settings section still registers on a site whose
	 * compliance status is not active, while the front-end hooks that would act
	 * on visitors do not. Every module had this ordering written out
	 * separately; it is stated once here.
	 *
	 * @return void
	 */
	public function __construct() {
		// get main instance
		$cn = Cookie_Notice();

		$this->source = $this->define_source( $cn );

		// register source
		$cn->privacy_consent->add_instance( $this, $this->source['id'] );
		$cn->privacy_consent->add_source( $this->source );

		add_action( 'admin_init', [ $this, 'register_source' ] );

		// check compliance status
		if ( $cn->get_status() !== 'active' )
			return;

		$this->register_hooks( $cn );
	}

	/**
	 * Build the source descriptor.
	 *
	 * @param object $cn  Main plugin instance, for options/defaults lookups.
	 *
	 * @return array
	 */
	abstract protected function define_source( $cn );

	/**
	 * Attach the integration's own hooks.
	 *
	 * Called only when the compliance status is active.
	 *
	 * @param object $cn  Main plugin instance.
	 *
	 * @return void
	 */
	abstract protected function register_hooks( $cn );

	/**
	 * Register the module's settings group.
	 *
	 * Group and option name are both 'cookie_notice_privacy_consent_<id>' —
	 * the same string, as every module declared it.
	 *
	 * @return void
	 */
	public function register_source() {
		$option = 'cookie_notice_privacy_consent_' . $this->source['id'];

		register_setting(
			$option,
			$option,
			[
				'type' => 'array'
			]
		);
	}

	/**
	 * Validate the module's two settings.
	 *
	 * '<id>_active' is a checkbox, so its absence from the submitted array is
	 * the "off" signal rather than an error. '<id>_active_type' falls back to
	 * the plugin default unless it names a known active type — an unrecognised
	 * value is discarded rather than stored.
	 *
	 * @param array $input
	 *
	 * @return array
	 */
	public function validate( $input ) {
		// get main instance
		$cn = Cookie_Notice();

		$id     = $this->source['id'];
		$active = $id . '_active';
		$type   = $id . '_active_type';

		$input[ $active ] = isset( $input[ $active ] );
		$input[ $type ] = isset( $input[ $type ] ) && array_key_exists( $input[ $type ], $cn->privacy_consent->form_active_types ) ? $input[ $type ] : $cn->defaults['privacy_consent'][ $type ];

		return $input;
	}
}

/**
 * Base class for a module whose forms are a WordPress custom post type.
 *
 * Contact Form 7, Mailchimp and WPForms each store forms as posts and differ
 * only in the post type name, so their form_exists() and get_forms() were
 * character-for-character identical once that one string was substituted.
 *
 * @class Cookie_Notice_Privacy_Consent_Post_Type_Module
 */
abstract class Cookie_Notice_Privacy_Consent_Post_Type_Module extends Cookie_Notice_Privacy_Consent_Module {

	/**
	 * The post type the integration stores its forms as.
	 *
	 * @var string
	 */
	protected $post_type = '';

	/**
	 * Check whether form exists.
	 *
	 * @param int $form_id
	 *
	 * @return bool
	 */
	public function form_exists( $form_id ) {
		$query = new WP_Query( [
			'p'				=> $form_id,
			'post_status'	=> 'publish',
			'post_type'		=> $this->post_type,
			'fields'		=> 'ids',
			'no_found_rows'	=> true
		] );

		return $query->have_posts();
	}

	/**
	 * Get forms.
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function get_forms( $args ) {
		// get only published forms
		$query = new WP_Query( [
			'post_status'		=> 'publish',
			'post_type'			=> $this->post_type,
			'order'				=> $args['order'],
			'orderby'			=> $args['orderby'],
			'fields'			=> 'all',
			'posts_per_page'	=> 10,
			'no_found_rows'		=> false,
			'paged'				=> $args['page'],
			's'					=> $args['search']
		] );

		$forms = [];

		// any forms?
		if ( ! empty( $query->posts ) ) {
			foreach ( $query->posts as $post ) {
				$forms[] = [
					'id'		=> $post->ID,
					'title'		=> $post->post_title,
					'date'		=> $post->post_date,
					'fields'	=> []
				];
			}
		}

		return [
			'forms'		=> $forms,
			'total'		=> $query->found_posts,
			'max_pages'	=> $query->max_num_pages
		];
	}
}
