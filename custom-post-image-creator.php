<?php
/**
 * Plugin Name:       Custom Post Image Creator
 * Plugin URI:        https://github.com/arshadalgalib/custom-post-image-creator
 * Description:       Design studio, multilingual (Bengali-first) social image rendering, brand kits, templates, multi-account Facebook and Telegram publishing, delivery tracking, campaigns, calendar, analytics and automation for WordPress content.
 * Version:           7.0.1
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
	define( 'CPIC_VERSION', '7.0.1' );
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
	define( 'CPIC_DB_VERSION', '7.0.1' );
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

/**
 * Main plugin accessor.
 *
 * @return \CPIC\Core\Plugin
 */
function cpic() {
	return \CPIC\Core\Plugin::instance();
}

/**
 * Service container shortcut.
 *
 * @param string|null $id Service id.
 * @return mixed
 */
function cpic_service( $id = null ) {
	return null === $id ? cpic()->container() : cpic()->container()->get( $id );
}

// Boot as early as possible; modules decide their own hook timing.
cpic();
