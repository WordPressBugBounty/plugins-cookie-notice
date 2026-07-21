<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

use Hummingbird\Core\Utils;

/**
 * Cookie Notice Modules Hummingbird class.
 *
 * Compatibility since: 2.1.0
 *
 * @class Cookie_Notice_Modules_Hummingbird
 */
class Cookie_Notice_Modules_Hummingbird {

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// actions
		add_action( 'init', [ $this, 'load_module' ] );
	}

	/**
	 * Add compatibility to Hummingbird plugin.
	 *
	 * @return void
	 */
	public function load_module() {
		// Delay-JS exclusion is registered UNCONDITIONALLY — before the
		// class_exists() guard below and regardless of Hummingbird's page-cache
		// state OR the plugin's caching-compatibility toggle. Hummingbird's
		// DelayJS rewrites <script> tags from the raw HTML (it does not read the
		// WP script queue), and delaying our banner defers its execution past
		// first paint — which arms pre-consent script blocking too late, so
		// trackers can fire before consent (N1 failure; see DEC-006). Hook
		// membership is the contract, not load order; registering a filter for an
		// inactive optimizer is a harmless no-op (mirrors the Speed Optimizer
		// precedent). The cache-purge action below, by contrast, is a caching
		// concern — it self-gates behind is_caching_compatibility() so it does NOT
		// register when the toggle is off (this module now loads ungated, so the
		// purge must self-gate to preserve the pre-DEC-006 behavior).
		add_filter( 'wphb_delay_js_exclusions', [ $this, 'exclude_from_delay' ] );

		// bail if options class is not available
		if ( ! class_exists( 'Hummingbird\Core\Utils' ) )
			return;

		// get caching module
		$mod = Utils::get_module( 'page_cache' );

		// valid object?
		if ( is_a( $mod, 'Hummingbird\Core\Modules\Page_Cache' ) && method_exists( $mod, 'is_active' ) ) {
			// is caching enabled AND caching-compatibility toggle on?
			if ( $mod->is_active() && Cookie_Notice()->settings->is_caching_compatibility() ) {
				// delete cache files after updating settings or status
				add_action( 'cn_configuration_updated', [ $this, 'delete_cache' ] );
			}
		}
	}

	/**
	 * Exclude the Compliance widget from Hummingbird's DelayJS.
	 *
	 * DelayJS matches each entry as a case-insensitive regex against the raw
	 * <script> tag. 'hu-banner' matches the external loader (id="hu-banner-js",
	 * src=…hu-banner.min.js); 'huOptions' matches the inline config tag
	 * (var huOptions=…). Both are literal (no regex metacharacters).
	 *
	 * @param array $exclusions
	 * @return array
	 */
	public function exclude_from_delay( $exclusions ) {
		// coerce: the filter is documented as an array, but stay defensive
		$exclusions = (array) $exclusions;

		$exclusions[] = 'hu-banner';
		$exclusions[] = 'huOptions';

		return $exclusions;
	}

	/**
	 * Delete all cache files.
	 *
	 * @return void
	 */
	public function delete_cache() {
		do_action( 'wphb_clear_page_cache' );
	}
}

new Cookie_Notice_Modules_Hummingbird();