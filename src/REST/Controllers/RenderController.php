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
			'/post/(?P<post_id>\d+)/latest',
			array( 'GET' => array( 'callback' => array( $this, 'latest' ) ) )
		);

		$this->add(
			'/document',
			array(
				'POST' => array(
					'callback'   => array( $this, 'render_document' ),
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
	 * Render an unsaved studio document on the server.
	 *
	 * This is the path the studio publishes through, and it is deliberately the
	 * same one automations take: the document is turned into a plan by the same
	 * `render_plan` service and rasterised by the same renderer. A design
	 * therefore produces one image, whether it was published by a person
	 * clicking a button or by a cron job minutes later.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function render_document( $request ) {
		$document = $request->get_param( 'document' );

		if ( ! is_array( $document ) || array() === $document ) {
			return $this->fail( new \WP_Error( 'cpic_template_invalid', __( 'No design document was received.', 'custom-post-image-creator' ), array( 'status' => 400 ) ) );
		}

		$validated = $this->container->get( 'template_schema' )->validate( $document );

		if ( ! $validated['ok'] ) {
			return $this->fail( new \WP_Error( 'cpic_template_invalid', implode( ' ', $validated['errors'] ), array( 'status' => 400 ) ) );
		}

		$args = array(
			'document'    => $validated['document'],
			'format'      => $this->param( $request, 'format', 'square' ),
			'post_id'     => $this->param_int( $request, 'post_id' ),
			'template_id' => $this->param_int( $request, 'template_id' ),
			'brand_id'    => $this->param_int( $request, 'brand_id' ),
			'force'       => $this->param_bool( $request, 'force' ),
			'attach'      => $this->param_bool( $request, 'attach' ),
		);

		$artifact = $this->container->get( 'render' )->render( (int) $args['post_id'], $args );

		if ( is_wp_error( $artifact ) ) {
			return $this->render_error( $artifact );
		}

		return $this->ok( $artifact, 201 );
	}

	/**
	 * Turn a render failure into a response that names the actual fault.
	 *
	 * A missing rasteriser is the site owner's problem to fix and it is not the
	 * design's fault, so it is reported with the install instructions attached
	 * rather than as a generic rendering failure the user cannot act on.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_REST_Response
	 */
	public function render_error( $error ) {
		$code        = $error->get_error_code();
		$refusals    = array( 'cpic_no_background_renderer', 'cpic_complex_script', 'cpic_renderer_unavailable' );
		$status      = in_array( $code, $refusals, true ) ? 409 : 500;

		$data = array(
			'code'    => $code,
			'message' => $error->get_error_message(),
			'data'    => (array) $error->get_error_data(),
		);

		/*
		 * Tell the studio whether retrying in the browser would help. It never
		 * would — the whole point of server rendering is that the browser is
		 * not the renderer — so the client must not fall back to canvas.
		 */
		$data['data']['fallback'] = 'none';

		return new \WP_REST_Response( $data, $status );
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

		if ( is_wp_error( $plan ) ) {
			return $this->fail( $plan );
		}

		// The studio and the editor re-save what they render, so the sanitised
		// document travels with the plan (it is already the validated copy).
		if ( ! isset( $args['document'] ) ) {
			$template = $this->param_int( $request, 'template_id' ) > 0
				? $this->container->get( 'template_manager' )->get( $this->param_int( $request, 'template_id' ) )
				: $this->container->get( 'template_manager' )->for_post( (int) $args['post_id'] );

			$args['document'] = $template && isset( $template['document'] )
				? $template['document']
				: $this->container->get( 'template_manager' )->default_document();
		}

		$plan['document'] = $args['document'];

		return $this->ok( $plan );
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
				'overrides' => (array) $request->get_param( 'overrides' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->render_error( $result );
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
	 * The most recent artifact for a post (used by the editor panel).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function latest( $request ) {
		$artifact = $this->container->get( 'artifact_manager' )->latest( $this->param_int( $request, 'post_id' ) );

		if ( ! $artifact ) {
			return $this->ok( null );
		}

		return $this->ok( $artifact );
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
