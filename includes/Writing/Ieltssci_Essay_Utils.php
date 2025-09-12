<?php
/**
 * IELTS Science LMS Essay Utilities
 *
 * This file contains utility functions for IELTS essays,
 * including validation, formatting, and helper methods.
 *
 * @package IELTS_Science_LMS
 * @subpackage IeltsScienceLMS\Writing
 */

namespace IeltsScienceLMS\Writing;

/**
 * Class Ieltssci_Essay_Utils
 *
 * Provides utility functions for IELTS essay.
 *
 * @since 0.9.21
 */
class Ieltssci_Essay_Utils {
	/**
	 * Get the permalink to an essay's result page by essay ID.
	 *
	 * Determines the essay type and UUID using the database class, then
	 * generates a URL like result_task_2/<uuid>/ or result_task_1/<uuid>/ based on
	 * the configured Writing Module result pages and registered rewrite rules.
	 *
	 * Contract:
	 * - Input: $essay_id (int ID), $use_original (bool, optional, default false).
	 * - Output: Fully-qualified permalink string, or empty string on failure.
	 *
	 * @param int  $essay_id    Essay ID.
	 * @param bool $use_original Whether to query the original essay (true) or the final/cloned result (false). Default false.
	 * @return string The permalink URL or empty string if unavailable.
	 */
	public static function get_essay_result_permalink( $essay_id, $use_original = true ) {
		$essay_id = absint( $essay_id );
		if ( $essay_id <= 0 ) {
			return ''; // Invalid identifier.
		}

		// Build query args for the DB fetch.
		$args = array(
			'per_page' => 1,
			'page'     => 1,
		); // Limit to a single record.

		// Set the query key based on whether to use original or final essay.
		if ( $use_original ) {
			$args['id'] = $essay_id; // Query by original ID to get the final essay.
		} else {
			$args['original_id'] = $essay_id; // Query by final/cloned ID.
		}

		// Fetch essay from DB.
		$db     = new Ieltssci_Essay_DB(); // Instantiate the Essay DB handler.
		$essays = $db->get_essays( $args ); // Retrieve essay data.
		if ( \is_wp_error( $essays ) ) {
			return ''; // On error, return empty string.
		}

		if ( empty( $essays ) || ! is_array( $essays ) ) {
			return ''; // Nothing found.
		}

		$essay = reset( $essays ); // Take the first essay.

		// Extract properties from array or object.
		$essay_type = '';
		$uuid       = '';
		if ( is_array( $essay ) ) {
			$essay_type = isset( $essay['essay_type'] ) ? (string) $essay['essay_type'] : '';
			$uuid       = isset( $essay['uuid'] ) ? (string) $essay['uuid'] : '';
		} elseif ( is_object( $essay ) ) {
			$essay_type = isset( $essay->essay_type ) ? (string) $essay->essay_type : '';
			$uuid       = isset( $essay->uuid ) ? (string) $essay->uuid : '';
		}

		if ( '' === $essay_type || '' === $uuid ) {
			return ''; // Missing required fields.
		}

		return self::build_result_permalink_from_type_uuid( $essay_type, $uuid ); // Delegate to URL builder.
	}

	/**
	 * Build a result permalink from essay type and UUID.
	 *
	 * @param string $essay_type Essay type such as 'task_1' or 'task_2'.
	 * @param string $uuid       The essay UUID (8-4-4-4-12 hex format).
	 * @return string The permalink URL or empty string if unavailable.
	 */
	private static function build_result_permalink_from_type_uuid( $essay_type, $uuid ) {
		// Normalize inputs.
		$essay_type = strtolower( trim( (string) $essay_type ) ); // Normalize essay type to lowercase.
		$uuid       = trim( (string) $uuid ); // Trim UUID value.

		// Validate UUID (8-4-4-4-12 hex format).
		if ( empty( $uuid ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid ) ) {
			return '';
		}

		// Map essay type to the configured result page key using inclusive matching.
		$page_key   = '';
		$etype_norm = $essay_type; // Already normalized to lowercase above.
		if (
			false !== strpos( $etype_norm, 'task-2' ) ||
			false !== strpos( $etype_norm, 'task2' ) ||
			false !== strpos( $etype_norm, 'task_2' )
		) {
			$page_key = 'result_task_2';
		} elseif (
			false !== strpos( $etype_norm, 'task-1' ) ||
			false !== strpos( $etype_norm, 'task1' ) ||
			false !== strpos( $etype_norm, 'task_1' )
		) {
			$page_key = 'result_task_1';
		} else {
			return ''; // Only task 1 and task 2 types are supported here.
		}

		// Retrieve configured Writing Module pages.
		$pages = get_option( 'ielts_science_lms_pages', array() ); // Get module pages configuration.
		if ( empty( $pages ) || empty( $pages[ $page_key ] ) ) {
			return '';
		}

		// Build permalink and append UUID according to the rewrite rule.
		$base = get_permalink( (int) $pages[ $page_key ] ); // Get the base result page permalink.
		if ( empty( $base ) ) {
			return '';
		}

		// Ensure trailing slash on base, then append UUID and trailing slash.
		$url = trailingslashit( $base ) . rawurlencode( $uuid ); // Append encoded UUID.
		$url = trailingslashit( $url ); // Ensure trailing slash per site structure.

		return esc_url( $url ); // Return sanitized URL.
	}
}
