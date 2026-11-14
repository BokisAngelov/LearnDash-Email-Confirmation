<?php
/**
 * Plugin Name: LearnDash Email Confirmation
 * Plugin URI:  https://bokisangelov.com/learndash-email-confirmation
 * Description: Adds an email confirmation step to the LearnDash registration process, requiring users to confirm their email address before accessing content.
 * Version:     1.1.0
 * Author:      Bokis Angelov
 * Author URI:  https://bokisangelov.com
 * Text Domain: learndash-email-confirmation
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Hours a confirmation link stays valid.
if ( ! defined( 'LD_EMAIL_CONFIRMATION_TTL_HOURS' ) ) {
	define( 'LD_EMAIL_CONFIRMATION_TTL_HOURS', 48 );
}

// Load admin files in backend area.
if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'admin/admin-functions.php';
}

/**
 * Activation hook for basic setup checks and initialization.
 */
function ld_email_confirmation_activate() {
	include_once ABSPATH . 'wp-admin/includes/plugin.php';

	if ( ! is_plugin_active( 'sfwd-lms/sfwd_lms.php' ) ) {
		wp_die( esc_html__( 'This plugin requires LearnDash LMS to be installed and activated.', 'learndash-email-confirmation' ) );
	}
}
register_activation_hook( __FILE__, 'ld_email_confirmation_activate' );

/**
 * Is the current request a normal front-end registration (not admin, AJAX, REST, cron or CLI)?
 *
 * @return bool
 */
function ld_email_confirmation_is_frontend_registration() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return false;
	}
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return false;
	}
	return true;
}

/**
 * Send confirmation email upon user registration.
 *
 * Users created by an administrator, over REST, by WP-CLI or by an import are
 * trusted and marked as confirmed without an email.
 *
 * @param int $user_id User ID.
 */
function ld_email_confirmation_send_email( $user_id ) {
	if ( ! ld_email_confirmation_is_frontend_registration() ) {
		update_user_meta( $user_id, 'has_confirmed_email', 'yes' );
		return;
	}

	$user_info = get_userdata( $user_id );
	if ( ! $user_info ) {
		return;
	}
	$user_email = sanitize_email( $user_info->user_email );

	// Cryptographically random token. Only a hash of it is stored, so a database
	// leak does not expose usable confirmation links.
	$key = bin2hex( random_bytes( 32 ) );
	update_user_meta( $user_id, 'has_confirmed_email', 'no' );
	update_user_meta( $user_id, 'confirm_email_key_hash', hash( 'sha256', $key ) );
	update_user_meta( $user_id, 'confirm_email_expires', time() + ( (int) LD_EMAIL_CONFIRMATION_TTL_HOURS * HOUR_IN_SECONDS ) );
	delete_user_meta( $user_id, 'confirm_email_key' ); // Legacy plaintext key from v1.0.x.

	$confirmation_link = add_query_arg(
		array(
			'action' => 'confirm_email',
			'key'    => $key,
			'user'   => $user_id,
		),
		home_url( '/' )
	);

	// Site information for the email.
	$site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$site_url   = home_url();
	$from_name  = 'The ' . $site_name . ' Team';
	$from_email = 'wordpress@' . wp_parse_url( $site_url, PHP_URL_HOST );

	$subject = sprintf( 'Confirm Your Email Address for %s', $site_name );
	$message = sprintf(
		'Hello %1$s,<br><br>Thank you for registering at %2$s. To complete your registration and access your account, please confirm your email address by clicking the link below:<br><br><a href="%3$s" target="_blank" rel="noopener noreferrer">Confirm Email</a><br><br>This link expires in %4$d hours. If you did not request this, please ignore this email.<br><br>Best regards,<br>%5$s',
		esc_html( $user_info->user_login ),
		esc_html( $site_name ),
		esc_url( $confirmation_link ),
		(int) LD_EMAIL_CONFIRMATION_TTL_HOURS,
		esc_html( $from_name )
	);

	// Strip CR/LF so a hostile site name can never inject extra headers.
	$safe_from_name = str_replace( array( "\r", "\n" ), '', sanitize_text_field( $from_name ) );
	$headers        = array(
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . $safe_from_name . ' <' . sanitize_email( $from_email ) . '>',
		'Reply-To: ' . sanitize_email( $from_email ),
	);

	wp_mail( $user_email, $subject, $message, $headers );

	// Keep the password the visitor typed on the registration form (LearnDash fix).
	// Nonce verification is performed by the registration form handler that fired this hook.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( isset( $_POST['password'] ) && is_string( $_POST['password'] ) && '' !== $_POST['password'] ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		wp_set_password( wp_unslash( $_POST['password'] ), $user_id ); // Passwords must not be sanitized: that would alter them.
	}

	wp_safe_redirect( add_query_arg( 'ld_notice', 'registered', home_url( '/' ) ) );
	exit;
}
add_action( 'user_register', 'ld_email_confirmation_send_email' );

/**
 * Handle email confirmation.
 */
function ld_handle_email_confirmation() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Email links cannot carry a nonce; the secret token is the credential.
	if ( ! isset( $_GET['action'] ) || 'confirm_email' !== $_GET['action'] ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$user_id      = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
	$key_received = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$fail = static function () {
		wp_safe_redirect( add_query_arg( 'ld_notice', 'failed', home_url( '/' ) ) );
		exit;
	};

	// Reject anything that is not a well-formed token before touching the database.
	// An empty key must never match a user that has no stored key.
	if ( ! $user_id || ! preg_match( '/^[a-f0-9]{64}$/', $key_received ) ) {
		$fail();
	}

	$stored_hash = get_user_meta( $user_id, 'confirm_email_key_hash', true );
	$expires     = (int) get_user_meta( $user_id, 'confirm_email_expires', true );

	if (
		! is_string( $stored_hash ) || '' === $stored_hash
		|| ! hash_equals( $stored_hash, hash( 'sha256', $key_received ) )
		|| time() > $expires
	) {
		$fail();
	}

	// Single use: burn the token before logging the user in.
	delete_user_meta( $user_id, 'confirm_email_key_hash' );
	delete_user_meta( $user_id, 'confirm_email_expires' );
	update_user_meta( $user_id, 'has_confirmed_email', 'yes' );

	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id );
	wp_safe_redirect( add_query_arg( 'ld_notice', 'confirmed', home_url( '/' ) ) );
	exit;
}
add_action( 'init', 'ld_handle_email_confirmation' );

/**
 * Block login until the email address is confirmed.
 *
 * Only accounts explicitly flagged "no" are blocked, so users that existed before
 * this plugin was installed are not locked out.
 *
 * @param WP_User|WP_Error $user WP_User when authentication succeeded.
 * @return WP_User|WP_Error
 */
function ld_email_confirmation_block_unconfirmed( $user ) {
	if ( $user instanceof WP_User && 'no' === get_user_meta( $user->ID, 'has_confirmed_email', true ) ) {
		return new WP_Error(
			'email_not_confirmed',
			esc_html__( 'Please confirm your email address using the link we sent you before logging in.', 'learndash-email-confirmation' )
		);
	}
	return $user;
}
add_filter( 'wp_authenticate_user', 'ld_email_confirmation_block_unconfirmed' );

/**
 * Display notices for registration, confirmation success, and failure.
 *
 * The notice is chosen from a fixed whitelist via a query argument, so it is
 * never shared between visitors (unlike the former global transients).
 */
function ld_display_notices() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, whitelisted value.
	$notice = isset( $_GET['ld_notice'] ) ? sanitize_key( wp_unslash( $_GET['ld_notice'] ) ) : '';

	$notices = array(
		'registered' => array( 'An email confirmation has been sent to your email.', 'success' ),
		'confirmed'  => array( 'Your email has been confirmed! Welcome!', 'success' ),
		'failed'     => array( 'Email confirmation failed. Please try again or contact support if the problem persists.', 'danger' ),
	);

	if ( isset( $notices[ $notice ] ) ) {
		ld_echo_notice( $notices[ $notice ][0], $notices[ $notice ][1] );
	}
}
add_action( 'wp_footer', 'ld_display_notices' );

/**
 * Helper function to echo a notice.
 *
 * @param string $message The message to display.
 * @param string $type    The type of notice ('success' or 'danger').
 */
function ld_echo_notice( $message, $type ) {
	$type       = 'success' === $type ? 'success' : 'danger';
	$type_class = 'success' === $type ? 'uk-alert-success' : 'uk-alert-danger';

	printf(
		'<div id="ld-notice-%1$s" class="uk-alert %2$s" uk-alert><a class="uk-alert-close" uk-close></a><p>%3$s</p></div>',
		esc_attr( $type ),
		esc_attr( $type_class ),
		esc_html( $message )
	);

	// Move the notice to the top of the body. The id is a fixed, escaped literal.
	printf(
		'<script>document.addEventListener("DOMContentLoaded",function(){var n=document.getElementById(%s);if(n){document.body.insertBefore(n,document.body.firstChild);}});</script>',
		wp_json_encode( 'ld-notice-' . $type )
	);
}
