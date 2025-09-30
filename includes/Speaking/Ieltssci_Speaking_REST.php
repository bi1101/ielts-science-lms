<?php
/**
 * IELTS Science LMS - Speaking REST API
 *
 * This file contains the REST API endpoints for the speaking feature.
 *
 * @package IeltsScienceLMS
 * @subpackage Speaking
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Speaking;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_REST_Server;

/**
 * Class Ieltssci_Speaking_REST
 *
 * Handles REST API endpoints for the IELTS Speaking module.
 *
 * @package IeltsScienceLMS\Speaking
 * @since 1.0.0
 */
class Ieltssci_Speaking_REST {
	/**
	 * API namespace.
	 *
	 * @var string
	 */
	private $namespace = 'ieltssci/v1';

	/**
	 * API base path.
	 *
	 * @var string
	 */
	private $base = 'speaking';

	/**
	 * Speech service instance.
	 *
	 * @var Ieltssci_Speech_DB
	 */
	private $speech_service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		$this->speech_service = new Ieltssci_Speech_DB();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Speech recordings endpoints.
		register_rest_route(
			$this->namespace,
			"/{$this->base}/speeches",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_speeches' ),
					'permission_callback' => array( $this, 'get_speeches_permissions_check' ),
					'args'                => $this->get_speeches_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_speech' ),
					'permission_callback' => array( $this, 'create_speech_permissions_check' ),
					'args'                => $this->get_speech_creation_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			"/{$this->base}/speeches/(?P<uuid>[a-zA-Z0-9-]+)",
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_speech' ),
				'permission_callback' => array( $this, 'get_speech_permissions_check' ),
				'args'                => array(
					'uuid' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && ! empty( $param );
						},
					),
				),
			)
		);

		// Register route for updating speech feedback.
		register_rest_route(
			$this->namespace,
			"/{$this->base}/feedback",
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_speech_feedback' ),
				'permission_callback' => array( $this, 'update_speech_feedback_permissions_check' ),
				'args'                => array(
					'uuid'              => array(
						'required'          => true,
						'description'       => 'The UUID of the speech recording to update feedback for.',
						'type'              => 'string',
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && ! empty( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'feedback_criteria' => array(
						'required'          => true,
						'description'       => 'The criteria this feedback relates to.',
						'type'              => 'string',
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && ! empty( $param );
						},
						'sanitize_callback' => 'sanitize_key',
					),
					'language'          => array(
						'required'          => true,
						'description'       => 'The language code for the feedback.',
						'type'              => 'string',
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && ! empty( $param );
						},
						'sanitize_callback' => 'sanitize_key',
					),
					'cot_content'       => array(
						'required'          => false,
						'description'       => 'The Chain of Thought content.',
						'type'              => 'string',
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && ! empty( $param );
						},
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'score_content'     => array(
						'required'          => false,
						'description'       => 'The Score content.',
						'type'              => 'string',
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && ! empty( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'feedback_content'  => array(
						'required'          => false,
						'description'       => 'The main Feedback content.',
						'type'              => 'string',
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && ! empty( $param );
						},
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Check if user has permission to get speeches.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function get_speeches_permissions_check( $request ) {
		return is_user_logged_in();
	}

	/**
	 * Get arguments for the speeches list endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_speeches_args() {
		return array(
			'id'          => array(
				'description' => 'Speech ID or array of IDs',
				'type'        => array( 'integer', 'array' ),
			),
			'uuid'        => array(
				'description' => 'Speech UUID or array of UUIDs',
				'type'        => array( 'string', 'array' ),
			),
			'created_by'  => array(
				'description' => 'User ID or array of user IDs',
				'type'        => array( 'integer', 'array' ),
			),
			'all_entries' => array(
				'description'       => 'Whether to retrieve all speeches regardless of user. Only allowed for managers/administrators.',
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'after'       => array(
				'description' => 'Get speeches after this date',
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'before'      => array(
				'description' => 'Get speeches before this date',
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'orderby'     => array(
				'description' => 'Sort by field',
				'type'        => 'string',
				'default'     => 'id',
				'enum'        => array( 'id', 'uuid', 'created_at', 'created_by' ),
			),
			'order'       => array(
				'description' => 'Sort order',
				'type'        => 'string',
				'default'     => 'DESC',
				'enum'        => array( 'ASC', 'DESC' ),
			),
			'per_page'    => array(
				'description' => 'Items per page',
				'type'        => 'integer',
				'default'     => 10,
				'minimum'     => 1,
				'maximum'     => 100,
			),
			'page'        => array(
				'description' => 'Page number',
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
		);
	}

	/**
	 * Get speeches endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_speeches( $request ) {
		$args = array();

		// Get parameters that can be directly passed to get_speeches.
		$direct_params = array(
			'id',
			'uuid',
			'created_by',
			'orderby',
			'order',
			'per_page',
			'page',
		);

		foreach ( $direct_params as $param ) {
			$value = $request->get_param( $param );
			if ( ! is_null( $value ) ) {
				$args[ $param ] = $value;
			}
		}

		// Handle date query parameters.
		$after  = $request->get_param( 'after' );
		$before = $request->get_param( 'before' );

		if ( $after || $before ) {
			$args['date_query'] = array();

			if ( $after ) {
				$args['date_query']['after'] = $after;
			}

			if ( $before ) {
				$args['date_query']['before'] = $before;
			}
		}

		// Check for all_entries parameter.
		$all_entries = $request->get_param( 'all_entries' );

		// If all_entries is true, check if user has proper permissions.
		if ( $all_entries ) {
			// Check if user has manager/administrator capabilities.
			if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_others_posts' ) ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'You do not have permission to view all speeches.', 'ielts-science-lms' ),
					array( 'status' => 403 )
				);
			}
			// If no UUID or ID is provided and all_entries is not true, filter by current user automatically.
		} elseif ( ! isset( $args['uuid'] ) && ! isset( $args['id'] ) ) {
			$args['created_by'] = get_current_user_id();
		}

		// Get total count for pagination headers.
		$count_args          = $args;
		$count_args['count'] = true;
		$total               = $this->speech_service->get_speeches( $count_args );

		if ( is_wp_error( $total ) ) {
			return $total;
		}

		// Get speeches with current parameters.
		$speeches = $this->speech_service->get_speeches( $args );

		if ( is_wp_error( $speeches ) ) {
			return $speeches;
		}

		// Handle empty results.
		if ( empty( $speeches ) ) {
			$speeches = array();
		}

		// Calculate pagination values.
		$per_page    = isset( $args['per_page'] ) ? (int) $args['per_page'] : 10;
		$page        = isset( $args['page'] ) ? (int) $args['page'] : 1;
		$total_pages = ceil( $total / $per_page );

		// Build response with pagination headers.
		$response = new WP_REST_Response( $speeches, 200 );
		$response->header( 'X-WP-Total', (int) $total );
		$response->header( 'X-WP-TotalPages', (int) $total_pages );

		// Add pagination headers.
		$this->add_pagination_headers(
			$response,
			array(
				'page'        => $page,
				'total_pages' => $total_pages,
				'per_page'    => $per_page,
			)
		);

		return $response;
	}

	/**
	 * Check if user has permission to create speech.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function create_speech_permissions_check( $request ) {
		return is_user_logged_in();
	}

	/**
	 * Get arguments for speech creation.
	 *
	 * @return array Argument definitions.
	 */
	public function get_speech_creation_args() {
		return array(
			'uuid'       => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && ! empty( $param );
				},
			),
			'audio_ids'  => array(
				'required'          => false,
				'type'              => 'array',
				'default'           => array(),
				'validate_callback' => function ( $param ) {
					if ( ! is_array( $param ) ) {
						return false;
					}
					foreach ( $param as $id ) {
						if ( ! is_numeric( $id ) || $id <= 0 ) {
							return false;
						}
					}
					return true;
				},
			),
			'transcript' => array(
				'type'              => 'object',
				'required'          => false,
				'validate_callback' => function ( $param ) {
					if ( ! is_array( $param ) ) {
						return false;
					}

					// Validate that each key is a numeric ID and each value is valid.
					foreach ( $param as $key => $value ) {
						if ( ! is_numeric( $key ) ) {
							return false;
						}

						// Basic validation - can be enhanced based on expected transcript structure.
						if ( empty( $value ) ) {
							return false;
						}
					}
					return true;
				},
				'description'       => 'Transcript data as an object with attachment IDs as keys',
			),
		);
	}

	/**
	 * Create speech endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function create_speech( $request ) {
		$speech_data = array(
			'uuid'       => $request->get_param( 'uuid' ),
			'audio_ids'  => $request->get_param( 'audio_ids' ),
			'transcript' => $request->get_param( 'transcript' ),
			'created_by' => get_current_user_id(),
		);

		// Validate that transcript keys match audio IDs if transcript is provided.
		if ( ! empty( $speech_data['transcript'] ) ) {
			$audio_ids      = $speech_data['audio_ids'];
			$transcript_ids = array_map( 'intval', array_keys( $speech_data['transcript'] ) );

			$missing_ids = array_diff( $transcript_ids, $audio_ids );
			if ( ! empty( $missing_ids ) ) {
				return new WP_Error(
					'invalid_transcript_ids',
					sprintf(
						// translators: %s: Comma-separated list of missing transcript IDs.
						__( 'Transcript contains IDs that are not in the audio_ids list: %s', 'ielts-science-lms' ),
						implode( ', ', $missing_ids )
					),
					array( 'status' => 400 )
				);
			}
		}

		// Use the new dedicated creation method.
		$result = $this->speech_service->create_speech( $speech_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Check if user has permission to view speech.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Always returns true as speeches are public.
	 */
	public function get_speech_permissions_check( $request ) {
		return true; // Speeches are publicly viewable.
	}

	/**
	 * Get speech endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function get_speech( $request ) {
		$uuid    = $request->get_param( 'uuid' );
		$results = $this->speech_service->get_speeches( array( 'uuid' => $uuid ) );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		if ( empty( $results ) ) {
			return new WP_Error(
				'speech_not_found',
				__( 'Speech not found', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $results[0], 200 );
	}

	/**
	 * Add pagination Link headers to a response.
	 *
	 * @param WP_REST_Response $response   The response object.
	 * @param array            $pagination Pagination information.
	 */
	private function add_pagination_headers( WP_REST_Response $response, array $pagination ) {
		$base    = rest_url( $this->namespace . '/' . $this->base . '/speeches' );
		$request = $this->get_current_request_params();

		$max_pages = $pagination['total_pages'];
		$page      = $pagination['page'];

		$links = array();

		if ( $page > 1 ) {
			$prev_args         = $request;
			$prev_args['page'] = $page - 1;
			$links[]           = '<' . add_query_arg( $prev_args, $base ) . '>; rel="prev"';
		}

		if ( $max_pages > $page ) {
			$next_args         = $request;
			$next_args['page'] = $page + 1;
			$links[]           = '<' . add_query_arg( $next_args, $base ) . '>; rel="next"';
		}

		if ( ! empty( $links ) ) {
			$response->header( 'Link', implode( ', ', $links ) );
		}
	}

	/**
	 * Get current request parameters (excluding pagination ones).
	 *
	 * @return array Request parameters.
	 */
	private function get_current_request_params() {
		$request = array();
		$params  = $_GET;

		if ( ! empty( $params ) ) {
			foreach ( $params as $key => $value ) {
				if ( 'page' !== $key ) {
					$request[ $key ] = $value;
				}
			}
		}

		return $request;
	}

	/**
	 * Check if user has permission to update speech feedback.
	 *
	 * Only checks that the user is logged in. The ownership check happens in the callback.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function update_speech_feedback_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Update speech feedback endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function update_speech_feedback( WP_REST_Request $request ) {
		// Get parameters.
		$uuid              = $request->get_param( 'uuid' );
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

		// Initialize required DB handlers.
		$speech_db   = $this->speech_service;
		$feedback_db = new Ieltssci_Speaking_Feedback_DB();

		// Get speech details to verify ownership.
		$speeches = $speech_db->get_speeches(
			array(
				'uuid'     => $uuid,
				'per_page' => 1,
			)
		);

		// Check if speech exists.
		if ( is_wp_error( $speeches ) ) {
			return $speeches;
		}

		if ( empty( $speeches ) ) {
			return new WP_Error(
				'speech_not_found',
				__( 'Speech not found.', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		$speech = $speeches[0];

		// Verify ownership.
		if ( get_current_user_id() !== (int) $speech['created_by'] ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to update feedback for this speech.', 'ielts-science-lms' ),
				array( 'status' => 403 )
			);
		}

		// Set up feed array for feedback database function.
		$feed = array(
			'feedback_criteria' => $feedback_criteria,
		);

		$success = array();
		$errors  = array();

		// Process each content type if provided.
		if ( ! empty( $cot_content ) ) {
			$result = $feedback_db->save_feedback_to_database(
				$cot_content,
				$feed,
				$uuid,
				'chain-of-thought',
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
			$result = $feedback_db->save_feedback_to_database(
				$score_content,
				$feed,
				$uuid,
				'scoring',
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
			$result = $feedback_db->save_feedback_to_database(
				$feedback_content,
				$feed,
				$uuid,
				'feedback',
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
				__( 'Failed to update any feedback content.', 'ielts-science-lms' ),
				array(
					'status' => 500,
					'detail' => $errors,
				)
			);
		}

		return new WP_REST_Response(
			array(
				'status'  => 'success',
				'message' => __( 'Feedback updated successfully.', 'ielts-science-lms' ),
				'updated' => $success,
				'failed'  => $errors,
			),
			200
		);
	}
}
