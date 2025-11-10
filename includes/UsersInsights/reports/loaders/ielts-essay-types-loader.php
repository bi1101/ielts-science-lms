<?php
/**
 * Essay Types Loader
 *
 * @package IELTS_Science_LMS
 * @subpackage UsersInsights
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loader for essay types standard report.
 */
class Ieltssci_Ielts_Essay_Types_Loader extends USIN_Standard_Report_Loader {

	/**
	 * Load report data.
	 *
	 * @return array Report data.
	 */
	protected function load_data() {
		global $wpdb;
		$essays_table = $wpdb->prefix . 'ieltssci_essays';

		$results = $wpdb->get_results(
			"SELECT essay_type as {$this->label_col}, COUNT(*) as {$this->total_col}
			FROM {$essays_table}
			GROUP BY essay_type
			ORDER BY {$this->total_col} DESC"
		);

		// Format labels.
		foreach ( $results as $result ) {
			$result->{$this->label_col} = ucfirst( str_replace( array( '-', '_' ), ' ', $result->{$this->label_col} ) );
		}

		return $results;
	}
}
