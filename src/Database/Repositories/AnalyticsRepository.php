<?php
/**
 * Analytics aggregation queries.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database\Repositories;

use CPIC\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only aggregate queries across artifacts, deliveries and jobs.
 * Only plugin-owned facts are produced here; external platform metrics are
 * read from `metrics` rows that were written after a real API response.
 */
final class AnalyticsRepository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function key() {
		return 'metrics';
	}

	/**
	 * Count artifacts created in a range.
	 *
	 * @param string $from MySQL datetime.
	 * @param string $to   MySQL datetime.
	 * @return int
	 */
	public function artifacts_generated( $from, $to ) {
		global $wpdb;
		$table = Schema::table( 'artifacts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at BETWEEN %s AND %s AND status = 'ready'", $from, $to )
		);
	}

	/**
	 * Artifact storage bytes.
	 *
	 * @return int
	 */
	public function artifact_bytes() {
		global $wpdb;
		$table = Schema::table( 'artifacts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(bytes),0) FROM {$table} WHERE status = 'ready'" );
	}

	/**
	 * Renderer durations recorded in artifact meta.
	 *
	 * @param string $from MySQL datetime.
	 * @param string $to   MySQL datetime.
	 * @return float Average milliseconds.
	 */
	public function average_render_ms( $from, $to ) {
		global $wpdb;
		$table = Schema::table( 'metrics' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(value) FROM {$table} WHERE metric = 'render_ms' AND created_at BETWEEN %s AND %s",
				$from,
				$to
			)
		);

		return (float) $value;
	}

	/**
	 * Record a numeric metric.
	 *
	 * @param string              $metric      Metric key.
	 * @param float               $value       Value.
	 * @param string              $object_type Object type.
	 * @param int                 $object_id   Object id.
	 * @param array<string,mixed> $meta        Meta.
	 * @return int
	 */
	public function record_metric( $metric, $value, $object_type = '', $object_id = 0, array $meta = array() ) {
		return $this->insert(
			array(
				'metric'      => (string) $metric,
				'object_type' => (string) $object_type,
				'object_id'   => (int) $object_id,
				'value'       => (float) $value,
				'meta'        => wp_json_encode( $meta ),
				'created_at'  => $this->now(),
			)
		);
	}

	/**
	 * Delivery failure distribution by error code.
	 *
	 * @param string $from MySQL datetime.
	 * @param string $to   MySQL datetime.
	 * @param int    $limit Limit.
	 * @return array<int,array{code:string,total:int}>
	 */
	public function failure_distribution( $from, $to, $limit = 10 ) {
		global $wpdb;
		$table = Schema::table( 'deliveries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT error_code AS code, COUNT(*) AS total FROM {$table}
				 WHERE status = 'failed' AND created_at BETWEEN %s AND %s
				 GROUP BY error_code ORDER BY total DESC LIMIT %d",
				$from,
				$to,
				(int) $limit
			),
			ARRAY_A
		);

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[] = array(
					'code'  => (string) ( $row['code'] ? $row['code'] : 'unknown' ),
					'total' => (int) $row['total'],
				);
			}
		}

		return $out;
	}

	/**
	 * Daily delivery counts for charting.
	 *
	 * @param string $from MySQL datetime.
	 * @param string $to   MySQL datetime.
	 * @return array<string,array<string,int>>
	 */
	public function daily_deliveries( $from, $to ) {
		global $wpdb;
		$table = Schema::table( 'deliveries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, status, COUNT(*) AS total FROM {$table}
				 WHERE created_at BETWEEN %s AND %s GROUP BY day, status ORDER BY day ASC",
				$from,
				$to
			),
			ARRAY_A
		);

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$day = (string) $row['day'];
				if ( ! isset( $out[ $day ] ) ) {
					$out[ $day ] = array();
				}
				$out[ $day ][ (string) $row['status'] ] = (int) $row['total'];
			}
		}

		return $out;
	}

	/**
	 * Stored external platform metrics (only those actually fetched).
	 *
	 * @param int    $delivery_id Delivery id.
	 * @param string $from        MySQL datetime.
	 * @param string $to          MySQL datetime.
	 * @return array<int,array<string,mixed>>
	 */
	public function external_metrics( $from, $to ) {
		global $wpdb;
		$table = Schema::table( 'metrics' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE object_type = 'delivery' AND created_at BETWEEN %s AND %s ORDER BY id DESC LIMIT 500",
				$from,
				$to
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Retry counts (deliveries that needed more than one attempt).
	 *
	 * @param string $from MySQL datetime.
	 * @param string $to   MySQL datetime.
	 * @return int
	 */
	public function retry_count( $from, $to ) {
		global $wpdb;
		$table = Schema::table( 'deliveries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(attempts),0) FROM {$table} WHERE created_at BETWEEN %s AND %s AND attempts > 1", $from, $to )
		);
	}
}
