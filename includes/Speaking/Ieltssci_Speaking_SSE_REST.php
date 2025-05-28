<?php
/**
 * IELTS Science Speaking SSE REST API Handler
 *
 * This file contains the class that handles Server-Sent Events (SSE) REST endpoints
 * for the IELTS Science Speaking module.
 *
 * @package IeltsScienceLMS
 * @subpackage Speaking
 */

namespace IeltsScienceLMS\Speaking;

use WP_REST_Server;
use WP_REST_Request;
use IeltsScienceLMS\RateLimits\Ieltssci_RateLimit;

/**
 * Class Ieltssci_Speaking_SSE_REST
 *
 * Handles Server-Sent Events (SSE) REST endpoints for the IELTS Science Speaking module.
 * This allows for real-time streaming of AI-generated feedback and other speaking-related events.
 */
class Ieltssci_Speaking_SSE_REST {
	/**
	 * The namespace for the REST API endpoints.
	 *
	 * @var string
	 */
	private $namespace = 'ieltssci/v1';

	/**
	 * The base endpoint for speaking-related API routes.
	 *
	 * @var string
	 */
	private $base = 'speaking';

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
				'callback'            => array( $this, 'get_recording_feedback' ),
				'permission_callback' => '__return_true',
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
					'refetch'        => array(
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
	 * Streams AI-generated feedback for a speaking recording.
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function get_recording_feedback( $request ) {
		// Get parameters.
		$uuid           = $request->get_param( 'UUID' );
		$feed_id        = $request->get_param( 'feed_id' );
		$language       = $request->get_param( 'language' );
		$feedback_style = $request->get_param( 'feedback_style' );
		$guide_score    = $request->get_param( 'guide_score' );
		$guide_feedback = $request->get_param( 'guide_feedback' );
		$refetch        = $request->get_param( 'refetch' );

		// Check rate limits before setting headers.
		$rate_limiter = new Ieltssci_RateLimit();
		$rate_check   = $rate_limiter->check_rate_limit( 'speech_feedback', $uuid, $feed_id );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// If this is a HEAD request, we've already checked rate limits, so just return success.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			return new \WP_REST_Response( null, 200 );
		}

		// Set up SSE headers.
		$this->set_sse_headers();

		// Create feedback processor with message callback.
		$processor = new Ieltssci_Speaking_Feedback_Processor(
			// Pass a callback that can handle all three message types.
			function ( $event_type, $data, $is_error = false, $is_done = false ) {
				if ( $is_done ) {
					$this->send_done( $event_type );
				} elseif ( $is_error ) {
					$this->send_error( $event_type, $data );
				} else {
					$this->send_message( $event_type, $data );
				}
			}
		);

		// Process the specific feed.
		$result = $processor->process_feed_by_id(
			$feed_id,
			$uuid,
			$language,
			$feedback_style,
			$guide_score,
			$guide_feedback,
			$refetch
		);

		// Handle errors.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Signal completion.
		$this->send_done();

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
	 * @param string $event_type The event type (optional).
	 */
	private function send_done( $event_type = null ) {
		if ( $event_type ) {
			echo 'event: ' . esc_html( $event_type ) . "\n";
		}
		echo "data: [DONE]\n\n";

		if ( ob_get_level() > 0 ) {
			ob_flush();
		}
		flush();
	}
}
