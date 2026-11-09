<?php
/**
 * Audit trail persistence.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records who changed what (approvals, publishing, credential changes).
 */
final class AuditRepository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function key() {
		return 'audit';
	}

	/**
	 * Record an entry.
	 *
	 * @param string              $action      Action key.
	 * @param string              $object_type Object type.
	 * @param int                 $object_id   Object id.
	 * @param string              $message     Human message.
	 * @param array<string,mixed> $context     Extra context.
	 * @return int
	 */
	public function record( $action, $object_type, $object_id, $message = '', array $context = array() ) {
		return $this->insert(
			array(
				'actor_id'    => get_current_user_id(),
				'action'      => (string) $action,
				'object_type' => (string) $object_type,
				'object_id'   => (int) $object_id,
				'message'     => (string) $message,
				'context'     => wp_json_encode( $context ),
				'created_at'  => $this->now(),
			)
		);
	}

	/**
	 * Recent entries.
	 *
	 * @param int $limit Limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( $limit = 50 ) {
		return $this->select( array(), 'id DESC', (int) $limit );
	}

	/**
	 * Entries for one object.
	 *
	 * @param string $object_type Type.
	 * @param int    $object_id   Id.
	 * @param int    $limit       Limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_object( $object_type, $object_id, $limit = 50 ) {
		return $this->select(
			array(
				'object_type' => (string) $object_type,
				'object_id'   => (int) $object_id,
			),
			'id DESC',
			(int) $limit
		);
	}

	/**
	 * Prune old rows.
	 *
	 * @param int $days Days to keep.
	 * @return int Deleted rows.
	 */
	public function prune( $days = 180 ) {
		global $wpdb;
		$table = $this->table();
		$cut   = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cut ) );
	}
}
