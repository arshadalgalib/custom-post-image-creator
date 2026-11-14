<?php
/**
 * 6.x compatibility shim: SettingsAdapter.
 *
 * One class per file so the autoloader can find it; the shared helper lives in
 * `bootstrap.php` and is required below.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Support\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

/**
 * 6.x `CPIC_Settings` shim.
 */
final class SettingsAdapter {

	/**
	 * Get a legacy option.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		return bridge()->option( $key, $default );
	}

	/**
	 * Update a legacy option through the validated schema.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public static function update( $key, $value ) {
		$key = 0 === strpos( (string) $key, 'cpic_' ) ? (string) $key : 'cpic_' . (string) $key;

		return \CPIC\Core\Plugin::instance()->service( 'settings' )->set( $key, $value );
	}
}
