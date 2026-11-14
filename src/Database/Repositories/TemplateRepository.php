<?php
/**
 * Template persistence.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Templates + template version history.
 */
final class TemplateRepository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function key() {
		return 'templates';
	}

	/**
	 * Version table name.
	 *
	 * @return string
	 */
	public function versions_table() {
		return \CPIC\Database\Schema::table( 'versions' );
	}

	/**
	 * Find by slug.
	 *
	 * @param string $slug Slug.
	 * @return array<string,mixed>|null
	 */
	public function find_by_slug( $slug ) {
		return $this->first( array( 'slug' => sanitize_title( $slug ) ) );
	}

	/**
	 * All templates with optional filters.
	 *
	 * @param array<string,mixed> $args {
	 *     @type bool   $active     Only active.
	 *     @type bool   $favorites  Only favourites.
	 *     @type string $collection Collection slug.
	 *     @type string $category   Category slug.
	 *     @type string $search     Free text (name/description/tags).
	 *     @type string $status     Status.
	 *     @type int    $limit      Limit.
	 *     @type int    $offset     Offset.
	 *     @type string $order      Order clause.
	 * }
	 * @return array<int,array<string,mixed>>
	 */
	public function all( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'active'     => false,
				'favorites'  => false,
				'collection' => '',
				'category'   => '',
				'status'     => '',
				'search'     => '',
				'brand_id'   => 0,
				'limit'      => 0,
				'offset'     => 0,
				'order'      => 'is_default DESC, id ASC',
			)
		);

		$table  = $this->table();
		$where  = array( '1=1' );
		$params = array();

		if ( $args['active'] ) {
			$where[] = 'is_active = 1';
		}
		if ( $args['favorites'] ) {
			$where[] = 'is_favorite = 1';
		}
		if ( '' !== $args['collection'] ) {
			$where[]  = 'collection = %s';
			$params[] = (string) $args['collection'];
		}
		if ( '' !== $args['category'] ) {
			$where[]  = 'category = %s';
			$params[] = (string) $args['category'];
		}
		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( (int) $args['brand_id'] > 0 ) {
			$where[]  = 'brand_id = %d';
			$params[] = (int) $args['brand_id'];
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR description LIKE %s OR tags LIKE %s OR slug LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where );
		$sql .= ' ORDER BY ' . $this->safe_order( $args['order'] );

		if ( (int) $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $args['limit'];
			$params[] = max( 0, (int) $args['offset'] );
		}

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $params );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark a template default and clear the previous default.
	 *
	 * @param int $id Template id.
	 * @return void
	 */
	public function set_default( $id ) {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_default = 0 WHERE is_default = 1 AND id <> %d", (int) $id ) );
		$this->update( $id, array( 'is_default' => 1 ) );
	}

	/**
	 * Clear the default flag everywhere.
	 *
	 * @return void
	 */
	public function clear_default() {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "UPDATE {$table} SET is_default = 0 WHERE is_default = 1" );
	}

	/**
	 * Bump revision and return the new revision number.
	 *
	 * @param int $id Template id.
	 * @return int
	 */
	public function bump_revision( $id ) {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET revision = revision + 1, updated_at = %s WHERE id = %d", $this->now(), (int) $id ) );

		$revision = $wpdb->get_var( $wpdb->prepare( "SELECT revision FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $revision;
	}

	/**
	 * Save a version snapshot.
	 *
	 * @param int    $template_id Template id.
	 * @param int    $revision    Revision number.
	 * @param string $config      JSON config.
	 * @param string $name        Template name.
	 * @param int    $user_id     Author.
	 * @return int
	 */
	public function save_version( $template_id, $revision, $config, $name = '', $user_id = 0 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$this->versions_table(),
			array(
				'template_id' => (int) $template_id,
				'revision'    => (int) $revision,
				'config'      => (string) $config,
				'name'        => (string) $name,
				'created_by'  => (int) $user_id,
				'created_at'  => $this->now(),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Version history.
	 *
	 * @param int $template_id Template id.
	 * @param int $limit       Limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function versions( $template_id, $limit = 30 ) {
		global $wpdb;

		$table = $this->versions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, template_id, revision, name, created_by, created_at FROM {$table} WHERE template_id = %d ORDER BY revision DESC LIMIT %d", (int) $template_id, (int) $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch one version row (with config).
	 *
	 * @param int $version_id Version id.
	 * @return array<string,mixed>|null
	 */
	public function version( $version_id ) {
		global $wpdb;
		$table = $this->versions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $version_id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Delete version history for a template.
	 *
	 * @param int $template_id Template id.
	 * @return void
	 */
	public function delete_versions( $template_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $this->versions_table(), array( 'template_id' => (int) $template_id ), array( '%d' ) );
	}

	/**
	 * Distinct collections for the library filter UI.
	 *
	 * @return array<int,string>
	 */
	public function collections() {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$values = $wpdb->get_col( "SELECT DISTINCT collection FROM {$table} WHERE collection <> '' ORDER BY collection ASC" );

		return is_array( $values ) ? $values : array();
	}

	/**
	 * Distinct categories for the library filter UI.
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
