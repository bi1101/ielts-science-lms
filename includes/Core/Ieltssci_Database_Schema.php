<?php

namespace IeltsScienceLMS\Core;
class Ieltssci_Database_Schema {
	private const TABLE_PREFIX = 'ieltssci_';
	private $db_version = '0.0.1';
	private \wpdb $wpdb;

	public function __construct( \wpdb $wpdb = null ) {
		global $wpdb;
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	public function create_tables() {
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			$this->create_api_feeds_table();
			$this->create_api_feed_essay_type_table();
			$this->create_rate_limit_rule_table();
			$this->create_api_feed_rate_limit_rule_table();
			$this->create_role_rate_limit_rule_table();

			update_option( 'ieltssci_db_version', $this->db_version );
			$this->wpdb->query( 'COMMIT' );
			return;
		} catch (\Exception $e) {
			$this->wpdb->query( 'ROLLBACK' );
			error_log( 'Database creation failed: ' . $e->getMessage() );
			return new \WP_Error( 500, 'Database creation failed' );
		}
	}

	private function create_api_feeds_table() {
		$table_name = $this->wpdb->prefix . self::TABLE_PREFIX . 'api_feed';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            feedback_criteria varchar(191) NOT NULL,
            feed_title varchar(191) NOT NULL,
            feed_desc text DEFAULT NULL,
            apply_to varchar(50) NOT NULL,
            meta longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY feedback_criteria (feedback_criteria(191))
        ) $charset_collate";

		return $this->execute_sql( $sql );
	}

	private function create_api_feed_essay_type_table() {
		$table_name = $this->wpdb->prefix . self::TABLE_PREFIX . 'api_feed_essay_type';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            api_feed_id bigint(20) UNSIGNED NOT NULL,
            essay_type varchar(191) NOT NULL,
            process_order int(11) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY api_feed_id (api_feed_id),
            KEY essay_type (essay_type(191)),
            CONSTRAINT fk_essay_type_api_feed 
                FOREIGN KEY (api_feed_id) 
                REFERENCES {$this->wpdb->prefix}" . self::TABLE_PREFIX . "api_feed(id) 
                ON DELETE CASCADE
        ) $charset_collate";

		return $this->execute_sql( $sql );
	}

	private function create_rate_limit_rule_table() {
		$table_name = $this->wpdb->prefix . self::TABLE_PREFIX . 'rate_limit_rule';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            rate_limit int(11) NOT NULL,
            time_period_type varchar(50) NOT NULL,
            limit_rule json NOT NULL,
            message json NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY time_period_type (time_period_type(50))
        ) $charset_collate";

		return $this->execute_sql( $sql );
	}

	private function create_api_feed_rate_limit_rule_table() {
		$table_name = $this->wpdb->prefix . self::TABLE_PREFIX . 'api_feed_rate_limit_rule';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            api_feed_id bigint(20) UNSIGNED NOT NULL,
            rate_limit_rule_id bigint(20) UNSIGNED NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY api_feed_id (api_feed_id),
            KEY rate_limit_rule_id (rate_limit_rule_id),
            CONSTRAINT fk_rate_limit_api_feed 
                FOREIGN KEY (api_feed_id) 
                REFERENCES {$this->wpdb->prefix}" . self::TABLE_PREFIX . "api_feed(id) 
                ON DELETE CASCADE,
            CONSTRAINT fk_rate_limit_rule 
                FOREIGN KEY (rate_limit_rule_id) 
                REFERENCES {$this->wpdb->prefix}" . self::TABLE_PREFIX . "rate_limit_rule(id) 
                ON DELETE CASCADE
        ) $charset_collate";

		return $this->execute_sql( $sql );
	}

	private function create_role_rate_limit_rule_table() {
		$table_name = $this->wpdb->prefix . self::TABLE_PREFIX . 'role_rate_limit_rule';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            role varchar(50) NOT NULL,
            rate_limit_rule_id bigint(20) UNSIGNED NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY role (role(50)),
            KEY rate_limit_rule_id (rate_limit_rule_id),
            CONSTRAINT fk_role_rate_limit_rule 
                FOREIGN KEY (rate_limit_rule_id) 
                REFERENCES {$this->wpdb->prefix}" . self::TABLE_PREFIX . "rate_limit_rule(id) 
                ON DELETE CASCADE
        ) $charset_collate";

		return $this->execute_sql( $sql );
	}


	private function execute_sql( $sql ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );
		if ( ! empty( $this->wpdb->last_error ) ) {
			throw new \Exception( $this->wpdb->last_error );
		}
		return true;
	}

	public function needs_upgrade() {
		$current_version = get_option( 'ieltssci_db_version', '0' );
		return version_compare( $current_version, $this->db_version, '<' );
	}
}