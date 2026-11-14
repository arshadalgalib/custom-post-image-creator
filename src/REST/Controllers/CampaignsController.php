<?php
/**
 * Campaigns REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/campaigns`
 */
final class CampaignsController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/campaigns';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$manage = $this->requires( 'cpic_manage_campaigns' );

		$this->add(
			'',
			array(
				'GET'  => array( 'callback' => array( $this, 'index' ) ),
				'POST' => array( 'callback' => array( $this, 'create' ), 'permission' => $manage ),
			)
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
			'/(?P<id>\d+)/posts',
			array(
				'GET'  => array( 'callback' => array( $this, 'posts' ) ),
				'POST' => array( 'callback' => array( $this, 'assign' ), 'permission' => $manage ),
				'DELETE' => array( 'callback' => array( $this, 'unassign' ), 'permission' => $manage ),
			)
		);

		$this->add(
			'/(?P<id>\d+)/status',
			array( 'POST' => array( 'callback' => array( $this, 'set_status' ), 'permission' => $manage ) )
		);
	}

	/**
	 * List campaigns.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index( $request ) {
		return $this->ok(
			array(
				'campaigns' => $this->container->get( 'campaign_manager' )->all(
					array(
						'status' => $this->param( $request, 'status' ),
						'limit'  => $this->param_int( $request, 'limit', 100 ),
					)
				),
			)
		);
	}

	/**
	 * One campaign.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function show( $request ) {
		$campaign = $this->container->get( 'campaign_manager' )->get( $this->param_int( $request, 'id' ) );

		if ( ! $campaign ) {
			return $this->fail( new \WP_Error( 'cpic_campaign_missing', __( 'Campaign not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		return $this->ok( $campaign );
	}

	/**
	 * Create.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create( $request ) {
		return $this->maybe( $this->container->get( 'campaign_manager' )->create( $this->payload( $request ) ), 201 );
	}

	/**
	 * Update.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update( $request ) {
		return $this->maybe( $this->container->get( 'campaign_manager' )->update( $this->param_int( $request, 'id' ), $this->payload( $request ) ) );
	}

	/**
	 * Delete.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function destroy( $request ) {
		return $this->maybe( $this->container->get( 'campaign_manager' )->delete( $this->param_int( $request, 'id' ) ) );
	}

	/**
	 * Posts in a campaign.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function posts( $request ) {
		return $this->ok(
			array(
				'posts' => $this->container->get( 'campaign_manager' )->posts( $this->param_int( $request, 'id' ) ),
			)
		);
	}

	/**
	 * Assign a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function assign( $request ) {
		return $this->maybe(
			$this->container->get( 'campaign_manager' )->assign_post(
				$this->param_int( $request, 'id' ),
				$this->param_int( $request, 'post_id' ),
				$this->param_int( $request, 'template_id' )
			)
		);
	}

	/**
	 * Unassign a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function unassign( $request ) {
		return $this->maybe(
			$this->container->get( 'campaign_manager' )->unassign_post(
				$this->param_int( $request, 'id' ),
				$this->param_int( $request, 'post_id' )
			)
		);
	}

	/**
	 * Change status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function set_status( $request ) {
		return $this->maybe(
			$this->container->get( 'campaign_manager' )->set_status(
				$this->param_int( $request, 'id' ),
				$this->param( $request, 'status' )
			)
		);
	}

	/**
	 * Request → campaign fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private function payload( $request ) {
		$data = array();

		foreach ( array( 'name', 'description', 'start_at', 'end_at', 'status', 'notes' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$data[ $key ] = $request->get_param( $key );
			}
		}

		foreach ( array( 'destinations', 'templates', 'tags' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$data[ $key ] = (array) $request->get_param( $key );
			}
		}

		if ( null !== $request->get_param( 'brand_id' ) ) {
			$data['brand_id'] = $this->param_int( $request, 'brand_id' );
		}

		return $data;
	}
}
