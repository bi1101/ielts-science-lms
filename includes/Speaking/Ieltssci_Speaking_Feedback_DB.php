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
	 * Check if a step has already been processed and retrieve existing content
	 *
	 * @param string $step_type     The type of step (chain-of-thought, scoring, feedback).
	 * @param array  $feed          The feed data containing configuration.
	 * @param string $uuid          The UUID of the speech recording.
	 * @param string $content_field The database field to retrieve.
	 *
	 * @return string|null The existing content if found, null otherwise.
	 */
	public function get_existing_step_content( $step_type, $feed, $uuid, $content_field = '' ) {
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

		// Check if speech feedback already exists for this step.
		$existing_feedback = $this->speech_db->get_speech_feedbacks(
			array(
				'speech_id'         => $speech_id,
				'feedback_criteria' => $feedback_criteria,
				'number'            => 1,
			)
		);

		if ( ! is_wp_error( $existing_feedback ) && ! empty( $existing_feedback ) && ! empty( $existing_feedback[0][ $content_field ] ) ) {
			$existing_content = $existing_feedback[0][ $content_field ];
		}

		return $existing_content;
	}

	/**
	 * Save feedback results to the database
	 *
	 * @param string $feedback   The feedback content to save.
	 * @param array  $feed       The feed configuration data.
	 * @param string $uuid       The UUID of the speech recording.
	 * @param string $step_type  The type of step being processed.
	 * @param string $language   The language of the feedback.
	 * @param string $source     The source of the feedback, 'ai' or 'human'.
	 * @param string $refetch    Whether to refetch content, 'all' or specific step type.
	 * @return bool|int|WP_Error True or ID on success, WP_Error on failure.
	 */
	public function save_feedback_to_database( $feedback, $feed, $uuid, $step_type, $language = 'en', $source = 'ai', $refetch = '' ) {
		// Check if we have a valid feedback content.
		if ( empty( $feedback ) ) {
			return new WP_Error( 'empty_feedback', 'No feedback content provided.' );
		}

		// Save the speech-level feedback.
		return $this->save_speech_feedback( $uuid, $feedback, $feed, $step_type, $language, $source, $refetch );
	}

	/**
	 * Save speech feedback to the database
	 *
	 * @param string $uuid       The UUID of the speech recording.
	 * @param string $feedback   The feedback content to save.
	 * @param array  $feed       The feed data (containing criteria, etc.).
	 * @param string $step_type  The type of step (chain-of-thought, scoring, feedback).
	 * @param string $language   The language of the feedback.
	 * @param string $source     The source of the feedback, 'ai' or 'human'.
	 * @param string $refetch    Whether to refetch content, 'all' or specific step type.
	 * @return int|WP_Error|bool ID of created/updated feedback, error, or false if skipped.
	 */
	private function save_speech_feedback( $uuid, $feedback, $feed, $step_type, $language = 'en', $source = 'ai', $refetch = '' ) {
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

		// Check if existing feedback already exists for this speech and criteria.
		$existing_feedback = $this->speech_db->get_speech_feedbacks(
			array(
				'speech_id'         => $speech_id,
				'feedback_criteria' => $feedback_criteria,
				'number'            => 1,
			)
		);

		// Prepare feedback data.
		$feedback_data = array(
			'speech_id'         => $speech_id,
			'feedback_criteria' => $feedback_criteria,
			'feedback_language' => $language,
			'source'            => $source,
			$content_field      => $feedback,
			'created_by'        => get_current_user_id(),
		);

		// If existing record found.
		if ( ! is_wp_error( $existing_feedback ) && ! empty( $existing_feedback ) ) {
			$existing = $existing_feedback[0];

			// Check if the content field for this step_type is empty.
			if ( empty( $existing[ $content_field ] ) ) {
				// Update the existing record with new content.
				$feedback_data['id'] = $existing['id'];
				$result              = $this->speech_db->create_update_speech_feedback( $feedback_data );
				return $result;
			} else {
				return false;
			}
		} else {
			// No existing record, create new feedback entry.
			$result = $this->speech_db->create_update_speech_feedback( $feedback_data );
			return $result;
		}
	}
}
