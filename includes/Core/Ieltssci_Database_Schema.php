<?php
/**
 * Database Schema Manager
 *
 * This file handles the database schema creation and updates.
 *
 * The submission tables (test_submissions, task_submissions) are designed to work with
 * the ACF Custom Post Types defined in Ieltssci_ACF.php:
 * - test_submissions.test_id references wp_posts.ID where post_type='writing-test'
 * - task_submissions.task_id references wp_posts.ID where post_type='writing-task'
 *
 * These tables track user progress through writing tests and individual tasks,
 * storing submission status, timing data, and linking to essay content.
 *
 * @package IELTS_Science_LMS
 * @subpackage Core
 */

namespace IeltsScienceLMS\Core;

use wpdb;
use WP_Error;
use Exception;

/**
 * Database Schema Class
 *
 * Manages the creation and update of plugin database tables.
 *
 * @since 0.0.1
 */
class Ieltssci_Database_Schema {
	const TABLE_PREFIX = 'ieltssci_';

	/**
	 * Database version number.
	 *
	 * @var string
	 */
	private $db_version = '0.0.8'; // Updated version number for adding essays meta table.

	/**
	 * WordPress database object.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Constructor
	 *
	 * Initializes the database schema manager.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Gets the target database version defined in the code.
	 *
	 * @return string The target database version.
	 */
	public function get_db_version() {
		return $this->db_version;
	}

	/**
	 * Creates all required database tables if they don't exist.
	 * This should primarily use CREATE TABLE IF NOT EXISTS.
	 * It does NOT handle updates to existing tables.
	 *
	 * @return void|WP_Error Returns WP_Error on failure.
	 */
	public function create_tables() {
		// Note: Removed transaction and version update from here.
		// Updates are handled by run_updates().
		try {
			$this->create_api_feeds_table();
			$this->create_api_feed_essay_type_table();
			$this->create_rate_limit_rule_table();
			$this->create_api_feed_rate_limit_rule_table();
			$this->create_role_rate_limit_rule_table();
			$this->create_api_key_table();
			$this->create_essays_table();
			$this->create_essays_meta_table();
			$this->create_segment_table();
			$this->create_segment_feedback_table();
			$this->create_essay_feedback_table();
			$this->create_speech_table();
			$this->create_speech_feedback_table();
			$this->create_writing_test_submissions_table();
			$this->create_writing_task_submissions_table();
			$this->create_writing_test_submission_meta_table();
			$this->create_writing_task_submission_meta_table();

			return;
		} catch ( Exception $e ) {
			// Log the error appropriately.
			return new WP_Error( 'db_creation_error', $e->getMessage() );
		}
	}

	/**
	 * Runs sequential database updates based on version.
	 *
	 * @return void|WP_Error Returns WP_Error on failure.
	 */
	public function run_updates() {
		$current_version = get_option( 'ieltssci_db_version', '0' );

		if ( version_compare( $current_version, $this->db_version, '>=' ) ) {
			return; // Already up to date.
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Sequential updates - each one runs only if needed.
			if ( version_compare( $current_version, '0.0.1', '<' ) ) {
				$this->update_to_0_0_1();
			}

			if ( version_compare( $current_version, '0.0.2', '<' ) ) {
				$this->update_to_0_0_2();
			}

			if ( version_compare( $current_version, '0.0.3', '<' ) ) {
				$this->update_to_0_0_3();
			}

			if ( version_compare( $current_version, '0.0.4', '<' ) ) {
				$this->update_to_0_0_4();
			}

			if ( version_compare( $current_version, '0.0.5', '<' ) ) {
				$this->update_to_0_0_5();
			}

			if ( version_compare( $current_version, '0.0.6', '<' ) ) {
				$this->update_to_0_0_6();
			}

			if ( version_compare( $current_version, '0.0.7', '<' ) ) {
				$this->update_to_0_0_7();
			}

			if ( version_compare( $current_version, '0.0.8', '<' ) ) {
				$this->update_to_0_0_8();
			}

			// All updates successful, update the stored version.
			update_option( 'ieltssci_db_version', $this->db_version );
			$this->wpdb->query( 'COMMIT' );

		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			// Log the error appropriately.
			return new WP_Error( 'db_update_error', sprintf( 'Error updating database schema: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Update schema to version 0.0.1.
	 * Initial database setup.
	 *
	 * @throws \Exception On SQL error.
	 */
	private function update_to_0_0_1() {
		// Initial setup is handled by create_tables.
		$this->create_tables();
	}

	/**
	 * Update schema to version 0.0.2.
	 * Contains specific ALTER TABLE or other commands for this version.
	 *
	 * @throws \Exception On SQL error.
	 */
	private function update_to_0_0_2() {
		// No specific changes for version 0.0.2.
	}

	/**
	 * Update schema to version 0.0.3.
	 *
	 * @throws \Exception On SQL error.
	 */
	private function update_to_0_0_3() {
		// No specific changes for version 0.0.3.
	}

	/**
	 * Update schema to version 0.0.4.
	 *
	 * @throws \Exception On SQL error.
	 */
	private function update_to_0_0_4() {
		// No specific changes for version 0.0.4.
	}

	/**
	 * Update schema to version 0.0.5.
	 *
	 * @throws \Exception On SQL error.
	 */
	private function update_to_0_0_5() {
		// No specific changes for version 0.0.5.
	}

	/**
	 * Update schema to version 0.0.6.
	 * Adds dependencies column to api_feed_essay_type table.
	 *
	 * @throws \Exception On SQL error.
	 */
	private function update_to_0_0_6() {
		$table_name = $this->wpdb->prefix . self::TABLE_PREFIX . 'api_feed_essay_type';

		// Check if the column already exists using INFORMATION_SCHEMA for better compatibility.
		$column_exists = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table_name,
				'dependencies'
			)
		);

		// If column doesn't exist (count is 0), add it.
		if ( '0' === $column_exists ) {
			$sql = "ALTER TABLE `$table_name`
                    ADD COLUMN `dependencies` longtext DEFAULT NULL
                    COMMENT 'JSON encoded array of API feed IDs that this feed depends on'
                    AFTER `process_order`;"; // Specify position if desired.

			$this->wpdb->query( $sql );

			if ( ! empty( $this->wpdb->last_error ) ) {
				// Throw exception to trigger rollback.
				throw new Exception( 'Error adding dependencies column: ' . esc_html( $this->wpdb->last_error ) );
			}
		}
	}

	/**
	 * Update schema to version 0.0.7.
	 * Adds test submission and task submission tables with their meta tables.
	 *
	 * @throws \Exception On SQL error.
	 */
	private function update_to_0_0_7() {
		$this->create_writing_test_submissions_table();
		$this->create_writing_task_submissions_table();
		$this->create_writing_test_submission_meta_table();
		$this->create_writing_task_submission_meta_table();
	}

	/**
	 * Update schema to version 0.0.8.
	 * Adds essays meta table.
	 *
	 * @throws \Exception On SQL error.
	 */
	private function update_to_0_0_8() {
		$this->create_essays_meta_table();
	}

	/**
	 * Creates the API feeds table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
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

	/**
	 * Creates the API feed essay type table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
	private function create_api_feed_essay_type_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'api_feed_essay_type';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            api_feed_id bigint(20) UNSIGNED NOT NULL,
            essay_type varchar(191) NOT NULL,
            process_order int(11) NOT NULL,
            dependencies longtext DEFAULT NULL COMMENT 'JSON encoded array of API feed IDs that this feed depends on',
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

	/**
	 * Creates the rate limit rule table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
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

	/**
	 * Creates the API feed rate limit rule table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
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

	/**
	 * Creates the role rate limit rule table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
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

	/**
	 * Creates the API key table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
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

	/**
	 * Creates the essays table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
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

	/**
	 * Creates the essays meta table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
	private function create_essays_meta_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'essay_meta';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			meta_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			essay_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			meta_key varchar(255) DEFAULT NULL,
			meta_value longtext,
			PRIMARY KEY (meta_id),
			KEY essay_id (essay_id),
			KEY meta_key (meta_key(191)),
			CONSTRAINT fk_essay_meta_essay
				FOREIGN KEY (essay_id)
				REFERENCES {$this->wpdb->prefix}" . self::TABLE_PREFIX . "essays(id)
				ON DELETE CASCADE
		) $charset_collate";

		return $this->execute_sql( $sql );
	}

	/**
	 * Creates the segment table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
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

	/**
	 * Creates the segment feedback table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
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
			is_preferred boolean DEFAULT 0 COMMENT 'Feedback được chọn làm feedback mặc định',
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

	/**
	 * Creates the essay feedback table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
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
			is_preferred boolean DEFAULT 0 COMMENT 'Feedback được chọn làm feedback mặc định',
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

	/**
	 * Creates the speech table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
	private function create_speech_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'speech';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid varchar(36) NOT NULL,
			audio_ids text NOT NULL COMMENT 'ID của file audio người dùng upload',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			created_by bigint(20) UNSIGNED NOT NULL COMMENT 'ID người tạo bài, nếu fork từ bài khác thì là ID của người fork',
			PRIMARY KEY (id),
			UNIQUE KEY uuid (uuid)
		) $charset_collate";

		return $this->execute_sql( $sql );
	}

	/**
	 * Creates the speech feedback table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
	private function create_speech_feedback_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'speech_feedback';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			feedback_criteria varchar(191) NOT NULL COMMENT 'Tiêu chí feedback e.g relevance, clearAnswer, rewrite, logicDepth, etc.',
			speech_id bigint(20) UNSIGNED NOT NULL,
			feedback_language varchar(50) NOT NULL COMMENT 'Ngôn ngữ của feedback e.g English, Vietnamese',
			source varchar(20) NOT NULL COMMENT 'Nguồn của feedback',
			cot_content longtext DEFAULT NULL COMMENT 'Nội dung phần COT',
			score_content longtext DEFAULT NULL COMMENT 'Nội dung phần điểm số',
			feedback_content longtext DEFAULT NULL COMMENT 'Nội dung phần feedback',
			is_preferred boolean DEFAULT 0 COMMENT 'Feedback được chọn làm feedback mặc định',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			created_by bigint(20) UNSIGNED NOT NULL COMMENT 'ID của người call API hoặc update MANUAL feedback',
			PRIMARY KEY (id),
			KEY speech_id (speech_id),
			KEY feedback_criteria (feedback_criteria(191)),
			KEY feedback_language (feedback_language),
			KEY source (source),
			CONSTRAINT fk_speech_feedback_speech
				FOREIGN KEY (speech_id)
				REFERENCES {$this->wpdb->prefix}" . self::TABLE_PREFIX . "speech(id)
				ON DELETE CASCADE
		) $charset_collate";

		return $this->execute_sql( $sql );
	}

	/**
	 * Creates the writing test submissions table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
	private function create_writing_test_submissions_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'writing_test_submissions';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid varchar(36) NOT NULL,
			test_id bigint(20) UNSIGNED NOT NULL COMMENT 'ID of the test from wp_writing_tests',
			user_id bigint(20) UNSIGNED NOT NULL COMMENT 'ID of the user who submitted the test',
			status varchar(50) NOT NULL DEFAULT 'in-progress' COMMENT 'e.g., in-progress, completed, graded',
			started_at datetime DEFAULT NULL COMMENT 'GMT timestamp when the test was started',
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'GMT timestamp when the test was last updated',
			completed_at datetime DEFAULT NULL COMMENT 'GMT timestamp when the test was completed',
			PRIMARY KEY (id),
			UNIQUE KEY uuid (uuid),
			KEY test_id (test_id),
			KEY user_id (user_id),
			KEY status (status)
		) $charset_collate";

		return $this->execute_sql( $sql );
	}

	/**
	 * Creates the writing task submissions table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
	private function create_writing_task_submissions_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'writing_task_submissions';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid varchar(36) NOT NULL,
			test_submission_id bigint(20) UNSIGNED DEFAULT NULL COMMENT 'ID of the test submission from ieltssci_writing_test_submissions, nullable if standalone',
			user_id bigint(20) UNSIGNED NOT NULL COMMENT 'ID of the user who submitted the test',
			task_id bigint(20) UNSIGNED NOT NULL COMMENT 'ID of the writing task from wp_writing_tasks',
			status varchar(50) NOT NULL DEFAULT 'in-progress' COMMENT 'e.g., in-progress, completed, graded',
			essay_id bigint(20) UNSIGNED NOT NULL COMMENT 'ID of the essay from ieltssci_essays',
			started_at datetime DEFAULT NULL COMMENT 'GMT timestamp when the task was started',
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'GMT timestamp when the task was last updated',
			completed_at datetime DEFAULT NULL COMMENT 'GMT timestamp when the task was completed',
			PRIMARY KEY (id),
			UNIQUE KEY uuid (uuid),
			KEY test_submission_id (test_submission_id),
			KEY user_id (user_id),
			KEY task_id (task_id),
			KEY essay_id (essay_id),
			KEY status (status),
			CONSTRAINT fk_task_submission_test_submission
				FOREIGN KEY (test_submission_id)
				REFERENCES {$this->wpdb->prefix}" . self::TABLE_PREFIX . "writing_test_submissions(id)
				ON DELETE CASCADE,
			CONSTRAINT fk_task_submission_essay
				FOREIGN KEY (essay_id)
				REFERENCES {$this->wpdb->prefix}" . self::TABLE_PREFIX . "essays(id)
				ON DELETE CASCADE
		) $charset_collate";

		return $this->execute_sql( $sql );
	}

	/**
	 * Creates the writing test submission meta table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
	private function create_writing_test_submission_meta_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'writing_test_submission_meta';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			meta_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ieltssci_writing_test_submission_id bigint(20) UNSIGNED NOT NULL COMMENT 'FK to ieltssci_writing_test_submissions',
			meta_key varchar(191) NOT NULL COMMENT 'e.g., time_elapsed, time_limit, etc.',
			meta_value longtext DEFAULT NULL COMMENT 'Value for the meta key',
			PRIMARY KEY (meta_id),
			KEY ieltssci_writing_test_submission_id (ieltssci_writing_test_submission_id),
			KEY meta_key (meta_key),
			CONSTRAINT fk_writing_test_submission_meta_test_submission
				FOREIGN KEY (ieltssci_writing_test_submission_id)
				REFERENCES {$this->wpdb->prefix}" . self::TABLE_PREFIX . "writing_test_submissions(id)
				ON DELETE CASCADE
		) $charset_collate";

		return $this->execute_sql( $sql );
	}

	/**
	 * Creates the writing task submission meta table
	 *
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
	private function create_writing_task_submission_meta_table() {
		$table_name      = $this->wpdb->prefix . self::TABLE_PREFIX . 'writing_task_submission_meta';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			meta_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ieltssci_writing_task_submission_id bigint(20) UNSIGNED NOT NULL COMMENT 'FK to ieltssci_writing_task_submissions',
			meta_key varchar(191) NOT NULL COMMENT 'e.g., time_elapsed, time_limit, etc.',
			meta_value longtext DEFAULT NULL COMMENT 'Value for the meta key',
			PRIMARY KEY (meta_id),
			KEY ieltssci_writing_task_submission_id (ieltssci_writing_task_submission_id),
			KEY meta_key (meta_key),
			CONSTRAINT fk_writing_task_submission_meta_task_submission
				FOREIGN KEY (ieltssci_writing_task_submission_id)
				REFERENCES {$this->wpdb->prefix}" . self::TABLE_PREFIX . "writing_task_submissions(id)
				ON DELETE CASCADE
		) $charset_collate";

		return $this->execute_sql( $sql );
	}

	/**
	 * Executes an SQL query using dbDelta
	 *
	 * @param string $sql The SQL query to execute.
	 * @return bool True on success.
	 * @throws \Exception On SQL error.
	 */
	private function execute_sql( $sql ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );
		if ( ! empty( $this->wpdb->last_error ) ) {
			throw new Exception( esc_html( $this->wpdb->last_error ) );
		}
		return true;
	}

	/**
	 * Checks if database needs to be upgraded
	 *
	 * @return bool True if needs upgrade, false otherwise.
	 */
	public function needs_upgrade() {
		$current_version = get_option( 'ieltssci_db_version', '0' );
		return version_compare( $current_version, $this->db_version, '<' );
	}
}
