<?php
/**
 * Top Writers Loader
 *
 * @package IELTS_Science_LMS
 * @subpackage UsersInsights
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loader for top writers standard report.
 */
class Ieltssci_Ielts_Top_Writers_Loader extends USIN_Standard_Report_Loader {

	/**
	 * Load report data.
	 *
	 * @return array Report data.
	 */
	protected function load_data() {
		global $wpdb;
		$essays_table = $wpdb->prefix . 'ieltssci_essays';

		$results = $wpdb->get_results(
			"SELECT
				u.display_name as {$this->label_col},
				COUNT(e.id) as {$this->total_col}
			FROM {$essays_table} e
			INNER JOIN {$wpdb->users} u ON e.created_by = u.ID
			GROUP BY e.created_by, u.display_name
			ORDER BY {$this->total_col} DESC
			LIMIT 20"
		);

		return $results;
	}
}
