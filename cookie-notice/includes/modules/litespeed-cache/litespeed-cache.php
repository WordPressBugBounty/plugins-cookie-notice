<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie Notice Modules LiteSpeed Cache class.
 *
 * Compatibility since: 3.0.0
 *
 * @class Cookie_Notice_Modules_LiteSpeedCache
 */
class Cookie_Notice_Modules_LiteSpeedCache {

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'init', [ $this, 'load_module' ], 9 );
	}

	/**
	 * Add compatibility to LiteSpeed Cache plugin.
	 *
	 * @return void
	 */
	public function load_module() {
		add_filter( 'litespeed_optimize_js_excludes', [ $this, 'exclude_js' ] );
		add_filter( 'litespeed_optm_js_defer_exc ', [ $this, 'exclude_js' ] );
	}

	/**
	 * Exclude JavaScript external file and inline code.
	 *
	 * @param array $excludes
	 * @return array
	 */
	function exclude_js( $excludes ) {
		// add widget url
		$excludes[] = basename( Cookie_Notice()->get_url( 'widget' ) );

		// add widget inline code
		$excludes[] = 'huOptions';

		// React admin asset exclusions — see Cookie_Notice::REACT_ADMIN_*.
		// Defense-in-depth if LiteSpeed's JS combine / defer touches admin pages.
		$excludes[] = Cookie_Notice::REACT_ADMIN_BUNDLE_BASENAME;
		$excludes[] = Cookie_Notice::REACT_ADMIN_INLINE_KEYWORD;

		return $excludes;
	}
}

new Cookie_Notice_Modules_LiteSpeedCache();