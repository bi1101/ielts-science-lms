<?php
/**
 * REST API Controller for IELTS Science Test Submissions
 *
 * Handles CRUD operations for test submissions, including meta fields.
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
 * Class Ieltssci_Writing_Test_Submission_Controller
 *
 * REST API controller for writing test submissions.
 *
 * @since 1.0.0
 */
class Ieltssci_Writing_Test_Submission_Controller extends WP_REST_Controller {
	/**
	 * Namespace for REST routes.
	 *
	 * @var string
	 */
	protected $namespace = 'ieltssci/v1';

	/**
	 * Resource name for test submissions.
	 *
	 * @var string
	 */
	protected $resource_test = 'writing-test-submissions';

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
	 * Registers all routes for test submissions including collection and single item endpoints.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Test submissions.
		register_rest_route(
			$this->namespace,
			'/' . $this->resource_test,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_test_submissions' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => $this->get_test_submissions_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_test_submission' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => $this->get_test_submission_create_params(),
				),
				'schema' => array( $this, 'get_test_submission_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->resource_test . '/(?P<id>[0-9a-f-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_test_submission' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'id' => array(
							'description'       => 'Unique identifier (ID or UUID) for the test submission.',
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
					'callback'            => array( $this, 'update_test_submission' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => $this->get_test_submission_update_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_test_submission' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'id' => array(
							'description'       => 'Unique identifier (ID or UUID) for the test submission.',
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => function ( $param ) {
								// Accept either numeric ID or valid UUID format.
								return is_numeric( $param ) || wp_is_uuid( $param );
							},
						),
					),
				),
				'schema' => array( $this, 'get_test_submission_schema' ),
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
	 * Get test submissions collection.
	 *
	 * Retrieves a collection of test submissions based on provided parameters.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_test_submissions( WP_REST_Request $request ) {
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
		if ( ! empty( $request['test_id'] ) ) {
			$args['test_id'] = (int) $request['test_id'];
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
		$submissions = $this->db->get_test_submissions( $args );

		if ( is_wp_error( $submissions ) ) {
			return $submissions;
		}

		// Always fetch task submissions for linking (efficient batch query).
		if ( ! empty( $submissions ) ) {
			// Extract all submission IDs for batch query.
			$submission_ids = array_column( $submissions, 'id' );

			// Get all task submissions for these test submissions in one query.
			$task_submissions = $this->db->get_task_submissions(
				array(
					'test_submission_id' => $submission_ids,
					'number'             => 999, // Large number to get all related task submissions.
				)
			);

			if ( ! is_wp_error( $task_submissions ) ) {
				// Group task submissions by test submission ID.
				$task_submissions_by_test = array();
				foreach ( $task_submissions as $task_submission ) {
					$test_submission_id = $task_submission['test_submission_id'];
					if ( ! isset( $task_submissions_by_test[ $test_submission_id ] ) ) {
						$task_submissions_by_test[ $test_submission_id ] = array();
					}
					$task_submissions_by_test[ $test_submission_id ][] = array(
						'task_submission_id' => (int) $task_submission['id'],
						'task_id'            => (int) $task_submission['task_id'],
						'essay_id'           => (int) $task_submission['essay_id'],
					);
				}

				// Add task submissions to each test submission for link generation.
				foreach ( $submissions as &$submission ) {
					$submission_id                  = $submission['id'];
					$submission['task_submissions'] = $task_submissions_by_test[ $submission_id ] ?? array();
				}
			}
		}

		// Prepare response data - all submissions should already be accessible based on query filtering.
		$data = array();
		foreach ( $submissions as $submission ) {
			$response = $this->prepare_test_submission_for_response( $submission, $request );
			$data[]   = $this->prepare_response_for_collection( $response );
		}

		// Get total count for pagination headers.
		$count_args          = $args;
		$count_args['count'] = true;
		unset( $count_args['number'], $count_args['offset'] );
		$total = $this->db->get_test_submissions( $count_args );

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
	 * Get a single test submission by ID or UUID.
	 *
	 * Retrieves a specific test submission with its associated meta data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_test_submission( WP_REST_Request $request ) {
		$identifier = $request['id'];

		// Determine if identifier is numeric ID or UUID.
		if ( is_numeric( $identifier ) ) {
			$submission = $this->db->get_test_submission( (int) $identifier );
		} else {
			// It's a UUID, query by UUID.
			$submissions = $this->db->get_test_submissions( array( 'uuid' => $identifier ) );
			$submission  = ! empty( $submissions ) ? $submissions[0] : null;
		}

		if ( is_wp_error( $submission ) ) {
			return $submission;
		}

		if ( ! $submission ) {
			return new WP_Error(
				'ieltssci_test_submission_not_found',
				'Test submission not found.',
				array( 'status' => 404 )
			);
		}

		// Check if current user can access this submission.
		if ( ! $this->can_access_submission( $submission, $request ) ) {
			return new WP_Error(
				'rest_forbidden',
				'You cannot view this test submission.',
				array( 'status' => 403 )
			);
		}

		// Include meta data by default for single item requests.
		if ( ! isset( $submission['meta'] ) ) {
			$submission['meta'] = $this->db->get_test_submission_meta( $submission['id'] );
		}

		// Always fetch task submissions for link generation.
		if ( ! isset( $submission['task_submissions'] ) ) {
			$task_submissions = $this->db->get_task_submissions( array( 'test_submission_id' => $submission['id'] ) );
			if ( ! is_wp_error( $task_submissions ) && ! empty( $task_submissions ) ) {
				$submission['task_submissions'] = array_map(
					function ( $task_submission ) {
						return array(
							'task_submission_id' => (int) $task_submission['id'],
							'task_id'            => (int) $task_submission['task_id'],
							'essay_id'           => (int) $task_submission['essay_id'],
						);
					},
					$task_submissions
				);
			} else {
				$submission['task_submissions'] = array();
			}
		}

		// Prepare the response.
		$response = $this->prepare_test_submission_for_response( $submission, $request );

		return $response;
	}

	/**
	 * Create a new test submission.
	 *
	 * Creates a new test submission with the provided data and meta information.
	 *
	 * When creating a test submission, this method will:
	 * 1. Validate the test exists and is published.
	 * 2. Create the main test submission record.
	 * 3. Retrieve all writing tasks associated with the test (via ACF 'writing_tasks' field).
	 * 4. For each writing task:
	 *    - Create an essay with the task's question and chart data.
	 *    - Create a task submission linking the test submission to the task and essay.
	 * 5. Return the test submission with information about created task submissions.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function create_test_submission( WP_REST_Request $request ) {
		$test_id = (int) $request['test_id'];
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

		// Verify that the test exists and is a writing-test post type.
		$test_post = get_post( $test_id );
		if ( ! $test_post || 'writing-test' !== $test_post->post_type ) {
			return new WP_Error(
				'invalid_test',
				'Invalid test ID or test not found.',
				array( 'status' => 404 )
			);
		}

		// Check if test is published.
		if ( 'publish' !== $test_post->post_status ) {
			return new WP_Error(
				'test_not_available',
				'Test is not available for submission.',
				array( 'status' => 400 )
			);
		}

		// Prepare test submission data.
		$submission_data = array(
			'test_id' => $test_id,
			'user_id' => $user_id,
			'status'  => ! empty( $request['status'] ) ? sanitize_text_field( $request['status'] ) : 'in-progress',
		);

		// Add optional fields if provided.
		if ( ! empty( $request['uuid'] ) ) {
			$submission_data['uuid'] = sanitize_text_field( $request['uuid'] );
		}

		// Create the test submission.
		$submission_id = $this->db->add_test_submission( $submission_data );

		if ( is_wp_error( $submission_id ) ) {
			return $submission_id;
		}

		// Get associated writing tasks from the test.
		$writing_tasks    = get_field( 'writing_tasks', $test_id );
		$task_submissions = array();

		if ( ! empty( $writing_tasks ) && is_array( $writing_tasks ) ) {
			// Create task submissions for each associated writing task.
			foreach ( $writing_tasks as $task_post ) {
				if ( ! is_object( $task_post ) || empty( $task_post->ID ) ) {
					continue;
				}

				$task_id = $task_post->ID;

				// Get task data from ACF fields.
				$writing_question = get_field( 'writing_question', $task_id );
				$chart            = get_field( 'chart', $task_id );

				if ( empty( $writing_question ) ) {
					error_log( "Skipping task ID {$task_id}: No writing question found." );
					continue;
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
					error_log( "Failed to create essay for task ID {$task_id}: " . $essay->get_error_message() );
					continue;
				}

				// Prepare task submission data.
				$task_submission_data = array(
					'test_submission_id' => $submission_id,
					'user_id'            => $user_id,
					'task_id'            => $task_id,
					'essay_id'           => (int) $essay['id'],
					'status'             => 'in-progress',
				);

				// Create the task submission.
				$task_submission_id = $this->db->add_task_submission( $task_submission_data );

				if ( is_wp_error( $task_submission_id ) ) {
					error_log( "Failed to create task submission for task ID {$task_id}: " . $task_submission_id->get_error_message() );
					continue;
				}

				$task_submissions[] = array(
					'task_submission_id' => $task_submission_id,
					'task_id'            => $task_id,
					'essay_id'           => (int) $essay['id'],
				);
			}
		}

		// Add meta data if provided.
		if ( ! empty( $request['meta'] ) && is_array( $request['meta'] ) ) {
			foreach ( $request['meta'] as $meta_key => $meta_value ) {
				$meta_result = $this->db->add_test_submission_meta( $submission_id, $meta_key, $meta_value );
				if ( is_wp_error( $meta_result ) ) {
					error_log( 'Failed to add meta data: ' . $meta_result->get_error_message() );
				}
			}
		}

		// Retrieve the created submission with meta data.
		$created_submission = $this->db->get_test_submission( $submission_id );
		if ( is_wp_error( $created_submission ) ) {
			return $created_submission;
		}

		// Include meta data with consistent formatting.
		$created_submission['meta'] = $this->db->get_test_submission_meta( $submission_id );

		// Add task submissions to the submission data before preparation.
		if ( ! empty( $task_submissions ) ) {
			$created_submission['task_submissions'] = $task_submissions;
		}

		// Prepare the response.
		$response = $this->prepare_test_submission_for_response( $created_submission, $request );

		// Set 201 Created status.
		$response->set_status( 201 );

		/**
		 * Fires after a test submission is created via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $created_submission The created submission data.
		 * @param WP_REST_Request $request           Request used to create the submission.
		 */
		do_action( 'ieltssci_rest_create_test_submission', $created_submission, $request );

		return $response;
	}

	/**
	 * Update an existing test submission.
	 *
	 * Updates an existing test submission with the provided data and meta information.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function update_test_submission( WP_REST_Request $request ) {
		$identifier = $request['id'];

		// Determine if identifier is numeric ID or UUID and get submission.
		if ( is_numeric( $identifier ) ) {
			$existing_submission = $this->db->get_test_submission( (int) $identifier );
		} else {
			// It's a UUID, query by UUID.
			$submissions         = $this->db->get_test_submissions( array( 'uuid' => $identifier ) );
			$existing_submission = ! empty( $submissions ) ? $submissions[0] : null;
		}

		if ( is_wp_error( $existing_submission ) ) {
			return $existing_submission;
		}

		if ( ! $existing_submission ) {
			return new WP_Error(
				'ieltssci_test_submission_not_found',
				'Test submission not found.',
				array( 'status' => 404 )
			);
		}

		// Get the numeric submission ID for database operations.
		$submission_id = (int) $existing_submission['id'];

		// Check if current user can access this submission.
		if ( ! $this->can_access_submission( $existing_submission, $request ) ) {
			return new WP_Error(
				'rest_forbidden',
				'You cannot update this test submission.',
				array( 'status' => 403 )
			);
		}

		// Prepare update data - only include fields that are provided.
		$update_data = array();

		// Handle basic field updates.
		if ( isset( $request['test_id'] ) ) {
			// Verify the new test exists and is published.
			$test_post = get_post( (int) $request['test_id'] );
			if ( ! $test_post || 'writing-test' !== $test_post->post_type || 'publish' !== $test_post->post_status ) {
				return new WP_Error(
					'invalid_test',
					'Invalid test ID or test not available.',
					array( 'status' => 400 )
				);
			}
			$update_data['test_id'] = (int) $request['test_id'];
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

		if ( isset( $request['status'] ) ) {
			$update_data['status'] = sanitize_text_field( $request['status'] );
		}

		if ( isset( $request['started_at'] ) ) {
			// Convert from ISO 8601 to MySQL format if needed.
			$started_at = $request['started_at'];
			if ( ! empty( $started_at ) ) {
				// Convert ISO 8601 to GMT MySQL format.
				$datetime = rest_parse_date( $started_at );
				if ( false === $datetime ) {
					return new WP_Error(
						'invalid_date',
						'Invalid started_at date format.',
						array( 'status' => 400 )
					);
				}
				$update_data['started_at'] = gmdate( 'Y-m-d H:i:s', $datetime );
			} else {
				$update_data['started_at'] = null;
			}
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
			$update_result = $this->db->update_test_submission( $submission_id, $update_data );
			if ( is_wp_error( $update_result ) ) {
				return $update_result;
			}
		}

		// Handle meta data updates.
		if ( isset( $request['meta'] ) && is_array( $request['meta'] ) ) {
			foreach ( $request['meta'] as $meta_key => $meta_value ) {
				$meta_result = $this->db->update_test_submission_meta( $submission_id, $meta_key, $meta_value );
				if ( is_wp_error( $meta_result ) ) {
					error_log( 'Failed to update meta data: ' . $meta_result->get_error_message() );
				}
			}
		}

		// Retrieve the updated submission with meta data.
		$updated_submission = $this->db->get_test_submission( $submission_id );
		if ( is_wp_error( $updated_submission ) ) {
			return $updated_submission;
		}

		// Include meta data with consistent formatting.
		$updated_submission['meta'] = $this->db->get_test_submission_meta( $submission_id );

		// Add task submissions to the submission data before preparation.
		$task_submissions = $this->db->get_task_submissions( array( 'test_submission_id' => $submission_id ) );
		if ( ! is_wp_error( $task_submissions ) && ! empty( $task_submissions ) ) {
			$updated_submission['task_submissions'] = array_map(
				function ( $task ) {
					return array(
						'task_submission_id' => (int) $task['id'], // Ensure integer.
						'task_id'            => (int) $task['task_id'], // Ensure integer.
						'essay_id'           => (int) $task['essay_id'], // Ensure integer.
					);
				},
				$task_submissions
			);
		} else {
			$updated_submission['task_submissions'] = array();
		}

		// Prepare the response.
		$response = $this->prepare_test_submission_for_response( $updated_submission, $request );

		/**
		 * Fires after a test submission is updated via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $updated_submission The updated submission data.
		 * @param WP_REST_Request $request           Request used to update the submission.
		 */
		do_action( 'ieltssci_rest_update_test_submission', $updated_submission, $request );

		return $response;
	}

	/**
	 * Delete a test submission.
	 *
	 * Deletes a test submission and its associated meta data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function delete_test_submission( WP_REST_Request $request ) {
		$identifier = $request['id'];

		// Determine if identifier is numeric ID or UUID and get submission.
		if ( is_numeric( $identifier ) ) {
			$existing_submission = $this->db->get_test_submission( (int) $identifier );
			$submission_id       = (int) $identifier;
		} else {
			// It's a UUID, query by UUID.
			$submissions         = $this->db->get_test_submissions( array( 'uuid' => $identifier ) );
			$existing_submission = ! empty( $submissions ) ? $submissions[0] : null;
			$submission_id       = $existing_submission ? (int) $existing_submission['id'] : 0;
		}

		if ( is_wp_error( $existing_submission ) ) {
			return $existing_submission;
		}

		if ( ! $existing_submission ) {
			return new WP_Error(
				'ieltssci_test_submission_not_found',
				'Test submission not found.',
				array( 'status' => 404 )
			);
		}

		// Check if current user can access this submission.
		if ( ! $this->can_access_submission( $existing_submission, $request ) ) {
			return new WP_Error(
				'rest_forbidden',
				'You cannot delete this test submission.',
				array( 'status' => 403 )
			);
		}

		// Delete the submission.
		$deleted = $this->db->delete_test_submission( $submission_id );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		/**
		 * Fires after a test submission is deleted via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $existing_submission The deleted submission data.
		 * @param WP_REST_Request $request            Request used to delete the submission.
		 */
		do_action( 'ieltssci_rest_delete_test_submission', $existing_submission, $request );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Prepare test submission for response.
	 *
	 * Formats the test submission data for API response and adds embeddable links to related task submissions.
	 *
	 * @since 1.0.0
	 *
	 * @param array           $submission The submission data.
	 * @param WP_REST_Request $request    The REST request object.
	 * @return WP_REST_Response The formatted response.
	 */
	protected function prepare_test_submission_for_response( $submission, $request ) {
		$data = array(
			'id'           => (int) $submission['id'],
			'test_id'      => (int) $submission['test_id'],
			'user_id'      => (int) $submission['user_id'],
			'uuid'         => $submission['uuid'],
			'status'       => $submission['status'],
			'started_at'   => ! empty( $submission['started_at'] ) ? mysql_to_rfc3339( $submission['started_at'] ) : null,
			'completed_at' => ! empty( $submission['completed_at'] ) ? mysql_to_rfc3339( $submission['completed_at'] ) : null,
			'updated_at'   => mysql_to_rfc3339( $submission['updated_at'] ),
		);

		// Include meta data if available.
		if ( isset( $submission['meta'] ) ) {
			$data['meta'] = $submission['meta'];
		}

		// Include task submissions if available.
		if ( isset( $submission['task_submissions'] ) ) {
			$data['task_submissions'] = $submission['task_submissions'];
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->filter_response_by_context( $data, $context );

		$response = rest_ensure_response( $data );

		// Add embeddable link to the author (user).
		$response->add_link(
			'author',
			rest_url( 'wp/v2/users/' . $submission['user_id'] ),
			array(
				'embeddable' => true,
			)
		);

		// Add embeddable link to the writing test.
		$response->add_link(
			'writing-test',
			rest_url( 'wp/v2/writing-test/' . $submission['test_id'] ),
			array(
				'embeddable' => true,
			)
		);

		// Add embeddable links to related task submissions.
		if ( isset( $submission['task_submissions'] ) && ! empty( $submission['task_submissions'] ) ) {
			foreach ( $submission['task_submissions'] as $task_submission ) {
				$task_submission_id = $task_submission['task_submission_id'];
				$task_id            = $task_submission['task_id'];
				$essay_id           = $task_submission['essay_id'];

				// Add embeddable link to each task submission.
				$response->add_link(
					'task-submission',
					rest_url( $this->namespace . '/writing-task-submissions/' . $task_submission_id ),
					array(
						'embeddable' => true,
					)
				);

				// Add embeddable link to each writing task.
				$response->add_link(
					'writing-task',
					rest_url( 'wp/v2/writing-task/' . $task_id ),
					array(
						'embeddable' => true,
					)
				);

				// Add embeddable link to each essay using the collection endpoint with ID parameter.
				$response->add_link(
					'essay',
					rest_url( $this->namespace . '/writing/essays' ) . '?id=' . $essay_id,
					array(
						'embeddable' => true,
					)
				);
			}
		}

		/**
		 * Filters the test submission data for a REST API response.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_REST_Response $response  The response object.
		 * @param array            $submission The original submission data.
		 * @param WP_REST_Request  $request   Request used to generate the response.
		 */
		return apply_filters( 'ieltssci_rest_prepare_test_submission', $response, $submission, $request );
	}

	/**
	 * Get the query parameters for test submissions collection.
	 *
	 * @since 1.0.0
	 *
	 * @return array Collection parameters.
	 */
	protected function get_test_submissions_params() {
		$params = array();

		$params['context'] = $this->get_context_param( array( 'default' => 'view' ) );

		$params['page'] = array(
			'description'       => 'Current page of the collection.',
			'type'              => 'integer',
			'default'           => 1,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
			'minimum'           => 1,
		);

		$params['per_page'] = array(
			'description'       => 'Maximum number of items to be returned in result set.',
			'type'              => 'integer',
			'default'           => 20,
			'minimum'           => 1,
			'maximum'           => 100,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['offset'] = array(
			'description'       => 'Offset the result set by a specific number of items.',
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['order'] = array(
			'description'       => 'Order sort attribute ascending or descending.',
			'type'              => 'string',
			'default'           => 'desc',
			'enum'              => array( 'asc', 'desc' ),
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['orderby'] = array(
			'description'       => 'Sort collection by object attribute.',
			'type'              => 'string',
			'default'           => 'id',
			'enum'              => array( 'id', 'test_id', 'user_id', 'status', 'started_at', 'completed_at', 'created_at', 'updated_at' ),
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['test_id'] = array(
			'description'       => 'Limit result set to submissions for a specific test.',
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['user_id'] = array(
			'description'       => 'Limit result set to submissions for a specific user.',
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['status'] = array(
			'description'       => 'Limit result set to submissions with a specific status.',
			'type'              => 'string',
			'enum'              => array( 'in-progress', 'completed', 'graded', 'cancelled' ),
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['include_meta'] = array(
			'description' => 'Include meta fields in response. Pass specific meta keys as array, true for all meta, or false for none.',
			'type'        => array( 'boolean', 'array' ),
			'default'     => false,
			'items'       => array(
				'type' => 'string',
			),
		);

		return $params;
	}

	/**
	 * Get the query parameters for creating test submissions.
	 *
	 * @since 1.0.0
	 *
	 * @return array Creation parameters.
	 */
	protected function get_test_submission_create_params() {
		$params = array();

		$params['test_id'] = array(
			'description'       => 'The ID of the writing test.',
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
			'minimum'           => 1,
		);

		$params['user_id'] = array(
			'description'       => 'The ID of the user submitting. Defaults to current user.',
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
			'minimum'           => 1,
		);

		$params['uuid'] = array(
			'description'       => 'Custom UUID for the submission.',
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['status'] = array(
			'description'       => 'Submission status.',
			'type'              => 'string',
			'default'           => 'in-progress',
			'enum'              => array( 'in-progress', 'completed', 'graded', 'cancelled' ),
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['meta'] = array(
			'description'          => 'Meta data for the submission.',
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => true,
		);

		return $params;
	}

	/**
	 * Get the query parameters for updating test submissions.
	 *
	 * @since 1.0.0
	 *
	 * @return array Update parameters.
	 */
	protected function get_test_submission_update_params() {
		$params = array();

		$params['id'] = array(
			'description'       => 'Unique identifier (ID or UUID) for the test submission.',
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => function ( $param ) {
				// Accept either numeric ID or valid UUID format.
				return is_numeric( $param ) || wp_is_uuid( $param );
			},
		);

		$params['test_id'] = array(
			'description'       => 'The ID of the writing test.',
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
			'minimum'           => 1,
		);

		$params['user_id'] = array(
			'description'       => 'The ID of the user submitting.',
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
			'minimum'           => 1,
		);

		$params['status'] = array(
			'description'       => 'Submission status.',
			'type'              => 'string',
			'enum'              => array( 'in-progress', 'completed', 'graded', 'cancelled' ),
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['started_at'] = array(
			'description' => 'The date the submission was started, in the site\'s timezone.',
			'type'        => array( 'string', 'null' ),
			'format'      => 'date-time',
		);

		$params['completed_at'] = array(
			'description' => 'The date the submission was completed, in the site\'s timezone.',
			'type'        => array( 'string', 'null' ),
			'format'      => 'date-time',
		);

		$params['meta'] = array(
			'description'          => 'Meta data for the submission.',
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => true,
		);

		return $params;
	}

	/**
	 * Get the test submission schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Schema data.
	 */
	public function get_test_submission_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'test_submission',
			'type'       => 'object',
			'properties' => array(
				'id'               => array(
					'description' => 'Unique identifier for the test submission.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'create' ),
					'readonly'    => true,
				),
				'test_id'          => array(
					'description' => 'The ID of the writing test.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'create' ),
				),
				'user_id'          => array(
					'description' => 'The ID of the user who submitted.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'create' ),
				),
				'uuid'             => array(
					'description' => 'Unique identifier for the submission.',
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'create' ),
					'readonly'    => true,
				),
				'status'           => array(
					'description' => 'Submission status.',
					'type'        => 'string',
					'enum'        => array( 'in-progress', 'completed', 'graded', 'cancelled' ),
					'context'     => array( 'view', 'edit', 'create' ),
				),
				'started_at'       => array(
					'description' => 'The date the submission was started, in the site\'s timezone.',
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit', 'create' ),
				),
				'completed_at'     => array(
					'description' => 'The date the submission was completed, in the site\'s timezone.',
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit', 'create' ),
				),
				'created_at'       => array(
					'description' => 'The date the submission was created, in the site\'s timezone.',
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit', 'create' ),
					'readonly'    => true,
				),
				'updated_at'       => array(
					'description' => 'The date the submission was last modified, in the site\'s timezone.',
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit', 'create' ),
					'readonly'    => true,
				),
				'meta'             => array(
					'description'          => 'Meta data for the submission.',
					'type'                 => 'object',
					'context'              => array( 'view', 'edit', 'create' ),
					'properties'           => array(),
					'additionalProperties' => true,
				),
				'task_submissions' => array(
					'description' => 'Task submissions associated with this test submission.',
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'task_submission_id' => array(
								'description' => 'ID of the task submission.',
								'type'        => 'integer',
							),
							'task_id'            => array(
								'description' => 'ID of the writing task.',
								'type'        => 'integer',
							),
							'essay_id'           => array(
								'description' => 'ID of the associated essay.',
								'type'        => 'integer',
							),
						),
					),
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}
}
