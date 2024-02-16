<?php

// Exit if accessed directly
if (!defined('ABSPATH')) 
    exit; 

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