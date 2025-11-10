<?php
/**
 * User Activity Tracker for IELTS Science LMS
 *
 * Tracks essay submissions in user activity timeline.
 *
 * @package IELTS_Science_LMS
 * @subpackage UsersInsights
 */

namespace IeltsScienceLMS\UsersInsights;

use USIN_Activity_Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User activity tracker for essays.
 */
class USIN_IeltsScience_User_Activity {

	/**
	 * Essays table name.
	 *
	 * @var string
	 */
	protected $essays_table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->essays_table = $wpdb->prefix . 'ieltssci_essays';
	}

	/**
	 * Initialize activity tracking.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'usin_user_activity', array( $this, 'load_activity' ), 10, 2 );
	}

	/**
	 * Load essay activities for user.
	 *
	 * @param array $activities Existing activities.
	 * @param int   $user_id User ID.
	 * @return array Modified activities.
	 */
	public function load_activity( $activities, $user_id ) {
		$essay_activities = $this->get_essay_activities( $user_id );

		if ( ! empty( $essay_activities ) ) {
			$activities = array_merge( $activities, $essay_activities );
		}

		return $activities;
	}

	/**
	 * Get essay submission activities.
	 *
	 * @param int $user_id User ID.
	 * @return array Essay activities.
	 */
	protected function get_essay_activities( $user_id ) {
		global $wpdb;

		$essays = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, essay_type, created_at, essay_content, question
				FROM {$this->essays_table}
				WHERE created_by = %d
				ORDER BY created_at DESC
				LIMIT 50",
				$user_id
			)
		);

		if ( empty( $essays ) ) {
			return array();
		}

		$activities = array();

		foreach ( $essays as $essay ) {
			$word_count = $this->count_words( $essay->essay_content );
			$essay_type = ucfirst( str_replace( array( '-', '_' ), ' ', $essay->essay_type ) );

			// Truncate question if too long.
			$question = strlen( $essay->question ) > 100
				? substr( $essay->question, 0, 97 ) . '...'
				: $essay->question;

			$activities[] = array(
				'type'    => 'essay',
				'action'  => sprintf(
					/* translators: 1: Essay type, 2: Word count */
					__( 'Submitted %1$s essay (%2$d words)', 'ielts-science-lms' ),
					$essay_type,
					$word_count
				),
				'date'    => $essay->created_at,
				'objects' => array(
					array(
						'name' => $question,
						'link' => $this->get_essay_link( $essay->id ),
					),
				),
				'icon'    => 'dashicons-edit',
			);
		}

		return $activities;
	}

	/**
	 * Count words in text.
	 *
	 * @param string $text Text to count.
	 * @return int Word count.
	 */
	protected function count_words( $text ) {
		$text = strip_tags( $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return str_word_count( $text );
	}

	/**
	 * Get link to essay (if applicable).
	 *
	 * @param int $essay_id Essay ID.
	 * @return string|null Link URL or null.
	 */
	protected function get_essay_link( $essay_id ) {
		// Return null for now - can be customized to link to essay viewer.
		// Example: return admin_url( 'admin.php?page=ielts-essays&essay_id=' . $essay_id );.
		return null;
	}
}
