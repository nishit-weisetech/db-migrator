<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ACF integration helpers. Everything here degrades gracefully when ACF is not
 * active so the plugin still works for plain post / post-meta migrations.
 */
class DBMig_ACF {

	public static function is_active() {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' );
	}

	/**
	 * Return the ACF fields attached to a post type, flattened.
	 *
	 * Each entry: array(
	 *   key, name, label, type, parent_label,
	 *   sub_fields => array(...)   // only for repeater / group / flexible
	 * )
	 *
	 * @return array
	 */
	public static function get_fields_for_post_type( $post_type ) {
		if ( ! self::is_active() ) {
			return array();
		}

		$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
		$out    = array();

		if ( ! is_array( $groups ) ) {
			return $out;
		}

		foreach ( $groups as $group ) {
			$fields = acf_get_fields( $group['key'] );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				$out[] = self::map_field( $field, $group['title'] );
			}
		}
		return $out;
	}

	private static function map_field( $field, $group_label ) {
		$entry = array(
			'key'          => $field['key'],
			'name'         => $field['name'],
			'label'        => $field['label'],
			'type'         => $field['type'],
			'parent_label' => $group_label,
			'sub_fields'   => array(),
		);

		// Repeaters, groups and flexible content carry sub fields we expose for mapping.
		if ( in_array( $field['type'], array( 'repeater', 'group' ), true ) && ! empty( $field['sub_fields'] ) ) {
			foreach ( $field['sub_fields'] as $sub ) {
				$entry['sub_fields'][] = array(
					'key'   => $sub['key'],
					'name'  => $sub['name'],
					'label' => $sub['label'],
					'type'  => $sub['type'],
				);
			}
		}
		return $entry;
	}

	/**
	 * Return ACF fields attached to the user edit screen, flattened. Best-effort:
	 * collects every field group whose location targets users.
	 *
	 * @return array
	 */
	public static function get_fields_for_users() {
		if ( ! self::is_active() ) {
			return array();
		}
		$groups = acf_get_field_groups();
		$out    = array();
		if ( ! is_array( $groups ) ) {
			return $out;
		}
		foreach ( $groups as $group ) {
			if ( ! self::group_targets_user( $group ) ) {
				continue;
			}
			$fields = acf_get_fields( $group['key'] );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				$out[] = self::map_field( $field, $group['title'] );
			}
		}
		return $out;
	}

	/**
	 * Return the ACF fields whose field group is located on a given taxonomy,
	 * flattened. Used for the taxonomy-term migration type.
	 *
	 * @return array
	 */
	public static function get_fields_for_taxonomy( $taxonomy ) {
		if ( ! self::is_active() || '' === (string) $taxonomy ) {
			return array();
		}
		$groups = acf_get_field_groups( array( 'taxonomy' => $taxonomy ) );
		$out    = array();
		if ( ! is_array( $groups ) ) {
			return $out;
		}
		foreach ( $groups as $group ) {
			$fields = acf_get_fields( $group['key'] );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				$out[] = self::map_field( $field, $group['title'] );
			}
		}
		return $out;
	}

	/**
	 * Every single (non-repeater/group) ACF field across ALL field groups,
	 * flattened. Used for the manual "ACF field" mapping option so a field can be
	 * mapped even when its group's location doesn't match the current object type.
	 *
	 * @return array
	 */
	public static function get_all_fields() {
		if ( ! self::is_active() ) {
			return array();
		}
		$groups = acf_get_field_groups();
		$out    = array();
		if ( ! is_array( $groups ) ) {
			return $out;
		}
		foreach ( $groups as $group ) {
			$fields = acf_get_fields( $group['key'] );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( in_array( $field['type'], array( 'repeater', 'group', 'flexible_content' ), true ) ) {
					continue;
				}
				$out[] = self::map_field( $field, $group['title'] );
			}
		}
		return $out;
	}

	private static function group_targets_user( $group ) {
		if ( empty( $group['location'] ) || ! is_array( $group['location'] ) ) {
			return false;
		}
		foreach ( $group['location'] as $or ) {
			foreach ( (array) $or as $rule ) {
				if ( isset( $rule['param'] ) && in_array( $rule['param'], array( 'user_form', 'user_role' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Write an ACF value. Uses update_field when ACF is active so serialization,
	 * repeater rows and the field-key reference meta are handled correctly.
	 * Falls back to raw meta otherwise.
	 *
	 * @param string     $selector  field key (preferred) or field name
	 * @param mixed      $value
	 * @param int|string $object_id post ID, or "user_{ID}" for user fields
	 */
	public static function update_value( $selector, $value, $object_id ) {
		if ( self::is_active() && function_exists( 'update_field' ) ) {
			update_field( $selector, $value, $object_id );
			return;
		}
		// Fallback: store as plain meta under the selector.
		if ( is_string( $object_id ) && 0 === strpos( $object_id, 'user_' ) ) {
			$user_id = (int) substr( $object_id, 5 );
			update_user_meta( $user_id, $selector, $value );
		} elseif ( is_string( $object_id ) && 0 === strpos( $object_id, 'term_' ) ) {
			$term_id = (int) substr( $object_id, 5 );
			update_term_meta( $term_id, $selector, $value );
		} elseif ( is_string( $object_id ) && 0 === strpos( $object_id, 'comment_' ) ) {
			$comment_id = (int) substr( $object_id, 8 );
			update_comment_meta( $comment_id, $selector, $value );
		} else {
			update_post_meta( (int) $object_id, $selector, $value );
		}
	}
}
