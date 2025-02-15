<?php

namespace IeltsScienceLMS\RateLimits;

class Ieltssci_RateLimit_DB {
	private \wpdb $wpdb;
	private string $rate_limit_table;
	private string $role_rule_table;
	private string $api_feed_rule_table;

	public function __construct( \wpdb $wpdb = null ) {
		global $wpdb;
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
		$this->rate_limit_table = $this->wpdb->prefix . 'ieltssci_rate_limit_rule';
		$this->role_rule_table = $this->wpdb->prefix . 'ieltssci_role_rate_limit_rule';
		$this->api_feed_rule_table = $this->wpdb->prefix . 'ieltssci_api_feed_rate_limit_rule';
	}

	public function get_rate_limits(): array {
		$rules = $this->wpdb->get_results( "SELECT * FROM {$this->rate_limit_table}", ARRAY_A );
		$finalRules = [];

		if ( $rules ) {
			foreach ( $rules as $rule ) {
				$ruleId = $rule['id'];
				$roleRows = $this->wpdb->get_col(
					$this->wpdb->prepare(
						"SELECT role FROM {$this->role_rule_table} WHERE rate_limit_rule_id = %d",
						$ruleId
					)
				);
				$apiFeedRows = $this->wpdb->get_col(
					$this->wpdb->prepare(
						"SELECT api_feed_id FROM {$this->api_feed_rule_table} WHERE rate_limit_rule_id = %d",
						$ruleId
					)
				);

				$finalRules[] = $this->format_rule_for_response( $rule, $roleRows, $apiFeedRows );
			}
		}

		return $finalRules;
	}

	public function update_rate_limits( array $rules ) {
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$existingIDs = $this->wpdb->get_col( "SELECT id FROM {$this->rate_limit_table}" );
			$newIDs = [];

			foreach ( $rules as $rule ) {
				$rule_id = $this->save_rule( $rule );
				if ( ! $rule_id ) {
					throw new \Exception( 'Failed to save rule' );
				}
				$newIDs[] = $rule_id;
			}

			$this->delete_removed_rules( $existingIDs, $newIDs );
			$this->wpdb->query( 'COMMIT' );

			// Return the updated rules
			return $this->get_rate_limits();

		} catch (\Exception $e) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	private function save_rule( array $rule ) {
		$rule_data = $this->prepare_rule_data( $rule );

		if ( isset( $rule['ruleId'] ) && ! empty( $rule['ruleId'] ) ) {
			$rule_id = (int) $rule['ruleId'];
			$success = $this->wpdb->update(
				$this->rate_limit_table,
				$rule_data['data'],
				[ 'id' => $rule_id ],
				$rule_data['format'],
				[ '%d' ]
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
			throw new \Exception( 'Failed to save rate limit rule' );
		}

		$this->save_rule_associations( $rule_id, $rule['subject'] );
		return $rule_id;
	}

	private function save_rule_associations( int $rule_id, array $subject ) {
		// Clear existing associations
		$this->wpdb->delete( $this->role_rule_table, [ 'rate_limit_rule_id' => $rule_id ], [ '%d' ] );
		$this->wpdb->delete( $this->api_feed_rule_table, [ 'rate_limit_rule_id' => $rule_id ], [ '%d' ] );

		// Save roles
		$roles = array_filter( array_map( 'trim', explode( ',', $subject['role'] ) ) );
		foreach ( $roles as $role ) {
			$success = $this->wpdb->insert(
				$this->role_rule_table,
				[ 
					'role' => $role,
					'rate_limit_rule_id' => $rule_id,
				],
				[ '%s', '%d' ]
			);
			if ( false === $success ) {
				throw new \Exception( 'Failed to insert role rule' );
			}
		}

		// Save API feeds
		$feeds = array_filter( array_map( 'trim', explode( ',', $subject['apiFeed'] ) ) );
		foreach ( $feeds as $feed ) {
			$success = $this->wpdb->insert(
				$this->api_feed_rule_table,
				[ 
					'api_feed_id' => $feed,
					'rate_limit_rule_id' => $rule_id,
				],
				[ '%s', '%d' ]
			);
			if ( false === $success ) {
				throw new \Exception( 'Failed to insert API feed rule' );
			}
		}
	}

	private function delete_removed_rules( array $existingIDs, array $newIDs ) {
		$idsToDelete = array_diff( $existingIDs, $newIDs );
		if ( ! empty( $idsToDelete ) ) {
			$idsPlaceholder = implode( ',', array_fill( 0, count( $idsToDelete ), '%d' ) );
			$this->wpdb->query( $this->wpdb->prepare(
				"DELETE FROM {$this->role_rule_table} WHERE rate_limit_rule_id IN ($idsPlaceholder)",
				...$idsToDelete
			) );
			$this->wpdb->query( $this->wpdb->prepare(
				"DELETE FROM {$this->api_feed_rule_table} WHERE rate_limit_rule_id IN ($idsPlaceholder)",
				...$idsToDelete
			) );
			$this->wpdb->query( $this->wpdb->prepare(
				"DELETE FROM {$this->rate_limit_table} WHERE id IN ($idsPlaceholder)",
				...$idsToDelete
			) );
		}
	}

	private function prepare_rule_data( array $rule ) {
		$time_period_type = $rule['timePeriod']['type'];
		$limit_rule_data = $rule['timePeriod'];
		unset( $limit_rule_data['type'] );

		return [ 
			'data' => [ 
				'rate_limit' => $rule['limit'],
				'time_period_type' => $time_period_type,
				'limit_rule' => wp_json_encode( $limit_rule_data ),
				'message' => wp_json_encode( $rule['message'] ),
			],
			'format' => [ '%d', '%s', '%s', '%s' ],
		];
	}

	private function format_rule_for_response( array $rule, array $roleRows, array $apiFeedRows ) {
		return [ 
			'ruleId' => (int) $rule['id'],
			'subject' => [ 
				'role' => implode( ',', $roleRows ),
				'apiFeed' => implode( ',', $apiFeedRows ),
			],
			'timePeriod' => array_merge(
				[ 'type' => $rule['time_period_type'] ],
				json_decode( $rule['limit_rule'], true ) ?: []
			),
			'limit' => (int) $rule['rate_limit'],
			'message' => json_decode( $rule['message'], true ) ?: [],
		];
	}
}
