<?php
/**
 * Word Count Distribution Loader
 *
 * @package IELTS_Science_LMS
 * @subpackage UsersInsights
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loader for word count distribution standard report.
 */
class Ieltssci_Ielts_Word_Count_Distribution_Loader extends USIN_Standard_Report_Loader {

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
					WHEN avg_words < 150 THEN '< 150 words'
					WHEN avg_words < 200 THEN '150-200 words'
					WHEN avg_words < 250 THEN '200-250 words'
					WHEN avg_words < 300 THEN '250-300 words'
					ELSE '300+ words'
				END as {$this->label_col},
				COUNT(*) as {$this->total_col}
			FROM (
				SELECT
					created_by,
					AVG(LENGTH(essay_content) - LENGTH(REPLACE(essay_content, ' ', '')) + 1) as avg_words
				FROM {$essays_table}
				GROUP BY created_by
			) as word_counts
			GROUP BY {$this->label_col}
			ORDER BY
				CASE {$this->label_col}
					WHEN '< 150 words' THEN 1
					WHEN '150-200 words' THEN 2
					WHEN '200-250 words' THEN 3
					WHEN '250-300 words' THEN 4
					ELSE 5
				END"
		);

		return $results;
	}
}
