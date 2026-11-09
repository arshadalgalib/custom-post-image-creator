<?php
/**
 * Structured logging with redaction.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Support;

use CPIC\Core\EventDispatcher;
use CPIC\Database\Schema;
use CPIC\Security\Encryption;
use CPIC\Security\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes to the plugin log table (and optionally error_log in debug mode).
 * Credential-like keys are redacted before they are persisted.
 */
final class Logger {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Events.
	 *
	 * @var EventDispatcher
	 */
	private $events;

	/**
	 * Keys that must never be persisted.
	 *
	 * @var array<int,string>
	 */
	private static $redact_keys = array(
		'token',
		'access_token',
		'page_token',
		'bot_token',
		'app_secret',
		'api_key',
		'secret',
		'password',
		'authorization',
		'credentials',
	);

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings.
	 * @param EventDispatcher $events   Events.
	 */
	public function __construct( Settings $settings, EventDispatcher $events ) {
		$this->settings = $settings;
		$this->events   = $events;
	}

	/**
	 * Log an entry.
	 *
	 * @param string              $type    Channel (queue|render|facebook|telegram|ai|...).
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @param string              $level   debug|info|warning|error.
	 * @return int Inserted id.
	 */
	public function log( $type, $message, array $context = array(), $level = 'info' ) {
		global $wpdb;

		$table = Schema::table( 'logs' );

		$context = $this->redact( $context );
		$post_id = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;
		$job_id  = isset( $context['job_id'] ) ? (int) $context['job_id'] : 0;

		$data = array(
			'log_type'   => substr( sanitize_key( $type ), 0, 50 ),
			'level'      => in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ? $level : 'info',
			'message'    => sanitize_textarea_field( (string) $message ),
			'context'    => wp_json_encode( $context ),
			'post_id'    => $post_id,
			'job_id'     => $job_id,
			'created_at' => current_time( 'mysql' ),
		);

		$wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $this->settings->is_enabled( 'cpic_debug' ) && function_exists( 'error_log' ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			error_log( sprintf( '[CPIC:%s:%s] %s %s', $level, $type, $message, wp_json_encode( $context ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		$this->events->dispatch(
			'log.created',
			array(
				'type'    => $type,
				'level'   => $level,
				'message' => $message,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Convenience wrappers.
	 *
	 * @param string              $type    Type.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return int
	 */
	public function info( $type, $message, array $context = array() ) {
		return $this->log( $type, $message, $context, 'info' );
	}

	/**
	 * Warning level.
	 *
	 * @param string              $type    Type.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return int
	 */
	public function warning( $type, $message, array $context = array() ) {
		return $this->log( $type, $message, $context, 'warning' );
	}

	/**
	 * Error level.
	 *
	 * @param string              $type    Type.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return int
	 */
	public function error( $type, $message, array $context = array() ) {
		return $this->log( $type, $message, $context, 'error' );
	}

	/**
	 * Debug level.
	 *
	 * @param string              $type    Type.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return int
	 */
	public function debug( $type, $message, array $context = array() ) {
		return $this->log( $type, $message, $context, 'debug' );
	}

	/**
	 * Recursively redact sensitive values.
	 *
	 * @param array<string,mixed> $context Context.
	 * @return array<string,mixed>
	 */
	public function redact( array $context ) {
		foreach ( $context as $key => $value ) {
			$lookup = strtolower( is_string( $key ) ? $key : (string) $key );

			$sensitive = false;
			foreach ( self::$redact_keys as $needle ) {
				if ( false !== strpos( $lookup, $needle ) ) {
					$sensitive = true;
					break;
				}
			}

			if ( $sensitive ) {
				$context[ $key ] = is_string( $value ) ? Encryption::redact( $value ) : '••••••';
				continue;
			}

			if ( is_array( $value ) ) {
				$context[ $key ] = $this->redact( $value );
				continue;
			}

			if ( is_string( $value ) && strlen( $value ) > 2000 ) {
				$context[ $key ] = substr( $value, 0, 2000 ) . '…';
			}
		}

		return $context;
	}

	/**
	 * Fetch recent log rows.
	 *
	 * @param array<string,mixed> $args Filters: limit, level, type, post_id.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'limit'   => 50,
				'level'   => '',
				'type'    => '',
				'post_id' => 0,
				'after'   => '',
			)
		);

		$table  = Schema::table( 'logs' );
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['level'] ) {
			$where[]  = 'level = %s';
			$params[] = (string) $args['level'];
		}
		if ( '' !== $args['type'] ) {
			$where[]  = 'log_type = %s';
			$params[] = (string) $args['type'];
		}
		if ( (int) $args['post_id'] > 0 ) {
			$where[]  = 'post_id = %d';
			$params[] = (int) $args['post_id'];
		}
		if ( '' !== $args['after'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['after'];
		}

		$params[] = max( 1, min( 500, (int) $args['limit'] ) );

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $sql, $params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete logs older than N days.
	 *
	 * @param int $days Retention window.
	 * @return int Deleted rows.
	 */
	public function prune( $days = 30 ) {
		global $wpdb;

		$table = Schema::table( 'logs' );
		$cut   = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cut ) );
	}

	/**
	 * Delete everything (uninstall with data removal enabled).
	 *
	 * @return void
	 */
	public function purge() {
		global $wpdb;
		$table = Schema::table( 'logs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
