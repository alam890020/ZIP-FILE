<?php
/**
 * Static helpers for MAKE SCHOOL.
 *
 * Centralises formatting, ID generation, role/branch lookups, and other
 * cross-module utilities. Every method is pure where possible and
 * side-effect free (the only DB writes happen in the dedicated generators).
 *
 * @package MakeSchool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Make_School_Helpers
 */
class Make_School_Helpers {

	/* ---------------------------------------------------------------------
	 * Plugin & db accessors.
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve a fully-qualified table name through the DB helper.
	 *
	 * @param string $key Logical key.
	 * @return string
	 */
	public static function table( $key ) {
		return make_school()->db->table( $key );
	}

	/* ---------------------------------------------------------------------
	 * Settings & sessions.
	 * ------------------------------------------------------------------- */

	/**
	 * Read the merged plugin settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$defaults = array(
			'currency_symbol'           => '$',
			'currency_code'             => 'USD',
			'roll_number_prefix'        => 'MS',
			'invoice_prefix'            => 'INV',
			'enable_email_notify'       => 1,
			'default_pass_mark'         => 35,
			'school_name'               => get_bloginfo( 'name' ),
			'login_page_id'             => 0,
			'student_dashboard_page_id' => 0,
			'teacher_dashboard_page_id' => 0,
		);
		$stored = get_option( 'make_school_settings', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( $defaults, $stored );
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function setting( $key, $default = '' ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : $default;
	}

	/**
	 * Currently selected academic session.
	 *
	 * @return string
	 */
	public static function current_session() {
		$s = get_option( 'make_school_current_session', '' );
		return is_string( $s ) ? sanitize_text_field( $s ) : '';
	}

	/**
	 * All defined sessions.
	 *
	 * @return string[]
	 */
	public static function sessions() {
		$list = get_option( 'make_school_sessions', array() );
		if ( ! is_array( $list ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_text_field', $list ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Formatting.
	 * ------------------------------------------------------------------- */

	/**
	 * Format an amount with the configured currency symbol.
	 *
	 * @param float|string $amount Amount.
	 * @return string
	 */
	public static function format_currency( $amount ) {
		$symbol = self::setting( 'currency_symbol', '$' );
		return $symbol . number_format( (float) $amount, 2 );
	}

	/**
	 * Format a date in the site's date format.
	 *
	 * @param string $datetime MySQL-compatible datetime.
	 * @return string
	 */
	public static function format_date( $datetime ) {
		if ( empty( $datetime ) || '0000-00-00' === substr( (string) $datetime, 0, 10 ) ) {
			return '—';
		}
		$ts = strtotime( $datetime );
		if ( false === $ts ) {
			return '—';
		}
		return date_i18n( get_option( 'date_format', 'Y-m-d' ), $ts );
	}

	/**
	 * Format a date+time.
	 *
	 * @param string $datetime MySQL datetime.
	 * @return string
	 */
	public static function format_datetime( $datetime ) {
		if ( empty( $datetime ) || '0000-00-00 00:00:00' === $datetime ) {
			return '—';
		}
		$ts = strtotime( $datetime );
		if ( false === $ts ) {
			return '—';
		}
		return date_i18n( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), $ts );
	}

	/**
	 * Pretty status label.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$status = (string) $status;
		$labels = array(
			'pending'        => __( 'Pending', 'make-school' ),
			'approved'       => __( 'Approved', 'make-school' ),
			'rejected'       => __( 'Rejected', 'make-school' ),
			'active'         => __( 'Active', 'make-school' ),
			'inactive'       => __( 'Inactive', 'make-school' ),
			'paid'           => __( 'Paid', 'make-school' ),
			'unpaid'         => __( 'Unpaid', 'make-school' ),
			'partially_paid' => __( 'Partially Paid', 'make-school' ),
			'present'        => __( 'Present', 'make-school' ),
			'absent'         => __( 'Absent', 'make-school' ),
			'late'           => __( 'Late', 'make-school' ),
			'scheduled'      => __( 'Scheduled', 'make-school' ),
			'completed'      => __( 'Completed', 'make-school' ),
			'published'      => __( 'Published', 'make-school' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucwords( str_replace( '_', ' ', $status ) );
	}

	/* ---------------------------------------------------------------------
	 * ID generation.
	 * ------------------------------------------------------------------- */

	/**
	 * Generate a unique roll / enrolment number.
	 *
	 * Pattern: <PREFIX><YY><BRANCH-PADDED><SEQ-PADDED>, e.g. MS26010042.
	 *
	 * @param int    $branch_id Branch ID (0 if none).
	 * @param string $session   Session string e.g. "2026-2027".
	 * @return string
	 */
	public static function generate_roll_number( $branch_id = 0, $session = '' ) {
		global $wpdb;
		$table  = self::table( 'admissions' );
		$prefix = (string) self::setting( 'roll_number_prefix', 'MS' );
		$year   = $session ? substr( $session, 2, 2 ) : substr( gmdate( 'Y' ), -2 );
		$branch = str_pad( (string) absint( $branch_id ), 2, '0', STR_PAD_LEFT );

		// Pull the largest existing sequence for this prefix/year/branch.
		$pattern = $wpdb->esc_like( $prefix . $year . $branch ) . '%';
		$last    = $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT roll_number FROM {$table} WHERE roll_number LIKE %s ORDER BY id DESC LIMIT 1",
				$pattern
			)
		);

		$seq = 1;
		if ( $last ) {
			$tail = (int) substr( (string) $last, strlen( $prefix . $year . $branch ) );
			$seq  = $tail + 1;
		}
		return $prefix . $year . $branch . str_pad( (string) $seq, 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Generate a unique invoice number.
	 *
	 * Pattern: <PREFIX>-<YYYYMM>-<SEQ>.
	 *
	 * @return string
	 */
	public static function generate_invoice_number() {
		global $wpdb;
		$table  = self::table( 'invoices' );
		$prefix = (string) self::setting( 'invoice_prefix', 'INV' );
		$ym     = gmdate( 'Ym' );

		$pattern = $wpdb->esc_like( $prefix . '-' . $ym . '-' ) . '%';
		$last    = $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT invoice_no FROM {$table} WHERE invoice_no LIKE %s ORDER BY id DESC LIMIT 1",
				$pattern
			)
		);

		$seq = 1;
		if ( $last ) {
			$parts = explode( '-', (string) $last );
			$seq   = (int) end( $parts ) + 1;
		}
		return $prefix . '-' . $ym . '-' . str_pad( (string) $seq, 5, '0', STR_PAD_LEFT );
	}

	/* ---------------------------------------------------------------------
	 * Branches.
	 * ------------------------------------------------------------------- */

	/**
	 * Fetch all branches.
	 *
	 * @param string $status Filter by status, '' for all.
	 * @return array<int,object>
	 */
	public static function get_branches( $status = 'active' ) {
		global $wpdb;
		$table = self::table( 'branches' );
		if ( $status ) {
			return $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY name ASC", $status )
			);
		}
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" ); // phpcs:ignore
	}

	/**
	 * Fetch a single branch.
	 *
	 * @param int $id Branch ID.
	 * @return object|null
	 */
	public static function get_branch( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		$table = self::table( 'branches' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
	}

	/* ---------------------------------------------------------------------
	 * Classes.
	 * ------------------------------------------------------------------- */

	/**
	 * Fetch all classes, optionally scoped by branch / session.
	 *
	 * @param int    $branch_id Branch filter (0 = any).
	 * @param string $session   Session filter ('' = any).
	 * @return array<int,object>
	 */
	public static function get_classes( $branch_id = 0, $session = '' ) {
		global $wpdb;
		$table = self::table( 'classes' );
		$where = array( "status = 'active'" );
		$args  = array();
		if ( $branch_id ) {
			$where[] = 'branch_id = %d';
			$args[]  = absint( $branch_id );
		}
		if ( $session ) {
			$where[] = 'session = %s';
			$args[]  = $session;
		}
		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY class_name ASC, section ASC';
		if ( $args ) {
			return $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
		}
		return $wpdb->get_results( $sql ); // phpcs:ignore
	}

	/**
	 * Fetch a single class.
	 *
	 * @param int $id Class ID.
	 * @return object|null
	 */
	public static function get_class( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		$table = self::table( 'classes' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
	}

	/**
	 * Human-readable label for a class row.
	 *
	 * @param object|null $class Class row.
	 * @return string
	 */
	public static function class_label( $class ) {
		if ( ! $class ) {
			return '—';
		}
		$label = (string) $class->class_name;
		if ( ! empty( $class->section ) ) {
			$label .= ' — ' . $class->section;
		}
		return $label;
	}

	/**
	 * Classes assigned to a given teacher (as class teacher).
	 *
	 * @param int $teacher_user_id User ID.
	 * @return array<int,object>
	 */
	public static function teacher_classes( $teacher_user_id ) {
		global $wpdb;
		$teacher_user_id = absint( $teacher_user_id );
		if ( ! $teacher_user_id ) {
			return array();
		}
		$table = self::table( 'classes' );
		return $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE class_teacher_id = %d AND status = 'active' ORDER BY class_name ASC, section ASC",
				$teacher_user_id
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Roles & users.
	 * ------------------------------------------------------------------- */

	/**
	 * Returns true if the user has any of the given roles.
	 *
	 * @param int|WP_User $user  User or ID.
	 * @param string[]    $roles Roles to test.
	 * @return bool
	 */
	public static function user_has_role( $user, array $roles ) {
		if ( is_numeric( $user ) ) {
			$user = get_user_by( 'id', (int) $user );
		}
		if ( ! ( $user instanceof WP_User ) ) {
			return false;
		}
		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	/**
	 * Convenience role tests for the current user.
	 *
	 * @return bool
	 */
	public static function is_make_school_admin() {
		return is_user_logged_in() && self::user_has_role( wp_get_current_user(), array( 'make_school_admin', 'administrator' ) );
	}

	/** @return bool */
	public static function is_teacher() {
		return is_user_logged_in() && self::user_has_role( wp_get_current_user(), array( 'make_school_teacher' ) );
	}

	/** @return bool */
	public static function is_student() {
		return is_user_logged_in() && self::user_has_role( wp_get_current_user(), array( 'make_school_student' ) );
	}

	/** @return bool */
	public static function is_parent() {
		return is_user_logged_in() && self::user_has_role( wp_get_current_user(), array( 'make_school_parent' ) );
	}

	/**
	 * Resolve the student "subject" of the dashboard for the current user.
	 *
	 * Students see their own data; parents see the user-id stored against
	 * their account in user-meta `make_school_child_user_id`.
	 *
	 * @return int Student WP user-id, 0 on miss.
	 */
	public static function dashboard_student_id() {
		if ( ! is_user_logged_in() ) {
			return 0;
		}
		$user = wp_get_current_user();
		if ( self::user_has_role( $user, array( 'make_school_student' ) ) ) {
			return (int) $user->ID;
		}
		if ( self::user_has_role( $user, array( 'make_school_parent' ) ) ) {
			return (int) get_user_meta( $user->ID, 'make_school_child_user_id', true );
		}
		return 0;
	}

	/**
	 * Pull the admission row associated with a student WP user.
	 *
	 * @param int $user_id WP user ID.
	 * @return object|null
	 */
	public static function admission_for_user( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return null;
		}
		$table = self::table( 'admissions' );
		return $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND status = 'approved' LIMIT 1",
				$user_id
			)
		);
	}

	/**
	 * List students mapped to a class (via approved admissions).
	 *
	 * @param int $class_id Class ID.
	 * @return array<int,object>
	 */
	public static function students_in_class( $class_id ) {
		global $wpdb;
		$class_id = absint( $class_id );
		if ( ! $class_id ) {
			return array();
		}
		$table = self::table( 'admissions' );
		return $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id, user_id, full_name, roll_number, email, mobile FROM {$table}
				 WHERE class_id = %d AND status = 'approved' AND user_id > 0
				 ORDER BY roll_number ASC, full_name ASC",
				$class_id
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Fee types.
	 * ------------------------------------------------------------------- */

	/**
	 * Fetch all active fee types.
	 *
	 * @param int $branch_id Optional branch filter (0 = all incl. global).
	 * @return array<int,object>
	 */
	public static function get_fee_types( $branch_id = 0 ) {
		global $wpdb;
		$table = self::table( 'fee_types' );
		if ( $branch_id ) {
			return $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = 'active' AND (branch_id = 0 OR branch_id = %d) ORDER BY name ASC",
					absint( $branch_id )
				)
			);
		}
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' ORDER BY name ASC" ); // phpcs:ignore
	}

	/* ---------------------------------------------------------------------
	 * Grading.
	 * ------------------------------------------------------------------- */

	/**
	 * Map a percentage to a letter grade.
	 *
	 * @param float $percent Percent 0..100.
	 * @return string
	 */
	public static function compute_grade( $percent ) {
		$percent = (float) $percent;
		if ( $percent >= 90 ) {
			return 'A+';
		}
		if ( $percent >= 80 ) {
			return 'A';
		}
		if ( $percent >= 70 ) {
			return 'B+';
		}
		if ( $percent >= 60 ) {
			return 'B';
		}
		if ( $percent >= 50 ) {
			return 'C';
		}
		if ( $percent >= (float) self::setting( 'default_pass_mark', 35 ) ) {
			return 'D';
		}
		return 'F';
	}

	/* ---------------------------------------------------------------------
	 * Misc.
	 * ------------------------------------------------------------------- */

	/**
	 * Extract a YouTube video ID from any common URL shape.
	 *
	 * @param string $url URL or raw ID.
	 * @return string ID, or '' if not detected.
	 */
	public static function youtube_id( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '~^[A-Za-z0-9_-]{11}$~', $url ) ) {
			return $url;
		}
		if ( preg_match( '~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|v/|shorts/))([A-Za-z0-9_-]{11})~', $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Resolve the URL of the configured student dashboard page (or a sane fallback).
	 *
	 * @return string
	 */
	public static function student_dashboard_url() {
		$id = (int) self::setting( 'student_dashboard_page_id', 0 );
		if ( $id ) {
			$link = get_permalink( $id );
			if ( $link ) {
				return $link;
			}
		}
		return home_url( '/student-dashboard/' );
	}

	/**
	 * Resolve the URL of the configured teacher dashboard page.
	 *
	 * @return string
	 */
	public static function teacher_dashboard_url() {
		$id = (int) self::setting( 'teacher_dashboard_page_id', 0 );
		if ( $id ) {
			$link = get_permalink( $id );
			if ( $link ) {
				return $link;
			}
		}
		return home_url( '/teacher-dashboard/' );
	}

	/**
	 * Resolve the URL of the configured login page (or a fallback).
	 *
	 * @return string
	 */
	public static function login_url() {
		$id = (int) self::setting( 'login_page_id', 0 );
		if ( $id ) {
			$link = get_permalink( $id );
			if ( $link ) {
				return $link;
			}
		}
		return wp_login_url();
	}

	/**
	 * Render an admin "notice" once after a redirect.
	 *
	 * @param string $message HTML-ready message.
	 * @param string $type    notice type.
	 * @return string
	 */
	public static function notice( $message, $type = 'success' ) {
		$type = in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info';
		return '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
	}
}
