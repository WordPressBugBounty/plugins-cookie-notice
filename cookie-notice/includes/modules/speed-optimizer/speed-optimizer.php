<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

use SiteGround_Optimizer\Options\Options;

/**
 * Cookie Notice Modules Speed Optimizer class.
 *
 * Compatibility since: 5.5.0
 *
 * @class Cookie_Notice_Modules_SpeedOptimizer
 */
class Cookie_Notice_Modules_SpeedOptimizer {

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
	 * Add compatibility to Speed Optimizer plugin.
	 *
	 * @return void
	 */
	public function load_module() {
		// bail if options class is not available
		if ( ! class_exists( 'SiteGround_Optimizer\Options\Options' ) )
			return;

		// check caching status
		$cache_active = Options::is_enabled( 'siteground_optimizer_enable_cache' ) || Options::is_enabled( 'siteground_optimizer_file_caching' );

		// update 2.4.17 — cache purge is a caching concern, so it self-gates
		// behind the caching-compatibility toggle (this module now loads ungated
		// per DEC-006, so the purge must self-gate to preserve prior behavior).
		if ( version_compare( Cookie_Notice()->db_version, '2.4.16', '<=' ) ) {
			if ( $cache_active && Cookie_Notice()->settings->is_caching_compatibility() ) {
				// clear cache
				$this->delete_cache();
			}
		}

		// JS minify/combine is INDEPENDENT of caching in SiteGround Optimizer —
		// a site can combine/minify with caching off. So the widget exclusions
		// must register regardless of $cache_active OR the caching-compatibility
		// toggle, or the combiner swallows our loader + inline huOptions and the
		// widget can't self-locate (banner AND pre-consent blocking both die; N1
		// failure, see DEC-006). Registering a filter for an inactive optimizer is
		// a harmless no-op. Cache purging, by contrast, only makes sense when
		// caching is on, so it stays gated (below + above) behind both
		// $cache_active and is_caching_compatibility().
		add_filter( 'sgo_js_minify_exclude', [ $this, 'exclude_script' ] );
		add_filter( 'sgo_javascript_combine_exclude', [ $this, 'exclude_script' ] );
		add_filter( 'sgo_javascript_combine_excluded_external_paths', [ $this, 'exclude_script' ] );
		add_filter( 'sgo_javascript_combine_excluded_inline_content', [ $this, 'exclude_code' ] );

		if ( $cache_active && Cookie_Notice()->settings->is_caching_compatibility() ) {
			// actions
			add_action( 'cn_configuration_updated', [ $this, 'delete_cache' ] );
		}
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
		// Defense-in-depth if SG Optimizer's JS minify / combine touches admin pages.
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

	/**
	 * Delete all cache files.
	 *
	 * @return void
	 */
	public function delete_cache() {
		if ( function_exists( 'sg_cachepress_purge_cache' ) )
			sg_cachepress_purge_cache();
	}
}

new Cookie_Notice_Modules_SpeedOptimizer();