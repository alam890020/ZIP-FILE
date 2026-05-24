<?php
/**
 * MAKE SCHOOL — Frontend Dashboards.
 *
 * Owns the role-aware dashboard shells:
 *   - [make_school_student_dashboard] — students AND parents (parents see
 *     the data of the user mapped via user_meta `make_school_child_user_id`).
 *   - [make_school_teacher_dashboard] — teachers.
 *
 * Each dashboard is tab-style, navigated via the `section` query string.
 * Sections delegate rendering to the respective sibling modules
 * (Attendance, Fees, Exams, LMS) which expose `render_student_*` or
 * `render_teacher_*` methods.
 *
 * @package MakeSchool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Make_School_Dashboards
 */
class Make_School_Dashboards {

	/**
	 * Constructor — wires shortcodes.
	 */
	public function __construct() {
		add_shortcode( 'make_school_student_dashboard', array( $this, 'render_student_dashboard' ) );
		add_shortcode( 'make_school_teacher_dashboard', array( $this, 'render_teacher_dashboard' ) );
	}

	/* =====================================================================
	 * STUDENT / PARENT DASHBOARD
	 * ================================================================== */

	/**
	 * Render the student/parent dashboard.
	 *
	 * @return string HTML.
	 */
	public function render_student_dashboard() {
		if ( ! is_user_logged_in() ) {
			return $this->render_login_required();
		}
		if ( ! ( Make_School_Helpers::is_student() || Make_School_Helpers::is_parent() || Make_School_Helpers::is_make_school_admin() ) ) {
			return $this->render_access_denied();
		}

		$student_id = Make_School_Helpers::dashboard_student_id();
		// Admins viewing the page may pass ?student_id=X for a quick look.
		if ( ! $student_id && Make_School_Helpers::is_make_school_admin() && isset( $_GET['student_id'] ) ) {
			$student_id = absint( wp_unslash( $_GET['student_id'] ) );
		}
		if ( ! $student_id ) {
			return '<div class="make-school-alert make-school-alert-error">' . esc_html__( 'No student record is mapped to this account. Please contact the school administrator.', 'make-school' ) . '</div>';
		}

		$admission = Make_School_Helpers::admission_for_user( $student_id );
		if ( ! $admission ) {
			return '<div class="make-school-alert make-school-alert-error">' . esc_html__( 'No active admission found for this student.', 'make-school' ) . '</div>';
		}

		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'overview';
		$valid   = array( 'overview', 'attendance', 'fees', 'exams', 'lessons', 'profile' );
		if ( ! in_array( $section, $valid, true ) ) {
			$section = 'overview';
		}

		$tabs = array(
			'overview'   => __( 'Overview', 'make-school' ),
			'attendance' => __( 'Attendance', 'make-school' ),
			'fees'       => __( 'Fees', 'make-school' ),
			'exams'      => __( 'Exams & Marks', 'make-school' ),
			'lessons'    => __( 'Lessons', 'make-school' ),
			'profile'    => __( 'Profile', 'make-school' ),
		);

		ob_start();
		?>
		<div class="make-school-dashboard">
			<?php $this->render_header( $admission, 'student' ); ?>
			<?php $this->render_tabs( $tabs, $section ); ?>
			<div class="make-school-dashboard-body">
				<?php
				switch ( $section ) {
					case 'attendance':
						$this->call_module( make_school()->attendance, 'render_student_section', array( $student_id, $admission ) );
						break;
					case 'fees':
						$this->call_module( make_school()->fees, 'render_student_section', array( $student_id, $admission ) );
						break;
					case 'exams':
						$this->call_module( make_school()->exams, 'render_student_section', array( $student_id, $admission ) );
						break;
					case 'lessons':
						$this->call_module( make_school()->lms, 'render_student_section', array( $student_id, $admission ) );
						break;
					case 'profile':
						$this->render_student_profile( $admission );
						break;
					case 'overview':
					default:
						$this->render_student_overview( $student_id, $admission );
				}
				?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Student / parent overview — at-a-glance widgets pulling small
	 * numbers from each module so the user sees the whole picture.
	 *
	 * @param int    $student_id Student WP user id.
	 * @param object $admission  Admission row.
	 * @return void
	 */
	private function render_student_overview( $student_id, $admission ) {
		global $wpdb;

		// Attendance — last 30 days percentage.
		$attendance_table = make_school()->db->table( 'attendance' );
		$cut_off          = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$total            = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$attendance_table} WHERE student_user_id = %d AND attendance_date >= %s",
				$student_id,
				$cut_off
			)
		);
		$present = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$attendance_table} WHERE student_user_id = %d AND attendance_date >= %s AND status IN ('present','late')",
				$student_id,
				$cut_off
			)
		);
		$attendance_pct = $total > 0 ? round( ( $present / $total ) * 100, 1 ) : 0;

		// Fees — outstanding balance.
		$invoices_table = make_school()->db->table( 'invoices' );
		$outstanding    = (float) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount + tax - discount - amount_paid),0) FROM {$invoices_table}
				 WHERE student_user_id = %d AND status IN ('unpaid','partially_paid')",
				$student_id
			)
		);
		$paid_total = (float) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT COALESCE(SUM(amount_paid),0) FROM {$invoices_table} WHERE student_user_id = %d", $student_id )
		);

		// Lessons count.
		$lessons_table = make_school()->db->table( 'lessons' );
		$lesson_count  = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$lessons_table} WHERE class_id = %d AND status = 'published'",
				(int) $admission->class_id
			)
		);

		// Upcoming exams.
		$exams_table = make_school()->db->table( 'exams' );
		$upcoming    = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$exams_table} WHERE class_id = %d AND start_date >= %s AND status != 'completed'",
				(int) $admission->class_id,
				gmdate( 'Y-m-d' )
			)
		);
		?>
		<div class="make-school-kpi-grid">
			<div class="make-school-kpi"><span><?php esc_html_e( 'Attendance (30 days)', 'make-school' ); ?></span><strong><?php echo esc_html( $attendance_pct . '%' ); ?></strong></div>
			<div class="make-school-kpi"><span><?php esc_html_e( 'Outstanding fees', 'make-school' ); ?></span><strong><?php echo esc_html( Make_School_Helpers::format_currency( $outstanding ) ); ?></strong></div>
			<div class="make-school-kpi"><span><?php esc_html_e( 'Total paid', 'make-school' ); ?></span><strong><?php echo esc_html( Make_School_Helpers::format_currency( $paid_total ) ); ?></strong></div>
			<div class="make-school-kpi"><span><?php esc_html_e( 'Upcoming exams', 'make-school' ); ?></span><strong><?php echo esc_html( (string) $upcoming ); ?></strong></div>
			<div class="make-school-kpi"><span><?php esc_html_e( 'Lessons available', 'make-school' ); ?></span><strong><?php echo esc_html( (string) $lesson_count ); ?></strong></div>
		</div>

		<h3><?php esc_html_e( 'Quick actions', 'make-school' ); ?></h3>
		<p>
			<a class="make-school-btn make-school-btn-primary" href="<?php echo esc_url( $this->section_url( 'fees' ) ); ?>"><?php esc_html_e( 'View fees', 'make-school' ); ?></a>
			<a class="make-school-btn make-school-btn-ghost" href="<?php echo esc_url( $this->section_url( 'attendance' ) ); ?>"><?php esc_html_e( 'View attendance', 'make-school' ); ?></a>
			<a class="make-school-btn make-school-btn-ghost" href="<?php echo esc_url( $this->section_url( 'exams' ) ); ?>"><?php esc_html_e( 'Report card', 'make-school' ); ?></a>
			<a class="make-school-btn make-school-btn-ghost" href="<?php echo esc_url( $this->section_url( 'lessons' ) ); ?>"><?php esc_html_e( 'Open lessons', 'make-school' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Profile section — admission details (read-only).
	 *
	 * @param object $admission Admission row.
	 * @return void
	 */
	private function render_student_profile( $admission ) {
		$class  = Make_School_Helpers::get_class( (int) $admission->class_id );
		$branch = $admission->branch_id ? Make_School_Helpers::get_branch( (int) $admission->branch_id ) : null;
		?>
		<div class="make-school-card">
			<h3><?php esc_html_e( 'Student profile', 'make-school' ); ?></h3>
			<table class="make-school-data-table">
				<tbody>
					<tr><th><?php esc_html_e( 'Roll number', 'make-school' ); ?></th><td><?php echo esc_html( $admission->roll_number ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Full name', 'make-school' ); ?></th><td><?php echo esc_html( $admission->full_name ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Class', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Branch', 'make-school' ); ?></th><td><?php echo $branch ? esc_html( $branch->name ) : '—'; ?></td></tr>
					<tr><th><?php esc_html_e( 'Session', 'make-school' ); ?></th><td><?php echo esc_html( $admission->session ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Date of Birth', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::format_date( $admission->dob ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Gender', 'make-school' ); ?></th><td><?php echo esc_html( ucfirst( (string) $admission->gender ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Blood group', 'make-school' ); ?></th><td><?php echo esc_html( $admission->blood_group ); ?></td></tr>
					<tr><th><?php esc_html_e( "Father's name", 'make-school' ); ?></th><td><?php echo esc_html( $admission->father_name ); ?></td></tr>
					<tr><th><?php esc_html_e( "Mother's name", 'make-school' ); ?></th><td><?php echo esc_html( $admission->mother_name ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Email', 'make-school' ); ?></th><td><?php echo esc_html( $admission->email ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Mobile', 'make-school' ); ?></th><td><?php echo esc_html( $admission->mobile ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Address', 'make-school' ); ?></th><td><?php echo nl2br( esc_html( (string) $admission->address ) ); ?></td></tr>
				</tbody>
			</table>
			<?php if ( $admission->photo_url || $admission->document_url ) : ?>
				<p class="make-school-meta">
					<?php if ( $admission->photo_url ) : ?>
						<a href="<?php echo esc_url( $admission->photo_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Photo', 'make-school' ); ?></a>
					<?php endif; ?>
					<?php if ( $admission->document_url ) : ?>
						&nbsp;&middot;&nbsp;
						<a href="<?php echo esc_url( $admission->document_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Documents', 'make-school' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * TEACHER DASHBOARD
	 * ================================================================== */

	/**
	 * Render the teacher dashboard.
	 *
	 * @return string HTML.
	 */
	public function render_teacher_dashboard() {
		if ( ! is_user_logged_in() ) {
			return $this->render_login_required();
		}
		if ( ! ( Make_School_Helpers::is_teacher() || Make_School_Helpers::is_make_school_admin() ) ) {
			return $this->render_access_denied();
		}

		$user    = wp_get_current_user();
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'overview';
		$valid   = array( 'overview', 'attendance', 'marks', 'lessons' );
		if ( ! in_array( $section, $valid, true ) ) {
			$section = 'overview';
		}

		$tabs = array(
			'overview'   => __( 'Overview', 'make-school' ),
			'attendance' => __( 'Attendance', 'make-school' ),
			'marks'      => __( 'Marks Entry', 'make-school' ),
			'lessons'    => __( 'Lessons', 'make-school' ),
		);

		ob_start();
		?>
		<div class="make-school-dashboard">
			<?php $this->render_header( null, 'teacher', $user ); ?>
			<?php $this->render_tabs( $tabs, $section ); ?>
			<div class="make-school-dashboard-body">
				<?php
				switch ( $section ) {
					case 'attendance':
						$this->call_module( make_school()->attendance, 'render_teacher_section', array( (int) $user->ID ) );
						break;
					case 'marks':
						$this->call_module( make_school()->exams, 'render_teacher_marks_section', array( (int) $user->ID ) );
						break;
					case 'lessons':
						$this->call_module( make_school()->lms, 'render_teacher_section', array( (int) $user->ID ) );
						break;
					case 'overview':
					default:
						$this->render_teacher_overview( (int) $user->ID );
				}
				?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Teacher overview — assigned classes + counts.
	 *
	 * @param int $teacher_id User ID.
	 * @return void
	 */
	private function render_teacher_overview( $teacher_id ) {
		$classes = Make_School_Helpers::teacher_classes( $teacher_id );
		?>
		<div class="make-school-card">
			<h3><?php esc_html_e( 'Your classes', 'make-school' ); ?></h3>
			<?php if ( empty( $classes ) ) : ?>
				<p><?php esc_html_e( 'You have not been assigned as class teacher to any class yet. Contact the school administrator.', 'make-school' ); ?></p>
			<?php else : ?>
				<table class="make-school-data-table">
					<thead><tr>
						<th><?php esc_html_e( 'Class', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Section', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Session', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Students', 'make-school' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $classes as $c ) :
							$students = Make_School_Helpers::students_in_class( (int) $c->id );
							?>
							<tr>
								<td><strong><?php echo esc_html( $c->class_name ); ?></strong></td>
								<td><?php echo esc_html( $c->section ); ?></td>
								<td><?php echo esc_html( $c->session ); ?></td>
								<td><?php echo esc_html( (string) count( $students ) ); ?></td>
								<td>
									<a class="make-school-btn make-school-btn-ghost" href="<?php echo esc_url( $this->section_url( 'attendance' ) ); ?>"><?php esc_html_e( 'Attendance', 'make-school' ); ?></a>
									<a class="make-school-btn make-school-btn-ghost" href="<?php echo esc_url( $this->section_url( 'marks' ) ); ?>"><?php esc_html_e( 'Marks', 'make-school' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * SHARED RENDER HELPERS
	 * ================================================================== */

	/**
	 * Greeting/header bar at the top of every dashboard.
	 *
	 * @param object|null  $admission   Admission row (student/parent context).
	 * @param string       $kind        'student' or 'teacher'.
	 * @param WP_User|null $user        User (teacher context).
	 * @return void
	 */
	private function render_header( $admission, $kind, $user = null ) {
		$user      = $user ? $user : wp_get_current_user();
		$logout    = wp_logout_url( Make_School_Helpers::login_url() );
		$role_text = 'student' === $kind ? __( 'Student / Parent portal', 'make-school' ) : __( 'Teacher portal', 'make-school' );
		?>
		<div class="make-school-dashboard-header">
			<div>
				<h2><?php echo esc_html( Make_School_Helpers::setting( 'school_name', get_bloginfo( 'name' ) ) ); ?></h2>
				<p>
					<?php echo esc_html( $role_text ); ?>
					&middot;
					<?php
					if ( $admission ) {
						/* translators: 1: name, 2: roll */
						printf( esc_html__( '%1$s (%2$s)', 'make-school' ), esc_html( $admission->full_name ), '<code>' . esc_html( $admission->roll_number ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} else {
						echo esc_html( $user->display_name );
					}
					?>
				</p>
			</div>
			<div class="make-school-dashboard-actions">
				<a class="make-school-btn make-school-btn-ghost" href="<?php echo esc_url( $logout ); ?>"><?php esc_html_e( 'Log out', 'make-school' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a horizontal tab strip.
	 *
	 * @param array  $tabs    slug => label.
	 * @param string $current Active slug.
	 * @return void
	 */
	private function render_tabs( $tabs, $current ) {
		?>
		<nav class="make-school-tabs" aria-label="<?php esc_attr_e( 'Dashboard sections', 'make-school' ); ?>">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a class="make-school-tab <?php echo $current === $slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( $this->section_url( $slug ) ); ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Build a URL for a given section on the current page.
	 *
	 * @param string $slug Section slug.
	 * @return string
	 */
	private function section_url( $slug ) {
		$base = remove_query_arg( array( 'section' ) );
		return add_query_arg( 'section', $slug, $base );
	}

	/**
	 * Safely call a module's section renderer if the method exists,
	 * otherwise emit a fallback "module unavailable" message.
	 *
	 * @param object $module Module instance.
	 * @param string $method Method name.
	 * @param array  $args   Args.
	 * @return void
	 */
	private function call_module( $module, $method, array $args ) {
		if ( is_object( $module ) && method_exists( $module, $method ) ) {
			call_user_func_array( array( $module, $method ), $args );
			return;
		}
		echo '<div class="make-school-alert make-school-alert-warning">' . esc_html__( 'This section is not available yet.', 'make-school' ) . '</div>';
	}

	/**
	 * Login required notice.
	 *
	 * @return string
	 */
	private function render_login_required() {
		$url = Make_School_Helpers::login_url();
		ob_start();
		?>
		<div class="make-school-alert make-school-alert-info">
			<?php esc_html_e( 'You need to sign in to view this dashboard.', 'make-school' ); ?>
			&nbsp;
			<a class="make-school-btn make-school-btn-primary" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Sign in', 'make-school' ); ?></a>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Access denied notice.
	 *
	 * @return string
	 */
	private function render_access_denied() {
		ob_start();
		?>
		<div class="make-school-alert make-school-alert-error">
			<?php esc_html_e( 'You do not have permission to view this dashboard.', 'make-school' ); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
