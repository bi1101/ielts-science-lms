<?php

namespace IeltsScienceLMS\ApiKeys;

class Ieltssci_ApiKeys_REST {
	private $namespace = 'ieltssci/v1';
	private $base      = 'api-keys';
	private Ieltssci_ApiKeys_DB $db;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		$this->db = new Ieltssci_ApiKeys_DB();
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_api_keys' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
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

	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	public function get_api_keys( \WP_REST_Request $request ) {
		try {
			$api_keys = $this->db->get_api_keys();
			return new \WP_REST_Response( $api_keys, 200 );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'api_keys_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function update_api_keys( \WP_REST_Request $request ) {
		$api_keys = $request->get_param( 'apiKeys' );

		try {
			$updated_keys = $this->db->update_api_keys( $api_keys );
			return new \WP_REST_Response( $updated_keys, 200 );
		} catch ( \Exception $e ) {
			return new \WP_Error( 500, $e->getMessage() );
		}
	}
}
