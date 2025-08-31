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

		// Register external question metabox for sfwd-question.
		add_action( 'add_meta_boxes', array( $this, 'register_external_question_metabox' ) );

		// Save handler for external question metabox.
		add_action( 'save_post_sfwd-question', array( $this, 'save_external_question_metabox' ), 10, 2 );

		// Expose external question fields via LearnDash REST v2 (ldlms/v2/sfwd-question).
		add_action( 'learndash_rest_register_fields', array( $this, 'register_questions_rest_fields' ), 10, 2 );

		// Also register post meta for WP core REST exposure under meta.
		add_action( 'init', array( $this, 'register_question_meta' ) );

		// Intercept LD v2 question updates at REST pre-dispatch to ensure meta persistence.
		add_filter( 'rest_pre_dispatch', array( $this, 'intercept_ld_questions_update' ), 10, 3 );
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

		// Add close submission checkbox under due_date.
		$close_submission_value = '';
		if ( $post_id ) {
			$close_submission_value = get_post_meta( $post_id, '_ielts_ld_quiz_close_submission_after_due_date', true ) ? 'on' : '';
		}

		$setting_option_fields['close_submission_after_due_date'] = array(
			'name'           => 'close_submission_after_due_date',
			'label'          => esc_html__( 'Close submission after due date', 'ielts-science-lms' ),
			'type'           => 'checkbox',
			'value'          => $close_submission_value,
			'default'        => '',
			'help_text'      => esc_html__( 'Automatically close quiz submissions after the due date.', 'ielts-science-lms' ),
			'parent_setting' => 'deadline',
			'options'        => array(
				'on' => esc_html__( 'Close submissions after the due date.', 'ielts-science-lms' ),
			),
			'rest'           => array(
				'show_in_rest' => LearnDash_REST_API::enabled(),
				'rest_args'    => array(
					'get_callback'    => array( $this, 'rest_get_quiz_close_submission' ),
					'update_callback' => array( $this, 'rest_update_quiz_close_submission' ),
					'schema'          => array(
						'field_key' => 'close_submission_after_due_date',
						'type'      => 'boolean',
						'default'   => false,
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

		// Handle close submission checkbox.
		// phpcs:ignore WordPress.Security.NonceVerification
		$close_submission_raw = isset( $_POST[ $metabox_key ]['close_submission_after_due_date'] ) ? sanitize_text_field( wp_unslash( $_POST[ $metabox_key ]['close_submission_after_due_date'] ) ) : '';
		if ( 'on' === $close_submission_raw ) {
			update_post_meta( $post_id, '_ielts_ld_quiz_close_submission_after_due_date', 'on' );
		} else {
			delete_post_meta( $post_id, '_ielts_ld_quiz_close_submission_after_due_date' );
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

	/**
	 * REST GET callback for close_submission_after_due_date field.
	 *
	 * @param array|\WP_Post   $object REST object or WP_Post.
	 * @param string           $field_name Field name.
	 * @param \WP_REST_Request $request Request instance.
	 * @return bool Whether close submission is enabled.
	 */
	public function rest_get_quiz_close_submission( $object, $field_name, $request ) {
		$post_id = is_array( $object ) ? (int) ( $object['id'] ?? 0 ) : ( ( $object instanceof \WP_Post ) ? (int) $object->ID : 0 );
		if ( ! $post_id ) {
			return false;
		}

		return (bool) get_post_meta( $post_id, '_ielts_ld_quiz_close_submission_after_due_date', true );
	}

	/**
	 * REST UPDATE callback for close_submission_after_due_date field.
	 *
	 * @param mixed  $value Incoming value (boolean, string, or int).
	 * @param mixed  $object Object being updated (array or WP_Post).
	 * @param string $field_name Field name.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function rest_update_quiz_close_submission( $value, $object, $field_name ) {
		$post_id = is_array( $object ) ? (int) ( $object['id'] ?? 0 ) : ( ( $object instanceof \WP_Post ) ? (int) $object->ID : 0 );
		if ( ! $post_id ) {
			return new \WP_Error( 'invalid_post', __( 'Invalid post for close_submission_after_due_date update.', 'ielts-science-lms' ) );
		}

		// Only handle LearnDash quizzes.
		if ( learndash_get_post_type_slug( 'quiz' ) !== get_post_type( $post_id ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'close_submission_after_due_date only applies to quizzes.', 'ielts-science-lms' ) );
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
			update_post_meta( $post_id, '_ielts_ld_quiz_close_submission_after_due_date', 'on' );
		} else {
			delete_post_meta( $post_id, '_ielts_ld_quiz_close_submission_after_due_date' );
		}

		return true;
	}

	/**
	 * Register the External Question metabox on the LearnDash Question edit screen.
	 */
	public function register_external_question_metabox() {
		$question_pt = function_exists( 'learndash_get_post_type_slug' ) ? learndash_get_post_type_slug( 'question' ) : 'sfwd-question';
		add_meta_box(
			'ieltssci_external_question',
			esc_html__( 'External Question', 'ielts-science-lms' ),
			array( $this, 'render_external_question_metabox' ),
			$question_pt,
			'normal',
			'high'
		);
	}

	/**
	 * Render the External Question metabox fields.
	 *
	 * @param \WP_Post $post Current post object.
	 */
	public function render_external_question_metabox( $post ) {
		wp_nonce_field( 'ieltssci_extq_save', 'ieltssci_extq_nonce' );

		$enabled = (bool) get_post_meta( $post->ID, '_ielts_extq_enabled', true );
		$ext_id  = (int) get_post_meta( $post->ID, '_ielts_extq_id', true );

		?>
		<p>
			<label>
				<input type="checkbox" id="ieltssci_extq_enabled" name="ieltssci_extq_enabled" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Enable External Question.', 'ielts-science-lms' ); ?>
			</label>
		</p>

		<div id="external-item-id-container" style="margin-left:16px; display: <?php echo $enabled ? 'block' : 'none'; ?>;">
			<p>
				<label for="ieltssci_extq_id"><strong><?php esc_html_e( 'External Item ID', 'ielts-science-lms' ); ?></strong></label><br />
				<input type="number" id="ieltssci_extq_id" name="ieltssci_extq_id" value="<?php echo esc_attr( $ext_id ); ?>" min="0" class="small-text" />
			</p>
		</div>
		<script type="text/javascript">
		document.addEventListener('DOMContentLoaded', function() {
			var checkbox = document.getElementById('ieltssci_extq_enabled');
			var container = document.getElementById('external-item-id-container');
			if (checkbox && container) {
				checkbox.addEventListener('change', function() {
					container.style.display = this.checked ? 'block' : 'none';
				});
			}
		});
		</script>
		<?php
	}

	/**
	 * Save handler for External Question metabox.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save_external_question_metabox( $post_id, $post ) {
		// Nonce and capability checks.
		if ( ! isset( $_POST['ieltssci_extq_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ieltssci_extq_nonce'] ) ), 'ieltssci_extq_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$question_pt = function_exists( 'learndash_get_post_type_slug' ) ? learndash_get_post_type_slug( 'question' ) : 'sfwd-question';
		if ( get_post_type( $post_id ) !== $question_pt ) {
			return;
		}

		// Read and sanitize inputs.
		$enabled = isset( $_POST['ieltssci_extq_enabled'] ) ? '1' : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$ext_id  = isset( $_POST['ieltssci_extq_id'] ) ? absint( $_POST['ieltssci_extq_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.

		// Persist.
		update_post_meta( $post_id, '_ielts_extq_enabled', $enabled );
		if ( '1' === $enabled ) {
			update_post_meta( $post_id, '_ielts_extq_id', $ext_id );
		} else {
			delete_post_meta( $post_id, '_ielts_extq_id' );
		}
	}

	/**
	 * Register LearnDash REST v2 fields for Questions to expose external question data.
	 *
	 * @param string                             $post_type  Post type being registered.
	 * @param \LD_REST_Posts_Controller_V2|mixed $controller Controller instance.
	 */
	public function register_questions_rest_fields( $post_type, $controller ) {
		$question_pt = function_exists( 'learndash_get_post_type_slug' ) ? learndash_get_post_type_slug( 'question' ) : 'sfwd-question';
		if ( $post_type !== $question_pt ) {
			return;
		}

		$register = function ( $field, $args ) use ( $question_pt ) {
			\register_rest_field( $question_pt, $field, $args );
		};

		$register(
			'external_enabled',
			array(
				'get_callback'    => array( $this, 'get_external_enabled_callback' ),
				'update_callback' => array( $this, 'update_external_enabled_callback' ),
				'schema'          => array(
					'description' => __( 'Whether this question uses an external provider.', 'ielts-science-lms' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
			)
		);

		$register(
			'external_quiz_id',
			array(
				'get_callback'    => array( $this, 'get_external_quiz_id_callback' ),
				'update_callback' => array( $this, 'update_external_quiz_id_callback' ),
				'schema'          => array(
					'description' => __( 'External question or quiz ID.', 'ielts-science-lms' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
			)
		);
	}

	/**
	 * Intercept LearnDash v2 sfwd-question update requests before dispatch.
	 * Ensures our external fields are saved even if LD controllers skip callbacks.
	 *
	 * @param mixed            $result  Response to replace the requested version with. Default null to continue.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request used to generate the response.
	 * @return mixed Null to continue default handling or a response to short-circuit.
	 */
	public function intercept_ld_questions_update( $result, $server, $request ) {
		$route  = $request->get_route();
		$method = strtoupper( $request->get_method() );

		// Only handle LearnDash v2 questions endpoint updates.
		if ( false === strpos( $route, '/ldlms/v2/sfwd-question' ) ) {
			return $result;
		}
		if ( ! in_array( $method, array( 'PUT', 'PATCH', 'POST' ), true ) ) {
			return $result;
		}

		$post_id = 0;
		// Extract post ID from route: /ldlms/v2/sfwd-question/{id}.
		$route_parts = explode( '/', trim( $route, '/' ) );
		if ( count( $route_parts ) >= 4 && is_numeric( end( $route_parts ) ) ) {
			$post_id = absint( end( $route_parts ) );
		}
		if ( $post_id <= 0 ) {
			return $result; // Invalid or missing ID.
		}

		// Use LearnDash's own permission check for updating an item.
		if ( class_exists( 'LD_REST_Questions_Controller_V2' ) ) {
			$controller = new \LD_REST_Questions_Controller_V2();
			if ( method_exists( $controller, 'update_item_permissions_check' ) ) {
				// The check requires the 'id' to be set on the request.
				$request->set_param( 'id', $post_id );
				if ( ! $controller->update_item_permissions_check( $request ) ) {
					return $result; // Respect permissions from LD controller.
				}
			} elseif ( ! current_user_can( 'edit_post', $post_id ) ) {
				// Fallback for older LD versions or if method signature changes.
				return $result;
			}
		} elseif ( ! current_user_can( 'edit_post', $post_id ) ) {
			// Fallback for older LD versions or if class not found.
			return $result;
		}

		// Persist only if params present.
		if ( $request->offsetExists( 'external_enabled' ) ) {
			$val     = $request->get_param( 'external_enabled' );
			$enabled = false;
			if ( is_bool( $val ) ) {
				$enabled = $val;
			} elseif ( is_string( $val ) ) {
				$enabled = in_array( strtolower( $val ), array( '1', 'true', 'on' ), true );
			} elseif ( is_numeric( $val ) ) {
				$enabled = ( 1 === (int) $val );
			}
			update_post_meta( $post_id, '_ielts_extq_enabled', $enabled ? '1' : '0' );
		}

		if ( $request->offsetExists( 'external_quiz_id' ) ) {
			$ext_id = max( 0, absint( $request->get_param( 'external_quiz_id' ) ) );
			if ( $ext_id > 0 ) {
				update_post_meta( $post_id, '_ielts_extq_id', $ext_id );
			} else {
				delete_post_meta( $post_id, '_ielts_extq_id' );
			}
		}

		return $result; // Continue to normal dispatch so response is built.
	}

	/**
	 * Register question meta with show_in_rest for WordPress core REST API exposure.
	 */
	public function register_question_meta() {
		$question_pt = function_exists( 'learndash_get_post_type_slug' ) ? learndash_get_post_type_slug( 'question' ) : 'sfwd-question';
		$auth_cb     = function () {
			return current_user_can( 'edit_posts' );
		};
		\register_post_meta(
			$question_pt,
			'_ielts_extq_enabled',
			array(
				'single'        => true,
				'type'          => 'boolean',
				'show_in_rest'  => true,
				'auth_callback' => $auth_cb,
			)
		);
		\register_post_meta(
			$question_pt,
			'_ielts_extq_id',
			array(
				'single'        => true,
				'type'          => 'integer',
				'show_in_rest'  => true,
				'auth_callback' => $auth_cb,
			)
		);
	}

	/**
	 * REST GET callback for external_enabled field.
	 *
	 * @param array            $post_arr  Post array from REST API.
	 * @param string           $field_name Field name.
	 * @param \WP_REST_Request $request   Request instance.
	 * @return bool Whether external question is enabled.
	 */
	public function get_external_enabled_callback( $post_arr, $field_name, $request ) {
		$post_id = isset( $post_arr['id'] ) ? absint( $post_arr['id'] ) : 0;
		return (bool) get_post_meta( $post_id, '_ielts_extq_enabled', true );
	}

	/**
	 * REST UPDATE callback for external_enabled field.
	 *
	 * @param mixed    $value Incoming value.
	 * @param \WP_Post $post  Post object.
	 * @param string   $field_name Field name.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function update_external_enabled_callback( $value, $post, $field_name ) {
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'invalid_post', __( 'Invalid post in update callback.', 'ielts-science-lms' ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', __( 'You are not allowed to update this resource.', 'ielts-science-lms' ) );
		}

		$enabled = false;
		if ( is_bool( $value ) ) {
			$enabled = $value;
		} elseif ( is_string( $value ) ) {
			$enabled = in_array( strtolower( $value ), array( '1', 'true', 'on' ), true );
		} elseif ( is_numeric( $value ) ) {
			$enabled = ( 1 === (int) $value );
		}

		if ( $enabled ) {
			update_post_meta( $post->ID, '_ielts_extq_enabled', '1' );
		} else {
			update_post_meta( $post->ID, '_ielts_extq_enabled', '0' );
		}

		return true;
	}

	/**
	 * REST GET callback for external_quiz_id field.
	 *
	 * @param array            $post_arr  Post array from REST API.
	 * @param string           $field_name Field name.
	 * @param \WP_REST_Request $request   Request instance.
	 * @return int External quiz ID.
	 */
	public function get_external_quiz_id_callback( $post_arr, $field_name, $request ) {
		$post_id = isset( $post_arr['id'] ) ? absint( $post_arr['id'] ) : 0;
		return (int) get_post_meta( $post_id, '_ielts_extq_id', true );
	}

	/**
	 * REST UPDATE callback for external_quiz_id field.
	 *
	 * @param mixed    $value Incoming value.
	 * @param \WP_Post $post  Post object.
	 * @param string   $field_name Field name.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function update_external_quiz_id_callback( $value, $post, $field_name ) {
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'invalid_post', __( 'Invalid post in update callback.', 'ielts-science-lms' ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', __( 'You are not allowed to update this resource.', 'ielts-science-lms' ) );
		}

		update_post_meta( $post->ID, '_ielts_extq_id', max( 0, absint( $value ) ) );
		return true;
	}
}
