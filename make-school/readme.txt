=== MAKE SCHOOL ===
Contributors: makeschool
Tags: school, education, lms, attendance, fees, admission, report card
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise-grade School Management System for WordPress with multi-branch,
admissions, fees, attendance, exams, report cards and a built-in LMS.

== Description ==

MAKE SCHOOL turns any WordPress site into a full school management portal:

* Multi-branch / multi-institute architecture
* Academic-session switching (e.g. 2026-2027)
* Frontend admission pipeline with document upload
* Custom login portals with role-based redirection
* Fee configuration, bulk invoice generation and printable receipts
* Teacher attendance grid with AJAX student roster
* Exams, marks entry, automatic grading and printable report cards
* Built-in LMS — distribute YouTube lessons and PDF study materials

This release ships the foundation:

* Plugin bootstrapping
* `dbDelta()` schema for the 5 core tables
* The four dedicated user roles (admin, teacher, student, parent)
* Default plugin options and capability mapping

== Installation ==

1. Upload the `make-school` folder to `/wp-content/plugins/`.
2. Activate "MAKE SCHOOL" through the Plugins screen.
3. Custom tables and roles are created automatically on activation.

== Changelog ==

= 1.0.0 =
* Initial scaffold: main bootstrap, DB helper, roles, capabilities, options.
