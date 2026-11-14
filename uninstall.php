<?php
/**
 * Uninstall handler.
 *
 * Data is kept unless the site explicitly opted in to removal
 * (Settings → Storage → "Remove all data when the plugin is deleted"),
 * because deleting a plugin should not silently destroy a newsroom's designs,
 * brand kits and publishing history.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( ! defined( 'CPIC_PLUGIN_FILE' ) ) {
	define( 'CPIC_PLUGIN_FILE', __FILE__ );
}

// The plugin's own settings schema is the single source of truth for options.
$cpic_settings_file = __DIR__ . '/src/Security/Settings.php';

$cpic_option_keys = array();

if ( file_exists( $cpic_settings_file ) ) {
	require_once $cpic_settings_file;

	if ( class_exists( '\\CPIC\\Security\\Settings' ) && method_exists( '\\CPIC\\Security\\Settings', 'option_keys' ) ) {
		$cpic_option_keys = (array) \CPIC\Security\Settings::option_keys();
	}
}

$cpic_legacy_options = array(
	'cpic_publish_queue',
	'cpic_auto_canvas_image_path',
	'cpic_cron_installed',
	'cpic_legacy_imported',
	'cpic_db_version',
	'cpic_migration_state',
);

$cpic_remove = (bool) get_option( 'cpic_cleanup_on_uninstall', 0 );

if ( ! $cpic_remove ) {
	// Always remove request-scoped state; keep the site's content.
	foreach ( array( 'cpic_queue_lock', 'cpic_migration_running' ) as $cpic_transient ) {
		delete_transient( $cpic_transient );
	}

	delete_option( 'cpic_cron_installed' );

	return;
}

/* ── Full removal (opt-in) ──────────────────────────────────────── */

foreach ( array_unique( array_merge( $cpic_option_keys, $cpic_legacy_options ) ) as $cpic_option ) {
	delete_option( $cpic_option );
}

foreach ( array( 'cpic_queue_lock', 'cpic_migration_running' ) as $cpic_transient ) {
	delete_transient( $cpic_transient );
}

// Post meta written by 6.x and by the v7 editor integration.
foreach ( array( 'cpic_last_shared_to_facebook', 'cpic_last_shared_to_telegram', 'cpic_auto_canvas_image_path', '_cpic_editor_image' ) as $cpic_meta ) {
	delete_post_meta_by_key( $cpic_meta );
}

/*
 * Scheduled events — every generation of hook name.
 *
 * The current names are namespaced (`cpic/cron/...`). Clearing only the 6.x
 * and 7.0 names left three live cron events pointing at a plugin that no
 * longer exists, which WordPress keeps firing into the void forever.
 */
$cpic_hooks = array(
	'cpic/cron/queue',
	'cpic/cron/heartbeat',
	'cpic/cron/cleanup',
	'cpic_cron_process_queue',
	'cpic_cron_process_jobs',
	'cpic_run_queue',
	'cpic_heartbeat',
	'cpic_cleanup',
);

foreach ( $cpic_hooks as $cpic_hook ) {
	wp_clear_scheduled_hook( $cpic_hook );
}

// Capabilities added to roles.
$cpic_roles = function_exists( 'wp_roles' ) ? wp_roles() : null;

if ( $cpic_roles && ! empty( $cpic_roles->roles ) ) {
	foreach ( array_keys( $cpic_roles->roles ) as $cpic_role_name ) {
		$cpic_role = get_role( $cpic_role_name );

		if ( ! $cpic_role ) {
			continue;
		}

		foreach ( array_keys( $cpic_role->capabilities ) as $cpic_cap ) {
			if ( 0 === strpos( (string) $cpic_cap, 'cpic_' ) ) {
				$cpic_role->remove_cap( $cpic_cap );
			}
		}
	}
}

// Tables declared by the schema (never a hand-maintained list).
$cpic_tables = array();

$cpic_schema_file = __DIR__ . '/src/Database/Schema.php';

if ( file_exists( $cpic_schema_file ) ) {
	require_once $cpic_schema_file;

	if ( class_exists( '\\CPIC\\Database\\Schema' ) ) {
		$cpic_tables = array_values( \CPIC\Database\Schema::tables() );
	}
}

foreach ( $cpic_tables as $cpic_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$cpic_table}`" );
}

// Generated images (only the plugin's own directories).
$cpic_uploads = wp_upload_dir();
$cpic_dirs    = array( 'cpic-artifacts', 'cpic-generated' );

foreach ( $cpic_dirs as $cpic_dir ) {
	$cpic_path = trailingslashit( $cpic_uploads['basedir'] ) . $cpic_dir;

	if ( ! is_dir( $cpic_path ) ) {
		continue;
	}

	$cpic_files = glob( trailingslashit( $cpic_path ) . '*' );

	if ( is_array( $cpic_files ) ) {
		foreach ( $cpic_files as $cpic_file ) {
			if ( is_file( $cpic_file ) ) {
				wp_delete_file( $cpic_file );
			}
		}
	}

	// Remove the (now empty) directory.
	@rmdir( $cpic_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}
