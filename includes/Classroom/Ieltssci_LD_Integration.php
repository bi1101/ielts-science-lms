<?php
/**
 * LearnDash Integration Module for IELTS Science LMS.
 *
 * This class handles integration with LearnDash LMS, including automatic
 * course creation when groups are created.
 *
 * @package IELTS_Science_LMS
 * @subpackage Classroom
 */

namespace IeltsScienceLMS\Classroom;

use LearnDash_REST_API;

/**
 * Class for handling LearnDash integrations.
 */
class Ieltssci_LD_Integration {

	/**
	 * Initialize the LearnDash integration.
	 */
	public function __construct() {
		// Initialize auto-creator if LearnDash is active.
		add_action( 'plugins_loaded', array( $this, 'init_learndash_features' ) );
	}

	/**
	 * Initialize LearnDash-specific features.
	 */
	public function init_learndash_features() {
		// Check if LearnDash is active.
		if ( ! $this->is_learndash_active() ) {
			add_action( 'admin_notices', array( $this, 'learndash_inactive_notice' ) );
			return;
		}

		// Initialize the auto-creator.
		new Ieltssci_Group_Course_Auto_Creator();

		// Add custom Quiz field: Due date.
		add_filter( 'learndash_settings_fields', array( $this, 'add_quiz_custom_field' ), 10, 2 );

		// Save handler for the custom field.
		add_action( 'save_post', array( $this, 'save_quiz_custom_fields' ), 20, 3 );
	}

	/**
	 * Check if LearnDash is active.
	 *
	 * @return bool True if LearnDash is active, false otherwise.
	 */
	private function is_learndash_active() {
		return function_exists( 'learndash_get_post_type_slug' ) &&
				function_exists( 'learndash_update_setting' ) &&
				function_exists( 'ld_update_course_group_access' );
	}

	/**
	 * Display notice when LearnDash is not active.
	 */
	public function learndash_inactive_notice() {
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				esc_html_e(
					'IELTS Science LMS: LearnDash integration features are disabled because LearnDash LMS is not active.',
					'ielts-science-lms'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Add a "Due date" field to the LearnDash Quiz Access Settings metabox.
	 *
	 * Hook: learndash_settings_fields.
	 *
	 * @param array  $setting_option_fields Current field definitions array.
	 * @param string $settings_metabox_key  Current metabox key.
	 *
	 * @return array Modified fields array including our custom field when on Quiz Access Settings.
	 */
	public function add_quiz_custom_field( $setting_option_fields, $settings_metabox_key ) {
		// We only want to add the field on the Quiz Access Settings metabox.
		if ( 'learndash-quiz-access-settings' !== $settings_metabox_key ) {
			return $setting_option_fields;
		}

		$post_id = get_the_ID();

		$value = '';
		if ( $post_id ) {
			$value = (int) get_post_meta( $post_id, '_ielts_ld_quiz_due_date', true );
		}

		// Determine if deadline is enabled for this quiz.
		$has_deadline_value = '';
		if ( $post_id ) {
			$has_deadline_value = get_post_meta( $post_id, '_ielts_ld_quiz_has_deadline', true ) ? 'on' : '';
		}

		// Add the Deadline switch similar to the External setting.
		$setting_option_fields['deadline'] = array(
			'name'                => 'deadline',
			'label'               => esc_html__( 'Deadline', 'ielts-science-lms' ),
			'type'                => 'checkbox-switch',
			'value'               => $has_deadline_value,
			'child_section_state' => ( 'on' === $has_deadline_value ) ? 'open' : 'closed',
			'default'             => '',
			'options'             => array(
				'on' => esc_html__( 'Enable a deadline for this quiz.', 'ielts-science-lms' ),
				''   => '',
			),
			'rest'                => array(
				'show_in_rest' => LearnDash_REST_API::enabled(),
				'rest_args'    => array(
					'get_callback'    => array( $this, 'rest_get_quiz_deadline' ),
					'update_callback' => array( $this, 'rest_update_quiz_deadline' ),
					'schema'          => array(
						'field_key' => 'has_deadline',
						'type'      => 'boolean',
						'default'   => false,
					),
				),
			),
		);

		// Build the field config using LD field types.
		$setting_option_fields['due_date'] = array(
			'name'           => 'due_date',
			'label'          => esc_html__( 'Due date', 'ielts-science-lms' ),
			'type'           => 'date-entry',
			'class'          => 'learndash-datepicker-field',
			'value'          => $value,
			'label_none'     => false,
			'input_full'     => true,
			'help_text'      => esc_html__( 'Set the due date for this quiz (YYYY-MM-DD).', 'ielts-science-lms' ),
			'parent_setting' => 'deadline',
			'rest'           => array(
				'show_in_rest' => LearnDash_REST_API::enabled(),
				'rest_args'    => array(
					'get_callback'    => array( $this, 'rest_get_quiz_due_date' ),
					'update_callback' => array( $this, 'rest_update_quiz_due_date' ),
					'schema'          => array(
						'field_key'   => 'due_date',
						'description' => esc_html__( 'Quiz due date in YYYY-MM-DD format.', 'ielts-science-lms' ),
						'type'        => 'date',
						'default'     => '',
					),
				),
			),
		);

		return $setting_option_fields;
	}

	/**
	 * Save handler for the custom Quiz "Due date" field.
	 *
	 * Hook: save_post.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an existing post being updated.
	 */
	public function save_quiz_custom_fields( $post_id, $post, $update ) {
		// Bail on autosave or revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only for LearnDash quizzes.
		if ( learndash_get_post_type_slug( 'quiz' ) !== get_post_type( $post_id ) ) {
			return;
		}

		// Our field is posted within the settings array for this metabox key.
		$metabox_key = 'learndash-quiz-access-settings';
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_POST[ $metabox_key ] ) || ! is_array( $_POST[ $metabox_key ] ) ) {
			return;
		}

		// Handle the Deadline toggle similar to External. Using 'on' when enabled for consistency.
		// phpcs:ignore WordPress.Security.NonceVerification
		$deadline_raw = isset( $_POST[ $metabox_key ]['deadline'] ) ? sanitize_text_field( wp_unslash( $_POST[ $metabox_key ]['deadline'] ) ) : '';
		if ( 'on' === $deadline_raw ) {
			update_post_meta( $post_id, '_ielts_ld_quiz_has_deadline', 'on' );
		} else {
			delete_post_meta( $post_id, '_ielts_ld_quiz_has_deadline' );
			// If deadline is disabled, also clear any stored due date for consistency.
			delete_post_meta( $post_id, '_ielts_ld_quiz_due_date' );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$raw = isset( $_POST[ $metabox_key ]['due_date'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST[ $metabox_key ]['due_date'] ) ) : array();

		// Normalize date time to unix timestamp.
		$normalized = $this->validate_date_time( $raw );

		if ( '' === $normalized ) {
			delete_post_meta( $post_id, '_ielts_ld_quiz_due_date' );
		} else {
			update_post_meta( $post_id, '_ielts_ld_quiz_due_date', $normalized );
		}
	}

	/**
	 * REST GET callback for deadline toggle.
	 *
	 * @param array|\WP_Post   $object REST object or WP_Post.
	 * @param string           $field_name Field name.
	 * @param \WP_REST_Request $request Request instance.
	 * @return bool Whether deadline is enabled.
	 */
	public function rest_get_quiz_deadline( $object, $field_name, $request ) {
		$post_id = is_array( $object ) ? (int) ( $object['id'] ?? 0 ) : ( ( $object instanceof \WP_Post ) ? (int) $object->ID : 0 );
		if ( ! $post_id ) {
			return false;
		}

		return (bool) get_post_meta( $post_id, '_ielts_ld_quiz_has_deadline', true );
	}

	/**
	 * REST UPDATE callback for deadline toggle.
	 *
	 * @param mixed  $value Incoming value (boolean, string, or int).
	 * @param mixed  $object Object being updated (array or WP_Post).
	 * @param string $field_name Field name.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function rest_update_quiz_deadline( $value, $object, $field_name ) {
		$post_id = is_array( $object ) ? (int) ( $object['id'] ?? 0 ) : ( ( $object instanceof \WP_Post ) ? (int) $object->ID : 0 );
		if ( ! $post_id ) {
			return new \WP_Error( 'invalid_post', __( 'Invalid post for has_deadline update.', 'ielts-science-lms' ) );
		}

		// Only handle LearnDash quizzes.
		if ( learndash_get_post_type_slug( 'quiz' ) !== get_post_type( $post_id ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'has_deadline only applies to quizzes.', 'ielts-science-lms' ) );
		}

		$enabled = false;
		if ( is_bool( $value ) ) {
			$enabled = $value;
		} elseif ( is_string( $value ) ) {
			$val_l   = strtolower( $value );
			$enabled = ( 'on' === $val_l ) || ( 'true' === $val_l ) || ( '1' === $val_l );
		} elseif ( is_numeric( $value ) ) {
			$enabled = ( 1 === (int) $value );
		}

		if ( $enabled ) {
			update_post_meta( $post_id, '_ielts_ld_quiz_has_deadline', 'on' );
		} else {
			delete_post_meta( $post_id, '_ielts_ld_quiz_has_deadline' );
			// Also clear due date if disabling deadline.
			delete_post_meta( $post_id, '_ielts_ld_quiz_due_date' );
		}

		return true;
	}

	/**
	 * Validate Date/Time Input
	 *
	 * @since 3.0.0
	 *
	 * @param array $val Value to validate.
	 *
	 * @return bool|int $val validated value.
	 */
	public function validate_date_time( $val = array() ) {

		if ( isset( $val['aa'] ) ) {
			$val_aa = intval( $val['aa'] );
		} else {
			$val_aa = 0;
		}

		if ( isset( $val['mm'] ) ) {
			$val_mm = intval( $val['mm'] );
		} else {
			$val_mm = 0;
		}

		if ( isset( $val['jj'] ) ) {
			$val_jj = intval( $val['jj'] );
		} else {
			$val_jj = 0;
		}

		if ( isset( $val['hh'] ) ) {
			$val_hh = intval( $val['hh'] );
		} else {
			$val_hh = 0;
		}

		if ( isset( $val['mn'] ) ) {
			$val_mn = intval( $val['mn'] );
		} else {
			$val_mn = 0;
		}

		if ( ( ! empty( $val_aa ) ) && ( ! empty( $val_mm ) ) && ( ! empty( $val_jj ) ) ) {
			$date_string = sprintf(
				'%04d-%02d-%02d %02d:%02d:00',
				intval( $val_aa ),
				intval( $val_mm ),
				intval( $val_jj ),
				intval( $val_hh ),
				intval( $val_mn )
			);

			$date_string_gmt = get_gmt_from_date( $date_string, 'Y-m-d H:i:s' );
			$val             = strtotime( $date_string_gmt );
		} else {
			$val = 0;
		}
			return $val;
	}

	/**
	 * REST GET callback for due_date field.
	 *
	 * @param array|\WP_Post   $object REST object or WP_Post.
	 * @param string           $field_name Field name.
	 * @param \WP_REST_Request $request Request instance.
	 * @return int Unix timestamp or 0 if not set.
	 */
	public function rest_get_quiz_due_date( $object, $field_name, $request ) {
		$post_id = is_array( $object ) ? (int) ( $object['id'] ?? 0 ) : ( ( $object instanceof \WP_Post ) ? (int) $object->ID : 0 );
		if ( ! $post_id ) {
			return 0;
		}

		$ts = (int) get_post_meta( $post_id, '_ielts_ld_quiz_due_date', true );
		return $ts > 0 ? $ts : 0;
	}

	/**
	 * REST UPDATE callback for due_date field.
	 *
	 * Accepts Unix timestamp or date array format.
	 *
	 * @param array  $value Incoming value.
	 * @param mixed  $object Object being updated (array or WP_Post).
	 * @param string $field_name Field name.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function rest_update_quiz_due_date( $value, $object, $field_name ) {
		$post_id = is_array( $object ) ? (int) ( $object['id'] ?? 0 ) : ( ( $object instanceof \WP_Post ) ? (int) $object->ID : 0 );
		if ( ! $post_id ) {
			return new \WP_Error( 'invalid_post', __( 'Invalid post for due_date update.', 'ielts-science-lms' ) );
		}

		// Only handle LearnDash quizzes.
		if ( learndash_get_post_type_slug( 'quiz' ) !== get_post_type( $post_id ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'due_date only applies to quizzes.', 'ielts-science-lms' ) );
		}

		// Normalize supported inputs.
		$ts = 0;

		// Handle array format using validate_date_time.
		$ts = $this->validate_date_time( $value );

		// Empty value clears the meta.
		if ( empty( $value ) ) {
			delete_post_meta( $post_id, '_ielts_ld_quiz_due_date' );
			return true;
		}

		if ( ! $ts || $ts < 0 ) {
			return new \WP_Error( 'invalid_due_date', __( 'Invalid due_date. Expected Unix timestamp or date array.', 'ielts-science-lms' ) );
		}

		update_post_meta( $post_id, '_ielts_ld_quiz_due_date', $ts );
		return true;
	}
}
