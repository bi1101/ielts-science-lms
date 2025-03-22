<?php
/**
 * Segment Extractor for Writing Module
 *
 * @package IeltsScienceLMS
 * @subpackage Writing
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Writing;

/**
 * Class Ieltssci_Segment_Extractor
 *
 * Handles the extraction of segments from IELTS writing tasks.
 */
class Ieltssci_Segment_Extractor {

	/**
	 * Constructor
	 */
	public function __construct() {
	}

	/**
	 * Extract segments from a text.
	 *
	 * @param string $paragraph_content The text to extract segments from.
	 * @return array An array of extracted segments.
	 */
	public function extract_segments( $paragraph_content ) {
		$segments = array();

		// Regex to extract segments like Introduction, Topic Sentence, Main Point, Conclusion, etc.
		$segment_regex = '/#*\s*(?\'segment_title\'Introduction|Topic Sentence|Main Point \d+|Conclusion|Body Paragraph \d+)\s*(?\'segment_content\'[\s\S]*?)(?=\s*#|\s*$)/i';

		if ( preg_match_all( $segment_regex, $paragraph_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$title   = trim( $match['segment_title'] );
				$content = trim( $match['segment_content'] );

				// Skip if either title or content is empty.
				if ( empty( $title ) || empty( $content ) ) {
					continue;
				}

				// Clean up content - remove any markdown formatting.
				$content = preg_replace( '/^[\s\*\-]+/m', '', $content ); // Remove list markers.
				$content = preg_replace( '/#{1,6}\s+/m', '', $content ); // Remove headers.
				$content = trim( $content );

				$segments[] = array(
					'title'   => $title,
					'content' => $content,
				);
			}
		} else {
			// Fallback: if no segments were found, treat the entire content as a single segment.
			// Try to extract a title from the first line.
			$lines      = explode( "\n", $paragraph_content );
			$first_line = trim( $lines[0] );

			// If the first line looks like a title (short, doesn't end with period).
			if ( strlen( $first_line ) < 100 && ! preg_match( '/\.$/', $first_line ) ) {
				$title   = $first_line;
				$content = trim( substr( $paragraph_content, strlen( $first_line ) ) );
			} else {
				// Default title based on paragraph content.
				$title   = 'Paragraph';
				$content = trim( $paragraph_content );
			}

			$segments[] = array(
				'title'   => $title,
				'content' => $content,
			);
		}

		return $segments;
	}

	/**
	 * Determine segment type based on segment title
	 *
	 * @param string $segment_title The title of the segment.
	 * @return string The segment type.
	 */
	public function determine_segment_type( $segment_title ) {
		$title_lower = strtolower( $segment_title );

		if ( false !== strpos( $title_lower, 'introduction' ) ) {
			return 'introduction';
		} elseif ( false !== strpos( $title_lower, 'conclusion' ) ) {
			return 'conclusion';
		} elseif ( false !== strpos( $title_lower, 'topic sentence' ) ) {
			return 'topic-sentence';
		} elseif ( false !== strpos( $title_lower, 'main point' ) ) {
			return 'main-point';
		} else {
			return 'unknown';
		}
	}
}
