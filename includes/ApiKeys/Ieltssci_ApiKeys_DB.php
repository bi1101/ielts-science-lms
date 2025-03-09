<?php

namespace IeltsScienceLMS\ApiKeys;

class Ieltssci_ApiKeys_DB {
	private \wpdb $wpdb;
	private string $table_name;

	public function __construct() {
		global $wpdb;
		$this->wpdb       = $wpdb ?? $GLOBALS['wpdb'];
		$this->table_name = $this->wpdb->prefix . 'ieltssci_api_key';
	}

	/**
	 * Gets multiple API keys with flexible filtering options
	 *
	 * @param array $args {
	 *     Optional. Arguments to filter API keys.
	 *     @type string|array $provider    Filter by provider name(s)
	 *     @type string       $order_by    Column to order by (id, api_provider, usage_count, created_at, updated_at)
	 *     @type string       $order       Order direction (ASC or DESC)
	 *     @type int          $limit       Maximum number of keys to return
	 *     @type bool         $least_used  When true, returns keys with lowest usage count
	 *     @type array        $meta_query  Filter by meta value conditions
	 * }
	 * @return array Array of API keys grouped by provider
	 */
	public function get_api_keys( $args = array() ) {
		$defaults = array(
			'provider'   => '',
			'order_by'   => 'id',
			'order'      => 'ASC',
			'limit'      => 0,
			'least_used' => false,
			'meta_query' => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		$where_clauses = array();
		$where_values  = array();

		// Filter by provider
		if ( ! empty( $args['provider'] ) ) {
			if ( is_array( $args['provider'] ) ) {
				$placeholders    = implode( ',', array_fill( 0, count( $args['provider'] ), '%s' ) );
				$where_clauses[] = "api_provider IN ($placeholders)";
				$where_values    = array_merge( $where_values, $args['provider'] );
			} else {
				$where_clauses[] = 'api_provider = %s';
				$where_values[]  = $args['provider'];
			}
		}

		// Handle meta query conditions
		if ( ! empty( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
			foreach ( $args['meta_query'] as $query ) {
				if ( ! isset( $query['key'] ) || ! isset( $query['value'] ) ) {
					continue;
				}

				$compare = $query['compare'] ?? '=';
				$key     = $query['key'];
				$value   = $query['value'];

				// For JSON fields, we need to use JSON_EXTRACT or JSON_CONTAINS based on compare operator
				switch ( $compare ) {
					case '=':
						$where_clauses[] = "JSON_EXTRACT(meta, '$.{$key}') = %s";
						$where_values[]  = $value;
						break;
					case 'LIKE':
						$where_clauses[] = "JSON_EXTRACT(meta, '$.{$key}') LIKE %s";
						$where_values[]  = '%' . $this->wpdb->esc_like( $value ) . '%';
						break;
					case 'CONTAINS':
						$where_clauses[] = "JSON_CONTAINS(meta, %s, '$.{$key}')";
						$where_values[]  = json_encode( $value );
						break;
					case 'EXISTS':
						$where_clauses[] = "JSON_EXTRACT(meta, '$.{$key}') IS NOT NULL";
						break;
				}
			}
		}

		// Build query
		$query = "SELECT * FROM {$this->table_name}";

		if ( ! empty( $where_clauses ) ) {
			$query .= ' WHERE ' . implode( ' AND ', $where_clauses );
		}

		// Order results
		$allowed_order_columns = array( 'id', 'api_provider', 'usage_count', 'created_at', 'updated_at' );
		$order_by              = in_array( $args['order_by'], $allowed_order_columns ) ? $args['order_by'] : 'id';
		$order                 = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

		if ( $args['least_used'] ) {
			$order_by = 'usage_count';
			$order    = 'ASC';
		}

		$query .= " ORDER BY {$order_by} {$order}";

		// Apply limit
		if ( ! empty( $args['limit'] ) && is_numeric( $args['limit'] ) ) {
			$query         .= ' LIMIT %d';
			$where_values[] = (int) $args['limit'];
		}

		// Prepare and execute query
		if ( ! empty( $where_values ) ) {
			$prepared_query = $this->wpdb->prepare( $query, $where_values );
		} else {
			$prepared_query = $query;
		}

		$results = $this->wpdb->get_results( $prepared_query, ARRAY_A );

		// Format results grouped by provider
		$api_keys = array();
		foreach ( $results as $row ) {
			$provider = $row['api_provider'];
			if ( ! isset( $api_keys[ $provider ] ) ) {
				$api_keys[ $provider ] = array( 'keys' => array() );
			}

			$api_keys[ $provider ]['keys'][] = array(
				'id'          => (int) $row['id'],
				'meta'        => json_decode( $row['meta'], true ),
				'usage_count' => (int) $row['usage_count'],
				'created_at'  => $row['created_at'],
				'updated_at'  => $row['updated_at'],
			);
		}

		return $api_keys;
	}

	/**
	 * Gets a single API key by ID or other criteria
	 *
	 * @param int   $key_id  Key ID to search for
	 * @param array $args {
	 *     Optional. Additional arguments for finding the key.
	 *     @type string $provider     Filter by provider name
	 *     @type bool   $least_used   When true, returns the least used key
	 *     @type array  $meta_query   Filter by meta value conditions
	 *     @type bool   $increment_usage When true, increments usage count of returned key
	 * }
	 * @return array|null Single API key or null if not found
	 */
	public function get_api_key( $key_id = 0, $args = array() ) {
		$defaults = array(
			'provider'        => '',
			'least_used'      => true,
			'meta_query'      => array(),
			'increment_usage' => false,
		);

		$args        = wp_parse_args( $args, $defaults );
		$search_args = $args;

		// Set ID if provided
		if ( is_numeric( $key_id ) && $key_id > 0 ) {
			$search_args['id'] = (int) $key_id;
		}

		if ( $args['least_used'] ) {
			$search_args['order_by'] = 'usage_count';
			$search_args['order']    = 'ASC';
		}

		// Use the query building logic from above but always limit to one result
		$where_clauses = array();
		$where_values  = array();

		// Filter by ID if provided
		if ( ! empty( $search_args['id'] ) ) {
			$where_clauses[] = 'id = %d';
			$where_values[]  = (int) $search_args['id'];
		}

		// Filter by provider
		if ( ! empty( $search_args['provider'] ) ) {
			$where_clauses[] = 'api_provider = %s';
			$where_values[]  = $search_args['provider'];
		}

		// Handle meta query conditions
		if ( ! empty( $search_args['meta_query'] ) && is_array( $search_args['meta_query'] ) ) {
			foreach ( $search_args['meta_query'] as $query ) {
				if ( ! isset( $query['key'] ) || ! isset( $query['value'] ) ) {
					continue;
				}

				$compare = $query['compare'] ?? '=';
				$key     = $query['key'];
				$value   = $query['value'];

				switch ( $compare ) {
					case '=':
						$where_clauses[] = "JSON_EXTRACT(meta, '$.{$key}') = %s";
						$where_values[]  = $value;
						break;
					case 'LIKE':
						$where_clauses[] = "JSON_EXTRACT(meta, '$.{$key}') LIKE %s";
						$where_values[]  = '%' . $this->wpdb->esc_like( $value ) . '%';
						break;
					case 'CONTAINS':
						$where_clauses[] = "JSON_CONTAINS(meta, %s, '$.{$key}')";
						$where_values[]  = json_encode( $value );
						break;
					case 'EXISTS':
						$where_clauses[] = "JSON_EXTRACT(meta, '$.{$key}') IS NOT NULL";
						break;
				}
			}
		}

		// Build query
		$query = "SELECT * FROM {$this->table_name}";

		if ( ! empty( $where_clauses ) ) {
			$query .= ' WHERE ' . implode( ' AND ', $where_clauses );
		}

		// Order by usage_count if least_used is true
		if ( $args['least_used'] ) {
			$query .= ' ORDER BY usage_count ASC';
		}

		// Always limit to 1 result for get_api_key
		$query .= ' LIMIT 1';

		// Prepare and execute query
		if ( ! empty( $where_values ) ) {
			$prepared_query = $this->wpdb->prepare( $query, $where_values );
		} else {
			$prepared_query = $query;
		}

		$row = $this->wpdb->get_row( $prepared_query, ARRAY_A );

		if ( empty( $row ) ) {
			return null;
		}

		// Increment the usage count if requested
		if ( $args['increment_usage'] ) {
			$this->wpdb->update(
				$this->table_name,
				array( 'usage_count' => (int) $row['usage_count'] + 1 ),
				array( 'id' => (int) $row['id'] ),
				array( '%d' ),
				array( '%d' )
			);
		}

		// Format single key result
		return array(
			'id'          => (int) $row['id'],
			'provider'    => $row['api_provider'],
			'meta'        => json_decode( $row['meta'], true ),
			'usage_count' => (int) $row['usage_count'],
			'created_at'  => $row['created_at'],
			'updated_at'  => $row['updated_at'],
		);
	}

	public function update_api_keys( array $api_keys ): array {
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Get all existing keys
			$existing_keys = $this->wpdb->get_results(
				"SELECT id, api_provider FROM {$this->table_name}",
				ARRAY_A
			);

			$existing_ids = array_column( $existing_keys, 'id' );
			$updated_ids  = array();

			// Process each provider's keys
			foreach ( $api_keys as $provider => $data ) {
				foreach ( $data['keys'] as $key ) {
					if ( ! empty( $key['meta'] ) ) {
						$id = $this->save_api_key( $provider, $key );
						if ( $id ) {
							$updated_ids[] = $id;
						}
					}
				}
			}

			// Delete keys that weren't updated
			$ids_to_delete = array_diff( $existing_ids, $updated_ids );
			if ( ! empty( $ids_to_delete ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids_to_delete ), '%d' ) );
				$this->wpdb->query(
					$this->wpdb->prepare(
						"DELETE FROM {$this->table_name} WHERE id IN ($placeholders)",
						$ids_to_delete
					)
				);
			}

			$this->wpdb->query( 'COMMIT' );
			return $this->get_api_keys();

		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	private function save_api_key( string $provider, array $key ): ?int {
		$data    = array(
			'api_provider' => $provider,
			'meta'         => wp_json_encode( $key['meta'] ),
		);
		$formats = array( '%s', '%s' );

		// Add usage count if provided
		if ( isset( $key['usage_count'] ) ) {
			$data['usage_count'] = (int) $key['usage_count'];
			$formats[]           = '%d';
		}

		if ( ! empty( $key['id'] ) ) {
			$result = $this->wpdb->update(
				$this->table_name,
				$data,
				array( 'id' => $key['id'] ),
				$formats,
				array( '%d' )
			);
			return $result !== false ? $key['id'] : null;
		} else {
			$result = $this->wpdb->insert( $this->table_name, $data, $formats );
			return $result ? $this->wpdb->insert_id : null;
		}
	}
}
