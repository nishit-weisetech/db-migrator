<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates idempotent (create-or-update) MySQL for the "fast path": when the
 * external DB lives on the same MySQL server as WordPress, rows can be migrated
 * with cross-database statements. Supports both post-type and user migrations.
 *
 * Idempotency strategy (so re-running never duplicates):
 *   - Main row : UPDATE existing (matched by legacy key) + INSERT only-missing.
 *   - Meta     : DELETE existing for that meta_key on migrated rows, then INSERT.
 *
 * Still PHP-only: ACF repeaters and multi-value ACF relationships (these need
 * serialized arrays) and taxonomy term creation. Single ACF relationship /
 * post_object and post_author resolution ARE generated here via sub-queries
 * against the legacy_id / legacy_table_name link.
 */
class DBMig_SQL_Builder {

	private $profile;
	private $ext;
	private $source_db;

	/** wp_users real columns (everything else on a user is usermeta). */
	private $user_columns = array( 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'display_name' );

	public function __construct( $profile, DBMig_External_DB $ext = null ) {
		$this->profile   = $profile;
		$this->ext       = $ext ? $ext : new DBMig_External_DB();
		$this->source_db = $this->ext->get_database_name();
	}

	public function build() {
		$type = $this->profile['migration_type'] ?? 'post';
		if ( 'user' === $type ) {
			return $this->build_users();
		}
		if ( 'term' === $type ) {
			return $this->build_terms();
		}
		if ( 'comment' === $type ) {
			return $this->build_comments();
		}
		return $this->build_posts();
	}

	/**
	 * Split generated SQL into individually-executable statements (comments and
	 * blank lines removed). Statements are terminated by ";" at end of line; the
	 * generated SQL never puts a ";\n" inside a statement or string literal.
	 *
	 * @param string $sql
	 * @return string[]
	 */
	public static function split_statements( $sql ) {
		$out = array();
		foreach ( preg_split( '/;\s*\n/', $sql ) as $chunk ) {
			$clean = preg_replace( '/^\s*--.*$/m', '', $chunk );      // drop comment lines
			$clean = trim( rtrim( $clean, "; \n\r\t" ) );
			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * A short human label for a statement (for the run progress display).
	 */
	public static function statement_label( $stmt ) {
		$s = preg_replace( '/\s+/', ' ', trim( $stmt ) );
		if ( preg_match( '/^INSERT(?:\s+IGNORE)?\s+INTO\s+`?(\w+)/i', $s, $m ) ) {
			return 'Insert into ' . $m[1];
		}
		if ( preg_match( '/^UPDATE\s+`?(\w+)/i', $s, $m ) ) {
			return 'Update ' . $m[1];
		}
		if ( preg_match( '/^DELETE\b.*?FROM\s+`?(\w+)/i', $s, $m ) ) {
			return 'Delete from ' . $m[1];
		}
		if ( preg_match( '/^(CREATE|DROP)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?(\w+)/i', $s, $m ) ) {
			return ucfirst( strtolower( $m[1] ) ) . ' table ' . $m[2];
		}
		if ( preg_match( '/^SET\b/i', $s ) ) {
			return 'Session setting';
		}
		return substr( $s, 0, 40 );
	}

	/* ===================================================================== *
	 *  POSTS
	 * ===================================================================== */

	private function build_posts() {
		global $wpdb;

		list( , $base ) = $this->parse_table( $this->profile['source_table'] );
		$id_col = $this->id( $this->profile['source_id_column'] );
		$type   = esc_sql( $this->profile['post_type'] );
		$status = esc_sql( $this->profile['post_status'] ? $this->profile['post_status'] : 'publish' );
		$ltn    = esc_sql( $this->profile['source_table'] );
		$key    = "`{$base}`.`{$id_col}`";
		$from   = $this->source_from();

		$partial = ! empty( $this->profile['partial'] );
		$lines   = $this->header( 'post', $wpdb->posts );
		if ( $partial ) {
			$lines[] = '-- PARTIAL UPDATE: only already-migrated rows are touched (no new';
			$lines[] = '-- rows created) and only the mapped fields are written.';
			$lines[] = '';
		}

		// Post-field expressions (post_title, post_content, post_author, ...).
		$fields = $this->post_field_expressions( $base );

		// Attachments derive their MIME type automatically from the file URL (guid)
		// extension — there is no hand-mapped MIME field. Regular posts keep the old
		// behaviour (blank, or a mapped value on legacy profiles).
		$guid_expr = $this->expr_or( $fields, 'guid', "''" );
		$is_attach = ( 'attachment' === $type );
		$mime_expr = $is_attach
			? $this->mime_type_expr( $fields, $guid_expr )
			: $this->expr_or( $fields, 'post_mime_type', "''" );

		/* ---- 1. UPDATE existing posts (matched by legacy key) ---- */
		// COALESCE(expr, existing) so a NULL source / unmatched LEFT JOIN keeps the
		// current value instead of nulling a NOT NULL column or wiping good data.
		// In partial mode post_modified is left alone unless a post field is mapped.
		if ( ! $partial || ! empty( $fields ) ) {
			$set = $partial ? array() : array( 'p.`post_modified` = NOW()', 'p.`post_modified_gmt` = NOW()' );
			foreach ( $fields as $col => $expr ) {
				$set[] = "p.`{$col}` = COALESCE({$expr}, p.`{$col}`)";
			}
			// Keep the derived MIME type in sync on re-runs (only overwrites when the
			// guid extension yields a known type; an unknown/blank ext keeps existing).
			if ( $is_attach ) {
				$set[] = "p.`post_mime_type` = COALESCE(NULLIF({$mime_expr}, ''), p.`post_mime_type`)";
			}
			if ( $set ) {
				$lines[] = '-- 1) Update rows that were already migrated.';
				$lines[] = "UPDATE `{$wpdb->posts}` p";
				$lines[] = "JOIN {$from}";
				$lines[] = "  ON p.legacy_table_name = '{$ltn}' AND p.post_type = '{$type}' AND " . 'p.legacy_id = ' . $key;
				$lines[] = 'SET ' . implode( ",\n    ", $set ) . ';';
				$lines[] = '';
			}
		}

		/* ---- 2. INSERT posts that do not exist yet (skipped in partial mode) ---- */
		$cols = array(
			'post_author'           => $this->expr_or( $fields, 'post_author', '0' ),
			'post_date'             => $this->expr_or( $fields, 'post_date', 'NOW()' ),
			'post_date_gmt'         => $this->expr_or( $fields, 'post_date', 'NOW()' ),
			'post_content'          => $this->expr_or( $fields, 'post_content', "''" ),
			'post_title'            => $this->expr_or( $fields, 'post_title', "''" ),
			'post_excerpt'          => $this->expr_or( $fields, 'post_excerpt', "''" ),
			'post_status'           => "'{$status}'",
			'comment_status'        => "'closed'",
			'ping_status'           => "'closed'",
			'post_password'         => "''",
			'post_name'             => $this->expr_or( $fields, 'post_name', "''" ),
			'to_ping'               => "''",
			'pinged'                => "''",
			'post_modified'         => 'NOW()',
			'post_modified_gmt'     => 'NOW()',
			'post_content_filtered' => "''",
			'post_parent'           => $this->expr_or( $fields, 'post_parent', '0' ),
			'guid'                  => $guid_expr,
			'menu_order'            => $this->expr_or( $fields, 'menu_order', '0' ),
			'post_type'             => "'{$type}'",
			'post_mime_type'        => $mime_expr,
			'comment_count'         => '0',
			'legacy_id'             => $key,
			'legacy_table_name'     => "'{$ltn}'",
		);

		if ( ! $partial ) {
			$lines[] = '-- 2) Insert rows that have not been migrated yet.';
			$lines[] = "INSERT INTO `{$wpdb->posts}`\n  (`" . implode( '`, `', array_keys( $cols ) ) . '`)';
			$lines[] = 'SELECT ' . implode( ",\n    ", array_values( $cols ) );
			$lines[] = "FROM {$from}";
			$lines[] = "WHERE NOT EXISTS (SELECT 1 FROM `{$wpdb->posts}` p2 WHERE p2.legacy_table_name = '{$ltn}' AND p2.post_type = '{$type}' AND " . 'p2.legacy_id = ' . $key . ');';
			$lines[] = '';
		}

		/* ---- 3. Meta (delete + insert => idempotent) ---- */
		$meta = $this->meta_fields();
		if ( $meta ) {
			$lines[] = '-- 3) Meta values (delete-then-insert keeps re-runs duplicate-free).';
			// Relationships aggregate over the fanned-out join chain (all joins), so
			// every related id per post is collected into one ACF array.
			$from_all = $this->source_from( true );
			foreach ( $meta as $mf ) {
				if ( 'acf_relation' === $mf['target_kind'] ) {
					$lines = array_merge( $lines, $this->relation_meta_block( $mf, $base, $key, $ltn, $from_all ) );
				} else {
					$lines = array_merge( $lines, $this->post_meta_block( $mf, $base, $key, $ltn, $from ) );
				}
			}
		}

		/* ---- 4. Taxonomies (create terms + assign, via the join chain) ---- */
		$tax_lines = $this->build_taxonomy( $base, $ltn );
		if ( $tax_lines ) {
			$lines = array_merge( $lines, $tax_lines );
		}

		/* ---- 5. Post author resolution (fast, set-based) ---- */
		$author_lines = $this->build_author_links( $base, $ltn );
		if ( $author_lines ) {
			$lines = array_merge( $lines, $author_lines );
		}

		$lines[] = '';
		$lines[] = '-- Note: ACF repeaters and multi-value ACF relationships still run through "Run import".';
		$lines = array_merge( $lines, $this->footer() );

		return implode( "\n", $lines );
	}

	/**
	 * Generate term creation + assignment SQL for every taxonomy mapping.
	 *
	 * Terms are created from the taxonomy source's own table (small: e.g. a
	 * category table), then assigned to posts by walking the full join chain
	 * (base → junction → detail) so many-to-many relations attach every term.
	 * Idempotent: terms match by name, relationships guard with NOT EXISTS.
	 *
	 * @return array
	 */
	private function build_taxonomy( $base, $ltn ) {
		global $wpdb;

		$tax_fields = array();
		foreach ( $this->profile['fields'] as $f ) {
			if ( 'taxonomy' === $f['target_kind'] && ! empty( $f['source'] ) && ! empty( $f['target'] ) ) {
				$tax_fields[] = $f;
			}
		}
		if ( empty( $tax_fields ) ) {
			return array();
		}

		$db        = $this->id( $this->source_db );
		$from_all  = $this->source_from( true ); // base + ALL joins (fan-out wanted)
		$key       = "`{$base}`.`" . $this->id( $this->profile['source_id_column'] ) . '`';
		$name_coll = $this->wp_column_collation( 'name', $wpdb->terms );

		$lines = array();
		foreach ( $tax_fields as $f ) {
			$tax  = esc_sql( $f['target'] );
			$src  = $this->qualify_expr( $f['source'], $base ); // e.g. `detail`.`name`
			// Detail table the term values come from (small table -> cheap DISTINCT).
			// It may live in the source DB or, when joined from the current WP DB
			// (e.g. migration.wp_terms), in that database — resolve its real DB from
			// the join so the qualified reference is correct.
			$detail    = $base;
			$detail_db = $db;
			if ( false !== strpos( $f['source'], '.' ) ) {
				list( $dt ) = explode( '.', $f['source'], 2 );
				$detail     = $this->id( $dt );
				$detail_db  = $this->db_for_alias( $detail );
			}
			$detail_from = "`{$detail_db}`.`{$detail}` AS `{$detail}`";
			$dtl_esc     = esc_sql( $detail );
			// Best-effort slug derived from the name (WordPress de-dupes slugs itself).
			$slug_auto = "LOWER(TRIM(BOTH '-' FROM REPLACE(REPLACE(REPLACE(REPLACE({$src},' ','-'),'--','-'),'/','-'),'.','')))";
			$slug      = $slug_auto;
			// If a slug column is mapped, migrate it as-is; fall back to the derived
			// slug only when that source value is NULL/empty. A bare column name is
			// resolved against the same detail table the term name comes from.
			if ( ! empty( $f['term_slug'] ) ) {
				$slug_src = ( false !== strpos( $f['term_slug'], '.' ) )
					? $this->qualify_expr( $f['term_slug'], $detail )
					: "`{$detail}`.`" . $this->id( $f['term_slug'] ) . '`';
				$slug     = "IF({$slug_src} IS NULL OR {$slug_src} = '', {$slug_auto}, {$slug_src})";
			}

			// Three ways to reach the target term, most direct first:
			//   (a) current-terms: the join already lands on the current wp_terms
			//       (e.g. migration.wp_terms on legacy_id) — the term already exists,
			//       so just assign it by the joined term_id. No creation / stamping.
			//   (b) legacy: the detail is a source-DB term table with its own id —
			//       create/stamp terms by that legacy id.
			//   (c) name: a plain value with no term-table id — match/create by name.
			$is_current_terms = ( $detail_db === $this->id( DB_NAME ) && $this->id( $detail ) === $this->id( $wpdb->terms ) );
			$detail_key       = ( ! $is_current_terms && $detail !== $base ) ? $this->detail_key_for( $detail ) : '';
			$use_legacy       = ( '' !== $detail_key );
			$key_expr         = $use_legacy ? "`{$detail}`.`" . $detail_key . '`' : '';

			$lines[]   = '';
			$mode_desc = $is_current_terms ? 'assigned directly from the current wp_terms (by legacy id)'
				: ( $use_legacy ? "created & keyed by legacy id {$detail}.{$detail_key}" : 'matched / created by name' );
			$lines[]   = "-- ===== taxonomy: {$tax}  (from {$f['source']}, {$mode_desc}) =====";

			if ( $is_current_terms ) {
				// No creation — the joined wp_terms row IS the target term.
				$tt_term_ref  = "`{$detail}`.`term_id`";
				$term_join    = '';
				$assign_where = "`{$detail}`.`term_id` IS NOT NULL";
			} elseif ( $use_legacy ) {
				// 0) Adopt any existing same-name terms that were created before the
				//    legacy stamp existed, so they aren't duplicated.
				$lines[] = "UPDATE `{$wpdb->terms}` t";
				$lines[] = "JOIN `{$wpdb->term_taxonomy}` tt ON tt.term_id = t.term_id AND tt.taxonomy = '{$tax}'";
				$lines[] = "JOIN {$detail_from} ON t.name = {$src} COLLATE {$name_coll}";
				$lines[] = "SET t.legacy_id = {$key_expr}, t.legacy_table_name = '{$dtl_esc}'";
				$lines[] = "WHERE t.legacy_id IS NULL;";

				// 1) Create missing terms, stamped with the legacy link.
				$lines[] = "INSERT INTO `{$wpdb->terms}` (name, slug, term_group, legacy_id, legacy_table_name)";
				$lines[] = "SELECT DISTINCT {$src}, {$slug}, 0, {$key_expr}, '{$dtl_esc}'";
				$lines[] = "FROM {$detail_from}";
				$lines[] = "WHERE {$src} IS NOT NULL AND {$src} <> ''";
				$lines[] = "  AND NOT EXISTS (SELECT 1 FROM `{$wpdb->terms}` t JOIN `{$wpdb->term_taxonomy}` tt ON tt.term_id = t.term_id AND tt.taxonomy = '{$tax}' WHERE t.legacy_table_name = '{$dtl_esc}' AND t.legacy_id = {$key_expr});";

				// 2) term_taxonomy for those terms.
				$lines[] = "INSERT INTO `{$wpdb->term_taxonomy}` (term_id, taxonomy, description, parent, count)";
				$lines[] = "SELECT t.term_id, '{$tax}', '', 0, 0 FROM `{$wpdb->terms}` t";
				$lines[] = "WHERE t.legacy_table_name = '{$dtl_esc}'";
				$lines[] = "  AND NOT EXISTS (SELECT 1 FROM `{$wpdb->term_taxonomy}` tt WHERE tt.term_id = t.term_id AND tt.taxonomy = '{$tax}')";
				$lines[] = "  AND EXISTS (SELECT 1 FROM {$detail_from} WHERE {$key_expr} = t.legacy_id);";

				$tt_term_ref  = 't.term_id';
				$term_join    = "JOIN `{$wpdb->terms}` t ON t.legacy_table_name = '{$dtl_esc}' AND t.legacy_id = {$key_expr}";
				$assign_where = "{$key_expr} IS NOT NULL";
			} else {
				// 1) Create missing terms, matched by name.
				$lines[] = "INSERT INTO `{$wpdb->terms}` (name, slug, term_group)";
				$lines[] = "SELECT DISTINCT {$src}, {$slug}, 0";
				$lines[] = "FROM {$detail_from}";
				$lines[] = "WHERE {$src} IS NOT NULL AND {$src} <> ''";
				$lines[] = "  AND NOT EXISTS (SELECT 1 FROM `{$wpdb->terms}` t JOIN `{$wpdb->term_taxonomy}` tt ON tt.term_id = t.term_id AND tt.taxonomy = '{$tax}' WHERE t.name = {$src} COLLATE {$name_coll});";

				// 2) term_taxonomy for those terms.
				$lines[] = "INSERT INTO `{$wpdb->term_taxonomy}` (term_id, taxonomy, description, parent, count)";
				$lines[] = "SELECT t.term_id, '{$tax}', '', 0, 0 FROM `{$wpdb->terms}` t";
				$lines[] = "WHERE NOT EXISTS (SELECT 1 FROM `{$wpdb->term_taxonomy}` tt WHERE tt.term_id = t.term_id AND tt.taxonomy = '{$tax}')";
				$lines[] = "  AND EXISTS (SELECT 1 FROM {$detail_from} WHERE {$src} = t.name COLLATE {$name_coll});";

				$tt_term_ref  = 't.term_id';
				$term_join    = "JOIN `{$wpdb->terms}` t ON t.name = {$src} COLLATE {$name_coll}";
				$assign_where = "{$src} IS NOT NULL AND {$src} <> ''";
			}

			// 3) If not append: clear this profile's existing terms in this taxonomy.
			if ( empty( $f['term_append'] ) ) {
				$lines[] = "DELETE r FROM `{$wpdb->term_relationships}` r";
				$lines[] = "  JOIN `{$wpdb->posts}` p ON p.ID = r.object_id AND p.legacy_table_name = '{$ltn}' AND " . $this->pt_cond();
				$lines[] = "  JOIN `{$wpdb->term_taxonomy}` tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = '{$tax}';";
			}

			// 4) Assign terms to posts by walking the join chain.
			$lines[] = "INSERT INTO `{$wpdb->term_relationships}` (object_id, term_taxonomy_id, term_order)";
			$lines[] = "SELECT p.ID, tt.term_taxonomy_id, 0";
			$lines[] = "FROM {$from_all}";
			$lines[] = "JOIN `{$wpdb->posts}` p ON p.legacy_table_name = '{$ltn}' AND " . $this->pt_cond() . " AND p.legacy_id = {$key}";
			if ( '' !== $term_join ) {
				$lines[] = $term_join;
			}
			$lines[] = "JOIN `{$wpdb->term_taxonomy}` tt ON tt.term_id = {$tt_term_ref} AND tt.taxonomy = '{$tax}'";
			$lines[] = "WHERE {$assign_where}";
			$lines[] = "  AND NOT EXISTS (SELECT 1 FROM `{$wpdb->term_relationships}` r WHERE r.object_id = p.ID AND r.term_taxonomy_id = tt.term_taxonomy_id);";

			// 5) Recount.
			$lines[] = "UPDATE `{$wpdb->term_taxonomy}` tt SET tt.count = (SELECT COUNT(*) FROM `{$wpdb->term_relationships}` r WHERE r.term_taxonomy_id = tt.term_taxonomy_id) WHERE tt.taxonomy = '{$tax}';";
		}
		return $lines;
	}

	/**
	 * The key column of a joined detail table — i.e. the column of that table used
	 * in a join's ON condition (its id). Returns '' if it can't be determined.
	 */
	private function detail_key_for( $detail_table ) {
		foreach ( $this->profile['joins'] as $j ) {
			if ( empty( $j['table'] ) ) {
				continue;
			}
			foreach ( array( $j['left_col'], $j['right_col'] ) as $c ) {
				if ( $c && false !== strpos( $c, '.' ) ) {
					list( $t, $col ) = explode( '.', $c, 2 );
					if ( $this->id( $t ) === $detail_table ) {
						return $this->id( $col );
					}
				}
			}
		}
		return '';
	}

	/**
	 * Fast, set-based post_author resolution. For each post_author mapping that
	 * resolves from a migrated user table, build a small (legacy_id -> user_id)
	 * map from usermeta, then UPDATE posts by joining the source on legacy_id.
	 * Avoids a per-row correlated sub-query, which is unusably slow at scale.
	 *
	 * Handled only when the author column is on the base table (the norm). A
	 * joined-table author column is left to "Run import".
	 *
	 * @return array
	 */
	private function build_author_links( $base, $ltn ) {
		global $wpdb;
		$db    = $this->id( $this->source_db );
		$pk    = $this->id( $this->profile['source_id_column'] );
		$lines = array();
		$n     = 0;

		foreach ( $this->profile['fields'] as $f ) {
			if ( 'post_field' !== $f['target_kind'] || 'post_author' !== $f['target'] || empty( $f['rel_table'] ) || empty( $f['source'] ) ) {
				continue;
			}
			$author_tbl = ( false !== strpos( $f['source'], '.' ) ) ? $this->id( current( explode( '.', $f['source'], 2 ) ) ) : $base;
			if ( $author_tbl !== $base ) {
				$lines[] = '';
				$lines[] = "-- post_author from a joined table ({$f['source']}) is resolved via \"Run import\".";
				continue;
			}

			$n      = $n + 1;
			$rel    = esc_sql( $f['rel_table'] );
			$author = $this->qualify_expr( $f['source'], $base ); // `base`.`col`

			// wp_users.legacy_id / legacy_table_name are indexed, so a single
			// direct join resolves the author — no temp table needed.
			$lines[] = '';
			$lines[] = "-- ===== author: post_author <- {$f['source']}  (users migrated from {$f['rel_table']}) =====";
			$lines[] = "UPDATE `{$wpdb->posts}` p";
			$lines[] = "JOIN `{$db}`.`{$base}` AS `{$base}` ON `{$base}`.`{$pk}` = p.legacy_id";
			$lines[] = "JOIN `{$wpdb->users}` u ON u.legacy_table_name = '{$rel}' AND u.legacy_id = {$author}";
			$lines[] = "SET p.post_author = u.ID";
			$lines[] = "WHERE p.legacy_table_name = '{$ltn}' AND " . $this->pt_cond() . ';';
		}
		return $lines;
	}

	/**
	 * Condition restricting a wp_posts alias to this migration's post type, so two
	 * migrations from the SAME source table into DIFFERENT post types (e.g. video
	 * posts and their attachment images) don't collide on the legacy link.
	 */
	private function pt_cond( $alias = 'p' ) {
		return "{$alias}.post_type = '" . esc_sql( $this->profile['post_type'] ) . "'";
	}

	private function post_meta_block( $mf, $base, $key, $ltn, $from ) {
		global $wpdb;
		$meta_key = esc_sql( $this->meta_key_for( $mf ) );
		$expr     = $this->meta_value_expr( $mf, $base );
		$pt       = $this->pt_cond();

		$out   = array();
		$out[] = "-- meta: {$meta_key}";
		$out[] = "DELETE pm FROM `{$wpdb->postmeta}` pm";
		$out[] = "  JOIN `{$wpdb->posts}` p ON p.ID = pm.post_id";
		$out[] = "  WHERE pm.meta_key = '{$meta_key}' AND p.legacy_table_name = '{$ltn}' AND {$pt};";
		$out[] = "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value)";
		$out[] = "SELECT p.ID, '{$meta_key}', {$expr}";
		$out[] = "FROM {$from}";
		$out[] = "JOIN `{$wpdb->posts}` p ON p.legacy_table_name = '{$ltn}' AND {$pt} AND " . 'p.legacy_id = ' . $key;
		$out[] = "WHERE {$expr} IS NOT NULL;";

		// ACF needs the field-key reference meta (_fieldname => field_key) to show the value.
		if ( in_array( $mf['target_kind'], array( 'acf', 'acf_relation' ), true ) && ! empty( $mf['target'] ) ) {
			$fk_key = '_' . $meta_key;
			$fk_val = esc_sql( $mf['target'] );
			$out[]  = "DELETE pm FROM `{$wpdb->postmeta}` pm JOIN `{$wpdb->posts}` p ON p.ID = pm.post_id WHERE pm.meta_key = '{$fk_key}' AND p.legacy_table_name = '{$ltn}' AND {$pt};";
			$out[]  = "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) SELECT p.ID, '{$fk_key}', '{$fk_val}' FROM {$from} JOIN `{$wpdb->posts}` p ON p.legacy_table_name = '{$ltn}' AND {$pt} AND " . 'p.legacy_id = ' . $key . ';';
		}
		$out[] = '';
		return $out;
	}

	/**
	 * Meta block for an ACF relationship / post_object field. Unlike a scalar meta,
	 * a relationship can resolve to MANY posts (e.g. one video → many related news
	 * via a junction). This aggregates every resolved id per post into a single
	 * PHP-serialized array — the exact format ACF stores — so the field reads all of
	 * them. Idempotent (delete-then-insert). Uses the fanned-out FROM (all joins).
	 */
	private function relation_meta_block( $mf, $base, $key, $ltn, $from_all ) {
		global $wpdb;
		$meta_key = esc_sql( $this->meta_key_for( $mf ) );
		$match    = ! empty( $mf['rel_match'] ) ? $mf['rel_match'] : 'legacy';
		$id_expr  = $this->resolve_post_expr( $this->qualify_expr( $mf['source'], $base ), $mf, $match );
		$pt       = $this->pt_cond();
		$pjoin    = "JOIN `{$wpdb->posts}` p ON p.legacy_table_name = '{$ltn}' AND {$pt} AND p.legacy_id = {$key}";

		$out   = array();
		$out[] = "-- relation (multi-value ACF): {$meta_key}";
		$out[] = "DELETE pm FROM `{$wpdb->postmeta}` pm";
		$out[] = "  JOIN `{$wpdb->posts}` p ON p.ID = pm.post_id";
		$out[] = "  WHERE pm.meta_key = '{$meta_key}' AND p.legacy_table_name = '{$ltn}' AND {$pt};";
		// Aggregate all distinct resolved ids per post into one serialized array:
		//   a:N:{i:0;s:len:"id";i:1;...}
		$out[] = "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value)";
		$out[] = "SELECT post_id, '{$meta_key}', CONCAT('a:', cnt, ':{', GROUP_CONCAT(elem ORDER BY seq SEPARATOR ''), '}')";
		$out[] = 'FROM (';
		$out[] = "  SELECT post_id, cnt, seq, CONCAT('i:', seq - 1, ';s:', CHAR_LENGTH(CAST(rid AS CHAR)), ':\"', rid, '\";') AS elem";
		$out[] = '  FROM (';
		$out[] = '    SELECT post_id, rid,';
		$out[] = '      ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY rid) AS seq,';
		$out[] = '      COUNT(*)     OVER (PARTITION BY post_id) AS cnt';
		$out[] = '    FROM (';
		$out[] = "      SELECT DISTINCT p.ID AS post_id, ({$id_expr}) AS rid";
		$out[] = "      FROM {$from_all}";
		$out[] = "      {$pjoin}";
		$out[] = '    ) r WHERE r.rid IS NOT NULL AND r.rid <> 0';
		$out[] = '  ) w';
		$out[] = ') e GROUP BY post_id, cnt;';

		// ACF field-key reference meta (_fieldname => field_key).
		if ( ! empty( $mf['target'] ) ) {
			$fk_key = '_' . $meta_key;
			$fk_val = esc_sql( $mf['target'] );
			$out[]  = "DELETE pm FROM `{$wpdb->postmeta}` pm JOIN `{$wpdb->posts}` p ON p.ID = pm.post_id WHERE pm.meta_key = '{$fk_key}' AND p.legacy_table_name = '{$ltn}' AND {$pt};";
			$out[]  = "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) SELECT DISTINCT p.ID, '{$fk_key}', '{$fk_val}' FROM {$from_all} {$pjoin};";
		}
		$out[] = '';
		return $out;
	}

	private function post_field_expressions( $base ) {
		$map = array();
		foreach ( $this->profile['fields'] as $f ) {
			if ( 'post_field' !== $f['target_kind'] || empty( $f['source'] ) ) {
				continue;
			}
			// post_author resolved from a migrated user table is handled by a fast
			// set-based UPDATE in build_author_links() (a per-row sub-query is far
			// too slow at scale), so skip it here — new posts insert with author 0.
			if ( 'post_author' === $f['target'] && ! empty( $f['rel_table'] ) ) {
				continue;
			}
			$map[ $f['target'] ] = $this->field_expr( $f, $base );
		}
		return $map;
	}

	/* ===================================================================== *
	 *  USERS
	 * ===================================================================== */

	private function build_users() {
		global $wpdb;

		list( , $base ) = $this->parse_table( $this->profile['source_table'] );
		$id_col  = $this->id( $this->profile['source_id_column'] );
		$ltn     = esc_sql( $this->profile['source_table'] );
		$key     = "`{$base}`.`{$id_col}`";
		$from    = $this->source_from();
		$role    = $this->profile['role'] ? $this->profile['role'] : 'subscriber';

		$partial = ! empty( $this->profile['partial'] );
		$lines = $this->header( 'user', $wpdb->users );
		if ( $partial ) {
			$lines[] = '-- PARTIAL UPDATE: only already-migrated users are touched (none created).';
			$lines[] = '';
		}
		$lines[] = '-- WARNING: passwords written here are stored as-is and are NOT';
		$lines[] = '-- WordPress (phpash) hashes. If you need working logins, either map';
		$lines[] = '-- a column already containing WP hashes, or run "Run import" instead';
		$lines[] = '-- (it hashes properly), or have users reset their password.';
		$lines[] = '';

		// Split mapped user fields into wp_users columns vs usermeta.
		$cols_map = array();   // user_login, user_email, display_name, ...
		$meta_map = array();   // first_name, last_name, nickname, description, user_meta, acf
		foreach ( $this->profile['fields'] as $f ) {
			if ( empty( $f['source'] ) && 'acf_relation' !== $f['target_kind'] ) {
				continue;
			}
			if ( 'user_field' === $f['target_kind'] && in_array( $f['target'], $this->user_columns, true ) ) {
				$cols_map[ $f['target'] ] = $this->field_expr( $f, $base );
			} elseif ( 'user_field' === $f['target_kind'] ) {
				// first_name / last_name / nickname / description live in usermeta.
				$meta_map[] = array( 'meta_key' => $f['target'], 'expr' => $this->field_expr( $f, $base ), 'kind' => 'user_meta' );
			} elseif ( in_array( $f['target_kind'], array( 'user_meta' ), true ) ) {
				$meta_map[] = array( 'meta_key' => $f['target'], 'expr' => $this->field_expr( $f, $base ), 'kind' => 'user_meta' );
			} elseif ( in_array( $f['target_kind'], array( 'acf', 'acf_relation' ), true ) ) {
				$meta_map[] = $this->acf_meta_descriptor( $f, $base );
			}
		}

		// Unmapped fields are left at the wp_users column default (empty string) —
		// never auto-generate values (e.g. a fake e-mail). Every wp_users column is
		// NOT NULL DEFAULT '' except user_registered (a datetime), which gets NOW()
		// because '' is not a valid datetime.
		// COALESCE mapped columns to the default so a NULL source / unmatched LEFT
		// JOIN can't violate these NOT NULL wp_users columns.
		$login_expr = isset( $cols_map['user_login'] ) ? "COALESCE({$cols_map['user_login']}, '')" : "''";
		$email_expr = isset( $cols_map['user_email'] ) ? "COALESCE({$cols_map['user_email']}, '')" : "''";
		$disp_expr  = isset( $cols_map['display_name'] ) ? "COALESCE({$cols_map['display_name']}, '')" : "''";
		$nice_expr  = isset( $cols_map['user_nicename'] ) ? "COALESCE({$cols_map['user_nicename']}, '')" : "''";
		$pass_expr  = isset( $cols_map['user_pass'] ) ? "COALESCE({$cols_map['user_pass']}, '')" : "''";
		$url_expr   = isset( $cols_map['user_url'] ) ? "COALESCE({$cols_map['user_url']}, '')" : "''";
		$reg_expr   = isset( $cols_map['user_registered'] ) ? "COALESCE({$cols_map['user_registered']}, NOW())" : 'NOW()';

		// A user is "already migrated" when the legacy columns match.
		$exists_sql = "EXISTS (SELECT 1 FROM `{$wpdb->users}` u2 WHERE u2.legacy_table_name = '{$ltn}' AND u2.legacy_id = {$key})";
		// Join used to reach the WP user for a source row, via the indexed columns.
		$user_join  = "JOIN `{$wpdb->users}` u ON u.legacy_table_name = '{$ltn}' AND u.legacy_id = {$key}";

		/* ---- 1. INSERT missing users (skipped in partial mode) ---- */
		if ( ! $partial ) {
			$lines[] = '-- 1) Create users that have not been migrated yet.';
			$lines[] = "INSERT INTO `{$wpdb->users}`";
			$lines[] = '  (user_login, user_pass, user_nicename, user_email, user_url, user_registered, display_name, legacy_id, legacy_table_name)';
			$lines[] = "SELECT {$login_expr}, {$pass_expr}, {$nice_expr}, {$email_expr}, {$url_expr}, {$reg_expr}, {$disp_expr}, {$key}, '{$ltn}'";
			$lines[] = "FROM {$from}";
			$lines[] = "WHERE NOT {$exists_sql};";
			$lines[] = '';

			/* ---- 2. Role / capabilities meta for users that lack it ---- */
			$role_ser = esc_sql( $this->serialize_role( $role ) );
			$lines[]  = '-- 2) Role & capabilities for migrated users (matched by legacy columns).';
			foreach ( array(
				$wpdb->prefix . 'capabilities' => "'{$role_ser}'",
				$wpdb->prefix . 'user_level'   => "'0'",
			) as $mk => $mv ) {
				$mk_esc  = esc_sql( $mk );
				$lines[] = "INSERT INTO `{$wpdb->usermeta}` (user_id, meta_key, meta_value)";
				$lines[] = "SELECT u.ID, '{$mk_esc}', {$mv}";
				$lines[] = "FROM {$from} {$user_join}";
				$lines[] = "WHERE NOT EXISTS (SELECT 1 FROM `{$wpdb->usermeta}` m WHERE m.user_id = u.ID AND m.meta_key = '{$mk_esc}');";
				$lines[] = '';
			}
		}

		/* ---- 3. UPDATE existing users (matched by legacy columns) ---- */
		if ( ! empty( $cols_map ) ) {
			$set = array();
			foreach ( $cols_map as $col => $expr ) {
				if ( 'user_login' === $col ) {
					continue; // login is immutable
				}
				$set[] = "u.`{$col}` = COALESCE({$expr}, u.`{$col}`)";
			}
			if ( $set ) {
				$lines[] = '-- 3) Update users that were already migrated.';
				$lines[] = "UPDATE `{$wpdb->users}` u";
				$lines[] = "JOIN {$from} ON u.legacy_table_name = '{$ltn}' AND u.legacy_id = {$key}";
				$lines[] = 'SET ' . implode( ",\n    ", $set ) . ';';
				$lines[] = '';
			}
		}

		/* ---- 4. User meta + ACF (delete + insert => idempotent) ---- */
		if ( $meta_map ) {
			$lines[] = '-- 4) User meta / ACF values (delete-then-insert).';
			foreach ( $meta_map as $mf ) {
				$lines = array_merge( $lines, $this->user_meta_block( $mf, $base, $key, $ltn, $from ) );
			}
		}

		$lines[] = '-- Note: ACF repeaters and multi-value relationships still run via "Run import".';
		$lines = array_merge( $lines, $this->footer() );
		return implode( "\n", $lines );
	}

	private function user_meta_block( $mf, $base, $key, $ltn, $from ) {
		global $wpdb;
		$meta_key = esc_sql( $mf['meta_key'] );
		$expr     = $mf['expr'];

		// Reach the WP user for a source row via the indexed legacy columns.
		$user_join = "JOIN `{$wpdb->users}` u ON u.legacy_table_name = '{$ltn}' AND u.legacy_id = {$key}";

		$out   = array();
		$out[] = "-- user meta: {$meta_key}";
		$out[] = "DELETE um FROM `{$wpdb->usermeta}` um";
		$out[] = "  JOIN `{$wpdb->users}` u ON u.ID = um.user_id AND u.legacy_table_name = '{$ltn}'";
		$out[] = "  WHERE um.meta_key = '{$meta_key}';";
		$out[] = "INSERT INTO `{$wpdb->usermeta}` (user_id, meta_key, meta_value)";
		$out[] = "SELECT u.ID, '{$meta_key}', {$expr}";
		$out[] = "FROM {$from}";
		$out[] = $user_join . ';';

		if ( ! empty( $mf['field_key'] ) ) {
			$fk_key = '_' . $meta_key;
			$fk_val = esc_sql( $mf['field_key'] );
			$out[]  = "DELETE um FROM `{$wpdb->usermeta}` um JOIN `{$wpdb->users}` u ON u.ID = um.user_id AND u.legacy_table_name = '{$ltn}' WHERE um.meta_key = '{$fk_key}';";
			$out[]  = "INSERT INTO `{$wpdb->usermeta}` (user_id, meta_key, meta_value) SELECT u.ID, '{$fk_key}', '{$fk_val}' FROM {$from} {$user_join};";
		}
		$out[] = '';
		return $out;
	}

	/* ===================================================================== *
	 *  TAXONOMY TERMS
	 * ===================================================================== */

	private function build_terms() {
		global $wpdb;

		list( , $base ) = $this->parse_table( $this->profile['source_table'] );
		$id_col = $this->id( $this->profile['source_id_column'] );
		$tax    = esc_sql( $this->profile['taxonomy'] ?? '' );
		$ltn    = esc_sql( $this->profile['source_table'] );
		$key    = "`{$base}`.`{$id_col}`";
		$from   = $this->source_from();

		// Split mapped fields into wp_terms/wp_term_taxonomy columns vs term meta.
		$cols     = array(); // name, slug, description, parent
		$meta_map = array(); // term_meta + acf
		foreach ( $this->profile['fields'] as $f ) {
			if ( empty( $f['source'] ) && 'acf_relation' !== $f['target_kind'] ) {
				continue;
			}
			if ( 'term_field' === $f['target_kind'] ) {
				$cols[ $f['target'] ] = $this->field_expr( $f, $base );
			} elseif ( 'term_meta' === $f['target_kind'] ) {
				$meta_map[] = array( 'meta_key' => $f['target'], 'expr' => $this->field_expr( $f, $base ), 'field_key' => '' );
			} elseif ( in_array( $f['target_kind'], array( 'acf', 'acf_relation' ), true ) ) {
				$meta_map[] = $this->acf_meta_descriptor( $f, $base );
			}
		}

		// name (fallback to a legacy label) + slug (mapped, else derived from name).
		// Mapped values are NULL-guarded so a NULL source can't violate NOT NULL.
		$name_fallback = "CONCAT('{$ltn} #', {$key})";
		$name_expr     = isset( $cols['name'] ) ? "COALESCE({$cols['name']}, {$name_fallback})" : $name_fallback;
		$slug_auto     = "LOWER(TRIM(BOTH '-' FROM REPLACE(REPLACE(REPLACE(REPLACE({$name_expr},' ','-'),'--','-'),'/','-'),'.','')))";
		$slug_expr     = isset( $cols['slug'] )
			? "IF({$cols['slug']} IS NULL OR {$cols['slug']} = '', {$slug_auto}, {$cols['slug']})"
			: $slug_auto;
		$desc_expr   = isset( $cols['description'] ) ? "COALESCE({$cols['description']}, '')" : "''";
		$parent_expr = isset( $cols['parent'] ) ? "COALESCE({$cols['parent']}, 0)" : '0';

		$exists = "EXISTS (SELECT 1 FROM `{$wpdb->terms}` t2 WHERE t2.legacy_table_name = '{$ltn}' AND t2.legacy_id = {$key})";
		$join   = "JOIN `{$wpdb->terms}` t ON t.legacy_table_name = '{$ltn}' AND t.legacy_id = {$key}";

		$partial = ! empty( $this->profile['partial'] );
		$lines   = $this->header( 'term', $wpdb->terms . ' (' . $this->profile['taxonomy'] . ')' );
		if ( $partial ) {
			$lines[] = '-- PARTIAL UPDATE: only already-migrated terms are touched (none created).';
			$lines[] = '';
		}

		/* ---- 1 & 2. Create missing terms + attach (skipped in partial mode) ---- */
		if ( ! $partial ) {
			$lines[] = '-- 1) Create terms that have not been migrated yet.';
			$lines[] = "INSERT INTO `{$wpdb->terms}` (name, slug, term_group, legacy_id, legacy_table_name)";
			$lines[] = "SELECT {$name_expr}, {$slug_expr}, 0, {$key}, '{$ltn}'";
			$lines[] = "FROM {$from}";
			$lines[] = "WHERE NOT {$exists};";
			$lines[] = '';

			$lines[] = '-- 2) Attach each term to the taxonomy (with description / parent).';
			$lines[] = "INSERT INTO `{$wpdb->term_taxonomy}` (term_id, taxonomy, description, parent, count)";
			$lines[] = "SELECT t.term_id, '{$tax}', {$desc_expr}, {$parent_expr}, 0";
			$lines[] = "FROM {$from} {$join}";
			$lines[] = "WHERE NOT EXISTS (SELECT 1 FROM `{$wpdb->term_taxonomy}` tt WHERE tt.term_id = t.term_id AND tt.taxonomy = '{$tax}');";
			$lines[] = '';
		}

		/* ---- 3. Update terms that were already migrated (only mapped columns) ---- */
		$term_set = array();
		if ( isset( $cols['name'] ) ) {
			$term_set[] = "t.name = {$name_expr}";
		}
		if ( isset( $cols['slug'] ) ) {
			$term_set[] = "t.slug = {$slug_expr}";
		}
		if ( $term_set ) {
			$lines[] = '-- 3a) Update wp_terms for already-migrated terms.';
			$lines[] = "UPDATE `{$wpdb->terms}` t";
			$lines[] = "JOIN {$from} ON t.legacy_table_name = '{$ltn}' AND t.legacy_id = {$key}";
			$lines[] = 'SET ' . implode( ",\n    ", $term_set ) . ';';
			$lines[] = '';
		}
		$tt_set = array();
		if ( isset( $cols['description'] ) ) {
			$tt_set[] = "tt.description = {$desc_expr}";
		}
		if ( isset( $cols['parent'] ) ) {
			$tt_set[] = "tt.parent = {$parent_expr}";
		}
		if ( $tt_set ) {
			$lines[] = '-- 3b) Update wp_term_taxonomy for already-migrated terms.';
			$lines[] = "UPDATE `{$wpdb->term_taxonomy}` tt";
			$lines[] = "JOIN `{$wpdb->terms}` t ON t.term_id = tt.term_id AND tt.taxonomy = '{$tax}'";
			$lines[] = "JOIN {$from} ON t.legacy_table_name = '{$ltn}' AND t.legacy_id = {$key}";
			$lines[] = 'SET ' . implode( ",\n    ", $tt_set ) . ';';
			$lines[] = '';
		}

		/* ---- 4. Term meta + ACF (delete + insert => idempotent) ---- */
		if ( $meta_map ) {
			$lines[] = '-- 4) Term meta / ACF values (delete-then-insert).';
			foreach ( $meta_map as $mf ) {
				$lines = array_merge( $lines, $this->term_meta_block( $mf, $key, $ltn, $from ) );
			}
		}

		$lines[] = '-- Note: ACF repeaters and multi-value relationships still run via "Run import".';
		$lines = array_merge( $lines, $this->footer() );
		return implode( "\n", $lines );
	}

	private function term_meta_block( $mf, $key, $ltn, $from ) {
		global $wpdb;
		$meta_key  = esc_sql( $mf['meta_key'] );
		$expr      = $mf['expr'];
		$term_join = "JOIN `{$wpdb->terms}` t ON t.legacy_table_name = '{$ltn}' AND t.legacy_id = {$key}";

		$out   = array();
		$out[] = "-- term meta: {$meta_key}";
		$out[] = "DELETE tm FROM `{$wpdb->termmeta}` tm";
		$out[] = "  JOIN `{$wpdb->terms}` t ON t.term_id = tm.term_id AND t.legacy_table_name = '{$ltn}'";
		$out[] = "  WHERE tm.meta_key = '{$meta_key}';";
		$out[] = "INSERT INTO `{$wpdb->termmeta}` (term_id, meta_key, meta_value)";
		$out[] = "SELECT t.term_id, '{$meta_key}', {$expr}";
		$out[] = "FROM {$from}";
		$out[] = $term_join;
		$out[] = "WHERE {$expr} IS NOT NULL;";

		if ( ! empty( $mf['field_key'] ) ) {
			$fk_key = '_' . $meta_key;
			$fk_val = esc_sql( $mf['field_key'] );
			$out[]  = "DELETE tm FROM `{$wpdb->termmeta}` tm JOIN `{$wpdb->terms}` t ON t.term_id = tm.term_id AND t.legacy_table_name = '{$ltn}' WHERE tm.meta_key = '{$fk_key}';";
			$out[]  = "INSERT INTO `{$wpdb->termmeta}` (term_id, meta_key, meta_value) SELECT t.term_id, '{$fk_key}', '{$fk_val}' FROM {$from} {$term_join};";
		}
		$out[] = '';
		return $out;
	}

	/* ===================================================================== *
	 *  COMMENTS
	 * ===================================================================== */

	private function build_comments() {
		global $wpdb;

		$partial = ! empty( $this->profile['partial'] );
		list( , $base ) = $this->parse_table( $this->profile['source_table'] );
		$id_col = $this->id( $this->profile['source_id_column'] );
		$ltn    = esc_sql( $this->profile['source_table'] );
		$key    = "`{$base}`.`{$id_col}`";
		$from   = $this->source_from();

		$lines = $this->header( 'comment', $wpdb->comments );
		if ( $partial ) {
			$lines[] = '-- PARTIAL UPDATE: only already-migrated comments are touched (none created).';
			$lines[] = '';
		}

		// Mapped comment_field expressions + the meta fields.
		$fields   = array();
		$meta_map = array();
		foreach ( $this->profile['fields'] as $f ) {
			if ( empty( $f['source'] ) ) {
				continue;
			}
			if ( 'comment_field' === $f['target_kind'] ) {
				// comment_parent resolved to a migrated comment is self-referential,
				// so it can't be a sub-query inside the wp_comments write — it's
				// linked separately (build_comment_parent_links) after all inserts.
				if ( 'comment_parent' === $f['target'] && 'resolve_comment' === ( $f['transform'] ?? '' ) ) {
					continue;
				}
				$fields[ $f['target'] ] = $this->field_expr( $f, $base );
			} elseif ( 'comment_meta' === $f['target_kind'] ) {
				$meta_map[] = array( 'meta_key' => $f['target'], 'expr' => $this->field_expr( $f, $base ), 'field_key' => '' );
			} elseif ( 'acf' === $f['target_kind'] ) {
				$meta_map[] = $this->acf_meta_descriptor( $f, $base );
			}
		}

		/* ---- 1. UPDATE existing comments (mapped fields only) ---- */
		if ( ! $partial || ! empty( $fields ) ) {
			$set = array();
			foreach ( $fields as $col => $expr ) {
				$set[] = "c.`{$col}` = COALESCE({$expr}, c.`{$col}`)";
			}
			if ( $set ) {
				$lines[] = '-- 1) Update comments that were already migrated.';
				$lines[] = "UPDATE `{$wpdb->comments}` c";
				$lines[] = "JOIN {$from} ON c.legacy_table_name = '{$ltn}' AND c.legacy_id = {$key}";
				$lines[] = 'SET ' . implode( ",\n    ", $set ) . ';';
				$lines[] = '';
			}
		}

		/* ---- 2. INSERT missing comments (skipped in partial mode) ---- */
		if ( ! $partial ) {
			$cols = array(
				'comment_post_ID'      => $this->expr_or( $fields, 'comment_post_ID', '0' ),
				'comment_author'       => $this->expr_or( $fields, 'comment_author', "''" ),
				'comment_author_email' => $this->expr_or( $fields, 'comment_author_email', "''" ),
				'comment_author_url'   => $this->expr_or( $fields, 'comment_author_url', "''" ),
				'comment_author_IP'    => $this->expr_or( $fields, 'comment_author_IP', "''" ),
				'comment_date'         => $this->expr_or( $fields, 'comment_date', 'NOW()' ),
				'comment_date_gmt'     => $this->expr_or( $fields, 'comment_date', 'NOW()' ),
				'comment_content'      => $this->expr_or( $fields, 'comment_content', "''" ),
				'comment_karma'        => $this->expr_or( $fields, 'comment_karma', '0' ),
				'comment_approved'     => $this->expr_or( $fields, 'comment_approved', "'1'" ),
				'comment_agent'        => $this->expr_or( $fields, 'comment_agent', "''" ),
				'comment_type'         => $this->expr_or( $fields, 'comment_type', "'comment'" ),
				'comment_parent'       => $this->expr_or( $fields, 'comment_parent', '0' ),
				'user_id'              => $this->expr_or( $fields, 'user_id', '0' ),
				'legacy_id'            => $key,
				'legacy_table_name'    => "'{$ltn}'",
			);
			$lines[] = '-- 2) Insert comments that have not been migrated yet.';
			$lines[] = "INSERT INTO `{$wpdb->comments}`\n  (`" . implode( '`, `', array_keys( $cols ) ) . '`)';
			$lines[] = 'SELECT ' . implode( ",\n    ", array_values( $cols ) );
			$lines[] = "FROM {$from}";
			$lines[] = "WHERE NOT EXISTS (SELECT 1 FROM `{$wpdb->comments}` c2 WHERE c2.legacy_table_name = '{$ltn}' AND c2.legacy_id = {$key});";
			$lines[] = '';
		}

		/* ---- 3. Comment meta / ACF (delete + insert) ---- */
		if ( $meta_map ) {
			$lines[] = '-- 3) Comment meta / ACF values (delete-then-insert).';
			foreach ( $meta_map as $mf ) {
				$lines = array_merge( $lines, $this->comment_meta_block( $mf, $key, $ltn, $from ) );
			}
		}

		/* ---- 4. Resolve threaded parents (set-based self-join, after inserts) ---- */
		$lines = array_merge( $lines, $this->build_comment_parent_links( $base, $ltn, $from ) );

		/* ---- 5. Recount comment_count on the affected posts ---- */
		$lines[] = '-- 5) Recount comment_count on posts that received comments.';
		$lines[] = "UPDATE `{$wpdb->posts}` p SET p.comment_count = (SELECT COUNT(*) FROM `{$wpdb->comments}` c WHERE c.comment_post_ID = p.ID AND c.comment_approved = '1')";
		$lines[] = "WHERE p.ID IN (SELECT DISTINCT comment_post_ID FROM `{$wpdb->comments}` WHERE legacy_table_name = '{$ltn}' AND comment_post_ID > 0);";

		$lines[] = '-- Note: ACF repeaters and multi-value relationships still run via "Run import".';
		$lines = array_merge( $lines, $this->footer() );
		return implode( "\n", $lines );
	}

	/**
	 * Set-based resolution of comment_parent → the migrated parent comment, run
	 * after all comments exist. Uses a self-JOIN on wp_comments (allowed in a
	 * multi-table UPDATE, unlike a sub-query on the update target).
	 */
	private function build_comment_parent_links( $base, $ltn, $from ) {
		global $wpdb;
		$key   = "`{$base}`.`" . $this->id( $this->profile['source_id_column'] ) . '`';
		$lines = array();
		foreach ( $this->profile['fields'] as $f ) {
			if ( 'comment_field' !== $f['target_kind'] || 'comment_parent' !== $f['target'] ) {
				continue;
			}
			if ( 'resolve_comment' !== ( $f['transform'] ?? '' ) || empty( $f['rel_table'] ) || empty( $f['source'] ) ) {
				continue;
			}
			$rel = esc_sql( $f['rel_table'] );
			$src = $this->qualify_expr( $f['source'], $base ); // parent legacy id on the source row
			$lines[] = '';
			$lines[] = "-- ===== comment_parent <- {$f['source']}  (resolved to migrated comments) =====";
			$lines[] = "UPDATE `{$wpdb->comments}` c";
			$lines[] = "JOIN {$from} ON c.legacy_table_name = '{$ltn}' AND c.legacy_id = {$key}";
			$lines[] = "JOIN `{$wpdb->comments}` cp ON cp.legacy_table_name = '{$rel}' AND cp.legacy_id = {$src}";
			$lines[] = "SET c.comment_parent = cp.comment_ID";
			$lines[] = "WHERE {$src} IS NOT NULL AND {$src} <> 0;";
		}
		return $lines;
	}

	private function comment_meta_block( $mf, $key, $ltn, $from ) {
		global $wpdb;
		$meta_key   = esc_sql( $mf['meta_key'] );
		$expr       = $mf['expr'];
		$comment_jn = "JOIN `{$wpdb->comments}` c ON c.legacy_table_name = '{$ltn}' AND c.legacy_id = {$key}";

		$out   = array();
		$out[] = "-- comment meta: {$meta_key}";
		$out[] = "DELETE cm FROM `{$wpdb->commentmeta}` cm";
		$out[] = "  JOIN `{$wpdb->comments}` c ON c.comment_ID = cm.comment_id AND c.legacy_table_name = '{$ltn}'";
		$out[] = "  WHERE cm.meta_key = '{$meta_key}';";
		$out[] = "INSERT INTO `{$wpdb->commentmeta}` (comment_id, meta_key, meta_value)";
		$out[] = "SELECT c.comment_ID, '{$meta_key}', {$expr}";
		$out[] = "FROM {$from}";
		$out[] = $comment_jn;
		$out[] = "WHERE {$expr} IS NOT NULL;";

		if ( ! empty( $mf['field_key'] ) ) {
			$fk_key = '_' . $meta_key;
			$fk_val = esc_sql( $mf['field_key'] );
			$out[]  = "DELETE cm FROM `{$wpdb->commentmeta}` cm JOIN `{$wpdb->comments}` c ON c.comment_ID = cm.comment_id AND c.legacy_table_name = '{$ltn}' WHERE cm.meta_key = '{$fk_key}';";
			$out[]  = "INSERT INTO `{$wpdb->commentmeta}` (comment_id, meta_key, meta_value) SELECT c.comment_ID, '{$fk_key}', '{$fk_val}' FROM {$from} {$comment_jn};";
		}
		$out[] = '';
		return $out;
	}

	/* ===================================================================== *
	 *  Shared helpers
	 * ===================================================================== */

	private function header( $kind, $target_table ) {
		$lines   = array();
		$lines[] = '-- ============================================================';
		$lines[] = '-- DB Migrator generated SQL (idempotent: safe to re-run)';
		$lines[] = '-- Source : ' . $this->source_db . '.' . $this->profile['source_table'];
		$lines[] = '-- Target : ' . $kind . ' (' . $target_table . ')';
		$lines[] = '-- Same-server cross-database create-or-update.';
		$lines[] = '-- Run AFTER "Ensure schema".';
		$lines[] = '-- ============================================================';
		$lines[] = '';
		$lines[] = '-- Bulk-load speedups for this connection (restored at the end).';
		$lines[] = 'SET SESSION unique_checks = 0;';
		$lines[] = 'SET SESSION foreign_key_checks = 0;';
		$lines[] = '';
		return $lines;
	}

	private function footer() {
		return array(
			'',
			'-- Restore connection settings.',
			'SET SESSION unique_checks = 1;',
			'SET SESSION foreign_key_checks = 1;',
		);
	}

	/**
	 * Build "`db`.`base` AS `base` [JOINs]" used as the source FROM.
	 *
	 * @param bool $all_joins When true, include every valid join (used for taxonomy
	 *                        assignment where a one-to-many fan-out is desired).
	 *                        When false (default), include only joins a SQL field
	 *                        references (keeps the post SELECT one row per source id).
	 */
	private function source_from( $all_joins = false ) {
		list( $bdb, $base ) = $this->parse_table( $this->profile['source_table'] );
		$sql = "`{$bdb}`.`{$base}` AS `{$base}`";

		if ( $all_joins ) {
			$joins = array();
			foreach ( $this->profile['joins'] as $j ) {
				if ( ! empty( $j['table'] ) && ! empty( $j['left_col'] ) && ! empty( $j['right_col'] ) ) {
					$joins[] = $j;
				}
			}
		} else {
			$joins = $this->needed_joins();
		}

		foreach ( $joins as $j ) {
			list( $jdb, $jt ) = $this->parse_table( $j['table'] );
			$type  = ( 'INNER' === $j['type'] ) ? 'INNER' : 'LEFT';
			$left  = $this->qualify_expr( $j['left_col'], $base );
			$right = $this->qualify_expr( $j['right_col'], $base );
			$sql  .= "\n  {$type} JOIN `{$jdb}`.`{$jt}` AS `{$jt}` ON {$left} = {$right}" . $this->join_extra_on( $j, $base );
		}
		return $sql;
	}

	/**
	 * Parse a table reference into [database, alias]. A bare "table" belongs to
	 * the source (legacy) database; a qualified "db.table" (e.g. the current
	 * WordPress DB) uses that database, aliased to its bare name in the query.
	 */
	/**
	 * Extra AND/OR conditions appended to a join's ON clause (e.g. to constrain a
	 * current-DB join by post_type / legacy_table_name and avoid cross-type
	 * collisions). Each becomes " <AND|OR> <col> <op> ['value']".
	 */
	private function join_extra_on( $j, $base ) {
		if ( empty( $j['conditions'] ) || ! is_array( $j['conditions'] ) ) {
			return '';
		}
		$ops = array( '=', '!=', '<', '<=', '>', '>=', 'LIKE' );
		$out = '';
		foreach ( $j['conditions'] as $c ) {
			if ( empty( $c['col'] ) ) {
				continue;
			}
			$conj = ( 'OR' === ( $c['conj'] ?? 'AND' ) ) ? 'OR' : 'AND';
			$col  = $this->qualify_expr( $c['col'], $base );
			$op   = $c['op'] ?? '=';
			if ( 'IS NULL' === $op || 'IS NOT NULL' === $op ) {
				$out .= " {$conj} {$col} {$op}";
			} else {
				$op   = in_array( $op, $ops, true ) ? $op : '=';
				$out .= " {$conj} {$col} {$op} '" . esc_sql( $c['val'] ?? '' ) . "'";
			}
		}
		return $out;
	}

	/**
	 * The database an aliased table lives in, resolved from the base table and the
	 * join list (so a current-WP-DB join such as migration.wp_terms qualifies to
	 * `migration`, not the source DB). Falls back to the source DB.
	 */
	private function db_for_alias( $alias ) {
		list( $bdb, $balias ) = $this->parse_table( $this->profile['source_table'] );
		if ( $alias === $balias ) {
			return $bdb;
		}
		foreach ( $this->profile['joins'] as $j ) {
			if ( empty( $j['table'] ) ) {
				continue;
			}
			list( $jdb, $jalias ) = $this->parse_table( $j['table'] );
			if ( $jalias === $alias ) {
				return $jdb;
			}
		}
		return $this->id( $this->source_db );
	}

	private function parse_table( $value ) {
		if ( false !== strpos( (string) $value, '.' ) ) {
			list( $db, $t ) = explode( '.', $value, 2 );
			return array( $this->id( $db ), $this->id( $t ) );
		}
		return array( $this->id( $this->source_db ), $this->id( $value ) );
	}

	/**
	 * Joins that are actually needed by the SQL: those whose table (or a table in
	 * its ON condition, transitively) is referenced by a SQL-emitted field source.
	 * Joins used only for taxonomy/repeaters are excluded to avoid row fan-out.
	 *
	 * @return array[]  subset of $this->profile['joins'], in original order
	 */
	private function needed_joins() {
		list( , $base ) = $this->parse_table( $this->profile['source_table'] );
		$joins  = array();
		foreach ( $this->profile['joins'] as $j ) {
			if ( ! empty( $j['table'] ) && ! empty( $j['left_col'] ) && ! empty( $j['right_col'] ) ) {
				$joins[] = $j;
			}
		}
		if ( empty( $joins ) ) {
			return array();
		}

		// Tables referenced by SQL-emitted field sources (post fields + scalar meta).
		$needed = array();
		$add_table = function ( $source ) use ( &$needed, $base ) {
			if ( $source && false !== strpos( $source, '.' ) ) {
				list( $t ) = explode( '.', $source, 2 );
				$t = $this->id( $t );
				if ( $t && $t !== $base ) {
					$needed[ $t ] = true;
				}
			}
		};
		foreach ( $this->profile['fields'] as $f ) {
			// acf_relation is intentionally excluded: a relationship is often sourced
			// through a one-to-many junction, and relation_meta_block() runs on its
			// own fanned-out FROM. Including it here would fan out the post INSERT and
			// create duplicate posts.
			if ( in_array( $f['target_kind'], array( 'post_field', 'post_meta', 'acf' ), true ) ) {
				$add_table( $f['source'] ?? '' );
			}
		}

		// Transitive closure: including a join also requires the tables its ON
		// condition references (so a chained join can be reached).
		$changed = true;
		while ( $changed ) {
			$changed = false;
			foreach ( $joins as $j ) {
				list( , $jt ) = $this->parse_table( $j['table'] );
				if ( empty( $needed[ $jt ] ) ) {
					continue;
				}
				foreach ( array( $j['left_col'], $j['right_col'] ) as $c ) {
					if ( $c && false !== strpos( $c, '.' ) ) {
						list( $t ) = explode( '.', $c, 2 );
						$t = $this->id( $t );
						if ( $t && $t !== $base && empty( $needed[ $t ] ) ) {
							$needed[ $t ] = true;
							$changed      = true;
						}
					}
				}
			}
		}

		$out = array();
		foreach ( $joins as $j ) {
			list( , $jt ) = $this->parse_table( $j['table'] );
			if ( ! empty( $needed[ $jt ] ) ) {
				$out[] = $j;
			}
		}
		return $out;
	}

	/** Meta fields that produce a single scalar value (post path). */
	private function meta_fields() {
		$out = array();
		foreach ( $this->profile['fields'] as $f ) {
			if ( in_array( $f['target_kind'], array( 'post_meta', 'acf' ), true ) && ! empty( $f['source'] ) ) {
				$out[] = $f;
			} elseif ( 'acf_relation' === $f['target_kind'] && ! empty( $f['source'] ) ) {
				$match = ! empty( $f['rel_match'] ) ? $f['rel_match'] : 'legacy';
				$ok    = ( 'legacy' === $match ) ? ! empty( $f['rel_table'] )
					: ( ( 'meta' === $match ) ? ! empty( $f['rel_meta_key'] ) : true );
				if ( $ok ) {
					$out[] = $f; // single relationship resolved via sub-query
				}
			}
		}
		return $out;
	}

	private function meta_key_for( $f ) {
		if ( in_array( $f['target_kind'], array( 'acf', 'acf_relation' ), true ) && ! empty( $f['acf_name'] ) ) {
			return $f['acf_name'];
		}
		return $f['target'];
	}

	private function meta_value_expr( $f, $base ) {
		if ( 'acf_relation' === $f['target_kind'] ) {
			$match = ! empty( $f['rel_match'] ) ? $f['rel_match'] : 'legacy';
			return $this->resolve_post_expr( $this->qualify_expr( $f['source'], $base ), $f, $match );
		}
		return $this->field_expr( $f, $base );
	}

	/**
	 * Sub-query resolving one relationship value to a WP post ID, by the chosen
	 * match mode. Current-DB string comparisons are collation-normalised.
	 */
	private function resolve_post_expr( $value_expr, $f, $match ) {
		global $wpdb;
		$pt      = ! empty( $f['rel_post_type'] ) ? esc_sql( $f['rel_post_type'] ) : '';
		$pt_cond = $pt ? " AND rp.post_type = '{$pt}'" : '';

		switch ( $match ) {
			case 'direct':
				// The value is already a migrated WP post ID (e.g. wp_posts.ID from a
				// current-DB join) — use it as-is. relation_meta_block() aggregates +
				// serialises all ids per post.
				return "({$value_expr})";
			case 'title':
			case 'slug':
				$col  = ( 'slug' === $match ) ? 'post_name' : 'post_title';
				$coll = $this->wp_column_collation( $col, $wpdb->posts );
				return "(SELECT rp.ID FROM `{$wpdb->posts}` rp WHERE rp.{$col} = ({$value_expr}) COLLATE {$coll}{$pt_cond} AND rp.post_status <> 'trash' LIMIT 1)";
			case 'meta':
				$mk    = esc_sql( ! empty( $f['rel_meta_key'] ) ? $f['rel_meta_key'] : '' );
				$mcoll = $this->wp_column_collation( 'meta_value', $wpdb->postmeta );
				return "(SELECT m.post_id FROM `{$wpdb->postmeta}` m JOIN `{$wpdb->posts}` rp ON rp.ID = m.post_id WHERE m.meta_key = '{$mk}' AND m.meta_value = ({$value_expr}) COLLATE {$mcoll}{$pt_cond} LIMIT 1)";
			case 'legacy':
			default:
				return $this->resolve_post_subquery( $value_expr, $f['rel_table'] );
		}
	}

	private function acf_meta_descriptor( $f, $base ) {
		return array(
			'meta_key'  => $this->meta_key_for( $f ),
			'expr'      => $this->meta_value_expr( $f, $base ),
			'kind'      => $f['target_kind'],
			'field_key' => ! empty( $f['target'] ) ? $f['target'] : '',
		);
	}

	/** Correlated sub-query resolving a legacy id to a migrated WP post ID. */
	private function resolve_post_subquery( $value_expr, $rel_table ) {
		global $wpdb;
		$rt = esc_sql( $rel_table );
		return "(SELECT rp.ID FROM `{$wpdb->posts}` rp WHERE rp.legacy_table_name = '{$rt}' AND " . 'rp.legacy_id = ' . $value_expr . ' LIMIT 1)';
	}

	/** Correlated sub-query resolving a legacy id to a migrated WP user ID. */
	private function resolve_user_subquery( $value_expr, $rel_table ) {
		global $wpdb;
		$rt = esc_sql( $rel_table );
		return "(SELECT rm2.user_id FROM `{$wpdb->usermeta}` rm1
		    JOIN `{$wpdb->usermeta}` rm2 ON rm1.user_id = rm2.user_id
		    WHERE rm1.meta_key = '_dbmig_legacy_table' AND rm1.meta_value = '{$rt}'
		      AND rm2.meta_key = '_dbmig_legacy_id'    AND " . 'rm2.meta_value = ' . $value_expr . ' LIMIT 1)';
	}

	/**
	 * The mapped expression for a column, or a literal default when unmapped. When
	 * mapped, the value is NULL-guarded to the default so a NULL source (or an
	 * unmatched LEFT JOIN, e.g. post_author <- wp_users.ID) can never violate a
	 * NOT NULL column such as post_author / post_title.
	 */
	private function expr_or( $map, $key, $default ) {
		return array_key_exists( $key, $map ) ? "COALESCE({$map[ $key ]}, {$default})" : $default;
	}

	/**
	 * SQL expression that derives an attachment's post_mime_type from the file
	 * extension in its guid URL (e.g. ".../photo.JPG" => image/jpeg). The query
	 * string / fragment is stripped and the extension lower-cased before matching.
	 * Unknown / missing extensions yield '' (empty), so nothing bogus is stored.
	 * A legacy profile that still maps a post_mime_type column keeps priority.
	 *
	 * @param array  $fields    mapped post-field expressions
	 * @param string $guid_expr the SQL expression used for the guid column
	 * @return string SQL expression
	 */
	private function mime_type_expr( $fields, $guid_expr ) {
		$ext = "LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX({$guid_expr}, '?', 1), '#', 1), '.', -1))";

		$map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'jpe'  => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
			'bmp'  => 'image/bmp',
			'ico'  => 'image/x-icon',
			'svg'  => 'image/svg+xml',
			'tif'  => 'image/tiff',
			'tiff' => 'image/tiff',
			'heic' => 'image/heic',
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'  => 'application/vnd.ms-powerpoint',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'mp4'  => 'video/mp4',
			'm4v'  => 'video/mp4',
			'mov'  => 'video/quicktime',
			'avi'  => 'video/x-msvideo',
			'wmv'  => 'video/x-ms-wmv',
			'webm' => 'video/webm',
			'ogv'  => 'video/ogg',
			'mp3'  => 'audio/mpeg',
			'm4a'  => 'audio/mpeg',
			'wav'  => 'audio/wav',
			'ogg'  => 'audio/ogg',
			'zip'  => 'application/zip',
			'txt'  => 'text/plain',
			'csv'  => 'text/csv',
		);

		$when = '';
		foreach ( $map as $e => $mime ) {
			$when .= "\n        WHEN '{$e}' THEN '{$mime}'";
		}
		$case = "CASE {$ext}{$when}\n        ELSE '' END";

		// Honour an explicitly mapped column if a legacy profile still has one.
		return $this->expr_or( $fields, 'post_mime_type', $case );
	}

	/**
	 * SQL expression for a field's value: a quoted literal for a static value,
	 * otherwise the (transformed) source column.
	 */
	private function field_expr( $f, $base ) {
		global $wpdb;
		if ( '__static__' === ( $f['source'] ?? '' ) ) {
			$v = isset( $f['static_value'] ) ? $f['static_value'] : '';
			$t = $f['transform'] ?? 'none';
			if ( in_array( $t, array( 'int', 'float' ), true ) && is_numeric( $v ) ) {
				return $v; // numeric literal, unquoted
			}
			return "'" . esc_sql( $v ) . "'";
		}

		// Resolve a legacy id to the migrated WP post / user / term id (indexed).
		$transform = $f['transform'] ?? 'none';
		if ( in_array( $transform, array( 'resolve_post', 'resolve_user', 'resolve_term', 'resolve_comment' ), true ) ) {
			$col = $this->qualify_expr( $f['source'], $base );
			$rt  = esc_sql( $f['rel_table'] ?? '' );
			if ( 'resolve_user' === $transform ) {
				return "(SELECT ru.ID FROM `{$wpdb->users}` ru WHERE ru.legacy_table_name = '{$rt}' AND ru.legacy_id = {$col} LIMIT 1)";
			}
			if ( 'resolve_term' === $transform ) {
				return "(SELECT rtm.term_id FROM `{$wpdb->terms}` rtm WHERE rtm.legacy_table_name = '{$rt}' AND rtm.legacy_id = {$col} LIMIT 1)";
			}
			if ( 'resolve_comment' === $transform ) {
				return "(SELECT rc.comment_ID FROM `{$wpdb->comments}` rc WHERE rc.legacy_table_name = '{$rt}' AND rc.legacy_id = {$col} LIMIT 1)";
			}
			return "(SELECT rp.ID FROM `{$wpdb->posts}` rp WHERE rp.legacy_table_name = '{$rt}' AND rp.legacy_id = {$col} LIMIT 1)";
		}

		return $this->source_expr( $f['source'], $base, $transform );
	}

	private function source_expr( $source, $base, $transform ) {
		$col = $this->qualify_expr( $source, $base );
		switch ( $transform ) {
			case 'int':
				return "CAST({$col} AS SIGNED)";
			case 'float':
				return "CAST({$col} AS DECIMAL(20,6))";
			case 'bool':
				return "IF({$col} IS NULL OR {$col} = '' OR {$col} = '0', 0, 1)";
			case 'strip_tags':
				// Best-effort: importer does this precisely; SQL leaves as-is.
			default:
				return $col;
		}
	}

	private function qualify_expr( $source, $base ) {
		if ( false !== strpos( $source, '.' ) ) {
			list( $t, $c ) = explode( '.', $source, 2 );
			return '`' . $this->id( $t ) . '`.`' . $this->id( $c ) . '`';
		}
		return "`{$base}`.`" . $this->id( $source ) . '`';
	}

	private function serialize_role( $role ) {
		$role = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $role ) );
		return 'a:1:{s:' . strlen( $role ) . ':"' . $role . '";b:1;}';
	}

	private function id( $name ) {
		return preg_replace( '/[^A-Za-z0-9_]/', '', (string) $name );
	}

	/**
	 * Collation-safe, INDEX-PRESERVING equality for a WP string column compared
	 * to a legacy-derived string expression across databases. Only the legacy
	 * side is re-collated to the WP column's own collation, so the index on the
	 * WP column is still usable and MySQL does not raise "illegal mix of
	 * collations". Numeric columns (legacy_id, etc.) must NOT use this — compare
	 * them directly so the index is used.
	 */
	private function collate_match( $wp_column, $legacy_expr ) {
		global $wpdb;
		$coll = $this->wp_column_collation( $wp_column, $wpdb->users );
		return $wp_column . ' = (' . $legacy_expr . ') COLLATE ' . $coll;
	}

	private function wp_column_collation( $wp_column, $table = null ) {
		global $wpdb;
		$table = $table ? $table : $wpdb->users;
		$col   = $wp_column;
		if ( false !== strpos( $col, '.' ) ) {
			list( , $col ) = explode( '.', $col, 2 );
		}
		$coll = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				$table,
				$col
			)
		);
		$coll = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $coll );
		return $coll ? $coll : 'utf8mb4_general_ci';
	}
}
