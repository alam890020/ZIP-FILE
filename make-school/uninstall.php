<?php
/**
 * MAKE SCHOOL — uninstall handler.
 *
 * Runs only when the user removes the plugin from the WordPress
 * "Plugins" screen. Activation/deactivation does NOT trigger this file,
 * so installation data is preserved across normal lifecycle events.
 *
 * @package MakeSchool
 */

// Bail if WordPress did not invoke us through the official uninstall path.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Only an authenticated administrator may purge plugin data.
if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}

global $wpdb;

// Drop custom tables.
$tables = array(
	$wpdb->prefix . 'make_school_branches',
	$wpdb->prefix . 'make_school_classes',
	$wpdb->prefix . 'make_school_admissions',
	$wpdb->prefix . 'make_school_invoices',
	$wpdb->prefix . 'make_school_attendance',
	$wpdb->prefix . 'make_school_fee_types',
	$wpdb->prefix . 'make_school_exams',
	$wpdb->prefix . 'make_school_marks',
	$wpdb->prefix . 'make_school_lessons',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

// Remove plugin options.
delete_option( 'make_school_db_version' );
delete_option( 'make_school_current_session' );
delete_option( 'make_school_sessions' );
delete_option( 'make_school_settings' );

// Remove the four custom roles introduced by the plugin.
$roles_to_remove = array(
	'make_school_admin',
	'make_school_teacher',
	'make_school_student',
	'make_school_parent',
);

foreach ( $roles_to_remove as $role ) {
	if ( get_role( $role ) ) {
		remove_role( $role );
	}
}

// Strip plugin capabilities from the WordPress administrator role.
$wp_admin = get_role( 'administrator' );
if ( $wp_admin instanceof WP_Role ) {
	$wp_admin->remove_cap( 'make_school_manage_school' );
	$wp_admin->remove_cap( 'make_school_manage_fees' );
	$wp_admin->remove_cap( 'make_school_manage_exams' );
	$wp_admin->remove_cap( 'make_school_view_reports' );
}
