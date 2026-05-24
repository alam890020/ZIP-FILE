<?php
/**
 * MAKE SCHOOL — Asset registration and enqueuing.
 *
 * Registers and enqueues the plugin's CSS/JS bundles. Two scopes:
 *   - Front-end (`wp_enqueue_scripts`): pages that contain any of our
 *     shortcodes (login, admission, dashboards) get the front-end CSS;
 *     printable receipt / report-card / admit-card routes are self-styled.
 *   - Admin (`admin_enqueue_scripts`): all `make-school-*` admin pages
 *     get the admin CSS. JS is currently not required (the few inline
 *     scripts in module templates handle local interaction).
 *
 * @package MakeSchool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Make_School_Assets
 */
class Make_School_Assets {

	const HANDLE_ADMIN    = 'make-school-admin';
	const HANDLE_FRONTEND = 'make-school-frontend';

	/**
	 * Constructor — wires hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
	}

	/**
	 * Front-end enqueue.
	 *
	 * @return void
	 */
	public function enqueue_frontend() {
		// Always enqueue: the bundle is small and shortcodes are scattered
		// across various authored pages.
		wp_enqueue_style(
			self::HANDLE_FRONTEND,
			MAKE_SCHOOL_ASSETS_URL . 'css/frontend.css',
			array( 'dashicons' ),
			MAKE_SCHOOL_VERSION
		);
		wp_enqueue_script(
			self::HANDLE_FRONTEND,
			MAKE_SCHOOL_ASSETS_URL . 'js/frontend.js',
			array(),
			MAKE_SCHOOL_VERSION,
			true
		);
	}

	/**
	 * Admin enqueue.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_admin( $hook_suffix ) {
		// Only on our screens (matches "page=make-school" or "page=make-school-*").
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore
		if ( '' === $page || ( 'make-school' !== $page && 0 !== strpos( $page, 'make-school-' ) ) ) {
			unset( $hook_suffix );
			return;
		}

		wp_enqueue_style(
			self::HANDLE_ADMIN,
			MAKE_SCHOOL_ASSETS_URL . 'css/admin.css',
			array(),
			MAKE_SCHOOL_VERSION
		);
		wp_enqueue_script(
			self::HANDLE_ADMIN,
			MAKE_SCHOOL_ASSETS_URL . 'js/admin.js',
			array(),
			MAKE_SCHOOL_VERSION,
			true
		);
	}
}
