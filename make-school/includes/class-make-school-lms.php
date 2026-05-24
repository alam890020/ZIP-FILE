<?php
/**
 * MAKE SCHOOL — Built-in LMS (Lessons & Study Materials).
 *
 * Owns:
 *   - Admin sub-page 'make-school-lms' for full lesson CRUD (admins).
 *   - Teacher dashboard section: post a video/PDF, list own lessons,
 *     toggle publish state, delete.
 *   - Student dashboard section: filter by subject, watch YouTube
 *     embeds inline, download PDF study materials.
 *   - Type values: 'video' (uses youtube_id) or 'pdf' (uses file_url).
 *
 * @package MakeSchool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Make_School_LMS
 */
class Make_School_LMS {

	const CAP_MANAGE   = 'make_school_manage_school';
	const CAP_PUBLISH  = 'make_school_publish_lessons';
	const NONCE_ACTION = 'make_school_lms';
	const ALLOWED_PDF  = array( 'pdf' );

	/**
	 * Constructor — wires hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_admin_action' ) );
		add_action( 'init', array( $this, 'maybe_handle_frontend_action' ) );
	}

	/* =====================================================================
	 * ADMIN
	 * ================================================================== */

	/**
	 * Register admin sub-page.
	 *
	 * @return void
	 */
	public function register_admin_menus() {
		add_submenu_page(
			Make_School_Admin::PARENT_SLUG,
			__( 'LMS — Lessons', 'make-school' ),
			__( 'LMS', 'make-school' ),
			self::CAP_MANAGE,
			'make-school-lms',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the LMS admin page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'make-school' ) );
		}
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'list';
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		echo '<div class="wrap make-school-wrap"><h1>' . esc_html__( 'LMS — Lessons & Study Materials', 'make-school' );
		if ( 'list' === $mode ) {
			echo ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=make-school-lms&mode=edit' ) ) . '">' . esc_html__( 'Add New', 'make-school' ) . '</a>';
		}
		echo '</h1>';
		make_school()->admin->render_flash();

		if ( 'edit' === $mode ) {
			$this->render_lesson_form( $id );
		} else {
			$this->render_lessons_list();
		}
		echo '</div>';
	}

	/**
	 * Admin lessons list with filter.
	 *
	 * @return void
	 */
	private function render_lessons_list() {
		global $wpdb;
		$class_id = isset( $_GET['class_id'] ) ? absint( wp_unslash( $_GET['class_id'] ) ) : 0;

		$where  = '1=1';
		$params = array();
		if ( $class_id ) {
			$where    = 'class_id = %d';
			$params[] = $class_id;
		}
		$sql  = 'SELECT * FROM ' . make_school()->db->table( 'lessons' ) . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT 200';
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ); // phpcs:ignore

		$classes = Make_School_Helpers::get_classes();
		?>
		<form method="get" class="make-school-inline-form">
			<input type="hidden" name="page" value="make-school-lms" />
			<select name="class_id">
				<option value="0"><?php esc_html_e( 'Any class', 'make-school' ); ?></option>
				<?php foreach ( $classes as $c ) : ?>
					<option value="<?php echo esc_attr( (string) $c->id ); ?>" <?php selected( $class_id, (int) $c->id ); ?>><?php echo esc_html( Make_School_Helpers::class_label( $c ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'make-school' ); ?></button>
		</form>

		<table class="wp-list-table widefat fixed striped" style="margin-top:14px;">
			<thead><tr>
				<th><?php esc_html_e( 'Title', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Class', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Subject', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Type', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Posted', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Status', 'make-school' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No lessons posted yet.', 'make-school' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) :
					$class  = Make_School_Helpers::get_class( (int) $r->class_id );
					$author = $r->posted_by ? get_user_by( 'id', (int) $r->posted_by ) : null;
					?>
					<tr>
						<td><strong><?php echo esc_html( $r->title ); ?></strong></td>
						<td><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></td>
						<td><?php echo esc_html( $r->subject ); ?></td>
						<td><?php echo esc_html( strtoupper( (string) $r->type ) ); ?></td>
						<td><?php echo $author ? esc_html( $author->display_name ) : '—'; ?><br /><small><?php echo esc_html( Make_School_Helpers::format_datetime( $r->created_at ) ); ?></small></td>
						<td><span class="make-school-pill make-school-pill-<?php echo esc_attr( $r->status ); ?>"><?php echo esc_html( Make_School_Helpers::status_label( $r->status ) ); ?></span></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-lms&mode=edit&id=' . (int) $r->id ) ); ?>"><?php esc_html_e( 'Edit', 'make-school' ); ?></a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=make-school-lms&make_school_action=delete_lesson&id=' . (int) $r->id ), self::NONCE_ACTION ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this lesson?', 'make-school' ) ); ?>');" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'make-school' ); ?></a>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Admin lesson add/edit form.
	 *
	 * @param int $id Lesson ID.
	 * @return void
	 */
	private function render_lesson_form( $id ) {
		$row     = $id ? $this->get_lesson( $id ) : null;
		$g       = function ( $key, $default = '' ) use ( $row ) {
			return $row && isset( $row->$key ) ? $row->$key : $default;
		};
		$classes = Make_School_Helpers::get_classes();
		?>
		<form method="post" enctype="multipart/form-data" class="make-school-form">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="make_school_action" value="save_lesson" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<table class="form-table">
				<tr><th><label><?php esc_html_e( 'Title', 'make-school' ); ?> *</label></th>
					<td><input type="text" name="title" value="<?php echo esc_attr( $g( 'title' ) ); ?>" class="regular-text" required /></td></tr>
				<tr><th><label><?php esc_html_e( 'Class', 'make-school' ); ?> *</label></th>
					<td>
						<select name="class_id" required>
							<option value=""><?php esc_html_e( '— Select —', 'make-school' ); ?></option>
							<?php foreach ( $classes as $c ) : ?>
								<option value="<?php echo esc_attr( (string) $c->id ); ?>" <?php selected( (int) $g( 'class_id' ), (int) $c->id ); ?>><?php echo esc_html( Make_School_Helpers::class_label( $c ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<tr><th><label><?php esc_html_e( 'Subject', 'make-school' ); ?></label></th>
					<td><input type="text" name="subject" value="<?php echo esc_attr( $g( 'subject' ) ); ?>" class="regular-text" /></td></tr>
				<tr><th><label><?php esc_html_e( 'Type', 'make-school' ); ?> *</label></th>
					<td>
						<select name="type" id="ms-lesson-type" required>
							<option value="video" <?php selected( $g( 'type', 'video' ), 'video' ); ?>><?php esc_html_e( 'YouTube video', 'make-school' ); ?></option>
							<option value="pdf" <?php selected( $g( 'type' ), 'pdf' ); ?>><?php esc_html_e( 'PDF / file', 'make-school' ); ?></option>
						</select>
					</td></tr>
				<tr class="ms-lesson-video-row"><th><label><?php esc_html_e( 'YouTube URL or ID', 'make-school' ); ?></label></th>
					<td><input type="text" name="youtube_url" value="<?php echo esc_attr( (string) $g( 'youtube_id' ) ); ?>" class="regular-text" placeholder="https://youtube.com/watch?v=…" /></td></tr>
				<tr class="ms-lesson-pdf-row"><th><label><?php esc_html_e( 'PDF / file upload', 'make-school' ); ?></label></th>
					<td>
						<input type="file" name="lesson_file" accept=".pdf" />
						<?php if ( $g( 'file_url' ) ) : ?>
							<p class="description"><?php esc_html_e( 'Current file:', 'make-school' ); ?> <a href="<?php echo esc_url( $g( 'file_url' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( basename( $g( 'file_url' ) ) ); ?></a></p>
						<?php endif; ?>
					</td></tr>
				<tr><th><label><?php esc_html_e( 'Description', 'make-school' ); ?></label></th>
					<td><textarea name="description" rows="4" class="large-text"><?php echo esc_textarea( $g( 'description' ) ); ?></textarea></td></tr>
				<tr><th><label><?php esc_html_e( 'Status', 'make-school' ); ?></label></th>
					<td>
						<select name="status">
							<option value="published" <?php selected( $g( 'status', 'published' ), 'published' ); ?>><?php esc_html_e( 'Published', 'make-school' ); ?></option>
							<option value="inactive" <?php selected( $g( 'status', 'published' ), 'inactive' ); ?>><?php esc_html_e( 'Hidden', 'make-school' ); ?></option>
						</select>
					</td></tr>
			</table>
			<p class="submit">
				<button class="button button-primary" type="submit"><?php echo esc_html( $id ? __( 'Update lesson', 'make-school' ) : __( 'Publish lesson', 'make-school' ) ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-lms' ) ); ?>"><?php esc_html_e( 'Cancel', 'make-school' ); ?></a>
			</p>
		</form>
		<script>
		(function(){
			function sync(){
				var t = document.getElementById('ms-lesson-type').value;
				document.querySelectorAll('.ms-lesson-video-row').forEach(function(r){ r.style.display = (t==='video') ? '' : 'none'; });
				document.querySelectorAll('.ms-lesson-pdf-row').forEach(function(r){ r.style.display = (t==='pdf') ? '' : 'none'; });
			}
			document.getElementById('ms-lesson-type').addEventListener('change', sync);
			sync();
		})();
		</script>
		<?php
	}

	/* =====================================================================
	 * ADMIN — ACTION ROUTER
	 * ================================================================== */

	/**
	 * Dispatch admin actions (save / delete).
	 *
	 * @return void
	 */
	public function maybe_handle_admin_action() {
		if ( ! is_admin() ) {
			return;
		}

		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			$action = isset( $_POST['make_school_action'] ) ? sanitize_key( wp_unslash( $_POST['make_school_action'] ) ) : '';
			if ( 'save_lesson' === $action && current_user_can( self::CAP_MANAGE ) ) {
				$this->save_lesson( admin_url( 'admin.php?page=make-school-lms' ) );
			}
		}

		$g = isset( $_GET['make_school_action'] ) ? sanitize_key( wp_unslash( $_GET['make_school_action'] ) ) : '';
		if ( 'delete_lesson' === $g && current_user_can( self::CAP_MANAGE ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			if ( $id ) {
				global $wpdb;
				$wpdb->delete( make_school()->db->table( 'lessons' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore
				make_school()->admin->push_flash( __( 'Lesson deleted.', 'make-school' ) );
			}
			$this->redirect( admin_url( 'admin.php?page=make-school-lms' ) );
		}
	}

	/* =====================================================================
	 * FRONTEND ACTION (teacher posting from front-end dashboard)
	 * ================================================================== */

	/**
	 * Handle the teacher front-end "post lesson" submission.
	 *
	 * @return void
	 */
	public function maybe_handle_frontend_action() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			return;
		}
		$action = isset( $_POST['make_school_action'] ) ? sanitize_key( wp_unslash( $_POST['make_school_action'] ) ) : '';
		if ( 'frontend_save_lesson' !== $action ) {
			return;
		}
		if ( ! current_user_can( self::CAP_PUBLISH ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ACTION );

		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : home_url( '/' );
		$this->save_lesson( $ref );
	}

	/**
	 * Common save routine used by both admin and front-end forms.
	 *
	 * @param string $redirect_to Redirect target.
	 * @return void
	 */
	private function save_lesson( $redirect_to ) {
		check_admin_referer( self::NONCE_ACTION );

		global $wpdb;
		$table = make_school()->db->table( 'lessons' );
		$now   = current_time( 'mysql' );
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$class_id = isset( $_POST['class_id'] ) ? absint( $_POST['class_id'] ) : 0;
		$class    = Make_School_Helpers::get_class( $class_id );
		$type     = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'video';
		$type     = in_array( $type, array( 'video', 'pdf' ), true ) ? $type : 'video';

		$data = array(
			'class_id'    => $class_id,
			'branch_id'   => $class ? (int) $class->branch_id : 0,
			'session'     => $class && $class->session ? (string) $class->session : Make_School_Helpers::current_session(),
			'subject'     => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
			'title'       => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'type'        => $type,
			'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'status'      => ( isset( $_POST['status'] ) && 'inactive' === $_POST['status'] ) ? 'inactive' : 'published',
			'updated_at'  => $now,
		);

		if ( '' === $data['title'] || ! $class_id ) {
			make_school()->admin->push_flash( __( 'Title and class are required.', 'make-school' ), 'error' );
			$this->redirect( $redirect_to );
		}

		// Type-specific payload.
		if ( 'video' === $type ) {
			$yt = isset( $_POST['youtube_url'] ) ? sanitize_text_field( wp_unslash( $_POST['youtube_url'] ) ) : '';
			$id_yt = Make_School_Helpers::youtube_id( $yt );
			if ( '' === $id_yt ) {
				make_school()->admin->push_flash( __( 'Please provide a valid YouTube URL or 11-character video ID.', 'make-school' ), 'error' );
				$this->redirect( $redirect_to );
			}
			$data['youtube_id'] = $id_yt;
			$data['file_url']   = '';
		} else {
			// PDF — keep existing on update unless a new one is uploaded.
			$existing = $id ? $this->get_lesson( $id ) : null;
			$data['file_url']   = $existing ? (string) $existing->file_url : '';
			$data['youtube_id'] = '';
			$uploaded = $this->handle_pdf_upload( 'lesson_file' );
			if ( is_wp_error( $uploaded ) ) {
				make_school()->admin->push_flash( $uploaded->get_error_message(), 'error' );
				$this->redirect( $redirect_to );
			}
			if ( is_string( $uploaded ) && '' !== $uploaded ) {
				$data['file_url'] = $uploaded;
			}
			if ( ! $id && '' === $data['file_url'] ) {
				make_school()->admin->push_flash( __( 'Please upload a PDF file.', 'make-school' ), 'error' );
				$this->redirect( $redirect_to );
			}
		}

		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore
			make_school()->admin->push_flash( __( 'Lesson updated.', 'make-school' ) );
		} else {
			$data['posted_by']  = (int) get_current_user_id();
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data ); // phpcs:ignore
			make_school()->admin->push_flash( __( 'Lesson published.', 'make-school' ) );
		}

		$this->redirect( $redirect_to );
	}

	/**
	 * Validate + ingest a PDF upload.
	 *
	 * @param string $field $_FILES key.
	 * @return string|WP_Error|null URL on success, '' if no file, WP_Error on bad input.
	 */
	private function handle_pdf_upload( $field ) {
		if ( empty( $_FILES[ $field ] ) || empty( $_FILES[ $field ]['name'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( UPLOAD_ERR_NO_FILE === (int) $_FILES[ $field ]['error'] ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( UPLOAD_ERR_OK !== (int) $_FILES[ $field ]['error'] ) {
			return new WP_Error( 'upload_error', __( 'There was a problem uploading the file.', 'make-school' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$name = sanitize_file_name( wp_unslash( $_FILES[ $field ]['name'] ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_PDF, true ) ) {
			return new WP_Error( 'upload_ext', __( 'Only PDF files are allowed for study materials.', 'make-school' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$file = $_FILES[ $field ];
		$res  = wp_handle_upload( $file, array( 'test_form' => false ) );
		if ( isset( $res['error'] ) ) {
			return new WP_Error( 'upload_failed', (string) $res['error'] );
		}
		return isset( $res['url'] ) ? esc_url_raw( (string) $res['url'] ) : '';
	}

	/* =====================================================================
	 * STUDENT DASHBOARD SECTION
	 * ================================================================== */

	/**
	 * Render the student lessons section.
	 *
	 * @param int    $student_id Student WP user ID.
	 * @param object $admission  Admission row.
	 * @return void
	 */
	public function render_student_section( $student_id, $admission ) {
		global $wpdb;
		$table  = make_school()->db->table( 'lessons' );
		$class  = (int) $admission->class_id;

		$subject = isset( $_GET['subject'] ) ? sanitize_text_field( wp_unslash( $_GET['subject'] ) ) : '';

		$where  = array( "status = 'published'", 'class_id = %d' );
		$params = array( $class );
		if ( '' !== $subject ) {
			$where[]  = 'subject = %s';
			$params[] = $subject;
		}
		$sql  = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore

		// Distinct subjects for this class (for filter chip strip).
		$subjects = $wpdb->get_col( // phpcs:ignore
			$wpdb->prepare(
				"SELECT DISTINCT subject FROM {$table} WHERE class_id = %d AND status = 'published' AND subject != '' ORDER BY subject ASC",
				$class
			)
		);
		?>
		<div class="make-school-card">
			<h3><?php esc_html_e( 'Lessons & study materials', 'make-school' ); ?></h3>

			<?php if ( ! empty( $subjects ) ) : ?>
				<div class="make-school-chip-strip">
					<a class="make-school-chip <?php echo '' === $subject ? 'is-active' : ''; ?>" href="<?php echo esc_url( remove_query_arg( 'subject' ) ); ?>"><?php esc_html_e( 'All', 'make-school' ); ?></a>
					<?php foreach ( $subjects as $sub ) : ?>
						<a class="make-school-chip <?php echo $subject === $sub ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'subject', rawurlencode( (string) $sub ) ) ); ?>"><?php echo esc_html( $sub ); ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<p style="margin-top:14px;"><?php esc_html_e( 'No lessons published for your class yet.', 'make-school' ); ?></p>
			<?php else : ?>
				<div class="make-school-lessons-grid">
					<?php foreach ( $rows as $r ) : ?>
						<div class="make-school-lesson">
							<div class="make-school-lesson-media">
								<?php if ( 'video' === $r->type && $r->youtube_id ) : ?>
									<div class="make-school-yt"><iframe loading="lazy" src="<?php echo esc_url( 'https://www.youtube.com/embed/' . $r->youtube_id ); ?>" title="<?php echo esc_attr( $r->title ); ?>" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>
								<?php elseif ( 'pdf' === $r->type && $r->file_url ) : ?>
									<a class="make-school-lesson-doc" href="<?php echo esc_url( $r->file_url ); ?>" target="_blank" rel="noopener">
										<span class="dashicons dashicons-media-document" aria-hidden="true"></span>
										<span><?php echo esc_html( basename( (string) $r->file_url ) ); ?></span>
									</a>
								<?php endif; ?>
							</div>
							<div class="make-school-lesson-meta">
								<h4><?php echo esc_html( $r->title ); ?></h4>
								<?php if ( $r->subject ) : ?>
									<span class="make-school-pill"><?php echo esc_html( $r->subject ); ?></span>
								<?php endif; ?>
								<?php if ( $r->description ) : ?>
									<p><?php echo wp_kses_post( wpautop( esc_html( (string) $r->description ) ) ); ?></p>
								<?php endif; ?>
								<small><?php echo esc_html( Make_School_Helpers::format_date( $r->created_at ) ); ?></small>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		unset( $student_id );
	}

	/* =====================================================================
	 * TEACHER DASHBOARD SECTION
	 * ================================================================== */

	/**
	 * Render the teacher LMS section: post a new lesson + list own lessons.
	 *
	 * @param int $teacher_id Teacher WP user ID.
	 * @return void
	 */
	public function render_teacher_section( $teacher_id ) {
		if ( ! current_user_can( self::CAP_PUBLISH ) ) {
			echo '<div class="make-school-alert make-school-alert-error">' . esc_html__( 'You do not have permission to publish lessons.', 'make-school' ) . '</div>';
			return;
		}
		$classes = Make_School_Helpers::teacher_classes( (int) $teacher_id );
		if ( empty( $classes ) && Make_School_Helpers::is_make_school_admin() ) {
			$classes = Make_School_Helpers::get_classes();
		}
		?>
		<div class="make-school-card">
			<h3><?php esc_html_e( 'Post a new lesson', 'make-school' ); ?></h3>

			<?php if ( empty( $classes ) ) : ?>
				<p><?php esc_html_e( 'You have no classes assigned.', 'make-school' ); ?></p>
			<?php else : ?>
				<form method="post" enctype="multipart/form-data" class="make-school-form">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="make_school_action" value="frontend_save_lesson" />

					<div class="make-school-grid-2">
						<label class="make-school-field">
							<span><?php esc_html_e( 'Title', 'make-school' ); ?> *</span>
							<input type="text" name="title" required />
						</label>
						<label class="make-school-field">
							<span><?php esc_html_e( 'Class', 'make-school' ); ?> *</span>
							<select name="class_id" required>
								<?php foreach ( $classes as $c ) : ?>
									<option value="<?php echo esc_attr( (string) $c->id ); ?>"><?php echo esc_html( Make_School_Helpers::class_label( $c ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="make-school-field">
							<span><?php esc_html_e( 'Subject', 'make-school' ); ?></span>
							<input type="text" name="subject" />
						</label>
						<label class="make-school-field">
							<span><?php esc_html_e( 'Type', 'make-school' ); ?> *</span>
							<select name="type" id="ms-fe-lesson-type" required>
								<option value="video"><?php esc_html_e( 'YouTube video', 'make-school' ); ?></option>
								<option value="pdf"><?php esc_html_e( 'PDF / file', 'make-school' ); ?></option>
							</select>
						</label>
					</div>

					<label class="make-school-field ms-fe-video">
						<span><?php esc_html_e( 'YouTube URL or ID', 'make-school' ); ?></span>
						<input type="text" name="youtube_url" placeholder="https://youtube.com/watch?v=…" />
					</label>
					<label class="make-school-field ms-fe-pdf" style="display:none;">
						<span><?php esc_html_e( 'PDF file', 'make-school' ); ?></span>
						<input type="file" name="lesson_file" accept=".pdf" />
					</label>
					<label class="make-school-field">
						<span><?php esc_html_e( 'Description', 'make-school' ); ?></span>
						<textarea name="description" rows="3"></textarea>
					</label>

					<button type="submit" class="make-school-btn make-school-btn-primary"><?php esc_html_e( 'Publish lesson', 'make-school' ); ?></button>
				</form>
				<script>
				(function(){
					var t = document.getElementById('ms-fe-lesson-type');
					function s(){
						var v = t.value;
						document.querySelectorAll('.ms-fe-video').forEach(function(r){ r.style.display = (v==='video') ? '' : 'none'; });
						document.querySelectorAll('.ms-fe-pdf').forEach(function(r){ r.style.display = (v==='pdf') ? '' : 'none'; });
					}
					t.addEventListener('change', s); s();
				})();
				</script>
			<?php endif; ?>
		</div>

		<?php
		// Teacher's recent lessons.
		global $wpdb;
		$mine = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				'SELECT * FROM ' . make_school()->db->table( 'lessons' ) . ' WHERE posted_by = %d ORDER BY id DESC LIMIT 50',
				(int) $teacher_id
			)
		);
		?>
		<div class="make-school-card" style="margin-top:18px;">
			<h3><?php esc_html_e( 'My recent lessons', 'make-school' ); ?></h3>
			<?php if ( empty( $mine ) ) : ?>
				<p><?php esc_html_e( 'You have not posted any lessons yet.', 'make-school' ); ?></p>
			<?php else : ?>
				<table class="make-school-data-table">
					<thead><tr>
						<th><?php esc_html_e( 'Title', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Class', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Subject', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Type', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Posted', 'make-school' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $mine as $r ) :
							$class = Make_School_Helpers::get_class( (int) $r->class_id );
							?>
							<tr>
								<td><strong><?php echo esc_html( $r->title ); ?></strong></td>
								<td><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></td>
								<td><?php echo esc_html( $r->subject ); ?></td>
								<td><?php echo esc_html( strtoupper( (string) $r->type ) ); ?></td>
								<td><?php echo esc_html( Make_School_Helpers::format_datetime( $r->created_at ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * UTILITIES
	 * ================================================================== */

	/**
	 * Single-row lesson fetch.
	 *
	 * @param int $id Lesson ID.
	 * @return object|null
	 */
	private function get_lesson( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . make_school()->db->table( 'lessons' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
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
