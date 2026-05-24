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
require_once MAKE_SCHOOL_INCLUDES_DIR . 'class-make-school-helpers.php';
require_once MAKE_SCHOOL_INCLUDES_DIR . 'class-make-school-login.php';
require_once MAKE_SCHOOL_INCLUDES_DIR . 'class-make-school-admin.php';
require_once MAKE_SCHOOL_INCLUDES_DIR . 'class-make-school-admissions.php';
require_once MAKE_SCHOOL_INCLUDES_DIR . 'class-make-school-dashboards.php';
require_once MAKE_SCHOOL_INCLUDES_DIR . 'class-make-school-attendance.php';
require_once MAKE_SCHOOL_INCLUDES_DIR . 'class-make-school-fees.php';
require_once MAKE_SCHOOL_INCLUDES_DIR . 'class-make-school-exams.php';
require_once MAKE_SCHOOL_INCLUDES_DIR . 'class-make-school-lms.php';
require_once MAKE_SCHOOL_INCLUDES_DIR . 'class-make-school-ajax.php';
require_once MAKE_SCHOOL_INCLUDES_DIR . 'class-make-school-assets.php';

/**
 * Main plugin class — singleton orchestrator.
 *
 * Wires lifecycle hooks (activation / deactivation), bootstraps all
 * sub-modules at runtime, registers the four custom user roles, and
 * seeds the default options used across the plugin.
 */
final class Make_School_Plugin {

	/** @var Make_School_Plugin|null */
	private static $instance = null;

	/** @var Make_School_DB */
	public $db;

	/** @var Make_School_Login */
	public $login;

	/** @var Make_School_Admin */
	public $admin;

	/** @var Make_School_Admissions */
	public $admissions;

	/** @var Make_School_Dashboards */
	public $dashboards;

	/** @var Make_School_Attendance */
	public $attendance;

	/** @var Make_School_Fees */
	public $fees;

	/** @var Make_School_Exams */
	public $exams;

	/** @var Make_School_LMS */
	public $lms;

	/** @var Make_School_Ajax */
	public $ajax;

	/** @var Make_School_Assets */
	public $assets;

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

		// Bootstrap sub-modules. Each module attaches its own hooks
		// in its constructor — no global state outside of these objects.
		$this->login      = new Make_School_Login();
		$this->admin      = new Make_School_Admin();
		$this->admissions = new Make_School_Admissions();
		$this->dashboards = new Make_School_Dashboards();
		$this->attendance = new Make_School_Attendance();
		$this->fees       = new Make_School_Fees();
		$this->exams      = new Make_School_Exams();
		$this->lms        = new Make_School_LMS();
		$this->ajax       = new Make_School_Ajax();
		$this->assets     = new Make_School_Assets();

		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		add_action( 'init', array( $this, 'on_init' ) );
	}

	/** Block cloning. */
	private function __clone() {}

	/** Block unserialization. */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/* ---------------------------------------------------------------------
	 * Lifecycle.
	 * ------------------------------------------------------------------- */

	/**
	 * Activation handler — installs schema, registers roles, seeds options.
	 *
	 * @return void
	 */
	public function on_activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$this->db->install();
		$this->register_roles();
		$this->seed_default_options();
		$this->seed_default_fee_types();

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

		wp_clear_scheduled_hook( 'make_school_daily_cron' );
		flush_rewrite_rules();
	}

	/* ---------------------------------------------------------------------
	 * Runtime.
	 * ------------------------------------------------------------------- */

	/**
	 * plugins_loaded — translations + in-place schema upgrade.
	 *
	 * @return void
	 */
	public function on_plugins_loaded() {
		load_plugin_textdomain(
			MAKE_SCHOOL_TEXT_DOMAIN,
			false,
			dirname( MAKE_SCHOOL_PLUGIN_BASENAME ) . '/languages'
		);

		$installed = get_option( 'make_school_db_version' );
		if ( $installed !== MAKE_SCHOOL_DB_VERSION ) {
			$this->db->install();
		}
	}

	/**
	 * init — fires the public 'make_school_loaded' hook.
	 *
	 * @return void
	 */
	public function on_init() {
		/**
		 * Fires when MAKE SCHOOL has finished its core boot.
		 *
		 * @since 1.0.0
		 */
		do_action( 'make_school_loaded' );
	}

	/* ---------------------------------------------------------------------
	 * Roles & options.
	 * ------------------------------------------------------------------- */

	/**
	 * Register the 4 dedicated user roles.
	 *
	 * @return void
	 */
	private function register_roles() {
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

		add_role(
			'make_school_student',
			__( 'Student', 'make-school' ),
			array(
				'read'                          => true,
				'make_school_view_own_data'     => true,
			)
		);

		add_role(
			'make_school_parent',
			__( 'Parent', 'make-school' ),
			array(
				'read'                          => true,
				'make_school_view_child_data'   => true,
			)
		);

		// Mirror management caps onto the WP administrator role.
		$wp_admin = get_role( 'administrator' );
		if ( $wp_admin instanceof WP_Role ) {
			$wp_admin->add_cap( 'make_school_manage_school' );
			$wp_admin->add_cap( 'make_school_manage_fees' );
			$wp_admin->add_cap( 'make_school_manage_exams' );
			$wp_admin->add_cap( 'make_school_view_reports' );
			$wp_admin->add_cap( 'make_school_take_attendance' );
			$wp_admin->add_cap( 'make_school_enter_marks' );
			$wp_admin->add_cap( 'make_school_publish_lessons' );
		}
	}

	/**
	 * Seed default plugin options.
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
				'currency_code'        => 'USD',
				'roll_number_prefix'   => 'MS',
				'invoice_prefix'       => 'INV',
				'enable_email_notify'  => 1,
				'default_pass_mark'    => 35,
				'school_name'          => get_bloginfo( 'name' ),
				'login_page_id'        => 0,
				'student_dashboard_page_id' => 0,
				'teacher_dashboard_page_id' => 0,
			)
		);
	}

	/**
	 * Seed a baseline of standard fee types on first activation.
	 *
	 * @return void
	 */
	private function seed_default_fee_types() {
		global $wpdb;
		$table = $this->db->table( 'fee_types' );

		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
		if ( $existing > 0 ) {
			return;
		}

		$now      = current_time( 'mysql' );
		$defaults = array(
			array( 'Tuition Fee',   'tuition',   500.00 ),
			array( 'Admission Fee', 'admission', 250.00 ),
			array( 'Transport Fee', 'transport', 100.00 ),
			array( 'Exam Fee',      'exam',       75.00 ),
		);

		foreach ( $defaults as $row ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'branch_id'      => 0,
					'name'           => $row[0],
					'slug'           => $row[1],
					'default_amount' => $row[2],
					'description'    => '',
					'status'         => 'active',
					'created_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s' )
			);
		}
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
