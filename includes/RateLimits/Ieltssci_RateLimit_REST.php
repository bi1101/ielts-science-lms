<?php

namespace IeltsScienceLMS\RateLimits;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_REST_Server;

class Ieltssci_RateLimit_REST {
	private $namespace = 'ieltssci/v1';
	private $base = 'rate-limits';
	private Ieltssci_RateLimit_DB $db;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		$this->db = new Ieltssci_RateLimit_DB();
	}

	public function register_routes() {
		register_rest_route( $this->namespace, '/' . $this->base, [ 
			[ 
				'methods' => WP_REST_Server::READABLE,
				'callback' => [ $this, 'get_rate_limits' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
			[ 
				'methods' => WP_REST_Server::EDITABLE,
				'callback' => [ $this, 'update_rate_limits' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args' => [ 
					'rules' => [ 
						'required' => true,
						'type' => 'array',
						'items' => [ 
							'type' => 'object',
							'properties' => [ 
								'subject' => [ 
									'type' => 'object',
									'required' => true,
									'properties' => [ 
										'role' => [ 'type' => 'string' ],
										'apiFeed' => [ 'type' => 'string' ],
									],
								],
								'timePeriod' => [ 
									'type' => 'object',
									'required' => true,
									'properties' => [ 
										'type' => [ 'type' => 'string' ],
										'count' => [ 'type' => 'integer' ],
										'unit' => [ 'type' => 'string' ],
										'period' => [ 'type' => 'string' ],
									],
								],
								'limit' => [ 
									'type' => 'integer',
									'required' => true,
								],
								'message' => [ 
									'type' => 'object',
									'required' => true,
									'properties' => [ 
										'title' => [ 'type' => 'string' ],
										'body' => [ 'type' => 'string' ],
										'cta' => [ 'type' => 'string' ],
									],
								],
							],
						],
					],
				],
			],
		] );
	}

	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	public function get_rate_limits( WP_REST_Request $request ) {
		try {
			$db_rules = $this->db->get_rate_limits();
			$formatted_rules = array_map( [ $this, 'format_rule_for_response' ], $db_rules );
			return new WP_REST_Response( $formatted_rules, 200 );
		} catch (\Exception $e) {
			return new WP_Error( 500, $e->getMessage() );
		}
	}

	public function update_rate_limits( WP_REST_Request $request ) {
		$rules = $request->get_param( 'rules' );
		if ( ! is_array( $rules ) ) {
			return new WP_Error( 400, 'Invalid rules format' );
		}

		try {
			$updated_db_rules = $this->db->update_rate_limits( $rules );
			$formatted_rules = array_map( [ $this, 'format_rule_for_response' ], $updated_db_rules );
			return new WP_REST_Response( $formatted_rules, 200 );
		} catch (\Exception $e) {
			return new WP_Error( 500, $e->getMessage() );
		}
	}

	/**
	 * Formats a database rule for API response
	 * 
	 * @param array $rule The rule data from the database
	 * @return array Formatted rule for frontend consumption
	 */
	private function format_rule_for_response( array $rule ) {
		return [ 
			'ruleId' => (int) $rule['id'],
			'subject' => [ 
				'role' => implode( ',', $rule['roles'] ),
				'apiFeed' => implode( ',', $rule['feed_ids'] ),
			],
			'timePeriod' => array_merge(
				[ 'type' => $rule['time_period_type'] ],
				$rule['limit_rule'] ?? []
			),
			'limit' => (int) $rule['rate_limit'],
			'message' => $rule['message'] ?? [],
		];
	}
}