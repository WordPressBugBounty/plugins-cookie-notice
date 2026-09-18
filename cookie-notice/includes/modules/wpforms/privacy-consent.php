<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie Notice Modules WPForms Privacy Consent class.
 *
 * Compatibility since: 1.6.0
 *
 * @class Cookie_Notice_Modules_WPForms_Privacy_Consent
 */
class Cookie_Notice_Modules_WPForms_Privacy_Consent extends Cookie_Notice_Privacy_Consent_Post_Type_Module {

	/**
	 * Forms are stored as posts of this type.
	 *
	 * @var string
	 */
	protected $post_type = 'wpforms';

	/**
	 * Build the source descriptor.
	 *
	 * @param object $cn
	 *
	 * @return array
	 */
	protected function define_source( $cn ) {
		return [
			'name'			=> __( 'WPForms', 'cookie-notice' ),
			'id'			=> 'wpforms',
			'id_type'		=> 'integer',
			'type'			=> 'dynamic',
			'availability'	=> cn_is_plugin_active( 'wpforms', 'privacy-consent' ),
			'status'		=> $cn->options['privacy_consent']['wpforms_active'],
			'status_type'	=> $cn->options['privacy_consent']['wpforms_active_type'],
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
		add_action( 'wpforms_frontend_output', [ $this, 'wpforms_shortcode' ], 19, 5 );
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
			'post_type'		=> 'wpforms',
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
						'first_name'	=> '',
						'last_name'		=> ''
					]
				]
			];
		} else
			$form = [];

		return $form;
	}

	/**
	 * WPForms shortcode output.
	 *
	 * @param array|mixed $data
	 * @param null $deprecated
	 * @param bool $title
	 * @param bool $description
	 * @param array $errors
	 *
	 * @return void
	 */
	public function wpforms_shortcode( $data, $deprecated, $title, $description, $errors ) {
		$data = (array) $data;

		// active form?
		if ( Cookie_Notice()->privacy_consent->is_form_active( $data['id'], $this->source['id'] ) ) {
			// get form data
			$form_data = $this->get_form( [
				'form_id' => $data['id']
			] );

			echo '
			<script data-cfasync="false" data-nowprocket data-noptimize="1" data-no-optimize="1" nitro-exclude data-jetpack-boost="ignore">
			if ( typeof huOptions !== \'undefined\' ) {
				var huFormData = ' . wp_json_encode( $form_data ) . ';
				var huFormNode = document.querySelector( \'[id="wpforms-' . (int) $data['id'] . '"] form\' );

				var firstName = huFormNode.querySelector( \'input.wpforms-field-name-first\' );
				var lastName = huFormNode.querySelector( \'input.wpforms-field-name-last\' );

				if ( firstName )
					huFormData[\'fields\'][\'subject\'][\'first_name\'] = firstName.getAttribute( \'name\' );

				if ( lastName )
					huFormData[\'fields\'][\'subject\'][\'last_name\'] = lastName.getAttribute( \'name\' );

				huFormData[\'node\'] = huFormNode;
				huOptions[\'forms\'].push( huFormData );
			}
			</script>';
		}
	}
}

new Cookie_Notice_Modules_WPForms_Privacy_Consent();