<?php
/**
 * Rendered artifact persistence.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical rendered images. Publishing always reads from here; nothing else
 * may re-render a different image for the same intent.
 */
final class ArtifactRepository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function key() {
		return 'artifacts';
	}

	/**
	 * Latest ready artifact for a post in a given format.
	 *
	 * @param int    $post_id Post id.
	 * @param string $format  Format key ('' = any).
	 * @return array<string,mixed>|null
	 */
	public function latest_for_post( $post_id, $format = '' ) {
		$where = array(
			'post_id' => (int) $post_id,
			'status'  => 'ready',
		);
		if ( '' !== $format ) {
			$where['format'] = (string) $format;
		}
		return $this->first( $where, 'id DESC' );
	}

	/**
	 * Find an artifact matching a render signature (cache hit).
	 *
	 * @param int    $post_id  Post id.
	 * @param string $signature Signature hash.
	 * @param string $format   Format key.
	 * @return array<string,mixed>|null
	 */
	public function find_by_signature( $post_id, $signature, $format ) {
		return $this->first(
			array(
				'post_id'          => (int) $post_id,
				'render_signature' => (string) $signature,
				'format'           => (string) $format,
				'status'           => 'ready',
			),
			'id DESC'
		);
	}

	/**
	 * All artifacts for a post (history).
	 *
	 * @param int $post_id Post id.
	 * @param int $limit   Limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_post( $post_id, $limit = 20 ) {
		return $this->select( array( 'post_id' => (int) $post_id ), 'id DESC', $limit );
	}

	/**
	 * Total bytes on disk per status.
	 *
	 * @return array<string,int>
	 */
	public function storage_summary() {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total, COALESCE(SUM(bytes),0) AS bytes FROM {$table} GROUP BY status", ARRAY_A );

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ (string) $row['status'] ] = array(
					'count' => (int) $row['total'],
					'bytes' => (int) $row['bytes'],
				);
			}
		}

		return $out;
	}

	/**
	 * Orphan counts: artifacts whose post no longer exists.
	 *
	 * @param int $limit Max rows to inspect.
	 * @return array<int,array<string,mixed>>
	 */
	public function orphans( $limit = 100 ) {
		$out  = array();
		$rows = $this->select( array(), 'id ASC', (int) $limit );

		foreach ( $rows as $row ) {
			if ( ! get_post( (int) $row['post_id'] ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}
}
