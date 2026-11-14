<?php
/**
 * Shared REST controller behaviour.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Route helpers, permission callbacks and response shaping.
 */
abstract class BaseController {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	protected $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Base route for this controller ('' = root).
	 *
	 * @return string
	 */
	abstract public function base();

	/**
	 * Register the controller's routes.
	 *
	 * @return void
	 */
	abstract public function register();

	/**
	 * Register one route.
	 *
	 * @param string $path       Path relative to the base ('' = collection).
	 * @param array  $methods    method => { callback, permission? }.
	 * @return void
	 */
	protected function add( $path, array $methods ) {
		$args = array();

		foreach ( $methods as $method => $definition ) {
			$args[ strtoupper( $method ) ] = array(
				'callback'            => $definition['callback'],
				'permission_callback' => isset( $definition['permission'] )
					? $definition['permission']
					: array( $this, 'can_access' ),
			);
		}

		register_rest_route( CPIC_REST_NAMESPACE, $this->base() . $path, $args );
	}

	/**
	 * Default permission: any plugin user.
	 *
	 * @return bool
	 */
	public function can_access() {
		return $this->container->get( 'capabilities' )->can( 'cpic_access' );
	}

	/**
	 * Permission factory for a specific capability.
	 *
	 * @param string $cap Capability (short name allowed).
	 * @return callable
	 */
	protected function requires( $cap ) {
		return function () use ( $cap ) {
			if ( ! $this->container->get( 'capabilities' )->can( $cap ) ) {
				return new \WP_Error(
					'cpic_forbidden',
					__( 'You do not have permission to do that.', 'custom-post-image-creator' ),
					array( 'status' => is_user_logged_in() ? 403 : 401 )
				);
			}

			return true;
		};
	}

	/**
	 * Success response.
	 *
	 * @param mixed $data    Data.
	 * @param int   $status  Status.
	 * @return \WP_REST_Response
	 */
	protected function ok( $data, $status = 200 ) {
		return new \WP_REST_Response( $data, (int) $status );
	}

	/**
	 * Convert a WP_Error into a REST response with a proper status code.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_REST_Response
	 */
	protected function fail( \WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;

		return new \WP_REST_Response(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => is_array( $data ) ? $data : array(),
			),
			$status
		);
	}

	/**
	 * Pass through a value that may be a WP_Error.
	 *
	 * @param mixed $value  Value.
	 * @param int   $status Status for success.
	 * @return \WP_REST_Response
	 */
	protected function maybe( $value, $status = 200 ) {
		return is_wp_error( $value ) ? $this->fail( $value ) : $this->ok( $value, $status );
	}

	/**
	 * Integer route param.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $key     Key.
	 * @param int              $default Default.
	 * @return int
	 */
	protected function param_int( $request, $key, $default = 0 ) {
		$value = $request->get_param( $key );

		return null === $value || '' === $value ? (int) $default : (int) $value;
	}

	/**
	 * String route param.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $key     Key.
	 * @param string           $default Default.
	 * @return string
	 */
	protected function param( $request, $key, $default = '' ) {
		$value = $request->get_param( $key );

		return null === $value ? (string) $default : (string) $value;
	}

	/**
	 * Boolean route param.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $key     Key.
	 * @param bool             $default Default.
	 * @return bool
	 */
	protected function param_bool( $request, $key, $default = false ) {
		$value = $request->get_param( $key );

		if ( null === $value ) {
			return (bool) $default;
		}

		return in_array( $value, array( true, 1, '1', 'true', 'yes' ), true );
	}
}
