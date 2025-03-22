<?php
/**
 * Message Handler for Writing Module
 *
 * @package IeltsScienceLMS
 * @subpackage Writing
 * @since 1.0.0
 */

namespace IeltsScienceLMS\API;

/**
 * Class Ieltssci_Message_Handler
 *
 * Handles the sending of SSE messages and events during processing.
 */
class Ieltssci_Message_Handler {

	/**
	 * Callback function for sending messages back to client.
	 *
	 * @var callable
	 */
	private $message_callback;

	/**
	 * Constructor
	 *
	 * @param callable $message_callback Function to call when sending messages back to client.
	 */
	public function __construct( $message_callback ) {
		$this->message_callback = $message_callback;
	}

	/**
	 * Send an SSE message
	 *
	 * @param string $event_type The event type.
	 * @param mixed  $data The data to send.
	 */
	public function send_message( $event_type, $data ) {
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
	public function send_error( $event_type, $error ) {
		if ( is_callable( $this->message_callback ) ) {
			call_user_func( $this->message_callback, $event_type, $error, true, false );
		}
	}

	/**
	 * Send a done signal
	 *
	 * @param string $event_type The event type.
	 */
	public function send_done( $event_type = null ) {
		if ( is_callable( $this->message_callback ) ) {
			call_user_func( $this->message_callback, $event_type, null, false, true );
		}
	}

	/**
	 * Transform string to different case formats
	 *
	 * @param string $input_string The input string.
	 * @param string $case_type The target case format (snake, camel, pascal, etc.).
	 * @return string The transformed string.
	 */
	public function transform_case( $input_string, $case_type = 'snake_upper' ) {
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
}
