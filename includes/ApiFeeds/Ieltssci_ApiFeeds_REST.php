<?php

namespace IeltsScienceLMS\ApiFeeds;

use IeltsScienceLMS\Settings\Ieltssci_Settings_Config;

class Ieltssci_ApiFeeds_REST {
	private $namespace = 'ieltssci/v1';
	private $base = 'settings';
	private $db;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		$this->db = new Ieltssci_ApiFeeds_DB();
	}

	public function register_routes() {
		register_rest_route( $this->namespace, '/' . $this->base, [ 
			[ 
				'methods' => \WP_REST_Server::READABLE,
				'callback' => [ $this, 'get_settings' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args' => [ 
					'tab' => [ 
						'required' => false,
						'type' => 'string',
						'sanitize_callback' => 'sanitize_key', // Important for security
					],
					'type' => [ 
						'required' => false,
						'type' => 'string',
						'sanitize_callback' => 'sanitize_key', // Important for security
					],
				],
			],
			[ 
				'methods' => \WP_REST_Server::EDITABLE,
				'callback' => [ $this, 'update_settings' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args' => [ 
					'settings' => [ 
						'required' => true,
						'type' => 'object',
					],
					'tab' => [ 
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => 'sanitize_key', // Important for security
					],
					'type' => [ 
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => 'sanitize_key', // Important for security
					],
				],
			],
		] );
	}

	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Retrieves settings based on the specified tab and type parameters.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The REST request object containing tab and type parameters.
	 * 
	 * @return \WP_REST_Response|\WP_Error Returns a WP_REST_Response object with settings data and 200 status code
	 *                                   on success, or WP_Error on failure.
	 */
	public function get_settings( \WP_REST_Request $request ) {
		// Get the 'tab' parameter from the request.
		$tab = $request->get_param( 'tab' );
		$type = $request->get_param( 'type' );

		$result = match ( $type ) {
			'api-feeds' => $this->get_api_feed_settings( $tab ),
			default => new \WP_Error( 400, 'Invalid type.' ),
		};

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}


	public function get_api_feed_settings( string $tab ): array {
		// Get settings configuration
		$settings_config = ( new Ieltssci_Settings_Config() )->get_settings_config( $tab );
		if ( empty( $settings_config ) ) {
			return [];
		}

		// Get feeds from database
		$db_feeds = $this->db->get_all_api_feeds_settings();

		// Create a map of DB feeds by feedback_criteria for easy lookup
		$db_feeds_map = [];
		foreach ( $db_feeds as $feed ) {
			$criteria = $feed['feedback_criteria'];
			if ( ! isset( $db_feeds_map[ $criteria ] ) ) {
				$db_feeds_map[ $criteria ] = [ 
					'id' => $feed['id'],
					'feedName' => $criteria,
					'feedTitle' => $feed['feed_title'],
					'feedDesc' => $feed['feed_desc'],
					'applyTo' => $feed['apply_to'],
					'essayType' => [],
					'steps' => []
				];

				// Parse meta for steps data
				if ( ! empty( $feed['meta'] ) ) {
					$meta = json_decode( $feed['meta'], true );
					if ( isset( $meta['steps'] ) ) {
						$db_feeds_map[ $criteria ]['steps'] = $meta['steps'];
					}
				}
			}

			// Add essay type if exists
			if ( ! empty( $feed['essay_type'] ) ) {
				$db_feeds_map[ $criteria ]['essayType'][] = $feed['essay_type'];
			}
		}

		// Build result using config structure and merge with DB data
		$result = [];
		foreach ( $settings_config as $group ) {
			$group_feeds = [];

			foreach ( $group['feeds'] as $config_feed ) {
				$feedName = $config_feed['feedName'];

				// Start with config feed structure
				$feed = [ 
					'id' => null,
					'feedName' => $feedName,
					'feedTitle' => $config_feed['feedTitle'],
					'feedDesc' => $config_feed['feedDesc'] ?? null,
					'applyTo' => $config_feed['applyTo'],
					'essayType' => $config_feed['essayType'],
					'steps' => []
				];

				// If DB data exists for this feed, merge it
				if ( isset( $db_feeds_map[ $feedName ] ) ) {
					$db_feed = $db_feeds_map[ $feedName ];
					$feed['id'] = $db_feed['id'];
					$feed['feedTitle'] = $db_feed['feedTitle'];
					$feed['feedDesc'] = $db_feed['feedDesc'];
					$feed['essayType'] = $db_feed['essayType'];
				}

				// Process steps and their fields
				foreach ( $config_feed['steps'] as $step ) {
					$processed_step = [ 
						'step' => $step['step'],
						'sections' => []
					];

					foreach ( $step['sections'] as $section ) {
						$processed_section = [ 
							'section' => $section['section'],
							'fields' => []
						];

						foreach ( $section['fields'] as $field ) {
							// Look for DB value in steps data
							$value = null;
							if ( isset( $db_feeds_map[ $feedName ]['steps'] ) ) {
								foreach ( $db_feeds_map[ $feedName ]['steps'] as $db_step ) {
									if ( $db_step['step'] === $step['step'] ) {
										foreach ( $db_step['sections'] as $db_section ) {
											if ( $db_section['section'] === $section['section'] ) {
												foreach ( $db_section['fields'] as $db_field ) {
													if ( $db_field['id'] === $field['id'] ) {
														$value = $db_field['value'];
														break;
													}
												}
											}
										}
									}
								}
							}

							// Use DB value if exists, otherwise use default from config
							$processed_section['fields'][] = [ 
								'id' => $field['id'],
								'value' => $value ?? $field['default']
							];
						}

						$processed_step['sections'][] = $processed_section;
					}

					$feed['steps'][] = $processed_step;
				}

				$group_feeds[] = $feed;
			}

			$result[] = [ 
				'groupName' => $group['groupName'],
				'groupTitle' => $group['groupTitle'],
				'feeds' => $group_feeds
			];
		}

		return $result;
	}

	public function update_settings( \WP_REST_Request $request ) {
		// Validate request parameters
		$type = $request->get_param( 'type' );
		$settings = $request->get_param( 'settings' );
		$tab = $request->get_param( 'tab' );

		if ( empty( $type ) ) {
			return new \WP_Error( 400, 'No type provided.' );
		}

		if ( empty( $settings ) ) {
			return new \WP_Error( 400, 'No settings provided.' );
		}

		if ( empty( $tab ) ) {
			return new \WP_Error( 400, 'No tab provided.' );
		}

		// Process based on type
		$result = match ( $type ) {
			'api-feeds' => $this->update_api_feed_settings( $settings, $tab ),
			default => new \WP_Error( 400, 'Invalid type.' ),
		};

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	private function update_api_feed_settings( $settings, $tab ) {
		if ( ! isset( $settings ) || ! is_array( $settings ) ) {
			return new \WP_Error( '400', 'Invalid settings format for API feeds.' );
		}

		$this->db->start_transaction();

		try {
			// Process each group and its feeds
			foreach ( $settings as $group ) {
				foreach ( $group['feeds'] as $feed ) {
					// Update or insert feed
					$feed_id = $this->db->update_feed( $feed );

					// Update essay types
					$this->db->update_essay_types( $feed_id, $feed['essayType'] );
				}
			}

			$this->db->commit();

			// Get and return updated settings
			$updated_settings = $this->get_api_feed_settings( $tab );
			return $updated_settings;

		} catch (\Exception $e) {
			$this->db->rollback();
			return new \WP_Error( 400, $e->getMessage() );
		}
	}

}