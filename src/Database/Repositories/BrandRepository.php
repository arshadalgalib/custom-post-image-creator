<?php
/**
 * Brand kit persistence.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brand kits ("multiple brands" support).
 */
final class BrandRepository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function key() {
		return 'brands';
	}

	/**
	 * Ensure a default brand exists and return its id.
	 *
	 * @return int
	 */
	public function ensure_default() {
		$existing = $this->first( array( 'is_default' => 1 ) );
		if ( $existing ) {
			return (int) $existing['id'];
		}

		$rows = $this->select( array(), 'id ASC', 1 );
		if ( $rows ) {
			$this->update( (int) $rows[0]['id'], array( 'is_default' => 1 ) );
			return (int) $rows[0]['id'];
		}

		$site = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : 'Default Brand';

		return $this->create(
			array(
				'name'       => $site ? $site : 'Default Brand',
				'slug'       => 'default',
				'is_default' => 1,
			)
		);
	}

	/**
	 * Create a brand.
	 *
	 * @param array<string,mixed> $data Fields.
	 * @return int
	 */
	public function create( array $data ) {
		$defaults = array(
			'name'              => '',
			'slug'              => '',
			'is_default'        => 0,
			'logo_primary'      => '',
			'logo_secondary'    => '',
			'logo_light'        => '',
			'logo_dark'         => '',
			'primary_color'     => '#0F172A',
			'secondary_color'   => '#1E293B',
			'accent_color'      => '#E11D48',
			'background_palette' => wp_json_encode( array( '#0F172A', '#1E293B', '#F8FAFC' ) ),
			'typography'        => wp_json_encode( array( 'heading' => 'Noto Sans Bengali', 'body' => 'Hind Siliguri' ) ),
			'heading_font'      => 'Noto Sans Bengali',
			'body_font'         => 'Hind Siliguri',
			'watermark_asset_id' => 0,
			'website_url'       => '',
			'socials'           => wp_json_encode( array() ),
			'footer_text'       => '',
			'created_at'        => $this->now(),
			'updated_at'        => $this->now(),
		);

		$data = wp_parse_args( $data, $defaults );

		if ( '' === $data['slug'] ) {
			$data['slug'] = sanitize_title( $data['name'] );
		}
		$data['slug'] = $this->unique_slug( $data['slug'] );

		return $this->insert( $data );
	}

	/**
	 * Ensure a slug is unique.
	 *
	 * @param string $slug Base slug.
	 * @return string
	 */
	private function unique_slug( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			$slug = 'brand';
		}

		$candidate = $slug;
		$i         = 2;
		while ( $this->find_by_slug( $candidate ) ) {
			$candidate = $slug . '-' . $i;
			$i++;
		}

		return $candidate;
	}

	/**
	 * Clear the default flag from every brand.
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
	 * Find by slug.
	 *
	 * @param string $slug Slug.
	 * @return array<string,mixed>|null
	 */
	public function find_by_slug( $slug ) {
		return $this->first( array( 'slug' => sanitize_title( $slug ) ) );
	}

	/**
	 * Default brand row.
	 *
	 * @return array<string,mixed>|null
	 */
	public function default_brand() {
		$row = $this->first( array( 'is_default' => 1 ) );
		if ( $row ) {
			return $row;
		}
		$id = $this->ensure_default();
		return $id ? $this->find( $id ) : null;
	}

	/**
	 * Decoded brand payload (palettes/socials as arrays).
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	public function hydrate( array $row ) {
		$row['background_palette'] = $this->json_decode( isset( $row['background_palette'] ) ? $row['background_palette'] : '', array() );
		$row['typography']         = $this->json_decode( isset( $row['typography'] ) ? $row['typography'] : '', array() );
		$row['socials']            = $this->json_decode( isset( $row['socials'] ) ? $row['socials'] : '', array() );
		$row['id']                 = (int) $row['id'];
		return $row;
	}
}
