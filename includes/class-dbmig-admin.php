<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menus, settings handling, asset loading and page rendering.
 */
class DBMig_Admin {

	const MENU_SLUG     = 'db-migrator';
	const SETTINGS_SLUG = 'db-migrator-settings';
	const GUIDE_SLUG    = 'db-migrator-guide';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . DBMIG_BASENAME, array( $this, 'action_links' ) );
	}

	public function action_links( $links ) {
		$url           = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$links['open'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Migrations', 'db-migrator' ) . '</a>';
		return $links;
	}

	public function menu() {
		add_menu_page(
			__( 'DB Migrator', 'db-migrator' ),
			__( 'DB Migrator', 'db-migrator' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_main' ),
			'dashicons-database-import',
			58
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Migrations', 'db-migrator' ),
			__( 'Migrations', 'db-migrator' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_main' )
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'db-migrator' ),
			__( 'Settings', 'db-migrator' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings' )
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Guide', 'db-migrator' ),
			__( 'Guide', 'db-migrator' ),
			'manage_options',
			self::GUIDE_SLUG,
			array( $this, 'render_guide' )
		);
	}

	public function render_guide() {
		include DBMIG_DIR . 'includes/views/guide.php';
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}
		// Version by file mtime so edits bust the browser cache automatically
		// (avoids stale JS/CSS being served when the plugin version is unchanged).
		$css_ver = (string) @filemtime( DBMIG_DIR . 'assets/admin.css' );
		$js_ver  = (string) @filemtime( DBMIG_DIR . 'assets/admin.js' );

		// Select2 (bundled locally) for searchable dropdowns.
		wp_enqueue_style( 'dbmig-select2', DBMIG_URL . 'assets/vendor/select2.min.css', array(), '4.0.13' );
		wp_enqueue_script( 'dbmig-select2', DBMIG_URL . 'assets/vendor/select2.min.js', array( 'jquery' ), '4.0.13', true );

		wp_enqueue_style( 'dbmig-admin', DBMIG_URL . 'assets/admin.css', array( 'dbmig-select2' ), $css_ver ? $css_ver : DBMIG_VERSION );
		wp_enqueue_script( 'dbmig-admin', DBMIG_URL . 'assets/admin.js', array( 'jquery', 'dbmig-select2' ), $js_ver ? $js_ver : DBMIG_VERSION, true );

		wp_localize_script(
			'dbmig-admin',
			'DBMig',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( DBMig_Ajax::NONCE ),
				'acfActive'  => DBMig_ACF::is_active(),
				'postFields' => $this->post_field_choices(),
				'userFields' => $this->user_field_choices(),
				'termFields' => $this->term_field_choices(),
				'attachmentFields' => $this->attachment_field_choices(),
				'postTypes'  => $this->get_post_types(),
				'taxonomies' => $this->taxonomy_choices(),
				'roles'      => $this->role_choices(),
				'i18n'       => array(
					'testing'   => __( 'Testing…', 'db-migrator' ),
					'saving'    => __( 'Saving…', 'db-migrator' ),
					'running'   => __( 'Running…', 'db-migrator' ),
					'confirm'   => __( 'Delete this migration profile?', 'db-migrator' ),
					'done'      => __( 'Done', 'db-migrator' ),
					'selectCol' => __( '— select column —', 'db-migrator' ),
				),
			)
		);
	}

	/**
	 * Standard wp_posts fields offered as mapping targets.
	 */
	private function post_field_choices() {
		return array(
			'post_title'    => 'Title (post_title)',
			'post_content'  => 'Content (post_content)',
			'post_excerpt'  => 'Excerpt (post_excerpt)',
			'post_name'     => 'Slug (post_name)',
			'post_date'     => 'Date (post_date)',
			'post_status'   => 'Status (post_status)',
			'post_author'   => 'Author ID (post_author)',
			'menu_order'    => 'Menu order (menu_order)',
			'post_parent'   => 'Parent ID (post_parent)',
		);
	}

	/**
	 * Standard wp_users / core profile fields offered as mapping targets.
	 * (first_name, last_name, nickname, description are handled by wp_insert_user.)
	 */
	private function user_field_choices() {
		return array(
			'user_login'    => 'Username (user_login)',
			'user_email'    => 'Email (user_email)',
			'user_pass'     => 'Password — plain (user_pass)',
			'display_name'  => 'Display name (display_name)',
			'first_name'    => 'First name (first_name)',
			'last_name'     => 'Last name (last_name)',
			'nickname'      => 'Nickname (nickname)',
			'user_url'      => 'Website (user_url)',
			'user_nicename' => 'Slug (user_nicename)',
			'description'   => 'Bio (description)',
			'user_registered' => 'Registered date (user_registered)',
		);
	}

	private function role_choices() {
		$roles = function_exists( 'get_editable_roles' ) ? get_editable_roles() : wp_roles()->roles;
		$out   = array();
		foreach ( $roles as $slug => $r ) {
			$out[ $slug ] = isset( $r['name'] ) ? $r['name'] : $slug;
		}
		return $out;
	}

	private function attachment_field_choices() {
		return array(
			'post_title'     => 'Title (post_title)',
			'post_name'      => 'Slug (post_name)',
			'guid'           => 'File URL (guid) — MIME type is auto-detected from its extension',
			'post_content'   => 'Description (post_content)',
			'post_excerpt'   => 'Caption (post_excerpt)',
			'post_date'      => 'Date (post_date)',
			'post_author'    => 'Author ID (post_author)',
			'post_parent'    => 'Attached to — post ID (post_parent)',
		);
	}

	private function term_field_choices() {
		return array(
			'name'        => 'Name (term name)',
			'slug'        => 'Slug (term slug)',
			'description' => 'Description',
			'parent'      => 'Parent (term id — use “Resolve → migrated term”)',
		);
	}

	private function taxonomy_choices() {
		$taxes = get_taxonomies( array( 'show_ui' => true ), 'objects' );
		$out   = array();
		foreach ( $taxes as $slug => $obj ) {
			$out[ $slug ] = $obj->labels->singular_name . ' (' . $slug . ')';
		}
		return $out;
	}

	/* --------------------------------------------------------------------- *
	 *  Non-AJAX POST handling (settings + schema)
	 * --------------------------------------------------------------------- */

	public function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Save external DB settings.
		if ( isset( $_POST['dbmig_save_settings'] ) ) {
			check_admin_referer( 'dbmig_settings' );
			DBMig_External_DB::save_config(
				array(
					'host'   => $_POST['dbmig_host'] ?? 'localhost',
					'dbname' => $_POST['dbmig_dbname'] ?? '',
					'dbuser' => $_POST['dbmig_dbuser'] ?? '',
					'dbpass' => $_POST['dbmig_dbpass'] ?? '',
				)
			);
			$this->redirect_with( self::SETTINGS_SLUG, 'saved' );
		}

		// Ensure schema columns.
		if ( isset( $_POST['dbmig_ensure_schema'] ) ) {
			check_admin_referer( 'dbmig_settings' );
			$res = DBMig_Schema::ensure_columns();
			$flag = is_wp_error( $res ) ? 'schema_error' : 'schema_ok';
			$this->redirect_with( self::SETTINGS_SLUG, $flag );
		}

		// Delete profile (non-ajax fallback).
		if ( isset( $_GET['dbmig_delete'] ) && check_admin_referer( 'dbmig_delete_profile' ) ) {
			DBMig_Profiles::delete( sanitize_text_field( wp_unslash( $_GET['dbmig_delete'] ) ) );
			$this->redirect_with( self::MENU_SLUG, 'deleted' );
		}
	}

	private function redirect_with( $page, $flag ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page . '&dbmig_msg=' . $flag ) );
		exit;
	}

	private function notice() {
		$msg = isset( $_GET['dbmig_msg'] ) ? sanitize_key( $_GET['dbmig_msg'] ) : '';
		if ( ! $msg ) {
			return;
		}
		$map = array(
			'saved'        => array( 'success', __( 'Settings saved.', 'db-migrator' ) ),
			'schema_ok'    => array( 'success', __( 'Legacy columns are ready on wp_posts.', 'db-migrator' ) ),
			'schema_error' => array( 'error', __( 'Could not add legacy columns. Check DB privileges.', 'db-migrator' ) ),
			'deleted'      => array( 'success', __( 'Migration profile deleted.', 'db-migrator' ) ),
		);
		if ( isset( $map[ $msg ] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $map[ $msg ][0] ),
				esc_html( $map[ $msg ][1] )
			);
		}
	}

	/* --------------------------------------------------------------------- *
	 *  Page renderers
	 * --------------------------------------------------------------------- */

	public function render_settings() {
		$config = DBMig_External_DB::get_config();
		$ready  = DBMig_Schema::columns_ready();
		$this->notice();
		include DBMIG_DIR . 'includes/views/settings.php';
	}

	public function render_main() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
		$this->notice();

		if ( 'edit' === $action || 'new' === $action ) {
			$profile = null;
			if ( isset( $_GET['id'] ) ) {
				$profile = DBMig_Profiles::get( sanitize_text_field( wp_unslash( $_GET['id'] ) ) );
			}
			$post_types     = $this->get_post_types();
			$post_statuses  = $this->get_post_statuses();
			$post_field_map = $this->post_field_choices();
			$ext_config     = DBMig_External_DB::get_config();
			$schema_ready   = DBMig_Schema::columns_ready();
			include DBMIG_DIR . 'includes/views/profile-edit.php';
			return;
		}

		$profiles = DBMig_Profiles::all();
		$ext      = new DBMig_External_DB();
		$connected = $ext->is_configured();
		include DBMIG_DIR . 'includes/views/profile-list.php';
	}

	private function get_post_types() {
		$types = get_post_types( array( 'show_ui' => true ), 'objects' );
		$out   = array();
		foreach ( $types as $slug => $obj ) {
			if ( in_array( $slug, array( 'attachment' ), true ) ) {
				continue;
			}
			$out[ $slug ] = $obj->labels->singular_name . ' (' . $slug . ')';
		}
		return $out;
	}

	private function get_post_statuses() {
		return array(
			'publish' => __( 'Published', 'db-migrator' ),
			'draft'   => __( 'Draft', 'db-migrator' ),
			'pending' => __( 'Pending', 'db-migrator' ),
			'private' => __( 'Private', 'db-migrator' ),
		);
	}
}
