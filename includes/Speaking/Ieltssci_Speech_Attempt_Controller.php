<?php
/**
 * REST API Controller for IELTS Science Speech Attempts
 *
 * Handles creating and retrieving speech attempts with audio uploads.
 * Hooks into WordPress media REST API to automatically create speech attempts.
 *
 * @package IELTS_Science_LMS
 * @subpackage Speaking
 */

namespace IeltsScienceLMS\Speaking;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_Post;

/**
 * Class Ieltssci_Speech_Attempt_Controller
 *
 * REST API controller for speech attempts with audio file uploads.
 * Extends WordPress media upload to automatically create speech attempt records.
 *
 * @since 1.0.0
 */
class Ieltssci_Speech_Attempt_Controller extends WP_REST_Controller {
	/**
	 * Namespace for REST routes.
	 *
	 * @var string
	 */
	protected $namespace = 'ieltssci/v1';

	/**
	 * Resource name for speech attempts.
	 *
	 * @var string
	 */
	protected $resource_name = 'speech-attempts';

	/**
	 * DB handler.
	 *
	 * @var Ieltssci_Submission_DB
	 */
	protected $db;

	/**
	 * Constructor.
	 *
	 * Initializes the controller and registers hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->db = new Ieltssci_Submission_DB();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'rest_api_init', array( $this, 'register_attachment_hooks' ) );
	}

	/**
	 * Register hooks for attachment REST API.
	 *
	 * Hooks into WordPress media upload to create speech attempts automatically.
	 *
	 * @since 1.0.0
	 */
	public function register_attachment_hooks() {
		// Hook after attachment is inserted via REST API.
		add_action( 'rest_after_insert_attachment', array( $this, 'create_speech_attempt_from_attachment' ), 10, 3 );

		// Hook to modify attachment response to include speech attempt data.
		add_filter( 'rest_prepare_attachment', array( $this, 'add_speech_attempt_to_response' ), 10, 3 );
	}

	/**
	 * Register REST API routes.
	 *
	 * Registers routes for retrieving speech attempts (GET only).
	 * Creation is handled via WordPress media upload with hooks.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Collection route (GET only).
		register_rest_route(
			$this->namespace,
			'/' . $this->resource_name,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// Single item route (GET only).
		register_rest_route(
			$this->namespace,
			'/' . $this->resource_name . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => 'Unique identifier for the speech attempt.',
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Create speech attempt from attachment upload.
	 *
	 * Triggered after an attachment is created via REST API.
	 * Checks for submission_id and question_id in the request.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post         $attachment Inserted or updated attachment object.
	 * @param WP_REST_Request $request    The REST request.
	 * @param bool            $creating   True when creating, false when updating.
	 */
	public function create_speech_attempt_from_attachment( $attachment, $request, $creating ) {
		// Only process on creation, not updates.
		if ( ! $creating ) {
			return;
		}

		// Check if this is an audio file.
		$mime_type = get_post_mime_type( $attachment->ID );
		if ( ! $mime_type || strpos( $mime_type, 'audio/' ) !== 0 ) {
			return;
		}

		// Check if speech attempt parameters are present.
		$submission_id = $request->get_param( 'submission_id' );
		$question_id   = $request->get_param( 'question_id' );

		if ( empty( $submission_id ) || empty( $question_id ) ) {
			return;
		}

		// Create speech attempt record.
		$attempt_data = array(
			'submission_id' => (int) $submission_id,
			'question_id'   => (int) $question_id,
			'audio_id'      => $attachment->ID,
			'created_by'    => get_current_user_id(),
		);

		$attempt_id = $this->db->add_speech_attempt( $attempt_data );

		if ( is_wp_error( $attempt_id ) ) {
			// Store the error in post meta so we can include it in the response.
			update_post_meta( $attachment->ID, '_speech_attempt_error', $attempt_id->get_error_message() );
			return;
		}

		// Store the attempt ID in post meta for later retrieval.
		update_post_meta( $attachment->ID, '_speech_attempt_id', $attempt_id );
	}

	/**
	 * Add speech attempt data to attachment response.
	 *
	 * Modifies the REST response for attachments to include speech attempt information.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WP_Post          $post     The attachment post object.
	 * @param WP_REST_Request  $request  The REST request.
	 * @return WP_REST_Response Modified response object.
	 */
	public function add_speech_attempt_to_response( $response, $post, $request ) {
		$data = $response->get_data();

		// Exit early if speech attempt data is already in the response.
		if ( isset( $data['speech_attempt'] ) || isset( $data['speech_attempt_error'] ) ) {
			return $response;
		}

		// Check if this attachment has an associated speech attempt.
		$attempt_id = get_post_meta( $post->ID, '_speech_attempt_id', true );

		if ( $attempt_id ) {
			$attempt = $this->db->get_speech_attempt( (int) $attempt_id );

			if ( ! is_wp_error( $attempt ) && $attempt ) {
				$data['speech_attempt'] = $attempt;

				// Only add link if it doesn't already exist to avoid duplicates.
				$links = $response->get_links();
				if ( empty( $links['speech_attempt'] ) ) {
					$response->add_link(
						'speech_attempt',
						rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->resource_name, $attempt['id'] ) ),
						array( 'embeddable' => true )
					);
				}
			}
		}

		// Check for any errors during attempt creation.
		$attempt_error = get_post_meta( $post->ID, '_speech_attempt_error', true );
		if ( $attempt_error ) {
			$data['speech_attempt_error'] = $attempt_error;
		}

		$response->set_data( $data );
		return $response;
	}

	/**
	 * Check if the current user can read speech attempts.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool|WP_Error Whether the user can read attempts.
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'You must be logged in.', array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Check if the current user can read a specific speech attempt.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool|WP_Error Whether the user can read the attempt.
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'You must be logged in.', array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Check if the current user can create speech attempts.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool|WP_Error Whether the user can create attempts.
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'You must be logged in.', array( 'status' => 401 ) );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'rest_forbidden', 'You do not have permission to upload files.', array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Check if a user can access a specific speech attempt.
	 *
	 * @since 1.0.0
	 *
	 * @param array           $attempt Speech attempt data.
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool Whether the user can access the attempt.
	 */
	protected function can_access_attempt( $attempt, $request ) {
		$current_user_id = get_current_user_id();

		// Admins can access all.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Users can access their own attempts.
		if ( (int) $attempt['created_by'] === $current_user_id ) {
			return true;
		}

		// Allow filtering for custom access logic.
		return apply_filters( 'ieltssci_can_access_speech_attempt', false, $attempt, $current_user_id, $request );
	}

	/**
	 * Get a collection of speech attempts.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$params = array();

		if ( $request->has_param( 'submission_id' ) ) {
			$params['submission_id'] = (int) $request->get_param( 'submission_id' );
		}

		if ( $request->has_param( 'question_id' ) ) {
			$params['question_id'] = (int) $request->get_param( 'question_id' );
		}

		if ( $request->has_param( 'created_by' ) ) {
			$params['created_by'] = (int) $request->get_param( 'created_by' );
		}

		if ( $request->has_param( 'audio_id' ) ) {
			$params['audio_id'] = (int) $request->get_param( 'audio_id' );
		}

		$attempts = $this->db->get_speech_attempts( $params );

		if ( is_wp_error( $attempts ) ) {
			return $attempts;
		}

		$data = array();
		foreach ( $attempts as $attempt ) {
			if ( $this->can_access_attempt( $attempt, $request ) ) {
				$response = $this->prepare_item_for_response( $attempt, $request );
				$data[]   = $response->get_data();
			}
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Get a single speech attempt.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$attempt_id = (int) $request->get_param( 'id' );
		$attempt    = $this->db->get_speech_attempt( $attempt_id );

		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}

		if ( ! $attempt ) {
			return new WP_Error( 'rest_not_found', 'Speech attempt not found.', array( 'status' => 404 ) );
		}

		if ( ! $this->can_access_attempt( $attempt, $request ) ) {
			return new WP_Error( 'rest_forbidden', 'You do not have permission to view this attempt.', array( 'status' => 403 ) );
		}

		return $this->prepare_item_for_response( $attempt, $request );
	}

	/**
	 * Prepare a speech attempt for response.
	 *
	 * @since 1.0.0
	 *
	 * @param array           $attempt Speech attempt data.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $attempt, $request ) {
		$data = array(
			'id'            => (int) $attempt['id'],
			'submission_id' => (int) $attempt['submission_id'],
			'question_id'   => (int) $attempt['question_id'],
			'audio_id'      => (int) $attempt['audio_id'],
			'created_by'    => (int) $attempt['created_by'],
			'created_at'    => $attempt['created_at'],
		);

		$response = rest_ensure_response( $data );
		$response->add_links( $this->prepare_links( $attempt ) );

		return $response;
	}

	/**
	 * Prepare links for a speech attempt.
	 *
	 * @since 1.0.0
	 *
	 * @param array $attempt Speech attempt data.
	 * @return array Links for the given attempt.
	 */
	protected function prepare_links( $attempt ) {
		$base = sprintf( '%s/%s', $this->namespace, $this->resource_name );

		$links = array(
			'self'       => array(
				'href' => rest_url( trailingslashit( $base ) . $attempt['id'] ),
			),
			'collection' => array(
				'href' => rest_url( $base ),
			),
		);

		if ( ! empty( $attempt['audio_id'] ) ) {
			$links['audio'] = array(
				'href'       => rest_url( 'wp/v2/media/' . $attempt['audio_id'] ),
				'embeddable' => true,
			);
		}

		return $links;
	}

	/**
	 * Get parameters for filtering speech attempts collection.
	 *
	 * @since 1.0.0
	 *
	 * @return array Parameters definition.
	 */
	public function get_collection_params() {
		return array(
			'submission_id' => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'description'       => 'Filter by submission ID.',
			),
			'question_id'   => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'description'       => 'Filter by question ID.',
			),
			'created_by'    => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'description'       => 'Filter by creator user ID.',
			),
			'audio_id'      => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'description'       => 'Filter by audio attachment ID.',
			),
		);
	}

	/**
	 * Get the speech attempt schema, conforming to JSON Schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'speech-attempt',
			'type'       => 'object',
			'properties' => array(
				'id'            => array(
					'description' => 'Unique identifier for the attempt.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'submission_id' => array(
					'description' => 'Speaking part submission ID.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'question_id'   => array(
					'description' => 'Question ID.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'audio_id'      => array(
					'description' => 'Attachment ID for audio file.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'created_by'    => array(
					'description' => 'User ID who created the attempt.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'created_at'    => array(
					'description' => 'Creation timestamp.',
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);
	}
}
