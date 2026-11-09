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
	 * @return void
	 */
	public function boot() {
		foreach ( $this->providers as $provider ) {
			$provider->register( $this->container );
		}
		foreach ( $this->providers as $provider ) {
			$provider->boot( $this->container );
		}
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
