<?php
/**
 * IELTS Science LMS Test Submission Utilities
 *
 * Helper utilities for building permalinks and handling test submission related helpers.
 *
 * @package IELTS_Science_LMS
 * @subpackage Writing
 * @since 1.0.0
 * @version 1.0.0
 */

namespace IeltsScienceLMS\Writing;

/**
 * Class Ieltssci_Test_Submission_Utils
 *
 * Provides utilities specific to test submissions.
 */
class Ieltssci_Test_Submission_Utils {
	/**
	 * Get the permalink to a test submission instance by its ID.
	 *
	 * Builds a URL like writing-test-practice/<uuid>/ using the configured Writing Module pages
	 * and the registered rewrite rules.
	 *
	 * Contract:
	 * - Input: $submission_id (int ID).
	 * - Output: Fully-qualified permalink string, or empty string on failure.
	 *
	 * @param int $submission_id Test submission ID.
	 * @return string The permalink URL or empty string if unavailable.
	 */
	public static function get_test_submission_permalink( $submission_id ) {
		$submission_id = absint( $submission_id );
		if ( $submission_id <= 0 ) {
			return '';
		}

		// Load from DB to get the UUID.
		$db          = new Ieltssci_Submission_DB();
		$submissions = $db->get_test_submissions(
			array(
				'id'     => $submission_id,
				'number' => 1,
				'offset' => 0,
			)
		);
		if ( \is_wp_error( $submissions ) || empty( $submissions ) ) {
			return '';
		}

		$submission = is_array( $submissions ) ? reset( $submissions ) : $submissions;
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
		$pages   = get_option( 'ielts_science_lms_pages', array() );
		$page_id = 0;
		if ( ! empty( $pages['writing_test_practice'] ) ) {
			$page_id = (int) $pages['writing_test_practice'];
		}

		if ( $page_id <= 0 ) {
			return '';
		}

		$base = get_permalink( $page_id );
		if ( empty( $base ) ) {
			return '';
		}

		// Append UUID and trailing slash per rewrite structure.
		$url = trailingslashit( $base ) . rawurlencode( $uuid );
		$url = trailingslashit( $url );

		return esc_url( $url );
	}

	/**
	 * Get the result permalink for a test submission by its ID.
	 *
	 * Builds a URL like result-writing-test/<uuid>/ using the configured Writing Module pages
	 * and the registered rewrite rules.
	 *
	 * Contract:
	 * - Input: $submission_id (int ID).
	 * - Output: Fully-qualified result permalink string, or empty string on failure.
	 *
	 * @param int $submission_id Test submission ID.
	 * @return string The result permalink URL or empty string if unavailable.
	 */
	public static function get_test_submission_result_permalink( $submission_id ) {
		$submission_id = absint( $submission_id );
		if ( $submission_id <= 0 ) {
			return '';
		}

		// Load from DB to get the UUID.
		$db          = new Ieltssci_Submission_DB();
		$submissions = $db->get_test_submissions(
			array(
				'id'     => $submission_id,
				'number' => 1,
				'offset' => 0,
			)
		);
		if ( \is_wp_error( $submissions ) || empty( $submissions ) ) {
			return '';
		}

		$submission = is_array( $submissions ) ? reset( $submissions ) : $submissions;
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
		$pages   = get_option( 'ielts_science_lms_pages', array() );
		$page_id = 0;
		if ( ! empty( $pages['result_writing_test'] ) ) {
			$page_id = (int) $pages['result_writing_test'];
		}

		if ( $page_id <= 0 ) {
			return '';
		}

		$base = get_permalink( $page_id );
		if ( empty( $base ) ) {
			return '';
		}

		// Append UUID and trailing slash per rewrite structure.
		$url = trailingslashit( $base ) . rawurlencode( $uuid );
		$url = trailingslashit( $url );

		return esc_url( $url );
	}
}
