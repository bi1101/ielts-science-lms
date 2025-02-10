<?php

namespace IeltsScienceLMS\Core;

class Ieltssci_Settings_DB {
	private $table_name;

	public function __construct() {
		global $wpdb;
		$this->table_name = "{$wpdb->prefix}ieltssci_api_feed";
	}

	public function get_all_settings() {
		global $wpdb;
		$sql = "SELECT * FROM {$this->table_name} ORDER BY process_order ASC";
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	public function start_transaction() {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	public function commit() {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}

	public function rollback() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}

	public function get_existing_essay_types( string $feedback_criteria, string $apply_to, int $id ): array {
		global $wpdb;
		if ( $id == 0 ) {
			return [];
		}

		$sql = $wpdb->prepare(
			"SELECT DISTINCT essay_type FROM {$this->table_name} WHERE feedback_criteria = %s AND apply_to = %s AND id = %d",
			$feedback_criteria,
			$apply_to,
			$id
		);
		return $wpdb->get_col( $sql ) ?? [];
	}

	public function delete_essay_types( string $feedback_criteria, string $apply_to, array $essay_types ) {
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $essay_types ), '%s' ) );
		$sql = $wpdb->prepare(
			"DELETE FROM {$this->table_name} WHERE feedback_criteria = %s AND apply_to = %s AND essay_type IN ($placeholders)",
			array_merge( [ $feedback_criteria, $apply_to ], $essay_types )
		);
		return $wpdb->query( $sql );
	}

	public function insert_feed_setting( array $data, array $meta_data ): int|false {
		global $wpdb;
		$data['meta'] = wp_json_encode( $meta_data );

		$result = $wpdb->insert(
			$this->table_name,
			$data,
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $result ) {
			error_log( "Database error (insert): {$wpdb->last_error}" );
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	public function update_feed_setting( int $id, array $data, array $meta_data ): bool {
		global $wpdb;
		$data['meta'] = wp_json_encode( $meta_data );

		$result = $wpdb->update(
			$this->table_name,
			$data,
			[ 'id' => $id ],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $result ) {
			error_log( "Database error (update): {$wpdb->last_error}" );
			return false;
		}

		if ( 0 === $result ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table_name} WHERE id = %d", $id ) );
			if ( ! $exists ) {
				error_log( "Attempted to update non-existent row with ID: $id" );
				return false;
			}
		}

		return true;
	}
}
