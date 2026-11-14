<?php
/**
 * Campaign persistence.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaigns and their post assignments.
 */
final class CampaignRepository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function key() {
		return 'campaigns';
	}

	/**
	 * Assignment table.
	 *
	 * @return string
	 */
	public function posts_table() {
		return \CPIC\Database\Schema::table( 'campaign_posts' );
	}

	/**
	 * Campaigns with a status filter.
	 *
	 * @param string $status Status ('' = all).
	 * @param int    $limit  Limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function by_status( $status = '', $limit = 100 ) {
		$where = array();
		if ( '' !== $status ) {
			$where['status'] = (string) $status;
		}
		return $this->select( $where, 'id DESC', (int) $limit );
	}

	/**
	 * Active campaigns covering a timestamp.
	 *
	 * @param string $timestamp MySQL datetime.
	 * @return array<int,array<string,mixed>>
	 */
	public function active_at( $timestamp ) {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status = 'active'
				   AND (start_at IS NULL OR start_at = '0000-00-00 00:00:00' OR start_at <= %s)
				   AND (end_at IS NULL OR end_at = '0000-00-00 00:00:00' OR end_at >= %s)
				 ORDER BY id ASC",
				(string) $timestamp,
				(string) $timestamp
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Assign a post to a campaign (idempotent).
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $post_id     Post id.
	 * @param int $template_id Optional template override.
	 * @return int Assignment id.
	 */
	public function assign_post( $campaign_id, $post_id, $template_id = 0 ) {
		global $wpdb;

		$table    = $this->posts_table();
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE campaign_id = %d AND post_id = %d", (int) $campaign_id, (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		if ( $existing ) {
			$wpdb->update( $table, array( 'template_id' => (int) $template_id ), array( 'id' => (int) $existing ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $existing;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'campaign_id' => (int) $campaign_id,
				'post_id'     => (int) $post_id,
				'template_id' => (int) $template_id,
				'status'      => 'assigned',
				'created_at'  => $this->now(),
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove an assignment.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $post_id     Post id.
	 * @return bool
	 */
	public function unassign_post( $campaign_id, $post_id ) {
		global $wpdb;
		return (bool) $wpdb->delete( $this->posts_table(), array( 'campaign_id' => (int) $campaign_id, 'post_id' => (int) $post_id ), array( '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Posts assigned to a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<int,array<string,mixed>>
	 */
	public function posts( $campaign_id ) {
		global $wpdb;
		$table = $this->posts_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d ORDER BY id DESC", (int) $campaign_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Campaign ids containing a post.
	 *
	 * @param int $post_id Post id.
	 * @return array<int,int>
	 */
	public function campaigns_for_post( $post_id ) {
		global $wpdb;
		$table = $this->posts_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT campaign_id FROM {$table} WHERE post_id = %d", (int) $post_id ) );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Decoded campaign payload.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	public function hydrate( array $row ) {
		$row['destinations'] = $this->json_decode( isset( $row['destinations'] ) ? $row['destinations'] : '', array() );
		$row['templates']    = $this->json_decode( isset( $row['templates'] ) ? $row['templates'] : '', array() );
		$row['tags']         = $this->json_decode( isset( $row['tags'] ) ? $row['tags'] : '', array() );
		$row['id']           = (int) $row['id'];
		return $row;
	}
}
