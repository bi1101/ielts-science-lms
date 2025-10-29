<?php
/**
 * Writing Feedback Database Handler
 *
 * @package IeltsScienceLMS
 * @subpackage Writing
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Writing;

use WP_Error;
use IeltsScienceLMS\Writing\Ieltssci_Segment_Extractor;

/**
 * Class Ieltssci_Writing_Feedback_DB
 *
 * Handles database operations for writing feedback, including retrieving existing content
 * and saving feedback to the database for essays, paragraphs, and segments.
 */
class Ieltssci_Writing_Feedback_DB {

	/**
	 * Segment extractor instance.
	 *
	 * @var Ieltssci_Segment_Extractor
	 */
	private $segment_extractor;

	/**
	 * Constructor for the feedback database class.
	 *
	 * Initializes the segment extractor and sets up message handling capability.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->segment_extractor = new Ieltssci_Segment_Extractor();
	}


	/**
	 * Resolve the user ID that should be credited for feedback on a given essay.
	 *
	 * Follows the same pattern used in rate-limit checks: if the essay is linked
	 * to a task submission that has an 'instructor_id' meta value, then use that
	 * instructor ID; otherwise, return 0 so callers can fall back to current user.
	 *
	 * @param int $essay_id The essay ID.
	 * @return int The instructor user ID if available, or 0 if none found.
	 */
	private function resolve_feedback_creator_user_id( $essay_id ) {
		if ( empty( $essay_id ) ) {
			return 0; // Invalid essay ID, no override.
		}

		try {
			$submission_db   = new Ieltssci_Submission_DB();
			$task_submission = $submission_db->get_task_submissions(
				array(
					'essay_id' => (int) $essay_id,
					'number'   => 1,
					'orderby'  => 'id',
					'order'    => 'DESC',
				)
			);
			if ( is_wp_error( $task_submission ) ) {
				return 0; // DB error, skip override.
			}
			if ( $task_submission && is_array( $task_submission ) && ! empty( $task_submission ) && ! empty( $task_submission[0]['id'] ) ) {
				// If the task submission is associated with a test, get instructor_id from test submission meta.
				if ( isset( $task_submission[0]['test_submission_id'] ) && ! empty( $task_submission[0]['test_submission_id'] ) ) {
					$instructor_id = $submission_db->get_test_submission_meta( $task_submission[0]['test_submission_id'], 'instructor_id', true );
				} else {
					$instructor_id = $submission_db->get_task_submission_meta( $task_submission[0]['id'], 'instructor_id', true );
				}
				return $instructor_id ? (int) $instructor_id : 0; // Return instructor or 0.
			}
		} catch ( \Exception $e ) {
			// Log exception if needed, but return 0 to avoid blocking feedback saving.
			error_log( 'Error resolving feedback creator user ID: ' . $e->getMessage() );
			return 0;
		}

		return 0; // No instructor found.
	}

	/**
	 * Check if a step has already been processed and retrieve existing content
	 *
	 * @param string $step_type     The type of step (chain-of-thought, scoring, feedback).
	 * @param array  $feed          The feed data containing configuration.
	 * @param string $uuid          The UUID of the essay.
	 * @param array  $segment       Optional. The segment data if processing a specific segment.
	 * @param string $content_field The database field to retrieve.
	 *
	 * @return array|string|null The existing content if found, an array with segments data if paragraph processing, or null otherwise.
	 */
	public function get_existing_step_content( $step_type, $feed, $uuid, $segment = null, $content_field = '' ) {
		// Get the apply_to value from feed to determine which table to check.
		$apply_to = isset( $feed['apply_to'] ) ? $feed['apply_to'] : '';

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

		// Initialize Essay DB.
		$essay_db = new Ieltssci_Essay_DB();

		// Get essay ID from UUID.
		$essays = $essay_db->get_essays(
			array(
				'uuid'     => $uuid,
				'per_page' => 1,
				'fields'   => array( 'id' ),
			)
		);

		if ( is_wp_error( $essays ) || empty( $essays ) ) {
			return null;
		}

		$essay_id = $essays[0]['id'];

		// Check the appropriate table based on apply_to.
		switch ( $apply_to ) {
			case 'essay':
				// Get feedback.
				$feedback_results = $essay_db->get_essay_feedbacks(
					array(
						'essay_id'          => $essay_id,
						'feedback_criteria' => $feedback_criteria,
						'fields'            => array( $content_field, 'created_at' ),
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
				break;

			case 'paragraph':
				// Check if segments already exist for this essay.
				// Segment extraction doesn't depend on source, so this remains the same.
				$existing_segments = $essay_db->get_segments(
					array(
						'essay_id' => $essay_id,
					)
				);

				// If segments already exist, format them for return.
				if ( ! is_wp_error( $existing_segments ) && ! empty( $existing_segments ) ) {
					// Return both the segments data and formatted content.
					$segments_text = '';
					foreach ( $existing_segments as $seg ) {
						$segments_text .= "# {$seg['title']}\n\n{$seg['content']}\n\n---\n\n";
					}

					return array(
						'content'       => trim( $segments_text ),
						'segments_data' => array(
							'segments' => $existing_segments,
							'count'    => count( $existing_segments ),
							'reused'   => true,
						),
					);
				}
				break;

			case 'introduction':
			case 'topic-sentence':
			case 'main-point':
			case 'conclusion':
				// Check if segment feedback already exists for this step.
				if ( null !== $segment && ! empty( $segment['id'] ) ) {
					// Get feedback regardless of source.
					$feedback_results = $essay_db->get_segment_feedbacks(
						array(
							'segment_id'        => $segment['id'],
							'feedback_criteria' => $feedback_criteria,
							'fields'            => array( $content_field, 'created_at' ),
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
				}
				break;
		}

		return $existing_content;
	}

	/**
	 * Save feedback results to the database
	 *
	 * @param string $feedback The feedback content to save.
	 * @param array  $feed The feed configuration data.
	 * @param string $uuid The UUID of the essay.
	 * @param string $step_type The type of step being processed.
	 * @param array  $segment Optional. The segment data if processing a specific segment.
	 * @param string $language The language of the feedback.
	 * @param string $source The source of the feedback, 'ai' or 'human'.
	 * @param string $refetch Whether to refetch content, 'all' or specific step type.
	 * @return bool True on success, false on failure.
	 */
	public function save_feedback_to_database( $feedback, $feed, $uuid, $step_type, $segment = null, $language = 'en', $source = 'ai', $refetch = '' ) {
		// Check if we have a valid apply_to setting.
		if ( empty( $feed['apply_to'] ) ) {
			return false;
		}

		// Get the apply_to value which determines where to save.
		$apply_to = $feed['apply_to'];

		// Implement the database saving logic based on the apply_to value.
		switch ( $apply_to ) {

			case 'essay':
				// Save the essay-level feedback.
				$this->save_essay_feedback( $uuid, $feedback, $feed, $step_type, $language, $source, $refetch );
				break;

			case 'paragraph':
				// Save the segments.
				$this->save_paragraph_feedback( $uuid, $feedback, $feed, $step_type );
				break;

			case 'introduction':
			case 'topic-sentence':
			case 'main-point':
			case 'conclusion':
				// Save feedback for the specific segment.
				$this->save_segment_feedback( $uuid, $feedback, $feed, $step_type, $segment, $language, $source, $refetch );
				break;

			// Add other cases as needed.
		}
		return true;
	}

	/**
	 * Save segment feedback to the database
	 *
	 * @param string $uuid       The UUID of the essay.
	 * @param string $feedback   The feedback content to save.
	 * @param array  $feed       The feed data (containing criteria, etc.).
	 * @param string $step_type  The type of step (chain-of-thought, scoring, feedback).
	 * @param array  $segment    The segment data object.
	 * @param string $language   The language of the feedback.
	 * @param string $source     The source of the feedback, 'ai' or 'human'.
	 * @param string $refetch    Whether to refetch content, 'all' or specific step type.
	 * @return int|WP_Error|bool ID of created feedback, error, or false if skipped.
	 */
	private function save_segment_feedback( $uuid, $feedback, $feed, $step_type, $segment, $language = 'en', $source = 'ai', $refetch = '' ) {
		// Check if segment is null - if so, return false (nothing to save).
		if ( empty( $segment ) || empty( $segment['id'] ) ) {
			return false;
		}

		// Get segment ID from segment object.
		$segment_id = $segment['id'];

		// Try to obtain essay_id from provided segment or by querying the DB if not present.
		$essay_id = isset( $segment['essay_id'] ) ? (int) $segment['essay_id'] : 0; // Prefer essay_id from segment payload if available.
		if ( ! $essay_id ) {
			$essay_db   = new Ieltssci_Essay_DB();
			$seg_record = $essay_db->get_segments(
				array(
					'segment_id' => $segment_id,
					'fields'     => array( 'essay_id' ),
				)
			);
			if ( ! is_wp_error( $seg_record ) && ! empty( $seg_record ) && isset( $seg_record[0]['essay_id'] ) ) {
				$essay_id = (int) $seg_record[0]['essay_id'];
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

		// Initialize Essay DB.
		$essay_db = new Ieltssci_Essay_DB();

		// Prepare feedback data.
		$feedback_data = array(
			'segment_id'        => $segment_id,
			'feedback_criteria' => $feedback_criteria,
			'feedback_language' => $language,
			'source'            => $source,
			$content_field      => $feedback,
		);

		// If the essay is linked to a task submission with an instructor, credit feedback to the instructor.
		$instructor_id = $essay_id ? $this->resolve_feedback_creator_user_id( $essay_id ) : 0;
		if ( $instructor_id > 0 ) {
			$feedback_data['created_by'] = $instructor_id; // Override creator for attribution.
		}

		// Always create a new feedback entry regardless of existing records.
		return $essay_db->create_segment_feedback( $feedback_data );
	}

	/**
	 * Save essay feedback to the database
	 *
	 * @param string $uuid The UUID of the essay.
	 * @param string $feedback The feedback content to save.
	 * @param array  $feed The feed data (containing feedback criteria, etc.).
	 * @param string $step_type The type of step (chain-of-thought, scoring, feedback).
	 * @param string $language The language of the feedback.
	 * @param string $source The source of the feedback, 'ai' or 'human'.
	 * @param string $refetch Whether to refetch content, 'all' or specific step type.
	 * @return int|WP_Error|bool ID of created feedback, error, or false if skipped.
	 */
	private function save_essay_feedback( $uuid, $feedback, $feed, $step_type, $language = 'en', $source = 'ai', $refetch = '' ) {
		// Initialize Essay DB.
		$essay_db = new Ieltssci_Essay_DB();

		// Get the essay_id from UUID.
		$essays = $essay_db->get_essays(
			array(
				'uuid'     => $uuid,
				'per_page' => 1,
				'fields'   => array( 'id' ),
			)
		);

		if ( is_wp_error( $essays ) || empty( $essays ) ) {
			return false;
		}

		$essay_id = $essays[0]['id'];

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
			'essay_id'          => $essay_id,
			'feedback_criteria' => $feedback_criteria,
			'feedback_language' => $language,
			'source'            => $source,
			$content_field      => $feedback,
		);

		// If the essay is linked to a task submission with an instructor, credit feedback to the instructor.
		$instructor_id = $this->resolve_feedback_creator_user_id( $essay_id );
		if ( $instructor_id > 0 ) {
			$feedback_data['created_by'] = $instructor_id; // Override creator for attribution.
		}

		// Always create a new feedback entry.
		return $essay_db->create_essay_feedback( $feedback_data );
	}

	/**
	 * Save segmenting results to the database
	 *
	 * @param string $uuid The UUID of the essay.
	 * @param string $feedback The feedback content as JSON string.
	 * @param array  $feed The feed data.
	 * @param string $step_type The type of step being processed.
	 */
	private function save_paragraph_feedback( $uuid, $feedback, $feed, $step_type ) {
		// Initialize Essay DB.
		$essay_db = new Ieltssci_Essay_DB();

		// Skip chain-of-thought step type.
		if ( 'chain-of-thought' === $step_type ) {
			return;
		}

		// Get the essay_id from UUID.
		$essays = $essay_db->get_essays(
			array(
				'uuid'     => $uuid,
				'per_page' => 1,
				'fields'   => array( 'id' ),
			)
		);

		if ( is_wp_error( $essays ) || empty( $essays ) ) {
			return;
		}

		$essay_id = $essays[0]['id'];

		// First, check if segments already exist for this essay.
		$existing_segments = $essay_db->get_segments(
			array(
				'essay_id' => $essay_id,
				'per_page' => 1,
			)
		);

		// If segments already exist, don't process.
		if ( ! is_wp_error( $existing_segments ) && ! empty( $existing_segments ) ) {
			// Segments already exist, don't duplicate them.
			return;
		}

		// Parse the JSON feedback.
		$segments_data = json_decode( $feedback, true );

		// Check if valid JSON was provided.
		if ( null === $segments_data || ! is_array( $segments_data ) ) {
			// Invalid JSON format, log error or handle accordingly.
			return;
		}

		// Arrays to store different segment types and track ordering.
		$all_segments     = array();
		$segment_order    = 0;
		$main_point_count = 0;

		// Process each segment from the JSON data.
		foreach ( $segments_data as $item ) {
			// Validate required fields exist.
			if ( empty( $item['text'] ) || empty( $item['type'] ) ) {
				continue; // Skip invalid entries.
			}

			++$segment_order;

			// Map the JSON 'type' to database segment type.
			$segment_type = strtolower( str_replace( ' ', '-', $item['type'] ) );

			// Handle the special case where 'Intro' should map to 'introduction'.
			if ( 'intro' === $segment_type ) {
				$segment_type = 'introduction';
			}

			// Increment main point counter if this is a main point.
			if ( 'main-point' === $segment_type ) {
				++$main_point_count;
			}

			// Create title based on segment type.
			$title = '';
			switch ( $segment_type ) {
				case 'introduction':
					$title = 'Introduction';
					break;
				case 'topic-sentence':
					$title = 'Topic Sentence';
					break;
				case 'main-point':
					$title = 'Main Point ' . $main_point_count;
					break;
				case 'conclusion':
					$title = 'Conclusion';
					break;
				default:
					$title = ucfirst( $segment_type );
			}

			// Add to the collection with appropriate metadata.
			$all_segments[] = array(
				'title'   => $title,
				'content' => $item['text'],
				'type'    => $segment_type,
				'order'   => $segment_order,
			);
		}

		// Save the segments to the database.
		foreach ( $all_segments as $segment_data ) {
			$this->save_segment( $essay_id, $segment_data, $segment_data['order'] );
		}
	}

	/**
	 * Save a segment to the database
	 *
	 * @param int   $essay_id The essay ID.
	 * @param array $segment_data The segment data.
	 * @param int   $order The segment order.
	 * @return int|WP_Error Segment ID or error.
	 */
	private function save_segment( $essay_id, $segment_data, $order ) {
		$essay_db = new Ieltssci_Essay_DB();

		$segment = array(
			'essay_id' => $essay_id,
			'type'     => $segment_data['type'],
			'title'    => $segment_data['title'],
			'content'  => $segment_data['content'],
			'order'    => $order,
		);

		return $essay_db->create_update_segment( $segment );
	}
}
