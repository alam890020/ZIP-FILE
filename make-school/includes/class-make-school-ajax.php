<?php
/**
 * MAKE SCHOOL — AJAX coordinator.
 *
 * Each module that exposes its own AJAX endpoints does so directly via
 * wp_ajax_* hooks (Attendance, for instance). This class exists as the
 * single, well-known place to hang any cross-module AJAX endpoints
 * (e.g. global search) and as the central nonce / action registry that
 * front-end JS can fetch via wp_localize_script().
 *
 * @package MakeSchool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Make_School_Ajax
 */
class Make_School_Ajax {

	/**
	 * Constructor — wires hooks.
	 */
	public function __construct() {
		// Currently a no-op. Add cross-module AJAX endpoints here as needed.
	}

	/**
	 * The map of nonce-action labels keyed by feature.
	 *
	 * @return array<string,string>
	 */
	public static function nonce_actions() {
		return array(
			'attendance' => 'make_school_attendance',
			'admin'      => 'make_school_admin',
			'login'      => 'make_school_login',
			'admission'  => 'make_school_admission_form',
			'fees'       => 'make_school_fees',
			'exams'      => 'make_school_exams',
			'lms'        => 'make_school_lms',
		);
	}
}
