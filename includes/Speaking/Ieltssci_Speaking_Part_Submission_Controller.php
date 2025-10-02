<?php
/**
 * REST API Controller for IELTS Science Speaking Part Submissions
 *
 * Handles CRUD operations for part submissions, including meta fields.
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
 * Class Ieltssci_Speaking_Part_Submission_Controller
 *
 * REST API controller for speaking part submissions.
 *
 * @since 1.0.0
 */
class Ieltssci_Speaking_Part_Submission_Controller extends WP_REST_Controller {
	/**
	 * Namespace for REST routes.
	 *
	 * @var string
	 */
	protected $namespace = 'ieltssci/v1';

	/**
	 * Resource name for part submissions.
	 *
	 * @var string
	 */
	protected $resource_part = 'speaking-part-submissions';

	/**
	 * DB handler.
	 *
	 * @var Ieltssci_Submission_DB
	 */
	protected $db;

	/**
	 * Speech DB handler.
	 *
	 * @var Ieltssci_Speech_DB
	 */
	protected $speech_db;

	/**
	 * Constructor.
	 *
	 * Initializes the controller and registers routes.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->db        = new Ieltssci_Submission_DB();
		$this->speech_db = new Ieltssci_Speech_DB();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * Registers all routes for part submissions including collection and single item endpoints.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Part submissions.
		register_rest_route(
			$this->namespace,
			'/' . $this->resource_part,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_part_submissions' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => $this->get_part_submissions_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_part_submission' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => $this->get_part_submission_create_params(),
				),
				'schema' => array( $this, 'get_part_submission_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->resource_part . '/(?P<id>[0-9a-f-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_part_submission' ),
					'permission_callback' => '__return_true', // Allow public access to single submission.
					'args'                => array(
						'id' => array(
							'description'       => 'Unique identifier (ID or UUID) for the part submission.',
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
					'callback'            => array( $this, 'update_part_submission' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => $this->get_part_submission_update_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_part_submission' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'id' => array(
							'description'       => 'Unique identifier (ID or UUID) for the part submission.',
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => function ( $param ) {
								// Accept either numeric ID or valid UUID format.
								return is_numeric( $param ) || wp_is_uuid( $param );
							},
						),
					),
				),
				'schema' => array( $this, 'get_part_submission_schema' ),
			)
		);

		// Register route for forking a part submission.
		register_rest_route(
			$this->namespace,
			'/' . $this->resource_part . '/fork/(?P<id>\\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'fork_part_submission' ),
					'permission_callback' => array( $this, 'fork_part_submission_permissions_check' ),
					'args'                => $this->get_fork_part_submission_args(),
				),
				'schema' => array( $this, 'get_fork_part_submission_schema' ),
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
			$submission['meta'] = $this->db->get_part_submission_meta( $submission['id'] );
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
	 * Get part submissions collection.
	 *
	 * Retrieves a collection of part submissions based on provided parameters.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_part_submissions( WP_REST_Request $request ) {
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
		if ( ! empty( $request['part_id'] ) ) {
			$args['part_id'] = (int) $request['part_id'];
		}

		if ( ! empty( $request['test_submission_id'] ) ) {
			$args['test_submission_id'] = (int) $request['test_submission_id'];
		}

		if ( ! empty( $request['speech_id'] ) ) {
			$args['speech_id'] = (int) $request['speech_id'];
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
			$all_submissions = $this->db->get_part_submissions( $all_args );

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
			$submissions = $this->db->get_part_submissions( $args );

			if ( is_wp_error( $submissions ) ) {
				return $submissions;
			}

			$total = 0;
		}

		// Prepare response data - all submissions should already be accessible based on query filtering.
		$data = array();
		foreach ( $submissions as $submission ) {
			$response = $this->prepare_part_submission_for_response( $submission, $request );
			$data[]   = $this->prepare_response_for_collection( $response );
		}

		if ( current_user_can( 'manage_options' ) ) {
			// Get total count for pagination headers.
			$count_args          = $args;
			$count_args['count'] = true;
			unset( $count_args['number'], $count_args['offset'] );
			$total = $this->db->get_part_submissions( $count_args );

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
	 * Get a single part submission by ID or UUID.
	 *
	 * Retrieves a specific part submission with its associated meta data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_part_submission( WP_REST_Request $request ) {
		$identifier = $request['id'];

		// Determine if identifier is numeric ID or UUID.
		if ( is_numeric( $identifier ) ) {
			$submission = $this->db->get_part_submission( (int) $identifier );
		} else {
			// It's a UUID, query by UUID.
			$submissions = $this->db->get_part_submissions( array( 'uuid' => $identifier ) );
			$submission  = ! empty( $submissions ) ? $submissions[0] : null;
		}

		if ( is_wp_error( $submission ) ) {
			return $submission;
		}

		if ( ! $submission ) {
			return new WP_Error(
				'ieltssci_part_submission_not_found',
				'Part submission not found.',
				array( 'status' => 404 )
			);
		}

		// Include meta data by default for single item requests.
		if ( ! isset( $submission['meta'] ) ) {
			$submission['meta'] = $this->db->get_part_submission_meta( $submission['id'] );
		}

		// Check access permissions.
		if ( ! $this->can_access_submission( $submission, $request ) ) {
			return new WP_Error(
				'ieltssci_part_submission_access_denied',
				'You do not have permission to access this part submission.',
				array( 'status' => 403 )
			);
		}

		$response = $this->prepare_part_submission_for_response( $submission, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Create a new part submission.
	 *
	 * Creates a new part submission with the provided data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function create_part_submission( WP_REST_Request $request ) {
		$data = array();

		// Map REST parameters to database fields.
		if ( isset( $request['user_id'] ) ) {
			$data['user_id'] = (int) $request['user_id'];
		} else {
			$data['user_id'] = get_current_user_id();
		}

		if ( isset( $request['part_id'] ) ) {
			$data['part_id'] = (int) $request['part_id'];
		}

		if ( isset( $request['speech_id'] ) ) {
			$data['speech_id'] = (int) $request['speech_id'];
		}

		if ( isset( $request['test_submission_id'] ) ) {
			$data['test_submission_id'] = (int) $request['test_submission_id'];
		}

		if ( isset( $request['status'] ) ) {
			$data['status'] = sanitize_text_field( $request['status'] );
		}

		if ( isset( $request['started_at'] ) ) {
			$data['started_at'] = sanitize_text_field( $request['started_at'] );
		}

		if ( isset( $request['completed_at'] ) ) {
			$data['completed_at'] = sanitize_text_field( $request['completed_at'] );
		}

		// Validate user.
		if ( ! $data['user_id'] ) {
			return new WP_Error(
				'invalid_user',
				'No valid user provided.',
				array( 'status' => 400 )
			);
		}

		// Check permissions for creating submissions for other users.
		if ( get_current_user_id() !== $data['user_id'] && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				'You cannot create submissions for other users.',
				array( 'status' => 403 )
			);
		}

		// Verify that the part exists and is a speaking-part post type.
		$part_post = get_post( $data['part_id'] );
		if ( ! $part_post || 'speaking-part' !== $part_post->post_type ) {
			return new WP_Error(
				'invalid_part',
				'Invalid part ID or part not found.',
				array( 'status' => 404 )
			);
		}

		// Check if part is published.
		if ( 'publish' !== $part_post->post_status ) {
			return new WP_Error(
				'part_not_available',
				'Part is not available for submission.',
				array( 'status' => 400 )
			);
		}

		// Create speech if not provided.
		if ( empty( $data['speech_id'] ) ) {
			$speech_data = array(
				'created_by' => $data['user_id'],
			);
			$speech      = $this->speech_db->create_speech( $speech_data );
			if ( is_wp_error( $speech ) ) {
				return $speech;
			}
			$data['speech_id'] = $speech['id'];
		}

		// Validate required fields.
		if ( empty( $data['user_id'] ) || empty( $data['part_id'] ) ) {
			return new WP_Error(
				'ieltssci_part_submission_missing_required',
				'Missing required fields: user_id and part_id are required.',
				array( 'status' => 400 )
			);
		}

		// Create the submission.
		$submission_id = $this->db->add_part_submission( $data );

		if ( is_wp_error( $submission_id ) ) {
			return $submission_id;
		}

		// Get the created submission.
		$created_submission = $this->db->get_part_submission( $submission_id );

		if ( is_wp_error( $created_submission ) ) {
			return $created_submission;
		}

		// Include meta data.
		$created_submission['meta'] = $this->db->get_part_submission_meta( $created_submission['id'] );

		$response = $this->prepare_part_submission_for_response( $created_submission, $request );

		$response->set_status( 201 );

		/**
		 * Fires after a speaking part submission is created via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $created_submission The created submission data.
		 * @param WP_REST_Request $request           Request used to create the submission.
		 */
		do_action( 'ieltssci_rest_create_part_submission', $created_submission, $request );

		return $response;
	}

	/**
	 * Update an existing part submission.
	 *
	 * Updates a part submission with the provided data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function update_part_submission( WP_REST_Request $request ) {
		$identifier = $request['id'];

		// Determine if identifier is numeric ID or UUID and get submission.
		if ( is_numeric( $identifier ) ) {
			$existing_submission = $this->db->get_part_submission( (int) $identifier );
		} else {
			// It's a UUID, query by UUID.
			$submissions         = $this->db->get_part_submissions( array( 'uuid' => $identifier ) );
			$existing_submission = ! empty( $submissions ) ? $submissions[0] : null;
		}

		if ( is_wp_error( $existing_submission ) ) {
			return $existing_submission;
		}

		if ( ! $existing_submission ) {
			return new WP_Error(
				'ieltssci_part_submission_not_found',
				'Part submission not found.',
				array( 'status' => 404 )
			);
		}

		// Get the numeric submission ID for database operations.
		$submission_id = (int) $existing_submission['id'];

		// Check if current user can access this submission.
		if ( ! $this->can_access_submission( $existing_submission, $request ) ) {
			return new WP_Error(
				'ieltssci_part_submission_access_denied',
				'You do not have permission to access this part submission.',
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

		if ( isset( $request['part_id'] ) ) {
			// Verify the new part exists and is published.
			$part_post = get_post( (int) $request['part_id'] );
			if ( ! $part_post || 'speaking-part' !== $part_post->post_type || 'publish' !== $part_post->post_status ) {
				return new WP_Error(
					'invalid_part',
					'Invalid part ID or part not available.',
					array( 'status' => 400 )
				);
			}
			$update_data['part_id'] = (int) $request['part_id'];
		}

		if ( isset( $request['speech_id'] ) ) {
			$update_data['speech_id'] = (int) $request['speech_id'];
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
			$update_result = $this->db->update_part_submission( $submission_id, $update_data );
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
				$existing_meta = $this->db->get_part_submission_meta( $submission_id, $meta_key, true );

				if ( ! empty( $existing_meta ) ) {
					// Update existing meta.
					$meta_result = $this->db->update_part_submission_meta( $submission_id, $meta_key, $sanitized_value );
				} else {
					// Add new meta.
					$meta_result = $this->db->add_part_submission_meta( $submission_id, $meta_key, $sanitized_value );
				}

				if ( is_wp_error( $meta_result ) ) {
					error_log( 'Failed to update meta data: ' . $meta_result->get_error_message() );
				}
			}
		}

		// Retrieve the updated submission with meta data.
		$updated_submission = $this->db->get_part_submission( $submission_id );
		if ( is_wp_error( $updated_submission ) ) {
			return $updated_submission;
		}

		// Include meta data with consistent formatting.
		$updated_submission['meta'] = $this->db->get_part_submission_meta( $submission_id );

		// Prepare the response.
		$response = $this->prepare_part_submission_for_response( $updated_submission, $request );

		/**
		 * Fires after a speaking part submission is updated via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $updated_submission The updated submission data.
		 * @param array           $existing_submission The original submission data before update.
		 * @param WP_REST_Request $request            Request used to update the submission.
		 */
		do_action( 'ieltssci_rest_update_part_submission', $updated_submission, $existing_submission, $request );

		return $response;
	}

	/**
	 * Delete a part submission.
	 *
	 * Deletes a part submission and its associated meta data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function delete_part_submission( WP_REST_Request $request ) {
		$identifier = $request['id'];

		// Determine if identifier is numeric ID or UUID and get submission.
		if ( is_numeric( $identifier ) ) {
			$existing_submission = $this->db->get_part_submission( (int) $identifier );
			$submission_id       = (int) $identifier;
		} else {
			// It's a UUID, query by UUID.
			$submissions         = $this->db->get_part_submissions( array( 'uuid' => $identifier ) );
			$existing_submission = ! empty( $submissions ) ? $submissions[0] : null;
			$submission_id       = $existing_submission ? (int) $existing_submission['id'] : 0;
		}

		if ( is_wp_error( $existing_submission ) ) {
			return $existing_submission;
		}

		if ( ! $existing_submission ) {
			return new WP_Error(
				'ieltssci_part_submission_not_found',
				'Part submission not found.',
				array( 'status' => 404 )
			);
		}

		// Check if current user can access this submission.
		if ( ! $this->can_access_submission( $existing_submission, $request ) ) {
			return new WP_Error(
				'ieltssci_part_submission_access_denied',
				'You do not have permission to access this part submission.',
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
		$existing_submission['meta'] = $this->db->get_part_submission_meta( $submission_id );

		// Attempt to delete the part submission (this will also delete meta data).
		$delete_result = $this->db->delete_part_submission( $submission_id );

		if ( is_wp_error( $delete_result ) ) {
			return $delete_result;
		}

		// Prepare the response with the deleted submission data.
		$response_data = array(
			'deleted'  => true,
			'previous' => $this->prepare_part_submission_for_response( $existing_submission, $request )->get_data(),
		);

		$response = rest_ensure_response( $response_data );

		/**
		 * Fires after a speaking part submission is deleted via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $existing_submission The deleted submission data (including meta).
		 * @param WP_REST_Request $request            Request used to delete the submission.
		 */
		do_action( 'ieltssci_rest_delete_part_submission', $existing_submission, $request );

		return $response;
	}

	/**
	 * Fork a part submission.
	 *
	 * Creates a duplicate of an existing part submission.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function fork_part_submission( WP_REST_Request $request ) {
		$part_submission_id = $request->get_param( 'id' );

		// Get options from request body.
		$options = array(
			'fork_speech'          => $request->get_param( 'fork_speech' ) !== null ? $request->get_param( 'fork_speech' ) : true,
			'copy_speech_meta'     => $request->get_param( 'copy_speech_meta' ) !== null ? $request->get_param( 'copy_speech_meta' ) : true,
			'copy_speech_feedback' => $request->get_param( 'copy_speech_feedback' ) !== null ? $request->get_param( 'copy_speech_feedback' ) : true,
			'copy_meta'            => $request->get_param( 'copy_meta' ) !== null ? $request->get_param( 'copy_meta' ) : true,
			'keep_status'          => $request->get_param( 'keep_status' ) !== null ? $request->get_param( 'keep_status' ) : true,
			'test_submission_id'   => $request->get_param( 'test_submission_id' ),
		);

		// Get the part submission to check if it exists.
		$part_submission = $this->db->get_part_submission( $part_submission_id );

		if ( is_wp_error( $part_submission ) ) {
			return $part_submission; // Return the WP_Error directly from the DB layer.
		}

		if ( ! $part_submission ) {
			return new WP_Error(
				'part_submission_not_found',
				__( 'Part submission not found.', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		// Check if user can access this submission.
		if ( ! $this->can_access_submission( $part_submission, $request ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to fork this part submission.', 'ielts-science-lms' ),
				array( 'status' => 403 )
			);
		}

		// Call the fork method in the database service.
		$result = $this->db->fork_part_submission( $part_submission_id, get_current_user_id(), $options );

		if ( is_wp_error( $result ) ) {
			return $result; // Return the WP_Error directly from the DB layer.
		}

		// Prepare the response data with detailed fork information.
		$response_data = array(
			'part_submission'  => $this->prepare_part_submission_for_response( $result['part_submission'], $request )->data,
			'forked_speech'    => $result['forked_speech'],
			'copied_meta_keys' => $result['copied_meta_keys'],
		);

		// Create response with 201 Created status.
		$response = rest_ensure_response( $response_data );
		$response->set_status( 201 );

		// Add link to the original part submission.
		$response->add_link(
			'original-part-submission',
			rest_url( $this->namespace . '/' . $this->resource_part . '/' . $part_submission_id ),
			array(
				'embeddable' => true,
			)
		);

		/**
		 * Fires after a part submission is forked via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $result  The fork result data.
		 * @param WP_REST_Request $request Request used to fork the part submission.
		 */
		do_action( 'ieltssci_rest_fork_part_submission', $result, $request );

		return $response;
	}

	/**
	 * Check permissions for forking a part submission.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool|WP_Error True if allowed, WP_Error if not.
	 */
	public function fork_part_submission_permissions_check( $request ) {
		return is_user_logged_in();
	}

	/**
	 * Get the query parameters for the get_part_submissions method.
	 *
	 * @since 1.0.0
	 *
	 * @return array Query parameters.
	 */
	public function get_part_submissions_params() {
		return array(
			'user_id'            => array(
				'description' => 'Limit results to submissions by a specific user.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'part_id'            => array(
				'description' => 'Limit results to submissions for a specific part.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'test_submission_id' => array(
				'description' => 'Limit results to submissions for a specific test submission.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'speech_id'          => array(
				'description' => 'Limit results to submissions for a specific speech.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'status'             => array(
				'description' => 'Limit results to submissions with a specific status.',
				'type'        => 'string',
				'enum'        => array( 'in-progress', 'completed', 'not_graded', 'graded' ),
			),
			'orderby'            => array(
				'description' => 'Sort collection by object attribute.',
				'type'        => 'string',
				'default'     => 'id',
				'enum'        => array( 'id', 'uuid', 'part_id', 'test_submission_id', 'user_id', 'speech_id', 'status', 'started_at', 'completed_at', 'updated_at' ),
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
				'description' => 'Include meta data in the response.',
				'type'        => 'boolean',
				'default'     => false,
			),
		);
	}

	/**
	 * Get the query parameters for creating a part submission.
	 *
	 * @since 1.0.0
	 *
	 * @return array Query parameters.
	 */
	public function get_part_submission_create_params() {
		return array(
			'user_id'            => array(
				'description' => 'The ID of the user who owns the submission.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'part_id'            => array(
				'description' => 'The ID of the speaking part.',
				'type'        => 'integer',
				'minimum'     => 1,
				'required'    => true,
			),
			'speech_id'          => array(
				'description' => 'The ID of the speech associated with the submission.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'test_submission_id' => array(
				'description' => 'The ID of the parent test submission.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'status'             => array(
				'description' => 'The submission status.',
				'type'        => 'string',
				'default'     => 'in-progress',
				'enum'        => array( 'in-progress', 'completed', 'not_graded', 'graded' ),
			),
			'started_at'         => array(
				'description' => 'The timestamp when the submission was started (GMT).',
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'completed_at'       => array(
				'description' => 'The timestamp when the submission was completed (GMT).',
				'type'        => 'string',
				'format'      => 'date-time',
			),
		);
	}

	/**
	 * Get the query parameters for updating a part submission.
	 *
	 * @since 1.0.0
	 *
	 * @return array Query parameters.
	 */
	public function get_part_submission_update_params() {
		return array(
			'test_submission_id' => array(
				'description' => 'The ID of the parent test submission.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'user_id'            => array(
				'description' => 'The ID of the user who owns the submission.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'part_id'            => array(
				'description' => 'The ID of the speaking part.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'speech_id'          => array(
				'description' => 'The ID of the speech associated with the submission.',
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'status'             => array(
				'description' => 'The submission status.',
				'type'        => 'string',
				'enum'        => array( 'in-progress', 'completed', 'not_graded', 'graded' ),
			),
			'started_at'         => array(
				'description' => 'The timestamp when the submission was started (GMT).',
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'completed_at'       => array(
				'description' => 'The timestamp when the submission was completed (GMT).',
				'type'        => 'string',
				'format'      => 'date-time',
			),
		);
	}

	/**
	 * Get the query parameters for forking a part submission.
	 *
	 * @since 1.0.0
	 *
	 * @return array Query parameters.
	 */
	public function get_fork_part_submission_args() {
		return array(
			'id'                   => array(
				'required'          => true,
				'description'       => 'The ID of the part submission to fork.',
				'type'              => 'integer',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
				'sanitize_callback' => 'absint',
			),
			'test_submission_id'   => array(
				'required'          => false,
				'description'       => 'Override the parent test submission ID.',
				'type'              => 'integer',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
				'sanitize_callback' => 'absint',
			),
			'copy_meta'            => array(
				'required'          => false,
				'description'       => 'Whether to copy part submission meta data.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'fork_speech'          => array(
				'required'          => false,
				'description'       => 'Whether to fork the associated speech.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'copy_speech_meta'     => array(
				'required'          => false,
				'description'       => 'Whether to copy meta data on the forked speech.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'copy_speech_feedback' => array(
				'required'          => false,
				'description'       => 'Whether to copy speech feedback to the forked speech.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'keep_status'          => array(
				'required'          => false,
				'description'       => 'Whether to keep the original status or reset to in-progress.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Prepare a part submission for the REST API response.
	 *
	 * @since 1.0.0
	 *
	 * @param array           $submission The submission data.
	 * @param WP_REST_Request $request    The REST request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_part_submission_for_response( $submission, $request ) {
		// Prepare basic fields.
		$data = array(
			'id'           => (int) $submission['id'],
			'uuid'         => $submission['uuid'],
			'user_id'      => (int) $submission['user_id'],
			'part_id'      => (int) $submission['part_id'],
			'speech_id'    => (int) $submission['speech_id'],
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

		// Add embeddable link to the speaking part.
		$response->add_link(
			'speaking-part',
			rest_url( 'wp/v2/speaking-part/' . $submission['part_id'] ),
			array(
				'embeddable' => true,
			)
		);

		// Add embeddable link to the speech.
		$response->add_link(
			'speech',
			rest_url( $this->namespace . '/speaking/speeches' ) . '?id=' . $submission['speech_id'],
			array(
				'embeddable' => true,
			)
		);

		// Add embeddable link to the parent test submission if available.
		if ( ! empty( $submission['test_submission_id'] ) ) {
			$response->add_link(
				'test-submission',
				rest_url( $this->namespace . '/speaking-test-submissions/' . $submission['test_submission_id'] ),
				array(
					'embeddable' => true,
				)
			);
		}

		/**
		 * Filter part submission data returned from the REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_REST_Response $response  The response object.
		 * @param array            $submission Raw part submission data.
		 * @param WP_REST_Request  $request   Request used to generate the response.
		 */
		return apply_filters( 'ieltssci_rest_prepare_part_submission', $response, $submission, $request );
	}

	/**
	 * Get the part submission schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Schema array.
	 */
	public function get_part_submission_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'ieltssci_part_submission',
			'type'       => 'object',
			'properties' => array(
				'id'                 => array(
					'description' => 'Unique identifier for the part submission.',
					'type'        => 'integer',
					'readonly'    => true,
				),
				'uuid'               => array(
					'description' => 'Unique UUID for the part submission.',
					'type'        => 'string',
					'format'      => 'uuid',
					'readonly'    => true,
				),
				'test_submission_id' => array(
					'description' => 'ID of the parent test submission.',
					'type'        => 'integer',
				),
				'user_id'            => array(
					'description' => 'ID of the user who owns the submission.',
					'type'        => 'integer',
				),
				'part_id'            => array(
					'description' => 'ID of the speaking part.',
					'type'        => 'integer',
				),
				'speech_id'          => array(
					'description' => 'ID of the speech associated with the submission.',
					'type'        => 'integer',
				),
				'status'             => array(
					'description' => 'Submission status.',
					'type'        => 'string',
					'enum'        => array( 'in-progress', 'completed', 'not_graded', 'graded' ),
				),
				'started_at'         => array(
					'description' => 'Timestamp when the submission was started (GMT).',
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'completed_at'       => array(
					'description' => 'Timestamp when the submission was completed (GMT).',
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'updated_at'         => array(
					'description' => 'Timestamp when the submission was last updated (GMT).',
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'meta'               => array(
					'description' => 'Meta data associated with the submission.',
					'type'        => 'object',
				),
			),
		);
	}

	/**
	 * Get the fork part submission schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Schema array.
	 */
	public function get_fork_part_submission_schema() {
		// Get the part submission item schema properties for reuse.
		$schema                     = $this->get_part_submission_schema();
		$part_submission_properties = isset( $schema['properties'] ) ? $schema['properties'] : array();

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'fork_part_submission',
			'type'       => 'object',
			'properties' => array(
				'part_submission'  => array(
					'description' => 'The newly forked part submission object.',
					'type'        => 'object',
					'properties'  => $part_submission_properties,
				),
				'forked_speech'    => array(
					'description' => 'Details of the forked speech if speech was forked.',
					'type'        => 'object',
					'properties'  => array(
						'speech'   => array(
							'type' => 'object',
						),
						'feedback' => array(
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
			'required'   => array( 'part_submission' ),
		);
	}
}
