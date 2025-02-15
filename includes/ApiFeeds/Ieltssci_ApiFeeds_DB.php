<?php

namespace IeltsScienceLMS\ApiFeeds;

class Ieltssci_ApiFeeds_DB {
	private string $api_feed_table;
	private string $essay_type_table;
	private \wpdb $wpdb;

	public function __construct( \wpdb $wpdb = null ) {
		global $wpdb;
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
		$this->api_feed_table = "{$this->wpdb->prefix}ieltssci_api_feed";
		$this->essay_type_table = "{$this->wpdb->prefix}ieltssci_api_feed_essay_type";
	}

	public function get_all_api_feeds_settings(): array {
		$sql = "
            SELECT 
                f.id,
                f.feedback_criteria,
                f.feed_title,
                f.feed_desc,
                f.apply_to,
                f.meta,
                et.essay_type,
                et.process_order
            FROM {$this->api_feed_table} f
            LEFT JOIN {$this->essay_type_table} et ON f.id = et.api_feed_id
            ORDER BY f.id, et.process_order";

		return $this->wpdb->get_results( $sql, ARRAY_A );
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
					// Update process_order if different
					if ( (int) $record['process_order'] !== $new_index ) {
						$this->wpdb->update(
							$table,
							[ 'process_order' => $new_index ],
							[ 'id' => $record['id'] ],
							[ '%d' ],
							[ '%d' ]
						);
					}
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
}
