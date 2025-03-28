<?php
/**
 * API Client for AI services integration
 *
 * @package IeltsScienceLMS
 * @subpackage API
 * @since 1.0.0
 */

namespace IeltsScienceLMS\API;

use Exception;
use WP_Error;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Pool;

/**
 * Class Ieltssci_API_Client
 *
 * Handles communication with external AI APIs.
 */
class Ieltssci_API_Client {

	/**
	 * Message handler for sending progress updates.
	 *
	 * @var Ieltssci_Message_Handler
	 */
	private $message_handler;

	/**
	 * Constructor
	 *
	 * @param Ieltssci_Message_Handler $message_handler Message handler for progress updates.
	 */
	public function __construct( $message_handler ) {
		$this->message_handler = $message_handler;
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
				$settings['base_uri'] = 'http://api3.ieltsscience.fun/v1/';
				break;
			case 'home-server-whisperx-api-server':
				$settings['base_uri'] = 'https://api3.ieltsscience.fun/v1/';
				break;
			default:
				// Default to OpenAI.
				$settings['base_uri'] = 'https://api.openai.com/v1/';
		}

		return $settings;
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

		if ( ! $api_key && 'home-server' !== $provider && 'home-server-whisperx-api-server' !== $provider ) {
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
			case 'home-server-whisperx-api-server':
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
	 * Get request payload based on API provider
	 *
	 * @param string $provider API provider name.
	 * @param string $model Model name.
	 * @param string $prompt Prompt text.
	 * @param float  $temperature Temperature setting.
	 * @param int    $max_tokens Maximum tokens.
	 * @param bool   $stream Whether to stream the response or not.
	 * @param array  $images Array of base64-encoded images.
	 * @return array Request payload.
	 * @throws Exception When API key is not found for the provider.
	 */
	private function get_request_payload( $provider, $model, $prompt, $temperature, $max_tokens, $stream = true, $images = array() ) {
		if ( 'home-server' === $provider ) {
			// Get API key for huggingface when using home-server.
			$api_keys_db = new \IeltsScienceLMS\ApiKeys\Ieltssci_ApiKeys_DB();
			$api_key     = $api_keys_db->get_api_key(
				0,
				array(
					'provider'        => 'huggingface',
					'increment_usage' => false,
				)
			);

			if ( ! $api_key ) {
				throw new Exception( esc_html( 'HF API key not found for provider: home-server' ) );
			}
		}

		// Base message content.
		$message_content = array();

		// Set up message content based on whether we have images.
		if ( empty( $images ) ) {
			$message_content = array(
				'role'    => 'user',
				'content' => $prompt,
			);
		} else {
			// For multi-modal content, create content array.
			$message_content = array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => $prompt,
					),
				),
			);

			// Add images to content.
			foreach ( $images as $image ) {
				if ( isset( $image['base64'] ) ) {
					$message_content['content'][] = array(
						'type'      => 'image_url',
						'image_url' => array(
							'url' => $image['base64'],
						),
					);
				}
			}
		}

		$base_payload = array(
			'model'       => $model,
			'messages'    => array( $message_content ),
			'temperature' => $temperature,
			'max_tokens'  => $max_tokens,
			'stream'      => $stream,
		);

		// Add API token for home-server.
		if ( 'home-server' === $provider ) {
			$base_payload['api_token'] = $api_key['meta']['api-key'];
		}

		return $base_payload;
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
				if ( JSON_ERROR_NONE === json_last_error() &&
					isset( $chunk['choices'][0]['delta']['content'] ) ) {
					return $chunk['choices'][0]['delta']['content'];
				}
				break;

			case 'openai':
			case 'open-key-ai':
				$chunk = json_decode( $data, true );
				if ( JSON_ERROR_NONE === json_last_error() &&
					isset( $chunk['choices'][0]['delta']['content'] ) ) {
					return $chunk['choices'][0]['delta']['content'];
				}
				break;

			default:
				$chunk = json_decode( $data, true );
				if ( JSON_ERROR_NONE === json_last_error() &&
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
		if ( JSON_ERROR_NONE !== json_last_error() ) {
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
	 * Make an API call to the language model with streaming
	 *
	 * @param string $api_provider The API provider to use.
	 * @param string $model The model name.
	 * @param string $prompt The prompt to send.
	 * @param float  $temperature The temperature setting.
	 * @param int    $max_tokens The maximum tokens to generate.
	 * @param array  $feed The feed data.
	 * @param string $step_type The type of step being processed.
	 * @param array  $images Array of base64-encoded images.
	 * @return string The full accumulated response from the API.
	 */
	public function make_stream_api_call( $api_provider, $model, $prompt, $temperature, $max_tokens, $feed, $step_type, $images = array() ) {
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

		$client = new Client( $client_settings );

		// Prepare request payload based on the API provider.
		$payload = $this->get_request_payload( $api_provider, $model, $prompt, $temperature, $max_tokens, true, $images );

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
					$this->message_handler->send_message(
						$this->message_handler->transform_case( $step_type, 'snake_upper' ),
						array(
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
	 * Make a non-streaming API call specifically for scoring
	 *
	 * @param string $api_provider The API provider to use.
	 * @param string $model The model name.
	 * @param string $prompt The prompt to send.
	 * @param float  $temperature The temperature setting.
	 * @param int    $max_tokens The maximum tokens to generate.
	 * @param string $score_regex Regex pattern to extract score from the response.
	 * @param array  $images Array of base64-encoded images.
	 * @return string The extracted score or full response if score extraction fails.
	 */
	public function make_score_api_call( $api_provider, $model, $prompt, $temperature, $max_tokens, $score_regex, $images = array() ) {
		// Get client settings.
		$client_settings = $this->get_client_settings( $api_provider );

		// Get headers including API key.
		$headers = $this->get_request_headers( $api_provider, false );

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

		$client = new Client( $client_settings );

		// Prepare request payload based on the API provider - no streaming.
		$payload = $this->get_request_payload( $api_provider, $model, $prompt, $temperature, $max_tokens, false, $images );

		$endpoint = 'chat/completions';

		try {
			$response = $client->request(
				'POST',
				$endpoint,
				array(
					'json' => $payload,
				)
			);

			$response_body = $response->getBody()->getContents();
			$full_content  = $this->extract_content_from_full_response( $response_body );

			// Extract score from the content using regex.
			$extracted_score = $this->extract_score_from_result( $full_content, $score_regex );

			// Send both raw content and extracted score to frontend.
			$this->message_handler->send_message(
				'SCORE_RESULT',
				array(
					'raw_content' => $full_content,
					'score'       => $extracted_score,
					'regex_used'  => $score_regex,
				)
			);

			// Return the extracted score.
			return $extracted_score;

		} catch ( Exception $e ) {
			// Send error message in case of failure.
			$this->message_handler->send_error(
				'score_error',
				array(
					'title'   => 'Scoring Error',
					'message' => $e->getMessage(),
				)
			);
			return 'Error: ' . $e->getMessage();
		}
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
	public function make_parallel_api_calls( $api_provider, $model, $prompts, $temperature, $max_tokens, $feed, $step_type ) {
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

		$client = new Client( $client_settings );

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
						$this->message_handler->send_message(
							'parallel_progress',
							array(
								'index'     => $index,
								'total'     => $total_prompts,
								'processed' => $processed_count,
								'progress'  => $progress,
							)
						);

						// Send content chunk.
						$this->message_handler->send_message(
							$this->message_handler->transform_case( $step_type, 'snake_upper' ),
							array(
								'index'   => $index,
								'content' => $content,
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
					$this->message_handler->send_error(
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
		$this->message_handler->send_message(
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
	 * Get request payload for audio transcription
	 *
	 * @param string $file_path Local path to the audio file.
	 * @param string $model Model name for transcription.
	 * @param string $prompt Optional prompt to guide transcription.
	 * @param string $response_format Format of the response.
	 * @param array  $timestamp_granularities Timestamp granularity options.
	 * @return array Multipart form data array for Guzzle.
	 * @throws Exception When file doesn't exist or can't be read.
	 */
	private function get_transcription_payload( $file_path, $model, $prompt = '', $response_format = 'verbose_json', $timestamp_granularities = array( 'word' ) ) {
		// Check if file exists and is readable.
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new Exception( esc_html( "Audio file not found or not readable: {$file_path}" ) );
		}

		// Create base multipart payload.
		$multipart = array(
			array(
				'name'     => 'file',
				'contents' => fopen( $file_path, 'r' ),
				'filename' => basename( $file_path ),
			),
			array(
				'name'     => 'model',
				'contents' => $model,
			),
			array(
				'name'     => 'response_format',
				'contents' => $response_format,
			),
		);

		// Add timestamp granularities if provided.
		if ( ! empty( $timestamp_granularities ) ) {
			$multipart[] = array(
				'name'     => 'timestamp_granularities',
				'contents' => wp_json_encode( $timestamp_granularities ),
			);
		}

		$multipart[] = array(
			'name'     => 'language',
			'contents' => 'en',
		);

		// Add prompt if provided.
		if ( ! empty( $prompt ) ) {
			$multipart[] = array(
				'name'     => 'prompt',
				'contents' => $prompt,
			);
		}

		return $multipart;
	}

	/**
	 * Make parallel API calls to transcribe multiple audio files
	 *
	 * @param string $api_provider The API provider to use.
	 * @param string $model The transcription model to use.
	 * @param array  $audio_files Array of local paths to audio files.
	 * @param string $prompt Optional prompt to guide transcription.
	 * @param string $response_format Desired response format (default: 'verbose_json').
	 * @param array  $timestamp_granularities Options for timestamp granularity (e.g., ['word']).
	 * @return array|WP_Error Array of transcription results indexed by file paths, or WP_Error on failure.
	 */
	public function make_parallel_transcription_api_call( $api_provider, $model, $audio_files, $prompt = '', $response_format = 'verbose_json', $timestamp_granularities = array( 'word' ) ) {
		try {
			// Get client settings.
			$client_settings = $this->get_client_settings( $api_provider );

			// Get headers including API key.
			$headers = $this->get_request_headers( $api_provider, false );

			// Combine settings and headers.
			$client_settings['headers'] = $headers;

			// Add retry middleware.
			$stack = HandlerStack::create();
			$stack->push(
				Middleware::retry(
					function ( $retries ) {
						return $retries < 3; // Max 3 retries.
					}
				)
			);
			$client_settings['handler'] = $stack;

			$client = new Client( $client_settings );

			// Send progress update that transcription has started.
			$this->message_handler->send_message(
				'TRANSCRIPTION_START',
				array(
					'message'     => 'Starting batch audio transcription...',
					'model'       => $model,
					'total_files' => count( $audio_files ),
				)
			);

			// Track results and errors.
			$results         = array();
			$errors          = array();
			$processed_count = 0;
			$total_files     = count( $audio_files );
			$endpoint        = 'audio/transcriptions';

			// Generate request objects.
			$requests = function ( $audio_files ) use ( $api_provider, $model, $prompt, $response_format, $timestamp_granularities, $endpoint ) {
				foreach ( $audio_files as $index => $audio_file ) {
					try {
						// Check if we have the expected structure with media_id and file_path.
						if ( ! isset( $audio_file['file_path'] ) || ! isset( $audio_file['media_id'] ) ) {
							throw new Exception( 'Invalid audio file data structure. Both media_id and file_path are required.' );
						}

						$file_path = $audio_file['file_path'];
						$media_id  = $audio_file['media_id'];

						// Generate multipart payload for this file.
						$multipart_payload = $this->get_transcription_payload(
							$file_path,
							$model,
							$prompt,
							$response_format,
							$timestamp_granularities
						);

						$boundary                = uniqid();
						$headers                 = $this->get_request_headers( $api_provider, false );
						$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

						$multipart_stream = new MultipartStream( $multipart_payload, $boundary );

						// Use media_id as key instead of file_path to maintain tracking.
						yield $media_id => new Request(
							'POST',
							$endpoint,
							$headers,
							$multipart_stream,
							'1.1'
						);
					} catch ( Exception $e ) {
						// Store error with media_id for reference.
						$media_id            = isset( $audio_file['media_id'] ) ? $audio_file['media_id'] : $index;
						$errors[ $media_id ] = new WP_Error( 'transcription_request_error', $e->getMessage() );
					}
				}
			};

			// Set up the request pool.
			$pool = new Pool(
				$client,
				$requests( $audio_files ),
				array(
					'concurrency' => 3, // Process 3 files at a time.
					'fulfilled'   => function ( $response, $file_path ) use ( &$results, &$processed_count, $total_files ) {
						// Process successful response.
						$response_body = $response->getBody()->getContents();
						$result = json_decode( $response_body, true );

						if ( JSON_ERROR_NONE !== json_last_error() ) {
							$results[ $file_path ] = new WP_Error(
								'transcription_parse_error',
								'Failed to parse transcription response: ' . json_last_error_msg()
							);
						} else {
							$results[ $file_path ] = $result;
						}

						// Update progress.
						$processed_count++;
						$progress = round( ( $processed_count / $total_files ) * 100 );

						// Send progress update.
						$this->message_handler->send_message(
							'TRANSCRIPTION_PROGRESS',
							array(
								'file_path'  => $file_path,
								'total'      => $total_files,
								'processed'  => $processed_count,
								'progress'   => $progress,
								'transcript' => isset( $result['text'] ) ? $result['text'] : null,
								'result'     => $result,
							)
						);
					},
					'rejected'    => function ( $reason, $file_path ) use ( &$errors, &$processed_count, $total_files ) {
						// Handle failed request.
						$error_message = $reason instanceof RequestException
							? ( $reason->hasResponse() ? $reason->getResponse()->getBody()->getContents() : $reason->getMessage() )
							: 'Unknown error';

						$errors[ $file_path ] = new WP_Error( 'transcription_api_error', $error_message );

						// Update progress even for errors.
						$processed_count++;
						$progress = round( ( $processed_count / $total_files ) * 100 );

						// Send error message.
						$this->message_handler->send_error(
							'transcription_error',
							array(
								'file_path' => $file_path,
								'filename'  => basename( $file_path ),
								'title'     => 'Transcription Error',
								'message'   => $error_message,
								'progress'  => $progress,
							)
						);
					},
				)
			);

			// Execute all requests and wait for completion.
			$promise = $pool->promise();
			$promise->wait();

			// Add any errors encountered during request creation.
			foreach ( $errors as $file_path => $error ) {
				if ( ! isset( $results[ $file_path ] ) ) {
					$results[ $file_path ] = $error;

					// Send error message if not already sent.
					$this->message_handler->send_error(
						'transcription_error',
						array(
							'file_path' => $file_path,
							'filename'  => basename( $file_path ),
							'title'     => 'Transcription Request Error',
							'message'   => $error->get_error_message(),
						)
					);
				}
			}

			// Send completion message.
			$this->message_handler->send_message(
				'TRANSCRIPTION_COMPLETE',
				array(
					'message'     => 'Batch audio transcription completed',
					'total_files' => $total_files,
					'successful'  => count( $results ) - count( $errors ),
					'failed'      => count( $errors ),
				)
			);

			return $results;

		} catch ( Exception $e ) {
			// Send error message for overall process failure.
			$this->message_handler->send_error(
				'transcription_batch_error',
				array(
					'title'   => 'Batch Transcription Error',
					'message' => $e->getMessage(),
				)
			);

			return new WP_Error( 'transcription_batch_error', $e->getMessage() );
		}
	}
}
