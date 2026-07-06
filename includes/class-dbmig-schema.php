<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds and verifies the legacy-link columns used to relate WordPress rows back
 * to their source rows:
 *   - legacy_id          BIGINT   (id of the source row)
 *   - legacy_table_name  VARCHAR  (which source table the row came from)
 *
 * These are added, with a composite index, to wp_posts, wp_users and wp_terms so
 * posts, authors and taxonomy terms can all be resolved and re-run idempotently
 * via a fast indexed lookup.
 */
class DBMig_Schema {

	const INDEX = 'dbmig_legacy';

	/**
	 * @return true|WP_Error
	 */
	public static function ensure_columns() {
		global $wpdb;

		foreach ( array( $wpdb->posts, $wpdb->users, $wpdb->terms ) as $table ) {
			$res = self::add_legacy_columns( $table );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		// Carry over any users previously stamped in usermeta into the new columns.
		self::backfill_user_columns();

		return true;
	}

	/**
	 * Add legacy_id + legacy_table_name + composite index to a table.
	 *
	 * @return true|WP_Error
	 */
	private static function add_legacy_columns( $table ) {
		global $wpdb;

		if ( ! self::column_exists( $table, 'legacy_id' ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `legacy_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL" );
			if ( $wpdb->last_error ) {
				return new WP_Error( 'dbmig_alter_failed', $wpdb->last_error );
			}
		}
		if ( ! self::column_exists( $table, 'legacy_table_name' ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `legacy_table_name` VARCHAR(191) NULL DEFAULT NULL" );
			if ( $wpdb->last_error ) {
				return new WP_Error( 'dbmig_alter_failed', $wpdb->last_error );
			}
		}
		if ( ! self::index_exists( $table, self::INDEX ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `" . self::INDEX . "` (`legacy_table_name`, `legacy_id`)" );
		}
		return true;
	}

	/**
	 * Populate the wp_users legacy columns from the older usermeta stamps
	 * (_dbmig_legacy_id / _dbmig_legacy_table), for anything migrated before the
	 * columns existed. Only fills rows whose column is still empty.
	 */
	private static function backfill_user_columns() {
		global $wpdb;
		if ( ! self::column_exists( $wpdb->users, 'legacy_id' ) ) {
			return;
		}
		$wpdb->query(
			"UPDATE `{$wpdb->users}` u
			 JOIN `{$wpdb->usermeta}` lt ON lt.user_id = u.ID AND lt.meta_key = '_dbmig_legacy_table'
			 JOIN `{$wpdb->usermeta}` li ON li.user_id = u.ID AND li.meta_key = '_dbmig_legacy_id'
			 SET u.legacy_table_name = lt.meta_value, u.legacy_id = li.meta_value
			 WHERE u.legacy_id IS NULL"
		);
	}

	public static function columns_ready() {
		global $wpdb;
		return self::column_exists( $wpdb->posts, 'legacy_id' )
			&& self::column_exists( $wpdb->posts, 'legacy_table_name' );
	}

	public static function users_columns_ready() {
		global $wpdb;
		return self::column_exists( $wpdb->users, 'legacy_id' );
	}

	public static function terms_columns_ready() {
		global $wpdb;
		return self::column_exists( $wpdb->terms, 'legacy_id' );
	}

	public static function column_exists( $table, $column ) {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column )
		);
		return ! empty( $found );
	}

	public static function index_exists( $table, $index ) {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index )
		);
		return ! empty( $found );
	}

	/* --------------------------------------------------------------------- *
	 *  Lookups (indexed columns)
	 * --------------------------------------------------------------------- */

	public static function find_post_by_legacy( $legacy_table, $legacy_id, $post_type = '' ) {
		if ( '' === (string) $legacy_id || null === $legacy_id ) {
			return 0;
		}
		// When a post type is given, match on it too so the same source table can
		// feed two migrations of different types without colliding.
		if ( '' !== (string) $post_type ) {
			global $wpdb;
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM `{$wpdb->posts}` WHERE legacy_table_name = %s AND legacy_id = %d AND post_type = %s LIMIT 1",
					$legacy_table,
					$legacy_id,
					$post_type
				)
			);
		}
		return self::find_by_legacy( 'posts', 'ID', $legacy_table, $legacy_id );
	}

	public static function find_user_by_legacy( $legacy_table, $legacy_id ) {
		return self::find_by_legacy( 'users', 'ID', $legacy_table, $legacy_id );
	}

	public static function find_term_by_legacy( $legacy_table, $legacy_id ) {
		return self::find_by_legacy( 'terms', 'term_id', $legacy_table, $legacy_id );
	}

	private static function find_by_legacy( $which, $id_col, $legacy_table, $legacy_id ) {
		global $wpdb;
		if ( '' === (string) $legacy_id || null === $legacy_id ) {
			return 0;
		}
		$table = $wpdb->$which;
		$id    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT {$id_col} FROM `{$table}` WHERE legacy_table_name = %s AND legacy_id = %d LIMIT 1",
				$legacy_table,
				$legacy_id
			)
		);
		return (int) $id;
	}

	/* --------------------------------------------------------------------- *
	 *  Stamps (write the indexed columns)
	 * --------------------------------------------------------------------- */

	public static function stamp_post_legacy( $post_id, $legacy_table, $legacy_id ) {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'legacy_table_name' => $legacy_table, 'legacy_id' => $legacy_id ), array( 'ID' => $post_id ), array( '%s', '%d' ), array( '%d' ) );
	}

	public static function stamp_user_legacy( $user_id, $legacy_table, $legacy_id ) {
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'legacy_table_name' => $legacy_table, 'legacy_id' => $legacy_id ), array( 'ID' => $user_id ), array( '%s', '%d' ), array( '%d' ) );
	}

	public static function stamp_term_legacy( $term_id, $legacy_table, $legacy_id ) {
		global $wpdb;
		$wpdb->update( $wpdb->terms, array( 'legacy_table_name' => $legacy_table, 'legacy_id' => $legacy_id ), array( 'term_id' => $term_id ), array( '%s', '%d' ), array( '%d' ) );
	}
}
