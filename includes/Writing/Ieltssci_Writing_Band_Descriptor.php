<?php
/**
 * Writing Band Descriptor class for IELTS Science LMS.
 * Mirrors the band descriptor logic from TypeScript to PHP.
 *
 * @package IeltsScienceLMS\Writing
 */

namespace IeltsScienceLMS\Writing;

/**
 * Class Ieltssci_Writing_Band_Descriptor.
 * Provides band descriptor logic for writing assessment.
 */
class Ieltssci_Writing_Band_Descriptor {

	/**
	 * Constructor for Ieltssci_Writing_Band_Descriptor.
	 */
	public function __construct() {
	}

	/**
	 * Get the band descriptor mapping for a given criteria and optional sub-criteria.
	 *
	 * @param string      $criteria Main criteria (e.g., 'TR', 'CC', 'LR', 'GRA', 'AE', 'TA').
	 * @param string|null $sub_criteria Optional sub-criteria.
	 * @return array Mapping for the given criteria and sub-criteria.
	 */
	public function get_band_descriptor_mapping( $criteria, $sub_criteria = null ) {
		$band_descriptor   = $this->get_band_descriptor_data();
		$standard_criteria = $this->map_to_standard_criteria( $criteria );
		if ( ! isset( $band_descriptor[ $standard_criteria ] ) ) {
			return array();
		}
		if ( $sub_criteria ) {
			return isset( $band_descriptor[ $standard_criteria ][ $sub_criteria ] ) ? $band_descriptor[ $standard_criteria ][ $sub_criteria ] : array();
		}
		return $band_descriptor[ $standard_criteria ];
	}

	/**
	 * Map criteria aliases to their standard forms.
	 *
	 * @param string $criteria Criteria alias or standard.
	 * @return string Standard criteria key.
	 */
	private function map_to_standard_criteria( $criteria ) {
		$alias = array(
			'taskResponse'      => 'TR',
			'coherenceCohesion' => 'CC',
			'lexicalResource'   => 'LR',
			'gra'               => 'GRA',
			'taskAchievement'   => 'TA',
		);
		return isset( $alias[ $criteria ] ) ? $alias[ $criteria ] : $criteria;
	}

	/**
	 * Get the score and color for a given criteria, option, and optional sub-criteria.
	 *
	 * @param string      $criteria Main criteria.
	 * @param string|int  $option Option key.
	 * @param string|null $sub_criteria Optional sub-criteria.
	 * @return array ['score' => ..., 'color' => ...]
	 */
	public function get_score( $criteria, $option, $sub_criteria = null ) {
		$band_descriptor = $this->get_band_descriptor_mapping( $criteria, $sub_criteria );
		$score           = null;
		$score_color     = 'info'; // Default color.
		if ( $sub_criteria && is_array( $band_descriptor ) && isset( $band_descriptor[ $option ] ) ) {
			$descriptor = $band_descriptor[ $option ];
			if ( $descriptor ) {
				$score       = $descriptor['score'];
				$score_color = $descriptor['color'];
			}
		} else {
			foreach ( $band_descriptor as $sub_category => $descriptors ) {
				if ( isset( $descriptors[ $option ] ) ) {
					$score       = $descriptors[ $option ]['score'];
					$score_color = $descriptors[ $option ]['color'];
					break;
				}
			}
		}
		return array(
			'score' => null !== $score ? $score : 0,
			'color' => $score_color,
		);
	}

	/**
	 * Get the lowest band score for a criteria and array of options.
	 *
	 * @param string $criteria Criteria key.
	 * @param array  $options Array of option keys.
	 * @return array ['score' => int|null]
	 */
	public function get_band_score( $criteria, $options ) {
		$score = null;
		foreach ( $options as $option ) {
			$score_data  = $this->get_score( $criteria, $option );
			$score_value = $score_data['score'];
			if ( $this->is_numeric( $score_value ) ) {
				$numeric_score = (int) $score_value;
				if ( null === $score || $numeric_score < $score ) {
					$score = $numeric_score;
				}
			}
		}
		return array( 'score' => $score );
	}

	/**
	 * Check if a value is numeric.
	 *
	 * @param mixed $value Value to check.
	 * @return bool True if numeric.
	 */
	private function is_numeric( $value ) {
		return is_numeric( $value ) && null !== $value;
	}

	/**
	 * Get the band descriptor data structure.
	 *
	 * @return array Band descriptor data.
	 */
	private function get_band_descriptor_data() {
		return array(
			'TR'  => array(
				'Idea Development' => array(
					'Well extended and supported' => array(
						'score' => 8,
						'color' => 'success',
					),
					'Extended and supported, but overgeneralized, lack focus or precision' => array(
						'score' => 7,
						'color' => 'info',
					),
					'Insufficiently developed or lack clarity' => array(
						'score' => 6,
						'color' => 'warning',
					),
					'Little to no development, containing irrelevant or illogical detail' => array(
						'score' => 5,
						'color' => 'error',
					),
				),
				'Relevance'        => array(
					'ALL parts of the question are answered' => array(
						'score' => '6+',
						'color' => 'success',
					),
					'SOME parts of the question are NOT answered' => array(
						'score' => 5,
						'color' => 'yellow',
					),
					'Answers are not exact to the requirement' => array(
						'score' => 4,
						'color' => 'warning',
					),
					'The prompt has been misunderstood' => array(
						'score' => 3,
						'color' => 'error',
					),
				),
				'Clear Position'   => array(
					'Has a position directly answering the question' => array(
						'score' => '5+',
						'color' => 'success',
					),
					'Hard to discern position' => array(
						'score' => 4,
						'color' => 'warning',
					),
					'Hard to discern answer'   => array(
						'score' => 4,
						'color' => 'warning',
					),
					'No discernable position'  => array(
						'score' => 3,
						'color' => 'error',
					),
					'No discernable answer'    => array(
						'score' => 3,
						'color' => 'error',
					),
				),
			),
			'CC'  => array(
				'Flow'                    => array(
					'Ideas are connected flawlessly'    => array(
						'score' => 8,
						'color' => 'success',
					),
					'Ideas are logically connected; minor sentence-level disruptions' => array(
						'score' => 7,
						'color' => 'info',
					),
					'Ideas are logically connected; minor paragraph-level disruptions or considerable sentence-level disruptions' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Ideas recognizable but not logically connected' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Idea arrangement causes confusion' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
				'Paragraphing'            => array(
					'Effective paragraphing'       => array(
						'score' => '7+',
						'color' => 'success',
					),
					'Clear structure, not all paragraphs have a clear purpose' => array(
						'score' => 6,
						'color' => 'info',
					),
					'Missing paragraph'            => array(
						'score' => 5,
						'color' => 'yellow',
					),
					'No paragraphing or all paragraphs lack a clear purpose' => array(
						'score' => 4,
						'color' => 'warning',
					),
					'No identifiable paragraphing' => array(
						'score' => 3,
						'color' => 'error',
					),
				),
				'Referencing'             => array(
					'Skillfully used, no lapse' => array(
						'score' => 8,
						'color' => 'success',
					),
					'Some lapses'               => array(
						'score' => 7,
						'color' => 'info',
					),
					'Unclear referencing causes some repetition & misunderstanding' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Inadequate & inaccurate referencing causes frequent repetition' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Minimal & inaccurate use of referencing' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
				'Use of Cohesive Devices' => array(
					'Perfect use' => array(
						'score' => 8,
						'color' => 'success',
					),
					'Mostly accurate & flexible use, not mechanical' => array(
						'score' => 7,
						'color' => 'info',
					),
					'Some good use, but some grammar errors; mechanical use' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Limited/overuse and frequent inaccurate use' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Some use of basic devices inaccurately or repetitively' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
			),
			'LR'  => array(
				'Range of Vocabulary'             => array(
					'Wide resources, flexible use, and precise meaning' => array(
						'score' => 8,
						'color' => 'success',
					),
					'Sufficient resource to allow flexibility, not repetitive' => array(
						'score' => 7,
						'color' => 'info',
					),
					'Adequate resources, some repetition & few paraphrasing' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Limited and minimally adequate, frequent repetition' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Limited and inadequate, frequent repetition, no paraphrasing' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
				'Word choice, Collocation, Style' => array(
					'Occasional error in word choice, collocation and informal language' => array(
						'score' => 8,
						'color' => 'success',
					),
					'Few minor errors in style and collocation. No word choice errors' => array(
						'score' => 7,
						'color' => 'info',
					),
					'Some errors, not affecting comprehension' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Frequent errors, not affecting comprehension' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Errors impede meaning' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
				'Uncommon Vocabulary'             => array(
					'Uncommon vocab does not impede comprehension, no unnatural usage' => array(
						'score' => '8+',
						'color' => 'success',
					),
					'Uncommon vocab does not impede comprehension, occasional unnatural usage' => array(
						'score' => 8,
						'color' => 'success',
					),
					'Uncommon vocab does not impede comprehension, some unnatural usage' => array(
						'score' => 7,
						'color' => 'info',
					),
					'Uncommon vocab may not be appropriate' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'No usage of uncommon vocab' => array(
						'score' => 5,
						'color' => 'warning',
					),
				),
				'Spelling, Word Form Error'       => array(
					'Spelling mistakes never impede meaning, few errors' => array(
						'score' => 8,
						'color' => 'success',
					),
					'Spelling mistakes never impede meaning, some errors' => array(
						'score' => 7,
						'color' => 'info',
					),
					'Spelling mistakes never impede meaning, several errors' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Spelling sometimes impedes meaning' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Spelling errors always impede meaning' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
			),
			'GRA' => array(
				'Range of Structures' => array(
					'A wide range of structures is flexibly and accurately used' => array(
						'score' => 8,
						'color' => 'success',
					),
					'A variety of structures is used with SOME flexibility' => array(
						'score' => 7,
						'color' => 'info',
					),
					'A mix of simple and multi-clause sentences; limited flexibility, complex structures are less accurate' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'The range of structures is limited and repetitive; some multi-clause sentences are used' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'A very limited range of structures is used, only single clause sentences' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
				'Accuracy'            => array(
					'Rare, non-systematic errors, NEVER impede meaning' => array(
						'score' => 8,
						'color' => 'success',
					),
					'Few errors, NEVER impede meaning'   => array(
						'score' => 7,
						'color' => 'info',
					),
					'Some errors, RARELY impede meaning' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Frequent errors & SOMETIMES impede meaning' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Errors FREQUENTLY impede meaning'   => array(
						'score' => 4,
						'color' => 'error',
					),
				),
			),
			'AE'  => array(
				'Logic & Depth - Main Points'         => array(
					'Well extended and supported' => array(
						'score' => 8,
						'color' => 'success',
					),
					'Extended and supported'      => array(
						'score' => 7,
						'color' => 'info',
					),
					'Insufficiently developed, containing unclear detail' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Little to no development, may contain illogical detail' => array(
						'score' => 5,
						'color' => 'warning',
					),
				),
				'Relevance - Intro & Conclusion'      => array(
					'ALL parts of the question are answered' => array(
						'score' => '6+',
						'color' => 'success',
					),
					'SOME parts of the question are NOT answered' => array(
						'score' => 5,
						'color' => 'yellow',
					),
					'Not exact to the question'           => array(
						'score' => 4,
						'color' => 'warning',
					),
					'The question has been misunderstood' => array(
						'score' => 3,
						'color' => 'error',
					),
				),
				'Clear Position - Intro & Conclusion' => array(
					'Clear position'           => array(
						'score' => '5+',
						'color' => 'success',
					),
					'Clear answer'             => array(
						'score' => '5+',
						'color' => 'success',
					),
					'Hard to discern position' => array(
						'score' => 4,
						'color' => 'warning',
					),
					'Hard to discern answer'   => array(
						'score' => 4,
						'color' => 'warning',
					),
					'No discernable position'  => array(
						'score' => 3,
						'color' => 'error',
					),
					'No discernable answer'    => array(
						'score' => 3,
						'color' => 'error',
					),
				),
				'Brief Overview'                      => array(
					'Include a brief overview of main points' => array(
						'score' => '7+',
						'color' => 'success',
					),
					'No overview of main points' => array(
						'score' => 6,
						'color' => 'yellow',
					),
				),
				'Overgeneralization - Main Points'    => array(
					'No overgeneralization'           => array(
						'score' => 8,
						'color' => 'success',
					),
					'Contain overgeneralized details' => array(
						'score' => '7 or less',
						'color' => 'info',
					),
				),
				'Relevance - Main Points'             => array(
					'Good'                          => array(
						'score' => 8,
						'color' => 'success',
					),
					'Contain lack-of-focus details' => array(
						'score' => 7,
						'color' => 'info',
					),
					'Contain less relevant details' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Contain irrelevant details'    => array(
						'score' => 5,
						'color' => 'warning',
					),
				),
				'Linking'                             => array(
					'Good'               => array(
						'score' => 8,
						'color' => 'success',
					),
					'Could be improved'  => array(
						'score' => 7,
						'color' => 'info',
					),
					'Fair'               => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Needs work'         => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Requires attention' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
			),
			'TA'  => array(
				'Use data accurately'  => array(
					'Accurate and relevant data'      => array(
						'score' => 8,
						'color' => 'success',
					),
					'Inaccurate data'                 => array(
						'score' => 4,
						'color' => 'warning',
					),
					'The data has been misunderstood' => array(
						'score' => 3,
						'color' => 'error',
					),
				),
				'Present key features' => array(
					'Key features are skillfully selected and clearly highlighted' => array(
						'score' => 8,
						'color' => 'success',
					),
					'Key features are covered, and clearly highlighted, but could be further extended' => array(
						'score' => 7,
						'color' => 'info',
					),
					'Key features are adequately covered, some missing or excessive details' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Key features are not adequately covered, irrelevant or mechanically recounted' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Few key features are selected, and they are irrelevant or repetitive' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
				'Use data'             => array(
					'Appropriately use data' => array(
						'score' => '6+',
						'color' => 'success',
					),
					'No data'                => array(
						'score' => 5,
						'color' => 'error',
					),
				),
				'Present an overview'  => array(
					'A clear overview with main trends or differences' => array(
						'score' => '7+',
						'color' => 'success',
					),
					'A relevant overview is attempted'   => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'No overview, only focus on details' => array(
						'score' => 5,
						'color' => 'error',
					),
				),
				'Format'               => array(
					'Appropriate format, data are appropriately categorized' => array(
						'score' => '7+',
						'color' => 'success',
					),
					'Appropriate format'   => array(
						'score' => 6,
						'color' => 'info',
					),
					'Format may be inappropriate in places' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Inappropriate format' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
			),
		);
	}
}
