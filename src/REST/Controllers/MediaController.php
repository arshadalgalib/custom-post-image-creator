<?php
/**
 * Media library REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/media`
 */
final class MediaController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$manage = $this->requires( 'cpic_manage_assets' );

		$this->add(
			'',
			array( 'GET' => array( 'callback' => array( $this, 'index' ) ) )
		);

		$this->add(
			'/register',
			array( 'POST' => array( 'callback' => array( $this, 'register_attachment' ), 'permission' => $manage ) )
		);

		$this->add(
			'/upload',
			array( 'POST' => array( 'callback' => array( $this, 'upload' ), 'permission' => $manage ) )
		);

		$this->add(
			'/summary',
			array( 'GET' => array( 'callback' => array( $this, 'summary' ) ) )
		);
	}

	/**
	 * Browse assets.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index( $request ) {
		return $this->ok(
			$this->container->get( 'media' )->browse(
				array(
					'kind'     => $this->param( $request, 'kind' ),
					'category' => $this->param( $request, 'category' ),
					'search'   => $this->param( $request, 'search' ),
					'page'     => $this->param_int( $request, 'page', 1 ),
					'per'      => $this->param_int( $request, 'per', 40 ),
				)
			)
		);
	}

	/**
	 * Index an existing attachment.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function register_attachment( $request ) {
		return $this->maybe(
			$this->container->get( 'media' )->register_attachment(
				$this->param_int( $request, 'attachment_id' ),
				array(
					'kind'     => $this->param( $request, 'kind', 'image' ),
					'category' => $this->param( $request, 'category', 'general' ),
					'tags'     => $this->param( $request, 'tags' ),
					'alt'      => $this->param( $request, 'alt' ),
					'title'    => $this->param( $request, 'title' ),
				)
			),
			201
		);
	}

	/**
	 * Upload a file through the REST API.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function upload( $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return $this->fail( new \WP_Error( 'cpic_no_file', __( 'No file was uploaded.', 'custom-post-image-creator' ), array( 'status' => 400 ) ) );
		}

		return $this->maybe(
			$this->container->get( 'media' )->upload(
				(array) $files['file'],
				array(
					'kind'     => $this->param( $request, 'kind', 'image' ),
					'category' => $this->param( $request, 'category', 'general' ),
				)
			),
			201
		);
	}

	/**
	 * Library summary.
	 *
	 * @return \WP_REST_Response
	 */
	public function summary() {
		return $this->ok( $this->container->get( 'media' )->summary() );
	}
}
