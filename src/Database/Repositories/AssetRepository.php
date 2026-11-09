<?php
/**
 * Media asset persistence.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset records pointing at WordPress attachments (source of truth for files)
 * plus plugin-level metadata (category, tags, checksum, usage).
 */
final class AssetRepository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function key() {
		return 'assets';
	}

	/**
	 * Find an asset for a WordPress attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string,mixed>|null
	 */
	public function find_by_attachment( $attachment_id ) {
		return $this->first( array( 'attachment_id' => (int) $attachment_id ) );
	}

	/**
	 * Find by checksum (dedupe uploads).
	 *
	 * @param string $checksum SHA-256.
	 * @return array<string,mixed>|null
	 */
	public function find_by_checksum( $checksum ) {
		if ( '' === (string) $checksum ) {
			return null;
		}
		return $this->first( array( 'checksum' => (string) $checksum ) );
	}

	/**
	 * Search assets.
	 *
	 * @param array<string,mixed> $args {
	 *     @type string $search   Free text.
	 *     @type string $kind     image|logo|font|svg|background.
	 *     @type string $category Category slug.
	 *     @type int    $limit    Limit.
	 *     @type int    $offset   Offset.
	 * }
	 * @return array<int,array<string,mixed>>
	 */
	public function search( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'kind'     => '',
				'category' => '',
				'limit'    => 60,
				'offset'   => 0,
			)
		);

		$table  = $this->table();
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['kind'] ) {
			$where[]  = 'kind = %s';
			$params[] = (string) $args['kind'];
		}
		if ( '' !== $args['category'] ) {
			$where[]  = 'category = %s';
			$params[] = (string) $args['category'];
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(title LIKE %s OR alt LIKE %s OR tags LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[] = max( 1, (int) $args['limit'] );
		$params[] = max( 0, (int) $args['offset'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $sql, $params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Increment the usage counter (assets referenced by templates).
	 *
	 * @param string $url Asset URL.
	 * @param int    $by  Delta.
	 * @return void
	 */
	public function bump_usage( $url, $by = 1 ) {
		global $wpdb;

		$url = (string) $url;
		if ( '' === $url ) {
			return;
		}

		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET usage_count = GREATEST(0, usage_count + %d) WHERE url = %s", (int) $by, $url ) );
	}

	/**
	 * Category list for filters.
	 *
	 * @return array<int,string>
	 */
	public function categories() {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$values = $wpdb->get_col( "SELECT DISTINCT category FROM {$table} WHERE category <> '' ORDER BY category ASC" );
		return is_array( $values ) ? $values : array();
	}
}
