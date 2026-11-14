<?php
/**
 * System diagnostics.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Automation;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports what is actually working, and what a human needs to fix.
 *
 * Every check returns `ok` plus an actionable `message` when it is not ok.
 * Nothing here inspects or returns secret values; secrets are reported only as
 * present/absent.
 */
final class HealthMonitor {

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
	 * Full report.
	 *
	 * @return array<string,mixed>
	 */
	public function report() {
		$report = array(
			'generated_at' => current_time( 'mysql' ),
			'plugin'       => array(
				'version'    => defined( 'CPIC_VERSION' ) ? CPIC_VERSION : '',
				'db_version' => (string) get_option( 'cpic_db_version', '' ),
				'php'        => PHP_VERSION,
				'wordpress'  => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			),
			'database'   => $this->database(),
			'renderer'   => $this->renderer(),
			'fonts'      => $this->fonts(),
			'queue'      => $this->queue(),
			'publishing' => $this->publishing(),
			'scheduler'  => $this->scheduler(),
			'storage'    => $this->storage(),
			// Named "credentials" on purpose: reports only whether each secret
			// is present, never a value.
			'credentials' => $this->secrets(),
			'environment' => $this->environment(),
		);

		$report['problems'] = $this->problems( $report );
		$report['ok']       = empty( $report['problems'] );

		/**
		 * Filter the diagnostics report.
		 *
		 * @param array<string,mixed> $report Report.
		 */
		return apply_filters( 'cpic/health/report', $report );
	}

	/**
	 * A short status suitable for a dashboard widget.
	 *
	 * @return array<string,mixed>
	 */
	public function summary() {
		$report = $this->report();

		return array(
			'ok'       => (bool) $report['ok'],
			'problems' => $report['problems'],
			'queue'    => $report['queue']['pending'],
			'renderer' => $report['renderer']['preferred'],
		);
	}

	/**
	 * Database checks.
	 *
	 * @return array<string,mixed>
	 */
	private function database() {
		$migrator = $this->container->get( 'migrator' );
		$state    = $migrator->state();
		$verify   = $migrator->verify_tables();

		return array(
			'ok'         => (bool) $verify['ok'] && empty( $state['failed'] ),
			'version'    => (string) get_option( 'cpic_db_version', '' ),
			'expected'   => defined( 'CPIC_DB_VERSION' ) ? CPIC_DB_VERSION : '',
			'missing'    => $verify['missing'],
			'last_run'   => isset( $state['completed_at'] ) ? (string) $state['completed_at'] : '',
			'last_error' => isset( $state['failed'] ) ? (string) $state['error'] : '',
		);
	}

	/**
	 * Renderer checks.
	 *
	 * @return array<string,mixed>
	 */
	private function renderer() {
		$registry = $this->container->get( 'renderers' );
		$status   = $registry->status();
		$preferred = (string) $this->container->get( 'settings' )->get( 'cpic_renderer', '' );
		$svg       = $registry->get( 'svg' );

		$available = array();
		foreach ( $status as $row ) {
			if ( ! empty( $row['available'] ) ) {
				$available[] = (string) $row['id'];
			}
		}

		return array(
			'ok'                 => ! empty( $available ),
			'preferred'          => $preferred,
			'available'          => $available,
			'renderers'          => $status,
			/*
			 * The rasteriser is the single fact that decides whether this site
			 * can publish at all, so it is reported by name rather than left
			 * for the admin to infer from a failed publish.
			 */
			'svg_backend'        => $svg && $svg->is_available()
				? ( method_exists( $svg, 'backend_name' ) ? (string) $svg->backend_name() : 'available' )
				: '',
			'svg_reason'         => $svg && ! $svg->is_available() ? $svg->unavailable_reason() : '',
			'background_capable' => $registry->has_full_background_renderer(),
			'complex_script_mode' => $registry->has_full_background_renderer() ? 'background' : 'unavailable',
		);
	}

	/**
	 * Font checks.
	 *
	 * @return array<string,mixed>
	 */
	private function fonts() {
		$fonts = $this->container->get( 'fonts' )->status();

		return array(
			'ok'       => (bool) $fonts['ok'],
			'families' => $fonts['families'],
			'missing'  => (int) $fonts['missing'],
		);
	}

	/**
	 * Queue checks.
	 *
	 * @return array<string,mixed>
	 */
	private function queue() {
		$stats = $this->container->get( 'queue' )->stats();

		$stats['ok'] = (bool) $stats['healthy'];

		return $stats;
	}

	/**
	 * Publishing account checks.
	 *
	 * @return array<string,mixed>
	 */
	private function publishing() {
		$accounts = $this->container->get( 'accounts' );
		$summary  = $accounts->summary();

		$connected = 0;
		foreach ( $summary as $provider ) {
			$connected += (int) $provider['connected'];
		}

		$expiring = $this->container->get( 'destinations' )->expiring( 7 );

		return array(
			// Having no accounts is a configuration choice, not a fault; but it
			// means nothing can be published, so it is reported plainly.
			'ok'           => $connected > 0,
			'connected'    => $connected,
			'providers'    => $summary,
			'expiring'     => count( $expiring ),
			'expiring_ids' => wp_list_pluck( $expiring, 'id' ),
		);
	}

	/**
	 * Scheduler checks.
	 *
	 * @return array<string,mixed>
	 */
	private function scheduler() {
		$status = $this->container->get( 'scheduler' )->status();

		$status['ok'] = $status['enabled']
			? ! empty( $status['events']['queue']['scheduled'] )
			: true;

		return $status;
	}

	/**
	 * Storage checks.
	 *
	 * @return array<string,mixed>
	 */
	private function storage() {
		$summary = $this->container->get( 'artifact_manager' )->summary();
		$dir     = $summary['dir'];

		$writable = is_array( $dir ) && isset( $dir['path'] ) && is_writable( (string) $dir['path'] );

		return array(
			'ok'      => $writable,
			'artifacts' => array(
				'total' => (int) $summary['total'],
				'bytes' => (int) $summary['bytes'],
			),
			'uploads' => $writable ? 'writable' : 'not-writable',
		);
	}

	/**
	 * Which credentials are configured (never their values).
	 *
	 * @return array<string,mixed>
	 */
	private function secrets() {
		$settings = $this->container->get( 'settings' );

		$present = array();
		foreach ( $settings->definitions() as $key => $def ) {
			if ( 'secret' !== $def['type'] ) {
				continue;
			}
			$present[ $key ] = '' !== (string) get_option( $key, '' );
		}

		return array(
			'ok'      => true,
			'present' => $present,
		);
	}

	/**
	 * Environment notes that change behaviour.
	 *
	 * @return array<string,mixed>
	 */
	private function environment() {
		$gd = extension_loaded( 'gd' ) && function_exists( 'imagetypes' ) && ( imagetypes() & IMG_PNG );

		return array(
			'ok'              => true,
			'gd'              => $gd,
			'imagick'         => extension_loaded( 'imagick' ),
			'exec'            => function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ),
			'mbstring'        => function_exists( 'mb_strlen' ),
			'openssl'         => extension_loaded( 'openssl' ),
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'memory_limit'    => (string) ini_get( 'memory_limit' ),
			'max_execution'   => (string) ini_get( 'max_execution_time' ),
		);
	}

	/**
	 * Human-readable problems worth surfacing.
	 *
	 * @param array<string,mixed> $report Report.
	 * @return array<int,string>
	 */
	private function problems( array $report ) {
		$problems = array();

		if ( ! $report['database']['ok'] ) {
			$problems[] = empty( $report['database']['missing'] )
				? __( 'The database schema has not finished installing.', 'custom-post-image-creator' )
				: __( 'Some plugin tables are missing. Deactivate and reactivate the plugin to repair them.', 'custom-post-image-creator' );
		}

		if ( ! $report['renderer']['ok'] ) {
			$problems[] = __( 'No renderer is available on this server.', 'custom-post-image-creator' );
		}

		/*
		 * Images are now produced on the server, so a host without a shaping
		 * rasteriser cannot publish at all. Saying "no renderer" would be
		 * wrong — the GD renderer is present and cannot shape Bengali — so the
		 * missing piece is named explicitly, with the fix.
		 */
		if ( empty( $report['renderer']['background_capable'] ) ) {
			$problems[] = __( 'Images are rendered on the server, but this host has no rasteriser that can shape Bengali. Install librsvg (rsvg-convert), Inkscape, or ImageMagick with the librsvg delegate. Until then, publishing will stop with an explanation instead of producing a wrong image.', 'custom-post-image-creator' );
		}

		if ( empty( $report['environment']['exec'] ) && empty( $report['renderer']['background_capable'] ) ) {
			$problems[] = __( 'PHP cannot start processes on this host (exec is disabled), so no external rasteriser can be used. Ask your host to allow exec, or switch to a host that does.', 'custom-post-image-creator' );
		}

		if ( ! $report['fonts']['ok'] ) {
			$problems[] = __( 'A bundled Bengali font is missing, so text may fall back to a different face.', 'custom-post-image-creator' );
		}

		if ( ! $report['storage']['ok'] ) {
			$problems[] = __( 'The uploads directory is not writable, so images cannot be stored.', 'custom-post-image-creator' );
		}

		if ( ! $report['publishing']['ok'] ) {
			$problems[] = __( 'No social account is connected, so nothing can be published yet.', 'custom-post-image-creator' );
		}

		if ( ! $report['queue']['ok'] ) {
			$problems[] = __( 'The job queue is backed up. Check that WP-Cron is running.', 'custom-post-image-creator' );
		}

		if ( $report['publishing']['expiring'] > 0 ) {
			$problems[] = __( 'One or more access tokens expire within a week.', 'custom-post-image-creator' );
		}

		return $problems;
	}
}
