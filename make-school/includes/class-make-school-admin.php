<?php
/**
 * MAKE SCHOOL — admin orchestrator.
 *
 * Owns:
 *   - the top-level "Make School" admin menu + dashboard landing page
 *   - Branches CRUD (list / add / edit / delete)
 *   - Sessions management (add / set current / delete)
 *   - Classes CRUD
 *   - Settings page (currency, prefixes, page IDs)
 *
 * Other modules (Admissions, Fees, Exams, LMS) register their own
 * sub-menus against the parent slug exposed here as PARENT_SLUG.
 *
 * @package MakeSchool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Make_School_Admin
 */
class Make_School_Admin {

	const PARENT_SLUG  = 'make-school';
	const CAP_MANAGE   = 'make_school_manage_school';
	const NONCE_ACTION = 'make_school_admin';

	/**
	 * Constructor — wires hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ), 5 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/* ---------------------------------------------------------------------
	 * Menu registration.
	 * ------------------------------------------------------------------- */

	/**
	 * Register the parent menu + the screens this class owns.
	 *
	 * Other modules attach their own sub-menus via admin_menu @ priority 10+.
	 *
	 * @return void
	 */
	public function register_menus() {
		add_menu_page(
			__( 'MAKE SCHOOL', 'make-school' ),
			__( 'Make School', 'make-school' ),
			self::CAP_MANAGE,
			self::PARENT_SLUG,
			array( $this, 'render_dashboard_page' ),
			'dashicons-welcome-learn-more',
			3
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Dashboard', 'make-school' ),
			__( 'Dashboard', 'make-school' ),
			self::CAP_MANAGE,
			self::PARENT_SLUG,
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Branches', 'make-school' ),
			__( 'Branches', 'make-school' ),
			self::CAP_MANAGE,
			'make-school-branches',
			array( $this, 'render_branches_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Sessions', 'make-school' ),
			__( 'Sessions', 'make-school' ),
			self::CAP_MANAGE,
			'make-school-sessions',
			array( $this, 'render_sessions_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Classes', 'make-school' ),
			__( 'Classes', 'make-school' ),
			self::CAP_MANAGE,
			'make-school-classes',
			array( $this, 'render_classes_page' )
		);

		// Settings is registered last (priority 99 on admin_menu) so it
		// always sits at the bottom of the menu regardless of which other
		// modules attach themselves at the default priority.
		add_action( 'admin_menu', array( $this, 'register_settings_menu' ), 99 );
	}

	/**
	 * Tail-end menu — Settings.
	 *
	 * @return void
	 */
	public function register_settings_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Settings', 'make-school' ),
			__( 'Settings', 'make-school' ),
			self::CAP_MANAGE,
			'make-school-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Action router (admin_init).
	 * ------------------------------------------------------------------- */

	/**
	 * Dispatch the various POST/GET admin actions this class handles.
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			return;
		}

		// POST actions.
		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			$action = isset( $_POST['make_school_action'] ) ? sanitize_key( wp_unslash( $_POST['make_school_action'] ) ) : '';
			switch ( $action ) {
				case 'save_branch':
					$this->save_branch();
					break;
				case 'add_session':
					$this->add_session();
					break;
				case 'set_current_session':
					$this->set_current_session();
					break;
				case 'save_class':
					$this->save_class();
					break;
				case 'save_settings':
					$this->save_settings();
					break;
			}
		}

		// GET actions (delete links).
		$get_action = isset( $_GET['make_school_action'] ) ? sanitize_key( wp_unslash( $_GET['make_school_action'] ) ) : '';
		switch ( $get_action ) {
			case 'delete_branch':
				$this->delete_branch();
				break;
			case 'delete_session':
				$this->delete_session();
				break;
			case 'delete_class':
				$this->delete_class();
				break;
		}
	}

	/* =====================================================================
	 * DASHBOARD
	 * ================================================================== */

	/**
	 * Render the dashboard landing page (KPIs).
	 *
	 * @return void
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'make-school' ) );
		}

		global $wpdb;
		$tables = make_school()->db->all_tables();

		$kpi = array(
			'branches'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['branches']}" ), // phpcs:ignore
			'classes'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['classes']}" ), // phpcs:ignore
			'pending'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['admissions']} WHERE status = 'pending'" ), // phpcs:ignore
			'students'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['admissions']} WHERE status = 'approved'" ), // phpcs:ignore
			'unpaid'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['invoices']} WHERE status IN ('unpaid','partially_paid')" ), // phpcs:ignore
			'collected'  => (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount_paid),0) FROM {$tables['invoices']}" ), // phpcs:ignore
		);
		?>
		<div class="wrap make-school-wrap">
			<h1><?php esc_html_e( 'MAKE SCHOOL — Dashboard', 'make-school' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %s: session label */
					esc_html__( 'Current academic session: %s', 'make-school' ),
					'<strong>' . esc_html( Make_School_Helpers::current_session() ) . '</strong>'
				);
				?>
			</p>

			<div class="make-school-kpi-grid">
				<div class="make-school-kpi"><span><?php esc_html_e( 'Branches', 'make-school' ); ?></span><strong><?php echo esc_html( (string) $kpi['branches'] ); ?></strong></div>
				<div class="make-school-kpi"><span><?php esc_html_e( 'Classes', 'make-school' ); ?></span><strong><?php echo esc_html( (string) $kpi['classes'] ); ?></strong></div>
				<div class="make-school-kpi"><span><?php esc_html_e( 'Students', 'make-school' ); ?></span><strong><?php echo esc_html( (string) $kpi['students'] ); ?></strong></div>
				<div class="make-school-kpi"><span><?php esc_html_e( 'Pending Admissions', 'make-school' ); ?></span><strong><?php echo esc_html( (string) $kpi['pending'] ); ?></strong></div>
				<div class="make-school-kpi"><span><?php esc_html_e( 'Unpaid Invoices', 'make-school' ); ?></span><strong><?php echo esc_html( (string) $kpi['unpaid'] ); ?></strong></div>
				<div class="make-school-kpi"><span><?php esc_html_e( 'Total Collected', 'make-school' ); ?></span><strong><?php echo esc_html( Make_School_Helpers::format_currency( $kpi['collected'] ) ); ?></strong></div>
			</div>

			<h2><?php esc_html_e( 'Quick links', 'make-school' ); ?></h2>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-branches' ) ); ?>"><?php esc_html_e( 'Manage Branches', 'make-school' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-classes' ) ); ?>"><?php esc_html_e( 'Manage Classes', 'make-school' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-admissions' ) ); ?>"><?php esc_html_e( 'Review Admissions', 'make-school' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-fees' ) ); ?>"><?php esc_html_e( 'Fees & Invoices', 'make-school' ); ?></a>
			</p>

			<h2><?php esc_html_e( 'Available shortcodes', 'make-school' ); ?></h2>
			<ul class="ul-disc">
				<li><code>[make_school_login_form]</code> — <?php esc_html_e( 'Custom login portal with role-based redirection.', 'make-school' ); ?></li>
				<li><code>[make_school_admission_form]</code> — <?php esc_html_e( 'Public admission/enquiry form with document upload.', 'make-school' ); ?></li>
				<li><code>[make_school_student_dashboard]</code> — <?php esc_html_e( 'Student / Parent dashboard.', 'make-school' ); ?></li>
				<li><code>[make_school_teacher_dashboard]</code> — <?php esc_html_e( 'Teacher dashboard with attendance, marks and LMS.', 'make-school' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/* =====================================================================
	 * BRANCHES
	 * ================================================================== */

	/**
	 * Branches list / add-edit screen.
	 *
	 * @return void
	 */
	public function render_branches_page() {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'make-school' ) );
		}

		$mode      = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'list';
		$branch_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		echo '<div class="wrap make-school-wrap">';
		echo '<h1>' . esc_html__( 'Branches', 'make-school' );
		if ( 'list' === $mode ) {
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=make-school-branches&mode=edit' ) ) . '" class="page-title-action">' . esc_html__( 'Add New', 'make-school' ) . '</a>';
		}
		echo '</h1>';

		$this->render_flash();

		if ( 'edit' === $mode ) {
			$this->render_branch_form( $branch_id );
		} else {
			$this->render_branches_list();
		}

		echo '</div>';
	}

	/**
	 * Render the branches list table.
	 *
	 * @return void
	 */
	private function render_branches_list() {
		global $wpdb;
		$table = make_school()->db->table( 'branches' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" ); // phpcs:ignore
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Code', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'City', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Status', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'make-school' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No branches yet. Click "Add New" to create the first one.', 'make-school' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $b ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $b->name ); ?></strong></td>
							<td><?php echo esc_html( $b->code ); ?></td>
							<td><?php echo esc_html( $b->city ); ?></td>
							<td><?php echo esc_html( $b->phone ); ?></td>
							<td><span class="make-school-pill make-school-pill-<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( Make_School_Helpers::status_label( $b->status ) ); ?></span></td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-branches&mode=edit&id=' . (int) $b->id ) ); ?>"><?php esc_html_e( 'Edit', 'make-school' ); ?></a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=make-school-branches&make_school_action=delete_branch&id=' . (int) $b->id ), self::NONCE_ACTION ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this branch?', 'make-school' ) ); ?>');" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'make-school' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the branch add/edit form.
	 *
	 * @param int $id Branch ID (0 for new).
	 * @return void
	 */
	private function render_branch_form( $id ) {
		$row = $id ? Make_School_Helpers::get_branch( $id ) : null;
		$g   = function ( $key, $default = '' ) use ( $row ) {
			return $row && isset( $row->$key ) ? $row->$key : $default;
		};
		?>
		<form method="post" class="make-school-form" autocomplete="off">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="make_school_action" value="save_branch" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />

			<table class="form-table" role="presentation">
				<tr><th><label for="branch-name"><?php esc_html_e( 'Branch Name', 'make-school' ); ?> *</label></th>
					<td><input id="branch-name" type="text" name="name" value="<?php echo esc_attr( $g( 'name' ) ); ?>" class="regular-text" required /></td></tr>
				<tr><th><label for="branch-code"><?php esc_html_e( 'Branch Code', 'make-school' ); ?> *</label></th>
					<td><input id="branch-code" type="text" name="code" value="<?php echo esc_attr( $g( 'code' ) ); ?>" class="regular-text" required /></td></tr>
				<tr><th><label for="branch-email"><?php esc_html_e( 'Email', 'make-school' ); ?></label></th>
					<td><input id="branch-email" type="email" name="email" value="<?php echo esc_attr( $g( 'email' ) ); ?>" class="regular-text" /></td></tr>
				<tr><th><label for="branch-phone"><?php esc_html_e( 'Phone', 'make-school' ); ?></label></th>
					<td><input id="branch-phone" type="text" name="phone" value="<?php echo esc_attr( $g( 'phone' ) ); ?>" class="regular-text" /></td></tr>
				<tr><th><label for="branch-address"><?php esc_html_e( 'Address', 'make-school' ); ?></label></th>
					<td><textarea id="branch-address" name="address" rows="3" class="large-text"><?php echo esc_textarea( $g( 'address' ) ); ?></textarea></td></tr>
				<tr><th><label for="branch-city"><?php esc_html_e( 'City', 'make-school' ); ?></label></th>
					<td><input id="branch-city" type="text" name="city" value="<?php echo esc_attr( $g( 'city' ) ); ?>" class="regular-text" /></td></tr>
				<tr><th><label for="branch-state"><?php esc_html_e( 'State / Province', 'make-school' ); ?></label></th>
					<td><input id="branch-state" type="text" name="state" value="<?php echo esc_attr( $g( 'state' ) ); ?>" class="regular-text" /></td></tr>
				<tr><th><label for="branch-country"><?php esc_html_e( 'Country', 'make-school' ); ?></label></th>
					<td><input id="branch-country" type="text" name="country" value="<?php echo esc_attr( $g( 'country' ) ); ?>" class="regular-text" /></td></tr>
				<tr><th><label for="branch-zip"><?php esc_html_e( 'ZIP / Postal', 'make-school' ); ?></label></th>
					<td><input id="branch-zip" type="text" name="zip" value="<?php echo esc_attr( $g( 'zip' ) ); ?>" class="regular-text" /></td></tr>
				<tr><th><label for="branch-logo"><?php esc_html_e( 'Logo URL', 'make-school' ); ?></label></th>
					<td><input id="branch-logo" type="url" name="logo_url" value="<?php echo esc_attr( $g( 'logo_url' ) ); ?>" class="regular-text" /></td></tr>
				<tr><th><label for="branch-session"><?php esc_html_e( 'Session', 'make-school' ); ?></label></th>
					<td>
						<select id="branch-session" name="session">
							<option value=""><?php esc_html_e( '— Any —', 'make-school' ); ?></option>
							<?php foreach ( Make_School_Helpers::sessions() as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( (string) $g( 'session' ), $s ); ?>><?php echo esc_html( $s ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<tr><th><label for="branch-status"><?php esc_html_e( 'Status', 'make-school' ); ?></label></th>
					<td>
						<select id="branch-status" name="status">
							<option value="active" <?php selected( (string) $g( 'status', 'active' ), 'active' ); ?>><?php esc_html_e( 'Active', 'make-school' ); ?></option>
							<option value="inactive" <?php selected( (string) $g( 'status', 'active' ), 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'make-school' ); ?></option>
						</select>
					</td></tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo esc_html( $id ? __( 'Update Branch', 'make-school' ) : __( 'Create Branch', 'make-school' ) ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-branches' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'make-school' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Persist a branch row (create or update).
	 *
	 * @return void
	 */
	private function save_branch() {
		check_admin_referer( self::NONCE_ACTION );

		global $wpdb;
		$table = make_school()->db->table( 'branches' );
		$now   = current_time( 'mysql' );

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'code'       => isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '',
			'email'      => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'phone'      => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'address'    => isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '',
			'city'       => isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
			'state'      => isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '',
			'country'    => isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '',
			'zip'        => isset( $_POST['zip'] ) ? sanitize_text_field( wp_unslash( $_POST['zip'] ) ) : '',
			'logo_url'   => isset( $_POST['logo_url'] ) ? esc_url_raw( wp_unslash( $_POST['logo_url'] ) ) : '',
			'session'    => isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '',
			'status'     => ( isset( $_POST['status'] ) && 'inactive' === $_POST['status'] ) ? 'inactive' : 'active',
			'updated_at' => $now,
		);

		if ( '' === $data['name'] || '' === $data['code'] ) {
			$this->push_flash( __( 'Branch name and code are required.', 'make-school' ), 'error' );
			$this->redirect( admin_url( 'admin.php?page=make-school-branches&mode=edit&id=' . $id ) );
		}

		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
			$this->push_flash( __( 'Branch updated.', 'make-school' ) );
		} else {
			$data['created_by'] = (int) get_current_user_id();
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB
			$this->push_flash( __( 'Branch created.', 'make-school' ) );
		}

		$this->redirect( admin_url( 'admin.php?page=make-school-branches' ) );
	}

	/**
	 * Delete a branch row.
	 *
	 * @return void
	 */
	private function delete_branch() {
		check_admin_referer( self::NONCE_ACTION );

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $id ) {
			global $wpdb;
			$wpdb->delete( make_school()->db->table( 'branches' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore
			$this->push_flash( __( 'Branch deleted.', 'make-school' ) );
		}
		$this->redirect( admin_url( 'admin.php?page=make-school-branches' ) );
	}

	/* =====================================================================
	 * SESSIONS
	 * ================================================================== */

	/**
	 * Sessions management screen.
	 *
	 * @return void
	 */
	public function render_sessions_page() {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'make-school' ) );
		}

		$sessions = Make_School_Helpers::sessions();
		$current  = Make_School_Helpers::current_session();
		?>
		<div class="wrap make-school-wrap">
			<h1><?php esc_html_e( 'Academic Sessions', 'make-school' ); ?></h1>
			<?php $this->render_flash(); ?>

			<h2><?php esc_html_e( 'Current session', 'make-school' ); ?></h2>
			<form method="post" class="make-school-inline-form">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="make_school_action" value="set_current_session" />
				<select name="session">
					<?php foreach ( $sessions as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $current, $s ); ?>><?php echo esc_html( $s ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Set as current', 'make-school' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Add a session', 'make-school' ); ?></h2>
			<form method="post" class="make-school-inline-form">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="make_school_action" value="add_session" />
				<input type="text" name="session" placeholder="<?php esc_attr_e( '2028-2029', 'make-school' ); ?>" pattern="\d{4}-\d{4}" required />
				<button type="submit" class="button"><?php esc_html_e( 'Add session', 'make-school' ); ?></button>
				<p class="description"><?php esc_html_e( 'Format: YYYY-YYYY (e.g. 2028-2029).', 'make-school' ); ?></p>
			</form>

			<h2><?php esc_html_e( 'All sessions', 'make-school' ); ?></h2>
			<table class="wp-list-table widefat fixed striped" style="max-width:520px;">
				<thead><tr><th><?php esc_html_e( 'Session', 'make-school' ); ?></th><th><?php esc_html_e( 'Status', 'make-school' ); ?></th><th><?php esc_html_e( 'Actions', 'make-school' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $sessions as $s ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $s ); ?></strong></td>
							<td><?php echo $s === $current ? '<span class="make-school-pill make-school-pill-active">' . esc_html__( 'Current', 'make-school' ) . '</span>' : '—'; ?></td>
							<td>
								<?php if ( $s !== $current ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=make-school-sessions&make_school_action=delete_session&session=' . rawurlencode( $s ) ), self::NONCE_ACTION ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove this session?', 'make-school' ) ); ?>');" style="color:#b32d2e;"><?php esc_html_e( 'Remove', 'make-school' ); ?></a>
								<?php else : ?>
									<em><?php esc_html_e( 'Cannot remove active', 'make-school' ); ?></em>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Add a new session string.
	 *
	 * @return void
	 */
	private function add_session() {
		check_admin_referer( self::NONCE_ACTION );
		$session = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{4}$/', $session ) ) {
			$this->push_flash( __( 'Invalid session format. Use YYYY-YYYY.', 'make-school' ), 'error' );
			$this->redirect( admin_url( 'admin.php?page=make-school-sessions' ) );
		}
		$list = Make_School_Helpers::sessions();
		if ( ! in_array( $session, $list, true ) ) {
			$list[] = $session;
			sort( $list );
			update_option( 'make_school_sessions', $list );
			$this->push_flash( __( 'Session added.', 'make-school' ) );
		} else {
			$this->push_flash( __( 'Session already exists.', 'make-school' ), 'warning' );
		}
		$this->redirect( admin_url( 'admin.php?page=make-school-sessions' ) );
	}

	/**
	 * Switch the current session.
	 *
	 * @return void
	 */
	private function set_current_session() {
		check_admin_referer( self::NONCE_ACTION );
		$session = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
		if ( in_array( $session, Make_School_Helpers::sessions(), true ) ) {
			update_option( 'make_school_current_session', $session );
			$this->push_flash( __( 'Current session updated.', 'make-school' ) );
		} else {
			$this->push_flash( __( 'Unknown session.', 'make-school' ), 'error' );
		}
		$this->redirect( admin_url( 'admin.php?page=make-school-sessions' ) );
	}

	/**
	 * Remove a session.
	 *
	 * @return void
	 */
	private function delete_session() {
		check_admin_referer( self::NONCE_ACTION );
		$session = isset( $_GET['session'] ) ? sanitize_text_field( wp_unslash( $_GET['session'] ) ) : '';
		$current = Make_School_Helpers::current_session();
		if ( $session && $session !== $current ) {
			$list = array_values( array_diff( Make_School_Helpers::sessions(), array( $session ) ) );
			update_option( 'make_school_sessions', $list );
			$this->push_flash( __( 'Session removed.', 'make-school' ) );
		} else {
			$this->push_flash( __( 'Cannot remove the active session.', 'make-school' ), 'error' );
		}
		$this->redirect( admin_url( 'admin.php?page=make-school-sessions' ) );
	}

	/* =====================================================================
	 * CLASSES
	 * ================================================================== */

	/**
	 * Classes list / add-edit screen.
	 *
	 * @return void
	 */
	public function render_classes_page() {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'make-school' ) );
		}

		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'list';
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		echo '<div class="wrap make-school-wrap">';
		echo '<h1>' . esc_html__( 'Classes & Sections', 'make-school' );
		if ( 'list' === $mode ) {
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=make-school-classes&mode=edit' ) ) . '" class="page-title-action">' . esc_html__( 'Add New', 'make-school' ) . '</a>';
		}
		echo '</h1>';

		$this->render_flash();

		if ( 'edit' === $mode ) {
			$this->render_class_form( $id );
		} else {
			$this->render_classes_list();
		}

		echo '</div>';
	}

	/**
	 * Render the classes list table.
	 *
	 * @return void
	 */
	private function render_classes_list() {
		global $wpdb;
		$table = make_school()->db->table( 'classes' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY class_name ASC, section ASC" ); // phpcs:ignore
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Class', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Section', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Branch', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Session', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Class Teacher', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Capacity', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Status', 'make-school' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'make-school' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No classes yet.', 'make-school' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $c ) :
						$branch  = $c->branch_id ? Make_School_Helpers::get_branch( $c->branch_id ) : null;
						$teacher = $c->class_teacher_id ? get_user_by( 'id', $c->class_teacher_id ) : null;
						?>
						<tr>
							<td><strong><?php echo esc_html( $c->class_name ); ?></strong></td>
							<td><?php echo esc_html( $c->section ); ?></td>
							<td><?php echo $branch ? esc_html( $branch->name ) : '—'; ?></td>
							<td><?php echo esc_html( $c->session ); ?></td>
							<td><?php echo $teacher ? esc_html( $teacher->display_name ) : '—'; ?></td>
							<td><?php echo esc_html( (string) $c->capacity ); ?></td>
							<td><span class="make-school-pill make-school-pill-<?php echo esc_attr( $c->status ); ?>"><?php echo esc_html( Make_School_Helpers::status_label( $c->status ) ); ?></span></td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-classes&mode=edit&id=' . (int) $c->id ) ); ?>"><?php esc_html_e( 'Edit', 'make-school' ); ?></a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=make-school-classes&make_school_action=delete_class&id=' . (int) $c->id ), self::NONCE_ACTION ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this class?', 'make-school' ) ); ?>');" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'make-school' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the class add/edit form.
	 *
	 * @param int $id Class ID.
	 * @return void
	 */
	private function render_class_form( $id ) {
		$row = $id ? Make_School_Helpers::get_class( $id ) : null;
		$g   = function ( $key, $default = '' ) use ( $row ) {
			return $row && isset( $row->$key ) ? $row->$key : $default;
		};

		$teachers = get_users(
			array(
				'role__in' => array( 'make_school_teacher', 'administrator' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);
		$branches = Make_School_Helpers::get_branches( '' );
		?>
		<form method="post" class="make-school-form" autocomplete="off">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="make_school_action" value="save_class" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />

			<table class="form-table" role="presentation">
				<tr><th><label for="class-name"><?php esc_html_e( 'Class Name', 'make-school' ); ?> *</label></th>
					<td><input id="class-name" type="text" name="class_name" value="<?php echo esc_attr( $g( 'class_name' ) ); ?>" class="regular-text" required /></td></tr>
				<tr><th><label for="class-section"><?php esc_html_e( 'Section', 'make-school' ); ?></label></th>
					<td><input id="class-section" type="text" name="section" value="<?php echo esc_attr( $g( 'section' ) ); ?>" class="regular-text" /></td></tr>
				<tr><th><label for="class-branch"><?php esc_html_e( 'Branch', 'make-school' ); ?></label></th>
					<td>
						<select id="class-branch" name="branch_id">
							<option value="0"><?php esc_html_e( '— None —', 'make-school' ); ?></option>
							<?php foreach ( $branches as $b ) : ?>
								<option value="<?php echo esc_attr( (string) $b->id ); ?>" <?php selected( (int) $g( 'branch_id' ), (int) $b->id ); ?>><?php echo esc_html( $b->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<tr><th><label for="class-session"><?php esc_html_e( 'Session', 'make-school' ); ?></label></th>
					<td>
						<select id="class-session" name="session">
							<option value=""><?php esc_html_e( '— Any —', 'make-school' ); ?></option>
							<?php foreach ( Make_School_Helpers::sessions() as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( (string) $g( 'session', Make_School_Helpers::current_session() ), $s ); ?>><?php echo esc_html( $s ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<tr><th><label for="class-teacher"><?php esc_html_e( 'Class Teacher', 'make-school' ); ?></label></th>
					<td>
						<select id="class-teacher" name="class_teacher_id">
							<option value="0"><?php esc_html_e( '— None —', 'make-school' ); ?></option>
							<?php foreach ( $teachers as $t ) : ?>
								<option value="<?php echo esc_attr( (string) $t->ID ); ?>" <?php selected( (int) $g( 'class_teacher_id' ), (int) $t->ID ); ?>><?php echo esc_html( $t->display_name . ' (' . $t->user_email . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<tr><th><label for="class-room"><?php esc_html_e( 'Room', 'make-school' ); ?></label></th>
					<td><input id="class-room" type="text" name="room_no" value="<?php echo esc_attr( $g( 'room_no' ) ); ?>" class="regular-text" /></td></tr>
				<tr><th><label for="class-capacity"><?php esc_html_e( 'Capacity', 'make-school' ); ?></label></th>
					<td><input id="class-capacity" type="number" min="0" name="capacity" value="<?php echo esc_attr( (string) $g( 'capacity', 0 ) ); ?>" class="small-text" /></td></tr>
				<tr><th><label for="class-status"><?php esc_html_e( 'Status', 'make-school' ); ?></label></th>
					<td>
						<select id="class-status" name="status">
							<option value="active" <?php selected( (string) $g( 'status', 'active' ), 'active' ); ?>><?php esc_html_e( 'Active', 'make-school' ); ?></option>
							<option value="inactive" <?php selected( (string) $g( 'status', 'active' ), 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'make-school' ); ?></option>
						</select>
					</td></tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo esc_html( $id ? __( 'Update Class', 'make-school' ) : __( 'Create Class', 'make-school' ) ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-classes' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'make-school' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Persist a class row (create or update).
	 *
	 * @return void
	 */
	private function save_class() {
		check_admin_referer( self::NONCE_ACTION );

		global $wpdb;
		$table = make_school()->db->table( 'classes' );
		$now   = current_time( 'mysql' );

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'branch_id'        => isset( $_POST['branch_id'] ) ? absint( $_POST['branch_id'] ) : 0,
			'session'          => isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '',
			'class_name'       => isset( $_POST['class_name'] ) ? sanitize_text_field( wp_unslash( $_POST['class_name'] ) ) : '',
			'section'          => isset( $_POST['section'] ) ? sanitize_text_field( wp_unslash( $_POST['section'] ) ) : '',
			'class_teacher_id' => isset( $_POST['class_teacher_id'] ) ? absint( $_POST['class_teacher_id'] ) : 0,
			'room_no'          => isset( $_POST['room_no'] ) ? sanitize_text_field( wp_unslash( $_POST['room_no'] ) ) : '',
			'capacity'         => isset( $_POST['capacity'] ) ? absint( $_POST['capacity'] ) : 0,
			'status'           => ( isset( $_POST['status'] ) && 'inactive' === $_POST['status'] ) ? 'inactive' : 'active',
			'updated_at'       => $now,
		);

		if ( '' === $data['class_name'] ) {
			$this->push_flash( __( 'Class name is required.', 'make-school' ), 'error' );
			$this->redirect( admin_url( 'admin.php?page=make-school-classes&mode=edit&id=' . $id ) );
		}

		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore
			$this->push_flash( __( 'Class updated.', 'make-school' ) );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data ); // phpcs:ignore
			$this->push_flash( __( 'Class created.', 'make-school' ) );
		}
		$this->redirect( admin_url( 'admin.php?page=make-school-classes' ) );
	}

	/**
	 * Delete a class row.
	 *
	 * @return void
	 */
	private function delete_class() {
		check_admin_referer( self::NONCE_ACTION );
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $id ) {
			global $wpdb;
			$wpdb->delete( make_school()->db->table( 'classes' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore
			$this->push_flash( __( 'Class deleted.', 'make-school' ) );
		}
		$this->redirect( admin_url( 'admin.php?page=make-school-classes' ) );
	}

	/* =====================================================================
	 * SETTINGS
	 * ================================================================== */

	/**
	 * Render the Settings screen.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'make-school' ) );
		}

		$s     = Make_School_Helpers::settings();
		$pages = get_pages( array( 'sort_column' => 'post_title' ) );
		?>
		<div class="wrap make-school-wrap">
			<h1><?php esc_html_e( 'Settings', 'make-school' ); ?></h1>
			<?php $this->render_flash(); ?>
			<form method="post" class="make-school-form">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="make_school_action" value="save_settings" />

				<h2><?php esc_html_e( 'Branding', 'make-school' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="ms-school-name"><?php esc_html_e( 'School name', 'make-school' ); ?></label></th>
						<td><input id="ms-school-name" type="text" name="settings[school_name]" value="<?php echo esc_attr( (string) $s['school_name'] ); ?>" class="regular-text" /></td></tr>
				</table>

				<h2><?php esc_html_e( 'Currency & numbering', 'make-school' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="ms-currency-symbol"><?php esc_html_e( 'Currency symbol', 'make-school' ); ?></label></th>
						<td><input id="ms-currency-symbol" type="text" name="settings[currency_symbol]" value="<?php echo esc_attr( (string) $s['currency_symbol'] ); ?>" class="small-text" /></td></tr>
					<tr><th><label for="ms-currency-code"><?php esc_html_e( 'Currency code', 'make-school' ); ?></label></th>
						<td><input id="ms-currency-code" type="text" name="settings[currency_code]" value="<?php echo esc_attr( (string) $s['currency_code'] ); ?>" class="small-text" maxlength="3" /></td></tr>
					<tr><th><label for="ms-roll-prefix"><?php esc_html_e( 'Roll number prefix', 'make-school' ); ?></label></th>
						<td><input id="ms-roll-prefix" type="text" name="settings[roll_number_prefix]" value="<?php echo esc_attr( (string) $s['roll_number_prefix'] ); ?>" class="small-text" /></td></tr>
					<tr><th><label for="ms-invoice-prefix"><?php esc_html_e( 'Invoice prefix', 'make-school' ); ?></label></th>
						<td><input id="ms-invoice-prefix" type="text" name="settings[invoice_prefix]" value="<?php echo esc_attr( (string) $s['invoice_prefix'] ); ?>" class="small-text" /></td></tr>
					<tr><th><label for="ms-pass-mark"><?php esc_html_e( 'Default pass mark (%)', 'make-school' ); ?></label></th>
						<td><input id="ms-pass-mark" type="number" min="0" max="100" name="settings[default_pass_mark]" value="<?php echo esc_attr( (string) $s['default_pass_mark'] ); ?>" class="small-text" /></td></tr>
				</table>

				<h2><?php esc_html_e( 'Pages & redirection', 'make-school' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Pick the front-end pages that host the login and dashboard shortcodes. Used by the role-based redirection engine.', 'make-school' ); ?></p>
				<table class="form-table" role="presentation">
					<tr><th><label for="ms-login-page"><?php esc_html_e( 'Login page', 'make-school' ); ?></label></th>
						<td>
							<select id="ms-login-page" name="settings[login_page_id]">
								<option value="0"><?php esc_html_e( '— Use wp-login.php —', 'make-school' ); ?></option>
								<?php foreach ( $pages as $p ) : ?>
									<option value="<?php echo esc_attr( (string) $p->ID ); ?>" <?php selected( (int) $s['login_page_id'], (int) $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td></tr>
					<tr><th><label for="ms-student-page"><?php esc_html_e( 'Student / Parent dashboard', 'make-school' ); ?></label></th>
						<td>
							<select id="ms-student-page" name="settings[student_dashboard_page_id]">
								<option value="0"><?php esc_html_e( '— /student-dashboard/ —', 'make-school' ); ?></option>
								<?php foreach ( $pages as $p ) : ?>
									<option value="<?php echo esc_attr( (string) $p->ID ); ?>" <?php selected( (int) $s['student_dashboard_page_id'], (int) $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td></tr>
					<tr><th><label for="ms-teacher-page"><?php esc_html_e( 'Teacher dashboard', 'make-school' ); ?></label></th>
						<td>
							<select id="ms-teacher-page" name="settings[teacher_dashboard_page_id]">
								<option value="0"><?php esc_html_e( '— /teacher-dashboard/ —', 'make-school' ); ?></option>
								<?php foreach ( $pages as $p ) : ?>
									<option value="<?php echo esc_attr( (string) $p->ID ); ?>" <?php selected( (int) $s['teacher_dashboard_page_id'], (int) $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td></tr>
				</table>

				<h2><?php esc_html_e( 'Notifications', 'make-school' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="ms-email-notify"><?php esc_html_e( 'Email notifications', 'make-school' ); ?></label></th>
						<td>
							<label>
								<input id="ms-email-notify" type="checkbox" name="settings[enable_email_notify]" value="1" <?php checked( (int) $s['enable_email_notify'], 1 ); ?> />
								<?php esc_html_e( 'Send email when an admission is approved or rejected.', 'make-school' ); ?>
							</label>
						</td></tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'make-school' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist settings.
	 *
	 * @return void
	 */
	private function save_settings() {
		check_admin_referer( self::NONCE_ACTION );

		$incoming = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$current  = Make_School_Helpers::settings();

		$current['school_name']               = isset( $incoming['school_name'] ) ? sanitize_text_field( $incoming['school_name'] ) : $current['school_name'];
		$current['currency_symbol']           = isset( $incoming['currency_symbol'] ) ? sanitize_text_field( $incoming['currency_symbol'] ) : $current['currency_symbol'];
		$current['currency_code']             = isset( $incoming['currency_code'] ) ? strtoupper( sanitize_text_field( $incoming['currency_code'] ) ) : $current['currency_code'];
		$current['roll_number_prefix']        = isset( $incoming['roll_number_prefix'] ) ? sanitize_text_field( $incoming['roll_number_prefix'] ) : $current['roll_number_prefix'];
		$current['invoice_prefix']            = isset( $incoming['invoice_prefix'] ) ? sanitize_text_field( $incoming['invoice_prefix'] ) : $current['invoice_prefix'];
		$current['default_pass_mark']         = isset( $incoming['default_pass_mark'] ) ? max( 0, min( 100, (int) $incoming['default_pass_mark'] ) ) : $current['default_pass_mark'];
		$current['login_page_id']             = isset( $incoming['login_page_id'] ) ? absint( $incoming['login_page_id'] ) : 0;
		$current['student_dashboard_page_id'] = isset( $incoming['student_dashboard_page_id'] ) ? absint( $incoming['student_dashboard_page_id'] ) : 0;
		$current['teacher_dashboard_page_id'] = isset( $incoming['teacher_dashboard_page_id'] ) ? absint( $incoming['teacher_dashboard_page_id'] ) : 0;
		$current['enable_email_notify']       = ! empty( $incoming['enable_email_notify'] ) ? 1 : 0;

		update_option( 'make_school_settings', $current );

		$this->push_flash( __( 'Settings saved.', 'make-school' ) );
		$this->redirect( admin_url( 'admin.php?page=make-school-settings' ) );
	}

	/* =====================================================================
	 * SHARED HELPERS
	 * ================================================================== */

	/**
	 * Push a transient flash message keyed to the current admin user.
	 *
	 * @param string $message Message.
	 * @param string $type    Notice type.
	 * @return void
	 */
	public function push_flash( $message, $type = 'success' ) {
		set_transient( 'make_school_admin_flash_' . get_current_user_id(), array( 'msg' => (string) $message, 'type' => $type ), 60 );
	}

	/**
	 * Render any pending flash message.
	 *
	 * @return void
	 */
	public function render_flash() {
		$key = 'make_school_admin_flash_' . get_current_user_id();
		$f   = get_transient( $key );
		if ( ! $f || empty( $f['msg'] ) ) {
			return;
		}
		delete_transient( $key );
		echo Make_School_Helpers::notice( $f['msg'], isset( $f['type'] ) ? (string) $f['type'] : 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Safe redirect + die.
	 *
	 * @param string $url Target URL.
	 * @return void
	 */
	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
