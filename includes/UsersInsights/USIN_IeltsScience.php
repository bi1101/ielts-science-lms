<?php
/**
 * IELTS Science LMS Module for Users Insights
 *
 * Integrates essay submission data with Users Insights plugin.
 *
 * @package IELTS_Science_LMS
 * @subpackage UsersInsights
 */

namespace IeltsScienceLMS\UsersInsights;

use USIN_Plugin_Module;
use USIN_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main module class for Users Insights integration.
 */
class USIN_IeltsScience extends USIN_Plugin_Module {

	/**
	 * Query handler instance.
	 *
	 * @var USIN_IeltsScience_Query
	 */
	public $ielts_query = null;

	/**
	 * Module name identifier.
	 *
	 * @var string
	 */
	protected $module_name = 'ielts-science-lms';

	/**
	 * Plugin path for activation check.
	 *
	 * @var string
	 */
	protected $plugin_path = 'ielts-science-lms/ielts-science-lms.php';

	/**
	 * Initialize the module.
	 *
	 * @return void
	 */
	public function init() {
		$this->ielts_query = new USIN_IeltsScience_Query();
		$this->ielts_query->init();

		$ielts_user_activity = new USIN_IeltsScience_User_Activity();
		$ielts_user_activity->init();
	}

	/**
	 * Register the module with Users Insights.
	 *
	 * @return array Module configuration.
	 */
	public function register_module() {
		return array(
			'id'               => $this->module_name,
			'name'             => __( 'IELTS Science LMS', 'ielts-science-lms' ),
			'desc'             => __( 'Retrieves and displays essay submission data from the IELTS Science LMS plugin.', 'ielts-science-lms' ),
			'allow_deactivate' => true,
			'active'           => false,
		);
	}

	/**
	 * Initialize reports.
	 *
	 * @return void
	 */
	protected function init_reports() {
		new USIN_IeltsScience_Reports();
	}

	/**
	 * Register custom fields.
	 *
	 * @return array Field definitions.
	 */
	public function register_fields() {
		$fields = array(
			// Total essays submitted.
			array(
				'name'      => __( 'Essays Submitted', 'ielts-science-lms' ),
				'id'        => 'ielts_essays_submitted',
				'order'     => 'DESC',
				'show'      => true,
				'fieldType' => $this->module_name,
				'filter'    => array(
					'type'          => 'number',
					'disallow_null' => true,
				),
				'module'    => $this->module_name,
			),
			// Last essay submission date.
			array(
				'name'      => __( 'Last Essay Date', 'ielts-science-lms' ),
				'id'        => 'ielts_last_essay_date',
				'order'     => 'DESC',
				'show'      => true,
				'fieldType' => $this->module_name,
				'filter'    => array(
					'type' => 'date',
				),
				'module'    => $this->module_name,
			),
			// First essay submission date.
			array(
				'name'      => __( 'First Essay Date', 'ielts-science-lms' ),
				'id'        => 'ielts_first_essay_date',
				'order'     => 'DESC',
				'show'      => false,
				'fieldType' => $this->module_name,
				'filter'    => array(
					'type' => 'date',
				),
				'module'    => $this->module_name,
			),
			// Combined filter for essay submissions.
			array(
				'name'        => __( 'Submitted Essay', 'ielts-science-lms' ),
				'id'          => 'ielts_submitted_essay',
				'order'       => 'DESC',
				'show'        => false,
				'hideOnTable' => true,
				'fieldType'   => $this->module_name,
				'filter'      => array(
					'type'          => 'combined',
					'items'         => array(
						array(
							'name' => __( 'Date', 'ielts-science-lms' ),
							'id'   => 'date',
							'type' => 'date',
						),
						array(
							'name'    => __( 'Essay Type', 'ielts-science-lms' ),
							'id'      => 'essay_type',
							'type'    => 'select',
							'options' => $this->get_essay_type_options(),
						),
					),
					'disallow_null' => true,
				),
				'module'      => $this->module_name,
			),
			// Essays by type - Task 1.
			array(
				'name'      => __( 'Task 1 Essays', 'ielts-science-lms' ),
				'id'        => 'ielts_task1_count',
				'order'     => 'DESC',
				'show'      => false,
				'fieldType' => $this->module_name,
				'filter'    => array(
					'type'          => 'number',
					'disallow_null' => true,
				),
				'module'    => $this->module_name,
			),
			// Essays by type - Task 2.
			array(
				'name'      => __( 'Task 2 Essays', 'ielts-science-lms' ),
				'id'        => 'ielts_task2_count',
				'order'     => 'DESC',
				'show'      => false,
				'fieldType' => $this->module_name,
				'filter'    => array(
					'type'          => 'number',
					'disallow_null' => true,
				),
				'module'    => $this->module_name,
			),
			// Average word count.
			array(
				'name'      => __( 'Avg Essay Words', 'ielts-science-lms' ),
				'id'        => 'ielts_avg_word_count',
				'order'     => 'DESC',
				'show'      => false,
				'fieldType' => $this->module_name,
				'filter'    => array(
					'type'          => 'number',
					'disallow_null' => true,
				),
				'module'    => $this->module_name,
			),
		);

		return $fields;
	}

	/**
	 * Get essay type options for filters.
	 *
	 * @return array Essay type options.
	 */
	protected function get_essay_type_options() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ieltssci_essays';

		// Check if table exists.
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" );

		if ( ! $table_exists ) {
			return array();
		}

		$types = $wpdb->get_col( "SELECT DISTINCT essay_type FROM $table_name ORDER BY essay_type" );

		$options = array();
		if ( ! empty( $types ) ) {
			foreach ( $types as $type ) {
				$options[] = array(
					'key'  => $type,
					'name' => ucfirst( str_replace( array( '-', '_' ), ' ', $type ) ),
				);
			}
		}

		return $options;
	}
}
