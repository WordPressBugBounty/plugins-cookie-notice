<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie Notice Modules Breeze class.
 *
 * Compatibility since: 2.0.30
 *
 * @class Cookie_Notice_Modules_Breeze
 */
class Cookie_Notice_Modules_Breeze {

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'plugins_loaded', [ $this, 'load_module' ], 11 );
	}

	/**
	 * Add compatibility to Breeze plugin.
	 *
	 * The runtime inline-huOptions exclusion FILTER (cn_cookie_compliance_output)
	 * registers regardless of the caching-compatibility toggle or breeze-active —
	 * a minified/combined banner arms pre-consent script blocking too late (N1
	 * failure; see DEC-006). It stays conditioned only on breeze-minify-js, which
	 * reflects whether Breeze will actually minify. Breeze's PERSISTED DB WRITES
	 * (exclude_widget_script + remove_excluded_external_script, both breeze_update_option)
	 * and its cache purge are caching concerns and self-gate behind
	 * is_caching_compatibility() — they must NOT run on anonymous GETs when the
	 * toggle is off (DEC-006 (c), OBS-39).
	 *
	 * @return void
	 */
	public function load_module() {
		// get main instance
		$cn = Cookie_Notice();

		// runtime inline-huOptions exclusion filter — N1, registers regardless of
		// caching-compat (only conditioned on breeze-minify-js).
		if ( (int) Breeze_Options_Reader::get_option_value( 'breeze-minify-js' ) === 1 )
			add_filter( 'cn_cookie_compliance_output', [ $this, 'update_cc_output' ] );

		// persisted writes + purge are caching concerns — self-gate behind the
		// caching-compatibility toggle.
		if ( Cookie_Notice()->settings->is_caching_compatibility() ) {
			// update 2.5.7+
			if ( version_compare( $cn->db_version, '2.5.7', '<=' ) )
				$this->remove_excluded_external_script();

			// Keep the current widget FILE out of Breeze's JS minify/combine. The
			// other module concern only tags the inline huOptions (via the
			// //breeze-extra/ marker); nothing excluded the widget bundle itself,
			// so a combine could still swallow it and break the widget's
			// self-locate. Mirror the file-exclusion the other optimizer modules do.
			$this->exclude_widget_script();

			// is caching active?
			if ( (int) Breeze_Options_Reader::get_option_value( 'breeze-active' ) === 1 ) {
				// update 2.4.16+
				if ( version_compare( $cn->db_version, '2.4.16', '<=' ) ) {
					// clear cache
					$this->delete_cache();
				}

				add_action( 'cn_configuration_updated', [ $this, 'delete_cache' ] );
			}
		}
	}

	/**
	 * Update Cookie Compliance output.
	 *
	 * @param string $output
	 *
	 * @return string
	 */
	public function update_cc_output( $output ) {
		// add special /breeze-extra/ comment
		return preg_replace( '/<script(.*)var huOptions(.*)<\/script>/', "<script$1var huOptions$2\n//breeze-extra/</script>", $output, 1 );
	}

	/**
	 * Remove previously excluded external script from being minified/combined.
	 *
	 * @return void
	 */
	public function remove_excluded_external_script() {
		$pattern = '(.*)/js/hu-options.js(.*)';

		// get breeze file options
		$file_options = breeze_get_option( 'file_settings' );

		// find pattern
		$key = array_search( $pattern, $file_options['breeze-exclude-js'], true );

		// found pattern? remove it
		if ( $key !== false )
			unset( $file_options['breeze-exclude-js'][$key] );

		// update breeze file options
		breeze_update_option( 'file_settings', $file_options, true );
	}

	/**
	 * Add the current widget script to Breeze's JS exclusion list, so it is
	 * never minified/combined into another bundle.
	 *
	 * @return void
	 */
	public function exclude_widget_script() {
		// current widget filename (e.g. hu-banner.min.js) — matches what the
		// other optimizer modules exclude.
		$widget = basename( Cookie_Notice()->get_url( 'widget' ) );

		if ( $widget === '' )
			return;

		$pattern = '(.*)/' . $widget . '(.*)';

		// get breeze file options
		$file_options = breeze_get_option( 'file_settings' );

		// guard the option shape
		if ( ! is_array( $file_options ) )
			$file_options = [];

		if ( ! isset( $file_options['breeze-exclude-js'] ) || ! is_array( $file_options['breeze-exclude-js'] ) )
			$file_options['breeze-exclude-js'] = [];

		// already excluded? nothing to do (idempotent).
		if ( in_array( $pattern, $file_options['breeze-exclude-js'], true ) )
			return;

		// add the exclusion
		$file_options['breeze-exclude-js'][] = $pattern;

		// update breeze file options
		breeze_update_option( 'file_settings', $file_options, true );
	}

	/**
	 * Delete all cache files.
	 *
	 * @return void
	 */
	public function delete_cache() {
		do_action( 'breeze_clear_all_cache' );
	}
}

new Cookie_Notice_Modules_Breeze();