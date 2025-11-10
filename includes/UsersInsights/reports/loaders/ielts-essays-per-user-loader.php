<?php
/**
 * Essays Per User Loader
 *
 * @package IELTS_Science_LMS
 * @subpackage UsersInsights
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loader for essays per user distribution report.
 */
class Ieltssci_Ielts_Essays_Per_User_Loader extends USIN_Standard_Report_Loader {

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
				CASE
					WHEN essay_count = 1 THEN '1 essay'
					WHEN essay_count <= 5 THEN '2-5 essays'
					WHEN essay_count <= 10 THEN '6-10 essays'
					WHEN essay_count <= 20 THEN '11-20 essays'
					ELSE '20+ essays'
				END as {$this->label_col},
				COUNT(*) as {$this->total_col}
			FROM (
				SELECT created_by, COUNT(*) as essay_count
				FROM {$essays_table}
				GROUP BY created_by
			) as counts
			GROUP BY {$this->label_col}
			ORDER BY
				CASE {$this->label_col}
					WHEN '1 essay' THEN 1
					WHEN '2-5 essays' THEN 2
					WHEN '6-10 essays' THEN 3
					WHEN '11-20 essays' THEN 4
					ELSE 5
				END"
		);

		return $results;
	}
}
