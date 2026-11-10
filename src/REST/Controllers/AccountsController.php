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
			'/(?P<id>\\d+)',
			array(
				'GET'    => array( 'callback' => array( $this, 'show' ) ),
				'PUT'    => array( 'callback' => array( $this, 'update' ), 'permission' => $manage ),
				'DELETE' => array( 'callback' => array( $this, 'destroy' ), 'permission' => $manage ),
			)
		);

		$this->add(
			'/(?P<id>\\d+)/verify',
			array( 'POST' => array( 'callback' => array( $this, 'verify' ), 'permission' => $manage ) )
		);

		$this->add(
			'/(?P<id>\\d+)/default',
			array( 'POST' => array( 'callback' => array( $this, 'make_default' ), 'permission' => $manage ) )
		);

		$this->add(
			'/facebook/oauth-url',
			array( 'POST' => array( 'callback' => array( $this, 'facebook_oauth_url' ), 'permission' => $manage ) )
		);

		$this->add(
			'/facebook/pages',
			array( 'GET' => array( 'callback' => array( $this, 'facebook_pages' ), 'permission' => $manage ) )
		);

		$this->add(
			'/facebook/connect',
			array( 'POST' => array( 'callback' => array( $this, 'facebook_connect' ), 'permission' => $manage ) )
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

	/**
	 * Facebook OAuth: get authorization URL for popup.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function facebook_oauth_url( $request ) {
		// Hassle-free: if App ID/Secret were typed but not yet saved, accept them here and persist.
		$app_id     = $this->param( $request, 'app_id' );
		$app_secret = $this->param( $request, 'app_secret' );
		if ( '' !== $app_id ) {
			$this->container->get( 'settings' )->set( 'cpic_fb_app_id', sanitize_text_field( $app_id ) );
		}
		if ( '' !== $app_secret ) {
			$this->container->get( 'settings' )->set( 'cpic_fb_app_secret', $app_secret );
		}

		$oauth = $this->container->get( 'facebook_oauth' );
		$url   = $oauth->authorization_url( get_current_user_id() );

		if ( is_wp_error( $url ) ) {
			return $this->fail( $url );
		}

		return $this->ok(
			array(
				'authorization_url' => $url,
				'redirect_uri'      => $oauth->redirect_uri(),
			)
		);
	}

	/**
	 * Facebook OAuth: list pages from picker transient (after popup callback).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function facebook_pages( $request ) {
		$token   = $this->param( $request, 'picker_token' );
		$oauth   = $this->container->get( 'facebook_oauth' );
		$payload = $oauth->get_picker_payload( $token );

		if ( is_wp_error( $payload ) ) {
			return $this->fail( $payload );
		}

		// Strip tokens for transport; only expose display data.
		$pages = array();
		foreach ( (array) $payload['pages'] as $page ) {
			$pages[] = array(
				'id'        => (string) $page['id'],
				'name'      => (string) $page['name'],
				'category'  => isset( $page['category'] ) ? (string) $page['category'] : '',
				'fan_count' => isset( $page['fan_count'] ) ? (int) $page['fan_count'] : 0,
				'picture'   => isset( $page['picture'] ) ? (string) $page['picture'] : '',
			);
		}

		return $this->ok(
			array(
				'pages' => $pages,
			)
		);
	}

	/**
	 * Facebook OAuth: connect selected pages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function facebook_connect( $request ) {
		$token    = $this->param( $request, 'picker_token' );
		$page_ids = $request->get_param( 'page_ids' );

		if ( is_string( $page_ids ) ) {
			$decoded = json_decode( $page_ids, true );
			if ( is_array( $decoded ) ) {
				$page_ids = $decoded;
			} else {
				$page_ids = array( $page_ids );
			}
		}

		$result = $this->container->get( 'facebook_oauth' )->connect_pages( $token, (array) $page_ids );

		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}

		return $this->ok( array( 'connected' => $result ) );
	}
}
