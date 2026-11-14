<?php
/**
 * Versioned schema + data migrations.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database;

use CPIC\Core\Container;
use CPIC\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies additive migrations with a lock and progress journal so an
 * interrupted upgrade can resume safely on the next request.
 *
 * Nothing is ever dropped. Legacy 6.x tables are kept and extended with
 * `dbDelta()` / `ALTER TABLE`, and legacy template JSON is rewritten by
 * `TemplateMigrator`.
 */
final class Migrator {

	/**
	 * Option storing the applied schema version.
	 */
	const VERSION_OPTION = 'cpic_db_version';

	/**
	 * Option storing migration progress.
	 */
	const STATE_OPTION = 'cpic_migration_state';

	/**
	 * Option acting as a mutex.
	 */
	const LOCK_OPTION = 'cpic_migration_lock';

	/**
	 * Lock lifetime in seconds.
	 */
	const LOCK_TTL = 300;

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Run the full install/upgrade routine.
	 *
	 * @return array{from:string,to:string,steps:array<int,string>}
	 */
	public function install() {
		if ( ! $this->acquire_lock() ) {
			return array(
				'from'  => (string) get_option( self::VERSION_OPTION, '0' ),
				'to'    => CPIC_DB_VERSION,
				'steps' => array( 'locked' ),
			);
		}

		$from  = (string) get_option( self::VERSION_OPTION, '0' );
		$steps = array();

		try {
			$this->create_tables( $steps );
			$this->extend_legacy_tables( $steps );
			$this->run_data_migrations( $from, $steps );
			$this->ensure_directories( $steps );

			update_option( self::VERSION_OPTION, CPIC_DB_VERSION, false );
			update_option(
				self::STATE_OPTION,
				array(
					'completed_at' => current_time( 'mysql' ),
					'from'         => $from,
					'to'           => CPIC_DB_VERSION,
					'steps'        => $steps,
				),
				false
			);
		} catch ( \Throwable $e ) {
			update_option(
				self::STATE_OPTION,
				array(
					'failed_at' => current_time( 'mysql' ),
					'from'      => $from,
					'to'        => CPIC_DB_VERSION,
					'error'     => $e->getMessage(),
					'steps'     => $steps,
				),
				false
			);
			$this->release_lock();
			if ( function_exists( 'error_log' ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				error_log( 'CPIC migration failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
			return array(
				'from'  => $from,
				'to'    => CPIC_DB_VERSION,
				'steps' => $steps,
				'error' => $e->getMessage(),
			);
		}

		$this->release_lock();

		return array(
			'from'  => $from,
			'to'    => CPIC_DB_VERSION,
			'steps' => $steps,
		);
	}

	/**
	 * Upgrade when the stored version is older.
	 *
	 * @return bool Whether a migration ran.
	 */
	public function maybe_upgrade() {
		$stored = (string) get_option( self::VERSION_OPTION, '' );

		if ( version_compare( $stored, CPIC_DB_VERSION, '>=' ) ) {
			return false;
		}

		// Avoid running on every request when a lock is held elsewhere.
		if ( get_transient( 'cpic_migration_running' ) ) {
			return false;
		}

		set_transient( 'cpic_migration_running', 1, self::LOCK_TTL );
		$this->install();
		delete_transient( 'cpic_migration_running' );

		return true;
	}

	/**
	 * Create every declared table.
	 *
	 * @param array<int,string> $steps Step log.
	 * @return void
	 */
	private function create_tables( array &$steps ) {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::definitions() as $key => $sql ) {
			dbDelta( $sql );
			$steps[] = 'create_table:' . $key;
		}

		// Verify.
		$missing = array();
		foreach ( Schema::tables() as $key => $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				$missing[] = $key;
			}
		}
		if ( ! empty( $missing ) && function_exists( 'error_log' ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			error_log( 'CPIC: tables could not be created: ' . implode( ',', $missing ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Add columns introduced in 7.0 to tables created by 6.x.
	 *
	 * @param array<int,string> $steps Step log.
	 * @return void
	 */
	private function extend_legacy_tables( array &$steps ) {
		global $wpdb;

		foreach ( Schema::required_columns() as $key => $columns ) {
			if ( empty( $columns ) || ! isset( Schema::TABLES[ $key ] ) ) {
				continue;
			}
			$table = Schema::table( $key );

			foreach ( $columns as $column => $definition ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', $column ) );
				if ( ! empty( $exists ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition );
				$steps[] = 'add_column:' . $key . '.' . $column;
			}
		}

		foreach ( Schema::required_indexes() as $key => $indexes ) {
			$table = Schema::table( $key );
			foreach ( $indexes as $index => $columns ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$exists = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s', $index ) );
				if ( ! empty( $exists ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( 'ALTER TABLE ' . $table . ' ADD INDEX ' . $index . ' (' . $columns . ')' );
				$steps[] = 'add_index:' . $key . '.' . $index;
			}
		}
	}

	/**
	 * Data migrations.
	 *
	 * @param string            $from  Previously applied version.
	 * @param array<int,string> $steps Step log.
	 * @return void
	 */
	private function run_data_migrations( $from, array &$steps ) {
		if ( version_compare( $from, '7.0.0', '<' ) ) {
			$this->migrate_to_7_0_0( $steps );
		}

		/**
		 * Allow extensions to append idempotent data migrations.
		 *
		 * @param string            $from  Installed version.
		 * @param array<int,string> $steps Step log (by reference through hook return).
		 */
		do_action( 'cpic/database/migrations', $from, $steps );
	}

	/**
	 * 7.0.0 data migration: template configs, defaults, roles, starter library.
	 *
	 * @param array<int,string> $steps Step log.
	 * @return void
	 */
	private function migrate_to_7_0_0( array &$steps ) {
		$plugin = Plugin::instance();

		// 1. Normalise legacy template JSON into the 7.0 design document.
		$migrated = $plugin->service( 'template_manager' )->migrate_all_configs();
		$steps[]  = 'templates_migrated:' . (int) $migrated['updated'] . '/' . (int) $migrated['scanned'];

		// 2. Version snapshots so nothing is lost when a config is rewritten.
		$plugin->service( 'template_manager' )->snapshot_all( 'migration-7.0.0' );
		$steps[] = 'templates_snapshotted';

		// 3. Default brand.
		$brand_id = $plugin->service( 'brands' )->ensure_default();
		$steps[]  = 'default_brand:' . (int) $brand_id;

		// 4. Starter template library (only when the user has no templates of
		//    their own beyond the migrated 6.x set).
		$seeded = $plugin->service( 'starter_library' )->maybe_seed();
		$steps[] = 'starter_templates:' . (int) $seeded;

		// 5. Capabilities / roles.
		$plugin->service( 'capabilities' )->install_roles();
		$steps[] = 'roles_installed';

		// 6. Legacy credential import (single-page tokens become destination rows).
		$imported = $plugin->service( 'accounts' )->import_legacy_credentials();
		$steps[]  = 'legacy_destinations:' . (int) $imported;

		// 7. Settings defaults for 7.0 keys.
		$plugin->service( 'settings' )->ensure_defaults();
		$steps[] = 'settings_defaults';

		/**
		 * Fires after the 7.0.0 migration completed.
		 *
		 * @param array<int,string> $steps Step log.
		 */
		do_action( 'cpic/upgraded', '7.0.0', $steps );
	}

	/**
	 * Create upload directories.
	 *
	 * @param array<int,string> $steps Step log.
	 * @return void
	 */
	private function ensure_directories( array &$steps ) {
		$dirs = array( 'cpic-temp', 'cpic-generated', 'cpic-previews', 'cpic-artifacts', 'cpic-brands' );

		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return;
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return;
		}

		foreach ( $dirs as $dir ) {
			$path = trailingslashit( $upload['basedir'] ) . $dir;
			if ( ! file_exists( $path ) ) {
				wp_mkdir_p( $path );
			}
			$steps[] = 'dir:' . $dir . ':' . ( is_dir( $path ) && is_writable( $path ) ? 'ok' : 'unwritable' );
		}
	}

	/**
	 * Acquire the migration mutex.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		$now  = time();
		$lock = get_option( self::LOCK_OPTION );

		if ( is_array( $lock ) && ! empty( $lock['time'] ) && ( $now - (int) $lock['time'] ) < self::LOCK_TTL ) {
			return false;
		}

		update_option(
			self::LOCK_OPTION,
			array(
				'time' => $now,
				'pid'  => function_exists( 'getmypid' ) ? getmypid() : 0,
			),
			false
		);

		return true;
	}

	/**
	 * Release the migration mutex.
	 *
	 * @return void
	 */
	private function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Current migration state (diagnostics).
	 *
	 * @return array<string,mixed>
	 */
	public function state() {
		$state = get_option( self::STATE_OPTION, array() );

		return array(
			'installed_version' => (string) get_option( self::VERSION_OPTION, '0' ),
			'target_version'    => CPIC_DB_VERSION,
			'current'           => version_compare( (string) get_option( self::VERSION_OPTION, '0' ), CPIC_DB_VERSION, '>=' ),
			'lock'              => get_option( self::LOCK_OPTION, null ),
			'last_run'          => is_array( $state ) ? $state : array(),
		);
	}

	/**
	 * Verify every table exists and report missing ones.
	 *
	 * @return array{ok:bool,missing:string[],tables:array<string,string>}
	 */
	public function verify_tables() {
		global $wpdb;
		$missing = array();

		foreach ( Schema::tables() as $key => $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				$missing[] = $key;
			}
		}

		return array(
			'ok'      => empty( $missing ),
			'missing' => $missing,
			'tables'  => Schema::tables(),
		);
	}
}
