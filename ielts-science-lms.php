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

require_once __DIR__ . '/vendor/autoload.php';

// Initialize the core module
$core_module = new \IeltsScienceLMS\Core\Ieltssci_Core_Module();

register_activation_hook( __FILE__, [ $core_module, 'activate' ] );
register_deactivation_hook( __FILE__, [ $core_module, 'deactivate' ] );
