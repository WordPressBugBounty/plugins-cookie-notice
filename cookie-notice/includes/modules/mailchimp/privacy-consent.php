<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie Notice Modules Mailchimp Privacy Consent class.
 *
 * Compatibility since: 4.0.0
 *
 * @class Cookie_Notice_Modules_Mailchimp_Privacy_Consent
 */
class Cookie_Notice_Modules_Mailchimp_Privacy_Consent extends Cookie_Notice_Privacy_Consent_Post_Type_Module {

	/**
	 * Forms are stored as posts of this type.
	 *
	 * @var string
	 */
	protected $post_type = 'mc4wp-form';

	/**
	 * Build the source descriptor.
	 *
	 * @param object $cn
	 *
	 * @return array
	 */
	protected function define_source( $cn ) {
		return [
			'name'			=> __( 'Mailchimp for WP', 'cookie-notice' ),
			'id'			=> 'mailchimp',
			'id_type'		=> 'integer',
			'type'			=> 'dynamic',
			'availability'	=> cn_is_plugin_active( 'mailchimp', 'privacy-consent' ),
			'status'		=> $cn->options['privacy_consent']['mailchimp_active'],
			'status_type'	=> $cn->options['privacy_consent']['mailchimp_active_type'],
			'forms'			=> []
		];
	}

	/**
	 * Attach the integration's own hooks.
	 *
	 * @param object $cn
	 *
	 * @return void
	 */
	protected function register_hooks( $cn ) {
		// forms
		add_filter( 'mc4wp_form_after_fields', [ $this, 'form_html' ], 10, 2 );
		add_action( 'mc4wp_form_success', [ $this, 'handle_form' ] );
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
			'p'				=> (int) $args['form_id'],
			'post_status'	=> 'publish',
			'post_type'		=> 'mc4wp-form',
			'fields'		=> 'all',
			'no_found_rows'	=> true
		] );

		// any forms?
		if ( ! empty( $query->posts[0] ) ) {
			$form = [
				'source'	=> $this->source['id'],
				'id'		=> $query->posts[0]->ID,
				'title'		=> Cookie_Notice()->privacy_consent->strcut( sanitize_text_field( $query->posts[0]->post_title ), 100 ),
				'fields'	=> [
					'subject'	=> [
						'first_name'	=> 'FNAME',
						'last_name'		=> 'LNAME',
						'email'			=> 'EMAIL'
					],
					'preferences'	=> [
						'terms'		=> 'AGREE_TO_TERMS'
					]
				]
			];
		} else
			$form = [];

		return $form;
	}

	/**
	 * Get form.
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function form_html( $html, $form ) {
		// active form?
		if ( Cookie_Notice()->privacy_consent->is_form_active( $form->ID, $this->source['id'] ) ) {
			// get form data
			$form_data = $this->get_form( [
				'form_id' => $form->ID
			] );

			$html .= '
			<script data-cfasync="false" data-nowprocket data-noptimize="1" data-no-optimize="1" nitro-exclude data-jetpack-boost="ignore">
			if ( typeof huOptions !== \'undefined\' ) {
				var huFormData = ' . wp_json_encode( $form_data ) . ';
				var huFormNode = document.querySelector( \'form[class*="mc4wp-form-' . (int) $form->ID . '"]\' );

				huFormData[\'node\'] = huFormNode;
				huOptions[\'forms\'].push( huFormData );
			}
			</script>';
		}

		return $html;
	}

	/**
	 * Handle form submission.
	 *
	 * @param object $form
	 *
	 * @return void
	 */
	public function handle_form( $form ) {
		// get main instance
		$cn = Cookie_Notice();

		// active registration form?
		if ( $cn->privacy_consent->is_form_active( $form->ID, $this->source['id'] ) )
			$cn->privacy_consent->set_cookie( 'true' );
	}
}

new Cookie_Notice_Modules_Mailchimp_Privacy_Consent();