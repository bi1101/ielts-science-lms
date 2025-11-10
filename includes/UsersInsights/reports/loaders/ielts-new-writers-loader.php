<?php
/**
 * New Essay Writers Loader
 *
 * @package IELTS_Science_LMS
 * @subpackage UsersInsights
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loader for new writers period report.
 */
class Ieltssci_Ielts_New_Writers_Loader extends USIN_Period_Report_Loader {

	/**
	 * Load report data.
	 *
	 * @return array Report data.
	 */
	protected function load_data() {
		global $wpdb;
		$essays_table = $wpdb->prefix . 'ieltssci_essays';

		$date_select = USIN_Query_Helper::get_gmt_offset_date_select( 'first_essay' );

		$query = $wpdb->prepare(
			"SELECT COUNT(*) AS {$this->total_col}, {$date_select} AS {$this->label_col}
			FROM (
				SELECT MIN(created_at) as first_essay
				FROM {$essays_table}
				GROUP BY created_by
			) as first_essays
			WHERE {$date_select} >= %s AND {$date_select} <= %s
			GROUP BY {$this->get_group_by()}",
			$this->get_period_start(),
			$this->get_period_end()
		);

		return $wpdb->get_results( $query );
	}
}
