<?php
/**
 * Queue facade over the job repository.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Automation;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the "what should happen next" layer: enqueuing with dedupe keys,
 * claiming work with a lock token, and running the worker loop with a time
 * budget so a slow renderer cannot exceed PHP's max_execution_time.
 */
final class QueueManager {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Worker id for this request.
	 *
	 * @var string
	 */
	private $worker_id = '';

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Job repository.
	 *
	 * @return \CPIC\Database\Repositories\JobRepository
	 */
	public function repo() {
		return $this->container->get( 'jobs_repo' );
	}

	/**
	 * Stable worker id (host + pid + random) so two workers never collide.
	 *
	 * @return string
	 */
	public function worker_id() {
		if ( '' === $this->worker_id ) {
			$this->worker_id = substr( md5( (string) ( function_exists( 'gethostname' ) ? gethostname() : 'host' ) . '|' . getmypid() . '|' . wp_rand() ), 0, 16 );
		}

		return $this->worker_id;
	}

	/**
	 * Enqueue a job.
	 *
	 * @param array<string,mixed> $data Job fields (`type`, `post_id`, `dedupe_key`, `payload`…).
	 * @return array{id:int,created:bool,row:array|null}
	 */
	public function enqueue( array $data ) {
		$data = wp_parse_args(
			$data,
			array(
				'type'         => 'publish',
				'priority'     => 5,
				'max_retries'  => $this->container->get( 'retry' )->max_attempts(),
				'available_at' => current_time( 'mysql' ),
			)
		);

		$result = $this->repo()->enqueue( $data );

		if ( $result['created'] ) {
			$this->container->get( 'events' )->dispatch(
				'job.enqueued',
				array(
					'job_id'  => (int) $result['id'],
					'post_id' => (int) $data['post_id'],
					'type'    => (string) $data['type'],
				)
			);
		}

		return $result;
	}

	/**
	 * Enqueue a publish job for a post (deduped while one is live).
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $args    Publish arguments (stored in the payload).
	 * @param int                 $delay   Seconds to wait.
	 * @return array{id:int,created:bool,row:array|null}
	 */
	public function enqueue_publish( $post_id, array $args = array(), $delay = 0 ) {
		$delay = max( 0, (int) $delay );

		return $this->enqueue(
			array(
				'post_id'      => (int) $post_id,
				'type'         => 'publish',
				// Delayed jobs get their own dedupe slot so a reschedule works.
				'dedupe_key'   => 'post-' . (int) $post_id . '-publish',
				'payload'      => wp_json_encode( $args ),
				'template_id'  => isset( $args['template_id'] ) ? (int) $args['template_id'] : 0,
				'available_at' => $this->available_at( $delay ),
				'priority'     => 5,
			)
		);
	}

	/**
	 * MySQL datetime N seconds from now (site timezone, like the rest of the schema).
	 *
	 * @param int $delay Seconds.
	 * @return string
	 */
	private function available_at( $delay ) {
		$delay = max( 0, (int) $delay );

		return $delay > 0
			? gmdate( 'Y-m-d H:i:s', time() + $delay )
			: current_time( 'mysql' );
	}

	/**
	 * Run the worker loop.
	 *
	 * @param string $source manual|cron|cli|ajax.
	 * @return array<string,mixed>
	 */
	public function run( $source = 'manual' ) {
		$settings = $this->container->get( 'settings' );

		if ( ! $settings->is_enabled( 'cpic_enabled' ) && 'manual' !== $source ) {
			return array( 'skipped' => 'disabled', 'processed' => 0 );
		}

		// One worker per site at a time (the lock is advisory but effective).
		if ( ! $this->acquire_lock() ) {
			return array( 'skipped' => 'locked', 'processed' => 0 );
		}

		$budget   = max( 5, (int) $settings->int( 'cpic_time_budget', 20 ) );
		$batch    = max( 1, (int) $settings->int( 'cpic_batch_size', 5 ) );
		$deadline = time() + $budget;

		$token   = $this->lock_token();
		$runner  = $this->container->get( 'job_runner' );
		$total   = 0;
		$results = array();

		do {
			$jobs = $this->repo()->claim_batch( $this->worker_id(), $batch, $token );

			if ( empty( $jobs ) ) {
				break;
			}

			foreach ( $jobs as $job ) {
				if ( time() >= $deadline ) {
					// Give the remaining claims back without counting an attempt.
					$this->repo()->release( (int) $job['id'], $token );
					continue;
				}

				$results[] = $runner->run( $job, $token );
				$total++;
			}
		} while ( time() < $deadline );

		$this->release_lock();

		$this->container->get( 'events' )->dispatch(
			'queue.run',
			array(
				'source'    => (string) $source,
				'processed' => $total,
			)
		);

		return array(
			'processed' => $total,
			'source'    => (string) $source,
			'results'   => $results,
			'elapsed'   => max( 0, time() - ( $deadline - $budget ) ),
		);
	}

	/**
	 * Recover jobs abandoned by a dead worker.
	 *
	 * @param int $minutes Stale threshold.
	 * @return int Recovered rows.
	 */
	public function recover_stale( $minutes = 15 ) {
		$recovered = (int) $this->repo()->recover_stale( $minutes );

		if ( $recovered > 0 ) {
			$this->container->get( 'logger' )->warning(
				'queue',
				/* translators: %d: number of jobs */
				sprintf( __( 'Recovered %d stale job(s) from a stopped worker.', 'custom-post-image-creator' ), $recovered )
			);
		}

		return $recovered;
	}

	/**
	 * Cancel every live job for a post.
	 *
	 * @param int $post_id Post id.
	 * @return int Cancelled rows.
	 */
	public function cancel_for_post( $post_id ) {
		$rows = $this->repo()->select(
			array(
				'post_id' => (int) $post_id,
				'status'  => array( 'pending', 'waiting', 'claimed' ),
			)
		);

		$cancelled = 0;

		foreach ( $rows as $row ) {
			if ( $this->repo()->cancel( (int) $row['id'] ) ) {
				$cancelled++;
			}
		}

		return $cancelled;
	}

	/**
	 * Queue depth snapshot.
	 *
	 * @return array<string,mixed>
	 */
	public function stats() {
		$counts     = $this->repo()->status_counts();
		$oldest     = $this->repo()->oldest_pending_at();
		$lag        = 0;

		if ( $oldest ) {
			$lag = max( 0, time() - (int) strtotime( $oldest ) );
		}

		$pending = (int) $counts['pending'] + (int) $counts['waiting'];

		return array(
			'counts'      => $counts,
			'pending'     => $pending,
			'oldest'      => $oldest,
			'lag'         => $lag,
			// A queue that is growing without being drained is a real problem;
			// surface it instead of reporting "all good".
			'healthy'     => $pending < 25 && $lag < 1800,
			'worker'      => $this->worker_id(),
			'locked'      => (bool) get_transient( 'cpic_queue_lock' ),
		);
	}

	/**
	 * Acquire the worker lock.
	 *
	 * @return bool
	 */
	public function acquire_lock() {
		if ( get_transient( 'cpic_queue_lock' ) ) {
			return false;
		}

		set_transient( 'cpic_queue_lock', $this->lock_token(), max( 60, (int) $this->container->get( 'settings' )->int( 'cpic_worker_lock_ttl', 300 ) ) );

		// Re-read: two simultaneous requests can both write a transient.
		return (string) get_transient( 'cpic_queue_lock' ) === $this->lock_token();
	}

	/**
	 * Release the worker lock if we own it.
	 *
	 * @return void
	 */
	public function release_lock() {
		if ( (string) get_transient( 'cpic_queue_lock' ) === $this->lock_token() ) {
			delete_transient( 'cpic_queue_lock' );
		}
	}

	/**
	 * The token used for this worker's claims.
	 *
	 * @return string
	 */
	public function lock_token() {
		return substr( md5( $this->worker_id() . '|' . ( defined( 'NONCE_SALT' ) ? NONCE_SALT : 'cpic' ) ), 0, 24 );
	}
}
