<?php

namespace IeltsScienceLMS\ApiKeys;

class Ieltssci_ApiKeys_DB {
	private \wpdb $wpdb;
	private string $table_name;

	public function __construct( \wpdb $wpdb = null ) {
		global $wpdb;
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
		$this->table_name = $this->wpdb->prefix . 'ieltssci_api_key';
	}

	public function get_api_keys(): array {
		$results = $this->wpdb->get_results(
			"SELECT * FROM {$this->table_name}",
			ARRAY_A
		);

		$api_keys = [];
		foreach ( $results as $row ) {
			$provider = $row['api_provider'];
			if ( ! isset( $api_keys[ $provider ] ) ) {
				$api_keys[ $provider ] = [ 'keys' => [] ];
			}

			$api_keys[ $provider ]['keys'][] = [ 
				'id' => (int) $row['id'],
				'meta' => json_decode( $row['meta'], true ),
				'usage_count' => (int) $row['usage_count'],
			];
		}

		return $api_keys;
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
			$updated_ids = [];

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

		} catch (\Exception $e) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	private function save_api_key( string $provider, array $key ): ?int {
		$data = [ 
			'api_provider' => $provider,
			'meta' => wp_json_encode( $key['meta'] ),
		];
		$formats = [ '%s', '%s' ];

		// Add usage count if provided
		if ( isset( $key['usage_count'] ) ) {
			$data['usage_count'] = (int) $key['usage_count'];
			$formats[] = '%d';
		}

		if ( ! empty( $key['id'] ) ) {
			$result = $this->wpdb->update(
				$this->table_name,
				$data,
				[ 'id' => $key['id'] ],
				$formats,
				[ '%d' ]
			);
			return $result !== false ? $key['id'] : null;
		} else {
			$result = $this->wpdb->insert( $this->table_name, $data, $formats );
			return $result ? $this->wpdb->insert_id : null;
		}
	}
}
