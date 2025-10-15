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
	 * Feedback service instance.
	 *
	 * @var Ieltssci_Speaking_Feedback_DB
	 */
	protected $feedback_service;

	/**
	 * Constructor.
	 *
	 * Initializes the controller and registers hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->db               = new Ieltssci_Submission_DB();
		$this->feedback_service = new Ieltssci_Speaking_Feedback_DB();
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

		// Register route for updating speech attempt feedback.
		register_rest_route(
			$this->namespace,
			'/' . $this->resource_name . '/feedback/(?P<attempt_id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_attempt_feedback' ),
				'permission_callback' => array( $this, 'update_attempt_feedback_permissions_check' ),
				'args'                => $this->get_attempt_feedback_args(),
			)
		);
	}

	/**
	 * Create speech attempt from attachment upload.
	 *
	 * Triggered after an attachment is created via REST API.
	 * Creates speech attempt only if submission_id and question_id are provided,
	 * or if create_speech_attempt is explicitly set to true.
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

		// Check if this is an audio or video file.
		$mime_type = get_post_mime_type( $attachment->ID );
		if ( ! $mime_type || ( strpos( $mime_type, 'audio/' ) !== 0 && strpos( $mime_type, 'video/' ) !== 0 ) ) {
			return;
		}

		// Get speech attempt parameters (optional).
		$submission_id = $request->get_param( 'submission_id' );
		$question_id   = $request->get_param( 'question_id' );

		// If both submission_id and question_id are empty, require explicit create_speech_attempt flag.
		$submission_provided = isset( $submission_id ) && ! empty( $submission_id );
		$question_provided   = isset( $question_id ) && ! empty( $question_id );

		if ( ! $submission_provided && ! $question_provided ) {
			$create_attempt = $request->get_param( 'create_speech_attempt' );
			if ( ! $create_attempt || 'true' !== $create_attempt ) {
				return;
			}
		}

		// Create speech attempt record.
		$attempt_data = array(
			'audio_id'   => $attachment->ID,
			'created_by' => get_current_user_id(),
		);

		if ( $submission_provided ) {
			$attempt_data['submission_id'] = (int) $submission_id;
		}

		if ( $question_provided ) {
			$attempt_data['question_id'] = (int) $question_id;
		}

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

		if ( $request->has_param( 'id' ) ) {
			$id = $request->get_param( 'id' );
			if ( is_array( $id ) ) {
				$params['id'] = array_map( 'intval', $id );
			} else {
				$params['id'] = (int) $id;
			}
		}

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

		if ( $request->has_param( 'date_query' ) ) {
			$params['date_query'] = $request->get_param( 'date_query' );
		}

		if ( $request->has_param( 'orderby' ) ) {
			$params['orderby'] = $request->get_param( 'orderby' );
		}

		if ( $request->has_param( 'order' ) ) {
			$params['order'] = $request->get_param( 'order' );
		}

		if ( $request->has_param( 'number' ) ) {
			$params['number'] = (int) $request->get_param( 'number' );
		}

		if ( $request->has_param( 'offset' ) ) {
			$params['offset'] = (int) $request->get_param( 'offset' );
		}

		if ( $request->has_param( 'count' ) ) {
			$params['count'] = (bool) $request->get_param( 'count' );
		}

		// Handle pagination.
		if ( $request->has_param( 'page' ) && $request->has_param( 'per_page' ) ) {
			$page             = (int) $request->get_param( 'page' );
			$per_page         = (int) $request->get_param( 'per_page' );
			$params['number'] = $per_page;
			$params['offset'] = ( $page - 1 ) * $per_page;
		} elseif ( $request->has_param( 'per_page' ) ) {
			$params['number'] = (int) $request->get_param( 'per_page' );
		}

		$attempts = $this->db->get_speech_attempts( $params );

		if ( is_wp_error( $attempts ) ) {
			return $attempts;
		}

		if ( isset( $params['count'] ) && $params['count'] ) {
			return rest_ensure_response( $attempts );
		}

		$data = array();
		foreach ( $attempts as $attempt ) {
			if ( $this->can_access_attempt( $attempt, $request ) ) {
				$response = $this->prepare_item_for_response( $attempt, $request );
				// Follow core pattern: wrap item responses so _links are preserved in collections.
				$data[] = $this->prepare_response_for_collection( $response );
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
			'submission_id' => ! is_null( $attempt['submission_id'] ) ? (int) $attempt['submission_id'] : null,
			'question_id'   => ! is_null( $attempt['question_id'] ) ? (int) $attempt['question_id'] : null,
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
			'id'            => array(
				'description'       => 'Filter by attempt ID or array of IDs.',
				'type'              => array( 'integer', 'array' ),
				'items'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'sanitize_callback' => function ( $value ) {
					if ( is_array( $value ) ) {
						return array_map( 'absint', $value );
					}
					return absint( $value );
				},
			),
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
			'date_query'    => array(
				'description' => 'Date query for filtering by created_at.',
				'type'        => 'object',
				'properties'  => array(
					'after'  => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
					'before' => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
				),
			),
			'orderby'       => array(
				'description' => 'Field to order by.',
				'type'        => 'string',
				'enum'        => array( 'id', 'created_at' ),
				'default'     => 'id',
			),
			'order'         => array(
				'description' => 'Sort order.',
				'type'        => 'string',
				'enum'        => array( 'ASC', 'DESC' ),
				'default'     => 'DESC',
			),
			'number'        => array(
				'description' => 'Number of results to return.',
				'type'        => 'integer',
				'minimum'     => 1,
				'default'     => 20,
			),
			'offset'        => array(
				'description' => 'Offset for pagination.',
				'type'        => 'integer',
				'minimum'     => 0,
				'default'     => 0,
			),
			'count'         => array(
				'description' => 'Whether to return a count instead of results.',
				'type'        => 'boolean',
				'default'     => false,
			),
			'page'          => array(
				'description' => 'Current page of the collection.',
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
			'per_page'      => array(
				'description' => 'Maximum number of items to be returned in result set.',
				'type'        => 'integer',
				'default'     => 10,
				'minimum'     => 1,
				'maximum'     => 100,
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
					'type'        => array( 'integer', 'null' ),
					'context'     => array( 'view', 'edit' ),
				),
				'question_id'   => array(
					'description' => 'Question ID.',
					'type'        => array( 'integer', 'null' ),
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

	/**
	 * Get arguments for the update attempt feedback endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_attempt_feedback_args() {
		return array(
			'attempt_id'        => array(
				'type'              => 'integer',
				'required'          => true,
				'description'       => 'The ID of the speech attempt.',
				'sanitize_callback' => 'absint',
			),
			'feedback_criteria' => array(
				'type'              => 'string',
				'required'          => true,
				'description'       => 'The feedback criteria.',
				'sanitize_callback' => 'sanitize_key',
			),
			'language'          => array(
				'type'              => 'string',
				'required'          => true,
				'description'       => 'The feedback language.',
				'sanitize_callback' => 'sanitize_key',
			),
			'cot_content'       => array(
				'type'              => 'string',
				'required'          => false,
				'description'       => 'Chain of thought content.',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'score_content'     => array(
				'type'              => 'string',
				'required'          => false,
				'description'       => 'Score content.',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'feedback_content'  => array(
				'type'              => 'string',
				'required'          => false,
				'description'       => 'Feedback content.',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);
	}

	/**
	 * Check permissions for updating attempt feedback.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error True if allowed, WP_Error otherwise.
	 */
	public function update_attempt_feedback_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Update attempt feedback.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function update_attempt_feedback( WP_REST_Request $request ) {
		$attempt_id        = $request->get_param( 'attempt_id' );
		$feedback_criteria = $request->get_param( 'feedback_criteria' );
		$language          = $request->get_param( 'language' );
		$cot_content       = $request->get_param( 'cot_content' );
		$score_content     = $request->get_param( 'score_content' );
		$feedback_content  = $request->get_param( 'feedback_content' );

		// Validate that at least one content field is provided.
		if ( empty( $cot_content ) && empty( $score_content ) && empty( $feedback_content ) ) {
			return new WP_Error(
				'missing_content',
				__( 'At least one of cot_content, score_content, or feedback_content must be provided.', 'ielts-science-lms' ),
				array( 'status' => 400 )
			);
		}

		// Load attempt to verify existence and ownership.
		$submission_db = new Ieltssci_Submission_DB();
		$attempt       = $submission_db->get_speech_attempt( (int) $attempt_id );

		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}

		if ( empty( $attempt ) ) {
			return new WP_Error(
				'attempt_not_found',
				__( 'Speech attempt not found.', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		// Verify ownership.
		if ( get_current_user_id() !== (int) $attempt['created_by'] ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to update feedback for this speech attempt.', 'ielts-science-lms' ),
				array( 'status' => 403 )
			);
		}

		// Set up feed array for feedback database function.
		$feed = array(
			'apply_to'          => 'attempt',
			'feedback_criteria' => $feedback_criteria,
		);

		$success = array();
		$errors  = array();

		// Process each content type if provided.
		if ( ! empty( $cot_content ) ) {
			$result = $this->feedback_service->save_feedback_to_database(
				$cot_content,
				$feed,
				null,
				'chain-of-thought',
				$attempt,
				$language,
				'human'
			);

			if ( is_wp_error( $result ) || false === $result ) {
				$errors[] = 'cot';
			} else {
				$success[] = 'cot';
			}
		}

		if ( ! empty( $score_content ) ) {
			$result = $this->feedback_service->save_feedback_to_database(
				$score_content,
				$feed,
				null,
				'scoring',
				$attempt,
				$language,
				'human'
			);

			if ( is_wp_error( $result ) || false === $result ) {
				$errors[] = 'score';
			} else {
				$success[] = 'score';
			}
		}

		if ( ! empty( $feedback_content ) ) {
			$result = $this->feedback_service->save_feedback_to_database(
				$feedback_content,
				$feed,
				null,
				'feedback',
				$attempt,
				$language,
				'human'
			);

			if ( is_wp_error( $result ) || false === $result ) {
				$errors[] = 'feedback';
			} else {
				$success[] = 'feedback';
			}
		}

		// Return appropriate response based on results.
		if ( empty( $success ) ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update any attempt feedback content.', 'ielts-science-lms' ),
				array(
					'status' => 500,
					'detail' => $errors,
				)
			);
		}

		return new WP_REST_Response(
			array(
				'status'     => 'success',
				'message'    => __( 'Attempt feedback updated successfully.', 'ielts-science-lms' ),
				'updated'    => $success,
				'failed'     => $errors,
				'attempt_id' => (int) $attempt['id'],
			),
			200
		);
	}
}
