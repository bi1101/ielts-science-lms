<?php
/**
 * Placeholder class for Writing Score in IELTS Science LMS.
 *
 * @package IeltsScienceLMS\Writing
 */

namespace IeltsScienceLMS\Writing;

use IeltsScienceLMS\Writing\Ieltssci_Essay_DB;
use IeltsScienceLMS\Writing\Ieltssci_Writing_Band_Descriptor;

/**
 * Class Ieltssci_Writing_Score.
 * Placeholder for writing scoring logic.
 */
class Ieltssci_Writing_Score {

	/**
	 * Constructor for Ieltssci_Writing_Score.
	 */
	public function __construct() {
		// Initialization code here.
	}

	/**
	 * Get the overall score for an essay based on its type.
	 *
	 * @param array  $essay Essay object as returned by get_essays.
	 * @param string $score_type Type of score to get: 'final' for latest scores, 'initial' for earliest scores. Default 'final'.
	 * @return array|null Array with 'score' and 'source' keys, or null if type not recognized.
	 */
	public function get_overall_score( $essay, $score_type = 'final' ) {
		if ( ! isset( $essay['essay_type'] ) ) {
			return null;
		}
		$essay_type = $essay['essay_type'];
		if ( strpos( $essay_type, 'task-1' ) !== false ) {
			return $this->get_task_1_score( $essay, $score_type );
		} elseif ( strpos( $essay_type, 'task-2' ) !== false ) {
			return $this->get_task_2_score( $essay, $score_type );
		}
		return null;
	}

	/**
	 * Calculate overall score for Task 1 essays.
	 *
	 * @param array  $essay Essay object.
	 * @param string $score_type Type of score to get: 'final' for latest scores, 'initial' for earliest scores. Default 'final'.
	 * @return array|null Array with 'score' and 'source' keys, or null if calculation fails.
	 */
	protected function get_task_1_score( $essay, $score_type = 'final' ) {
		// Define Task 1 criteria with their corresponding feedNames (sub-criteria).
		$task_1_criteria = array(
			'lexicalResource'   => array(
				'range-of-vocab',
				'word-choice-collocation-style',
				'uncommon-vocab',
				'spelling-word-form-error',
			),
			'taskAchievement'   => array(
				'use-data-accurately',
				'present-key-features',
				'use-data',
				'present-an-overview',
				'format',
			),
			'coherenceCohesion' => array(
				'flow',
				'paragraphing-task-1',
				'referencing',
				'use-of-cohesive-devices',
			),
			'gra'               => array(
				'range-of-structures',
				'grammar-accuracy',
			),
		);

		return $this->calculate_score_by_criteria( $essay, $task_1_criteria, $score_type );
	}

	/**
	 * Calculate overall score for Task 2 essays.
	 *
	 * @param array  $essay Essay object.
	 * @param string $score_type Type of score to get: 'final' for latest scores, 'initial' for earliest scores. Default 'final'.
	 * @return array|null Array with 'score' and 'source' keys, or null if calculation fails.
	 */
	protected function get_task_2_score( $essay, $score_type = 'final' ) {
		// Define Task 2 criteria with their corresponding feedNames (sub-criteria).
		$task_2_criteria = array(
			'lexicalResource'   => array(
				'range-of-vocab',
				'word-choice-collocation-style',
				'uncommon-vocab',
				'spelling-word-form-error',
			),
			'taskResponse'      => array(
				'relevance',
				'clear-opinion',
				'idea-development',
			),
			'coherenceCohesion' => array(
				'flow',
				'paragraphing',
				'referencing',
				'use-of-cohesive-devices',
			),
			'gra'               => array(
				'range-of-structures',
				'grammar-accuracy',
			),
		);

		return $this->calculate_score_by_criteria( $essay, $task_2_criteria, $score_type );
	}

	/**
	 * Calculate overall score based on given criteria configuration.
	 *
	 * @param array  $essay Essay object.
	 * @param array  $criteria_config Array of criteria with their feedNames.
	 * @param string $score_type Type of score to get: 'final' for latest scores, 'initial' for earliest scores. Default 'final'.
	 * @return array|null Array with 'score' and 'source' keys, or null if calculation fails.
	 */
	protected function calculate_score_by_criteria( $essay, $criteria_config, $score_type = 'final' ) {
		// Check if essay has an ID.
		if ( ! isset( $essay['id'] ) ) {
			return null;
		}

		$essay_id = $essay['id'];

		// Initialize Essay DB and Band Descriptor.
		$essay_db        = new Ieltssci_Essay_DB();
		$band_descriptor = new Ieltssci_Writing_Band_Descriptor();

		// Determine order based on score_type.
		$order = ( 'final' === $score_type ) ? 'DESC' : 'ASC';

		$criteria_scores    = array();
		$has_human_feedback = false;

		// Get feedbacks for each criteria.
		foreach ( $criteria_config as $criteria => $feed_names ) {
			$all_scores = array();

			// Get feedbacks for each feedName within this criteria.
			foreach ( $feed_names as $feed_name ) {
				$feedback_results = $essay_db->get_essay_feedbacks(
					array(
						'essay_id'          => $essay_id,
						'feedback_criteria' => $feed_name,
						'fields'            => array( 'score_content', 'source' ),
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
					return null; // If any feed_name is missing score_content, return null immediately.
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
		if ( ! empty( $criteria_scores ) && count( $criteria_scores ) === count( $criteria_config ) ) {
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
