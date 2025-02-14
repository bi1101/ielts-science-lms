<?php

namespace IeltsScienceLMS\Settings;

class Ieltssci_Settings_DB {
	private string $table_name;
	private \wpdb $wpdb;

	public function __construct( \wpdb $wpdb = null ) {
		global $wpdb;
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
		$this->table_name = "{$wpdb->prefix}ieltssci_api_feed";
	}

	public function get_all_settings(): array {
		$sql = "SELECT * FROM {$this->table_name} ORDER BY process_order ASC";
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

	public function get_existing_essay_types( string $feedback_criteria, string $apply_to, int $id ): array {
		if ( $id == 0 ) {
			return [];
		}

		$sql = $this->wpdb->prepare(
			"SELECT DISTINCT essay_type FROM {$this->table_name} WHERE feedback_criteria = %s AND apply_to = %s AND id = %d",
			$feedback_criteria,
			$apply_to,
			$id
		);
		return $this->wpdb->get_col( $sql ) ?? [];
	}

	public function delete_essay_types( string $feedback_criteria, string $apply_to, array $essay_types ) {
		$placeholders = implode( ', ', array_fill( 0, count( $essay_types ), '%s' ) );
		$sql = $this->wpdb->prepare(
			"DELETE FROM {$this->table_name} WHERE feedback_criteria = %s AND apply_to = %s AND essay_type IN ($placeholders)",
			array_merge( [ $feedback_criteria, $apply_to ], $essay_types )
		);
		return $this->wpdb->query( $sql );
	}

	public function insert_feed_setting( array $data, array $meta_data ): int|false {
		$data['meta'] = wp_json_encode( $meta_data );

		$result = $this->wpdb->insert(
			$this->table_name,
			$data,
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $result ) {
			error_log( "Database error (insert): {$this->wpdb->last_error}" );
			return false;
		}

		return (int) $this->wpdb->insert_id;
	}

	public function update_feed_setting( int $id, array $data, array $meta_data ): bool {
		$data['meta'] = wp_json_encode( $meta_data );

		$result = $this->wpdb->update(
			$this->table_name,
			$data,
			[ 'id' => $id ],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $result ) {
			error_log( "Database error (update): {$this->wpdb->last_error}" );
			return false;
		}

		if ( 0 === $result ) {
			$exists = $this->wpdb->get_var( $this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE id = %d",
				$id
			) );
			if ( ! $exists ) {
				error_log( "Attempted to update non-existent row with ID: $id" );
				return false;
			}
		}

		return true;
	}
}
