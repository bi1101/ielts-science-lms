<?php
/**
 * REST API Controller for IELTS Science Speaking Test Submissions
 *
 * Handles CRUD operations for test submissions, including meta fields.
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

/**
 * Class Ieltssci_Speaking_Test_Submission_Controller
 *
 * REST API controller for speaking test submissions.
 *
 * @since 1.0.0
 */
class Ieltssci_Speaking_Test_Submission_Controller extends WP_REST_Controller {
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
	protected $resource_test = 'speaking-test-submissions';

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
					'permission_callback' => '__return_true', // Allow public access to single submission.
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

		// Register route for forking a test submission.
		register_rest_route(
			$this->namespace,
			'/' . $this->resource_test . '/fork/(?P<id>[0-9a-f-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'fork_test_submission' ),
					'permission_callback' => array( $this, 'fork_test_submission_permissions_check' ),
					'args'                => $this->get_fork_test_submission_args(),
				),
				'schema' => array( $this, 'get_fork_test_submission_schema' ),
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

		// Ensure meta data is loaded.
		if ( ! isset( $submission['meta'] ) ) {
			$submission['meta'] = $this->db->get_test_submission_meta( $submission['id'] );
		}

		// Users can access their own submissions.
		if ( (int) $submission['user_id'] === $current_user_id ) {
			return true;
		}

		// Instructors can access submissions for their courses.
		if ( ! empty( $submission['meta']['instructor_id'] ) && (int) $submission['meta']['instructor_id'][0] === $current_user_id ) {
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

		if ( ! current_user_can( 'manage_options' ) ) {
			// For non-admins, get all submissions and filter in PHP for security.
			$all_args                 = $args;
			$all_args['include_meta'] = true;
			unset( $all_args['number'], $all_args['offset'], $all_args['user_id'] ); // Remove user_id restriction.
			$all_submissions = $this->db->get_test_submissions( $all_args );

			if ( is_wp_error( $all_submissions ) ) {
				return $all_submissions;
			}

			// Filter submissions based on access permissions.
			$accessible_submissions = array();
			foreach ( $all_submissions as $submission ) {
				if ( $this->can_access_submission( $submission, $request ) ) {
					$accessible_submissions[] = $submission;
				}
			}

			$total = count( $accessible_submissions );

			// Apply pagination to filtered results.
			$offset      = $args['offset'];
			$number      = $args['number'];
			$submissions = array_slice( $accessible_submissions, $offset, $number );
		} else {
			// Get submissions from database.
			$submissions = $this->db->get_test_submissions( $args );

			if ( is_wp_error( $submissions ) ) {
				return $submissions;
			}

			$total = 0;
		}

		// Always fetch part submissions for linking (efficient batch query).
		if ( ! empty( $submissions ) ) {
			// Extract all submission IDs for batch query.
			$submission_ids = array_column( $submissions, 'id' );

			// Get all part submissions for these test submissions in one query.
			$part_submissions = $this->db->get_part_submissions(
				array(
					'test_submission_id' => $submission_ids,
					'number'             => 999, // Large number to get all related part submissions.
				)
			);

			if ( ! is_wp_error( $part_submissions ) ) {
				// Group part submissions by test submission ID.
				$part_submissions_by_test = array();
				foreach ( $part_submissions as $part_submission ) {
					$test_submission_id = $part_submission['test_submission_id'];
					if ( ! isset( $part_submissions_by_test[ $test_submission_id ] ) ) {
						$part_submissions_by_test[ $test_submission_id ] = array();
					}
					$part_submissions_by_test[ $test_submission_id ][] = array(
						'part_submission_id' => (int) $part_submission['id'],
						'part_id'            => (int) $part_submission['part_id'],
						'speech_id'          => (int) $part_submission['speech_id'],
					);
				}

				// Add part submissions to each test submission for link generation.
				foreach ( $submissions as &$submission ) {
					$submission_id                  = $submission['id'];
					$submission['part_submissions'] = $part_submissions_by_test[ $submission_id ] ?? array();
				}
			}
		}

		// Prepare response data - all submissions should already be accessible based on query filtering.
		$data = array();
		foreach ( $submissions as $submission ) {
			$response = $this->prepare_test_submission_for_response( $submission, $request );
			$data[]   = $this->prepare_response_for_collection( $response );
		}

		if ( current_user_can( 'manage_options' ) ) {
			// Get total count for pagination headers.
			$count_args          = $args;
			$count_args['count'] = true;
			unset( $count_args['number'], $count_args['offset'] );
			$total = $this->db->get_test_submissions( $count_args );

			if ( is_wp_error( $total ) ) {
				$total = 0;
			}
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

		// Include meta data by default for single item requests.
		if ( ! isset( $submission['meta'] ) ) {
			$submission['meta'] = $this->db->get_test_submission_meta( $submission['id'] );
		}

		// Always fetch part submissions for link generation.
		if ( ! isset( $submission['part_submissions'] ) ) {
			$part_submissions = $this->db->get_part_submissions( array( 'test_submission_id' => $submission['id'] ) );
			if ( ! is_wp_error( $part_submissions ) && ! empty( $part_submissions ) ) {
				$submission['part_submissions'] = array_map(
					function ( $part_submission ) {
						return array(
							'part_submission_id' => (int) $part_submission['id'],
							'part_id'            => (int) $part_submission['part_id'],
							'speech_id'          => (int) $part_submission['speech_id'],
						);
					},
					$part_submissions
				);
			} else {
				$submission['part_submissions'] = array();
			}
		}

		// Check access permissions.
		if ( ! $this->can_access_submission( $submission, $request ) ) {
			return new WP_Error(
				'ieltssci_test_submission_access_denied',
				'You do not have permission to access this test submission.',
				array( 'status' => 403 )
			);
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
	 * 3. Retrieve all speaking parts associated with the test (via ACF 'speaking_parts' field).
	 * 4. For each speaking part:
	 *    - Create a speech with the part's question and data.
	 *    - Create a part submission linking the test submission to the part and speech.
	 * 5. Return the test submission with information about created part submissions.
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

		// Verify that the test exists and is a speaking-test post type.
		$test_post = get_post( $test_id );
		if ( ! $test_post || 'speaking-test' !== $test_post->post_type ) {
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

		// Get associated speaking parts from the test.
		$speaking_parts   = get_field( 'speaking_parts', $test_id );
		$part_submissions = array();

		if ( ! empty( $speaking_parts ) && is_array( $speaking_parts ) ) {
			// Create part submissions for each associated speaking part.
			foreach ( $speaking_parts as $part_post ) {
				if ( ! is_object( $part_post ) || empty( $part_post->ID ) ) {
					continue;
				}

				$part_id = $part_post->ID;

				// Create speech data from part information.
				$speech_data = array(
					'created_by' => $user_id,
				);

				// Create the speech using the Speech DB handler.
				$speech_db = new Ieltssci_Speech_DB();
				$speech    = $speech_db->create_speech( $speech_data );

				if ( is_wp_error( $speech ) ) {
					error_log( "Failed to create speech for part ID {$part_id}: " . $speech->get_error_message() );
					continue;
				}

				// Prepare part submission data.
				$part_submission_data = array(
					'test_submission_id' => $submission_id,
					'user_id'            => $user_id,
					'part_id'            => $part_id,
					'speech_id'          => (int) $speech['id'],
					'status'             => 'in-progress',
				);

				// Create the part submission.
				$part_submission_id = $this->db->add_part_submission( $part_submission_data );

				if ( is_wp_error( $part_submission_id ) ) {
					error_log( "Failed to create part submission for part ID {$part_id}: " . $part_submission_id->get_error_message() );
					continue;
				}

				$part_submissions[] = array(
					'part_submission_id' => $part_submission_id,
					'part_id'            => $part_id,
					'speech_id'          => (int) $speech['id'],
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

		// Add part submissions to the submission data before preparation.
		if ( ! empty( $part_submissions ) ) {
			$created_submission['part_submissions'] = $part_submissions;
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
		do_action( 'ieltssci_rest_create_speaking_test_submission', $created_submission, $request );

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
			if ( ! $test_post || 'speaking-test' !== $test_post->post_type || 'publish' !== $test_post->post_status ) {
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

		// Add part submissions to the submission data before preparation.
		$part_submissions = $this->db->get_part_submissions( array( 'test_submission_id' => $submission_id ) );
		if ( ! is_wp_error( $part_submissions ) && ! empty( $part_submissions ) ) {
			$updated_submission['part_submissions'] = array_map(
				function ( $part ) {
					return array(
						'part_submission_id' => (int) $part['id'], // Ensure integer.
						'part_id'            => (int) $part['part_id'], // Ensure integer.
						'speech_id'          => (int) $part['speech_id'], // Ensure integer.
					);
				},
				$part_submissions
			);
		} else {
			$updated_submission['part_submissions'] = array();
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
		do_action( 'ieltssci_rest_update_speaking_test_submission', $updated_submission, $request );

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
		do_action( 'ieltssci_rest_delete_speaking_test_submission', $existing_submission, $request );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Fork a test submission.
	 *
	 * Creates a duplicate of an existing test submission.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function fork_test_submission( WP_REST_Request $request ) {
		$identifier = $request->get_param( 'id' );

		// Determine if identifier is numeric ID or UUID and get submission.
		if ( is_numeric( $identifier ) ) {
			$test_submission = $this->db->get_test_submission( (int) $identifier );
		} else {
			// It's a UUID, query by UUID.
			$submissions     = $this->db->get_test_submissions( array( 'uuid' => $identifier ) );
			$test_submission = ! empty( $submissions ) ? $submissions[0] : null;
		}

		if ( is_wp_error( $test_submission ) ) {
			return $test_submission; // Return the WP_Error directly from the DB layer.
		}

		if ( ! $test_submission ) {
			return new WP_Error(
				'test_submission_not_found',
				__( 'Test submission not found.', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		// Check if user can access this submission.
		if ( ! $this->can_access_submission( $test_submission, $request ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to fork this test submission.', 'ielts-science-lms' ),
				array( 'status' => 403 )
			);
		}

		// Get options from request body with defaults following the Writing pattern.
		$options = array(
			'copy_part_submissions' => $request->get_param( 'fork_part_submissions' ) !== null ? (bool) $request->get_param( 'fork_part_submissions' ) : true,
			// Keep DB option key singular to match existing DB layer expectations.
			'fork_speech'           => $request->get_param( 'fork_speeches' ) !== null ? (bool) $request->get_param( 'fork_speeches' ) : true,
			'copy_speech_feedback'  => $request->get_param( 'copy_speech_feedback' ) !== null ? (bool) $request->get_param( 'copy_speech_feedback' ) : true,
			'copy_meta'             => $request->get_param( 'copy_meta' ) !== null ? (bool) $request->get_param( 'copy_meta' ) : true,
			'keep_status'           => $request->get_param( 'keep_status' ) !== null ? (bool) $request->get_param( 'keep_status' ) : true,
			// Derive part-level defaults from test-level settings, mirroring Writing controller behaviour.
			'copy_part_meta'        => $request->get_param( 'copy_meta' ) !== null ? (bool) $request->get_param( 'copy_meta' ) : true,
			'keep_part_status'      => $request->get_param( 'keep_status' ) !== null ? (bool) $request->get_param( 'keep_status' ) : true,
		);

		// Call the fork method in the database service.
		$result = $this->db->fork_test_submission( $test_submission['id'], get_current_user_id(), $options );

		if ( is_wp_error( $result ) ) {
			return $result; // Return the WP_Error directly from the DB layer.
		}

		// Prepare the response data with detailed fork information.
		$response_data = array(
			'test_submission'         => $this->prepare_test_submission_for_response( $result['test_submission'], $request )->data,
			'forked_part_submissions' => isset( $result['forked_parts'] ) ? $result['forked_parts'] : array(),
			'copied_meta_keys'        => $result['copied_meta_keys'],
		);

		// Create response with 201 Created status.
		$response = rest_ensure_response( $response_data );
		$response->set_status( 201 );

		// Add link to the original test submission.
		$response->add_link(
			'original-test-submission',
			rest_url( $this->namespace . '/' . $this->resource_test . '/' . $test_submission['id'] ),
			array(
				'embeddable' => true,
			)
		);

		/**
		 * Fires after a test submission is forked via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $result  The fork result data.
		 * @param WP_REST_Request $request Request used to fork the test submission.
		 */
		do_action( 'ieltssci_rest_fork_test_submission', $result, $request );

		return $response;
	}

	/**
	 * Check permissions for forking a test submission.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool|WP_Error True if allowed, WP_Error if not.
	 */
	public function fork_test_submission_permissions_check( $request ) {
		return is_user_logged_in();
	}

	/**
	 * Prepare test submission for response.
	 *
	 * Formats the test submission data for API response and adds embeddable links to related part submissions.
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

		// Include part submissions if available.
		if ( isset( $submission['part_submissions'] ) ) {
			$data['part_submissions'] = $submission['part_submissions'];
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

		// Add embeddable link to the speaking test.
		$response->add_link(
			'speaking-test',
			rest_url( 'wp/v2/speaking-test/' . $submission['test_id'] ),
			array(
				'embeddable' => true,
			)
		);

		// Add embeddable link to the original speaking test submission if original_id meta exists.
		if ( isset( $submission['meta']['original_id'] ) && ! empty( $submission['meta']['original_id'] ) ) {
			$response->add_link(
				'original-speaking-test-submission',
				rest_url( $this->namespace . '/' . $this->resource_test . '/' . $submission['meta']['original_id'][0] ),
				array(
					'embeddable' => true,
				)
			);
		}

		// Add embeddable links to related part submissions.
		if ( isset( $submission['part_submissions'] ) && ! empty( $submission['part_submissions'] ) ) {
			foreach ( $submission['part_submissions'] as $part_submission ) {
				$part_submission_id = $part_submission['part_submission_id'];
				$part_id            = $part_submission['part_id'];
				$speech_id          = $part_submission['speech_id'];

				// Add embeddable link to each part submission.
				$response->add_link(
					'part-submission',
					rest_url( $this->namespace . '/speaking-part-submissions/' . $part_submission_id ),
					array(
						'embeddable' => true,
					)
				);

				// Add embeddable link to each speaking part.
				$response->add_link(
					'speaking-part',
					rest_url( 'wp/v2/speaking-part/' . $part_id ),
					array(
						'embeddable' => true,
					)
				);

				// Add embeddable link to each speech using the collection endpoint with ID parameter.
				$response->add_link(
					'speech',
					rest_url( $this->namespace . '/speaking/speeches/' . $speech_id ),
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
		return apply_filters( 'ieltssci_rest_prepare_speaking_test_submission', $response, $submission, $request );
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
			'validate_callback' => function ( $value ) {
				return is_numeric( $value ) && $value >= 1;
			},
			'minimum'           => 1,
		);

		$params['per_page'] = array(
			'description'       => 'Maximum number of items to be returned in result set.',
			'type'              => 'integer',
			'default'           => 20,
			'minimum'           => 1,
			'maximum'           => 100,
			'sanitize_callback' => 'absint',
		);

		$params['search'] = array(
			'description'       => 'Limit results to those matching a string.',
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);

		$params['after'] = array(
			'description' => 'Limit response to posts published after a given ISO8601 compliant date.',
			'type'        => 'string',
			'format'      => 'date-time',
		);

		$params['before'] = array(
			'description' => 'Limit response to posts published before a given ISO8601 compliant date.',
			'type'        => 'string',
			'format'      => 'date-time',
		);

		$params['exclude'] = array(
			'description'       => 'Ensure result set excludes specific IDs.',
			'type'              => 'array',
			'items'             => array(
				'type' => 'integer',
			),
			'sanitize_callback' => function ( $value ) {
				return is_array( $value ) ? array_map( 'absint', $value ) : array();
			},
		);

		$params['include'] = array(
			'description'       => 'Limit result set to specific IDs.',
			'type'              => 'array',
			'items'             => array(
				'type' => 'integer',
			),
			'sanitize_callback' => function ( $value ) {
				return is_array( $value ) ? array_map( 'absint', $value ) : array();
			},
		);

		$params['offset'] = array(
			'description'       => 'Offset the result set by a specific number of items.',
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		);

		$params['order'] = array(
			'description' => 'Order sort attribute ascending or descending.',
			'type'        => 'string',
			'default'     => 'desc',
			'enum'        => array( 'asc', 'desc' ),
		);

		$params['orderby'] = array(
			'description' => 'Sort collection by object attribute.',
			'type'        => 'string',
			'default'     => 'id',
			'enum'        => array( 'id', 'uuid', 'test_id', 'user_id', 'status', 'started_at', 'completed_at', 'updated_at' ),
		);

		$params['user_id'] = array(
			'description' => 'Limit results to submissions by a specific user.',
			'type'        => 'integer',
			'minimum'     => 1,
		);

		$params['test_id'] = array(
			'description' => 'Limit results to submissions for a specific test.',
			'type'        => 'integer',
			'minimum'     => 1,
		);

		$params['status'] = array(
			'description' => 'Limit results to submissions with a specific status.',
			'type'        => 'string',
			'enum'        => array( 'in-progress', 'completed', 'cancelled' ),
		);

		$params['include_meta'] = array(
			'description' => 'Include meta data in the response.',
			'type'        => 'boolean',
			'default'     => false,
		);

		return $params;
	}

	/**
	 * Get the query parameters for creating a test submission.
	 *
	 * @since 1.0.0
	 *
	 * @return array Query parameters.
	 */
	protected function get_test_submission_create_params() {
		return array(
			'test_id' => array(
				'description' => 'The ID of the speaking test.',
				'type'        => 'integer',
				'minimum'     => 1,
				'required'    => true,
			),
			'user_id' => array(
				'description' => 'The ID of the user who owns the submission.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'uuid'    => array(
				'description' => 'Custom UUID for the submission.',
				'type'        => 'string',
				'format'      => 'uuid',
			),
			'status'  => array(
				'description' => 'The submission status.',
				'type'        => 'string',
				'default'     => 'in-progress',
				'enum'        => array( 'in-progress', 'completed', 'cancelled' ),
			),
			'meta'    => array(
				'description' => 'Meta data to associate with the submission.',
				'type'        => 'object',
			),
		);
	}

	/**
	 * Get the query parameters for updating a test submission.
	 *
	 * @since 1.0.0
	 *
	 * @return array Query parameters.
	 */
	protected function get_test_submission_update_params() {
		return array(
			'test_id'      => array(
				'description' => 'The ID of the speaking test.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'user_id'      => array(
				'description' => 'The ID of the user who owns the submission.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'status'       => array(
				'description' => 'The submission status.',
				'type'        => 'string',
				'enum'        => array( 'in-progress', 'completed', 'cancelled' ),
			),
			'started_at'   => array(
				'description' => 'The timestamp when the submission was started (GMT).',
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'completed_at' => array(
				'description' => 'The timestamp when the submission was completed (GMT).',
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'meta'         => array(
				'description' => 'Meta data to update for the submission.',
				'type'        => 'object',
			),
		);
	}

	/**
	 * Get the query parameters for forking a test submission.
	 *
	 * @since 1.0.0
	 *
	 * @return array Query parameters.
	 */
	protected function get_fork_test_submission_args() {
		return array(
			'id'                    => array(
				'required'          => true,
				'description'       => 'The ID or UUID of the test submission to fork.',
				'type'              => 'string',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) || wp_is_uuid( $param );
				},
				'sanitize_callback' => function ( $param ) {
					return is_numeric( $param ) ? absint( $param ) : sanitize_text_field( $param );
				},
			),
			'fork_part_submissions' => array(
				'required'          => false,
				'description'       => 'Whether to fork the associated part submissions.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'fork_speeches'         => array(
				'required'          => false,
				'description'       => 'Whether to fork the speeches associated with part submissions.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'copy_speech_feedback'  => array(
				'required'          => false,
				'description'       => 'Whether to copy speech feedback when forking speeches.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'copy_meta'             => array(
				'required'          => false,
				'description'       => 'Whether to copy test submission meta data.',
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
		);
	}

	/**
	 * Get the test submission schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Schema array.
	 */
	public function get_test_submission_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'ieltssci_speaking_test_submission',
			'type'       => 'object',
			'properties' => array(
				'id'               => array(
					'description' => 'Unique identifier for the test submission.',
					'type'        => 'integer',
					'readonly'    => true,
				),
				'test_id'          => array(
					'description' => 'ID of the speaking test.',
					'type'        => 'integer',
				),
				'user_id'          => array(
					'description' => 'ID of the user who owns the submission.',
					'type'        => 'integer',
				),
				'uuid'             => array(
					'description' => 'Unique UUID for the test submission.',
					'type'        => 'string',
					'format'      => 'uuid',
					'readonly'    => true,
				),
				'status'           => array(
					'description' => 'Submission status.',
					'type'        => 'string',
					'enum'        => array( 'in-progress', 'completed', 'cancelled' ),
				),
				'started_at'       => array(
					'description' => 'Timestamp when the submission was started (GMT).',
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'completed_at'     => array(
					'description' => 'Timestamp when the submission was completed (GMT).',
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'updated_at'       => array(
					'description' => 'Timestamp when the submission was last updated (GMT).',
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'meta'             => array(
					'description' => 'Meta data associated with the submission.',
					'type'        => 'object',
				),
				'part_submissions' => array(
					'description' => 'Part submissions associated with this test submission.',
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'part_submission_id' => array(
								'description' => 'ID of the part submission.',
								'type'        => 'integer',
							),
							'part_id'            => array(
								'description' => 'ID of the speaking part.',
								'type'        => 'integer',
							),
							'speech_id'          => array(
								'description' => 'ID of the speech.',
								'type'        => 'integer',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Get the fork test submission schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Schema array.
	 */
	public function get_fork_test_submission_schema() {
		// Reuse the test submission item schema properties.
		$test_submission_properties = $this->get_test_submission_schema();
		$test_submission_properties = isset( $test_submission_properties['properties'] ) ? $test_submission_properties['properties'] : array();

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'fork_speaking_test_submission',
			'type'       => 'object',
			'properties' => array(
				'test_submission'         => array(
					'description' => 'The newly forked test submission object.',
					'type'        => 'object',
					'properties'  => $test_submission_properties,
				),
				'forked_part_submissions' => array(
					'description' => 'Details of the forked part submissions.',
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'original_id' => array(
								'description' => 'The original part submission ID.',
								'type'        => 'string',
							),
							'result'      => array(
								'type'       => 'object',
								'properties' => array(
									'part_submission' => array(
										'type' => 'object',
									),
									'forked_speech'   => array(
										'type' => 'object',
									),
									'copied_meta_keys' => array(
										'description' => 'Array of meta keys that were copied for this part submission.',
										'type'        => 'array',
										'items'       => array(
											'type' => 'string',
										),
									),
								),
							),
						),
					),
				),
				'copied_meta_keys'        => array(
					'description' => 'Array of meta keys that were copied.',
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
				),
			),
			'required'   => array( 'test_submission' ),
		);
	}
}
