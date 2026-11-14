<?php
/**
 * Calendar REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/calendar`
 */
final class CalendarController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/calendar';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$this->add(
			'',
			array( 'GET' => array( 'callback' => array( $this, 'month' ) ) )
		);

		$this->add(
			'/upcoming',
			array( 'GET' => array( 'callback' => array( $this, 'upcoming' ) ) )
		);

		$this->add(
			'/deliveries/(?P<id>\d+)/reschedule',
			array(
				'POST' => array(
					'callback'   => array( $this, 'reschedule' ),
					'permission' => $this->requires( 'cpic_schedule' ),
				),
			)
		);
	}

	/**
	 * Month view.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function month( $request ) {
		return $this->ok(
			$this->container->get( 'calendar' )->month(
				$this->param( $request, 'month', current_time( 'Y-m' ) ),
				array(
					'campaign_id' => $this->param_int( $request, 'campaign_id' ),
					'status'      => $this->param( $request, 'status' ),
				)
			)
		);
	}

	/**
	 * Upcoming events.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function upcoming( $request ) {
		return $this->ok(
			array(
				'events' => $this->container->get( 'calendar' )->upcoming( $this->param_int( $request, 'limit', 5 ) ),
			)
		);
	}

	/**
	 * Reschedule a delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reschedule( $request ) {
		return $this->maybe(
			$this->container->get( 'calendar' )->reschedule(
				$this->param_int( $request, 'id' ),
				$this->param( $request, 'scheduled_for' )
			)
		);
	}
}
