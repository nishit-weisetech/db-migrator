<?php
/**
 * Plugin Name: DB Migrator
 * Description: Migrate data from an external (legacy) MySQL database into WordPress posts, post meta, taxonomies, users and ACF fields with a visual field-mapping panel. Maintains a legacy_id / legacy_table_name relation on wp_posts so cross-table relations and re-runs stay consistent.
 * Version: 0.0.1
 * Author: Weise Technologies
 * Requires PHP: 7.4
 * Text Domain: db-migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DBMIG_VERSION', '1.0.0' );
define( 'DBMIG_FILE', __FILE__ );
define( 'DBMIG_DIR', plugin_dir_path( __FILE__ ) );
define( 'DBMIG_URL', plugin_dir_url( __FILE__ ) );
define( 'DBMIG_BASENAME', plugin_basename( __FILE__ ) );

require_once DBMIG_DIR . 'includes/class-dbmig-external-db.php';
require_once DBMIG_DIR . 'includes/class-dbmig-schema.php';
require_once DBMIG_DIR . 'includes/class-dbmig-acf.php';
require_once DBMIG_DIR . 'includes/class-dbmig-media.php';
require_once DBMIG_DIR . 'includes/class-dbmig-profiles.php';
require_once DBMIG_DIR . 'includes/class-dbmig-importer.php';
require_once DBMIG_DIR . 'includes/class-dbmig-sql-builder.php';
require_once DBMIG_DIR . 'includes/class-dbmig-exporter.php';
require_once DBMIG_DIR . 'includes/class-dbmig-ajax.php';
require_once DBMIG_DIR . 'includes/class-dbmig-admin.php';
require_once DBMIG_DIR . 'includes/class-dbmig-plugin.php';

register_activation_hook( __FILE__, array( 'DBMig_Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'DBMig_Plugin', 'instance' ) );
