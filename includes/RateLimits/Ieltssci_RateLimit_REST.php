<?php
/**
 * Rate Limit REST API Handler
 *
 * @package IELTS_Science_LMS
 * @subpackage RateLimits
 * @since 1.0.0
 */

namespace IeltsScienceLMS\RateLimits;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_REST_Server;

/**
 * Class Ieltssci_RateLimit_REST
 *
 * Handles REST API endpoints for managing rate limits
 *
 * @package IELTS_Science_LMS\RateLimits
 */
class Ieltssci_RateLimit_REST {
	/**
	 * REST API namespace
	 *
	 * @var string
	 */
	private $namespace = 'ieltssci/v1';

	/**
	 * REST API base
	 *
	 * @var string
	 */
	private $base = 'rate-limits';

	/**
	 * Database handler instance
	 *
	 * @var Ieltssci_RateLimit_DB
	 */
	private $db;

	/**
	 * Constructor
	 *
	 * Initializes the class and sets up hooks
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		$this->db = new Ieltssci_RateLimit_DB();
	}

	/**
	 * Register REST API routes
	 *
	 * Sets up the endpoints for handling rate limit requests
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rate_limits' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_rate_limits' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'rules' => array(
							'required' => true,
							'type'     => 'array',
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'subject'    => array(
										'type'       => 'object',
										'required'   => true,
										'properties' => array(
											'role'    => array( 'type' => 'string' ),
											'apiFeed' => array( 'type' => 'string' ),
										),
									),
									'timePeriod' => array(
										'type'       => 'object',
										'required'   => true,
										'properties' => array(
											'type'   => array( 'type' => 'string' ),
											'count'  => array( 'type' => 'integer' ),
											'unit'   => array( 'type' => 'string' ),
											'period' => array( 'type' => 'string' ),
										),
									),
									'limit'      => array(
										'type'     => 'integer',
										'required' => true,
									),
									'message'    => array(
										'type'       => 'object',
										'required'   => true,
										'properties' => array(
											'title' => array( 'type' => 'string' ),
											'body'  => array( 'type' => 'string' ),
											'cta'   => array( 'type' => 'string' ),
										),
									),
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Check user permission to access endpoints
	 *
	 * @return bool True if user has permission, false otherwise
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get all rate limits
	 *
	 * Retrieves and formats all rate limit rules
	 *
	 * @return WP_REST_Response|WP_Error Response object or error
	 */
	public function get_rate_limits() {
		try {
			$db_rules        = $this->db->get_rate_limits();
			$formatted_rules = array_map( array( $this, 'format_rule_for_response' ), $db_rules );
			return new WP_REST_Response( $formatted_rules, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 500, $e->getMessage() );
		}
	}

	/**
	 * Update rate limits
	 *
	 * Updates rate limit rules based on provided data
	 *
	 * @param WP_REST_Request $request The request object containing rules to update.
	 * @return WP_REST_Response|WP_Error Response object or error
	 */
	public function update_rate_limits( WP_REST_Request $request ) {
		$rules = $request->get_param( 'rules' );
		if ( ! is_array( $rules ) ) {
			return new WP_Error( 400, 'Invalid rules format' );
		}

		try {
			$updated_db_rules = $this->db->update_rate_limits( $rules );
			$formatted_rules  = array_map( array( $this, 'format_rule_for_response' ), $updated_db_rules );
			return new WP_REST_Response( $formatted_rules, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 500, $e->getMessage() );
		}
	}

	/**
	 * Formats a database rule for API response
	 *
	 * @param array $rule The rule data from the database.
	 * @return array Formatted rule for frontend consumption.
	 */
	private function format_rule_for_response( array $rule ) {
		return array(
			'ruleId'     => (int) $rule['id'],
			'subject'    => array(
				'role'    => implode( ',', $rule['roles'] ),
				'apiFeed' => implode( ',', $rule['feed_ids'] ),
			),
			'timePeriod' => array_merge(
				array( 'type' => $rule['time_period_type'] ),
				$rule['limit_rule'] ?? array()
			),
			'limit'      => (int) $rule['rate_limit'],
			'message'    => $rule['message'] ?? array(),
		);
	}
}
