<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps a connection to the external (legacy) MySQL database using a dedicated
 * wpdb instance, and exposes schema introspection helpers used by the mapping UI.
 */
class DBMig_External_DB {

	const OPTION_KEY = 'dbmig_external_db';

	/** @var wpdb|null */
	private $db = null;

	/** @var array */
	private $config;

	public function __construct( $config = null ) {
		$this->config = $config ? $config : self::get_config();
	}

	public static function get_config() {
		$defaults = array(
			'host'   => 'localhost',
			'dbname' => '',
			'dbuser' => '',
			'dbpass' => '',
		);
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	public static function save_config( $config ) {
		$clean = array(
			'host'   => sanitize_text_field( $config['host'] ),
			'dbname' => sanitize_text_field( $config['dbname'] ),
			'dbuser' => sanitize_text_field( $config['dbuser'] ),
			// Password may contain special chars; only strip control chars / slashes added by WP.
			'dbpass' => (string) wp_unslash( $config['dbpass'] ),
		);
		update_option( self::OPTION_KEY, $clean );
		return $clean;
	}

	public function is_configured() {
		return ! empty( $this->config['dbname'] ) && ! empty( $this->config['dbuser'] );
	}

	/**
	 * Lazily create and return the wpdb connection. Returns WP_Error on failure.
	 *
	 * @return wpdb|WP_Error
	 */
	public function db() {
		if ( $this->db instanceof wpdb ) {
			return $this->db;
		}
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'dbmig_not_configured', __( 'External database is not configured.', 'db-migrator' ) );
		}

		// Suppress the fatal "Error establishing a database connection" page; we handle errors ourselves.
		$db = new wpdb(
			$this->config['dbuser'],
			$this->config['dbpass'],
			$this->config['dbname'],
			$this->config['host']
		);
		$db->suppress_errors( true );
		$db->hide_errors();

		if ( ! empty( $db->error ) ) {
			$msg = is_wp_error( $db->error ) ? $db->error->get_error_message() : (string) $db->error;
			return new WP_Error( 'dbmig_connect_failed', $msg ? $msg : __( 'Could not connect to external database.', 'db-migrator' ) );
		}

		// Force a round-trip so we surface connection problems immediately.
		$check = $db->get_var( 'SELECT 1' );
		if ( '1' !== (string) $check && ! empty( $db->last_error ) ) {
			return new WP_Error( 'dbmig_connect_failed', $db->last_error );
		}

		$this->db = $db;
		return $this->db;
	}

	/**
	 * @return true|WP_Error
	 */
	public function test() {
		$db = $this->db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		return true;
	}

	/**
	 * List all tables in the external database.
	 *
	 * @return array|WP_Error
	 */
	public function get_tables() {
		$db = $this->db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$rows = $db->get_col( 'SHOW TABLES' );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		sort( $rows );
		return $rows;
	}

	/**
	 * List columns for a given table.
	 *
	 * @return array[]|WP_Error  Each item: array( name, type, key, nullable )
	 */
	public function get_columns( $table ) {
		$db = $this->db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		// Accept a database-qualified "db.table" (used for current-WP-DB tables).
		$qualified = $this->qualify_table( $table );
		if ( ! $qualified ) {
			return new WP_Error( 'dbmig_bad_table', __( 'Invalid table name.', 'db-migrator' ) );
		}
		$rows = $db->get_results( "SHOW COLUMNS FROM {$qualified}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$cols = array();
		foreach ( $rows as $r ) {
			$cols[] = array(
				'name'     => $r['Field'],
				'type'     => $r['Type'],
				'key'      => $r['Key'],
				'nullable' => ( 'YES' === $r['Null'] ),
			);
		}
		return $cols;
	}

	/**
	 * Sample a few rows from a table for preview in the mapping UI.
	 *
	 * @return array|WP_Error
	 */
	public function sample_rows( $table, $limit = 5 ) {
		$db = $this->db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$table = $this->safe_identifier( $table );
		if ( ! $table ) {
			return new WP_Error( 'dbmig_bad_table', __( 'Invalid table name.', 'db-migrator' ) );
		}
		$limit = max( 1, min( 50, (int) $limit ) );
		$rows  = $db->get_results( "SELECT * FROM `{$table}` LIMIT {$limit}", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function count_rows( $where_sql, $from_sql ) {
		$db = $this->db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$sql = "SELECT COUNT(*) {$from_sql}";
		if ( $where_sql ) {
			$sql .= " WHERE {$where_sql}";
		}
		return (int) $db->get_var( $sql );
	}

	/**
	 * Run an arbitrary read query (built by the importer) and return rows.
	 *
	 * @return array|WP_Error
	 */
	public function query( $sql ) {
		$db = $this->db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$rows = $db->get_results( $sql, ARRAY_A );
		if ( null === $rows && ! empty( $db->last_error ) ) {
			return new WP_Error( 'dbmig_query_failed', $db->last_error );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Allow only sane identifier characters to avoid injection in `SHOW`/`FROM`.
	 */
	public function safe_identifier( $name ) {
		$name = (string) $name;
		if ( preg_match( '/^[A-Za-z0-9_-]+$/', $name ) ) {
			return $name;
		}
		return false;
	}

	/**
	 * Turn a table reference into a safe backtick-qualified identifier.
	 * Accepts "table" (source DB, implicit) or "db.table" (explicit, e.g. the
	 * current WordPress DB). Returns false if invalid.
	 */
	public function qualify_table( $table ) {
		$table = (string) $table;
		if ( false !== strpos( $table, '.' ) ) {
			list( $db, $t ) = explode( '.', $table, 2 );
			$db = $this->safe_identifier( $db );
			$t  = $this->safe_identifier( $t );
			return ( $db && $t ) ? "`{$db}`.`{$t}`" : false;
		}
		$t = $this->safe_identifier( $table );
		return $t ? "`{$t}`" : false;
	}

	/**
	 * Run a write / DDL statement (INSERT, UPDATE, CREATE, ALTER, …) against the
	 * source DB. Returns affected-row count (int) / true, or WP_Error on failure.
	 * NOTE: unlike the rest of this class, this WRITES to the source database — the
	 * caller (the normalize tool) needs CREATE / ALTER / INSERT / UPDATE privileges.
	 *
	 * @return int|bool|WP_Error
	 */
	public function exec( $sql ) {
		$db = $this->db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$res = $db->query( $sql );
		if ( false === $res && ! empty( $db->last_error ) ) {
			return new WP_Error( 'dbmig_exec_failed', $db->last_error );
		}
		return $res;
	}

	/**
	 * Single scalar value from a read query. Returns null on error.
	 */
	public function scalar( $sql ) {
		$db = $this->db();
		if ( is_wp_error( $db ) ) {
			return null;
		}
		return $db->get_var( $sql );
	}

	public function table_exists( $table ) {
		$t = $this->safe_identifier( $table );
		if ( ! $t ) {
			return false;
		}
		$db = $this->db();
		if ( is_wp_error( $db ) ) {
			return false;
		}
		return (bool) $db->get_var( "SHOW TABLES LIKE '{$t}'" );
	}

	public function column_exists( $table, $column ) {
		$qt = $this->qualify_table( $table );
		$c  = $this->safe_identifier( $column );
		if ( ! $qt || ! $c ) {
			return false;
		}
		$db = $this->db();
		if ( is_wp_error( $db ) ) {
			return false;
		}
		return (bool) $db->get_var( "SHOW COLUMNS FROM {$qt} LIKE '{$c}'" );
	}

	/**
	 * The collation of a specific column (e.g. utf8mb4_unicode_ci), or '' for
	 * non-text columns / on error. Used so a table we create to join against this
	 * column can match its collation and avoid "illegal mix of collations".
	 */
	public function column_collation( $table, $column ) {
		$qt = $this->qualify_table( $table );
		$c  = $this->safe_identifier( $column );
		if ( ! $qt || ! $c ) {
			return '';
		}
		$db = $this->db();
		if ( is_wp_error( $db ) ) {
			return '';
		}
		$row = $db->get_row( "SHOW FULL COLUMNS FROM {$qt} LIKE '{$c}'", ARRAY_A );
		return ( $row && ! empty( $row['Collation'] ) ) ? (string) $row['Collation'] : '';
	}

	public function get_database_name() {
		return $this->config['dbname'];
	}

	public function get_host() {
		return $this->config['host'];
	}
}
