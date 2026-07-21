<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie Notice Modules WP Rocket class.
 *
 * Compatibility since: 3.8.0
 *
 * @class Cookie_Notice_Modules_WPRocket
 */
class Cookie_Notice_Modules_WPRocket {

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
	 * Add compatibility to WP Rocket plugin.
	 *
	 * @return void
	 */
	public function load_module() {
		// JS-exclusion filters register UNCONDITIONALLY (regardless of the
		// caching-compatibility toggle). Rocket's delay/defer/minify can swallow
		// our loader + inline huOptions with caching-compat off, breaking the
		// widget's self-locate and arming pre-consent blocking too late (N1
		// failure; see DEC-006). Registering a filter for an inactive optimizer is
		// a harmless no-op.
		add_filter( 'rocket_exclude_defer_js', [ $this, 'exclude_script' ] );
		add_filter( 'rocket_exclude_js', [ $this, 'exclude_script' ] );
		add_filter( 'rocket_delay_js_exclusions', [ $this, 'exclude_script' ] );
		add_filter( 'rocket_delay_js_exclusions', [ $this, 'exclude_code' ] );
		add_filter( 'rocket_defer_inline_exclusions', [ $this, 'exclude_code' ] );
		add_filter( 'rocket_excluded_inline_js_content', [ $this, 'exclude_code' ] );

		// Cache purging is a caching concern — self-gate behind the
		// caching-compatibility toggle (this module now loads ungated per
		// DEC-006, so the purge must self-gate to preserve prior behavior).
		if ( Cookie_Notice()->settings->is_caching_compatibility() ) {
			// update 2.4.17
			if ( version_compare( Cookie_Notice()->db_version, '2.4.16', '<=' ) )
				$this->delete_cache();

			// delete cache files after updating settings or status
			add_action( 'cn_configuration_updated', [ $this, 'delete_cache' ] );
		}
	}

	/**
	 * Delete all cache files.
	 *
	 * @return void
	 */
	public function delete_cache() {
		// clear cache
		if ( function_exists( 'rocket_clean_domain' ) )
			rocket_clean_domain();

		// clear minified css and js files
		if ( function_exists( 'rocket_clean_minify' ) )
			rocket_clean_minify( [ 'js', 'css' ] );
	}

	/**
	 * Exclude JavaScript file.
	 *
	 * @param array $excludes
	 * @return array
	 */
	function exclude_script( $excludes ) {
		// add widget url
		$excludes[] = basename( Cookie_Notice()->get_url( 'widget' ) );

		// React admin asset exclusions — see Cookie_Notice::REACT_ADMIN_*.
		// Defense-in-depth if Rocket's delay-JS / minify touches admin pages
		// (e.g. via Cloudflare APO integrations).
		$excludes[] = Cookie_Notice::REACT_ADMIN_BUNDLE_BASENAME;

		return $excludes;
	}

	/**
	 * Exclude JavaScript inline code.
	 *
	 * @param array $excludes
	 * @return array
	 */
	function exclude_code( $excludes ) {
		// add widget inline code
		$excludes[] = 'huOptions';

		// React admin asset exclusions — see Cookie_Notice::REACT_ADMIN_*.
		$excludes[] = Cookie_Notice::REACT_ADMIN_INLINE_KEYWORD;

		return $excludes;
	}
}

new Cookie_Notice_Modules_WPRocket();