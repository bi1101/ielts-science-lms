<?php

namespace IeltsScienceLMS\Core;

class Ieltssci_Database_Schema {
	private const TABLE_PREFIX = 'ieltssci_';
	private $db_version        = '0.0.2'; // Updated version number
	private \wpdb $wpdb;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public function create_tables() {
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			$this->create_api_feeds_table();
			$this->create_api_feed_essay_type_table();
			$this->create_rate_limit_rule_table();
			$this->create_api_feed_rate_limit_rule_table();
			$this->create_role_rate_limit_rule_table();
			$this->create_api_key_table();

			// Add new tables
			$this->create_essays_table();
			$this->create_segment_table();
			$this->create_segment_feedback_table();
			$this->create_essay_feedback_table();

			update_option( 'ieltssci_db_version', $this->db_version );
			$this->wpdb->query( 'COMMIT' );
			return;
		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			error_log( 'Database creation failed: ' . $e->getMessage() );
			return new \WP_Error( 500, 'Database creation failed' );
		}
	}

	private function create_api_feeds_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'api_feed';
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
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'api_feed_essay_type';
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
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'rate_limit_rule';
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
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'api_feed_rate_limit_rule';
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
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'role_rate_limit_rule';
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

	private function create_api_key_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'api_key';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            api_provider varchar(50) NOT NULL,
            meta json NOT NULL,
            usage_count bigint(20) UNSIGNED DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY api_provider (api_provider)
        ) $charset_collate";

		return $this->execute_sql( $sql );
	}

	private function create_essays_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'essays';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid varchar(36) NOT NULL,
			original_id bigint(20) UNSIGNED DEFAULT NULL,
			ocr_image_ids text DEFAULT NULL,
			chart_image_ids text DEFAULT NULL,
			essay_type varchar(50) NOT NULL,
			question text NOT NULL,
			essay_content longtext NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			created_by bigint(20) UNSIGNED NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uuid (uuid)
		) $charset_collate";

		return $this->execute_sql( $sql );
	}

	private function create_segment_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'segment';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			essay_id bigint(20) UNSIGNED NOT NULL,
			type varchar(50) NOT NULL,
			`order` int(11) NOT NULL,
			title text NOT NULL,
			content longtext NOT NULL,
			PRIMARY KEY (id),
			KEY essay_id (essay_id),
			CONSTRAINT fk_segment_essay
				FOREIGN KEY (essay_id)
				REFERENCES {$this->wpdb->prefix}" . self::TABLE_PREFIX . "essays(id)
				ON DELETE CASCADE
		) $charset_collate";

		return $this->execute_sql( $sql );
	}

	private function create_segment_feedback_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'segment_feedback';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			feedback_criteria varchar(191) NOT NULL,
			segment_id bigint(20) UNSIGNED NOT NULL,
			feedback_language varchar(50) NOT NULL,
			source varchar(20) NOT NULL,
			cot_content longtext DEFAULT NULL,
			score_content longtext DEFAULT NULL,
			feedback_content longtext DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			created_by bigint(20) UNSIGNED NOT NULL,
			PRIMARY KEY (id),
			KEY segment_id (segment_id),
			KEY feedback_criteria (feedback_criteria(191)),
			KEY feedback_language (feedback_language),
			KEY source (source),
			CONSTRAINT fk_segment_feedback_segment
				FOREIGN KEY (segment_id)
				REFERENCES {$this->wpdb->prefix}" . self::TABLE_PREFIX . "segment(id)
				ON DELETE CASCADE
		) $charset_collate";

		return $this->execute_sql( $sql );
	}

	private function create_essay_feedback_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'essay_feedback';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			feedback_criteria varchar(191) NOT NULL,
			essay_id bigint(20) UNSIGNED NOT NULL,
			feedback_language varchar(50) NOT NULL,
			source varchar(20) NOT NULL,
			cot_content longtext DEFAULT NULL,
			score_content longtext DEFAULT NULL,
			feedback_content longtext DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			created_by bigint(20) UNSIGNED NOT NULL,
			PRIMARY KEY (id),
			KEY essay_id (essay_id),
			KEY feedback_criteria (feedback_criteria(191)),
			KEY feedback_language (feedback_language),
			KEY source (source),
			CONSTRAINT fk_essay_feedback_essay
				FOREIGN KEY (essay_id)
				REFERENCES {$this->wpdb->prefix}" . self::TABLE_PREFIX . "essays(id)
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
