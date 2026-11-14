<?php
/**
 * Automation module: hooks that start publishing work.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Core\Providers;

use CPIC\Core\Container;
use CPIC\Core\ServiceProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides *when* work is enqueued. The queue itself never decides policy.
 *
 * Auto-publish is off by default and requires both the master switch and the
 * per-platform switch, so an upgrade can never start posting to a page by
 * itself.
 */
final class AutomationProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'automation';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	public function boot( Container $c ) {
		// Test hook: the harness simulates a host where one module explodes.
		do_action( 'cpic/test/boot/automation', $c );

		$c->get( 'scheduler' )->register_handlers();

		// Schedule on any admin request if a previous install lost its events.
		add_action(
			'init',
			function () use ( $c ) {
				$c->get( 'scheduler' )->install_events();
			},
			20
		);

		add_action(
			'cpic/database/installed',
			function () use ( $c ) {
				$c->get( 'scheduler' )->install_events();
			}
		);

		// ── Transition-based automation ──────────────────────────
		add_action(
			'transition_post_status',
			function ( $new_status, $old_status, $post ) use ( $c ) {
				$this->on_transition( $c, $new_status, $old_status, $post );
			},
			10,
			3
		);

		/*
		 * A run parked because the image had to come from the browser resumes
		 * the moment the studio stores that image, instead of waiting for the
		 * next cron tick. Without this the publish dialog said "saved for
		 * publishing" and nothing happened until a background sweep.
		 */
		$c->get( 'events' )->add_listener(
			'artifact.created',
			static function ( $payload ) use ( $c ) {
				if ( empty( $payload['post_id'] ) ) {
					return;
				}

				$c->get( 'delivery_manager' )->resume_parked( (int) $payload['post_id'] );
			}
		);

		// A post deleted while queued must not fire later.
		add_action(
			'before_delete_post',
			function ( $post_id ) use ( $c ) {
				$c->get( 'queue' )->cancel_for_post( (int) $post_id );
			}
		);

		// Manual "publish now" from the post list.
		add_action(
			'admin_post_cpic_publish_now',
			function () use ( $c ) {
				$this->handle_manual_publish( $c );
			}
		);

		// Lightweight front-end entry point for the queue (used when WP-Cron
		// is disabled and no real cron job is set up).
		add_action(
			'wp_ajax_cpic_run_queue',
			function () use ( $c ) {
				if ( ! $c->get( 'capabilities' )->can( 'cpic_run_queue' ) || ! check_ajax_referer( 'cpic_admin', 'nonce', false ) ) {
					wp_send_json_error( array( 'message' => __( 'Not allowed.', 'custom-post-image-creator' ) ), 403 );
				}

				wp_send_json_success( $c->get( 'queue' )->run( 'ajax' ) );
			}
		);
	}

	/**
	 * React to a post status change.
	 *
	 * @param Container $c          Container.
	 * @param string    $new_status New status.
	 * @param string    $old_status Old status.
	 * @param \WP_Post  $post       Post.
	 * @return void
	 */
	private function on_transition( Container $c, $new_status, $old_status, $post ) {
		if ( ! $post || $new_status === $old_status ) {
			return;
		}

		$settings = $c->get( 'settings' );

		if ( ! $settings->is_enabled( 'cpic_enabled' ) ) {
			return;
		}

		$post_types = array_filter( array_map( 'trim', explode( ',', (string) $settings->get( 'cpic_post_types', 'post' ) ) ) );

		if ( ! empty( $post_types ) && ! in_array( (string) $post->post_type, $post_types, true ) ) {
			return;
		}

		$post_id = (int) $post->ID;

		// Only on the transition into "publish" (never on updates of a live post).
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		/**
		 * Short-circuit automatic publishing for a post.
		 *
		 * @param bool     $skip    Whether to skip.
		 * @param int      $post_id Post id.
		 * @param \WP_Post $post    Post.
		 */
		if ( apply_filters( 'cpic/automation/skip', false, $post_id, $post ) ) {
			return;
		}

		$auto_publish = $settings->is_enabled( 'cpic_auto_publish' );
		$auto_generate = $settings->is_enabled( 'cpic_auto_generate' );

		if ( ! $auto_publish && ! $auto_generate ) {
			return;
		}

		if ( $settings->is_enabled( 'cpic_require_approval' ) ) {
			// Human sign-off is required; leave a note instead of posting.
			$c->get( 'audit' )->record(
				'automation.deferred',
				'post',
				$post_id,
				__( 'Automatic publishing is waiting for editorial approval.', 'custom-post-image-creator' )
			);

			$c->get( 'events' )->dispatch( 'automation.deferred', array( 'post_id' => $post_id ) );

			return;
		}

		$providers = array();
		if ( $settings->is_enabled( 'cpic_auto_fb' ) ) {
			$providers[] = 'facebook';
		}
		if ( $settings->is_enabled( 'cpic_auto_tg' ) ) {
			$providers[] = 'telegram';
		}

		if ( $auto_publish && empty( $providers ) ) {
			// No platform selected: do not silently post nowhere.
			$c->get( 'logger' )->warning(
				'automation',
				__( 'Automatic publishing is on but no platform is enabled.', 'custom-post-image-creator' ),
				array( 'post_id' => $post_id )
			);

			return;
		}

		$delay = max( 0, (int) $settings->int( 'cpic_auto_delay', 0 ) );

		if ( $auto_publish ) {
			$result = $c->get( 'queue' )->enqueue(
				array(
					'post_id'      => $post_id,
					'type'         => 'publish',
					'dedupe_key'   => 'post-' . $post_id . '-publish',
					'payload'      => wp_json_encode(
						array(
							'providers' => $providers,
							'trigger'   => 'auto',
						)
					),
					'available_at' => $delay > 0 ? gmdate( 'Y-m-d H:i:s', time() + $delay ) : current_time( 'mysql' ),
				)
			);

			if ( ! empty( $result['created'] ) ) {
				$c->get( 'audit' )->record(
					'automation.queued',
					'post',
					$post_id,
					/* translators: %s: platforms */
					sprintf( __( 'Queued automatic publishing to %s', 'custom-post-image-creator' ), implode( ', ', $providers ) )
				);
			}

			return;
		}

		// Generate-only mode: produce the images and stop.
		$c->get( 'queue' )->enqueue(
			array(
				'post_id'      => $post_id,
				'type'         => 'generate',
				'dedupe_key'   => 'post-' . $post_id . '-generate',
				'payload'      => wp_json_encode( array() ),
				'available_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Admin-post handler for the "Publish image" button.
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	private function handle_manual_publish( Container $c ) {
		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;

		if ( ! $post_id || ! check_admin_referer( 'cpic_publish_' . $post_id ) ) {
			wp_die( esc_html__( 'That link has expired. Reload the post and try again.', 'custom-post-image-creator' ), 403 );
		}

		if ( ! $c->get( 'capabilities' )->can( 'cpic_publish' ) ) {
			wp_die( esc_html__( 'You are not allowed to publish this post.', 'custom-post-image-creator' ), 403 );
		}

		$result = $c->get( 'delivery_manager' )->publish_post(
			$post_id,
			array(
				'trigger' => 'manual',
			)
		);

		$redirect = add_query_arg(
			array(
				'post'   => $post_id,
				'action' => 'edit',
				'cpic'   => is_wp_error( $result ) ? 'error' : 'published',
			),
			admin_url( 'post.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
