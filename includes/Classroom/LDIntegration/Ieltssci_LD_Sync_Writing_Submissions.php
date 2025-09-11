<?php
/**
 * LearnDash Sync Writing Submissions for IELTS Science LMS.
 *
 * This class handles syncing writing submissions with LearnDash essay question.
 *
 * @package IELTS_Science_LMS
 * @subpackage Classroom\LDIntegration
 */

namespace IeltsScienceLMS\Classroom\LDIntegration;

use WpProQuiz_Model_QuestionMapper;
use WpProQuiz_Model_QuizMapper;
use WP_REST_Request;
use IeltsScienceLMS\Writing\Ieltssci_Writing_Score;
use IeltsScienceLMS\Writing\Ieltssci_Task_Submission_Utils;
use LD_REST_Essays_Controller_V2;
use WP_REST_Server;
use WpProQuiz_Controller_Statistics;
use WP_User;

/**
 * Class for syncing writing submissions with LearnDash.
 */
class Ieltssci_LD_Sync_Writing_Submissions {

	/**
	 * Initialize the Sync Writing Submissions integration.
	 */
	public function __construct() {
		// Hook external writing task submission creation to create LD essays.
		add_action( 'ieltssci_rest_create_task_submission', array( $this, 'on_rest_create_task_submission' ), 10, 2 );
		// Hook external writing test submission creation to create a single LD essay.
		add_action( 'ieltssci_rest_create_test_submission', array( $this, 'on_rest_create_test_submission' ), 10, 2 );
		// Hook external writing task submission updates to create LD essays.
		add_action( 'ieltssci_rest_update_task_submission', array( $this, 'on_rest_update_task_submission' ), 10, 3 );
		// Hook to modify essay URLs for writing task submissions.
		add_filter( 'bb_essay_url', array( $this, 'filter_essay_url' ), 10, 2 );
	}

	/**
	 * Handle IELTS Science external writing task submission creation and create LD Essay.
	 *
	 * This listens to the custom action `ieltssci_rest_create_task_submission` fired by our REST layer when a
	 * new submission is created. It extracts the course/quiz/question IDs from the submission meta, resolves the
	 * LearnDash ProQuiz models, and creates a corresponding `sfwd-essays` post via `learndash_add_new_essay_response`.
	 * The created essay is linked back to the submission for later grading and quiz attempt creation.
	 *
	 * @param array           $created_submission The created submission data array.
	 * @param WP_REST_Request $request            Request used to create the submission.
	 *
	 * @return void
	 */
	public function on_rest_create_task_submission( $created_submission, $request ) {
		// Extract submission ID from created data.
		$submission_id = isset( $created_submission['id'] ) ? absint( $created_submission['id'] ) : 0;
		if ( $submission_id <= 0 ) {
			return; // Invalid submission ID.
		}

		// Determine the student/user to attribute the essay to.
		$user_id = isset( $created_submission['user_id'] ) ? absint( $created_submission['user_id'] ) : 0;
		if ( $user_id <= 0 ) {
			return; // Cannot create an essay without a user.
		}

		// Extract hierarchical context from submission meta.
		$meta = isset( $created_submission['meta'] ) && is_array( $created_submission['meta'] ) ? $created_submission['meta'] : array();

		$course_id    = isset( $meta['course_id'] ) ? (int) $meta['course_id'][0] : 0;
		$quiz_post_id = isset( $meta['quiz_id'] ) ? (int) $meta['quiz_id'][0] : 0;
		if ( $quiz_post_id <= 0 ) {
			return; // Quiz is required to create an essay.
		}

		// Resolve the essay question to attach to using submission meta when available.
		$question_post_id = isset( $meta['question_id'] ) ? (int) $meta['question_id'][0] : 0;
		if ( $question_post_id <= 0 ) {
			return; // question_id not included, submission not linked to LD.
		}

		// Ensure it is an essay question for safety.
		$q_type_check = get_post_meta( $question_post_id, 'question_type', true );
		if ( 'essay' !== $q_type_check ) {
			return; // Provided question is not an essay question.
		}

		$question_pro_id = (int) get_post_meta( $question_post_id, 'question_pro_id', true );
		if ( $question_pro_id <= 0 ) {
			return; // Cannot proceed without ProQuiz question link.
		}

		$question_mapper = new WpProQuiz_Model_QuestionMapper();
		$question_model  = $question_mapper->fetchById( $question_pro_id, null );
		if ( ! ( $question_model instanceof \WpProQuiz_Model_Question ) ) {
			return; // ProQuiz question not found.
		}

		// Derive the ProQuiz quiz model from the question to avoid post->pro mapping issues.
		$quiz_mapper = new WpProQuiz_Model_QuizMapper();
		$quiz_model  = $quiz_mapper->fetch( (int) $question_model->getQuizId() );
		if ( ! ( $quiz_model instanceof \WpProQuiz_Model_Quiz ) ) {
			return; // ProQuiz quiz not found.
		}

		// Create the LD essay now so it's ready for later completion and grading.
		$this->handle_create_submission( $submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, 'writing-task' );
	}

	/**
	 * Handle IELTS Science external writing test submission creation and create a single LD Essay.
	 *
	 * This listens to the `ieltssci_rest_create_test_submission` action. When a new test submission is created,
	 * it resolves a single LearnDash essay question associated with the entire `writing-test`. It then
	 * creates one corresponding `sfwd-essays` post, linking it to the main test submission ID. This allows
	 * the entire test to be represented by a single gradable essay item in LearnDash.
	 *
	 * @param array           $created_test_submission The created test submission data array.
	 * @param WP_REST_Request $request                 Request used to create the submission.
	 *
	 * @return void
	 */
	public function on_rest_create_test_submission( $created_test_submission, $request ) {
		// Extract IDs from the created test submission data.
		$test_submission_id = isset( $created_test_submission['id'] ) ? absint( $created_test_submission['id'] ) : 0;
		$test_id            = isset( $created_test_submission['test_id'] ) ? absint( $created_test_submission['test_id'] ) : 0;
		$user_id            = isset( $created_test_submission['user_id'] ) ? absint( $created_test_submission['user_id'] ) : 0;

		if ( $test_submission_id <= 0 || $user_id <= 0 || $test_id <= 0 ) {
			return; // Cannot proceed without essential IDs.
		}

		// Extract hierarchical context from submission meta.
		$meta = isset( $created_test_submission['meta'] ) && is_array( $created_test_submission['meta'] ) ? $created_test_submission['meta'] : array();

		$course_id    = isset( $meta['course_id'] ) ? (int) $meta['course_id'][0] : 0;
		$quiz_post_id = isset( $meta['quiz_id'] ) ? (int) $meta['quiz_id'][0] : 0;
		if ( $quiz_post_id <= 0 ) {
			return; // Quiz is required to create an essay.
		}

		$question_post_id = (int) isset( $meta['question_id'] ) ? (int) $meta['question_id'][0] : 0;
		if ( $question_post_id <= 0 ) {
			return; // Test not linked to a LearnDash question.
		}

		// Ensure it is an essay question for safety.
		$q_type_check = get_post_meta( $question_post_id, 'question_type', true );
		if ( 'essay' !== $q_type_check ) {
			return; // Linked question is not an essay question.
		}

		$question_pro_id = (int) get_post_meta( $question_post_id, 'question_pro_id', true );
		if ( $question_pro_id <= 0 ) {
			return; // Cannot proceed without ProQuiz question link.
		}

		$question_mapper = new WpProQuiz_Model_QuestionMapper();
		$question_model  = $question_mapper->fetchById( $question_pro_id, null );
		if ( ! ( $question_model instanceof \WpProQuiz_Model_Question ) ) {
			return; // ProQuiz question not found.
		}

		// Derive the ProQuiz quiz model from the question.
		$quiz_mapper = new WpProQuiz_Model_QuizMapper();
		$quiz_model  = $quiz_mapper->fetch( (int) $question_model->getQuizId() );
		if ( ! ( $quiz_model instanceof \WpProQuiz_Model_Quiz ) ) {
			return; // ProQuiz quiz not found.
		}

		// Create the single LD essay for the entire test, linking it with the main test submission ID.
		$this->handle_create_submission( $test_submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, 'writing-test' );
	}

	/**
	 * Handle IELTS Science external writing task submission updates and create/update LD Essay.
	 *
	 * This listens to the custom action `ieltssci_rest_update_task_submission` fired by our REST layer.
	 * It extracts course/quiz/lesson/topic IDs from the submission meta, resolves the associated
	 * LearnDash essay question, and creates a `sfwd-essays` post via `learndash_add_new_essay_response`
	 * for completed submissions, or updates existing essays for graded submissions.
	 *
	 * @param array           $updated_submission The updated submission data array.
	 * @param array           $existing_submission The original submission data before update.
	 * @param WP_REST_Request $request            Request used to update the submission.
	 *
	 * @return void
	 */
	public function on_rest_update_task_submission( $updated_submission, $existing_submission, $request ) {

		// Extract submission ID from updated data.
		$submission_id = isset( $updated_submission['id'] ) ? absint( $updated_submission['id'] ) : 0;
		if ( $submission_id <= 0 ) {
			return; // Invalid submission ID.
		}

		// Determine the student/user to attribute the essay to.
		$user_id = isset( $updated_submission['user_id'] ) ? absint( $updated_submission['user_id'] ) : 0;
		if ( $user_id <= 0 ) {
			return; // Cannot create an essay without a user.
		}

		// Check if the submission status is completed or graded.
		$submission_status = isset( $updated_submission['status'] ) ? $updated_submission['status'] : '';
		if ( ! in_array( $submission_status, array( 'completed', 'graded', 'not_graded' ), true ) ) {
			return; // Only process completed or graded submissions.
		}

		// Extract hierarchical context from submission meta.
		$meta = isset( $updated_submission['meta'] ) && is_array( $updated_submission['meta'] ) ? $updated_submission['meta'] : array();

		$course_id    = isset( $meta['course_id'] ) ? (int) $meta['course_id'][0] : 0;
		$quiz_post_id = isset( $meta['quiz_id'] ) ? (int) $meta['quiz_id'][0] : 0;
		$lesson_id    = isset( $meta['lesson_id'] ) ? (int) $meta['lesson_id'][0] : 0;
		$topic_id     = isset( $meta['topic_id'] ) ? (int) $meta['topic_id'][0] : 0;

		if ( $quiz_post_id <= 0 ) {
			return; // Quiz is required to create an essay.
		}

		// Resolve the essay question to attach to using submission meta when available.
		$question_post_id = isset( $meta['question_id'] ) ? (int) $meta['question_id'][0] : 0;

		if ( $question_post_id <= 0 ) {
			return; // question_id not included, submission not linked to LD.
		}

		// Ensure it is an essay question for safety.
		$q_type_check = get_post_meta( $question_post_id, 'question_type', true );

		if ( 'essay' !== $q_type_check ) {
			return; // Provided question is not an essay question.
		}

		$question_pro_id = (int) get_post_meta( $question_post_id, 'question_pro_id', true );
		if ( $question_pro_id <= 0 ) {
			return; // Cannot proceed without ProQuiz question link.
		}

		$question_mapper = new WpProQuiz_Model_QuestionMapper();
		$question_model  = $question_mapper->fetchById( $question_pro_id, null );
		if ( ! ( $question_model instanceof \WpProQuiz_Model_Question ) ) {
			return; // ProQuiz question not found.
		}

		// Derive the ProQuiz quiz model from the question to avoid post->pro mapping issues.
		$quiz_mapper = new WpProQuiz_Model_QuizMapper();
		$quiz_model  = $quiz_mapper->fetch( (int) $question_model->getQuizId() );
		if ( ! ( $quiz_model instanceof \WpProQuiz_Model_Quiz ) ) {
			return; // ProQuiz quiz not found.
		}

		// Route to appropriate handler based on submission status.
		switch ( $submission_status ) {
			case 'completed':
				$this->handle_completed_submission( $updated_submission, $submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, $submission_status );
				break;

			case 'not_graded':
			case 'graded':
				$this->handle_graded_submission( $updated_submission, $submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, $submission_status );
				break;

			default:
				// Status not handled, do nothing.
				break;
		}
	}

	/**
	 * Handle completed submission by creating a LearnDash quiz attempt only.
	 *
	 * This method assumes the related LearnDash `sfwd-essays` post was already created during the
	 * submission creation phase. It looks up that essay via `_ielts_submission_id` linkage and
	 * initializes a LearnDash quiz attempt in user meta, ensuring course progression works.
	 *
	 * Steps:
	 * 1. Locate the LD essay created for this submission via post meta.
	 * 2. Resolve optional start time (from external essay created_at) and elapsed time from meta.
	 * 3. Call `create_ld_quiz_attempt_from_essay()` to persist attempt and fire LD hooks.
	 *
	 * @param array  $updated_submission The updated submission data.
	 * @param int    $submission_id      The submission ID.
	 * @param int    $user_id            The user ID.
	 * @param int    $course_id          The course ID.
	 * @param int    $quiz_post_id       The quiz post ID.
	 * @param object $question_model     The ProQuiz question model.
	 * @param object $quiz_model         The ProQuiz quiz model.
	 * @param string $submission_status  The submission status.
	 */
	private function handle_completed_submission( $updated_submission, $submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, $submission_status ) {
		// Initialize quiz time placeholder (seconds) so it's defined for all paths.
		$quiz_time = 0; // Will be overwritten if meta elapsed_time present.

		// Try to derive start timestamp and content from the external essay record if available.
		$essay_created_at_unix = 0; // Will hold essay creation timestamp for quiz attempt start.
		$response_text         = ''; // Will hold the final essay content to sync into the LD essay post.
		$ext_essay_id          = 0;
		if ( isset( $updated_submission['essay_id'] ) && (int) $updated_submission['essay_id'] > 0 ) {
			$ext_essay_id = (int) $updated_submission['essay_id'];
		} elseif ( isset( $updated_submission['meta']['essay_id'] ) && (int) $updated_submission['meta']['essay_id'] > 0 ) {
			$ext_essay_id = (int) $updated_submission['meta']['essay_id'];
		}
		if ( $ext_essay_id > 0 ) {
			try {
				$essay_db = new \IeltsScienceLMS\Writing\Ieltssci_Essay_DB();
				$essays   = $essay_db->get_essays(
					array(
						'id'       => $ext_essay_id,
						'per_page' => 1,
					)
				);
				if ( ! is_wp_error( $essays ) ) {
					$essay_row = isset( $essays['id'] ) ? $essays : ( is_array( $essays ) && ! empty( $essays ) ? reset( $essays ) : array() );
					if ( is_array( $essay_row ) && ! empty( $essay_row['created_at'] ) ) {
						$created_raw = $essay_row['created_at'];
						$ts          = strtotime( $created_raw );
						if ( $ts && $ts > 0 ) {
							$essay_created_at_unix = $ts;
						}
					}
					// Extract essay content string.
					if ( is_array( $essay_row ) && isset( $essay_row['essay_content'] ) ) {
						$response_text = (string) $essay_row['essay_content'];
					}
				}
			} catch ( \Throwable $e ) {
				error_log( 'Error fetching external essay for submission ID ' . $submission_id . ': ' . $e->getMessage() );
			}
		}

		// Derive quiz elapsed time (in minutes) from updated submission meta if available (fallback 0).
		if ( isset( $updated_submission['meta']['elapsed_time'][0] ) ) {
			$et_raw = $updated_submission['meta']['elapsed_time'][0];
			if ( is_array( $et_raw ) ) {
				$et_raw = reset( $et_raw );
			}
			$quiz_time = max( 0, (int) $et_raw * 60 ); // Convert minutes to seconds.
		}

		// Find the LD essay associated with this submission via meta linkage.
		$essay_post_id = 0;
		$essay_ids     = get_posts(
			array(
				'post_type'              => 'sfwd-essays',
				'post_status'            => array( 'publish', 'not_graded', 'graded', 'draft' ),
				'numberposts'            => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'cache_results'          => false,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'   => '_ielts_submission_id',
						'value' => $submission_id,
					),
					array(
						'key'   => '_ielts_question_type',
						'value' => 'writing-task',
					),
				),
			)
		);
		if ( is_array( $essay_ids ) && ! empty( $essay_ids ) ) {
			$essay_post_id = (int) ( is_object( $essay_ids[0] ) ? $essay_ids[0]->ID : $essay_ids[0] );
		}
		if ( $essay_post_id <= 0 ) {
			return; // No previously created LD essay found for this submission.
		}

		// If we have external content, update the LD essay's content (and store in a meta key for compatibility).
		$response_text = trim( (string) $response_text );
		if ( '' !== $response_text ) {
			// Update post content.
			wp_update_post(
				array(
					'ID'           => $essay_post_id,
					'post_content' => $response_text,
				)
			);
		}

		// Initialize a LearnDash quiz attempt so _sfwd-quizzes and activity entries are initialized.
		$this->create_ld_quiz_attempt_from_essay( $user_id, $quiz_post_id, $quiz_model, $question_model, $essay_post_id, $course_id, $essay_created_at_unix, $quiz_time ); // Direct meta/action initialization.
	}

	/**
	 * Handle new submission by creating a new LearnDash essay post (sfwd-essays).
	 *
	 * This method creates the LD essay early in the submission lifecycle so that later updates (e.g.,
	 * completion and grading) can reference the same essay. It ensures the correct post_author, links
	 * the essay back to the submission via post meta, and sets the initial status to 'not_graded'.
	 *
	 * Steps:
	 * 1. Check if an essay already exists for this submission via meta query; if yes, return early.
	 * 2. Switch the current user context to the student to set the correct post_author.
	 * 3. Call `learndash_add_new_essay_response( '', $question_model, $quiz_model, $post_data )` to create the essay.
	 * 4. Restore the original current user context.
	 * 5. If created, set post_status to 'not_graded' and add linkage metas.
	 *
	 * @param int                       $submission_id  The submission ID.
	 * @param int                       $user_id        The user ID.
	 * @param int                       $course_id      The course ID.
	 * @param int                       $quiz_post_id   The quiz post ID.
	 * @param \WpProQuiz_Model_Question $question_model The ProQuiz question model.
	 * @param \WpProQuiz_Model_Quiz     $quiz_model     The ProQuiz quiz model.
	 * @param string                    $question_type  The type of question ('writing-task' or 'writing-test').
	 *
	 * @return void
	 */
	private function handle_create_submission( $submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, $question_type = 'writing-task' ) {
		// Ensure no duplicate essay exists for this submission.
		$existing = get_posts(
			array(
				'post_type'              => 'sfwd-essays',
				'post_status'            => array( 'publish', 'not_graded', 'graded', 'draft' ),
				'numberposts'            => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'cache_results'          => false,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'   => '_ielts_submission_id',
						'value' => $submission_id,
					),
					array(
						'key'   => '_ielts_question_type',
						'value' => $question_type,
					),
				),
			)
		);
		if ( ! empty( $existing ) ) {
			return; // Essay already created for this submission.
		}

		if ( ! ( $question_model instanceof \WpProQuiz_Model_Question ) || ! ( $quiz_model instanceof \WpProQuiz_Model_Quiz ) ) {
			return; // Safety check for required models.
		}

		// Temporarily set current user to the student to ensure essay post_author is correct.
		$current_user_id = get_current_user_id();
		if ( $current_user_id !== $user_id ) {
			wp_set_current_user( $user_id );
		}

		$post_data = array(
			'quiz_id'   => $quiz_post_id,
			'course_id' => $course_id,
		);

		// Create the LD Essay post with empty/initial content.
		$essay_id = \learndash_add_new_essay_response( '', $question_model, $quiz_model, $post_data );

		// Restore previous current user if changed.
		if ( $current_user_id !== $user_id ) {
			wp_set_current_user( $current_user_id );
		}

		if ( is_numeric( $essay_id ) && $essay_id > 0 ) {
			// Ensure essay status is 'not_graded'.
			wp_update_post(
				array(
					'ID'          => $essay_id,
					'post_status' => 'not_graded',
				)
			);

			// Link back to submission for traceability.
			add_post_meta( $essay_id, '_ielts_submission_id', $submission_id, true );

			// Add meta to differentiate question type (writing-task or writing-test).
			add_post_meta( $essay_id, '_ielts_question_type', $question_type, true );
		}
	}

	/**
	 * Directly create a LearnDash quiz attempt for an externally created essay without mimicking the AJAX controller.
	 *
	 * This mirrors the essential parts of LD_QuizPro->wp_pro_quiz_completed() needed for course progression:
	 * - Build $quizdata structure.
	 * - Save statistics if enabled for the quiz.
	 * - Append to user meta _sfwd-quizzes.
	 * - Fire learndash_quiz_submitted and learndash_quiz_completed actions.
	 *
	 * @param int                       $user_id       User ID owning the attempt.
	 * @param int                       $quiz_post_id  WP post ID of the quiz.
	 * @param \WpProQuiz_Model_Quiz     $quiz_model    ProQuiz quiz model instance.
	 * @param \WpProQuiz_Model_Question $question_model ProQuiz essay question model instance.
	 * @param int                       $essay_post_id Newly created LD essay post ID.
	 * @param int                       $course_id     Related course ID (0 if standalone).
	 * @param int                       $start_unix    Optional original start timestamp (seconds).
	 * @param int                       $quiz_time     Elapsed quiz time in seconds.
	 * @return void
	 */
	private function create_ld_quiz_attempt_from_essay( $user_id, $quiz_post_id, $quiz_model, $question_model, $essay_post_id, $course_id, $start_unix = 0, $quiz_time = 0 ) {
		if ( ! ( $quiz_model instanceof \WpProQuiz_Model_Quiz ) ) {
			return; // Invalid quiz model.
		}
		if ( ! ( $question_model instanceof \WpProQuiz_Model_Question ) ) {
			return; // Invalid question model.
		}
		if ( $user_id <= 0 ) {
			return; // Must have user.
		}

		// Prevent duplicate creation for same essay.
		$existing_attempts = get_user_meta( $user_id, '_sfwd-quizzes', true );
		if ( is_array( $existing_attempts ) ) {
			foreach ( $existing_attempts as $attempt ) {
				if ( isset( $attempt['quiz'], $attempt['graded'] ) && (int) $attempt['quiz'] === (int) $quiz_post_id ) {
					foreach ( (array) $attempt['graded'] as $gq ) {
						if ( isset( $gq['post_id'] ) && (int) $gq['post_id'] === (int) $essay_post_id ) {
							return; // Already recorded.
						}
					}
				}
			}
		}

		$quiz_pro_id     = (int) $quiz_model->getId();
		$question_pro_id = (int) $question_model->getId();
		$question_points = (int) $question_model->getPoints();
		if ( $question_points <= 0 ) {
			$question_points = 1; // Minimal points fallback.
		}

		// Derive lesson/topic (optional).
		$lesson_id = 0;
		$topic_id  = 0;
		if ( function_exists( 'learndash_course_get_single_parent_step' ) && $course_id > 0 ) {
			$lesson_id = (int) learndash_course_get_single_parent_step( $course_id, $quiz_post_id, 'sfwd-lessons' );
			$topic_id  = (int) learndash_course_get_single_parent_step( $course_id, $quiz_post_id, 'sfwd-topic' );
		}

		// Build graded question entry mirroring LD structure.
		$graded = array(
			$question_pro_id => array(
				'post_id'        => $essay_post_id,
				'points_awarded' => 0,
				'status'         => 'not_graded',
			),
		);

		// Passing percentage check.
		$quiz_post_settings = learndash_get_setting( $quiz_post_id );
		if ( ! is_array( $quiz_post_settings ) ) {
			$quiz_post_settings = array();
		}
		if ( ! isset( $quiz_post_settings['passingpercentage'] ) ) {
			$quiz_post_settings['passingpercentage'] = 0; // Ensure index.
		}
		$passingpercentage = absint( $quiz_post_settings['passingpercentage'] );

		// No score yet (ungraded essay) so percentage is 0.
		$result = 0;
		$pass   = ( $result >= $passingpercentage ) ? 1 : 0;

		// Time handling similar to LD logic: if we have a start + elapsed build difference anchored to now.
		$now = time();
		if ( $start_unix > 0 && $quiz_time > 0 ) {
			$started   = $now - (int) $quiz_time; // Anchor to current time like LD recomputation.
			$completed = $now; // Completed now.
		} else {
			$started   = 0;
			$completed = 0;
		}

		$questions_count       = 1; // Only essay question represented.
		$questions_shown_count = 1; // Same as above.

		$quizdata = array(
			'quiz'                => $quiz_post_id,
			'score'               => 0,
			'count'               => $questions_count,
			'question_show_count' => $questions_shown_count,
			'pass'                => $pass,
			'rank'                => '-',
			'time'                => $now,
			'pro_quizid'          => $quiz_pro_id,
			'course'              => $course_id,
			'lesson'              => $lesson_id,
			'topic'               => $topic_id,
			'points'              => 0,
			'total_points'        => $question_points,
			'percentage'          => 0,
			'timespent'           => $quiz_time,
			'has_graded'          => true,
			'statistic_ref_id'    => 0, // Will update if statistics saved.
			'started'             => $started,
			'completed'           => $completed,
			'graded'              => $graded,
			'ld_version'          => defined( 'LEARNDASH_VERSION' ) ? LEARNDASH_VERSION : 'unknown',
		);

		$quizdata['quiz_key'] = $quizdata['completed'] . '_' . $quiz_pro_id . '_' . $quiz_post_id . '_' . absint( $course_id );

		// Save statistics if enabled for the quiz.
		$statistic_ref_id = 0;
		if ( method_exists( $quiz_model, 'isStatisticsOn' ) && $quiz_model->isStatisticsOn() ) {
			// Prepare a minimal results payload compatible with WpProQuiz_Controller_Statistics::save().
			$results_payload = array(
				$question_pro_id => array(
					'time'           => 0,
					'points'         => 0,
					'correct'        => 0,
					'possiblePoints' => $question_points,
					'data'           => array( 'graded_id' => $essay_post_id ),
					'graded_id'      => $essay_post_id,
				),
				'comp'           => array(
					'quizTime' => max( 0, (int) $quiz_time ),
				),
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$original_post_glob = $_POST; // Backup global.
			// Populate expected keys; controller uses $this->_post not raw $_POST but parent copies it from superglobal.
			$_POST = array(
				'quizId'    => $quiz_pro_id,
				'quiz'      => $quiz_post_id,
				'course_id' => $course_id,
				'results'   => $results_payload,
			);
			try {
				$stats_controller = new WpProQuiz_Controller_Statistics();
				$statistic_ref_id = (int) $stats_controller->save( $quiz_model );
			} catch ( \Throwable $e ) {
				$statistic_ref_id = 0; // Fail silently.
			}
			$_POST = $original_post_glob; // Restore.
		}
		if ( $statistic_ref_id > 0 ) {
			$quizdata['statistic_ref_id'] = $statistic_ref_id;
		}

		// Persist to user meta BEFORE enriching quizdata with objects, mirroring wp_pro_quiz_completed().
		$user_quiz_meta = array();
		if ( is_array( $existing_attempts ) ) {
			$user_quiz_meta = $existing_attempts;
		}
		$user_quiz_meta[] = $quizdata;
		update_user_meta( $user_id, '_sfwd-quizzes', $user_quiz_meta );

		// Now attach objects for action consumers (post-persistence enrichment like LD core does).
		$quizdata['course'] = ! empty( $course_id ) ? get_post( $course_id ) : 0;
		$quizdata['lesson'] = ! empty( $lesson_id ) ? get_post( $lesson_id ) : 0;
		$quizdata['topic']  = ! empty( $topic_id ) ? get_post( $topic_id ) : 0;
		// Populate questions array with the full quiz question set like core LD (fallback to single essay question)..
		try {
			$question_mapper_all = new WpProQuiz_Model_QuestionMapper();
			$questions_all       = $question_mapper_all->fetchAll( $quiz_model );
			if ( is_array( $questions_all ) && ! empty( $questions_all ) ) {
				$quizdata['questions'] = $questions_all;
			} else {
				$quizdata['questions'] = array( $question_model ); // Fallback single question array.
			}
		} catch ( \Throwable $e ) {
			$quizdata['questions'] = array( $question_model ); // Safety fallback.
		}

		// Fire hooks for LD integrations.
		/**
		 * Fires after the quiz is submitted
		 *
		 * @since 3.0.0
		 *
		 * @param array   $quiz_data    An array of quiz data.
		 * @param WP_User $current_user Current user object.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		do_action( 'learndash_quiz_submitted', $quizdata, get_user_by( 'id', $user_id ) );
		// Mirror core parent step completion logic from wp_pro_quiz_completed() prior to final learndash_quiz_completed action.
		if ( ! empty( $course_id ) && function_exists( 'learndash_process_mark_complete' ) && function_exists( 'learndash_can_complete_step' ) ) {
			$quiz_parent_post_id = 0;
			if ( ! empty( $topic_id ) ) {
				$quiz_parent_post_id = $topic_id;
			} elseif ( ! empty( $lesson_id ) ) {
				$quiz_parent_post_id = $lesson_id;
			}

			if ( ! empty( $quiz_parent_post_id ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
				$complete_all = apply_filters( 'learndash_complete_all_parent_steps', true, $quiz_post_id, $user_id, $course_id );
				if ( $complete_all ) {
					if ( ! empty( $topic_id ) && learndash_can_complete_step( $user_id, $topic_id, $course_id ) ) {
						learndash_process_mark_complete( $user_id, $topic_id, false, $course_id );
					}
					if ( ! empty( $lesson_id ) && learndash_can_complete_step( $user_id, $lesson_id, $course_id ) ) {
						learndash_process_mark_complete( $user_id, $lesson_id, false, $course_id );
					}
				} elseif ( learndash_can_complete_step( $user_id, $quiz_parent_post_id, $course_id ) ) {
					learndash_process_mark_complete( $user_id, $quiz_parent_post_id, false, $course_id );
				}
			} elseif ( function_exists( 'learndash_get_global_quiz_list' ) && function_exists( 'learndash_is_quiz_notcomplete' ) ) {
				// If quiz has no direct parent lesson/topic, check if all quizzes are complete then maybe complete the course (core behavior).
				$all_quizzes_complete = true;
				$quizzes_list         = learndash_get_global_quiz_list( $course_id );
				if ( ! empty( $quizzes_list ) ) {
					foreach ( $quizzes_list as $q_obj ) {
						if ( learndash_is_quiz_notcomplete( $user_id, array( $q_obj->ID => 1 ), false, $course_id ) ) {
							$all_quizzes_complete = false;
							break;
						}
					}
				}
				if ( true === $all_quizzes_complete && learndash_can_complete_step( $user_id, $course_id, $course_id ) ) {
					learndash_process_mark_complete( $user_id, $course_id, false, $course_id );
				}
			}
		}
		/** This action mirrors LD core learndash_quiz_completed hook firing for progress triggers. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		do_action( 'learndash_quiz_completed', $quizdata, get_user_by( 'id', $user_id ) );
	}

	/**
	 * Handle graded submission by updating existing essay.
	 *
	 * @param array  $updated_submission The updated submission data.
	 * @param int    $submission_id      The submission ID.
	 * @param int    $user_id            The user ID.
	 * @param int    $course_id          The course ID.
	 * @param int    $quiz_post_id       The quiz post ID.
	 * @param object $question_model    The ProQuiz question model.
	 * @param object $quiz_model        The ProQuiz quiz model.
	 * @param string $submission_status  The submission status.
	 */
	private function handle_graded_submission( $updated_submission, $submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, $submission_status ) {

		// Find the external essay linked to this $updated_submission.
		$ext_essay_id = 0;
		if ( isset( $updated_submission['essay_id'] ) && (int) $updated_submission['essay_id'] > 0 ) {
			$ext_essay_id = (int) $updated_submission['essay_id'];
		}

		if ( $ext_essay_id <= 0 ) {
			return; // No linked essay.
		}

		// Retrieve essay data from the IELTS Science essays table.
		$essay_db = new \IeltsScienceLMS\Writing\Ieltssci_Essay_DB();
		$essays   = $essay_db->get_essays(
			array(
				'id'       => $ext_essay_id,
				'per_page' => 1,
			)
		);

		if ( is_wp_error( $essays ) || empty( $essays ) ) {
			return; // Unable to fetch essay.
		}

		// Normalize to essay array.
		if ( isset( $essays['id'] ) ) {
			$essay = $essays;
		} else {
			$essay = is_array( $essays ) && ! empty( $essays ) ? reset( $essays ) : array();
		}

		if ( empty( $essay ) ) {
			return; // No essay data.
		}

		// Get the overall score.
		$writing_score = new Ieltssci_Writing_Score();
		$score_data    = $writing_score->get_overall_score( $essay, 'final' );

		if ( ! $score_data || ! isset( $score_data['score'] ) ) {
			return; // Unable to calculate score.
		}

		$overall_score = $score_data['score'];

		// Convert overall score to 0-100 scale for LearnDash grading consistency.
		$percent_score = max( 0.0, min( 100.0, round( ( $overall_score / 9.0 ) * 100.0, 2 ) ) );

		// Find the LD essay associated with this submission via meta linkage.
		$essay_post_id = 0;
		$essay_ids     = get_posts(
			array(
				'post_type'   => 'sfwd-essays',
				'post_status' => array( 'publish', 'not_graded', 'graded', 'draft' ),
				'meta_query'  => array(
					array(
						'key'   => '_ielts_submission_id',
						'value' => $submission_id,
					),
					array(
						'key'   => '_ielts_question_type',
						'value' => 'writing-task',
					),
				),
			)
		);

		if ( is_array( $essay_ids ) && ! empty( $essay_ids ) ) {
			$essay_post_id = $essay_ids[0]->ID;
		}

		// Update the LD Essay via REST controller with points_awarded using percent score.
		if ( $essay_post_id > 0 && class_exists( '\LD_REST_Essays_Controller_V2' ) ) {
			try {
				$controller = new LD_REST_Essays_Controller_V2();
				$request    = new WP_REST_Request( WP_REST_Server::EDITABLE, '/ldlms/v2/essays/' . $essay_post_id );
				$request->set_param( 'id', $essay_post_id );
				$request->set_param( 'points_awarded', $percent_score );
				$request->set_param( 'status', $submission_status );

				$response = $controller->update_item( $request );
				if ( is_wp_error( $response ) ) {
					return; // Failed to update essay via REST controller.
				}
			} catch ( \Throwable $e ) {
				return; // Safety: controller update failed.
			}
		}
	}

	/**
	 * Filter essay URL to redirect to writing task submission result permalink when applicable.
	 *
	 * This method hooks into the 'bb_essay_url' filter to check if an essay is linked
	 * to a writing task submission. If so, it returns the task submission result permalink
	 * instead of the default essay permalink.
	 *
	 * @param string $essay_url     The original essay URL.
	 * @param int    $essay_post_id The essay post ID.
	 * @return string The filtered essay URL.
	 */
	public function filter_essay_url( $essay_url, $essay_post_id ) {
		// Validate essay post ID.
		$essay_post_id = absint( $essay_post_id );
		if ( $essay_post_id <= 0 ) {
			return $essay_url; // Return original URL if invalid.
		}

		// Check if this essay has an IELTS submission ID.
		$submission_id = get_post_meta( $essay_post_id, '_ielts_submission_id', true );
		if ( empty( $submission_id ) ) {
			return $essay_url; // No submission linked, return original URL.
		}

		// Check if this is a writing task submission.
		$question_type = get_post_meta( $essay_post_id, '_ielts_question_type', true );
		if ( 'writing-task' !== $question_type ) {
			return $essay_url; // Not a writing task, return original URL.
		}

		// Get the task submission permalink.
		$task_submission_url = Ieltssci_Task_Submission_Utils::get_task_submission_result_permalink( $submission_id );
		if ( empty( $task_submission_url ) ) {
			return $essay_url; // Failed to get submission permalink, return original URL.
		}

		return $task_submission_url; // Return the task submission permalink.
	}
}
