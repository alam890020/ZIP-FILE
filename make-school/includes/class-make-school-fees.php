<?php
/**
 * MAKE SCHOOL — Fees & Invoices.
 *
 * Owns:
 *   - Fee Type CRUD (admin/sub-page).
 *   - Invoice list with filters (admin).
 *   - Create-invoice flow: either a single student or a bulk
 *     generation against a whole class (one $wpdb->insert per student).
 *   - Record-payment flow with status auto-transitioning between
 *     unpaid / partially_paid / paid.
 *   - Student / parent ledger (frontend section).
 *   - Printable receipt rendered via the public route `?make_school_receipt=ID`.
 *   - Gateway hook: filter `make_school_pay_invoice_url` lets Stripe /
 *     Razorpay add-ons inject their own checkout URL.
 *
 * @package MakeSchool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Make_School_Fees
 */
class Make_School_Fees {

	const CAP_MANAGE   = 'make_school_manage_fees';
	const NONCE_ACTION = 'make_school_fees';

	/**
	 * Constructor — wires hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_admin_action' ) );

		// Public receipt route.
		add_action( 'template_redirect', array( $this, 'maybe_render_receipt' ) );
	}

	/* =====================================================================
	 * ADMIN MENUS
	 * ================================================================== */

	/**
	 * Register the admin sub-pages: Fees, Fee Types.
	 *
	 * @return void
	 */
	public function register_admin_menus() {
		add_submenu_page(
			Make_School_Admin::PARENT_SLUG,
			__( 'Fees & Invoices', 'make-school' ),
			__( 'Fees', 'make-school' ),
			self::CAP_MANAGE,
			'make-school-fees',
			array( $this, 'render_invoices_page' )
		);

		add_submenu_page(
			Make_School_Admin::PARENT_SLUG,
			__( 'Fee Types', 'make-school' ),
			__( 'Fee Types', 'make-school' ),
			self::CAP_MANAGE,
			'make-school-fee-types',
			array( $this, 'render_fee_types_page' )
		);
	}

	/* =====================================================================
	 * ADMIN — FEE TYPES CRUD
	 * ================================================================== */

	/**
	 * Render the fee types screen (list + add inline form).
	 *
	 * @return void
	 */
	public function render_fee_types_page() {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'make-school' ) );
		}

		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'list';
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		echo '<div class="wrap make-school-wrap"><h1>' . esc_html__( 'Fee Types', 'make-school' );
		if ( 'list' === $mode ) {
			echo ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=make-school-fee-types&mode=edit' ) ) . '">' . esc_html__( 'Add New', 'make-school' ) . '</a>';
		}
		echo '</h1>';
		make_school()->admin->render_flash();

		if ( 'edit' === $mode ) {
			$this->render_fee_type_form( $id );
		} else {
			$this->render_fee_types_list();
		}

		echo '</div>';
	}

	/**
	 * Render the fee types list table.
	 *
	 * @return void
	 */
	private function render_fee_types_list() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . make_school()->db->table( 'fee_types' ) . ' ORDER BY name ASC' ); // phpcs:ignore
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Name', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Slug', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Default Amount', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Status', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'make-school' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No fee types defined.', 'make-school' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r->name ); ?></strong></td>
						<td><code><?php echo esc_html( $r->slug ); ?></code></td>
						<td><?php echo esc_html( Make_School_Helpers::format_currency( $r->default_amount ) ); ?></td>
						<td><span class="make-school-pill make-school-pill-<?php echo esc_attr( $r->status ); ?>"><?php echo esc_html( Make_School_Helpers::status_label( $r->status ) ); ?></span></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-fee-types&mode=edit&id=' . (int) $r->id ) ); ?>"><?php esc_html_e( 'Edit', 'make-school' ); ?></a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=make-school-fee-types&make_school_action=delete_fee_type&id=' . (int) $r->id ), self::NONCE_ACTION ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this fee type?', 'make-school' ) ); ?>');" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'make-school' ); ?></a>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the fee type add/edit form.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	private function render_fee_type_form( $id ) {
		global $wpdb;
		$row = null;
		if ( $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . make_school()->db->table( 'fee_types' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
		}
		$g = function ( $key, $default = '' ) use ( $row ) {
			return $row && isset( $row->$key ) ? $row->$key : $default;
		};
		?>
		<form method="post" class="make-school-form">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="make_school_action" value="save_fee_type" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<table class="form-table">
				<tr><th><label><?php esc_html_e( 'Name', 'make-school' ); ?> *</label></th>
					<td><input type="text" name="name" value="<?php echo esc_attr( $g( 'name' ) ); ?>" class="regular-text" required /></td></tr>
				<tr><th><label><?php esc_html_e( 'Slug', 'make-school' ); ?></label></th>
					<td><input type="text" name="slug" value="<?php echo esc_attr( $g( 'slug' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Auto-generated from name if left blank.', 'make-school' ); ?></p></td></tr>
				<tr><th><label><?php esc_html_e( 'Default Amount', 'make-school' ); ?></label></th>
					<td><input type="number" step="0.01" min="0" name="default_amount" value="<?php echo esc_attr( (string) $g( 'default_amount', 0 ) ); ?>" class="small-text" /></td></tr>
				<tr><th><label><?php esc_html_e( 'Description', 'make-school' ); ?></label></th>
					<td><textarea name="description" rows="3" class="large-text"><?php echo esc_textarea( $g( 'description' ) ); ?></textarea></td></tr>
				<tr><th><label><?php esc_html_e( 'Status', 'make-school' ); ?></label></th>
					<td>
						<select name="status">
							<option value="active" <?php selected( $g( 'status', 'active' ), 'active' ); ?>><?php esc_html_e( 'Active', 'make-school' ); ?></option>
							<option value="inactive" <?php selected( $g( 'status', 'active' ), 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'make-school' ); ?></option>
						</select>
					</td></tr>
			</table>
			<p class="submit">
				<button class="button button-primary" type="submit"><?php echo esc_html( $id ? __( 'Update', 'make-school' ) : __( 'Create', 'make-school' ) ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-fee-types' ) ); ?>"><?php esc_html_e( 'Cancel', 'make-school' ); ?></a>
			</p>
		</form>
		<?php
	}

	/* =====================================================================
	 * ADMIN — INVOICES
	 * ================================================================== */

	/**
	 * Render the invoices admin screen (list + create + view + record).
	 *
	 * @return void
	 */
	public function render_invoices_page() {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'make-school' ) );
		}

		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'list';
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		echo '<div class="wrap make-school-wrap"><h1>' . esc_html__( 'Fees & Invoices', 'make-school' );
		if ( 'list' === $mode ) {
			echo ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=make-school-fees&mode=create' ) ) . '">' . esc_html__( 'New Invoice', 'make-school' ) . '</a>';
			echo ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=make-school-fees&mode=bulk' ) ) . '">' . esc_html__( 'Bulk Generate', 'make-school' ) . '</a>';
		}
		echo '</h1>';
		make_school()->admin->render_flash();

		switch ( $mode ) {
			case 'create':
				$this->render_invoice_create_form();
				break;
			case 'bulk':
				$this->render_bulk_create_form();
				break;
			case 'view':
				$this->render_invoice_view( $id );
				break;
			case 'list':
			default:
				$this->render_invoices_list();
		}
		echo '</div>';
	}

	/**
	 * Invoice list with filters.
	 *
	 * @return void
	 */
	private function render_invoices_list() {
		global $wpdb;
		$table_inv = make_school()->db->table( 'invoices' );

		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$class_id = isset( $_GET['class_id'] ) ? absint( wp_unslash( $_GET['class_id'] ) ) : 0;
		$session  = isset( $_GET['session'] ) ? sanitize_text_field( wp_unslash( $_GET['session'] ) ) : '';

		$where  = array( '1=1' );
		$params = array();
		if ( in_array( $status, array( 'unpaid', 'paid', 'partially_paid' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( $class_id ) {
			$where[]  = 'class_id = %d';
			$params[] = $class_id;
		}
		if ( $session ) {
			$where[]  = 'session = %s';
			$params[] = $session;
		}
		$sql = 'SELECT * FROM ' . $table_inv . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 200';
		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) // phpcs:ignore
			: $wpdb->get_results( $sql ); // phpcs:ignore

		$classes  = Make_School_Helpers::get_classes();
		$sessions = Make_School_Helpers::sessions();
		?>
		<form method="get" class="make-school-inline-form">
			<input type="hidden" name="page" value="make-school-fees" />
			<select name="status">
				<option value=""><?php esc_html_e( 'Any status', 'make-school' ); ?></option>
				<?php foreach ( array( 'unpaid', 'partially_paid', 'paid' ) as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( Make_School_Helpers::status_label( $s ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="class_id">
				<option value="0"><?php esc_html_e( 'Any class', 'make-school' ); ?></option>
				<?php foreach ( $classes as $c ) : ?>
					<option value="<?php echo esc_attr( (string) $c->id ); ?>" <?php selected( $class_id, (int) $c->id ); ?>><?php echo esc_html( Make_School_Helpers::class_label( $c ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="session">
				<option value=""><?php esc_html_e( 'Any session', 'make-school' ); ?></option>
				<?php foreach ( $sessions as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $session, $s ); ?>><?php echo esc_html( $s ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button" type="submit"><?php esc_html_e( 'Filter', 'make-school' ); ?></button>
		</form>

		<table class="wp-list-table widefat fixed striped" style="margin-top:14px;">
			<thead><tr>
				<th><?php esc_html_e( 'Invoice', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Student', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Class', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Type', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Total', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Paid', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Status', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Due', 'make-school' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'make-school' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="9"><?php esc_html_e( 'No invoices match the filters.', 'make-school' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) :
					$student = $r->student_user_id ? get_user_by( 'id', (int) $r->student_user_id ) : null;
					$class   = Make_School_Helpers::get_class( (int) $r->class_id );
					$total   = (float) $r->amount + (float) $r->tax - (float) $r->discount;
					?>
					<tr>
						<td><strong><?php echo esc_html( $r->invoice_no ); ?></strong></td>
						<td><?php echo $student ? esc_html( $student->display_name ) : '—'; ?></td>
						<td><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></td>
						<td><?php echo esc_html( $r->fee_type ); ?></td>
						<td><?php echo esc_html( Make_School_Helpers::format_currency( $total ) ); ?></td>
						<td><?php echo esc_html( Make_School_Helpers::format_currency( $r->amount_paid ) ); ?></td>
						<td><span class="make-school-pill make-school-pill-<?php echo esc_attr( $r->status ); ?>"><?php echo esc_html( Make_School_Helpers::status_label( $r->status ) ); ?></span></td>
						<td><?php echo esc_html( Make_School_Helpers::format_date( $r->due_date ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-fees&mode=view&id=' . (int) $r->id ) ); ?>"><?php esc_html_e( 'View', 'make-school' ); ?></a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( $this->receipt_url( (int) $r->id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Receipt', 'make-school' ); ?></a>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the single-invoice creation form.
	 *
	 * @return void
	 */
	private function render_invoice_create_form() {
		$students = $this->student_options();
		$types    = Make_School_Helpers::get_fee_types();
		?>
		<h2><?php esc_html_e( 'Create invoice', 'make-school' ); ?></h2>
		<form method="post" class="make-school-form">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="make_school_action" value="create_invoice" />
			<table class="form-table">
				<tr><th><label><?php esc_html_e( 'Student', 'make-school' ); ?> *</label></th>
					<td>
						<select name="student_user_id" required>
							<option value=""><?php esc_html_e( '— Select —', 'make-school' ); ?></option>
							<?php foreach ( $students as $opt ) : ?>
								<option value="<?php echo esc_attr( (string) $opt['id'] ); ?>"><?php echo esc_html( $opt['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<tr><th><label><?php esc_html_e( 'Fee type', 'make-school' ); ?> *</label></th>
					<td>
						<select name="fee_type" required>
							<option value=""><?php esc_html_e( '— Select —', 'make-school' ); ?></option>
							<?php foreach ( $types as $t ) : ?>
								<option value="<?php echo esc_attr( $t->name ); ?>" data-amount="<?php echo esc_attr( (string) $t->default_amount ); ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<tr><th><label><?php esc_html_e( 'Amount', 'make-school' ); ?> *</label></th>
					<td><input type="number" step="0.01" min="0" name="amount" value="0.00" required /></td></tr>
				<tr><th><label><?php esc_html_e( 'Discount', 'make-school' ); ?></label></th>
					<td><input type="number" step="0.01" min="0" name="discount" value="0.00" /></td></tr>
				<tr><th><label><?php esc_html_e( 'Tax', 'make-school' ); ?></label></th>
					<td><input type="number" step="0.01" min="0" name="tax" value="0.00" /></td></tr>
				<tr><th><label><?php esc_html_e( 'Due date', 'make-school' ); ?></label></th>
					<td><input type="date" name="due_date" value="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '+30 days' ) ) ); ?>" /></td></tr>
				<tr><th><label><?php esc_html_e( 'Description', 'make-school' ); ?></label></th>
					<td><textarea name="description" rows="3" class="large-text"></textarea></td></tr>
			</table>
			<p class="submit">
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Create Invoice', 'make-school' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-fees' ) ); ?>"><?php esc_html_e( 'Cancel', 'make-school' ); ?></a>
			</p>
		</form>
		<script>
		(function(){
			var sel = document.querySelector('select[name="fee_type"]');
			var amt = document.querySelector('input[name="amount"]');
			if (sel && amt){
				sel.addEventListener('change', function(){
					var o = sel.options[sel.selectedIndex];
					if (o && o.dataset && o.dataset.amount) { amt.value = o.dataset.amount; }
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Render the bulk class invoice creation form.
	 *
	 * @return void
	 */
	private function render_bulk_create_form() {
		$classes = Make_School_Helpers::get_classes();
		$types   = Make_School_Helpers::get_fee_types();
		?>
		<h2><?php esc_html_e( 'Bulk generate invoices', 'make-school' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Issues one invoice per enrolled student in the chosen class. Useful for monthly tuition runs.', 'make-school' ); ?></p>
		<form method="post" class="make-school-form">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="make_school_action" value="bulk_create_invoices" />
			<table class="form-table">
				<tr><th><label><?php esc_html_e( 'Class', 'make-school' ); ?> *</label></th>
					<td>
						<select name="class_id" required>
							<option value=""><?php esc_html_e( '— Select —', 'make-school' ); ?></option>
							<?php foreach ( $classes as $c ) : ?>
								<option value="<?php echo esc_attr( (string) $c->id ); ?>"><?php echo esc_html( Make_School_Helpers::class_label( $c ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<tr><th><label><?php esc_html_e( 'Fee type', 'make-school' ); ?> *</label></th>
					<td>
						<select name="fee_type" required>
							<option value=""><?php esc_html_e( '— Select —', 'make-school' ); ?></option>
							<?php foreach ( $types as $t ) : ?>
								<option value="<?php echo esc_attr( $t->name ); ?>" data-amount="<?php echo esc_attr( (string) $t->default_amount ); ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				<tr><th><label><?php esc_html_e( 'Amount per student', 'make-school' ); ?> *</label></th>
					<td><input type="number" step="0.01" min="0" name="amount" value="0.00" required /></td></tr>
				<tr><th><label><?php esc_html_e( 'Due date', 'make-school' ); ?></label></th>
					<td><input type="date" name="due_date" value="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '+30 days' ) ) ); ?>" /></td></tr>
				<tr><th><label><?php esc_html_e( 'Description', 'make-school' ); ?></label></th>
					<td><textarea name="description" rows="3" class="large-text"></textarea></td></tr>
			</table>
			<p class="submit">
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Generate invoices', 'make-school' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-fees' ) ); ?>"><?php esc_html_e( 'Cancel', 'make-school' ); ?></a>
			</p>
		</form>
		<script>
		(function(){
			var sel = document.querySelector('select[name="fee_type"]');
			var amt = document.querySelector('input[name="amount"]');
			if (sel && amt){
				sel.addEventListener('change', function(){
					var o = sel.options[sel.selectedIndex];
					if (o && o.dataset && o.dataset.amount) { amt.value = o.dataset.amount; }
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Render the single-invoice detail / payment screen.
	 *
	 * @param int $id Invoice ID.
	 * @return void
	 */
	private function render_invoice_view( $id ) {
		$inv = $this->get_invoice( $id );
		if ( ! $inv ) {
			echo '<p>' . esc_html__( 'Invoice not found.', 'make-school' ) . '</p>';
			return;
		}
		$student = $inv->student_user_id ? get_user_by( 'id', (int) $inv->student_user_id ) : null;
		$class   = Make_School_Helpers::get_class( (int) $inv->class_id );
		$total   = (float) $inv->amount + (float) $inv->tax - (float) $inv->discount;
		$balance = max( 0, $total - (float) $inv->amount_paid );
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=make-school-fees' ) ); ?>">&larr; <?php esc_html_e( 'Back to invoices', 'make-school' ); ?></a></p>
		<div class="make-school-card">
			<h2><?php echo esc_html( $inv->invoice_no ); ?>
				<span class="make-school-pill make-school-pill-<?php echo esc_attr( $inv->status ); ?>" style="float:right;">
					<?php echo esc_html( Make_School_Helpers::status_label( $inv->status ) ); ?>
				</span>
			</h2>
			<table class="widefat striped">
				<tr><th><?php esc_html_e( 'Student', 'make-school' ); ?></th><td><?php echo $student ? esc_html( $student->display_name . ' (' . $student->user_email . ')' ) : '—'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Class', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Session', 'make-school' ); ?></th><td><?php echo esc_html( $inv->session ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Fee type', 'make-school' ); ?></th><td><?php echo esc_html( $inv->fee_type ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Description', 'make-school' ); ?></th><td><?php echo nl2br( esc_html( (string) $inv->description ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Amount', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::format_currency( $inv->amount ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Discount', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::format_currency( $inv->discount ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Tax', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::format_currency( $inv->tax ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Total', 'make-school' ); ?></th><td><strong><?php echo esc_html( Make_School_Helpers::format_currency( $total ) ); ?></strong></td></tr>
				<tr><th><?php esc_html_e( 'Paid', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::format_currency( $inv->amount_paid ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Balance', 'make-school' ); ?></th><td><strong><?php echo esc_html( Make_School_Helpers::format_currency( $balance ) ); ?></strong></td></tr>
				<tr><th><?php esc_html_e( 'Due date', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::format_date( $inv->due_date ) ); ?></td></tr>
				<?php if ( $inv->paid_at ) : ?>
					<tr><th><?php esc_html_e( 'Last payment', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::format_datetime( $inv->paid_at ) ); ?> &middot; <?php echo esc_html( $inv->payment_method ); ?> <?php echo $inv->payment_ref ? '(' . esc_html( $inv->payment_ref ) . ')' : ''; ?></td></tr>
				<?php endif; ?>
			</table>

			<p style="margin-top:14px;">
				<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $this->receipt_url( (int) $inv->id ) ); ?>"><?php esc_html_e( 'Open receipt', 'make-school' ); ?></a>
			</p>

			<?php if ( 'paid' !== $inv->status ) : ?>
				<h3><?php esc_html_e( 'Record payment', 'make-school' ); ?></h3>
				<form method="post" class="make-school-form">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="make_school_action" value="record_payment" />
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $inv->id ); ?>" />
					<table class="form-table">
						<tr><th><label><?php esc_html_e( 'Amount', 'make-school' ); ?> *</label></th>
							<td><input type="number" step="0.01" min="0.01" max="<?php echo esc_attr( (string) $balance ); ?>" name="amount" value="<?php echo esc_attr( (string) $balance ); ?>" required /></td></tr>
						<tr><th><label><?php esc_html_e( 'Method', 'make-school' ); ?></label></th>
							<td>
								<select name="method">
									<option value="cash"><?php esc_html_e( 'Cash', 'make-school' ); ?></option>
									<option value="card"><?php esc_html_e( 'Card', 'make-school' ); ?></option>
									<option value="bank"><?php esc_html_e( 'Bank Transfer', 'make-school' ); ?></option>
									<option value="online"><?php esc_html_e( 'Online Gateway', 'make-school' ); ?></option>
									<option value="cheque"><?php esc_html_e( 'Cheque', 'make-school' ); ?></option>
								</select>
							</td></tr>
						<tr><th><label><?php esc_html_e( 'Reference', 'make-school' ); ?></label></th>
							<td><input type="text" name="payment_ref" value="" class="regular-text" /></td></tr>
					</table>
					<p class="submit"><button class="button button-primary" type="submit"><?php esc_html_e( 'Record payment', 'make-school' ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * ADMIN — ACTION ROUTER
	 * ================================================================== */

	/**
	 * Dispatch admin actions for fees + fee-types.
	 *
	 * @return void
	 */
	public function maybe_handle_admin_action() {
		if ( ! is_admin() || ! current_user_can( self::CAP_MANAGE ) ) {
			return;
		}

		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			$action = isset( $_POST['make_school_action'] ) ? sanitize_key( wp_unslash( $_POST['make_school_action'] ) ) : '';
			switch ( $action ) {
				case 'save_fee_type':
					$this->save_fee_type();
					break;
				case 'create_invoice':
					$this->create_invoice();
					break;
				case 'bulk_create_invoices':
					$this->bulk_create_invoices();
					break;
				case 'record_payment':
					$this->record_payment();
					break;
			}
		}

		$g = isset( $_GET['make_school_action'] ) ? sanitize_key( wp_unslash( $_GET['make_school_action'] ) ) : '';
		if ( 'delete_fee_type' === $g ) {
			$this->delete_fee_type();
		}
	}

	/**
	 * Save (insert/update) a fee type.
	 *
	 * @return void
	 */
	private function save_fee_type() {
		check_admin_referer( self::NONCE_ACTION );

		global $wpdb;
		$table = make_school()->db->table( 'fee_types' );
		$now   = current_time( 'mysql' );
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$slug  = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}
		if ( '' === $name || '' === $slug ) {
			make_school()->admin->push_flash( __( 'Name is required.', 'make-school' ), 'error' );
			$this->redirect( admin_url( 'admin.php?page=make-school-fee-types&mode=edit&id=' . $id ) );
		}

		$data = array(
			'name'           => $name,
			'slug'           => $slug,
			'default_amount' => isset( $_POST['default_amount'] ) ? (float) wp_unslash( $_POST['default_amount'] ) : 0,
			'description'    => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'status'         => ( isset( $_POST['status'] ) && 'inactive' === $_POST['status'] ) ? 'inactive' : 'active',
			'updated_at'     => $now,
		);
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore
			make_school()->admin->push_flash( __( 'Fee type updated.', 'make-school' ) );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data ); // phpcs:ignore
			make_school()->admin->push_flash( __( 'Fee type created.', 'make-school' ) );
		}
		$this->redirect( admin_url( 'admin.php?page=make-school-fee-types' ) );
	}

	/**
	 * Delete a fee type.
	 *
	 * @return void
	 */
	private function delete_fee_type() {
		check_admin_referer( self::NONCE_ACTION );
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $id ) {
			global $wpdb;
			$wpdb->delete( make_school()->db->table( 'fee_types' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore
			make_school()->admin->push_flash( __( 'Fee type deleted.', 'make-school' ) );
		}
		$this->redirect( admin_url( 'admin.php?page=make-school-fee-types' ) );
	}

	/**
	 * Create a single invoice.
	 *
	 * @return void
	 */
	private function create_invoice() {
		check_admin_referer( self::NONCE_ACTION );

		$student_id = isset( $_POST['student_user_id'] ) ? absint( $_POST['student_user_id'] ) : 0;
		$fee_type   = isset( $_POST['fee_type'] ) ? sanitize_text_field( wp_unslash( $_POST['fee_type'] ) ) : '';
		$amount     = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0;
		$discount   = isset( $_POST['discount'] ) ? (float) wp_unslash( $_POST['discount'] ) : 0;
		$tax        = isset( $_POST['tax'] ) ? (float) wp_unslash( $_POST['tax'] ) : 0;
		$due        = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';
		$desc       = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( ! $student_id || '' === $fee_type || $amount <= 0 ) {
			make_school()->admin->push_flash( __( 'Student, fee type and amount are required.', 'make-school' ), 'error' );
			$this->redirect( admin_url( 'admin.php?page=make-school-fees&mode=create' ) );
		}

		$id = $this->insert_invoice(
			array(
				'student_user_id' => $student_id,
				'fee_type'        => $fee_type,
				'amount'          => $amount,
				'discount'        => $discount,
				'tax'             => $tax,
				'due_date'        => $due ? $due : null,
				'description'     => $desc,
			)
		);

		if ( $id ) {
			make_school()->admin->push_flash( __( 'Invoice created.', 'make-school' ) );
			$this->redirect( admin_url( 'admin.php?page=make-school-fees&mode=view&id=' . $id ) );
		}
		make_school()->admin->push_flash( __( 'Could not create invoice.', 'make-school' ), 'error' );
		$this->redirect( admin_url( 'admin.php?page=make-school-fees&mode=create' ) );
	}

	/**
	 * Bulk create invoices for a class.
	 *
	 * @return void
	 */
	private function bulk_create_invoices() {
		check_admin_referer( self::NONCE_ACTION );

		$class_id = isset( $_POST['class_id'] ) ? absint( $_POST['class_id'] ) : 0;
		$fee_type = isset( $_POST['fee_type'] ) ? sanitize_text_field( wp_unslash( $_POST['fee_type'] ) ) : '';
		$amount   = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0;
		$due      = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';
		$desc     = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( ! $class_id || '' === $fee_type || $amount <= 0 ) {
			make_school()->admin->push_flash( __( 'Class, fee type and amount are required.', 'make-school' ), 'error' );
			$this->redirect( admin_url( 'admin.php?page=make-school-fees&mode=bulk' ) );
		}

		$students = Make_School_Helpers::students_in_class( $class_id );
		if ( empty( $students ) ) {
			make_school()->admin->push_flash( __( 'This class has no enrolled students.', 'make-school' ), 'warning' );
			$this->redirect( admin_url( 'admin.php?page=make-school-fees&mode=bulk' ) );
		}

		$created = 0;
		foreach ( $students as $s ) {
			if ( $this->insert_invoice(
				array(
					'student_user_id' => (int) $s->user_id,
					'fee_type'        => $fee_type,
					'amount'          => $amount,
					'due_date'        => $due ? $due : null,
					'description'     => $desc,
				)
			) ) {
				$created++;
			}
		}

		make_school()->admin->push_flash(
			sprintf(
				/* translators: %d: count */
				esc_html( _n( '%d invoice generated.', '%d invoices generated.', $created, 'make-school' ) ),
				$created
			)
		);
		$this->redirect( admin_url( 'admin.php?page=make-school-fees' ) );
	}

	/**
	 * Insert a new invoice row, deriving the missing context (branch, class,
	 * session) from the student's user-meta or the supplied class.
	 *
	 * @param array $args Invoice fields.
	 * @return int Inserted ID, or 0.
	 */
	private function insert_invoice( $args ) {
		global $wpdb;

		$args = array_merge(
			array(
				'student_user_id' => 0,
				'fee_type'        => '',
				'amount'          => 0,
				'discount'        => 0,
				'tax'             => 0,
				'due_date'        => null,
				'description'     => '',
				'class_id'        => 0,
				'branch_id'       => 0,
				'session'         => '',
			),
			$args
		);

		// Resolve class/branch/session from user-meta if not explicit.
		if ( $args['student_user_id'] && ! $args['class_id'] ) {
			$args['class_id']  = (int) get_user_meta( $args['student_user_id'], 'make_school_class_id', true );
			$args['branch_id'] = (int) get_user_meta( $args['student_user_id'], 'make_school_branch_id', true );
			$args['session']   = (string) get_user_meta( $args['student_user_id'], 'make_school_session', true );
		}
		if ( ! $args['session'] ) {
			$args['session'] = Make_School_Helpers::current_session();
		}

		$now    = current_time( 'mysql' );
		$res    = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			make_school()->db->table( 'invoices' ),
			array(
				'invoice_no'      => Make_School_Helpers::generate_invoice_number(),
				'branch_id'       => (int) $args['branch_id'],
				'session'         => (string) $args['session'],
				'class_id'        => (int) $args['class_id'],
				'student_user_id' => (int) $args['student_user_id'],
				'fee_type'        => (string) $args['fee_type'],
				'description'     => (string) $args['description'],
				'amount'          => (float) $args['amount'],
				'discount'        => (float) $args['discount'],
				'tax'             => (float) $args['tax'],
				'amount_paid'     => 0.00,
				'currency'        => (string) Make_School_Helpers::setting( 'currency_code', 'USD' ),
				'due_date'        => $args['due_date'] ? $args['due_date'] : null,
				'paid_at'         => null,
				'payment_method'  => '',
				'payment_ref'     => '',
				'status'          => 'unpaid',
				'created_by'      => (int) get_current_user_id(),
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		return $res ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Record a payment against an invoice. Auto-transitions the status:
	 *   amount_paid >= total           → paid
	 *   0 < amount_paid < total        → partially_paid
	 *   amount_paid <= 0               → unpaid (unchanged)
	 *
	 * @return void
	 */
	private function record_payment() {
		check_admin_referer( self::NONCE_ACTION );

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$amt    = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0;
		$method = isset( $_POST['method'] ) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : 'cash';
		$ref    = isset( $_POST['payment_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_ref'] ) ) : '';

		$inv = $this->get_invoice( $id );
		if ( ! $inv || $amt <= 0 ) {
			make_school()->admin->push_flash( __( 'Invalid payment.', 'make-school' ), 'error' );
			$this->redirect( admin_url( 'admin.php?page=make-school-fees&mode=view&id=' . $id ) );
		}

		$total       = (float) $inv->amount + (float) $inv->tax - (float) $inv->discount;
		$paid_total  = (float) $inv->amount_paid + $amt;
		$paid_total  = round( $paid_total, 2 );
		$status      = 'unpaid';
		if ( $paid_total >= $total - 0.001 ) {
			$status     = 'paid';
			$paid_total = $total; // clamp
		} elseif ( $paid_total > 0 ) {
			$status = 'partially_paid';
		}

		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->update( // phpcs:ignore
			make_school()->db->table( 'invoices' ),
			array(
				'amount_paid'    => $paid_total,
				'status'         => $status,
				'payment_method' => $method,
				'payment_ref'    => $ref,
				'paid_at'        => $now,
				'updated_at'     => $now,
			),
			array( 'id' => (int) $id ),
			array( '%f', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		make_school()->admin->push_flash( __( 'Payment recorded.', 'make-school' ) );
		$this->redirect( admin_url( 'admin.php?page=make-school-fees&mode=view&id=' . $id ) );
	}

	/* =====================================================================
	 * STUDENT/PARENT FRONTEND LEDGER
	 * ================================================================== */

	/**
	 * Render the student fee ledger (called by the dashboards module).
	 *
	 * @param int    $student_id WP user ID.
	 * @param object $admission  Admission row.
	 * @return void
	 */
	public function render_student_section( $student_id, $admission ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				'SELECT * FROM ' . make_school()->db->table( 'invoices' ) . ' WHERE student_user_id = %d ORDER BY id DESC',
				$student_id
			)
		);

		$total_due  = 0.0;
		$total_paid = 0.0;
		foreach ( $rows as $r ) {
			$net          = (float) $r->amount + (float) $r->tax - (float) $r->discount;
			$total_due   += $net;
			$total_paid  += (float) $r->amount_paid;
		}
		$balance = max( 0, $total_due - $total_paid );
		?>
		<div class="make-school-card">
			<h3><?php esc_html_e( 'Fee statement', 'make-school' ); ?></h3>
			<div class="make-school-kpi-grid">
				<div class="make-school-kpi"><span><?php esc_html_e( 'Billed', 'make-school' ); ?></span><strong><?php echo esc_html( Make_School_Helpers::format_currency( $total_due ) ); ?></strong></div>
				<div class="make-school-kpi"><span><?php esc_html_e( 'Paid', 'make-school' ); ?></span><strong><?php echo esc_html( Make_School_Helpers::format_currency( $total_paid ) ); ?></strong></div>
				<div class="make-school-kpi"><span><?php esc_html_e( 'Balance', 'make-school' ); ?></span><strong><?php echo esc_html( Make_School_Helpers::format_currency( $balance ) ); ?></strong></div>
			</div>

			<?php if ( empty( $rows ) ) : ?>
				<p style="margin-top:14px;"><?php esc_html_e( 'No invoices issued yet.', 'make-school' ); ?></p>
			<?php else : ?>
				<table class="make-school-data-table" style="margin-top:14px;">
					<thead><tr>
						<th><?php esc_html_e( 'Invoice', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Type', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Issued', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Due', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Total', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Paid', 'make-school' ); ?></th>
						<th><?php esc_html_e( 'Status', 'make-school' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rows as $r ) :
						$net    = (float) $r->amount + (float) $r->tax - (float) $r->discount;
						$bal    = max( 0, $net - (float) $r->amount_paid );
						$payurl = $this->pay_url( $r );
						?>
						<tr>
							<td><strong><?php echo esc_html( $r->invoice_no ); ?></strong></td>
							<td><?php echo esc_html( $r->fee_type ); ?></td>
							<td><?php echo esc_html( Make_School_Helpers::format_date( $r->created_at ) ); ?></td>
							<td><?php echo esc_html( Make_School_Helpers::format_date( $r->due_date ) ); ?></td>
							<td><?php echo esc_html( Make_School_Helpers::format_currency( $net ) ); ?></td>
							<td><?php echo esc_html( Make_School_Helpers::format_currency( $r->amount_paid ) ); ?></td>
							<td><span class="make-school-pill make-school-pill-<?php echo esc_attr( $r->status ); ?>"><?php echo esc_html( Make_School_Helpers::status_label( $r->status ) ); ?></span></td>
							<td>
								<a href="<?php echo esc_url( $this->receipt_url( (int) $r->id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Receipt', 'make-school' ); ?></a>
								<?php if ( $bal > 0 && $payurl ) : ?>
									&nbsp;|&nbsp;<a href="<?php echo esc_url( $payurl ); ?>"><?php esc_html_e( 'Pay', 'make-school' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		unset( $admission );
	}

	/**
	 * Filterable pay URL — gateway add-ons can override this.
	 *
	 * @param object $invoice Invoice row.
	 * @return string
	 */
	private function pay_url( $invoice ) {
		/**
		 * Filter the "pay invoice" URL.
		 *
		 * Stripe / Razorpay extensions return the gateway checkout URL.
		 * Default behaviour: `?make_school_pay=ID` placeholder.
		 *
		 * @param string $url     Default URL.
		 * @param object $invoice Invoice row.
		 */
		return apply_filters(
			'make_school_pay_invoice_url',
			add_query_arg( 'make_school_pay', (int) $invoice->id, home_url( '/' ) ),
			$invoice
		);
	}

	/* =====================================================================
	 * PUBLIC RECEIPT ROUTE
	 * ================================================================== */

	/**
	 * Build a printable-receipt URL.
	 *
	 * @param int $invoice_id Invoice ID.
	 * @return string
	 */
	private function receipt_url( $invoice_id ) {
		return add_query_arg( 'make_school_receipt', (int) $invoice_id, home_url( '/' ) );
	}

	/**
	 * If `?make_school_receipt=ID` is present, render a clean
	 * printer-friendly receipt and exit (replaces the theme).
	 *
	 * @return void
	 */
	public function maybe_render_receipt() {
		if ( ! isset( $_GET['make_school_receipt'] ) ) {
			return;
		}
		$id  = absint( wp_unslash( $_GET['make_school_receipt'] ) );
		$inv = $this->get_invoice( $id );
		if ( ! $inv ) {
			wp_die( esc_html__( 'Invoice not found.', 'make-school' ) );
		}

		// Authorisation: the invoiced student, their parent, or a manager.
		$user = wp_get_current_user();
		$is_owner   = is_user_logged_in() && (int) $user->ID === (int) $inv->student_user_id;
		$is_parent  = Make_School_Helpers::is_parent() && (int) get_user_meta( $user->ID, 'make_school_child_user_id', true ) === (int) $inv->student_user_id;
		$is_manager = current_user_can( self::CAP_MANAGE );
		if ( ! ( $is_owner || $is_parent || $is_manager ) ) {
			wp_die( esc_html__( 'You are not allowed to view this receipt.', 'make-school' ), 403 );
		}

		$student = $inv->student_user_id ? get_user_by( 'id', (int) $inv->student_user_id ) : null;
		$class   = Make_School_Helpers::get_class( (int) $inv->class_id );
		$total   = (float) $inv->amount + (float) $inv->tax - (float) $inv->discount;
		$balance = max( 0, $total - (float) $inv->amount_paid );

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $inv->invoice_no ); ?> — <?php echo esc_html( Make_School_Helpers::setting( 'school_name' ) ); ?></title>
	<style>
		body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#222;margin:0;padding:24px;background:#f5f6f8;}
		.receipt{max-width:760px;margin:0 auto;background:#fff;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.06);padding:32px;}
		.r-head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1px solid #eee;padding-bottom:16px;margin-bottom:18px;}
		.r-head h1{margin:0 0 4px 0;font-size:22px;}
		.r-head h2{margin:0;font-size:14px;color:#777;font-weight:500;}
		.pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;}
		.pill-paid{background:#e7f6ec;color:#1d6f42;}
		.pill-unpaid{background:#fdecec;color:#b32d2e;}
		.pill-partially_paid{background:#fff7e0;color:#a56500;}
		table{width:100%;border-collapse:collapse;margin-top:14px;}
		th,td{text-align:left;padding:8px 6px;border-bottom:1px solid #f0f0f0;font-size:14px;}
		th{font-weight:600;color:#666;font-size:12px;text-transform:uppercase;letter-spacing:.04em;}
		.totals td{font-size:14px;}
		.totals .grand{font-size:18px;font-weight:700;}
		.print{margin-top:16px;}
		button{padding:8px 14px;font-size:14px;border-radius:6px;border:1px solid #ddd;background:#fff;cursor:pointer;}
		@media print { body{background:#fff;padding:0;} .receipt{box-shadow:none;border-radius:0;} .print{display:none;} }
	</style>
</head>
<body>
	<div class="receipt">
		<div class="r-head">
			<div>
				<h1><?php echo esc_html( Make_School_Helpers::setting( 'school_name' ) ); ?></h1>
				<h2><?php esc_html_e( 'Fee Receipt', 'make-school' ); ?></h2>
			</div>
			<div style="text-align:right;">
				<div><strong><?php echo esc_html( $inv->invoice_no ); ?></strong></div>
				<div style="font-size:12px;color:#888;"><?php echo esc_html( Make_School_Helpers::format_datetime( $inv->created_at ) ); ?></div>
				<div style="margin-top:6px;"><span class="pill pill-<?php echo esc_attr( $inv->status ); ?>"><?php echo esc_html( Make_School_Helpers::status_label( $inv->status ) ); ?></span></div>
			</div>
		</div>

		<table>
			<tr><th><?php esc_html_e( 'Student', 'make-school' ); ?></th><td><?php echo $student ? esc_html( $student->display_name ) : '—'; ?></td></tr>
			<tr><th><?php esc_html_e( 'Class', 'make-school' ); ?></th><td><?php echo esc_html( Make_School_Helpers::class_label( $class ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Session', 'make-school' ); ?></th><td><?php echo esc_html( $inv->session ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Fee type', 'make-school' ); ?></th><td><?php echo esc_html( $inv->fee_type ); ?></td></tr>
			<?php if ( $inv->description ) : ?>
				<tr><th><?php esc_html_e( 'Description', 'make-school' ); ?></th><td><?php echo nl2br( esc_html( (string) $inv->description ) ); ?></td></tr>
			<?php endif; ?>
		</table>

		<table class="totals">
			<tr><th><?php esc_html_e( 'Amount', 'make-school' ); ?></th><td style="text-align:right;"><?php echo esc_html( Make_School_Helpers::format_currency( $inv->amount ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Discount', 'make-school' ); ?></th><td style="text-align:right;">- <?php echo esc_html( Make_School_Helpers::format_currency( $inv->discount ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Tax', 'make-school' ); ?></th><td style="text-align:right;"><?php echo esc_html( Make_School_Helpers::format_currency( $inv->tax ) ); ?></td></tr>
			<tr><th class="grand"><?php esc_html_e( 'Total', 'make-school' ); ?></th><td class="grand" style="text-align:right;"><?php echo esc_html( Make_School_Helpers::format_currency( $total ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Paid', 'make-school' ); ?></th><td style="text-align:right;"><?php echo esc_html( Make_School_Helpers::format_currency( $inv->amount_paid ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Balance', 'make-school' ); ?></th><td style="text-align:right;"><?php echo esc_html( Make_School_Helpers::format_currency( $balance ) ); ?></td></tr>
			<?php if ( $inv->paid_at ) : ?>
				<tr><th><?php esc_html_e( 'Last payment', 'make-school' ); ?></th><td style="text-align:right;"><?php echo esc_html( Make_School_Helpers::format_datetime( $inv->paid_at ) ); ?> &middot; <?php echo esc_html( $inv->payment_method ); ?></td></tr>
			<?php endif; ?>
		</table>

		<p style="margin-top:18px;font-size:12px;color:#888;">
			<?php esc_html_e( 'This is a system-generated receipt and does not require a signature.', 'make-school' ); ?>
		</p>

		<div class="print">
			<button type="button" onclick="window.print();"><?php esc_html_e( 'Print receipt', 'make-school' ); ?></button>
		</div>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/* =====================================================================
	 * UTILITIES
	 * ================================================================== */

	/**
	 * Single-row invoice fetch.
	 *
	 * @param int $id Invoice ID.
	 * @return object|null
	 */
	private function get_invoice( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . make_school()->db->table( 'invoices' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	/**
	 * List enrolled students for the create-invoice dropdown.
	 *
	 * @return array<int,array{id:int,label:string}>
	 */
	private function student_options() {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore
			'SELECT user_id, full_name, roll_number FROM ' . make_school()->db->table( 'admissions' )
			. " WHERE status = 'approved' AND user_id > 0 ORDER BY full_name ASC LIMIT 1000"
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'    => (int) $r->user_id,
				'label' => sprintf( '%s (%s)', $r->full_name, $r->roll_number ),
			);
		}
		return $out;
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
