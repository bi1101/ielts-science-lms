<?php
/**
 * Speaking Feedback Processor
 *
 * @package IeltsScienceLMS
 * @subpackage Speaking
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Speaking;

use WP_Error;
use Exception;
use IeltsScienceLMS\ApiFeeds\Ieltssci_ApiFeeds_DB;
use IeltsScienceLMS\API\Ieltssci_API_Client;
use IeltsScienceLMS\API\Ieltssci_Message_Handler;
use IeltsScienceLMS\MergeTags\Ieltssci_Merge_Tags_Processor;

/**
 * Class Ieltssci_Speaking_Feedback_Processor
 *
 * Handles the processing of feedback feeds for IELTS Science Speaking module.
 * Responsible for processing feed groups, API integration, and content generation.
 */
class Ieltssci_Speaking_Feedback_Processor {

	/**
	 * API Feeds database handler.
	 *
	 * @var Ieltssci_ApiFeeds_DB
	 */
	private $api_feeds_db;

	/**
	 * Callback function for sending messages back to client.
	 *
	 * @var callable
	 */
	private $message_callback;

	/**
	 * Message handler for sending progress updates.
	 *
	 * @var Ieltssci_Message_Handler
	 */
	private $message_handler;

	/**
	 * API Client instance.
	 *
	 * @var Ieltssci_API_Client
	 */
	private $api_client;

	/**
	 * Merge Tags processor instance.
	 *
	 * @var Ieltssci_Merge_Tags_Processor
	 */
	private $merge_tags_processor;

	/**
	 * Feedback database handler.
	 *
	 * @var Ieltssci_Speaking_Feedback_DB
	 */
	private $feedback_db;

	/**
	 * Constructor
	 *
	 * @param callable|null $message_callback Function to call when sending messages back to client.
	 */
	public function __construct( $message_callback = null ) {
		$this->api_feeds_db         = new Ieltssci_ApiFeeds_DB(); // Initialize API feeds database handler.
		$this->message_callback     = $message_callback; // Set message callback function.
		$this->merge_tags_processor = new Ieltssci_Merge_Tags_Processor(); // Initialize merge tags processor.

		// Initialize message handler.
		$this->message_handler = new Ieltssci_Message_Handler( $message_callback );

		// Initialize API client with message handler.
		$this->api_client = new Ieltssci_API_Client( message_handler: $this->message_handler );

		$this->feedback_db = new Ieltssci_Speaking_Feedback_DB();
	}

	/**
	 * Process a specific feed by ID
	 *
	 * @param int    $feed_id       ID of the feed to process.
	 * @param string $uuid          The UUID of the recording.
	 * @param string $language      The language of the feedback.
	 * @param string $feedback_style The sample feedback style provided by the user for the AI to replicate.
	 * @param string $guide_score   Human-guided scoring for the AI to consider.
	 * @param string $guide_feedback Human-guided feedback content for the AI to incorporate.
	 * @param string $refetch       Whether to refetch the content even if it exists.
	 * @return WP_Error|null Error or null on success.
	 * @throws Exception When feed processing fails.
	 */
	public function process_feed_by_id( $feed_id, $uuid, $language = 'en', $feedback_style = '', $guide_score = '', $guide_feedback = '', $refetch = '' ) {
		// Get the specific feed that needs processing.
		$feeds = $this->api_feeds_db->get_api_feeds(
			array(
				'feed_id' => $feed_id,
				'include' => array( 'meta' ),
				'limit'   => 1,
			)
		);

		if ( is_wp_error( $feeds ) ) {
			return $feeds;
		}

		if ( empty( $feeds ) ) {
			return new WP_Error( 404, 'Feed not found.' );
		}

		$feed = $feeds[0];

		try {
			// Process the feed (to be implemented).
			$this->process_feed( $feed, $uuid, $language, $feedback_style, $guide_score, $guide_feedback, $refetch );
			return null;
		} catch ( Exception $e ) {
			return new WP_Error( 500, $e->getMessage() );
		}
	}

	/**
	 * Process a feed
	 *
	 * @param array  $feed          The feed data.
	 * @param string $uuid          The UUID of the recording.
	 * @param string $language      The language of the feedback.
	 * @param string $feedback_style The sample feedback style provided by the user for the AI to replicate.
	 * @param string $guide_score   Human-guided scoring for the AI to consider.
	 * @param string $guide_feedback Human-guided feedback content for the AI to incorporate.
	 * @param string $refetch       Whether to refetch the content even if it exists.
	 *
	 * @throws Exception When feed processing fails.
	 */
	public function process_feed( $feed, $uuid, $language, $feedback_style = '', $guide_score = '', $guide_feedback = '', $refetch = '' ) {
		// Announce starting this feed.
		$this->send_message(
			'feed_start',
			array(
				'feed_id'           => $feed['id'],
				'feed_title'        => isset( $feed['feed_title'] ) ? $feed['feed_title'] : 'Speaking Feedback',
				'message'           => 'Starting speaking feedback processing...',
				'feedback_criteria' => isset( $feed['feedback_criteria'] ) ? $feed['feedback_criteria'] : '',
			)
		);

		try {
			$steps = isset( $feed['meta'] ) ? json_decode( $feed['meta'], true )['steps'] : array();

			// If refetch is set to a specific step, then only process that step, if refetch is set to 'all', then process all steps.
			if ( ! empty( $refetch ) && is_string( $refetch ) && 'all' !== $refetch ) {
				// Filter steps to only process the specific step requested.
				$steps = array_filter(
					$steps,
					function ( $step ) use ( $refetch ) {
						return isset( $step['step'] ) && $step['step'] === $refetch;
					}
				);
			}

			$results = array();

			foreach ( $steps as $step ) {
				$result    = $this->process_step( $step, $uuid, $feed, $language, $feedback_style, $guide_score, $guide_feedback, $refetch );
				$results[] = $result;
			}

			// Signal completion of this feed.
			$this->send_message(
				'feed_complete',
				array(
					'feed_id'  => $feed['id'],
					'status'   => 'success',
					'feedback' => $results,
				)
			);
		} catch ( Exception $e ) {
			// Handle error.
			$this->send_error(
				'feed_error',
				array(
					'feed_id'  => isset( $feed['id'] ) ? $feed['id'] : 0,
					'title'    => 'Error Processing Feedback',
					'message'  => $e->getMessage(),
					'ctaTitle' => 'Try Again',
					'ctaLink'  => '#',
				)
			);
			throw $e;
		}
	}

	/**
	 * Process a single step from a feed
	 *
	 * @param array  $step    The step configuration.
	 * @param string $uuid    The UUID of the recording.
	 * @param array  $feed    The feed data.
	 * @param string $language The language of the feedback.
	 * @param string $feedback_style The sample feedback style provided by the user for the AI to replicate.
	 * @param string $guide_score   Human-guided scoring for the AI to consider.
	 * @param string $guide_feedback Human-guided feedback content for the AI to incorporate.
	 * @param string $refetch       Whether to refetch the content even if it exists.
	 *
	 * @return string The processed content.
	 * @throws Exception Throw exception when requests fail.
	 */
	public function process_step( $step, $uuid, $feed, $language, $feedback_style = '', $guide_score = '', $guide_feedback = '', $refetch = '' ) {
		// Get settings from the step.
		$step_type = isset( $step['step'] ) ? $step['step'] : 'feedback';
		$sections  = isset( $step['sections'] ) ? $step['sections'] : array();

		// Default settings.
		$api_provider = 'google';
		$model        = 'gemini-2.0-flash-lite';
		$temperature  = 0.7;
		$max_tokens   = 2048;
		$prompt       = 'Hello.';
		$score_regex  = '/\d+/';

		$config = array();
		foreach ( $sections as $section ) {
			if ( isset( $section['section'] ) && ! empty( $section['section'] ) && isset( $section['fields'] ) ) {
				$section_key            = $section['section'];
				$config[ $section_key ] = array();

				foreach ( $section['fields'] as $field ) {
					if ( isset( $field['id'] ) && isset( $field['value'] ) ) {
						$config[ $section_key ][ $field['id'] ] = $field['value'];
					}
				}
			}
		}

		// Extract configuration settings from step.
		$english_prompt    = isset( $config['general-setting']['englishPrompt'] ) ? $config['general-setting']['englishPrompt'] : $prompt;
		$vietnamese_prompt = isset( $config['general-setting']['vietnamesePrompt'] ) ? $config['general-setting']['vietnamesePrompt'] : $prompt;
		$api_provider      = isset( $config['general-setting']['apiProvider'] ) ? $config['general-setting']['apiProvider'] : $api_provider;
		$model             = isset( $config['general-setting']['model'] ) ? $config['general-setting']['model'] : $model;
		$temperature       = isset( $config['advanced-setting']['temperature'] ) ? $config['advanced-setting']['temperature'] : $temperature;
		$max_tokens        = isset( $config['advanced-setting']['maxToken'] ) ? $config['advanced-setting']['maxToken'] : $max_tokens;
		$enable_thinking   = isset( $config['general-setting']['enable_thinking'] ) ? $config['general-setting']['enable_thinking'] : false;

		// Extract guided parameters from advanced settings.
		$guided_choice = isset( $config['advanced-setting']['guided_choice'] ) ? $config['advanced-setting']['guided_choice'] : null;
		$guided_regex  = isset( $config['advanced-setting']['guided_regex'] ) ? $config['advanced-setting']['guided_regex'] : null;
		$guided_json   = isset( $config['advanced-setting']['guided_json'] ) ? $config['advanced-setting']['guided_json'] : null;
		$guided_json_vi = isset( $config['advanced-setting']['guided_json_vi'] ) ? $config['advanced-setting']['guided_json_vi'] : null;

		// Determine if the source should be 'human' based on guide content.
		$source = 'ai';
		if ( ! empty( $guide_score ) || ! empty( $guide_feedback ) ) {
			$source = 'human';
		}

		// Handle special case for transcription step.
		if ( 'transcribe' === $step_type ) {
			$transcription_prompt = isset( $config['general-setting']['prompt'] ) ? $config['general-setting']['prompt'] : '';
			return $this->process_transcription_step( $uuid, $api_provider, $model, $transcription_prompt );
		}

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

		// Check if we should skip checking existing content based on refetch parameter.
		$should_check_existing = ! ( 'all' === $refetch || $step_type === $refetch );

		// Check if this step has already been processed.
		$existing_content = null;
		if ( $should_check_existing ) {
			$existing_content = $this->feedback_db->get_existing_step_content( $step_type, $feed, $uuid, $content_field );
		}

		// Get score regex if available in scoring step.
		if ( 'scoring' === $step_type && isset( $config['advanced-setting']['scoreRegex'] ) ) {
			$score_regex = $config['advanced-setting']['scoreRegex'];
		}

		// Select prompt based on language parameter.
		$selected_prompt = 'vi' === strtolower( $language ) ? $vietnamese_prompt : $english_prompt;

		// Select guided JSON based on language parameter with fallback.
		$selected_guided_json = 'vi' === strtolower( $language ) && ! empty( $guided_json_vi ) ? $guided_json_vi : $guided_json;

		// If existing content is found, send it back without making a new API call.
		if ( ! empty( $existing_content ) ) {
			// If this step has thinking enabled, check for existing chain-of-thought content.
			if ( $enable_thinking ) {
				$existing_cot = $this->feedback_db->get_existing_step_content( 'chain-of-thought', $feed, $uuid, 'cot_content' );

				if ( $existing_cot ) {
					// Stream the existing chain-of-thought content to the client.
					$this->send_message(
						'CHAIN_OF_THOUGHT',
						array(
							'content' => $existing_cot,
							'reused'  => true,
						)
					);
				}
				// Send DONE message to indicate completion of chain-of-thought.
				$this->send_done( 'CHAIN_OF_THOUGHT' );
			}

			$this->send_message(
				$this->transform_case( $step_type, 'snake_upper' ),
				array(
					'content' => $existing_content,
					'reused'  => true,
				)
			);
			// Send DONE message to indicate completion.
			$this->send_done( $this->transform_case( $step_type, 'snake_upper' ) );
			return $existing_content;
		}

		// Process merge tags in the prompt.
		$processed_prompt = $this->merge_tags_processor->process_merge_tags(
			$selected_prompt,
			$uuid,
			null, // No segment_order for speaking.
			$feedback_style,
			$guide_score,
			$guide_feedback
		);

		// Handle different prompt processing results.
		if ( is_array( $processed_prompt ) ) {
			// If it's an array, we'll use parallel API calls.
			$event_type   = 'batch_processing';
			$message_data = array(
				'total_prompts' => count( $processed_prompt ),
				'message'       => 'Starting parallel processing of multiple prompts',
			);

			$this->send_message( $event_type, $message_data );

			// Use API client for parallel API calls.
			$result = $this->api_client->make_parallel_api_calls( $api_provider, $model, $processed_prompt, $temperature, $max_tokens, $feed, $step_type, $guided_choice, $guided_regex, $selected_guided_json, $enable_thinking );

			// Check if result is a WP_Error.
			if ( is_wp_error( $result ) ) {
				$error_message = $result->get_error_message();
				$this->send_error(
					$this->transform_case( $step_type, 'snake_upper' ) . '_ERROR',
					array(
						'title'    => 'Parallel API Request Failed',
						'message'  => $error_message,
						'ctaTitle' => 'Try Again',
						'ctaLink'  => '#',
					)
				);
				throw new Exception( esc_html( 'Parallel API request failed: ' . $error_message ) );
			}

			// Process the result if it's a scoring step.
			if ( 'scoring' === $step_type ) {
				// If we have a guide_score, use it instead of the extracted score.
				if ( ! empty( $guide_score ) ) {
					// Send the AI reasoning and guide score to frontend.
					$this->send_message(
						$this->transform_case( $step_type, 'snake_upper' ),
						array(
							'content'     => $guide_score,
							'raw_content' => $result,
							'guided'      => true,
						)
					);

					// Override result with guide_score.
					$result = $guide_score;
				} else {
					// Extract score using regex as normal.
					$extracted_score = $this->extract_score_from_result( $result, $score_regex );
					// Send score to frontend.
					$this->send_message(
						$this->transform_case( $step_type, 'snake_upper' ),
						array(
							'content'     => $extracted_score,
							'raw_content' => $result,
							'regex_used'  => $score_regex,
						)
					);

					// Use extracted score as the result.
					$result = $extracted_score;
				}
			}
		} else {
			// For single prompt, proceed with appropriate call.
			$prompt = $processed_prompt;

			// If this is a scoring step and we have guide_score provided, still make the API call to get reasoning.
			// When step_type is 'scoring', the score will be extracted from the response.
			$result = $this->api_client->make_stream_api_call(
				$api_provider,
				$model,
				$prompt,
				$temperature,
				$max_tokens,
				$feed,
				$step_type,
				array(), // no images for speaking.
				$guided_choice,
				$guided_regex,
				$selected_guided_json,
				$enable_thinking,
				'scoring' === $step_type ? $score_regex : null
			);

			// Check if result is a WP_Error.
			if ( is_wp_error( $result ) ) {
				$error_message = $result->get_error_message();
				$this->send_error(
					$this->transform_case( $step_type, 'snake_upper' ) . '_ERROR',
					array(
						'title'    => 'API Request Failed',
						'message'  => $error_message,
						'ctaTitle' => 'Try Again',
						'ctaLink'  => '#',
					)
				);
				throw new Exception( esc_html( 'API request failed: ' . $error_message ) );
			}

			// If this is a scoring step and we have guide_score, use it instead of the extracted score.
			if ( 'scoring' === $step_type && ! empty( $guide_score ) ) {
				// Extract any reasoning from the result if it's an array.
				$reasoning_content = null;
				if ( is_array( $result ) && isset( $result['reasoning_content'] ) ) {
					$reasoning_content = $result['reasoning_content'];
				}

				// Send the AI reasoning and guide score to frontend.
				$this->send_message(
					$this->transform_case( $step_type, 'snake_upper' ),
					array(
						'content'     => $guide_score,
						'raw_content' => $result,
						'guided'      => true,
					)
				);

				// Override result with guide_score but preserve reasoning if available.
				if ( $reasoning_content ) {
					$result = array(
						'content'           => $guide_score,
						'reasoning_content' => $reasoning_content,
					);
				} else {
					$result = $guide_score;
				}
			}
		}

		// Process result based on type.
		if ( is_array( $result ) ) {
			$main_content      = $result['content'] ?? $result[0] ?? '';
			$reasoning_content = $result['reasoning_content'] ?? null;

			// Save the main content to the database based on step_type.
			$this->feedback_db->save_feedback_to_database(
				$main_content,
				$feed,
				$uuid,
				$step_type,
				$language,
				$source
			);

			// Save the reasoning content as chain-of-thought if available.
			if ( ! empty( $reasoning_content ) ) {
				// Save reasoning content to cot_content regardless of step_type.
				$this->feedback_db->save_feedback_to_database(
					$reasoning_content,
					$feed,
					$uuid,
					'chain-of-thought', // Force step_type to chain-of-thought.
					$language,
					$source
				);
			}

			// Set result to main content for return value.
			$result = $main_content;
		} else {
			// Enforce $result to be a string.
			$result = (string) $result;

			// Save the string result to the database.
			$this->feedback_db->save_feedback_to_database(
				$result,
				$feed,
				$uuid,
				$step_type,
				$language,
				$source
			);
		}

		// Send completion signal.
		$this->send_done( $this->transform_case( $step_type, 'snake_upper' ) );

		return $result;
	}

	/**
	 * Process transcription step for audio files
	 *
	 * @param string $uuid The UUID of the recording.
	 * @param string $api_provider The API provider to use.
	 * @param string $model The transcription model.
	 * @param string $prompt Additional prompt to guide transcription.
	 * @return string The concatenated responses from all transcription result API calls.
	 * @throws Exception If the transcription process fails.
	 */
	private function process_transcription_step( $uuid, $api_provider, $model, $prompt ) {
		// Get audio file paths associated with this recording.
		$speech_db = new Ieltssci_Speech_DB();
		$speech    = $speech_db->get_speeches(
			array(
				'uuid'     => $uuid,
				'per_page' => 1,
			)
		);

		if ( is_wp_error( $speech ) || empty( $speech ) ) {
			throw new Exception( 'Speech recording not found.' );
		}

		$audio_ids = $speech[0]['audio_ids'];
		if ( empty( $audio_ids ) ) {
			throw new Exception( 'No audio files found for this recording.' );
		}

		// Format audio files in the structure expected by make_parallel_transcription_api_call.
		$audio_files_to_transcribe = array();
		$existing_transcriptions   = array();

		// Check each audio file for existing transcriptions.
		foreach ( $audio_ids as $media_id ) {
			$file_path = get_attached_file( $media_id );
			if ( $file_path && file_exists( $file_path ) ) {
				// Check if this audio already has transcription metadata.
				$existing_transcription = get_post_meta( $media_id, 'ieltssci_audio_transcription', true );

				if ( ! empty( $existing_transcription ) ) {
					// Store existing transcription to use later.
					$existing_transcriptions[ $media_id ] = $existing_transcription;

					// Send message to indicate we're using cached transcription.
					$this->send_message(
						'TRANSCRIPTION_CACHE',
						array(
							'media_id' => $media_id,
							'message'  => 'Using cached transcription',
						)
					);
				} else {
					// Only add files without existing transcriptions to the list for API calls.
					$audio_files_to_transcribe[] = array(
						'media_id'  => $media_id,
						'file_path' => $file_path,
					);
				}
			}
		}

		// Set transcription parameters.
		$response_format         = 'verbose_json';
		$timestamp_granularities = array( 'word' );

		// Initialize transcription results with existing transcriptions.
		$transcription_results = $existing_transcriptions;

		// Only make API calls if there are files that need transcription.
		if ( ! empty( $audio_files_to_transcribe ) ) {
			$this->send_message(
				'TRANSCRIPTION_PROCESSING',
				array(
					'files_to_process' => count( $audio_files_to_transcribe ),
					'message'          => 'Processing new transcriptions',
				)
			);

			// Call the transcription API only for files without existing transcriptions.
			$new_transcription_results = $this->api_client->make_parallel_transcription_api_call(
				$api_provider,
				$model,
				$audio_files_to_transcribe,
				$prompt,
				$response_format,
				$timestamp_granularities
			);

			// Merge new transcription results.
			foreach ( $new_transcription_results as $media_id => $result ) {
				$transcription_results[ $media_id ] = $result;
			}
		}

		// Finished processing let's save results.
		$combined_transcript = '';

		// Process results based on media ID.
		foreach ( $transcription_results as $media_id => $result ) {
			if ( is_wp_error( $result ) ) {
				// Log error but continue with other transcriptions.
				$this->message_handler->send_error(
					'transcription_processing_error',
					array(
						'media_id' => $media_id,
						'title'    => 'Transcription Processing Error',
						'message'  => $result->get_error_message(),
					)
				);
				continue;
			}

			// Extract the transcript text.
			$transcript_text = isset( $result['text'] ) ? $result['text'] : '';

			if ( ! empty( $transcript_text ) ) {
				// Add to combined transcript.
				if ( ! empty( $combined_transcript ) ) {
					$combined_transcript .= "\n\n";
				}
				$combined_transcript .= $transcript_text;

				// Update the media attachment with the transcript as metadata if not already present.
				if ( ! isset( $existing_transcriptions[ $media_id ] ) ) {
					update_post_meta( $media_id, 'ieltssci_audio_transcription', $result );
				}
			}
		}

		// Send the transcription data to the client.
		$this->send_message(
			'TRANSCRIPTION_DATA',
			$transcription_results
		);
		return $combined_transcript;
	}

	/**
	 * Extract score from API result using regex pattern
	 *
	 * @param string $content The API response content.
	 * @param string $regex_pattern The regex pattern to extract the score.
	 * @return string The extracted score or original content if extraction fails.
	 */
	private function extract_score_from_result( $content, $regex_pattern ) {
		// Default to original content if regex is invalid.
		if ( empty( $regex_pattern ) ) {
			$regex_pattern = '/\d+/';
		}

		// Try to extract score using the provided regex pattern.
		$matches = array();
		if ( preg_match( $regex_pattern, $content, $matches ) ) {
			// Return the first match (should be the score).
			return trim( $matches[0] );
		}

		// If no match found, return the original content.
		return $content;
	}

	/**
	 * Transform string to different case formats
	 *
	 * @param string $input_string The input string.
	 * @param string $case_type The target case format (snake, camel, pascal, etc.).
	 * @return string The transformed string.
	 */
	private function transform_case( $input_string, $case_type = 'snake_upper' ) {
		// First normalize the string (remove special chars, replace spaces with underscores).
		$normalized = preg_replace( '/[^a-zA-Z0-9]/', ' ', $input_string );
		$normalized = preg_replace( '/\s+/', ' ', $normalized );
		$normalized = trim( $normalized );

		return match ( $case_type ) {
			'snake' => strtolower( str_replace( ' ', '_', $normalized ) ),
			'snake_upper' => strtoupper( str_replace( ' ', '_', $normalized ) ),
			'kebab' => strtolower( str_replace( ' ', '-', $normalized ) ),
			'camel' => lcfirst( str_replace( ' ', '', ucwords( $normalized ) ) ),
			'pascal' => str_replace( ' ', '', ucwords( $normalized ) ),
			'constant' => strtoupper( str_replace( ' ', '_', $normalized ) ),
			default => $normalized,
		};
	}

	/**
	 * Send an SSE message
	 *
	 * @param string $event_type The event type.
	 * @param mixed  $data The data to send.
	 */
	private function send_message( $event_type, $data ) {
		if ( is_callable( $this->message_callback ) ) {
			call_user_func( $this->message_callback, $event_type, $data, false, false );
		}
	}

	/**
	 * Send an error message
	 *
	 * @param string $event_type The event type.
	 * @param array  $error Error details with title, message, ctaTitle, and ctaLink.
	 */
	private function send_error( $event_type, $error ) {
		if ( is_callable( $this->message_callback ) ) {
			call_user_func( $this->message_callback, $event_type, $error, true, false );
		}
	}

	/**
	 * Send a done signal
	 *
	 * @param string $event_type The event type.
	 */
	private function send_done( $event_type = null ) {
		if ( is_callable( $this->message_callback ) ) {
			call_user_func( $this->message_callback, $event_type, null, false, true );
		}
	}
}
