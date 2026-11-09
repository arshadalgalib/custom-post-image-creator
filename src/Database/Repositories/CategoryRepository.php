<?php
/**
 * Category → template routing persistence.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the 6.x `cpic_category_mappings` table and adds per-category
 * destination routing.
 */
final class CategoryRepository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function key() {
		return 'mappings';
	}

	/**
	 * All mappings ordered by priority.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all() {
		return $this->select( array(), 'priority ASC, id ASC' );
	}

	/**
	 * Mapping for a term.
	 *
	 * @param int $term_id Term id.
	 * @return array<string,mixed>|null
	 */
	public function for_term( $term_id ) {
		return $this->first( array( 'term_id' => (int) $term_id ) );
	}

	/**
	 * Create or update a mapping.
	 *
	 * @param int               $term_id     Term id.
	 * @param int               $template_id Template id.
	 * @param int               $priority    Priority (lower wins).
	 * @param array<int,int>    $destinations Destination ids.
	 * @return bool
	 */
	public function save_mapping( $term_id, $template_id, $priority = 1, array $destinations = array() ) {
		global $wpdb;

		$existing = $this->for_term( $term_id );
		$payload  = array(
			'term_id'         => (int) $term_id,
			'template_id'     => (int) $template_id,
			'priority'        => (int) $priority,
			'destination_ids' => implode( ',', array_map( 'intval', $destinations ) ),
		);

		if ( $existing ) {
			return $this->update( (int) $existing['id'], $payload );
		}

		$payload['created_at'] = $this->now();
		return $this->insert( $payload ) > 0;
	}

	/**
	 * Remove mappings pointing at a template.
	 *
	 * @param int $template_id Template id.
	 * @return void
	 */
	public function remove_for_template( $template_id ) {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'template_id' => (int) $template_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Destination ids configured for a term (empty = all enabled).
	 *
	 * @param int $term_id Term id.
	 * @return array<int,int>
	 */
	public function destinations_for_term( $term_id ) {
		$row = $this->for_term( $term_id );
		if ( ! $row || empty( $row['destination_ids'] ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'intval', explode( ',', (string) $row['destination_ids'] ) ) ) );
	}
}
