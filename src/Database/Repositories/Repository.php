<?php
/**
 * Shared repository base.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database\Repositories;

use CPIC\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin, predictable data-access base. Every query goes through
 * `$wpdb->prepare()`; identifiers come from a fixed allow-list.
 */
abstract class Repository {

	/**
	 * Logical schema key (see Schema::TABLES).
	 *
	 * @return string
	 */
	abstract protected function key();

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public function table() {
		return Schema::table( $this->key() );
	}

	/**
	 * Current GMT-ish site time in MySQL format (WordPress local time).
	 *
	 * @return string
	 */
	protected function now() {
		return current_time( 'mysql' );
	}

	/**
	 * Insert a row.
	 *
	 * @param array<string,mixed> $data    Column values.
	 * @param array<string,string> $formats Optional %d/%s/%f map.
	 * @return int Inserted id (0 on failure).
	 */
	public function insert( array $data, array $formats = array() ) {
		global $wpdb;

		$format = $this->formats_for( $data, $formats );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( $this->table(), $data, $format );

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Update by primary id.
	 *
	 * @param int                  $id      Row id.
	 * @param array<string,mixed>  $data    Values.
	 * @param array<string,string> $formats Optional format map.
	 * @return bool
	 */
	public function update( $id, array $data, array $formats = array() ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 || empty( $data ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update( $this->table(), $data, array( 'id' => $id ), $this->formats_for( $data, $formats ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Delete by primary id.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( $this->table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Find by primary id.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Generic select.
	 *
	 * @param array<string,mixed> $where    Column => value pairs (ANDed).
	 * @param string              $order_by Clause without ORDER BY, e.g. 'id DESC'.
	 * @param int                 $limit    0 = no limit.
	 * @param int                 $offset   Offset.
	 * @param string              $columns  Column list (default '*').
	 * @return array<int,array<string,mixed>>
	 */
	public function select( array $where = array(), $order_by = '', $limit = 0, $offset = 0, $columns = '*' ) {
		global $wpdb;

		$table  = $this->table();
		$built  = $this->build_where( $where );
		$sql    = "SELECT {$columns} FROM {$table}";
		$args   = $built['args'];

		if ( $built['sql'] ) {
			$sql .= ' WHERE ' . $built['sql'];
		}

		$order_by = $this->safe_order( $order_by );
		if ( $order_by ) {
			$sql .= ' ORDER BY ' . $order_by;
		}

		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = (int) $limit;

			if ( $offset > 0 ) {
				$sql   .= ' OFFSET %d';
				$args[] = (int) $offset;
			}
		}

		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $args );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * First matching row.
	 *
	 * @param array<string,mixed> $where    Conditions.
	 * @param string              $order_by Order clause.
	 * @return array<string,mixed>|null
	 */
	public function first( array $where = array(), $order_by = 'id ASC' ) {
		$rows = $this->select( $where, $order_by, 1 );
		return $rows ? $rows[0] : null;
	}

	/**
	 * Count rows matching conditions.
	 *
	 * @param array<string,mixed> $where Conditions.
	 * @return int
	 */
	public function count( array $where = array() ) {
		global $wpdb;

		$table = $this->table();
		$built = $this->build_where( $where );
		$sql   = "SELECT COUNT(*) FROM {$table}";

		if ( $built['sql'] ) {
			$sql .= ' WHERE ' . $built['sql'];
		}

		if ( ! empty( $built['args'] ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $built['args'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Build a WHERE clause from a column map.
	 *
	 * @param array<string,mixed> $where Conditions.
	 * @return array{sql:string,args:array<int,mixed>}
	 */
	protected function build_where( array $where ) {
		$clauses = array();
		$args    = array();

		foreach ( $where as $column => $value ) {
			$safe_column = $this->safe_column( $column );

			if ( '' === $safe_column ) {
				continue;
			}

			// `column => array( 'between', a, b )` and a bare array => IN (…).
			if ( is_array( $value ) && isset( $value[0] ) && in_array( $value[0], array( 'between', 'gt', 'gte', 'lt', 'lte', 'neq' ), true ) ) {
				$operator = (string) array_shift( $value );
				$values   = array_values( $value );

				if ( 'between' === $operator && 2 === count( $values ) ) {
					$clauses[] = $safe_column . ' BETWEEN ' . $this->placeholder( $values[0] ) . ' AND ' . $this->placeholder( $values[1] );
					$args[]    = $values[0];
					$args[]    = $values[1];
					continue;
				}

				$map = array( 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'neq' => '<>' );

				if ( isset( $map[ $operator ] ) && 1 === count( $values ) ) {
					$clauses[] = $safe_column . ' ' . $map[ $operator ] . ' ' . $this->placeholder( $values[0] );
					$args[]    = $values[0];
					continue;
				}
			}

			if ( is_array( $value ) ) {
				$values = array_values( $value );

				if ( empty( $values ) ) {
					// An empty IN () is never what the caller means; match nothing.
					$clauses[] = '1=0';
					continue;
				}

				$placeholders = array();
				foreach ( $values as $item ) {
					$placeholders[] = $this->placeholder( $item );
					$args[]         = $item;
				}
				$clauses[] = $safe_column . ' IN (' . implode( ',', $placeholders ) . ')';
				continue;
			}

			if ( null === $value ) {
				$clauses[] = $safe_column . ' IS NULL';
				continue;
			}

			$clauses[] = $safe_column . ' = ' . $this->placeholder( $value );
			$args[]    = $value;
		}

		return array(
			'sql'  => implode( ' AND ', $clauses ),
			'args' => $args,
		);
	}

	/**
	 * Placeholder for a value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function placeholder( $value ) {
		if ( is_int( $value ) || is_bool( $value ) ) {
			return '%d';
		}

		if ( is_float( $value ) ) {
			return '%f';
		}

		return '%s';
	}

	/**
	 * Whitelist a column identifier (`[a-z0-9_]`), rejecting anything else.
	 *
	 * @param string $column Column.
	 * @return string Safe column or ''.
	 */
	protected function safe_column( $column ) {
		$column = (string) $column;

		return preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $column ) ? $column : '';
	}

	/**
	 * Sanitise an ORDER BY clause: only column tokens, ASC/DESC and commas.
	 *
	 * @param string $order_by Raw clause.
	 * @return string
	 */
	protected function safe_order( $order_by ) {
		$order_by = trim( (string) $order_by );
		if ( '' === $order_by ) {
			return '';
		}
		if ( ! preg_match( '/^[A-Za-z0-9_,\s\.]+$/', $order_by ) ) {
			return 'id DESC';
		}
		return $order_by;
	}

	/**
	 * Derive formats from value types, with explicit overrides.
	 *
	 * @param array<string,mixed>  $data     Data.
	 * @param array<string,string> $formats  Overrides.
	 * @return array<int,string>|array<string,string>
	 */
	protected function formats_for( array $data, array $formats = array() ) {
		if ( ! empty( $formats ) ) {
			return $formats;
		}

		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_int( $value ) || is_bool( $value ) ) {
				$out[ $key ] = '%d';
			} elseif ( is_float( $value ) ) {
				$out[ $key ] = '%f';
			} else {
				$out[ $key ] = '%s';
			}
		}

		return $out;
	}

	/**
	 * Decode a JSON column safely.
	 *
	 * @param mixed $value   Raw column value.
	 * @param array $default Default when empty/invalid.
	 * @return array
	 */
	protected function json_decode( $value, array $default = array() ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return $default;
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : $default;
	}

	/**
	 * Encode a value for storage.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	protected function json_encode( $value ) {
		return (string) wp_json_encode( $value );
	}

	/**
	 * Paginate a select for REST/admin lists.
	 *
	 * @param array<string,mixed> $where  Conditions.
	 * @param string              $order  Order clause.
	 * @param int                 $page   Page number (1-based).
	 * @param int                 $per    Per page.
	 * @return array{items:array,total:int,page:int,per_page:int,pages:int}
	 */
	public function paginate( array $where = array(), $order = 'id DESC', $page = 1, $per = 20 ) {
		$page = max( 1, (int) $page );
		$per  = min( 200, max( 1, (int) $per ) );

		return array(
			'items'    => $this->select( $where, $order, $per, ( $page - 1 ) * $per ),
			'total'    => $this->count( $where ),
			'page'     => $page,
			'per_page' => $per,
			'pages'    => (int) ceil( $this->count( $where ) / $per ),
		);
	}
}
