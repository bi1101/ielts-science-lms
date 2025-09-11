<?php
/**
 * REST API Controller for IELTS Science Task Submissions
 *
 * Handles CRUD operations for task submissions, including meta fields.
 *
 * @package IELTS_Science_LMS
 * @subpackage Writing
 */

namespace IeltsScienceLMS\Writing;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Class Ieltssci_Submission_Controller
 *
 * REST API controller for writing task submissions.
 *
 * @since 1.0.0
 */
class Ieltssci_Writing_Task_Submission_Controller extends WP_REST_Controller {
	/**
	 * Namespace for REST routes.
	 *
	 * @var string
	 */
	protected $namespace = 'ieltssci/v1';
	/**
	 * Resource name for task submissions.
	 *
	 * @var string
	 */
	protected $resource_task = 'writing-task-submissions';

	/**
	 * DB handler.
	 *
	 * @var Ieltssci_Submission_DB
	 */
	protected $db;
	/**
	 * Constructor.
	 *
	 * Initializes the controller and registers routes.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->db = new Ieltssci_Submission_DB();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * Registers all routes for task submissions including collection and single item endpoints.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Task submissions.
		register_rest_route(
			$this->namespace,
			'/' . $this->resource_task,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_task_submissions' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => $this->get_task_submissions_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_task_submission' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => $this->get_task_submission_create_params(),
				),
				'schema' => array( $this, 'get_task_submission_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->resource_task . '/(?P<id>[0-9a-f-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_task_submission' ),
					'permission_callback' => '__return_true', // Allow public access to single submission.
					'args'                => array(
						'id' => array(
							'description'       => 'Unique identifier (ID or UUID) for the task submission.',
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => function ( $param ) {
								// Accept either numeric ID or valid UUID format.
								return is_numeric( $param ) || wp_is_uuid( $param );
							},
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_task_submission' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => $this->get_task_submission_update_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_task_submission' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'id' => array(
							'description'       => 'Unique identifier (ID or UUID) for the task submission.',
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => function ( $param ) {
								// Accept either numeric ID or valid UUID format.
								return is_numeric( $param ) || wp_is_uuid( $param );
							},
						),
					),
				),
				'schema' => array( $this, 'get_task_submission_schema' ),
			)
		);

		// Register route for forking a task submission.
		register_rest_route(
			$this->namespace,
			'/' . $this->resource_task . '/fork/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'fork_task_submission' ),
					'permission_callback' => array( $this, 'fork_task_submission_permissions_check' ),
					'args'                => $this->get_fork_task_submission_args(),
				),
				'schema' => array( $this, 'get_fork_task_submission_schema' ),
			)
		);
	}

	/**
	 * Check if the current user can read submissions.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool Whether the user can read submissions.
	 */
	public function can_read( $request ) {
		return is_user_logged_in();
	}

	/**
	 * Check if the current user can edit submissions.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool Whether the user can edit submissions.
	 */
	public function can_edit( $request ) {
		return is_user_logged_in();
	}

	/**
	 * Check if the current user can access a specific submission.
	 *
	 * Users can access their own submissions, and administrators can access all submissions.
	 *
	 * @since 1.0.0
	 *
	 * @param array           $submission The submission data.
	 * @param WP_REST_Request $request    The REST request object.
	 * @return bool Whether the user can access the submission.
	 */
	protected function can_access_submission( $submission, $request ) {
		$current_user_id = get_current_user_id();

		// Administrators can access any submission.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Users can access their own submissions.
		if ( (int) $submission['user_id'] === $current_user_id ) {
			return true;
		}

		return false;
	}

	/**
	 * Get task submissions collection.
	 *
	 * Retrieves a collection of task submissions based on provided parameters.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_task_submissions( WP_REST_Request $request ) {
		// Build query arguments from request parameters.
		$args = array();

		// Apply access control based on user permissions.
		$current_user_id = get_current_user_id();

		// If not an administrator, restrict to current user's submissions only.
		if ( ! current_user_can( 'manage_options' ) ) {
			$args['user_id'] = $current_user_id;
			// Administrators can optionally filter by user_id.
		} elseif ( ! empty( $request['user_id'] ) ) {
			$args['user_id'] = (int) $request['user_id'];
		}

		// Map REST parameters to database query arguments.
		if ( ! empty( $request['task_id'] ) ) {
			$args['task_id'] = (int) $request['task_id'];
		}

		if ( ! empty( $request['test_submission_id'] ) ) {
			$args['test_submission_id'] = (int) $request['test_submission_id'];
		}

		if ( ! empty( $request['essay_id'] ) ) {
			$args['essay_id'] = (int) $request['essay_id'];
		}

		if ( ! empty( $request['status'] ) ) {
			$args['status'] = sanitize_text_field( $request['status'] );
		}

		// Handle pagination.
		$args['number'] = isset( $request['per_page'] ) ? (int) $request['per_page'] : 20;
		$args['offset'] = isset( $request['offset'] ) ? (int) $request['offset'] : 0;

		// Handle ordering.
		if ( ! empty( $request['orderby'] ) ) {
			$args['orderby'] = sanitize_text_field( $request['orderby'] );
		}

		if ( ! empty( $request['order'] ) ) {
			$args['order'] = strtoupper( sanitize_text_field( $request['order'] ) );
		}

		// Handle meta data inclusion based on client request.
		if ( isset( $request['include_meta'] ) ) {
			$args['include_meta'] = $request['include_meta'];
		} else {
			$args['include_meta'] = false;
		}

		// Get submissions from database.
		$submissions = $this->db->get_task_submissions( $args );

		if ( is_wp_error( $submissions ) ) {
			return $submissions;
		}

		// Prepare response data - all submissions should already be accessible based on query filtering.
		$data = array();
		foreach ( $submissions as $submission ) {
			$response = $this->prepare_task_submission_for_response( $submission, $request );
			$data[]   = $this->prepare_response_for_collection( $response );
		}

		// Get total count for pagination headers.
		$count_args          = $args;
		$count_args['count'] = true;
		unset( $count_args['number'], $count_args['offset'] );
		$total = $this->db->get_task_submissions( $count_args );

		if ( is_wp_error( $total ) ) {
			$total = 0;
		}

		// Create response with pagination headers.
		$response = rest_ensure_response( $data );
		$response->header( 'X-WP-Total', (int) $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / $args['number'] ) );

		return $response;
	}

	/**
	 * Get a single task submission by ID or UUID.
	 *
	 * Retrieves a specific task submission with its associated meta data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_task_submission( WP_REST_Request $request ) {
		$identifier = $request['id'];

		// Determine if identifier is numeric ID or UUID.
		if ( is_numeric( $identifier ) ) {
			$submission = $this->db->get_task_submission( (int) $identifier );
		} else {
			// It's a UUID, query by UUID.
			$submissions = $this->db->get_task_submissions( array( 'uuid' => $identifier ) );
			$submission  = ! empty( $submissions ) ? $submissions[0] : null;
		}

		if ( is_wp_error( $submission ) ) {
			return $submission;
		}

		if ( ! $submission ) {
			return new WP_Error(
				'ieltssci_task_submission_not_found',
				'Task submission not found.',
				array( 'status' => 404 )
			);
		}

		// Include meta data by default for single item requests.
		if ( ! isset( $submission['meta'] ) ) {
			$submission['meta'] = $this->db->get_task_submission_meta( $submission['id'] );
		}

		// Prepare the response.
		$response = $this->prepare_task_submission_for_response( $submission, $request );

		return $response;
	}

	/**
	 * Create a new task submission.
	 *
	 * Creates a new task submission with the provided data and meta information.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function create_task_submission( WP_REST_Request $request ) {
		$task_id = (int) $request['task_id'];
		$user_id = ! empty( $request['user_id'] ) ? (int) $request['user_id'] : get_current_user_id();

		// Validate user.
		if ( ! $user_id ) {
			return new WP_Error(
				'invalid_user',
				'No valid user provided.',
				array( 'status' => 400 )
			);
		}

		// Check permissions for creating submissions for other users.
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				'You cannot create submissions for other users.',
				array( 'status' => 403 )
			);
		}

		// Verify that the task exists and is a writing-task post type.
		$task_post = get_post( $task_id );
		if ( ! $task_post || 'writing-task' !== $task_post->post_type ) {
			return new WP_Error(
				'invalid_task',
				'Invalid task ID or task not found.',
				array( 'status' => 404 )
			);
		}

		// Check if task is published.
		if ( 'publish' !== $task_post->post_status ) {
			return new WP_Error(
				'task_not_available',
				'Task is not available for submission.',
				array( 'status' => 400 )
			);
		}

		// Handle essay: use provided essay_id or create new essay.
		if ( ! empty( $request['essay_id'] ) ) {
			$essay_id = (int) $request['essay_id'];

			// Validate that the essay exists.
			$essay_db = new Ieltssci_Essay_DB();
			$essays   = $essay_db->get_essays( array( 'id' => $essay_id ) );

			if ( is_wp_error( $essays ) || empty( $essays ) ) {
				return new WP_Error(
					'invalid_essay',
					'Invalid essay ID or essay not found.',
					array( 'status' => 404 )
				);
			}

			$existing_essay = $essays[0];

			// Check if the essay belongs to the user or if user has permission to use it.
			if ( (int) $existing_essay['created_by'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'rest_forbidden',
					'You cannot use this essay for the submission.',
					array( 'status' => 403 )
				);
			}
		} else {
			// Get task data from ACF fields.
			$writing_question = get_field( 'writing_question', $task_id );
			$chart            = get_field( 'chart', $task_id );

			if ( empty( $writing_question ) ) {
				return new WP_Error(
					'task_incomplete',
					'Task does not have a writing question.',
					array( 'status' => 400 )
				);
			}

			// Get the task type from the writing-task-type taxonomy.
			$task_types = wp_get_post_terms( $task_id, 'writing-task-type', array( 'fields' => 'slugs' ) );
			$essay_type = 'task-1'; // Default fallback.

			if ( ! is_wp_error( $task_types ) && ! empty( $task_types ) ) {
				// Use the first task type slug as the essay type.
				$essay_type = $task_types[0];
			}

			// Create essay data from task information.
			$essay_data = array(
				'essay_type'    => $essay_type,
				'question'      => $writing_question,
				'essay_content' => '', // Empty content for new submission.
				'created_by'    => $user_id,
			);

			// Add chart image IDs if chart exists.
			if ( ! empty( $chart ) && is_array( $chart ) && ! empty( $chart['ID'] ) ) {
				$essay_data['chart_image_ids'] = array( (int) $chart['ID'] );
			}

			// Create the essay using the Essay DB handler.
			$essay_db = new Ieltssci_Essay_DB();
			$essay    = $essay_db->create_essay( $essay_data );

			if ( is_wp_error( $essay ) ) {
				return new WP_Error(
					'essay_creation_failed',
					'Failed to create essay: ' . $essay->get_error_message(),
					array( 'status' => 500 )
				);
			}

			$essay_id = $essay['id'];
		}

		// Prepare task submission data.
		$submission_data = array(
			'user_id'  => $user_id,
			'task_id'  => $task_id,
			'essay_id' => $essay_id,
			'status'   => ! empty( $request['status'] ) ? sanitize_text_field( $request['status'] ) : 'in-progress',
		);

		// Add optional fields if provided.
		if ( ! empty( $request['test_submission_id'] ) ) {
			$submission_data['test_submission_id'] = (int) $request['test_submission_id'];
		}

		if ( ! empty( $request['uuid'] ) ) {
			$submission_data['uuid'] = sanitize_text_field( $request['uuid'] );
		}

		// Create the task submission.
		$submission_id = $this->db->add_task_submission( $submission_data );

		if ( is_wp_error( $submission_id ) ) {
			return $submission_id;
		}

		// Add meta data if provided.
		if ( ! empty( $request['meta'] ) && is_array( $request['meta'] ) ) {
			foreach ( $request['meta'] as $meta_key => $meta_value ) {
				// Ensure consistent meta value format - always store as string.
				$sanitized_value = is_string( $meta_value ) ? $meta_value : (string) $meta_value;
				$meta_result     = $this->db->add_task_submission_meta( $submission_id, $meta_key, $sanitized_value );
				if ( is_wp_error( $meta_result ) ) {
					error_log( 'Failed to add meta data: ' . $meta_result->get_error_message() );
				}
			}
		}

		// Retrieve the created submission with meta data.
		$created_submission = $this->db->get_task_submission( $submission_id );
		if ( is_wp_error( $created_submission ) ) {
			return $created_submission;
		}

		// Include meta data with consistent formatting.
		$created_submission['meta'] = $this->db->get_task_submission_meta( $submission_id );

		// Prepare the response.
		$response = $this->prepare_task_submission_for_response( $created_submission, $request );

		// Set 201 Created status.
		$response->set_status( 201 );

		/**
		 * Fires after a task submission is created via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $created_submission The created submission data.
		 * @param WP_REST_Request $request           Request used to create the submission.
		 */
		do_action( 'ieltssci_rest_create_task_submission', $created_submission, $request );

		return $response;
	}

	/**
	 * Update an existing task submission.
	 *
	 * Updates an existing task submission with the provided data and meta information.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function update_task_submission( WP_REST_Request $request ) {
		$identifier = $request['id'];

		// Determine if identifier is numeric ID or UUID and get submission.
		if ( is_numeric( $identifier ) ) {
			$existing_submission = $this->db->get_task_submission( (int) $identifier );
		} else {
			// It's a UUID, query by UUID.
			$submissions         = $this->db->get_task_submissions( array( 'uuid' => $identifier ) );
			$existing_submission = ! empty( $submissions ) ? $submissions[0] : null;
		}

		if ( is_wp_error( $existing_submission ) ) {
			return $existing_submission;
		}

		if ( ! $existing_submission ) {
			return new WP_Error(
				'ieltssci_task_submission_not_found',
				'Task submission not found.',
				array( 'status' => 404 )
			);
		}

		// Get the numeric submission ID for database operations.
		$submission_id = (int) $existing_submission['id'];

		// Check if current user can access this submission.
		if ( ! $this->can_access_submission( $existing_submission, $request ) ) {
			return new WP_Error(
				'rest_forbidden',
				'You cannot update this task submission.',
				array( 'status' => 403 )
			);
		}

		// Prepare update data - only include fields that are provided.
		$update_data = array();

		// Handle basic field updates.
		if ( isset( $request['test_submission_id'] ) ) {
			$update_data['test_submission_id'] = (int) $request['test_submission_id'];
		}

		if ( isset( $request['user_id'] ) ) {
			// Only administrators can change the user_id.
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'rest_forbidden',
					'You cannot change the user for this submission.',
					array( 'status' => 403 )
				);
			}
			$update_data['user_id'] = (int) $request['user_id'];
		}

		if ( isset( $request['task_id'] ) ) {
			// Verify the new task exists and is published.
			$task_post = get_post( (int) $request['task_id'] );
			if ( ! $task_post || 'writing-task' !== $task_post->post_type || 'publish' !== $task_post->post_status ) {
				return new WP_Error(
					'invalid_task',
					'Invalid task ID or task not available.',
					array( 'status' => 400 )
				);
			}
			$update_data['task_id'] = (int) $request['task_id'];
		}

		if ( isset( $request['essay_id'] ) ) {
			$update_data['essay_id'] = (int) $request['essay_id'];
		}

		if ( isset( $request['status'] ) ) {
			$update_data['status'] = sanitize_text_field( $request['status'] );
		}

		if ( isset( $request['completed_at'] ) ) {
			// Convert from ISO 8601 to MySQL format if needed.
			$completed_at = $request['completed_at'];
			if ( ! empty( $completed_at ) ) {
				// Convert ISO 8601 to GMT MySQL format.
				$datetime = rest_parse_date( $completed_at );
				if ( false === $datetime ) {
					return new WP_Error(
						'invalid_date',
						'Invalid completed_at date format.',
						array( 'status' => 400 )
					);
				}
				$update_data['completed_at'] = gmdate( 'Y-m-d H:i:s', $datetime );
			} else {
				$update_data['completed_at'] = null;
			}
		}

		// Update the submission if there are changes.
		if ( ! empty( $update_data ) ) {
			$update_result = $this->db->update_task_submission( $submission_id, $update_data );
			if ( is_wp_error( $update_result ) ) {
				return $update_result;
			}
		}

		// Handle meta data updates.
		if ( isset( $request['meta'] ) && is_array( $request['meta'] ) ) {
			foreach ( $request['meta'] as $meta_key => $meta_value ) {
				// Ensure consistent meta value format - always store as string.
				$sanitized_value = is_string( $meta_value ) ? $meta_value : (string) $meta_value;

				// Check if this meta key already exists.
				$existing_meta = $this->db->get_task_submission_meta( $submission_id, $meta_key, true );

				if ( ! empty( $existing_meta ) ) {
					// Update existing meta.
					$meta_result = $this->db->update_task_submission_meta( $submission_id, $meta_key, $sanitized_value );
				} else {
					// Add new meta.
					$meta_result = $this->db->add_task_submission_meta( $submission_id, $meta_key, $sanitized_value );
				}

				if ( is_wp_error( $meta_result ) ) {
					error_log( 'Failed to update meta data: ' . $meta_result->get_error_message() );
				}
			}
		}

		// Retrieve the updated submission with meta data.
		$updated_submission = $this->db->get_task_submission( $submission_id );
		if ( is_wp_error( $updated_submission ) ) {
			return $updated_submission;
		}

		// Include meta data with consistent formatting.
		$updated_submission['meta'] = $this->db->get_task_submission_meta( $submission_id );

		// Prepare the response.
		$response = $this->prepare_task_submission_for_response( $updated_submission, $request );

		/**
		 * Fires after a task submission is updated via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $updated_submission The updated submission data.
		 * @param array           $existing_submission The original submission data before update.
		 * @param WP_REST_Request $request            Request used to update the submission.
		 */
		do_action( 'ieltssci_rest_update_task_submission', $updated_submission, $existing_submission, $request );

		return $response;
	}

	/**
	 * Delete a task submission.
	 *
	 * Deletes a task submission and its associated meta data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function delete_task_submission( WP_REST_Request $request ) {
		$identifier = $request['id'];

		// Determine if identifier is numeric ID or UUID and get submission.
		if ( is_numeric( $identifier ) ) {
			$existing_submission = $this->db->get_task_submission( (int) $identifier );
			$submission_id       = (int) $identifier;
		} else {
			// It's a UUID, query by UUID.
			$submissions         = $this->db->get_task_submissions( array( 'uuid' => $identifier ) );
			$existing_submission = ! empty( $submissions ) ? $submissions[0] : null;
			$submission_id       = $existing_submission ? (int) $existing_submission['id'] : 0;
		}

		if ( is_wp_error( $existing_submission ) ) {
			return $existing_submission;
		}

		if ( ! $existing_submission ) {
			return new WP_Error(
				'ieltssci_task_submission_not_found',
				'Task submission not found.',
				array( 'status' => 404 )
			);
		}

		// Check if current user can access this submission.
		if ( ! $this->can_access_submission( $existing_submission, $request ) ) {
			return new WP_Error(
				'rest_forbidden',
				'You cannot delete this task submission.',
				array( 'status' => 403 )
			);
		}

		// Additional permission check - only allow deletion if user is admin or submission is in-progress.
		if ( ! current_user_can( 'manage_options' ) && 'in-progress' !== $existing_submission['status'] ) {
			return new WP_Error(
				'rest_forbidden',
				'You cannot delete a submission that has been completed or graded.',
				array( 'status' => 403 )
			);
		}

		// Include meta data for the response.
		$existing_submission['meta'] = $this->db->get_task_submission_meta( $submission_id );

		// Attempt to delete the task submission (this will also delete meta data).
		$delete_result = $this->db->delete_task_submission( $submission_id );

		if ( is_wp_error( $delete_result ) ) {
			return $delete_result;
		}

		// Prepare the response with the deleted submission data.
		$response_data = array(
			'deleted'  => true,
			'previous' => $this->prepare_task_submission_for_response( $existing_submission, $request )->get_data(),
		);

		$response = rest_ensure_response( $response_data );

		/**
		 * Fires after a task submission is deleted via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $existing_submission The deleted submission data (including meta).
		 * @param WP_REST_Request $request            Request used to delete the submission.
		 */
		do_action( 'ieltssci_rest_delete_task_submission', $existing_submission, $request );

		return $response;
	}

	/**
	 * Prepare a task submission for REST API response.
	 *
	 * @since 1.0.0
	 *
	 * @param array           $submission Raw task submission data from database.
	 * @param WP_REST_Request $request    Current request object.
	 * @return WP_REST_Response Response object with task submission data.
	 */
	public function prepare_task_submission_for_response( $submission, $request ) {
		// Prepare basic fields.
		$data = array(
			'id'           => (int) $submission['id'],
			'uuid'         => $submission['uuid'],
			'user_id'      => (int) $submission['user_id'],
			'task_id'      => (int) $submission['task_id'],
			'essay_id'     => (int) $submission['essay_id'],
			'status'       => $submission['status'],
			'started_at'   => ! empty( $submission['started_at'] ) ? mysql_to_rfc3339( $submission['started_at'] ) : null,
			'completed_at' => ! empty( $submission['completed_at'] ) ? mysql_to_rfc3339( $submission['completed_at'] ) : null,
			'updated_at'   => ! empty( $submission['updated_at'] ) ? mysql_to_rfc3339( $submission['updated_at'] ) : null,
		);

		// Add optional test_submission_id field.
		if ( ! empty( $submission['test_submission_id'] ) ) {
			$data['test_submission_id'] = (int) $submission['test_submission_id'];
		}

		// Include meta data if available.
		if ( ! empty( $submission['meta'] ) && is_array( $submission['meta'] ) ) {
			$data['meta'] = $submission['meta'];
		} else {
			$data['meta'] = array();
		}

		// Add context-based field filtering.
		$data = $this->filter_response_by_context( $data, 'view' );

		// Create response object.
		$response = rest_ensure_response( $data );

		// Add embeddable link to the author (user).
		$response->add_link(
			'author',
			rest_url( 'wp/v2/users/' . $submission['user_id'] ),
			array(
				'embeddable' => true,
			)
		);

		// Add embeddable link to the writing task.
		$response->add_link(
			'writing-task',
			rest_url( 'wp/v2/writing-task/' . $submission['task_id'] ),
			array(
				'embeddable' => true,
			)
		);

		// Add embeddable link to the essay.
		$response->add_link(
			'essay',
			rest_url( $this->namespace . '/writing/essays' ) . '?id=' . $submission['essay_id'],
			array(
				'embeddable' => true,
			)
		);

		// Add embeddable link to the parent test submission if available.
		if ( ! empty( $submission['test_submission_id'] ) ) {
			$response->add_link(
				'test-submission',
				rest_url( $this->namespace . '/writing-test-submissions/' . $submission['test_submission_id'] ),
				array(
					'embeddable' => true,
				)
			);
		}

		/**
		 * Filter task submission data returned from the REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_REST_Response $response  The response object.
		 * @param array            $submission Raw task submission data.
		 * @param WP_REST_Request  $request   Request used to generate the response.
		 */
		return apply_filters( 'ieltssci_rest_prepare_task_submission', $response, $submission, $request );
	}

	/**
	 * Get the task submission schema for REST API responses.
	 *
	 * @since 1.0.0
	 *
	 * @return array The task submission schema.
	 */
	public function get_task_submission_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'writing_task_submission',
			'type'       => 'object',
			'properties' => array(
				'id'                 => array(
					'type'        => 'integer',
					'readonly'    => true,
					'description' => 'Unique identifier for the task submission.',
				),
				'uuid'               => array(
					'type'        => 'string',
					'format'      => 'uuid',
					'description' => 'UUID for the task submission.',
				),
				'user_id'            => array(
					'type'        => 'integer',
					'description' => 'ID of the user submitting.',
					'minimum'     => 1,
				),
				'task_id'            => array(
					'type'        => 'integer',
					'description' => 'ID of the task (from wp_posts).',
					'minimum'     => 1,
				),
				'essay_id'           => array(
					'type'        => 'integer',
					'description' => 'ID of the associated essay.',
					'minimum'     => 1,
				),
				'test_submission_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the parent test submission.',
					'minimum'     => 1,
				),
				'status'             => array(
					'type'        => 'string',
					'description' => 'Submission status.',
					'enum'        => array( 'in-progress', 'completed', 'not_graded', 'graded' ),
				),
				'started_at'         => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => 'Start timestamp in ISO 8601 format. (Site timezone)',
				),
				'completed_at'       => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => 'Completion timestamp in ISO 8601 format. (Site timezone)',
				),
				'updated_at'         => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => 'Last update timestamp in ISO 8601 format. (Site timezone)',
				),
				'meta'               => array(
					'type'                 => 'object',
					'description'          => 'Meta data for the task submission.',
					'additionalProperties' => true,
				),
			),
		);
	}

	/**
	 * Get collection parameters for task submissions endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return array Query parameters for task submissions listing.
	 */
	public function get_task_submissions_params() {
		return array(
			'user_id'            => array(
				'description' => 'Limit result set to submissions for specific user ID.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'task_id'            => array(
				'description' => 'Limit result set to submissions for specific task ID.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'test_submission_id' => array(
				'description' => 'Limit result set to submissions for specific test submission ID.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'essay_id'           => array(
				'description' => 'Limit result set to submissions for specific essay ID.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'status'             => array(
				'description' => 'Limit result set to submissions with specific status.',
				'type'        => 'string',
				'enum'        => array( 'in-progress', 'completed', 'not_graded', 'graded' ),
			),
			'orderby'            => array(
				'description' => 'Sort collection by object attribute.',
				'type'        => 'string',
				'default'     => 'id',
				'enum'        => array( 'id', 'started_at', 'completed_at', 'status' ),
			),
			'order'              => array(
				'description' => 'Order sort attribute ascending or descending.',
				'type'        => 'string',
				'default'     => 'desc',
				'enum'        => array( 'asc', 'desc' ),
			),
			'per_page'           => array(
				'description' => 'Maximum number of items to be returned in result set.',
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 100,
			),
			'offset'             => array(
				'description' => 'Offset the result set by a specific number of items.',
				'type'        => 'integer',
				'minimum'     => 0,
			),
			'include_meta'       => array(
				'description' => 'Include meta fields in response. Pass specific meta keys as array, true for all meta, or false for none.',
				'type'        => array( 'boolean', 'array' ),
				'default'     => false,
				'items'       => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Get create parameters for task submissions endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return array Parameters for creating a task submission.
	 */
	public function get_task_submission_create_params() {
		return array(
			'user_id'            => array(
				'description' => 'ID of the user submitting. Defaults to current user.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'task_id'            => array(
				'description' => 'ID of the task (from wp_posts with post_type writing-task).',
				'type'        => 'integer',
				'required'    => true,
				'minimum'     => 1,
			),
			'test_submission_id' => array(
				'description' => 'ID of the parent test submission (optional).',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'essay_id'           => array(
				'description' => 'ID of the associated essay (optional). If not provided, a new essay will be created.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'uuid'               => array(
				'description' => 'Custom UUID for the submission (optional).',
				'type'        => 'string',
				'format'      => 'uuid',
			),
			'status'             => array(
				'description' => 'Submission status.',
				'type'        => 'string',
				'default'     => 'in-progress',
				'enum'        => array( 'in-progress', 'completed', 'not_graded', 'graded' ),
			),
			'meta'               => array(
				'description'          => 'Meta data for the task submission. All values should be provided as strings.',
				'type'                 => 'object',
				'additionalProperties' => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Get update parameters for task submissions endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return array Parameters for updating a task submission.
	 */
	public function get_task_submission_update_params() {
		return array(
			'id'                 => array(
				'description'       => 'Unique identifier (ID or UUID) for the task submission.',
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => function ( $param ) {
					// Accept either numeric ID or valid UUID format.
					return is_numeric( $param ) || wp_is_uuid( $param );
				},
			),
			'test_submission_id' => array(
				'description' => 'ID of the parent test submission.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'user_id'            => array(
				'description' => 'ID of the user submitting.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'task_id'            => array(
				'description' => 'ID of the task (from wp_posts).',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'essay_id'           => array(
				'description' => 'ID of the associated essay.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'status'             => array(
				'description' => 'Submission status.',
				'type'        => 'string',
				'enum'        => array( 'in-progress', 'completed', 'not_graded', 'graded' ),
			),
			'completed_at'       => array(
				'description' => 'Completion timestamp in ISO 8601 format (GMT timezone).',
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'meta'               => array(
				'description'          => 'Meta data for the task submission. All values should be provided as strings.',
				'type'                 => 'object',
				'additionalProperties' => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Check if user has permission to fork a task submission.
	 *
	 * Only checks that the user is logged in. The ownership check happens in the callback.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function fork_task_submission_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Get arguments for the fork task submission endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_fork_task_submission_args() {
		return array(
			'id'                    => array(
				'required'          => true,
				'description'       => 'The ID of the task submission to fork.',
				'type'              => 'integer',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
				'sanitize_callback' => 'absint',
			),
			'fork_essay'            => array(
				'required'          => false,
				'description'       => 'Whether to fork the associated essay.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'copy_segments'         => array(
				'required'          => false,
				'description'       => 'Whether to copy segments from the original essay.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'copy_segment_feedback' => array(
				'required'          => false,
				'description'       => 'Whether to copy segment feedback from the original essay.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'copy_essay_feedback'   => array(
				'required'          => false,
				'description'       => 'Whether to copy essay feedback from the original essay.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'copy_meta'             => array(
				'required'          => false,
				'description'       => 'Whether to copy task submission meta data.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'keep_status'           => array(
				'required'          => false,
				'description'       => 'Whether to keep the original status or reset to in-progress.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'test_submission_id'    => array(
				'required'          => false,
				'description'       => 'Override the parent test submission ID.',
				'type'              => 'integer',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Get the JSON schema for the fork task submission endpoint.
	 *
	 * @return array The schema for the fork task submission response.
	 */
	public function get_fork_task_submission_schema() {
		// Get the task submission item schema properties for reuse.
		$task_submission_properties = $this->get_task_submission_schema();
		$task_submission_properties = isset( $task_submission_properties['properties'] ) ? $task_submission_properties['properties'] : array();

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'fork_task_submission',
			'type'       => 'object',
			'properties' => array(
				'task_submission'  => array(
					'description' => 'The newly forked task submission object.',
					'type'        => 'object',
					'properties'  => $task_submission_properties,
				),
				'forked_essay'     => array(
					'description' => 'Details of the forked essay if essay was forked.',
					'type'        => 'object',
					'properties'  => array(
						'essay'            => array(
							'type' => 'object',
						),
						'copied_segments'  => array(
							'type' => 'object',
						),
						'segment_feedback' => array(
							'type' => 'array',
						),
						'essay_feedback'   => array(
							'type' => 'array',
						),
					),
				),
				'copied_meta_keys' => array(
					'description' => 'Array of meta keys that were copied.',
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
				),
			),
			'required'   => array( 'task_submission' ),
		);
	}

	/**
	 * Fork task submission endpoint.
	 *
	 * Creates a copy of an existing task submission including its essay and meta data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function fork_task_submission( WP_REST_Request $request ) {
		$task_submission_id = $request->get_param( 'id' );

		// Get options from request body.
		$options = array(
			'fork_essay'            => $request->get_param( 'fork_essay' ) !== null ? $request->get_param( 'fork_essay' ) : true,
			'copy_segments'         => $request->get_param( 'copy_segments' ) !== null ? $request->get_param( 'copy_segments' ) : true,
			'copy_segment_feedback' => $request->get_param( 'copy_segment_feedback' ) !== null ? $request->get_param( 'copy_segment_feedback' ) : true,
			'copy_essay_feedback'   => $request->get_param( 'copy_essay_feedback' ) !== null ? $request->get_param( 'copy_essay_feedback' ) : true,
			'copy_meta'             => $request->get_param( 'copy_meta' ) !== null ? $request->get_param( 'copy_meta' ) : true,
			'keep_status'           => $request->get_param( 'keep_status' ) !== null ? $request->get_param( 'keep_status' ) : true,
			'test_submission_id'    => $request->get_param( 'test_submission_id' ),
		);

		// Get the task submission to check if it exists.
		$task_submission = $this->db->get_task_submission( $task_submission_id );

		if ( is_wp_error( $task_submission ) ) {
			return $task_submission; // Return the WP_Error directly from the DB layer.
		}

		if ( ! $task_submission ) {
			return new WP_Error(
				'task_submission_not_found',
				__( 'Task submission not found.', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		// Check if user can access this submission.
		if ( ! $this->can_access_submission( $task_submission, $request ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to fork this task submission.', 'ielts-science-lms' ),
				array( 'status' => 403 )
			);
		}

		// Call the fork method in the database service.
		$result = $this->db->fork_task_submission( $task_submission_id, get_current_user_id(), $options );

		if ( is_wp_error( $result ) ) {
			return $result; // Return the WP_Error directly from the DB layer.
		}

		// Prepare the response data with detailed fork information.
		$response_data = array(
			'task_submission'  => $this->prepare_task_submission_for_response( $result['task_submission'], $request )->data,
			'forked_essay'     => $result['forked_essay'],
			'copied_meta_keys' => $result['copied_meta_keys'],
		);

		// Create response with 201 Created status.
		$response = rest_ensure_response( $response_data );
		$response->set_status( 201 );

		// Add link to the original task submission.
		$response->add_link(
			'original-task-submission',
			rest_url( $this->namespace . '/' . $this->resource_task . '/' . $task_submission_id ),
			array(
				'embeddable' => true,
			)
		);

		/**
		 * Fires after a task submission is forked via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $result  The fork result data.
		 * @param WP_REST_Request $request Request used to fork the task submission.
		 */
		do_action( 'ieltssci_rest_fork_task_submission', $result, $request );

		return $response;
	}
}
