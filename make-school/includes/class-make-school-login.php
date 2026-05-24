<?php
/**
 * Login portal, role-based redirection and wp-admin security wall.
 *
 * Provides:
 *   - [make_school_login_form] — custom mobile-responsive login UI.
 *   - login_redirect filter routing each role to its proper dashboard.
 *   - admin_init guard that blocks make_school_teacher / _student / _parent
 *     from ever loading /wp-admin pages (admin-ajax.php is allowed).
 *   - Logout redirection back to the configured login page.
 *
 * @package MakeSchool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Make_School_Login
 */
class Make_School_Login {

	const NONCE_ACTION = 'make_school_login';
	const NONCE_FIELD  = 'make_school_login_nonce';

	/**
	 * Constructor — wires hooks.
	 */
	public function __construct() {
		// Shortcode.
		add_shortcode( 'make_school_login_form', array( $this, 'render_login_form' ) );

		// Authentication intercept (handles the POST submit on the same page).
		add_action( 'init', array( $this, 'maybe_handle_submit' ) );

		// Redirection engine.
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 10, 3 );
		add_action( 'wp_login', array( $this, 'on_wp_login' ), 10, 2 );

		// Security wall — block restricted roles from /wp-admin.
		add_action( 'admin_init', array( $this, 'block_wp_admin' ) );

		// Logout redirect.
		add_action( 'wp_logout', array( $this, 'on_logout' ) );
		add_filter( 'logout_redirect', array( $this, 'logout_redirect' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Shortcode renderer.
	 * ------------------------------------------------------------------- */

	/**
	 * Render the [make_school_login_form] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML.
	 */
	public function render_login_form( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'redirect' => '',
				'title'    => __( 'Sign in to your account', 'make-school' ),
			),
			(array) $atts,
			'make_school_login_form'
		);

		// Already logged in — bounce to the relevant dashboard.
		if ( is_user_logged_in() ) {
			return $this->render_logged_in_panel();
		}

		$message = $this->pop_login_error();

		ob_start();
		?>
		<div class="make-school-auth-wrap">
			<div class="make-school-auth-card">
				<div class="make-school-auth-brand">
					<h2><?php echo esc_html( Make_School_Helpers::setting( 'school_name', get_bloginfo( 'name' ) ) ); ?></h2>
					<p><?php echo esc_html( $atts['title'] ); ?></p>
				</div>

				<?php if ( $message ) : ?>
					<div class="make-school-alert make-school-alert-error" role="alert">
						<?php echo esc_html( $message ); ?>
					</div>
				<?php endif; ?>

				<form method="post" class="make-school-auth-form" novalidate>
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="hidden" name="make_school_action" value="login" />
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $atts['redirect'] ); ?>" />

					<label class="make-school-field">
						<span><?php esc_html_e( 'Username or Email', 'make-school' ); ?></span>
						<input type="text" name="log" autocomplete="username" required />
					</label>

					<label class="make-school-field">
						<span><?php esc_html_e( 'Password', 'make-school' ); ?></span>
						<input type="password" name="pwd" autocomplete="current-password" required />
					</label>

					<label class="make-school-checkbox">
						<input type="checkbox" name="rememberme" value="forever" />
						<span><?php esc_html_e( 'Remember me', 'make-school' ); ?></span>
					</label>

					<button type="submit" class="make-school-btn make-school-btn-primary">
						<?php esc_html_e( 'Sign in', 'make-school' ); ?>
					</button>

					<div class="make-school-auth-meta">
						<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">
							<?php esc_html_e( 'Forgot password?', 'make-school' ); ?>
						</a>
					</div>
				</form>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a friendly "you are signed in" panel with role-aware quick links.
	 *
	 * @return string
	 */
	private function render_logged_in_panel() {
		$user      = wp_get_current_user();
		$dashboard = $this->dashboard_url_for_user( $user );
		$logout    = wp_logout_url( Make_School_Helpers::login_url() );

		ob_start();
		?>
		<div class="make-school-auth-wrap">
			<div class="make-school-auth-card">
				<div class="make-school-auth-brand">
					<h2><?php echo esc_html( $user->display_name ); ?></h2>
					<p><?php esc_html_e( 'You are signed in.', 'make-school' ); ?></p>
				</div>
				<div class="make-school-auth-actions">
					<a href="<?php echo esc_url( $dashboard ); ?>" class="make-school-btn make-school-btn-primary">
						<?php esc_html_e( 'Go to my dashboard', 'make-school' ); ?>
					</a>
					<a href="<?php echo esc_url( $logout ); ?>" class="make-school-btn make-school-btn-ghost">
						<?php esc_html_e( 'Log out', 'make-school' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Form submission handler (custom on-page POST flow).
	 * ------------------------------------------------------------------- */

	/**
	 * Handle the [make_school_login_form] POST submission.
	 *
	 * Runs on `init` so we can perform `wp_safe_redirect()` before any
	 * output is emitted. On failure we stash a message in a transient
	 * keyed by the visitor's session cookie hash.
	 *
	 * @return void
	 */
	public function maybe_handle_submit() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			return;
		}
		if ( ! isset( $_POST['make_school_action'] ) || 'login' !== $_POST['make_school_action'] ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ||
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			$this->push_login_error( __( 'Security check failed. Please try again.', 'make-school' ) );
			$this->safe_back();
			return;
		}

		$creds = array(
			'user_login'    => isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '',
			'user_password' => isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '',
			'remember'      => ! empty( $_POST['rememberme'] ),
		);

		if ( '' === $creds['user_login'] || '' === $creds['user_password'] ) {
			$this->push_login_error( __( 'Please enter both username/email and password.', 'make-school' ) );
			$this->safe_back();
			return;
		}

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			$this->push_login_error( wp_strip_all_tags( $user->get_error_message() ) );
			$this->safe_back();
			return;
		}

		// Determine redirect target.
		$override = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
		$target   = $override ? $override : $this->dashboard_url_for_user( $user );

		wp_safe_redirect( $target );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Redirection engine.
	 * ------------------------------------------------------------------- */

	/**
	 * Standard wp-login.php redirect filter.
	 *
	 * @param string           $redirect_to Requested redirect.
	 * @param string           $request     Original request.
	 * @param WP_User|WP_Error $user        Authenticated user or error.
	 * @return string
	 */
	public function login_redirect( $redirect_to, $request, $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $redirect_to;
		}
		return $this->dashboard_url_for_user( $user );
	}

	/**
	 * Fired immediately after a successful wp_login event. Useful for
	 * forcing a redirect when the login happened outside of our shortcode
	 * (e.g. a custom theme login form).
	 *
	 * @param string  $user_login Login.
	 * @param WP_User $user       The user.
	 * @return void
	 */
	public function on_wp_login( $user_login, $user ) {
		unset( $user_login );
		// Intentionally empty: login_redirect already routes. This hook is
		// reserved for future audit logging and stays no-op for now.
		if ( $user instanceof WP_User ) {
			return;
		}
	}

	/**
	 * Resolve the correct dashboard URL for a user based on role.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	public function dashboard_url_for_user( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return home_url( '/' );
		}

		// Real WP admin or School Admin → wp-admin.
		if ( Make_School_Helpers::user_has_role( $user, array( 'administrator', 'make_school_admin' ) ) ) {
			return admin_url( 'admin.php?page=make-school' );
		}

		if ( Make_School_Helpers::user_has_role( $user, array( 'make_school_teacher' ) ) ) {
			return Make_School_Helpers::teacher_dashboard_url();
		}

		if ( Make_School_Helpers::user_has_role( $user, array( 'make_school_student', 'make_school_parent' ) ) ) {
			return Make_School_Helpers::student_dashboard_url();
		}

		return home_url( '/' );
	}

	/* ---------------------------------------------------------------------
	 * Security wall.
	 * ------------------------------------------------------------------- */

	/**
	 * Block teachers, students and parents from /wp-admin.
	 *
	 * AJAX requests must continue to work — admin-ajax.php is the channel
	 * the teacher attendance grid and other front-end widgets use.
	 *
	 * @return void
	 */
	public function block_wp_admin() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			return;
		}

		$user = wp_get_current_user();
		if ( Make_School_Helpers::user_has_role(
			$user,
			array( 'make_school_teacher', 'make_school_student', 'make_school_parent' )
		) ) {
			$target = $this->dashboard_url_for_user( $user );
			wp_safe_redirect( $target );
			exit;
		}
	}

	/* ---------------------------------------------------------------------
	 * Logout.
	 * ------------------------------------------------------------------- */

	/**
	 * Force the post-logout destination to the configured login page.
	 *
	 * @param string  $redirect_to           Requested redirect.
	 * @param string  $requested_redirect_to Requested redirect.
	 * @param WP_User $user                  User logging out.
	 * @return string
	 */
	public function logout_redirect( $redirect_to, $requested_redirect_to, $user ) {
		unset( $redirect_to, $requested_redirect_to, $user );
		return Make_School_Helpers::login_url();
	}

	/**
	 * Fires on wp_logout — currently a hook stub for future audit logging.
	 *
	 * @return void
	 */
	public function on_logout() {
		// Reserved for future audit/event logging.
	}

	/* ---------------------------------------------------------------------
	 * Internal helpers.
	 * ------------------------------------------------------------------- */

	/**
	 * Per-visitor key for storing transient error messages.
	 *
	 * @return string
	 */
	private function flash_key() {
		$basis = '';
		if ( ! empty( $_COOKIE ) && is_array( $_COOKIE ) ) {
			$cookie_str = wp_json_encode( array_keys( $_COOKIE ) );
			$basis      = is_string( $cookie_str ) ? $cookie_str : '';
		}
		$basis .= isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$basis .= isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return 'make_school_flash_' . md5( $basis );
	}

	/**
	 * Push a one-shot login error message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function push_login_error( $message ) {
		set_transient( $this->flash_key(), (string) $message, 60 );
	}

	/**
	 * Pop the one-shot login error message.
	 *
	 * @return string
	 */
	private function pop_login_error() {
		$key = $this->flash_key();
		$msg = get_transient( $key );
		if ( false === $msg ) {
			return '';
		}
		delete_transient( $key );
		return (string) $msg;
	}

	/**
	 * Redirect back to the originating page.
	 *
	 * @return void
	 */
	private function safe_back() {
		$ref = '';
		if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$ref = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}
		if ( ! $ref ) {
			$ref = Make_School_Helpers::login_url();
		}
		wp_safe_redirect( $ref );
		exit;
	}
}
