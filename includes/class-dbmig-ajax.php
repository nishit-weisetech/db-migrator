<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All admin-ajax endpoints. Every handler verifies the nonce and capability.
 */
class DBMig_Ajax {

	const NONCE = 'dbmig_nonce';

	public function hooks() {
		$actions = array(
			'test_connection',
			'get_tables',
			'get_columns',
			'get_acf_fields',
			'sample_rows',
			'save_profile',
			'delete_profile',
			'count',
			'run_batch',
			'generate_sql',
			'list_sql',
			'delete_sql',
			'run_sql_prepare',
			'run_sql_step',
			'media_scan',
			'media_generate',
			'import_profiles',
		);
		foreach ( $actions as $a ) {
			add_action( 'wp_ajax_dbmig_' . $a, array( $this, $a ) );
		}
		// Downloads use a GET link with their own capability + nonce check.
		add_action( 'wp_ajax_dbmig_download_sql', array( 'DBMig_Exporter', 'handle_download' ) );
		add_action( 'wp_ajax_dbmig_export_profiles', array( 'DBMig_Profiles', 'handle_export' ) );
	}

	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'db-migrator' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private function maybe_error( $thing ) {
		if ( is_wp_error( $thing ) ) {
			wp_send_json_error( array( 'message' => $thing->get_error_message() ) );
		}
	}

	public function test_connection() {
		$this->guard();

		// Allow testing unsaved credentials from the form.
		$config = null;
		if ( isset( $_POST['config'] ) && is_array( $_POST['config'] ) ) {
			$config = array(
				'host'   => sanitize_text_field( wp_unslash( $_POST['config']['host'] ?? '' ) ),
				'dbname' => sanitize_text_field( wp_unslash( $_POST['config']['dbname'] ?? '' ) ),
				'dbuser' => sanitize_text_field( wp_unslash( $_POST['config']['dbuser'] ?? '' ) ),
				'dbpass' => (string) wp_unslash( $_POST['config']['dbpass'] ?? '' ),
			);
		}
		$ext    = new DBMig_External_DB( $config );
		$result = $ext->test();
		$this->maybe_error( $result );

		$tables = $ext->get_tables();
		$this->maybe_error( $tables );

		wp_send_json_success(
			array(
				'message'     => __( 'Connection successful.', 'db-migrator' ),
				'table_count' => count( $tables ),
			)
		);
	}

	public function get_tables() {
		$this->guard();
		global $wpdb;
		$ext    = new DBMig_External_DB();
		$tables = $ext->get_tables();

		// A source-DB connection problem must NOT hide the current WordPress DB
		// tables — those are local and always available. Report the source error
		// separately instead of aborting the whole response.
		$source_error = '';
		if ( is_wp_error( $tables ) ) {
			$source_error = $tables->get_error_message();
			$tables       = array();
		}

		// The current WordPress database's tables (for joining to already-migrated
		// content). Same MySQL server, so a cross-DB join works.
		$current = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		sort( $current );

		wp_send_json_success(
			array(
				'tables'       => $tables,
				'source_db'    => $ext->get_database_name(),
				'current_db'   => DB_NAME,
				'current'      => $current,
				'source_error' => $source_error,
			)
		);
	}

	public function get_columns() {
		$this->guard();
		$table = sanitize_text_field( wp_unslash( $_POST['table'] ?? '' ) );
		if ( ! $table ) {
			wp_send_json_error( array( 'message' => __( 'No table specified.', 'db-migrator' ) ) );
		}
		$ext  = new DBMig_External_DB();
		$cols = $ext->get_columns( $table );
		$this->maybe_error( $cols );

		$sample = $ext->sample_rows( $table, 3 );
		wp_send_json_success(
			array(
				'columns' => $cols,
				'sample'  => is_wp_error( $sample ) ? array() : $sample,
			)
		);
	}

	public function sample_rows() {
		$this->guard();
		$table = sanitize_text_field( wp_unslash( $_POST['table'] ?? '' ) );
		$ext   = new DBMig_External_DB();
		$rows  = $ext->sample_rows( $table, (int) ( $_POST['limit'] ?? 5 ) );
		$this->maybe_error( $rows );
		wp_send_json_success( array( 'rows' => $rows ) );
	}

	public function get_acf_fields() {
		$this->guard();
		$context = sanitize_key( wp_unslash( $_POST['context'] ?? 'post' ) );
		if ( 'user' === $context ) {
			$fields = DBMig_ACF::get_fields_for_users();
		} elseif ( 'term' === $context ) {
			$taxonomy = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) );
			$fields   = DBMig_ACF::get_fields_for_taxonomy( $taxonomy );
		} elseif ( 'all' === $context ) {
			$fields = DBMig_ACF::get_all_fields();
		} else {
			$post_type = sanitize_key( wp_unslash( $_POST['post_type'] ?? '' ) );
			$fields    = DBMig_ACF::get_fields_for_post_type( $post_type );
		}
		wp_send_json_success(
			array(
				'acf_active' => DBMig_ACF::is_active(),
				'fields'     => $fields,
			)
		);
	}

	public function save_profile() {
		$this->guard();
		$raw = isset( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : array();
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid profile payload.', 'db-migrator' ) ) );
		}
		$profile = DBMig_Profiles::sanitize( $raw );
		if ( empty( $profile['source_table'] ) || empty( $profile['source_id_column'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Source table and source id column are required.', 'db-migrator' ) ) );
		}
		$id = DBMig_Profiles::save( $profile );
		wp_send_json_success(
			array(
				'message' => __( 'Saved.', 'db-migrator' ),
				'id'      => $id,
			)
		);
	}

	/**
	 * Import migration profiles from an exported JSON file (uploaded as text).
	 * Upserts by profile id, so re-importing on the server updates in place.
	 */
	public function import_profiles() {
		$this->guard();
		$raw  = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['profiles'] ) || ! is_array( $data['profiles'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Not a valid DB Migrator export file.', 'db-migrator' ) ) );
		}
		$added   = 0;
		$updated = 0;
		foreach ( $data['profiles'] as $rawp ) {
			if ( ! is_array( $rawp ) ) {
				continue;
			}
			$profile = DBMig_Profiles::sanitize( $rawp );
			if ( empty( $profile['source_table'] ) ) {
				continue; // skip incomplete entries
			}
			$exists = ! empty( $profile['id'] ) && DBMig_Profiles::get( $profile['id'] );
			DBMig_Profiles::save( $profile );
			if ( $exists ) {
				++$updated;
			} else {
				++$added;
			}
		}
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: added count, 2: updated count */
					__( 'Import complete: %1$d added, %2$d updated.', 'db-migrator' ),
					$added,
					$updated
				),
			)
		);
	}

	public function delete_profile() {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		DBMig_Profiles::delete( $id );
		wp_send_json_success( array( 'message' => __( 'Deleted.', 'db-migrator' ) ) );
	}

	public function count() {
		$this->guard();
		$id      = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$profile = DBMig_Profiles::get( $id );
		if ( ! $profile ) {
			wp_send_json_error( array( 'message' => __( 'Profile not found.', 'db-migrator' ) ) );
		}
		$imp   = new DBMig_Importer( $profile );
		$total = $imp->total();
		$this->maybe_error( $total );
		wp_send_json_success( array( 'total' => (int) $total ) );
	}

	public function run_batch() {
		$this->guard();
		$id      = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$offset  = (int) ( $_POST['offset'] ?? 0 );
		$limit   = (int) ( $_POST['limit'] ?? 50 );
		$limit   = max( 1, min( 500, $limit ) );
		$profile = DBMig_Profiles::get( $id );
		if ( ! $profile ) {
			wp_send_json_error( array( 'message' => __( 'Profile not found.', 'db-migrator' ) ) );
		}

		@set_time_limit( 0 );
		$imp    = new DBMig_Importer( $profile );
		$result = $imp->run_batch( $offset, $limit );
		$this->maybe_error( $result );
		wp_send_json_success( $result );
	}

	public function generate_sql() {
		$this->guard();
		$id      = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$profile = DBMig_Profiles::get( $id );
		if ( ! $profile ) {
			wp_send_json_error( array( 'message' => __( 'Profile not found.', 'db-migrator' ) ) );
		}
		$builder = new DBMig_SQL_Builder( $profile );
		$sql     = $builder->build();

		$saved = DBMig_Exporter::generate( $profile, $sql );
		$this->maybe_error( $saved );

		wp_send_json_success(
			array(
				'record'   => $saved,
				'existing' => $saved['existing'],
				'sql'      => $sql,
				'listing'  => DBMig_Exporter::listing( $profile ),
			)
		);
	}

	/**
	 * Prepare an in-browser SQL run: generate the SQL, split into statements,
	 * cache them, and return the count + labels so the UI can step through them.
	 */
	public function run_sql_prepare() {
		$this->guard();
		if ( ! DBMig_Schema::columns_ready() ) {
			wp_send_json_error( array( 'message' => __( 'Legacy columns are missing on wp_posts. Run "Ensure schema" first.', 'db-migrator' ) ) );
		}
		$id      = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$profile = DBMig_Profiles::get( $id );
		if ( ! $profile ) {
			wp_send_json_error( array( 'message' => __( 'Profile not found.', 'db-migrator' ) ) );
		}
		$sql   = ( new DBMig_SQL_Builder( $profile ) )->build();
		$stmts = DBMig_SQL_Builder::split_statements( $sql );

		set_transient( 'dbmig_run_' . $id, $stmts, HOUR_IN_SECONDS );

		$labels = array();
		foreach ( $stmts as $s ) {
			$labels[] = DBMig_SQL_Builder::statement_label( $s );
		}
		wp_send_json_success(
			array(
				'total'  => count( $stmts ),
				'labels' => $labels,
			)
		);
	}

	/**
	 * Execute one prepared statement by index.
	 */
	public function run_sql_step() {
		$this->guard();
		global $wpdb;

		$id    = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$index = (int) ( $_POST['index'] ?? 0 );
		$stmts = get_transient( 'dbmig_run_' . $id );
		if ( ! is_array( $stmts ) || ! isset( $stmts[ $index ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Run session expired — please start again.', 'db-migrator' ) ) );
		}

		@set_time_limit( 0 );
		$sql = $stmts[ $index ];

		$suppress = $wpdb->suppress_errors( true );
		$t0       = microtime( true );
		$result   = $wpdb->query( $sql );
		$ms       = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$error    = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( $error ) {
			wp_send_json_error(
				array(
					'message' => $error,
					'index'   => $index,
					'label'   => DBMig_SQL_Builder::statement_label( $sql ),
				)
			);
		}

		$is_last = ( $index + 1 >= count( $stmts ) );
		if ( $is_last ) {
			delete_transient( 'dbmig_run_' . $id );
		}

		wp_send_json_success(
			array(
				'index'   => $index,
				'label'   => DBMig_SQL_Builder::statement_label( $sql ),
				'rows'    => ( false === $result ) ? 0 : (int) $result,
				'ms'      => $ms,
				'is_last' => $is_last,
			)
		);
	}

	public function media_scan() {
		$this->guard();
		$plugin_only = ! empty( $_POST['plugin_only'] );
		$ids         = DBMig_Media::scan_missing( $plugin_only );
		wp_send_json_success(
			array(
				'total' => count( $ids ),
				'ids'   => $ids,
			)
		);
	}

	public function media_generate() {
		$this->guard();
		@set_time_limit( 0 );
		$ids  = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
		$done = 0;
		$fail = 0;
		$log  = array();
		foreach ( $ids as $id ) {
			$res = DBMig_Media::generate_sizes( (int) $id );
			if ( true === $res ) {
				$done++;
			} else {
				$fail++;
				if ( is_wp_error( $res ) ) {
					$log[] = $res->get_error_message();
				}
			}
		}
		wp_send_json_success(
			array(
				'done' => $done,
				'fail' => $fail,
				'log'  => $log,
			)
		);
	}

	public function list_sql() {
		$this->guard();
		$id      = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$profile = DBMig_Profiles::get( $id );
		if ( ! $profile ) {
			wp_send_json_error( array( 'message' => __( 'Profile not found.', 'db-migrator' ) ) );
		}
		wp_send_json_success( DBMig_Exporter::listing( $profile ) );
	}

	public function delete_sql() {
		$this->guard();
		$id      = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$file    = sanitize_file_name( wp_unslash( $_POST['file'] ?? '' ) );
		$res     = DBMig_Exporter::delete( $file );
		$this->maybe_error( $res );
		$profile = DBMig_Profiles::get( $id );
		wp_send_json_success(
			array(
				'message' => __( 'Deleted.', 'db-migrator' ),
				'listing' => $profile ? DBMig_Exporter::listing( $profile ) : array( 'files' => array(), 'current_hash' => '' ),
			)
		);
	}
}
