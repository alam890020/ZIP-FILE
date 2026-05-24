<?php
/**
 * Plugin Name:       MAKE SCHOOL
 * Plugin URI:        https://example.com/make-school
 * Description:       Enterprise-grade School Management System for WordPress with multi-branch, admissions, fees, attendance, exams, report cards and a built-in LMS.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            MAKE SCHOOL Team
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       make-school
 * Domain Path:       /languages
 *
 * @package MakeSchool
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Plugin constants.
 * ------------------------------------------------------------------------- */

define( 'MAKE_SCHOOL_VERSION', '1.0.0' );
define( 'MAKE_SCHOOL_DB_VERSION', '1.0.0' );
define( 'MAKE_SCHOOL_PLUGIN_FILE', __FILE__ );
define( 'MAKE_SCHOOL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'MAKE_SCHOOL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAKE_SCHOOL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MAKE_SCHOOL_INCLUDES_DIR', MAKE_SCHOOL_PLUGIN_DIR . 'includes/' );
define( 'MAKE_SCHOOL_ASSETS_URL', MAKE_SCHOOL_PLUGIN_URL . 'assets/' );
define( 'MAKE_SCHOOL_TEXT_DOMAIN', 'make-school' );

/* -------------------------------------------------------------------------
 * Bootstrap loader.
 * ------------------------------------------------------------------------- */

require_once MAKE_SCHOOL_INCLUDES_DIR . 'class-make-school-db.php';

/**
 * Main plugin class.
 *
 * Handles bootstrapping, lifecycle hooks (activation / deactivation /
 * uninstall) and loading of sub-modules. Implemented as a singleton to
 * guarantee a single source of truth for the plugin runtime.
 */
final class Make_School_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Make_School_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Database helper instance.
	 *
	 * @var Make_School_DB
	 */
	public $db;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Make_School_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — wires up lifecycle and runtime hooks.
	 */
	private function __construct() {
		$this->db = new Make_School_DB();

		// Lifecycle hooks.
		register_activation_hook( MAKE_SCHOOL_PLUGIN_FILE, array( $this, 'on_activate' ) );
		register_deactivation_hook( MAKE_SCHOOL_PLUGIN_FILE, array( $this, 'on_deactivate' ) );

		// Runtime hooks.
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		add_action( 'init', array( $this, 'on_init' ) );
	}

	/**
	 * Block cloning.
	 */
	private function __clone() {}

	/**
	 * Block unserialization.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/* ---------------------------------------------------------------------
	 * Lifecycle.
	 * ------------------------------------------------------------------- */

	/**
	 * Activation handler — installs schema, registers roles, schedules tasks.
	 *
	 * @return void
	 */
	public function on_activate() {
		// Capability check — only an actual administrator should ever trigger
		// activation through the WordPress UI.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// 1. Install / upgrade database tables via dbDelta().
		$this->db->install();

		// 2. Programmatically create the four dedicated user roles.
		$this->register_roles();

		// 3. Seed default options used by other modules.
		$this->seed_default_options();

		// 4. Flush rewrite rules so the future endpoints
		//    (e.g. /teacher-dashboard, /student-dashboard) resolve cleanly.
		flush_rewrite_rules();
	}

	/**
	 * Deactivation handler — keeps data, only flushes runtime artefacts.
	 *
	 * @return void
	 */
	public function on_deactivate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Clear scheduled cron events that may have been registered by sub-modules.
		wp_clear_scheduled_hook( 'make_school_daily_cron' );

		flush_rewrite_rules();
	}

	/* ---------------------------------------------------------------------
	 * Runtime.
	 * ------------------------------------------------------------------- */

	/**
	 * Late-bound plugin bootstrapping (translations, integrations).
	 *
	 * @return void
	 */
	public function on_plugins_loaded() {
		load_plugin_textdomain(
			MAKE_SCHOOL_TEXT_DOMAIN,
			false,
			dirname( MAKE_SCHOOL_PLUGIN_BASENAME ) . '/languages'
		);

		// Run an in-place schema upgrade if the stored DB version is older
		// than the code-level DB version. This keeps installations on
		// long-running sites in sync without requiring re-activation.
		$installed = get_option( 'make_school_db_version' );
		if ( $installed !== MAKE_SCHOOL_DB_VERSION ) {
			$this->db->install();
		}
	}

	/**
	 * Init-time bootstrapping. Sub-module classes (admissions, fees,
	 * attendance, exams, lms) are loaded here in subsequent assignments.
	 *
	 * @return void
	 */
	public function on_init() {
		/**
		 * Fires when MAKE SCHOOL has finished its core boot. Other modules
		 * (admin panels, shortcodes, AJAX handlers) should listen on this
		 * action to register themselves cleanly.
		 */
		do_action( 'make_school_loaded' );
	}

	/* ---------------------------------------------------------------------
	 * Roles & options.
	 * ------------------------------------------------------------------- */

	/**
	 * Register the 4 dedicated user roles with sensible default capabilities.
	 *
	 * @return void
	 */
	private function register_roles() {
		// Admin role — full plugin control, no WP super-admin powers.
		add_role(
			'make_school_admin',
			__( 'School Admin', 'make-school' ),
			array(
				'read'                       => true,
				'upload_files'               => true,
				'make_school_manage_school'  => true,
				'make_school_manage_fees'    => true,
				'make_school_manage_exams'   => true,
				'make_school_view_reports'   => true,
			)
		);

		// Teacher role — attendance, marks entry, LMS distribution.
		add_role(
			'make_school_teacher',
			__( 'Teacher', 'make-school' ),
			array(
				'read'                          => true,
				'upload_files'                  => true,
				'make_school_take_attendance'   => true,
				'make_school_enter_marks'       => true,
				'make_school_publish_lessons'   => true,
			)
		);

		// Student role — read-only access to own ledger, attendance, LMS.
		add_role(
			'make_school_student',
			__( 'Student', 'make-school' ),
			array(
				'read'                          => true,
				'make_school_view_own_data'     => true,
			)
		);

		// Parent role — read-only access to mapped child's data.
		add_role(
			'make_school_parent',
			__( 'Parent', 'make-school' ),
			array(
				'read'                          => true,
				'make_school_view_child_data'   => true,
			)
		);

		// Mirror the management capabilities onto the WordPress administrator
		// role so a site owner can always reach the plugin's admin screens.
		$wp_admin = get_role( 'administrator' );
		if ( $wp_admin instanceof WP_Role ) {
			$wp_admin->add_cap( 'make_school_manage_school' );
			$wp_admin->add_cap( 'make_school_manage_fees' );
			$wp_admin->add_cap( 'make_school_manage_exams' );
			$wp_admin->add_cap( 'make_school_view_reports' );
		}
	}

	/**
	 * Seed default options if absent. Uses add_option() so existing values
	 * are never overwritten on re-activation.
	 *
	 * @return void
	 */
	private function seed_default_options() {
		add_option( 'make_school_db_version', MAKE_SCHOOL_DB_VERSION );
		add_option( 'make_school_current_session', '2026-2027' );
		add_option(
			'make_school_sessions',
			array( '2025-2026', '2026-2027', '2027-2028' )
		);
		add_option(
			'make_school_settings',
			array(
				'currency_symbol'      => '$',
				'roll_number_prefix'   => 'MS',
				'enable_email_notify'  => 1,
				'default_pass_mark'    => 35,
			)
		);
	}
}

/**
 * Bootstrap accessor.
 *
 * @return Make_School_Plugin
 */
function make_school() {
	return Make_School_Plugin::instance();
}

// Kick off the plugin.
make_school();
