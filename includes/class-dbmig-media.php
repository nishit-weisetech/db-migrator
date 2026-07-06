<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media / attachment helper.
 *
 * Files are expected to already exist inside wp-content/uploads (the current
 * month folder by default — the same folder wp_upload_dir() points at). Given a
 * filename from the source DB, this creates a WordPress attachment that points at
 * that file WITHOUT generating any intermediate image sizes (fast). The resized
 * versions can be generated later with the "Generate missing image sizes" tool.
 */
class DBMig_Media {

	/**
	 * Create (or reuse) an attachment for a filename located in the uploads dir.
	 *
	 * @param string $filename  bare filename, path, or URL (only the basename is used)
	 * @param int    $parent_id post to attach to (0 for none / users)
	 * @return int attachment ID, or 0 if the file was not found
	 */
	public static function ensure_attachment( $filename, $parent_id = 0 ) {
		$filename = self::clean_filename( $filename );
		if ( '' === $filename ) {
			return 0;
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}

		// Look in the current-month folder first, then the uploads root.
		$candidates = array(
			trailingslashit( $upload['path'] ) . $filename,
			trailingslashit( $upload['basedir'] ) . $filename,
		);
		$path = '';
		foreach ( $candidates as $c ) {
			if ( file_exists( $c ) ) {
				$path = $c;
				break;
			}
		}
		if ( ! $path ) {
			return 0; // file not uploaded yet
		}

		// Relative path stored in _wp_attached_file (e.g. 2026/07/photo.jpg).
		$basedir = wp_normalize_path( trailingslashit( $upload['basedir'] ) );
		$relpath = ltrim( str_replace( $basedir, '', wp_normalize_path( $path ) ), '/' );

		// Idempotent: reuse an attachment already pointing at this file.
		$existing = self::find_by_file( $relpath );
		if ( $existing ) {
			return $existing;
		}

		$filetype = wp_check_filetype( $filename );
		$url      = trailingslashit( $upload['baseurl'] ) . $relpath;

		$attachment = array(
			'guid'           => $url,
			'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
			'post_title'     => sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$id = wp_insert_attachment( $attachment, $path, (int) $parent_id, true );
		if ( is_wp_error( $id ) ) {
			return 0;
		}

		update_post_meta( $id, '_wp_attached_file', $relpath );

		// Minimal metadata: full size only, NO generated sizes.
		$meta = array(
			'file'  => $relpath,
			'sizes' => array(),
		);
		$dims = @getimagesize( $path );
		if ( is_array( $dims ) ) {
			$meta['width']  = (int) $dims[0];
			$meta['height'] = (int) $dims[1];
		}
		wp_update_attachment_metadata( $id, $meta );

		// Mark as plugin-created so the regen tool can target these.
		update_post_meta( $id, '_dbmig_media', 1 );

		return (int) $id;
	}

	public static function find_by_file( $relpath ) {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$relpath
			)
		);
		return (int) $id;
	}

	/**
	 * Reduce a value to a bare filename (handles full URLs and paths).
	 */
	public static function clean_filename( $val ) {
		$val = trim( (string) $val );
		if ( '' === $val ) {
			return '';
		}
		$p = wp_parse_url( $val, PHP_URL_PATH );
		if ( $p ) {
			$val = $p;
		}
		return basename( $val );
	}

	/* ---- Generate missing image sizes ---- */

	public static function needs_sizes( $id ) {
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) ) {
			return true;
		}
		return empty( $meta['sizes'] );
	}

	/**
	 * IDs of image attachments that have no generated sizes yet.
	 *
	 * @param bool $plugin_only limit to attachments this plugin created
	 * @return int[]
	 */
	public static function scan_missing( $plugin_only = false ) {
		global $wpdb;
		$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'";
		if ( $plugin_only ) {
			$sql = "SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_dbmig_media'
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'";
		}
		$ids     = $wpdb->get_col( $sql );
		$missing = array();
		foreach ( $ids as $id ) {
			if ( self::needs_sizes( (int) $id ) ) {
				$missing[] = (int) $id;
			}
		}
		return $missing;
	}

	/**
	 * Generate the intermediate sizes for one attachment.
	 *
	 * @return true|WP_Error|false
	 */
	public static function generate_sizes( $id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'dbmig_no_file', sprintf( 'File missing for attachment %d', $id ) );
		}
		$meta = wp_generate_attachment_metadata( $id, $file );
		if ( ! empty( $meta ) ) {
			wp_update_attachment_metadata( $id, $meta );
			return true;
		}
		return false;
	}
}
