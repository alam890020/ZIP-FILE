<?php
/**
 * MAKE SCHOOL — Attendance.
 *
 * Owns:
 *   - Teacher dashboard section: class selector + AJAX roster + grid
 *     check-list (Present / Absent / Late) with AJAX save.
 *   - Student dashboard widget: monthly analytics + day-by-day log.
 *   - AJAX endpoints (registered via class-make-school-ajax.php):
 *       make_school_load_roster
 *       make_school_save_attendance
 *
 * Each (student_user_id, class_id, attendance_date) row is unique
 * (DB constraint), so saving uses INSERT ... ON DUPLICATE KEY UPDATE
 * via $wpdb->replace().
 *
 * @package MakeSchool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Make_School_Attendance
 */
class Make_School_Attendance {

	const NONCE_AJAX = 'make_school_attendance';
	const CAP_TAKE   = 'make_school_take_attendance';

	/**
	 * Constructor — registers AJAX handlers.
	 */
	public function __construct() {
		add_action( 'wp_ajax_make_school_load_roster', array( $this, 'ajax_load_roster' ) );
		add_action( 'wp_ajax_make_school_save_attendance', array( $this, 'ajax_save_attendance' ) );
	}

	/* =====================================================================
	 * TEACHER SECTION (called from the teacher dashboard).
	 * ================================================================== */

	/**
	 * Render the teacher attendance UI.
	 *
	 * @param int $teacher_id Teacher user ID.
	 * @return void
	 */
	public function render_teacher_section( $teacher_id ) {
		if ( ! current_user_can( self::CAP_TAKE ) ) {
			echo '<div class="make-school-alert make-school-alert-error">' . esc_html__( 'You do not have permission to record attendance.', 'make-school' ) . '</div>';
			return;
		}

		$classes = Make_School_Helpers::teacher_classes( (int) $teacher_id );
		// Admins see every active class as a fallback.
		if ( empty( $classes ) && Make_School_Helpers::is_make_school_admin() ) {
			$classes = Make_School_Helpers::get_classes( 0, '' );
		}

		if ( empty( $classes ) ) {
			echo '<div class="make-school-alert make-school-alert-info">' . esc_html__( 'You have no classes assigned. Ask the school administrator to assign you as class teacher.', 'make-school' ) . '</div>';
			return;
		}

		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( self::NONCE_AJAX );
		$today    = current_time( 'Y-m-d' );
		?>
		<div class="make-school-card">
			<h3><?php esc_html_e( 'Daily attendance', 'make-school' ); ?></h3>
			<form id="make-school-roster-filter" class="make-school-inline-form" onsubmit="return false;">
				<label class="make-school-field">
					<span><?php esc_html_e( 'Class', 'make-school' ); ?></span>
					<select id="make-school-att-class">
						<?php foreach ( $classes as $c ) : ?>
							<option value="<?php echo esc_attr( (string) $c->id ); ?>"><?php echo esc_html( Make_School_Helpers::class_label( $c ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="make-school-field">
					<span><?php esc_html_e( 'Date', 'make-school' ); ?></span>
					<input id="make-school-att-date" type="date" value="<?php echo esc_attr( $today ); ?>" max="<?php echo esc_attr( $today ); ?>" />
				</label>
				<button id="make-school-att-load" type="button" class="make-school-btn make-school-btn-primary"><?php esc_html_e( 'Load roster', 'make-school' ); ?></button>
			</form>

			<div id="make-school-att-status" class="make-school-meta" style="margin-top:10px;"></div>
			<form id="make-school-att-form" style="margin-top:14px;">
				<div id="make-school-att-roster"></div>
				<div class="make-school-att-actions" style="display:none;">
					<button type="submit" class="make-school-btn make-school-btn-primary"><?php esc_html_e( 'Save attendance', 'make-school' ); ?></button>
				</div>
			</form>
		</div>

		<script>
		(function(){
			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

			function el(id){ return document.getElementById(id); }
			function setStatus(msg, kind){
				var s = el('make-school-att-status');
				s.textContent = msg || '';
				s.className = 'make-school-meta' + (kind ? ' make-school-' + kind : '');
			}

			function rosterRow(stu){
				var statusVal = stu.status || 'present';
				function radio(val,label){
					var checked = (statusVal === val) ? ' checked' : '';
					return '<label class="make-school-radio"><input type="radio" name="status_' + stu.user_id + '" value="' + val + '"' + checked + ' /> ' + label + '</label>';
				}
				return ''
					+ '<tr>'
					+ '<td><strong>' + (stu.roll || '') + '</strong></td>'
					+ '<td>' + stu.full_name + '</td>'
					+ '<td>' + radio('present', '<?php echo esc_js( __( 'Present', 'make-school' ) ); ?>') + '</td>'
					+ '<td>' + radio('absent', '<?php echo esc_js( __( 'Absent', 'make-school' ) ); ?>') + '</td>'
					+ '<td>' + radio('late', '<?php echo esc_js( __( 'Late', 'make-school' ) ); ?>') + '</td>'
					+ '</tr>';
			}

			function renderRoster(students){
				if (!students.length){
					el('make-school-att-roster').innerHTML = '<p>' + '<?php echo esc_js( __( 'No students enrolled in this class yet.', 'make-school' ) ); ?>' + '</p>';
					document.querySelector('.make-school-att-actions').style.display = 'none';
					return;
				}
				var html = ''
					+ '<table class="make-school-data-table">'
					+ '<thead><tr><th><?php echo esc_js( __( 'Roll', 'make-school' ) ); ?></th><th><?php echo esc_js( __( 'Student', 'make-school' ) ); ?></th><th><?php echo esc_js( __( 'Present', 'make-school' ) ); ?></th><th><?php echo esc_js( __( 'Absent', 'make-school' ) ); ?></th><th><?php echo esc_js( __( 'Late', 'make-school' ) ); ?></th></tr></thead>'
					+ '<tbody>';
				for (var i=0; i<students.length; i++){ html += rosterRow(students[i]); }
				html += '</tbody></table>';
				el('make-school-att-roster').innerHTML = html;
				document.querySelector('.make-school-att-actions').style.display = '';
			}

			el('make-school-att-load').addEventListener('click', function(){
				setStatus('<?php echo esc_js( __( 'Loading…', 'make-school' ) ); ?>');
				var fd = new FormData();
				fd.append('action', 'make_school_load_roster');
				fd.append('nonce', nonce);
				fd.append('class_id', el('make-school-att-class').value);
				fd.append('date', el('make-school-att-date').value);
				fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:fd })
					.then(function(r){ return r.json(); })
					.then(function(res){
						if (!res || !res.success){ setStatus((res && res.data && res.data.message) || 'Error', 'error'); return; }
						renderRoster(res.data.students || []);
						setStatus(res.data.notice || '');
					})
					.catch(function(){ setStatus('<?php echo esc_js( __( 'Network error.', 'make-school' ) ); ?>', 'error'); });
			});

			el('make-school-att-form').addEventListener('submit', function(e){
				e.preventDefault();
				var roster = el('make-school-att-roster');
				if (!roster) return;
				var inputs = roster.querySelectorAll('input[type=radio]:checked');
				var rows = [];
				for (var i=0; i<inputs.length; i++){
					var nm = inputs[i].name;
					var uid = nm.replace('status_','');
					rows.push({ user_id: uid, status: inputs[i].value });
				}
				if (!rows.length){ setStatus('<?php echo esc_js( __( 'Nothing to save.', 'make-school' ) ); ?>', 'error'); return; }
				setStatus('<?php echo esc_js( __( 'Saving…', 'make-school' ) ); ?>');
				var fd = new FormData();
				fd.append('action', 'make_school_save_attendance');
				fd.append('nonce', nonce);
				fd.append('class_id', el('make-school-att-class').value);
				fd.append('date', el('make-school-att-date').value);
				fd.append('rows', JSON.stringify(rows));
				fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:fd })
					.then(function(r){ return r.json(); })
					.then(function(res){
						if (!res || !res.success){ setStatus((res && res.data && res.data.message) || 'Error', 'error'); return; }
						setStatus(res.data.message || '<?php echo esc_js( __( 'Saved.', 'make-school' ) ); ?>', 'success');
					})
					.catch(function(){ setStatus('<?php echo esc_js( __( 'Network error.', 'make-school' ) ); ?>', 'error'); });
			});
		})();
		</script>
		<?php
	}

	/* =====================================================================
	 * STUDENT SECTION (called from the student/parent dashboard).
	 * ================================================================== */

	/**
	 * Render the student attendance widget.
	 *
	 * @param int    $student_id Student WP user id.
	 * @param object $admission  Admission row.
	 * @return void
	 */
	public function render_student_section( $student_id, $admission ) {
		global $wpdb;
		$table = make_school()->db->table( 'attendance' );

		// Pick month — default current.
		$month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : current_time( 'Y-m' );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = current_time( 'Y-m' );
		}
		$start = $month . '-01';
		$end   = gmdate( 'Y-m-t', strtotime( $start ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT attendance_date, status, remarks FROM {$table}
				 WHERE student_user_id = %d AND attendance_date BETWEEN %s AND %s
				 ORDER BY attendance_date ASC",
				$student_id,
				$start,
				$end
			)
		);

		// Aggregate.
		$summary = array( 'present' => 0, 'absent' => 0, 'late' => 0 );
		foreach ( $rows as $r ) {
			if ( isset( $summary[ $r->status ] ) ) {
				$summary[ $r->status ]++;
			}
		}
		$marked  = array_sum( $summary );
		$percent = $marked > 0 ? round( ( ( $summary['present'] + $summary['late'] ) / $marked ) * 100, 1 ) : 0;

		// 12-month picker.
		$months = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$ts       = strtotime( gmdate( 'Y-m-01' ) . " -$i months" );
			$months[] = gmdate( 'Y-m', $ts );
		}
		?>
		<div class="make-school-card">
			<h3><?php esc_html_e( 'Attendance summary', 'make-school' ); ?></h3>
			<form method="get" class="make-school-inline-form">
				<?php foreach ( $_GET as $k => $v ) : if ( in_array( $k, array( 'month', 'page' ), true ) ) { continue; } ?>
					<input type="hidden" name="<?php echo esc_attr( (string) $k ); ?>" value="<?php echo esc_attr( is_scalar( $v ) ? (string) $v : '' ); ?>" />
				<?php endforeach; ?>
				<input type="hidden" name="section" value="attendance" />
				<label class="make-school-field">
					<span><?php esc_html_e( 'Month', 'make-school' ); ?></span>
					<select name="month" onchange="this.form.submit();">
						<?php foreach ( $months as $m ) : ?>
							<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $month, $m ); ?>><?php echo esc_html( date_i18n( 'F Y', strtotime( $m . '-01' ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</form>

			<div class="make-school-kpi-grid" style="margin-top:14px;">
				<div class="make-school-kpi"><span><?php esc_html_e( 'Days marked', 'make-school' ); ?></span><strong><?php echo esc_html( (string) $marked ); ?></strong></div>
				<div class="make-school-kpi"><span><?php esc_html_e( 'Present', 'make-school' ); ?></span><strong><?php echo esc_html( (string) $summary['present'] ); ?></strong></div>
				<div class="make-school-kpi"><span><?php esc_html_e( 'Late', 'make-school' ); ?></span><strong><?php echo esc_html( (string) $summary['late'] ); ?></strong></div>
				<div class="make-school-kpi"><span><?php esc_html_e( 'Absent', 'make-school' ); ?></span><strong><?php echo esc_html( (string) $summary['absent'] ); ?></strong></div>
				<div class="make-school-kpi"><span><?php esc_html_e( 'Attendance %', 'make-school' ); ?></span><strong><?php echo esc_html( (string) $percent . '%' ); ?></strong></div>
			</div>

			<h4 style="margin-top:18px;"><?php esc_html_e( 'Daily log', 'make-school' ); ?></h4>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No attendance has been recorded for this month.', 'make-school' ); ?></p>
			<?php else : ?>
				<table class="make-school-data-table">
					<thead><tr><th><?php esc_html_e( 'Date', 'make-school' ); ?></th><th><?php esc_html_e( 'Status', 'make-school' ); ?></th><th><?php esc_html_e( 'Remarks', 'make-school' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td><?php echo esc_html( Make_School_Helpers::format_date( $r->attendance_date ) ); ?></td>
								<td><span class="make-school-pill make-school-pill-<?php echo esc_attr( $r->status ); ?>"><?php echo esc_html( Make_School_Helpers::status_label( $r->status ) ); ?></span></td>
								<td><?php echo esc_html( $r->remarks ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		unset( $admission ); // silence unused-arg lint.
	}

	/* =====================================================================
	 * AJAX HANDLERS
	 * ================================================================== */

	/**
	 * AJAX: load the roster for a class on a given date, including any
	 * already-saved attendance rows (so the teacher can edit retro-actively).
	 *
	 * @return void
	 */
	public function ajax_load_roster() {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( self::CAP_TAKE ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'make-school' ) ), 403 );
		}

		$class_id = isset( $_POST['class_id'] ) ? absint( wp_unslash( $_POST['class_id'] ) ) : 0;
		$date     = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		if ( ! $class_id || ! $this->valid_date( $date ) ) {
			wp_send_json_error( array( 'message' => __( 'Class and date are required.', 'make-school' ) ), 400 );
		}

		// Authorise: either the teacher of the class, or an admin.
		$class = Make_School_Helpers::get_class( $class_id );
		if ( ! $class ) {
			wp_send_json_error( array( 'message' => __( 'Class not found.', 'make-school' ) ), 404 );
		}
		if ( ! Make_School_Helpers::is_make_school_admin() && (int) $class->class_teacher_id !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'You can only take attendance for your own class.', 'make-school' ) ), 403 );
		}

		$students = Make_School_Helpers::students_in_class( $class_id );

		// Pull existing rows for this date.
		global $wpdb;
		$table  = make_school()->db->table( 'attendance' );
		$saved  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT student_user_id, status FROM {$table} WHERE class_id = %d AND attendance_date = %s",
				$class_id,
				$date
			),
			OBJECT_K
		);

		$payload = array();
		foreach ( $students as $s ) {
			$payload[] = array(
				'user_id'   => (int) $s->user_id,
				'roll'      => (string) $s->roll_number,
				'full_name' => (string) $s->full_name,
				'status'    => isset( $saved[ $s->user_id ] ) ? (string) $saved[ $s->user_id ]->status : 'present',
			);
		}

		$notice = $saved
			? sprintf(
				/* translators: %d: count */
				esc_html( _n( '%d existing entry pre-loaded for this date.', '%d existing entries pre-loaded for this date.', count( $saved ), 'make-school' ) ),
				count( $saved )
			)
			: '';

		wp_send_json_success(
			array(
				'students' => $payload,
				'notice'   => $notice,
			)
		);
	}

	/**
	 * AJAX: save attendance for a class on a given date. Uses $wpdb->replace()
	 * which performs an INSERT or UPDATE based on the unique constraint on
	 * (student_user_id, class_id, attendance_date).
	 *
	 * @return void
	 */
	public function ajax_save_attendance() {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( self::CAP_TAKE ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'make-school' ) ), 403 );
		}

		$class_id = isset( $_POST['class_id'] ) ? absint( wp_unslash( $_POST['class_id'] ) ) : 0;
		$date     = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$rows_raw = isset( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : '[]';
		if ( ! $class_id || ! $this->valid_date( $date ) ) {
			wp_send_json_error( array( 'message' => __( 'Class and date are required.', 'make-school' ) ), 400 );
		}

		$class = Make_School_Helpers::get_class( $class_id );
		if ( ! $class ) {
			wp_send_json_error( array( 'message' => __( 'Class not found.', 'make-school' ) ), 404 );
		}
		if ( ! Make_School_Helpers::is_make_school_admin() && (int) $class->class_teacher_id !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'You can only take attendance for your own class.', 'make-school' ) ), 403 );
		}

		$rows = json_decode( (string) $rows_raw, true );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid roster payload.', 'make-school' ) ), 400 );
		}

		// Build a quick lookup of valid student user-ids for this class.
		$valid_students = array();
		foreach ( Make_School_Helpers::students_in_class( $class_id ) as $s ) {
			$valid_students[ (int) $s->user_id ] = true;
		}

		global $wpdb;
		$table = make_school()->db->table( 'attendance' );
		$now   = current_time( 'mysql' );
		$saved = 0;

		foreach ( $rows as $row ) {
			$uid = isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0;
			$st  = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : '';
			if ( ! $uid || ! isset( $valid_students[ $uid ] ) ) {
				continue;
			}
			if ( ! in_array( $st, array( 'present', 'absent', 'late' ), true ) ) {
				continue;
			}
			$res = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'branch_id'       => (int) $class->branch_id,
					'session'         => (string) $class->session,
					'class_id'        => $class_id,
					'student_user_id' => $uid,
					'attendance_date' => $date,
					'status'          => $st,
					'remarks'         => '',
					'marked_by'       => (int) get_current_user_id(),
					'created_at'      => $now,
					'updated_at'      => $now,
				),
				array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			if ( false !== $res ) {
				$saved++;
			}
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: count */
					esc_html( _n( '%d student saved.', '%d students saved.', $saved, 'make-school' ) ),
					$saved
				),
				'count'   => $saved,
			)
		);
	}

	/* =====================================================================
	 * UTILITIES
	 * ================================================================== */

	/**
	 * Validate a Y-m-d date string (and disallow far-future dates).
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	private function valid_date( $date ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			return false;
		}
		$ts = strtotime( $date );
		if ( false === $ts ) {
			return false;
		}
		// Disallow more than 1 day in the future.
		if ( $ts > strtotime( '+1 day', current_time( 'timestamp' ) ) ) {
			return false;
		}
		return true;
	}
}
