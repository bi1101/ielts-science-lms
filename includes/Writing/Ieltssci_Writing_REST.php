<?php

namespace IeltsScienceLMS\Writing;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_REST_Server;

class Ieltssci_Writing_REST {
	private $namespace = 'ieltssci/v1';
	private $base = 'writing';
	private $essay_service;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		$this->essay_service = new Ieltssci_Essay_DB();
	}

	/**
	 * Register routes
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			"/{$this->base}/essays",
			[ 
				[ 
					'methods' => WP_REST_Server::READABLE,
					'callback' => [ $this, 'get_essays' ],
					'permission_callback' => [ $this, 'get_essays_permissions_check' ],
					'args' => $this->get_essays_args(),
				],
				[ 
					'methods' => WP_REST_Server::CREATABLE,
					'callback' => [ $this, 'create_essay' ],
					'permission_callback' => [ $this, 'create_essay_permissions_check' ],
					'args' => $this->get_essay_creation_args(),
				],
			]
		);

		register_rest_route(
			$this->namespace,
			"/{$this->base}/essays/(?P<uuid>[a-zA-Z0-9-]+)",
			[ 
				[ 
					'methods' => WP_REST_Server::READABLE,
					'callback' => [ $this, 'get_essay' ],
					'permission_callback' => [ $this, 'get_essay_permissions_check' ],
					'args' => [ 
						'uuid' => [ 
							'required' => true,
							'validate_callback' => function ($param) {
								return is_string( $param ) && ! empty( $param );
							},
						],
					],
				],
			]
		);
	}

	/**
	 * Check if user has permission to get essays list
	 */
	public function get_essays_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Get arguments for the essays list endpoint
	 */
	public function get_essays_args() {
		return [ 
			'id' => [ 
				'description' => 'Essay ID or array of IDs',
				'type' => [ 'integer', 'array' ],
				'validate_callback' => function ($param) {
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
			],
			'uuid' => [ 
				'description' => 'Essay UUID or array of UUIDs',
				'type' => [ 'string', 'array' ],
				'validate_callback' => function ($param) {
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
			],
			'original_id' => [ 
				'description' => 'Original essay ID or array of IDs',
				'type' => [ 'integer', 'array' ],
				'validate_callback' => function ($param) {
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
			],
			'essay_type' => [ 
				'description' => 'Essay type or array of types',
				'type' => [ 'string', 'array' ],
				'sanitize_callback' => function ($param) {
					if ( is_array( $param ) ) {
						return array_map( 'sanitize_text_field', $param );
					}
					return sanitize_text_field( $param );
				},
			],
			'created_by' => [ 
				'description' => 'User ID or array of user IDs who created the essays',
				'type' => [ 'integer', 'array' ],
				'validate_callback' => function ($param) {
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
			],
			'search' => [ 
				'description' => 'Search term to look for in question or content',
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'after' => [ 
				'description' => 'Get essays after this date',
				'type' => 'string',
				'format' => 'date-time',
			],
			'before' => [ 
				'description' => 'Get essays before this date',
				'type' => 'string',
				'format' => 'date-time',
			],
			'orderby' => [ 
				'description' => 'Field to order results by',
				'type' => 'string',
				'default' => 'id',
				'enum' => [ 'id', 'uuid', 'essay_type', 'created_at', 'created_by' ],
			],
			'order' => [ 
				'description' => 'Order direction',
				'type' => 'string',
				'default' => 'DESC',
				'enum' => [ 'ASC', 'DESC', 'asc', 'desc' ],
			],
			'per_page' => [ 
				'description' => 'Number of essays to return per page',
				'type' => 'integer',
				'default' => 10,
				'minimum' => 1,
				'maximum' => 100,
			],
			'page' => [ 
				'description' => 'Page number',
				'type' => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
		];
	}

	/**
	 * Get essays endpoint
	 * 
	 * @param WP_REST_Request $request The request object
	 * @return WP_REST_Response|WP_Error Response object or error
	 */
	public function get_essays( WP_REST_Request $request ) {
		$args = [];

		// Get parameters that can be directly passed to get_essays
		$direct_params = [ 
			'id', 'uuid', 'original_id', 'essay_type', 'created_by', 'search',
			'orderby', 'order', 'per_page', 'page'
		];

		foreach ( $direct_params as $param ) {
			$value = $request->get_param( $param );
			if ( ! is_null( $value ) ) {
				$args[ $param ] = $value;
			}
		}

		// Handle date query parameters
		$after = $request->get_param( 'after' );
		$before = $request->get_param( 'before' );

		if ( $after || $before ) {
			$args['date_query'] = [];

			if ( $after ) {
				$args['date_query']['after'] = $after;
			}

			if ( $before ) {
				$args['date_query']['before'] = $before;
			}
		}

		try {
			// Get total count for pagination headers
			$count_args = $args;
			$count_args['count'] = true;
			$total = $this->essay_service->get_essays( $count_args );

			if ( is_wp_error( $total ) ) {
				return $total;
			}

			// Get essays with current parameters
			$essays = $this->essay_service->get_essays( $args );

			if ( is_wp_error( $essays ) ) {
				return $essays;
			}

			// Handle empty results properly
			if ( empty( $essays ) ) {
				$essays = [];
			}

			// Calculate pagination values
			$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 10;
			$page = isset( $args['page'] ) ? (int) $args['page'] : 1;
			$total_pages = ceil( $total / $per_page );

			// Build response with pagination headers
			$response = new WP_REST_Response( $essays, 200 );
			$response->header( 'X-WP-Total', (int) $total );
			$response->header( 'X-WP-TotalPages', (int) $total_pages );

			// Add Link header for pagination discovery (HATEOAS)
			$this->add_pagination_headers( $response, [ 
				'page' => $page,
				'total_pages' => $total_pages,
				'per_page' => $per_page
			] );

			return $response;
		} catch (\Exception $e) {
			return new WP_Error(
				'api_error',
				__( 'An error occurred while retrieving essays', 'ielts-science-lms' ),
				[ 
					'status' => 500,
					'message' => $e->getMessage()
				]
			);
		}
	}

	/**
	 * Add pagination Link headers to a response
	 *
	 * @param WP_REST_Response $response The response object
	 * @param array $pagination Pagination information
	 */
	private function add_pagination_headers( WP_REST_Response $response, array $pagination ) {
		$base = rest_url( $this->namespace . '/' . $this->base . '/essays' );
		$request = $this->get_current_request_params();

		$max_pages = $pagination['total_pages'];
		$page = $pagination['page'];

		$links = [];

		// First page
		$first_args = $request;
		$first_args['page'] = 1;
		$links[] = '<' . add_query_arg( $first_args, $base ) . '>; rel="first"';

		// Previous page
		if ( $page > 1 ) {
			$prev_args = $request;
			$prev_args['page'] = $page - 1;
			$links[] = '<' . add_query_arg( $prev_args, $base ) . '>; rel="prev"';
		}

		// Next page
		if ( $max_pages > $page ) {
			$next_args = $request;
			$next_args['page'] = $page + 1;
			$links[] = '<' . add_query_arg( $next_args, $base ) . '>; rel="next"';
		}

		// Last page
		$last_args = $request;
		$last_args['page'] = $max_pages;
		$links[] = '<' . add_query_arg( $last_args, $base ) . '>; rel="last"';

		if ( ! empty( $links ) ) {
			$response->header( 'Link', implode( ', ', $links ) );
		}
	}

	/**
	 * Get current request parameters (excluding pagination ones)
	 * 
	 * @return array Request parameters
	 */
	private function get_current_request_params() {
		$request = [];
		$params = $_GET;

		if ( ! empty( $params ) ) {
			foreach ( $params as $key => $value ) {
				if ( $key !== 'page' ) {
					$request[ $key ] = $value;
				}
			}
		}

		return $request;
	}

	/**
	 * Check if user has permission to create essay
	 */
	public function create_essay_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Check if user has permission to view a specific essay
	 * Anyone can view a specific essay
	 */
	public function get_essay_permissions_check( WP_REST_Request $request ) {
		return true; // Allow anyone to view a specific essay
	}

	/**
	 * Get arguments for the create essay endpoint
	 */
	public function get_essay_creation_args() {
		return [ 
			'uuid' => [ 
				'required' => true,
				'type' => 'string',
				'validate_callback' => function ($param) {
					return is_string( $param ) && ! empty( $param );
				},
			],
			'essay_type' => [ 
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ($param) {
					return is_string( $param ) && ! empty( $param );
				},
			],
			'question' => [ 
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => function ($param) {
					return is_string( $param ) && ! empty( $param );
				},
			],
			'essay_content' => [ 
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => function ($param) {
					return is_string( $param ) && ! empty( $param );
				},
			],
			'original_id' => [ 
				'required' => false,
				'type' => 'integer',
				'validate_callback' => function ($param) {
					return is_numeric( $param ) && $param > 0;
				},
			],
			'ocr_image_ids' => [ 
				'required' => false,
				'type' => 'array',
				'validate_callback' => function ($param) {
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
			],
			'chart_image_ids' => [ 
				'required' => false,
				'type' => 'array',
				'validate_callback' => function ($param) {
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
			],
		];
	}

	/**
	 * Create essay endpoint
	 */
	public function create_essay( WP_REST_Request $request ) {
		$essay_data = [ 
			'uuid' => $request->get_param( 'uuid' ),
			'essay_type' => $request->get_param( 'essay_type' ),
			'question' => $request->get_param( 'question' ),
			'essay_content' => $request->get_param( 'essay_content' ),
			'original_id' => $request->get_param( 'original_id' ),
			'ocr_image_ids' => $request->get_param( 'ocr_image_ids' ),
			'chart_image_ids' => $request->get_param( 'chart_image_ids' ),
			'created_by' => get_current_user_id(),
		];

		$result = $this->essay_service->create_essay( $essay_data );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				$result->get_error_data()
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get specific essay endpoint
	 */
	public function get_essay( WP_REST_Request $request ) {

		$essay = $this->essay_service->get_essays( [ 'uuid' => $request->get_param( 'uuid' ) ] );

		if ( is_wp_error( $essay ) ) {
			return $essay;
		}

		return new WP_REST_Response( $essay, 200 );
	}
}