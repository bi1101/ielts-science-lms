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

	/**
	 * Retrieves API feed settings for a specific tab.
	 *
	 * This method retrieves and combines settings from both configuration and database
	 * for the specified settings tab.
	 *
	 * @param string $tab The settings tab identifier.
	 * @return array An array of combined settings. Returns empty array if settings configuration is not found.
	 */
	public function get_api_feed_settings( $tab ) {
		// Get settings configuration
		$settings_config = ( new Ieltssci_Settings_Config() )->get_settings_config( $tab );
		if ( empty( $settings_config ) ) {
			return [];
		}

		// Get database settings
		$dbSettings = $this->get_db_settings();

		// Combine settings to get final settings
		$finalSettings = $this->build_final_settings( $settings_config, $dbSettings );

		return $finalSettings;
	}

	/**
	 * Retrieves and processes settings from the database.
	 * 
	 * This method fetches all settings from the database and organizes them into an associative array.
	 * For each unique combination of feedback_criteria and essay_type, it keeps only the most recent entry
	 * based on the updated_at timestamp.
	 *
	 * @return array An associative array where keys are combined feedback_criteria-essay_type strings
	 *               and values are the corresponding setting rows with the most recent timestamps.
	 *               Each row contains the complete settings data from the database.
	 * 
	 * @access private
	 */
	private function get_db_settings() {
		$results = $this->db->get_all_settings();
		$dbSettings = [];
		$lastUpdated = [];

		foreach ( $results as $row ) {
			$key = $row['feedback_criteria'] . '-' . $row['essay_type'];
			$currentTimestamp = strtotime( $row['updated_at'] );

			if ( ! isset( $lastUpdated[ $key ] ) || $currentTimestamp > $lastUpdated[ $key ] ) {
				$dbSettings[ $key ] = $row;
				$lastUpdated[ $key ] = $currentTimestamp;
			}
		}

		return $dbSettings;
	}

	/**
	 * Builds the final settings array by combining configuration and database settings.
	 *
	 * @param array $settings_config The configuration array containing groups and feeds
	 * @param array $dbSettings The settings stored in the database
	 * @return array The processed and combined final settings array
	 *
	 * @access private
	 * @since 1.0.0
	 *
	 * This method iterates through the settings configuration groups and their feeds,
	 * processes each feed using the database settings, and builds a final settings array
	 * organized by group index.
	 */
	private function build_final_settings( $settings_config, $dbSettings ) {
		$finalSettings = [];
		$groupIndex = 0;

		foreach ( $settings_config as $group ) {
			foreach ( $group['feeds'] as $feed ) {
				$this->process_feed( $feed, $dbSettings, $finalSettings, $groupIndex );
			}
			$groupIndex++;
		}

		return $finalSettings;
	}

	/**
	 * Processes a feed configuration and updates the final settings.
	 *
	 * @param array  $feed          The feed configuration array containing feedName and essayType
	 * @param array  $dbSettings    The database settings array
	 * @param array  &$finalSettings Reference to the final settings array to be updated
	 * @param int    $groupIndex    The index of the current feed group
	 *
	 * @return void
	 *
	 * This method:
	 * 1. Generates a process order key based on the feed name
	 * 2. Creates a composite key using feed name and first essay type
	 * 3. Sets the process order in final settings using DB value or fallback to groupIndex + 1
	 * 4. Processes each essay type in the feed
	 */
	private function process_feed( $feed, $dbSettings, &$finalSettings, $groupIndex ) {
		$processOrderKey = $feed['feedName'] . '-process-order';
		$process_order_composite_key = $feed['feedName'] . '-' . $feed['essayType'][0];
		$finalSettings[ $processOrderKey ] = $dbSettings[ $process_order_composite_key ]['process_order'] ?? ( $groupIndex + 1 );

		foreach ( $feed['essayType'] as $essay_type ) {
			$this->process_essay_type( $feed, $essay_type, $dbSettings, $finalSettings );
		}
	}

	/**
	 * Process essay type settings and its associated steps.
	 * 
	 * @param array  $feed          The feed configuration array containing feedName and steps
	 * @param string $essay_type    The type of essay being processed
	 * @param array  $dbSettings    Database settings array containing stored configuration
	 * @param array  &$finalSettings Reference to the final settings array to be updated
	 * 
	 * @return void
	 * 
	 * @access private
	 */
	private function process_essay_type( $feed, $essay_type, $dbSettings, &$finalSettings ) {
		$uid_composite_key = $feed['feedName'] . '-' . $essay_type;
		$uid = $feed['feedName'] . '-' . $essay_type . '-id';
		$finalSettings[ $uid ] = $dbSettings[ $uid_composite_key ]['id'] ?? null;

		foreach ( $feed['steps'] as $step ) {
			$this->process_step( $feed, $step, $dbSettings, $uid_composite_key, $finalSettings );
		}
	}

	/**
	 * Processes a single step from the settings configuration and populates the final settings array.
	 *
	 * @param array $feed The feed configuration array containing feedName and other settings
	 * @param array $step The step configuration array containing sections
	 * @param array $dbSettings Array of settings from the database
	 * @param string $uid_composite_key The unique identifier composite key for the settings
	 * @param array &$finalSettings Reference to the array where processed settings will be stored
	 * 
	 * @return void This method modifies $finalSettings array by reference
	 * 
	 * Takes a step configuration and processes all its sections and fields.
	 * For each field, creates a unique setting ID by combining feed name, step, section, and field IDs.
	 * Retrieves the corresponding field value and stores it in the finalSettings array.
	 */
	private function process_step( $feed, $step, $dbSettings, $uid_composite_key, &$finalSettings ) {
		foreach ( $step['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$settingId = $feed['feedName'] . '-' . $step['step'] . '-' . $section['section'] . '-' . $field['id'];
				$finalSettings[ $settingId ] = $this->get_field_value( $dbSettings, $uid_composite_key, $step, $section, $field );
			}
		}
	}

	/**
	 * Retrieves the value of a specific field from database settings.
	 *
	 * @param array  $dbSettings       The database settings array.
	 * @param string $uid_composite_key The unique identifier composite key.
	 * @param array  $step             The step identifier.
	 * @param array  $section          The section identifier.
	 * @param array  $field            The field configuration array containing default value.
	 *
	 * @return mixed Returns the field value if found, the default value if specified, or null otherwise.
	 */
	private function get_field_value( $dbSettings, $uid_composite_key, $step, $section, $field ) {
		if ( ! isset( $dbSettings[ $uid_composite_key ] ) ) {
			return $field['default'] ?? null;
		}

		$meta = json_decode( $dbSettings[ $uid_composite_key ]['meta'], true );
		if ( ! is_array( $meta ) || ! isset( $meta['steps'] ) ) {
			return $field['default'];
		}

		return $this->find_field_value_in_meta( $meta, $step, $section, field: $field );
	}

	/**
	 * Search for a field value in a nested meta array structure
	 *
	 * @param array $meta     The meta data array containing steps, sections and fields
	 * @param array $step     The step array to search in
	 * @param array $section  The section array to search in
	 * @param array $field    The field array containing the target id and default value
	 * 
	 * @return mixed Returns the field value if found, otherwise returns the field's default value
	 */
	private function find_field_value_in_meta( $meta, $step, $section, $field ) {
		foreach ( $meta['steps'] as $metaStep ) {
			if ( $metaStep['step'] !== $step['step'] )
				continue;

			foreach ( $metaStep['sections'] as $metaSection ) {
				if ( $metaSection['section'] !== $section['section'] )
					continue;

				foreach ( $metaSection['fields'] as $metaField ) {
					if ( $metaField['id'] === $field['id'] ) {
						return $metaField['value'] ?? $field['default'];
					}
				}
			}
		}

		return $field['default'];
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