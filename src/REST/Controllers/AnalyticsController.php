<?php
/**
 * Analytics REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/analytics`
 */
final class AnalyticsController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/analytics';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$view = $this->requires( 'cpic_view_analytics' );

		$this->add(
			'/overview',
			array( 'GET' => array( 'callback' => array( $this, 'overview' ), 'permission' => $view ) )
		);

		$this->add(
			'/deliveries/(?P<id>\d+)/metrics',
			array(
				'POST' => array(
					'callback'   => array( $this, 'fetch_metrics' ),
					'permission' => $this->requires( 'cpic_publish' ),
				),
			)
		);
	}

	/**
	 * Overview metrics.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function overview( $request ) {
		return $this->ok(
			$this->container->get( 'analytics' )->overview(
				array(
					'days' => $this->param_int( $request, 'days', 30 ),
					'from' => $this->param( $request, 'from' ),
					'to'   => $this->param( $request, 'to' ),
				)
			)
		);
	}

	/**
	 * Fetch external platform metrics for a delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function fetch_metrics( $request ) {
		return $this->maybe( $this->container->get( 'analytics' )->fetch_external( $this->param_int( $request, 'id' ) ) );
	}
}
