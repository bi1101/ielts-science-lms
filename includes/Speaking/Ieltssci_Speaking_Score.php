<?php
/**
 * Placeholder class for Speaking Score in IELTS Science LMS.
 *
 * @package IeltsScienceLMS\Speaking
 */

namespace IeltsScienceLMS\Speaking;

use IeltsScienceLMS\Speaking\Ieltssci_Speech_DB;
use IeltsScienceLMS\Speaking\Ieltssci_Speaking_Band_Descriptor;

/**
 * Class Ieltssci_Speaking_Score.
 * Placeholder for speaking scoring logic.
 */
class Ieltssci_Speaking_Score {

	/**
	 * Constructor for Ieltssci_Speaking_Score.
	 */
	public function __construct() {
		// Initialization code here.
	}

	/**
	 * Get the overall score for a speech.
	 *
	 * @param array  $speech Speech object as returned by get_speeches.
	 * @param string $score_type Type of score to get: 'final' for latest scores, 'initial' for earliest scores. Default 'final'.
	 * @return array|null Array with 'score' and 'source' keys, or null if type not recognized.
	 */
	public function get_overall_score( $speech, $score_type = 'final' ) {
		return $this->get_speech_score( $speech, $score_type );
	}

	/**
	 * Calculate overall score for a speech.
	 *
	 * @param array  $speech Speech object.
	 * @param string $score_type Type of score to get: 'final' for latest scores, 'initial' for earliest scores. Default 'final'.
	 * @return array|null Array with 'score' and 'source' keys, or null if calculation fails.
	 */
	protected function get_speech_score( $speech, $score_type = 'final' ) {
		// Define Speaking criteria with their corresponding feedNames (sub-criteria).
		$speaking_criteria = array(
			'fluencyCoherence' => array(
				'coherence',
				'speech-length',
				'pacing',
			),
			'lexicalResource'  => array(
				'range-of-vocab-speaking',
				'word-choice-collocation-style-speaking',
				'uncommon-vocab-speaking',
			),
			'gra'              => array(
				'range-of-structures-speaking',
				'grammar-accuracy-speaking',
			),
			'pronunciation'    => array(
				'pronunciation-accuracy-manual',
				'intelligibility-manual',
				'rhythm-manual',
				'intonation-stress-manual',
				'phonological-features-manual',
			),
		);

		return $this->calculate_score_by_criteria( $speech, $speaking_criteria, $score_type );
	}

	/**
	 * Calculate overall score based on given criteria configuration.
	 *
	 * @param array  $speech Speech object.
	 * @param array  $criteria_config Array of criteria with their feedNames.
	 * @param string $score_type Type of score to get: 'final' for latest scores, 'initial' for earliest scores. Default 'final'.
	 * @return array|null Array with 'score' and 'source' keys, or null if calculation fails.
	 */
	protected function calculate_score_by_criteria( $speech, $criteria_config, $score_type = 'final' ) {
		// Check if speech has an ID.
		if ( ! isset( $speech['id'] ) ) {
			return null;
		}

		$speech_id = $speech['id'];

		// Initialize Speech DB and Band Descriptor.
		$speech_db       = new Ieltssci_Speech_DB();
		$band_descriptor = new Ieltssci_Speaking_Band_Descriptor();

		// Determine order based on score_type.
		$order = ( 'final' === $score_type ) ? 'DESC' : 'ASC';

		$criteria_scores    = array();
		$has_human_feedback = false;

		// Get feedbacks for each criteria.
		foreach ( $criteria_config as $criteria => $feed_names ) {
			$all_scores = array();

			// Get feedbacks for each feedName within this criteria.
			foreach ( $feed_names as $feed_name ) {
				$feedback_results = $speech_db->get_speech_feedbacks(
					array(
						'speech_id'         => $speech_id,
						'feedback_criteria' => $feed_name,
						'include_score'     => true,
						'include_feedback'  => false,
						'include_cot'       => false,
						'orderby'           => 'created_at',
						'order'             => $order,
						'number'            => 20, // Get all (most recent or oldest) feedback.
					)
				);

				// Use the first feedback that has score_content in the required field.
				$found_score = false;
				if ( ! is_wp_error( $feedback_results ) && ! empty( $feedback_results ) ) {
					foreach ( $feedback_results as $feedback ) {
						if ( ! empty( $feedback['score_content'] ) ) {
							$all_scores[] = $feedback['score_content'];
							// Check if this feedback is from a human source.
							if ( isset( $feedback['source'] ) && 'human' === $feedback['source'] ) {
								$has_human_feedback = true;
							}
							$found_score = true;
							break; // Only take the first one since we ordered and limited.
						}
					}
				}
				if ( ! $found_score ) {
					// Only allow missing feedback for pronunciation criteria (requires manual review).
					if ( 'pronunciation' !== $criteria ) {
						return null; // If any other criteria is missing score_content, return null immediately.
					}
					// For pronunciation, skip this criteria if feedback is not available.
					continue 2; // Skip to next criteria.
				}
			}

			// Get band score for this criteria using the band descriptor.
			if ( ! empty( $all_scores ) ) {
				$band_result = $band_descriptor->get_band_score( $criteria, $all_scores );
				if ( isset( $band_result['score'] ) && null !== $band_result['score'] ) {
					$criteria_scores[] = (float) $band_result['score'];
				}
			}
		}

		// Calculate overall score as average of criteria scores.
		// Require at least 3 criteria (all except pronunciation) to calculate score.
		if ( ! empty( $criteria_scores ) && count( $criteria_scores ) >= 3 ) {
			$average = array_sum( $criteria_scores ) / count( $criteria_scores );
			// Round to nearest 0.5.
			$final_score = round( $average * 2 ) / 2;

			// Determine source: 'human' if any human feedback found, otherwise 'ai'.
			$source = $has_human_feedback ? 'human' : 'ai';

			return array(
				'score'  => $final_score,
				'source' => $source,
			);
		}

		return null;
	}
}
