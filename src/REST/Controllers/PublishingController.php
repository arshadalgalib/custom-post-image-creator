<?php
/**
 * Publishing REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/publishing`
 */
final class PublishingController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/publishing';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$publish_permission = $this->requires( 'cpic_publish' );

		$this->add(
			'/publish',
			array( 'POST' => array( 'callback' => array( $this, 'publish' ), 'permission' => $publish_permission ) )
		);

		$this->add(
			'/schedule',
			array( 'POST' => array( 'callback' => array( $this, 'schedule' ), 'permission' => $this->requires( 'cpic_schedule' ) ) )
		);

		$this->add(
			'/post/(?P<post_id>\d+)',
			array( 'GET' => array( 'callback' => array( $this, 'for_post' ) ) )
		);

		$this->add(
			'/deliveries/(?P<id>\d+)/retry',
			array( 'POST' => array( 'callback' => array( $this, 'retry' ), 'permission' => $publish_permission ) )
		);

		$this->add(
			'/deliveries/(?P<id>\d+)/cancel',
			array( 'POST' => array( 'callback' => array( $this, 'cancel' ), 'permission' => $publish_permission ) )
		);

		$this->add(
			'/deliveries/(?P<id>\d+)/undo',
			array( 'POST' => array( 'callback' => array( $this, 'undo' ), 'permission' => $this->requires( 'cpic_publish' ) ) )
		);

		$this->add(
			'/summary',
			array( 'GET' => array( 'callback' => array( $this, 'summary' ) ) )
		);

		$this->add(
			'/preview-caption',
			array( 'POST' => array( 'callback' => array( $this, 'preview_caption' ) ) )
		);
	}

	/**
	 * Publish a post now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function publish( $request ) {
		$post_id = $this->param_int( $request, 'post_id' );

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return $this->fail( new \WP_Error( 'cpic_post_missing', __( 'Post not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		$limit = $this->container->get( 'rate_limiter' )->allow( 'publish', 'post_' . $post_id );

		if ( ! $limit ) {
			return $this->fail(
				new \WP_Error(
					'cpic_rate_limited',
					$this->container->get( 'rate_limiter' )->message(),
					array( 'status' => 429 )
				)
			);
		}

		$result = $this->container->get( 'delivery_manager' )->publish_post(
			$post_id,
			array(
				'destination_ids' => $this->int_list( $request->get_param( 'destination_ids' ) ),
				'providers'       => $this->string_list( $request->get_param( 'providers' ) ),
				'format'          => $this->param( $request, 'format' ),
				'overrides'       => (array) $request->get_param( 'overrides' ),
				'artifact_id'     => $this->param_int( $request, 'artifact_id' ),
				'trigger'         => 'manual',
				'caption_args'    => (array) $request->get_param( 'caption_args' ),
			)
		);

		return is_wp_error( $result ) ? $this->fail( $result ) : $this->ok( $result, 201 );
	}

	/**
	 * Schedule a publish run.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function schedule( $request ) {
		$post_id = $this->param_int( $request, 'post_id' );
		$when    = $this->param( $request, 'scheduled_for' );

		$timestamp = strtotime( $when );

		if ( false === $timestamp ) {
			return $this->fail( new \WP_Error( 'cpic_bad_date', __( 'A valid schedule date is required.', 'custom-post-image-creator' ), array( 'status' => 400 ) ) );
		}

		if ( $timestamp < time() - 60 ) {
			return $this->fail( new \WP_Error( 'cpic_past_date', __( 'The scheduled time is in the past.', 'custom-post-image-creator' ), array( 'status' => 400 ) ) );
		}

		$result = $this->container->get( 'delivery_manager' )->publish_post(
			$post_id,
			array(
				'destination_ids' => $this->int_list( $request->get_param( 'destination_ids' ) ),
				'providers'       => $this->string_list( $request->get_param( 'providers' ) ),
				'format'          => $this->param( $request, 'format' ),
				'schedule_only'   => true,
				'scheduled_for'   => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'trigger'         => 'schedule',
			)
		);

		return is_wp_error( $result ) ? $this->fail( $result ) : $this->ok( $result, 201 );
	}

	/**
	 * Delivery history for a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function for_post( $request ) {
		$post_id = $this->param_int( $request, 'post_id' );

		$deliveries = array();
		foreach ( $this->container->get( 'deliveries' )->for_post( $post_id, 50 ) as $row ) {
			$deliveries[] = $this->shape( $row );
		}

		$runs = array();
		foreach ( $this->container->get( 'deliveries' )->runs_for_post( $post_id, 20 ) as $run ) {
			$runs[] = $run;
		}

		return $this->ok(
			array(
				'post_id'    => $post_id,
				'deliveries' => $deliveries,
				'runs'       => $runs,
			)
		);
	}

	/**
	 * Retry one delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function retry( $request ) {
		return $this->maybe(
			$this->container->get( 'delivery_manager' )->retry(
				$this->param_int( $request, 'id' ),
				$this->param_bool( $request, 'acknowledge_duplicate_risk' )
			)
		);
	}

	/**
	 * Cancel one delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function cancel( $request ) {
		return $this->maybe( $this->container->get( 'delivery_manager' )->cancel( $this->param_int( $request, 'id' ) ) );
	}

	/**
	 * Undo a published delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function undo( $request ) {
		return $this->maybe( $this->container->get( 'delivery_manager' )->undo( $this->param_int( $request, 'id' ) ) );
	}

	/**
	 * Publishing summary for the dashboard.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function summary( $request ) {
		return $this->ok( $this->container->get( 'delivery_manager' )->summary( $this->param_int( $request, 'post_id' ) ) );
	}

	/**
	 * Preview the caption a destination would receive.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function preview_caption( $request ) {
		$post_id  = $this->param_int( $request, 'post_id' );
		$provider = $this->param( $request, 'provider', 'default' );

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return $this->fail( new \WP_Error( 'cpic_post_missing', __( 'Post not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		$captions = $this->container->get( 'captions' );

		$caption = $captions->build(
			$post_id,
			array(
				'provider'  => $provider,
				'hashtags'  => $this->param( $request, 'hashtags' ),
				'template'  => $this->param( $request, 'template' ),
			)
		);

		return $this->ok(
			array(
				'caption' => $caption,
				'length'  => mb_strlen( $caption, 'UTF-8' ),
				'limit'   => $captions->limit_for( $provider ),
			)
		);
	}

	/**
	 * Shape a delivery row for the UI.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function shape( array $row ) {
		$destination = $this->container->get( 'destinations' )->find( (int) $row['destination_id'] );

		$row['id']              = (int) $row['id'];
		$row['attempts']        = (int) $row['attempts'];
		$row['max_attempts']    = (int) $row['max_attempts'];
		$row['destination']     = $destination ? (string) $destination['label'] : '';
		$row['error_hint']      = '' !== (string) $row['error_code'] ? $this->container->get( 'retry' )->explain( (string) $row['error_code'] ) : '';
		$row['can_retry']       = ! in_array( (string) $row['status'], array( 'published', 'processing' ), true ) && (int) $row['attempts'] < (int) $row['max_attempts'];

		return $row;
	}

	/**
	 * Clean an int list param.
	 *
	 * @param mixed $value Raw.
	 * @return array<int,int>
	 */
	private function int_list( $value ) {
		return array_values( array_unique( array_filter( array_map( 'intval', (array) $value ) ) ) );
	}

	/**
	 * Clean a string list param.
	 *
	 * @param mixed $value Raw.
	 * @return array<int,string>
	 */
	private function string_list( $value ) {
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $value ) ) ) );
	}
}
