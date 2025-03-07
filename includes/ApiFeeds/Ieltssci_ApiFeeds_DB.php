<?php

namespace IeltsScienceLMS\ApiFeeds;

use WP_Error;
use wpdb;
use IeltsScienceLMS\RateLimits\Ieltssci_RateLimit_DB;

class Ieltssci_ApiFeeds_DB {
	private string $api_feed_table;
	private string $essay_type_table;
	private wpdb $wpdb;
	private Ieltssci_RateLimit_DB $rate_limit_db;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
		$this->api_feed_table = "{$this->wpdb->prefix}ieltssci_api_feed";
		$this->essay_type_table = "{$this->wpdb->prefix}ieltssci_api_feed_essay_type";
		$this->rate_limit_db = new Ieltssci_RateLimit_DB();
	}

	/**
	 * Get API feeds based on various criteria with selective field inclusion
	 *
	 * @param array $args {
	 *     Optional. Array of arguments.
	 *     @type int    $feed_id           Specific API feed ID to retrieve.
	 *     @type string $feedback_criteria Filter feeds by specific criteria.
	 *     @type string $essay_type        Filter feeds applicable to specific essay types.
	 *     @type string $apply_to          Filter by what the feed applies to.
	 *     @type string $order_by          Field to sort results by (id, created_at, updated_at, process_order).
	 *                                     Use 'process_order' to sort by the essay type's process_order.
	 *                                     Default 'id'.
	 *     @type string $order_direction   Sorting direction (ASC or DESC). Default 'ASC'.
	 *     @type int    $limit             Maximum number of feeds to retrieve. Default 10.
	 *     @type int    $offset            For pagination, skip a certain number of records. Default 0.
	 *     @type array  $include           Specify which data to include in the response.
	 *                                     Possible values: 'meta', 'essay_types', 'rate_limits', 'process_order'.
	 *                                     If empty, only returns basic feed data.
	 * }
	 * @return array|WP_Error Array of API feeds or WP_Error on failure
	 */
	public function get_api_feeds( $args = [] ) {

		$defaults = [ 
			'feed_id' => 0,
			'feedback_criteria' => '',
			'essay_type' => '',
			'apply_to' => '',
			'order_by' => 'id',
			'order_direction' => 'ASC',
			'limit' => 10,
			'offset' => 0,
			'include' => [],
		];

		$args = wp_parse_args( $args, $defaults );

		// Sanitize and validate arguments
		$feed_id = absint( $args['feed_id'] );
		$feedback_criteria = sanitize_text_field( $args['feedback_criteria'] );
		$essay_type = sanitize_text_field( $args['essay_type'] );
		$apply_to = sanitize_text_field( $args['apply_to'] );

		// Validate order_by
		$allowed_order_fields = [ 'id', 'created_at', 'updated_at', 'feedback_criteria', 'feed_title' ];
		$special_order = false;

		if ( 'process_order' === $args['order_by'] ) {
			$special_order = true;
		} elseif ( ! in_array( $args['order_by'], $allowed_order_fields, true ) ) {
			$args['order_by'] = 'id';
		}

		// Validate order_direction
		$args['order_direction'] = strtoupper( $args['order_direction'] );
		if ( ! in_array( $args['order_direction'], [ 'ASC', 'DESC' ], true ) ) {
			$args['order_direction'] = 'ASC';
		}

		// Ensure include is an array
		if ( ! is_array( $args['include'] ) ) {
			$args['include'] = empty( $args['include'] ) ? [] : [ $args['include'] ];
		}

		// Special handling for process_order sort or when process_order is included
		$include_process_order = in_array( 'process_order', $args['include'], true );

		if ( ( $special_order || $include_process_order ) && ! empty( $essay_type ) ) {
			// When ordering by process_order or including it in results, we need to join with the essay_type table
			$query = "SELECT f.*, et.process_order 
                 FROM {$this->api_feed_table} f
                 JOIN {$this->essay_type_table} et ON f.id = et.api_feed_id";

			$where_clauses = [ "et.essay_type = %s" ];
			$where_values = [ $essay_type ];

			if ( $feed_id > 0 ) {
				$where_clauses[] = 'f.id = %d';
				$where_values[] = $feed_id;
			}

			if ( ! empty( $feedback_criteria ) ) {
				$where_clauses[] = 'f.feedback_criteria = %s';
				$where_values[] = $feedback_criteria;
			}

			if ( ! empty( $apply_to ) ) {
				$where_clauses[] = 'f.apply_to = %s';
				$where_values[] = $apply_to;
			}

			// Add WHERE clause
			$query .= ' WHERE ' . implode( ' AND ', $where_clauses );

			// Add ORDER BY
			$query .= " ORDER BY et.process_order {$args['order_direction']}";

			// Add LIMIT and OFFSET
			$query .= $this->wpdb->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );

			// Prepare the full query
			$query = $this->wpdb->prepare( $query, $where_values );

		} else {
			// Standard query without process_order
			$query = "SELECT * FROM $this->api_feed_table";
			$where_clauses = [];
			$where_values = [];

			if ( $feed_id > 0 ) {
				$where_clauses[] = 'id = %d';
				$where_values[] = $feed_id;
			}

			if ( ! empty( $feedback_criteria ) ) {
				$where_clauses[] = 'feedback_criteria = %s';
				$where_values[] = $feedback_criteria;
			}

			if ( ! empty( $apply_to ) ) {
				$where_clauses[] = 'apply_to = %s';
				$where_values[] = $apply_to;
			}

			// Handle essay_type filtering here
			if ( ! empty( $essay_type ) ) {
				$feed_ids = $this->wpdb->get_col(
					$this->wpdb->prepare(
						"SELECT api_feed_id FROM $this->essay_type_table WHERE essay_type = %s",
						$essay_type
					)
				);

				if ( $this->wpdb->last_error ) {
					return new WP_Error(
						500,
						sprintf( 'Database error while getting feed IDs for essay type: %s', $essay_type ),
						[ 'error' => $this->wpdb->last_error ]
					);
				}

				if ( ! empty( $feed_ids ) ) {
					$where_clauses[] = 'id IN (' . implode( ',', $feed_ids ) . ')';
				} else {
					// No feeds with this essay type
					return [];
				}
			}

			// Add WHERE clause if needed
			if ( ! empty( $where_clauses ) ) {
				$query .= ' WHERE ' . implode( ' AND ', $where_clauses );
			}

			// Add ORDER BY
			$query .= " ORDER BY {$args['order_by']} {$args['order_direction']}";

			// Add LIMIT and OFFSET
			$query .= $this->wpdb->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );

			// Prepare the full query if where values exist
			if ( ! empty( $where_values ) ) {
				$query = $this->wpdb->prepare( $query, $where_values );
			}
		}

		// Execute the query
		$feeds = $this->wpdb->get_results( $query, ARRAY_A );

		// Check for database errors
		if ( $this->wpdb->last_error ) {
			return new WP_Error(
				'database_query_error',
				'Database error retrieving API feeds',
				[ 'error' => $this->wpdb->last_error, 'query' => $query ]
			);
		}

		if ( empty( $feeds ) ) {
			return [];
		}

		// Process includes
		foreach ( $feeds as &$feed ) {
			// Always decode meta from JSON for internal use
			if ( isset( $feed['meta'] ) ) {
				// $meta = json_decode( $feed['meta'], true );

				// Only include meta in response if requested
				if ( ! in_array( 'meta', $args['include'], true ) ) {
					unset( $feed['meta'] );
				}
			}

			// If process_order isn't included and wasn't part of the query, get it separately
			if ( in_array( 'process_order', $args['include'], true ) && ! isset( $feed['process_order'] ) && ! empty( $essay_type ) ) {
				$process_order = $this->wpdb->get_var( $this->wpdb->prepare(
					"SELECT process_order FROM $this->essay_type_table 
					WHERE api_feed_id = %d AND essay_type = %s",
					$feed['id'], $essay_type
				) );

				if ( $process_order !== null ) {
					$feed['process_order'] = (int) $process_order;
				}
			}

			// Include essay types if requested
			if ( in_array( 'essay_types', $args['include'], true ) ) {
				$essay_types_result = $this->get_api_feed_essay_types( $feed['id'] );

				// Check if the result is a WP_Error
				if ( is_wp_error( $essay_types_result ) ) {
					return $essay_types_result;
				}

				$feed['essay_types'] = $essay_types_result;
			}

			// Include rate limits if requested
			if ( in_array( 'rate_limits', $args['include'], true ) ) {
				try {
					$feed['rate_limits'] = $this->rate_limit_db->get_rate_limits( $feed['id'] );
				} catch (\Exception $e) {
					return new WP_Error(
						500,
						sprintf( 'Error retrieving rate limits for feed: %s', $feed['id'] ),
						[ 'error' => $e->getMessage() ]
					);
				}
			}
		}

		return $feeds;
	}

	/**
	 * Helper function to get essay types for an API feed
	 *
	 * @param int $feed_id API feed ID
	 * @return array|WP_Error Essay types associated with the feed or WP_Error on failure
	 */
	public function get_api_feed_essay_types( $feed_id ) {

		$query = $this->wpdb->prepare(
			"SELECT essay_type, process_order FROM $this->essay_type_table WHERE api_feed_id = %d ORDER BY process_order ASC",
			$feed_id
		);

		$results = $this->wpdb->get_results( $query, ARRAY_A );

		if ( $this->wpdb->last_error ) {
			return new WP_Error(
				500,
				sprintf( 'Error retrieving feed: %s', values: $feed_id ),
				[ 'error' => $this->wpdb->last_error ]
			);
		}

		return $results;

	}

	public function start_transaction(): void {
		$this->wpdb->query( 'START TRANSACTION' );
	}

	public function commit(): void {
		$this->wpdb->query( 'COMMIT' );
	}

	public function rollback(): void {
		$this->wpdb->query( 'ROLLBACK' );
	}

	public function update_feed( $feed_data ): int {
		$data = [ 
			'feedback_criteria' => $feed_data['feedName'],
			'feed_title' => $feed_data['feedTitle'],
			'feed_desc' => $feed_data['feedDesc'],
			'apply_to' => $feed_data['applyTo'],
			'meta' => json_encode( [ 'steps' => $feed_data['steps'] ] )
		];

		if ( empty( $feed_data['id'] ) ) {
			// Insert new feed
			$result = $this->wpdb->insert(
				$this->api_feed_table,
				$data,
				[ '%s', '%s', '%s', '%s', '%s' ]
			);

			if ( $result === false ) {
				throw new \Exception( "Failed to insert feed: " . $this->wpdb->last_error );
			}

			$feed_id = $this->wpdb->insert_id;
		} else {
			// Update existing feed
			$result = $this->wpdb->update(
				$this->api_feed_table,
				$data,
				[ 'id' => $feed_data['id'] ],
				[ '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);

			if ( $result === false ) {
				throw new \Exception( "Failed to update feed: " . $this->wpdb->last_error );
			}

			$feed_id = $feed_data['id'];
		}

		return $feed_id;
	}

	public function update_essay_types( $feed_id, $new_essay_types ): void {
		$table = $this->essay_type_table;
		// Fetch current essay types for this feed
		$query = $this->wpdb->prepare(
			"SELECT id, essay_type, process_order FROM $table WHERE api_feed_id = %d ORDER BY process_order",
			$feed_id
		);
		$current = $this->wpdb->get_results( $query, ARRAY_A );

		// Process new list: update process_order if changed; insert new records as needed
		foreach ( $new_essay_types as $new_index => $essay_type ) {
			// Try to find a matching current record
			$found = false;
			foreach ( $current as $record ) {
				if ( $record['essay_type'] === $essay_type ) {
					$found = true;
					break;
				}
			}
			// Insert if not found
			if ( ! $found ) {
				$result = $this->wpdb->insert(
					$table,
					[ 
						'api_feed_id' => $feed_id,
						'essay_type' => $essay_type,
						'process_order' => $new_index
					],
					[ '%d', '%s', '%d' ]
				);
				if ( $result === false ) {
					throw new \Exception( "Failed to insert essay type: " . $this->wpdb->last_error );
				}
			}
		}

		// Delete any essay types that are no longer present
		foreach ( $current as $record ) {
			if ( ! in_array( $record['essay_type'], $new_essay_types, true ) ) {
				$this->wpdb->delete(
					$table,
					[ 'id' => $record['id'] ],
					[ '%d' ]
				);
			}
		}
	}

	public function update_process_order( int $api_feed_id, string $essay_type, int $process_order ): bool {
		$table = $this->essay_type_table;

		$result = $this->wpdb->update(
			$table,
			[ 'process_order' => $process_order ],
			[ 
				'api_feed_id' => $api_feed_id,
				'essay_type' => $essay_type
			],
			[ '%d' ],
			[ '%d', '%s' ]
		);

		if ( $result === false ) {
			throw new \Exception( "Failed to update process order: " . $this->wpdb->last_error );
		}

		return true;
	}
}
