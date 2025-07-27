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
	 * Get the fallback provider for a given provider.
	 *
	 * @param string $current_provider The provider that just failed.
	 * @return string|null The next provider to try, or null if no fallback is available.
	 */
	private function get_fallback_provider( $current_provider ) {
		$fallback_chains = array(
			'gpt2-shupremium' => 'two-key-ai',
			'two-key-ai'      => 'google',
		);

		return isset( $fallback_chains[ $current_provider ] ) ? $fallback_chains[ $current_provider ] : null;
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
				$settings['base_uri'] = 'https://open.keyai.shop/v1/';
				break;
			case 'two-key-ai':
					$settings['base_uri'] = 'https://gpt5.shupremium.com/v1/';
				break;
			case 'gpt2-shupremium':
				$settings['base_uri'] = 'https://gpt2.shupremium.com/v1/';
				break;

			case 'home-server':
				$settings['base_uri'] = 'http://api3.ieltsscience.fun/v1/';
				break;
			case 'home-server-whisperx-api-server':
				$settings['base_uri'] = 'https://api3.ieltsscience.fun/v1/';
				break;
			case 'vllm':
				$settings['base_uri'] = 'https://llm.ieltsscience.fun/v1/';
				break;
			case 'vllm2':
				$settings['base_uri'] = 'https://llm2.ieltsscience.fun/v1/';
				break;
			case 'slm':
				$settings['base_uri'] = 'https://slm.ieltsscience.fun/v1/';
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

		if ( ! $api_key && 'home-server' !== $provider && 'home-server-whisperx-api-server' !== $provider && 'vllm' !== $provider && 'slm' !== $provider ) {
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
			case 'two-key-ai':
				return array(
					'Authorization' => 'Bearer ' . $api_key['meta']['api-key'],
					'Content-Type'  => 'application/json',
					'Accept'        => $accept_header,
				);
			case 'gpt2-shupremium':
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
			case 'vllm':
			case 'vllm2':
				return array(
					'Content-Type'  => 'application/json',
					'Accept'        => $accept_header,
					'Authorization' => $api_key ? 'Bearer ' . $api_key['meta']['api-key'] : '',
				);

			case 'slm':
				return array(
					'Content-Type'  => 'application/json',
					'Accept'        => $accept_header,
					'Authorization' => $api_key ? 'Bearer ' . $api_key['meta']['api-key'] : '',
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
	 * @param string $guided_choice Guided choice parameter.
	 * @param string $guided_regex Guided regex parameter.
	 * @param string $guided_json Guided JSON parameter.
	 * @param bool   $enable_thinking Whether to enable reasoning for vllm/slm.
	 * @return array Request payload.
	 * @throws Exception When API key is not found for the provider.
	 */
	private function get_request_payload( $provider, $model, $prompt, $temperature, $max_tokens, $stream = true, $images = array(), $guided_choice = null, $guided_regex = null, $guided_json = null, $enable_thinking = false ) {
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

		// Base payload for all providers.
		$base_payload = array(
			'model'       => $model,
			'messages'    => array( $message_content ),
			'temperature' => (float) $temperature,
			'max_tokens'  => (int) $max_tokens,
			'stream'      => (bool) $stream,
		);

		// Provider-specific configurations.
		switch ( $provider ) {
			case 'vllm':
			case 'slm':
			case 'vllm2':
				// Add guided generation parameters if available, with priority: JSON > Regex > Choice.
				if ( ! empty( $guided_json ) ) {
					$json_schema = json_decode( $guided_json, true );
					// Ensure JSON decoding was successful before adding it.
					if ( JSON_ERROR_NONE === json_last_error() ) {
						$base_payload['guided_json'] = $json_schema;
					}
					// Optionally, you could log an error here if json_decode fails.
				} elseif ( ! empty( $guided_regex ) ) {
					$base_payload['guided_regex'] = $guided_regex;
				} elseif ( ! empty( $guided_choice ) ) {
					// Choices are expected to be a string separated by `|` character.
					$choices_array = array_map( 'trim', explode( '|', $guided_choice ) );
					// Ensure the array is not empty after splitting.
					if ( ! empty( $choices_array ) && ( count( $choices_array ) > 1 || ! empty( $choices_array[0] ) ) ) {
						$base_payload['guided_choice'] = $choices_array;
					}
				}

				// Add enable_thinking parameter to chat_template_kwargs.
				$base_payload['chat_template_kwargs'] = array(
					'enable_thinking' => $enable_thinking,
				);
				break;

			case 'home-server':
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

				// Add API token for home-server.
				$base_payload['api_token'] = $api_key['meta']['api-key'];
				break;

			case 'google':
			case 'openai':
			case 'open-key-ai':
			case 'two-key-ai':
			case 'gpt2-shupremium':
				// Add guided JSON using response_format if available.
				if ( ! empty( $guided_json ) ) {
					$json_schema = json_decode( $guided_json, true );
					// Ensure JSON decoding was successful before adding it.
					if ( JSON_ERROR_NONE === json_last_error() ) {
						$base_payload['response_format'] = array(
							'type'        => 'json_schema',
							'json_schema' => array(
								'strict' => true,
								'schema' => $json_schema,
							),
						);
					}
				}
				break;

			case 'home-server-whisperx-api-server':
			default:
				// Standard OpenAI-compatible format, no additional parameters needed.
				break;
		}

		return $base_payload;
	}

	/**
	 * Extract content from a data line based on provider format
	 *
	 * @param string $provider API provider name.
	 * @param string $line Line from response.
	 * @return array|string|null Array containing content and reasoning_content, [DONE] string, or null if no content.
	 */
	private function extract_content( $provider, $line ) {
		$data = substr( $line, 6 ); // Remove "data: " prefix.

		// Check for [DONE] message.
		if ( '[DONE]' === trim( $data ) ) {
			return '[DONE]';
		}

		// Initialize result structure for content and reasoning.
		$result = array(
			'content'           => null,
			'reasoning_content' => null,
		);

		// Process data based on provider.
		switch ( $provider ) {
			case 'google':
				$chunk = json_decode( $data, true );
				if ( JSON_ERROR_NONE === json_last_error() && isset( $chunk['choices'][0]['delta'] ) ) {
					$delta = $chunk['choices'][0]['delta'];

					// Extract content if available.
					if ( isset( $delta['content'] ) ) {
						$result['content'] = $delta['content'];
					}

					// Extract reasoning_content if available.
					if ( isset( $delta['reasoning_content'] ) ) {
						$result['reasoning_content'] = $delta['reasoning_content'];
					}
				}
				break;

			case 'openai':
			case 'open-key-ai':
			case 'two-key-ai':
			case 'gpt2-shupremium':
				$chunk = json_decode( $data, true );
				if ( JSON_ERROR_NONE === json_last_error() && isset( $chunk['choices'][0]['delta'] ) ) {
					$delta = $chunk['choices'][0]['delta'];

					// Extract content if available.
					if ( isset( $delta['content'] ) ) {
						$result['content'] = $delta['content'];
					}

					// Extract reasoning_content if available.
					if ( isset( $delta['reasoning_content'] ) ) {
						$result['reasoning_content'] = $delta['reasoning_content'];
					}
				}
				break;

			default:
				// Default handler for all other providers.
				$chunk = json_decode( $data, true );
				if ( JSON_ERROR_NONE === json_last_error() && isset( $chunk['choices'][0]['delta'] ) ) {
					$delta = $chunk['choices'][0]['delta'];

					// Extract content if available.
					if ( isset( $delta['content'] ) ) {
						$result['content'] = $delta['content'];
					}

					// Extract reasoning_content if available.
					if ( isset( $delta['reasoning_content'] ) ) {
						$result['reasoning_content'] = $delta['reasoning_content'];
					}
				}
				break;
		}

		// Return the result if either content or reasoning_content is present.
		if ( ! is_null( $result['content'] ) || ! is_null( $result['reasoning_content'] ) ) {
			return $result;
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
	 * Process a detached stream resource for streaming responses.
	 *
	 * @param resource $handle Stream resource.
	 * @param string   $api_provider API provider name.
	 * @param string   $step_type Step type for message handling.
	 * @return array Array containing 'content' and 'reasoning_content' keys.
	 */
	private function process_stream( $handle, $api_provider, $step_type ) {
		// Set stream to non-blocking mode for better responsiveness.
		stream_set_blocking( $handle, false );

		// Delegate streaming loop processing.
		return $this->process_stream_loop( $handle, $api_provider, $step_type );
	}

	/**
	 * Process the stream loop for streaming responses.
	 *
	 * @param resource $handle Stream resource.
	 * @param string   $api_provider API provider name.
	 * @param string   $step_type Step type for message handling.
	 * @return array Array containing 'content' and 'reasoning_content' keys.
	 */
	private function process_stream_loop( $handle, $api_provider, $step_type ) {
		// Buffer for accumulating parts of a line.
		$line_accumulator = '';
		// Flag to stop processing after [DONE] is received.
		$done_received = false;
		// Accumulated full response.
		$full_response = '';
		// Accumulated reasoning content.
		$reasoning_response = '';
		// Flag to track if chain-of-thought is active.
		$cot_active = false;
		// Flag to determine if we should stream content to client.
		$is_scoring_step = 'scoring' === $step_type;

		// Loop until done.
		while ( ! feof( $handle ) && ! $done_received ) {
			$chunk = fgets( $handle ); // Read a chunk from the stream.
			if ( false === $chunk && ! feof( $handle ) ) {
				usleep( 10000 ); // Sleep briefly to avoid CPU spinning.
				continue;
			}
			if ( false !== $chunk ) {
				$line_accumulator .= $chunk;
			}

			// Process complete lines.
			while ( ( $newline_pos = strpos( $line_accumulator, "\n" ) ) !== false && ! $done_received ) {
				$line_to_process  = substr( $line_accumulator, 0, $newline_pos + 1 );
				$line_accumulator = substr( $line_accumulator, $newline_pos + 1 );
				$line             = trim( $line_to_process );

				if ( '' === $line ) {
					continue;
				}

				if ( 0 === strpos( $line, 'data: ' ) ) {
					$content_chunk = $this->extract_content( $api_provider, $line );

					// Check if COT was active and has now ended.
					// This happens if $cot_active is true AND (
					// 1. The current chunk is an array but does NOT contain 'reasoning_content' OR
					// 2. The current chunk is the main '[DONE]' signal.
					// ).
					if ( $cot_active &&
						( ( is_array( $content_chunk ) && ! isset( $content_chunk['reasoning_content'] ) ) || '[DONE]' === $content_chunk ) ) {
						$this->message_handler->send_message(
							$this->message_handler->transform_case( 'chain-of-thought', 'snake_upper' ),
							array(
								'content'   => '[DONE]',
								'step_type' => $step_type,
							)
						);
						$this->message_handler->send_done( $this->message_handler->transform_case( 'chain-of-thought', 'snake_upper' ) );
						$cot_active = false;
					}

					if ( '[DONE]' === $content_chunk ) {
						$done_received = true;
						$this->message_handler->send_message(
							$this->message_handler->transform_case( $step_type, 'snake_upper' ),
							array(
								'content'   => '[DONE]',
								'step_type' => $step_type,
							)
						);
						break;
					}

					if ( ! is_null( $content_chunk ) && is_array( $content_chunk ) ) {
						// Only process the normal content part for now.
						if ( isset( $content_chunk['content'] ) && ! is_null( $content_chunk['content'] ) && '' !== $content_chunk['content'] ) {
							$full_response .= $content_chunk['content'];

							// For scoring steps, don't send content to client until the end.
							// Only accumulate it in $full_response.
							if ( ! $is_scoring_step ) {
								$this->message_handler->send_message(
									$this->message_handler->transform_case( $step_type, 'snake_upper' ),
									array(
										'content'   => $content_chunk['content'],
										'step_type' => $step_type,
									)
								);
							}
						}

						// Send reasoning_content as a COT event if available.
						if ( isset( $content_chunk['reasoning_content'] ) && ! is_null( $content_chunk['reasoning_content'] ) && '' !== $content_chunk['reasoning_content'] ) {
							// Accumulate reasoning content in addition to sending it.
							$reasoning_response .= $content_chunk['reasoning_content'];

							$this->message_handler->send_message(
								$this->message_handler->transform_case( 'chain-of-thought', 'snake_upper' ),
								array(
									'content'   => $content_chunk['reasoning_content'],
									'step_type' => $step_type,
								)
							);
							$cot_active = true; // Mark COT as active.
						}
					}
				}
			}

			// Process any remaining fragment at EOF.
			if ( feof( $handle ) && ! $done_received && ! empty( trim( $line_accumulator ) ) ) {
				$line = trim( $line_accumulator );
				if ( 0 === strpos( $line, 'data: ' ) ) {
					$content_chunk = $this->extract_content( $api_provider, $line );

					// Check if COT was active and has now ended.
					if ( $cot_active &&
						( ( is_array( $content_chunk ) && ! isset( $content_chunk['reasoning_content'] ) ) || '[DONE]' === $content_chunk ) ) {
						$this->message_handler->send_message(
							$this->message_handler->transform_case( 'chain-of-thought', 'snake_upper' ),
							array(
								'content'   => '[DONE]',
								'step_type' => $step_type,
							)
						);
						$this->message_handler->send_done( $this->message_handler->transform_case( 'chain-of-thought', 'snake_upper' ) );
						$cot_active = false;
					}

					if ( '[DONE]' === $content_chunk ) {
						$done_received = true;
						$this->message_handler->send_message(
							$this->message_handler->transform_case( $step_type, 'snake_upper' ),
							array(
								'content'   => '[DONE]',
								'step_type' => $step_type,
							)
						);
					}

					if ( ! is_null( $content_chunk ) && is_array( $content_chunk ) ) {
						// Only process the normal content part for now.
						if ( isset( $content_chunk['content'] ) && ! is_null( $content_chunk['content'] ) && '' !== $content_chunk['content'] ) {
							$full_response .= $content_chunk['content'];

							// For scoring steps, don't send content to client until the end.
							// Only accumulate it in $full_response.
							if ( ! $is_scoring_step ) {
								$this->message_handler->send_message(
									$this->message_handler->transform_case( $step_type, 'snake_upper' ),
									array(
										'content'   => $content_chunk['content'],
										'step_type' => $step_type,
									)
								);
							}
						}

						// Send reasoning_content as a COT event if available.
						if ( isset( $content_chunk['reasoning_content'] ) && ! is_null( $content_chunk['reasoning_content'] ) && '' !== $content_chunk['reasoning_content'] ) {
							// Accumulate reasoning content in addition to sending it.
							$reasoning_response .= $content_chunk['reasoning_content'];

							$this->message_handler->send_message(
								$this->message_handler->transform_case( 'chain-of-thought', 'snake_upper' ),
								array(
									'content'   => $content_chunk['reasoning_content'],
									'step_type' => $step_type,
								)
							);
							$cot_active = true; // Mark COT as active.
						}
					}
				}
				$line_accumulator = '';
				break;
			}
		}

		// If COT was active when the stream ended, send a final COT [DONE].
		if ( $cot_active ) {
			$this->message_handler->send_message(
				$this->message_handler->transform_case( 'chain-of-thought', 'snake_upper' ),
				array(
					'content'   => '[DONE]',
					'step_type' => $step_type,
				)
			);
			$this->message_handler->send_done( $this->message_handler->transform_case( 'chain-of-thought', 'snake_upper' ) );
		}

		if ( is_resource( $handle ) ) {
			fclose( $handle );
		}

		// Return both the full response and reasoning response.
		return array(
			'content'           => $full_response,
			'reasoning_content' => $reasoning_response,
		);
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
	 * @param string $guided_choice Guided choice parameter.
	 * @param string $guided_regex Guided regex parameter.
	 * @param string $guided_json Guided JSON parameter.
	 * @param bool   $enable_thinking Whether to enable reasoning for vllm/slm.
	 * @param string $score_regex Optional. Regex pattern to extract score from the response when step_type is 'scoring'.
	 * @return array|WP_Error Array containing 'content' and 'reasoning_content' from the API, or WP_Error on failure. For scoring steps, returns an array with 'content' (score) and 'reasoning_content'.
	 */
	public function make_stream_api_call( $api_provider, $model, $prompt, $temperature, $max_tokens, $feed, $step_type, $images = array(), $guided_choice = null, $guided_regex = null, $guided_json = null, $enable_thinking = false, $score_regex = null ) {
		$current_provider = $api_provider;

		do {
			try {
				// Get client settings.
				$client_settings = $this->get_client_settings( $current_provider );

				// Get headers including API key.
				$headers = $this->get_request_headers( $current_provider, true );

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
				$payload = $this->get_request_payload( $current_provider, $model, $prompt, $temperature, $max_tokens, true, $images, $guided_choice, $guided_regex, $guided_json, $enable_thinking );

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

				$stream = $response->getBody();

				// Get a PHP stream resource from Guzzle's stream.
				$handle = $stream->detach();

				// Check if handle is a valid resource.
				if ( ! is_resource( $handle ) ) {
					return new WP_Error( 'stream_detach_failed', 'Failed to detach stream resource.' );
				}

				$full_response = $this->process_stream( $handle, $current_provider, $step_type );
				// If this is a scoring step and we have a score_regex, extract the score from the full response.
				if ( 'scoring' === $step_type && ! empty( $score_regex ) ) {
					// Extract score from the content using regex.
					$extracted_score = $this->extract_score_from_result( $full_response['content'], $score_regex );

					// Create a result structure with score, content, and reasoning_content.
					$result = array(
						'content'           => $extracted_score,
						'reasoning_content' => $full_response['reasoning_content'],
					);

					// Send both raw content and extracted score to frontend.
					$this->message_handler->send_message(
						$this->message_handler->transform_case( $step_type, 'snake_upper' ),
						array(
							'content'           => $extracted_score,
							'raw_content'       => $full_response['content'],
							'reasoning_content' => $full_response['reasoning_content'],
							'regex_used'        => $score_regex,
						)
					);

					// Return the result array with content, and reasoning_content.
					return $result;
				}

				return $full_response;

			} catch ( RequestException $e ) {
				$fallback_provider = $this->get_fallback_provider( $current_provider );

				if ( $fallback_provider ) {
					$this->message_handler->send_message(
						'API_FALLBACK',
						array(
							'message'           => "Provider '{$current_provider}' failed. Attempting fallback to '{$fallback_provider}'.",
							'failed_provider'   => $current_provider,
							'fallback_provider' => $fallback_provider,
						)
					);
					$current_provider = $fallback_provider;
				} else {
					$error_message = $e->getMessage();
					if ( $e->hasResponse() ) {
						$error_message .= ' Response: ' . $e->getResponse()->getBody();
					}

					$this->message_handler->send_error(
						'stream_api_error',
						array(
							'title'   => 'Streaming API Error',
							'message' => $error_message,
						)
					);

					return new WP_Error( 'stream_api_request_failed', 'Streaming API request failed: ' . $error_message, array( 'status' => $e->getCode() ? $e->getCode() : 500 ) );
				}
			} catch ( Exception $e ) {
				$fallback_provider = $this->get_fallback_provider( $current_provider );

				if ( $fallback_provider ) {
					$this->message_handler->send_message(
						'API_FALLBACK',
						array(
							'message'           => "Provider '{$current_provider}' failed with general error. Attempting fallback to '{$fallback_provider}'.",
							'failed_provider'   => $current_provider,
							'fallback_provider' => $fallback_provider,
						)
					);
					$current_provider = $fallback_provider;
				} else {
					$this->message_handler->send_error(
						'stream_api_error',
						array(
							'title'   => 'Streaming API Error',
							'message' => $e->getMessage(),
						)
					);

					return new WP_Error( 'stream_api_general_error', 'An unexpected error occurred during streaming: ' . $e->getMessage(), array( 'status' => $e->getCode() ? $e->getCode() : 500 ) );
				}
			}
		} while ( true );
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
	 * @param string $guided_choice Guided choice parameter.
	 * @param string $guided_regex Guided regex parameter.
	 * @param string $guided_json Guided JSON parameter.
	 * @param bool   $enable_thinking Whether to enable reasoning for vllm/slm.
	 * @param string $return_format Optional. The format of the return value. 'string' for concatenated responses (default), 'array' for indexed array of responses.
	 * @return string|array|WP_Error The concatenated responses from all API calls, indexed array of responses, or WP_Error on failure.
	 */
	public function make_parallel_api_calls( $api_provider, $model, $prompts, $temperature, $max_tokens, $feed, $step_type, $guided_choice = null, $guided_regex = null, $guided_json = null, $enable_thinking = false, $return_format = 'string' ) {
		$current_provider  = $api_provider;
		$remaining_prompts = $prompts; // Prompts that still need to be processed.
		$final_responses   = array(); // Successful responses from all attempts.
		$total_prompts     = count( $prompts );

		while ( ! empty( $remaining_prompts ) ) {
			try {
				// Get client settings.
				$client_settings = $this->get_client_settings( $current_provider );

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

				// Track results and errors for this attempt.
				$responses_by_index = array();
				$errors_by_index    = array();
				$processed_count    = 0;

				// Generate request objects for remaining prompts.
				$requests = function ( $prompts_to_process ) use ( $current_provider, $model, $temperature, $max_tokens, $guided_choice, $guided_regex, $guided_json, $enable_thinking ) {
					foreach ( $prompts_to_process as $index => $prompt ) {
						try {
							// Prepare request payload for this prompt.
							$payload = $this->get_request_payload( $current_provider, $model, $prompt, $temperature, $max_tokens, false, array(), $guided_choice, $guided_regex, $guided_json, $enable_thinking );

							// Get headers including API key.
							$headers = $this->get_request_headers( $current_provider, false );

							// Yield request with index metadata.
							yield $index => new Request(
								'POST',
								'chat/completions',
								$headers,
								wp_json_encode( $payload )
							);
						} catch ( Exception $e ) {
							// Track errors that occur during request creation.
							$errors_by_index[ $index ] = $e->getMessage();
						}
					}
				};

				// Set up the request pool.
				$pool = new Pool(
					$client,
					$requests( $remaining_prompts ),
					array(
						'concurrency' => 20, // Process 20 requests at a time.
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
							} else {
								// Content extraction failed.
								$errors_by_index[ $index ] = 'Failed to extract content from API response.';
								$processed_count++;
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

				// Store successful responses in final results.
				foreach ( $responses_by_index as $index => $response ) {
					$final_responses[ $index ] = $response;
				}

				// Check if there were any failures and if we have a fallback provider.
				if ( ! empty( $errors_by_index ) ) {
					$fallback_provider = $this->get_fallback_provider( $current_provider );

					if ( $fallback_provider ) {
						// Notify about fallback attempt.
						$this->message_handler->send_message(
							'API_FALLBACK',
							array(
								'message'           => "Provider '{$current_provider}' failed for " . count( $errors_by_index ) . " prompts. Attempting fallback to '{$fallback_provider}'.",
								'failed_provider'   => $current_provider,
								'fallback_provider' => $fallback_provider,
								'failed_count'      => count( $errors_by_index ),
							)
						);

						// Prepare remaining prompts (only the ones that failed).
						$new_remaining_prompts = array();
						foreach ( $errors_by_index as $failed_index => $error ) {
							if ( isset( $remaining_prompts[ $failed_index ] ) ) {
								$new_remaining_prompts[ $failed_index ] = $remaining_prompts[ $failed_index ];
							}
						}

						$remaining_prompts = $new_remaining_prompts;
						$current_provider  = $fallback_provider;
					} else {
						// No more fallback providers available.
						// Log the remaining errors and break out of the loop.
						foreach ( $errors_by_index as $index => $error ) {
							$this->message_handler->send_error(
								'parallel_final_error',
								array(
									'index'   => $index,
									'title'   => 'Final API Error',
									'message' => "No more fallback providers available. Final error: {$error}",
								)
							);
						}
						break; // Exit the retry loop.
					}
				} else {
					// All requests succeeded, exit the loop.
					break;
				}
			} catch ( Exception $e ) {
				// Critical error in the parallel execution itself.
				$fallback_provider = $this->get_fallback_provider( $current_provider );

				if ( $fallback_provider ) {
					$this->message_handler->send_message(
						'API_FALLBACK',
						array(
							'message'           => "Provider '{$current_provider}' failed with critical error. Attempting fallback to '{$fallback_provider}'.",
							'failed_provider'   => $current_provider,
							'fallback_provider' => $fallback_provider,
						)
					);
					$current_provider = $fallback_provider;
				} else {
					// Send error message for overall process failure.
					$this->message_handler->send_error(
						'parallel_execution_error',
						array(
							'title'   => 'Parallel Processing Error',
							'message' => 'A critical error occurred during parallel request execution: ' . $e->getMessage(),
						)
					);

					return new WP_Error( 'parallel_api_execution_error', 'Parallel API execution failed: ' . $e->getMessage(), array( 'status' => 500 ) );
				}
			}
		}

		// Process final results.
		if ( empty( $final_responses ) ) {
			// No successful responses at all.
			$this->message_handler->send_error(
				'parallel_complete_error',
				array(
					'title'   => 'All Parallel Requests Failed',
					'message' => 'All API requests failed across all providers.',
				)
			);

			return new WP_Error( 'parallel_all_failed', 'All parallel API requests failed across all fallback providers.' );
		}

		// Build the final response.
		$full_response = '';
		if ( ! empty( $final_responses ) ) {
			// Sort responses by index to maintain original order.
			ksort( $final_responses );

			// Return based on the requested format.
			if ( 'array' === $return_format ) {
				// Return the indexed array of responses.
				$result = $final_responses;
			} else {
				// Build the combined response (default string format).
				foreach ( $final_responses as $index => $response ) {
					$full_response .= $response;

					// Add separator if not the last response.
					if ( $index < count( $prompts ) - 1 ) {
						$full_response .= "\n\n---\n\n";
					}
				}
				$result = $full_response;
			}
		} else {
			// No successful responses.
			$result = 'array' === $return_format ? array() : '';
		}

		// Final completion message.
		$this->message_handler->send_message(
			'parallel_complete',
			array(
				'total_prompts' => $total_prompts,
				'successful'    => count( $final_responses ),
				'failed'        => $total_prompts - count( $final_responses ),
				'status'        => count( $final_responses ) === $total_prompts ? 'success' : 'partial_success',
			)
		);

		return $result;
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
