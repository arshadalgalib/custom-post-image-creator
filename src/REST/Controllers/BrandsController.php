<?php
/**
 * Brand kits REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/brands`
 */
final class BrandsController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/brands';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$manage = $this->requires( 'cpic_manage_brands' );

		$this->add(
			'',
			array(
				'GET'  => array( 'callback' => array( $this, 'index' ) ),
				'POST' => array( 'callback' => array( $this, 'create' ), 'permission' => $manage ),
			)
		);

		$this->add(
			'/(?P<id>\d+)',
			array(
				'GET' => array( 'callback' => array( $this, 'show' ) ),
				'PUT' => array( 'callback' => array( $this, 'update' ), 'permission' => $manage ),
			)
		);

		$this->add(
			'/(?P<id>\d+)/default',
			array( 'POST' => array( 'callback' => array( $this, 'make_default' ), 'permission' => $manage ) )
		);
	}

	/**
	 * List brands.
	 *
	 * @return \WP_REST_Response
	 */
	public function index() {
		$repo = $this->container->get( 'brands' );

		$out = array();
		foreach ( $repo->select( array(), 'is_default DESC, name ASC' ) as $row ) {
			$out[] = $repo->hydrate( $row );
		}

		return $this->ok( array( 'brands' => $out ) );
	}

	/**
	 * One brand.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function show( $request ) {
		$row = $this->container->get( 'brands' )->find( $this->param_int( $request, 'id' ) );

		if ( ! $row ) {
			return $this->fail( new \WP_Error( 'cpic_brand_missing', __( 'Brand kit not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		return $this->ok( $this->container->get( 'brands' )->hydrate( $row ) );
	}

	/**
	 * Create a brand.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create( $request ) {
		$name = $this->param( $request, 'name' );

		if ( '' === $name ) {
			return $this->fail( new \WP_Error( 'cpic_brand_name', __( 'A brand name is required.', 'custom-post-image-creator' ), array( 'status' => 400 ) ) );
		}

		$id = $this->container->get( 'brands' )->create( $this->payload( $request, array( 'name' => $name ) ) );

		if ( ! $id ) {
			return $this->fail( new \WP_Error( 'cpic_brand_save', __( 'The brand kit could not be saved.', 'custom-post-image-creator' ), array( 'status' => 500 ) ) );
		}

		return $this->ok( $this->container->get( 'brands' )->hydrate( $this->container->get( 'brands' )->find( $id ) ), 201 );
	}

	/**
	 * Update a brand.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update( $request ) {
		$id = $this->param_int( $request, 'id' );

		if ( ! $this->container->get( 'brands' )->find( $id ) ) {
			return $this->fail( new \WP_Error( 'cpic_brand_missing', __( 'Brand kit not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		$this->container->get( 'brands' )->update( $id, $this->payload( $request, array() ) );

		return $this->ok( $this->container->get( 'brands' )->hydrate( $this->container->get( 'brands' )->find( $id ) ) );
	}

	/**
	 * Set the default brand.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function make_default( $request ) {
		$repo = $this->container->get( 'brands' );
		$id   = $this->param_int( $request, 'id' );

		if ( ! $repo->find( $id ) ) {
			return $this->fail( new \WP_Error( 'cpic_brand_missing', __( 'Brand kit not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		$repo->clear_default();
		$repo->update( $id, array( 'is_default' => 1 ) );
		$this->container->get( 'settings' )->set( 'cpic_default_brand_id', $id );

		return $this->ok( array( 'default_brand_id' => $id ) );
	}

	/**
	 * Request → brand fields.
	 *
	 * @param \WP_REST_Request    $request Request.
	 * @param array<string,mixed> $base    Base fields.
	 * @return array<string,mixed>
	 */
	private function payload( $request, array $base ) {
		$fields = array( 'name', 'logo_primary', 'logo_secondary', 'logo_light', 'logo_dark', 'primary_color', 'secondary_color', 'accent_color', 'heading_font', 'body_font', 'website_url', 'footer_text' );

		foreach ( $fields as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$base[ $key ] = $request->get_param( $key );
			}
		}

		if ( null !== $request->get_param( 'watermark_asset_id' ) ) {
			$base['watermark_asset_id'] = $this->param_int( $request, 'watermark_asset_id' );
		}

		foreach ( array( 'background_palette', 'socials', 'typography' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$value = $request->get_param( $key );
				$base[ $key ] = wp_json_encode( is_array( $value ) ? $value : array() );
			}
		}

		return $base;
	}
}
