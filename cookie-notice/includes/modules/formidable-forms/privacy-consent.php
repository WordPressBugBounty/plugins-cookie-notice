<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie Notice Modules Formidable Forms Privacy Consent class.
 *
 * Compatibility since: 2.0.0
 *
 * @class Cookie_Notice_Modules_FormidableForms_Privacy_Consent
 */
class Cookie_Notice_Modules_FormidableForms_Privacy_Consent extends Cookie_Notice_Privacy_Consent_Module {

	/**
	 * Build the source descriptor.
	 *
	 * @param object $cn
	 *
	 * @return array
	 */
	protected function define_source( $cn ) {
		return [
			'name'			=> __( 'Formidable Forms', 'cookie-notice' ),
			'id'			=> 'formidableforms',
			'id_type'		=> 'integer',
			'type'			=> 'dynamic',
			'availability'	=> cn_is_plugin_active( 'formidableforms', 'privacy-consent' ),
			'status'		=> $cn->options['privacy_consent']['formidableforms_active'],
			'status_type'	=> $cn->options['privacy_consent']['formidableforms_active_type'],
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
		add_filter( 'do_shortcode_tag', [ $this, 'shortcode' ], 10, 3 );
		add_filter( 'frm_validate_entry', [ $this, 'handle_form' ], PHP_INT_MAX, 2 );
	}

	/**
	 * Check whether form exists.
	 *
	 * @param int $form_id
	 *
	 * @return bool
	 */
	public function form_exists( $form_id ) {
		$form = FrmForm::get_published_forms( [ 'id' => $form_id ], 1, 'exclude' );

		return ! empty( $form );
	}

	/**
	 * Get forms.
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function get_forms( $args ) {
		// default query args
		$query = [
			'is_template'		=> 0,
			'parent_form_id'	=> array( null, 0 ),
			'status'			=> array( null, '', 'published' )
		];

		// searching?
		if ( $args['search'] !== '' ) {
			$query[] = [
				'or'				=> true,
				'name LIKE'			=> $args['search'],
				'description LIKE'	=> $args['search'],
				'form_key LIKE'		=> $args['search']
			];
		}

		// check orderby
		if ( $args['orderby'] === 'title' )
			$orderby = 'name';
		elseif ( $args['orderby'] === 'date' )
			$orderby = 'created_at';

		// get only published forms
		$frm_forms = FrmForm::getAll( $query, $orderby . ' ' . $args['order'], '10 OFFSET ' . ( ( $args['page'] - 1 ) * 10 ) );

		$forms = [];

		// any forms?
		if ( ! empty( $frm_forms ) ) {
			$total_items = FrmDb::get_count( 'frm_forms', $query );

			foreach ( $frm_forms as $form ) {
				$forms[] = [
					'id'		=> $form->id,
					'title'		=> $form->name,
					'date'		=> $form->created_at,
					'fields'	=> []
				];
			}
		} else
			$total_items = 0;

		return [
			'forms'		=> $forms,
			'total'		=> $total_items,
			'max_pages'	=> (int) ceil( $total_items / 10 )
		];
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
		$frm_form = FrmForm::get_published_forms( [ 'id' => (int) $args['form_id'] ], 1, 'exclude' );

		// any forms?
		if ( ! empty( $frm_form ) ) {
			$form = [
				'source'	=> $this->source['id'],
				'id'		=> $frm_form->id,
				'title'		=> Cookie_Notice()->privacy_consent->strcut( sanitize_text_field( $frm_form->name ), 100 ),
				'fields'	=> [
					'subject'		=> [],
					'preferences'	=> []
				]
			];
		} else
			$form = [];

		return $form;
		
	}

	/**
	 * Formidable Forms shortcode output.
	 *
	 * @param string $output
	 * @param string $tag
	 * @param array $attr
	 *
	 * @return string
	 */
	public function shortcode( $output, $tag, $attr ) {
		if ( $tag !== 'formidable' )
			return $output;

		// check form id
		$form_id = isset( $attr['id'] ) ? trim( $attr['id'] ) : '';

		if ( empty( $form_id ) )
			return $output;

		// cast form id
		$form_id = (int) $form_id;

		// get form
		// $form = FrmForm::getOne( $form_id );
		$form = FrmForm::get_published_forms( [ 'id' => $form_id ], 1, 'exclude' );

		// valid abd active form?
		if ( $form && Cookie_Notice()->privacy_consent->is_form_active( $form_id, $this->source['id'] ) ) {
			// get form data
			$form_data = $this->get_form( [
				'form_id' => $form_id
			] );

			$output = (string) $output;
			$output .= '
			<script data-cfasync="false" data-nowprocket data-noptimize="1" data-no-optimize="1" nitro-exclude data-jetpack-boost="ignore">
			if ( typeof huOptions !== \'undefined\' ) {
				var huFormData = ' . wp_json_encode( $form_data ) . ';
				var huFormNode = document.querySelector( \'#frm_form_' . $form_id . '_container form\' );

				huFormData[\'node\'] = huFormNode;
				huOptions[\'forms\'].push( huFormData );
			}
			</script>';
		}

		return $output;
	}

	/**
	 * Handle form submission.
	 *
	 * @param array $errors
	 * @param array $values
	 *
	 * @return array
	 */
	public function handle_form( $errors, $values ) {
		// get main instance
		$cn = Cookie_Notice();

		// active registration form?
		if ( $cn->privacy_consent->is_form_active( $values['form_id'], $this->source['id'] ) )
			$cn->privacy_consent->set_cookie( empty( $errors ) ? 'true' : 'false' );

		return $errors;
	}
}

new Cookie_Notice_Modules_FormidableForms_Privacy_Consent();