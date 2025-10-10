<?php
/**
 * Speaking Feedback Database Handler
 *
 * @package IeltsScienceLMS
 * @subpackage Speaking
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Speaking;

use WP_Error;

/**
 * Class Ieltssci_Speaking_Feedback_DB
 *
 * Handles database operations for speaking feedback, including retrieving existing content
 * and saving feedback to the database for speech recordings.
 */
class Ieltssci_Speaking_Feedback_DB {

	/**
	 * Speech DB instance.
	 *
	 * @var Ieltssci_Speech_DB
	 */
	private $speech_db;

	/**
	 * Constructor for the speaking feedback database class.
	 *
	 * Initializes the speech DB and sets up message handling capability.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->speech_db = new Ieltssci_Speech_DB();
	}

	/**
	 * Check if a step has already been processed and retrieve existing content.
	 *
	 * @param string      $step_type     The type of step (chain-of-thought, scoring, feedback).
	 * @param array       $feed          The feed data containing configuration.
	 * @param string|null $uuid          The UUID of the speech recording. Can be null for standalone speech attempts.
	 * @param array       $attempt       Optional. The attempt data if processing a specific attempt.
	 * @param string      $content_field The database field to retrieve.
	 *
	 * @return string|null The existing content if found, null otherwise.
	 */
	public function get_existing_step_content( $step_type, $feed, $uuid = null, $attempt = null, $content_field = '' ) {
		// If content_field is not provided, determine it from the step_type.
		if ( empty( $content_field ) ) {
			// Map step_type to the appropriate database column.
			switch ( $step_type ) {
				case 'chain-of-thought':
					$content_field = 'cot_content';
					break;
				case 'scoring':
					$content_field = 'score_content';
					break;
				case 'feedback':
				default:
					$content_field = 'feedback_content';
					break;
			}
		}

		// Initialize variables for existing content.
		$existing_content  = null;
		$feedback_criteria = isset( $feed['feedback_criteria'] ) ? $feed['feedback_criteria'] : 'general';
		$apply_to          = isset( $feed['apply_to'] ) ? $feed['apply_to'] : 'speech';

		// Switch based on apply_to for extendability.
		switch ( $apply_to ) {
			case 'attempt':
				if ( empty( $attempt ) || empty( $attempt['id'] ) ) {
					return null; // No attempt context provided, cannot check attempt-level content.
				}

				$feedback_results = $this->speech_db->get_speech_attempt_feedbacks(
					array(
						'attempt_id'        => (int) $attempt['id'],
						'feedback_criteria' => $feedback_criteria,
						'include_cot'       => ( 'cot_content' === $content_field ),
						'include_score'     => ( 'score_content' === $content_field ),
						'include_feedback'  => ( 'feedback_content' === $content_field ),
						'orderby'           => 'created_at',
						'order'             => 'DESC',
						'limit'             => 10,
					)
				);

				// Use the first feedback that has content in the required field.
				if ( ! is_wp_error( $feedback_results ) && ! empty( $feedback_results ) ) {
					foreach ( $feedback_results as $feedback ) {
						if ( ! empty( $feedback[ $content_field ] ) ) {
							$existing_content = $feedback[ $content_field ];
							break;
						}
					}
				}

				return $existing_content;

			case 'speech':
			default:
				// Speech-level: UUID is required.
				if ( empty( $uuid ) ) {
					return null;
				}

				// Get speech ID from UUID.
				$speeches = $this->speech_db->get_speeches(
					array(
						'uuid'     => $uuid,
						'per_page' => 1,
					)
				);

				if ( is_wp_error( $speeches ) || empty( $speeches ) ) {
					return null;
				}

				$speech_id = $speeches[0]['id'];

				// Get feedback ordered by creation date (newest first).
				$feedback_results = $this->speech_db->get_speech_feedbacks(
					array(
						'speech_id'         => $speech_id,
						'feedback_criteria' => $feedback_criteria,
						'include_cot'       => ( 'cot_content' === $content_field ),
						'include_score'     => ( 'score_content' === $content_field ),
						'include_feedback'  => ( 'feedback_content' === $content_field ),
						'orderby'           => 'created_at',
						'order'             => 'DESC',
					)
				);

				// Use the first feedback that has content in the required field.
				if ( ! is_wp_error( $feedback_results ) && ! empty( $feedback_results ) ) {
					foreach ( $feedback_results as $feedback ) {
						if ( ! empty( $feedback[ $content_field ] ) ) {
							$existing_content = $feedback[ $content_field ];
							break;
						}
					}
				}

				return $existing_content;
		}
	}

	/**
	 * Save feedback results to the database
	 *
	 * @param string      $feedback   The feedback content to save.
	 * @param array       $feed       The feed configuration data.
	 * @param string|null $uuid       The UUID of the speech recording. Can be null for standalone speech attempts.
	 * @param string      $step_type  The type of step being processed.
	 * @param array       $attempt    Optional. The attempt data if processing a specific attempt.
	 * @param string      $language   The language of the feedback.
	 * @param string      $source     The source of the feedback, 'ai' or 'human'.
	 * @param string      $refetch    Whether to refetch content, 'all' or specific step type.
	 * @return bool|int|WP_Error True or ID on success, WP_Error on failure.
	 */
	public function save_feedback_to_database( $feedback, $feed, $uuid = null, $step_type, $attempt = null, $language = 'en', $source = 'ai', $refetch = '' ) {
		// Check if we have a valid feedback content.
		if ( empty( $feedback ) ) {
			return new WP_Error( 'empty_feedback', 'No feedback content provided.' );
		}

		// Get the apply_to value which determines where to save.
		$apply_to = isset( $feed['apply_to'] ) ? $feed['apply_to'] : 'speech';

		// Implement the database saving logic based on the apply_to value.
		switch ( $apply_to ) {
			case 'speech':
				// Save the speech-level feedback.
				return $this->save_speech_feedback( $uuid, $feedback, $feed, $step_type, $language, $source, $refetch );
			case 'attempt':
				// Save the attempt-level feedback.
				return $this->save_speech_attempt_feedback( $uuid, $feedback, $feed, $step_type, $attempt, $language, $source, $refetch );
			default:
				return new WP_Error( 'invalid_apply_to', 'Invalid apply_to value for speaking feedback.' );
		}
	}

	/**
	 * Save speech feedback to the database
	 *
	 * @param string|null $uuid       The UUID of the speech recording. Required for speech-level feedback.
	 * @param string      $feedback   The feedback content to save.
	 * @param array       $feed       The feed data (containing criteria, etc.).
	 * @param string      $step_type  The type of step (chain-of-thought, scoring, feedback).
	 * @param string      $language   The language of the feedback.
	 * @param string      $source     The source of the feedback, 'ai' or 'human'.
	 * @param string      $refetch    Whether to refetch content, 'all' or specific step type.
	 * @return int|WP_Error ID of created feedback or error.
	 */
	private function save_speech_feedback( $uuid = null, $feedback, $feed, $step_type, $language = 'en', $source = 'ai', $refetch = '' ) {
		// UUID is required for speech-level feedback.
		if ( empty( $uuid ) ) {
			return new WP_Error( 'missing_uuid', 'UUID is required for speech-level feedback.' );
		}

		// Get speech ID from UUID.
		$speeches = $this->speech_db->get_speeches(
			array(
				'uuid'     => $uuid,
				'per_page' => 1,
			)
		);

		if ( is_wp_error( $speeches ) || empty( $speeches ) ) {
			return new WP_Error( 'speech_not_found', 'Speech recording not found with the provided UUID.' );
		}

		$speech_id = $speeches[0]['id'];

		// Extract needed feed attributes.
		$feedback_criteria = isset( $feed['feedback_criteria'] ) ? $feed['feedback_criteria'] : 'general';

		// Map step_type to the appropriate database column.
		$content_field = '';
		switch ( $step_type ) {
			case 'chain-of-thought':
				$content_field = 'cot_content';
				break;
			case 'scoring':
				$content_field = 'score_content';
				break;
			case 'feedback':
			default:
				$content_field = 'feedback_content';
				break;
		}

		// Prepare feedback data.
		$feedback_data = array(
			'speech_id'         => $speech_id,
			'feedback_criteria' => $feedback_criteria,
			'feedback_language' => $language,
			'source'            => $source,
			// Set the specific content field dynamically.
			$content_field      => $feedback,
			'created_by'        => get_current_user_id(),
		);

		// Always create a new feedback entry.
		return $this->speech_db->create_speech_feedback( $feedback_data );
	}

	/**
	 * Save speech attempt feedback to the database
	 *
	 * @param string|null $uuid       The UUID of the speech recording. Can be null for standalone speech attempts.
	 * @param string      $feedback   The feedback content to save.
	 * @param array       $feed       The feed data (containing criteria, etc.).
	 * @param string      $step_type  The type of step (chain-of-thought, scoring, feedback).
	 * @param array       $attempt    The attempt data object.
	 * @param string      $language   The language of the feedback.
	 * @param string      $source     The source of the feedback, 'ai' or 'human'.
	 * @param string      $refetch    Whether to refetch content, 'all' or specific step type.
	 * @return int|WP_Error|bool ID of created feedback, error, or false if skipped.
	 */
	private function save_speech_attempt_feedback( $uuid = null, $feedback, $feed, $step_type, $attempt, $language = 'en', $source = 'ai', $refetch = '' ) {
		// Check if attempt is null - if so, return false (nothing to save).
		if ( empty( $attempt ) || empty( $attempt['id'] ) ) {
			return false;
		}

		// Get attempt ID from attempt object.
		$attempt_id = $attempt['id'];

		// Try to obtain speech_id from provided attempt or by querying the DB if not present.
		$speech_id = isset( $attempt['speech_id'] ) ? (int) $attempt['speech_id'] : 0; // Prefer speech_id from attempt payload if available.
		if ( ! $speech_id ) {
			global $wpdb;
			$table          = $wpdb->prefix . 'ieltssci_speech_attempt';
			$attempt_record = $wpdb->get_row( $wpdb->prepare( 'SELECT speech_id FROM ' . $table . ' WHERE id = %d LIMIT 1', (int) $attempt_id ), ARRAY_A );
			if ( $attempt_record && isset( $attempt_record['speech_id'] ) ) {
				$speech_id = (int) $attempt_record['speech_id'];
			}
		}

		// Extract needed feed attributes.
		$feedback_criteria = isset( $feed['feedback_criteria'] ) ? $feed['feedback_criteria'] : 'general';

		// Map step_type to the appropriate database column.
		$content_field = '';
		switch ( $step_type ) {
			case 'chain-of-thought':
				$content_field = 'cot_content';
				break;
			case 'scoring':
				$content_field = 'score_content';
				break;
			case 'feedback':
			default:
				$content_field = 'feedback_content';
				break;
		}

		// Prepare feedback data.
		$feedback_data = array(
			'attempt_id'        => $attempt_id,
			'feedback_criteria' => $feedback_criteria,
			'feedback_language' => $language,
			'source'            => $source,
			$content_field      => $feedback,
			'created_by'        => get_current_user_id(),
		);

		// Always create a new feedback entry.
		return $this->speech_db->create_speech_attempt_feedback( $feedback_data );
	}
}
