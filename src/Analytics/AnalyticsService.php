<?php
/**
 * Analytics that only reports what it actually knows.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Analytics;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two clearly separated metric families:
 *
 *  - **internal**: things the plugin itself witnessed (artifacts generated,
 *    deliveries attempted, failures, retries, queue lag). Always accurate.
 *  - **external**: reach/likes/impressions as reported by Facebook or
 *    Telegram. These are only present once they have been fetched, and are
 *    reported as `available: false` until then — never estimated.
 */
final class AnalyticsService {

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
	 * Overview for a date range.
	 *
	 * @param array<string,mixed> $args { @type int $days; @type string $from; @type string $to; }
	 * @return array<string,mixed>
	 */
	public function overview( array $args = array() ) {
		$range = $this->range( $args );

		$deliveries = $this->container->get( 'deliveries' );
		$artifacts  = $this->container->get( 'artifact_manager' );

		$counts = $deliveries->status_counts(
			array(
				'created_at' => array( 'between', $range['from'], $range['to'] ),
			)
		);

		$storage = $artifacts->summary();

		$internal = array(
			'deliveries' => array(
				'published' => (int) $counts['published'],
				'failed'    => (int) $counts['failed'],
				'cancelled' => (int) $counts['cancelled'],
				'pending'   => (int) $counts['pending'] + (int) $counts['queued'] + (int) $counts['processing'] + (int) $counts['waiting_for_artifact'],
				'total'     => array_sum( $counts ),
			),
			'artifacts'  => array(
				'total'   => (int) $storage['total'],
				'bytes'   => (int) $storage['bytes'],
				'created' => $this->container->get( 'analytics_repo' )->artifacts_generated( $range['from'], $range['to'] ),
			),
			'render_ms'  => round( $this->container->get( 'analytics_repo' )->average_render_ms( $range['from'], $range['to'] ), 1 ),
			'retries'    => $this->container->get( 'analytics_repo' )->retry_count( $range['from'], $range['to'] ),
			'success_rate' => $this->rate( (int) $counts['published'], (int) $counts['published'] + (int) $counts['failed'] ),
		);

		$external_rows = $this->container->get( 'analytics_repo' )->external_metrics( $range['from'], $range['to'] );

		return array(
			'range'    => $range,
			'internal' => $internal,
			'external' => $this->external( $external_rows ),
			'providers' => $this->provider_breakdown( $range ),
			'daily'    => $this->container->get( 'analytics_repo' )->daily_deliveries( $range['from'], $range['to'] ),
			'failures' => $this->container->get( 'analytics_repo' )->failure_distribution( $range['from'], $range['to'] ),
		);
	}

	/**
	 * Fetch external metrics for a delivery (Facebook insights).
	 *
	 * Deliberately opt-in and honest: if the API does not return a field, the
	 * field is absent rather than zero.
	 *
	 * @param int $delivery_id Delivery id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function fetch_external( $delivery_id ) {
		$delivery = $this->container->get( 'deliveries' )->find( (int) $delivery_id );

		if ( ! $delivery || 'published' !== (string) $delivery['status'] || '' === (string) $delivery['remote_id'] ) {
			return new \WP_Error( 'cpic_not_published', __( 'Metrics are only available for published deliveries.', 'custom-post-image-creator' ), array( 'status' => 409 ) );
		}

		if ( 'facebook' !== (string) $delivery['provider'] ) {
			return new \WP_Error( 'cpic_metrics_unsupported', __( 'This platform does not expose per-post metrics through its public API.', 'custom-post-image-creator' ), array( 'status' => 501 ) );
		}

		$destination = $this->container->get( 'destinations' )->find( (int) $delivery['destination_id'] );

		if ( ! $destination ) {
			return new \WP_Error( 'cpic_destination_missing', __( 'The destination for this delivery no longer exists.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$publisher = $this->container->get( 'publishers' )->get( 'facebook' );

		if ( ! $publisher ) {
			return new \WP_Error( 'cpic_publisher_missing', __( 'The Facebook publisher is not available.', 'custom-post-image-creator' ) );
		}

		/**
		 * Filter the metrics fetched for a published delivery.
		 *
		 * @param array<string,mixed>|\WP_Error $metrics     Metrics (default empty).
		 * @param array<string,mixed>           $delivery    Delivery row.
		 * @param array<string,mixed>           $destination Destination row.
		 */
		$metrics = apply_filters( 'cpic/analytics/fetch_external', array(), $delivery, $destination );

		if ( is_wp_error( $metrics ) ) {
			return $metrics;
		}

		foreach ( (array) $metrics as $metric => $value ) {
			$this->container->get( 'analytics_repo' )->record_metric(
				'external.' . $metric,
				(float) $value,
				'delivery',
				(int) $delivery_id,
				array( 'provider' => (string) $delivery['provider'] )
			);
		}

		return array(
			'delivery_id' => (int) $delivery_id,
			'metrics'     => (array) $metrics,
			'fetched_at'  => current_time( 'mysql' ),
		);
	}

	/**
	 * External metric aggregate.
	 *
	 * @param array<int,array<string,mixed>> $rows Metric rows.
	 * @return array<string,mixed>
	 */
	private function external( array $rows ) {
		if ( empty( $rows ) ) {
			return array(
				'available' => false,
				'message'   => __( 'No platform engagement metrics have been fetched for this period.', 'custom-post-image-creator' ),
				'metrics'   => array(),
			);
		}

		$metrics = array();

		foreach ( $rows as $row ) {
			$key = (string) $row['metric'];
			if ( ! isset( $metrics[ $key ] ) ) {
				$metrics[ $key ] = array( 'total' => 0, 'samples' => 0 );
			}
			$metrics[ $key ]['total']   += (float) $row['value'];
			$metrics[ $key ]['samples'] += 1;
		}

		foreach ( $metrics as $key => $data ) {
			$metrics[ $key ]['average'] = $data['samples'] > 0 ? round( $data['total'] / $data['samples'], 2 ) : 0;
		}

		return array(
			'available' => true,
			'message'   => '',
			'metrics'   => $metrics,
			'fetched_at' => (string) $rows[0]['created_at'],
		);
	}

	/**
	 * Per-provider breakdown.
	 *
	 * @param array<string,string> $range Range.
	 * @return array<string,mixed>
	 */
	private function provider_breakdown( array $range ) {
		$rows = $this->container->get( 'deliveries' )->provider_breakdown( $range['from'], $range['to'] );

		$out = array();

		foreach ( $rows as $row ) {
			$provider = (string) $row['provider'];
			if ( '' === $provider ) {
				continue;
			}
			if ( ! isset( $out[ $provider ] ) ) {
				$out[ $provider ] = array( 'published' => 0, 'failed' => 0, 'total' => 0 );
			}
			$out[ $provider ][ (string) $row['status'] ] = (int) $row['total'];
			$out[ $provider ]['total'] += (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Success rate as a percentage.
	 *
	 * @param int $success Successful count.
	 * @param int $total   Total attempts.
	 * @return float
	 */
	private function rate( $success, $total ) {
		if ( $total <= 0 ) {
			return 0.0;
		}

		return round( ( $success / $total ) * 100, 1 );
	}

	/**
	 * Resolve a date range.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array{from:string,to:string,days:int}
	 */
	public function range( array $args = array() ) {
		$days = isset( $args['days'] ) ? max( 1, min( 365, (int) $args['days'] ) ) : 30;

		$to = isset( $args['to'] ) && '' !== $args['to']
			? gmdate( 'Y-m-d 23:59:59', (int) strtotime( (string) $args['to'] ) )
			: current_time( 'mysql' );

		$from = isset( $args['from'] ) && '' !== $args['from']
			? gmdate( 'Y-m-d 00:00:00', (int) strtotime( (string) $args['from'] ) )
			: gmdate( 'Y-m-d 00:00:00', (int) strtotime( (string) $to ) - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

		return array(
			'from' => $from,
			'to'   => $to,
			'days' => (int) ceil( ( (int) strtotime( $to ) - (int) strtotime( $from ) ) / DAY_IN_SECONDS ) + 1,
		);
	}
}
