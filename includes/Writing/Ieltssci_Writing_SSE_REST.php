<?php
/**
 * IELTS Science Writing SSE REST API Handler
 *
 * This file contains the class that handles Server-Sent Events (SSE) REST endpoints
 * for the IELTS Science Writing module.
 *
 * @package IeltsScienceLMS
 * @subpackage Writing
 */

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

	/**
	 * The base endpoint for writing-related API routes.
	 *
	 * @var string
	 */
	private $base = 'writing';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->base . '/feedback',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_essay_feedback' ),
				'permission_callback' => '__return_true', // Accessible to anyone.
				'args'                => array(
					'UUID'           => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && ! empty( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'feed_id'        => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && intval( $param ) > 0;
						},
						'sanitize_callback' => 'absint',
					),
					'language'       => array(
						'required'          => false,
						'validate_callback' => function ( $param ) {
							return is_string( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'feedback_style' => array(
						'required'          => false,
						'validate_callback' => function ( $param ) {
							return is_string( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'guide_score'    => array(
						'required'          => false,
						'validate_callback' => function ( $param ) {
							return is_string( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'guide_feedback' => array(
						'required'          => false,
						'validate_callback' => function ( $param ) {
							return is_string( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Register route for segment feedback.
		register_rest_route(
			$this->namespace,
			'/' . $this->base . '/segment-feedback',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_segment_feedback' ),
				'permission_callback' => '__return_true', // Accessible to anyone.
				'args'                => array(
					'UUID'           => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && ! empty( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'feed_id'        => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && intval( $param ) > 0;
						},
						'sanitize_callback' => 'absint',
					),
					'segment_order'  => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && intval( $param ) >= 0;
						},
						'sanitize_callback' => 'absint',
					),
					'language'       => array(
						'required'          => false,
						'validate_callback' => function ( $param ) {
							return is_string( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'feedback_style' => array(
						'required'          => false,
						'validate_callback' => function ( $param ) {
							return is_string( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'guide_score'    => array(
						'required'          => false,
						'validate_callback' => function ( $param ) {
							return is_string( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'guide_feedback' => array(
						'required'          => false,
						'validate_callback' => function ( $param ) {
							return is_string( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Callback for the feedback endpoint.
	 *
	 * Streams AI-generated feedback for an essay.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function get_essay_feedback( $request ) {
		// Get parameters.
		$uuid           = $request->get_param( 'UUID' );
		$feed_id        = $request->get_param( 'feed_id' );
		$language       = $request->get_param( 'language' );
		$feedback_style = $request->get_param( 'feedback_style' );
		$guide_score    = $request->get_param( 'guide_score' );
		$guide_feedback = $request->get_param( 'guide_feedback' );

		// Set up SSE headers.
		$this->set_sse_headers();

		// Create feedback processor with message callback.
		$processor = new Ieltssci_Writing_Feedback_Processor(
			// Pass the message sending function as a callback.
			function ( $event_type, $data, $is_error = false ) {
				if ( $is_error ) {
					$this->send_error( $event_type, $data );
				} else {
					$this->send_message( $event_type, $data );
				}
			}
		);

		// Process the specific feed.
		$result = $processor->process_feed_by_id( $feed_id, $uuid, null, $language, $feedback_style, $guide_score, $guide_feedback );

		// Handle errors.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Signal completion.
		$this->send_done( 'END' );

		exit;
	}

	/**
	 * Callback for the segment feedback endpoint.
	 *
	 * Streams AI-generated feedback for a specific segment of an essay.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function get_segment_feedback( $request ) {
		// Get parameters.
		$uuid           = $request->get_param( 'UUID' );
		$feed_id        = $request->get_param( 'feed_id' );
		$segment_order  = $request->get_param( 'segment_order' );
		$language       = $request->get_param( 'language' );
		$feedback_style = $request->get_param( 'feedback_style' );
		$guide_score    = $request->get_param( 'guide_score' );
		$guide_feedback = $request->get_param( 'guide_feedback' );

		// Set up SSE headers.
		$this->set_sse_headers();

		// Create feedback processor with message callback.
		$processor = new Ieltssci_Writing_Feedback_Processor(
			// Pass the message sending function as a callback.
			function ( $event_type, $data, $is_error = false ) {
				if ( $is_error ) {
					$this->send_error( $event_type, $data );
				} else {
					$this->send_message( $event_type, $data );
				}
			}
		);

		// Process the specific feed for the segment.
		$result = $processor->process_feed_by_id( $feed_id, $uuid, $segment_order, $language, $feedback_style, $guide_score, $guide_feedback );

		// Handle errors.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Signal completion.
		$this->send_done( 'END' );

		exit;
	}

	/**
	 * Set headers for SSE.
	 */
	private function set_sse_headers() {
		// Disable output buffering at all levels.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// Prevent PHP from buffering and sending content in chunks.
		if ( function_exists( 'apache_setenv' ) ) {
			apache_setenv( 'no-gzip', '1' );
		}

		// Disable compression.
		ini_set( 'zlib.output_compression', '0' );

		// Set headers for SSE.
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' ); // Disable buffering for Nginx.

		// Ensure output is not buffered.
		set_time_limit( 0 );
		// ignore_user_abort( true );.
	}

	/**
	 * Send an SSE message.
	 *
	 * @param string $event_type The event type.
	 * @param mixed  $data The data to send.
	 */
	private function send_message( $event_type, $data ) {
		echo 'event: ' . esc_html( $event_type ) . "\n";
		echo 'data: ' . wp_json_encode( array( 'data' => $data ) ) . "\n\n";

		// Force flush the output buffer.
		if ( ob_get_level() > 0 ) {
			ob_flush();
		}
		flush();
	}

	/**
	 * Send an error message.
	 *
	 * @param string $event_type The event type.
	 * @param array  $error Error details with title, message, ctaTitle, and ctaLink.
	 */
	private function send_error( $event_type, $error ) {

		echo 'event: ' . esc_html( $event_type ) . "\n";
		echo 'data: ' . wp_json_encode( array( 'error' => $error ) ) . "\n\n";

		if ( ob_get_level() > 0 ) {
			ob_flush();
		}
		flush();
	}

	/**
	 * Send a done signal for an event type.
	 *
	 * @param string $event_type The event type.
	 */
	private function send_done( $event_type ) {
		echo 'event: ' . esc_html( $event_type ) . "\n";
		echo "data: [DONE]\n\n";

		if ( ob_get_level() > 0 ) {
			ob_flush();
		}
		flush();
	}
}
