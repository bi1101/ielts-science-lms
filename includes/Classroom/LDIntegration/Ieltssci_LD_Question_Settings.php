<?php
/**
 * LearnDash Question Settings Integration for IELTS Science LMS.
 *
 * This class handles custom question settings for LearnDash integration.
 *
 * @package IELTS_Science_LMS
 * @subpackage Classroom\LDIntegration
 */

namespace IeltsScienceLMS\Classroom\LDIntegration;

use WpProQuiz_Model_QuestionMapper;

/**
 * Class for managing LearnDash Question custom settings.
 */
class Ieltssci_LD_Question_Settings {

	/**
	 * Initialize the Question Settings integration.
	 */
	public function __construct() {
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

		// Create/sync ProQuiz question on REST creation of LearnDash Question posts.
		$question_pt = function_exists( 'learndash_get_post_type_slug' ) ? learndash_get_post_type_slug( 'question' ) : 'sfwd-question';
		add_action( 'rest_after_insert_' . $question_pt, array( $this, 'rest_after_insert_question' ), 10, 3 );
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

		$enabled  = (bool) get_post_meta( $post->ID, '_ielts_extq_enabled', true );
		$ext_id   = (int) get_post_meta( $post->ID, '_ielts_extq_id', true );
		$ext_type = (string) get_post_meta( $post->ID, '_ielts_extq_type', true );

		?>
		<p>
			<label>
				<input type="checkbox" id="ieltssci_extq_enabled" name="ieltssci_extq_enabled" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Enable External Question.', 'ielts-science-lms' ); ?>
			</label>
		</p>

		<div id="external-item-id-container" style="margin-left:16px; display: <?php echo $enabled ? 'block' : 'none'; ?>;">
			<p>
				<label for="ieltssci_extq_type"><strong><?php esc_html_e( 'External Quiz Type', 'ielts-science-lms' ); ?></strong></label><br />
				<input type="text" id="ieltssci_extq_type" name="ieltssci_extq_type" value="<?php echo esc_attr( $ext_type ); ?>" />
			</p>
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
		$enabled  = isset( $_POST['ieltssci_extq_enabled'] ) ? '1' : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$ext_id   = isset( $_POST['ieltssci_extq_id'] ) ? absint( $_POST['ieltssci_extq_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$ext_type = isset( $_POST['ieltssci_extq_type'] ) ? sanitize_text_field( wp_unslash( $_POST['ieltssci_extq_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.

		// Persist.
		update_post_meta( $post_id, '_ielts_extq_enabled', $enabled );
		if ( '1' === $enabled ) {
			update_post_meta( $post_id, '_ielts_extq_id', $ext_id );
			update_post_meta( $post_id, '_ielts_extq_type', $ext_type );
		} else {
			delete_post_meta( $post_id, '_ielts_extq_id' );
			delete_post_meta( $post_id, '_ielts_extq_type' );
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

		$register(
			'external_quiz_type',
			array(
				'get_callback'    => array( $this, 'get_external_quiz_type_callback' ),
				'update_callback' => array( $this, 'update_external_quiz_type_callback' ),
				'schema'          => array(
					'description' => __( 'External quiz type.', 'ielts-science-lms' ),
					'type'        => 'string',
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

		if ( $request->offsetExists( 'external_quiz_type' ) ) {
			$ext_type = sanitize_text_field( $request->get_param( 'external_quiz_type' ) );
			update_post_meta( $post_id, '_ielts_extq_type', $ext_type );
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
		\register_post_meta(
			$question_pt,
			'_ielts_extq_type',
			array(
				'single'        => true,
				'type'          => 'string',
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

	/**
	 * REST GET callback for external_quiz_type field.
	 *
	 * @param array            $post_arr  Post array from REST API.
	 * @param string           $field_name Field name.
	 * @param \WP_REST_Request $request   Request instance.
	 * @return string External quiz type.
	 */
	public function get_external_quiz_type_callback( $post_arr, $field_name, $request ) {
		$post_id = isset( $post_arr['id'] ) ? absint( $post_arr['id'] ) : 0;
		return (string) get_post_meta( $post_id, '_ielts_extq_type', true );
	}

	/**
	 * REST UPDATE callback for external_quiz_type field.
	 *
	 * @param mixed    $value Incoming value.
	 * @param \WP_Post $post  Post object.
	 * @param string   $field_name Field name.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function update_external_quiz_type_callback( $value, $post, $field_name ) {
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'invalid_post', __( 'Invalid post in update callback.', 'ielts-science-lms' ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', __( 'You are not allowed to update this resource.', 'ielts-science-lms' ) );
		}

		update_post_meta( $post->ID, '_ielts_extq_type', sanitize_text_field( $value ) );
		return true;
	}

	/**
	 * REST after-insert handler for LearnDash Questions to create/sync the ProQuiz question.
	 *
	 * Ensures that when creating a question via REST, we also create the corresponding
	 * WPProQuiz question entity, link it to the quiz, and sync LearnDash question meta.
	 *
	 * @param \WP_Post         $post     The inserted post object.
	 * @param \WP_REST_Request $request  The REST request.
	 * @param bool             $creating True when creating, false when updating.
	 */
	public function rest_after_insert_question( $post, $request, $creating ) {

		$question_pt = function_exists( 'learndash_get_post_type_slug' ) ? learndash_get_post_type_slug( 'question' ) : 'sfwd-question';
		if ( ! $creating || ! ( $post instanceof \WP_Post ) || $post->post_type !== $question_pt ) {
			return; // Not a new LearnDash question.
		}

		// Avoid double processing if already linked to ProQuiz.
		$question_post_id = (int) $post->ID;
		$existing_pro_id  = (int) get_post_meta( $question_post_id, 'question_pro_id', true );
		if ( $existing_pro_id > 0 ) {
			return; // Already linked.
		}

		// Resolve quiz linkage: accept LD quiz post ID via 'quiz' or ProQuiz quiz ID via '_quizId'.
		$quiz_wp_id  = (int) $request->get_param( 'quiz' );
		$quiz_pro_id = 0;
		if ( $quiz_wp_id > 0 ) {
			$quiz_pro_id = (int) get_post_meta( $quiz_wp_id, 'quiz_pro_id', true );
		}
		if ( $quiz_pro_id <= 0 ) {
			$quiz_pro_id = (int) $request->get_param( '_quizId' );
		}
		if ( $quiz_pro_id <= 0 ) {
			return; // Cannot create ProQuiz question without its quiz id.
		}

		// Map fields.
		$answer_type = (string) $request->get_param( 'question_type' );
		if ( '' === $answer_type ) {
			$answer_type = (string) $request->get_param( '_answerType' );
		}
		if ( '' === $answer_type ) {
			$answer_type = 'single';
		}

		$points_total           = $this->to_int( $request->get_param( 'points_total' ), $request->get_param( '_points' ) );
		$points_per_answer      = $this->to_bool( $request->get_param( 'points_per_answer' ), $request->get_param( '_answerPointsActivated' ) );
		$points_show_in_message = $this->to_bool( $request->get_param( 'points_show_in_message' ), $request->get_param( '_showPointsInBox' ) );
		$points_diff_modus      = $this->to_bool( $request->get_param( 'points_diff_modus' ), $request->get_param( '_answerPointsDiffModusActivated' ) );
		$disable_correct        = $this->to_bool( $request->get_param( 'disable_correct' ), $request->get_param( '_disableCorrect' ) );
		$correct_same           = $this->to_bool( $request->get_param( 'correct_same' ), $request->get_param( '_correctSameText' ) );
		$hints_enabled          = $this->to_bool( $request->get_param( 'hints_enabled' ), $request->get_param( '_tipEnabled' ) );

		$correct_msg   = $this->extract_text_field( $request->get_param( 'correct_message' ), $request->get_param( '_correctMsg' ) );
		$incorrect_msg = $this->extract_text_field( $request->get_param( 'incorrect_message' ), $request->get_param( '_incorrectMsg' ) );
		$tip_msg       = $this->extract_text_field( $request->get_param( 'hints_message' ), $request->get_param( '_tipMsg' ) );

		$answer_data = $request->get_param( '_answerData' );
		// Normalize incoming answer data to array if JSON string is provided (like V1 controller does).
		if ( is_string( $answer_data ) ) {
			$decoded = json_decode( $answer_data, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$answer_data = $decoded;
			}
		}
		if ( empty( $answer_data ) ) {
			$answer_data = $this->map_answers_to_proquiz( $request->get_param( 'answers' ) );
		}

		// If explicit question content is present via V1 style `_question`, prefer it.
		$question_content = (string) $post->post_content;
		$question_payload = $request->get_param( '_question' );
		if ( is_string( $question_payload ) && '' !== $question_payload ) {
			// Update the newly created post content to keep LD post aligned with ProQuiz data.
			\wp_update_post(
				array(
					'ID'           => $question_post_id,
					'post_content' => \wp_slash( $question_payload ),
				)
			);
			$question_content = $question_payload;
		}

		// Recalculate points and sanitize answers via ProQuiz validator similar to V1 update flow.
		// This makes sure provided points and answer flags are consistent with ProQuiz logic.
		if ( class_exists( '\\WpProQuiz_Controller_Question' ) ) {
			$validation_input = array(
				'answerPointsActivated'          => (bool) $points_per_answer,
				'answerPointsDiffModusActivated' => (bool) $points_diff_modus,
				'disableCorrect'                 => (bool) $disable_correct,
				'answerType'                     => $answer_type,
				'points'                         => (int) $points_total,
				'answerData'                     => is_array( $answer_data ) ? $answer_data : array(),
			);

			$validated_post = \WpProQuiz_Controller_Question::clearPost( $validation_input );

			if ( is_array( $validated_post ) ) {
				// Apply validated values back to our local variables.
				if ( isset( $validated_post['points'] ) ) {
					$points_total = (int) $validated_post['points'];
				}
				if ( isset( $validated_post['answerPointsActivated'] ) ) {
					$points_per_answer = (bool) $validated_post['answerPointsActivated'];
				}
				if ( isset( $validated_post['answerData'] ) ) {
					$answer_data = $validated_post['answerData'];
				}
			}
		}

		$post_args = array(
			'action'                          => 'new_step',
			'_title'                          => $post->post_title,
			'_quizId'                         => $quiz_pro_id,
			'_answerType'                     => $answer_type,
			'_points'                         => $points_total,
			'_answerPointsActivated'          => $points_per_answer,
			'_showPointsInBox'                => $points_show_in_message,
			'_answerPointsDiffModusActivated' => $points_diff_modus,
			'_disableCorrect'                 => $disable_correct,
			'_correctMsg'                     => $correct_msg,
			'_incorrectMsg'                   => $incorrect_msg,
			'_correctSameText'                => $correct_same,
			'_tipEnabled'                     => $hints_enabled,
			'_tipMsg'                         => $tip_msg,
			'_answerData'                     => is_array( $answer_data ) ? $answer_data : array(),
			'_question'                       => $question_content,
		);

		if ( function_exists( 'learndash_update_pro_question' ) ) {
			$question_pro_id = (int) learndash_update_pro_question( 0, $post_args );

			if ( $question_pro_id > 0 ) {
				update_post_meta( $question_post_id, 'question_pro_id', $question_pro_id );
				if ( $quiz_wp_id > 0 ) {
					update_post_meta( $question_post_id, 'quiz_id', $quiz_wp_id ); // Help LD REST 'quiz' field resolve consistently.
				}
				if ( function_exists( 'learndash_proquiz_sync_question_fields' ) ) {
					learndash_proquiz_sync_question_fields( $question_post_id, $question_pro_id );
				}

				// Ensure the question is listed under the quiz mapping meta.
				if ( $quiz_wp_id > 0 ) {
					$quiz_questions = get_post_meta( $quiz_wp_id, 'ld_quiz_questions', true );
					if ( ! is_array( $quiz_questions ) ) {
						$quiz_questions = array();
					}
					$quiz_questions[ $question_post_id ] = $question_pro_id;
					update_post_meta( $quiz_wp_id, 'ld_quiz_questions', $quiz_questions );
				}

				// Final pass: update ProQuiz model with our post args like V1 update_item does.
				// This keeps ProQuiz in sync if validation adjusted points/answers above.
				try {
					if ( class_exists( '\\WpProQuiz_Model_QuestionMapper' ) ) {
						$qm      = new WpProQuiz_Model_QuestionMapper();
						$q_model = $qm->fetch( (int) $question_pro_id );
						if ( $q_model ) {
							$q_model->set_array_to_object( $post_args );
							$qm->save( $q_model );
						}
					}
				} catch ( \Throwable $e ) {
					// Soft fail to avoid breaking the REST request, admin can re-save if needed.
					error_log( 'Failed to create ProQuiz question: ' . $e->getMessage() );
				}
			}
		}
		// Persist core LD question meta.
		update_post_meta( $question_post_id, 'question_points', $points_total );
		update_post_meta( $question_post_id, 'question_type', $answer_type );
	}

	/**
	 * Extract text from LD REST message fields that may be objects or strings.
	 *
	 * @param mixed $value    Primary value (object with raw/rendered or string).
	 * @param mixed $fallback Fallback value if primary is empty.
	 * @return string Extracted text value.
	 */
	private function extract_text_field( $value, $fallback = '' ) {
		if ( is_array( $value ) && isset( $value['raw'] ) ) {
			return (string) $value['raw'];
		}
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}
		return is_string( $fallback ) ? $fallback : '';
	}

	/**
	 * Convert a value to boolean accepting common string/number representations.
	 *
	 * @param mixed $primary   Primary value.
	 * @param mixed $secondary Secondary fallback value.
	 * @return bool Boolean result.
	 */
	private function to_bool( $primary, $secondary = null ) {
		$val = $primary;
		if ( null === $val || '' === $val ) {
			$val = $secondary;
		}
		if ( is_bool( $val ) ) {
			return $val;
		}
		if ( is_numeric( $val ) ) {
			return ( (int) $val ) === 1;
		}
		if ( is_string( $val ) ) {
			$val_l = strtolower( $val );
			return in_array( $val_l, array( '1', 'true', 'on', 'yes' ), true );
		}
		return false;
	}

	/**
	 * Convert a value to integer with fallback.
	 *
	 * @param mixed $primary   Primary value.
	 * @param mixed $secondary Secondary fallback value.
	 * @return int Integer result.
	 */
	private function to_int( $primary, $secondary = null ) {
		if ( is_numeric( $primary ) ) {
			return (int) $primary;
		}
		if ( is_numeric( $secondary ) ) {
			return (int) $secondary;
		}
		return 0;
	}

	/**
	 * Map a generic answers payload to ProQuiz-style _answerData structure when possible.
	 *
	 * @param mixed $answers Answers value from REST request.
	 * @return array Mapped _answerData array.
	 */
	private function map_answers_to_proquiz( $answers ) {
		if ( ! is_array( $answers ) ) {
			return array();
		}
		$mapped = array();
		foreach ( $answers as $ans ) {
			if ( ! is_array( $ans ) ) {
				continue; // Skip invalid entries.
			}
			$mapped[] = array(
				'_answer'             => isset( $ans['_answer'] ) ? $ans['_answer'] : ( ( isset( $ans['answer'] ) && is_string( $ans['answer'] ) ) ? $ans['answer'] : '' ),
				'_points'             => isset( $ans['_points'] ) ? (int) $ans['_points'] : ( ( isset( $ans['points'] ) && is_numeric( $ans['points'] ) ) ? (int) $ans['points'] : 0 ),
				'_sortString'         => isset( $ans['_sortString'] ) ? $ans['_sortString'] : ( isset( $ans['sortString'] ) ? $ans['sortString'] : '' ),
				'_correct'            => isset( $ans['_correct'] ) ? (bool) $ans['_correct'] : ( isset( $ans['correct'] ) ? (bool) $ans['correct'] : false ),
				'_html'               => isset( $ans['_html'] ) ? (bool) $ans['_html'] : ( isset( $ans['html'] ) ? (bool) $ans['html'] : null ),
				'_graded'             => isset( $ans['_graded'] ) ? (bool) $ans['_graded'] : ( isset( $ans['graded'] ) ? (bool) $ans['graded'] : null ),
				'_gradingProgression' => isset( $ans['_gradingProgression'] ) ? $ans['_gradingProgression'] : ( isset( $ans['gradingProgression'] ) ? $ans['gradingProgression'] : null ),
				'_gradedType'         => isset( $ans['_gradedType'] ) ? $ans['_gradedType'] : ( isset( $ans['gradedType'] ) ? $ans['gradedType'] : null ),
			);
		}
		return $mapped;
	}
}
