<?php
/**
 * Queue / jobs REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/jobs`
 */
final class JobsController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/jobs';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$run = $this->requires( 'cpic_run_queue' );

		$this->add(
			'',
			array(
				'GET'  => array( 'callback' => array( $this, 'index' ) ),
				'POST' => array( 'callback' => array( $this, 'enqueue' ), 'permission' => $run ),
			)
		);

		$this->add(
			'/stats',
			array( 'GET' => array( 'callback' => array( $this, 'stats' ) ) )
		);

		$this->add(
			'/run',
			array( 'POST' => array( 'callback' => array( $this, 'run_queue' ), 'permission' => $run ) )
		);

		$this->add(
			'/(?P<id>\d+)/retry',
			array( 'POST' => array( 'callback' => array( $this, 'retry' ), 'permission' => $run ) )
		);

		$this->add(
			'/(?P<id>\d+)/cancel',
			array( 'POST' => array( 'callback' => array( $this, 'cancel' ), 'permission' => $run ) )
		);
	}

	/**
	 * Job history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index( $request ) {
		$history = $this->container->get( 'jobs_repo' )->history(
			array(
				'status'  => $this->param( $request, 'status' ),
				'type'    => $this->param( $request, 'type' ),
				'post_id' => $this->param_int( $request, 'post_id' ),
				'page'    => $this->param_int( $request, 'page', 1 ),
				'per'     => $this->param_int( $request, 'per', 20 ),
			)
		);

		foreach ( $history['items'] as &$item ) {
			$item['attempts']     = (int) $item['attempts'];
			$item['max_retries']  = (int) $item['max_retries'];
			$item['error_hint']   = '' !== (string) $item['last_error_code'] ? $this->container->get( 'retry' )->explain( (string) $item['last_error_code'] ) : '';
		}
		unset( $item );

		return $this->ok( $history );
	}

	/**
	 * Queue statistics.
	 *
	 * @return \WP_REST_Response
	 */
	public function stats() {
		return $this->ok( $this->container->get( 'queue' )->stats() );
	}

	/**
	 * Enqueue a job manually.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function enqueue( $request ) {
		$type = $this->param( $request, 'type', 'publish' );

		if ( ! in_array( $type, \CPIC\Automation\JobRunner::TYPES, true ) ) {
			return $this->fail( new \WP_Error( 'cpic_bad_job', __( 'Unknown job type.', 'custom-post-image-creator' ), array( 'status' => 400 ) ) );
		}

		$result = $this->container->get( 'queue' )->enqueue(
			array(
				'post_id'     => $this->param_int( $request, 'post_id' ),
				'type'        => $type,
				'dedupe_key'  => $this->param( $request, 'dedupe_key' ),
				'payload'     => wp_json_encode( (array) $request->get_param( 'payload' ) ),
				'template_id' => $this->param_int( $request, 'template_id' ),
			)
		);

		return $this->ok(
			array(
				'id'      => (int) $result['id'],
				'created' => (bool) $result['created'],
			),
			$result['created'] ? 201 : 200
		);
	}

	/**
	 * Drain the queue now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function run_queue( $request ) {
		$result = $this->container->get( 'queue' )->run( 'manual' );

		if ( isset( $result['skipped'] ) ) {
			$reason = (string) $result['skipped'];

			return $this->fail(
				new \WP_Error(
					'locked' === $reason ? 'cpic_queue_locked' : 'cpic_queue_skipped',
					'locked' === $reason
						? __( 'Another worker is already processing the queue.', 'custom-post-image-creator' )
						: __( 'Automation is disabled in the settings.', 'custom-post-image-creator' ),
					array( 'status' => 409 )
				)
			);
		}

		return $this->ok( $result );
	}

	/**
	 * Retry a job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function retry( $request ) {
		$id = $this->param_int( $request, 'id' );

		if ( ! $this->container->get( 'jobs_repo' )->find( $id ) ) {
			return $this->fail( new \WP_Error( 'cpic_job_missing', __( 'Job not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		return $this->maybe( $this->container->get( 'jobs_repo' )->retry( $id ) );
	}

	/**
	 * Cancel a job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function cancel( $request ) {
		$id = $this->param_int( $request, 'id' );

		if ( ! $this->container->get( 'jobs_repo' )->find( $id ) ) {
			return $this->fail( new \WP_Error( 'cpic_job_missing', __( 'Job not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		return $this->maybe( $this->container->get( 'jobs_repo' )->cancel( $id ) );
	}
}
