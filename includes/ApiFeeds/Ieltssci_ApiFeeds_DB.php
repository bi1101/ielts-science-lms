<?php
/**
 * API Feeds Database Handler
 *
 * Handles database operations for API feeds including CRUD operations
 * and relationships with essay types and rate limits.
 *
 * @package IELTS_Science_LMS
 * @subpackage ApiFeeds
 * @since 1.0.0
 */

namespace IeltsScienceLMS\ApiFeeds;

use WP_Error;
use wpdb;
use IeltsScienceLMS\RateLimits\Ieltssci_RateLimit_DB;

/**
 * Class Ieltssci_ApiFeeds_DB
 *
 * Database handler for API feeds with operations for managing feeds,
 * essay types, and their relationships.
 *
 * @package IELTS_Science_LMS\ApiFeeds
 * @since 1.0.0
 */
class Ieltssci_ApiFeeds_DB {
	/**
	 * The API feeds table name.
	 *
	 * @var string
	 */
	private $api_feed_table;

	/**
	 * The essay type table name.
	 *
	 * @var string
	 */
	private $essay_type_table;

	/**
	 * The WordPress database object.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * The rate limit database handler.
	 *
	 * @var Ieltssci_RateLimit_DB
	 */
	private $rate_limit_db;

	/**
	 * Constructor for API feeds database handler.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb             = $wpdb ?? $GLOBALS['wpdb'];
		$this->api_feed_table   = "{$this->wpdb->prefix}ieltssci_api_feed";
		$this->essay_type_table = "{$this->wpdb->prefix}ieltssci_api_feed_essay_type";
		$this->rate_limit_db    = new Ieltssci_RateLimit_DB();
	}

	/**
	 * Get API feeds based on various criteria with selective field inclusion.
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
	 * @return array|WP_Error Array of API feeds or WP_Error on failure.
	 */
	public function get_api_feeds( $args = array() ) {

		$defaults = array(
			'feed_id'           => 0,
			'feedback_criteria' => '',
			'essay_type'        => '',
			'apply_to'          => '',
			'order_by'          => 'id',
			'order_direction'   => 'ASC',
			'limit'             => 10,
			'offset'            => 0,
			'include'           => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		// Sanitize and validate arguments.
		$feed_id           = absint( $args['feed_id'] );
		$feedback_criteria = sanitize_text_field( $args['feedback_criteria'] );
		$essay_type        = sanitize_text_field( $args['essay_type'] );
		$apply_to          = sanitize_text_field( $args['apply_to'] );

		// Validate order_by.
		$allowed_order_fields = array( 'id', 'created_at', 'updated_at', 'feedback_criteria', 'feed_title' );
		$special_order        = false;

		if ( 'process_order' === $args['order_by'] ) {
			$special_order = true;
		} elseif ( ! in_array( $args['order_by'], $allowed_order_fields, true ) ) {
			$args['order_by'] = 'id';
		}

		// Validate order_direction.
		$args['order_direction'] = strtoupper( $args['order_direction'] );
		if ( ! in_array( $args['order_direction'], array( 'ASC', 'DESC' ), true ) ) {
			$args['order_direction'] = 'ASC';
		}

		// Ensure include is an array.
		if ( ! is_array( $args['include'] ) ) {
			$args['include'] = empty( $args['include'] ) ? array() : array( $args['include'] );
		}

		// Special handling for process_order sort or when process_order is included.
		$include_process_order = in_array( 'process_order', $args['include'], true );

		if ( ( $special_order || $include_process_order ) && ! empty( $essay_type ) ) {
			// When ordering by process_order or including it in results, we need to join with the essay_type table.
			$query = "SELECT f.*, et.process_order 
                 FROM {$this->api_feed_table} f
                 JOIN {$this->essay_type_table} et ON f.id = et.api_feed_id";

			$where_clauses = array( 'et.essay_type = %s' );
			$where_values  = array( $essay_type );

			if ( 0 < $feed_id ) {
				$where_clauses[] = 'f.id = %d';
				$where_values[]  = $feed_id;
			}

			if ( ! empty( $feedback_criteria ) ) {
				$where_clauses[] = 'f.feedback_criteria = %s';
				$where_values[]  = $feedback_criteria;
			}

			if ( ! empty( $apply_to ) ) {
				$where_clauses[] = 'f.apply_to = %s';
				$where_values[]  = $apply_to;
			}

			// Add WHERE clause.
			$query .= ' WHERE ' . implode( ' AND ', $where_clauses );

			// Add ORDER BY.
			$query .= " ORDER BY et.process_order {$args['order_direction']}";

			// Add LIMIT and OFFSET.
			$query .= $this->wpdb->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );

			// Prepare the full query.
			$query = $this->wpdb->prepare( $query, $where_values );

		} else {
			// Standard query without process_order.
			$query         = "SELECT * FROM $this->api_feed_table";
			$where_clauses = array();
			$where_values  = array();

			if ( 0 < $feed_id ) {
				$where_clauses[] = 'id = %d';
				$where_values[]  = $feed_id;
			}

			if ( ! empty( $feedback_criteria ) ) {
				$where_clauses[] = 'feedback_criteria = %s';
				$where_values[]  = $feedback_criteria;
			}

			if ( ! empty( $apply_to ) ) {
				$where_clauses[] = 'apply_to = %s';
				$where_values[]  = $apply_to;
			}

			// Handle essay_type filtering here.
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
						array( 'error' => $this->wpdb->last_error )
					);
				}

				if ( ! empty( $feed_ids ) ) {
					$where_clauses[] = 'id IN (' . implode( ',', $feed_ids ) . ')';
				} else {
					// No feeds with this essay type.
					return array();
				}
			}

			// Add WHERE clause if needed.
			if ( ! empty( $where_clauses ) ) {
				$query .= ' WHERE ' . implode( ' AND ', $where_clauses );
			}

			// Add ORDER BY.
			$query .= " ORDER BY {$args['order_by']} {$args['order_direction']}";

			// Add LIMIT and OFFSET.
			$query .= $this->wpdb->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );

			// Prepare the full query if where values exist.
			if ( ! empty( $where_values ) ) {
				$query = $this->wpdb->prepare( $query, $where_values );
			}
		}

		// Execute the query.
		$feeds = $this->wpdb->get_results( $query, ARRAY_A );

		// Check for database errors.
		if ( $this->wpdb->last_error ) {
			return new WP_Error(
				'database_query_error',
				'Database error retrieving API feeds',
				array(
					'error' => $this->wpdb->last_error,
					'query' => $query,
				)
			);
		}

		if ( empty( $feeds ) ) {
			return array();
		}

		// Process includes.
		foreach ( $feeds as &$feed ) {
			// Always decode meta from JSON for internal use.
			if ( isset( $feed['meta'] ) ) {

				// Only include meta in response if requested.
				if ( ! in_array( 'meta', $args['include'], true ) ) {
					unset( $feed['meta'] );
				}
			}

			// If process_order isn't included and wasn't part of the query, get it separately.
			if ( in_array( 'process_order', $args['include'], true ) && ! isset( $feed['process_order'] ) && ! empty( $essay_type ) ) {
				$process_order = $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT process_order FROM $this->essay_type_table 
					WHERE api_feed_id = %d AND essay_type = %s",
						$feed['id'],
						$essay_type
					)
				);

				if ( null !== $process_order ) {
					$feed['process_order'] = (int) $process_order;
				}
			}

			// Include essay types if requested.
			if ( in_array( 'essay_types', $args['include'], true ) ) {
				$essay_types_result = $this->get_api_feed_essay_types( $feed['id'] );

				// Check if the result is a WP_Error.
				if ( is_wp_error( $essay_types_result ) ) {
					return $essay_types_result;
				}

				$feed['essay_types'] = $essay_types_result;
			}

			// Include rate limits if requested.
			if ( in_array( 'rate_limits', $args['include'], true ) ) {
				try {
					$feed['rate_limits'] = $this->rate_limit_db->get_rate_limits( array( 'feed_id' => $feed['id'] ) );
				} catch ( \Exception $e ) {
					return new WP_Error(
						500,
						sprintf( 'Error retrieving rate limits for feed: %s', $feed['id'] ),
						array( 'error' => $e->getMessage() )
					);
				}
			}
		}

		return $feeds;
	}

	/**
	 * Helper function to get essay types for an API feed.
	 *
	 * @param int $feed_id API feed ID.
	 * @return array|WP_Error Essay types associated with the feed or WP_Error on failure.
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
				array( 'error' => $this->wpdb->last_error )
			);
		}

		return $results;
	}

	/**
	 * Starts a database transaction.
	 *
	 * @return void
	 */
	public function start_transaction() {
		$this->wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Commits a database transaction.
	 *
	 * @return void
	 */
	public function commit() {
		$this->wpdb->query( 'COMMIT' );
	}

	/**
	 * Rolls back a database transaction.
	 *
	 * @return void
	 */
	public function rollback() {
		$this->wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Updates or creates a feed record.
	 *
	 * @param array $feed_data Feed data to update or insert.
	 * @return int Feed ID of the updated or created record.
	 * @throws \Exception When database operation fails.
	 */
	public function update_feed( $feed_data ): int {
		$data = array(
			'feedback_criteria' => $feed_data['feedName'],
			'feed_title'        => $feed_data['feedTitle'],
			'feed_desc'         => $feed_data['feedDesc'] ?? '',
			'apply_to'          => $feed_data['applyTo'],
			'meta'              => wp_json_encode( array( 'steps' => $feed_data['steps'] ) ),
		);

		if ( empty( $feed_data['id'] ) ) {
			// Insert new feed.
			$result = $this->wpdb->insert(
				$this->api_feed_table,
				$data,
				array( '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false === $result ) {
				throw new \Exception( 'Failed to insert feed: ' . esc_html( $this->wpdb->last_error ) );
			}

			$feed_id = $this->wpdb->insert_id;
		} else {
			// Update existing feed.
			$result = $this->wpdb->update(
				$this->api_feed_table,
				$data,
				array( 'id' => $feed_data['id'] ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				throw new \Exception( 'Failed to update feed: ' . esc_html( $this->wpdb->last_error ) );
			}

			$feed_id = $feed_data['id'];
		}

		return $feed_id;
	}

	/**
	 * Updates essay types associated with a feed.
	 *
	 * @param int   $feed_id        The feed ID.
	 * @param array $new_essay_types Array of essay types.
	 * @throws \Exception When database operation fails.
	 */
	public function update_essay_types( $feed_id, $new_essay_types ) {
		$table = $this->essay_type_table;
		// Fetch current essay types for this feed.
		$query   = $this->wpdb->prepare(
			"SELECT id, essay_type, process_order FROM $table WHERE api_feed_id = %d ORDER BY process_order",
			$feed_id
		);
		$current = $this->wpdb->get_results( $query, ARRAY_A );

		// Process new list: insert new records as needed.
		foreach ( $new_essay_types as $new_index => $essay_type ) {
			// Try to find a matching current record.
			$found = false;
			foreach ( $current as $record ) {
				if ( $record['essay_type'] === $essay_type ) {
					$found = true;
					break;
				}
			}
			// Insert if not found.
			if ( ! $found ) {
				$result = $this->wpdb->insert(
					$table,
					array(
						'api_feed_id'   => $feed_id,
						'essay_type'    => $essay_type,
						'process_order' => $new_index,
					),
					array( '%d', '%s', '%d' )
				);
				if ( false === $result ) {
					throw new \Exception( 'Failed to insert essay type: ' . esc_html( $this->wpdb->last_error ) );
				}
			}
		}

		// Delete any essay types that are no longer present.
		foreach ( $current as $record ) {
			if ( ! in_array( $record['essay_type'], $new_essay_types, true ) ) {
				$this->wpdb->delete(
					$table,
					array( 'id' => $record['id'] ),
					array( '%d' )
				);
			}
		}
	}

	/**
	 * Updates the process order for a specific essay type.
	 *
	 * @param int    $api_feed_id   The API feed ID.
	 * @param string $essay_type    The essay type.
	 * @param int    $process_order The new process order.
	 * @return bool True on success.
	 * @throws \Exception When database operation fails.
	 */
	public function update_process_order( int $api_feed_id, string $essay_type, int $process_order ): bool {
		$table = $this->essay_type_table;

		$result = $this->wpdb->update(
			$table,
			array( 'process_order' => $process_order ),
			array(
				'api_feed_id' => $api_feed_id,
				'essay_type'  => $essay_type,
			),
			array( '%d' ),
			array( '%d', '%s' )
		);

		if ( false === $result ) {
			throw new \Exception( 'Failed to update process order: ' . esc_html( $this->wpdb->last_error ) );
		}

		return true;
	}
}
