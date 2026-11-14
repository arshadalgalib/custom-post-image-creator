<?php
/**
 * Cron scheduling and worker bootstrapping.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Automation;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's cron events and decides how work actually runs.
 *
 * On a normal site WP-Cron is traffic-driven, which means a quiet news site can
 * leave posts unpublished for hours. When `DISABLE_WP_CRON` is set (or the
 * scheduler setting is `server`), the plugin skips its own scheduling and
 * exposes a CLI/webhook entry point instead of pretending it is running.
 */
final class Scheduler {

	/**
	 * Cron hook that runs the job queue.
	 */
	const HOOK_QUEUE = 'cpic/cron/queue';

	/**
	 * Cron hook for periodic housekeeping.
	 */
	const HOOK_HEARTBEAT = 'cpic/cron/heartbeat';

	/**
	 * Cron hook for retention cleanup.
	 */
	const HOOK_CLEANUP = 'cpic/cron/cleanup';

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
	 * Schedule every event (activation, upgrade and self-healing).
	 *
	 * @return void
	 */
	public function install_events() {
		if ( ! $this->cron_enabled() ) {
			return;
		}

		$this->ensure( self::HOOK_QUEUE, 'cpic_minute' );
		$this->ensure( self::HOOK_HEARTBEAT, 'cpic_five_minutes' );
		$this->ensure( self::HOOK_CLEANUP, 'daily' );

		update_option( 'cpic_cron_installed', time(), false );
	}

	/**
	 * Remove every scheduled event (deactivation only — never data).
	 *
	 * @return void
	 */
	public function clear_events() {
		foreach ( array( self::HOOK_QUEUE, self::HOOK_HEARTBEAT, self::HOOK_CLEANUP ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		delete_option( 'cpic_cron_installed' );
	}

	/**
	 * Register the custom schedules.
	 *
	 * @param array<string,array<string,mixed>> $schedules Existing schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_schedules( $schedules ) {
		$schedules['cpic_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Post Image Creator)', 'custom-post-image-creator' ),
		);

		$schedules['cpic_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Post Image Creator)', 'custom-post-image-creator' ),
		);

		return $schedules;
	}

	/**
	 * Hook handlers (registered by the provider).
	 *
	 * @return void
	 */
	public function register_handlers() {
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );

		add_action(
			self::HOOK_QUEUE,
			function () {
				/*
				 * Scheduled posts live in `deliveries`, not in the job table: a run
				 * created with `schedule_only` sits there until its `scheduled_for`
				 * passes. Nothing else drains that table, so without this call
				 * "Schedule" silently posted nothing at the appointed time. Rows
				 * that are not yet due are skipped by the query itself.
				 */
				$this->container->get( 'delivery_manager' )->process_due( 10 );
				$this->container->get( 'queue' )->run( 'cron' );
			}
		);

		add_action(
			self::HOOK_HEARTBEAT,
			function () {
				$this->container->get( 'queue' )->recover_stale();
				$this->container->get( 'scheduler' )->install_events();
			}
		);

		add_action(
			self::HOOK_CLEANUP,
			function () {
				$this->container->get( 'render' )->cleanup_artifacts();
				$this->container->get( 'deliveries' )->recover_stale( 30 );
				$this->container->get( 'jobs_repo' )->recover_stale( 30 );
				$this->container->get( 'audit' )->prune( 365 );
			}
		);

		// A single, cheap safety net: if cron was lost, rebuild it on admin load.
		add_action(
			'admin_init',
			function () {
				if ( ! get_option( 'cpic_cron_installed' ) ) {
					$this->install_events();
				}
			},
			5
		);
	}

	/**
	 * Whether the plugin should schedule its own events.
	 *
	 * @return bool
	 */
	public function cron_enabled() {
		$mode = (string) $this->container->get( 'settings' )->get( 'cpic_scheduler', 'auto' );

		if ( 'server' === $mode ) {
			return false;
		}

		if ( 'wp_cron' === $mode ) {
			return true;
		}

		// auto: respect DISABLE_WP_CRON.
		return ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	/**
	 * Ensure a hook is scheduled with the given recurrence.
	 *
	 * @param string $hook       Hook.
	 * @param string $recurrence Schedule name.
	 * @return void
	 */
	private function ensure( $hook, $recurrence ) {
		$next = wp_next_scheduled( $hook );

		if ( $next ) {
			return;
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );
	}

	/**
	 * Human-readable scheduling state for diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public function status() {
		$events = array();

		foreach ( array( self::HOOK_QUEUE => 'queue', self::HOOK_HEARTBEAT => 'heartbeat', self::HOOK_CLEANUP => 'cleanup' ) as $hook => $label ) {
			$next = wp_next_scheduled( $hook );

			$events[ $label ] = array(
				'hook'      => $hook,
				'scheduled' => (bool) $next,
				'next_run'  => $next ? gmdate( 'c', (int) $next ) : '',
				'offset'    => $next ? max( 0, (int) $next - time() ) : 0,
			);
		}

		return array(
			'enabled'  => $this->cron_enabled(),
			'mode'     => (string) $this->container->get( 'settings' )->get( 'cpic_scheduler', 'auto' ),
			'wp_cron'  => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			'events'   => $events,
		);
	}
}
