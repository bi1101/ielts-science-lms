<?php

namespace IeltsScienceLMS\ApiFeeds;

use IeltsScienceLMS\Settings\Ieltssci_Settings_Config;

class Ieltssci_ApiFeeds_REST {
	private $namespace = 'ieltssci/v1';
	private $base      = 'settings';
	private $db;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		$this->db = new Ieltssci_ApiFeeds_DB();
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'tab'  => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key', // Important for security
						),
						'type' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key', // Important for security
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'settings' => array(
							'required' => true,
							'type'     => 'object',
						),
						'tab'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key', // Important for security
						),
						'type'     => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key', // Important for security
						),
					),
				),
			)
		);
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
		$tab  = $request->get_param( 'tab' );
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
			return array();
		}

		// Get feeds from database using the more flexible get_api_feeds method
		$db_feeds = $this->db->get_api_feeds(
			array(
				'limit'   => 500, // Set a high limit to ensure we get all feeds
				'include' => array( 'essay_types', 'meta' ), // Include necessary related data
			)
		);

		// Create a map of DB feeds by feedback_criteria for easy lookup
		$db_feeds_map = array();
		foreach ( $db_feeds as $feed ) {
			$criteria = $feed['feedback_criteria'];
			if ( ! isset( $db_feeds_map[ $criteria ] ) ) {
				$db_feeds_map[ $criteria ] = array(
					'id'        => $feed['id'],
					'feedName'  => $criteria,
					'feedTitle' => $feed['feed_title'],
					'feedDesc'  => $feed['feed_desc'],
					'applyTo'   => $feed['apply_to'],
					'essayType' => array(),
					'steps'     => array(),
				);

				// Parse meta for steps data
				if ( isset( $feed['meta'] ) ) {
					$meta = json_decode( $feed['meta'], true );
					if ( isset( $meta['steps'] ) ) {
						$db_feeds_map[ $criteria ]['steps'] = $meta['steps'];
					}
				}
			}

			// Get essay types from related essay_types data
			if ( ! empty( $feed['essay_types'] ) ) {
				foreach ( $feed['essay_types'] as $essay_type ) {
					if ( ! in_array( $essay_type['essay_type'], $db_feeds_map[ $criteria ]['essayType'] ) ) {
						$db_feeds_map[ $criteria ]['essayType'][] = $essay_type['essay_type'];
					}
				}
			}
		}

		// Build result using config structure and merge with DB data
		$result = array();
		foreach ( $settings_config as $group ) {
			$group_feeds = array();

			foreach ( $group['feeds'] as $config_feed ) {
				$feedName = $config_feed['feedName'];

				// Start with config feed structure
				$feed = array(
					'id'        => null,
					'feedName'  => $feedName,
					'feedTitle' => $config_feed['feedTitle'],
					'feedDesc'  => $config_feed['feedDesc'] ?? null,
					'applyTo'   => $config_feed['applyTo'],
					'essayType' => $config_feed['essayType'],
					'steps'     => array(),
				);

				// If DB data exists for this feed, merge it
				if ( isset( $db_feeds_map[ $feedName ] ) ) {
					$db_feed           = $db_feeds_map[ $feedName ];
					$feed['id']        = $db_feed['id'];
					$feed['feedTitle'] = $db_feed['feedTitle'];
					$feed['feedDesc']  = $db_feed['feedDesc'];
					$feed['essayType'] = $db_feed['essayType'];
				}

				// Process steps and their fields
				foreach ( $config_feed['steps'] as $step ) {
					$processed_step = array(
						'step'     => $step['step'],
						'sections' => array(),
					);

					foreach ( $step['sections'] as $section ) {
						$processed_section = array(
							'section' => $section['section'],
							'fields'  => array(),
						);

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
							$processed_section['fields'][] = array(
								'id'    => $field['id'],
								'value' => $value ?? $field['default'],
							);
						}

						$processed_step['sections'][] = $processed_section;
					}

					$feed['steps'][] = $processed_step;
				}

				$group_feeds[] = $feed;
			}

			$result[] = array(
				'groupName'  => $group['groupName'],
				'groupTitle' => $group['groupTitle'],
				'feeds'      => $group_feeds,
			);
		}

		return $result;
	}

	public function update_settings( \WP_REST_Request $request ) {
		// Validate request parameters
		$type     = $request->get_param( 'type' );
		$settings = $request->get_param( 'settings' );
		$tab      = $request->get_param( 'tab' );

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
			'api-feeds-process-order' => $this->update_process_order_settings( $settings, $tab ),
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

		} catch ( \Exception $e ) {
			$this->db->rollback();
			return new \WP_Error( 400, $e->getMessage() );
		}
	}

	private function update_process_order_settings( $settings, $tab ) {
		if ( ! is_array( $settings ) ) {
			return new \WP_Error( '400', 'Invalid settings format.' );
		}

		$this->db->start_transaction();

		try {
			// Get current process orders from DB for comparison
			$current_settings = $this->db->get_api_feeds(
				array(
					'limit'   => 500,
					'include' => array( 'essay_types' ),
				)
			);
			$current_orders   = array();

			// Create lookup of current process orders
			foreach ( $current_settings as $feed ) {
				if ( ! empty( $feed['essay_types'] ) ) {
					foreach ( $feed['essay_types'] as $essay_type ) {
						$key                    = $essay_type['essay_type'] . '-' . $feed['id'];
						$current_orders[ $key ] = (int) $essay_type['process_order'];
					}
				}
			}

			// Track changes to minimize DB operations
			$updates = array();

			// Compare and collect changes
			foreach ( $settings as $group ) {
				foreach ( $group['feeds'] as $feed ) {
					$key       = $group['groupName'] . '-' . $feed['id'];
					$new_order = (int) $feed['processOrder'];

					// Only update if order has changed
					if ( ! isset( $current_orders[ $key ] ) || $current_orders[ $key ] !== $new_order ) {
						$updates[] = array(
							'api_feed_id'   => $feed['id'],
							'essay_type'    => $group['groupName'],
							'process_order' => $new_order,
						);
					}
				}
			}

			// Apply updates
			foreach ( $updates as $update ) {
				$this->db->update_process_order(
					$update['api_feed_id'],
					$update['essay_type'],
					$update['process_order']
				);
			}

			$this->db->commit();

			// Return updated settings
			return ( new Ieltssci_Settings_Config() )->get_settings_config( $tab );

		} catch ( \Exception $e ) {
			$this->db->rollback();
			return new \WP_Error( 400, $e->getMessage() );
		}
	}
}
