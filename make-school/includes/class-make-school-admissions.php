<?php
/**
 * MAKE SCHOOL — Admissions module.
 *
 * Owns:
 *   - [make_school_admission_form] frontend shortcode (multipart upload).
 *   - Public submission handler with nonce verification, sanitisation,
 *     file validation via wp_handle_upload().
 *   - Admin "Admissions" sub-menu with status filter, view, approve, reject.
 *   - Approval workflow: creates a make_school_student WP user, maps the
 *     admission row, generates a unique roll number, sends email.
 *
 * @package MakeSchool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Make_School_Admissions
 */
class Make_School_Admissions {

	const NONCE_FORM   = 'make_school_admission_form';
	const NONCE_ADMIN  = 'make_school_admission_admin';
	const CAP_REVIEW   = 'make_school_manage_school';
	const ALLOWED_DOC  = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf' );

	/**
	 * Constructor — wires hooks.
	 */
	public function __construct() {
		add_shortcode( 'make_school_admission_form', array( $this, 'render_form' ) );

		add_action( 'init', array( $this, 'maybe_handle_submission' ) );

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_admin_action' ) );
	}

	/* =====================================================================
	 * FRONTEND — SHORTCODE
	 * ================================================================== */

	/**
	 * Render the [make_school_admission_form] shortcode.
	 *
	 * @param array $atts Attrs.
	 * @return string HTML.
	 */
	public function render_form( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'success_message' => __( 'Thank you! Your admission application has been received. Our team will contact you shortly.', 'make-school' ),
			),
			(array) $atts,
			'make_school_admission_form'
		);

		// Show success message after a successful POST + redirect.
		if ( isset( $_GET['admission'] ) && 'success' === $_GET['admission'] ) {
			return '<div class="make-school-alert make-school-alert-success">' . esc_html( $atts['success_message'] ) . '</div>';
		}

		$flash   = $this->pop_flash();
		$classes = Make_School_Helpers::get_classes( 0, Make_School_Helpers::current_session() );
		if ( empty( $classes ) ) {
			$classes = Make_School_Helpers::get_classes(); // fallback to any session.
		}

		ob_start();
		?>
		<div class="make-school-form-wrap">
			<?php if ( $flash ) : ?>
				<div class="make-school-alert make-school-alert-error"><?php echo esc_html( $flash ); ?></div>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data" class="make-school-admission-form" novalidate>
				<?php wp_nonce_field( self::NONCE_FORM, 'make_school_admission_nonce' ); ?>
				<input type="hidden" name="make_school_action" value="submit_admission" />

				<h3><?php esc_html_e( 'Student details', 'make-school' ); ?></h3>
				<div class="make-school-grid-2">
					<label class="make-school-field">
						<span><?php esc_html_e( 'Full Name', 'make-school' ); ?> *</span>
						<input type="text" name="full_name" required maxlength="190" />
					</label>
					<label class="make-school-field">
						<span><?php esc_html_e( 'Date of Birth', 'make-school' ); ?> *</span>
						<input type="date" name="dob" required />
					</label>
					<label class="make-school-field">
						<span><?php esc_html_e( 'Gender', 'make-school' ); ?> *</span>
						<select name="gender" required>
							<option value=""><?php esc_html_e( '— Select —', 'make-school' ); ?></option>
							<option value="male"><?php esc_html_e( 'Male', 'make-school' ); ?></option>
							<option value="female"><?php esc_html_e( 'Female', 'make-school' ); ?></option>
							<option value="other"><?php esc_html_e( 'Other', 'make-school' ); ?></option>
						</select>
					</label>
					<label class="make-school-field">
						<span><?php esc_html_e( 'Blood Group', 'make-school' ); ?></span>
						<select name="blood_group">
							<option value=""><?php esc_html_e( '— Select —', 'make-school' ); ?></option>
							<?php foreach ( array( 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-' ) as $bg ) : ?>
								<option value="<?php echo esc_attr( $bg ); ?>"><?php echo esc_html( $bg ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>

				<h3><?php esc_html_e( 'Parents / Guardian', 'make-school' ); ?></h3>
				<div class="make-school-grid-2">
					<label class="make-school-field">
						<span><?php esc_html_e( "Father's Name", 'make-school' ); ?> *</span>
						<input type="text" name="father_name" required maxlength="190" />
					</label>
					<label class="make-school-field">
						<span><?php esc_html_e( "Mother's Name", 'make-school' ); ?> *</span>
						<input type="text" name="mother_name" required maxlength="190" />
					</label>
				</div>

				<h3><?php esc_html_e( 'Contact details', 'make-school' ); ?></h3>
				<div class="make-school-grid-2">
					<label class="make-school-field">
						<span><?php esc_html_e( 'Email', 'make-school' ); ?> *</span>
						<input type="email" name="email" required />
					</label>
					<label class="make-school-field">
						<span><?php esc_html_e( 'Mobile Number', 'make-school' ); ?> *</span>
						<input type="tel" name="mobile" required maxlength="40" />
					</label>
				</div>
				<label class="make-school-field">
					<span><?php esc_html_e( 'Address', 'make-school' ); ?></span>
					<textarea name="address" rows="3"></textarea>
				</label>

				<h3><?php esc_html_e( 'Academic preference', 'make-school' ); ?></h3>
				<label class="make-school-field">
					<span><?php esc_html_e( 'Class', 'make-school' ); ?> *</span>
					<select name="class_id" required>
						<option value=""><?php esc_html_e( '— Select a class —', 'make-school' ); ?></option>
						<?php foreach ( $classes as $c ) : ?>
							<option value="<?php echo esc_attr( (string) $c->id ); ?>">
								<?php echo esc_html( Make_School_Helpers::class_label( $c ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<h3><?php esc_html_e( 'Create your account', 'make-school' ); ?></h3>
				<div class="make-school-grid-2">
					<label class="make-school-field">
						<span><?php esc_html_e( 'Username', 'make-school' ); ?> *</span>
						<input type="text" name="username" required maxlength="60" autocomplete="username" />
					</label>
					<label class="make-school-field">
						<span><?php esc_html_e( 'Password', 'make-school' ); ?> *</span>
						<input type="password" name="user_password" required minlength="6" autocomplete="new-password" />
					</label>
				</div>

				<h3><?php esc_html_e( 'Documents', 'make-school' ); ?></h3>
				<div class="make-school-grid-2">
					<label class="make-school-field">
						<span><?php esc_html_e( 'Photo (jpg/png)', 'make-school' ); ?></span>
						<input type="file" name="photo" accept="image/*" />
					</label>
					<label class="make-school-field">
						<span><?php esc_html_e( 'Supporting document (pdf/image)', 'make-school' ); ?></span>
						<input type="file" name="document" accept=".pdf,image/*" />
					</label>
				</div>

				<label class="make-school-checkbox">
					<input type="checkbox" name="agree" value="1" required />
					<span><?php esc_html_e( 'I confirm the information above is accurate.', 'make-school' ); ?></span>
				</label>

				<button type="submit" class="make-school-btn make-school-btn-primary">
					<?php esc_html_e( 'Submit application', 'make-school' ); ?>
				</button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* =====================================================================
	 * FRONTEND — SUBMISSION HANDLER
	 * ================================================================== */

	/**
	 * Detect and handle a public admission submission on init.
	 *
	 * @return void
	 */
	public function maybe_handle_submission() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			return;
		}
		if ( ! isset( $_POST['make_school_action'] ) || 'submit_admission' !== $_POST['make_school_action'] ) {
			return;
		}
		if ( ! isset( $_POST['make_school_admission_nonce'] ) ||
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['make_school_admission_nonce'] ) ), self::NONCE_FORM ) ) {
			$this->push_flash( __( 'Security check failed. Please try again.', 'make-school' ) );
			$this->safe_back();
			return;
		}

		// Sanitise.
		$data = array(
			'full_name'   => isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '',
			'dob'         => isset( $_POST['dob'] ) ? sanitize_text_field( wp_unslash( $_POST['dob'] ) ) : '',
			'gender'      => isset( $_POST['gender'] ) ? sanitize_key( wp_unslash( $_POST['gender'] ) ) : '',
			'blood_group' => isset( $_POST['blood_group'] ) ? sanitize_text_field( wp_unslash( $_POST['blood_group'] ) ) : '',
			'father_name' => isset( $_POST['father_name'] ) ? sanitize_text_field( wp_unslash( $_POST['father_name'] ) ) : '',
			'mother_name' => isset( $_POST['mother_name'] ) ? sanitize_text_field( wp_unslash( $_POST['mother_name'] ) ) : '',
			'email'       => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'mobile'      => isset( $_POST['mobile'] ) ? sanitize_text_field( wp_unslash( $_POST['mobile'] ) ) : '',
			'address'     => isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '',
			'class_id'    => isset( $_POST['class_id'] ) ? absint( $_POST['class_id'] ) : 0,
			'username'    => isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ), true ) : '',
			'password'    => isset( $_POST['user_password'] ) ? (string) wp_unslash( $_POST['user_password'] ) : '',
		);

		// Validate.
		$required = array( 'full_name', 'dob', 'gender', 'father_name', 'mother_name', 'email', 'mobile' );
		foreach ( $required as $f ) {
			if ( '' === $data[ $f ] ) {
				$this->push_flash( __( 'Please complete all required fields.', 'make-school' ) );
				$this->safe_back();
				return;
			}
		}
		if ( ! is_email( $data['email'] ) ) {
			$this->push_flash( __( 'Please enter a valid email address.', 'make-school' ) );
			$this->safe_back();
			return;
		}
		if ( ! in_array( $data['gender'], array( 'male', 'female', 'other' ), true ) ) {
			$this->push_flash( __( 'Please choose a valid gender option.', 'make-school' ) );
			$this->safe_back();
			return;
		}
		if ( ! $data['class_id'] || ! Make_School_Helpers::get_class( $data['class_id'] ) ) {
			$this->push_flash( __( 'Please select a valid class.', 'make-school' ) );
			$this->safe_back();
			return;
		}
		if ( empty( $_POST['agree'] ) ) {
			$this->push_flash( __( 'Please confirm the declaration to continue.', 'make-school' ) );
			$this->safe_back();
			return;
		}

		// Validate username & password.
		if ( '' === $data['username'] || '' === $data['password'] ) {
			$this->push_flash( __( 'Username and password are required to create your account.', 'make-school' ) );
			$this->safe_back();
			return;
		}
		if ( strlen( $data['password'] ) < 6 ) {
			$this->push_flash( __( 'Password must be at least 6 characters.', 'make-school' ) );
			$this->safe_back();
			return;
		}
		if ( username_exists( $data['username'] ) ) {
			$this->push_flash( __( 'That username is already taken. Please choose another.', 'make-school' ) );
			$this->safe_back();
			return;
		}
		if ( email_exists( $data['email'] ) ) {
			$this->push_flash( __( 'An account with this email already exists. Please use a different email or log in.', 'make-school' ) );
			$this->safe_back();
			return;
		}

		// Create the WP user immediately.
		$new_user_id = wp_insert_user(
			array(
				'user_login'   => $data['username'],
				'user_email'   => $data['email'],
				'user_pass'    => $data['password'],
				'display_name' => $data['full_name'],
				'first_name'   => $data['full_name'],
				'role'         => 'make_school_student',
			)
		);
		if ( is_wp_error( $new_user_id ) ) {
			$this->push_flash( $new_user_id->get_error_message() );
			$this->safe_back();
			return;
		}

		// Optional uploads.
		$photo_url    = $this->handle_upload( 'photo' );
		$document_url = $this->handle_upload( 'document' );
		if ( is_wp_error( $photo_url ) ) {
			$this->push_flash( $photo_url->get_error_message() );
			$this->safe_back();
			return;
		}
		if ( is_wp_error( $document_url ) ) {
			$this->push_flash( $document_url->get_error_message() );
			$this->safe_back();
			return;
		}

		// Insert row.
		global $wpdb;
		$table = make_school()->db->table( 'admissions' );
		$class = Make_School_Helpers::get_class( $data['class_id'] );

		$now      = current_time( 'mysql' );
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'branch_id'    => $class ? (int) $class->branch_id : 0,
				'session'      => $class && $class->session ? (string) $class->session : Make_School_Helpers::current_session(),
				'class_id'     => $data['class_id'],
				'roll_number'  => '', // assigned on approval.
				'full_name'    => $data['full_name'],
				'dob'          => $data['dob'],
				'gender'       => $data['gender'],
				'blood_group'  => $data['blood_group'],
				'father_name'  => $data['father_name'],
				'mother_name'  => $data['mother_name'],
				'email'        => $data['email'],
				'mobile'       => $data['mobile'],
				'address'      => $data['address'],
				'photo_url'    => is_string( $photo_url ) ? $photo_url : '',
				'document_url' => is_string( $document_url ) ? $document_url : '',
				'notes'        => '',
				'status'       => 'pending',
				'user_id'      => (int) $new_user_id,
				'reviewed_by'  => 0,
				'reviewed_at'  => null,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);

		if ( ! $inserted ) {
			$this->push_flash( __( 'Sorry, we could not save your application. Please try again.', 'make-school' ) );
			$this->safe_back();
			return;
		}

		// Store class/branch/session in user meta for module lookups.
		update_user_meta( (int) $new_user_id, 'make_school_class_id', (int) $data['class_id'] );
		update_user_meta( (int) $new_user_id, 'make_school_branch_id', $class ? (int) $class->branch_id : 0 );
		update_user_meta( (int) $new_user_id, 'make_school_session', $class && $class->session ? (string) $class->session : Make_School_Helpers::current_session() );
		update_user_meta( (int) $new_user_id, 'make_school_admission_id', (int) $wpdb->insert_id );

		// Notify admin (best-effort, non-blocking on failure).
		if ( (int) Make_School_Helpers::setting( 'enable_email_notify', 1 ) ) {
			$admin_email = get_option( 'admin_email' );
			if ( $admin_email ) {
				wp_mail(
					$admin_email,
					sprintf( __( '[%s] New admission application', 'make-school' ), Make_School_Helpers::setting( 'school_name' ) ),
					sprintf(
						/* translators: 1: name, 2: class, 3: email */
						__( "A new admission application has been submitted.\n\nName: %1\$s\nClass: %2\$s\nEmail: %3\$s\n\nReview pending applications in the WordPress admin.", 'make-school' ),
						$data['full_name'],
						Make_School_Helpers::class_label( $class ),
						$data['email']
					)
				);
			}
		}

		// PRG redirect with success flag.
		$target = remove_query_arg( array( 'admission' ) );
		$target = add_query_arg( 'admission', 'success', $target );
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Validate + ingest a single uploaded file. Returns URL on success,
	 * empty string when no file was provided, or WP_Error on bad input.
	 *
	 * @param string $field $_FILES key.
	 * @return string|WP_Error
	 */
	private function handle_upload( $field ) {
		if ( empty( $_FILES[ $field ] ) || empty( $_FILES[ $field ]['name'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		if ( UPLOAD_ERR_NO_FILE === (int) $_FILES[ $field ]['error'] ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		if ( UPLOAD_ERR_OK !== (int) $_FILES[ $field ]['error'] ) {
			return new WP_Error( 'upload_error', __( 'There was a problem uploading the file. Please try again.', 'make-school' ) );
		}

		// Whitelist by extension before WP's own check.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$name = isset( $_FILES[ $field ]['name'] ) ? sanitize_file_name( wp_unslash( $_FILES[ $field ]['name'] ) ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_DOC, true ) ) {
			return new WP_Error( 'upload_ext', __( 'Unsupported file type. Allowed: jpg, png, gif, webp, pdf.', 'make-school' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$overrides = array( 'test_form' => false );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$file = $_FILES[ $field ];
		$res  = wp_handle_upload( $file, $overrides );

		if ( isset( $res['error'] ) ) {
			return new WP_Error( 'upload_failed', (string) $res['error'] );
		}
		return isset( $res['url'] ) ? esc_url_raw( (string) $res['url'] ) : '';
	}

	/* =====================================================================
	 * ADMIN — REVIEW SCREEN
	 * ================================================================== */

	/**
	 * Register the Admissions admin sub-menu page.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_submenu_page(
			Make_School_Admin::PARENT_SLUG,
			__( 'Admissions', 'make-school' ),
			__( 'Admissions', 'make-school' ),
			self::CAP_REVIEW,
			'make-school-admissions',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the Admissions admin screen (list + view detail).
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( self::CAP_REVIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'make-school' ) );
		}

		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'list';
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		echo '<div class="wrap make-school-wrap">';
		echo '<h1>' . esc_html__( 'Admissions', 'make-school' ) . '</h1>';
		make_school()->admin->render_flash();

		if ( 'view' === $mode && $id ) {
			$this->render_admin_view( $id );
		} else {
			$this->render_admin_list();
		}
		echo '</div>';
	}

	/**
	 * Admin — list view with status filter.
	 *
	 * @return void
	 */
	private function render_admin_list() {
		global $wpdb;
		$table = make_school()->db->table( 'admissions' );
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending';
		$status = in_array( $status, array( 'pending', 'approved', 'rejected', 'all' ), true ) ? $status : 'pending';

		$where  = '1=1';
		$params = array();
		if ( 'all' !== $status ) {
			$where    = 'status = %s';
			$params[] = $status;
		}
		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT 200", $params ) ) // phpcs:ignore
			: $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200" ); // phpcs:ignore

		?>
		<?php
		$base          = admin_url( 'admin.php?page=make-school-admissions' );
		$status_tabs   = array( 'pending', 'approved', 'rejected', 'all' );
		$last_tab      = end( $status_tabs );
		?>
		<ul class="subsubsub">
			<?php foreach ( $status_tabs as $s ) :
				$label = ( 'all' === $s ) ? __( 'All', 'make-school' ) : Make_School_Helpers::status_label( $s );
				?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'status', $s, $base ) ); ?>"
					   <?php echo $status === $s ? 'class="current"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
					<?php echo $last_tab !== $s ? '|' : ''; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<table class="wp-list-table widefat fixed striped" style="margin-top:14px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Applicant', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Class', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Email', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Mobile', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Status', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Submitted', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'make-school' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No applications match the current filter.', 'make-school' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) :
						$class = Make_School_Helpers::get_class( (int) $r->class_id );
						?>
						<tr>
							<td><strong><?php echo esc_html( $r->full_name ); ?></strong>
								<?php if ( $r->roll_number ) : ?><br /><small><?php echo esc_html( $r->roll_number ); ?></small><?php endif; ?>
							</td>
							<td><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></td>
							<td><?php echo esc_html( $r->email ); ?></td>
							<td><?php echo esc_html( $r->mobile ); ?></td>
							<td><span class="make-school-pill make-school-pill-<?php echo esc_attr( $r->status ); ?>"><?php echo esc_html( Make_School_Helpers::status_label( $r->status ) ); ?></span></td>
							<td><?php echo esc_html( Make_School_Helpers::format_datetime( $r->created_at ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-admissions&mode=view&id=' . (int) $r->id ) ); ?>"><?php esc_html_e( 'View', 'make-school' ); ?></a>
								<?php if ( 'pending' === $r->status ) : ?>
									&nbsp;|&nbsp;
									<a href="<?php echo esc_url( $this->action_url( 'approve_admission', (int) $r->id ) ); ?>" style="color:#1d6f42;"><?php esc_html_e( 'Approve', 'make-school' ); ?></a>
									&nbsp;|&nbsp;
									<a href="<?php echo esc_url( $this->action_url( 'reject_admission', (int) $r->id ) ); ?>" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Reject this application?', 'make-school' ) ); ?>');"><?php esc_html_e( 'Reject', 'make-school' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Admin — single application detail view.
	 *
	 * @param int $id Admission ID.
	 * @return void
	 */
	private function render_admin_view( $id ) {
		global $wpdb;
		$table = make_school()->db->table( 'admissions' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
		if ( ! $row ) {
			echo '<p>' . esc_html__( 'Application not found.', 'make-school' ) . '</p>';
			return;
		}
		$class    = Make_School_Helpers::get_class( (int) $row->class_id );
		$reviewer = $row->reviewed_by ? get_user_by( 'id', (int) $row->reviewed_by ) : null;
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-admissions' ) ); ?>">&larr; <?php esc_html_e( 'Back to list', 'make-school' ); ?></a></p>

		<div class="make-school-card">
			<h2><?php echo esc_html( $row->full_name ); ?>
				<span class="make-school-pill make-school-pill-<?php echo esc_attr( $row->status ); ?>" style="float:right;">
					<?php echo esc_html( Make_School_Helpers::status_label( $row->status ) ); ?>
				</span>
			</h2>

			<table class="widefat striped">
				<tbody>
					<tr><th><?php esc_html_e( 'Roll Number', 'make-school' ); ?></th><td><?php echo $row->roll_number ? esc_html( $row->roll_number ) : '—'; ?></td></tr>
					<tr><th><?php esc_html_e( 'Class', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Session', 'make-school' ); ?></th><td><?php echo esc_html( $row->session ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Date of Birth', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::format_date( $row->dob ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Gender', 'make-school' ); ?></th><td><?php echo esc_html( ucfirst( (string) $row->gender ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Blood Group', 'make-school' ); ?></th><td><?php echo esc_html( $row->blood_group ); ?></td></tr>
					<tr><th><?php esc_html_e( "Father's Name", 'make-school' ); ?></th><td><?php echo esc_html( $row->father_name ); ?></td></tr>
					<tr><th><?php esc_html_e( "Mother's Name", 'make-school' ); ?></th><td><?php echo esc_html( $row->mother_name ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Email', 'make-school' ); ?></th><td><a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></td></tr>
					<tr><th><?php esc_html_e( 'Mobile', 'make-school' ); ?></th><td><?php echo esc_html( $row->mobile ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Address', 'make-school' ); ?></th><td><?php echo nl2br( esc_html( (string) $row->address ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Photo', 'make-school' ); ?></th>
						<td>
							<?php if ( $row->photo_url ) : ?>
								<a href="<?php echo esc_url( $row->photo_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View / download', 'make-school' ); ?></a>
							<?php else : ?>—<?php endif; ?>
						</td></tr>
					<tr><th><?php esc_html_e( 'Document', 'make-school' ); ?></th>
						<td>
							<?php if ( $row->document_url ) : ?>
								<a href="<?php echo esc_url( $row->document_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View / download', 'make-school' ); ?></a>
							<?php else : ?>—<?php endif; ?>
						</td></tr>
					<tr><th><?php esc_html_e( 'Submitted', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::format_datetime( $row->created_at ) ); ?></td></tr>
					<?php if ( $reviewer ) : ?>
					<tr><th><?php esc_html_e( 'Reviewed by', 'make-school' ); ?></th><td><?php echo esc_html( $reviewer->display_name ); ?> &middot; <?php echo esc_html( Make_School_Helpers::format_datetime( $row->reviewed_at ) ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( 'pending' === $row->status ) : ?>
				<p style="margin-top:14px;">
					<a class="button button-primary" href="<?php echo esc_url( $this->action_url( 'approve_admission', (int) $row->id ) ); ?>"><?php esc_html_e( 'Approve & enrol student', 'make-school' ); ?></a>
					<a class="button" href="<?php echo esc_url( $this->action_url( 'reject_admission', (int) $row->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Reject this application?', 'make-school' ) ); ?>');"><?php esc_html_e( 'Reject', 'make-school' ); ?></a>
				</p>
			<?php elseif ( 'approved' === $row->status && $row->user_id ) : ?>
				<p style="margin-top:14px;">
					<a class="button" href="<?php echo esc_url( get_edit_user_link( (int) $row->user_id ) ); ?>"><?php esc_html_e( 'Edit student user', 'make-school' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * ADMIN — APPROVE / REJECT
	 * ================================================================== */

	/**
	 * Build a nonce-protected admin action URL.
	 *
	 * @param string $action Action key.
	 * @param int    $id     Admission ID.
	 * @return string
	 */
	private function action_url( $action, $id ) {
		return wp_nonce_url(
			admin_url( 'admin.php?page=make-school-admissions&make_school_action=' . rawurlencode( $action ) . '&id=' . absint( $id ) ),
			self::NONCE_ADMIN
		);
	}

	/**
	 * Dispatch admin GET actions (approve / reject).
	 *
	 * @return void
	 */
	public function maybe_handle_admin_action() {
		if ( ! is_admin() || ! current_user_can( self::CAP_REVIEW ) ) {
			return;
		}
		$action = isset( $_GET['make_school_action'] ) ? sanitize_key( wp_unslash( $_GET['make_school_action'] ) ) : '';
		if ( ! in_array( $action, array( 'approve_admission', 'reject_admission' ), true ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ADMIN );

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! $id ) {
			return;
		}

		if ( 'approve_admission' === $action ) {
			$this->approve( $id );
		} else {
			$this->reject( $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=make-school-admissions&mode=view&id=' . $id ) );
		exit;
	}

	/**
	 * Approve an admission: create the WP user, assign role, generate roll
	 * number, persist mapping, optionally email the applicant.
	 *
	 * @param int $id Admission ID.
	 * @return void
	 */
	private function approve( $id ) {
		global $wpdb;
		$table = make_school()->db->table( 'admissions' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
		if ( ! $row ) {
			make_school()->admin->push_flash( __( 'Application not found.', 'make-school' ), 'error' );
			return;
		}
		if ( 'approved' === $row->status ) {
			make_school()->admin->push_flash( __( 'Application is already approved.', 'make-school' ), 'warning' );
			return;
		}

		// Create or reuse the WP user.
		$user_id = email_exists( $row->email );
		$plain_password = '';
		if ( ! $user_id ) {
			$base   = sanitize_user( current( explode( '@', $row->email ) ), true );
			$base   = $base ? $base : 'student';
			$login  = $base;
			$suffix = 1;
			while ( username_exists( $login ) ) {
				$login = $base . $suffix;
				$suffix++;
			}
			$plain_password = wp_generate_password( 12, true, false );
			$user_id        = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $row->email,
					'user_pass'    => $plain_password,
					'display_name' => $row->full_name,
					'first_name'   => $row->full_name,
					'role'         => 'make_school_student',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				make_school()->admin->push_flash( $user_id->get_error_message(), 'error' );
				return;
			}
		} else {
			$user = get_user_by( 'id', $user_id );
			if ( $user instanceof WP_User && ! in_array( 'make_school_student', (array) $user->roles, true ) ) {
				$user->add_role( 'make_school_student' );
			}
		}

		// Persist mapping in user meta (single source of truth for queries).
		update_user_meta( (int) $user_id, 'make_school_class_id', (int) $row->class_id );
		update_user_meta( (int) $user_id, 'make_school_branch_id', (int) $row->branch_id );
		update_user_meta( (int) $user_id, 'make_school_session', (string) $row->session );
		update_user_meta( (int) $user_id, 'make_school_admission_id', (int) $row->id );

		// Roll number — only generate if missing.
		$roll = $row->roll_number ? (string) $row->roll_number : Make_School_Helpers::generate_roll_number( (int) $row->branch_id, (string) $row->session );

		$now = current_time( 'mysql' );
		$wpdb->update( // phpcs:ignore WordPress.DB
			$table,
			array(
				'status'      => 'approved',
				'user_id'     => (int) $user_id,
				'roll_number' => $roll,
				'reviewed_by' => (int) get_current_user_id(),
				'reviewed_at' => $now,
				'updated_at'  => $now,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		// Email the applicant.
		if ( (int) Make_School_Helpers::setting( 'enable_email_notify', 1 ) ) {
			$body  = sprintf(
				/* translators: 1: name, 2: roll, 3: school */
				__( "Hello %1\$s,\n\nGood news — your admission application has been approved.\nYour roll/enrolment number: %2\$s\n\n%3\$s", 'make-school' ),
				$row->full_name,
				$roll,
				Make_School_Helpers::setting( 'school_name' )
			);
			if ( $plain_password ) {
				$body .= "\n\n" . sprintf(
					/* translators: 1: login URL, 2: password */
					__( "You can sign in here: %1\$s\nTemporary password: %2\$s\nPlease change it after first login.", 'make-school' ),
					Make_School_Helpers::login_url(),
					$plain_password
				);
			}
			wp_mail(
				$row->email,
				sprintf( __( '[%s] Admission approved', 'make-school' ), Make_School_Helpers::setting( 'school_name' ) ),
				$body
			);
		}

		make_school()->admin->push_flash(
			sprintf(
				/* translators: %s: roll number */
				__( 'Approved. Roll number: %s', 'make-school' ),
				'<code>' . esc_html( $roll ) . '</code>'
			)
		);
	}

	/**
	 * Reject an admission.
	 *
	 * @param int $id Admission ID.
	 * @return void
	 */
	private function reject( $id ) {
		global $wpdb;
		$table = make_school()->db->table( 'admissions' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
		if ( ! $row ) {
			return;
		}
		$now = current_time( 'mysql' );
		$wpdb->update( // phpcs:ignore WordPress.DB
			$table,
			array(
				'status'      => 'rejected',
				'reviewed_by' => (int) get_current_user_id(),
				'reviewed_at' => $now,
				'updated_at'  => $now,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( (int) Make_School_Helpers::setting( 'enable_email_notify', 1 ) && $row->email ) {
			wp_mail(
				$row->email,
				sprintf( __( '[%s] Admission update', 'make-school' ), Make_School_Helpers::setting( 'school_name' ) ),
				sprintf(
					/* translators: 1: name, 2: school */
					__( "Hello %1\$s,\n\nThank you for applying. After review we are unable to offer admission at this time.\n\n%2\$s", 'make-school' ),
					$row->full_name,
					Make_School_Helpers::setting( 'school_name' )
				)
			);
		}

		make_school()->admin->push_flash( __( 'Application rejected.', 'make-school' ) );
	}

	/* =====================================================================
	 * UTILITIES
	 * ================================================================== */

	/**
	 * Frontend flash storage (per visitor, short-lived transient).
	 *
	 * @return string
	 */
	private function flash_key() {
		$basis  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$basis .= isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return 'make_school_admission_flash_' . md5( $basis );
	}

	/**
	 * Push a one-shot flash error.
	 *
	 * @param string $msg Message.
	 * @return void
	 */
	private function push_flash( $msg ) {
		set_transient( $this->flash_key(), (string) $msg, 60 );
	}

	/**
	 * Pop a one-shot flash error.
	 *
	 * @return string
	 */
	private function pop_flash() {
		$key = $this->flash_key();
		$m   = get_transient( $key );
		if ( false === $m ) {
			return '';
		}
		delete_transient( $key );
		return (string) $m;
	}

	/**
	 * Bounce back to the originating request.
	 *
	 * @return void
	 */
	private function safe_back() {
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : home_url( '/' );
		wp_safe_redirect( $ref );
		exit;
	}
}
