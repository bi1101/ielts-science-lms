<?php
/**
 * IELTS Science LMS - Writing REST API
 *
 * This file contains the REST API endpoints for the writing feature.
 *
 * @package IeltsScienceLMS
 * @subpackage Writing
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Writing;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_REST_Server;

/**
 * Class Ieltssci_Writing_Essay_Controller
 *
 * Handles Essay REST API endpoints for the IELTS Writing module.
 *
 * @package IeltsScienceLMS\Writing
 * @since 1.0.0
 */
class Ieltssci_Writing_Essay_Controller extends WP_REST_Controller {
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
	protected $rest_base = 'writing/essays';

	/**
	 * Essay service instance.
	 *
	 * @var Ieltssci_Essay_DB
	 */
	protected $essay_service;

	/**
	 * Constructor.
	 *
	 * Initializes the REST API routes and services.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		$this->essay_service = new Ieltssci_Essay_DB();
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
					'callback'            => array( $this, 'get_essays' ),
					'permission_callback' => array( $this, 'get_essays_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_essay' ),
					'permission_callback' => array( $this, 'create_essay_permissions_check' ),
					'args'                => $this->get_essay_creation_args(),
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
					'callback'            => array( $this, 'get_essay' ),
					'permission_callback' => array( $this, 'get_essay_permissions_check' ),
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
					'callback'            => array( $this, 'update_essay' ),
					'permission_callback' => array( $this, 'update_essay_permissions_check' ),
					'args'                => $this->get_essay_update_args(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// Register route for updating essay feedback.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/feedback/(?P<uuid>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_essay_feedback' ),
				'permission_callback' => array( $this, 'update_essay_feedback_permissions_check' ),
				'args'                => $this->get_essay_feedback_args(),
			)
		);

		// Register route for updating segment feedback.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/segment-feedback/(?P<uuid>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_segment_feedback' ),
				'permission_callback' => array( $this, 'update_segment_feedback_permissions_check' ),
				'args'                => $this->get_segment_feedback_args(),
			)
		);

		// Register route for forking an essay.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/fork/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'fork_essay' ),
					'permission_callback' => array( $this, 'fork_essay_permissions_check' ),
					'args'                => $this->get_fork_essay_args(),
				),
				'schema' => array( $this, 'get_fork_essay_schema' ),
			)
		);
	}

	/**
	 * Get the JSON schema for the fork essay endpoint.
	 *
	 * @return array The schema for the fork essay response.
	 */
	public function get_fork_essay_schema() {
		// Get the essay item schema properties for reuse.
		$essay_properties = $this->get_item_schema();
		$essay_properties = isset( $essay_properties['properties'] ) ? $essay_properties['properties'] : array();

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'fork_essay',
			'type'       => 'object',
			'properties' => array(
				'essay'            => array(
					'description' => 'The newly forked essay object.',
					'type'        => 'object',
					'properties'  => $essay_properties,
				),
				'copied_segments'  => array(
					'description'          => 'Mapping of original segment IDs to new segment data.',
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'       => 'object',
						'properties' => array(
							'original_id' => array(
								'type' => 'integer',
							),
							'new_id'      => array(
								'type' => 'integer',
							),
							'type'        => array(
								'type' => 'string',
							),
							'order'       => array(
								'type' => 'integer',
							),
							'title'       => array(
								'type' => 'string',
							),
						),
					),
				),
				'segment_feedback' => array(
					'description' => 'Array of copied segment feedback.',
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'                => array(
								'type' => 'integer',
							),
							'segment_id'        => array(
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
				'essay_feedback'   => array(
					'description' => 'Array of copied essay feedback.',
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'                => array(
								'type' => 'integer',
							),
							'essay_id'          => array(
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
			'required'   => array( 'essay' ),
		);
	}


	/**
	 * Check if user has permission to get essays list.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function get_essays_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Get arguments for the essays list endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		// Add custom parameters specific to essays.
		$params['id'] = array(
			'description'       => 'Essay ID or array of IDs',
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
			'description'       => 'Essay UUID or array of UUIDs',
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

		$params['original_id'] = array(
			'description'       => 'Original essay ID or array of IDs',
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

		$params['essay_type'] = array(
			'description'       => 'Essay type or array of types',
			'type'              => array( 'string', 'array' ),
			'sanitize_callback' => function ( $param ) {
				if ( is_array( $param ) ) {
					return array_map( 'sanitize_text_field', $param );
				}
				return sanitize_text_field( $param );
			},
		);

		$params['created_by'] = array(
			'description'       => 'User ID or array of user IDs who created the essays',
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
			'description'       => 'Whether to retrieve all essays regardless of user. Only allowed for managers/administrators.',
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		);

		$params['search'] = array(
			'description'       => 'Search term to look for in question or content',
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);

		$params['after'] = array(
			'description' => 'Get essays after this date',
			'type'        => 'string',
			'format'      => 'date-time',
		);

		$params['before'] = array(
			'description' => 'Get essays before this date',
			'type'        => 'string',
			'format'      => 'date-time',
		);

		$params['include'] = array(
			'description' => 'Include specific fields in the response',
			'type'        => 'array',
			'default'     => array(),
			'items'       => array(
				'type' => 'string',
			),
		);

		return $params;
	}

	/**
	 * Get essays endpoint.
	 *
	 * Retrieves a collection of essays based on provided parameters.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_essays( WP_REST_Request $request ) {
		$args = array();

		// Get parameters that can be directly passed to get_essays.
		$direct_params = array(
			'id',
			'uuid',
			'original_id',
			'essay_type',
			'created_by',
			'search',
			'orderby',
			'order',
			'per_page',
			'page',
			'include',
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

		// Handle include parameter.
		$include = $request->get_param( 'include' );
		if ( ! empty( $include ) && is_array( $include ) ) {
			$args['include'] = array_map( 'sanitize_text_field', $include );
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
					__( 'You do not have permission to view all essays.', 'ielts-science-lms' ),
					array( 'status' => 403 )
				);
			}
			// If permission check passes, we don't add created_by filter, allowing all essays to be returned.
		} elseif ( ! current_user_can( 'manage_options' ) ) {
			// New access control pattern: If not an administrator, restrict to current user's essays only.
			$args['created_by'] = $current_user_id;
			// Administrators can optionally filter by created_by.
		} elseif ( ! empty( $request['created_by'] ) ) {
			$args['created_by'] = (int) $request['created_by'];
		}

		// Get total count for pagination headers.
		$count_args          = $args;
		$count_args['count'] = true;
		$total               = $this->essay_service->get_essays( $count_args );

		if ( is_wp_error( $total ) ) {
			return $total; // Return the WP_Error directly from the DB layer.
		}

		// Get essays with current parameters.
		$essays = $this->essay_service->get_essays( $args );

		if ( is_wp_error( $essays ) ) {
			return $essays; // Return the WP_Error directly from the DB layer.
		}

		// Handle empty results properly.
		if ( empty( $essays ) ) {
			$essays = array();
		}

		// Prepare response data - process each essay.
		$data = array();
		foreach ( $essays as $essay ) {
			$response = $this->prepare_item_for_response( $essay, $request );
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

		// Security check should be added - nonce verification.
		if ( ! empty( $params ) ) {
			foreach ( $params as $key => $value ) {
				if ( 'page' !== $key ) { // Yoda condition fix.
					$request[ $key ] = $value;
				}
			}
		}

		return $request;
	}

	/**
	 * Check if user has permission to create essay.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function create_essay_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Check if user has permission to view a specific essay.
	 * Anyone can view a specific essay.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Always returns true.
	 */
	public function get_essay_permissions_check( WP_REST_Request $request ) {
		return true; // Allow anyone to view a specific essay.
	}

	/**
	 * Check if user has permission to update essay feedback.
	 *
	 * Only checks that the user is logged in. The ownership check happens in the callback.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function update_essay_feedback_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Check if user has permission to update segment feedback.
	 *
	 * Only checks that the user is logged in. The ownership check happens in the callback.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function update_segment_feedback_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Check if user has permission to update essay.
	 *
	 * Only checks that the user is logged in. The ownership check happens in the callback.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function update_essay_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Check if user has permission to fork an essay.
	 *
	 * Only checks that the user is logged in. The ownership check happens in the callback.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the user has permission.
	 */
	public function fork_essay_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Get arguments for the create essay endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_essay_creation_args() {
		return array(
			'uuid'            => array(
				'required'          => false,
				'type'              => 'string',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && ! empty( $param );
				},
			),
			'essay_type'      => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && ! empty( $param );
				},
			),
			'question'        => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && ! empty( $param );
				},
			),
			'essay_content'   => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => function ( $param ) {
					// Allow empty string or null for essay_content.
					return is_null( $param ) || is_string( $param );
				},
			),
			'original_id'     => array(
				'required'          => false,
				'type'              => 'integer',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			),
			'ocr_image_ids'   => array(
				'required'          => false,
				'type'              => 'array',
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
			'chart_image_ids' => array(
				'required'          => false,
				'type'              => 'array',
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
		);
	}

	/**
	 * Get arguments for the update essay endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_essay_update_args() {
		return array(
			'identifier'      => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && ! empty( $param );
				},
			),
			'essay_type'      => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && ! empty( $param );
				},
			),
			'question'        => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && ! empty( $param );
				},
			),
			'essay_content'   => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => function ( $param ) {
					// Allow empty string or null for essay_content.
					return is_null( $param ) || is_string( $param );
				},
			),
			'original_id'     => array(
				'required'          => false,
				'type'              => 'integer',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			),
			'ocr_image_ids'   => array(
				'required'          => false,
				'type'              => 'array',
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
			'chart_image_ids' => array(
				'required'          => false,
				'type'              => 'array',
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
		);
	}

	/**
	 * Get arguments for the fork essay endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_fork_essay_args() {
		return array(
			'id'                    => array(
				'required'          => true,
				'description'       => 'The ID of the essay to fork.',
				'type'              => 'integer',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
				'sanitize_callback' => 'absint',
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
		);
	}

	/**
	 * Create essay endpoint.
	 *
	 * Creates a new essay with the provided data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function create_essay( WP_REST_Request $request ) {
		$essay_data = array(
			'uuid'            => $request->get_param( 'uuid' ),
			'essay_type'      => $request->get_param( 'essay_type' ),
			'question'        => $request->get_param( 'question' ),
			'essay_content'   => $request->get_param( 'essay_content' ),
			'original_id'     => $request->get_param( 'original_id' ),
			'ocr_image_ids'   => $request->get_param( 'ocr_image_ids' ),
			'chart_image_ids' => $request->get_param( 'chart_image_ids' ),
			'created_by'      => get_current_user_id(),
		);

		$result = $this->essay_service->create_essay( $essay_data );

		if ( is_wp_error( $result ) ) {
			return $result; // Return the WP_Error directly from the DB layer.
		}

		// Prepare the response.
		$response = $this->prepare_item_for_response( $result, $request );

		// Set 201 Created status.
		$response->set_status( 201 );

		/**
		 * Fires after an essay is created via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $result  The created essay data.
		 * @param WP_REST_Request $request Request used to create the essay.
		 */
		do_action( 'ieltssci_rest_create_essay', $result, $request );

		return $response;
	}

	/**
	 * Get specific essay endpoint.
	 *
	 * Retrieves a specific essay by UUID or ID.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function get_essay( WP_REST_Request $request ) {
		$identifier = $request->get_param( 'identifier' );

		// Determine if identifier is numeric (ID) or string (UUID).
		if ( is_numeric( $identifier ) ) {
			$essays = $this->essay_service->get_essays( array( 'id' => (int) $identifier ) );
		} else {
			$essays = $this->essay_service->get_essays( array( 'uuid' => $identifier ) );
		}

		if ( is_wp_error( $essays ) ) {
			return $essays; // Return the WP_Error directly from the DB layer.
		}

		if ( empty( $essays ) ) {
			return new WP_Error(
				'essay_not_found',
				__( 'Essay not found.', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		// Prepare the response using the inherited method.
		$response = $this->prepare_item_for_response( $essays[0], $request );

		return $response;
	}

	/**
	 * Update essay endpoint.
	 *
	 * Updates an existing essay with the provided data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function update_essay( WP_REST_Request $request ) {
		$identifier = $request->get_param( 'identifier' );

		// Get the essay to check ownership.
		// Determine if identifier is numeric (ID) or string (UUID).
		if ( is_numeric( $identifier ) ) {
			$essays = $this->essay_service->get_essays( array( 'id' => (int) $identifier ) );
		} else {
			$essays = $this->essay_service->get_essays( array( 'uuid' => $identifier ) );
		}

		if ( is_wp_error( $essays ) ) {
			return $essays; // Return the WP_Error directly from the DB layer.
		}

		if ( empty( $essays ) ) {
			return new WP_Error(
				'essay_not_found',
				__( 'Essay not found.', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		$essay = $essays[0];

		// Check if current user can update this essay (must be owner or admin).
		$current_user_id = get_current_user_id();
		$is_owner        = $current_user_id === (int) $essay['created_by'];
		$is_admin        = current_user_can( 'manage_options' );

		if ( ! $is_owner && ! $is_admin ) {
			return new WP_Error(
				'essay_not_owned',
				__( 'You can only update essays that you created or if you are an administrator.', 'ielts-science-lms' ),
				array( 'status' => 403 )
			);
		}

		// Prepare update data - only include fields that are provided.
		$essay_data = array();

		if ( $request->has_param( 'essay_type' ) ) {
			$essay_data['essay_type'] = $request->get_param( 'essay_type' );
		}

		if ( $request->has_param( 'question' ) ) {
			$essay_data['question'] = $request->get_param( 'question' );
		}

		if ( $request->has_param( 'essay_content' ) ) {
			$essay_data['essay_content'] = $request->get_param( 'essay_content' );
		}

		if ( $request->has_param( 'original_id' ) ) {
			$essay_data['original_id'] = $request->get_param( 'original_id' );
		}

		if ( $request->has_param( 'ocr_image_ids' ) ) {
			$essay_data['ocr_image_ids'] = $request->get_param( 'ocr_image_ids' );
		}

		if ( $request->has_param( 'chart_image_ids' ) ) {
			$essay_data['chart_image_ids'] = $request->get_param( 'chart_image_ids' );
		}

		$result = $this->essay_service->update_essay( $identifier, $essay_data );

		if ( is_wp_error( $result ) ) {
			return $result; // Return the WP_Error directly from the DB layer.
		}

		// Prepare the response.
		$response = $this->prepare_item_for_response( $result, $request );

		/**
		 * Fires after an essay is updated via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $result  The updated essay data.
		 * @param WP_REST_Request $request Request used to update the essay.
		 */
		do_action( 'ieltssci_rest_update_essay', $result, $request );

		return $response;
	}

	/**
	 * Fork essay endpoint.
	 *
	 * Creates a copy of an existing essay including its segments and feedback.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function fork_essay( WP_REST_Request $request ) {
		$essay_id = $request->get_param( 'id' );

		// Get options from request body.
		$options = array(
			'copy_segments'         => $request->get_param( 'copy_segments' ) !== null ? $request->get_param( 'copy_segments' ) : true,
			'copy_segment_feedback' => $request->get_param( 'copy_segment_feedback' ) !== null ? $request->get_param( 'copy_segment_feedback' ) : true,
			'copy_essay_feedback'   => $request->get_param( 'copy_essay_feedback' ) !== null ? $request->get_param( 'copy_essay_feedback' ) : true,
		);

		// Get the essay to check if it exists.
		$essays = $this->essay_service->get_essays( array( 'id' => (int) $essay_id ) );

		if ( is_wp_error( $essays ) ) {
			return $essays; // Return the WP_Error directly from the DB layer.
		}

		if ( empty( $essays ) ) {
			return new WP_Error(
				'essay_not_found',
				__( 'Essay not found.', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		// Anyone can fork any essay - no ownership check needed for forking.
		// The new essay will be owned by the current user.

		// Call the fork method in the database service.
		$result = $this->essay_service->fork_essay( $essay_id, get_current_user_id(), $options );

		if ( is_wp_error( $result ) ) {
			return $result; // Return the WP_Error directly from the DB layer.
		}

		// Prepare the response data with detailed fork information.
		$response_data = array(
			'essay'            => $this->prepare_item_for_response( $result['essay'], $request )->data,
			'copied_segments'  => array(),
			'segment_feedback' => array(),
			'essay_feedback'   => array(),
		);

		// Prepare segment information.
		if ( ! empty( $result['copied_segments'] ) ) {
			foreach ( $result['copied_segments'] as $original_id => $new_segment ) {
				$response_data['copied_segments'][ $original_id ] = array(
					'original_id' => $original_id,
					'new_id'      => $new_segment['id'],
					'type'        => $new_segment['type'],
					'order'       => $new_segment['order'],
					'title'       => $new_segment['title'],
				);
			}
		}

		// Prepare feedback information.
		if ( ! empty( $result['segment_feedback'] ) ) {
			foreach ( $result['segment_feedback'] as $feedback ) {
				$response_data['segment_feedback'][] = array(
					'id'                => $feedback['id'],
					'segment_id'        => $feedback['segment_id'],
					'feedback_criteria' => $feedback['feedback_criteria'],
					'feedback_language' => $feedback['feedback_language'],
					'source'            => $feedback['source'],
				);
			}
		}

		if ( ! empty( $result['essay_feedback'] ) ) {
			foreach ( $result['essay_feedback'] as $feedback ) {
				$response_data['essay_feedback'][] = array(
					'id'                => $feedback['id'],
					'essay_id'          => $feedback['essay_id'],
					'feedback_criteria' => $feedback['feedback_criteria'],
					'feedback_language' => $feedback['feedback_language'],
					'source'            => $feedback['source'],
				);
			}
		}

		// Create response with 201 Created status.
		$response = rest_ensure_response( $response_data );
		$response->set_status( 201 );

		// Add link to the original essay.
		$response->add_link(
			'original-essay',
			rest_url( $this->namespace . '/' . $this->rest_base . '/' . $essay_id ),
			array(
				'embeddable' => true,
			)
		);

		/**
		 * Fires after an essay is forked via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param array           $result  The fork result data.
		 * @param WP_REST_Request $request Request used to fork the essay.
		 */
		do_action( 'ieltssci_rest_fork_essay', $result, $request );

		return $response;
	}

	/**
	 * Update essay feedback endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function update_essay_feedback( WP_REST_Request $request ) {
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
		$essay_db    = new Ieltssci_Essay_DB();
		$feedback_db = new Ieltssci_Writing_Feedback_DB();

		// Get essay details to verify ownership.
		$essays = $essay_db->get_essays(
			array(
				'uuid'     => $uuid,
				'per_page' => 1,
			)
		);

		// Check if essay exists.
		if ( is_wp_error( $essays ) ) {
			return $essays;
		}

		if ( empty( $essays ) ) {
			return new WP_Error(
				'essay_not_found',
				__( 'Essay not found.', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		$essay = $essays[0];

		// Verify ownership.
		if ( get_current_user_id() !== (int) $essay['created_by'] ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to update feedback for this essay.', 'ielts-science-lms' ),
				array( 'status' => 403 )
			);
		}

		// Set up feed array for feedback database function.
		$feed = array(
			'apply_to'          => 'essay',
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
			$result = $feedback_db->save_feedback_to_database(
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
			$result = $feedback_db->save_feedback_to_database(
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

	/**
	 * Update segment feedback endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function update_segment_feedback( WP_REST_Request $request ) {
		// Get parameters.
		$uuid              = $request->get_param( 'uuid' );
		$segment_order     = $request->get_param( 'segment_order' );
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
		$essay_db    = new Ieltssci_Essay_DB();
		$feedback_db = new Ieltssci_Writing_Feedback_DB();

		// Get essay details to verify ownership.
		$essays = $essay_db->get_essays(
			array(
				'uuid'     => $uuid,
				'per_page' => 1,
			)
		);

		// Check if essay exists.
		if ( is_wp_error( $essays ) ) {
			return $essays;
		}

		if ( empty( $essays ) ) {
			return new WP_Error(
				'essay_not_found',
				__( 'Essay not found.', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		$essay = $essays[0];

		// Verify ownership.
		if ( get_current_user_id() !== (int) $essay['created_by'] ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to update feedback for this essay.', 'ielts-science-lms' ),
				array( 'status' => 403 )
			);
		}

		// Get the specific segment.
		$segments = $essay_db->get_segments(
			array(
				'essay_id' => $essay['id'],
				'order'    => $segment_order,
				'number'   => 1,
			)
		);

		if ( is_wp_error( $segments ) ) {
			return $segments;
		}

		if ( empty( $segments ) ) {
			return new WP_Error(
				'segment_not_found',
				__( 'Segment not found for the given segment order.', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		$segment = $segments[0];

		// Set up feed array for feedback database function.
		$feed = array(
			'apply_to'          => $segment['type'],
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
				$segment,
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
				$segment,
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
				$segment,
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
				__( 'Failed to update any segment feedback content.', 'ielts-science-lms' ),
				array(
					'status' => 500,
					'detail' => $errors,
				)
			);
		}

		return new WP_REST_Response(
			array(
				'status'       => 'success',
				'message'      => __( 'Segment feedback updated successfully.', 'ielts-science-lms' ),
				'updated'      => $success,
				'failed'       => $errors,
				'segment_id'   => $segment['id'],
				'segment_type' => $segment['type'],
			),
			200
		);
	}

	/**
	 * Get arguments for the essay feedback endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_essay_feedback_args() {
		return array(
			'uuid'              => array(
				'required'          => true,
				'description'       => 'The UUID of the essay to add/update feedback for.',
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
					return is_string( $param );
				},
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'score_content'     => array(
				'required'          => false,
				'description'       => 'The Score content.',
				'type'              => 'string',
				'validate_callback' => function ( $param ) {
					return is_string( $param );
				},
				'sanitize_callback' => 'sanitize_text_field',
			),
			'feedback_content'  => array(
				'required'          => false,
				'description'       => 'The main Feedback content.',
				'type'              => 'string',
				'validate_callback' => function ( $param ) {
					return is_string( $param );
				},
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);
	}

	/**
	 * Get arguments for the segment feedback endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_segment_feedback_args() {
		return array(
			'uuid'              => array(
				'required'          => true,
				'description'       => 'The UUID of the essay containing the segment.',
				'type'              => 'string',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && ! empty( $param );
				},
				'sanitize_callback' => 'sanitize_text_field',
			),
			'segment_order'     => array(
				'required'          => true,
				'description'       => 'The order/index of the segment within the essay.',
				'type'              => 'integer',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && intval( $param ) > 0;
				},
				'sanitize_callback' => 'absint',
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
		);
	}

	/**
	 * Get the essay schema for REST API responses.
	 *
	 * @since 1.0.0
	 *
	 * @return array The essay schema.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			// Since WordPress 5.3, the schema can be cached in the $schema property.
			return $this->schema;
		}

		$this->schema = array(
			// This tells the spec of JSON Schema we are using which is draft 4.
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			// The title property marks the identity of the resource.
			'title'      => 'essay',
			'type'       => 'object',
			// In JSON Schema you can specify object properties in the properties attribute.
			'properties' => array(
				'id'              => array(
					'description' => 'Unique identifier for the essay.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'uuid'            => array(
					'description' => 'UUID for the essay.',
					'type'        => 'string',
					'format'      => 'uuid',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'original_id'     => array(
					'description' => 'ID of the original essay if this is a fork.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'ocr_image_ids'   => array(
					'description' => 'Array of OCR image attachment IDs.',
					'type'        => 'array',
					'items'       => array(
						'type' => 'integer',
					),
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'chart_image_ids' => array(
					'description' => 'Array of chart image attachment IDs.',
					'type'        => 'array',
					'items'       => array(
						'type' => 'integer',
					),
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'essay_type'      => array(
					'description' => 'Type of the essay (e.g., task-1, task-2).',
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'question'        => array(
					'description' => 'The essay question or prompt.',
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'essay_content'   => array(
					'description' => 'The main content of the essay.',
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'created_by'      => array(
					'description' => 'ID of the user who created the essay.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'created_at'      => array(
					'description' => 'Creation timestamp in ISO 8601 format.',
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'updated_at'      => array(
					'description' => 'Last update timestamp in ISO 8601 format.',
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
			),
		);

		return $this->schema;
	}

	/**
	 * Prepare an essay for REST API response.
	 *
	 * @since 1.0.0
	 *
	 * @param array           $essay   Raw essay data from database.
	 * @param WP_REST_Request $request Current request object.
	 * @return WP_REST_Response Response object with essay data.
	 */
	public function prepare_item_for_response( $essay, $request ) {
		// Prepare basic fields.
		$data = array(
			'id'              => (int) $essay['id'],
			'uuid'            => $essay['uuid'],
			'original_id'     => ! empty( $essay['original_id'] ) ? (int) $essay['original_id'] : null,
			'ocr_image_ids'   => ! empty( $essay['ocr_image_ids'] ) ? $essay['ocr_image_ids'] : array(),
			'chart_image_ids' => ! empty( $essay['chart_image_ids'] ) ? $essay['chart_image_ids'] : array(),
			'essay_type'      => $essay['essay_type'],
			'question'        => $essay['question'],
			'essay_content'   => $essay['essay_content'],
			'created_by'      => (int) $essay['created_by'],
			'created_at'      => ! empty( $essay['created_at'] ) ? mysql_to_rfc3339( $essay['created_at'] ) : null,
			'updated_at'      => ! empty( $essay['updated_at'] ) ? mysql_to_rfc3339( $essay['updated_at'] ) : null,
		);

		// Add context-based field filtering.
		$data = $this->filter_response_by_context( $data, 'view' );

		// Create response object.
		$response = rest_ensure_response( $data );

		// Add embeddable link to the author (user).
		$response->add_link(
			'author',
			rest_url( 'wp/v2/users/' . $essay['created_by'] ),
			array(
				'embeddable' => true,
			)
		);

		// Add embeddable link to the ocr images if available.
		if ( ! empty( $essay['ocr_image_ids'] ) ) {
			foreach ( $essay['ocr_image_ids'] as $image_id ) {
				$response->add_link(
					'ocr_image',
					rest_url( 'wp/v2/media/' . $image_id ),
					array(
						'embeddable' => true,
					)
				);
			}
		}

		// Add embeddable link to the chart images if available.
		if ( ! empty( $essay['chart_image_ids'] ) ) {
			foreach ( $essay['chart_image_ids'] as $image_id ) {
				$response->add_link(
					'chart_image',
					rest_url( 'wp/v2/media/' . $image_id ),
					array(
						'embeddable' => true,
					)
				);
			}
		}

		// Add embeddable link to the original essay if this is a fork.
		if ( ! empty( $essay['original_id'] ) ) {
			$response->add_link(
				'original-essay',
				rest_url( $this->namespace . '/' . $this->rest_base . '/' . $essay['original_id'] ),
				array(
					'embeddable' => true,
				)
			);
		}

		/**
		 * Filter essay data returned from the REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_REST_Response $response The response object.
		 * @param array            $essay    Raw essay data.
		 * @param WP_REST_Request  $request  Request used to generate the response.
		 */
		return apply_filters( 'ieltssci_rest_prepare_essay', $response, $essay, $request );
	}

	/**
	 * Sets up the proper HTTP status code for authorization.
	 *
	 * @since 1.0.0
	 *
	 * @return int HTTP status code.
	 */
	public function authorization_status_code() {
		$status = 401;

		if ( is_user_logged_in() ) {
			$status = 403;
		}

		return $status;
	}
}
