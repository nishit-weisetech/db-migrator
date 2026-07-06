<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core bootstrap. Wires the admin, ajax and schema pieces together.
 */
class DBMig_Plugin {

	/** @var DBMig_Plugin */
	private static $instance = null;

	/** @var DBMig_Admin */
	public $admin;

	/** @var DBMig_Ajax */
	public $ajax;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->admin = new DBMig_Admin();
		$this->ajax  = new DBMig_Ajax();

		$this->admin->hooks();
		$this->ajax->hooks();
	}

	/**
	 * Runs on activation. Ensures the legacy columns exist on wp_posts.
	 */
	public static function activate() {
		DBMig_Schema::ensure_columns();
		if ( false === get_option( DBMig_External_DB::OPTION_KEY ) ) {
			add_option(
				DBMig_External_DB::OPTION_KEY,
				array(
					'host'   => 'localhost',
					'dbname' => '',
					'dbuser' => '',
					'dbpass' => '',
				)
			);
		}
	}
}
