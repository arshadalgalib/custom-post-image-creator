<?php
/**
 * Plugin bootstrap and service container wiring.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central plugin object.
 *
 * Services are registered as lazy factories so a front-end request only builds
 * what it actually needs. Modules (providers) attach the WordPress hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Module manager.
	 *
	 * @var ModuleManager
	 */
	private $modules;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Message from a failed module boot, if any.
	 *
	 * @var string
	 */
	private $boot_error = '';

	/**
	 * Retrieve the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * The plugin object, or null if it never finished constructing.
	 *
	 * Unlike instance(), this never builds anything, so it is safe to call from
	 * diagnostics and notices: calling instance() from inside a notice on a site
	 * whose construction failed would throw again, in a place with no handler,
	 * and take the admin page down with it.
	 *
	 * @return \CPIC\Core\Plugin|null
	 */
	public static function loaded() {
		return self::$instance;
	}

	/**
	 * Constructor: build container, register services, then boot modules.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->modules   = new ModuleManager( $this->container );

		$this->register_core_services();
		$this->register_modules();

		$this->boot();
	}

	/**
	 * Service container.
	 *
	 * @return Container
	 */
	public function container() {
		return $this->container;
	}

	/**
	 * Module manager.
	 *
	 * @return ModuleManager
	 */
	public function modules() {
		return $this->modules;
	}

	/**
	 * Shorthand service getter.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 */
	public function service( $id ) {
		return $this->container->get( $id );
	}

	/**
	 * Core, always-available services.
	 *
	 * @return void
	 */
	private function register_core_services() {
		( new \CPIC\Core\Providers\ConfigServiceProvider() )->register( $this->container );
	}

	/**
	 * Register module providers (hooks are attached in boot()).
	 *
	 * @return void
	 */
	private function register_modules() {
		$this->modules->add( new \CPIC\Core\Providers\CoreProvider() );
		$this->modules->add( new \CPIC\Core\Providers\DataProvider() );
		$this->modules->add( new \CPIC\Core\Providers\AutomationProvider() );
		$this->modules->add( new \CPIC\Core\Providers\RestProvider() );
		$this->modules->add( new \CPIC\Core\Providers\EditorProvider() );
		$this->modules->add( new \CPIC\Core\Providers\AdminProvider() );
		$this->modules->add( new \CPIC\Core\Providers\LegacyProvider() );
	}

	/**
	 * Boot modules once.
	 *
	 * Order matters. The activation/deactivation hooks are registered *first*,
	 * and the modules boot afterwards inside a guard.
	 *
	 * This file is included at a point where WordPress has already fired
	 * `plugins_loaded` (most importantly during activation, where
	 * `activate_plugin()` includes the plugin from inside an admin request).
	 * Anything a module does at that moment - reading an option, touching a
	 * table, resolving a user, calling a function added by another plugin that
	 * has not loaded yet - can throw. If that happened before the hooks were
	 * registered, or escaped at all, WordPress would keep running with this
	 * plugin's activation hook missing and report "could not be activated
	 * because it triggered a fatal error".
	 *
	 * So: register the lifecycle hooks, then let modules attach their own hooks
	 * defensively. `ModuleManager` already isolates each provider; this outer
	 * guard covers the manager itself.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		register_activation_hook( CPIC_PLUGIN_FILE, array( $this, 'on_activate' ) );
		register_deactivation_hook( CPIC_PLUGIN_FILE, array( $this, 'on_deactivate' ) );

		try {
			$this->modules->boot();
		} catch ( \Throwable $e ) {
			$this->boot_error = $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';

			if ( function_exists( 'error_log' ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				error_log( 'CPIC: module boot failed: ' . $this->boot_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}
	}

	/**
	 * Whether the plugin booted fully, for the diagnostics screen.
	 *
	 * @return bool
	 */
	public function booted_cleanly() {
		return empty( $this->boot_error ) && empty( $this->modules->failures() );
	}

	/**
	 * Everything that went wrong while loading, keyed by module id.
	 *
	 * @return array<string,array<string,string>|string>
	 */
	public function load_failures() {
		$failures = $this->modules->failures();

		if ( ! empty( $this->boot_error ) ) {
			$failures['bootstrap'] = array(
				'phase'   => 'boot',
				'message' => $this->boot_error,
				'file'    => '',
			);
		}

		return $failures;
	}

	/**
	 * Activation handler.
	 *
	 * Each step is isolated: a host that blocks one thing (a read-only uploads
	 * directory, a missing DB privilege, a PHP extension the renderer needs)
	 * must not turn into "the plugin could not be activated because it
	 * triggered a fatal error". Failures are recorded and surfaced as an admin
	 * notice so the site owner can see exactly which step failed and why.
	 *
	 * @return void
	 */
	public function on_activate() {
		// A module that failed to load means its services are missing; recording
		// that here turns a mysterious fatal into a named cause.
		$failures = $this->modules->failures();

		$steps = array(
			'database'     => static function ( Plugin $plugin ) {
				$plugin->service( 'migrator' )->install();
			},
			'scheduler'    => static function ( Plugin $plugin ) {
				$plugin->service( 'scheduler' )->install_events();
			},
			'capabilities' => static function ( Plugin $plugin ) {
				$plugin->service( 'capabilities' )->install_roles();
			},
			'settings'     => static function ( Plugin $plugin ) {
				$plugin->service( 'settings' )->ensure_defaults();
			},
			'rewrites'     => static function () {
				// A full flush is a heavy operation on a large site and some
				// hosts drop the request mid-way. Soft flush, then defer the
				// hard one to the next admin page load.
				flush_rewrite_rules( false );
				delete_option( 'cpic_rewrites_pending' );
			},
		);

		foreach ( $steps as $step => $callback ) {
			try {
				$callback( $this );
			} catch ( \Throwable $e ) {
				$failures[ $step ] = $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';

				if ( function_exists( 'error_log' ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
					error_log( 'CPIC activation step "' . $step . '" failed: ' . $failures[ $step ] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				}
			}
		}

		// Activation on shared hosting runs under a hard time limit. If the
		// request is killed part-way WordPress sees no completion and reports a
		// fatal, so record what a repair pass still needs to finish.
		$remaining = array();

		foreach ( array_keys( $steps ) as $step ) {
			if ( isset( $failures[ $step ] ) ) {
				$remaining[] = $step;
			}
		}

		if ( 'yes' !== (string) get_option( 'cpic_installation_complete' ) ) {
			update_option( 'cpic_installation_complete', 'partial', false );
		}

		if ( ! empty( $remaining ) ) {
			update_option( 'cpic_activation_pending', $remaining, false );
		} else {
			delete_option( 'cpic_activation_pending' );
			update_option( 'cpic_installation_complete', 'yes', false );
		}

		if ( ! empty( $failures ) ) {
			update_option(
				'cpic_activation_errors',
				array(
					'time'   => time(),
					'php'    => PHP_VERSION,
					'errors' => $failures,
				),
				false
			);
			return;
		}

		delete_option( 'cpic_activation_errors' );
	}

	/**
	 * Deactivation handler: unschedule work but never destroy data.
	 *
	 * @return void
	 */
	public function on_deactivate() {
		$this->service( 'scheduler' )->clear_events();
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {
	}

	/**
	 * Prevent unserialising the singleton.
	 *
	 * @throws \Exception Always.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize CPIC\\Core\\Plugin' );
	}
}
