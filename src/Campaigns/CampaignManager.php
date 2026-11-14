<?php
/**
 * Campaigns: group posts under a shared brief, brand and window.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Campaigns;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A campaign is a named window in which a set of posts share a brand, a
 * template set and a destination list. It is deliberately *not* an
 * autopublisher: publishing still goes through DeliveryManager so every send
 * keeps its idempotency key and audit trail.
 */
final class CampaignManager {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Valid statuses.
	 */
	const STATUSES = array( 'draft', 'active', 'paused', 'completed', 'archived' );

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Repository.
	 *
	 * @return \CPIC\Database\Repositories\CampaignRepository
	 */
	public function repo() {
		return $this->container->get( 'campaigns' );
	}

	/**
	 * Create a campaign.
	 *
	 * @param array<string,mixed> $data Fields.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function create( array $data ) {
		$name = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';

		if ( '' === $name ) {
			return new \WP_Error( 'cpic_campaign_name', __( 'A campaign name is required.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		$start = $this->normalise_datetime( isset( $data['start_at'] ) ? $data['start_at'] : '' );
		$end   = $this->normalise_datetime( isset( $data['end_at'] ) ? $data['end_at'] : '' );

		if ( false === $start || false === $end ) {
			return new \WP_Error( 'cpic_campaign_dates', __( 'The campaign start or end date is not a valid date.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		if ( $start && $end && strtotime( $end ) < strtotime( $start ) ) {
			return new \WP_Error( 'cpic_campaign_order', __( 'The campaign cannot end before it starts.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		$status = isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ? (string) $data['status'] : 'draft';

		$id = $this->repo()->insert(
			array(
				'name'         => $name,
				'slug'         => $this->unique_slug( isset( $data['slug'] ) ? (string) $data['slug'] : $name ),
				'description'  => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : '',
				'start_at'     => $start ? $start : null,
				'end_at'       => $end ? $end : null,
				'status'       => $status,
				'brand_id'     => isset( $data['brand_id'] ) ? max( 0, (int) $data['brand_id'] ) : 0,
				'destinations' => wp_json_encode( $this->int_list( isset( $data['destinations'] ) ? $data['destinations'] : array() ) ),
				'templates'    => wp_json_encode( $this->int_list( isset( $data['templates'] ) ? $data['templates'] : array() ) ),
				'tags'         => wp_json_encode( $this->string_list( isset( $data['tags'] ) ? $data['tags'] : array() ) ),
				'notes'        => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : '',
				'created_by'   => get_current_user_id(),
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			)
		);

		if ( ! $id ) {
			return new \WP_Error( 'cpic_campaign_save', __( 'The campaign could not be saved.', 'custom-post-image-creator' ), array( 'status' => 500 ) );
		}

		$this->container->get( 'audit' )->record( 'campaign.created', 'campaign', $id, $name );
		$this->container->get( 'events' )->dispatch( 'campaign.created', array( 'campaign_id' => $id ) );

		return $this->get( $id );
	}

	/**
	 * Update a campaign.
	 *
	 * @param int                 $id   Campaign id.
	 * @param array<string,mixed> $data Fields.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function update( $id, array $data ) {
		$existing = $this->get( $id );

		if ( ! $existing ) {
			return new \WP_Error( 'cpic_campaign_missing', __( 'Campaign not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$update = array( 'updated_at' => current_time( 'mysql' ) );

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( (string) $data['name'] );
			if ( '' === $name ) {
				return new \WP_Error( 'cpic_campaign_name', __( 'A campaign name is required.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
			}
			$update['name'] = $name;
		}

		if ( isset( $data['description'] ) ) {
			$update['description'] = sanitize_textarea_field( (string) $data['description'] );
		}

		foreach ( array( 'start_at', 'end_at' ) as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$value = $this->normalise_datetime( $data[ $field ] );
				if ( false === $value ) {
					return new \WP_Error( 'cpic_campaign_dates', __( 'That date is not valid.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
				}
				$update[ $field ] = $value ? $value : null;
			}
		}

		$start = isset( $update['start_at'] ) ? $update['start_at'] : $existing['start_at'];
		$end   = isset( $update['end_at'] ) ? $update['end_at'] : $existing['end_at'];

		if ( $start && $end && strtotime( (string) $end ) < strtotime( (string) $start ) ) {
			return new \WP_Error( 'cpic_campaign_order', __( 'The campaign cannot end before it starts.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		if ( isset( $data['status'] ) ) {
			if ( ! in_array( $data['status'], self::STATUSES, true ) ) {
				return new \WP_Error( 'cpic_campaign_status', __( 'That campaign status is not recognised.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
			}
			$update['status'] = (string) $data['status'];
		}

		if ( isset( $data['brand_id'] ) ) {
			$update['brand_id'] = max( 0, (int) $data['brand_id'] );
		}

		foreach ( array( 'destinations' => 'int_list', 'templates' => 'int_list', 'tags' => 'string_list' ) as $field => $normaliser ) {
			if ( isset( $data[ $field ] ) ) {
				$update[ $field ] = wp_json_encode( $this->{$normaliser}( $data[ $field ] ) );
			}
		}

		if ( isset( $data['notes'] ) ) {
			$update['notes'] = sanitize_textarea_field( (string) $data['notes'] );
		}

		$this->repo()->update( (int) $id, $update );

		$this->container->get( 'audit' )->record( 'campaign.updated', 'campaign', (int) $id, (string) $existing['name'] );
		$this->container->get( 'events' )->dispatch( 'campaign.updated', array( 'campaign_id' => (int) $id ) );

		return $this->get( $id );
	}

	/**
	 * Get one campaign.
	 *
	 * @param int $id Campaign id.
	 * @return array<string,mixed>|null
	 */
	public function get( $id ) {
		$row = $this->repo()->find( (int) $id );

		if ( ! $row ) {
			return null;
		}

		$campaign = $this->repo()->hydrate( $row );

		$campaign['post_ids'] = $this->post_ids( (int) $id );
		$campaign['stats']    = $this->stats( (int) $id );

		return $campaign;
	}

	/**
	 * List campaigns.
	 *
	 * @param array<string,mixed> $args Filters (`status`, `limit`).
	 * @return array<int,array<string,mixed>>
	 */
	public function all( array $args = array() ) {
		$rows = $this->repo()->by_status(
			isset( $args['status'] ) ? (string) $args['status'] : '',
			isset( $args['limit'] ) ? (int) $args['limit'] : 100
		);

		return array_values(
			array_map(
				function ( $row ) {
					$campaign          = $this->repo()->hydrate( $row );
					$campaign['stats'] = $this->stats( (int) $row['id'] );
					return $campaign;
				},
				$rows
			)
		);
	}

	/**
	 * Campaigns active right now.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function active() {
		return array_map( array( $this->repo(), 'hydrate' ), $this->repo()->active_at( current_time( 'mysql' ) ) );
	}

	/**
	 * Assign a post to a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $post_id     Post id.
	 * @param int $template_id Optional template override.
	 * @return bool|\WP_Error
	 */
	public function assign_post( $campaign_id, $post_id, $template_id = 0 ) {
		$campaign = $this->repo()->find( (int) $campaign_id );

		if ( ! $campaign ) {
			return new \WP_Error( 'cpic_campaign_missing', __( 'Campaign not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		if ( ! get_post( (int) $post_id ) ) {
			return new \WP_Error( 'cpic_post_missing', __( 'Post not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$this->repo()->assign_post( (int) $campaign_id, (int) $post_id, (int) $template_id );

		$this->container->get( 'events' )->dispatch(
			'campaign.post_assigned',
			array( 'campaign_id' => (int) $campaign_id, 'post_id' => (int) $post_id )
		);

		return true;
	}

	/**
	 * Remove a post from a campaign.
	 *
	 * `false` here used to mean three different things — no such campaign, no
	 * such assignment, or a write that failed — and REST reported every one of
	 * them as `200 false`. Mirror assign_post() instead: refuse with a code and
	 * a status the client can act on.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $post_id     Post id.
	 * @return bool|\WP_Error
	 */
	public function unassign_post( $campaign_id, $post_id ) {
		$campaign_id = (int) $campaign_id;
		$post_id     = (int) $post_id;

		if ( ! $this->repo()->find( $campaign_id ) ) {
			return new \WP_Error( 'cpic_campaign_missing', __( 'Campaign not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		if ( $post_id <= 0 ) {
			return new \WP_Error( 'cpic_post_missing', __( 'A post is required.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		$removed = (bool) $this->repo()->unassign_post( $campaign_id, $post_id );

		if ( ! $removed ) {
			return new \WP_Error( 'cpic_not_assigned', __( 'That post is not in this campaign.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$this->container->get( 'events' )->dispatch(
			'campaign.post_unassigned',
			array( 'campaign_id' => $campaign_id, 'post_id' => $post_id )
		);

		return true;
	}

	/**
	 * Posts assigned to a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<int,array<string,mixed>>
	 */
	public function posts( $campaign_id ) {
		$rows = $this->repo()->posts( (int) $campaign_id );

		foreach ( $rows as &$row ) {
			$post = get_post( (int) $row['post_id'] );

			$row['title']     = $post ? (string) $post->post_title : __( '(deleted post)', 'custom-post-image-creator' );
			$row['permalink'] = $post ? (string) get_permalink( (int) $post->ID ) : '';
			$row['exists']    = (bool) $post;
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Post ids assigned to a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<int,int>
	 */
	public function post_ids( $campaign_id ) {
		return array_map(
			static function ( $row ) {
				return (int) $row['post_id'];
			},
			$this->repo()->posts( (int) $campaign_id )
		);
	}

	/**
	 * Campaigns that include a post.
	 *
	 * @param int $post_id Post id.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_post( $post_id ) {
		$out = array();

		foreach ( $this->repo()->campaigns_for_post( (int) $post_id ) as $campaign_id ) {
			$campaign = $this->get( (int) $campaign_id );
			if ( $campaign ) {
				$out[] = $campaign;
			}
		}

		return $out;
	}

	/**
	 * Change status with the transitions the UI offers.
	 *
	 * @param int    $id     Campaign id.
	 * @param string $status Status.
	 * @return bool|\WP_Error
	 */
	public function set_status( $id, $status ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new \WP_Error( 'cpic_campaign_status', __( 'That campaign status is not recognised.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		if ( ! $this->repo()->find( (int) $id ) ) {
			return new \WP_Error( 'cpic_campaign_missing', __( 'Campaign not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$this->repo()->update( (int) $id, array( 'status' => (string) $status, 'updated_at' => current_time( 'mysql' ) ) );

		$this->container->get( 'events' )->dispatch(
			'campaign.status_changed',
			array( 'campaign_id' => (int) $id, 'status' => (string) $status )
		);

		return true;
	}

	/**
	 * Pause a campaign (deliveries already queued keep their own state).
	 *
	 * @param int $id Campaign id.
	 * @return bool|\WP_Error
	 */
	public function pause( $id ) {
		return $this->set_status( $id, 'paused' );
	}

	/**
	 * Resume a paused campaign.
	 *
	 * @param int $id Campaign id.
	 * @return bool|\WP_Error
	 */
	public function resume( $id ) {
		return $this->set_status( $id, 'active' );
	}

	/**
	 * Delete a campaign and its assignments (posts are never touched).
	 *
	 * @param int $id Campaign id.
	 * @return bool|\WP_Error
	 */
	public function delete( $id ) {
		$campaign = $this->repo()->find( (int) $id );

		if ( ! $campaign ) {
			return new \WP_Error( 'cpic_campaign_missing', __( 'Campaign not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		foreach ( $this->post_ids( (int) $id ) as $post_id ) {
			$this->repo()->unassign_post( (int) $id, $post_id );
		}

		$this->repo()->delete( (int) $id );

		$this->container->get( 'audit' )->record( 'campaign.deleted', 'campaign', (int) $id, (string) $campaign['name'] );

		return true;
	}

	/**
	 * Delivery statistics for a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<string,int>
	 */
	public function stats( $campaign_id ) {
		$post_ids = $this->post_ids( $campaign_id );

		if ( empty( $post_ids ) ) {
			return array( 'posts' => 0, 'published' => 0, 'failed' => 0, 'pending' => 0 );
		}

		$counts = $this->container->get( 'deliveries' )->status_counts( array( 'post_id' => $post_ids ) );

		return array(
			'posts'     => count( $post_ids ),
			'published' => (int) $counts['published'],
			'failed'    => (int) $counts['failed'],
			'pending'   => (int) $counts['pending'] + (int) $counts['queued'] + (int) $counts['processing'],
		);
	}

	/**
	 * Draft a publishing window: used by the calendar to preview a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function schedule_summary( $campaign_id ) {
		$campaign = $this->get( $campaign_id );

		if ( ! $campaign ) {
			return new \WP_Error( 'cpic_campaign_missing', __( 'Campaign not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		return array(
			'campaign_id' => (int) $campaign_id,
			'name'        => $campaign['name'],
			'status'      => $campaign['status'],
			'window'      => array(
				'start' => $campaign['start_at'],
				'end'   => $campaign['end_at'],
				'days'  => $campaign['start_at'] && $campaign['end_at']
					? max( 1, (int) ceil( ( strtotime( (string) $campaign['end_at'] ) - strtotime( (string) $campaign['start_at'] ) ) / DAY_IN_SECONDS ) + 1 )
					: 0,
			),
			'posts'       => $this->posts( $campaign_id ),
			'stats'       => $campaign['stats'],
		);
	}

	/**
	 * Normalise a date input to a MySQL datetime.
	 *
	 * @param mixed $value Raw value.
	 * @return string|false|null Null for empty, false for invalid.
	 */
	private function normalise_datetime( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return false;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Unique slug for a campaign name.
	 *
	 * @param string $name Base.
	 * @return string
	 */
	private function unique_slug( $name ) {
		$slug = sanitize_title( $name );

		if ( '' === $slug ) {
			$slug = 'campaign';
		}

		$candidate = $slug;
		$i         = 2;

		while ( $this->repo()->first( array( 'slug' => $candidate ) ) ) {
			$candidate = $slug . '-' . $i;
			$i++;
			if ( $i > 200 ) {
				return $slug . '-' . substr( md5( (string) microtime( true ) ), 0, 6 );
			}
		}

		return $candidate;
	}

	/**
	 * Clean an int list.
	 *
	 * @param mixed $value Raw.
	 * @return array<int,int>
	 */
	private function int_list( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		return array_values( array_unique( array_filter( array_map( 'intval', (array) $value ) ) ) );
	}

	/**
	 * Clean a string list.
	 *
	 * @param mixed $value Raw.
	 * @return array<int,string>
	 */
	private function string_list( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $value ) ) ) );
	}
}
