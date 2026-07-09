<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Normalize source" tool.
 *
 * Turns a denormalized name column (e.g. posts.author_name, where the same name
 * repeats and there is no author id) into a proper lookup table + foreign key,
 * all inside the SOURCE database:
 *
 *   1. CREATE a table (default `dbmig_authors`) with an auto-increment id and a
 *      UNIQUE name — so each distinct name gets exactly one id.
 *   2. INSERT IGNORE the DISTINCT names into it.
 *   3. ADD a foreign-key column (default `author_id`) to the source table.
 *   4. UPDATE the source table, setting that column from the matching name.
 *
 * After this, the source table has a real unique id per name, so the normal
 * id-based User migration and post_author resolution work with no special cases.
 *
 * Everything is idempotent (CREATE IF NOT EXISTS, INSERT IGNORE against a UNIQUE
 * key, ADD COLUMN only when missing, a re-runnable UPDATE) and WRITES to the
 * source DB — the connection user needs CREATE / ALTER / INSERT / UPDATE rights.
 */
class DBMig_Normalizer {

	const DEFAULT_TABLE = 'dbmig_authors';
	const DEFAULT_FK    = 'author_id';

	/**
	 * Validate + normalise the raw request into a clean options array.
	 *
	 * @return array|WP_Error  array( source_table, name_col, target_table, fk_col, trim )
	 */
	public static function sanitize( $raw ) {
		$ext = new DBMig_External_DB();

		$source = self::ident( $raw['source_table'] ?? '' );
		$name   = self::ident( $raw['name_col'] ?? '' );
		$target = self::ident( $raw['target_table'] ?? '' ) ?: self::DEFAULT_TABLE;
		$fk     = self::ident( $raw['fk_col'] ?? '' ) ?: self::DEFAULT_FK;

		if ( '' === $source ) {
			return new WP_Error( 'dbmig_nz_source', __( 'Pick a source table (in the legacy DB).', 'db-migrator' ) );
		}
		if ( false !== strpos( (string) ( $raw['source_table'] ?? '' ), '.' ) ) {
			return new WP_Error( 'dbmig_nz_source_db', __( 'The source table must live in the legacy database (not a db.table reference), because this tool writes to it.', 'db-migrator' ) );
		}
		if ( '' === $name ) {
			return new WP_Error( 'dbmig_nz_name', __( 'Pick the name column to build ids from.', 'db-migrator' ) );
		}
		if ( $target === $source ) {
			return new WP_Error( 'dbmig_nz_collide', __( 'The new lookup table must have a different name from the source table.', 'db-migrator' ) );
		}
		if ( $fk === $name ) {
			return new WP_Error( 'dbmig_nz_fkname', __( 'The new id column must have a different name from the name column.', 'db-migrator' ) );
		}

		return array(
			'source_table' => $source,
			'name_col'     => $name,
			'target_table' => $target,
			'fk_col'       => $fk,
			'trim'         => ! empty( $raw['trim'] ),
		);
	}

	/** Allow only safe identifier characters; '' if invalid/empty. */
	private static function ident( $v ) {
		$v = trim( (string) wp_unslash( $v ) );
		return preg_match( '/^[A-Za-z0-9_]+$/', $v ) ? $v : '';
	}

	/**
	 * Read-only counts + existence flags, so the UI can show what will happen
	 * before anything is written.
	 *
	 * @return array
	 */
	public static function preview( $ext, $o ) {
		$src      = $o['source_table'];
		$name     = $o['name_col'];
		$name_raw = "`{$name}`";
		$name_sel = $o['trim'] ? "TRIM({$name_raw})" : $name_raw;
		$where    = "{$name_raw} IS NOT NULL AND {$name_sel} <> ''";

		$distinct = $ext->scalar( "SELECT COUNT(DISTINCT {$name_sel}) FROM `{$src}` WHERE {$where}" );
		$rows     = $ext->scalar( "SELECT COUNT(*) FROM `{$src}` WHERE {$where}" );

		return array(
			'distinct_names' => (int) $distinct,
			'linkable_rows'  => (int) $rows,
			'target_exists'  => $ext->table_exists( $o['target_table'] ),
			'fk_exists'      => $ext->column_exists( $o['source_table'], $o['fk_col'] ),
		);
	}

	/**
	 * The ordered statements to run, reflecting the CURRENT schema state (so the
	 * ADD COLUMN step is omitted when the column already exists — MySQL has no
	 * "ADD COLUMN IF NOT EXISTS"). Each item: array( label, sql ).
	 *
	 * @return array[]
	 */
	public static function statements( $ext, $o ) {
		$src  = $o['source_table'];
		$name = $o['name_col'];
		$tgt  = $o['target_table'];
		$fk   = $o['fk_col'];

		$name_sel  = $o['trim'] ? "TRIM(`{$name}`)" : "`{$name}`";
		$name_join = $o['trim'] ? "TRIM(p.`{$name}`)" : "p.`{$name}`";

		// Match the lookup column's collation to the source column so the JOIN in
		// step 4 can't hit "illegal mix of collations".
		$collation = $ext->column_collation( $src, $name );
		$name_def  = 'VARCHAR(191) NOT NULL';
		if ( $collation ) {
			$charset  = current( explode( '_', $collation ) );
			$name_def = "VARCHAR(191) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL";
		}

		$stmts = array();

		$stmts[] = array(
			'label' => sprintf( 'Create lookup table `%s`', $tgt ),
			'sql'   => "CREATE TABLE IF NOT EXISTS `{$tgt}` (\n"
				. "  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
				. "  `name` {$name_def},\n"
				. "  PRIMARY KEY (`id`),\n"
				. "  UNIQUE KEY `name` (`name`)\n"
				. ');',
		);

		$stmts[] = array(
			'label' => 'Insert one row per distinct name',
			'sql'   => "INSERT IGNORE INTO `{$tgt}` (`name`)\n"
				. "SELECT DISTINCT {$name_sel}\n"
				. "FROM `{$src}`\n"
				. "WHERE `{$name}` IS NOT NULL AND {$name_sel} <> '';",
		);

		if ( ! $ext->column_exists( $src, $fk ) ) {
			$stmts[] = array(
				'label' => sprintf( 'Add id column `%s` to `%s`', $fk, $src ),
				'sql'   => "ALTER TABLE `{$src}` ADD COLUMN `{$fk}` BIGINT UNSIGNED NULL;",
			);
		}

		$stmts[] = array(
			'label' => sprintf( 'Link `%s`.`%s` to the matching id', $src, $fk ),
			'sql'   => "UPDATE `{$src}` p\n"
				. "JOIN `{$tgt}` a ON a.`name` = {$name_join}\n"
				. "SET p.`{$fk}` = a.`id`;",
		);

		return $stmts;
	}

	/**
	 * Render statements as a single copy/paste-able .sql script.
	 */
	public static function to_text( $statements, $o ) {
		$lines   = array();
		$lines[] = '-- DB Migrator — normalize source (build a lookup table of names → ids)';
		$lines[] = sprintf( '-- source: `%s`.`%s`  →  lookup `%s` (id, name) + FK `%s`', $o['source_table'], $o['name_col'], $o['target_table'], $o['fk_col'] );
		$lines[] = '-- Run against the LEGACY (source) database. Safe to re-run.';
		$lines[] = '';
		foreach ( $statements as $i => $s ) {
			$lines[] = sprintf( '-- %d) %s', $i + 1, $s['label'] );
			$lines[] = $s['sql'];
			$lines[] = '';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Execute the statements in order. Stops at the first error.
	 *
	 * @return array|WP_Error  array of array( label, rows ) on success
	 */
	public static function run( $ext, $o ) {
		$results = array();
		foreach ( self::statements( $ext, $o ) as $s ) {
			$res = $ext->exec( $s['sql'] );
			if ( is_wp_error( $res ) ) {
				return new WP_Error(
					'dbmig_nz_run',
					$s['label'] . ': ' . $res->get_error_message(),
					array( 'results' => $results )
				);
			}
			$results[] = array(
				'label' => $s['label'],
				'rows'  => is_int( $res ) ? $res : 0,
			);
		}
		return $results;
	}
}
