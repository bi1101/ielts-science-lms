<?php
/**
 * Plugin Name: IELTS Science LMS
 * Plugin URI: https://ieltsscience.fun/
 * Description: IELTS Science Learning Management System.
 * Version: 0.9.2
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

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Load plugin text domain for translations.
 */
function ieltssci_load_textdomain() {
	load_plugin_textdomain( 'ielts-science-lms', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'ieltssci_load_textdomain' );

// Initialize the core module.
$ieltssci_core_module = new \IeltsScienceLMS\Core\Ieltssci_Core_Module();

register_activation_hook( __FILE__, array( $ieltssci_core_module, 'activate' ) );
register_deactivation_hook( __FILE__, array( $ieltssci_core_module, 'deactivate' ) );
