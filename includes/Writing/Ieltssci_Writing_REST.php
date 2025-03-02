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
					'methods' => WP_REST_Server::CREATABLE,
					'callback' => [ $this, 'create_essay' ],
					'permission_callback' => [ $this, 'create_essay_permissions_check' ],
					'args' => $this->get_essay_creation_args(),
				],
				[ 
					'methods' => WP_REST_Server::READABLE,
					'callback' => [ $this, 'get_essays' ],
					'permission_callback' => [ $this, 'get_essays_permissions_check' ],
					'args' => $this->get_essays_args(),
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
	 * Check if user has permission to create essay
	 */
	public function create_essay_permissions_check( WP_REST_Request $request ) {
		return is_user_logged_in();
	}

	/**
	 * Check if user has permission to view essays
	 * Anyone can view essays
	 */
	public function get_essays_permissions_check( WP_REST_Request $request ) {
		return true; // Allow anyone to view essays
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
	 * Get arguments for the essays list endpoint
	 */
	public function get_essays_args() {
		return [ 
			'page' => [ 
				'default' => 1,
				'type' => 'integer',
				'validate_callback' => function ($param) {
					return is_numeric( $param ) && $param > 0;
				},
			],
			'per_page' => [ 
				'default' => 10,
				'type' => 'integer',
				'validate_callback' => function ($param) {
					return is_numeric( $param ) && $param > 0 && $param <= 100;
				},
			],
			'essay_type' => [ 
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
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
	 * Get essays endpoint
	 */
	public function get_essays( WP_REST_Request $request ) {
		$args = [ 
			'page' => $request->get_param( 'page' ),
			'per_page' => $request->get_param( 'per_page' ),
			'essay_type' => $request->get_param( 'essay_type' ),
			'user_id' => get_current_user_id(),
		];

		$essays = $this->essay_service->get_essays( $args );

		if ( is_wp_error( $essays ) ) {
			return $essays;
		}

		return new WP_REST_Response( $essays, 200 );
	}

	/**
	 * Get specific essay endpoint
	 */
	public function get_essay( WP_REST_Request $request ) {
		$uuid = $request->get_param( 'uuid' );
		$essay = $this->essay_service->get_essay_by_uuid( $uuid );

		if ( is_wp_error( $essay ) ) {
			return $essay;
		}

		return new WP_REST_Response( $essay, 200 );
	}
}