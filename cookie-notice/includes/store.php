<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Cookie Notice option/transient store — the one owner of site-vs-network scope.
 *
 * WHY THIS EXISTS
 * ---------------
 * WordPress splits every storage primitive in two: get_option/get_site_option,
 * update_option/update_site_option, and so on down the list. This plugin runs in
 * both scopes, so before this class the choice between them was made INLINE at
 * every call site — a ternary or a matched if/else pair, written out by hand a
 * hundred-odd times across eleven files.
 *
 * That shape fails in one specific, silent way. The two arms are written
 * separately, so they drift: a fix applied to the site arm and not the network
 * arm leaves a network install reading a value nobody wrote, with no error and
 * nothing visible in the admin screens. #2245 (GPC always reporting "Off") was
 * exactly this, and the duplicated arms in the `use_license` handler — five
 * identical operations written twice, differing only in which function stored
 * the result — are what the shape looks like before it drifts.
 *
 * Routing is a mechanical decision, so it belongs in one place that is tested
 * once. The caller's remaining job is to say WHICH SCOPE it means, which is the
 * part that actually carries meaning and must stay visible at the call site.
 *
 * THE SCOPE ARGUMENT IS REQUIRED, DELIBERATELY
 * --------------------------------------------
 * There is no default and no fallback to the singleton. Callers resolve scope
 * from several different predicates — is_network_admin(), is_plugin_network_active(),
 * is_network_options(), or a local $network already in hand — and they are not
 * interchangeable. A convenience default would pick one of them for every caller
 * that omitted the argument, which is how a site read starts answering with the
 * network row. Omitting it is a fatal, which is the correct failure direction.
 *
 * WHAT THIS CLASS DOES NOT DO
 * ---------------------------
 * It does not decide scope, cache, merge defaults, or know any option's meaning.
 * It is the storage primitive only. Calls that deliberately and unconditionally
 * target one scope — reading the NETWORK row to answer "is this app shared?",
 * or activation seeding the network row — are NOT routed through here: they have
 * no scope decision to make, and passing a literal `true` would only disguise
 * that. Leave those as direct core calls.
 *
 * @class Cookie_Notice_Store
 */
class Cookie_Notice_Store {

	/**
	 * Read an option.
	 *
	 * @param string $key      Option name.
	 * @param mixed  $default  Returned when the option is absent.
	 * @param bool   $network  true = network row, false = site row.
	 *
	 * @return mixed
	 */
	public static function get( $key, $default, $network ) {
		return $network
			? get_site_option( $key, $default )
			: get_option( $key, $default );
	}

	/**
	 * Write an option, creating it if absent.
	 *
	 * $autoload has no network equivalent — update_site_option() takes no such
	 * argument, because network options are not part of the site's autoloaded
	 * alloptions cache. It is therefore applied on the site branch only, and
	 * passing it is not an error on network: the argument describes a site-only
	 * storage detail, so silently having no effect there is correct rather than
	 * something a caller must branch around.
	 *
	 * Defaults to null, which core reads as "leave the existing autoload setting
	 * alone" — the same thing a two-argument update_option() call does today.
	 *
	 * @param string    $key       Option name.
	 * @param mixed     $value     Value to store.
	 * @param bool      $network   true = network row, false = site row.
	 * @param bool|null $autoload  Site scope only; null leaves it unchanged.
	 *
	 * @return bool
	 */
	public static function set( $key, $value, $network, $autoload = null ) {
		return $network
			? update_site_option( $key, $value )
			: update_option( $key, $value, $autoload );
	}

	/**
	 * Create an option only if it does not already exist.
	 *
	 * Distinct from set(): core's add_* family is a no-op when the key is
	 * present, which is what activation wants and what set() would violate.
	 *
	 * add_option()'s third parameter is the long-deprecated $deprecated slot, so
	 * $autoload is passed fourth; add_site_option() again has no equivalent.
	 *
	 * @param string    $key       Option name.
	 * @param mixed     $value     Value to store.
	 * @param bool      $network   true = network row, false = site row.
	 * @param bool|null $autoload  Site scope only; null leaves core's default.
	 *
	 * @return bool
	 */
	public static function add( $key, $value, $network, $autoload = null ) {
		return $network
			? add_site_option( $key, $value )
			: add_option( $key, $value, null, $autoload );
	}

	/**
	 * Delete an option.
	 *
	 * @param string $key      Option name.
	 * @param bool   $network  true = network row, false = site row.
	 *
	 * @return bool
	 */
	public static function delete( $key, $network ) {
		return $network
			? delete_site_option( $key )
			: delete_option( $key );
	}

	/**
	 * Read a transient.
	 *
	 * @param string $key      Transient name.
	 * @param bool   $network  true = network transient, false = site transient.
	 *
	 * @return mixed  false when absent or expired.
	 */
	public static function get_transient( $key, $network ) {
		return $network
			? get_site_transient( $key )
			: get_transient( $key );
	}

	/**
	 * Write a transient.
	 *
	 * The transient family is symmetric — both signatures take $expiration — so
	 * unlike set() there is no argument that applies to one scope only.
	 *
	 * @param string $key         Transient name.
	 * @param mixed  $value       Value to store.
	 * @param int    $expiration  Seconds until expiry; 0 = no expiry.
	 * @param bool   $network     true = network transient, false = site transient.
	 *
	 * @return bool
	 */
	public static function set_transient( $key, $value, $expiration, $network ) {
		return $network
			? set_site_transient( $key, $value, $expiration )
			: set_transient( $key, $value, $expiration );
	}

	/**
	 * Delete a transient.
	 *
	 * @param string $key      Transient name.
	 * @param bool   $network  true = network transient, false = site transient.
	 *
	 * @return bool
	 */
	public static function delete_transient( $key, $network ) {
		return $network
			? delete_site_transient( $key )
			: delete_transient( $key );
	}
}
