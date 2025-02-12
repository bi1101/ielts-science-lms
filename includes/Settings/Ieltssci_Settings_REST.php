<?php

namespace IeltsScienceLMS\Settings;

class Ieltssci_Settings_REST {
	private $namespace = 'ieltssci/v1';
	private $base = 'settings';
	private $db;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		$this->db = new Ieltssci_Settings_DB();
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

	public function get_settings( \WP_REST_Request $request ) {
		// Get the 'tab' parameter from the request.
		$tab = $request->get_param( 'tab' );
		$type = $request->get_param( 'type' );

		$result = match ( $type ) {
			'api-feeds' => $this->get_api_feed_settings( $tab ),
			default => new \WP_Error( 400, 'Invalid type.' ),
		};

		return new \WP_REST_Response( $result, 200 );
	}

	private function get_api_feed_settings( $tab ) {
		// 1. Get settingsConfig (from the Ieltssci_Settings class)
		$settings_config_instance = new Ieltssci_Settings_Config();
		$settings_config = $settings_config_instance->get_settings_config( $tab );  // Pass the $tab parameter

		// Return early if settings config is empty.
		if ( empty( $settings_config ) ) {
			return [];
		}

		// 2. Query the database
		$results = $this->db->get_all_settings();

		// 3. Create an associative array to store DB results by composite key
		$dbSettings = [];
		$lastUpdated = [];

		foreach ( $results as $row ) {
			$key = $row['feedback_criteria'] . '-' . $row['essay_type'];
			$currentTimestamp = strtotime( $row['updated_at'] );

			// If key doesn't exist yet, or if this row is more recent
			if ( ! isset( $lastUpdated[ $key ] ) || $currentTimestamp > $lastUpdated[ $key ] ) {
				$dbSettings[ $key ] = $row;
				$lastUpdated[ $key ] = $currentTimestamp;
			}
		}

		// 4. Create an array for the final flattened settings
		$finalSettings = [];
		$groupIndex = 0;

		// 5. Iterate through settingsConfig
		foreach ( $settings_config as $group ) {
			foreach ( $group['feeds'] as $feed ) {
				// Each feedName has a process order key
				$processOrderKey = $feed['feedName'] . '-process-order';

				// Add process order unique key to retrieve the process order from the database.
				$process_order_composite_key = $feed['feedName'] . '-' . $feed['essayType'][0]; // Use the first essay type as the key because in the same feed both essay type has the same process order.
				$finalSettings[ $processOrderKey ] = $dbSettings[ $process_order_composite_key ]['process_order'] ?? ( $groupIndex + 1 );

				foreach ( $feed['essayType'] as $essay_type ) {

					// Add ID for each row, identified by the feed name and essay type.
					$uid_composite_key = $feed['feedName'] . '-' . $essay_type;
					$uid = $feed['feedName'] . '-' . $essay_type . '-id';
					$finalSettings[ $uid ] = $dbSettings[ $uid_composite_key ]['id'] ?? null;

					foreach ( $feed['steps'] as $step ) {
						foreach ( $step['sections'] as $section ) {
							foreach ( $section['fields'] as $field ) {
								// 6. Construct settingId and composite key
								$settingId = $feed['feedName'] . '-' . $step['step'] . '-' . $section['section'] . '-' . $field['id'];

								// 7. Check if the setting exists in the database
								if ( isset( $dbSettings[ $uid_composite_key ] ) ) {
									// 8. Get and decode the meta field
									$meta = json_decode( $dbSettings[ $uid_composite_key ]['meta'], true );

									// 9. Get the field value from meta, or default (DIRECT ACCESS)
									if ( is_array( $meta ) && isset( $meta['steps'] ) ) {
										// Find the correct step.
										$targetStep = null;
										foreach ( $meta['steps'] as $metaStep ) {
											if ( $metaStep['step'] === $step['step'] ) {
												$targetStep = $metaStep;
												break;
											}
										}

										if ( $targetStep ) {
											//Find the correct section
											$targetSection = null;
											foreach ( $targetStep['sections'] as $metaSection ) {
												if ( $metaSection['section'] === $section['section'] ) {
													$targetSection = $metaSection;
													break;
												}
											}

											if ( $targetSection ) {
												// Find the correct field
												$targetField = null;
												foreach ( $targetSection['fields'] as $metaField ) {
													if ( $metaField['id'] === $field['id'] ) {
														$targetField = $metaField;
														break;
													}
												}

												if ( $targetField ) {
													$finalSettings[ $settingId ] = $targetField['value'] ?? $field['default'];
												} else {
													$finalSettings[ $settingId ] = $field['default']; // Field not found
												}
											} else {
												$finalSettings[ $settingId ] = $field['default']; // Section not found
											}
										} else {
											$finalSettings[ $settingId ] = $field['default']; // Step not found
										}
									} else {
										$finalSettings[ $settingId ] = $field['default']; // Meta data is invalid or missing
									}
								} else {
									// 10. Use the default value
									$finalSettings[ $settingId ] = $field['default'] ?? null;
								}
							}
						}
					}
				}
			}
			$groupIndex++;
		}

		// 11. Return the flattened settings
		return $finalSettings;
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

		// Get and return updated settings
		$updated_settings = $this->get_settings( $request )->get_data();
		if ( is_wp_error( $updated_settings ) ) {
			return new \WP_REST_Response( 'Settings updated, but could not retrieve updated settings.', 200 );
		}

		return new \WP_REST_Response( $updated_settings, 200 );
	}

	private function update_api_feed_settings( $settings, $tab ) {
		if ( ! isset( $settings ) || ! is_array( $settings ) ) {
			return new \WP_Error( '400', 'Invalid settings format for API feeds.' );
		}

		$this->db->start_transaction();

		try {
			foreach ( $settings as $group ) {
				if ( ! isset( $group['feeds'] ) || ! is_array( $group['feeds'] ) ) {
					throw new \Exception( 'Invalid feeds format.' );
				}

				foreach ( $group['feeds'] as $feed ) {
					if ( ! isset( $feed['feedName'], $feed['feedTitle'], $feed['processOrder'], $feed['applyTo'], $feed['essayType'] )
						|| ! is_array( $feed['essayType'] ) ) {
						throw new \Exception( 'Missing required feed fields or essayType is not an array.' );
					}

					$existing_essay_types = $this->db->get_existing_essay_types( $feed['feedName'], $feed['applyTo'], $feed['id'] ?? 0 );
					$essay_types_to_delete = array_diff( $existing_essay_types, $feed['essayType'] );

					if ( ! empty( $essay_types_to_delete ) ) {
						$this->db->delete_essay_types( $feed['feedName'], $feed['applyTo'], $essay_types_to_delete );
					}

					// Process new essay types
					$this->process_new_essay_types( $feed );

					// Update existing essay types
					$this->update_existing_essay_types( $feed, $existing_essay_types );
				}
			}

			$this->db->commit();
			return true;

		} catch (\Exception $e) {
			$this->db->rollback();
			return new \WP_Error( 400, $e->getMessage() );
		}
	}

	private function process_new_essay_types( $feed ) {
		$existing_essay_types = $this->db->get_existing_essay_types( $feed['feedName'], $feed['applyTo'], $feed['id'] ?? 0 );
		$essay_types_to_add = array_diff( $feed['essayType'], $existing_essay_types );

		foreach ( $essay_types_to_add as $essay_type ) {
			$data = $this->prepare_feed_data( $feed, $essay_type );
			$meta_data = $this->prepare_meta_data( $feed );

			if ( ! $this->db->insert_feed_setting( $data, $meta_data ) ) {
				throw new \Exception( "Failed to insert feed setting for {$feed['feedName']} and essay type $essay_type" );
			}
		}
	}

	private function update_existing_essay_types( $feed, $existing_essay_types ) {
		foreach ( $existing_essay_types as $essay_type ) {
			$data = $this->prepare_feed_data( $feed, $essay_type );
			$meta_data = $this->prepare_meta_data( $feed );

			if ( ! $this->db->update_feed_setting( $feed['id'], $data, $meta_data ) ) {
				throw new \Exception( "Failed to update feed setting for ID {$feed['id']} and essay type $essay_type" );
			}
		}
	}

	private function prepare_feed_data( $feed, $essay_type ) {
		return [ 
			'feedback_criteria' => $feed['feedName'],
			'feed_title' => $feed['feedTitle'],
			'process_order' => (int) $feed['processOrder'],
			'essay_type' => $essay_type,
			'apply_to' => $feed['applyTo'],
			'feed_desc' => $feed['feedDesc'] ?? null,
		];
	}

	private function prepare_meta_data( $feed ) {
		$meta_data = [];
		if ( isset( $feed['steps'] ) ) {
			$meta_data['steps'] = $feed['steps'];
		}
		return $meta_data;
	}
}