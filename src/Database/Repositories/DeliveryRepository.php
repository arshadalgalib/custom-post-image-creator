<?php
/**
 * Delivery run + per-destination delivery persistence.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A delivery *run* groups the per-destination deliveries created for one
 * publishing intent. Each destination succeeds or fails independently, so a
 * Facebook success is never repeated because Telegram failed.
 */
final class DeliveryRepository extends Repository {

	/**
	 * Valid delivery statuses.
	 */
	const STATUSES = array( 'pending', 'queued', 'waiting_for_artifact', 'processing', 'published', 'failed', 'cancelled', 'skipped' );

	/**
	 * {@inheritDoc}
	 */
	protected function key() {
		return 'deliveries';
	}

	/**
	 * Runs table.
	 *
	 * @return string
	 */
	public function runs_table() {
		return \CPIC\Database\Schema::table( 'runs' );
	}

	/* ─── Runs ─────────────────────────────────────────── */

	/**
	 * Create a delivery run.
	 *
	 * @param array<string,mixed> $data Run fields.
	 * @return int Run id.
	 */
	public function create_run( array $data ) {
		global $wpdb;

		$defaults = array(
			'public_id'         => $this->public_id( 'run' ),
			'post_id'           => 0,
			'campaign_id'       => 0,
			'artifact_id'       => 0,
			'source_revision'   => '',
			'template_revision' => 0,
			'caption_snapshot'  => null,
			'status'            => 'pending',
			'scheduled_for'     => null,
			'started_at'        => null,
			'completed_at'      => null,
			'trigger_source'    => 'auto',
			'created_by'        => get_current_user_id(),
			'created_at'        => $this->now(),
		);

		$data = wp_parse_args( $data, $defaults );

		$wpdb->insert( $this->runs_table(), $data, $this->formats_for( $data ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a run.
	 *
	 * @param int $run_id Run id.
	 * @return array<string,mixed>|null
	 */
	public function find_run( $run_id ) {
		global $wpdb;
		$table = $this->runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $run_id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Update a run.
	 *
	 * @param int                 $run_id Run id.
	 * @param array<string,mixed> $data   Fields.
	 * @return bool
	 */
	public function update_run( $run_id, array $data ) {
		global $wpdb;
		$run_id = (int) $run_id;
		if ( $run_id <= 0 || empty( $data ) ) {
			return false;
		}
		$result = $wpdb->update( $this->runs_table(), $data, array( 'id' => $run_id ), $this->formats_for( $data ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $result;
	}

	/**
	 * Runs for a post, newest first.
	 *
	 * @param int $post_id Post id.
	 * @param int $limit   Limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function runs_for_post( $post_id, $limit = 20 ) {
		global $wpdb;
		$table = $this->runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY id DESC LIMIT %d", (int) $post_id, (int) $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Pending runs whose schedule is due.
	 *
	 * @param int $limit Limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function due_runs( $limit = 10 ) {
		global $wpdb;
		$table = $this->runs_table();
		$now   = $this->now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ('pending','queued')
				 AND (scheduled_for IS NULL OR scheduled_for <= %s)
				 ORDER BY scheduled_for ASC, id ASC LIMIT %d",
				$now,
				(int) $limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Runs within a date range (calendar).
	 *
	 * @param string $from  MySQL datetime.
	 * @param string $to    MySQL datetime.
	 * @param array<string,mixed> $filters Optional campaign_id / status / post_id.
	 * @return array<int,array<string,mixed>>
	 */
	public function runs_between( $from, $to, array $filters = array() ) {
		global $wpdb;

		$table  = $this->runs_table();
		$sql    = "SELECT * FROM {$table} WHERE scheduled_for IS NOT NULL AND scheduled_for BETWEEN %s AND %s";
		$params = array( (string) $from, (string) $to );

		if ( ! empty( $filters['campaign_id'] ) ) {
			$sql     .= ' AND campaign_id = %d';
			$params[] = (int) $filters['campaign_id'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$sql     .= ' AND status = %s';
			$params[] = (string) $filters['status'];
		}
		if ( ! empty( $filters['post_id'] ) ) {
			$sql     .= ' AND post_id = %d';
			$params[] = (int) $filters['post_id'];
		}

		$sql .= ' ORDER BY scheduled_for ASC LIMIT 500';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $sql, $params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/* ─── Deliveries ───────────────────────────────────── */

	/**
	 * Create a per-destination delivery. Idempotent through the unique
	 * `idempotency_key` index.
	 *
	 * @param array<string,mixed> $data Delivery fields.
	 * @return int Delivery id (existing id when the key already exists).
	 */
	public function create_delivery( array $data ) {
		global $wpdb;

		$key = isset( $data['idempotency_key'] ) ? (string) $data['idempotency_key'] : '';
		if ( '' !== $key ) {
			$existing = $this->find_by_key( $key );
			if ( $existing ) {
				return (int) $existing['id'];
			}
		}

		$defaults = array(
			'public_id'        => $this->public_id( 'dlv' ),
			'run_id'           => 0,
			'post_id'          => 0,
			'destination_id'   => 0,
			'provider'         => '',
			'idempotency_key'  => $key,
			'artifact_id'      => 0,
			'caption'          => '',
			'status'           => 'pending',
			'attempts'         => 0,
			'max_attempts'     => 5,
			'scheduled_for'    => null,
			'remote_id'        => '',
			'remote_url'       => '',
			'first_comment_id' => '',
			'error_code'       => '',
			'last_error'       => null,
			'started_at'       => null,
			'claimed_at'       => null,
			'completed_at'     => null,
			'metrics'          => null,
			'created_at'       => $this->now(),
		);

		$data = wp_parse_args( $data, $defaults );

		$wpdb->insert( $this->table(), $data, $this->formats_for( $data ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! $wpdb->insert_id && '' !== $key ) {
			$existing = $this->find_by_key( $key );
			return $existing ? (int) $existing['id'] : 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Find by idempotency key.
	 *
	 * @param string $key Key.
	 * @return array<string,mixed>|null
	 */
	public function find_by_key( $key ) {
		return $this->first( array( 'idempotency_key' => (string) $key ) );
	}

	/**
	 * Deliveries for a run.
	 *
	 * @param int $run_id Run id.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_run( $run_id ) {
		return $this->select( array( 'run_id' => (int) $run_id ), 'id ASC' );
	}

	/**
	 * Deliveries for a post.
	 *
	 * @param int $post_id Post id.
	 * @param int $limit   Limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_post( $post_id, $limit = 50 ) {
		return $this->select( array( 'post_id' => (int) $post_id ), 'id DESC', (int) $limit );
	}

	/**
	 * Atomically claim a delivery for processing.
	 *
	 * @param int $delivery_id Delivery id.
	 * @return bool True when this process owns the delivery.
	 */
	public function claim( $delivery_id ) {
		global $wpdb;

		$table = $this->table();
		$now   = $this->now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'processing', claimed_at = %s, started_at = IFNULL(started_at, %s)
				 WHERE id = %d
				   AND status IN ('pending','queued','waiting_for_artifact','failed')
				   AND (claimed_at IS NULL OR claimed_at < %s)",
				$now,
				$now,
				(int) $delivery_id,
				gmdate( 'Y-m-d H:i:s', time() - 600 )
			)
		);

		return (bool) $affected;
	}

	/**
	 * Mark published.
	 *
	 * @param int                 $delivery_id Delivery id.
	 * @param array<string,mixed> $data        remote_id, remote_url, first_comment_id, metrics.
	 * @return bool
	 */
	public function mark_published( $delivery_id, array $data = array() ) {
		$data['status']       = 'published';
		$data['completed_at'] = $this->now();
		$data['last_error']   = null;
		$data['error_code']   = '';

		if ( isset( $data['metrics'] ) && is_array( $data['metrics'] ) ) {
			$data['metrics'] = wp_json_encode( $data['metrics'] );
		}

		return $this->update( (int) $delivery_id, $data );
	}

	/**
	 * Mark failed with a classified error.
	 *
	 * @param int    $delivery_id Delivery id.
	 * @param string $code        Error code.
	 * @param string $message     Error message.
	 * @param bool   $terminal    Final failure.
	 * @param string $retry_at    Optional retry timestamp.
	 * @return bool
	 */
	public function mark_failed( $delivery_id, $code, $message, $terminal = false, $retry_at = null ) {
		global $wpdb;

		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", (int) $delivery_id ) );

		$row = $this->find( $delivery_id );

		$data = array(
			'error_code'  => (string) $code,
			'last_error'  => (string) $message,
			'claimed_at'  => null,
			'status'      => $terminal ? 'failed' : 'queued',
			'completed_at' => $terminal ? $this->now() : null,
		);

		if ( ! $terminal && $retry_at ) {
			$data['scheduled_for'] = $retry_at;
		}

		if ( ! $terminal && $row && (int) $row['attempts'] >= (int) $row['max_attempts'] ) {
			$data['status']       = 'failed';
			$data['completed_at'] = $this->now();
		}

		return $this->update( (int) $delivery_id, $data );
	}

	/**
	 * Reset a failed delivery for a manual retry.
	 *
	 * @param int $delivery_id Delivery id.
	 * @return bool
	 */
	public function requeue( $delivery_id ) {
		return $this->update(
			(int) $delivery_id,
			array(
				'status'       => 'queued',
				'claimed_at'   => null,
				'completed_at' => null,
				'error_code'   => '',
				'last_error'   => null,
				'scheduled_for' => $this->now(),
			)
		);
	}

	/**
	 * Recover deliveries stuck in processing.
	 *
	 * @param int $minutes Stale threshold in minutes.
	 * @return int Number recovered.
	 */
	public function recover_stale( $minutes = 10 ) {
		global $wpdb;

		$table  = $this->table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $minutes ) * MINUTE_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'queued', claimed_at = NULL
				 WHERE status = 'processing' AND (claimed_at IS NULL OR claimed_at < %s)",
				$cutoff
			)
		);
	}

	/**
	 * Due queued deliveries (per destination, so channels retry separately).
	 *
	 * @param int $limit Limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function due( $limit = 10 ) {
		global $wpdb;

		$table = $this->table();
		$now   = $this->now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status IN ('pending','queued','waiting_for_artifact','failed')
				   AND attempts < max_attempts
				   AND (scheduled_for IS NULL OR scheduled_for <= %s)
				 ORDER BY scheduled_for ASC, id ASC
				 LIMIT %d",
				$now,
				(int) $limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Status counts, optionally filtered.
	 *
	 * @param array<string,mixed> $where Optional conditions.
	 * @return array<string,int>
	 */
	public function status_counts( array $where = array() ) {
		global $wpdb;

		$table = $this->table();
		$built = $this->build_where( $where );
		$sql   = "SELECT status, COUNT(*) AS total FROM {$table}";
		if ( $built['sql'] ) {
			$sql .= ' WHERE ' . $built['sql'];
		}
		$sql .= ' GROUP BY status';

		if ( ! empty( $built['args'] ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $built['args'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out = array_fill_keys( self::STATUSES, 0 );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ (string) $row['status'] ] = (int) $row['total'];
			}
		}

		return $out;
	}

	/**
	 * Per-provider counts within a date range.
	 *
	 * @param string $from MySQL datetime.
	 * @param string $to   MySQL datetime.
	 * @return array<int,array<string,mixed>>
	 */
	public function provider_breakdown( $from, $to ) {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT provider, status, COUNT(*) AS total FROM {$table}
				 WHERE created_at BETWEEN %s AND %s
				 GROUP BY provider, status",
				(string) $from,
				(string) $to
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Average seconds between created_at and completed_at for published rows.
	 *
	 * @param string $from MySQL datetime.
	 * @param string $to   MySQL datetime.
	 * @return float
	 */
	public function average_duration( $from, $to ) {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) FROM {$table}
				 WHERE status = 'published' AND completed_at IS NOT NULL AND created_at BETWEEN %s AND %s",
				(string) $from,
				(string) $to
			)
		);

		return (float) $value;
	}

	/**
	 * Generate a public identifier.
	 *
	 * @param string $prefix Prefix.
	 * @return string
	 */
	public function public_id( $prefix ) {
		return $prefix . '_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 20 );
	}
}
