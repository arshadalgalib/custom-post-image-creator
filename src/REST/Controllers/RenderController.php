<?php
/**
 * Rendering REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/render`
 */
final class RenderController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/render';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$this->add(
			'/plan',
			array( 'POST' => array( 'callback' => array( $this, 'plan' ) ) )
		);

		$this->add(
			'/post/(?P<post_id>\d+)',
			array(
				'POST' => array(
					'callback'   => array( $this, 'render_post' ),
					'permission' => $this->requires( 'cpic_manage_templates' ),
				),
			)
		);

		$this->add(
			'/post/(?P<post_id>\d+)/all',
			array(
				'POST' => array(
					'callback'   => array( $this, 'render_all' ),
					'permission' => $this->requires( 'cpic_manage_templates' ),
				),
			)
		);

		$this->add(
			'/status',
			array( 'GET' => array( 'callback' => array( $this, 'status' ) ) )
		);
	}

	/**
	 * Build a plan without rendering (studio canvas).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function plan( $request ) {
		$args = array(
			'format'    => $this->param( $request, 'format', 'square' ),
			'post_id'   => $this->param_int( $request, 'post_id' ),
			'template_id' => $this->param_int( $request, 'template_id' ),
			'brand_id'  => $this->param_int( $request, 'brand_id' ),
			'overrides' => (array) $request->get_param( 'overrides' ),
			'scale'     => $this->param_int( $request, 'scale', 1 ),
		);

		if ( null !== $request->get_param( 'document' ) ) {
			$validated = $this->container->get( 'template_schema' )->validate( $request->get_param( 'document' ) );

			if ( ! $validated['ok'] ) {
				return $this->fail( new \WP_Error( 'cpic_template_invalid', implode( ' ', $validated['errors'] ), array( 'status' => 400 ) ) );
			}

			$args['document'] = $validated['document'];
		}

		$plan = $this->container->get( 'render_plan' )->build( $args );

		return $this->maybe( $plan );
	}

	/**
	 * Attempt a server-side render of one post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function render_post( $request ) {
		$result = $this->container->get( 'render' )->render(
			$this->param_int( $request, 'post_id' ),
			array(
				'format'      => $this->param( $request, 'format' ),
				'force'       => $this->param_bool( $request, 'force' ),
				'overrides'   => (array) $request->get_param( 'overrides' ),
				'browser_only' => false,
			)
		);

		if ( is_wp_error( $result ) ) {
			// A browser-only design is not an error the user caused; make that
			// explicit so the studio knows to render client-side instead.
			$data = array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'data'    => (array) $result->get_error_data(),
			);

			return new \WP_REST_Response( $data, in_array( $result->get_error_code(), array( 'cpic_browser_only', 'cpic_complex_script', 'cpic_no_background_renderer' ), true ) ? 409 : 500 );
		}

		return $this->ok( $result );
	}

	/**
	 * Render every format variant.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function render_all( $request ) {
		$formats = $request->get_param( 'formats' );
		$formats = is_array( $formats ) ? array_map( 'sanitize_key', $formats ) : array();

		$result = $this->container->get( 'render' )->render_all(
			$this->param_int( $request, 'post_id' ),
			$formats,
			array(
				'force' => $this->param_bool( $request, 'force' ),
			)
		);

		return $this->maybe( $result );
	}

	/**
	 * Renderer + font status.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		$status = $this->container->get( 'render' )->status();

		// Never expose server paths.
		unset( $status['fonts']['fonts'] );

		return $this->ok( $status );
	}
}
