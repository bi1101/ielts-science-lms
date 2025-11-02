<?php
/**
 * IELTS Science LMS Speaking Part Submission Utilities.
 *
 * Helper utilities for building permalinks and handling speaking part submission related helpers.
 *
 * @package IELTS_Science_LMS
 * @subpackage Speaking
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Speaking;

/**
 * Class Ieltssci_Speaking_Part_Submission_Utils
 *
 * Provides utilities specific to speaking part submissions.
 */
class Ieltssci_Speaking_Part_Submission_Utils {
	/**
	 * Get the permalink to a speaking part submission instance by its ID.
	 *
	 * Builds a URL like speaking-part-practice/<uuid>/ using the configured Speaking Module pages
	 * and the registered rewrite rules.
	 *
	 * Contract:
	 * - Input: $submission_id (int ID).
	 * - Output: Fully-qualified permalink string, or empty string on failure.
	 *
	 * @param int $submission_id Speaking part submission ID.
	 * @return string The permalink URL or empty string if unavailable.
	 */
	public static function get_part_submission_permalink( $submission_id ) {
		$submission_id = absint( $submission_id );
		if ( $submission_id <= 0 ) {
			return ''; // Invalid identifier.
		}

		// Load from DB to get the UUID.
		$db          = new Ieltssci_Submission_DB(); // Instantiate the Submission DB handler.
		$submissions = $db->get_part_submissions(
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
		} else {
			$uuid = '';
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
		if ( ! empty( $pages['speaking_part_practice'] ) ) {
			$page_id = (int) $pages['speaking_part_practice'];
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
	 * Get the result permalink for a speaking part submission by its ID.
	 *
	 * Resolves the associated speech for this part submission and delegates to the Speech Utils
	 * to build the final speaking result URL.
	 *
	 * Contract:
	 * - Input: $submission_id (int ID), $use_original (bool, optional, default true).
	 * - Output: Fully-qualified result permalink string, or empty string on failure.
	 *
	 * @param int  $submission_id Speaking part submission ID.
	 * @param bool $use_original  Whether to use the original associated speech (true) or attempt to use a forked version (false). Default true.
	 * @return string The result permalink URL or empty string if unavailable.
	 */
	public static function get_part_submission_result_permalink( $submission_id, $use_original = true ) {
		$submission_id = absint( $submission_id );
		if ( $submission_id <= 0 ) {
			return ''; // Invalid identifier.
		}

		// Load part submission to get speech_id.
		$db          = new Ieltssci_Submission_DB(); // Instantiate the Submission DB handler.
		$submissions = $db->get_part_submissions(
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

		// Extract speech_id from array or object.
		$speech_id = 0;
		if ( is_array( $submission ) ) {
			$speech_id = isset( $submission['speech_id'] ) ? absint( $submission['speech_id'] ) : 0;
		} elseif ( is_object( $submission ) ) {
			$speech_id = isset( $submission->speech_id ) ? absint( $submission->speech_id ) : 0;
		}

		if ( $speech_id <= 0 ) {
			return ''; // No speech linked to this submission.
		}

		// Delegate to speech utils to build the result permalink.
		return Ieltssci_Speech_Utils::get_speech_result_permalink( $speech_id, $use_original );
	}
}
