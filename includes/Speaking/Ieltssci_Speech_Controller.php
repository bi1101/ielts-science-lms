<?php
/**
 * IELTS Science LMS - Speaking Speech REST API Controller
 *
 * This file contains the REST API controller endpoints for speech recordings.
 *
 * @package IeltsScienceLMS
 * @subpackage Speaking
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Speaking;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_REST_Server;

/**
 * Class Ieltssci_Speech_Controller
 *
 * Handles Speech REST API endpoints for the IELTS Speaking module.
 *
 * @package IeltsScienceLMS\Speaking
 * @since 1.0.0
 */
class Ieltssci_Speech_Controller extends WP_REST_Controller {
	/**
	 * API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ieltssci/v1';

	/**
	 * API base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'speaking/speeches';

	/**
	 * Speech service instance.
	 *
	 * @var Ieltssci_Speech_DB
	 */
	protected $speech_service;

	/**
	 * Speech feedback service instance.
	 *
	 * @var Ieltssci_Speaking_Feedback_DB
	 */
	protected $feedback_service;

	/**
	 * Constructor.
	 *
	 * Initializes the REST API routes and services.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		$this->speech_service   = new Ieltssci_Speech_DB();
		$this->feedback_service = new Ieltssci_Speaking_Feedback_DB();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_speeches' ),
					'permission_callback' => array( $this, 'get_speeches_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_speech' ),
					'permission_callback' => array( $this, 'create_speech_permissions_check' ),
					'args'                => $this->get_speech_creation_args(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<identifier>[a-zA-Z0-9-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_speech' ),
					'permission_callback' => array( $this, 'get_speech_permissions_check' ),
					'args'                => array(
						'identifier' => array(
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_string( $param ) && ! empty( $param );
							},
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_speech' ),
					'permission_callback' => array( $this, 'update_speech_permissions_check' ),
					'args'                => $this->get_speech_update_args(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// Register route for updating speech feedback.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/feedback/(?P<uuid>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_speech_feedback' ),
				'permission_callback' => array( $this, 'update_speech_feedback_permissions_check' ),
				'args'                => $this->get_speech_feedback_args(),
			)
		);

		// Register route for getting speech feedbacks.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/feedbacks',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_speech_feedbacks' ),
				'permission_callback' => array( $this, 'get_speech_feedbacks_permissions_check' ),
				'args'                => $this->get_speech_feedbacks_collection_params(),
				'schema'              => array( $this, 'get_speech_feedbacks_schema' ),
			)
		);

		// Register route for getting specific speech feedback.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/feedback/(?P<uuid>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_speech_feedback' ),
				'permission_callback' => '__return_true', // Accessible to anyone.
				'args'                => $this->get_single_speech_feedback_args(),
				'schema'              => array( $this, 'get_speech_feedback_schema' ),
			)
		);

		// Register route for forking a speech.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/fork/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'fork_speech' ),
					'permission_callback' => array( $this, 'fork_speech_permissions_check' ),
					'args'                => $this->get_fork_speech_args(),
				),
				'schema' => array( $this, 'get_fork_speech_schema' ),
			)
		);
	}

	/**
	 * Get the JSON schema for the fork speech endpoint.
	 *
	 * @return array The schema for the fork speech response.
	 */
	public function get_fork_speech_schema() {
		$speech_properties = $this->get_item_schema();
		$speech_properties = isset( $speech_properties['properties'] ) ? $speech_properties['properties'] : array();

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'fork_speech',
			'type'       => 'object',
			'properties' => array(
				'speech'          => array(
					'description' => 'The newly forked speech object.',
					'type'        => 'object',
					'properties'  => $speech_properties,
				),
				'speech_feedback' => array(
					'description' => 'Array of copied speech feedback.',
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'                => array(
								'type' => 'integer',
							),
							'speech_id'         => array(
								'type' => 'integer',
							),
							'feedback_criteria' => array(
								'type' => 'string',
							),
							'feedback_language' => array(
								'type' => 'string',
							),
							'source'            => array(
								'type' => 'string',
							),
						),
					),
				),
			),
			'required'   => array( 'speech' ),
		);
	}

	/**
	 * Check if user has permission to get speeches list.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function get_speeches_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Get arguments for the speeches list endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		// Add custom parameters specific to speeches.
		$params['id'] = array(
			'description'       => 'Speech ID or array of IDs',
			'type'              => array( 'integer', 'array' ),
			'validate_callback' => function ( $param ) {
				if ( is_array( $param ) ) {
					foreach ( $param as $id ) {
						if ( ! is_numeric( $id ) || $id <= 0 ) {
							return false;
						}
					}
					return true;
				}
				return is_numeric( $param ) && $param > 0;
			},
		);

		$params['uuid'] = array(
			'description'       => 'Speech UUID or array of UUIDs',
			'type'              => array( 'string', 'array' ),
			'validate_callback' => function ( $param ) {
				if ( is_array( $param ) ) {
					foreach ( $param as $uuid ) {
						if ( ! is_string( $uuid ) || empty( $uuid ) ) {
							return false;
						}
					}
					return true;
				}
				return is_string( $param ) && ! empty( $param );
			},
		);

		$params['created_by'] = array(
			'description'       => 'User ID or array of user IDs who created the speeches',
			'type'              => array( 'integer', 'array' ),
			'validate_callback' => function ( $param ) {
				if ( is_array( $param ) ) {
					foreach ( $param as $id ) {
						if ( ! is_numeric( $id ) || $id <= 0 ) {
							return false;
						}
					}
					return true;
				}
				return is_numeric( $param ) && $param > 0;
			},
		);

		$params['all_entries'] = array(
			'description'       => 'Whether to retrieve all speeches regardless of user. Only allowed for managers/administrators.',
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		);

		$params['after'] = array(
			'description' => 'Get speeches after this date',
			'type'        => 'string',
			'format'      => 'date-time',
		);

		$params['before'] = array(
			'description' => 'Get speeches before this date',
			'type'        => 'string',
			'format'      => 'date-time',
		);

		$params['meta_query'] = array(
			'description' => __( 'Meta query for filtering speeches by meta key and value. Example: {"key":"result_confirmed","value":"1"}', 'ielts-science-lms' ),
			'type'        => 'object',
			'properties'  => array(
				'key'   => array(
					'type'        => 'string',
					'description' => __( 'Meta key to filter by.', 'ielts-science-lms' ),
				),
				'value' => array(
					'type'        => 'string',
					'description' => __( 'Meta value to filter by.', 'ielts-science-lms' ),
				),
			),
			'required'    => false,
		);

		return $params;
	}

	/**
	 * Get speeches endpoint.
	 *
	 * Retrieves a collection of speeches based on provided parameters.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_speeches( WP_REST_Request $request ) {
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
			'meta_query',
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

		// Apply access control based on user permissions.
		$current_user_id = get_current_user_id();
		$all_entries     = $request->get_param( 'all_entries' );

		// Check for backward compatibility with all_entries parameter.
		if ( $all_entries ) {
			// Legacy all_entries parameter logic for backward compatibility.
			if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_others_posts' ) ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'You do not have permission to view all speeches.', 'ielts-science-lms' ),
					array( 'status' => 403 )
				);
			}
			// If permission check passes, we don't add created_by filter, allowing all speeches to be returned.
		} elseif ( ! current_user_can( 'manage_options' ) ) {
			// New access control pattern: If not an administrator, restrict to current user's speeches only.
			$args['created_by'] = $current_user_id;
			// Administrators can optionally filter by created_by.
		} elseif ( ! empty( $request['created_by'] ) ) {
			$args['created_by'] = (int) $request['created_by'];
		}

		// Get total count for pagination headers.
		$count_args          = $args;
		$count_args['count'] = true;
		$total               = $this->speech_service->get_speeches( $count_args );

		if ( is_wp_error( $total ) ) {
			return $total; // Return the WP_Error directly from the DB layer.
		}

		// Get speeches with current parameters.
		$speeches = $this->speech_service->get_speeches( $args );

		if ( is_wp_error( $speeches ) ) {
			return $speeches; // Return the WP_Error directly from the DB layer.
		}

		// Handle empty results properly.
		if ( empty( $speeches ) ) {
			$speeches = array();
		}

		// Prepare response data - process each speech.
		$data = array();
		foreach ( $speeches as $speech ) {
			$response = $this->prepare_item_for_response( $speech, $request );
			$data[]   = $this->prepare_response_for_collection( $response );
		}

		// Calculate pagination values.
		$per_page    = isset( $args['per_page'] ) ? (int) $args['per_page'] : 10;
		$page        = isset( $args['page'] ) ? (int) $args['page'] : 1;
		$total_pages = ceil( $total / $per_page );

		// Build response with pagination headers.
		$response = rest_ensure_response( $data );
		$response->header( 'X-WP-Total', (int) $total );
		$response->header( 'X-WP-TotalPages', (int) $total_pages );

		// Add Link header for pagination discovery (HATEOAS).
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
	 * Add pagination Link headers to a response.
	 *
	 * @param WP_REST_Response $response   The response object.
	 * @param array            $pagination Pagination information.
	 */
	private function add_pagination_headers( WP_REST_Response $response, array $pagination ) {
		$base    = rest_url( $this->namespace . '/' . $this->rest_base );
		$request = $this->get_current_request_params();

		$max_pages = $pagination['total_pages'];
		$page      = $pagination['page'];

		$links = array();

		// First page.
		$first_args         = $request;
		$first_args['page'] = 1;
		$links[]            = '<' . add_query_arg( $first_args, $base ) . '>; rel="first"';

		// Previous page.
		if ( $page > 1 ) {
			$prev_args         = $request;
			$prev_args['page'] = $page - 1;
			$links[]           = '<' . add_query_arg( $prev_args, $base ) . '>; rel="prev"';
		}

		// Next page.
		if ( $max_pages > $page ) {
			$next_args         = $request;
			$next_args['page'] = $page + 1;
			$links[]           = '<' . add_query_arg( $next_args, $base ) . '>; rel="next"';
		}

		// Last page.
		$last_args         = $request;
		$last_args['page'] = $max_pages;
		$links[]           = '<' . add_query_arg( $last_args, $base ) . '>; rel="last"';

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
	 * Check if user has permission to create speech.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function create_speech_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Check if user has permission to view a specific speech.
	 * Anyone can view a specific speech.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Always returns true.
	 */
	public function get_speech_permissions_check( WP_REST_Request $request ) {
		return true; // Allow anyone to view a specific speech.
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
	 * Check if user has permission to update speech.
	 *
	 * Only checks that the user is logged in. The ownership check happens in the callback.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function update_speech_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Check if user has permission to fork a speech.
	 *
	 * Only checks that the user is logged in. The ownership check happens in the callback.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function fork_speech_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Check if user has permission to get speech feedbacks.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function get_speech_feedbacks_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Get arguments for the create speech endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_speech_creation_args() {
		return array(
			'uuid'       => array(
				'required'          => false,
				'description'       => 'UUID for the speech. If not provided, one will be generated.',
				'type'              => 'string',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && ! empty( $param );
				},
			),
			'audio_ids'  => array(
				'required'          => false,
				'type'              => 'array',
				'default'           => array(),
				'description'       => 'Array of audio attachment IDs.',
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
				'description'       => 'Transcript data as an object with attachment IDs as keys.',
				'validate_callback' => function ( $param ) {
					if ( ! is_array( $param ) ) {
						return false;
					}
					return true;
				},
			),
		);
	}

	/**
	 * Get arguments for the update speech endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_speech_update_args() {
		return array(
			'audio_ids'  => array(
				'required'          => false,
				'type'              => 'array',
				'description'       => 'Array of audio attachment IDs.',
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
				'description'       => 'Transcript data as an object with attachment IDs as keys.',
				'validate_callback' => function ( $param ) {
					if ( ! is_array( $param ) ) {
						return false;
					}
					return true;
				},
			),
		);
	}

	/**
	 * Get arguments for speech feedback endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_speech_feedback_args() {
		return array(
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
	 * Get arguments for fork speech endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_fork_speech_args() {
		return array(
			'copy_speech_feedback' => array(
				'type'              => 'boolean',
				'required'          => false,
				'default'           => true,
				'description'       => 'Whether to copy speech feedback.',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Get arguments for single speech feedback endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_single_speech_feedback_args() {
		return array(
			'feedback_criteria' => array(
				'type'              => 'string',
				'required'          => false,
				'description'       => 'Filter by feedback criteria.',
				'sanitize_callback' => 'sanitize_key',
			),
			'language'          => array(
				'type'              => 'string',
				'required'          => false,
				'description'       => 'Filter by feedback language.',
				'sanitize_callback' => 'sanitize_key',
			),
			'source'            => array(
				'type'              => 'string',
				'required'          => false,
				'description'       => 'Filter by feedback source (ai or human).',
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	/**
	 * Get arguments for the speech feedbacks collection endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_speech_feedbacks_collection_params() {
		$params = parent::get_collection_params();

		$params['speech_id'] = array(
			'description'       => 'Speech ID or array of IDs',
			'type'              => array( 'integer', 'array' ),
			'validate_callback' => function ( $param ) {
				if ( is_array( $param ) ) {
					foreach ( $param as $id ) {
						if ( ! is_numeric( $id ) || $id <= 0 ) {
							return false;
						}
					}
					return true;
				}
				return is_numeric( $param ) && $param > 0;
			},
		);

		$params['feedback_criteria'] = array(
			'description'       => 'Feedback criteria or array of criteria',
			'type'              => array( 'string', 'array' ),
			'sanitize_callback' => function ( $param ) {
				if ( is_array( $param ) ) {
					return array_map( 'sanitize_key', $param );
				}
				return sanitize_key( $param );
			},
		);

		$params['feedback_language'] = array(
			'description'       => 'Feedback language or array of languages',
			'type'              => array( 'string', 'array' ),
			'sanitize_callback' => function ( $param ) {
				if ( is_array( $param ) ) {
					return array_map( 'sanitize_key', $param );
				}
				return sanitize_key( $param );
			},
		);

		$params['source'] = array(
			'description'       => 'Feedback source (ai or human) or array of sources',
			'type'              => array( 'string', 'array' ),
			'sanitize_callback' => function ( $param ) {
				if ( is_array( $param ) ) {
					return array_map( 'sanitize_key', $param );
				}
				return sanitize_key( $param );
			},
		);

		$params['is_preferred'] = array(
			'description'       => 'Filter by preferred feedback',
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
		);

		return $params;
	}

	/**
	 * Create speech endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function create_speech( WP_REST_Request $request ) {
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
					'invalid_transcript',
					__( 'Transcript contains IDs not present in audio_ids.', 'ielts-science-lms' ),
					array(
						'status'      => 400,
						'missing_ids' => $missing_ids,
					)
				);
			}
		}

		// Use the dedicated creation method.
		$result = $this->speech_service->create_speech( $speech_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = $this->prepare_item_for_response( $result, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Get speech endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function get_speech( WP_REST_Request $request ) {
		$identifier = $request->get_param( 'identifier' );

		// Try to get by UUID first, then by ID.
		$args = array();
		if ( is_numeric( $identifier ) ) {
			$args['id'] = (int) $identifier;
		} else {
			$args['uuid'] = $identifier;
		}

		$results = $this->speech_service->get_speeches( $args );

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

		$response = $this->prepare_item_for_response( $results[0], $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Update speech endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function update_speech( WP_REST_Request $request ) {
		$identifier = $request->get_param( 'identifier' );

		// Determine if identifier is ID or UUID.
		$where = array();
		if ( is_numeric( $identifier ) ) {
			$where['id'] = (int) $identifier;
		} else {
			$where['uuid'] = $identifier;
		}

		// Get the existing speech to check ownership.
		$existing = $this->speech_service->get_speeches( $where );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( empty( $existing ) ) {
			return new WP_Error(
				'speech_not_found',
				__( 'Speech not found', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		$speech = $existing[0];

		// Verify ownership.
		if ( get_current_user_id() !== (int) $speech['created_by'] ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to update this speech.', 'ielts-science-lms' ),
				array( 'status' => 403 )
			);
		}

		// Prepare update data.
		$update_data = array();
		$audio_ids   = $request->get_param( 'audio_ids' );
		$transcript  = $request->get_param( 'transcript' );

		if ( null !== $audio_ids ) {
			$update_data['audio_ids'] = $audio_ids;
		}

		if ( null !== $transcript ) {
			$update_data['transcript'] = $transcript;
		}

		// Update the speech.
		$result = $this->speech_service->update_speech( $where, $update_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = $this->prepare_item_for_response( $result, $request );

		return rest_ensure_response( $response );
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

		// Get speech details to verify ownership.
		$speeches = $this->speech_service->get_speeches(
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
			$result = $this->feedback_service->save_feedback_to_database(
				$cot_content,
				$feed,
				$uuid,
				'chain-of-thought',
				null,
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
				$uuid,
				'scoring',
				null,
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
				$uuid,
				'feedback',
				null,
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
					'failed' => $errors,
				)
			);
		}

		return rest_ensure_response(
			array(
				'status'  => 'success',
				'message' => __( 'Feedback updated successfully.', 'ielts-science-lms' ),
				'updated' => $success,
				'failed'  => $errors,
			)
		);
	}

	/**
	 * Fork speech endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function fork_speech( WP_REST_Request $request ) {
		$speech_id            = (int) $request->get_param( 'id' );
		$copy_speech_feedback = $request->get_param( 'copy_speech_feedback' );
		$current_user_id      = get_current_user_id();

		// Fork the speech.
		$result = $this->speech_service->fork_speech(
			$speech_id,
			$current_user_id,
			array(
				'copy_speech_feedback' => $copy_speech_feedback,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = $this->prepare_item_for_response( $result, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Get speech feedbacks endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function get_speech_feedbacks( WP_REST_Request $request ) {
		$args = array();

		// Get parameters that can be directly passed to get_speech_feedbacks.
		$direct_params = array(
			'speech_id',
			'feedback_criteria',
			'feedback_language',
			'source',
			'is_preferred',
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

		// Get total count for pagination headers.
		$count_args          = $args;
		$count_args['count'] = true;
		$total               = $this->speech_service->get_speech_feedbacks( $count_args );

		if ( is_wp_error( $total ) ) {
			return $total;
		}

		// Get speech feedbacks with current parameters.
		$feedbacks = $this->speech_service->get_speech_feedbacks( $args );

		if ( is_wp_error( $feedbacks ) ) {
			return $feedbacks;
		}

		// Handle empty results.
		if ( empty( $feedbacks ) ) {
			$feedbacks = array();
		}

		// Calculate pagination values.
		$per_page    = isset( $args['per_page'] ) ? (int) $args['per_page'] : 10;
		$page        = isset( $args['page'] ) ? (int) $args['page'] : 1;
		$total_pages = ceil( $total / $per_page );

		// Build response with pagination headers.
		$response = rest_ensure_response( $feedbacks );
		$response->header( 'X-WP-Total', (int) $total );
		$response->header( 'X-WP-TotalPages', (int) $total_pages );

		return $response;
	}

	/**
	 * Get speech feedback endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function get_speech_feedback( WP_REST_Request $request ) {
		$uuid              = $request->get_param( 'uuid' );
		$feedback_criteria = $request->get_param( 'feedback_criteria' );
		$language          = $request->get_param( 'language' );
		$source            = $request->get_param( 'source' );

		// Get the speech by UUID.
		$speeches = $this->speech_service->get_speeches(
			array(
				'uuid'     => $uuid,
				'per_page' => 1,
			)
		);

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

		// Build query for feedback.
		$feedback_args = array(
			'speech_id' => $speech['id'],
		);

		if ( ! empty( $feedback_criteria ) ) {
			$feedback_args['feedback_criteria'] = $feedback_criteria;
		}

		if ( ! empty( $language ) ) {
			$feedback_args['feedback_language'] = $language;
		}

		if ( ! empty( $source ) ) {
			$feedback_args['source'] = $source;
		}

		// Get feedback.
		$feedbacks = $this->speech_service->get_speech_feedbacks( $feedback_args );

		if ( is_wp_error( $feedbacks ) ) {
			return $feedbacks;
		}

		return rest_ensure_response( $feedbacks );
	}

	/**
	 * Prepare item for response.
	 *
	 * @param array           $item    Speech data.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $item, $request ) {
		$data = $item;

		$response = rest_ensure_response( $data );

		// Add author link so the creator can be embedded with _embed.
		if ( ! empty( $data['created_by'] ) ) {
			$author_id = absint( $data['created_by'] );
			if ( $author_id > 0 ) {
				$response->add_link(
					'author',
					rest_url( 'wp/v2/users/' . $author_id ),
					array( 'embeddable' => true )
				);
			}
		}

		// Add audio file links and speech-attempts links for each audio file associated with the speech.
		// These appear under the _links object and can be embedded with _embed if desired.
		if ( ! empty( $data['audio_ids'] ) && is_array( $data['audio_ids'] ) ) {
			foreach ( $data['audio_ids'] as $audio_id ) {
				$audio_id = absint( $audio_id );
				if ( $audio_id > 0 ) {
					// Link to the audio attachment for embedding the media item.
					$response->add_link(
						'audio_files',
						rest_url( 'wp/v2/media/' . $audio_id ),
						array( 'embeddable' => true )
					);

					// Link to our attempts collection filtered by this audio attachment.
					$response->add_link(
						'speech_attempts',
						add_query_arg(
							array( 'audio_id' => $audio_id ),
							rest_url( 'ieltssci/v1/speech-attempts' )
						),
						array( 'embeddable' => true )
					);
				}
			}
		}

		return $response;
	}

	/**
	 * Get the schema for speech items.
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'speech',
			'type'       => 'object',
			'properties' => array(
				'id'         => array(
					'description' => __( 'Unique identifier for the speech.', 'ielts-science-lms' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'uuid'       => array(
					'description' => __( 'UUID for the speech.', 'ielts-science-lms' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'audio_ids'  => array(
					'description' => __( 'Array of audio attachment IDs.', 'ielts-science-lms' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit', 'embed' ),
					'items'       => array(
						'type' => 'integer',
					),
				),
				'transcript' => array(
					'description' => __( 'Transcript data keyed by attachment ID.', 'ielts-science-lms' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'created_by' => array(
					'description' => __( 'User ID who created the speech.', 'ielts-science-lms' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'created_at' => array(
					'description' => __( 'Speech creation date.', 'ielts-science-lms' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
			),
		);

		$this->schema = $schema;

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Get the schema for speech feedback items.
	 *
	 * @return array Item schema data.
	 */
	public function get_speech_feedback_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'speech_feedback',
			'type'       => 'object',
			'properties' => array(
				'id'                => array(
					'description' => __( 'Unique identifier for the feedback.', 'ielts-science-lms' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'speech_id'         => array(
					'description' => __( 'ID of the associated speech.', 'ielts-science-lms' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'feedback_criteria' => array(
					'description' => __( 'Criteria for the feedback.', 'ielts-science-lms' ),
					'type'        => 'string',
				),
				'feedback_language' => array(
					'description' => __( 'Language of the feedback.', 'ielts-science-lms' ),
					'type'        => 'string',
				),
				'source'            => array(
					'description' => __( 'Source of the feedback (ai or human).', 'ielts-science-lms' ),
					'type'        => 'string',
				),
				'cot_content'       => array(
					'description' => __( 'Chain of thought content.', 'ielts-science-lms' ),
					'type'        => 'string',
				),
				'score_content'     => array(
					'description' => __( 'Score content.', 'ielts-science-lms' ),
					'type'        => 'string',
				),
				'feedback_content'  => array(
					'description' => __( 'Feedback content.', 'ielts-science-lms' ),
					'type'        => 'string',
				),
				'is_preferred'      => array(
					'description' => __( 'Whether this feedback is preferred.', 'ielts-science-lms' ),
					'type'        => 'boolean',
				),
				'created_at'        => array(
					'description' => __( 'Feedback creation date.', 'ielts-science-lms' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Get the schema for speech feedbacks collection.
	 *
	 * @return array Schema data.
	 */
	public function get_speech_feedbacks_schema() {
		return array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title'   => 'speech_feedbacks',
			'type'    => 'array',
			'items'   => $this->get_speech_feedback_schema(),
		);
	}
}
