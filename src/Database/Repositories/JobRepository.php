<?php
/**
 * Background job persistence with atomic claiming.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jobs are the durable unit of work for the automation engine. Claiming is a
 * single UPDATE ... WHERE so two workers can never own the same row.
 */
final class JobRepository extends Repository {

	/**
	 * Allowed job statuses.
	 */
	const STATUSES = array( 'pending', 'claimed', 'processing', 'completed', 'failed', 'cancelled', 'waiting' );

	/**
	 * {@inheritDoc}
	 */
	protected function key() {
		return 'jobs';
	}

	/**
	 * Find a job by dedupe key.
	 *
	 * @param string $dedupe_key Key.
	 * @return array<string,mixed>|null
	 */
	public function find_by_dedupe( $dedupe_key ) {
		if ( '' === (string) $dedupe_key ) {
			return null;
		}
		return $this->first( array( 'dedupe_key' => (string) $dedupe_key ), 'id ASC' );
	}

	/**
	 * Enqueue a job, deduping on `dedupe_key` while the job is still live.
	 *
	 * @param array<string,mixed> $data Job fields.
	 * @return array{id:int,created:bool,row:array|null}
	 */
	public function enqueue( array $data ) {
		$dedupe = isset( $data['dedupe_key'] ) ? (string) $data['dedupe_key'] : '';

		if ( '' !== $dedupe ) {
			$existing = $this->find_by_dedupe( $dedupe );
			if ( $existing && in_array( (string) $existing['status'], array( 'pending', 'claimed', 'processing', 'waiting' ), true ) ) {
				return array(
					'id'      => (int) $existing['id'],
					'created' => false,
					'row'     => $existing,
				);
			}
		}

		$defaults = array(
			'post_id'      => 0,
			'job_key'      => '',
			'dedupe_key'   => $dedupe,
			'type'         => 'publish',
			'status'       => 'pending',
			'priority'     => 5,
			'payload'      => wp_json_encode( array() ),
			'template_id'  => 0,
			'attempts'     => 0,
			'max_retries'  => 5,
			'available_at' => $this->now(),
			'created_at'   => $this->now(),
			'updated_at'   => $this->now(),
		);

		$data = wp_parse_args( $data, $defaults );

		if ( '' === $data['job_key'] ) {
			$data['job_key'] = 'job-' . substr( md5( $dedupe . '|' . $data['type'] . '|' . microtime( true ) ), 0, 16 );
		}

		$id = $this->insert( $data );

		return array(
			'id'      => $id,
			'created' => $id > 0,
			'row'     => $id ? $this->find( $id ) : null,
		);
	}

	/**
	 * Atomically claim up to N due jobs for this worker.
	 *
	 * @param string $worker_id Unique worker id.
	 * @param int    $limit     Max jobs.
	 * @param string $token     Lock token stored with the row.
	 * @return array<int,array<string,mixed>> Claimed jobs.
	 */
	public function claim_batch( $worker_id, $limit, $token ) {
		global $wpdb;

		$table = $this->table();
		$now   = $this->now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE status IN ('pending','waiting')
				   AND attempts <= max_retries
				   AND (available_at IS NULL OR available_at <= %s)
				 ORDER BY priority ASC, available_at ASC, id ASC
				 LIMIT %d",
				$now,
				(int) $limit
			)
		);

		if ( empty( $candidates ) ) {
			return array();
		}

		$claimed = array();

		foreach ( $candidates as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET status = 'claimed', locked_at = %s, locked_by = %s, lock_token = %s, updated_at = %s
					 WHERE id = %d AND status IN ('pending','waiting')",
					$now,
					(string) $worker_id,
					(string) $token,
					$now,
					(int) $id
				)
			);

			if ( $affected ) {
				$claimed[] = $this->find( (int) $id );
			}
		}

		return array_values( array_filter( $claimed ) );
	}

	/**
	 * Mark a claimed job as processing (owned by the token).
	 *
	 * @param int    $job_id Job id.
	 * @param string $token  Lock token.
	 * @return bool
	 */
	public function start( $job_id, $token ) {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'processing', updated_at = %s
				 WHERE id = %d AND lock_token = %s AND status = 'claimed'",
				$this->now(),
				(int) $job_id,
				(string) $token
			)
		);

		return (bool) $affected;
	}

	/**
	 * Complete a job.
	 *
	 * @param int                 $job_id Job id.
	 * @param array<string,mixed> $data   Optional extra fields.
	 * @return bool
	 */
	public function complete( $job_id, array $data = array() ) {
		$data['status']       = 'completed';
		$data['locked_at']    = null;
		$data['lock_token']   = '';
		$data['last_error']   = null;
		$data['updated_at']   = $this->now();
		$data['available_at'] = null;

		return $this->update( (int) $job_id, $data );
	}

	/**
	 * Fail a job (terminal) or schedule a retry.
	 *
	 * @param int    $job_id      Job id.
	 * @param string $code        Error code.
	 * @param string $message     Error message.
	 * @param bool   $terminal    Terminal failure.
	 * @param string $available_at Next attempt time.
	 * @return bool
	 */
	public function fail( $job_id, $code, $message, $terminal, $available_at = null ) {
		global $wpdb;

		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", (int) $job_id ) );

		$row = $this->find( $job_id );

		$status = $terminal ? 'failed' : 'pending';

		if ( ! $terminal && $row && (int) $row['attempts'] >= (int) $row['max_retries'] ) {
			$status = 'failed';
		}

		return $this->update(
			(int) $job_id,
			array(
				'status'          => $status,
				'last_error'      => (string) $message,
				'last_error_code' => (string) $code,
				'locked_at'       => null,
				'locked_by'       => '',
				'lock_token'      => '',
				'available_at'    => $terminal ? null : ( $available_at ? $available_at : $this->now() ),
				'updated_at'      => $this->now(),
			)
		);
	}

	/**
	 * Release a claim without counting an attempt (worker shutdown).
	 *
	 * @param int    $job_id Job id.
	 * @param string $token  Token.
	 * @return bool
	 */
	public function release( $job_id, $token ) {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', locked_at = NULL, locked_by = '', lock_token = '', updated_at = %s
				 WHERE id = %d AND lock_token = %s",
				$this->now(),
				(int) $job_id,
				(string) $token
			)
		);

		return (bool) $affected;
	}

	/**
	 * Recover jobs whose worker died mid-flight.
	 *
	 * @param int $minutes Stale threshold.
	 * @return int Recovered rows.
	 */
	public function recover_stale( $minutes = 15 ) {
		global $wpdb;

		$table  = $this->table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $minutes ) * MINUTE_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'pending', locked_at = NULL, locked_by = '', lock_token = '', available_at = %s
				 WHERE status IN ('claimed','processing') AND (locked_at IS NULL OR locked_at < %s)",
				$this->now(),
				$cutoff
			)
		);
	}

	/**
	 * Cancel a job.
	 *
	 * @param int $job_id Job id.
	 * @return bool
	 */
	public function cancel( $job_id ) {
		return $this->update(
			(int) $job_id,
			array(
				'status'       => 'cancelled',
				'locked_at'    => null,
				'lock_token'   => '',
				'updated_at'   => $this->now(),
				'available_at' => null,
			)
		);
	}

	/**
	 * Retry a failed/cancelled job immediately.
	 *
	 * @param int $job_id Job id.
	 * @return bool
	 */
	public function retry( $job_id ) {
		return $this->update(
			(int) $job_id,
			array(
				'status'       => 'pending',
				'attempts'     => 0,
				'last_error'   => null,
				'last_error_code' => '',
				'available_at' => $this->now(),
				'updated_at'   => $this->now(),
			)
		);
	}

	/**
	 * Paginated job history.
	 *
	 * @param array<string,mixed> $args Filters: status, type, post_id, page, per.
	 * @return array{items:array,total:int,page:int,per_page:int,pages:int}
	 */
	public function history( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'  => '',
				'type'    => '',
				'post_id' => 0,
				'page'    => 1,
				'per'     => 20,
			)
		);

		$table  = $this->table();
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( '' !== $args['type'] ) {
			$where[]  = 'type = %s';
			$params[] = (string) $args['type'];
		}
		if ( (int) $args['post_id'] > 0 ) {
			$where[]  = 'post_id = %d';
			$params[] = (int) $args['post_id'];
		}

		$clause = implode( ' AND ', $where );
		$page   = max( 1, (int) $args['page'] );
		$per    = min( 200, max( 1, (int) $args['per'] ) );
		$offset = ( $page - 1 ) * $per;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$list_sql  = "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d";

		$count_params = $params;
		$list_params  = array_merge( $params, array( $per, $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $count_params ? $wpdb->prepare( $count_sql, $count_params ) : $count_sql );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

		return array(
			'items'    => is_array( $rows ) ? $rows : array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per,
			'pages'    => (int) ceil( $total / $per ),
		);
	}

	/**
	 * Status counts.
	 *
	 * @return array<string,int>
	 */
	public function status_counts() {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		$out = array_fill_keys( self::STATUSES, 0 );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ (string) $row['status'] ] = (int) $row['total'];
			}
		}

		return $out;
	}

	/**
	 * Oldest pending job timestamp (queue lag diagnostics).
	 *
	 * @return string|null
	 */
	public function oldest_pending_at() {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$value = $wpdb->get_var( "SELECT MIN(available_at) FROM {$table} WHERE status IN ('pending','waiting')" );

		return $value ? (string) $value : null;
	}
}
