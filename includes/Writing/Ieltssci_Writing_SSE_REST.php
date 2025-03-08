<?php

namespace IeltsScienceLMS\Writing;

use WP_REST_Server;
use WP_REST_Request;
use WP_Error;

/**
 * Class Ieltssci_Writing_SSE_REST
 * 
 * Handles Server-Sent Events (SSE) REST endpoints for the IELTS Science Writing module.
 * This allows for real-time streaming of AI-generated feedback and other writing-related events.
 */
class Ieltssci_Writing_SSE_REST {
	/**
	 * The namespace for the REST API endpoints.
	 *
	 * @var string
	 */
	private $namespace = 'ieltssci/v1';

	private $base = 'writing';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->base . '/feedback',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_essay_feedback' ),
				'permission_callback' => '__return_true', // Accessible to anyone
				'args' => array(
					'UUID' => array(
						'required' => true,
						'validate_callback' => function ($param) {
							return is_string( $param ) && ! empty( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'feed_id' => array(
						'required' => true,
						'validate_callback' => function ($param) {
							return is_numeric( $param ) && intval( $param ) > 0;
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Callback for the feedback endpoint
	 * 
	 * Streams AI-generated feedback for an essay
	 * 
	 * @param WP_REST_Request $request The request object.
	 */
	public function get_essay_feedback( $request ) {
		// Get parameters
		$uuid = $request->get_param( 'UUID' );
		$feed_id = $request->get_param( 'feed_id' );

		// Set up SSE headers
		$this->set_sse_headers();

		// Create feedback processor with message callback
		$processor = new Ieltssci_Writing_Feedback_Processor(
			// Pass the message sending function as a callback
			function ($event_type, $data, $is_error = false) {
				if ( $is_error ) {
					$this->send_error( $event_type, $data );
				} else {
					$this->send_message( $event_type, $data );
				}
			}
		);

		// Process the specific feed
		$result = $processor->process_feed_by_id( $feed_id, $uuid );

		// Handle errors
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Signal completion
		$this->send_done( 'END' );

		exit;
	}

	/**
	 * Set headers for SSE
	 */
	private function set_sse_headers() {
		// Disable output buffering at all levels
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// Prevent PHP from buffering and sending content in chunks
		if ( function_exists( 'apache_setenv' ) ) {
			apache_setenv( 'no-gzip', '1' );
		}

		// Disable compression
		ini_set( 'zlib.output_compression', '0' );

		// Set headers for SSE
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' ); // Disable buffering for Nginx

		// Ensure output is not buffered
		set_time_limit( 0 );
		// ignore_user_abort( true );
	}

	/**
	 * Send an SSE message
	 * 
	 * @param string $event_type The event type.
	 * @param mixed $data The data to send.
	 */
	private function send_message( $event_type, $data ) {
		$json_data = json_encode( [ 'data' => $data ] );
		echo "event: {$event_type}\n";
		echo "data: {$json_data}\n\n";

		// Force flush the output buffer
		if ( ob_get_level() > 0 ) {
			ob_flush();
		}
		flush();
	}

	/**
	 * Send an error message
	 * 
	 * @param string $event_type The event type.
	 * @param array $error Error details with title, message, ctaTitle, and ctaLink.
	 */
	private function send_error( $event_type, $error ) {
		$error_data = json_encode( array( 'error' => $error ) );

		echo "event: {$event_type}\n";
		echo "data: {$error_data}\n\n";

		if ( ob_get_level() > 0 ) {
			ob_flush();
		}
		flush();
	}

	/**
	 * Send a done signal for an event type
	 * 
	 * @param string $event_type The event type.
	 */
	private function send_done( $event_type ) {
		echo "event: {$event_type}\n";
		echo "data: [DONE]\n\n";

		if ( ob_get_level() > 0 ) {
			ob_flush();
		}
		flush();
	}
}