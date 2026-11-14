<?php
/**
 * Minimal service container with lazy factories.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-11-ish container. Factories are only executed when a service is first
 * requested, which keeps front-end requests cheap.
 */
final class Container {

	/**
	 * Resolved instances.
	 *
	 * @var array<string,mixed>
	 */
	private $instances = array();

	/**
	 * Factories.
	 *
	 * @var array<string,callable>
	 */
	private $factories = array();

	/**
	 * Register a lazy factory.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory Factory receiving this container.
	 * @return void
	 */
	public function factory( $id, callable $factory ) {
		$this->factories[ $id ] = $factory;
	}

	/**
	 * Register an already-built instance.
	 *
	 * @param string $id       Service id.
	 * @param mixed  $instance Instance.
	 * @return void
	 */
	public function instance( $id, $instance ) {
		$this->instances[ $id ] = $instance;
	}

	/**
	 * Whether a service is known.
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( $id ) {
		return isset( $this->instances[ $id ] ) || isset( $this->factories[ $id ] );
	}

	/**
	 * Every registered service id (used by diagnostics and integrity checks).
	 *
	 * @return array<int,string>
	 */
	public function ids() {
		$ids = array_merge( array_keys( $this->instances ), array_keys( $this->factories ) );

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * Get a service, building it on first use.
	 *
	 * @param string $id Service id.
	 * @return mixed|null
	 */
	public function get( $id ) {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( isset( $this->factories[ $id ] ) ) {
			$instance              = call_user_func( $this->factories[ $id ], $this );
			$this->instances[ $id ] = $instance;
			return $instance;
		}

		return null;
	}

	/**
	 * Build a service without caching it (used for stateless helpers/tests).
	 *
	 * @param string $id Service id.
	 * @return mixed|null
	 */
	public function make( $id ) {
		if ( isset( $this->factories[ $id ] ) ) {
			return call_user_func( $this->factories[ $id ], $this );
		}
		return $this->get( $id );
	}

	/**
	 * All resolved service ids (diagnostics).
	 *
	 * @return string[]
	 */
	public function resolved_ids() {
		return array_keys( $this->instances );
	}
}
