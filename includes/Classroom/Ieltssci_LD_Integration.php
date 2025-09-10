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

use WpProQuiz_Model_QuestionMapper;
use WpProQuiz_Model_QuizMapper;
use WP_REST_Request;
use WP_REST_Users_Controller;
use IeltsScienceLMS\Writing\Ieltssci_Writing_Score;
use LD_REST_Essays_Controller_V2;
use WP_REST_Server;
use WpProQuiz_Controller_Statistics;
use WP_User;

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

		// Initialize Quiz Settings integration.
		new LDIntegration\Ieltssci_LD_Quiz_Settings();

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

		// Hook external writing task submission updates to create LD essays.
		add_action( 'ieltssci_rest_update_task_submission', array( $this, 'on_rest_update_task_submission' ), 10, 3 );

		// Grant access if user is the post author.
		add_filter( 'sfwd_lms_has_access', array( $this, 'grant_access_if_post_owner' ), 10, 3 );

		// Register and enqueue LD integration assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_ld_integration_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_ld_integration_assets' ), 100 );
	}

	/**
	 * Register assets (scripts and styles) for the LD integration.
	 *
	 * This function locates and registers JavaScript and CSS files required for the LD integration.
	 * Asset files are expected to be in the 'ld_integration/build/' directory.
	 *
	 * @return void
	 */
	public function register_ld_integration_assets() {
		$build_path  = plugin_dir_path( __FILE__ ) . 'ld_integration/build/';
		$asset_files = glob( $build_path . '*.asset.php' );

		foreach ( $asset_files as $asset_file ) {
			$asset  = include $asset_file;
			$handle = 'ielts-science-ld-integration-' . basename( $asset_file, '.asset.php' );
			$src    = plugin_dir_url( __FILE__ ) . 'ld_integration/build/' . basename( $asset_file, '.asset.php' ) . '.js';
			$deps   = $asset['dependencies'];
			$deps[] = 'wpProQuiz_front_javascript'; // Add ProQuiz front-end script as dependency.
			$ver    = $asset['version'];

			wp_register_script( $handle, $src, $deps, $ver, true );
			wp_set_script_translations( $handle, 'ielts-science-lms', dirname( plugin_dir_path( __FILE__ ), 2 ) . '/languages' );

			$css_file = str_replace( '.asset.php', '.css', $asset_file );
			if ( file_exists( $css_file ) ) {
				$css_handle = $handle . '-css';
				$css_src    = plugin_dir_url( __FILE__ ) . 'ld_integration/build/' . basename( $css_file );
				wp_register_style( $css_handle, $css_src, array(), $ver );
			}
		}
	}

	/**
	 * Enqueue assets for the LD integration based on the current page.
	 *
	 * Loads necessary scripts and styles when the user is on a LearnDash quiz page.
	 * Provides localized data to the JavaScript including page routes, user info, and authentication.
	 *
	 * @return void
	 */
	public function enqueue_ld_integration_assets() {
		if ( ! function_exists( 'learndash_get_post_type_slug' ) ) {
			return; // LD not active.
		}

		// Get the saved page settings.
		$ielts_pages = get_option( 'ielts_science_lms_pages', array() );

		// Get module pages data from Writing module.
		$module_pages_data = array();
		$writing_module    = new \IeltsScienceLMS\Writing\Ieltssci_Writing_Module();
		$module_pages_data = $writing_module->provide_module_pages_data( $module_pages_data );

		// Also get dashboard module pages data.
		$dashboard_module  = new \IeltsScienceLMS\Dashboard\Ieltssci_Dashboard_Module();
		$module_pages_data = $dashboard_module->provide_module_pages_data( $module_pages_data );

		// Extract writing module pages.
		$writing_module_pages = array();
		if ( isset( $module_pages_data['writing_module']['pages'] ) ) {
			$writing_module_pages = $module_pages_data['writing_module']['pages'];
		}

		// Extract dashboard module pages.
		$dashboard_module_pages = array();
		if ( isset( $module_pages_data['dashboard_module']['pages'] ) ) {
			$dashboard_module_pages = $module_pages_data['dashboard_module']['pages'];
		}

		$should_enqueue = is_singular( learndash_get_post_type_slug( 'quiz' ) );

		if ( $should_enqueue ) {
			$script_handle  = 'ielts-science-ld-integration-index';
			$style_handle   = 'ielts-science-ld-integration-index-css';
			$runtime_handle = 'ielts-science-ld-integration-runtime';

			// Enqueue the runtime script if it's registered.
			if ( wp_script_is( $runtime_handle, 'registered' ) ) {
				wp_enqueue_script( $runtime_handle );
			}

			// Enqueue the script if it's registered.
			if ( wp_script_is( $script_handle, 'registered' ) ) {
				wp_enqueue_script( $script_handle );
			}

			// Enqueue the style if it's registered.
			if ( wp_style_is( $style_handle, 'registered' ) ) {
				wp_enqueue_style( $style_handle );
			}

			// Prepare data for localization using writing module pages.
			$page_data_for_js = array();
			foreach ( $writing_module_pages as $page_key => $page_label ) {
				if ( isset( $ielts_pages[ $page_key ] ) ) {
					$page_id = $ielts_pages[ $page_key ];
					// Check if this page is set as the front page - ensure consistent types for comparison.
					$front_page_id = get_option( 'page_on_front' );
					$is_front_page = ( (int) $page_id === (int) $front_page_id );
					// Use empty string for homepage URI to match root route.
					$uri                           = $is_front_page ? '' : get_page_uri( $page_id );
					$page_data_for_js[ $page_key ] = $uri;
				}
			}

			// Add dashboard module pages to the localized data.
			foreach ( $dashboard_module_pages as $page_key => $page_label ) {
				if ( isset( $ielts_pages[ $page_key ] ) ) {
					$page_id = $ielts_pages[ $page_key ];
					// Check if this page is set as the front page - ensure consistent types for comparison.
					$front_page_id = get_option( 'page_on_front' );
					$is_front_page = ( (int) $page_id === (int) $front_page_id );
					// Use empty string for homepage URI to match root route.
					$uri                           = $is_front_page ? '' : get_page_uri( $page_id );
					$page_data_for_js[ $page_key ] = $uri;
				}
			}

			// Create a nonce.
			$nonce = wp_create_nonce( 'wp_rest' );

			// Get the REST API root URL.
			$root_url = rest_url();

			// --- User Data ---
			$current_user = wp_get_current_user();

			// Prepare safe user data using WordPress REST API user preparation.
			$safe_user_data = null;

			if ( is_user_logged_in() ) {

				$users_controller = new WP_REST_Users_Controller();
				$request          = new WP_REST_Request();
				$request->set_param( 'context', 'edit' ); // Use 'edit' context for more comprehensive data.

				// Prepare user data using WordPress's own REST API methods.
				$user_data      = $users_controller->prepare_item_for_response( $current_user, $request );
				$safe_user_data = $user_data->get_data();
			}

			// Combine all data to be localized.
			$localized_data = array(
				'pages'        => $page_data_for_js,
				'nonce'        => $nonce,
				'root_url'     => $root_url,
				'is_logged_in' => is_user_logged_in(),
				'current_user' => $safe_user_data,
			);

			// Localize script (pass data to the React app).
			wp_localize_script( $script_handle, 'ielts_ld_integration_data', $localized_data );
		}
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
	 * Grant access to the post if the user is the owner (author) of the post.
	 *
	 * @param bool $has_access Whether the user has access.
	 * @param int  $post_id    The post ID.
	 * @param int  $user_id    The user ID.
	 *
	 * @return bool Modified access status.
	 */
	public function grant_access_if_post_owner( $has_access, $post_id, $user_id ) {
		// If access is already granted, no need to check further.
		if ( $has_access ) {
			return $has_access;
		}

		if ( empty( $user_id ) || $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		// Check if the user is the author of the post.
		$post_author = get_post_field( 'post_author', $post_id );
		if ( $post_author == $user_id ) {
			return true;
		}

		return $has_access;
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
		if ( ! $this->is_learndash_active() ) {
			return; // LearnDash not active; nothing to do.
		}

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
	 * Handle completed submission by creating a new essay.
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
	private function handle_completed_submission( $updated_submission, $submission_id, $user_id, $course_id, $quiz_post_id, $question_model, $quiz_model, $submission_status ) {
		// Initialize quiz time placeholder (seconds) so it's defined for all paths.
		$quiz_time = 0; // Will be overwritten if meta elapsed_time present.
		// Retrieve essay content from the IELTS Science essays table via DB API.
		$ext_essay_id = 0;
		if ( isset( $updated_submission['essay_id'] ) && (int) $updated_submission['essay_id'] > 0 ) {
			$ext_essay_id = (int) $updated_submission['essay_id'];
		} elseif ( isset( $updated_submission['meta']['essay_id'] ) && (int) $updated_submission['meta']['essay_id'] > 0 ) {
			$ext_essay_id = (int) $updated_submission['meta']['essay_id'];
		}

		if ( $ext_essay_id <= 0 ) {
			return; // No linked essay to pull content from.
		}

		$response_text         = '';
		$essay_created_at_unix = 0; // Will hold essay creation timestamp for quiz attempt start.
		try {
			$essay_db = new \IeltsScienceLMS\Writing\Ieltssci_Essay_DB();
			$essays   = $essay_db->get_essays(
				array(
					'id'       => $ext_essay_id,
					'per_page' => 1,
				)
			);
			if ( is_wp_error( $essays ) ) {
				return; // Unable to fetch essay content.
			}
			// get_essays may return a single row or a list; normalize to array of rows.
			if ( isset( $essays['id'] ) ) {
				$essay_row = $essays;
			} else {
				$essay_row = is_array( $essays ) && ! empty( $essays ) ? reset( $essays ) : array();
			}
			if ( is_array( $essay_row ) ) {
				$response_text = (string) ( $essay_row['essay_content'] ?? '' );
				if ( ! empty( $essay_row['created_at'] ) ) {
					// Attempt to parse created_at as site-local time, fallback to GMT parsing.
					$created_raw = $essay_row['created_at'];
					$ts          = strtotime( $created_raw );
					if ( $ts && $ts > 0 ) {
						$essay_created_at_unix = $ts;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return; // Safety: if essay DB fails, bail.
		}

		$response_text = trim( (string) $response_text );
		if ( '' === $response_text ) {
			return; // Nothing to submit.
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

		// Create the LD Essay post.
		$essay_id = \learndash_add_new_essay_response( $response_text, $question_model, $quiz_model, $post_data );

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

			// Derive quiz elapsed time (in seconds) from updated submission meta if available (fallback 0).
			if ( isset( $updated_submission['meta']['elapsed_time'][0] ) ) {
				$et_raw = $updated_submission['meta']['elapsed_time'][0];
				if ( is_array( $et_raw ) ) {
					$et_raw = reset( $et_raw );
				}
				$quiz_time = max( 0, (int) $et_raw );
			}

			// After creating the essay, initialize a LearnDash quiz attempt so _sfwd-quizzes and activity entries are initialized.
			$this->create_ld_quiz_attempt_from_essay( $user_id, $quiz_post_id, $quiz_model, $question_model, $essay_id, $course_id, $essay_created_at_unix, $quiz_time ); // Direct meta/action initialization.
		}
	}

	/**
	 * Directly create a LearnDash quiz attempt for an externally created essay without mimicking the AJAX controller.
	 *
	 * This mirrors the essential parts of LD_QuizPro->wp_pro_quiz_completed() needed for course progression:
	 * - Build $quizdata structure.
	 * - Append to user meta _sfwd-quizzes.
	 * - Fire learndash_quiz_submitted and learndash_quiz_completed actions.
	 * It intentionally skips WP Pro Quiz statistics tables to avoid redundant/echo-heavy controller flows.
	 * A filter 'ielts_ld_skip_direct_attempt' can short-circuit the process.
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

		// Optionally persist ProQuiz statistics (mirrors WpProQuiz_Controller_Quiz->completedQuiz early save path).
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
				'meta_key'    => '_ielts_submission_id',
				'post_status' => array( 'publish', 'not_graded', 'graded', 'draft' ),
				'meta_value'  => $submission_id,
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
