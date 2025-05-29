<?php
/**
 * Merge Tags Processor
 *
 * @package IeltsScienceLMS
 * @subpackage MergeTags
 * @since 1.0.0
 */

namespace IeltsScienceLMS\MergeTags;

use IeltsScienceLMS\Writing\Ieltssci_Essay_DB;
use IeltsScienceLMS\Speaking\Ieltssci_Speech_DB;

/**
 * Class Ieltssci_Merge_Tags_Processor
 *
 * Handles the processing of merge tags for IELTS Science modules.
 * Responsible for processing content with merge tags and applying modifiers.
 */
class Ieltssci_Merge_Tags_Processor {

	/**
	 * Process merge tags in a prompt string
	 *
	 * @param string $prompt       The prompt string containing merge tags.
	 * @param string $uuid         The UUID of the essay to use for fetching content.
	 * @param int    $segment_order Optional. The order of the segment to filter by.
	 * @param string $feedback_style The feedback style to use.
	 * @param string $guide_score   Human-guided scoring for the AI to consider.
	 * @param string $guide_feedback Human-guided feedback content for the AI to incorporate.
	 * @return string|array The processed prompt with merge tags replaced, or an array if a modifier results in an array.
	 */
	public function process_merge_tags( $prompt, $uuid, $segment_order = null, $feedback_style = '', $guide_score = '', $guide_feedback = '' ) {
		// Regex to find merge tags in format {prefix|parameters|suffix}.
		$regex = '/\{(?\'prefix\'.*?)\|(?\'parameters\'.*?)\|(?\'suffix\'.*?)\}/ms';

		// Find all merge tags in the prompt.
		preg_match_all( $regex, $prompt, $matches, PREG_SET_ORDER, 0 );

		// First scan: identify all array-producing merge tags and their contents.
		$array_tags       = array();
		$max_array_length = 0;

		foreach ( $matches as $match ) {
			$full_tag   = $match[0];
			$parameters = $match['parameters'];

			// Special cases for parameter-based replacements.
			if ( 'feedback_style' === trim( $parameters ) && ! empty( $feedback_style ) ) {
					$content = $feedback_style;
			} elseif ( 'guide_score' === trim( $parameters ) && ! empty( $guide_score ) ) {
					$content = $guide_score;
			} elseif ( 'guide_feedback' === trim( $parameters ) && ! empty( $guide_feedback ) ) {
					$content = $guide_feedback;
			} else {
				// Standard case: fetch content based on parameters.
				$content = $this->fetch_content_for_merge_tag( $parameters, $uuid, $segment_order );
			}

			if ( is_array( $content ) ) {
				$array_tags[ $full_tag ] = array(
					'prefix'  => $match['prefix'],
					'suffix'  => $match['suffix'],
					'content' => $content,
				);

				// Track the length of the longest array.
				$max_array_length = max( $max_array_length, count( $content ) );
			}
		}

		// If no array-returning tags found, process normally.
		if ( empty( $array_tags ) ) {
			// Process each merge tag with standard replacement.
			foreach ( $matches as $match ) {
				$full_tag   = $match[0];
				$prefix     = $match['prefix'];
				$parameters = $match['parameters'];
				$suffix     = $match['suffix'];

				// Special cases for parameter-based replacements.
				if ( 'feedback_style' === trim( $parameters ) && ! empty( $feedback_style ) ) {
					$content = $feedback_style;
				} elseif ( 'guide_score' === trim( $parameters ) && ! empty( $guide_score ) ) {
					$content = $guide_score;
				} elseif ( 'guide_feedback' === trim( $parameters ) && ! empty( $guide_feedback ) ) {
					$content = $guide_feedback;
				} else {
					// Standard case: fetch content based on parameters.
					$content = $this->fetch_content_for_merge_tag( $parameters, $uuid, $segment_order );
				}

				// For non-array content, standard replacement.
				$replacement = empty( $content ) ? '' : "{$prefix}{$content}{$suffix}";
				$prompt      = str_replace( $full_tag, $replacement, $prompt );
			}

			return $prompt;
		}

		// If we have array tags, create parallel variants.
		$variants = array();

		// Determine the minimum array length to use for parallel processing.
		$min_array_length = PHP_INT_MAX;
		foreach ( $array_tags as $tag_data ) {
			$min_array_length = min( $min_array_length, count( $tag_data['content'] ) );
		}

		// Create one variant for each position up to min_array_length.
		for ( $i = 0; $i < $min_array_length; $i++ ) {
			$variant = $prompt;

			// Replace each array tag with its corresponding item at position $i.
			foreach ( $array_tags as $full_tag => $tag_data ) {
				$replacement = "{$tag_data['prefix']}{$tag_data['content'][ $i ]}{$tag_data['suffix']}";
				$variant     = str_replace( $full_tag, $replacement, $variant );
			}

			// Now process any remaining standard (non-array) merge tags.
			foreach ( $matches as $match ) {
				$full_tag = $match[0];

				// Skip if this tag is an array tag that we've already processed.
				if ( isset( $array_tags[ $full_tag ] ) ) {
					continue;
				}

				$prefix     = $match['prefix'];
				$parameters = $match['parameters'];
				$suffix     = $match['suffix'];

				// Special cases for parameter-based replacements.
				if ( 'feedback_style' === trim( $parameters ) && ! empty( $feedback_style ) ) {
					$content = $feedback_style;
				} elseif ( 'guide_score' === trim( $parameters ) && ! empty( $guide_score ) ) {
					$content = $guide_score;
				} elseif ( 'guide_feedback' === trim( $parameters ) && ! empty( $guide_feedback ) ) {
					$content = $guide_feedback;
				} else {
					// Standard case: fetch content based on parameters.
					$content = $this->fetch_content_for_merge_tag( $parameters, $uuid, $segment_order );
				}

				$replacement = empty( $content ) ? '' : "{$prefix}{$content}{$suffix}";

				$variant = str_replace( $full_tag, $replacement, $variant );
			}

			$variants[] = $variant;
		}

		return $variants;
	}

	/**
	 * Fetch content for a merge tag based on parameters
	 *
	 * @param string $parameters    The parameters specifying what content to fetch.
	 * @param string $uuid          The UUID of the essay.
	 * @param int    $segment_order Optional. The order of the segment to filter by.
	 * @return array|string|null The content to replace the merge tag with, or null if not found.
	 */
	private function fetch_content_for_merge_tag( $parameters, $uuid, $segment_order = null ) {
		// Regex to extract parameter components:
		// table:field[filter_field:filter_value]:modifier.
		$regex = '/(?\'table\'.*?):(?\'field\'[^:\[]+)(?:\[(?\'filter_field\'.*?):(?\'filter_value\'.*?)\])?(?::(?\'modifier\'.*))?/m';

		// Initialize content as empty.
		$content = null;

		if ( preg_match( $regex, $parameters, $match ) ) {
			// Extract components from the parameters.
			$table        = isset( $match['table'] ) ? trim( $match['table'] ) : '';
			$field        = isset( $match['field'] ) ? trim( $match['field'] ) : '';
			$filter_field = isset( $match['filter_field'] ) ? trim( $match['filter_field'] ) : '';
			$filter_value = isset( $match['filter_value'] ) ? trim( $match['filter_value'] ) : '';
			$modifier     = isset( $match['modifier'] ) ? trim( $match['modifier'] ) : '';

			// Special case: if filter_value is 'uuid', use the provided UUID.
			if ( 'uuid' === $filter_value ) {
				$filter_value = $uuid;
			}

			// Get content based on extracted parameters.
			$content = $this->get_content_from_database( $table, $field, $filter_field, $filter_value, $uuid, $segment_order );

			// Apply modifier if present and content is not empty.
			if ( ! empty( $content ) && ! empty( $modifier ) ) {
				$content = $this->apply_content_modifier( $content, $modifier );
			}
		}

		return $content;
	}

	/**
	 * Get content from database based on parameters
	 *
	 * @param string $table         The table/source to fetch from.
	 * @param string $field         The field to retrieve.
	 * @param string $filter_field  The field to filter by.
	 * @param string $filter_value  The value to filter with.
	 * @param string $uuid          The UUID of the essay (always required).
	 * @param int    $segment_order Optional. The order of the segment to filter by.
	 * @return string|array|null The retrieved content, array of values, or null if not found.
	 */
	public function get_content_from_database( $table, $field, $filter_field, $filter_value, $uuid, $segment_order = null ) {
		// Skip if any required parameter is missing.
		if ( empty( $table ) || empty( $field ) || empty( $uuid ) ) {
			return null;
		}

		// List of supported tables.
		$supported_tables = array(
			'essay',
			'segment',
			'essay_feedback',
			'segment_feedback',
			'speech',
			'speech_feedback',
		);

		if ( ! in_array( $table, $supported_tables, true ) ) {
			return null;
		}

		// Handle speech tables.
		if ( in_array( $table, array( 'speech', 'speech_feedback' ), true ) ) {
			return $this->get_speech_content_from_database( $table, $field, $filter_field, $filter_value, $uuid );
		}

		// Initialize Essay DB.
		$essay_db = new Ieltssci_Essay_DB();

		// Handle different tables with their specific query methods.
		switch ( $table ) {
			case 'essay':
				// For essay table.
				$query_args = array();

				// Always include UUID filter for essay table.
				$query_args['uuid'] = $uuid;

				// Add additional filter if provided.
				if ( ! empty( $filter_field ) && ! empty( $filter_value ) && 'uuid' !== $filter_field ) {
					$query_args[ $filter_field ] = $filter_value;
				}

				// Add fields filter if it's not "all fields" query.
				if ( '*' !== $field ) {
					$query_args['fields'] = array( $field );
				}

				$essays = $essay_db->get_essays( $query_args );
				if ( ! is_wp_error( $essays ) && ! empty( $essays ) ) {
					if ( 1 === count( $essays ) ) {
						// Return just the specific field for single result.
						return isset( $essays[0][ $field ] ) ? $essays[0][ $field ] : null;
					} else {
						// For multiple results, create an array of field values.
						$values = array_column( $essays, $field );
						return ! empty( $values ) ? $values : null;
					}
				}
				break;

			case 'segment':
				// For segment table, get essay_id first.
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

				// Build query for segments.
				$query_args = array(
					'essay_id' => $essay_id,
				);

				// Add segment_order filter if provided.
				if ( null !== $segment_order ) {
					$query_args['order'] = $segment_order;
				}

				// Add additional filter if provided.
				if ( ! empty( $filter_field ) && ! empty( $filter_value ) ) {
					$query_args[ $filter_field ] = $filter_value;
				}

				// Add fields filter.
				if ( '*' !== $field ) {
					$query_args['fields'] = array( $field );
				}

				$segments = $essay_db->get_segments( $query_args );
				if ( ! is_wp_error( $segments ) && ! empty( $segments ) ) {
					if ( 1 === count( $segments ) ) {
						// Return just the specific field for single result.
						return isset( $segments[0][ $field ] ) ? $segments[0][ $field ] : null;
					} else {
						// For multiple results, create an array of field values.
						$values = array_column( $segments, $field );
						return ! empty( $values ) ? $values : null;
					}
				}
				break;

			case 'essay_feedback':
				// For essay_feedback table, get essay_id first.
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

				// Build query for feedbacks.
				$query_args = array(
					'essay_id' => $essay_id,
				);

				// Add additional filter if provided.
				if ( ! empty( $filter_field ) && ! empty( $filter_value ) ) {
					$query_args[ $filter_field ] = $filter_value;
				}

				// Add fields filter.
				if ( '*' !== $field ) {
					$query_args['fields'] = array( $field );
				}

				$feedbacks = $essay_db->get_essay_feedbacks( $query_args );
				if ( ! is_wp_error( $feedbacks ) && ! empty( $feedbacks ) ) {
					// Filter out feedbacks where the required field is empty.
					$filtered_feedbacks = array_filter(
						$feedbacks,
						function ( $feedback ) use ( $field ) {
							return isset( $feedback[ $field ] ) && ! empty( $feedback[ $field ] );
						}
					);

					// If no feedbacks remain after filtering, return null.
					if ( empty( $filtered_feedbacks ) ) {
						return null;
					}

					// Re-index the array.
					$filtered_feedbacks = array_values( $filtered_feedbacks );

					return $filtered_feedbacks[0][ $field ];
				}
				break;

			case 'segment_feedback':
				// For segment_feedback table, get essay_id first.
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

				// Get segments for this essay with optional segment_order filter.
				$segment_query = array(
					'essay_id' => $essay_id,
					'fields'   => array( 'id' ),
				);

				if ( null !== $segment_order ) {
					$segment_query['order'] = $segment_order;
				}

				$segments = $essay_db->get_segments( $segment_query );

				if ( is_wp_error( $segments ) || empty( $segments ) ) {
					return null;
				}

				// Process segment feedbacks one by one to get exactly one feedback per segment.
				$collected_feedback_content = array();

				// For each segment, get only one feedback.
				foreach ( $segments as $segment ) {
					$segment_id = $segment['id'];

					// Build query for this segment's feedback.
					$query_args = array(
						'segment_id' => $segment_id,
					);

					// Add additional filter if provided.
					if ( ! empty( $filter_field ) && ! empty( $filter_value ) ) {
						$query_args[ $filter_field ] = $filter_value;
					}

					// Add fields filter.
					if ( '*' !== $field ) {
						$query_args['fields'] = array( $field );
					}

					// Get all feedback for this segment.
					$segment_feedback = $essay_db->get_segment_feedbacks( $query_args );

					// If we found any feedback, filter for non-empty content.
					if ( ! is_wp_error( $segment_feedback ) && ! empty( $segment_feedback ) ) {
						// Filter feedbacks where the requested field has valid content.
						$filtered_feedback = array_filter(
							$segment_feedback,
							function ( $feedback ) use ( $field ) {
								return isset( $feedback[ $field ] ) && ! empty( $feedback[ $field ] );
							}
						);

						// If we have any valid feedback after filtering, use the first one.
						if ( ! empty( $filtered_feedback ) ) {
							$filtered_feedback            = array_values( $filtered_feedback );
							$collected_feedback_content[] = $filtered_feedback[0][ $field ];
						}
					}
				}

				// If no valid feedbacks were found, return null.
				if ( empty( $collected_feedback_content ) ) {
					return null;
				}

				// If segment_order was specified, we should have only one result.
				// Or if we happened to get just one feedback anyway.
				if ( null !== $segment_order || 1 === count( $collected_feedback_content ) ) {
					return $collected_feedback_content[0]; // Return just the first/only value.
				} else {
					// Return all collected feedback content as an array.
					return $collected_feedback_content;
				}
		}

		// If we get here, no results were found.
		return null;
	}

	/**
	 * Get speech content from database based on parameters
	 *
	 * @param string $table         The table to fetch from (speech or speech_feedback).
	 * @param string $field         The field to retrieve.
	 * @param string $filter_field  The field to filter by.
	 * @param string $filter_value  The value to filter with.
	 * @param string $uuid          The UUID of the speech recording.
	 * @return string|array|null The retrieved content, array of values, or null if not found.
	 */
	private function get_speech_content_from_database( $table, $field, $filter_field, $filter_value, $uuid ) {
		// Initialize Speech DB.
		$speech_db = new Ieltssci_Speech_DB();

		switch ( $table ) {
			case 'speech':
				// For speech table.
				$query_args = array();

				// Always include UUID filter for speech table.
				$query_args['uuid'] = $uuid;

				// Add additional filter if provided.
				if ( ! empty( $filter_field ) && ! empty( $filter_value ) && 'uuid' !== $filter_field ) {
					$query_args[ $filter_field ] = $filter_value;
				}

				$speeches = $speech_db->get_speeches( $query_args );
				if ( ! is_wp_error( $speeches ) && ! empty( $speeches ) ) {
					if ( 1 === count( $speeches ) ) {
						// Handle special case for transcript field.
						if ( 'transcript' === $field || 'transcript_text' === $field ) {
							if ( isset( $speeches[0]['transcript'] ) && is_array( $speeches[0]['transcript'] ) ) {
								// For transcript_text, return just the text content.
								if ( 'transcript_text' === $field ) {
									$texts = array();
									foreach ( $speeches[0]['transcript'] as $attachment_id => $transcript ) {
										if ( isset( $transcript['text'] ) ) {
											$texts[] = $transcript['text'];
										}
									}
									return implode( "\n\n", $texts );
								}
								return $speeches[0]['transcript'];
							}
							return null;
						}

						// Return just the specific field for single result.
						return isset( $speeches[0][ $field ] ) ? $speeches[0][ $field ] : null;
					} else {
						// For multiple results, create an array of field values.
						$values = array_column( $speeches, $field );
						return ! empty( $values ) ? $values : null;
					}
				}
				break;

			case 'speech_feedback':
				// For speech_feedback table, get speech_id first.
				$speeches = $speech_db->get_speeches(
					array(
						'uuid'     => $uuid,
						'per_page' => 1,
						'fields'   => array( 'id' ),
					)
				);
				if ( is_wp_error( $speeches ) || empty( $speeches ) ) {
					return null;
				}
				$speech_id = $speeches[0]['id'];

				// Build query for feedbacks.
				$query_args = array(
					'speech_id' => $speech_id,
				);

				// Add additional filter if provided.
				if ( ! empty( $filter_field ) && ! empty( $filter_value ) ) {
					$query_args[ $filter_field ] = $filter_value;
				}

				$feedbacks = $speech_db->get_speech_feedbacks( $query_args );
				if ( ! is_wp_error( $feedbacks ) && ! empty( $feedbacks ) ) {
					// Filter out feedbacks where the required field is empty.
					$filtered_feedbacks = array_filter(
						$feedbacks,
						function ( $feedback ) use ( $field ) {
							return isset( $feedback[ $field ] ) && ! empty( $feedback[ $field ] );
						}
					);

					// If no feedbacks remain after filtering, return null.
					if ( empty( $filtered_feedbacks ) ) {
						return null;
					}

					// Re-index the array.
					$filtered_feedbacks = array_values( $filtered_feedbacks );

					// Return just the first result's field value.
					return $filtered_feedbacks[0][ $field ];
				}
				break;
		}

		return null;
	}

	/**
	 * Apply modifier to content
	 *
	 * @param string|array $content Original content (string or array).
	 * @param string       $modifier Modifier to apply.
	 * @return string|array Modified content, can return array for certain modifiers.
	 */
	private function apply_content_modifier( $content, $modifier ) {

		// Special handling for flatten modifier (standalone or as part of compound).
		if ( 'flatten' === $modifier && is_array( $content ) ) {
			return implode( "\n\n---\n\n", $content );
		}

		// Check for compound modifiers containing flatten.
		if ( false !== strpos( $modifier, ':' ) && false !== strpos( $modifier, 'flatten' ) ) {
			$modifiers = explode( ':', $modifier );

			// Process each modifier in sequence.
			foreach ( $modifiers as $mod ) {
				if ( 'flatten' === $mod && is_array( $content ) ) {
					$content = implode( "\n\n---\n\n", $content );
				} else {
					$content = $this->apply_content_modifier( $content, $mod );
				}
			}
			return $content;
		}

		// If content is an array, apply modifier to each item in the array.
		if ( is_array( $content ) ) {
			$result = array();
			foreach ( $content as $item ) {
				// Apply modifier to each item.
				$modified_item = $this->apply_content_modifier( $item, $modifier );

				// If the result is an array (e.g., from sentence or paragraph modifier).
				// flatten it to maintain consistent depth.
				if ( is_array( $modified_item ) ) {
					$result = array_merge( $result, $modified_item );
				} else {
					$result[] = $modified_item;
				}
			}
			return $result;
		}

		// Process single string content.
		switch ( $modifier ) {
			case 'uppercase':
				return strtoupper( $content );

			case 'lowercase':
				return strtolower( $content );

			case 'capitalize':
				return ucwords( $content );

			case 'trim':
				return trim( $content );

			case 'html_entity_decode':
				return html_entity_decode( $content );

			case 'sentence':
				// Split content into sentences.
				$sentences = $this->split_into_sentences( $content );
				if ( empty( $sentences ) ) {
					return array( 'No content available.' );
				}
				return $sentences;

			case 'paragraph':
				// Split content into paragraphs.
				$paragraphs = $this->split_into_paragraphs( $content );
				if ( empty( $paragraphs ) ) {
					return array( 'No content available.' );
				}
				return $paragraphs;

			default:
				// If no valid modifier is found, return the original content.
				return $content;
		}
	}

	/**
	 * Split text into sentences
	 *
	 * @param string $text The text to split.
	 * @return array Array of sentences.
	 */
	private function split_into_sentences( $text ) {
		if ( empty( $text ) ) {
			return array();
		}

		// Clean the text first.
		$text = trim( $text );

		// Split by common sentence delimiters: period, exclamation mark, question mark.
		$pattern   = '/(?<=[.!?])\s+(?=[A-Z])/';
		$sentences = preg_split( $pattern, $text );

		// Filter out empty sentences and trim each sentence.
		$sentences = array_filter( array_map( 'trim', $sentences ) );

		// Return indexed array (not associative).
		return array_values( $sentences );
	}

	/**
	 * Split text into paragraphs
	 *
	 * @param string $text The text to split.
	 * @return array Array of paragraphs.
	 */
	private function split_into_paragraphs( $text ) {
		if ( empty( $text ) ) {
			return array();
		}

		// Clean the text first.
		$text = trim( $text );

		// Normalize line endings.
		$text = str_replace( "\r\n", "\n", $text );
		$text = str_replace( "\r", "\n", $text );

		// Split by any combination of newlines and whitespace that could represent paragraph breaks.
		$paragraphs = preg_split( '/(\n\s*\n|\n\s+)/', $text );

		// For single-line paragraphs (when text uses just one \n between paragraphs).
		if ( count( $paragraphs ) <= 1 && false !== strpos( $text, "\n" ) ) {
			$paragraphs = explode( "\n", $text );
		}

		// Filter out empty paragraphs and trim each paragraph.
		$paragraphs = array_filter( array_map( 'trim', $paragraphs ) );

		// Return indexed array (not associative).
		return array_values( $paragraphs );
	}

	/**
	 * Process rate limit message tags
	 *
	 * Replaces merge tags in rate limit messages using the standardized format {prefix|parameter|suffix}.
	 * Supports rate limit specific parameters: "usage_count", "max_allowed", "remaining", and "percentage".
	 *
	 * @param string $message     The message template containing merge tags.
	 * @param int    $usage_count The current usage count.
	 * @param int    $max_allowed The maximum allowed usage.
	 * @return string The processed message with merge tags replaced.
	 */
	public function process_rate_limit_message_tags( $message, $usage_count, $max_allowed ) {
		if ( empty( $message ) ) {
			return '';
		}

		// Regex to find merge tags in format {prefix|parameters|suffix}.
		$regex = '/\{(?\'prefix\'.*?)\|(?\'parameters\'.*?)\|(?\'suffix\'.*?)\}/ms';

		// Find all merge tags in the message.
		preg_match_all( $regex, $message, $matches, PREG_SET_ORDER, 0 );

		// Process each merge tag.
		foreach ( $matches as $match ) {
			$full_tag   = $match[0];
			$prefix     = $match['prefix'];
			$parameters = $match['parameters'];
			$suffix     = $match['suffix'];

			// Determine content based on parameter.
			$content = '';

			switch ( trim( $parameters ) ) {
				case 'usage_count':
					$content = $usage_count;
					break;
				case 'max_allowed':
					$content = $max_allowed;
					break;
				case 'remaining':
					// Calculate remaining usage.
					$content = max( 0, $max_allowed - $usage_count );
					break;
				case 'percentage':
					// Calculate usage as percentage.
					$content = ( $max_allowed > 0 ) ? round( ( $usage_count / $max_allowed ) * 100 ) : 100;
					break;
				default:
					// Unknown parameter, keep tag as is.
					continue 2; // Skip to next iteration of outer loop.
			}

			// Create replacement with prefix and suffix.
			$replacement = "{$prefix}{$content}{$suffix}";

			// Replace in message.
			$message = str_replace( $full_tag, $replacement, $message );
		}

		return $message;
	}
}
