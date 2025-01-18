<?php
/**
 * Plugin Name: IELTS Science LMS
 * Plugin URI: https://ieltsscience.fun/
 * Description: IELTS Science Learning Management System.
 * Version: 0.0.1
 * Author: IELTS Science
 * Author URI: https://ieltsscience.fun/
 * Text Domain: ielts-science-lms
 * Domain Path: /languages/
 * Requires at least: 6.0
 * Requires PHP: 7.0
 * License: Proprietary
 * License URI: https://ieltsscience.fun/license
 *
 * @package IELTS_Science_LMS
 **/

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Checks the current WordPress version and deactivates the plugin if the version is below the required version.
 *
 * This function compares the current WordPress version with the required version (6.0).
 * If the current version is lower than the required version, the plugin is deactivated
 * and an admin notice is added to inform the user.
 *
 * @global string $wp_version The current WordPress version.
 */
function ieltssci_check_wp_version() {
	global $wp_version;
	$required_wp_version = '6.0';

	if ( version_compare( $wp_version, $required_wp_version, '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		add_action( 'admin_notices', 'ielts_science_lms_wp_version_notice' );
	}
}
add_action( 'admin_init', 'ielts_science_lms_check_wp_version' );

/**
 * Displays an admin notice if the WordPress version is below the required version.
 *
 * This function outputs an error message in the WordPress admin area
 * indicating that the IELTS Science LMS plugin requires WordPress version 6.0 or higher.
 * The message also informs the user that the plugin has been deactivated.
 *
 * @return void
 */
function ieltssci_wp_version_notice() {
	echo '<div class="error"><p><strong>' . esc_html__( 'IELTS Science LMS', 'ielts-science-lms' ) . '</strong> ' . esc_html__( 'requires WordPress version 6.0 or higher. The plugin has been deactivated.', 'ielts-science-lms' ) . '</p></div>';
}
