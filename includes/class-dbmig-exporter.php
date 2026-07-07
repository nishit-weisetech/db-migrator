<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes generated SQL to .sql files inside the plugin's exports/ directory and
 * tracks them in an index (wp_options). Regeneration is deduplicated by a hash
 * of the mapping, so clicking "Generate" repeatedly without changing the mapping
 * reuses the existing file instead of piling up duplicates. Files can be listed
 * and deleted from the admin page.
 */
class DBMig_Exporter {

	const SUBDIR = 'exports';
	const OPTION = 'dbmig_exports';

	// Bump when the SQL generation logic changes so previously generated files
	// are treated as stale and a fresh (corrected) file is produced.
	const GEN_VERSION = 30;

	public static function dir_path() {
		return trailingslashit( DBMIG_DIR . self::SUBDIR );
	}

	public static function ensure_dir() {
		$dir = self::dir_path();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
	}

	/* ---- index (wp_options) ---- */

	private static function index() {
		$i = get_option( self::OPTION, array() );
		return is_array( $i ) ? $i : array();
	}

	private static function save_index( $index ) {
		update_option( self::OPTION, array_values( $index ) );
	}

	/**
	 * Hash of the parts of a profile that affect the generated SQL. Name / id are
	 * excluded so renaming does not force a regenerate.
	 */
	public static function profile_hash( $profile ) {
		$keys = array( 'migration_type', 'post_type', 'taxonomy', 'post_status', 'role', 'partial', 'source_table', 'source_id_column', 'joins', 'fields', 'repeaters' );
		$sub  = array();
		foreach ( $keys as $k ) {
			$sub[ $k ] = isset( $profile[ $k ] ) ? $profile[ $k ] : null;
		}
		return substr( md5( self::GEN_VERSION . '|' . wp_json_encode( $sub ) ), 0, 10 );
	}

	/**
	 * Generate (or reuse) a .sql file for a profile.
	 *
	 * @return array|WP_Error  record + { existing: bool }
	 */
	public static function generate( $profile, $sql ) {
		self::ensure_dir();
		$hash = self::profile_hash( $profile );

		// Reuse an existing file for this profile + mapping hash if present.
		foreach ( self::index() as $rec ) {
			if ( $rec['profile_id'] === $profile['id'] && $rec['hash'] === $hash && file_exists( self::dir_path() . $rec['file'] ) ) {
				return self::decorate( $rec, true, $hash );
			}
		}

		$slug     = sanitize_file_name( ! empty( $profile['name'] ) ? $profile['name'] : $profile['id'] );
		$slug     = $slug ? $slug : 'migration';
		$stamp    = gmdate( 'Ymd-His' );
		$filename = sprintf( '%s-%s-%s.sql', $slug, $hash, $stamp );
		$path     = self::dir_path() . $filename;

		$bytes = file_put_contents( $path, $sql );
		if ( false === $bytes ) {
			return new WP_Error( 'dbmig_write_failed', __( 'Could not write the .sql file. Check that the plugin directory is writable.', 'db-migrator' ) );
		}

		$rec = array(
			'file'         => $filename,
			'profile_id'   => $profile['id'],
			'profile_name' => isset( $profile['name'] ) ? $profile['name'] : '',
			'hash'         => $hash,
			'bytes'        => $bytes,
			'created'      => time(),
		);
		$index   = self::index();
		$index[] = $rec;
		self::save_index( $index );

		return self::decorate( $rec, false, $hash );
	}

	/**
	 * List generated files for a profile (drops entries whose file is gone).
	 *
	 * @return array  { current_hash, files: [record...] }
	 */
	public static function listing( $profile ) {
		$current_hash = self::profile_hash( $profile );
		$index        = self::index();
		$out          = array();
		$kept         = array();
		$changed      = false;

		foreach ( $index as $rec ) {
			if ( ! file_exists( self::dir_path() . $rec['file'] ) ) {
				$changed = true;
				continue; // prune missing
			}
			$kept[] = $rec;
			if ( $rec['profile_id'] === $profile['id'] ) {
				$out[] = self::decorate( $rec, true, $current_hash );
			}
		}
		if ( $changed ) {
			self::save_index( $kept );
		}

		// Newest first.
		usort(
			$out,
			function ( $a, $b ) {
				return $b['created'] - $a['created'];
			}
		);

		return array(
			'current_hash' => $current_hash,
			'files'        => $out,
		);
	}

	/**
	 * Delete a generated file (by name) and drop it from the index.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( $file ) {
		$file = basename( $file );
		if ( ! preg_match( '/^[A-Za-z0-9._-]+\.sql$/', $file ) ) {
			return new WP_Error( 'dbmig_bad_file', __( 'Invalid file name.', 'db-migrator' ) );
		}
		$path = self::dir_path() . $file;
		if ( file_exists( $path ) ) {
			@unlink( $path );
		}
		$index = self::index();
		$kept  = array();
		foreach ( $index as $rec ) {
			if ( $rec['file'] !== $file ) {
				$kept[] = $rec;
			}
		}
		self::save_index( $kept );
		return true;
	}

	/**
	 * Add computed fields (path, command, download url, up-to-date flag) to a record.
	 */
	private static function decorate( $rec, $existing, $current_hash ) {
		$path                 = self::dir_path() . $rec['file'];
		$rec['path']          = wp_normalize_path( $path );
		$rec['bytes']         = file_exists( $path ) ? filesize( $path ) : ( isset( $rec['bytes'] ) ? $rec['bytes'] : 0 );
		$rec['command']       = self::command( $path );
		$rec['download_url']  = self::download_url( $rec['file'] );
		$rec['existing']      = (bool) $existing;
		$rec['is_current']    = ( $rec['hash'] === $current_hash );
		$rec['created_human'] = self::human_time( $rec['created'] );
		return $rec;
	}

	private static function human_time( $ts ) {
		$ts = (int) $ts;
		if ( ! $ts ) {
			return '';
		}
		return date_i18n( 'Y-m-d H:i', $ts + ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) );
	}

	private static function download_url( $file ) {
		return add_query_arg(
			array(
				'action' => 'dbmig_download_sql',
				'file'   => rawurlencode( $file ),
				'nonce'  => wp_create_nonce( 'dbmig_download' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * mysql import command for a file. Defaults the connection to the WordPress
	 * database so unqualified table names resolve; the SQL qualifies the legacy DB.
	 */
	public static function command( $path ) {
		$host = DB_HOST;
		$port = '';
		if ( false !== strpos( $host, ':' ) ) {
			list( $host, $suffix ) = explode( ':', $host, 2 );
			if ( is_numeric( $suffix ) ) {
				$port = ' -P ' . $suffix;
			} else {
				$port = ' --socket=' . $suffix;
			}
		}
		$host = $host ? $host : 'localhost';
		$pass = ( defined( 'DB_PASSWORD' ) && '' !== DB_PASSWORD ) ? ' -p' : '';

		return sprintf(
			'mysql -h %s%s -u %s%s %s < "%s"',
			$host,
			$port,
			DB_USER,
			$pass,
			DB_NAME,
			wp_normalize_path( $path )
		);
	}

	/**
	 * Stream a previously generated file to the browser (authenticated).
	 */
	public static function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'db-migrator' ), 403 );
		}
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'dbmig_download' ) ) {
			wp_die( esc_html__( 'Invalid or expired link.', 'db-migrator' ), 403 );
		}
		$file = isset( $_GET['file'] ) ? basename( wp_unslash( $_GET['file'] ) ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9._-]+\.sql$/', $file ) ) {
			wp_die( esc_html__( 'Invalid file.', 'db-migrator' ), 400 );
		}
		$path = self::dir_path() . $file;
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'db-migrator' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: application/sql; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}
}
