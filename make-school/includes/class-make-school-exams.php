<?php
/**
 * MAKE SCHOOL — Exams, Marks, Report Cards, Admit Cards.
 *
 * Owns:
 *   - Exam term CRUD (admin) — name, class, dates, times, max/pass marks,
 *     subjects (one per line), venue, notes, publish toggle.
 *   - Marks entry (teacher) — grid of students × subjects, persisted via
 *     $wpdb->replace() against the unique (exam,student,subject) key.
 *   - Report Card engine — derives percentage and letter grade from
 *     entered marks, rendered via the public route
 *     `?make_school_report_card=EXAM_ID&student_id=ID`.
 *   - Admit Card — printable card via the public route
 *     `?make_school_admit_card=EXAM_ID&student_id=ID` (published exams).
 *   - Student dashboard section: list of exams + admit card / report card
 *     links + quick marks summary.
 *
 * @package MakeSchool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Make_School_Exams
 */
class Make_School_Exams {

	const CAP_MANAGE   = 'make_school_manage_exams';
	const CAP_MARKS    = 'make_school_enter_marks';
	const NONCE_ACTION = 'make_school_exams';

	/**
	 * Constructor — wires hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_admin_action' ) );

		// Public printable views.
		add_action( 'template_redirect', array( $this, 'maybe_render_admit_card' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_report_card' ) );
	}

	/* =====================================================================
	 * ADMIN
	 * ================================================================== */

	/**
	 * Register admin sub-pages: Exams, Marks Entry.
	 *
	 * @return void
	 */
	public function register_admin_menus() {
		add_submenu_page(
			Make_School_Admin::PARENT_SLUG,
			__( 'Exams', 'make-school' ),
			__( 'Exams', 'make-school' ),
			self::CAP_MANAGE,
			'make-school-exams',
			array( $this, 'render_exams_page' )
		);
		add_submenu_page(
			Make_School_Admin::PARENT_SLUG,
			__( 'Marks Entry', 'make-school' ),
			__( 'Marks Entry', 'make-school' ),
			self::CAP_MARKS,
			'make-school-marks',
			array( $this, 'render_marks_admin_page' )
		);
	}

	/**
	 * Render the Exams admin page (list / add / edit / view).
	 *
	 * @return void
	 */
	public function render_exams_page() {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'make-school' ) );
		}

		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'list';
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		echo '<div class="wrap make-school-wrap"><h1>' . esc_html__( 'Exams', 'make-school' );
		if ( 'list' === $mode ) {
			echo ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=make-school-exams&mode=edit' ) ) . '">' . esc_html__( 'Add New', 'make-school' ) . '</a>';
		}
		echo '</h1>';
		make_school()->admin->render_flash();

		if ( 'edit' === $mode ) {
			$this->render_exam_form( $id );
		} else {
			$this->render_exams_list();
		}
		echo '</div>';
	}

	/**
	 * Render the exams list table.
	 *
	 * @return void
	 */
	private function render_exams_list() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . make_school()->db->table( 'exams' ) . ' ORDER BY start_date DESC, id DESC LIMIT 200' ); // phpcs:ignore
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Exam', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Class', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Session', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Dates', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Max', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Status', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Published', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'make-school' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No exams configured yet.', 'make-school' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) :
					$class = Make_School_Helpers::get_class( (int) $r->class_id );
					?>
					<tr>
						<td><strong><?php echo esc_html( $r->name ); ?></strong></td>
						<td><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></td>
						<td><?php echo esc_html( $r->session ); ?></td>
						<td><?php echo esc_html( Make_School_Helpers::format_date( $r->start_date ) ); ?> &mdash; <?php echo esc_html( Make_School_Helpers::format_date( $r->end_date ) ); ?></td>
						<td><?php echo esc_html( (string) $r->max_marks ); ?></td>
						<td><span class="make-school-pill make-school-pill-<?php echo esc_attr( $r->status ); ?>"><?php echo esc_html( Make_School_Helpers::status_label( $r->status ) ); ?></span></td>
						<td><?php echo $r->published ? '<span class="make-school-pill make-school-pill-active">' . esc_html__( 'Yes', 'make-school' ) . '</span>' : '—'; ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-exams&mode=edit&id=' . (int) $r->id ) ); ?>"><?php esc_html_e( 'Edit', 'make-school' ); ?></a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-marks&exam_id=' . (int) $r->id ) ); ?>"><?php esc_html_e( 'Marks', 'make-school' ); ?></a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=make-school-exams&make_school_action=delete_exam&id=' . (int) $r->id ), self::NONCE_ACTION ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this exam?', 'make-school' ) ); ?>');" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'make-school' ); ?></a>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the exam add/edit form.
	 *
	 * @param int $id Exam ID.
	 * @return void
	 */
	private function render_exam_form( $id ) {
		$row = $id ? $this->get_exam( $id ) : null;
		$g   = function ( $key, $default = '' ) use ( $row ) {
			return $row && isset( $row->$key ) ? $row->$key : $default;
		};
		$subjects = $row ? $this->parse_subjects( $row->subjects ) : array();
		$classes  = Make_School_Helpers::get_classes();
		?>
		<form method="post" class="make-school-form">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="make_school_action" value="save_exam" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<table class="form-table">
				<tr><th><label><?php esc_html_e( 'Exam name', 'make-school' ); ?> *</label></th>
					<td><input type="text" name="name" value="<?php echo esc_attr( $g( 'name' ) ); ?>" class="regular-text" required /></td></tr>
				<tr><th><label><?php esc_html_e( 'Class', 'make-school' ); ?> *</label></th>
					<td>
						<select name="class_id" required>
							<option value=""><?php esc_html_e( '— Select —', 'make-school' ); ?></option>
							<?php foreach ( $classes as $c ) : ?>
								<option value="<?php echo esc_attr( (string) $c->id ); ?>" <?php selected( (int) $g( 'class_id' ), (int) $c->id ); ?>><?php echo esc_html( Make_School_Helpers::class_label( $c ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<tr><th><label><?php esc_html_e( 'Session', 'make-school' ); ?></label></th>
					<td>
						<select name="session">
							<?php foreach ( Make_School_Helpers::sessions() as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $g( 'session', Make_School_Helpers::current_session() ), $s ); ?>><?php echo esc_html( $s ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<tr><th><label><?php esc_html_e( 'Subjects', 'make-school' ); ?> *</label></th>
					<td>
						<textarea name="subjects" rows="6" class="large-text" required placeholder="<?php esc_attr_e( "One subject per line, e.g.&#10;Mathematics&#10;English&#10;Science", 'make-school' ); ?>"><?php echo esc_textarea( implode( "\n", $subjects ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Enter one subject per line.', 'make-school' ); ?></p>
					</td></tr>
				<tr><th><label><?php esc_html_e( 'Start date', 'make-school' ); ?></label></th>
					<td><input type="date" name="start_date" value="<?php echo esc_attr( (string) $g( 'start_date' ) ); ?>" /></td></tr>
				<tr><th><label><?php esc_html_e( 'End date', 'make-school' ); ?></label></th>
					<td><input type="date" name="end_date" value="<?php echo esc_attr( (string) $g( 'end_date' ) ); ?>" /></td></tr>
				<tr><th><label><?php esc_html_e( 'Start time', 'make-school' ); ?></label></th>
					<td><input type="time" name="start_time" value="<?php echo esc_attr( (string) $g( 'start_time' ) ); ?>" /></td></tr>
				<tr><th><label><?php esc_html_e( 'End time', 'make-school' ); ?></label></th>
					<td><input type="time" name="end_time" value="<?php echo esc_attr( (string) $g( 'end_time' ) ); ?>" /></td></tr>
				<tr><th><label><?php esc_html_e( 'Max marks per subject', 'make-school' ); ?></label></th>
					<td><input type="number" min="1" name="max_marks" value="<?php echo esc_attr( (string) $g( 'max_marks', 100 ) ); ?>" class="small-text" /></td></tr>
				<tr><th><label><?php esc_html_e( 'Pass marks per subject', 'make-school' ); ?></label></th>
					<td><input type="number" min="0" name="pass_marks" value="<?php echo esc_attr( (string) $g( 'pass_marks', Make_School_Helpers::setting( 'default_pass_mark', 35 ) ) ); ?>" class="small-text" /></td></tr>
				<tr><th><label><?php esc_html_e( 'Venue', 'make-school' ); ?></label></th>
					<td><input type="text" name="venue" value="<?php echo esc_attr( (string) $g( 'venue' ) ); ?>" class="regular-text" /></td></tr>
				<tr><th><label><?php esc_html_e( 'Notes', 'make-school' ); ?></label></th>
					<td><textarea name="notes" rows="3" class="large-text"><?php echo esc_textarea( (string) $g( 'notes' ) ); ?></textarea></td></tr>
				<tr><th><label><?php esc_html_e( 'Status', 'make-school' ); ?></label></th>
					<td>
						<select name="status">
							<?php foreach ( array( 'scheduled', 'in_progress', 'completed' ) as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $g( 'status', 'scheduled' ), $s ); ?>><?php echo esc_html( Make_School_Helpers::status_label( $s ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<tr><th><label><?php esc_html_e( 'Publish admit cards', 'make-school' ); ?></label></th>
					<td><label><input type="checkbox" name="published" value="1" <?php checked( (int) $g( 'published' ), 1 ); ?> /> <?php esc_html_e( 'Allow students to download their admit card.', 'make-school' ); ?></label></td></tr>
			</table>
			<p class="submit">
				<button class="button button-primary" type="submit"><?php echo esc_html( $id ? __( 'Update Exam', 'make-school' ) : __( 'Create Exam', 'make-school' ) ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-exams' ) ); ?>"><?php esc_html_e( 'Cancel', 'make-school' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Render the marks-entry admin screen.
	 *
	 * @return void
	 */
	public function render_marks_admin_page() {
		if ( ! current_user_can( self::CAP_MARKS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'make-school' ) );
		}

		$exam_id = isset( $_GET['exam_id'] ) ? absint( wp_unslash( $_GET['exam_id'] ) ) : 0;

		echo '<div class="wrap make-school-wrap"><h1>' . esc_html__( 'Marks Entry', 'make-school' ) . '</h1>';
		make_school()->admin->render_flash();

		if ( ! $exam_id ) {
			$this->render_exam_picker();
		} else {
			$this->render_marks_grid( $exam_id );
		}
		echo '</div>';
	}

	/**
	 * Pick an exam to enter marks for.
	 *
	 * @return void
	 */
	private function render_exam_picker() {
		global $wpdb;
		$exams = $wpdb->get_results( 'SELECT * FROM ' . make_school()->db->table( 'exams' ) . ' ORDER BY start_date DESC LIMIT 200' ); // phpcs:ignore
		?>
		<p><?php esc_html_e( 'Select an exam to enter marks.', 'make-school' ); ?></p>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Exam', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Class', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Dates', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Max', 'make-school' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
				<?php if ( empty( $exams ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No exams configured.', 'make-school' ); ?></td></tr>
				<?php else : foreach ( $exams as $r ) :
					$class = Make_School_Helpers::get_class( (int) $r->class_id );
					?>
					<tr>
						<td><strong><?php echo esc_html( $r->name ); ?></strong></td>
						<td><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></td>
						<td><?php echo esc_html( Make_School_Helpers::format_date( $r->start_date ) ); ?> &mdash; <?php echo esc_html( Make_School_Helpers::format_date( $r->end_date ) ); ?></td>
						<td><?php echo esc_html( (string) $r->max_marks ); ?></td>
						<td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-marks&exam_id=' . (int) $r->id ) ); ?>"><?php esc_html_e( 'Enter marks', 'make-school' ); ?></a></td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the marks entry grid for a specific exam.
	 *
	 * @param int $exam_id Exam ID.
	 * @return void
	 */
	private function render_marks_grid( $exam_id ) {
		$exam = $this->get_exam( $exam_id );
		if ( ! $exam ) {
			echo '<p>' . esc_html__( 'Exam not found.', 'make-school' ) . '</p>';
			return;
		}

		// Authorise: admin OR class teacher of this exam's class.
		$class = Make_School_Helpers::get_class( (int) $exam->class_id );
		if ( ! Make_School_Helpers::is_make_school_admin() && ( ! $class || (int) $class->class_teacher_id !== get_current_user_id() ) ) {
			wp_die( esc_html__( 'You can only enter marks for classes assigned to you.', 'make-school' ) );
		}

		$subjects = $this->parse_subjects( $exam->subjects );
		$students = Make_School_Helpers::students_in_class( (int) $exam->class_id );
		$existing = $this->fetch_marks_lookup( $exam_id );
		?>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-marks' ) ); ?>">&larr; <?php esc_html_e( 'Back to exam picker', 'make-school' ); ?></a>
		</p>
		<div class="make-school-card">
			<h2><?php echo esc_html( $exam->name ); ?> <small style="font-weight:normal;color:#666;">(<?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?>)</small></h2>
			<p><?php
				/* translators: 1: max, 2: pass */
				printf( esc_html__( 'Max marks per subject: %1$d &middot; Pass marks: %2$d', 'make-school' ), (int) $exam->max_marks, (int) $exam->pass_marks );
			?></p>

			<?php if ( empty( $subjects ) ) : ?>
				<div class="make-school-alert make-school-alert-warning"><?php esc_html_e( 'This exam has no subjects defined. Edit the exam to add subjects first.', 'make-school' ); ?></div>
			<?php elseif ( empty( $students ) ) : ?>
				<div class="make-school-alert make-school-alert-info"><?php esc_html_e( 'No students enrolled in this class.', 'make-school' ); ?></div>
			<?php else : ?>
				<form method="post" class="make-school-form">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="make_school_action" value="save_marks" />
					<input type="hidden" name="exam_id" value="<?php echo esc_attr( (string) $exam_id ); ?>" />

					<table class="wp-list-table widefat fixed striped">
						<thead><tr>
							<th><?php esc_html_e( 'Roll', 'make-school' ); ?></th>
							<th><?php esc_html_e( 'Student', 'make-school' ); ?></th>
							<?php foreach ( $subjects as $sub ) : ?>
								<th><?php echo esc_html( $sub ); ?></th>
							<?php endforeach; ?>
						</tr></thead>
						<tbody>
							<?php foreach ( $students as $stu ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $stu->roll_number ); ?></strong></td>
									<td><?php echo esc_html( $stu->full_name ); ?></td>
									<?php foreach ( $subjects as $sub ) :
										$key  = (int) $stu->user_id . '|' . $sub;
										$val  = isset( $existing[ $key ] ) ? (float) $existing[ $key ] : '';
										$nm   = 'marks[' . (int) $stu->user_id . '][' . esc_attr( base64_encode( $sub ) ) . ']';
										?>
										<td>
											<input type="number" min="0" max="<?php echo esc_attr( (string) $exam->max_marks ); ?>" step="0.01" name="<?php echo esc_attr( $nm ); ?>" value="<?php echo esc_attr( '' === $val ? '' : (string) $val ); ?>" class="small-text" />
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="submit"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save marks', 'make-school' ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * ADMIN — ACTION ROUTER
	 * ================================================================== */

	/**
	 * Dispatch admin POST/GET actions.
	 *
	 * @return void
	 */
	public function maybe_handle_admin_action() {
		if ( ! is_admin() ) {
			return;
		}

		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			$action = isset( $_POST['make_school_action'] ) ? sanitize_key( wp_unslash( $_POST['make_school_action'] ) ) : '';
			if ( 'save_exam' === $action && current_user_can( self::CAP_MANAGE ) ) {
				$this->save_exam();
			} elseif ( 'save_marks' === $action && current_user_can( self::CAP_MARKS ) ) {
				$this->save_marks();
			}
		}

		$g = isset( $_GET['make_school_action'] ) ? sanitize_key( wp_unslash( $_GET['make_school_action'] ) ) : '';
		if ( 'delete_exam' === $g && current_user_can( self::CAP_MANAGE ) ) {
			$this->delete_exam();
		}
	}

	/**
	 * Save (insert/update) an exam.
	 *
	 * @return void
	 */
	private function save_exam() {
		check_admin_referer( self::NONCE_ACTION );

		global $wpdb;
		$table = make_school()->db->table( 'exams' );
		$now   = current_time( 'mysql' );
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$class_id = isset( $_POST['class_id'] ) ? absint( $_POST['class_id'] ) : 0;
		$class    = Make_School_Helpers::get_class( $class_id );

		$data = array(
			'class_id'   => $class_id,
			'branch_id'  => $class ? (int) $class->branch_id : 0,
			'session'    => isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : Make_School_Helpers::current_session(),
			'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'subjects'   => isset( $_POST['subjects'] ) ? $this->normalise_subjects( wp_unslash( $_POST['subjects'] ) ) : '',
			'start_date' => isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : null,
			'end_date'   => isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : null,
			'start_time' => isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '',
			'end_time'   => isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '',
			'max_marks'  => isset( $_POST['max_marks'] ) ? max( 1, (int) wp_unslash( $_POST['max_marks'] ) ) : 100,
			'pass_marks' => isset( $_POST['pass_marks'] ) ? max( 0, (int) wp_unslash( $_POST['pass_marks'] ) ) : 35,
			'venue'      => isset( $_POST['venue'] ) ? sanitize_text_field( wp_unslash( $_POST['venue'] ) ) : '',
			'notes'      => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			'status'     => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'scheduled',
			'published'  => ! empty( $_POST['published'] ) ? 1 : 0,
			'updated_at' => $now,
		);

		if ( ! $class_id || '' === $data['name'] || '' === $data['subjects'] ) {
			make_school()->admin->push_flash( __( 'Class, exam name and subjects are required.', 'make-school' ), 'error' );
			$this->redirect( admin_url( 'admin.php?page=make-school-exams&mode=edit&id=' . $id ) );
		}

		// Empty dates must be NULL, not ''.
		foreach ( array( 'start_date', 'end_date' ) as $f ) {
			if ( empty( $data[ $f ] ) ) {
				$data[ $f ] = null;
			}
		}

		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore
			make_school()->admin->push_flash( __( 'Exam updated.', 'make-school' ) );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data ); // phpcs:ignore
			$id = (int) $wpdb->insert_id;
			make_school()->admin->push_flash( __( 'Exam created.', 'make-school' ) );
		}
		$this->redirect( admin_url( 'admin.php?page=make-school-exams' ) );
	}

	/**
	 * Delete an exam (and its associated marks).
	 *
	 * @return void
	 */
	private function delete_exam() {
		check_admin_referer( self::NONCE_ACTION );
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $id ) {
			global $wpdb;
			$wpdb->delete( make_school()->db->table( 'marks' ), array( 'exam_id' => $id ), array( '%d' ) ); // phpcs:ignore
			$wpdb->delete( make_school()->db->table( 'exams' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore
			make_school()->admin->push_flash( __( 'Exam deleted.', 'make-school' ) );
		}
		$this->redirect( admin_url( 'admin.php?page=make-school-exams' ) );
	}

	/**
	 * Save marks from the marks entry grid.
	 *
	 * Persists each (exam, student, subject) row via $wpdb->replace().
	 *
	 * @return void
	 */
	private function save_marks() {
		check_admin_referer( self::NONCE_ACTION );

		$exam_id = isset( $_POST['exam_id'] ) ? absint( $_POST['exam_id'] ) : 0;
		$exam    = $this->get_exam( $exam_id );
		if ( ! $exam ) {
			make_school()->admin->push_flash( __( 'Exam not found.', 'make-school' ), 'error' );
			$this->redirect( admin_url( 'admin.php?page=make-school-marks' ) );
		}

		// Authorise: admin or class teacher.
		$class = Make_School_Helpers::get_class( (int) $exam->class_id );
		if ( ! Make_School_Helpers::is_make_school_admin() && ( ! $class || (int) $class->class_teacher_id !== get_current_user_id() ) ) {
			wp_die( esc_html__( 'You can only enter marks for classes assigned to you.', 'make-school' ) );
		}

		$payload = isset( $_POST['marks'] ) && is_array( $_POST['marks'] ) ? wp_unslash( $_POST['marks'] ) : array();
		if ( empty( $payload ) ) {
			make_school()->admin->push_flash( __( 'Nothing to save.', 'make-school' ), 'warning' );
			$this->redirect( admin_url( 'admin.php?page=make-school-marks&exam_id=' . $exam_id ) );
		}

		// Whitelist students and subjects.
		$valid_students = array();
		foreach ( Make_School_Helpers::students_in_class( (int) $exam->class_id ) as $s ) {
			$valid_students[ (int) $s->user_id ] = true;
		}
		$valid_subjects = array_flip( $this->parse_subjects( $exam->subjects ) );

		global $wpdb;
		$table = make_school()->db->table( 'marks' );
		$now   = current_time( 'mysql' );
		$max   = (float) $exam->max_marks;
		$saved = 0;

		foreach ( $payload as $uid => $subjects ) {
			$uid = absint( $uid );
			if ( ! $uid || ! isset( $valid_students[ $uid ] ) ) {
				continue;
			}
			if ( ! is_array( $subjects ) ) {
				continue;
			}
			foreach ( $subjects as $sub_b64 => $val ) {
				$sub = base64_decode( (string) $sub_b64, true );
				if ( false === $sub || ! isset( $valid_subjects[ $sub ] ) ) {
					continue;
				}
				$val = is_numeric( $val ) ? max( 0, min( $max, (float) $val ) ) : null;
				if ( null === $val ) {
					// Skip empty cells — treated as "not entered yet".
					continue;
				}
				$pct   = $max > 0 ? ( $val / $max ) * 100 : 0;
				$grade = Make_School_Helpers::compute_grade( $pct );

				$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'exam_id'         => (int) $exam_id,
						'student_user_id' => (int) $uid,
						'subject'         => (string) $sub,
						'marks_obtained'  => $val,
						'max_marks'       => $max,
						'grade'           => $grade,
						'remarks'         => '',
						'entered_by'      => (int) get_current_user_id(),
						'created_at'      => $now,
						'updated_at'      => $now,
					),
					array( '%d', '%d', '%s', '%f', '%f', '%s', '%s', '%d', '%s', '%s' )
				);
				$saved++;
			}
		}

		make_school()->admin->push_flash(
			sprintf(
				/* translators: %d: count */
				esc_html( _n( '%d mark saved.', '%d marks saved.', $saved, 'make-school' ) ),
				$saved
			)
		);
		$this->redirect( admin_url( 'admin.php?page=make-school-marks&exam_id=' . $exam_id ) );
	}

	/* =====================================================================
	 * STUDENT DASHBOARD SECTION
	 * ================================================================== */

	/**
	 * Render the student exams section (called from the student dashboard).
	 *
	 * @param int    $student_id WP user ID.
	 * @param object $admission  Admission row.
	 * @return void
	 */
	public function render_student_section( $student_id, $admission ) {
		global $wpdb;
		$table = make_school()->db->table( 'exams' );
		$exams = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE class_id = %d ORDER BY start_date DESC, id DESC",
				(int) $admission->class_id
			)
		);
		?>
		<div class="make-school-card">
			<h3><?php esc_html_e( 'Exams & marks', 'make-school' ); ?></h3>
			<?php if ( empty( $exams ) ) : ?>
				<p><?php esc_html_e( 'No exams scheduled for your class yet.', 'make-school' ); ?></p>
			<?php else : ?>
				<table class="make-school-data-table">
					<thead><tr>
						<th><?php esc_html_e( 'Exam', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Dates', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Status', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Result', 'make-school' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $exams as $e ) :
							$summary = $this->result_summary( (int) $e->id, $student_id );
							?>
							<tr>
								<td><strong><?php echo esc_html( $e->name ); ?></strong></td>
								<td><?php echo esc_html( Make_School_Helpers::format_date( $e->start_date ) ); ?> &mdash; <?php echo esc_html( Make_School_Helpers::format_date( $e->end_date ) ); ?></td>
								<td><span class="make-school-pill make-school-pill-<?php echo esc_attr( $e->status ); ?>"><?php echo esc_html( Make_School_Helpers::status_label( $e->status ) ); ?></span></td>
								<td>
									<?php if ( $summary['count'] > 0 ) : ?>
										<?php echo esc_html( number_format( $summary['percent'], 1 ) . '% (' . $summary['grade'] . ')' ); ?>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $e->published ) : ?>
										<a target="_blank" rel="noopener" href="<?php echo esc_url( $this->admit_card_url( (int) $e->id, $student_id ) ); ?>"><?php esc_html_e( 'Admit card', 'make-school' ); ?></a>
									<?php endif; ?>
									<?php if ( $summary['count'] > 0 ) : ?>
										<?php if ( $e->published ) echo '&nbsp;|&nbsp;'; ?>
										<a target="_blank" rel="noopener" href="<?php echo esc_url( $this->report_card_url( (int) $e->id, $student_id ) ); ?>"><?php esc_html_e( 'Report card', 'make-school' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the teacher marks-entry section in the front-end dashboard.
	 *
	 * @param int $teacher_id Teacher user ID.
	 * @return void
	 */
	public function render_teacher_marks_section( $teacher_id ) {
		if ( ! current_user_can( self::CAP_MARKS ) ) {
			echo '<div class="make-school-alert make-school-alert-error">' . esc_html__( 'You do not have permission to enter marks.', 'make-school' ) . '</div>';
			return;
		}

		global $wpdb;
		$class_ids = array_map(
			function ( $c ) {
				return (int) $c->id;
			},
			Make_School_Helpers::teacher_classes( (int) $teacher_id )
		);
		if ( empty( $class_ids ) ) {
			echo '<div class="make-school-alert make-school-alert-info">' . esc_html__( 'You have no classes assigned.', 'make-school' ) . '</div>';
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $class_ids ), '%d' ) );
		$exams        = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				'SELECT * FROM ' . make_school()->db->table( 'exams' ) . " WHERE class_id IN ({$placeholders}) ORDER BY start_date DESC LIMIT 100",
				$class_ids
			)
		);
		?>
		<div class="make-school-card">
			<h3><?php esc_html_e( 'Marks entry', 'make-school' ); ?></h3>
			<p><?php esc_html_e( 'Enter marks via the admin marks-entry screen, which provides the full student × subject grid for any of your exams.', 'make-school' ); ?></p>
			<?php if ( empty( $exams ) ) : ?>
				<p><?php esc_html_e( 'No exams scheduled for your classes.', 'make-school' ); ?></p>
			<?php else : ?>
				<table class="make-school-data-table">
					<thead><tr>
						<th><?php esc_html_e( 'Exam', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Class', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Dates', 'make-school' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $exams as $e ) :
							$class = Make_School_Helpers::get_class( (int) $e->class_id );
							?>
							<tr>
								<td><strong><?php echo esc_html( $e->name ); ?></strong></td>
								<td><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></td>
								<td><?php echo esc_html( Make_School_Helpers::format_date( $e->start_date ) ); ?> &mdash; <?php echo esc_html( Make_School_Helpers::format_date( $e->end_date ) ); ?></td>
								<td><a class="make-school-btn make-school-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-marks&exam_id=' . (int) $e->id ) ); ?>"><?php esc_html_e( 'Open grid', 'make-school' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * PUBLIC PRINTABLE ROUTES
	 * ================================================================== */

	/**
	 * Build admit-card URL.
	 *
	 * @param int $exam_id Exam ID.
	 * @param int $student_id Student WP user ID.
	 * @return string
	 */
	private function admit_card_url( $exam_id, $student_id ) {
		return add_query_arg(
			array( 'make_school_admit_card' => (int) $exam_id, 'student_id' => (int) $student_id ),
			home_url( '/' )
		);
	}

	/**
	 * Build report-card URL.
	 *
	 * @param int $exam_id Exam ID.
	 * @param int $student_id Student WP user ID.
	 * @return string
	 */
	private function report_card_url( $exam_id, $student_id ) {
		return add_query_arg(
			array( 'make_school_report_card' => (int) $exam_id, 'student_id' => (int) $student_id ),
			home_url( '/' )
		);
	}

	/**
	 * Public route — render the printable admit card.
	 *
	 * @return void
	 */
	public function maybe_render_admit_card() {
		if ( ! isset( $_GET['make_school_admit_card'] ) ) {
			return;
		}
		$exam_id    = absint( wp_unslash( $_GET['make_school_admit_card'] ) );
		$student_id = isset( $_GET['student_id'] ) ? absint( wp_unslash( $_GET['student_id'] ) ) : 0;
		$exam       = $this->get_exam( $exam_id );
		if ( ! $exam || ! $student_id ) {
			wp_die( esc_html__( 'Admit card not available.', 'make-school' ) );
		}
		if ( empty( $exam->published ) ) {
			wp_die( esc_html__( 'This admit card has not been published yet.', 'make-school' ) );
		}
		if ( ! $this->user_can_view( $student_id ) ) {
			wp_die( esc_html__( 'You are not allowed to view this admit card.', 'make-school' ), 403 );
		}

		$admission = Make_School_Helpers::admission_for_user( $student_id );
		if ( ! $admission || (int) $admission->class_id !== (int) $exam->class_id ) {
			wp_die( esc_html__( 'This admit card is not valid for this student.', 'make-school' ) );
		}
		$class    = Make_School_Helpers::get_class( (int) $exam->class_id );
		$subjects = $this->parse_subjects( $exam->subjects );

		$this->render_print_doc(
			__( 'Admit Card', 'make-school' ) . ' — ' . $exam->name,
			function () use ( $exam, $admission, $class, $subjects ) {
				?>
				<div class="r-head">
					<div>
						<h1><?php echo esc_html( Make_School_Helpers::setting( 'school_name' ) ); ?></h1>
						<h2><?php esc_html_e( 'Admit Card', 'make-school' ); ?></h2>
					</div>
					<div style="text-align:right;">
						<div><strong><?php echo esc_html( $exam->name ); ?></strong></div>
						<div style="font-size:12px;color:#888;"><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></div>
					</div>
				</div>

				<table>
					<tr><th><?php esc_html_e( 'Student', 'make-school' ); ?></th><td><strong><?php echo esc_html( $admission->full_name ); ?></strong></td></tr>
					<tr><th><?php esc_html_e( 'Roll number', 'make-school' ); ?></th><td><?php echo esc_html( $admission->roll_number ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Class', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Session', 'make-school' ); ?></th><td><?php echo esc_html( $admission->session ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Dates', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::format_date( $exam->start_date ) ); ?> &mdash; <?php echo esc_html( Make_School_Helpers::format_date( $exam->end_date ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Time', 'make-school' ); ?></th><td><?php echo esc_html( $exam->start_time . ' — ' . $exam->end_time ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Venue', 'make-school' ); ?></th><td><?php echo esc_html( $exam->venue ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Max marks', 'make-school' ); ?></th><td><?php echo esc_html( (string) $exam->max_marks ); ?></td></tr>
					<?php if ( $subjects ) : ?>
						<tr><th><?php esc_html_e( 'Subjects', 'make-school' ); ?></th><td><?php echo esc_html( implode( ', ', $subjects ) ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $exam->notes ) : ?>
						<tr><th><?php esc_html_e( 'Notes', 'make-school' ); ?></th><td><?php echo nl2br( esc_html( (string) $exam->notes ) ); ?></td></tr>
					<?php endif; ?>
				</table>

				<p style="margin-top:18px;font-size:12px;color:#888;">
					<?php esc_html_e( 'Bring this admit card to the examination hall on each exam day.', 'make-school' ); ?>
				</p>
				<?php
			}
		);
		exit;
	}

	/**
	 * Public route — render the printable report card.
	 *
	 * @return void
	 */
	public function maybe_render_report_card() {
		if ( ! isset( $_GET['make_school_report_card'] ) ) {
			return;
		}
		$exam_id    = absint( wp_unslash( $_GET['make_school_report_card'] ) );
		$student_id = isset( $_GET['student_id'] ) ? absint( wp_unslash( $_GET['student_id'] ) ) : 0;
		$exam       = $this->get_exam( $exam_id );
		if ( ! $exam || ! $student_id ) {
			wp_die( esc_html__( 'Report card not available.', 'make-school' ) );
		}
		if ( ! $this->user_can_view( $student_id ) ) {
			wp_die( esc_html__( 'You are not allowed to view this report card.', 'make-school' ), 403 );
		}

		$admission = Make_School_Helpers::admission_for_user( $student_id );
		if ( ! $admission || (int) $admission->class_id !== (int) $exam->class_id ) {
			wp_die( esc_html__( 'This report card is not valid for this student.', 'make-school' ) );
		}
		$class = Make_School_Helpers::get_class( (int) $exam->class_id );
		$rows  = $this->fetch_marks_for_student( $exam_id, $student_id );

		// Aggregate.
		$obtained = 0.0;
		$max      = 0.0;
		foreach ( $rows as $r ) {
			$obtained += (float) $r->marks_obtained;
			$max      += (float) $r->max_marks;
		}
		$percent       = $max > 0 ? ( $obtained / $max ) * 100 : 0;
		$overall_grade = Make_School_Helpers::compute_grade( $percent );
		$pass          = (float) $exam->pass_marks;
		$failed_any    = false;
		foreach ( $rows as $r ) {
			if ( (float) $r->marks_obtained < $pass ) {
				$failed_any = true;
				break;
			}
		}
		$result = $failed_any ? __( 'Fail', 'make-school' ) : __( 'Pass', 'make-school' );

		$this->render_print_doc(
			__( 'Report Card', 'make-school' ) . ' — ' . $exam->name,
			function () use ( $exam, $admission, $class, $rows, $obtained, $max, $percent, $overall_grade, $result, $pass ) {
				?>
				<div class="r-head">
					<div>
						<h1><?php echo esc_html( Make_School_Helpers::setting( 'school_name' ) ); ?></h1>
						<h2><?php esc_html_e( 'Report Card', 'make-school' ); ?></h2>
					</div>
					<div style="text-align:right;">
						<div><strong><?php echo esc_html( $exam->name ); ?></strong></div>
						<div style="font-size:12px;color:#888;"><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></div>
					</div>
				</div>

				<table>
					<tr><th><?php esc_html_e( 'Student', 'make-school' ); ?></th><td><strong><?php echo esc_html( $admission->full_name ); ?></strong></td></tr>
					<tr><th><?php esc_html_e( 'Roll number', 'make-school' ); ?></th><td><?php echo esc_html( $admission->roll_number ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Class', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Session', 'make-school' ); ?></th><td><?php echo esc_html( $admission->session ); ?></td></tr>
				</table>

				<h3 style="margin-top:18px;"><?php esc_html_e( 'Subjects', 'make-school' ); ?></h3>
				<table>
					<thead><tr>
						<th><?php esc_html_e( 'Subject', 'make-school' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Obtained', 'make-school' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Max', 'make-school' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Percent', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Grade', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Result', 'make-school' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $rows as $r ) :
							$pct = (float) $r->max_marks > 0 ? ( (float) $r->marks_obtained / (float) $r->max_marks ) * 100 : 0;
							$pf  = ( (float) $r->marks_obtained >= $pass ) ? __( 'Pass', 'make-school' ) : __( 'Fail', 'make-school' );
							?>
							<tr>
								<td><?php echo esc_html( $r->subject ); ?></td>
								<td style="text-align:right;"><?php echo esc_html( number_format( (float) $r->marks_obtained, 2 ) ); ?></td>
								<td style="text-align:right;"><?php echo esc_html( number_format( (float) $r->max_marks, 0 ) ); ?></td>
								<td style="text-align:right;"><?php echo esc_html( number_format( $pct, 1 ) . '%' ); ?></td>
								<td><strong><?php echo esc_html( $r->grade ); ?></strong></td>
								<td><?php echo esc_html( $pf ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<table class="totals" style="margin-top:14px;">
					<tr><th><?php esc_html_e( 'Total obtained', 'make-school' ); ?></th><td style="text-align:right;"><?php echo esc_html( number_format( $obtained, 2 ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Total max', 'make-school' ); ?></th><td style="text-align:right;"><?php echo esc_html( number_format( $max, 0 ) ); ?></td></tr>
					<tr><th class="grand"><?php esc_html_e( 'Percentage', 'make-school' ); ?></th><td class="grand" style="text-align:right;"><?php echo esc_html( number_format( $percent, 2 ) . '%' ); ?></td></tr>
					<tr><th class="grand"><?php esc_html_e( 'Overall grade', 'make-school' ); ?></th><td class="grand" style="text-align:right;"><?php echo esc_html( $overall_grade ); ?></td></tr>
					<tr><th class="grand"><?php esc_html_e( 'Result', 'make-school' ); ?></th><td class="grand" style="text-align:right;"><?php echo esc_html( $result ); ?></td></tr>
				</table>
				<?php
			}
		);
		exit;
	}

	/**
	 * Shared printable-document wrapper used by admit cards and report cards.
	 * Accepts a callable that emits the inner HTML, and the page title.
	 *
	 * @param string   $title    Document title.
	 * @param callable $renderer Inner-HTML renderer.
	 * @return void
	 */
	private function render_print_doc( $title, $renderer ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $title ); ?></title>
	<style>
		body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#222;margin:0;padding:24px;background:#f5f6f8;}
		.doc{max-width:820px;margin:0 auto;background:#fff;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.06);padding:32px;}
		.r-head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1px solid #eee;padding-bottom:16px;margin-bottom:18px;}
		.r-head h1{margin:0 0 4px 0;font-size:22px;}
		.r-head h2{margin:0;font-size:14px;color:#777;font-weight:500;}
		table{width:100%;border-collapse:collapse;margin-top:14px;}
		th,td{text-align:left;padding:8px 6px;border-bottom:1px solid #f0f0f0;font-size:14px;}
		th{font-weight:600;color:#666;font-size:12px;text-transform:uppercase;letter-spacing:.04em;}
		.totals .grand{font-size:18px;font-weight:700;}
		.print{margin-top:16px;}
		button{padding:8px 14px;font-size:14px;border-radius:6px;border:1px solid #ddd;background:#fff;cursor:pointer;}
		@media print { body{background:#fff;padding:0;} .doc{box-shadow:none;border-radius:0;} .print{display:none;} }
	</style>
</head>
<body>
	<div class="doc">
		<?php call_user_func( $renderer ); ?>
		<div class="print">
			<button type="button" onclick="window.print();"><?php esc_html_e( 'Print', 'make-school' ); ?></button>
		</div>
	</div>
</body>
</html>
		<?php
	}

	/* =====================================================================
	 * UTILITIES
	 * ================================================================== */

	/**
	 * Single-row exam fetch.
	 *
	 * @param int $id Exam ID.
	 * @return object|null
	 */
	private function get_exam( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . make_school()->db->table( 'exams' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	/**
	 * Parse the exam's `subjects` field into a clean string array.
	 *
	 * @param string|null $raw Raw stored value.
	 * @return string[]
	 */
	private function parse_subjects( $raw ) {
		if ( empty( $raw ) ) {
			return array();
		}
		$lines = preg_split( '/\r?\n/', (string) $raw );
		$out   = array();
		foreach ( $lines as $line ) {
			$s = trim( (string) $line );
			if ( '' !== $s ) {
				$out[] = $s;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Normalise the textarea input for storage.
	 *
	 * @param string $raw Raw textarea value.
	 * @return string
	 */
	private function normalise_subjects( $raw ) {
		$list = $this->parse_subjects( sanitize_textarea_field( $raw ) );
		return implode( "\n", $list );
	}

	/**
	 * Lookup map for marks: 'student_user_id|subject' => marks_obtained.
	 *
	 * @param int $exam_id Exam ID.
	 * @return array<string,float>
	 */
	private function fetch_marks_lookup( $exam_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				'SELECT student_user_id, subject, marks_obtained FROM ' . make_school()->db->table( 'marks' ) . ' WHERE exam_id = %d',
				absint( $exam_id )
			)
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->student_user_id . '|' . $r->subject ] = (float) $r->marks_obtained;
		}
		return $out;
	}

	/**
	 * Fetch all subject marks for a single student in an exam.
	 *
	 * @param int $exam_id    Exam ID.
	 * @param int $student_id Student WP user ID.
	 * @return array<int,object>
	 */
	private function fetch_marks_for_student( $exam_id, $student_id ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				'SELECT * FROM ' . make_school()->db->table( 'marks' ) . ' WHERE exam_id = %d AND student_user_id = %d ORDER BY subject ASC',
				absint( $exam_id ),
				absint( $student_id )
			)
		);
	}

	/**
	 * Compact result summary for a student in an exam.
	 *
	 * @param int $exam_id    Exam ID.
	 * @param int $student_id Student WP user ID.
	 * @return array{count:int,obtained:float,max:float,percent:float,grade:string}
	 */
	private function result_summary( $exam_id, $student_id ) {
		$rows  = $this->fetch_marks_for_student( $exam_id, $student_id );
		$obt   = 0.0;
		$max   = 0.0;
		$count = count( $rows );
		foreach ( $rows as $r ) {
			$obt += (float) $r->marks_obtained;
			$max += (float) $r->max_marks;
		}
		$pct = $max > 0 ? ( $obt / $max ) * 100 : 0;
		return array(
			'count'    => $count,
			'obtained' => $obt,
			'max'      => $max,
			'percent'  => $pct,
			'grade'    => Make_School_Helpers::compute_grade( $pct ),
		);
	}

	/**
	 * Authorisation check for the public printable views.
	 *
	 * Allowed: the student themselves, their mapped parent, or any user
	 * with manage capability.
	 *
	 * @param int $student_id Student WP user ID.
	 * @return bool
	 */
	private function user_can_view( $student_id ) {
		if ( current_user_can( self::CAP_MANAGE ) || current_user_can( self::CAP_MARKS ) ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( (int) $user->ID === (int) $student_id ) {
			return true;
		}
		if ( Make_School_Helpers::is_parent() && (int) get_user_meta( $user->ID, 'make_school_child_user_id', true ) === (int) $student_id ) {
			return true;
		}
		return false;
	}

	/**
	 * Safe redirect + die.
	 *
	 * @param string $url URL.
	 * @return void
	 */
	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
