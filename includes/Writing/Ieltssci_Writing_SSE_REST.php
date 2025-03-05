<?php

namespace IeltsScienceLMS\Writing;

use WP_REST_Server;
use WP_REST_Request;
use WP_Error;
use Exception;
use IeltsScienceLMS\ApiFeeds\Ieltssci_ApiFeeds_DB;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;

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
	 * Database handler for essays
	 * 
	 * @var Ieltssci_Essay_DB
	 */
	private $essays_db;

	/**
	 * API Feeds database handler
	 * 
	 * @var Ieltssci_ApiFeeds_DB
	 */
	private $api_feeds_db;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->essays_db = new Ieltssci_Essay_DB();
		$this->api_feeds_db = new Ieltssci_ApiFeeds_DB();
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
				'methods' => WP_REST_Server::CREATABLE,
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
					'essay_type' => array(
						'required' => true,
						'validate_callback' => function ($param) {
							return is_string( $param ) && ! empty( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
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
		$essay_type = $request->get_param( 'essay_type' );

		// Get all feeds that need processing based on the essay type
		$feeds = $this->api_feeds_db->get_api_feeds( [ 
			'essay_type' => $essay_type,
			'order_by' => 'process_order',
			'order_direction' => 'ASC',
			'limit' => 50,
			'include' => [ 'meta', 'process_order' ],
		] );

		if ( is_wp_error( $feeds ) ) {
			return $feeds;
		}

		if ( empty( $feeds ) ) {
			return new WP_Error( 500, 'No feedback available for this essay type.' );
		}

		// No error, start streaming feedback
		$this->set_sse_headers();

		// Group feeds by process_order
		$feeds_by_process_order = [];
		foreach ( $feeds as $feed ) {
			$process_order = (int) $feed['process_order'];
			if ( ! isset( $feeds_by_process_order[ $process_order ] ) ) {
				$feeds_by_process_order[ $process_order ] = [];
			}
			$feeds_by_process_order[ $process_order ][] = $feed;
		}

		// Process each group in order
		foreach ( $feeds_by_process_order as $process_order => $group_feeds ) {
			$this->send_message( 'processing_group', [ 
				'process_order' => $process_order,
				'feed_count' => count( $group_feeds ),
			] );

			// Process each feed in the group concurrently
			$this->process_feeds_concurrently( $group_feeds, $uuid );
		}

		// Signal completion
		$this->send_done( 'END' );

		exit;
	}

	/**
	 * Process a group of feeds concurrently
	 *
	 * @param array $feeds Array of feed data to process
	 * @param string $uuid The UUID of the essay
	 * @param int $concurrency Maximum number of concurrent operations
	 */
	private function process_feeds_concurrently( $feeds, $uuid, $concurrency = 3 ) {

	}

	/**
	 * Set headers for SSE
	 */
	private function set_sse_headers() {
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' ); // Disable buffering for Nginx

		// Prevent output buffering
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Disable time limit
		set_time_limit( 0 );
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

		// Flush output buffer
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

		flush();
	}

}
