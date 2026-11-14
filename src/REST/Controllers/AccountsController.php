<?php
/**
 * Accounts / destinations REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/accounts`
 *
 * Credentials are write-only: they can be sent, and are reported as present or
 * absent, but never returned.
 */
final class AccountsController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/accounts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$manage = $this->requires( 'cpic_manage_accounts' );

		$this->add(
			'',
			array(
				'GET'  => array( 'callback' => array( $this, 'index' ) ),
				'POST' => array( 'callback' => array( $this, 'create' ), 'permission' => $manage ),
			)
		);

		$this->add(
			'/providers',
			array( 'GET' => array( 'callback' => array( $this, 'providers' ) ) )
		);

		$this->add(
			'/(?P<id>\d+)',
			array(
				'GET'    => array( 'callback' => array( $this, 'show' ) ),
				'PUT'    => array( 'callback' => array( $this, 'update' ), 'permission' => $manage ),
				'DELETE' => array( 'callback' => array( $this, 'destroy' ), 'permission' => $manage ),
			)
		);

		$this->add(
			'/(?P<id>\d+)/verify',
			array( 'POST' => array( 'callback' => array( $this, 'verify' ), 'permission' => $manage ) )
		);

		$this->add(
			'/(?P<id>\d+)/default',
			array( 'POST' => array( 'callback' => array( $this, 'make_default' ), 'permission' => $manage ) )
		);
	}

	/**
	 * List destinations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index( $request ) {
		$accounts = $this->container->get( 'accounts' );

		return $this->ok(
			array(
				'accounts' => $accounts->all(
					array(
						'provider' => $this->param( $request, 'provider' ),
						'status'   => $this->param( $request, 'status' ),
					)
				),
				'summary'  => $accounts->summary(),
			)
		);
	}

	/**
	 * Provider descriptors.
	 *
	 * @return \WP_REST_Response
	 */
	public function providers() {
		return $this->ok(
			array(
				'providers' => $this->container->get( 'publishers' )->descriptors(),
			)
		);
	}

	/**
	 * One destination.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function show( $request ) {
		$account = $this->container->get( 'accounts' )->get( $this->param_int( $request, 'id' ) );

		if ( ! $account ) {
			return $this->fail( new \WP_Error( 'cpic_account_missing', __( 'That account no longer exists.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		return $this->ok( $account );
	}

	/**
	 * Create a destination.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create( $request ) {
		$result = $this->container->get( 'accounts' )->create(
			array(
				'provider'     => $this->param( $request, 'provider' ),
				'label'        => $this->param( $request, 'label' ),
				'account_id'   => $this->param( $request, 'account_id' ),
				'account_name' => $this->param( $request, 'account_name' ),
				'credentials'  => (array) $request->get_param( 'credentials' ),
				'config'       => (array) $request->get_param( 'config' ),
				'status'       => $this->param( $request, 'status', 'connected' ),
				'priority'     => $this->param_int( $request, 'priority', 5 ),
			)
		);

		return $this->maybe( $result, 201 );
	}

	/**
	 * Update a destination.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update( $request ) {
		$data = array();

		foreach ( array( 'label', 'account_name', 'status' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$data[ $key ] = $request->get_param( $key );
			}
		}

		if ( null !== $request->get_param( 'config' ) ) {
			$data['config'] = (array) $request->get_param( 'config' );
		}

		if ( null !== $request->get_param( 'credentials' ) ) {
			$data['credentials'] = (array) $request->get_param( 'credentials' );
		}

		return $this->maybe( $this->container->get( 'accounts' )->update( $this->param_int( $request, 'id' ), $data ) );
	}

	/**
	 * Delete a destination.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function destroy( $request ) {
		return $this->maybe( $this->container->get( 'accounts' )->delete( $this->param_int( $request, 'id' ) ) );
	}

	/**
	 * Verify credentials against the provider.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function verify( $request ) {
		$id          = $this->param_int( $request, 'id' );
		$destination = $this->container->get( 'destinations' )->find( $id );

		if ( ! $destination ) {
			return $this->fail( new \WP_Error( 'cpic_account_missing', __( 'That account no longer exists.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		$publisher = $this->container->get( 'publishers' )->get( (string) $destination['provider'] );

		if ( ! $publisher ) {
			return $this->fail( new \WP_Error( 'cpic_bad_provider', __( 'Unknown publishing provider.', 'custom-post-image-creator' ), array( 'status' => 400 ) ) );
		}

		$result = $publisher->verify( $destination );

		if ( is_wp_error( $result ) ) {
			$this->container->get( 'accounts' )->set_status( $id, 'error', $result->get_error_message() );

			return $this->fail( $result );
		}

		$this->container->get( 'destinations' )->update(
			$id,
			array(
				'status'            => 'connected',
				'account_name'      => isset( $result['account_name'] ) ? (string) $result['account_name'] : '',
				'last_validated_at' => current_time( 'mysql' ),
				'last_error'        => null,
			)
		);

		return $this->ok( $result );
	}

	/**
	 * Make a destination the default for its provider.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function make_default( $request ) {
		$id          = $this->param_int( $request, 'id' );
		$destination = $this->container->get( 'destinations' )->find( $id );

		if ( ! $destination ) {
			return $this->fail( new \WP_Error( 'cpic_account_missing', __( 'That account no longer exists.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		$this->container->get( 'destinations' )->set_default( $id, (string) $destination['provider'] );

		return $this->ok( array( 'default' => true ) );
	}
}
