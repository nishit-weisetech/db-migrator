<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes a migration profile against the external DB and writes WordPress
 * posts / meta / taxonomies / ACF values. Designed to run in batches from AJAX
 * and to be idempotent: rows are matched back to existing posts by the
 * (legacy_table_name, legacy_id) pair so re-running updates rather than dupes.
 */
class DBMig_Importer {

	/** @var array */
	private $profile;

	/** @var DBMig_External_DB */
	private $ext;

	/** @var array collected human-readable log lines */
	private $log = array();

	public function __construct( $profile, DBMig_External_DB $ext = null ) {
		$this->profile = $profile;
		$this->ext     = $ext ? $ext : new DBMig_External_DB();
	}

	public function get_log() {
		return $this->log;
	}

	private function log( $msg ) {
		$this->log[] = $msg;
	}

	/**
	 * Total number of source rows for the profile (the FROM + JOIN scope).
	 *
	 * @return int|WP_Error
	 */
	public function total() {
		$from = $this->build_from();
		return $this->ext->count_rows( '', $from );
	}

	/**
	 * Process one batch.
	 *
	 * @return array|WP_Error  array( processed, created, updated, skipped, log )
	 */
	public function run_batch( $offset, $limit ) {
		if ( ! DBMig_Schema::columns_ready() ) {
			return new WP_Error( 'dbmig_no_columns', __( 'Legacy columns are missing on wp_posts. Run "Ensure schema" first.', 'db-migrator' ) );
		}

		$sql  = $this->build_select( $offset, $limit );
		$rows = $this->ext->query( $sql );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$stats = array(
			'processed' => 0,
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
		);

		$type = $this->profile['migration_type'] ?? 'post';

		foreach ( $rows as $row ) {
			if ( 'user' === $type ) {
				$result = $this->import_user_row( $row );
			} elseif ( 'term' === $type ) {
				$result = $this->import_term_row( $row );
			} elseif ( 'comment' === $type ) {
				$result = $this->import_comment_row( $row );
			} else {
				$result = $this->import_row( $row );
			}
			$stats['processed']++;
			if ( 'created' === $result ) {
				$stats['created']++;
			} elseif ( 'updated' === $result ) {
				$stats['updated']++;
			} else {
				$stats['skipped']++;
			}
		}

		$stats['log'] = $this->log;
		$this->log    = array();
		return $stats;
	}

	/**
	 * Import a single source row.
	 *
	 * @return string created|updated|skipped
	 */
	private function import_row( $row ) {
		$id_col    = $this->profile['source_id_column'];
		$legacy_id = isset( $row[ $id_col ] ) ? $row[ $id_col ] : null;

		if ( null === $legacy_id || '' === $legacy_id ) {
			$this->log( 'Skipped row with empty source id.' );
			return 'skipped';
		}

		$legacy_table = $this->profile['source_table'];
		$existing_id  = DBMig_Schema::find_post_by_legacy( $legacy_table, $legacy_id, $this->profile['post_type'] );

		// Partial mode: only touch already-migrated rows (never create new ones).
		if ( ! empty( $this->profile['partial'] ) && ! $existing_id ) {
			return 'skipped';
		}

		// Build the core post array from post_field mappings.
		$postarr = array(
			'post_type'   => $this->profile['post_type'],
			'post_status' => $this->profile['post_status'] ? $this->profile['post_status'] : 'publish',
		);

		foreach ( $this->profile['fields'] as $f ) {
			if ( 'post_field' !== $f['target_kind'] ) {
				continue;
			}
			// post_author may reference a legacy user table; resolve it to a WP user.
			if ( 'post_author' === $f['target'] && ! empty( $f['rel_table'] ) ) {
				$raw = $this->resolve_source_value( $row, $f, true );
				$uid = DBMig_Schema::find_user_by_legacy( $f['rel_table'], $raw );
				if ( $uid ) {
					$postarr['post_author'] = $uid;
				} else {
					$this->log( sprintf( 'Author: no migrated user for %s#%s yet.', $f['rel_table'], $raw ) );
				}
				continue;
			}
			$value = $this->resolve_source_value( $row, $f );
			if ( null === $value ) {
				continue;
			}
			$postarr[ $f['target'] ] = $value;
		}

		if ( empty( $postarr['post_title'] ) && empty( $postarr['post_content'] ) ) {
			$postarr['post_title'] = sprintf( '%s #%s', $legacy_table, $legacy_id );
		}

		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( $postarr, true );
			$action        = 'updated';
		} else {
			$post_id = wp_insert_post( $postarr, true );
			$action  = 'created';
		}

		if ( is_wp_error( $post_id ) ) {
			$this->log( sprintf( 'Error on source id %s: %s', $legacy_id, $post_id->get_error_message() ) );
			return 'skipped';
		}

		// Stamp the legacy link so relations + re-runs work.
		$this->stamp_legacy( $post_id, $legacy_table, $legacy_id );

		// Meta, ACF, taxonomy, relations.
		foreach ( $this->profile['fields'] as $f ) {
			$this->apply_field( $post_id, $row, $f );
		}

		// ACF repeaters from child tables.
		foreach ( $this->profile['repeaters'] as $rep ) {
			$this->apply_repeater( $post_id, $row, $rep );
		}

		return $action;
	}

	/**
	 * Import a single source row as a WordPress user. Idempotent via the legacy
	 * key kept in usermeta.
	 *
	 * @return string created|updated|skipped
	 */
	private function import_user_row( $row ) {
		$id_col    = $this->profile['source_id_column'];
		$legacy_id = isset( $row[ $id_col ] ) ? $row[ $id_col ] : null;
		if ( null === $legacy_id || '' === $legacy_id ) {
			$this->log( 'Skipped user row with empty source id.' );
			return 'skipped';
		}
		$legacy_table = $this->profile['source_table'];
		$existing_id  = DBMig_Schema::find_user_by_legacy( $legacy_table, $legacy_id );

		if ( ! empty( $this->profile['partial'] ) && ! $existing_id ) {
			return 'skipped';
		}

		// Map the WP user-table / core fields.
		$userarr = array();
		$meta    = array();
		foreach ( $this->profile['fields'] as $f ) {
			if ( 'user_field' === $f['target_kind'] ) {
				$value = $this->resolve_source_value( $row, $f );
				if ( null !== $value ) {
					$userarr[ $f['target'] ] = $value;
				}
			}
		}

		// Never invent an e-mail: if it is not mapped it stays empty (wp_users and
		// wp_insert_user() both allow an empty e-mail). user_login IS required by
		// wp_insert_user(), so synthesize one only when it is unmapped, preferring
		// real identifying data (e-mail / display name) over a generic legacy name.
		if ( empty( $userarr['user_login'] ) ) {
			$base = '';
			if ( ! empty( $userarr['user_email'] ) ) {
				$base = sanitize_user( current( explode( '@', $userarr['user_email'] ) ), true );
			} elseif ( ! empty( $userarr['display_name'] ) ) {
				$base = sanitize_user( $userarr['display_name'], true );
			}
			$userarr['user_login'] = $base ? $base . '_' . $legacy_id : 'legacy_user_' . $legacy_id;
		}
		if ( empty( $userarr['role'] ) ) {
			$userarr['role'] = $this->profile['role'] ? $this->profile['role'] : 'subscriber';
		}

		if ( $existing_id ) {
			$userarr['ID'] = $existing_id;
			// Never overwrite login on update (immutable in WP).
			unset( $userarr['user_login'] );
			$user_id = wp_update_user( $userarr );
			$action  = 'updated';
		} else {
			// Make the login unique so a collision (e.g. a byline whose slug matches
			// an existing account) creates a distinct user instead of failing.
			if ( username_exists( $userarr['user_login'] ) ) {
				$try = $userarr['user_login'] . '_' . $legacy_id;
				$n   = 2;
				while ( username_exists( $try ) ) {
					$try = $userarr['user_login'] . '_' . $legacy_id . '_' . $n;
					$n++;
				}
				$userarr['user_login'] = $try;
			}
			if ( empty( $userarr['user_pass'] ) ) {
				$userarr['user_pass'] = wp_generate_password( 16, true, true );
			}
			$user_id = wp_insert_user( $userarr );
			$action  = 'created';
		}

		if ( is_wp_error( $user_id ) ) {
			$this->log( sprintf( 'User error on source id %s: %s', $legacy_id, $user_id->get_error_message() ) );
			return 'skipped';
		}

		DBMig_Schema::stamp_user_legacy( $user_id, $legacy_table, $legacy_id );

		// User meta + ACF user fields.
		foreach ( $this->profile['fields'] as $f ) {
			if ( 'user_meta' === $f['target_kind'] ) {
				$value = $this->resolve_source_value( $row, $f );
				if ( null !== $value ) {
					update_user_meta( $user_id, $f['target'], $value );
				}
			} elseif ( 'acf' === $f['target_kind'] || 'acf_relation' === $f['target_kind'] ) {
				if ( 'acf_relation' === $f['target_kind'] ) {
					$raw   = $this->resolve_source_value( $row, $f, true );
					$match = ! empty( $f['rel_match'] ) ? $f['rel_match'] : 'legacy';
					if ( null === $raw || '' === $raw || ( 'legacy' === $match && empty( $f['rel_table'] ) ) ) {
						continue;
					}
					$ids = array();
					foreach ( array_filter( array_map( 'trim', explode( ',', (string) $raw ) ), 'strlen' ) as $v ) {
						$wp_id = $this->resolve_related_post( $v, $f, $match );
						if ( $wp_id ) {
							$ids[] = $wp_id;
						}
					}
					if ( empty( $ids ) ) {
						continue;
					}
					$value = ( count( $ids ) === 1 ) ? $ids[0] : $ids;
				} else {
					$value = $this->resolve_source_value( $row, $f );
					if ( null === $value ) {
						continue;
					}
				}
				$selector = $f['target'] ? $f['target'] : $f['acf_name'];
				DBMig_ACF::update_value( $selector, $value, 'user_' . $user_id );
			}
		}

		// Media attachments (avatar / profile image, etc.).
		foreach ( $this->profile['fields'] as $f ) {
			if ( 'media' === $f['target_kind'] ) {
				$this->apply_media( $user_id, $row, $f, true );
			}
		}

		// ACF repeaters from child tables (e.g. a user's list of qualifications).
		foreach ( $this->profile['repeaters'] as $rep ) {
			$this->apply_repeater( 'user_' . $user_id, $row, $rep );
		}

		return $action;
	}

	/**
	 * Import a single source row as a taxonomy term. Idempotent via the legacy key
	 * stored on wp_terms. Writes term fields (name/slug/description/parent), term
	 * meta and ACF term fields.
	 *
	 * @return string created|updated|skipped
	 */
	private function import_term_row( $row ) {
		$id_col    = $this->profile['source_id_column'];
		$legacy_id = isset( $row[ $id_col ] ) ? $row[ $id_col ] : null;
		if ( null === $legacy_id || '' === $legacy_id ) {
			$this->log( 'Skipped term row with empty source id.' );
			return 'skipped';
		}
		$taxonomy = $this->profile['taxonomy'] ?? '';
		if ( ! taxonomy_exists( $taxonomy ) ) {
			$this->log( sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ) );
			return 'skipped';
		}
		$legacy_table = $this->profile['source_table'];
		$existing_id  = DBMig_Schema::find_term_by_legacy( $legacy_table, $legacy_id );

		if ( ! empty( $this->profile['partial'] ) && ! $existing_id ) {
			return 'skipped';
		}

		// Collect the mapped term fields (name / slug / description / parent).
		$tf = array();
		foreach ( $this->profile['fields'] as $f ) {
			if ( 'term_field' === $f['target_kind'] ) {
				$value = $this->resolve_source_value( $row, $f );
				if ( null !== $value ) {
					$tf[ $f['target'] ] = $value;
				}
			}
		}

		$args = array();
		foreach ( array( 'slug', 'description' ) as $k ) {
			if ( isset( $tf[ $k ] ) && '' !== $tf[ $k ] ) {
				$args[ $k ] = $tf[ $k ];
			}
		}
		if ( isset( $tf['parent'] ) && '' !== $tf['parent'] ) {
			$args['parent'] = (int) $tf['parent'];
		}
		$name = isset( $tf['name'] ) && '' !== trim( (string) $tf['name'] )
			? $tf['name']
			: sprintf( '%s #%s', $legacy_table, $legacy_id );

		if ( $existing_id ) {
			$args['name'] = $name;
			$res          = wp_update_term( (int) $existing_id, $taxonomy, $args );
			$action       = 'updated';
		} else {
			$res    = wp_insert_term( $name, $taxonomy, $args );
			$action = 'created';
		}

		if ( is_wp_error( $res ) ) {
			// A slug/name collision with an existing (un-stamped) term: adopt it.
			$dup = $res->get_error_data();
			if ( is_array( $dup ) && ! empty( $dup['term_id'] ) ) {
				$term_id = (int) $dup['term_id'];
				unset( $args['parent'] ); // keep it minimal on adoption
				wp_update_term( $term_id, $taxonomy, array_merge( $args, array( 'name' => $name ) ) );
			} else {
				$this->log( sprintf( 'Term error on source id %s: %s', $legacy_id, $res->get_error_message() ) );
				return 'skipped';
			}
		} else {
			$term_id = (int) $res['term_id'];
		}

		DBMig_Schema::stamp_term_legacy( $term_id, $legacy_table, $legacy_id );

		// Term meta + ACF term fields.
		foreach ( $this->profile['fields'] as $f ) {
			if ( 'term_meta' === $f['target_kind'] ) {
				$value = $this->resolve_source_value( $row, $f );
				if ( null !== $value ) {
					update_term_meta( $term_id, $f['target'], $value );
				}
			} elseif ( 'acf' === $f['target_kind'] ) {
				$value = $this->resolve_source_value( $row, $f );
				if ( null !== $value ) {
					$selector = $f['target'] ? $f['target'] : $f['acf_name'];
					DBMig_ACF::update_value( $selector, $value, 'term_' . $term_id );
				}
			} elseif ( 'acf_relation' === $f['target_kind'] ) {
				$raw   = $this->resolve_source_value( $row, $f, true );
				$match = ! empty( $f['rel_match'] ) ? $f['rel_match'] : 'legacy';
				if ( null === $raw || '' === $raw || ( 'legacy' === $match && empty( $f['rel_table'] ) ) ) {
					continue;
				}
				$ids = array();
				foreach ( array_filter( array_map( 'trim', explode( ',', (string) $raw ) ), 'strlen' ) as $v ) {
					$wp_id = $this->resolve_related_post( $v, $f, $match );
					if ( $wp_id ) {
						$ids[] = $wp_id;
					}
				}
				if ( ! empty( $ids ) ) {
					$selector = $f['target'] ? $f['target'] : $f['acf_name'];
					DBMig_ACF::update_value( $selector, ( count( $ids ) === 1 ) ? $ids[0] : $ids, 'term_' . $term_id );
				}
			}
		}

		// ACF repeaters from child tables (term meta).
		foreach ( $this->profile['repeaters'] as $rep ) {
			$this->apply_repeater( 'term_' . $term_id, $row, $rep );
		}

		return $action;
	}

	/**
	 * Import a single source row as a WordPress comment. Idempotent via the legacy
	 * key stored on wp_comments. Writes comment fields + comment meta.
	 *
	 * @return string created|updated|skipped
	 */
	private function import_comment_row( $row ) {
		$id_col    = $this->profile['source_id_column'];
		$legacy_id = isset( $row[ $id_col ] ) ? $row[ $id_col ] : null;
		if ( null === $legacy_id || '' === $legacy_id ) {
			$this->log( 'Skipped comment row with empty source id.' );
			return 'skipped';
		}
		$legacy_table = $this->profile['source_table'];
		$existing_id  = DBMig_Schema::find_comment_by_legacy( $legacy_table, $legacy_id );

		if ( ! empty( $this->profile['partial'] ) && ! $existing_id ) {
			return 'skipped';
		}

		// Collect mapped comment fields (comment_post_ID / author / content / ...).
		$commentarr = array();
		foreach ( $this->profile['fields'] as $f ) {
			if ( 'comment_field' === $f['target_kind'] ) {
				$value = $this->resolve_source_value( $row, $f );
				if ( null !== $value ) {
					$commentarr[ $f['target'] ] = $value;
				}
			}
		}

		if ( $existing_id ) {
			$commentarr['comment_ID'] = $existing_id;
			$ok                       = wp_update_comment( wp_slash( $commentarr ) );
			$comment_id               = ( false === $ok ) ? 0 : $existing_id;
			$action                   = 'updated';
		} else {
			// wp_insert_comment does not sanitize; pass through as-is.
			$comment_id = wp_insert_comment( wp_slash( $commentarr ) );
			$action     = 'created';
		}

		if ( ! $comment_id ) {
			$this->log( sprintf( 'Comment error on source id %s.', $legacy_id ) );
			return 'skipped';
		}

		DBMig_Schema::stamp_comment_legacy( $comment_id, $legacy_table, $legacy_id );

		// Comment meta + ACF comment fields.
		foreach ( $this->profile['fields'] as $f ) {
			if ( 'comment_meta' === $f['target_kind'] ) {
				$value = $this->resolve_source_value( $row, $f );
				if ( null !== $value ) {
					update_comment_meta( $comment_id, $f['target'], $value );
				}
			} elseif ( 'acf' === $f['target_kind'] ) {
				$value = $this->resolve_source_value( $row, $f );
				if ( null !== $value ) {
					$selector = $f['target'] ? $f['target'] : $f['acf_name'];
					DBMig_ACF::update_value( $selector, $value, 'comment_' . $comment_id );
				}
			}
		}

		return $action;
	}

	private function stamp_legacy( $post_id, $legacy_table, $legacy_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'legacy_table_name' => $legacy_table,
				'legacy_id'         => $legacy_id,
			),
			array( 'ID' => $post_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
		// Also keep a meta copy for convenience / WP_Query meta lookups.
		update_post_meta( $post_id, '_dbmig_legacy_id', $legacy_id );
		update_post_meta( $post_id, '_dbmig_legacy_table', $legacy_table );
	}

	/**
	 * Apply a single non-post_field mapping (meta / acf / taxonomy / relation).
	 */
	private function apply_field( $post_id, $row, $f ) {
		switch ( $f['target_kind'] ) {
			case 'post_meta':
				$value = $this->resolve_source_value( $row, $f );
				if ( null !== $value ) {
					update_post_meta( $post_id, $f['target'], $value );
				}
				break;

			case 'acf':
				$value = $this->resolve_source_value( $row, $f );
				if ( null !== $value ) {
					$selector = $f['target'] ? $f['target'] : $f['acf_name'];
					DBMig_ACF::update_value( $selector, $value, $post_id );
				}
				break;

			case 'acf_relation':
				$this->apply_relation( $post_id, $row, $f );
				break;

			case 'taxonomy':
				$this->apply_taxonomy( $post_id, $row, $f );
				break;

			case 'media':
				$this->apply_media( $post_id, $row, $f, false );
				break;

			case 'post_field':
			default:
				// handled in import_row()
				break;
		}
	}

	/**
	 * Media: source column holds an image filename (already uploaded to
	 * wp-content/uploads). Create/reuse an attachment and attach it as a featured
	 * image, post/user meta, or ACF image field. Supports comma-separated lists.
	 *
	 * @param int  $numeric_id post ID or user ID
	 * @param bool $is_user
	 */
	private function apply_media( $numeric_id, $row, $f, $is_user ) {
		$raw = $this->resolve_source_value( $row, $f, true );
		if ( null === $raw || '' === $raw ) {
			return;
		}

		$names  = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ), 'strlen' );
		$parent = $is_user ? 0 : $numeric_id;
		$ids    = array();
		foreach ( $names as $n ) {
			$att = DBMig_Media::ensure_attachment( $n, $parent );
			if ( $att ) {
				$ids[] = $att;
			} else {
				$this->log( sprintf( 'Media file not found in uploads: %s', $n ) );
			}
		}
		if ( empty( $ids ) ) {
			return;
		}

		$attach_as = $f['attach_as'] ? $f['attach_as'] : 'attachment';
		$selector  = $f['target'] ? $f['target'] : $f['acf_name'];
		$value     = ( count( $ids ) === 1 ) ? $ids[0] : $ids;

		switch ( $attach_as ) {
			case 'featured':
				if ( ! $is_user ) {
					set_post_thumbnail( $numeric_id, $ids[0] );
				}
				break;
			case 'meta':
				if ( $is_user ) {
					update_user_meta( $numeric_id, $selector, $value );
				} else {
					update_post_meta( $numeric_id, $selector, $value );
				}
				break;
			case 'acf':
				DBMig_ACF::update_value( $selector, $value, $is_user ? 'user_' . $numeric_id : $numeric_id );
				break;
			case 'attachment':
			default:
				// Attachment created and (for posts) attached via post_parent; nothing else.
				break;
		}
	}

	/**
	 * ACF relationship / post-object: source column holds a legacy id pointing at
	 * another already-migrated table. Resolve to the WP post ID.
	 */
	private function apply_relation( $post_id, $row, $f ) {
		$raw = $this->resolve_source_value( $row, $f, true );
		if ( null === $raw || '' === $raw ) {
			return;
		}
		$match = ! empty( $f['rel_match'] ) ? $f['rel_match'] : 'legacy';
		if ( 'legacy' === $match && empty( $f['rel_table'] ) ) {
			$this->log( 'Relation field has no referenced legacy table configured.' );
			return;
		}

		// Support comma-separated lists (multi relationship).
		$values   = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ), 'strlen' );
		$resolved = array();
		foreach ( $values as $v ) {
			$wp_id = $this->resolve_related_post( $v, $f, $match );
			if ( $wp_id ) {
				$resolved[] = $wp_id;
			} else {
				$this->log( sprintf( 'Relation: no post matched "%s" (match by %s).', $v, $match ) );
			}
		}

		if ( empty( $resolved ) ) {
			return;
		}

		$selector = $f['target'] ? $f['target'] : $f['acf_name'];
		// Single id stays scalar, multiple become an array (ACF relationship handles both).
		$value = ( count( $resolved ) === 1 ) ? $resolved[0] : $resolved;
		DBMig_ACF::update_value( $selector, $value, $post_id );
	}

	/**
	 * Resolve one relationship value to a WP post ID, using the chosen match mode:
	 * legacy (via the legacy link), or a current-DB field: title / slug / meta.
	 */
	private function resolve_related_post( $value, $f, $match ) {
		global $wpdb;
		$pt = ! empty( $f['rel_post_type'] ) ? $f['rel_post_type'] : '';

		switch ( $match ) {
			case 'direct':
				// The value is already a migrated WP post ID (e.g. wp_posts.ID from a
				// current-DB join) — use it as-is.
				return (int) $value;
			case 'title':
				return $this->find_post_by_field( 'post_title', $value, $pt );
			case 'slug':
				return $this->find_post_by_field( 'post_name', $value, $pt );
			case 'meta':
				$key = ! empty( $f['rel_meta_key'] ) ? $f['rel_meta_key'] : '';
				if ( ! $key ) {
					return 0;
				}
				$sql  = "SELECT p.ID FROM `{$wpdb->posts}` p JOIN `{$wpdb->postmeta}` m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_status <> 'trash'";
				$args = array( $key, $value );
				if ( $pt ) {
					$sql   .= ' AND p.post_type = %s';
					$args[] = $pt;
				}
				return (int) $wpdb->get_var( $wpdb->prepare( $sql . ' LIMIT 1', $args ) );
			case 'legacy':
			default:
				return DBMig_Schema::find_post_by_legacy( $f['rel_table'], $value );
		}
	}

	private function find_post_by_field( $field, $value, $post_type ) {
		global $wpdb;
		$col  = ( 'post_name' === $field ) ? 'post_name' : 'post_title';
		$sql  = "SELECT ID FROM `{$wpdb->posts}` WHERE {$col} = %s AND post_status <> 'trash'";
		$args = array( $value );
		if ( $post_type ) {
			$sql   .= ' AND post_type = %s';
			$args[] = $post_type;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql . ' LIMIT 1', $args ) );
	}

	/**
	 * Taxonomy assignment. The source value can be a term name / slug / id, or an
	 * already-migrated term resolved via legacy meta on the term.
	 */
	private function apply_taxonomy( $post_id, $row, $f ) {
		$raw = $this->resolve_source_value( $row, $f, true );
		if ( null === $raw || '' === $raw ) {
			return;
		}
		$taxonomy = $f['target'];
		if ( ! taxonomy_exists( $taxonomy ) ) {
			$this->log( sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ) );
			return;
		}

		$term_ids = array();
		$detail   = $this->taxonomy_detail( $f );
		$slug     = $this->taxonomy_slug_value( $row, $f );

		if ( $detail ) {
			// Legacy-id mode: the join yields one term per row, so match/create/stamp
			// the term by the source term's id (robust against renames / dupes).
			$key_col   = $this->strip_qualifier( $detail[1] );
			$legacy_id = array_key_exists( $key_col, $row ) ? $row[ $key_col ] : null;
			if ( null !== $legacy_id && '' !== $legacy_id ) {
				$tid = $this->find_or_create_term_legacy( (string) $raw, $taxonomy, $f, $detail[0], $legacy_id, $slug );
				if ( $tid ) {
					$term_ids[] = (int) $tid;
				}
			}
		} else {
			// Name mode: a single column may hold a comma-separated list. A mapped
			// slug only applies when there is exactly one term (one slug per term).
			$values   = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ), 'strlen' );
			$one_slug = ( 1 === count( $values ) ) ? $slug : '';
			foreach ( $values as $v ) {
				$term = $this->find_or_create_term( $v, $taxonomy, $f, $one_slug );
				if ( $term ) {
					$term_ids[] = (int) $term;
				}
			}
		}

		if ( ! empty( $term_ids ) ) {
			// Append mode keeps existing terms so a many-to-many source (e.g. a
			// news↔category junction, which yields one joined row per category)
			// accumulates every term instead of the last one overwriting the rest.
			// Term ids de-duplicate, so re-runs stay safe.
			$append = ! empty( $f['term_append'] );
			wp_set_object_terms( $post_id, $term_ids, $taxonomy, $append );

			// In append mode WordPress may have auto-assigned the default category
			// ("Uncategorized") when the post was created. Once real categories are
			// attached, drop that default so it does not linger.
			if ( $append && 'category' === $taxonomy ) {
				$default = (int) get_option( 'default_category' );
				if ( $default && ! in_array( $default, $term_ids, true ) ) {
					wp_remove_object_terms( $post_id, $default, 'category' );
				}
			}
		}
	}

	/**
	 * Find (or create + stamp) a term by its legacy id. Falls back to matching an
	 * existing same-name term (and stamping it) before creating a new one.
	 */
	private function find_or_create_term_legacy( $name, $taxonomy, $f, $detail_table, $legacy_id, $slug = '' ) {
		$tid = DBMig_Schema::find_term_by_legacy( $detail_table, $legacy_id );
		if ( $tid && term_exists( (int) $tid, $taxonomy ) ) {
			return $tid;
		}

		// Not linked yet: reuse a same-name term if one exists, else create.
		$name    = trim( (string) $name );
		$existing = $name ? term_exists( $name, $taxonomy ) : 0;
		if ( ! $existing && ! empty( $f['term_create'] ) && '' !== $name ) {
			$created = wp_insert_term( $name, $taxonomy, $this->term_args( $slug ) );
			if ( ! is_wp_error( $created ) ) {
				$existing = $created;
			}
		}
		if ( $existing ) {
			$new_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
			DBMig_Schema::stamp_term_legacy( $new_id, $detail_table, $legacy_id );
			return $new_id;
		}
		$this->log( sprintf( 'Term "%s" (%s#%s) not found/created in %s.', $name, $detail_table, $legacy_id, $taxonomy ) );
		return 0;
	}

	private function find_or_create_term( $value, $taxonomy, $f, $slug = '' ) {
		$match = $f['term_match'] ? $f['term_match'] : 'name';

		if ( 'id' === $match ) {
			$term = get_term( (int) $value, $taxonomy );
			return ( $term && ! is_wp_error( $term ) ) ? $term->term_id : 0;
		}

		$field = ( 'slug' === $match ) ? 'slug' : 'name';
		$term  = get_term_by( $field, $value, $taxonomy );
		if ( $term ) {
			return $term->term_id;
		}

		if ( ! empty( $f['term_create'] ) ) {
			$created = wp_insert_term( $value, $taxonomy, $this->term_args( $slug ) );
			if ( ! is_wp_error( $created ) ) {
				return $created['term_id'];
			}
		}
		$this->log( sprintf( 'Term "%s" not found in %s.', $value, $taxonomy ) );
		return 0;
	}

	/**
	 * The value of a taxonomy field's mapped slug column for this row ('' when no
	 * slug column is mapped or the source value is empty — the caller then lets
	 * WordPress derive the slug from the name).
	 */
	private function taxonomy_slug_value( $row, $f ) {
		if ( empty( $f['term_slug'] ) ) {
			return '';
		}
		$col = $this->strip_qualifier( $f['term_slug'] );
		if ( array_key_exists( $col, $row ) && null !== $row[ $col ] ) {
			return trim( (string) $row[ $col ] );
		}
		return '';
	}

	/** wp_insert_term() args: pass a slug only when one was migrated from source. */
	private function term_args( $slug ) {
		return ( '' !== (string) $slug ) ? array( 'slug' => $slug ) : array();
	}

	/**
	 * Build ACF repeater rows from a child table and write them in one go.
	 *
	 * @param int|string $object_id Post ID, or "user_{ID}" for a user repeater.
	 */
	private function apply_repeater( $object_id, $row, $rep ) {
		$child_table = $this->ext->safe_identifier( $rep['child_table'] );
		$child_fk    = $this->ext->safe_identifier( $rep['child_fk'] );
		if ( ! $child_table || ! $child_fk ) {
			$this->log( 'Repeater has an invalid child table / fk.' );
			return;
		}
		$base_tbl = $this->ext->safe_identifier( $this->profile['source_table'] );
		$vias     = ( ! empty( $rep['joins'] ) && is_array( $rep['joins'] ) ) ? $rep['joins'] : array();

		$order = '';
		if ( ! empty( $rep['order_by'] ) ) {
			$ob = $this->ext->safe_identifier( $this->strip_qualifier( $rep['order_by'] ) );
			if ( $ob ) {
				$order = " ORDER BY `{$child_table}`.`{$ob}` ASC";
			}
		}

		// Resolve "table.col" / bare into [table, col] (bare belongs to the base).
		$qual = function ( $ref ) use ( $base_tbl ) {
			if ( false !== strpos( $ref, '.' ) ) {
				list( $t, $c ) = explode( '.', $ref, 2 );
				return array( $this->ext->safe_identifier( $t ), $this->ext->safe_identifier( $c ) );
			}
			return array( $base_tbl, $this->ext->safe_identifier( $ref ) );
		};

		if ( empty( $vias ) ) {
			// Simple direct case: child.child_fk = <parent source value>.
			$parent_col = $rep['parent_col'] ? $this->strip_qualifier( $rep['parent_col'] ) : $this->profile['source_id_column'];
			$parent_val = array_key_exists( $parent_col, $row ) ? $row[ $parent_col ] : null;
			if ( null === $parent_val ) {
				return;
			}
			$sql = "SELECT * FROM `{$child_table}` WHERE `{$child_fk}` = '" . esc_sql( $parent_val ) . "'" . $order;
		} else {
			// Multi-hop: cross-join the intermediate tables; base-table references
			// are pinned to this parent row's values (implicit inner join via WHERE).
			$tables = array();
			$where  = array();
			$pcol   = $rep['parent_col'] ? $rep['parent_col'] : $this->profile['source_id_column'];
			if ( false !== strpos( $pcol, '.' ) ) {
				list( $pt, $pc ) = $qual( $pcol );
				$tables[ $pt ]   = true;
				$where[]         = "`{$child_table}`.`{$child_fk}` = `{$pt}`.`{$pc}`";
			} else {
				$bv = array_key_exists( $this->strip_qualifier( $pcol ), $row ) ? $row[ $this->strip_qualifier( $pcol ) ] : null;
				if ( null === $bv ) {
					return;
				}
				$where[] = "`{$child_table}`.`{$child_fk}` = '" . esc_sql( $bv ) . "'";
			}
			foreach ( $vias as $vj ) {
				list( $lt, $lc ) = $qual( $vj['left_col'] );
				list( $rt, $rc ) = $qual( $vj['right_col'] );
				$is_l = ( $lt === $base_tbl );
				$is_r = ( $rt === $base_tbl );
				if ( $is_l || $is_r ) {
					$base_col = $is_l ? $lc : $rc;
					$ot       = $is_l ? $rt : $lt;
					$oc       = $is_l ? $rc : $lc;
					$bv       = array_key_exists( $base_col, $row ) ? $row[ $base_col ] : null;
					if ( null === $bv ) {
						return;
					}
					if ( $ot !== $base_tbl ) {
						$tables[ $ot ] = true;
					}
					$where[] = "`{$ot}`.`{$oc}` = '" . esc_sql( $bv ) . "'";
					// "latest by": restrict this via table to its newest row per parent.
					if ( ! empty( $vj['latest_by'] ) ) {
						$lb  = $this->ext->safe_identifier( $vj['latest_by'] );
						$kec = 'id';
						if ( ! empty( $rep['parent_col'] ) && false !== strpos( $rep['parent_col'], '.' ) ) {
							list( $ppt, $ppc ) = explode( '.', $rep['parent_col'], 2 );
							if ( $this->ext->safe_identifier( $ppt ) === $ot ) {
								$kec = $this->ext->safe_identifier( $ppc );
							}
						}
						if ( $lb && $kec ) {
							$where[] = "`{$ot}`.`{$kec}` = (SELECT `s`.`{$kec}` FROM `{$ot}` `s` WHERE `s`.`{$oc}` = '" . esc_sql( $bv ) . "' ORDER BY `s`.`{$lb}` DESC, `s`.`{$kec}` DESC LIMIT 1)";
						}
					}
				} else {
					if ( $lt !== $base_tbl ) {
						$tables[ $lt ] = true;
					}
					if ( $rt !== $base_tbl ) {
						$tables[ $rt ] = true;
					}
					$where[] = "`{$lt}`.`{$lc}` = `{$rt}`.`{$rc}`";
				}
			}
			$from = "`{$child_table}`";
			foreach ( array_keys( $tables ) as $t ) {
				if ( $t && $t !== $child_table ) {
					$from .= ", `{$t}`";
				}
			}
			$sql = "SELECT `{$child_table}`.* FROM {$from} WHERE " . implode( ' AND ', $where ) . $order;
		}

		$child_rows = $this->ext->query( $sql );
		if ( is_wp_error( $child_rows ) || empty( $child_rows ) ) {
			return;
		}

		$repeater_value = array();
		foreach ( $child_rows as $crow ) {
			$rowval = array();
			foreach ( $rep['sub_map'] as $sub ) {
				$src = $this->strip_qualifier( $sub['source'] );
				$val = isset( $crow[ $src ] ) ? $crow[ $src ] : null;
				$val = $this->transform_value( $val, $sub['transform'] ?? 'none' );

				// Sub field can itself be a relation to a migrated table.
				if ( ! empty( $sub['rel_table'] ) && null !== $val && '' !== $val ) {
					$val = DBMig_Schema::find_post_by_legacy( $sub['rel_table'], $val );
				}

				// ACF repeater rows must be keyed by the sub-field NAME (not its key)
				// so update_field() writes the correct skills_0_<name> meta.
				$selector            = $sub['sub_name'] ? $sub['sub_name'] : $sub['sub_field'];
				$rowval[ $selector ] = $val;
			}
			$repeater_value[] = $rowval;
		}

		$selector = $rep['acf_field'] ? $rep['acf_field'] : $rep['acf_name'];
		DBMig_ACF::update_value( $selector, $repeater_value, $object_id );
	}

	/**
	 * Resolve a mapped source value out of a row, applying any transform.
	 *
	 * @param bool $raw If true, skip transform (for relation/taxonomy lookups).
	 * @return mixed|null
	 */
	private function resolve_source_value( $row, $f, $raw = false ) {
		// A literal static value instead of a source column.
		if ( '__static__' === ( $f['source'] ?? '' ) ) {
			$value = isset( $f['static_value'] ) ? $f['static_value'] : '';
			return $raw ? $value : $this->transform_value( $value, $f['transform'] ?? 'none' );
		}
		if ( empty( $f['source'] ) ) {
			return null;
		}
		$col = $this->strip_qualifier( $f['source'] );
		if ( ! array_key_exists( $col, $row ) ) {
			return null;
		}
		$value = $row[ $col ];
		if ( $raw ) {
			return $value;
		}
		$transform = $f['transform'] ?? 'none';

		// Resolve a legacy id to the migrated WP post / user / term / comment id.
		if ( in_array( $transform, array( 'resolve_post', 'resolve_user', 'resolve_term', 'resolve_comment' ), true ) ) {
			if ( null === $value || '' === $value ) {
				return null;
			}
			$rt = $f['rel_table'] ?? '';
			if ( 'resolve_user' === $transform ) {
				$id = DBMig_Schema::find_user_by_legacy( $rt, $value );
			} elseif ( 'resolve_term' === $transform ) {
				$id = DBMig_Schema::find_term_by_legacy( $rt, $value );
			} elseif ( 'resolve_comment' === $transform ) {
				$id = DBMig_Schema::find_comment_by_legacy( $rt, $value );
			} else {
				$id = DBMig_Schema::find_post_by_legacy( $rt, $value );
			}
			return $id ? $id : null;
		}

		return $this->transform_value( $value, $transform );
	}

	private function transform_value( $value, $transform ) {
		if ( null === $value ) {
			return null;
		}
		switch ( $transform ) {
			case 'int':
				return (int) $value;
			case 'float':
				return (float) $value;
			case 'bool':
				return ( $value && '0' !== (string) $value ) ? 1 : 0;
			case 'date':
				// Normalise to MySQL datetime.
				$ts = strtotime( (string) $value );
				return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : $value;
			case 'json_decode':
				$decoded = json_decode( (string) $value, true );
				return ( null === $decoded ) ? $value : $decoded;
			case 'serialize_decode':
				$un = @maybe_unserialize( $value );
				return $un;
			case 'strip_tags':
				return wp_strip_all_tags( (string) $value );
			case 'none':
			default:
				return $value;
		}
	}

	/**
	 * Strip a `table.` / `alias.` qualifier so we can index into the flat result row.
	 * The SELECT aliases collisions, but bare column names are what land in $row keys.
	 */
	private function strip_qualifier( $col ) {
		$col = (string) $col;
		$pos = strpos( $col, '.' );
		return ( false !== $pos ) ? substr( $col, $pos + 1 ) : $col;
	}

	/* --------------------------------------------------------------------- *
	 *  Query building
	 * --------------------------------------------------------------------- */

	// Bare alias of a table reference ("db.table" -> "table").
	private function table_alias( $value ) {
		$value = (string) $value;
		$i     = strrpos( $value, '.' );
		return $i !== false ? substr( $value, $i + 1 ) : $value;
	}

	private function build_from() {
		$balias = $this->ext->safe_identifier( $this->table_alias( $this->profile['source_table'] ) );
		$bqt    = $this->ext->qualify_table( $this->profile['source_table'] );
		$from   = "FROM {$bqt} AS `{$balias}`";

		foreach ( $this->profile['joins'] as $j ) {
			if ( empty( $j['table'] ) || empty( $j['left_col'] ) || empty( $j['right_col'] ) ) {
				continue;
			}
			$qt = $this->ext->qualify_table( $j['table'] );
			if ( ! $qt ) {
				continue;
			}
			$jalias = $this->ext->safe_identifier( $this->table_alias( $j['table'] ) );
			$type   = ( 'INNER' === $j['type'] ) ? 'INNER' : 'LEFT';
			$left   = $this->qualify( $j['left_col'] );
			$right  = $this->qualify( $j['right_col'] );
			$from  .= " {$type} JOIN {$qt} AS `{$jalias}` ON {$left} = {$right}" . $this->join_extra_on( $j );
		}
		return $from;
	}

	/**
	 * Extra AND/OR ON conditions for a join (e.g. AND wp_posts.post_type = 'news').
	 */
	private function join_extra_on( $j ) {
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
			$col  = $this->qualify( $c['col'] );
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

	private function build_select( $offset, $limit ) {
		$base   = $this->ext->safe_identifier( $this->table_alias( $this->profile['source_table'] ) );
		$from   = $this->build_from();
		$offset = max( 0, (int) $offset );
		$limit  = max( 1, (int) $limit );

		// Select everything from the base table; joined columns are reachable by bare
		// name when unambiguous. For ambiguous columns, users should map qualified.
		$select = "SELECT `{$base}`.*";

		// Add explicitly-mapped joined columns so they appear in the row.
		$extra = $this->collect_join_columns();
		if ( ! empty( $extra ) ) {
			$select .= ', ' . implode( ', ', $extra );
		}

		$order = "ORDER BY `{$base}`.`" . esc_sql( $this->profile['source_id_column'] ) . '` ASC';

		return "{$select} {$from} {$order} LIMIT {$limit} OFFSET {$offset}";
	}

	/**
	 * Gather qualified columns referenced from joined tables in field mappings so
	 * they get aliased into the flat row (bare column name).
	 */
	private function collect_join_columns() {
		$base   = $this->table_alias( $this->profile['source_table'] );
		$cols   = array();
		$seen   = array();

		$consider = function ( $source ) use ( &$cols, &$seen, $base ) {
			if ( empty( $source ) || false === strpos( $source, '.' ) ) {
				return;
			}
			list( $tbl, $col ) = explode( '.', $source, 2 );
			if ( $tbl === $base ) {
				return; // already covered by base.*
			}
			$tbl_safe = preg_replace( '/[^A-Za-z0-9_-]/', '', $tbl );
			$col_safe = preg_replace( '/[^A-Za-z0-9_-]/', '', $col );
			if ( ! $tbl_safe || ! $col_safe ) {
				return;
			}
			$key = $col_safe;
			if ( isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			// Alias to the bare column name so strip_qualifier() lands on it.
			$cols[] = "`{$tbl_safe}`.`{$col_safe}` AS `{$col_safe}`";
		};

		foreach ( $this->profile['fields'] as $f ) {
			$consider( $f['source'] ?? '' );
			// A mapped taxonomy slug column (from a joined table) must be selected too.
			if ( 'taxonomy' === $f['target_kind'] && ! empty( $f['term_slug'] ) ) {
				$consider( $f['term_slug'] );
			}
			// Also pull the detail-table key for taxonomy fields, so terms can be
			// stamped / matched by legacy id (not just by name).
			if ( 'taxonomy' === $f['target_kind'] && ! empty( $f['source'] ) ) {
				$detail = $this->taxonomy_detail( $f );
				if ( $detail ) {
					$consider( $detail[0] . '.' . $detail[1] );
				}
			}
		}
		return $cols;
	}

	/**
	 * For a taxonomy field, the [detail_table, key_col] the terms come from, or
	 * null when the value is a plain column on the base row (name-only matching).
	 */
	private function taxonomy_detail( $f ) {
		if ( empty( $f['source'] ) || false === strpos( $f['source'], '.' ) ) {
			return null;
		}
		list( $dt ) = explode( '.', $f['source'], 2 );
		$detail     = preg_replace( '/[^A-Za-z0-9_-]/', '', $dt );
		if ( $detail === $this->profile['source_table'] ) {
			return null;
		}
		$key = $this->detail_key_for( $detail );
		return $key ? array( $detail, $key ) : null;
	}

	private function detail_key_for( $detail_table ) {
		foreach ( $this->profile['joins'] as $j ) {
			if ( empty( $j['table'] ) ) {
				continue;
			}
			foreach ( array( $j['left_col'], $j['right_col'] ) as $c ) {
				if ( $c && false !== strpos( $c, '.' ) ) {
					list( $t, $col ) = explode( '.', $c, 2 );
					if ( preg_replace( '/[^A-Za-z0-9_-]/', '', $t ) === $detail_table ) {
						return preg_replace( '/[^A-Za-z0-9_-]/', '', $col );
					}
				}
			}
		}
		return '';
	}

	private function qualify( $col ) {
		if ( false !== strpos( $col, '.' ) ) {
			list( $t, $c ) = explode( '.', $col, 2 );
			$t = preg_replace( '/[^A-Za-z0-9_-]/', '', $t );
			$c = preg_replace( '/[^A-Za-z0-9_-]/', '', $c );
			return "`{$t}`.`{$c}`";
		}
		$base = $this->ext->safe_identifier( $this->table_alias( $this->profile['source_table'] ) );
		$c    = preg_replace( '/[^A-Za-z0-9_-]/', '', $col );
		return "`{$base}`.`{$c}`";
	}
}
