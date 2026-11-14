<?php
/**
 * Module manager.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects ServiceProvider instances, boots them in order and tracks their
 * status for the diagnostics screen.
 */
final class ModuleManager {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Registered providers.
	 *
	 * @var ServiceProvider[]
	 */
	private $providers = array();

	/**
	 * Modules that failed to load (id => phase/message/file).
	 *
	 * @var array<string,array{phase:string,message:string,file:string}>
	 */
	private $failures = array();

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Add a provider.
	 *
	 * @param ServiceProvider $provider Provider.
	 * @return void
	 */
	public function add( ServiceProvider $provider ) {
		$this->providers[] = $provider;
	}

	/**
	 * Boot all providers.
	 *
	 * Providers run at file-include time, *after* the activation hook has been
	 * registered. An exception escaping here therefore kills the request at the
	 * exact moment WordPress is activating the plugin, which is reported as
	 * "could not be activated because it triggered a fatal error" with no clue
	 * about the cause. Each provider is isolated instead: one broken module
	 * degrades its own feature and the rest of the plugin keeps working, and the
	 * failure is recorded for the admin notice and the error log.
	 *
	 * @return void
	 */
	public function boot() {
		foreach ( $this->providers as $provider ) {
			$this->guard( $provider, 'register' );
		}

		foreach ( $this->providers as $provider ) {
			$this->guard( $provider, 'boot' );
		}
	}

	/**
	 * Load one provider, recording rather than propagating a failure.
	 *
	 * @param ServiceProvider $provider Provider.
	 * @param string          $phase    Either `register` or `boot`.
	 * @return bool Whether the provider loaded.
	 */
	private function guard( ServiceProvider $provider, $phase ) {
		try {
			if ( 'register' === $phase ) {
				$provider->register( $this->container );
			} else {
				$provider->boot( $this->container );
			}

			return true;
		} catch ( \Throwable $e ) {
			$this->failures[ $provider->id() ] = array(
				'phase'   => $phase,
				'message' => $e->getMessage(),
				'file'    => basename( $e->getFile() ) . ':' . $e->getLine(),
			);

			if ( function_exists( 'error_log' ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
					sprintf(
						'CPIC: module "%1$s" failed during %2$s: %3$s (%4$s)',
						$provider->id(),
						$phase,
						$e->getMessage(),
						basename( $e->getFile() ) . ':' . $e->getLine()
					)
				);
			}

			return false;
		}
	}

	/**
	 * Modules that failed to load, keyed by id.
	 *
	 * @return array<string,array{phase:string,message:string,file:string}>
	 */
	public function failures() {
		return $this->failures;
	}

	/**
	 * Provider ids.
	 *
	 * @return string[]
	 */
	public function ids() {
		return array_map(
			static function ( ServiceProvider $provider ) {
				return $provider->id();
			},
			$this->providers
		);
	}
}
