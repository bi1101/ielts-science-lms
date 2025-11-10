<?php
/**
 * Plugin Module Initializer for IELTS Science LMS
 *
 * This file is required by Users Insights to register the IELTS Science LMS module.
 *
 * @package IELTS_Science_LMS
 * @subpackage UsersInsights
 */

// Check if IELTS Science LMS plugin is active.
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'IeltsScienceLMS\Core\Ieltssci_Core_Module' ) ) {
	return;
}

// The module is initialized by the IELTS Science LMS plugin via the init_users_insights_module method.
// in the Ieltssci_Core_Module class.
// This file serves as a placeholder for Users Insights plugin module discovery.
