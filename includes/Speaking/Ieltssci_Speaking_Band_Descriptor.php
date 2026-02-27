<?php
/**
 * Speaking Band Descriptor class for IELTS Science LMS.
 * Mirrors the band descriptor logic from TypeScript to PHP.
 *
 * @package IeltsScienceLMS\Speaking
 */

namespace IeltsScienceLMS\Speaking;

/**
 * Class Ieltssci_Speaking_Band_Descriptor.
 * Provides band descriptor logic for speaking assessment.
 */
class Ieltssci_Speaking_Band_Descriptor {

	/**
	 * Constructor for Ieltssci_Speaking_Band_Descriptor.
	 */
	public function __construct() {
	}

	/**
	 * Get the band descriptor mapping for a given criteria and optional sub-criteria.
	 *
	 * @param string      $criteria Main criteria (e.g., 'FC', 'LR', 'GRA', 'PR').
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
			'fluencyCoherence' => 'FC',
			'lexicalResource'  => 'LR',
			'gra'              => 'GRA',
			'pronunciation'    => 'PR',
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
					'Occasional error in word choice and collocation' => array(
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
				'Paraphrase'                      => array(
					'Effective use of paraphrase'   => array(
						'score' => '7+',
						'color' => 'success',
					),
					'Often paraphrase successfully' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Attempts paraphrase but not always succeed' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Rarely attempt paraphrase'     => array(
						'score' => 4,
						'color' => 'error',
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
			'FC'  => array(
				'Speech Length' => array(
					'Able to keep going and easily produce long turns' => array(
						'score' => '7+',
						'color' => 'success',
					),
					'Able to keep going and willing to produce long turns' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Able to keep going'   => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Unable to keep going' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
				'Pace'          => array(
					'Fluent, rare self-correction; hesitations for content only' => array(
						'score' => 8,
						'color' => 'success',
					),
					'Some minor mid-sentence language-related hesitations or corrections; coherence intact' => array(
						'score' => 7,
						'color' => 'info',
					),
					'Occasional loss of coherence due to hesitation, repetition and/or self-correction' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Speech is slow, relying on repetition, correction, and hesitation for basic word searches' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Struggles to keep going, with slow speech, frequent pauses, repetition, and self-correction' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
				'Coherence'     => array(
					'Topic development is coherent, appropriate and relevant' => array(
						'score' => 8,
						'color' => 'success',
					),
					'Uses discourse markers and connectives flexibly and cohesively' => array(
						'score' => 7,
						'color' => 'info',
					),
					'Uses various discourse markers and connectives, though not always appropriately' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Overuse of certain discourse markers, connectives and other cohesive features' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Links simple sentences with repetitive connectives; coherence may break down' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
			),
			'PR'  => array(
				'Pronunciation Accuracy' => array(
					'Highly accurate pronunciation and effortless to understand' => array(
						'score' => 8,
						'color' => 'success',
					),
					'Mostly accurate pronunciation with rare minor errors that do not affect clarity' => array(
						'score' => 7,
						'color' => 'info',
					),
					'Individual words or phonemes may be mispronounced but this causes only occasional lack of clarity' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Individual words or phonemes are often mispronounced, causing lack of clarity' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Individual words or phonemes are frequently mispronounced, causing lack of clarity' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
				'Intelligibility'        => array(
					'Clear throughout'                    => array(
						'score' => 8,
						'color' => 'success',
					),
					'Clear throughout, minimal effort needed' => array(
						'score' => 7,
						'color' => 'info',
					),
					'Mostly clear, minimal effort needed' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Few parts are unclear, may require some effort to understand' => array(
						'score' => 5,
						'color' => 'warning',
					),
					'Some parts are unclear, requiring effort to understand' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
				'Rhythm'                 => array(
					'Can sustain appropriate rhythm' => array(
						'score' => '8+',
						'color' => 'success',
					),
					'Chunking is fair, but stress-timing or speed may disrupt rhythm' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Chunking occurs, but rhythm often breaks down' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
				'Intonation & Stress'    => array(
					'Effective stress and intonation over long utterances, with rare lapses' => array(
						'score' => '8+',
						'color' => 'success',
					),
					'Some effective stress and intonation, but not sustained' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Attempts intonation and stress, but control is limited' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
				'Phonological features'  => array(
					'Effectively uses varied phonological features for precise meaning' => array(
						'score' => '8+',
						'color' => 'success',
					),
					'Uses some phonological features, but inconsistently' => array(
						'score' => 6,
						'color' => 'yellow',
					),
					'Uses basic phonological features acceptably, but lacks variety' => array(
						'score' => 4,
						'color' => 'error',
					),
				),
			),
		);
	}
}
