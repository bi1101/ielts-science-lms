<?php
/**
 * IELTS Science LMS Speech Utilities.
 *
 * Helper utilities for building permalinks and handling speech-related helpers.
 *
 * @package IELTS_Science_LMS
 * @subpackage Speaking
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Speaking;

/**
 * Class Ieltssci_Speech_Utils
 *
 * Provides utilities specific to speech recordings.
 */
class Ieltssci_Speech_Utils {
	/**
	 * Get the result permalink for a speech by its ID.
	 *
	 * Builds a URL like speaking-result/<uuid>/ using the configured Speaking Module pages
	 * and the registered rewrite rules.
	 *
	 * Contract:
	 * - Input: $speech_id (int ID), $use_original (bool, optional, default true).
	 * - Output: Fully-qualified result permalink string, or empty string on failure.
	 *
	 * @param int  $speech_id     Speech ID.
	 * @param bool $use_original  Whether to query the original speech (true) or the latest forked result (false). Default true.
	 * @return string The result permalink URL or empty string if unavailable.
	 */
	public static function get_speech_result_permalink( $speech_id, $use_original = true ) {
		$speech_id = absint( $speech_id );
		if ( $speech_id <= 0 ) {
			return ''; // Invalid identifier.
		}

		$db = new Ieltssci_Speech_DB(); // Instantiate the Speech DB handler.

		// Try to resolve the speech row and UUID.
		$speech = null;

		if ( $use_original ) {
			$rows = $db->get_speeches(
				array(
					'id'       => $speech_id,
					'per_page' => 1,
					'page'     => 1,
				)
			); // Fetch original.
			if ( \is_wp_error( $rows ) || empty( $rows ) || ! is_array( $rows ) ) {
				return '';
			}
			$speech = is_array( $rows ) ? reset( $rows ) : $rows;
		} else {
			// Attempt to find the latest fork that references this speech via meta 'original_speech'.
			// Fallback to the original if meta-query is unsupported or returns nothing.
			$fork = $db->get_speeches(
				array(
					'meta_query' => array(
						array(
							'key'     => 'original_speech',
							'value'   => (int) $speech_id,
							'compare' => '=',
						),
					),
					'orderby'    => 'id',
					'order'      => 'DESC',
					'per_page'   => 1,
					'page'       => 1,
				)
			);
			if ( ! \is_wp_error( $fork ) && ! empty( $fork ) && is_array( $fork ) ) {
				$speech = reset( $fork );
			} else {
				// Fallback to original.
				$rows = $db->get_speeches(
					array(
						'id'       => $speech_id,
						'per_page' => 1,
						'page'     => 1,
					)
				);
				if ( \is_wp_error( $rows ) || empty( $rows ) || ! is_array( $rows ) ) {
					return '';
				}
				$speech = is_array( $rows ) ? reset( $rows ) : $rows;
			}
		}

		// Extract UUID.
		$uuid = '';
		if ( is_array( $speech ) ) {
			$uuid = isset( $speech['uuid'] ) ? (string) $speech['uuid'] : '';
		} elseif ( is_object( $speech ) ) {
			$uuid = isset( $speech->uuid ) ? (string) $speech->uuid : '';
		}

		if ( '' === $uuid ) {
			return '';
		}

		// Validate UUID format (8-4-4-4-12 hex).
		if ( empty( $uuid ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid ) ) {
			return '';
		}

		// Resolve the base page ID from plugin settings.
		$pages   = get_option( 'ielts_science_lms_pages', array() ); // Speaking module pages configuration.
		$page_id = 0;
		if ( ! empty( $pages['speaking_result'] ) ) {
			$page_id = (int) $pages['speaking_result'];
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
}
