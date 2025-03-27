<?php
/**
 * IELTS Science Speaking Module
 *
 * This file contains the implementation of the Speaking Module for IELTS Science LMS.
 *
 * @package IeltsScienceLMS\Speaking
 */

namespace IeltsScienceLMS\Speaking;

/**
 * Class Ieltssci_Speaking_Module
 *
 * Handles the functionality for the IELTS Science Speaking Module.
 * Manages assets, routes, and data for the speaking module features.
 */
class Ieltssci_Speaking_Module {
	/**
	 * Constructor for the Ieltssci_Speaking_Module class.
	 *
	 * Initializes the speaking module by setting up hooks and loading dependencies.
	 */
	public function __construct() {
		new Ieltssci_Speaking_Settings();
		add_filter( 'ieltssci_lms_module_pages_data', array( $this, 'provide_module_pages_data' ) );
	}

	/**
	 * Provide module pages data for the Speaking module.
	 *
	 * Adds the Speaking module page information to the overall module pages data.
	 *
	 * @param array $module_data Existing module data.
	 * @return array Updated module data with speaking module information.
	 */
	public function provide_module_pages_data( $module_data ) {
		$module_data['speaking_module'] = array(
			'module_name'   => 'speaking_module',
			'section_title' => __( 'Speaking Module Pages', 'ielts-science-lms' ),
			'section_desc'  => __( 'Select the pages for the Speaking Module.', 'ielts-science-lms' ),
			'pages'         => array(
				'speaking_practice' => __( 'IELTS Science Speaking', 'ielts-science-lms' ),
				'speaking_result'   => __( 'Speaking Results', 'ielts-science-lms' ),
				'speaking_history'  => __( 'Speaking History', 'ielts-science-lms' ),
			),
		);

		return $module_data;
	}
}
