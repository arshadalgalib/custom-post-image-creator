<?php
/**
 * Base service provider contract.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A module of the plugin. Providers register services first (all providers),
 * then attach WordPress hooks in boot().
 */
abstract class ServiceProvider {

	/**
	 * Stable module id.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Register container bindings.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ) {
	}

	/**
	 * Attach hooks.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function boot( Container $container ) {
	}
}
