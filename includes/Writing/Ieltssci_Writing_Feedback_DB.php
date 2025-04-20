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
				// Check if essay feedback already exists for this step.
				$existing_feedback = $essay_db->get_essay_feedbacks(
					array(
						'essay_id'          => $essay_id,
						'feedback_criteria' => $feedback_criteria,
						'fields'            => array( $content_field ),
						'per_page'          => 1,
					)
				);

				if ( ! is_wp_error( $existing_feedback ) && ! empty( $existing_feedback ) && ! empty( $existing_feedback[0][ $content_field ] ) ) {
					$existing_content = $existing_feedback[0][ $content_field ];
				}
				break;

			case 'paragraph':
				// Check if segments already exist for this essay.
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
					$existing_feedback = $essay_db->get_segment_feedbacks(
						array(
							'segment_id'        => $segment['id'],
							'feedback_criteria' => $feedback_criteria,
							'fields'            => array( $content_field ),
							'per_page'          => 1,
						)
					);

					if ( ! is_wp_error( $existing_feedback ) && ! empty( $existing_feedback ) && ! empty( $existing_feedback[0][ $content_field ] ) ) {
						$existing_content = $existing_feedback[0][ $content_field ];
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
	 * @return bool True on success, false on failure.
	 */
	public function save_feedback_to_database( $feedback, $feed, $uuid, $step_type, $segment = null, $language = 'en' ) {
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
				$this->save_essay_feedback( $uuid, $feedback, $feed, $step_type, $language );
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
				$this->save_segment_feedback( $uuid, $feedback, $feed, $step_type, $segment, $language );
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
	 * @return int|WP_Error|bool ID of created/updated feedback, error, or false if skipped.
	 */
	private function save_segment_feedback( $uuid, $feedback, $feed, $step_type, $segment, $language = 'en' ) {
		// Check if segment is null - if so, return false (nothing to save).
		if ( empty( $segment ) || empty( $segment['id'] ) ) {
			return false;
		}

		// Get segment ID from segment object.
		$segment_id = $segment['id'];

		// Extract needed feed attributes.
		$feedback_criteria = isset( $feed['feedback_criteria'] ) ? $feed['feedback_criteria'] : 'general';
		$source            = isset( $feed['source'] ) ? $feed['source'] : 'ai';

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

		// Check if existing feedback already exists for this segment and criteria.
		$existing_feedback = $essay_db->get_segment_feedbacks(
			array(
				'segment_id'        => $segment_id,
				'feedback_criteria' => $feedback_criteria,
				'per_page'          => 1,
			)
		);

		// Prepare feedback data.
		$feedback_data = array(
			'segment_id'        => $segment_id,
			'feedback_criteria' => $feedback_criteria,
			'feedback_language' => $language,
			'source'            => $source,
			$content_field      => $feedback,
		);

		// If existing record found.
		if ( ! is_wp_error( $existing_feedback ) && ! empty( $existing_feedback ) ) {
			$existing = $existing_feedback[0];

			// Check if the content field for this step_type is empty.
			if ( empty( $existing[ $content_field ] ) ) {
				// Update the existing record with new content.
				$feedback_data['id'] = $existing['id'];
				return $essay_db->create_update_segment_feedback( $feedback_data );
			} else {
				// Skip saving if content already exists.
				return false;
			}
		} else {
			// No existing record, create new feedback entry.
			return $essay_db->create_update_segment_feedback( $feedback_data );
		}
	}

	/**
	 * Save essay feedback to the database
	 *
	 * @param string $uuid The UUID of the essay.
	 * @param string $feedback The feedback content to save.
	 * @param array  $feed The feed data (containing feedback criteria, etc.).
	 * @param string $step_type The type of step (chain-of-thought, scoring, feedback).
	 * @param string $language The language of the feedback.
	 * @return int|WP_Error|bool ID of created feedback, error, or false if skipped.
	 */
	private function save_essay_feedback( $uuid, $feedback, $feed, $step_type, $language = 'en' ) {
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
		$source            = isset( $feed['source'] ) ? $feed['source'] : 'ai';

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

		// Check if existing feedback already exists for this essay and criteria.
		$existing_feedback = $essay_db->get_essay_feedbacks(
			array(
				'essay_id'          => $essay_id,
				'feedback_criteria' => $feedback_criteria,
				'per_page'          => 1,
			)
		);

		// Prepare feedback data.
		$feedback_data = array(
			'essay_id'          => $essay_id,
			'feedback_criteria' => $feedback_criteria,
			'feedback_language' => $language,
			'source'            => $source,
			$content_field      => $feedback,
		);

		// If existing record found.
		if ( ! is_wp_error( $existing_feedback ) && ! empty( $existing_feedback ) ) {
			$existing = $existing_feedback[0];

			// Check if the content field for this step_type is empty.
			if ( empty( $existing[ $content_field ] ) ) {
				// Update the existing record with new content.
				$feedback_data['id'] = $existing['id'];
				return $essay_db->create_update_essay_feedback( $feedback_data );
			} else {
				// Skip saving if content already exists.
				return false;
			}
		} else {
			// No existing record, create new feedback entry.
			return $essay_db->create_update_essay_feedback( $feedback_data );
		}
	}

	/**
	 * Save segmenting results to the database
	 *
	 * @param string $uuid The UUID of the essay.
	 * @param string $feedback The feedback content.
	 * @param array  $feed The feed data.
	 * @param string $step_type The type of step being processed.
	 */
	private function save_paragraph_feedback( $uuid, $feedback, $feed, $step_type ) {
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

		// Split the feedback using the separator that was added during concatenation.
		$separator      = "\n\n---\n\n";
		$feedback_items = array_map( 'trim', explode( $separator, $feedback ) );

		// Regex to extract paragraph content from each feedback item.
		$paragraph_regex = '/#*\s*Paragraph\s*-\s*(?\'paragraph_title\'.+)\s*(?\'paragraph_content\'[\S\s]+)/m';

		// Arrays to store different segment types and track ordering.
		$all_segments  = array();
		$segment_order = 0;

		// Process each feedback item.
		foreach ( $feedback_items as $item ) {
			if ( empty( $item ) ) {
				continue;
			}

			// Extract paragraph information.
			if ( preg_match( $paragraph_regex, $item, $matches ) ) {
				$paragraph_content = trim( $matches['paragraph_content'] );

				// Extract the segments from this paragraph content.
				$segments = $this->segment_extractor->extract_segments( $paragraph_content );

				foreach ( $segments as $segment ) {
					if ( empty( $segment['title'] ) || empty( $segment['content'] ) ) {
						continue;
					}

					++$segment_order;

					$segment_type = $this->segment_extractor->determine_segment_type( $segment['title'] );

					// Add to the collection with appropriate metadata.
					$all_segments[] = array(
						'title'   => $segment['title'],
						'content' => $segment['content'],
						'type'    => $segment_type,
						'order'   => $segment_order,
					);
				}
			}
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
