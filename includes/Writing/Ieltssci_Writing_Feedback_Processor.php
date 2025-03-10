<?php
/**
 * Writing Feedback Processor
 *
 * @package IeltsScienceLMS
 * @subpackage Writing
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Writing;

use WP_Error;
use Exception;
use IeltsScienceLMS\ApiFeeds\Ieltssci_ApiFeeds_DB;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;

/**
 * Class Ieltssci_Writing_Feedback_Processor
 *
 * Handles the processing of feedback feeds for IELTS Science Writing module.
 * Responsible for processing feed groups, API integration, and content generation.
 */
class Ieltssci_Writing_Feedback_Processor {

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
	 * Constructor
	 *
	 * @param callable|null $message_callback Function to call when sending messages back to client.
	 */
	public function __construct( $message_callback = null ) {
		$this->api_feeds_db     = new Ieltssci_ApiFeeds_DB(); // Initialize API feeds database handler.
		$this->message_callback = $message_callback; // Set message callback function.
	}

	/**
	 * Process a specific feed by ID
	 *
	 * @param int    $feed_id ID of the feed to process.
	 * @param string $uuid The UUID of the essay.
	 * @return WP_Error|null Error or null on success.
	 * @throws Exception When feed processing fails.
	 */
	public function process_feed_by_id( $feed_id, $uuid ) {
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
			// Process the feed.
			$this->process_feed( $feed, $uuid );
			return null;
		} catch ( Exception $e ) {
			return new WP_Error( 500, $e->getMessage() );
		}
	}

	/**
	 * Process a feed
	 *
	 * @param array  $feed The feed data.
	 * @param string $uuid The UUID of the essay.
	 *
	 * @throws Exception When feed processing fails.
	 */
	public function process_feed( $feed, $uuid ) {
		// Announce starting this feed.
		$this->send_message(
			'feed_start',
			array(
				'feed_id'           => $feed['id'],
				'feed_title'        => isset( $feed['feed_title'] ) ? $feed['feed_title'] : 'Feedback',
				'feedback_criteria' => $feed['feedback_criteria'],
			)
		);

		try {
			$steps   = isset( $feed['meta'] ) ? json_decode( $feed['meta'], true )['steps'] : array();
			$results = array();

			foreach ( $steps as $step ) {
				$result    = $this->process_step( $step, $uuid, $feed );
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
	 * @param array  $step The step configuration.
	 * @param string $uuid The UUID of the essay.
	 * @param array  $feed The feed data.
	 * @return string The processed content.
	 */
	public function process_step( $step, $uuid, $feed ) {
		// Get settings from the step.
		$step_type = isset( $step['step'] ) ? $step['step'] : 'feedback';
		$sections  = isset( $step['sections'] ) ? $step['sections'] : array();

		// Default settings.
		$api_provider = 'google';
		$model        = 'gemini-2.0-flash-lite';
		$temperature  = 0.7;
		$max_tokens   = 2048;
		$prompt       = 'Hello.';

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

		$english_prompt = $config['general-setting']['englishPrompt'];
		$api_provider   = $config['general-setting']['apiProvider'];
		$model          = $config['general-setting']['model'];
		$temperature    = $config['advanced-setting']['temperature'];
		$max_tokens     = $config['advanced-setting']['maxToken'];

		// Process merge tags in the prompt.
		$processed_prompt = $this->process_merge_tags( $english_prompt, $uuid );

		// Check if the processed prompt is a string or an array.
		if ( is_array( $processed_prompt ) ) {
			// If it's an array, we'll use parallel API calls.
			$this->send_message(
				'batch_processing',
				array(
					'total_prompts' => count( $processed_prompt ),
					'message'       => 'Starting parallel processing of multiple prompts',
				)
			);

			// Use parallel API calls for array prompts.
			$result = $this->make_parallel_api_calls( $api_provider, $model, $processed_prompt, $temperature, $max_tokens, $feed, $step_type );
		} else {
			// For a single string prompt, proceed with streaming call.
			$prompt = $processed_prompt;
			$result = $this->make_stream_api_call( $api_provider, $model, $prompt, $temperature, $max_tokens, $feed, $step_type );
		}

		// Save the results to the database.
		$this->save_feedback_to_database( $result, $feed, $uuid, $step_type );

		return $result;
	}

	/**
	 * Save feedback results to the database
	 *
	 * @param string $feedback The feedback content to save.
	 * @param array  $feed The feed configuration data.
	 * @param string $uuid The UUID of the essay.
	 * @param string $step_type The type of step being processed.
	 * @return bool True on success, false on failure.
	 */
	private function save_feedback_to_database( $feedback, $feed, $uuid, $step_type ) {
		// Placeholder function that will be filled in later.

		// Check if we have a valid apply_to setting.
		if ( empty( $feed['apply_to'] ) ) {
			return false;
		}

		// Get the apply_to value which determines where to save.
		$apply_to = $feed['apply_to'];

		// TODO: Implement the actual database saving logic based on the apply_to value.
		// Possible values might be 'essay', 'paragraph', 'introduction', 'topic-sentence', 'main-point, 'conclusion'.
		switch ( $apply_to ) {

			case 'essay':
				// Save the essay-level feedback.
				$this->save_essay_feedback( $uuid, $feedback, $feed, $step_type );
				break;

			case 'paragraph':
				// Save the segments.
				$this->save_paragraph_feedback( $uuid, $feedback, $feed, $step_type );
				break;

			// Add other cases as needed.
		}
		return true;
	}

	/**
	 * Save essay feedback to the database
	 *
	 * @param string $uuid The UUID of the essay.
	 * @param string $feedback The feedback content to save.
	 * @param array  $feed The feed data (containing feedback criteria, etc.).
	 * @param string $step_type The type of step (chain-of-thought, scoring, feedback).
	 * @return int|WP_Error|bool ID of created feedback, error, or false if skipped.
	 */
	private function save_essay_feedback( $uuid, $feedback, $feed, $step_type ) {
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
		$feedback_language = isset( $feed['feedback_language'] ) ? $feed['feedback_language'] : 'en';
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
			'feedback_language' => $feedback_language,
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
				$segments = $this->extract_segment_content( $paragraph_content );

				foreach ( $segments as $segment ) {
					if ( empty( $segment['title'] ) || empty( $segment['content'] ) ) {
						continue;
					}

					++$segment_order;

					$segment_type = $this->determine_segment_type( $segment['title'] );

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

	/**
	 * Extract segments title and content from the paragraph content
	 *
	 * @param string $paragraph_content The full paragraph feedback content.
	 * @return array The extracted segments title & content.
	 */
	private function extract_segment_content( $paragraph_content ) {
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
	private function determine_segment_type( $segment_title ) {
		$title_lower = strtolower( $segment_title );

		if ( strpos( $title_lower, 'introduction' ) !== false ) {
			return 'introduction';
		} elseif ( strpos( $title_lower, 'conclusion' ) !== false ) {
			return 'conclusion';
		} elseif ( strpos( $title_lower, 'topic sentence' ) !== false ) {
			return 'topic-sentence';
		} elseif ( strpos( $title_lower, 'main point' ) !== false ) {
			return 'main-point';
		} else {
			return 'unknown';
		}
	}

	/**
	 * Make parallel API calls to the language model for multiple prompts
	 *
	 * @param string $api_provider The API provider to use.
	 * @param string $model The model name.
	 * @param array  $prompts Array of prompts to send.
	 * @param float  $temperature The temperature setting.
	 * @param int    $max_tokens The maximum tokens to generate.
	 * @param array  $feed The feed data.
	 * @param string $step_type The type of step being processed.
	 * @return string The concatenated responses from all API calls.
	 */
	private function make_parallel_api_calls( $api_provider, $model, $prompts, $temperature, $max_tokens, $feed, $step_type ) {
		// Get client settings.
		$client_settings = $this->get_client_settings( $api_provider );

		// Decider Function: Determines IF a request should be retried.
		$decider = function ( $retries, $request, $response, $exception ) {
			// 1. Limit the maximum number of retries.
			if ( $retries >= 3 ) {  // Max retries.
				return false;
			}

			return true;
		};

		// Add handler stack with retry middleware.
		$stack = HandlerStack::create();
		$stack->push( Middleware::retry( $decider ) );
		$client_settings['handler'] = $stack;

		$client = new \GuzzleHttp\Client( $client_settings );

		// Track results and errors.
		$responses_by_index = array();
		$errors_by_index    = array();
		$processed_count    = 0;
		$total_prompts      = count( $prompts );

		// Generate request objects.
		$requests = function ( $prompts ) use ( $api_provider, $model, $temperature, $max_tokens ) {
			foreach ( $prompts as $index => $prompt ) {
				// Prepare request payload for this prompt.
				$payload = $this->get_request_payload( $api_provider, $model, $prompt, $temperature, $max_tokens, false );

				// Get headers including API key.
				$headers = $this->get_request_headers( $api_provider, false );

				// Yield request with index metadata.
				yield $index => new Request(
					'POST',
					'chat/completions',
					$headers,
					wp_json_encode( $payload )
				);
			}
		};

		// Set up the request pool.
		$pool = new Pool(
			$client,
			$requests( $prompts ),
			array(
				'concurrency' => 5, // Process 5 requests at a time.
				'fulfilled'   => function ( $response, $index ) use ( &$responses_by_index, &$processed_count, $total_prompts, $step_type ) {
					// Process successful response.
					$body    = $response->getBody()->getContents();
					$content = $this->extract_content_from_full_response( $body );

					if ( $content ) {
						// Store response by index to maintain order.
						$responses_by_index[ $index ] = $content;

						// Update progress.
						$processed_count++;
						$progress = round( ( $processed_count / $total_prompts ) * 100 );

						// Send progress update.
						$this->send_message(
							'parallel_progress',
							array(
								'index'     => $index,
								'total'     => $total_prompts,
								'processed' => $processed_count,
								'progress'  => $progress,
							)
						);

						// Send content chunk.
						$this->send_message(
							$this->transform_case( $step_type, 'snake_upper' ),
							array(
								'index'     => $index,
								'timestamp' => gmdate( 'Y-m-d H:i:s.u' ),
								'content'   => $content,
							)
						);
					}
				},
				'rejected'    => function ( $reason, $index ) use ( &$errors_by_index, &$processed_count, $total_prompts ) {
					// Handle failed request.
					$error_message = $reason instanceof RequestException
					? ( $reason->hasResponse() ? $reason->getResponse()->getBody()->getContents() : $reason->getMessage() )
					: 'Unknown error';

					$errors_by_index[ $index ] = $error_message;

					// Update progress even for errors.
					$processed_count++;
					$progress = round( ( $processed_count / $total_prompts ) * 100 );

					// Send error message.
					$this->send_error(
						'parallel_error',
						array(
							'index'    => $index,
							'title'    => 'API Request Failed',
							'message'  => $error_message,
							'progress' => $progress,
						)
					);
				},
			)
		);

		// Execute all requests and wait for completion.
		$promise = $pool->promise();
		$promise->wait();

		// Final processing of results.
		$full_response = '';
		if ( ! empty( $responses_by_index ) ) {
			// Sort responses by index to maintain original order.
			ksort( $responses_by_index );

			// Build the combined response.
			foreach ( $responses_by_index as $index => $response ) {
				$full_response .= $response;

				// Add separator if not the last response.
				if ( $index < count( $prompts ) - 1 ) {
					$full_response .= "\n\n---\n\n";
				}
			}
		}

		// Add error messages for any failed requests.
		if ( ! empty( $errors_by_index ) ) {
			foreach ( $errors_by_index as $index => $error ) {
				if ( ! isset( $responses_by_index[ $index ] ) ) {
					$error_text = "Error processing prompt #{$index}: {$error}";
					if ( $full_response ) {
						$full_response .= "\n\n---\n\n{$error_text}";
					} else {
						$full_response = $error_text;
					}
				}
			}
		}

		// Final completion message.
		$this->send_message(
			'parallel_complete',
			array(
				'total_prompts' => $total_prompts,
				'successful'    => count( $responses_by_index ),
				'failed'        => count( $errors_by_index ),
			)
		);

		return $full_response;
	}

	/**
	 * Make an API call to the language model with streaming
	 *
	 * @param string $api_provider The API provider to use.
	 * @param string $model The model name.
	 * @param string $prompt The prompt to send.
	 * @param float  $temperature The temperature setting.
	 * @param int    $max_tokens The maximum tokens to generate.
	 * @param array  $feed The feed data.
	 * @param string $step_type The type of step being processed.
	 * @return string The response from the API.
	 */
	private function make_stream_api_call( $api_provider, $model, $prompt, $temperature, $max_tokens, $feed, $step_type ) {
		// Get client settings.
		$client_settings = $this->get_client_settings( $api_provider );

		// Get headers including API key.
		$headers = $this->get_request_headers( $api_provider, true );

		// Combine settings and headers.
		$client_settings['headers'] = $headers;

		// Decider Function: Determines IF a request should be retried.
		$decider = function ( $retries, $request, $response, $exception ) {
			// 1. Limit the maximum number of retries.
			if ( $retries >= 3 ) {  // Max retries.
				return false;
			}

			return true;
		};

		// Add handler stack with retry middleware.
		$stack = HandlerStack::create();
		$stack->push( Middleware::retry( $decider ) );
		$client_settings['handler'] = $stack;

		$client = new \GuzzleHttp\Client( $client_settings );

		// Prepare request payload based on the API provider.
		$payload = $this->get_request_payload( $api_provider, $model, $prompt, $temperature, $max_tokens, true );

		$endpoint = 'chat/completions';

		$response = $client->request(
			'POST',
			$endpoint,
			array(
				'json'           => $payload,
				'stream'         => true,
				'decode_content' => true,
			)
		);

		$full_response = '';
		$stream        = $response->getBody();

		// Get a PHP stream resource from Guzzle's stream.
		$handle = $stream->detach();

		// Set stream to non-blocking mode for better responsiveness.
		stream_set_blocking( $handle, false );

		// Process the stream.
		while ( ! feof( $handle ) ) {
			$line = fgets( $handle );

			// If no data is available yet in non-blocking mode, wait briefly and continue.
			if ( false === $line ) {
				usleep( 10000 ); // Sleep for 10ms to avoid CPU spinning.
				continue;
			}

			// Skip empty lines.
			if ( '' === $line ) {
				continue;
			}

			// Process data lines based on provider format.
			if ( 0 === strpos( $line, 'data: ' ) ) {
				$content_chunk = $this->extract_content( $api_provider, $line );

				if ( '[DONE]' === $content_chunk ) {
					break;
				}

				if ( $content_chunk ) {
					$full_response .= $content_chunk;

					// Send chunk immediately.
					$this->send_message(
						$this->transform_case( $step_type, 'snake_upper' ),
						array(
							'timestamp' => gmdate( 'Y-m-d H:i:s.u' ),
							'content'   => $content_chunk,
							'step_type' => $step_type,
						)
					);
				}
			}
		}

		// Ensure we close the stream handle.
		fclose( $handle );

		return $full_response;
	}

	/**
	 * Process merge tags in a prompt string
	 *
	 * @param string $prompt The prompt string containing merge tags.
	 * @param string $uuid The UUID of the essay to use for fetching content.
	 * @return string|array The processed prompt with merge tags replaced, or an array if a modifier results in an array.
	 */
	private function process_merge_tags( $prompt, $uuid ) {
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

			// Fetch the content based on parameters and UUID.
			$content = $this->fetch_content_for_merge_tag( $parameters, $uuid );

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

				$content = $this->fetch_content_for_merge_tag( $parameters, $uuid );

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

				$content     = $this->fetch_content_for_merge_tag( $parameters, $uuid );
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
	 * @param string $parameters The parameters specifying what content to fetch.
	 * @param string $uuid The UUID of the essay.
	 * @return array|string|null The content to replace the merge tag with, or null if not found.
	 */
	private function fetch_content_for_merge_tag( $parameters, $uuid ) {
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
			$content = $this->get_content_from_database( $table, $field, $filter_field, $filter_value, $uuid );

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
	 * @param string $table The table/source to fetch from.
	 * @param string $field The field to retrieve.
	 * @param string $filter_field The field to filter by.
	 * @param string $filter_value The value to filter with.
	 * @param string $uuid The UUID of the essay (always required).
	 * @return string|array|null The retrieved content, array of values, or null if not found.
	 */
	private function get_content_from_database( $table, $field, $filter_field, $filter_value, $uuid ) {
		// Skip if any required parameter is missing.
		if ( empty( $table ) || empty( $field ) || empty( $uuid ) ) {
			return null;
		}

		// List of supported tables.
		$supported_tables = array( 'essay', 'segment', 'essay_feedback', 'segment_feedback' );

		if ( ! in_array( $table, $supported_tables, true ) ) {
			return null;
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
					if ( count( $essays ) === 1 ) {
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
					if ( count( $segments ) === 1 ) {
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
					if ( count( $feedbacks ) === 1 ) {
						// Return just the specific field for single result.
						return isset( $feedbacks[0][ $field ] ) ? $feedbacks[0][ $field ] : null;
					} else {
						// For multiple results, create an array of field values.
						$values = array_column( $feedbacks, $field );
						return ! empty( $values ) ? $values : null;
					}
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

				// Get segments for this essay.
				$segments = $essay_db->get_segments(
					array(
						'essay_id' => $essay_id,
						'fields'   => array( 'id' ),
					)
				);
				if ( is_wp_error( $segments ) || empty( $segments ) ) {
					return null;
				}

				// Extract segment IDs.
				$segment_ids = array_column( $segments, 'id' );

				// Build query for segment feedbacks.
				$query_args = array(
					'segment_id' => $segment_ids,
				);

				// Add additional filter if provided.
				if ( ! empty( $filter_field ) && ! empty( $filter_value ) ) {
					$query_args[ $filter_field ] = $filter_value;
				}

				// Add fields filter.
				if ( '*' !== $field ) {
					$query_args['fields'] = array( $field );
				}

				$feedbacks = $essay_db->get_segment_feedbacks( $query_args );
				if ( ! is_wp_error( $feedbacks ) && ! empty( $feedbacks ) ) {
					if ( count( $feedbacks ) === 1 ) {
						// Return just the specific field for single result.
						return isset( $feedbacks[0][ $field ] ) ? $feedbacks[0][ $field ] : null;
					} else {
						// For multiple results, create an array of field values.
						$values = array_column( $feedbacks, $field );
						return ! empty( $values ) ? $values : null;
					}
				}
				break;
		}

		// If we get here, no results were found.
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
		if ( count( $paragraphs ) <= 1 && strpos( $text, "\n" ) !== false ) {
			$paragraphs = explode( "\n", $text );
		}

		// Filter out empty paragraphs and trim each paragraph.
		$paragraphs = array_filter( array_map( 'trim', $paragraphs ) );

		// Return indexed array (not associative).
		return array_values( $paragraphs );
	}

	/**
	 * Build API request headers
	 *
	 * @param string $provider API provider name.
	 * @param bool   $streaming Whether this is for streaming requests.
	 * @return array Request headers.
	 * @throws Exception When API key is not found for the provider.
	 */
	private function get_request_headers( $provider, $streaming = true ) {
		// Get API key.
		$api_keys_db = new \IeltsScienceLMS\ApiKeys\Ieltssci_ApiKeys_DB();
		$api_key     = $api_keys_db->get_api_key(
			0,
			array(
				'provider'        => $provider,
				'increment_usage' => true,
			)
		);

		if ( ! $api_key && 'home-server' !== $provider ) {
			throw new Exception( esc_html( "API key not found for provider: {$provider}" ) );
		}

		$accept_header = $streaming ? 'text/event-stream' : 'application/json';

		switch ( $provider ) {
			case 'google':
				return array(
					'Authorization' => 'Bearer ' . $api_key['meta']['api-key'],
					'Content-Type'  => 'application/json',
					'Accept'        => $accept_header,
				);

			case 'openai':
				return array(
					'Authorization' => 'Bearer ' . $api_key['meta']['api-key'],
					'Content-Type'  => 'application/json',
					'Accept'        => $accept_header,
				);
			case 'open-key-ai':
				return array(
					'Authorization' => 'Bearer ' . $api_key['meta']['api-key'],
					'Content-Type'  => 'application/json',
					'Accept'        => $accept_header,
				);
			case 'home-server':
				return array(
					'Content-Type' => 'application/json',
					'Accept'       => $accept_header,
				);

			default:
				// Default to OpenAI-style headers.
				return array(
					'Authorization' => 'Bearer ' . $api_key['meta']['api-key'],
					'Content-Type'  => 'application/json',
					'Accept'        => $accept_header,
				);
		}
	}

	/**
	 * Get client settings based on API provider
	 *
	 * @param string $provider API provider name.
	 * @return array Client configuration without headers.
	 */
	private function get_client_settings( $provider ) {
		$settings = array(
			'connect_timeout' => 5,
			'timeout'         => 120,
			'read_timeout'    => 120,
		);

		// Get the base URI for the provider.
		switch ( $provider ) {
			case 'google':
				$settings['base_uri'] = 'https://generativelanguage.googleapis.com/v1beta/openai/';
				break;

			case 'openai':
				$settings['base_uri'] = 'https://api.openai.com/v1/';
				break;
			case 'open-key-ai':
				$settings['base_uri'] = 'https://api.hakai.shop/v1/';
				break;

			case 'home-server':
				$settings['base_uri'] = 'https://lorax.ieltsscience.fun/v1/';
				break;
			default:
				// Default to OpenAI.
				$settings['base_uri'] = 'https://api.openai.com/v1/';
		}

		return $settings;
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
	 * Get request payload based on API provider
	 *
	 * @param string $provider API provider name.
	 * @param string $model Model name.
	 * @param string $prompt Prompt text.
	 * @param float  $temperature Temperature setting.
	 * @param int    $max_tokens Maximum tokens.
	 * @param bool   $stream Whether to stream the response or not.
	 * @return array Request payload.
	 * @throws Exception When API key is not found for the provider.
	 */
	private function get_request_payload( $provider, $model, $prompt, $temperature, $max_tokens, $stream = true ) {
		if ( 'home-server' === $provider ) {
			// Get API key for huggingface when using home-server.
			$api_keys_db = new \IeltsScienceLMS\ApiKeys\Ieltssci_ApiKeys_DB();
			$api_key     = $api_keys_db->get_api_key(
				0,
				array(
					'provider'        => 'huggingface',
					'increment_usage' => true,
				)
			);

			if ( ! $api_key ) {
				throw new Exception( esc_html( 'HF API key not found for provider: home-server' ) );
			}
		}

		return match ( $provider ) {
			'openai' => array(
				'model'       => $model,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
				'stream'      => $stream,
			),
			'open-key-ai' => array(
				'model'       => $model,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
				'stream'      => $stream,
			),
			'home-server' => array(
				'model'       => $model,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
				'stream'      => $stream,
				'api_token'   => $api_key['meta']['api-key'],
			),
			default => array(
				'model'       => $model,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
				'stream'      => $stream,
			),
		};
	}

	/**
	 * Extract content from a data line based on provider format
	 *
	 * @param string $provider API provider name.
	 * @param string $line Line from response.
	 * @return string|null Content or null if no content.
	 */
	private function extract_content( $provider, $line ) {
		$data = substr( $line, 6 ); // Remove "data: " prefix.

		// Check for [DONE] message.
		if ( '[DONE]' === trim( $data ) ) {
			return '[DONE]';
		}

		switch ( $provider ) {
			case 'google':
				$chunk = json_decode( $data, true );
				if ( json_last_error() === JSON_ERROR_NONE &&
					isset( $chunk['choices'][0]['delta']['content'] ) ) {
					return $chunk['choices'][0]['delta']['content'];
				}
				break;

			case 'openai':
			case 'open-key-ai':
				$chunk = json_decode( $data, true );
				if ( json_last_error() === JSON_ERROR_NONE &&
					isset( $chunk['choices'][0]['delta']['content'] ) ) {
					return $chunk['choices'][0]['delta']['content'];
				}
				break;

			default:
				$chunk = json_decode( $data, true );
				if ( json_last_error() === JSON_ERROR_NONE &&
					isset( $chunk['choices'][0]['delta']['content'] ) ) {
					return $chunk['choices'][0]['delta']['content'];
				}
		}

		return null;
	}

	/**
	 * Extract content from a full non-streaming API response
	 *
	 * @param string $response_body Full response body as JSON string.
	 * @return string|null The extracted content or null if not found.
	 */
	private function extract_content_from_full_response( $response_body ) {
		if ( empty( $response_body ) ) {
			return null;
		}

		$response_data = json_decode( $response_body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return 'Error parsing response: ' . json_last_error_msg();
		}

		// Extract content based on typical API response structure.
		if ( isset( $response_data['choices'][0]['message']['content'] ) ) {
			return $response_data['choices'][0]['message']['content'];
		} elseif ( isset( $response_data['choices'][0]['text'] ) ) {
			return $response_data['choices'][0]['text'];
		}

		return null;
	}

	/**
	 * Send an SSE message
	 *
	 * @param string $event_type The event type.
	 * @param mixed  $data The data to send.
	 */
	private function send_message( $event_type, $data ) {
		if ( is_callable( $this->message_callback ) ) {
			call_user_func( $this->message_callback, $event_type, $data );
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
			call_user_func( $this->message_callback, $event_type, $error, true );
		}
	}
}
