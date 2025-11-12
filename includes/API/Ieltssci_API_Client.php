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
use Jfcherng\Diff\Differ;
use Jfcherng\Diff\Renderer\Text\JsonText;

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
	 * Apply content manipulation based on the specified rule
	 *
	 * @param string      $content The content to manipulate.
	 * @param string|null $manipulation The manipulation rule to apply.
	 * @param string|null $prompt The prompt that produced the content.
	 * @param array|null  $feed The feed data.
	 * @param string|null $step_type The type of step being processed.
	 * @param array       $resolved_tags Optional. Resolved merge tag values for advanced manipulations.
	 * @return string The manipulated content.
	 */
	private function apply_content_manipulation( $content, $manipulation, $prompt = null, $feed = null, $step_type = null, $resolved_tags = array() ) {
		if ( empty( $manipulation ) ) {
			return $content;
		}

		switch ( $manipulation ) {
			case 'uppercase':
				return strtoupper( $content );
			case 'lowercase':
				return strtolower( $content );
			case 'capitalize':
				return ucwords( strtolower( $content ) );
			case 'remove_html':
				return wp_strip_all_tags( $content );
			case 'trim':
				return trim( $content );
			case 'strip_whitespace':
				return preg_replace( '/\s+/', ' ', trim( $content ) );

			case 'merge_with_previous_changes':
				// Merge changes from current content INTO previous feedback changes.
				// Looks for 'json_sentence' in resolved_tags.
				$previous_data = null;

				// Search for the tag in resolved_tags.
				foreach ( $resolved_tags as $tag_key => $tag_value ) {
					if ( false !== strpos( $tag_key, 'json_sentence' ) ) {
						// Found the tag, now extract the full data object.
						if ( is_string( $tag_value ) ) {
							$parsed_value = json_decode( $tag_value, true );
							if ( json_last_error() === JSON_ERROR_NONE ) {
								$previous_data = $parsed_value;
							}
						} elseif ( is_array( $tag_value ) ) {
							$previous_data = $tag_value;
						}
						break;
					}
				}

				// Parse current content.
				$current_data = json_decode( $content, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					// If content is not valid JSON, return as-is.
					return $content;
				}

				// Extract current changes.
				$current_changes = isset( $current_data['changes'] ) && is_array( $current_data['changes'] ) ? $current_data['changes'] : array();

				// If no previous data exists, return current content as-is.
				if ( empty( $previous_data ) || ! is_array( $previous_data ) ) {
					return $content;
				}

				// Extract previous changes.
				$previous_changes = isset( $previous_data['changes'] ) && is_array( $previous_data['changes'] ) ? $previous_data['changes'] : array();

				// Start with previous changes as the base.
				$merged_changes = $previous_changes;

				// Merge current changes into previous changes.
				// For each current change, find matching previous change by tag, old, new.
				foreach ( $current_changes as $current_change ) {
					if ( ! isset( $current_change['tag'] ) || ! isset( $current_change['old'] ) || ! isset( $current_change['new'] ) ) {
						continue; // Skip invalid changes.
					}

					// Search for matching change in previous changes.
					foreach ( $merged_changes as &$merged_change ) {
						if ( isset( $merged_change['tag'] ) && isset( $merged_change['old'] ) && isset( $merged_change['new'] ) ) {
							// Check if tag, old, and new all match.
							if ( $merged_change['tag'] === $current_change['tag'] &&
								$merged_change['old'] === $current_change['old'] &&
								$merged_change['new'] === $current_change['new'] ) {
								// Found a match - merge additional properties from current change.
								$merged_change = array_merge( $merged_change, $current_change );
								break;
							}
						}
					}
				}

				// Build the final result, preserving original_sentence and suggested_sentence from previous data.
				$result = array(
					'sentence' => array(
						array(
							'changes' => $merged_changes,
						),
					),
				);

				// Preserve original_sentence if it exists in previous data.
				if ( isset( $previous_data['original_sentence'] ) ) {
					$result['sentence'][0]['original_sentence'] = $previous_data['original_sentence'];
				}

				// Preserve suggested_sentence if it exists in previous data.
				if ( isset( $previous_data['suggested_sentence'] ) ) {
					$result['sentence'][0]['suggested_sentence'] = $previous_data['suggested_sentence'];
				}

				// Add any other fields from current_data that aren't changes, original_sentence, or suggested_sentence.
				foreach ( $current_data as $key => $value ) {
					if ( ! in_array( $key, array( 'changes', 'original_sentence', 'suggested_sentence' ), true ) ) {
						$result['sentence'][0][ $key ] = $value;
					}
				}

				return wp_json_encode( $result );

			case 'add_sentence_changes':
				// Check if content is JSON and matches the sentence schema.
				$data = json_decode( $content, true );
				if ( json_last_error() === JSON_ERROR_NONE && isset( $data['sentence'] ) && is_array( $data['sentence'] ) ) {
					foreach ( $data['sentence'] as &$sentence ) {
						if ( isset( $sentence['suggested_sentence'] ) ) {
							// Determine original sentence.
							$original_sentence = isset( $sentence['original_sentence'] ) ? $sentence['original_sentence'] : null;

							// If original sentence is not available, search for a tag ending with ':sentence'.
							if ( ! $original_sentence ) {
								foreach ( $resolved_tags as $tag_key => $tag_value ) {
									if ( substr( $tag_key, -9 ) === ':sentence' ) {
										$original_sentence = $tag_value;
										// Add the original_sentence to the data.
										$sentence['original_sentence'] = $original_sentence;
										break;
									}
								}
							}

							// Only proceed if we have both original and suggested sentences.
							if ( $original_sentence && isset( $sentence['suggested_sentence'] ) ) {
								$old_words = explode( ' ', $original_sentence );
								$new_words = explode( ' ', $sentence['suggested_sentence'] );

								// Use Jfcherng\Diff for word-level diff.
								$differ = new Differ(
									$old_words,
									$new_words,
									array(
										'context'          => Differ::CONTEXT_ALL,
										'ignoreWhitespace' => true,
										'ignoreLineEnding' => true,
									)
								);

								$renderer = new JsonText(
									array(
										'outputTagAsString' => true,
									)
								);

								$diff_result = $renderer->render( $differ );
								$diff_data   = json_decode( $diff_result, true );

								// Simplify the diff data.
								$changes = array();
								foreach ( $diff_data as $hunk ) {
									foreach ( $hunk as $change ) {
										if ( isset( $change['old'] ) && isset( $change['new'] ) ) {
											$changes[] = array(
												'old' => implode( ' ', $change['old']['lines'] ?? $change['old'] ),
												'new' => implode( ' ', $change['new']['lines'] ?? $change['new'] ),
												'tag' => $change['tag'] ?? 'eq',
											);
										}
									}
								}

								$sentence['changes'] = $changes;
							}
						}
					}
					return wp_json_encode( $data );
				}
				// If not matching schema, return original.
				return $content;
			default:
				// If manipulation is not recognized, return original content.
				return $content;
		}
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
			'connect_timeout' => 600,
			'timeout'         => 600,
			'read_timeout'    => 600,
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
			case 'lite-llm':
				$settings['base_uri'] = 'https://litellm.ieltsscience.fun/v1/'; // Lite LLM OpenAI compatible endpoint.
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

			case 'lite-llm':
				// Lite LLM is OpenAI compatible so we mirror OpenAI style headers.
				return array(
					'Authorization' => 'Bearer ' . $api_key['meta']['api-key'],
					'Content-Type'  => 'application/json',
					'Accept'        => $accept_header,
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
	 * @param float  $top_p Top P sampling parameter.
	 * @param int    $top_k Top K sampling parameter.
	 * @return array Request payload.
	 * @throws Exception When API key is not found for the provider.
	 */
	private function get_request_payload( $provider, $model, $prompt, $temperature, $max_tokens, $stream = true, $images = array(), $guided_choice = null, $guided_regex = null, $guided_json = null, $enable_thinking = false, $top_p = 0.8, $top_k = 20 ) {
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

		// Add top_p if it's not the default value (0.8).
		if ( 0.8 !== (float) $top_p ) {
			$base_payload['top_p'] = (float) $top_p;
		}

		// Add top_k if it's not the default value (20).
		if ( 20 !== (int) $top_k ) {
			$base_payload['top_k'] = (int) $top_k;
		}

		// Provider-specific configurations.
		// Special handling: if model name starts with 'hosted_vllm', treat it like a vllm/slm backend regardless of provider.
		if ( 0 === strpos( $model, 'hosted_vllm' ) ) {
			// Add guided generation parameters if available, with priority: JSON > Regex > Choice.
			if ( ! empty( $guided_json ) ) {
				$json_schema = json_decode( $guided_json, true );
				if ( JSON_ERROR_NONE === json_last_error() ) { // Only add if schema is valid.
					$base_payload['guided_json'] = $json_schema;
				}
			} elseif ( ! empty( $guided_regex ) ) {
				$base_payload['guided_regex'] = $guided_regex; // Add guided regex constraint.
			} elseif ( ! empty( $guided_choice ) ) {
				$choices_array = array_map( 'trim', explode( '|', $guided_choice ) );
				if ( ! empty( $choices_array ) && ( count( $choices_array ) > 1 || ! empty( $choices_array[0] ) ) ) {
					$base_payload['guided_choice'] = $choices_array; // Add guided choice list.
				}
			}

			$base_payload['chat_template_kwargs'] = array( 'enable_thinking' => $enable_thinking ); // Enable thinking flag.

			return $base_payload; // Return early for hosted_vllm models.
		}

		// Standard provider-specific configurations.
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
			case 'lite-llm':
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
			case 'lite-llm':
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
	 * @param float  $top_p Top P sampling parameter.
	 * @param int    $top_k Top K sampling parameter.
	 * @param string $content_manipulation Optional. Content manipulation rule to apply to the streamed content.
	 * @param array  $resolved_tags Optional. Resolved merge tag values for content manipulation.
	 * @return array|WP_Error Array containing 'content' and 'reasoning_content' from the API, or WP_Error on failure. For scoring steps, returns an array with 'content' (score) and 'reasoning_content'.
	 */
	public function make_stream_api_call( $api_provider, $model, $prompt, $temperature, $max_tokens, $feed, $step_type, $images = array(), $guided_choice = null, $guided_regex = null, $guided_json = null, $enable_thinking = false, $score_regex = null, $top_p = 0.8, $top_k = 20, $content_manipulation = null, $resolved_tags = array() ) {
		try {
			$current_provider = $api_provider; // Single attempt only.
			$client_settings  = $this->get_client_settings( $current_provider );
			$headers_array    = $this->get_request_headers( $current_provider, true );
			$payload          = $this->get_request_payload( $current_provider, $model, $prompt, $temperature, $max_tokens, true, $images, $guided_choice, $guided_regex, $guided_json, $enable_thinking, $top_p, $top_k );

			$endpoint_url = rtrim( $client_settings['base_uri'], '/' ) . '/chat/completions';

			// Prepare headers for cURL.
			$curl_headers = array();
			foreach ( $headers_array as $k => $v ) {
				$curl_headers[] = $k . ': ' . $v;
			}
			// Prevent "Expect: 100-continue" delays.
			$curl_headers[] = 'Expect:';

			$ch = curl_init();
			if ( false === $ch ) {
				return new WP_Error( 'curl_init_failed', 'Failed to initialize cURL.' );
			}

			// Streaming state variables mirroring process_stream_loop.
			$line_accumulator    = '';
			$done_received       = false;
			$full_response       = '';
			$reasoning_response  = '';
			$cot_active          = false;
			$is_scoring_step     = ( 'scoring' === $step_type );
			$score_regex_pattern = $score_regex; // For later extraction after full content.

			curl_setopt_array(
				$ch,
				array(
					CURLOPT_URL            => $endpoint_url,
					CURLOPT_POST           => true,
					CURLOPT_HTTPHEADER     => $curl_headers,
					CURLOPT_POSTFIELDS     => wp_json_encode( $payload ),
					CURLOPT_RETURNTRANSFER => false, // We stream manually.
					CURLOPT_WRITEFUNCTION  => function ( $curl, $data ) use ( &$line_accumulator, &$done_received, &$full_response, &$reasoning_response, &$cot_active, $current_provider, $step_type, $is_scoring_step, $content_manipulation, $prompt, $feed, $resolved_tags ) {
						// Append incoming chunk to accumulator.
						$line_accumulator .= $data;

						// Process complete lines.
						while ( ( $newline_pos = strpos( $line_accumulator, "\n" ) ) !== false && ! $done_received ) {
							$line_to_process  = substr( $line_accumulator, 0, $newline_pos + 1 );
							$line_accumulator = substr( $line_accumulator, $newline_pos + 1 );
							$line             = trim( $line_to_process );
							if ( '' === $line ) {
								continue;
							}
							if ( 0 === strpos( $line, 'data: ' ) ) {
								$content_chunk = $this->extract_content( $current_provider, $line );

								// Handle end of chain-of-thought when reasoning stops.
								if ( $cot_active && ( ( is_array( $content_chunk ) && ! isset( $content_chunk['reasoning_content'] ) ) || '[DONE]' === $content_chunk ) ) {
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
									break; // Exit processing loop.
								}

								if ( is_array( $content_chunk ) ) {
									if ( isset( $content_chunk['content'] ) && '' !== $content_chunk['content'] ) {
										$full_response .= $content_chunk['content'];
										if ( ! $is_scoring_step ) { // Stream immediately unless scoring step.
											$manipulated_content = $this->apply_content_manipulation( $content_chunk['content'], $content_manipulation, $prompt, $feed, $step_type, $resolved_tags );
											$this->message_handler->send_message(
												$this->message_handler->transform_case( $step_type, 'snake_upper' ),
												array(
													'content' => $manipulated_content,
													'step_type' => $step_type,
												)
											);
										}
									}
									if ( isset( $content_chunk['reasoning_content'] ) && '' !== $content_chunk['reasoning_content'] ) {
										$reasoning_response .= $content_chunk['reasoning_content'];
										$manipulated_reasoning = $this->apply_content_manipulation( $content_chunk['reasoning_content'], $content_manipulation, $prompt, $feed, $step_type, $resolved_tags );
										$this->message_handler->send_message(
											$this->message_handler->transform_case( 'chain-of-thought', 'snake_upper' ),
											array(
												'content' => $manipulated_reasoning,
												'step_type' => $step_type,
											)
										);
										$cot_active = true;
									}
								}
							}
						}

						return strlen( $data ); // Tell cURL we consumed the chunk.
					},
					CURLOPT_CONNECTTIMEOUT => isset( $client_settings['connect_timeout'] ) ? $client_settings['connect_timeout'] : 120,
					CURLOPT_TIMEOUT        => isset( $client_settings['timeout'] ) ? $client_settings['timeout'] : 120,
					CURLOPT_NOPROGRESS     => true,
				)
			);

			// Execute streaming request.
			$exec_result = curl_exec( $ch );
			$curl_error  = curl_error( $ch );
			$http_code   = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );

			if ( false === $exec_result && ! empty( $curl_error ) ) {
				$this->message_handler->send_error(
					'stream_api_error',
					array(
						'title'   => 'Streaming API Error',
						'message' => $curl_error,
					)
				);
				return new WP_Error( 'stream_api_curl_error', 'cURL streaming failed: ' . $curl_error, array( 'status' => $http_code ? $http_code : 500 ) );
			}

			// If COT was active but no explicit [DONE] received for reasoning, send final DONE.
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

			// For scoring step extract score now (content not streamed earlier).
			if ( $is_scoring_step && ! empty( $score_regex_pattern ) ) {
				$extracted_score       = $this->extract_score_from_result( $full_response, $score_regex_pattern );
				$manipulated_score     = $this->apply_content_manipulation( $extracted_score, $content_manipulation, $prompt, $feed, $step_type, $resolved_tags );
				$manipulated_reasoning = $this->apply_content_manipulation( $reasoning_response, $content_manipulation, $prompt, $feed, $step_type, $resolved_tags );
				$this->message_handler->send_message(
					$this->message_handler->transform_case( $step_type, 'snake_upper' ),
					array(
						'content'           => $manipulated_score,
						'raw_content'       => $full_response,
						'reasoning_content' => $manipulated_reasoning,
						'regex_used'        => $score_regex_pattern,
					)
				);
				return array(
					'content'           => $manipulated_score,
					'reasoning_content' => $manipulated_reasoning,
				);
			}

			return array(
				'content'           => $this->apply_content_manipulation( $full_response, $content_manipulation, $prompt, $feed, $step_type, $resolved_tags ),
				'reasoning_content' => $this->apply_content_manipulation( $reasoning_response, $content_manipulation, $prompt, $feed, $step_type, $resolved_tags ),
			);
		} catch ( Exception $e ) {
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
	 * Get resolved tags for a specific variant index
	 *
	 * Combines common tags with variant-specific tags for parallel processing.
	 *
	 * @param array $resolved_tags The resolved tags structure from merge processor.
	 * @param int   $variant_index The index of the current variant being processed.
	 * @return array Combined array of resolved tag values for this variant.
	 */
	private function get_tags_for_variant( $resolved_tags, $variant_index ) {
		$result = array();

		// Include all common tags (tags that resolved to single values).
		if ( isset( $resolved_tags['common'] ) && is_array( $resolved_tags['common'] ) ) {
			$result = $resolved_tags['common'];
		}

		// Add variant-specific tags for this index (tags that resolved to arrays).
		if ( isset( $resolved_tags['variant_specific'] ) && is_array( $resolved_tags['variant_specific'] ) ) {
			foreach ( $resolved_tags['variant_specific'] as $tag_key => $values_array ) {
				if ( is_array( $values_array ) && isset( $values_array[ $variant_index ] ) ) {
					$result[ $tag_key ] = $values_array[ $variant_index ];
				}
			}
		}

		return $result;
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
	 * @param float  $top_p Top P sampling parameter.
	 * @param int    $top_k Top K sampling parameter.
	 * @param string $content_manipulation Optional. Content manipulation rule to apply to each response.
	 * @param array  $resolved_tags Optional. Resolved merge tag values for content manipulation.
	 * @return string|array|WP_Error The concatenated responses from all API calls, indexed array of responses, or WP_Error on failure.
	 */
	public function make_parallel_api_calls( $api_provider, $model, $prompts, $temperature, $max_tokens, $feed, $step_type, $guided_choice = null, $guided_regex = null, $guided_json = null, $enable_thinking = false, $return_format = 'string', $top_p = 0.8, $top_k = 20, $content_manipulation = null, $resolved_tags = array() ) {
		$current_provider  = $api_provider;
		$remaining_prompts = $prompts; // Prompts that still need to be processed.
		$final_responses   = array(); // Successful responses from all attempts.
		$total_prompts     = count( $prompts );

		while ( ! empty( $remaining_prompts ) ) {
			try {
				// Get client settings.
				$client_settings = $this->get_client_settings( $current_provider );

				// Decider Function (run every success/failure): Determines IF a request should be retried.
				$decider = function ( $retries, $request, $response, $exception ) {
					// 1. Limit the maximum number of retries to prevent infinite loops.
					if ( $retries >= 3 ) {
						return false;
					}

					// 2. Retry on connection errors, which are safe to retry.
					if ( $exception instanceof \GuzzleHttp\Exception\ConnectException ) {
						return true;
					}

					// 3. Retry on server errors (5xx) or rate limiting (429), but not other client errors.
					if ( $response && ( $response->getStatusCode() >= 500 || $response->getStatusCode() === 429 ) ) {
						return true;
					}

					return false; // Do not retry for success/other failures.
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
				$requests = function ( $prompts_to_process ) use ( $current_provider, $model, $temperature, $max_tokens, $guided_choice, $guided_regex, $guided_json, $enable_thinking, $top_p, $top_k ) {
					foreach ( $prompts_to_process as $index => $prompt ) {
						try {
							// Prepare request payload for this prompt.
							$payload = $this->get_request_payload( $current_provider, $model, $prompt, $temperature, $max_tokens, false, array(), $guided_choice, $guided_regex, $guided_json, $enable_thinking, $top_p, $top_k );

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
						'fulfilled'   => function ( $response, $index ) use ( &$responses_by_index, &$processed_count, $total_prompts, $step_type, $content_manipulation, $remaining_prompts, $feed, $resolved_tags ) {
							// Process successful response.
							$body    = $response->getBody()->getContents();
							$content = $this->extract_content_from_full_response( $body );

							if ( $content ) {
								// Get variant-specific tags for this index.
								$variant_tags = $this->get_tags_for_variant( $resolved_tags, $index );

								// Apply content manipulation if specified.
								$content = $this->apply_content_manipulation( $content, $content_manipulation, $remaining_prompts[ $index ], $feed, $step_type, $variant_tags );

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
	 * Call the phonemize API to convert text to phonemes
	 *
	 * @param string $text The text to phonemize.
	 * @param string $language The language code (default: 'a').
	 * @return array|WP_Error Array containing 'phonemes' and 'tokens', or WP_Error on failure.
	 * @throws Exception If API call fails.
	 */
	public function make_phonemize_api_call( $text, $language = 'a' ) {
		try {
			// Create Guzzle client with appropriate settings.
			$client_settings = array(
				'base_uri'        => 'https://api3.ieltsscience.fun/',
				'connect_timeout' => 30,
				'timeout'         => 30,
			);

			$client = new Client( $client_settings );

			// Prepare request payload.
			$payload = array(
				'text'     => $text,
				'language' => $language,
			);

			// Make the API request.
			$response = $client->post(
				'dev/phonemize',
				array(
					'headers' => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					'json'    => $payload,
				)
			);

			// Parse response.
			$response_body = $response->getBody()->getContents();
			$result        = json_decode( $response_body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				throw new Exception( 'Failed to parse phonemize response: ' . json_last_error_msg() );
			}

			return $result;

		} catch ( Exception $e ) {
			return new WP_Error( 'phonemize_api_error', $e->getMessage() );
		}
	}

	/**
	 * Call the text-to-speech API to synthesize audio from text
	 *
	 * @param string $input The text to synthesize.
	 * @param string $model The TTS model to use (default: 'kokoro').
	 * @param string $voice The voice to use (default: 'af_heart').
	 * @param string $response_format Audio format (default: 'mp3').
	 * @param float  $speed Speech speed (default: 1.0).
	 * @return string|WP_Error Binary audio data on success, or WP_Error on failure.
	 * @throws Exception If API call fails.
	 */
	public function make_tts_api_call( $input, $model = 'kokoro', $voice = 'af_heart', $response_format = 'mp3', $speed = 1.0 ) {
		try {
			// Create Guzzle client with appropriate settings.
			$client_settings = array(
				'base_uri'        => 'https://api3.ieltsscience.fun/',
				'connect_timeout' => 60,
				'timeout'         => 60,
			);

			$client = new Client( $client_settings );

			// Prepare request payload.
			$payload = array(
				'model'           => $model,
				'input'           => $input,
				'voice'           => $voice,
				'response_format' => $response_format,
				'speed'           => (string) $speed,
			);

			// Make the API request.
			$response = $client->post(
				'v1/audio/speech',
				array(
					'headers' => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'audio/*',
					),
					'json'    => $payload,
				)
			);

			// Return the binary audio data.
			return $response->getBody()->getContents();

		} catch ( Exception $e ) {
			return new WP_Error( 'tts_api_error', $e->getMessage() );
		}
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
