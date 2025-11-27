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
	 * @param string      $prompt       The prompt string containing merge tags.
	 * @param string|null $uuid         The UUID of the essay or speech. Can be null for standalone speech attempts.
	 * @param int         $segment_order_or_attempt_id Optional. The order of the segment or speech attempt id to filter by.
	 * @param string      $feedback_style The feedback style to use.
	 * @param string      $guide_score   Human-guided scoring for the AI to consider.
	 * @param string      $guide_feedback Human-guided feedback content for the AI to incorporate.
	 * @param array|null  $attempt      Optional. The attempt data array for attempt-specific merge tags.
	 * @param bool        $return_structured Optional. If true, returns structured array with resolved tags. Default false.
	 * @param int|null    $target_score Optional. The desired score for conditional merge tags.
	 * @return string|array The processed prompt with merge tags replaced, or structured array with prompts and resolved_tags.
	 */
	public function process_merge_tags( $prompt, $uuid = null, $segment_order_or_attempt_id = null, $feedback_style = '', $guide_score = '', $guide_feedback = '', $attempt = null, $return_structured = false, $target_score = null ) {
		// Regex to find merge tags in format {prefix|parameters|suffix}.
		$regex = '/\{(?\'prefix\'.*?)\|(?\'parameters\'.*?)\|(?\'suffix\'.*?)\}/ms';

		// Find all merge tags in the prompt.
		preg_match_all( $regex, $prompt, $matches, PREG_SET_ORDER, 0 );

		// Initialize storage for resolved tags.
		$resolved_tags = array(
			'common'           => array(), // Tags that resolved to single values.
			'variant_specific' => array(), // Tags that resolved to arrays (for parallel).
		);

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
			} elseif ( 0 === strpos( trim( $parameters ), 'target_score' ) && ! empty( $target_score ) ) {
					$content = $this->process_target_score_tag( trim( $parameters ), $target_score );
			} else {
				// Standard case: fetch content based on parameters.
				$content = $this->fetch_content_for_merge_tag( $parameters, $uuid, $segment_order_or_attempt_id, $attempt );
			}

			// Store resolved values.
			if ( is_array( $content ) ) {
				$array_tags[ $full_tag ] = array(
					'prefix'  => $match['prefix'],
					'suffix'  => $match['suffix'],
					'content' => $content,
				);

				// Track the length of the longest array.
				$max_array_length = max( $max_array_length, count( $content ) );

				// Store as variant-specific tag.
				$resolved_tags['variant_specific'][ trim( $parameters ) ] = $content;
			} else {
				// Store as common tag.
				$resolved_tags['common'][ trim( $parameters ) ] = $content;
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
				} elseif ( 0 === strpos( trim( $parameters ), 'target_score' ) && ! empty( $target_score ) ) {
					$content = $this->process_target_score_tag( trim( $parameters ), $target_score );
				} else {
					// Standard case: fetch content based on parameters.
					$content = $this->fetch_content_for_merge_tag( $parameters, $uuid, $segment_order_or_attempt_id, $attempt );
				}

				// For non-array content, standard replacement.
				$replacement = empty( $content ) ? '' : "{$prefix}{$content}{$suffix}";
				$prompt      = str_replace( $full_tag, $replacement, $prompt );
			}

			// Return structured format if requested.
			if ( $return_structured ) {
				return array(
					'prompts'       => $prompt,
					'resolved_tags' => $resolved_tags,
					'parallel_mode' => false,
				);
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
				$item = $tag_data['content'][ $i ];
				// Convert array items to JSON string.
				if ( is_array( $item ) ) {
					$item = wp_json_encode( $item );
				}
				$replacement = "{$tag_data['prefix']}{$item}{$tag_data['suffix']}";
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
				} elseif ( 0 === strpos( trim( $parameters ), 'target_score' ) && ! empty( $target_score ) ) {
					$content = $this->process_target_score_tag( trim( $parameters ), $target_score );
				} else {
					// Standard case: fetch content based on parameters.
					$content = $this->fetch_content_for_merge_tag( $parameters, $uuid, $segment_order_or_attempt_id, $attempt );
				}

				$replacement = empty( $content ) ? '' : "{$prefix}{$content}{$suffix}";

				$variant = str_replace( $full_tag, $replacement, $variant );
			}

			$variants[] = $variant;
		}

		// Return structured format if requested.
		if ( $return_structured ) {
			return array(
				'prompts'       => $variants,
				'resolved_tags' => $resolved_tags,
				'parallel_mode' => true,
			);
		}

		return $variants;
	}

	/**
	 * Fetch content for a merge tag based on parameters
	 *
	 * @param string      $parameters    The parameters specifying what content to fetch.
	 * @param string|null $uuid          The UUID of the essay or speech. Can be null for standalone speech attempts.
	 * @param int         $segment_order_or_attempt_id Optional. The order of the segment to filter by.
	 * @param array|null  $attempt      Optional. The attempt data array for attempt-specific merge tags.
	 * @return array|string|null The content to replace the merge tag with, or null if not found.
	 */
	private function fetch_content_for_merge_tag( $parameters, $uuid = null, $segment_order_or_attempt_id = null, $attempt = null ) {
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
			$content = $this->get_content_from_database(
				$table,
				$field,
				$filter_field,
				$filter_value,
				$uuid,
				$segment_order_or_attempt_id,
				$attempt
			);

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
	 * @param string      $table         The table/source to fetch from.
	 * @param string      $field         The field to retrieve.
	 * @param string      $filter_field  The field to filter by.
	 * @param string      $filter_value  The value to filter with.
	 * @param string|null $uuid          The UUID of the essay or speech. Can be null for standalone speech attempts.
	 * @param int         $segment_order_or_attempt_id Optional. The order of the segment to filter by.
	 * @param array|null  $attempt       Optional. The attempt data array for attempt-specific merge tags.
	 * @return string|array|null The retrieved content, array of values, or null if not found.
	 */
	public function get_content_from_database( $table, $field, $filter_field, $filter_value, $uuid = null, $segment_order_or_attempt_id = null, $attempt = null ) {
		// Skip if any required parameter is missing (except uuid which can be null for speech attempts).
		if ( empty( $table ) || empty( $field ) ) {
			return null;
		}

		// For non-speech-attempt and non-attempt tables, UUID is required.
		if ( empty( $uuid ) && ! in_array( $table, array( 'speech_attempt_feedback', 'attempt' ), true ) ) {
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
			'speech_attempt_feedback',
			'attempt',
		);

		if ( ! in_array( $table, $supported_tables, true ) ) {
			return null;
		}

		// Handle attempt table.
		if ( 'attempt' === $table ) {
			return $this->get_speech_attempt_content( $field, $attempt );
		}

		// Handle speech tables.
		if ( in_array( $table, array( 'speech', 'speech_feedback', 'speech_attempt_feedback' ), true ) ) {
			return $this->get_speech_content_from_database(
				$table,
				$field,
				$filter_field,
				$filter_value,
				$uuid,
				$segment_order_or_attempt_id
			);
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
				if ( null !== $segment_order_or_attempt_id ) {
					$query_args['order'] = $segment_order_or_attempt_id;
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

				if ( null !== $segment_order_or_attempt_id ) {
					$segment_query['order'] = $segment_order_or_attempt_id;
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
				if ( null !== $segment_order_or_attempt_id || 1 === count( $collected_feedback_content ) ) {
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
	 * @param string      $table         The table to fetch from (speech, speech_feedback, speech_attempt_feedback).
	 * @param string      $field         The field to retrieve.
	 * @param string      $filter_field  The field to filter by.
	 * @param string      $filter_value  The value to filter with.
	 * @param string|null $uuid          The UUID of the speech recording. Can be null for standalone speech attempts.
	 * @param int         $attempt_id    Optional. The ID of the speech attempt.
	 * @return string|array|null The retrieved content, array of values, or null if not found.
	 */
	private function get_speech_content_from_database( $table, $field, $filter_field, $filter_value, $uuid = null, $attempt_id = null ) {
		// Initialize Speech DB.
		$speech_db = new Ieltssci_Speech_DB();

		switch ( $table ) {
			case 'speech':
				// For speech table, UUID is required.
				if ( empty( $uuid ) ) {
					return null;
				}

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
						if ( 'transcript' === $field || 'transcript_text' === $field || 'transcript_with_pause' === $field || 'transcript_with_chunking' === $field || 'speaking_rate' === $field ) {
							if ( isset( $speeches[0]['transcript'] ) && is_array( $speeches[0]['transcript'] ) ) {
								// For transcript_text, return just the text content.
								if ( 'transcript_text' === $field ) {
									$texts = array();
									foreach ( $speeches[0]['transcript'] as $attachment_id => $transcript ) {
										if ( isset( $transcript['text'] ) ) {
											$text = $transcript['text'];
											// Fix capitalization after mid-sentence newlines.
											$text = preg_replace_callback(
												'/([^\.\!\?\n])\n+([A-Z])/u',
												function ( $matches ) {
													return $matches[1] . ' ' . mb_strtolower( $matches[2] );
												},
												$text
											);
											// Replace all whitespace with single spaces.
											$texts[] = preg_replace( '/\s+/', ' ', trim( $text ) );
										}
									}
									return implode( ' ', $texts );
								}
								// For transcript_with_pause, return formatted text with pause indicators.
								if ( 'transcript_with_pause' === $field ) {
									$formatted_texts = array();
									foreach ( $speeches[0]['transcript'] as $attachment_id => $transcript_data ) {
										$formatted_text = $this->format_transcript_with_pauses( $attachment_id, $transcript_data );
										if ( ! empty( $formatted_text ) ) {
											$formatted_texts[] = $formatted_text;
										}
									}
									return ! empty( $formatted_texts ) ? implode( "\n\n", $formatted_texts ) : null;
								}
								// For transcript_with_chunking, return formatted text with pause/hesitation indicators.
								if ( 'transcript_with_chunking' === $field ) {
									$formatted_texts = array();
									foreach ( $speeches[0]['transcript'] as $attachment_id => $transcript_data ) {
										$formatted_text = $this->format_transcript_with_chunking( $attachment_id, $transcript_data );
										if ( ! empty( $formatted_text ) ) {
											$formatted_texts[] = $formatted_text;
										}
									}
									return ! empty( $formatted_texts ) ? implode( "\n\n", $formatted_texts ) : null;
								}
								// For speaking_rate, calculate WPM from transcript data.
								if ( 'speaking_rate' === $field ) {
									return $this->calculate_speaking_rate( $speeches[0]['transcript'] );
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
				// For speech_feedback table, UUID is required to get speech_id.
				if ( empty( $uuid ) ) {
					return null;
				}

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

			case 'speech_attempt_feedback':
				// For speech_attempt_feedback table, can work with or without UUID.
				// If UUID is provided, constrain by speech; otherwise query by attempt_id only.
				$query_args = array(
					'orderby' => 'created_at',
					'order'   => 'DESC',
					'limit'   => 200,
				);

				// Add speech constraint if UUID is provided.
				if ( ! empty( $uuid ) ) {
					$query_args['speech_uuid'] = $uuid;
				}

				// Add attempt_id filter if provided.
				if ( null !== $attempt_id ) {
					$query_args['attempt_id'] = (int) $attempt_id;
				}

				// Map requested field to include flags to avoid selecting unnecessary columns.
				switch ( $field ) {
					case 'cot_content':
						$query_args['include_cot']      = true;
						$query_args['include_score']    = false;
						$query_args['include_feedback'] = false;
						break;
					case 'score_content':
						$query_args['include_cot']      = false;
						$query_args['include_score']    = true;
						$query_args['include_feedback'] = false;
						break;
					case 'feedback_content':
					default:
						$query_args['include_cot']      = false;
						$query_args['include_score']    = false;
						$query_args['include_feedback'] = true;
						break;
				}

				// Apply additional filter if provided and not the special 'uuid'.
				if ( ! empty( $filter_field ) && ! empty( $filter_value ) && 'uuid' !== $filter_field ) {
					$query_args[ $filter_field ] = $filter_value;
				}

				$attempt_feedbacks = $speech_db->get_speech_attempt_feedbacks( $query_args );
				if ( is_wp_error( $attempt_feedbacks ) || empty( $attempt_feedbacks ) ) {
					return null;
				}

				// Group by attempt_id to get at most one content per attempt, picking the first with non-empty field.
				$by_attempt = array();
				foreach ( $attempt_feedbacks as $fb ) {
					if ( ! isset( $fb['attempt_id'] ) ) {
						continue;
					}
					$aid = $fb['attempt_id'];
					if ( ! isset( $by_attempt[ $aid ] ) && isset( $fb[ $field ] ) && ! empty( $fb[ $field ] ) ) {
						$by_attempt[ $aid ] = $fb[ $field ];
					}
				}

				if ( empty( $by_attempt ) ) {
					return null;
				}

				// If a specific attempt_id filter was provided, return just the first matching content.
				if ( ! empty( $query_args['attempt_id'] ) ) {
					$id = $query_args['attempt_id'];
					if ( isset( $by_attempt[ $id ] ) ) {
						return $by_attempt[ $id ];
					}
					// If no matching attempt found, return null.
					return null;
				}

				// If only one attempt has content, return it; otherwise, return an array ordered by attempt_id.
				if ( 1 === count( $by_attempt ) ) {
					return array_values( $by_attempt )[0];
				}

				ksort( $by_attempt );
				return array_values( $by_attempt );
		}

		return null;
	}

	/**
	 * Get attempt-specific content
	 *
	 * Handles merge tags for attempt data like audio title, transcript, question title, and question content.
	 *
	 * @param string     $field   The field to retrieve (title, transcript, transcript_with_pause, speaking_rate, question_title, question_content).
	 * @param array|null $attempt Optional. The attempt data array.
	 * @return string|int|null The retrieved content, or null if not found.
	 */
	private function get_speech_attempt_content( $field, $attempt = null ) {
		// If no attempt data provided, return null.
		if ( is_null( $attempt ) ) {
			return null;
		}

		switch ( $field ) {
			case 'title':
				// Get audio attachment title.
				if ( isset( $attempt['audio_id'] ) ) {
					$attachment_id = (int) $attempt['audio_id'];
					$raw_title     = get_the_title( $attachment_id );
					return is_string( $raw_title ) ? $raw_title : '';
				}
				break;

			case 'transcript':
				// Get audio transcript text.
				if ( isset( $attempt['audio_id'] ) ) {
					$attachment_id = (int) $attempt['audio_id'];
					return $this->get_audio_transcript_text( $attachment_id );
				}
				break;

			case 'transcript_with_pause':
				// Get audio transcript with pause indicators.
				if ( isset( $attempt['audio_id'] ) ) {
					$attachment_id   = (int) $attempt['audio_id'];
					$transcript_data = get_post_meta( $attachment_id, 'ieltssci_audio_transcription', true );

					if ( ! empty( $transcript_data ) && is_array( $transcript_data ) ) {
						return $this->format_transcript_with_pauses( $attachment_id, $transcript_data );
					}
				}
				break;

			case 'transcript_with_chunking':
				// Get audio transcript with natural pause and hesitation indicators.
				if ( isset( $attempt['audio_id'] ) ) {
					$attachment_id   = (int) $attempt['audio_id'];
					$transcript_data = get_post_meta( $attachment_id, 'ieltssci_audio_transcription', true );

					if ( ! empty( $transcript_data ) && is_array( $transcript_data ) ) {
						return $this->format_transcript_with_chunking( $attachment_id, $transcript_data );
					}
				}
				break;

			case 'speaking_rate':
				// Calculate speaking rate from audio transcript.
				if ( isset( $attempt['audio_id'] ) ) {
					$attachment_id   = (int) $attempt['audio_id'];
					$transcript_data = get_post_meta( $attachment_id, 'ieltssci_audio_transcription', true );

					if ( ! empty( $transcript_data ) && is_array( $transcript_data ) ) {
						// Wrap in array keyed by attachment ID to match expected format.
						$wrapped_data = array( $attachment_id => $transcript_data );
						return $this->calculate_speaking_rate( $wrapped_data );
					}
				}
				break;

			case 'question_title':
				// Get question post title.
				if ( isset( $attempt['question_id'] ) ) {
					$question_id = (int) $attempt['question_id'];
					$raw_title   = get_the_title( $question_id );
					return is_string( $raw_title ) ? $raw_title : '';
				}
				break;

			case 'question_content':
				// Get question post content.
				if ( isset( $attempt['question_id'] ) ) {
					$question_id = (int) $attempt['question_id'];
					$post        = get_post( $question_id );
					if ( $post && ! is_wp_error( $post ) ) {
						return ! empty( $post->post_content ) ? $post->post_content : '';
					}
				}
				break;
		}

		return null;
	}

	/**
	 * Format transcript with pause indicators
	 *
	 * Processes transcription data to insert pause indicators between words,
	 * replicating the frontend logic from LeftPanelFC.tsx.
	 *
	 * @param int|string $media_id The media/attachment ID.
	 * @param array      $transcript_data The transcription data for this media file.
	 * @return string|null Formatted transcript with pause indicators, or null if no data.
	 */
	private function format_transcript_with_pauses( $media_id, $transcript_data ) {
		if ( empty( $transcript_data ) || ! is_array( $transcript_data ) ) {
			return null;
		}

		// Extract timed words from the transcript data.
		$timed_words = $this->get_timed_words_for_media( $media_id, $transcript_data );

		if ( empty( $timed_words ) ) {
			// Fallback to plain text if no word timing data.
			return isset( $transcript_data['text'] ) ? $transcript_data['text'] : null;
		}

		// Calculate pause threshold for this media.
		$pause_threshold = $this->calculate_pause_threshold( $media_id, $transcript_data );

		// Build the formatted text with pause indicators.
		$formatted_parts = array();
		$pauses_detected = false;

		foreach ( $timed_words as $index => $word_data ) {
			// Add the word.
			$formatted_parts[] = $word_data['word'];

			// Check if we should add a pause indicator.
			if ( $index < count( $timed_words ) - 1 ) {
				$next_word      = $timed_words[ $index + 1 ];
				$pause_duration = $next_word['start'] - $word_data['end'];

				// Only add pause if it exceeds threshold and current word doesn't end with punctuation.
				if ( $pause_duration > $pause_threshold['threshold'] && ! $this->ends_with_punctuation( $word_data['word'] ) ) {
					$pause_seconds     = round( $pause_duration, 1 );
					$formatted_parts[] = '[' . $pause_seconds . 's PAUSE]';
					$pauses_detected   = true;
				}
			}
		}

		$formatted_text = implode( ' ', $formatted_parts );

		// If no pauses were detected, append a message.
		if ( ! $pauses_detected ) {
			$formatted_text .= "\n\n[No significant pauses detected in this recording]";
		}

		return $formatted_text;
	}

	/**
	 * Get timed words for a specific media ID from transcript data
	 *
	 * @param int|string $media_id The media/attachment ID.
	 * @param array      $transcript_data The transcription data.
	 * @return array Array of word objects with timing information.
	 */
	private function get_timed_words_for_media( $media_id, $transcript_data ) {
		if ( empty( $transcript_data ) ) {
			return array();
		}

		$timed_words = array();
		$time_ranges = array();

		// Helper function to check if a word overlaps with existing words.
		$is_overlapping = function ( $start, $end ) use ( &$time_ranges ) {
			$word_duration     = $end - $start;
			$overlap_threshold = $word_duration * 0.5;

			foreach ( $time_ranges as $range ) {
				$overlap_start    = max( $range['start'], $start );
				$overlap_end      = min( $range['end'], $end );
				$overlap_duration = $overlap_end - $overlap_start;
				if ( $overlap_duration > $overlap_threshold ) {
					return true;
				}
			}
			return false;
		};

		// Prefer using words from segments if available.
		if ( ! empty( $transcript_data['segments'] ) && is_array( $transcript_data['segments'] ) ) {
			foreach ( $transcript_data['segments'] as $segment ) {
				if ( ! empty( $segment['words'] ) && is_array( $segment['words'] ) ) {
					foreach ( $segment['words'] as $word ) {
						if ( ! $is_overlapping( $word['start'], $word['end'] ) ) {
							$timed_words[] = array(
								'word'  => $word['word'],
								'start' => $word['start'],
								'end'   => $word['end'],
								'score' => isset( $word['score'] ) ? $word['score'] : null,
							);
							$time_ranges[] = array(
								'start' => $word['start'],
								'end'   => $word['end'],
							);
						}
					}
				}
			}
		}

		// Add top-level words only if they don't overlap with segment words.
		if ( ! empty( $transcript_data['words'] ) && is_array( $transcript_data['words'] ) ) {
			foreach ( $transcript_data['words'] as $word ) {
				if ( ! $is_overlapping( $word['start'], $word['end'] ) ) {
					$timed_words[] = array(
						'word'  => $word['word'],
						'start' => $word['start'],
						'end'   => $word['end'],
						'score' => isset( $word['score'] ) ? $word['score'] : null,
					);
					$time_ranges[] = array(
						'start' => $word['start'],
						'end'   => $word['end'],
					);
				}
			}
		}

		// Sort by start time.
		usort(
			$timed_words,
			function ( $a, $b ) {
				return $a['start'] <=> $b['start'];
			}
		);

		return $timed_words;
	}

	/**
	 * Calculate pause threshold for transcript data
	 *
	 * Calculates dynamic pause threshold based on pauses between words within segments.
	 *
	 * @param int|string $media_id The media/attachment ID.
	 * @param array      $transcript_data The transcription data.
	 * @return array Array with 'average' and 'threshold' pause durations.
	 */
	private function calculate_pause_threshold( $media_id, $transcript_data ) {
		$default_threshold = array(
			'average'   => 0,
			'threshold' => 0.7,
		);

		if ( empty( $transcript_data ) ) {
			return $default_threshold;
		}

		// Get timed words.
		$words = $this->get_timed_words_for_media( $media_id, $transcript_data );

		if ( count( $words ) < 2 ) {
			return $default_threshold;
		}

		if ( empty( $transcript_data['segments'] ) ) {
			return $default_threshold;
		}

		// Create a map of word time ranges to their containing segment.
		$word_segment_map = array();

		foreach ( $transcript_data['segments'] as $segment_index => $segment ) {
			if ( ! empty( $segment['words'] ) && is_array( $segment['words'] ) ) {
				foreach ( $segment['words'] as $word ) {
					$word_segment_map[ $word['start'] ] = $segment_index;
				}
			}
		}

		// Calculate pauses only between words in the same segment.
		$pauses      = array();
		$words_count = count( $words );

		for ( $i = 1; $i < $words_count; $i++ ) {
			$prev_word    = $words[ $i - 1 ];
			$current_word = $words[ $i ];

			$prev_segment_index    = isset( $word_segment_map[ $prev_word['start'] ] ) ? $word_segment_map[ $prev_word['start'] ] : null;
			$current_segment_index = isset( $word_segment_map[ $current_word['start'] ] ) ? $word_segment_map[ $current_word['start'] ] : null;

			// Only include pauses between words in the same segment.
			if ( null !== $prev_segment_index && null !== $current_segment_index && $prev_segment_index === $current_segment_index ) {
				$pause_duration = $current_word['start'] - $prev_word['end'];
				if ( $pause_duration > 0 ) {
					$pauses[] = $pause_duration;
				}
			}
		}

		// If we don't have enough pauses within segments, use a fallback approach.
		if ( count( $pauses ) < 3 ) {
			$all_pauses = array();
			for ( $i = 1; $i < $words_count; $i++ ) {
				$pause = max( 0, $words[ $i ]['start'] - $words[ $i - 1 ]['end'] );
				if ( $pause > 0 ) {
					$all_pauses[] = $pause;
				}
			}

			if ( empty( $all_pauses ) ) {
				return $default_threshold;
			}

			$average_pause = array_sum( $all_pauses ) / count( $all_pauses );
			return array(
				'average'   => $average_pause,
				'threshold' => max( 0.7, $average_pause * 2 ),
			);
		}

		// Calculate statistics based on within-segment pauses.
		$average_pause = array_sum( $pauses ) / count( $pauses );

		$variance = 0;
		foreach ( $pauses as $pause ) {
			$variance += pow( $pause - $average_pause, 2 );
		}
		$std_dev_pause = sqrt( $variance / count( $pauses ) );

		// Dynamic threshold: average + 3 * stdDev, with a minimum threshold.
		$threshold = max( $average_pause + 3 * $std_dev_pause, 0.7 );

		return array(
			'average'   => $average_pause,
			'threshold' => $threshold,
		);
	}

	/**
	 * Check if a word ends with punctuation
	 *
	 * @param string $word The word to check.
	 * @return bool True if word ends with punctuation.
	 */
	private function ends_with_punctuation( $word ) {
		return (bool) preg_match( '/[.,:;!?]$/', $word );
	}

	/**
	 * Format transcript with chunking (natural pauses vs hesitations)
	 *
	 * Processes transcription data to differentiate between:
	 * - Natural pauses (after punctuation): marked as [X.Xs PAUSE]
	 * - Unnatural pauses/hesitations (not after punctuation): marked as [X.Xs HESITATION]
	 *
	 * @param int|string $media_id The media/attachment ID.
	 * @param array      $transcript_data The transcription data for this media file.
	 * @return string|null Formatted transcript with pause/hesitation indicators, or null if no data.
	 */
	private function format_transcript_with_chunking( $media_id, $transcript_data ) {
		if ( empty( $transcript_data ) || ! is_array( $transcript_data ) ) {
			return null;
		}

		// Extract timed words from the transcript data.
		$timed_words = $this->get_timed_words_for_media( $media_id, $transcript_data );

		if ( empty( $timed_words ) ) {
			// Fallback to plain text if no word timing data.
			return isset( $transcript_data['text'] ) ? $transcript_data['text'] : null;
		}

		// Calculate pause threshold for this media.
		$pause_threshold = $this->calculate_pause_threshold( $media_id, $transcript_data );

		// Build the formatted text with pause/hesitation indicators.
		$formatted_parts      = array();
		$pauses_detected      = false;
		$hesitations_detected = false;

		foreach ( $timed_words as $index => $word_data ) {
			// Add the word.
			$formatted_parts[] = $word_data['word'];

			// Check if we should add a pause/hesitation indicator.
			if ( $index < count( $timed_words ) - 1 ) {
				$next_word      = $timed_words[ $index + 1 ];
				$pause_duration = $next_word['start'] - $word_data['end'];

				// Only add indicator if pause exceeds threshold.
				if ( $pause_duration > $pause_threshold['threshold'] ) {
					$pause_seconds = round( $pause_duration, 1 );

					// Check if current word ends with punctuation.
					if ( $this->ends_with_punctuation( $word_data['word'] ) ) {
						// Natural pause after punctuation.
						$formatted_parts[] = '[' . $pause_seconds . 's NATURAL PAUSE]';
						$pauses_detected   = true;
					} else {
						// Unnatural pause/hesitation (not after punctuation).
						$formatted_parts[]    = '[' . $pause_seconds . 's HESITATION]';
						$hesitations_detected = true;
					}
				}
			}
		}

		$formatted_text = implode( ' ', $formatted_parts );

		// Add summary message if no pauses or hesitations were detected.
		if ( ! $pauses_detected && ! $hesitations_detected ) {
			$formatted_text .= "\n\n[No significant pauses or hesitations detected in this recording]";
		}

		return $formatted_text;
	}

	/**
	 * Calculate speaking rate in words per minute (WPM) from transcription data
	 *
	 * Uses only segments with actual speech to avoid counting long pauses.
	 * Replicates the frontend logic from calculateSpeakingRate in transcriptionPostProcess.ts.
	 *
	 * @param array $transcript_data Array of transcription data keyed by attachment ID.
	 * @return int|null Speaking rate in words per minute, or null if data is insufficient.
	 */
	private function calculate_speaking_rate( $transcript_data ) {
		if ( empty( $transcript_data ) || ! is_array( $transcript_data ) ) {
			return null;
		}

		$total_words    = 0;
		$total_duration = 0; // in seconds.

		// Process each media file's transcription.
		foreach ( $transcript_data as $attachment_id => $file_data ) {
			if ( empty( $file_data ) || ! is_array( $file_data ) ) {
				continue;
			}

			// If segments are available, use them for more accurate calculation.
			if ( ! empty( $file_data['segments'] ) && is_array( $file_data['segments'] ) ) {
				foreach ( $file_data['segments'] as $segment ) {
					// Calculate segment duration and word count.
					$segment_duration = $segment['end'] - $segment['start'];

					// Count words in this segment.
					$segment_word_count = 0;
					if ( ! empty( $segment['words'] ) && is_array( $segment['words'] ) ) {
						$segment_word_count = count( $segment['words'] );
					} elseif ( ! empty( $segment['text'] ) ) {
						$words              = preg_split( '/\s+/', trim( $segment['text'] ) );
						$segment_word_count = count( array_filter( $words ) );
					}

					$total_duration += $segment_duration;
					$total_words    += $segment_word_count;
				}
			} elseif ( ! empty( $file_data['words'] ) && is_array( $file_data['words'] ) ) {
				// If no segments but we have words array at the file level.
				$total_words += count( $file_data['words'] );

				// Use the duration between first and last word.
				if ( count( $file_data['words'] ) > 1 ) {
					$first_word      = $file_data['words'][0];
					$last_word       = $file_data['words'][ count( $file_data['words'] ) - 1 ];
					$total_duration += $last_word['end'] - $first_word['start'];
				}
			} elseif ( ! empty( $file_data['text'] ) && isset( $file_data['duration'] ) ) {
				// Fallback to full text and overall duration if available.
				$words           = preg_split( '/\s+/', trim( $file_data['text'] ) );
				$total_words    += count( array_filter( $words ) );
				$total_duration += $file_data['duration'];
			}
		}

		// Calculate WPM, avoiding division by zero.
		if ( $total_duration <= 0 || 0 === $total_words ) {
			return null;
		}

		// Convert seconds to minutes and calculate WPM.
		$duration_in_minutes = $total_duration / 60;
		$wpm                 = round( $total_words / $duration_in_minutes );

		return (int) $wpm;
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
			$content = array_map(
				function ( $item ) {
					return is_array( $item ) ? json_encode( $item ) : $item;
				},
				$content
			);
			return implode( "\n\n---\n\n", $content );
		}

		// Check for compound modifiers.
		if ( false !== strpos( $modifier, ':' ) ) {
			$modifiers = explode( ':', $modifier );

			// Process each modifier in sequence.
			foreach ( $modifiers as $mod ) {
				if ( 'flatten' === $mod && is_array( $content ) ) {
					$content = array_map(
						function ( $item ) {
							return is_array( $item ) ? json_encode( $item ) : $item;
						},
						$content
					);
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

			case 'paragraph_count':
				// Count non-empty paragraphs using split_into_paragraphs().
				$split_paragraphs = $this->split_into_paragraphs( $content );
				return count( $split_paragraphs );

			default:
				// Check if modifier starts with 'json_all_' for dynamic JSON property extraction (all occurrences).
				if ( strpos( $modifier, 'json_all_' ) === 0 ) {
					$property_name = substr( $modifier, 9 ); // Remove 'json_all_' prefix.

					// Decode JSON content.
					$data = json_decode( $content, true );
					if ( json_last_error() !== JSON_ERROR_NONE ) {
						return array( 'Invalid JSON.' );
					}

					// Recursively search for all properties.
					$property_values = $this->find_all_json_properties( $data, $property_name );

					if ( empty( $property_values ) ) {
						return array( "No {$property_name} found." );
					}

					// Flatten the array if all values are arrays or scalars.
					$flattened = array();
					foreach ( $property_values as $value ) {
						if ( is_array( $value ) ) {
							$flattened = array_merge( $flattened, $value );
						} else {
							$flattened[] = $value;
						}
					}

					return $flattened;
				}

				// Check if modifier starts with 'json_' for dynamic JSON property extraction (first occurrence).
				if ( strpos( $modifier, 'json_' ) === 0 ) {
					$property_name = substr( $modifier, 5 ); // Remove 'json_' prefix.

					// Decode JSON content.
					$data = json_decode( $content, true );
					if ( json_last_error() !== JSON_ERROR_NONE ) {
						return array( 'Invalid JSON.' );
					}

					// Recursively search for the property.
					$property_value = $this->find_json_property( $data, $property_name );

					if ( null === $property_value ) {
						return array( "Schema mismatch: {$property_name} property not found." );
					}

					// Return the property value (could be array or scalar).
					if ( is_array( $property_value ) ) {
						if ( empty( $property_value ) ) {
							return array( "No {$property_name} found." );
						}
						return $property_value;
					}

					return $property_value;
				}

				// Check if modifier starts with 'remove_property_' for dynamic JSON property removal.
				if ( strpos( $modifier, 'remove_property_' ) === 0 ) {
					$property_name = substr( $modifier, 16 ); // Remove 'remove_property_' prefix.

					// Decode JSON content.
					$data = json_decode( $content, true );
					if ( json_last_error() !== JSON_ERROR_NONE ) {
						return array( 'Invalid JSON.' );
					}

					// Recursively remove the property.
					$modified_data = $this->remove_json_property( $data, $property_name );

					// Re-encode as JSON.
					return wp_json_encode( $modified_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				}

				// Check if modifier starts with 'remove_items_where_' for filtering array items.
				if ( strpos( $modifier, 'remove_items_where_' ) === 0 ) {
					// Extract the modifier parameters.
					// Format: remove_items_where_{property}_{operator}_{value}.
					$pattern = '/^remove_items_where_\{(.+?)\}_\{(.+?)\}_\{(.+?)\}$/';
					if ( preg_match( $pattern, $modifier, $matches ) ) {
						$property = $matches[1];
						$operator = $matches[2];
						$value    = $matches[3];

						// Decode JSON content.
						$data = json_decode( $content, true );
						if ( json_last_error() !== JSON_ERROR_NONE ) {
							return array( 'Invalid JSON.' );
						}

						// Remove items matching the criteria.
						$modified_data = $this->remove_json_items_where( $data, $property, $operator, $value );

						// Re-encode as JSON.
						return wp_json_encode( $modified_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					}
				}

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
	 * Recursively find a property in nested arrays
	 *
	 * @param array  $data The data to search.
	 * @param string $property_name The property name to find.
	 * @return mixed The property value if found, null otherwise.
	 */
	private function find_json_property( $data, $property_name ) {
		if ( ! is_array( $data ) ) {
			return null;
		}

		// Check if current level has the property key.
		if ( isset( $data[ $property_name ] ) ) {
			return $data[ $property_name ];
		}

		// Recursively search in all array values.
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$result = $this->find_json_property( $value, $property_name );
				if ( null !== $result ) {
					return $result;
				}
			}
		}

		return null;
	}

	/**
	 * Recursively find all properties in nested arrays
	 *
	 * @param array  $data The data to search.
	 * @param string $property_name The property name to find.
	 * @return array The array of property values if found, empty array otherwise.
	 */
	private function find_all_json_properties( $data, $property_name ) {
		$results = array();

		if ( ! is_array( $data ) ) {
			return $results;
		}

		// Check if current level has the property key.
		if ( isset( $data[ $property_name ] ) ) {
			$results[] = $data[ $property_name ];
		}

		// Recursively search in all array values.
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$results = array_merge( $results, $this->find_all_json_properties( $value, $property_name ) );
			}
		}

		return $results;
	}

	/**
	 * Recursively remove a property from nested arrays
	 *
	 * @param array  $data The data to process.
	 * @param string $property_name The property name to remove.
	 * @return array The modified data with property removed.
	 */
	private function remove_json_property( $data, $property_name ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		// Remove the property at current level if it exists.
		if ( isset( $data[ $property_name ] ) ) {
			unset( $data[ $property_name ] );
		}

		// Recursively process all nested arrays.
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->remove_json_property( $value, $property_name );
			}
		}

		return $data;
	}

	/**
	 * Recursively remove items from arrays based on property condition
	 *
	 * This method searches through nested JSON structures and removes array items
	 * that match the specified property condition.
	 *
	 * @param array  $data The data to process.
	 * @param string $property The property name to check.
	 * @param string $operator The comparison operator (equals, not_equals, contains, not_contains).
	 * @param string $value The value to compare against.
	 * @return array The modified data with matching items removed.
	 */
	private function remove_json_items_where( $data, $property, $operator, $value ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		// Check if this is a sequential array (list of items).
		if ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			// Filter items based on the condition.
			$filtered = array();
			foreach ( $data as $item ) {
				if ( is_array( $item ) ) {
					// First, recursively process the item to handle nested structures.
					$processed_item = $this->remove_json_items_where( $item, $property, $operator, $value );

					// Then check if this item itself should be removed.
					if ( isset( $item[ $property ] ) ) {
						// Check if item should be removed based on operator.
						if ( ! $this->matches_condition( $item[ $property ], $operator, $value ) ) {
							// Keep the item (it doesn't match the remove condition).
							$filtered[] = $processed_item;
						}
						// If it matches the remove condition, don't add it to filtered.
					} else {
						// Keep items that don't have the property.
						$filtered[] = $processed_item;
					}
				} else {
					// Keep non-array items as is.
					$filtered[] = $item;
				}
			}
			return $filtered;
		}

		// For associative arrays, recursively process each value.
		foreach ( $data as $key => $item_value ) {
			if ( is_array( $item_value ) ) {
				$data[ $key ] = $this->remove_json_items_where( $item_value, $property, $operator, $value );
			}
		}

		return $data;
	}

	/**
	 * Check if a value matches a condition based on operator
	 *
	 * @param mixed  $item_value The value from the item to check.
	 * @param string $operator The comparison operator.
	 * @param string $compare_value The value to compare against.
	 * @return bool True if the condition matches, false otherwise.
	 */
	private function matches_condition( $item_value, $operator, $compare_value ) {
		// Convert to string for comparison.
		$item_value    = (string) $item_value;
		$compare_value = (string) $compare_value;

		switch ( $operator ) {
			case 'equals':
				return $item_value === $compare_value;

			case 'not_equals':
				return $item_value !== $compare_value;

			case 'contains':
				return false !== strpos( $item_value, $compare_value );

			case 'not_contains':
				return false === strpos( $item_value, $compare_value );

			default:
				// Unknown operator, don't match.
				return false;
		}
	}

	/**
	 * Split text into paragraphs
	 *
	 * @param string $text The text to split.
	 * @return array Array of paragraphs with numbering.
	 */
	private function split_into_paragraphs( $text ) {
		if ( empty( $text ) ) {
			return array();
		}

		// Normalize line endings to a single newline character.
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		// Split by single newline, mirroring the JS logic that uses split("\n").
		$parts = explode( "\n", $text );

		// Trim each part and filter out only truly empty strings (preserve "0" content).
		$trimmed    = array_map( 'trim', $parts );
		$paragraphs = array_values(
			array_filter(
				$trimmed,
				function ( $p ) {
					return '' !== $p;
				}
			)
		);

		// Add paragraph numbering.
		$numbered_paragraphs = array();
		$i                   = 1;
		foreach ( $paragraphs as $paragraph ) {
			$numbered_paragraphs[] = "Paragraph {$i}: {$paragraph}";
			++$i;
		}

		// Return indexed array (not associative).
		return array_values( $numbered_paragraphs );
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

	/**
	 * Get audio transcript text from attachment metadata
	 *
	 * @param int $media_id The attachment/media ID.
	 * @return string The transcript text or empty string if not found.
	 */
	private function get_audio_transcript_text( $media_id ) {
		$transcription_meta = get_post_meta( $media_id, 'ieltssci_audio_transcription', true );
		$text               = '';

		if ( is_array( $transcription_meta ) && isset( $transcription_meta['text'] ) && is_string( $transcription_meta['text'] ) ) {
			$text = $transcription_meta['text'];
		} elseif ( is_string( $transcription_meta ) && ! empty( $transcription_meta ) ) {
			// Some sites may store JSON-encoded string. Try to decode first.
			$decoded = json_decode( $transcription_meta, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) && isset( $decoded['text'] ) && is_string( $decoded['text'] ) ) {
				$text = $decoded['text'];
			} else {
				// Fallback: if it is a plain string, use as-is.
				$text = $transcription_meta;
			}
		}

		if ( empty( $text ) ) {
			return '';
		}

		// Fix capitalization after mid-sentence newlines.
		// Match pattern: non-sentence-ending character followed by newline and capitalized word.
		$text = preg_replace_callback(
			'/([^\.\!\?\n])\n+([A-Z])/u',
			function ( $matches ) {
				// Lowercase the capitalized letter after the newline.
				return $matches[1] . ' ' . mb_strtolower( $matches[2] );
			},
			$text
		);

		// Now replace all remaining whitespace (including newlines) with single spaces.
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );

		return $text;
	}

	/**
	 * Process target_score merge tag with conditional logic
	 *
	 * Supports formats:
	 * - {|target_score|} → returns the numeric score
	 * - {|target_score:if[5]then[basic proficiency]|} → returns text if condition matches
	 * - {|target_score:if[>=7]then[advanced level]|} → supports comparison operators
	 *
	 * @param string $parameters The full parameters string (e.g., "target_score:if[5]then[text]").
	 * @param int    $target_score The desired score value.
	 * @return string The processed content.
	 */
	private function process_target_score_tag( $parameters, $target_score ) {
		// If just "target_score" with no modifier, return the numeric value.
		if ( 'target_score' === $parameters ) {
			return (string) $target_score;
		}

		// Check for conditional format: target_score:if[condition]then[text].
		if ( preg_match( '/^target_score:if\[(.+?)\]then\[(.+?)\]$/s', $parameters, $matches ) ) {
			$condition = $matches[1];
			$then_text = $matches[2];

			// Evaluate the condition.
			if ( $this->evaluate_score_condition( $target_score, $condition ) ) {
				return $then_text;
			}

			// Condition not met, return empty string.
			return '';
		}

		// If no recognized format, return the score as fallback.
		return (string) $target_score;
	}

	/**
	 * Evaluate a condition against a desired score
	 *
	 * Supports operators: ==, !=, >, <, >=, <=, or plain number (equals).
	 *
	 * @param int    $score The score to evaluate.
	 * @param string $condition The condition string (e.g., "5", ">=7", "<6").
	 * @return bool True if condition matches.
	 */
	private function evaluate_score_condition( $score, $condition ) {
		$condition = trim( $condition );

		// Check for comparison operators.
		if ( preg_match( '/^(>=|<=|>|<|!=|==)?\s*(\d+(?:\.\d+)?)$/', $condition, $matches ) ) {
			$operator = ! empty( $matches[1] ) ? $matches[1] : '==';
			$value    = (float) $matches[2];

			switch ( $operator ) {
				case '>=':
					return $score >= $value;
				case '<=':
					return $score <= $value;
				case '>':
					return $score > $value;
				case '<':
					return $score < $value;
				case '!=':
					return $score != $value;
				case '==':
				default:
					return $score == $value;
			}
		}

		// If no valid condition found, return false.
		return false;
	}
}
