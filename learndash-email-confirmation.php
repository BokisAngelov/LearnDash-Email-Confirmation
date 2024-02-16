<?php
/**
 * Plugin Name: LearnDash Email Confirmation
 * Plugin URI:  https://bokisangelov.com/learndash-email-confirmation
 * Description: Adds an email confirmation step to the LearnDash registration process, requiring users to confirm their email address before accessing content.
 * Version:     1.0.1
 * Author:      Bokis Angelov
 * Author URI:  https://bokisangelov.com
 * Text Domain: learndash-email-confirmation
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Exit if accessed directly
if (!defined('ABSPATH')) 
    exit; 

/**
 * Activation hook for basic setup checks and initialization.
 */
function ld_email_confirmation_activate() {
    include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

    if (!is_plugin_active('sfwd-lms/sfwd_lms.php')) {
        wp_die('This plugin requires LearnDash LMS to be installed and activated.');
    }
}
register_activation_hook(__FILE__, 'ld_email_confirmation_activate');

/**
 * Send confirmation email upon user registration.
 *
 * @param int $user_id User ID.
 */
function ld_email_confirmation_send_email($user_id) {
    $user_info = get_userdata($user_id);
    $user_email = sanitize_email($user_info->user_email);
    
    $key = sha1(time() . $user_email . wp_rand());
    update_user_meta($user_id, 'has_confirmed_email', 'no');
    update_user_meta($user_id, 'confirm_email_key', $key);
    
    // Correctly build the confirmation link
    $params = array(
        'action' => 'confirm_email',
        'key' => $key,
        'user' => $user_id
    );

    $confirmation_link = home_url('/') . '?' . http_build_query($params);

    // Site information for the email
    $site_name = get_bloginfo('name');
    $site_url = home_url();
    $from_name = 'The ' . $site_name . ' Team';
    $from_email = 'info@' . parse_url($site_url, PHP_URL_HOST);

    // Email subject and body
    $subject = sprintf('Please Confirm Your Email Address for %s', $site_name);
    $message = <<<EMAIL
                Hello,
                <br><br>
                Thank you for registering at {$site_name}. To complete your registration and access your account, please confirm your email address by clicking the link below:
                <br><br>
                {$confirmation_link}
                <br><br>
                If you did not request this, please ignore this email.
                <br><br>
                Best regards,
                
                {$from_name}
                EMAIL;

    // Email headers
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>'
    );

    // Send the email
    wp_mail($user_email, $subject, $message, $headers);
    
    set_transient('ld-registered-notice', true, 60);
    wp_redirect(add_query_arg('registered', 'true', home_url()));
    exit;
}
add_action('user_register', 'ld_email_confirmation_send_email');

/**
 * Handle email confirmation.
 */
function ld_handle_email_confirmation() {
    if (isset($_GET['action'], $_GET['user']) && $_GET['action'] === 'confirm_email') {
        $user_id = intval($_GET['user']);
        $key_received = sanitize_text_field($_GET['key']);
        $key_expected = get_user_meta($user_id, 'confirm_email_key', true);

        if ($key_received === $key_expected) {
            update_user_meta($user_id, 'has_confirmed_email', 'yes');
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);
            set_transient('ld-confirmed-notice', true, 60);
            wp_redirect(add_query_arg('confirmed', 'true', home_url()));
            exit;
        } else {
            set_transient('ld-confirmation-failed-notice', true, 60);
            wp_redirect(add_query_arg('confirmation', 'failed', home_url()));
            exit;
        }
    }
}
add_action('init', 'ld_handle_email_confirmation');


/**
 * Display notices for registration, confirmation success, and failure.
 */
function ld_display_notices() {
    if (get_transient('ld-registered-notice')) {
        ld_echo_notice('An email confirmation has been sent to your email.', 'success');
        delete_transient('ld-registered-notice');
    }

    if (get_transient('ld-confirmed-notice')) {
        ld_echo_notice('Your email has been confirmed! Welcome!', 'success');
        delete_transient('ld-confirmed-notice');
    }

    if (get_transient('ld-confirmation-failed-notice')) {
        ld_echo_notice('Email confirmation failed. Please try again or contact support if the problem persists.', 'danger');
        delete_transient('ld-confirmation-failed-notice');
    }
}
add_action('wp_footer', 'ld_display_notices');

/**
 * Helper function to echo a notice.
 *
 * @param string $message The message to display.
 * @param string $type    The type of notice.
 */
function ld_echo_notice($message, $type) {
    // Sanitize the type to avoid unexpected values
    $type_class = 'success' === $type ? 'uk-alert-success' : 'uk-alert-danger';

    // Ensure the message is properly escaped for output
    $sanitized_message = esc_html__($message, 'learndash-email-confirmation');

    // Echo the notice div with sanitized content
    echo "<div id=\"ld-notice-{$type}\" class=\"uk-alert {$type_class}\" uk-alert>
            <a class=\"uk-alert-close\" uk-close></a>
            <p>{$sanitized_message}</p>
          </div>";

    // Inline script to move the notice under the .uk-navbar or to the top of the body
    echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                var notice = document.getElementById('ld-notice-{$type}');
                var firstChild = document.body.firstChild;
                if (notice) {
                    document.body.insertBefore(notice, firstChild);
                }
            });
          </script>";
}

// Add a new column to the users list
function ld_add_user_confirmed_column($columns) {
    $columns['email_confirmed'] = __('Account Confirmed', 'learndash-email-confirmation');
    return $columns;
}
add_filter('manage_users_columns', 'ld_add_user_confirmed_column');

// Populate the new column with user confirmation status
function ld_show_user_confirmed_column_content($value, $column_name, $user_id) {
    if ('email_confirmed' === $column_name) {
        $is_confirmed = get_user_meta($user_id, 'has_confirmed_email', true);
        return $is_confirmed === 'yes' ? __('Yes', 'learndash-email-confirmation') : __('No', 'learndash-email-confirmation');
    }
    return $value;
}
add_filter('manage_users_custom_column', 'ld_show_user_confirmed_column_content', 10, 3);

// Add a manual confirmation action link
function ld_add_confirm_user_link($actions, $user_object) {
    if (get_user_meta($user_object->ID, 'has_confirmed_email', true) !== 'yes') {
        $actions['confirm_user'] = sprintf('<a href="%s">%s</a>', wp_nonce_url(add_query_arg(['action' => 'ld_confirm_user', 'user' => $user_object->ID], admin_url('users.php')), 'ld_confirm_user_' . $user_object->ID), __('Confirm Account', 'learndash-email-confirmation'));
    }
    return $actions;
}
add_filter('user_row_actions', 'ld_add_confirm_user_link', 10, 2);

// Process manual user confirmation
function ld_process_manual_user_confirmation() {
    if (isset($_GET['action'], $_GET['user']) && $_GET['action'] === 'ld_confirm_user') {
        $user_id = absint($_GET['user']);
        if (!current_user_can('edit_user', $user_id) || !wp_verify_nonce($_GET['_wpnonce'], 'ld_confirm_user_' . $user_id)) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'learndash-email-confirmation'));
        }

        update_user_meta($user_id, 'has_confirmed_email', 'yes');
        wp_redirect(remove_query_arg(['action', 'user', '_wpnonce']));
        exit;
    }
}
add_action('admin_init', 'ld_process_manual_user_confirmation');
