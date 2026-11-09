<?php
/**
 * A PDO/SQLite implementation of the `$wpdb` API surface used by the plugin.
 *
 * This is test infrastructure only. It lets the repositories, migrator and
 * services run against a real SQL database without a MySQL server, which makes
 * the PHP suite runnable on any machine (and in CI) with pdo_sqlite.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal, MySQL-to-SQLite translating $wpdb replacement.
 */
class CPIC_Test_WPDB {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Last insert id.
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * Last error.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * PDO handle.
	 *
	 * @var PDO
	 */
	public $pdo;

	/**
	 * Query log (used by tests to assert query counts).
	 *
	 * @var array<int,string>
	 */
	public $queries = array();

	/**
	 * Constructor.
	 *
	 * @param PDO $pdo Connection.
	 */
	public function __construct( PDO $pdo ) {
		$this->pdo = $pdo;
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'PRAGMA foreign_keys = OFF' );
	}

	/**
	 * Collation clause (ignored by SQLite).
	 *
	 * @return string
	 */
	public function get_charset_collate() {
		return '';
	}

	/**
	 * Escape LIKE wildcards.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * Prepare a query with %s/%d/%f placeholders.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Arguments (array or variadic).
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$query = (string) $query;
		$out   = '';
		$index = 0;
		$len   = strlen( $query );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $query[ $i ];

			if ( '%' !== $char || $i + 1 >= $len ) {
				$out .= $char;
				continue;
			}

			$next = $query[ $i + 1 ];

			if ( '%' === $next ) {
				$out .= '%';
				$i++;
				continue;
			}

			if ( ! in_array( $next, array( 's', 'd', 'f' ), true ) ) {
				$out .= $char;
				continue;
			}

			$value = array_key_exists( $index, $args ) ? $args[ $index ] : null;
			$index++;
			$i++;

			if ( null === $value ) {
				$out .= 'NULL';
			} elseif ( 'd' === $next ) {
				$out .= (string) (int) $value;
			} elseif ( 'f' === $next ) {
				$out .= (string) (float) $value;
			} else {
				$out .= "'" . str_replace( "'", "''", (string) $value ) . "'";
			}
		}

		return $out;
	}

	/**
	 * Run a raw query.
	 *
	 * @param string $query Query.
	 * @return int|false Affected rows.
	 */
	public function query( $query ) {
		$sql = $this->translate( (string) $query );
		$this->queries[] = $sql;

		try {
			$statement = $this->pdo->prepare( $sql );
			$statement->execute();

			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $sql ) ) {
				$affected = $statement->rowCount();
				if ( preg_match( '/^\s*INSERT/i', $sql ) ) {
					$this->insert_id = (int) $this->pdo->lastInsertId();
				}
				return $affected;
			}

			// Store result set for get_results().
			$this->last_statement = $statement;

			return (int) $statement->rowCount();
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Last executed statement.
	 *
	 * @var PDOStatement|null
	 */
	public $last_statement = null;

	/**
	 * Fetch a single variable.
	 *
	 * @param string $query Query.
	 * @param int    $x     Column offset.
	 * @param int    $y     Row offset.
	 * @return string|null
	 */
	public function get_var( $query, $x = 0, $y = 0 ) {
		$rows = $this->get_results( $query, ARRAY_N );

		if ( empty( $rows ) || ! isset( $rows[ $y ] ) ) {
			return null;
		}

		$row = $rows[ $y ];

		return array_key_exists( $x, $row ) ? $row[ $x ] : null;
	}

	/**
	 * Fetch a single row.
	 *
	 * @param string $query  Query.
	 * @param string $output Output type.
	 * @param int    $y      Row offset.
	 * @return array|object|null
	 */
	public function get_row( $query, $output = ARRAY_A, $y = 0 ) {
		$rows = $this->get_results( $query, $output );

		if ( empty( $rows ) || ! isset( $rows[ $y ] ) ) {
			return null;
		}

		return $rows[ $y ];
	}

	/**
	 * Fetch a column.
	 *
	 * @param string $query Query.
	 * @param int    $x     Column offset.
	 * @return array<int,mixed>
	 */
	public function get_col( $query, $x = 0 ) {
		$rows = $this->get_results( $query, ARRAY_N );
		$out  = array();

		foreach ( $rows as $row ) {
			$out[] = array_key_exists( $x, $row ) ? $row[ $x ] : null;
		}

		return $out;
	}

	/**
	 * Fetch many rows.
	 *
	 * @param string $query  Query.
	 * @param string $output Output type.
	 * @return array<int,mixed>
	 */
	public function get_results( $query, $output = ARRAY_A ) {
		$sql = $this->translate( (string) $query );
		$this->queries[] = $sql;

		try {
			$statement = $this->pdo->query( $sql );

			if ( ! $statement ) {
				return array();
			}

			$rows = $statement->fetchAll( PDO::FETCH_ASSOC );

			if ( ARRAY_N === $output ) {
				return array_map( 'array_values', $rows );
			}

			if ( OBJECT === $output ) {
				return array_map(
					static function ( $row ) {
						return (object) $row;
					},
					$rows
				);
			}

			return $rows;
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return array();
		}
	}

	/**
	 * Insert a row.
	 *
	 * @param string              $table   Table name.
	 * @param array<string,mixed> $data    Values.
	 * @param array<string,string>|null $format Formats (ignored).
	 * @return int|false
	 */
	public function insert( $table, $data, $format = null ) {
		$columns = array_keys( $data );
		$values  = array_values( $data );
		$marks   = implode( ',', array_fill( 0, count( $columns ), '?' ) );

		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES (%s)',
			$table,
			implode( ',', $columns ),
			$marks
		);

		$this->queries[] = $sql;

		try {
			$statement = $this->pdo->prepare( $sql );
			$statement->execute( $values );
			$this->insert_id = (int) $this->pdo->lastInsertId();

			return 1;
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Update rows.
	 *
	 * @param string               $table  Table.
	 * @param array<string,mixed>  $data   Values.
	 * @param array<string,mixed>  $where  Conditions.
	 * @param array|null           $format Formats (ignored).
	 * @param array|null           $where_format Where formats (ignored).
	 * @return int|false
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$set   = array();
		$args  = array();

		foreach ( $data as $column => $value ) {
			$set[]  = $column . ' = ?';
			$args[] = $value;
		}

		$conditions = array();
		foreach ( $where as $column => $value ) {
			if ( null === $value ) {
				$conditions[] = $column . ' IS NULL';
				continue;
			}
			$conditions[] = $column . ' = ?';
			$args[]       = $value;
		}

		$sql = sprintf(
			'UPDATE %s SET %s WHERE %s',
			$table,
			implode( ', ', $set ),
			implode( ' AND ', $conditions )
		);

		$this->queries[] = $sql;

		try {
			$statement = $this->pdo->prepare( $sql );
			$statement->execute( $args );

			return $statement->rowCount();
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Delete rows.
	 *
	 * @param string              $table  Table.
	 * @param array<string,mixed> $where  Conditions.
	 * @param array|null          $format Formats (ignored).
	 * @return int|false
	 */
	public function delete( $table, $where, $format = null ) {
		$conditions = array();
		$args       = array();

		foreach ( $where as $column => $value ) {
			if ( null === $value ) {
				$conditions[] = $column . ' IS NULL';
				continue;
			}
			$conditions[] = $column . ' = ?';
			$args[]       = $value;
		}

		$sql = sprintf( 'DELETE FROM %s WHERE %s', $table, implode( ' AND ', $conditions ) );

		$this->queries[] = $sql;

		try {
			$statement = $this->pdo->prepare( $sql );
			$statement->execute( $args );

			return $statement->rowCount();
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Translate MySQL DDL/DML into SQLite.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	public function translate( $sql ) {
		$sql = trim( (string) $sql );

		/* SHOW TABLES LIKE 'x' */
		if ( preg_match( '/^SHOW\s+TABLES\s+LIKE\s+\'?([^\'";]+)\'?$/i', $sql, $m ) ) {
			$name = trim( $m[1] );
			return "SELECT name AS tables_in FROM sqlite_master WHERE type = 'table' AND name = '" . str_replace( "'", "''", $name ) . "'";
		}

		/* SHOW COLUMNS FROM t LIKE 'c' */
		if ( preg_match( '/^SHOW\s+COLUMNS\s+FROM\s+(\S+)\s+LIKE\s+\'?([^\'";]+)\'?$/i', $sql, $m ) ) {
			$table  = trim( $m[1], '`' );
			$column = trim( $m[2] );
			return "SELECT name AS Field FROM pragma_table_info('" . str_replace( "'", "''", $table ) . "') WHERE name = '" . str_replace( "'", "''", $column ) . "'";
		}

		/* SHOW INDEX FROM t WHERE Key_name = 'x' */
		if ( preg_match( '/^SHOW\s+INDEX\s+FROM\s+(\S+)\s+WHERE\s+Key_name\s*=\s*\'([^\']+)\'$/i', $sql, $m ) ) {
			$table = trim( $m[1], '`' );
			$name  = $m[2];
			return "SELECT name AS Key_name FROM pragma_index_list('" . str_replace( "'", "''", $table ) . "') WHERE name = '" . str_replace( "'", "''", $name ) . "'";
		}

		/* TRUNCATE */
		if ( preg_match( '/^TRUNCATE\s+TABLE\s+(\S+)$/i', $sql, $m ) ) {
			return 'DELETE FROM ' . $m[1];
		}

		/* ALTER TABLE ADD INDEX */
		if ( preg_match( '/^ALTER\s+TABLE\s+(\S+)\s+ADD\s+(?:INDEX|KEY)\s+(\S+)\s*\(([^)]+)\)$/i', $sql, $m ) ) {
			return 'CREATE INDEX IF NOT EXISTS ' . $m[2] . ' ON ' . $m[1] . ' (' . $m[3] . ')';
		}

		/* ALTER TABLE ADD COLUMN */
		if ( preg_match( '/^ALTER\s+TABLE\s+(\S+)\s+ADD\s+(?:COLUMN\s+)?(\S+)\s+(.+)$/i', $sql, $m ) ) {
			return 'ALTER TABLE ' . $m[1] . ' ADD COLUMN ' . $m[2] . ' ' . $this->translate_column( $m[3] );
		}

		/* CREATE TABLE */
		if ( 0 === strpos( strtoupper( $sql ), 'CREATE TABLE' ) ) {
			return $this->translate_create( $sql );
		}

		/* Portable function shims. */
		$sql = str_replace( 'GREATEST(', 'MAX(', $sql );

		return $sql;
	}

	/**
	 * Translate a CREATE TABLE statement.
	 *
	 * @param string $sql Statement.
	 * @return string
	 */
	private function translate_create( $sql ) {
		// Strip trailing charset/collation clause.
		$sql = preg_replace( '/\)\s*[^)]*;\s*$/s', ')', $sql );
		$sql = rtrim( $sql, ";\n\r\t " );

		$open  = strpos( $sql, '(' );
		$close = strrpos( $sql, ')' );

		if ( false === $open || false === $close ) {
			return $sql;
		}

		$head = substr( $sql, 0, $open );
		$body = substr( $sql, $open + 1, $close - $open - 1 );

		$head = preg_replace( '/CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?/i', 'CREATE TABLE IF NOT EXISTS ', $head );

		$lines    = $this->split_definitions( $body );
		$columns  = array();
		$uniques  = array();
		$indexes  = array();
		$table    = '';

		if ( preg_match( '/(\S+)\s*$/i', trim( $head ), $m ) ) {
			$table = $m[1];
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '/^PRIMARY\s+KEY\s*\(([^)]+)\)/i', $line, $m ) ) {
				continue; // Handled inline for the id column.
			}

			if ( preg_match( '/^UNIQUE\s+KEY\s+(\S+)\s*\(([^)]+)\)/i', $line, $m ) ) {
				$uniques[] = 'UNIQUE (' . $m[2] . ')';
				continue;
			}

			if ( preg_match( '/^(?:KEY|INDEX)\s+(\S+)\s*\(([^)]+)\)/i', $line, $m ) ) {
				$indexes[] = array( $m[1], $m[2] );
				continue;
			}

			if ( preg_match( '/^CONSTRAINT/i', $line ) ) {
				continue;
			}

			$columns[] = $this->translate_column( $line );
		}

		$create = sprintf( 'CREATE TABLE IF NOT EXISTS %s (%s)', $table, implode( ', ', array_merge( $columns, $uniques ) ) );
		$this->pending_indexes = $indexes;

		// Indexes are created by the caller through `create_pending_indexes()`.
		$this->pending_create_indexes[ $table ] = $indexes;

		return $create;
	}

	/**
	 * Indexes to create after a CREATE TABLE.
	 *
	 * @var array<string,array<int,array{0:string,1:string}>>
	 */
	public $pending_create_indexes = array();

	/**
	 * Pending indexes for the last create.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	public $pending_indexes = array();

	/**
	 * Create the indexes collected while translating CREATE TABLE statements.
	 *
	 * @return void
	 */
	public function create_pending_indexes() {
		foreach ( $this->pending_create_indexes as $table => $indexes ) {
			foreach ( $indexes as $index ) {
				$name = $index[0];
				if ( 0 === strpos( $name, '(' ) ) {
					continue;
				}
				$sql = sprintf( 'CREATE INDEX IF NOT EXISTS %s ON %s (%s)', $name, $table, $index[1] );
				try {
					$this->pdo->exec( $sql );
				} catch ( \Throwable $e ) {
					$this->last_error = $e->getMessage();
				}
			}
		}
		$this->pending_create_indexes = array();
	}

	/**
	 * Split a CREATE TABLE body on top-level commas.
	 *
	 * @param string $body Body.
	 * @return array<int,string>
	 */
	private function split_definitions( $body ) {
		$lines  = array();
		$depth  = 0;
		$buffer = '';
		$len    = strlen( $body );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $body[ $i ];

			if ( '(' === $char ) {
				$depth++;
			} elseif ( ')' === $char ) {
				$depth--;
			}

			if ( ',' === $char && 0 === $depth ) {
				$lines[] = $buffer;
				$buffer  = '';
				continue;
			}

			$buffer .= $char;
		}

		if ( '' !== trim( $buffer ) ) {
			$lines[] = $buffer;
		}

		return $lines;
	}

	/**
	 * Translate a single column definition.
	 *
	 * @param string $definition Definition.
	 * @return string
	 */
	public function translate_column( $definition ) {
		$definition = trim( $definition );
		$definition = str_replace( 'UNSIGNED', '', $definition );
		$definition = preg_replace( '/\bBIGINT\s*\(\s*\d+\s*\)/i', 'INTEGER', $definition );
		$definition = preg_replace( '/\bBIGINT\b/i', 'INTEGER', $definition );
		$definition = preg_replace( '/\b(?:TINYINT|SMALLINT|MEDIUMINT|INT)\s*\(\s*\d+\s*\)/i', 'INTEGER', $definition );
		$definition = preg_replace( '/\bTINYINT\b/i', 'INTEGER', $definition );
		$definition = preg_replace( '/\bINT\b/i', 'INTEGER', $definition );
		$definition = preg_replace( '/\b(?:VARCHAR|CHAR|VARBINARY)\s*\(\s*\d+\s*\)/i', 'TEXT', $definition );
		$definition = preg_replace( '/\b(?:LONGTEXT|MEDIUMTEXT|TINYTEXT|TEXT)\b/i', 'TEXT', $definition );
		$definition = preg_replace( '/\bDATETIME\b/i', 'TEXT', $definition );
		$definition = preg_replace( '/\bDECIMAL\s*\(\s*\d+\s*,\s*\d+\s*\)/i', 'REAL', $definition );
		$definition = preg_replace( '/\bDOUBLE\b/i', 'REAL', $definition );
		$definition = preg_replace( '/\bTIMESTAMP\b/i', 'TEXT', $definition );

		$is_auto = false !== stripos( $definition, 'AUTO_INCREMENT' );
		$definition = preg_replace( '/\bAUTO_INCREMENT\b/i', '', $definition );

		$definition = preg_replace( '/\s+/', ' ', trim( $definition ) );

		// SQLite requires an inline primary key for AUTOINCREMENT.
		if ( $is_auto ) {
			$definition = preg_replace( '/\bNOT\s+NULL\b/i', '', $definition );
			$definition = trim( $definition );
			$definition .= ' PRIMARY KEY AUTOINCREMENT';
		}

		return $definition;
	}
}
