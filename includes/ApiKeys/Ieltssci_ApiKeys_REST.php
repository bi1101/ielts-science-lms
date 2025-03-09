<?php
/**
 * REST API handler for API Keys
 *
 * @package IELTS_Science_LMS
 * @subpackage ApiKeys
 */

namespace IeltsScienceLMS\ApiKeys;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_REST_Server;

/**
 * Handles REST API endpoints for API Keys management
 */
class Ieltssci_ApiKeys_REST {
	/**
	 * API namespace
	 *
	 * @var string
	 */
	private $namespace = 'ieltssci/v1';

	/**
	 * API endpoint base
	 *
	 * @var string
	 */
	private $base = 'api-keys';

	/**
	 * Database handler instance
	 *
	 * @var Ieltssci_ApiKeys_DB
	 */
	private $db;

	/**
	 * Constructor
	 *
	 * Registers REST API routes
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		$this->db = new Ieltssci_ApiKeys_DB();
	}

	/**
	 * Register the REST API routes
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_api_keys' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_api_keys' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'apiKeys' => array(
							'required'   => true,
							'type'       => 'object',
							'properties' => array(
								'*' => array(
									'type'       => 'object',
									'properties' => array(
										'keys' => array(
											'type'  => 'array',
											'items' => array(
												'type' => 'object',
												'properties' => array(
													'id'   => array( 'type' => 'integer' ),
													'meta' => array( 'type' => 'object' ),
													'usage_count' => array( 'type' => 'integer' ),
												),
											),
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
	 * Check if current user has permission to access endpoints
	 *
	 * @return bool Whether user has permission
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get all API keys
	 *
	 * @return WP_REST_Response|WP_Error Response with API keys or error
	 */
	public function get_api_keys() {
		try {
			$api_keys = $this->db->get_api_keys();
			return new WP_REST_Response( $api_keys, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'api_keys_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update API keys
	 *
	 * @param WP_REST_Request $request The request object containing API keys.
	 * @return WP_REST_Response|WP_Error Response with updated keys or error
	 */
	public function update_api_keys( $request ) {
		$api_keys = $request->get_param( 'apiKeys' );

		try {
			$updated_keys = $this->db->update_api_keys( $api_keys );
			return new WP_REST_Response( $updated_keys, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 500, $e->getMessage() );
		}
	}
}
