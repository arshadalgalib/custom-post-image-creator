<?php
/**
 * Social destination persistence.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destinations are individual publish targets (a Facebook Page, a Telegram
 * chat). Credentials are stored encrypted via `Encryption`.
 */
final class DestinationRepository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function key() {
		return 'destinations';
	}

	/**
	 * Destinations for a provider.
	 *
	 * @param string $provider facebook|telegram.
	 * @param bool   $only_enabled Filter by status connected.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_provider( $provider, $only_enabled = false ) {
		$where = array( 'provider' => (string) $provider );
		if ( $only_enabled ) {
			$where['status'] = 'connected';
		}

		return $this->select( $where, 'priority ASC, id ASC' );
	}

	/**
	 * Enabled destinations sorted for publishing.
	 *
	 * @param array<int,int> $ids Optional id restriction.
	 * @return array<int,array<string,mixed>>
	 */
	public function enabled( array $ids = array() ) {
		$where = array( 'status' => 'connected' );
		if ( ! empty( $ids ) ) {
			$where['id'] = array_map( 'intval', $ids );
		}

		return $this->select( $where, 'priority ASC, id ASC' );
	}

	/**
	 * Find by public id.
	 *
	 * @param string $public_id Public id.
	 * @return array<string,mixed>|null
	 */
	public function find_by_public_id( $public_id ) {
		return $this->first( array( 'public_id' => (string) $public_id ) );
	}

	/**
	 * Find by provider account identity (page id / chat id).
	 *
	 * @param string $provider Provider.
	 * @param string $account  Account id.
	 * @return array<string,mixed>|null
	 */
	public function find_by_account( $provider, $account ) {
		return $this->first(
			array(
				'provider'   => (string) $provider,
				'account_id' => (string) $account,
			)
		);
	}

	/**
	 * Default destination for a provider (used by legacy single-account flows).
	 *
	 * @param string $provider Provider.
	 * @return array<string,mixed>|null
	 */
	public function default_for( $provider ) {
		$row = $this->first(
			array(
				'provider'   => (string) $provider,
				'is_default' => 1,
				'status'     => 'connected',
			)
		);
		if ( $row ) {
			return $row;
		}

		return $this->first(
			array(
				'provider' => (string) $provider,
				'status'   => 'connected',
			),
			'priority ASC, id ASC'
		);
	}

	/**
	 * Ensure only one default per provider.
	 *
	 * @param int    $id       Destination id.
	 * @param string $provider Provider.
	 * @return void
	 */
	public function set_default( $id, $provider ) {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_default = 0 WHERE provider = %s AND id <> %d", (string) $provider, (int) $id ) );
		$this->update( $id, array( 'is_default' => 1 ) );
	}

	/**
	 * Counts by provider and status.
	 *
	 * @return array<string,array<string,int>>
	 */
	public function status_summary() {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT provider, status, COUNT(*) AS total FROM {$table} GROUP BY provider, status", ARRAY_A );

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$provider = (string) $row['provider'];
				if ( ! isset( $out[ $provider ] ) ) {
					$out[ $provider ] = array();
				}
				$out[ $provider ][ (string) $row['status'] ] = (int) $row['total'];
			}
		}

		return $out;
	}

	/**
	 * Generate a public identifier.
	 *
	 * @param string $prefix Prefix.
	 * @return string
	 */
	public function public_id( $prefix ) {
		return $prefix . '_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 20 );
	}

	/**
	 * Destinations whose tokens expire soon.
	 *
	 * @param int $days Days ahead.
	 * @return array<int,array<string,mixed>>
	 */
	public function expiring( $days = 7 ) {
		global $wpdb;
		$table  = $this->table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() + ( (int) $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token_expires_at IS NOT NULL AND token_expires_at <> '0000-00-00 00:00:00' AND token_expires_at < %s ORDER BY token_expires_at ASC", $cutoff ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
