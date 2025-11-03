<?php
/**
 * LearnDash Sync Speaking Submissions for IELTS Science LMS.
 *
 * This class handles syncing speaking part submissions with LearnDash essay questions when
 * the part is linked to a LearnDash quiz essay question via meta (course_id, quiz_id, question_id).
 * It also exposes a filter to route essay URLs back to the IELTS Science Speaking result pages.
 *
 * @package IELTS_Science_LMS
 * @subpackage Classroom\LDIntegration
 */

namespace IeltsScienceLMS\Classroom\LDIntegration;

use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use WP_Post;
use WpProQuiz_Model_QuestionMapper;
use WpProQuiz_Model_QuizMapper;
use IeltsScienceLMS\Speaking\Ieltssci_Speaking_Part_Submission_Utils;
use LD_REST_Essays_Controller_V2;
use WP_REST_Server;
use WpProQuiz_Controller_Statistics;
use WpProQuiz_Model_Question;
use WpProQuiz_Model_Quiz;

/**
 * Class for syncing speaking submissions with LearnDash.
 */
class Ieltssci_LD_Sync_Speaking_Submissions {

	/**
	 * Initialize the Sync Speaking Submissions integration.
	 */
	public function __construct() {
		// Hook external speaking part submission creation to create LD essays.
		add_action( 'ieltssci_rest_create_part_submission', array( $this, 'on_rest_create_part_submission' ), 10, 2 );
		// Hook external speaking part submission updates to update LD essays and attempts.
		add_action( 'ieltssci_rest_update_part_submission', array( $this, 'on_rest_update_part_submission' ), 10, 3 );

		// Hook to modify essay URLs for speaking part submissions.
		add_filter( 'bb_essay_url', array( $this, 'filter_essay_url' ), 10, 2 );

		// Hook to add post meta to essay REST responses and include result link.
		if ( function_exists( 'learndash_get_post_type_slug' ) ) {
			$essay_post_type = learndash_get_post_type_slug( 'essay' );
			add_filter( 'rest_prepare_' . $essay_post_type, array( $this, 'add_ieltssci_data_to_essay_response' ), 10, 3 );
		}
	}

	/**
	 * Handle IELTS Science external speaking part submission creation and create LD Essay.
	 *
	 * @param array           $created_submission The created part submission data array.
	 * @param WP_REST_Request $request            Request used to create the submission.
	 *
	 * @return void
	 */
	public function on_rest_create_part_submission( $created_submission, $request ) {
		$submission_id = isset( $created_submission['id'] ) ? absint( $created_submission['id'] ) : 0;
		$user_id       = isset( $created_submission['user_id'] ) ? absint( $created_submission['user_id'] ) : 0;
		if ( $submission_id <= 0 || $user_id <= 0 ) {
			return; // Missing essential identifiers.
		}

		$meta = isset( $created_submission['meta'] ) && is_array( $created_submission['meta'] ) ? $created_submission['meta'] : array();

		$course_id     = isset( $meta['course_id'] ) ? (int) $meta['course_id'][0] : 0;
		$quiz_post_id  = isset( $meta['quiz_id'] ) ? (int) $meta['quiz_id'][0] : 0;
		$question_post = isset( $meta['question_id'] ) ? (int) $meta['question_id'][0] : 0;

		if ( $quiz_post_id <= 0 || $question_post <= 0 ) {
			return; // Not linked to a LD quiz essay question.
		}

		// Ensure it is an essay question.
		$q_type_check = get_post_meta( $question_post, 'question_type', true );
		if ( 'essay' !== $q_type_check ) {
			return; // Provided question is not an essay question.
		}

		$question_pro_id = (int) get_post_meta( $question_post, 'question_pro_id', true );
		if ( $question_pro_id <= 0 ) {
			return; // Cannot proceed without ProQuiz question link.
		}

		$question_mapper = new WpProQuiz_Model_QuestionMapper();
		$question_model  = $question_mapper->fetchById( $question_pro_id, null );
		if ( ! ( $question_model instanceof WpProQuiz_Model_Question ) ) {
			return; // ProQuiz question not found.
		}

		$quiz_mapper = new WpProQuiz_Model_QuizMapper();
		$quiz_model  = $quiz_mapper->fetch( (int) $question_model->getQuizId() );
		if ( ! ( $quiz_model instanceof WpProQuiz_Model_Quiz ) ) {
			return; // ProQuiz quiz not found.
		}

		$this->handle_create_submission( $submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, 'speaking-part' );
	}

	/**
	 * Handle IELTS Science external speaking part submission updates and update LD essay/attempt.
	 *
	 * @param array           $updated_submission  The updated part submission data array.
	 * @param array           $existing_submission The previous part submission data array.
	 * @param WP_REST_Request $request             Request used to update the submission.
	 *
	 * @return void
	 */
	public function on_rest_update_part_submission( $updated_submission, $existing_submission, $request ) {
		$submission_id = isset( $updated_submission['id'] ) ? absint( $updated_submission['id'] ) : 0;
		$user_id       = isset( $updated_submission['user_id'] ) ? absint( $updated_submission['user_id'] ) : 0;
		if ( $submission_id <= 0 || $user_id <= 0 ) {
			return; // Missing essential identifiers.
		}

		$status = isset( $updated_submission['status'] ) ? (string) $updated_submission['status'] : '';
		if ( ! in_array( $status, array( 'completed', 'graded', 'not_graded' ), true ) ) {
			return; // Process only on completion/grade updates.
		}

		$meta          = isset( $updated_submission['meta'] ) && is_array( $updated_submission['meta'] ) ? $updated_submission['meta'] : array();
		$course_id     = isset( $meta['course_id'] ) ? (int) $meta['course_id'][0] : 0;
		$quiz_post_id  = isset( $meta['quiz_id'] ) ? (int) $meta['quiz_id'][0] : 0;
		$question_post = isset( $meta['question_id'] ) ? (int) $meta['question_id'][0] : 0;
		if ( $quiz_post_id <= 0 || $question_post <= 0 ) {
			return; // Not linked to a LD quiz essay question.
		}

		$question_pro_id = (int) get_post_meta( $question_post, 'question_pro_id', true );
		if ( $question_pro_id <= 0 ) {
			return; // Cannot proceed without ProQuiz question link.
		}
		$question_mapper = new WpProQuiz_Model_QuestionMapper();
		$question_model  = $question_mapper->fetchById( $question_pro_id, null );
		if ( ! ( $question_model instanceof WpProQuiz_Model_Question ) ) {
			return; // ProQuiz question not found.
		}
		$quiz_mapper = new WpProQuiz_Model_QuizMapper();
		$quiz_model  = $quiz_mapper->fetch( (int) $question_model->getQuizId() );
		if ( ! ( $quiz_model instanceof WpProQuiz_Model_Quiz ) ) {
			return; // ProQuiz quiz not found.
		}

		// Route to appropriate handler based on submission status following Writing pattern.
		switch ( $status ) {
			case 'completed':
				// Update the LD essay content and initialize a quiz attempt when completed.
				$this->handle_completed_speaking_part_submission( $updated_submission, $submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, $status );
				break;

			case 'not_graded':
			case 'graded':
				// Update LD essay grade/status on grading events.
				$this->handle_graded_speaking_part_submission( $updated_submission, $submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, $status );
				break;

			default:
				// Status not handled, do nothing.
				break;
		}
	}

	/**
	 * Handle graded speaking part submission by updating the LD essay grade and status.
	 *
	 * Attempts to update via LearnDash REST controller when available; falls back to wp_update_post
	 * for status-only updates. Points are optional; if a percentage score exists in submission meta,
	 * it will be passed as points_awarded.
	 *
	 * @param array  $updated_submission The updated part submission data.
	 * @param int    $submission_id      The part submission ID.
	 * @param int    $user_id            The user ID.
	 * @param int    $course_id          The course ID.
	 * @param int    $quiz_post_id       The quiz post ID.
	 * @param object $question_model     The ProQuiz question model.
	 * @param object $quiz_model         The ProQuiz quiz model.
	 * @param string $submission_status  The submission status ('graded' or 'not_graded').
	 *
	 * @return void
	 */
	private function handle_graded_speaking_part_submission( $updated_submission, $submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, $submission_status ) {
		// Determine the submission id used to link the LD essay (handle forks like Writing).
		$lookup_submission_id = $submission_id;
		if ( isset( $updated_submission['meta']['original_id'][0] ) && ! empty( $updated_submission['meta']['original_id'][0] ) ) {
			$lookup_submission_id = (int) $updated_submission['meta']['original_id'][0];
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
						'value' => $lookup_submission_id,
					),
					array(
						'key'   => '_ielts_question_type',
						'value' => 'speaking-part',
					),
				),
			)
		);

		if ( is_array( $essay_ids ) && ! empty( $essay_ids ) ) {
			$essay_post_id = (int) ( is_object( $essay_ids[0] ) ? $essay_ids[0]->ID : $essay_ids[0] );
		}
		if ( $essay_post_id <= 0 ) {
			return; // No linked LD essay found.
		}

		// Try to compute percent score like Writing using Speaking score class.
		$percent_score = null;
		try {
			$speech_id = isset( $updated_submission['speech_id'] ) ? (int) $updated_submission['speech_id'] : 0;
			if ( $speech_id > 0 ) {
				$speech_db = new \IeltsScienceLMS\Speaking\Ieltssci_Speech_DB();

				// Determine query key based on submission status (like Writing uses 'original_id' vs 'id').
				$key = ( 'graded' === $submission_status ) ? 'original_speech' : 'id';

				// For 'original_speech' meta query, we need to find the speech that has this meta value.
				if ( 'original_speech' === $key ) {
					$speeches = $speech_db->get_speeches(
						array(
							'meta_query' => array(
								array(
									'key'   => 'original_speech',
									'value' => $speech_id,
								),
							),
							'per_page'   => 1,
						)
					);
				} else {
					$speeches = $speech_db->get_speeches(
						array(
							'id'       => $speech_id,
							'per_page' => 1,
						)
					);
				}

				$speech = null;
				if ( is_array( $speeches ) && ! empty( $speeches ) ) {
					$speech = isset( $speeches['id'] ) ? $speeches : reset( $speeches );
				}
				if ( is_array( $speech ) && ! empty( $speech ) ) {
					$speaking_score = new \IeltsScienceLMS\Speaking\Ieltssci_Speaking_Score();
					$score_data     = $speaking_score->get_overall_score( $speech, 'final' );
					if ( $score_data && isset( $score_data['score'] ) ) {
						$overall_score = (float) $score_data['score'];
						$percent_score = max( 0.0, min( 100.0, round( ( $overall_score / 9.0 ) * 100.0, 2 ) ) );
					}
				}
			}
		} catch ( \Throwable $e ) {
			// Ignore score calculation failure; we'll still update status.
			error_log( 'IELTS Science LMS: Failed to calculate speaking score for LD essay update. ' . $e->getMessage() );
		}

		// Try to update via LearnDash REST if available for parity with Writing.
		if ( class_exists( '\\LD_REST_Essays_Controller_V2' ) ) {
			try {
				$controller = new LD_REST_Essays_Controller_V2();
				$request    = new WP_REST_Request( WP_REST_Server::EDITABLE, '/ldlms/v2/essays/' . $essay_post_id );
				$request->set_param( 'id', $essay_post_id );
				$request->set_param( 'status', $submission_status );

				if ( null !== $percent_score ) {
					$request->set_param( 'points_awarded', $percent_score );
				}

				$response = $controller->update_item( $request );
				if ( is_wp_error( $response ) ) {
					// Fallback to direct post status update on failure.
					wp_update_post(
						array(
							'ID'          => $essay_post_id,
							'post_status' => $submission_status,
						)
					);
				}
			} catch ( \Throwable $e ) {
				// Fallback to direct post status update if REST controller not usable.
				wp_update_post(
					array(
						'ID'          => $essay_post_id,
						'post_status' => $submission_status,
					)
				);
			}
		} else {
			// Fallback: update post status only.
			wp_update_post(
				array(
					'ID'          => $essay_post_id,
					'post_status' => $submission_status,
				)
			);
		}
	}

	/**
	 * Handle completed speaking part submission by updating the LD essay content and initializing a quiz attempt.
	 *
	 * @param array  $updated_submission The updated part submission data.
	 * @param int    $submission_id      The part submission ID.
	 * @param int    $user_id            The user ID.
	 * @param int    $course_id          The course ID.
	 * @param int    $quiz_post_id       The quiz post ID.
	 * @param object $question_model     The ProQuiz question model.
	 * @param object $quiz_model         The ProQuiz quiz model.
	 * @param string $submission_status  The submission status.
	 *
	 * @return void
	 */
	private function handle_completed_speaking_part_submission( $updated_submission, $submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, $submission_status ) {
		// Prepare essay creation time and elapsed time if provided.
		$essay_created_at_unix = 0; // Start timestamp for attempt initialization.
		if ( isset( $updated_submission['started_at'] ) && ! empty( $updated_submission['started_at'] ) ) {
			$ts = strtotime( $updated_submission['started_at'] );
			if ( $ts && $ts > 0 ) {
				$essay_created_at_unix = $ts;
			}
		}

		$quiz_time = 0; // Elapsed time in seconds.
		if ( isset( $updated_submission['meta']['elapsed_time'][0] ) ) {
			$et_raw = $updated_submission['meta']['elapsed_time'][0];
			if ( is_array( $et_raw ) ) {
				$et_raw = reset( $et_raw );
			}
			$quiz_time = max( 0, (int) $et_raw * 60 );
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
						'value' => 'speaking-part',
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

		// Build a simple essay content with a link to the speaking result page.
		$result_link = Ieltssci_Speaking_Part_Submission_Utils::get_part_submission_result_permalink( $submission_id, ( 'graded' !== $submission_status ) );
		if ( ! empty( $result_link ) ) {
			$response_text = sprintf(
				// translators: %s is a URL to the speaking result page.
				__( 'View your speaking feedback and result here: %s', 'ielts-science-lms' ),
				esc_url( $result_link )
			);

			wp_update_post(
				array(
					'ID'           => $essay_post_id,
					'post_content' => $response_text,
				)
			);
		}

		// Initialize a LearnDash quiz attempt so _sfwd-quizzes and activity entries are initialized.
		$this->create_ld_quiz_attempt_from_essay( $user_id, $quiz_post_id, $quiz_model, $question_model, $essay_post_id, $course_id, $essay_created_at_unix, $quiz_time );
	}

	/**
	 * Handle new submission by creating a new LearnDash essay post (sfwd-essays).
	 *
	 * @param int                       $submission_id  The submission ID.
	 * @param int                       $user_id        The user ID.
	 * @param int                       $course_id      The course ID.
	 * @param int                       $quiz_post_id   The quiz post ID.
	 * @param \WpProQuiz_Model_Question $question_model The ProQuiz question model.
	 * @param \WpProQuiz_Model_Quiz     $quiz_model     The ProQuiz quiz model.
	 * @param string                    $question_type  The type of question ('speaking-part').
	 *
	 * @return void
	 */
	private function handle_create_submission( $submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, $question_type = 'speaking-part' ) {
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

		if ( ! ( $question_model instanceof WpProQuiz_Model_Question ) || ! ( $quiz_model instanceof WpProQuiz_Model_Quiz ) ) {
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

			// Add meta to differentiate question type (speaking-part).
			add_post_meta( $essay_id, '_ielts_question_type', $question_type, true );
		}
	}

	/**
	 * Directly create a LearnDash quiz attempt for an externally created essay without mimicking the AJAX controller.
	 *
	 * @param int                       $user_id        User ID owning the attempt.
	 * @param int                       $quiz_post_id   WP post ID of the quiz.
	 * @param \WpProQuiz_Model_Quiz     $quiz_model     ProQuiz quiz model instance.
	 * @param \WpProQuiz_Model_Question $question_model ProQuiz essay question model instance.
	 * @param int                       $essay_post_id  Newly created LD essay post ID.
	 * @param int                       $course_id      Related course ID (0 if standalone).
	 * @param int                       $start_unix     Optional original start timestamp (seconds).
	 * @param int                       $quiz_time      Elapsed quiz time in seconds.
	 * @return void
	 */
	private function create_ld_quiz_attempt_from_essay( $user_id, $quiz_post_id, $quiz_model, $question_model, $essay_post_id, $course_id, $start_unix = 0, $quiz_time = 0 ) {
		if ( ! ( $quiz_model instanceof WpProQuiz_Model_Quiz ) ) {
			return; // Invalid quiz model.
		}
		if ( ! ( $question_model instanceof WpProQuiz_Model_Question ) ) {
			return; // Invalid question model.
		}
		if ( $user_id <= 0 ) {
			return; // Must have user.
		}

		// Prevent duplicate creation for same essay/quiz in user meta.
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

		// Timestamps.
		$now = time();
		if ( $start_unix > 0 ) {
			$started   = $start_unix; // Use provided start time.
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
			'timespent'           => max( 0, (int) $quiz_time ),
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
		// Populate questions array with the full quiz question set like core LD (fallback to single essay question).
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

		// Fire hooks for LD integrations mirroring Writing sync.
		/**
		 * Fires after the quiz is submitted.
		 *
		 * @param array   $quiz_data    An array of quiz data.
		 * @param WP_User $current_user Current user object.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		do_action( 'learndash_quiz_submitted', $quizdata, get_user_by( 'id', $user_id ) );

		// Mirror core parent step completion logic prior to final learndash_quiz_completed action.
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
	 * Filter essay URL to redirect to speaking submission result permalink when applicable.
	 *
	 * @param string $essay_url     The original essay URL.
	 * @param int    $essay_post_id The essay post ID.
	 * @return string The filtered essay URL.
	 */
	public function filter_essay_url( $essay_url, $essay_post_id ) {
		$essay_post_id = absint( $essay_post_id );
		if ( $essay_post_id <= 0 ) {
			return $essay_url; // Invalid ID.
		}

		$submission_id = get_post_meta( $essay_post_id, '_ielts_submission_id', true );
		if ( empty( $submission_id ) ) {
			return $essay_url; // No linked submission.
		}

		$question_type = get_post_meta( $essay_post_id, '_ielts_question_type', true );
		if ( 'speaking-part' !== $question_type ) {
			return $essay_url; // Not a speaking part essay.
		}

		$essay_status = get_post_status( $essay_post_id );
		$use_original = ( 'graded' === $essay_status ) ? false : true;

		$submission_url = Ieltssci_Speaking_Part_Submission_Utils::get_part_submission_result_permalink( (int) $submission_id, $use_original );
		if ( empty( $submission_url ) ) {
			return $essay_url; // Fallback to original URL.
		}

		return $submission_url;
	}

	/**
	 * Add IELTS data to essay REST API response, including result link for speaking submissions.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WP_Post          $post     The post object.
	 * @param WP_REST_Request  $request  The request object.
	 * @return WP_REST_Response The modified response.
	 */
	public function add_ieltssci_data_to_essay_response( $response, $post, $request ) {
		$meta                   = get_post_meta( $post->ID );
		$response->data['meta'] = $meta;

		$submission_id = isset( $meta['_ielts_submission_id'] ) ? $meta['_ielts_submission_id'][0] : '';
		$question_type = isset( $meta['_ielts_question_type'] ) ? $meta['_ielts_question_type'][0] : '';

		if ( ! empty( $submission_id ) && 'speaking-part' === $question_type ) {
			$essay_status   = get_post_status( $post->ID );
			$use_original   = ( 'graded' === $essay_status ) ? false : true;
			$submission_url = Ieltssci_Speaking_Part_Submission_Utils::get_part_submission_result_permalink( (int) $submission_id, $use_original );
			if ( ! empty( $submission_url ) ) {
				$response->data['result_link'] = $submission_url;
			}
		}

		return $response;
	}
}
