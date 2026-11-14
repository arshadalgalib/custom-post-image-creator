<?php
/**
 * Job execution.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Automation;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes one claimed job.
 *
 * Job types:
 *  - `publish`   → render (if needed) + create deliveries + send them
 *  - `generate`  → render and store an artifact only
 *  - `deliver`   → retry the outstanding deliveries of a run
 *  - `cleanup`   → retention sweep
 *
 * Every failure is classified through RetryManager so retryable problems come
 * back later and permanent ones stop immediately with a readable message.
 */
final class JobRunner {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Supported job types.
	 */
	const TYPES = array( 'publish', 'generate', 'deliver', 'cleanup' );

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Run one claimed job.
	 *
	 * @param array<string,mixed> $job   Job row.
	 * @param string              $token Lock token.
	 * @return array<string,mixed> Outcome.
	 */
	public function run( array $job, $token ) {
		$job_id = (int) $job['id'];
		$type   = (string) $job['type'];

		if ( ! $this->container->get( 'jobs_repo' )->start( $job_id, $token ) ) {
			return array( 'job_id' => $job_id, 'status' => 'not_owned' );
		}

		$started = microtime( true );

		$payload = json_decode( (string) $job['payload'], true );
		$payload = is_array( $payload ) ? $payload : array();

		try {
			$result = $this->execute( $type, (int) $job['post_id'], $payload, $job );
		} catch ( \Throwable $e ) {
			$result = new \WP_Error(
				'job_exception',
				/* translators: %s: exception message */
				sprintf( __( 'The job crashed: %s', 'custom-post-image-creator' ), $e->getMessage() )
			);
		}

		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $result ) ) {
			$code     = $result->get_error_code();
			$retry    = $this->container->get( 'retry' );
			$terminal = ! $retry->is_retryable( $code );
			$attempts = (int) $job['attempts'] + 1;

			if ( ! $terminal && $attempts >= (int) $job['max_retries'] ) {
				$terminal = true;
			}

			$this->container->get( 'jobs_repo' )->fail(
				$job_id,
				$code,
				$result->get_error_message(),
				$terminal,
				$terminal ? null : $retry->next_attempt_at( $attempts )
			);

			$this->container->get( 'logger' )->warning(
				'job',
				/* translators: 1: job type, 2: error message */
				sprintf( __( '%1$s job failed: %2$s', 'custom-post-image-creator' ), $type, $result->get_error_message() ),
				array(
					'job_id'   => $job_id,
					'post_id'  => (int) $job['post_id'],
					'code'     => $code,
					'terminal' => $terminal,
				)
			);

			$this->container->get( 'events' )->dispatch(
				'job.failed',
				array(
					'job_id'   => $job_id,
					'post_id'  => (int) $job['post_id'],
					'type'     => $type,
					'code'     => $code,
					'terminal' => $terminal,
				)
			);

			return array(
				'job_id'   => $job_id,
				'status'   => $terminal ? 'failed' : 'pending',
				'error'    => $code,
				'message'  => $result->get_error_message(),
				'duration' => $duration,
			);
		}

		$this->container->get( 'jobs_repo' )->complete( $job_id, array( 'payload' => wp_json_encode( array_merge( $payload, array( 'result' => $this->summarise( $result ) ) ) ) ) );

		$this->container->get( 'analytics_repo' )->record_metric( 'job.duration_ms', $duration, 'job', $job_id, array( 'type' => $type ) );

		$this->container->get( 'events' )->dispatch(
			'job.completed',
			array(
				'job_id'   => $job_id,
				'post_id'  => (int) $job['post_id'],
				'type'     => $type,
				'duration' => $duration,
			)
		);

		return array(
			'job_id'   => $job_id,
			'status'   => 'completed',
			'result'   => $this->summarise( $result ),
			'duration' => $duration,
		);
	}

	/**
	 * Execute a job type.
	 *
	 * @param string              $type    Job type.
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $payload Payload.
	 * @param array<string,mixed> $job     Job row.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function execute( $type, $post_id, array $payload, array $job ) {
		switch ( $type ) {
			case 'publish':
				return $this->do_publish( $post_id, $payload );

			case 'generate':
				return $this->do_generate( $post_id, $payload );

			case 'deliver':
				return $this->do_deliver( $payload );

			case 'cleanup':
				return array( 'deleted' => $this->container->get( 'render' )->cleanup_artifacts() );
		}

		/**
		 * Run a custom job type registered by an add-on.
		 *
		 * @param array|\WP_Error     $result  Result (default: unknown type).
		 * @param string              $type    Job type.
		 * @param int                 $post_id Post id.
		 * @param array<string,mixed> $payload Payload.
		 * @param array<string,mixed> $job     Job row.
		 */
		$result = apply_filters( 'cpic/jobs/run', null, $type, $post_id, $payload, $job );

		if ( null !== $result ) {
			return $result;
		}

		return new \WP_Error(
			'unknown_job_type',
			/* translators: %s: job type */
			sprintf( __( 'Unknown job type "%s".', 'custom-post-image-creator' ), (string) $type )
		);
	}

	/**
	 * Render and publish.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $payload Payload.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function do_publish( $post_id, array $payload ) {
		return $this->container->get( 'delivery_manager' )->publish_post(
			(int) $post_id,
			array_merge(
				$payload,
				array(
					'trigger'      => 'auto',
					'browser_only' => false,
				)
			)
		);
	}

	/**
	 * Render only.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $payload Payload.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function do_generate( $post_id, array $payload ) {
		return $this->container->get( 'render' )->render( (int) $post_id, $payload );
	}

	/**
	 * Retry outstanding deliveries.
	 *
	 * @param array<string,mixed> $payload Payload (`run_id`).
	 * @return array<string,mixed>|\WP_Error
	 */
	private function do_deliver( array $payload ) {
		if ( empty( $payload['run_id'] ) ) {
			return new \WP_Error( 'missing_run', __( 'This delivery job has no run id.', 'custom-post-image-creator' ) );
		}

		return array(
			'results' => $this->container->get( 'delivery_manager' )->process_run( (int) $payload['run_id'] ),
		);
	}

	/**
	 * Reduce a job result to what belongs in the payload/diagnostics.
	 *
	 * @param mixed $result Result.
	 * @return array<string,mixed>
	 */
	private function summarise( $result ) {
		if ( ! is_array( $result ) ) {
			return array( 'value' => is_scalar( $result ) ? $result : null );
		}

		$keep = array( 'run_id', 'post_id', 'format', 'artifact_id', 'id', 'file_url', 'waiting_render', 'render_error', 'processed', 'deleted' );

		$out = array();
		foreach ( $keep as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$out[ $key ] = $result[ $key ];
			}
		}

		if ( isset( $result['deliveries'] ) && is_array( $result['deliveries'] ) ) {
			$out['deliveries'] = count( $result['deliveries'] );
		}

		if ( isset( $result['results'] ) && is_array( $result['results'] ) ) {
			$statuses = array();
			foreach ( $result['results'] as $row ) {
				$status = isset( $row['status'] ) ? (string) $row['status'] : 'unknown';
				$statuses[ $status ] = isset( $statuses[ $status ] ) ? $statuses[ $status ] + 1 : 1;
			}
			$out['delivery_statuses'] = $statuses;
		}

		return $out;
	}
}
