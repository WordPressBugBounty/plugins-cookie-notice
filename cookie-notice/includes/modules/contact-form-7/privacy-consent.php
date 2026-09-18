<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie Notice Modules Contact Form 7 Privacy Consent class.
 *
 * Compatibility since: 5.3.0
 *
 * @class Cookie_Notice_Modules_ContactForm7_Privacy_Consent
 */
class Cookie_Notice_Modules_ContactForm7_Privacy_Consent extends Cookie_Notice_Privacy_Consent_Post_Type_Module {


	/**
	 * Contact Form 7 stores each form as a post of this type.
	 *
	 * @var string
	 */
	protected $post_type = 'wpcf7_contact_form';

	/**
	 * Build the source descriptor.
	 *
	 * @param object $cn
	 *
	 * @return array
	 */
	protected function define_source( $cn ) {
		return [
			'name'			=> __( 'Contact Form 7', 'cookie-notice' ),
			'id'			=> 'contactform7',
			'id_type'		=> 'integer',
			'type'			=> 'dynamic',
			'availability'	=> cn_is_plugin_active( 'contactform7', 'privacy-consent' ),
			'status'		=> $cn->options['privacy_consent']['contactform7_active'],
			'status_type'	=> $cn->options['privacy_consent']['contactform7_active_type'],
			'forms'			=> []
		];
	}

	/**
	 * Attach Contact Form 7's hooks.
	 *
	 * @param object $cn
	 *
	 * @return void
	 */
	protected function register_hooks( $cn ) {
		// forms
		add_filter( 'do_shortcode_tag', [ $this, 'shortcode' ], 10, 3 );
	}

	/**
	 * Get form.
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function get_form( $args ) {
		// get only one form
		$query = new WP_Query( [
			'p'					=> (int) $args['form_id'],
			'post_status'		=> 'publish',
			'post_type'			=> 'wpcf7_contact_form',
			'fields'			=> 'all',
			'no_found_rows'		=> true
		] );

		// any forms?
		if ( ! empty( $query->posts[0] ) ) {
			$form = [
				'source'	=> $this->source['id'],
				'id'		=> $query->posts[0]->ID,
				'title'		=> Cookie_Notice()->privacy_consent->strcut( sanitize_text_field( $query->posts[0]->post_title ), 100 ),
				'fields'	=> [
					'subject'	=> [
						'first_name'	=> 'your-name',
						'email'			=> 'your-email'
					],
					'preferences'	=> [
						'terms'		=> 'your-consent'
					]
				]
			];
		} else
			$form = [];

		return $form;
	}

	/**
	 * Contact Form 7 shortcode output.
	 *
	 * @param string $output
	 * @param string $tag
	 * @param array $attr
	 *
	 * @return string
	 */
	public function shortcode( $output, $tag, $attr ) {
		if ( $tag !== 'contact-form-7' )
			return $output;

		// check form id
		$form_id = isset( $attr['id'] ) ? trim( $attr['id'] ) : '';

		if ( empty( $form_id ) )
			return $output;

		$form = null;

		// sanitize form id
		$form_id = preg_replace( '/[^a-z0-9]/i', '', $form_id );

		// get form by hash, since cf7 5.8
		if ( function_exists( 'wpcf7_get_contact_form_by_hash' ) )
			$form = wpcf7_get_contact_form_by_hash( $form_id );

		// get form by integer id
		if ( ! $form && function_exists( 'wpcf7_contact_form' ) )
			$form = wpcf7_contact_form( $form_id );

		// valid form?
		if ( $form && is_a( $form, 'WPCF7_ContactForm' ) ) {
			// get id
			$form_id = (int) $form->id();

			// active form?
			if ( $form_id && Cookie_Notice()->privacy_consent->is_form_active( $form_id, $this->source['id'] ) ) {
				// get form data
				$form_data = $this->get_form( [
					'form_id' => $form_id
				] );

				$output = (string) $output;
				$output .= '
				<script data-cfasync="false" data-nowprocket data-noptimize="1" data-no-optimize="1" nitro-exclude data-jetpack-boost="ignore">
				if ( typeof huOptions !== \'undefined\' ) {
					var huFormData = ' . wp_json_encode( $form_data ) . ';
					var huFormNode = document.querySelector( \'[id^="wpcf7-f' . (int) $form_id . '-"] form\' );

					huFormData[\'node\'] = huFormNode;
					huOptions[\'forms\'].push( huFormData );
				}
				</script>';
			}
		}

		return $output;
	}
}

new Cookie_Notice_Modules_ContactForm7_Privacy_Consent();