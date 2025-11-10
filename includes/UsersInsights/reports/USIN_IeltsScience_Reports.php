<?php
/**
 * Reports for IELTS Science LMS
 *
 * Analytics and reporting for essay submissions.
 *
 * @package IELTS_Science_LMS
 * @subpackage UsersInsights
 */

namespace IeltsScienceLMS\UsersInsights;

use USIN_Module_Reports;
use USIN_Period_Report;
use USIN_Standard_Report;
use USIN_Report;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports class for IELTS Science.
 */
class USIN_IeltsScience_Reports extends USIN_Module_Reports {

	/**
	 * Report group identifier.
	 *
	 * @var string
	 */
	protected $group = 'ielts-science';

	/**
	 * Get report group configuration.
	 *
	 * @return array Report group settings.
	 */
	public function get_group() {
		return array(
			array(
				'id'   => $this->group,
				'name' => 'IELTS Science',
				'info' => __( 'Essay submission analytics from IELTS Science LMS', 'ielts-science-lms' ),
			),
		);
	}

	/**
	 * Get available reports.
	 *
	 * @return array Report definitions.
	 */
	public function get_reports() {
		return array(
			// Essay submissions over time.
			new USIN_Period_Report(
				'ielts_essay_submissions',
				__( 'Essay Submissions', 'ielts-science-lms' ),
				array(
					'group'        => $this->group,
					'info'         => __( 'Number of essays submitted per period', 'ielts-science-lms' ),
					'loader_class' => 'Ieltssci_Ielts_Essay_Submissions_Loader',
				)
			),

			// New essay writers over time.
			new USIN_Period_Report(
				'ielts_new_writers',
				__( 'New Essay Writers', 'ielts-science-lms' ),
				array(
					'group'        => $this->group,
					'info'         => __( 'Users who submitted their first essay in each period', 'ielts-science-lms' ),
					'loader_class' => 'Ieltssci_Ielts_New_Writers_Loader',
				)
			),

			// Essay types breakdown.
			new USIN_Standard_Report(
				'ielts_essay_types',
				__( 'Essay Types', 'ielts-science-lms' ),
				array(
					'group'        => $this->group,
					'type'         => USIN_Report::PIE,
					'info'         => __( 'Distribution of essay types submitted', 'ielts-science-lms' ),
					'loader_class' => 'Ieltssci_Ielts_Essay_Types_Loader',
				)
			),

			// Average word count distribution.
			new USIN_Standard_Report(
				'ielts_word_count_distribution',
				__( 'Word Count Distribution', 'ielts-science-lms' ),
				array(
					'group'        => $this->group,
					'type'         => USIN_Report::BAR,
					'visible'      => false,
					'info'         => __( 'Distribution of average word counts per user', 'ielts-science-lms' ),
					'loader_class' => 'Ieltssci_Ielts_Word_Count_Distribution_Loader',
				)
			),

			// Essays per user.
			new USIN_Standard_Report(
				'ielts_essays_per_user',
				__( 'Essays Per User', 'ielts-science-lms' ),
				array(
					'group'        => $this->group,
					'type'         => USIN_Report::BAR,
					'info'         => __( 'Distribution of essay submission counts', 'ielts-science-lms' ),
					'loader_class' => 'Ieltssci_Ielts_Essays_Per_User_Loader',
				)
			),

			// Most active writers.
			new USIN_Standard_Report(
				'ielts_top_writers',
				__( 'Top Writers', 'ielts-science-lms' ),
				array(
					'group'        => $this->group,
					'type'         => USIN_Report::BAR,
					'visible'      => false,
					'info'         => __( 'Users with most essay submissions', 'ielts-science-lms' ),
					'loader_class' => 'Ieltssci_Ielts_Top_Writers_Loader',
				)
			),
		);
	}
}
