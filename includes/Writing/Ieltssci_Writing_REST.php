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

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_REST_Server;

/**
 * Class Ieltssci_Writing_REST
 *
 * Handles REST API endpoints for the IELTS Writing module.
 *
 * @package IeltsScienceLMS\Writing
 * @since 1.0.0
 */
class Ieltssci_Writing_REST {
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
	private $base = 'writing';

	/**
	 * Essay service instance.
	 *
	 * @var Ieltssci_Essay_DB
	 */
	private $essay_service;

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
			"/{$this->base}/essays",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_essays' ),
					'permission_callback' => array( $this, 'get_essays_permissions_check' ),
					'args'                => $this->get_essays_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_essay' ),
					'permission_callback' => array( $this, 'create_essay_permissions_check' ),
					'args'                => $this->get_essay_creation_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			"/{$this->base}/essays/(?P<uuid>[a-zA-Z0-9-]+)",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_essay' ),
					'permission_callback' => array( $this, 'get_essay_permissions_check' ),
					'args'                => array(
						'uuid' => array(
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_string( $param ) && ! empty( $param );
							},
						),
					),
				),
			)
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
	public function get_essays_args() {
		return array(
			'id'          => array(
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
			),
			'uuid'        => array(
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
			),
			'original_id' => array(
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
			),
			'essay_type'  => array(
				'description'       => 'Essay type or array of types',
				'type'              => array( 'string', 'array' ),
				'sanitize_callback' => function ( $param ) {
					if ( is_array( $param ) ) {
						return array_map( 'sanitize_text_field', $param );
					}
					return sanitize_text_field( $param );
				},
			),
			'created_by'  => array(
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
			),
			'search'      => array(
				'description'       => 'Search term to look for in question or content',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'after'       => array(
				'description' => 'Get essays after this date',
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'before'      => array(
				'description' => 'Get essays before this date',
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'orderby'     => array(
				'description' => 'Field to order results by',
				'type'        => 'string',
				'default'     => 'id',
				'enum'        => array( 'id', 'uuid', 'essay_type', 'created_at', 'created_by' ),
			),
			'order'       => array(
				'description' => 'Order direction',
				'type'        => 'string',
				'default'     => 'DESC',
				'enum'        => array( 'ASC', 'DESC', 'asc', 'desc' ),
			),
			'per_page'    => array(
				'description' => 'Number of essays to return per page',
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
	 * Get essays endpoint.
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

		// Calculate pagination values.
		$per_page    = isset( $args['per_page'] ) ? (int) $args['per_page'] : 10;
		$page        = isset( $args['page'] ) ? (int) $args['page'] : 1;
		$total_pages = ceil( $total / $per_page );

		// Build response with pagination headers.
		$response = new WP_REST_Response( $essays, 200 );
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
		$base    = rest_url( $this->namespace . '/' . $this->base . '/essays' );
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
	 * Get arguments for the create essay endpoint.
	 *
	 * @return array Argument definitions.
	 */
	public function get_essay_creation_args() {
		return array(
			'uuid'            => array(
				'required'          => true,
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
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && ! empty( $param );
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
	 * Create essay endpoint.
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
			'chart_image_ids' => $request->get_param( 'chart_media_ids' ),
			'created_by'      => get_current_user_id(),
		);

		$result = $this->essay_service->create_essay( $essay_data );

		if ( is_wp_error( $result ) ) {
			return $result; // Return the WP_Error directly from the DB layer.
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get specific essay endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function get_essay( WP_REST_Request $request ) {
		$uuid   = $request->get_param( 'uuid' );
		$essays = $this->essay_service->get_essays( array( 'uuid' => $uuid ) );

		if ( is_wp_error( $essays ) ) {
			return $essays; // Return the WP_Error directly from the DB layer.
		}

		if ( empty( $essays ) ) {
			return new WP_Error(
				'essay_not_found',
				__( 'Essay not found', 'ielts-science-lms' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $essays[0], 200 );
	}
}
