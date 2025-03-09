<?php
/**
 * Rate Limit Database Handler
 *
 * Handles database operations for rate limiting functionality.
 *
 * @package IELTS_Science_LMS\RateLimits
 */

namespace IeltsScienceLMS\RateLimits;

use wpdb;
use Exception;

/**
 * Rate Limit Database Class
 *
 * Manages database operations for rate limiting rules, including CRUD operations
 * and relationship management between rules, roles, and API feeds.
 */
class Ieltssci_RateLimit_DB {
	/**
	 * WordPress database object.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Rate limit rules table name.
	 *
	 * @var string
	 */
	private $rate_limit_table;

	/**
	 * Role-rule relationship table name.
	 *
	 * @var string
	 */
	private $role_rule_table;

	/**
	 * API feed-rule relationship table name.
	 *
	 * @var string
	 */
	private $api_feed_rule_table;

	/**
	 * Constructor.
	 *
	 * Initializes the database tables used by the rate limiting system.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb                = $wpdb ?? $GLOBALS['wpdb'];
		$this->rate_limit_table    = $this->wpdb->prefix . 'ieltssci_rate_limit_rule';
		$this->role_rule_table     = $this->wpdb->prefix . 'ieltssci_role_rate_limit_rule';
		$this->api_feed_rule_table = $this->wpdb->prefix . 'ieltssci_api_feed_rate_limit_rule';
	}

	/**
	 * Get rate limits with optional filtering
	 *
	 * @param array $args {
	 *     Optional. Array of arguments for filtering rate limits.
	 *     @type int    $rule_id          Specific rate limit rule ID to retrieve.
	 *     @type int    $feed_id          Filter rate limits by specific API feed ID.
	 *     @type string $role             Filter rate limits by user role.
	 *     @type string $time_period_type Filter by time period type.
	 *     @type array  $include          Specify what related data to include. Options: 'roles', 'feeds'.
	 *                                   Default includes both.
	 * }
	 * @return array Array of rate limit rules
	 */
	public function get_rate_limits( array $args = array() ) {
		$defaults = array(
			'rule_id'          => 0,
			'feed_id'          => 0,
			'role'             => '',
			'time_period_type' => '',
			'include'          => array( 'roles', 'feeds' ),
		);

		$args = wp_parse_args( $args, $defaults );

		// Ensure include is an array.
		if ( ! is_array( $args['include'] ) ) {
			$args['include'] = empty( $args['include'] ) ? array() : array( $args['include'] );
		}

		// Start building the query.
		$query         = "SELECT DISTINCT r.* FROM {$this->rate_limit_table} r";
		$where_clauses = array();
		$where_values  = array();
		$joins         = array();

		// Add conditions based on args.
		if ( ! empty( $args['rule_id'] ) ) {
			$where_clauses[] = 'r.id = %d';
			$where_values[]  = (int) $args['rule_id'];
		}

		if ( ! empty( $args['feed_id'] ) ) {
			$joins[]         = "JOIN {$this->api_feed_rule_table} afr ON r.id = afr.rate_limit_rule_id";
			$where_clauses[] = 'afr.api_feed_id = %d';
			$where_values[]  = (int) $args['feed_id'];
		}

		if ( ! empty( $args['role'] ) ) {
			$joins[]         = "JOIN {$this->role_rule_table} rr ON r.id = rr.rate_limit_rule_id";
			$where_clauses[] = 'rr.role = %s';
			$where_values[]  = $args['role'];
		}

		if ( ! empty( $args['time_period_type'] ) ) {
			$where_clauses[] = 'r.time_period_type = %s';
			$where_values[]  = $args['time_period_type'];
		}

		// Add joins to the query if needed.
		if ( ! empty( $joins ) ) {
			$query .= ' ' . implode( ' ', array_unique( $joins ) );
		}

		// Add WHERE clause if needed.
		if ( ! empty( $where_clauses ) ) {
			$query .= ' WHERE ' . implode( ' AND ', $where_clauses );
		}

		// Prepare the query if there are where values.
		if ( ! empty( $where_values ) ) {
			$query = $this->wpdb->prepare( $query, $where_values );
		}

		// Execute the query.
		$rules = $this->wpdb->get_results( $query, ARRAY_A );

		if ( ! $rules ) {
			return array();
		}

		// Process the results.
		$final_rules = array();

		foreach ( $rules as $rule ) {
			$rule_id = $rule['id'];

			$role_rows = array();
			if ( in_array( 'roles', $args['include'], true ) ) {
				$role_rows = $this->wpdb->get_col(
					$this->wpdb->prepare(
						"SELECT role FROM {$this->role_rule_table} WHERE rate_limit_rule_id = %d",
						$rule_id
					)
				);
			}

			$api_feed_rows = array();
			if ( in_array( 'feeds', $args['include'], true ) ) {
				$api_feed_rows = $this->wpdb->get_col(
					$this->wpdb->prepare(
						"SELECT api_feed_id FROM {$this->api_feed_rule_table} WHERE rate_limit_rule_id = %d",
						$rule_id
					)
				);
			}

			$rule['roles']      = $role_rows;
			$rule['feed_ids']   = $api_feed_rows;
			$rule['limit_rule'] = json_decode( $rule['limit_rule'], true );
			$rule['message']    = json_decode( $rule['message'], true );
			$final_rules[]      = $rule;
		}

		return $final_rules;
	}

	/**
	 * Update rate limits by inserting, updating, and deleting rules as needed.
	 *
	 * @param array $rules Array of rate limit rules to update.
	 * @return array Updated rate limit rules.
	 * @throws Exception If there is a database error.
	 */
	public function update_rate_limits( array $rules ) {
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$existing_ids = $this->wpdb->get_col( "SELECT id FROM {$this->rate_limit_table}" );
			$new_ids      = array();

			foreach ( $rules as $rule ) {
				$rule_id = $this->save_rule( $rule );
				if ( ! $rule_id ) {
					throw new Exception( 'Failed to save rule' );
				}
				$new_ids[] = $rule_id;
			}

			$this->delete_removed_rules( $existing_ids, $new_ids );
			$this->wpdb->query( 'COMMIT' );

			// Return the updated rules.
			return $this->get_rate_limits();

		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Save a single rate limit rule.
	 *
	 * @param array $rule Rate limit rule data.
	 * @return int|bool Rule ID if successful, false otherwise.
	 * @throws Exception If there is a database error.
	 */
	private function save_rule( array $rule ) {
		$rule_data = $this->prepare_rule_data( $rule );

		if ( isset( $rule['ruleId'] ) && ! empty( $rule['ruleId'] ) ) {
			$rule_id = (int) $rule['ruleId'];
			$success = $this->wpdb->update(
				$this->rate_limit_table,
				$rule_data['data'],
				array( 'id' => $rule_id ),
				$rule_data['format'],
				array( '%d' )
			);
		} else {
			$success = $this->wpdb->insert(
				$this->rate_limit_table,
				$rule_data['data'],
				$rule_data['format']
			);
			$rule_id = $this->wpdb->insert_id;
		}

		if ( false === $success ) {
			throw new Exception( 'Failed to save rate limit rule' );
		}

		$this->save_rule_associations( $rule_id, $rule['subject'] );
		return $rule_id;
	}

	/**
	 * Save rule associations for roles and API feeds.
	 *
	 * @param int   $rule_id Rule ID to associate with.
	 * @param array $subject Subject data containing role and API feed information.
	 * @throws Exception If there is a database error.
	 */
	private function save_rule_associations( int $rule_id, array $subject ) {
		// Clear existing associations.
		$this->wpdb->delete( $this->role_rule_table, array( 'rate_limit_rule_id' => $rule_id ), array( '%d' ) );
		$this->wpdb->delete( $this->api_feed_rule_table, array( 'rate_limit_rule_id' => $rule_id ), array( '%d' ) );

		// Save roles.
		$roles = array_filter( array_map( 'trim', explode( ',', $subject['role'] ) ) );
		foreach ( $roles as $role ) {
			$success = $this->wpdb->insert(
				$this->role_rule_table,
				array(
					'role'               => $role,
					'rate_limit_rule_id' => $rule_id,
				),
				array( '%s', '%d' )
			);
			if ( false === $success ) {
				throw new Exception( 'Failed to insert role rule' );
			}
		}

		// Save API feeds.
		$feeds = array_filter( array_map( 'trim', explode( ',', $subject['apiFeed'] ) ) );
		foreach ( $feeds as $feed ) {
			$success = $this->wpdb->insert(
				$this->api_feed_rule_table,
				array(
					'api_feed_id'        => $feed,
					'rate_limit_rule_id' => $rule_id,
				),
				array( '%s', '%d' )
			);
			if ( false === $success ) {
				throw new Exception( 'Failed to insert API feed rule' );
			}
		}
	}

	/**
	 * Delete rules that are no longer needed.
	 *
	 * @param array $existing_ids IDs of existing rules.
	 * @param array $new_ids IDs of new or updated rules.
	 */
	private function delete_removed_rules( array $existing_ids, array $new_ids ) {
		$ids_to_delete = array_diff( $existing_ids, $new_ids );
		if ( ! empty( $ids_to_delete ) ) {
			$ids_placeholder = implode( ',', array_fill( 0, count( $ids_to_delete ), '%d' ) );

			// Fix placeholder warnings by properly formatting the query and parameters.
			$query_role = "DELETE FROM {$this->role_rule_table} WHERE rate_limit_rule_id IN ($ids_placeholder)";
			$this->wpdb->query(
				$this->wpdb->prepare( $query_role, $ids_to_delete )
			);

			$query_feed = "DELETE FROM {$this->api_feed_rule_table} WHERE rate_limit_rule_id IN ($ids_placeholder)";
			$this->wpdb->query(
				$this->wpdb->prepare( $query_feed, $ids_to_delete )
			);

			$query_rule = "DELETE FROM {$this->rate_limit_table} WHERE id IN ($ids_placeholder)";
			$this->wpdb->query(
				$this->wpdb->prepare( $query_rule, $ids_to_delete )
			);
		}
	}

	/**
	 * Prepare rule data for insertion/update.
	 *
	 * @param array $rule Rule data.
	 * @return array Prepared data and format arrays.
	 */
	private function prepare_rule_data( array $rule ) {
		$time_period_type = $rule['timePeriod']['type'];
		$limit_rule_data  = $rule['timePeriod'];
		unset( $limit_rule_data['type'] );

		return array(
			'data'   => array(
				'rate_limit'       => $rule['limit'],
				'time_period_type' => $time_period_type,
				'limit_rule'       => wp_json_encode( $limit_rule_data ),
				'message'          => wp_json_encode( $rule['message'] ),
			),
			'format' => array( '%d', '%s', '%s', '%s' ),
		);
	}
}
