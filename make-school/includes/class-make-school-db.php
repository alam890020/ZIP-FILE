<?php
/**
 * Database helper for MAKE SCHOOL.
 *
 * Encapsulates schema creation, version tracking, and table-name access
 * for all five custom tables required by the plugin:
 *
 *   1. {prefix}make_school_branches    — multi-school tracking
 *   2. {prefix}make_school_classes     — class & section routing
 *   3. {prefix}make_school_admissions  — admissions & enquiries pipeline
 *   4. {prefix}make_school_invoices    — fees & financial ledger
 *   5. {prefix}make_school_attendance  — daily attendance log
 *
 * @package MakeSchool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Make_School_DB
 *
 * All schema operations are funneled through dbDelta() so installs on
 * existing sites perform safe, additive upgrades.
 */
class Make_School_DB {

	/**
	 * Logical table key => physical suffix (without $wpdb->prefix).
	 *
	 * @var array<string,string>
	 */
	private $tables = array(
		'branches'    => 'make_school_branches',
		'classes'     => 'make_school_classes',
		'admissions'  => 'make_school_admissions',
		'invoices'    => 'make_school_invoices',
		'attendance'  => 'make_school_attendance',
		'fee_types'   => 'make_school_fee_types',
		'exams'       => 'make_school_exams',
		'marks'       => 'make_school_marks',
		'lessons'     => 'make_school_lessons',
	);

	/* ---------------------------------------------------------------------
	 * Public API.
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve a fully-qualified table name (with site prefix).
	 *
	 * @param string $key Logical key (e.g. 'branches').
	 * @return string Fully-qualified table name, or empty string on bad key.
	 */
	public function table( $key ) {
		global $wpdb;

		$key = sanitize_key( $key );
		if ( ! isset( $this->tables[ $key ] ) ) {
			return '';
		}
		return $wpdb->prefix . $this->tables[ $key ];
	}

	/**
	 * Return all logical => physical table names. Useful for admin tools
	 * and data-export utilities.
	 *
	 * @return array<string,string>
	 */
	public function all_tables() {
		global $wpdb;
		$out = array();
		foreach ( $this->tables as $key => $suffix ) {
			$out[ $key ] = $wpdb->prefix . $suffix;
		}
		return $out;
	}

	/**
	 * Install or upgrade all tables using dbDelta().
	 *
	 * @return void
	 */
	public function install() {
		global $wpdb;

		// dbDelta() lives in upgrade.php and is not autoloaded.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		dbDelta( $this->schema_branches( $charset_collate ) );
		dbDelta( $this->schema_classes( $charset_collate ) );
		dbDelta( $this->schema_admissions( $charset_collate ) );
		dbDelta( $this->schema_invoices( $charset_collate ) );
		dbDelta( $this->schema_attendance( $charset_collate ) );
		dbDelta( $this->schema_fee_types( $charset_collate ) );
		dbDelta( $this->schema_exams( $charset_collate ) );
		dbDelta( $this->schema_marks( $charset_collate ) );
		dbDelta( $this->schema_lessons( $charset_collate ) );

		// Persist the DB version so future runs can short-circuit.
		update_option( 'make_school_db_version', MAKE_SCHOOL_DB_VERSION );
	}

	/**
	 * Drop all plugin tables. NOT called automatically — reserved for an
	 * explicit uninstall.php routine that the site owner opts into.
	 *
	 * @return void
	 */
	public function drop_all() {
		global $wpdb;
		foreach ( $this->all_tables() as $table ) {
			// Table names cannot be parameterised with prepare(); they have
			// already been built from a static whitelist above so this is safe.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}
		delete_option( 'make_school_db_version' );
	}

	/* ---------------------------------------------------------------------
	 * Schema definitions.
	 *
	 * IMPORTANT — dbDelta() is whitespace- and syntax-sensitive:
	 *   • Each column on its own line.
	 *   • Two spaces between PRIMARY KEY and the column list.
	 *   • Column types lowercase, KEY definitions uppercase.
	 *
	 * @link https://developer.wordpress.org/reference/functions/dbdelta/
	 * ------------------------------------------------------------------- */

	/**
	 * Branches — physical school sites / institutes under one WordPress.
	 *
	 * @param string $charset_collate Charset/collate clause.
	 * @return string SQL.
	 */
	private function schema_branches( $charset_collate ) {
		$table = $this->table( 'branches' );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			code VARCHAR(40) NOT NULL,
			email VARCHAR(190) DEFAULT '' NOT NULL,
			phone VARCHAR(40) DEFAULT '' NOT NULL,
			address TEXT NULL,
			city VARCHAR(120) DEFAULT '' NOT NULL,
			state VARCHAR(120) DEFAULT '' NOT NULL,
			country VARCHAR(120) DEFAULT '' NOT NULL,
			zip VARCHAR(40) DEFAULT '' NOT NULL,
			logo_url VARCHAR(255) DEFAULT '' NOT NULL,
			session VARCHAR(20) DEFAULT '' NOT NULL,
			status VARCHAR(20) DEFAULT 'active' NOT NULL,
			created_by BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			updated_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY status (status),
			KEY session (session)
		) {$charset_collate};";
	}

	/**
	 * Classes — every class+section combination, scoped to a branch & session.
	 *
	 * @param string $charset_collate Charset/collate clause.
	 * @return string SQL.
	 */
	private function schema_classes( $charset_collate ) {
		$table = $this->table( 'classes' );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			branch_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			session VARCHAR(20) DEFAULT '' NOT NULL,
			class_name VARCHAR(120) NOT NULL,
			section VARCHAR(40) DEFAULT '' NOT NULL,
			class_teacher_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			room_no VARCHAR(40) DEFAULT '' NOT NULL,
			capacity INT(11) DEFAULT 0 NOT NULL,
			status VARCHAR(20) DEFAULT 'active' NOT NULL,
			created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			updated_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			KEY branch_id (branch_id),
			KEY session (session),
			KEY class_teacher_id (class_teacher_id),
			KEY class_section (class_name,section)
		) {$charset_collate};";
	}

	/**
	 * Admissions — frontend application + post-approval enrolment record.
	 *
	 * @param string $charset_collate Charset/collate clause.
	 * @return string SQL.
	 */
	private function schema_admissions( $charset_collate ) {
		$table = $this->table( 'admissions' );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			branch_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			session VARCHAR(20) DEFAULT '' NOT NULL,
			class_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			roll_number VARCHAR(40) DEFAULT '' NOT NULL,
			full_name VARCHAR(190) NOT NULL,
			dob DATE DEFAULT NULL,
			gender VARCHAR(20) DEFAULT '' NOT NULL,
			blood_group VARCHAR(10) DEFAULT '' NOT NULL,
			father_name VARCHAR(190) DEFAULT '' NOT NULL,
			mother_name VARCHAR(190) DEFAULT '' NOT NULL,
			email VARCHAR(190) DEFAULT '' NOT NULL,
			mobile VARCHAR(40) DEFAULT '' NOT NULL,
			address TEXT NULL,
			photo_url VARCHAR(255) DEFAULT '' NOT NULL,
			document_url VARCHAR(255) DEFAULT '' NOT NULL,
			notes TEXT NULL,
			status VARCHAR(20) DEFAULT 'pending' NOT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			reviewed_by BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			reviewed_at DATETIME DEFAULT NULL,
			created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			updated_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY roll_number (roll_number),
			KEY status (status),
			KEY branch_id (branch_id),
			KEY session (session),
			KEY class_id (class_id),
			KEY user_id (user_id),
			KEY email (email)
		) {$charset_collate};";
	}

	/**
	 * Invoices — financial ledger driving the fee module.
	 *
	 * @param string $charset_collate Charset/collate clause.
	 * @return string SQL.
	 */
	private function schema_invoices( $charset_collate ) {
		$table = $this->table( 'invoices' );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_no VARCHAR(40) NOT NULL,
			branch_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			session VARCHAR(20) DEFAULT '' NOT NULL,
			class_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			student_user_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			fee_type VARCHAR(60) DEFAULT '' NOT NULL,
			description TEXT NULL,
			amount DECIMAL(12,2) DEFAULT 0.00 NOT NULL,
			discount DECIMAL(12,2) DEFAULT 0.00 NOT NULL,
			tax DECIMAL(12,2) DEFAULT 0.00 NOT NULL,
			amount_paid DECIMAL(12,2) DEFAULT 0.00 NOT NULL,
			currency VARCHAR(10) DEFAULT 'USD' NOT NULL,
			due_date DATE DEFAULT NULL,
			paid_at DATETIME DEFAULT NULL,
			payment_method VARCHAR(40) DEFAULT '' NOT NULL,
			payment_ref VARCHAR(120) DEFAULT '' NOT NULL,
			status VARCHAR(20) DEFAULT 'unpaid' NOT NULL,
			created_by BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			updated_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY invoice_no (invoice_no),
			KEY status (status),
			KEY student_user_id (student_user_id),
			KEY class_id (class_id),
			KEY branch_id (branch_id),
			KEY session (session),
			KEY due_date (due_date)
		) {$charset_collate};";
	}

	/**
	 * Attendance — one row per (student, class, date).
	 *
	 * @param string $charset_collate Charset/collate clause.
	 * @return string SQL.
	 */
	private function schema_attendance( $charset_collate ) {
		$table = $this->table( 'attendance' );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			branch_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			session VARCHAR(20) DEFAULT '' NOT NULL,
			class_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			student_user_id BIGINT(20) UNSIGNED NOT NULL,
			attendance_date DATE NOT NULL,
			status VARCHAR(20) DEFAULT 'present' NOT NULL,
			remarks VARCHAR(255) DEFAULT '' NOT NULL,
			marked_by BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			updated_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY student_class_date (student_user_id,class_id,attendance_date),
			KEY class_id (class_id),
			KEY attendance_date (attendance_date),
			KEY status (status),
			KEY branch_id (branch_id),
			KEY session (session)
		) {$charset_collate};";
	}

	/**
	 * Fee types — configurable fee buckets (Tuition, Admission, Transport, Exam, etc.).
	 *
	 * @param string $charset_collate Charset/collate clause.
	 * @return string SQL.
	 */
	private function schema_fee_types( $charset_collate ) {
		$table = $this->table( 'fee_types' );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			branch_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			name VARCHAR(120) NOT NULL,
			slug VARCHAR(120) NOT NULL,
			default_amount DECIMAL(12,2) DEFAULT 0.00 NOT NULL,
			description TEXT NULL,
			status VARCHAR(20) DEFAULT 'active' NOT NULL,
			created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			updated_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			KEY slug (slug),
			KEY branch_id (branch_id),
			KEY status (status)
		) {$charset_collate};";
	}

	/**
	 * Exams — exam terms scoped to branch / session / class.
	 *
	 * @param string $charset_collate Charset/collate clause.
	 * @return string SQL.
	 */
	private function schema_exams( $charset_collate ) {
		$table = $this->table( 'exams' );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			branch_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			session VARCHAR(20) DEFAULT '' NOT NULL,
			class_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			name VARCHAR(190) NOT NULL,
			subjects TEXT NULL,
			start_date DATE DEFAULT NULL,
			end_date DATE DEFAULT NULL,
			start_time VARCHAR(20) DEFAULT '' NOT NULL,
			end_time VARCHAR(20) DEFAULT '' NOT NULL,
			max_marks INT(11) DEFAULT 100 NOT NULL,
			pass_marks INT(11) DEFAULT 35 NOT NULL,
			venue VARCHAR(190) DEFAULT '' NOT NULL,
			notes TEXT NULL,
			status VARCHAR(20) DEFAULT 'scheduled' NOT NULL,
			published TINYINT(1) DEFAULT 0 NOT NULL,
			created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			updated_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			KEY branch_id (branch_id),
			KEY session (session),
			KEY class_id (class_id),
			KEY status (status)
		) {$charset_collate};";
	}

	/**
	 * Marks — one row per (exam, student, subject).
	 *
	 * @param string $charset_collate Charset/collate clause.
	 * @return string SQL.
	 */
	private function schema_marks( $charset_collate ) {
		$table = $this->table( 'marks' );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			exam_id BIGINT(20) UNSIGNED NOT NULL,
			student_user_id BIGINT(20) UNSIGNED NOT NULL,
			subject VARCHAR(120) NOT NULL,
			marks_obtained DECIMAL(6,2) DEFAULT 0.00 NOT NULL,
			max_marks DECIMAL(6,2) DEFAULT 100.00 NOT NULL,
			grade VARCHAR(10) DEFAULT '' NOT NULL,
			remarks VARCHAR(255) DEFAULT '' NOT NULL,
			entered_by BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			updated_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY exam_student_subject (exam_id,student_user_id,subject),
			KEY exam_id (exam_id),
			KEY student_user_id (student_user_id)
		) {$charset_collate};";
	}

	/**
	 * Lessons — LMS content (YouTube videos + PDF study materials).
	 *
	 * @param string $charset_collate Charset/collate clause.
	 * @return string SQL.
	 */
	private function schema_lessons( $charset_collate ) {
		$table = $this->table( 'lessons' );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			branch_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			session VARCHAR(20) DEFAULT '' NOT NULL,
			class_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			subject VARCHAR(120) DEFAULT '' NOT NULL,
			title VARCHAR(190) NOT NULL,
			type VARCHAR(20) DEFAULT 'video' NOT NULL,
			youtube_id VARCHAR(40) DEFAULT '' NOT NULL,
			file_url VARCHAR(255) DEFAULT '' NOT NULL,
			description TEXT NULL,
			posted_by BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			status VARCHAR(20) DEFAULT 'published' NOT NULL,
			created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			updated_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			KEY branch_id (branch_id),
			KEY session (session),
			KEY class_id (class_id),
			KEY subject (subject),
			KEY type (type),
			KEY status (status)
		) {$charset_collate};";
	}
}
