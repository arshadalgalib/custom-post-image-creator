<?php
/**
 * Artifacts (rendered images) REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/artifacts`
 */
final class ArtifactsController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/artifacts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$this->add(
			'',
			array(
				'GET'  => array( 'callback' => array( $this, 'index' ) ),
				'POST' => array(
					'callback'   => array( $this, 'store' ),
					'permission' => $this->requires( 'cpic_manage_templates' ),
				),
			)
		);

		$this->add(
			'/(?P<id>\d+)',
			array(
				'GET'    => array( 'callback' => array( $this, 'show' ) ),
				'DELETE' => array(
					'callback'   => array( $this, 'destroy' ),
					'permission' => $this->requires( 'cpic_manage_assets' ),
				),
			)
		);

		$this->add(
			'/summary',
			array( 'GET' => array( 'callback' => array( $this, 'summary' ) ) )
		);

		$this->add(
			'/(?P<id>\d+)/attach',
			array(
				'POST' => array(
					'callback'   => array( $this, 'attach' ),
					'permission' => $this->requires( 'cpic_manage_assets' ),
				),
			)
		);
	}

	/**
	 * List artifacts for a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index( $request ) {
		$manager = $this->container->get( 'artifact_manager' );
		$post_id = $this->param_int( $request, 'post_id' );

		if ( $post_id > 0 ) {
			$rows = $manager->repo()->for_post( $post_id, $this->param_int( $request, 'limit', 20 ) );
		} else {
			$rows = $manager->repo()->select( array(), 'id DESC', min( 100, max( 1, $this->param_int( $request, 'limit', 20 ) ) ) );
		}

		return $this->ok(
			array(
				'artifacts' => array_values( array_filter( array_map( array( $manager, 'hydrate' ), $rows ) ) ),
				'summary'   => $manager->summary(),
			)
		);
	}

	/**
	 * One artifact.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function show( $request ) {
		$artifact = $this->container->get( 'artifact_manager' )->get( $this->param_int( $request, 'id' ) );

		if ( ! $artifact ) {
			return $this->fail( new \WP_Error( 'cpic_artifact_missing', __( 'That image no longer exists.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		return $this->ok( $artifact );
	}

	/**
	 * Store a browser-rendered PNG (the studio's save path).
	 *
	 * The body is base64 (JSON) or raw PNG (binary). Nothing is trusted: the
	 * bytes are verified to be a real PNG inside the allowed size range.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function store( $request ) {
		$data = $this->param( $request, 'data' );

		if ( '' === $data ) {
			return $this->fail( new \WP_Error( 'cpic_empty_render', __( 'No image data was received.', 'custom-post-image-creator' ), array( 'status' => 400 ) ) );
		}

		// Accept a data URL, raw base64 or already-decoded bytes.
		if ( 0 === strpos( $data, 'data:image/png;base64,' ) ) {
			$data = substr( $data, strlen( 'data:image/png;base64,' ) );
		}

		$binary = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $binary || '' === $binary ) {
			return $this->fail( new \WP_Error( 'cpic_bad_base64', __( 'The image data could not be decoded.', 'custom-post-image-creator' ), array( 'status' => 400 ) ) );
		}

		$result = $this->container->get( 'artifact_manager' )->store_browser_render(
			array(
				'post_id'     => $this->param_int( $request, 'post_id' ),
				'template_id' => $this->param_int( $request, 'template_id' ),
				'format'      => $this->param( $request, 'format', 'square' ),
				'document'    => (array) $request->get_param( 'document' ),
				'binary'      => $binary,
				'renderer'    => 'browser',
				'attach'      => $this->param_bool( $request, 'attach' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}

		$this->container->get( 'media' )->track_document( (array) $request->get_param( 'document' ) );

		return $this->ok( $result, 201 );
	}

	/**
	 * Delete an artifact.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function destroy( $request ) {
		$deleted = $this->container->get( 'artifact_manager' )->delete( $this->param_int( $request, 'id' ) );

		if ( ! $deleted ) {
			return $this->fail( new \WP_Error( 'cpic_artifact_missing', __( 'That image no longer exists.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Attach to the media library.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function attach( $request ) {
		$manager  = $this->container->get( 'artifact_manager' );
		$artifact = $manager->get( $this->param_int( $request, 'id' ) );

		if ( ! $artifact || empty( $artifact['exists'] ) ) {
			return $this->fail( new \WP_Error( 'cpic_artifact_missing', __( 'That image is not on disk.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		$attachment_id = $manager->attach_to_media_library(
			(int) $artifact['id'],
			(string) $artifact['file_path'],
			(int) $artifact['post_id'],
			(int) $artifact['width'],
			(int) $artifact['height']
		);

		return is_wp_error( $attachment_id )
			? $this->fail( $attachment_id )
			: $this->ok( array( 'attachment_id' => (int) $attachment_id ) );
	}

	/**
	 * Storage summary.
	 *
	 * @return \WP_REST_Response
	 */
	public function summary() {
		return $this->ok( $this->container->get( 'artifact_manager' )->summary() );
	}
}
