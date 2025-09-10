<?php
/**
 * IELTS Science LMS Task Submission Utilities
 *
 * Helper utilities for building permalinks and handling task submission related helpers.
 *
 * @package IELTS_Science_LMS
 * @subpackage Writing
 * @since 1.0.0
 * @version 1.0.0
 */

namespace IeltsScienceLMS\Writing;

/**
 * Class Ieltssci_Task_Submission_Utils
 *
 * Provides utilities specific to task submissions.
 */
class Ieltssci_Task_Submission_Utils {
	/**
	 * Get the permalink to a task submission instance by its ID.
	 *
	 * Builds a URL like writing-task-practice/<uuid>/ using the configured Writing Module pages
	 * and the registered rewrite rules.
	 *
	 * Contract:
	 * - Input: $submission_id (int ID).
	 * - Output: Fully-qualified permalink string, or empty string on failure.
	 *
	 * @param int $submission_id Task submission ID.
	 * @return string The permalink URL or empty string if unavailable.
	 */
	public static function get_task_submission_permalink( $submission_id ) {
		$submission_id = absint( $submission_id );
		if ( $submission_id <= 0 ) {
			return ''; // Invalid identifier.
		}

		// Load from DB to get the UUID.
		$db          = new Ieltssci_Submission_DB(); // Instantiate the Submission DB handler.
		$submissions = $db->get_task_submissions(
			array(
				'id'     => $submission_id,
				'number' => 1,
				'offset' => 0,
			)
		); // Retrieve submission.
		if ( \is_wp_error( $submissions ) || empty( $submissions ) ) {
			return '';
		}

		$submission = is_array( $submissions ) ? reset( $submissions ) : $submissions; // Get the first.
		if ( is_array( $submission ) ) {
			$uuid = isset( $submission['uuid'] ) ? (string) $submission['uuid'] : '';
		} elseif ( is_object( $submission ) ) {
			$uuid = isset( $submission->uuid ) ? (string) $submission->uuid : '';
		}

		if ( '' === $uuid ) {
			return '';
		}

		// Validate UUID format.
		if ( empty( $uuid ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid ) ) {
			return '';
		}

		// Resolve the base page ID from plugin settings.
		$pages   = get_option( 'ielts_science_lms_pages', array() ); // Get module pages configuration.
		$page_id = 0;
		if ( ! empty( $pages['writing_task_practice'] ) ) {
			$page_id = (int) $pages['writing_task_practice'];
		}

		if ( $page_id <= 0 ) {
			return '';
		}

		$base = get_permalink( $page_id ); // Get base permalink.
		if ( empty( $base ) ) {
			return '';
		}

		// Append UUID and trailing slash per rewrite structure.
		$url = trailingslashit( $base ) . rawurlencode( $uuid ); // Append encoded UUID.
		$url = trailingslashit( $url ); // Ensure trailing slash per site structure.

		return esc_url( $url ); // Return sanitized URL.
	}

	/**
	 * Get the result permalink for a task submission by its ID.
	 *
	 * Fetches the task submission to get its essay_id, then uses that to build
	 * the essay result permalink using the Essay Utils class.
	 *
	 * Contract:
	 * - Input: $submission_id (int ID).
	 * - Output: Fully-qualified result permalink string, or empty string on failure.
	 *
	 * @param int $submission_id Task submission ID.
	 * @return string The result permalink URL or empty string if unavailable.
	 */
	public static function get_task_submission_result_permalink( $submission_id ) {
		$submission_id = absint( $submission_id );
		if ( $submission_id <= 0 ) {
			return ''; // Invalid identifier.
		}

		// Build query args for the DB fetch.
		$args = array(
			'number' => 1,
			'offset' => 0,
		); // Limit to a single record.

		$args['id'] = $submission_id; // Query by ID.

		// Load task submission from DB to get the essay_id.
		$db          = new Ieltssci_Submission_DB(); // Instantiate the Submission DB handler.
		$submissions = $db->get_task_submissions( $args ); // Retrieve submission.
		if ( \is_wp_error( $submissions ) || empty( $submissions ) ) {
			return ''; // On error or not found, return empty string.
		}

		$submission = is_array( $submissions ) ? reset( $submissions ) : $submissions; // Get the first.

		// Extract essay_id from array or object.
		$essay_id = 0;
		if ( is_array( $submission ) ) {
			$essay_id = isset( $submission['essay_id'] ) ? absint( $submission['essay_id'] ) : 0;
		} elseif ( is_object( $submission ) ) {
			$essay_id = isset( $submission->essay_id ) ? absint( $submission->essay_id ) : 0;
		}

		if ( $essay_id <= 0 ) {
			return ''; // No essay linked to this submission.
		}

		// Use the Essay Utils class to get the result permalink.
		return Ieltssci_Essay_Utils::get_essay_result_permalink( $essay_id );
	}
}
