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
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		register_activation_hook( CPIC_PLUGIN_FILE, array( $this, 'on_activate' ) );
		register_deactivation_hook( CPIC_PLUGIN_FILE, array( $this, 'on_deactivate' ) );

		$this->modules->boot();
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
				flush_rewrite_rules( false );
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
