<?php
/**
 * Plugin Name:       Custom Post Image Creator
 * Plugin URI:        https://github.com/arshadalgalib/custom-post-image-creator
 * Description:       Design studio, multilingual (Bengali-first) social image rendering, brand kits, templates, multi-account Facebook and Telegram publishing, delivery tracking, campaigns, calendar, analytics and automation for WordPress content.
 * Version:           7.0.4
 * Author:            CPIC Dev Team
 * Author URI:        https://github.com/arshadalgalib/custom-post-image-creator
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       custom-post-image-creator
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ─── Constants ─────────────────────────────────────────────────────── */

/*
 * Every constant is guarded: if a copy of this plugin is already loaded (an
 * mu-plugin, a second directory, a leftover test bootstrap) we must not emit
 * "Constant already defined" warnings during activation, and the first copy's
 * paths are the ones that should win.
 */
if ( ! defined( 'CPIC_VERSION' ) ) {
	define( 'CPIC_VERSION', '7.0.4' );
}
if ( ! defined( 'CPIC_PLUGIN_FILE' ) ) {
	define( 'CPIC_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'CPIC_PLUGIN_DIR' ) ) {
	define( 'CPIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'CPIC_PLUGIN_URL' ) ) {
	define( 'CPIC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'CPIC_PLUGIN_BASENAME' ) ) {
	define( 'CPIC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'CPIC_DB_VERSION' ) ) {
	define( 'CPIC_DB_VERSION', '7.0.4' );
}
if ( ! defined( 'CPIC_REST_NAMESPACE' ) ) {
	define( 'CPIC_REST_NAMESPACE', 'cpic/v1' );
}
if ( ! defined( 'CPIC_MIN_PHP' ) ) {
	define( 'CPIC_MIN_PHP', '7.4' );
}
if ( ! defined( 'CPIC_MIN_WP' ) ) {
	define( 'CPIC_MIN_WP', '5.8' );
}

/* ─── Autoloader (PSR-4 style, no Composer requirement at runtime) ──── */

require_once CPIC_PLUGIN_DIR . 'src/Core/Autoloader.php';
\CPIC\Core\Autoloader::register();

/* ─── Boot ──────────────────────────────────────────────────────────── */

if ( ! function_exists( 'cpic' ) ) {
	/**
	 * Main plugin accessor.
	 *
	 * @return \CPIC\Core\Plugin
	 */
	function cpic() {
		return \CPIC\Core\Plugin::instance();
	}
}

if ( ! function_exists( 'cpic_service' ) ) {
	/**
	 * Service container shortcut.
	 *
	 * @param string|null $id Service id.
	 * @return mixed
	 */
	function cpic_service( $id = null ) {
		return null === $id ? cpic()->container() : cpic()->container()->get( $id );
	}
}

/*
 * Boot as early as possible; modules decide their own hook timing.
 *
 * `Plugin` runs its constructor and every module's `boot()` here, at
 * file-include time. If that ever throws, WordPress is mid-activation and
 * reports only "could not be activated because it triggered a fatal error".
 * Catch it, log it, and leave a readable trace for the admin notice instead of
 * dying silently. Individual modules are already isolated inside
 * `ModuleManager`; this is the last-resort net around everything else.
 */
try {
	cpic();
} catch ( \Throwable $cpic_boot_error ) {
	update_option(
		'cpic_activation_errors',
		array(
			'time'   => time(),
			'php'    => PHP_VERSION,
			'errors' => array(
				'boot' => $cpic_boot_error->getMessage()
					. ' (' . basename( $cpic_boot_error->getFile() ) . ':' . $cpic_boot_error->getLine() . ')',
			),
		),
		false
	);

	if ( function_exists( 'error_log' ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		error_log( 'CPIC boot failed: ' . $cpic_boot_error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}
