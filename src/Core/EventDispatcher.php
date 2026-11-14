<?php
/**
 * Event dispatcher bridging plugin events onto WordPress hooks.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits namespaced events (`cpic/{event}`) and keeps an in-request buffer for
 * diagnostics. Third party code can listen through WordPress hooks or
 * `add_listener()`.
 */
final class EventDispatcher {

	/**
	 * Listeners keyed by event name.
	 *
	 * @var array<string,array<int,callable>>
	 */
	private $listeners = array();

	/**
	 * Recent events (bounded ring buffer for diagnostics).
	 *
	 * @var array<int,array{event:string,payload:array,time:int}>
	 */
	private $recent = array();

	/**
	 * Maximum number of buffered events.
	 */
	const BUFFER_LIMIT = 100;

	/**
	 * Register an in-request listener.
	 *
	 * @param string   $event    Event name, e.g. 'delivery.completed'.
	 * @param callable $listener Listener.
	 * @return void
	 */
	public function add_listener( $event, callable $listener ) {
		$event = (string) $event;
		if ( ! isset( $this->listeners[ $event ] ) ) {
			$this->listeners[ $event ] = array();
		}
		$this->listeners[ $event ][] = $listener;
	}

	/**
	 * Dispatch an event.
	 *
	 * @param string $event   Event name.
	 * @param array  $payload Event payload.
	 * @return void
	 */
	public function dispatch( $event, array $payload = array() ) {
		$event = (string) $event;

		$this->recent[] = array(
			'event'   => $event,
			'payload' => $payload,
			'time'    => time(),
		);
		if ( count( $this->recent ) > self::BUFFER_LIMIT ) {
			array_shift( $this->recent );
		}

		if ( ! empty( $this->listeners[ $event ] ) ) {
			foreach ( $this->listeners[ $event ] as $listener ) {
				try {
					call_user_func( $listener, $payload, $event );
				} catch ( \Throwable $e ) {
					if ( function_exists( 'error_log' ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
						error_log( 'CPIC event listener failed for ' . $event . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
					}
				}
			}
		}

		/**
		 * Generic plugin event hook.
		 *
		 * @param array  $payload Event payload.
		 * @param string $event   Event name.
		 */
		do_action( 'cpic/event', $payload, $event );

		/**
		 * Specific event hook, e.g. `cpic/delivery.completed`.
		 *
		 * @param array $payload Event payload.
		 */
		do_action( 'cpic/' . $event, $payload );
	}

	/**
	 * Buffered events.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent() {
		return $this->recent;
	}
}
