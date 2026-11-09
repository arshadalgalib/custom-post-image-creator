<?php
/**
 * Publishing orchestration: one intent, many independent deliveries.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Publishing;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns "publish this post" into a delivery run with one delivery per
 * destination.
 *
 * Guarantees:
 *  - Each destination succeeds or fails on its own. A Telegram failure never
 *    re-posts to Facebook.
 *  - Every delivery carries a deterministic idempotency key, so re-running a
 *    job (or a double-clicked button) cannot publish twice.
 *  - Only rows from `cpic_artifacts` are ever sent; the renderer output is
 *    verified to exist on disk first.
 *  - Ambiguous remote outcomes (`maybe_created`) are never auto-retried.
 */
final class DeliveryManager {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Delivery repository.
	 *
	 * @return \CPIC\Database\Repositories\DeliveryRepository
	 */
	public function repo() {
		return $this->container->get( 'deliveries' );
	}

	/**
	 * Create a delivery run plus its per-destination deliveries.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $args {
	 *     @type array<int,int>    $destination_ids Explicit destinations.
	 *     @type array<int,string> $providers       Provider filter.
	 *     @type string            $format          Image format.
	 *     @type array             $overrides       Text overrides.
	 *     @type bool              $schedule_only   Create deliveries without sending.
	 *     @type string            $scheduled_for   MySQL datetime.
	 *     @type string            $trigger         manual|auto|schedule|cli.
	 *     @type bool              $force_render    Ignore the artifact cache.
	 *     @type array             $caption_args    Caption options.
	 * }
	 * @return array<string,mixed>|\WP_Error {run_id, deliveries[], artifact}
	 */
	public function publish_post( $post_id, array $args = array() ) {
		$post_id = (int) $post_id;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return new \WP_Error( 'cpic_post_missing', __( 'The post could not be found.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$targets = $this->container->get( 'accounts' )->resolve_targets( $args );

		if ( empty( $targets ) ) {
			return new \WP_Error(
				'cpic_no_destinations',
				__( 'No connected Facebook pages or Telegram channels are selected.', 'custom-post-image-creator' ),
				array( 'status' => 409 )
			);
		}

		// Refuse to publish the same post to the same destination twice.
		$already = $this->already_published( $post_id, $targets );
		if ( ! empty( $already['blocked'] ) ) {
			return new \WP_Error(
				'cpic_already_published',
				sprintf(
					/* translators: %s: destination labels */
					__( 'This post was already published to: %s.', 'custom-post-image-creator' ),
					implode( ', ', $already['labels'] )
				),
				array(
					'status'       => 409,
					'deliveries'   => $already['deliveries'],
					'maybe_exists' => true,
				)
			);
		}

		// ── Image ────────────────────────────────────────────────
		$format = isset( $args['format'] ) ? sanitize_key( (string) $args['format'] ) : '';

		if ( '' === $format ) {
			foreach ( $targets as $target ) {
				$publisher = $this->container->get( 'publishers' )->get( (string) $target['provider'] );
				if ( $publisher && '' !== $publisher->preferred_format() ) {
					$format = $publisher->preferred_format();
					break;
				}
			}
		}

		$render_args = array(
			'format'      => $format ? $format : (string) $this->container->get( 'settings' )->get( 'cpic_default_format', 'square' ),
			'overrides'   => isset( $args['overrides'] ) ? (array) $args['overrides'] : array(),
			'browser_only' => false,
		);

		$template_id = 0;
		$template    = $this->container->get( 'template_manager' )->for_post( $post_id );
		if ( $template ) {
			$template_id             = (int) $template['id'];
			$render_args['document'] = $template['document'];
			$render_args['template_id'] = $template_id;
		}

		$artifact = null;

		if ( ! empty( $args['artifact_id'] ) ) {
			$artifact = $this->container->get( 'artifact_manager' )->get( (int) $args['artifact_id'] );
		}

		if ( ! $artifact ) {
			$artifact = $this->container->get( 'render' )->render( $post_id, $render_args );
		}

		// Browser-only designs: park the run; the studio will resume it.
		$renderer_blocked = is_wp_error( $artifact ) && in_array( $artifact->get_error_code(), array( 'cpic_browser_only', 'cpic_complex_script', 'cpic_no_background_renderer' ), true );

		if ( is_wp_error( $artifact ) && ! $renderer_blocked ) {
			$this->container->get( 'logger' )->error(
				'publish',
				/* translators: %s: error */
				sprintf( __( 'Render failed before publishing: %s', 'custom-post-image-creator' ), $artifact->get_error_message() ),
				array( 'post_id' => $post_id )
			);

			return $artifact;
		}

		if ( ! $renderer_blocked && ( ! is_array( $artifact ) || empty( $artifact['exists'] ) ) ) {
			return new \WP_Error( 'artifact_missing', __( 'The rendered image is missing from disk.', 'custom-post-image-creator' ), array( 'status' => 409 ) );
		}

		// ── Captions ─────────────────────────────────────────────
		$captions = $this->container->get( 'captions' )->build_for_destinations(
			$post_id,
			$targets,
			isset( $args['caption_args'] ) && is_array( $args['caption_args'] ) ? $args['caption_args'] : array()
		);

		$first_comment = $this->container->get( 'settings' )->is_enabled( 'cpic_auto_comment' )
			? $this->container->get( 'captions' )->first_comment( $post_id )
			: '';

		// ── Run ──────────────────────────────────────────────────
		$scheduled_for = isset( $args['scheduled_for'] ) && '' !== $args['scheduled_for'] ? (string) $args['scheduled_for'] : null;

		if ( ! $scheduled_for && ! empty( $args['schedule_only'] ) ) {
			$scheduled_for = current_time( 'mysql' );
		}

		$run_id = $this->repo()->create_run(
			array(
				'post_id'           => $post_id,
				'artifact_id'       => $renderer_blocked ? 0 : (int) $artifact['id'],
				'template_revision' => $template ? (int) $template['revision'] : 0,
				'caption_snapshot'  => wp_json_encode( $captions ),
				'status'            => $renderer_blocked ? 'waiting_for_artifact' : 'pending',
				// Always stamped so the calendar has a real position for every run.
				'scheduled_for'     => $scheduled_for ? $scheduled_for : current_time( 'mysql' ),
				'trigger_source'    => isset( $args['trigger'] ) ? sanitize_key( (string) $args['trigger'] ) : 'manual',
				'campaign_id'       => isset( $args['campaign_id'] ) ? (int) $args['campaign_id'] : 0,
				'created_by'        => get_current_user_id(),
				'created_at'        => current_time( 'mysql' ),
			)
		);

		if ( ! $run_id ) {
			return new \WP_Error( 'cpic_run_failed', __( 'The delivery run could not be created.', 'custom-post-image-creator' ), array( 'status' => 500 ) );
		}

		$deliveries = array();

		foreach ( $targets as $target ) {
			$destination_id = (int) $target['id'];
			$provider       = (string) $target['provider'];

			$delivery_id = $this->repo()->create_delivery(
				array(
					'run_id'          => $run_id,
					'post_id'         => $post_id,
					'destination_id'  => $destination_id,
					'provider'        => $provider,
					// Deterministic: run + destination + post can only ever exist once.
					'idempotency_key' => $this->idempotency_key( $run_id, $destination_id, $post_id ),
					'artifact_id'     => $renderer_blocked ? 0 : (int) $artifact['id'],
					'caption'         => isset( $captions[ $destination_id ] ) ? (string) $captions[ $destination_id ] : '',
					'status'          => $renderer_blocked ? 'waiting_for_artifact' : ( $scheduled_for && strtotime( $scheduled_for ) > time() ? 'queued' : 'pending' ),
					'max_attempts'    => $this->container->get( 'retry' )->max_attempts(),
					'scheduled_for'   => $scheduled_for,
					'created_at'      => current_time( 'mysql' ),
				)
			);

			if ( $delivery_id ) {
				$deliveries[] = $this->repo()->find( $delivery_id );
			}
		}

		$this->container->get( 'audit' )->record(
			'run.created',
			'run',
			$run_id,
			sprintf(
				/* translators: 1: post id, 2: destination count */
				__( 'Publishing run for post %1$d to %2$d destination(s)', 'custom-post-image-creator' ),
				$post_id,
				count( $deliveries )
			),
			array( 'post_id' => $post_id, 'trigger' => isset( $args['trigger'] ) ? (string) $args['trigger'] : 'manual' )
		);

		$this->container->get( 'events' )->dispatch(
			'run.created',
			array(
				'run_id'     => $run_id,
				'post_id'    => $post_id,
				'deliveries' => count( $deliveries ),
			)
		);

		$result = array(
			'run_id'         => $run_id,
			'post_id'        => $post_id,
			'deliveries'     => $deliveries,
			'artifact'       => $renderer_blocked ? null : $artifact,
			'format'         => $renderer_blocked ? '' : (string) $artifact['format'],
			'waiting_render' => $renderer_blocked,
			'render_error'   => $renderer_blocked ? $artifact->get_error_code() : '',
			'scheduled_for'  => $scheduled_for,
		);

		// Send immediately unless the caller asked to schedule it.
		if ( empty( $args['schedule_only'] ) && ! $renderer_blocked && ( ! $scheduled_for || strtotime( $scheduled_for ) <= time() ) ) {
			$result['results'] = $this->process_run( $run_id );
		}

		return $result;
	}

	/**
	 * Send each delivery of a run, independently.
	 *
	 * @param int $run_id Run id.
	 * @return array<int,array<string,mixed>> Per-delivery outcomes.
	 */
	public function process_run( $run_id ) {
		$results = array();

		foreach ( $this->repo()->for_run( (int) $run_id ) as $delivery ) {
			if ( in_array( (string) $delivery['status'], array( 'published', 'cancelled', 'skipped' ), true ) ) {
				$results[] = array(
					'delivery_id' => (int) $delivery['id'],
					'status'      => (string) $delivery['status'],
					'skipped'     => true,
				);
				continue;
			}

			$results[] = $this->dispatch_delivery( (int) $delivery['id'] );
		}

		$this->finalise_run( (int) $run_id );

		return $results;
	}

	/**
	 * Attempt one delivery.
	 *
	 * @param int $delivery_id Delivery id.
	 * @return array<string,mixed>
	 */
	public function dispatch_delivery( $delivery_id ) {
		$delivery_id = (int) $delivery_id;
		$delivery    = $this->repo()->find( $delivery_id );

		if ( ! $delivery ) {
			return array( 'delivery_id' => $delivery_id, 'status' => 'missing' );
		}

		if ( 'waiting_for_artifact' === (string) $delivery['status'] ) {
			return array( 'delivery_id' => $delivery_id, 'status' => 'waiting_for_artifact' );
		}

		if ( ! $this->repo()->claim( $delivery_id ) ) {
			return array( 'delivery_id' => $delivery_id, 'status' => 'not_claimed' );
		}

		$delivery  = $this->repo()->find( $delivery_id );
		$destination = $this->container->get( 'destinations' )->find( (int) $delivery['destination_id'] );
		$publisher = $this->container->get( 'publishers' )->get( (string) $delivery['provider'] );

		if ( ! $destination ) {
			$this->repo()->mark_failed( $delivery_id, 'invalid_destination', __( 'The destination no longer exists.', 'custom-post-image-creator' ), true );
			return $this->outcome( $delivery_id, 'failed', 'invalid_destination' );
		}

		if ( ! $publisher ) {
			$this->repo()->mark_failed( $delivery_id, 'invalid_destination', __( 'No publisher handles this provider.', 'custom-post-image-creator' ), true );
			return $this->outcome( $delivery_id, 'failed', 'invalid_destination' );
		}

		if ( 'connected' !== (string) $destination['status'] ) {
			$this->repo()->mark_failed( $delivery_id, 'account_disconnected', __( 'The destination is disconnected.', 'custom-post-image-creator' ), false );
			return $this->outcome( $delivery_id, 'queued', 'account_disconnected' );
		}

		$artifact = $this->container->get( 'artifact_manager' )->get( (int) $delivery['artifact_id'] );

		if ( ! $artifact || empty( $artifact['exists'] ) ) {
			$this->repo()->mark_failed( $delivery_id, 'artifact_missing', __( 'The rendered image is missing.', 'custom-post-image-creator' ), false );
			$this->container->get( 'events' )->dispatch( 'delivery.artifact_missing', array( 'delivery_id' => $delivery_id, 'post_id' => (int) $delivery['post_id'] ) );
			return $this->outcome( $delivery_id, 'queued', 'artifact_missing' );
		}

		$payload = array(
			'artifact'      => $artifact,
			'caption'       => (string) $delivery['caption'],
			'post_id'       => (int) $delivery['post_id'],
			'link'          => (string) get_permalink( (int) $delivery['post_id'] ),
			'first_comment' => 'facebook' === (string) $delivery['provider'] && $this->container->get( 'settings' )->is_enabled( 'cpic_auto_comment' )
				? $this->container->get( 'captions' )->first_comment( (int) $delivery['post_id'] )
				: '',
		);

		$result = $publisher->publish( $destination, $payload );

		if ( $result->ok() ) {
			$data = $result->to_array();

			$this->repo()->mark_published(
				$delivery_id,
				array(
					'remote_id'        => (string) $data['remote_id'],
					'remote_url'       => (string) $data['remote_url'],
					'first_comment_id' => (string) $data['first_comment_id'],
					'metrics'          => (array) $data['metrics'],
				)
			);

			$this->container->get( 'audit' )->record(
				'delivery.published',
				'delivery',
				$delivery_id,
				sprintf(
					/* translators: 1: provider, 2: destination label */
					__( 'Published to %1$s: %2$s', 'custom-post-image-creator' ),
					(string) $delivery['provider'],
					(string) $destination['label']
				),
				array( 'remote_id' => (string) $data['remote_id'] )
			);

			$this->container->get( 'events' )->dispatch(
				'delivery.published',
				array(
					'delivery_id'    => $delivery_id,
					'post_id'        => (int) $delivery['post_id'],
					'provider'       => (string) $delivery['provider'],
					'destination_id' => (int) $delivery['destination_id'],
					'remote_id'      => (string) $data['remote_id'],
				)
			);

			return $this->outcome( $delivery_id, 'published', '' );
		}

		$code = $result->error_code();

		// An ambiguous failure must never be retried automatically.
		if ( $result->maybe_created() ) {
			$this->repo()->update(
				$delivery_id,
				array(
					'status'      => 'failed',
					'error_code'  => $code,
					'last_error'  => $result->error_message(),
					'claimed_at'  => null,
					'completed_at' => current_time( 'mysql' ),
				)
			);

			$this->container->get( 'logger' )->warning(
				'publish',
				__( 'A publish attempt ended with an unknown outcome; it will not be retried automatically to avoid a duplicate post.', 'custom-post-image-creator' ),
				array(
					'delivery_id' => $delivery_id,
					'provider'    => (string) $delivery['provider'],
					'code'        => $code,
				)
			);

			$this->container->get( 'events' )->dispatch(
				'delivery.maybe_created',
				array(
					'delivery_id' => $delivery_id,
					'post_id'     => (int) $delivery['post_id'],
					'provider'    => (string) $delivery['provider'],
					'code'        => $code,
				)
			);

			return $this->outcome( $delivery_id, 'failed', $code );
		}

		$retry      = $this->container->get( 'retry' );
		$attempts   = (int) $delivery['attempts'] + 1;
		$terminal   = ! $retry->is_retryable( $code ) || $attempts >= (int) $delivery['max_attempts'];
		$retry_at   = $terminal ? null : $retry->next_attempt_at( $attempts );

		$this->repo()->mark_failed( $delivery_id, $code, $result->error_message(), $terminal, $retry_at );

		$this->container->get( 'logger' )->warning(
			'publish',
			/* translators: 1: provider, 2: message */
			sprintf( __( 'Delivery via %1$s failed: %2$s', 'custom-post-image-creator' ), (string) $delivery['provider'], $result->error_message() ),
			array(
				'delivery_id' => $delivery_id,
				'code'        => $code,
				'terminal'    => $terminal,
			)
		);

		$this->container->get( 'events' )->dispatch(
			'delivery.failed',
			array(
				'delivery_id' => $delivery_id,
				'post_id'     => (int) $delivery['post_id'],
				'provider'    => (string) $delivery['provider'],
				'code'        => $code,
				'terminal'    => $terminal,
			)
		);

		return $this->outcome( $delivery_id, $terminal ? 'failed' : 'queued', $code );
	}

	/**
	 * Process due deliveries (queue worker entry point).
	 *
	 * @param int $limit Max deliveries.
	 * @return array<string,mixed>
	 */
	public function process_due( $limit = 10 ) {
		$rows = $this->repo()->due( (int) $limit );

		$results = array();
		$runs    = array();

		foreach ( $rows as $row ) {
			$results[]         = $this->dispatch_delivery( (int) $row['id'] );
			$runs[ (int) $row['run_id'] ] = true;
		}

		foreach ( array_keys( $runs ) as $run_id ) {
			$this->finalise_run( (int) $run_id );
		}

		return array(
			'processed' => count( $results ),
			'results'   => $results,
		);
	}

	/**
	 * Update a run's status from its deliveries.
	 *
	 * @param int $run_id Run id.
	 * @return string Run status.
	 */
	public function finalise_run( $run_id ) {
		$deliveries = $this->repo()->for_run( (int) $run_id );

		if ( empty( $deliveries ) ) {
			return '';
		}

		$statuses = array();
		foreach ( $deliveries as $delivery ) {
			$statuses[ (string) $delivery['status'] ] = true;
		}

		if ( isset( $statuses['processing'] ) || isset( $statuses['pending'] ) || isset( $statuses['queued'] ) ) {
			$status = 'pending';
		} elseif ( isset( $statuses['waiting_for_artifact'] ) ) {
			$status = 'waiting_for_artifact';
		} elseif ( count( $statuses ) === 1 && isset( $statuses['published'] ) ) {
			$status = 'completed';
		} elseif ( isset( $statuses['published'] ) ) {
			// Partial success is its own state — never reported as success.
			$status = 'partial';
		} else {
			$status = 'failed';
		}

		$update = array( 'status' => $status );

		if ( in_array( $status, array( 'completed', 'partial', 'failed' ), true ) ) {
			$update['completed_at'] = current_time( 'mysql' );
		}

		$this->repo()->update_run( (int) $run_id, $update );

		return $status;
	}

	/**
	 * Retry one failed delivery manually.
	 *
	 * @param int  $delivery_id Delivery id.
	 * @param bool $acknowledge Set when a human confirms no duplicate exists.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function retry( $delivery_id, $acknowledge = false ) {
		$delivery = $this->repo()->find( (int) $delivery_id );

		if ( ! $delivery ) {
			return new \WP_Error( 'cpic_delivery_missing', __( 'That delivery no longer exists.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		if ( 'published' === (string) $delivery['status'] ) {
			return new \WP_Error(
				'cpic_already_published',
				__( 'This delivery was already published. Duplicating it would post twice.', 'custom-post-image-creator' ),
				array( 'status' => 409 )
			);
		}

		// Rows whose remote outcome is unknown require an explicit confirmation.
		$ambiguous = array( 'network_error', 'fb_server_error', 'tg_server_error', 'fb_unexpected_response', 'tg_unexpected_response' );

		if ( in_array( (string) $delivery['error_code'], $ambiguous, true ) && ! $acknowledge ) {
			return new \WP_Error(
				'cpic_duplicate_risk',
				__( 'The last attempt ended without a definite answer. Check the destination page first, then confirm the retry.', 'custom-post-image-creator' ),
				array( 'status' => 409, 'requires_confirmation' => true )
			);
		}

		$this->repo()->update(
			(int) $delivery_id,
			array(
				'attempts' => 0,
				'status'   => 'pending',
				'claimed_at' => null,
			)
		);

		$this->repo()->requeue( (int) $delivery_id );

		return $this->dispatch_delivery( (int) $delivery_id );
	}

	/**
	 * Cancel a delivery that has not been sent yet.
	 *
	 * @param int $delivery_id Delivery id.
	 * @return bool|\WP_Error
	 */
	public function cancel( $delivery_id ) {
		$delivery = $this->repo()->find( (int) $delivery_id );

		if ( ! $delivery ) {
			return new \WP_Error( 'cpic_delivery_missing', __( 'That delivery no longer exists.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		if ( in_array( (string) $delivery['status'], array( 'published', 'processing' ), true ) ) {
			return new \WP_Error( 'cpic_delivery_locked', __( 'Published or in-flight deliveries cannot be cancelled.', 'custom-post-image-creator' ), array( 'status' => 409 ) );
		}

		$this->repo()->update( (int) $delivery_id, array( 'status' => 'cancelled', 'claimed_at' => null ) );

		return true;
	}

	/**
	 * Delete a published remote post and mark the delivery undone.
	 *
	 * @param int $delivery_id Delivery id.
	 * @return bool|\WP_Error
	 */
	public function undo( $delivery_id ) {
		$delivery = $this->repo()->find( (int) $delivery_id );

		if ( ! $delivery || 'published' !== (string) $delivery['status'] ) {
			return new \WP_Error( 'cpic_delivery_not_published', __( 'Only published deliveries can be undone.', 'custom-post-image-creator' ), array( 'status' => 409 ) );
		}

		$destination = $this->container->get( 'destinations' )->find( (int) $delivery['destination_id'] );
		$publisher   = $this->container->get( 'publishers' )->get( (string) $delivery['provider'] );

		if ( ! $destination || ! $publisher ) {
			return new \WP_Error( 'cpic_destination_missing', __( 'The destination for this delivery no longer exists.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$result = $publisher->delete( $destination, (string) $delivery['remote_id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->repo()->update(
			(int) $delivery_id,
			array(
				'status'     => 'cancelled',
				'last_error' => __( 'Removed from the destination.', 'custom-post-image-creator' ),
			)
		);

		$this->container->get( 'audit' )->record( 'delivery.undone', 'delivery', (int) $delivery_id, (string) $delivery['remote_id'] );

		return true;
	}

	/**
	 * Deliveries that already exist for these destinations.
	 *
	 * @param int                            $post_id Post id.
	 * @param array<int,array<string,mixed>> $targets Destination rows.
	 * @return array{blocked:bool,labels:string[],deliveries:array}
	 */
	private function already_published( $post_id, array $targets ) {
		$rows = $this->repo()->for_post( (int) $post_id );

		$by_destination = array();
		foreach ( $rows as $row ) {
			if ( in_array( (string) $row['status'], array( 'published', 'processing' ), true ) ) {
				$by_destination[ (int) $row['destination_id'] ] = $row;
			}
		}

		// A post may legitimately be republished to a *different* destination.
		$labels = array();
		$ids    = array();

		foreach ( $targets as $target ) {
			$id = (int) $target['id'];
			if ( isset( $by_destination[ $id ] ) ) {
				$labels[] = (string) $target['label'];
				$ids[]    = (int) $by_destination[ $id ]['id'];
			}
		}

		if ( count( $labels ) !== count( $targets ) ) {
			return array( 'blocked' => false, 'labels' => array(), 'deliveries' => array() );
		}

		return array(
			'blocked'    => true,
			'labels'     => $labels,
			'deliveries' => $ids,
		);
	}

	/**
	 * Outcome array for one delivery.
	 *
	 * @param int    $delivery_id Delivery id.
	 * @param string $status      Status.
	 * @param string $code        Error code.
	 * @return array<string,mixed>
	 */
	private function outcome( $delivery_id, $status, $code ) {
		$row = $this->repo()->find( (int) $delivery_id );

		return array(
			'delivery_id'    => (int) $delivery_id,
			'status'         => $status,
			'provider'       => $row ? (string) $row['provider'] : '',
			'destination_id' => $row ? (int) $row['destination_id'] : 0,
			'remote_id'      => $row ? (string) $row['remote_id'] : '',
			'remote_url'     => $row ? (string) $row['remote_url'] : '',
			'error_code'     => (string) $code,
			'error_message'  => $code ? $this->container->get( 'retry' )->explain( $code ) : '',
			'attempts'       => $row ? (int) $row['attempts'] : 0,
		);
	}

	/**
	 * Deterministic idempotency key.
	 *
	 * @param int $run_id         Run id.
	 * @param int $destination_id Destination id.
	 * @param int $post_id        Post id.
	 * @return string
	 */
	public function idempotency_key( $run_id, $destination_id, $post_id ) {
		return sprintf( 'run%d-dst%d-post%d', (int) $run_id, (int) $destination_id, (int) $post_id );
	}

	/**
	 * Publishing status summary for the dashboard.
	 *
	 * @param int $post_id Optional post id.
	 * @return array<string,mixed>
	 */
	public function summary( $post_id = 0 ) {
		$where = $post_id ? array( 'post_id' => (int) $post_id ) : array();

		return array(
			'counts'    => $this->repo()->status_counts( $where ),
			'providers' => $this->container->get( 'accounts' )->summary(),
		);
	}
}
