<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for migration profiles. A profile describes how ONE source table maps to
 * ONE post type, including joins, field mappings, taxonomy mappings and ACF
 * repeater / relationship mappings. Stored as a single option (array of profiles).
 *
 * Profile shape:
 * array(
 *   'id'              => string,
 *   'name'            => string,
 *   'post_type'       => string,
 *   'post_status'     => string,
 *   'source_table'    => string,
 *   'source_id_column'=> string,
 *   'joins'           => array( array('table','type','left_col','right_col'), ... ),
 *   'fields'          => array( array(
 *                            'target_kind'  => post_field|post_meta|acf|taxonomy|acf_relation,
 *                            'target'       => string,      // meta key / acf field key / taxonomy slug / post field
 *                            'acf_name'     => string,      // acf field name (display)
 *                            'source'       => string,      // qualified source column
 *                            'transform'    => string,      // none|date|int|bool|json_decode|...
 *                            'term_match'   => name|slug|id, // taxonomy: how to match the source value
 *                            'term_create'  => bool,        // taxonomy: create term if missing
 *                            'rel_table'    => string,      // acf_relation: legacy table referenced
 *                        ), ... ),
 *   'repeaters'       => array( array(
 *                            'acf_field'    => string,      // repeater field key
 *                            'acf_name'     => string,
 *                            'child_table'  => string,
 *                            'child_fk'     => string,      // column on child table pointing back to parent id
 *                            'parent_col'   => string,      // source id column the fk matches (defaults to source_id_column)
 *                            'order_by'     => string,
 *                            'sub_map'      => array( array('sub_field','sub_name','source'), ... ),
 *                        ), ... ),
 * )
 */
class DBMig_Profiles {

	const OPTION_KEY = 'dbmig_profiles';

	public static function all() {
		$profiles = get_option( self::OPTION_KEY, array() );
		return is_array( $profiles ) ? $profiles : array();
	}

	public static function get( $id ) {
		foreach ( self::all() as $p ) {
			if ( $p['id'] === $id ) {
				return $p;
			}
		}
		return null;
	}

	public static function save( $profile ) {
		$profiles = self::all();

		if ( empty( $profile['id'] ) ) {
			$profile['id'] = 'mig_' . wp_generate_password( 8, false, false );
		}

		$found = false;
		foreach ( $profiles as $i => $p ) {
			if ( $p['id'] === $profile['id'] ) {
				$profiles[ $i ] = $profile;
				$found          = true;
				break;
			}
		}
		if ( ! $found ) {
			$profiles[] = $profile;
		}

		update_option( self::OPTION_KEY, $profiles );
		return $profile['id'];
	}

	/**
	 * Stream all migration profiles to the browser as a JSON file (authenticated),
	 * so they can be imported on another install (e.g. local → server).
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'db-migrator' ), 403 );
		}
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'dbmig_export' ) ) {
			wp_die( esc_html__( 'Invalid or expired link.', 'db-migrator' ), 403 );
		}
		$profiles = self::all();
		if ( empty( $profiles ) ) {
			wp_die( esc_html__( 'There are no migrations to export yet.', 'db-migrator' ), 400 );
		}

		// Optional selection: ?ids=a,b,c exports only those profiles.
		$ids = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';
		if ( '' !== $ids ) {
			$wanted   = array_filter( array_map( 'trim', explode( ',', $ids ) ) );
			$profiles = array_values(
				array_filter(
					$profiles,
					function ( $p ) use ( $wanted ) {
						return in_array( $p['id'], $wanted, true );
					}
				)
			);
		}

		$payload = array(
			'plugin'      => 'db-migrator',
			'version'     => defined( 'DBMIG_VERSION' ) ? DBMIG_VERSION : '',
			'exported_at' => gmdate( 'c' ),
			'profiles'    => $profiles,
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="db-migrator-profiles-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public static function delete( $id ) {
		$profiles = self::all();
		foreach ( $profiles as $i => $p ) {
			if ( $p['id'] === $id ) {
				unset( $profiles[ $i ] );
			}
		}
		update_option( self::OPTION_KEY, array_values( $profiles ) );
	}

	/**
	 * Normalise / sanitise a profile coming from the admin form ($_POST decoded).
	 */
	public static function sanitize( $raw ) {
		$profile = array(
			'id'               => isset( $raw['id'] ) ? sanitize_text_field( $raw['id'] ) : '',
			'name'             => isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '',
			'migration_type'   => in_array( $raw['migration_type'] ?? '', array( 'user', 'term', 'attachment', 'comment' ), true ) ? $raw['migration_type'] : 'post',
			'post_type'        => isset( $raw['post_type'] ) ? sanitize_key( $raw['post_type'] ) : 'post',
			'taxonomy'         => isset( $raw['taxonomy'] ) ? sanitize_key( $raw['taxonomy'] ) : '',
			'role'             => isset( $raw['role'] ) ? sanitize_key( $raw['role'] ) : 'subscriber',
			'post_status'      => isset( $raw['post_status'] ) ? sanitize_key( $raw['post_status'] ) : 'publish',
			'partial'          => ! empty( $raw['partial'] ) ? 1 : 0,
			'preserve_id'      => ! empty( $raw['preserve_id'] ) ? 1 : 0,
			'source_table'     => isset( $raw['source_table'] ) ? sanitize_text_field( $raw['source_table'] ) : '',
			'source_id_column' => isset( $raw['source_id_column'] ) ? sanitize_text_field( $raw['source_id_column'] ) : '',
			'joins'            => array(),
			'fields'           => array(),
			'repeaters'        => array(),
		);

		// A media-attachment migration is a post migration into the built-in
		// 'attachment' type, which always uses the 'inherit' status.
		if ( 'attachment' === $profile['migration_type'] ) {
			$profile['post_type']   = 'attachment';
			$profile['post_status'] = 'inherit';
		}

		if ( ! empty( $raw['joins'] ) && is_array( $raw['joins'] ) ) {
			foreach ( $raw['joins'] as $j ) {
				if ( empty( $j['table'] ) ) {
					continue;
				}
				$conditions = array();
				if ( ! empty( $j['conditions'] ) && is_array( $j['conditions'] ) ) {
					$ops = array( '=', '!=', '<', '<=', '>', '>=', 'LIKE', 'IS NULL', 'IS NOT NULL' );
					foreach ( $j['conditions'] as $c ) {
						if ( empty( $c['col'] ) ) {
							continue;
						}
						$conditions[] = array(
							'conj' => in_array( ( $c['conj'] ?? 'AND' ), array( 'AND', 'OR' ), true ) ? $c['conj'] : 'AND',
							'col'  => sanitize_text_field( $c['col'] ),
							'op'   => in_array( ( $c['op'] ?? '=' ), $ops, true ) ? $c['op'] : '=',
							'val'  => isset( $c['val'] ) ? (string) wp_unslash( $c['val'] ) : '',
						);
					}
				}
				$profile['joins'][] = array(
					'table'      => sanitize_text_field( $j['table'] ),
					'type'       => in_array( $j['type'], array( 'LEFT', 'INNER' ), true ) ? $j['type'] : 'LEFT',
					'left_col'   => sanitize_text_field( $j['left_col'] ),
					'right_col'  => sanitize_text_field( $j['right_col'] ),
					'conditions' => $conditions,
				);
			}
		}

		if ( ! empty( $raw['fields'] ) && is_array( $raw['fields'] ) ) {
			foreach ( $raw['fields'] as $f ) {
				if ( empty( $f['source'] ) && 'acf_relation' !== ( $f['target_kind'] ?? '' ) ) {
					// allow empty source only when nothing chosen; skip blank rows
				}
				if ( empty( $f['target'] ) && empty( $f['source'] ) ) {
					continue;
				}
				$profile['fields'][] = array(
					'target_kind'  => sanitize_text_field( $f['target_kind'] ?? 'post_meta' ),
					'target'       => sanitize_text_field( $f['target'] ?? '' ),
					'acf_name'     => sanitize_text_field( $f['acf_name'] ?? '' ),
					'source'       => sanitize_text_field( $f['source'] ?? '' ),
					'static_value' => isset( $f['static_value'] ) ? (string) wp_unslash( $f['static_value'] ) : '',
					'transform'   => sanitize_text_field( $f['transform'] ?? 'none' ),
					'term_match'  => sanitize_text_field( $f['term_match'] ?? 'name' ),
					'term_slug'     => sanitize_text_field( $f['term_slug'] ?? '' ),
					'term_create'   => ! empty( $f['term_create'] ) ? 1 : 0,
					'term_append'   => ! empty( $f['term_append'] ) ? 1 : 0,
					'rel_table'     => sanitize_text_field( $f['rel_table'] ?? '' ),
					'rel_match'     => sanitize_text_field( $f['rel_match'] ?? 'legacy' ),
					'rel_post_type' => sanitize_key( $f['rel_post_type'] ?? '' ),
					'rel_meta_key'  => sanitize_text_field( $f['rel_meta_key'] ?? '' ),
					'attach_as'     => sanitize_text_field( $f['attach_as'] ?? '' ),
				);
			}
		}

		if ( ! empty( $raw['repeaters'] ) && is_array( $raw['repeaters'] ) ) {
			foreach ( $raw['repeaters'] as $r ) {
				if ( empty( $r['acf_field'] ) || empty( $r['child_table'] ) ) {
					continue;
				}
				$sub_map = array();
				if ( ! empty( $r['sub_map'] ) && is_array( $r['sub_map'] ) ) {
					foreach ( $r['sub_map'] as $s ) {
						if ( empty( $s['sub_field'] ) ) {
							continue;
						}
						$sub_map[] = array(
							'sub_field' => sanitize_text_field( $s['sub_field'] ),
							'sub_name'  => sanitize_text_field( $s['sub_name'] ?? '' ),
							'source'    => sanitize_text_field( $s['source'] ?? '' ),
							'transform' => sanitize_text_field( $s['transform'] ?? 'none' ),
							'rel_table' => sanitize_text_field( $s['rel_table'] ?? '' ),
						);
					}
				}
				$rep_joins = array();
				if ( ! empty( $r['joins'] ) && is_array( $r['joins'] ) ) {
					foreach ( $r['joins'] as $rj ) {
						if ( empty( $rj['table'] ) || empty( $rj['left_col'] ) || empty( $rj['right_col'] ) ) {
							continue;
						}
						$rep_joins[] = array(
							'table'     => sanitize_text_field( $rj['table'] ),
							'type'      => in_array( $rj['type'] ?? 'LEFT', array( 'LEFT', 'INNER' ), true ) ? $rj['type'] : 'LEFT',
							'left_col'  => sanitize_text_field( $rj['left_col'] ),
							'right_col' => sanitize_text_field( $rj['right_col'] ),
							'latest_by' => sanitize_text_field( $rj['latest_by'] ?? '' ),
						);
					}
				}
				$profile['repeaters'][] = array(
					'acf_field'   => sanitize_text_field( $r['acf_field'] ),
					'acf_name'    => sanitize_text_field( $r['acf_name'] ?? '' ),
					'child_table' => sanitize_text_field( $r['child_table'] ),
					'child_fk'    => sanitize_text_field( $r['child_fk'] ?? '' ),
					'parent_col'  => sanitize_text_field( $r['parent_col'] ?? '' ),
					'order_by'    => sanitize_text_field( $r['order_by'] ?? '' ),
					'joins'       => $rep_joins,
					'sub_map'     => $sub_map,
				);
			}
		}

		return $profile;
	}
}
