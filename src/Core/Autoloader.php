<?php
/**
 * Lightweight PSR-4 autoloader.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps the CPIC\ namespace onto src/ without pulling in Composer.
 */
final class Autoloader {

	/**
	 * Namespace prefix.
	 */
	const PREFIX = 'CPIC\\';

	/**
	 * Namespace prefix length.
	 */
	const PREFIX_LENGTH = 5;

	/**
	 * Relative source directory.
	 */
	const BASE_DIR = 'src/';

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Resolve a class name to a file.
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	public static function load( $class ) {
		if ( 0 !== strncmp( self::PREFIX, $class, self::PREFIX_LENGTH ) ) {
			return;
		}

		$relative = substr( $class, self::PREFIX_LENGTH );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$path     = CPIC_PLUGIN_DIR . self::BASE_DIR . $relative . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Translate a class name into an expected file path (used by tests).
	 *
	 * @param string $class Class name.
	 * @return string
	 */
	public static function path_for( $class ) {
		$relative = substr( $class, self::PREFIX_LENGTH );
		return CPIC_PLUGIN_DIR . self::BASE_DIR . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
	}
}
