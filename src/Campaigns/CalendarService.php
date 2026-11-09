<?php
/**
 * Editorial calendar.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Campaigns;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A month/week/day view over three real sources of truth: scheduled delivery
 * runs, campaign windows and posts in the queue. Nothing is invented to fill a
 * grid — a day with no work is an empty day.
 */
final class CalendarService {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Month view.
	 *
	 * @param string              $month YYYY-MM (defaults to the current month).
	 * @param array<string,mixed> $args  Filters: `campaign_id`, `provider`, `status`.
	 * @return array<string,mixed>
	 */
	public function month( $month, array $args = array() ) {
		$month = preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ? (string) $month : current_time( 'Y-m' );

		$start = $month . '-01 00:00:00';
		$end   = gmdate( 'Y-m-t 23:59:59', (int) strtotime( $start ) );
		$days  = (int) gmdate( 't', (int) strtotime( $start ) );

		$events = $this->events( $start, $end, $args );

		$by_day = array();

		for ( $day = 1; $day <= $days; $day++ ) {
			$key          = $month . '-' . str_pad( (string) $day, 2, '0', STR_PAD_LEFT );
			$by_day[ $key ] = array(
				'date'       => $key,
				'day'        => $day,
				'weekday'    => (int) gmdate( 'N', (int) strtotime( $key ) ),
				'is_today'   => $key === current_time( 'Y-m-d' ),
				'is_past'    => $key < current_time( 'Y-m-d' ),
				'events'     => array(),
				'counts'     => array( 'scheduled' => 0, 'published' => 0, 'failed' => 0 ),
			);
		}

		foreach ( $events as $event ) {
			$key = substr( (string) $event['when'], 0, 10 );

			if ( ! isset( $by_day[ $key ] ) ) {
				continue;
			}

			$by_day[ $key ]['events'][] = $event;

			$bucket = 'scheduled';
			if ( 'published' === $event['status'] ) {
				$bucket = 'published';
			} elseif ( in_array( $event['status'], array( 'failed', 'partial' ), true ) ) {
				$bucket = 'failed';
			}

			$by_day[ $key ]['counts'][ $bucket ]++;
		}

		return array(
			'month'      => $month,
			'label'      => gmdate( 'F Y', (int) strtotime( $start ) ),
			'start'      => $start,
			'end'        => $end,
			'days'       => array_values( $by_day ),
			'week_start' => (int) $this->container->get( 'settings' )->get( 'start_of_week', 0 ),
			'events'     => $events,
			'totals'     => $this->totals( $events ),
		);
	}

	/**
	 * Events between two datetimes.
	 *
	 * @param string              $from MySQL datetime.
	 * @param string              $to   MySQL datetime.
	 * @param array<string,mixed> $args Filters.
	 * @return array<int,array<string,mixed>>
	 */
	public function events( $from, $to, array $args = array() ) {
		$events = array();

		// 1. Scheduled / completed delivery runs.
		foreach ( $this->container->get( 'deliveries' )->runs_between( $from, $to, $args ) as $run ) {
			$events[] = $this->run_event( $run );
		}

		// 2. Campaign windows.
		foreach ( $this->container->get( 'campaigns' )->select( array(), 'start_at ASC' ) as $campaign ) {
			$start = (string) $campaign['start_at'];
			$end   = (string) $campaign['end_at'];

			if ( '' === $start || '0000-00-00 00:00:00' === $start ) {
				continue;
			}

			if ( $end && strtotime( $end ) < strtotime( $from ) ) {
				continue;
			}

			if ( strtotime( $start ) > strtotime( $to ) ) {
				continue;
			}

			$events[] = array(
				'id'       => 'campaign-' . (int) $campaign['id'],
				'type'     => 'campaign',
				'when'     => substr( $start, 0, 16 ) . ':00',
				'end'      => $end ? substr( $end, 0, 16 ) . ':00' : '',
				'title'    => (string) $campaign['name'],
				'status'   => (string) $campaign['status'],
				'provider' => '',
				'post_id'  => 0,
				'url'      => '',
			);
		}

		usort(
			$events,
			static function ( $a, $b ) {
				return strcmp( (string) $a['when'], (string) $b['when'] );
			}
		);

		/**
		 * Filter calendar events.
		 *
		 * @param array<int,array<string,mixed>> $events Events.
		 * @param string                         $from   Range start.
		 * @param string                         $to     Range end.
		 * @param array<string,mixed>            $args   Filters.
		 */
		return apply_filters( 'cpic/calendar/events', $events, $from, $to, $args );
	}

	/**
	 * Upcoming work for a dashboard widget.
	 *
	 * @param int $limit Max events.
	 * @return array<int,array<string,mixed>>
	 */
	public function upcoming( $limit = 5 ) {
		$events = $this->events(
			current_time( 'mysql' ),
			gmdate( 'Y-m-d H:i:s', time() + ( 14 * DAY_IN_SECONDS ) )
		);

		return array_slice( $events, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Reschedule a delivery.
	 *
	 * @param int    $delivery_id Delivery id.
	 * @param string $when        New datetime (site timezone).
	 * @return bool|\WP_Error
	 */
	public function reschedule( $delivery_id, $when ) {
		$delivery = $this->container->get( 'deliveries' )->find( (int) $delivery_id );

		if ( ! $delivery ) {
			return new \WP_Error( 'cpic_delivery_missing', __( 'That delivery no longer exists.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		if ( 'published' === (string) $delivery['status'] ) {
			return new \WP_Error( 'cpic_already_published', __( 'A published delivery cannot be rescheduled.', 'custom-post-image-creator' ), array( 'status' => 409 ) );
		}

		$timestamp = strtotime( (string) $when );

		if ( false === $timestamp ) {
			return new \WP_Error( 'cpic_bad_date', __( 'That is not a valid date and time.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		$this->container->get( 'deliveries' )->update(
			(int) $delivery_id,
			array(
				'scheduled_for' => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'status'        => 'queued',
				'claimed_at'    => null,
			)
		);

		$this->container->get( 'audit' )->record(
			'delivery.rescheduled',
			'delivery',
			(int) $delivery_id,
			gmdate( 'Y-m-d H:i:s', $timestamp )
		);

		return true;
	}

	/**
	 * Build a calendar event from a run row.
	 *
	 * @param array<string,mixed> $run Run row.
	 * @return array<string,mixed>
	 */
	private function run_event( array $run ) {
		$post = get_post( (int) $run['post_id'] );

		$when = ! empty( $run['scheduled_for'] ) ? (string) $run['scheduled_for'] : (string) $run['created_at'];

		return array(
			'id'       => 'run-' . (int) $run['id'],
			'type'     => 'delivery_run',
			'when'     => substr( $when, 0, 16 ) . ':00',
			'end'      => '',
			'title'    => $post ? (string) $post->post_title : sprintf(
				/* translators: %d: post id */
				__( 'Post #%d', 'custom-post-image-creator' ),
				(int) $run['post_id']
			),
			'status'   => (string) $run['status'],
			'provider' => '',
			'post_id'  => (int) $run['post_id'],
			'run_id'   => (int) $run['id'],
			'url'      => $post ? (string) get_permalink( (int) $post->ID ) : '',
		);
	}

	/**
	 * Totals for a set of events.
	 *
	 * @param array<int,array<string,mixed>> $events Events.
	 * @return array<string,int>
	 */
	private function totals( array $events ) {
		$totals = array( 'total' => count( $events ), 'scheduled' => 0, 'published' => 0, 'failed' => 0, 'campaigns' => 0 );

		foreach ( $events as $event ) {
			if ( 'campaign' === $event['type'] ) {
				$totals['campaigns']++;
				continue;
			}

			if ( 'published' === $event['status'] || 'completed' === $event['status'] ) {
				$totals['published']++;
			} elseif ( in_array( $event['status'], array( 'failed', 'partial' ), true ) ) {
				$totals['failed']++;
			} else {
				$totals['scheduled']++;
			}
		}

		return $totals;
	}
}
