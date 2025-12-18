<?php
/**
 * LearnDash Teacher Dashboard Controller for IELTS Science LMS.
 *
 * This class handles the teacher dashboard functionality for LearnDash integration.
 *
 * @package IELTS_Science_LMS
 * @subpackage Classroom\LDIntegration
 */

namespace IeltsScienceLMS\Classroom\LDIntegration;

use LD_REST_Users_Course_Progress_Controller_V2;
use LD_REST_Users_Quiz_Progress_Controller_V2;
use LDLMS_Factory_Post;
use WP_REST_Controller;

/**
 * Class for handling LearnDash Teacher Dashboard.
 */
class Ieltssci_LD_Teacher_Dashboard_Controller extends WP_REST_Controller {
	/**
	 * User course activity cache for building steps.
	 *
	 * @var array
	 */
	protected $user_course_activity = array();
	/**
	 * Cached course titles keyed by course ID.
	 *
	 * @var array<int,string>
	 */
	protected $course_titles = array();
	/**
	 * API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ieltssci/v1';

	/**
	 * API base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'ldlms/teacher-dashboard';

	/**
	 * Initialize the Teacher Dashboard.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes for Teacher Dashboard.
	 *
	 * Exposes route:
	 * - GET /ieltssci/v1/ldlms/teacher-dashboard/quiz-attempts.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/quiz-attempts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_quiz_attempts' ),
					'permission_callback' => array( $this, 'quiz_attempts_permissions_check' ),
					'args'                => array(
						'sources'            => array(
							'description' => __( 'Sources to include for teacher courses. Allowed: author, group.', 'ielts-science-lms' ),
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'author', 'group' ),
							),
							'default'     => array( 'author', 'group' ),
						),
						'enrollment_sources' => array(
							'description' => __( 'Sources to include for course enrollments. Allowed: direct, group.', 'ielts-science-lms' ),
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'direct', 'group' ),
							),
							'default'     => array( 'direct', 'group' ),
						),
						'only_quizzes'       => array(
							'description' => __( 'If true, return only quiz steps.', 'ielts-science-lms' ),
							'type'        => 'boolean',
							'default'     => true,
						),
						'user_id'            => array(
							'description'       => __( 'Optional user ID to filter quiz attempts for a specific user. If provided, the current user must match or have teacher permissions.', 'ielts-science-lms' ),
							'type'              => 'integer',
							'default'           => null,
							'sanitize_callback' => 'absint',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Returns the public JSON schema for the quiz attempts route.
	 *
	 * Describes the response shape: a top-level object with a "courses" array,
	 * each course containing "course_id" and a "users" array. Each user has
	 * "user_id", "user_name", and a "steps" array. Quiz steps include
	 * optional "quiz_attempts" with attempt details.
	 *
	 * @return array JSON schema.
	 */
	public function get_public_item_schema() {
		// Pull properties from LearnDash controllers to keep our schema aligned with LD responses.
		$step_properties = array();
		if ( class_exists( '\\LD_REST_Users_Course_Progress_Controller_V2' ) ) {
			$ld_steps_controller = new LD_REST_Users_Course_Progress_Controller_V2();
			$ld_step_schema      = $ld_steps_controller->get_public_item_step_schema();
			if ( is_array( $ld_step_schema ) && isset( $ld_step_schema['properties'] ) && is_array( $ld_step_schema['properties'] ) ) {
				$step_properties = $ld_step_schema['properties'];
			}
		}
		// Fallback minimal step properties if LD schema is not available.
		if ( empty( $step_properties ) ) {
			$step_properties = array(
				'step'           => array(
					'description' => __( 'Step post ID.', 'ielts-science-lms' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'post_type'      => array(
					'description' => __( 'Step post type.', 'ielts-science-lms' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'step_title'     => array(
					'description' => __( 'Step title.', 'ielts-science-lms' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'date_started'   => array(
					'description' => __( 'Date started.', 'ielts-science-lms' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'date_completed' => array(
					'description' => __( 'Date completed.', 'ielts-science-lms' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'readonly'    => true,
				),
			);
		}

		// Our payload uses 'step_status'. If LD exposes 'progress_status', include our alias as well.
		if ( ! isset( $step_properties['step_status'] ) ) {
			$step_properties['step_status'] = array(
				'description' => __( 'Computed step status.', 'ielts-science-lms' ),
				'type'        => 'string',
				'enum'        => array( 'completed', 'pending-review', 'failed', 'in-progress', 'not-started', '' ),
				'readonly'    => true,
			);
		}

		// Ensure 'step_title' exists when pulling from LD schema as well.
		if ( ! isset( $step_properties['step_title'] ) ) {
			$step_properties['step_title'] = array(
				'description' => __( 'Step title.', 'ielts-science-lms' ),
				'type'        => 'string',
				'readonly'    => true,
			);
		}

		$attempt_properties = array();
		if ( class_exists( '\\LD_REST_Users_Quiz_Progress_Controller_V2' ) ) {
			$ld_quiz_controller = new LD_REST_Users_Quiz_Progress_Controller_V2();
			$ld_quiz_schema     = $ld_quiz_controller->get_public_item_schema();
			if ( is_array( $ld_quiz_schema ) && isset( $ld_quiz_schema['properties'] ) && is_array( $ld_quiz_schema['properties'] ) ) {
				$attempt_properties = $ld_quiz_schema['properties'];
			}
		}
		// Fallback minimal attempt properties if LD schema is not available.
		if ( empty( $attempt_properties ) ) {
			$attempt_properties = array(
				'id'            => array( 'type' => 'string' ),
				'user'          => array( 'type' => 'integer' ),
				'quiz'          => array( 'type' => 'integer' ),
				'course'        => array( 'type' => 'integer' ),
				'percentage'    => array( 'type' => 'number' ),
				'timespent'     => array( 'type' => 'number' ),
				'has_graded'    => array( 'type' => 'boolean' ),
				'graded'        => array( 'type' => array( 'array', 'object' ) ),
				'points_scored' => array( 'type' => 'integer' ),
				'points_total'  => array( 'type' => 'integer' ),
				'statistic'     => array( 'type' => 'integer' ),
				'pass'          => array( 'type' => 'integer' ),
				'score'         => array( 'type' => 'integer' ),
				'lesson'        => array( 'type' => 'integer' ),
				'topic'         => array( 'type' => 'integer' ),
				'started'       => array(
					'type'   => array( 'string', 'null' ),
					'format' => 'date-time',
				),
				'completed'     => array(
					'type'   => array( 'string', 'null' ),
					'format' => 'date-time',
				),
			);
		}

		// Extend steps with our quiz_attempts using the LD attempt properties.
		$step_properties['quiz_attempts'] = array(
			'description' => __( 'List of attempts for quiz steps.', 'ielts-science-lms' ),
			'type'        => 'array',
			'items'       => array(
				'type'       => 'object',
				'properties' => $attempt_properties,
			),
			'nullable'    => true,
		);

		$user_properties = array(
			'user_id'   => array(
				'description' => __( 'User ID.', 'ielts-science-lms' ),
				'type'        => 'integer',
				'readonly'    => true,
			),
			'user_name' => array(
				'description' => __( 'User display name.', 'ielts-science-lms' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'steps'     => array(
				'description' => __( 'List of course steps for this user.', 'ielts-science-lms' ),
				'type'        => 'array',
				'items'       => array(
					'type'       => 'object',
					'properties' => $step_properties,
				),
			),
		);

		$course_properties = array(
			'course_id'    => array(
				'description' => __( 'Course ID.', 'ielts-science-lms' ),
				'type'        => 'integer',
				'readonly'    => true,
			),
			'course_title' => array(
				'description' => __( 'Course title.', 'ielts-science-lms' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'users'        => array(
				'description' => __( 'Users enrolled in this course with their steps.', 'ielts-science-lms' ),
				'type'        => 'array',
				'items'       => array(
					'type'       => 'object',
					'properties' => $user_properties,
				),
			),
		);

		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'ieltssci-teacher-dashboard-quiz-attempts',
			'type'       => 'object',
			'properties' => array(
				'courses' => array(
					'description' => __( 'Collection of courses with users and their steps.', 'ielts-science-lms' ),
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => $course_properties,
					),
				),
			),
		);

		return $schema;
	}

	/**
	 * Permission callback for quiz attempts endpoint.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return bool|\WP_Error True if allowed, otherwise error.
	 */
	public function quiz_attempts_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'ielts-science-lms' ), array( 'status' => 401 ) );
		}

		$current_user_id = get_current_user_id();
		$user_id         = $request->get_param( 'user_id' );

		if ( $user_id && $user_id == $current_user_id ) {
			// Allow user to view their own attempts.
			return true;
		}

		// Allow admins or group leaders.
		if ( function_exists( 'learndash_is_admin_user' ) && learndash_is_admin_user( $current_user_id ) ) {
			return true;
		}
		if ( function_exists( 'learndash_is_group_leader_user' ) && learndash_is_group_leader_user( $current_user_id ) ) {
			return true;
		}

		// Allow authors/instructors who have at least one course.
		$courses = $this->get_teacher_courses( $current_user_id, array( 'author', 'group' ) );
		if ( ! empty( $courses ) ) {
			return true;
		}

		return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to view quiz attempts.', 'ielts-science-lms' ), array( 'status' => 403 ) );
	}

	/**
	 * Aggregates quiz attempts-like data by courses and enrolled users using LearnDash steps API.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error REST response or error.
	 */
	public function get_quiz_attempts( $request ) {
		$current_user_id    = get_current_user_id();
		$teacher_sources    = $request->get_param( 'sources' );
		$enrollment_sources = $request->get_param( 'enrollment_sources' );
		$only_quizzes       = filter_var( $request->get_param( 'only_quizzes' ), FILTER_VALIDATE_BOOL );
		$user_id_param      = $request->get_param( 'user_id' );

		// Resolve defaults when args are not provided by client.
		$teacher_sources    = is_array( $teacher_sources ) ? $teacher_sources : array( 'author', 'group' );
		$enrollment_sources = is_array( $enrollment_sources ) ? $enrollment_sources : array( 'direct', 'group' );

		$results = array();
		// If user_id is provided, get their enrolled courses and return only that user.
		if ( $user_id_param ) {
			$user_id_param = absint( $user_id_param );
			$courses       = $this->get_courses_enrolled_by_user( $user_id_param, $enrollment_sources );
		} else {
			// Get all courses for the current teacher user.
			$courses = $this->get_teacher_courses( $current_user_id, $teacher_sources );
		}
		$courses = array_map( 'absint', (array) $courses );

		foreach ( $courses as $course_id ) {
			if ( empty( $course_id ) ) {
				continue; // Skip invalid IDs.
			}

			// if user_id is provided, only include them.
			if ( $user_id_param ) {
				$enrolled_user_ids = array( $user_id_param );
			} else {
				// Get all users enrolled in this course.
				$enrolled_user_ids = $this->get_users_enrolled_in_course( $course_id, $enrollment_sources );
			}
			$enrolled_user_ids = array_map( 'absint', (array) $enrolled_user_ids );
			$enrolled_user_ids = array_values( array_filter( array_unique( $enrolled_user_ids ) ) );

			if ( empty( $enrolled_user_ids ) ) {
				$results[] = array(
					'course_id'    => $course_id,
					'course_title' => $this->get_cached_course_title( $course_id ),
					'users'        => array(),
				);
				continue;
			}

			$users_payload = array();
			foreach ( $enrolled_user_ids as $user_id ) {
				$user = get_user_by( 'ID', $user_id );
				if ( ! $user ) {
					continue; // Skip non-existing users.
				}

				// Build activity and steps similar to LearnDash controller.
				$this->user_course_activity = $this->get_user_course_activity( $user_id, $course_id );
				$steps                      = $this->get_user_course_progress_steps( $user_id, $course_id );
				$steps                      = is_array( $steps ) ? $steps : array();
				// Optionally filter to only quizzes for both steps and activity.
				if ( $only_quizzes ) {
					$steps = array_values(
						array_filter(
							$steps,
							function ( $step ) {
								return isset( $step['post_type'] ) && 'sfwd-quiz' === $step['post_type'];
							}
						)
					);
				}

				$users_payload[] = array(
					'user_id'   => $user_id,
					'user_name' => $user->display_name,
					'steps'     => $steps,
				);
			}

			$results[] = array(
				'course_id'    => $course_id,
				'course_title' => $this->get_cached_course_title( $course_id ),
				'users'        => $users_payload,
			);
		}

		$response = rest_ensure_response(
			array(
				'courses' => $results,
			)
		);

		return $response;
	}

	/**
	 * Get the user course progress steps data.
	 *
	 * @since 3.3.0
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return array of steps data.
	 */
	public function get_user_course_progress_steps( $user_id = 0, $course_id = 0 ) {

		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ( empty( $user_id ) ) || ( empty( $course_id ) ) ) {
			return array();
		}

		$user_course_progress_steps = array();

		$ld_course_steps_object = LDLMS_Factory_Post::course_steps( $course_id );
		$ld_course_steps_object->load_steps();
		$course_steps_l = $ld_course_steps_object->get_steps( 'l' );
		if ( ! empty( $course_steps_l ) ) {
			foreach ( $course_steps_l as $step_key ) {
				list( $step_type, $step_id ) = explode( ':', $step_key );

				$step_item = array();
				if ( ( isset( $this->user_course_activity[ $step_id ] ) ) && ( $step_type === $this->user_course_activity[ $step_id ]['post_type'] ) ) {
					$step_item = $this->user_course_activity[ $step_id ];
				} else {
					$step_item = array();
				}

				if ( ! isset( $step_item['step'] ) ) {
					$step_item['step'] = absint( $step_id );
				}

				if ( ! isset( $step_item['post_type'] ) ) {
					$step_item['post_type'] = esc_attr( $step_type );
				}

				if ( ! isset( $step_item['step_title'] ) ) {
					$step_item['step_title'] = wp_strip_all_tags( html_entity_decode( get_the_title( $step_id ), ENT_QUOTES, 'UTF-8' ) );
				}

				if ( ! isset( $step_item['step_status'] ) ) {
					$step_item['step_status'] = '';
				}

				if ( ! isset( $step_item['date_started'] ) ) {
					$step_item['date_started'] = '';
				}

				if ( ! isset( $step_item['date_completed'] ) ) {
					$step_item['date_completed'] = '';
				}

				$user_course_progress_steps[] = $step_item;
			}
		}

		return $user_course_progress_steps;
	}

	/**
	 * Get user course activity from DB.
	 *
	 * @since 3.3.0
	 *
	 * @param integer $user_id   User ID.
	 * @param integer $course_id Course ID.
	 *
	 * @return array of steps data.
	 */
	public function get_user_course_activity( $user_id = 0, $course_id = 0 ) {

		$user_course_activity = array();

		$priority_map = array(
			'completed'      => 5,
			'pending-review' => 4,
			'failed'         => 3,
			'in-progress'    => 2,
			'not-started'    => 1,
		);

		if ( ( empty( $user_id ) ) || ( empty( $course_id ) ) ) {
			return $user_course_activity;
		}

		$activity_query_args = array(
			'user_ids'   => array( absint( $user_id ) ),
			'course_ids' => array( absint( $course_id ) ),
			'per_page'   => 0,
		);

		$user_courses_reports = learndash_reports_get_activity( $activity_query_args );

		// Collect quiz attempts grouped by quiz post_id to attach after building status rows.
		$quiz_attempts_map = array(); // [quiz_id => [attempt, ...]].

		if ( ( isset( $user_courses_reports['results'] ) ) && ( ! empty( $user_courses_reports['results'] ) ) ) {
			foreach ( $user_courses_reports['results'] as $result ) {
				// Cache course title from reports when available to avoid extra queries.
				if (
					isset( $result->post_type, $result->post_id )
					&& 'sfwd-courses' === $result->post_type
					&& absint( $result->post_id ) === absint( $course_id )
					&& isset( $result->post_title )
				) {
					$this->course_titles[ absint( $course_id ) ] = wp_strip_all_tags( (string) $result->post_title );
				}

				$user_course_activity_row              = array();
				$user_course_activity_row['step']      = absint( $result->post_id );
				$user_course_activity_row['post_type'] = esc_attr( $result->post_type );
				// Use provided post_title from reports to avoid additional queries.
				$user_course_activity_row['step_title'] = isset( $result->post_title ) ? wp_strip_all_tags( (string) $result->post_title ) : '';

				if ( ! empty( $result->activity_started ) ) {
					$user_course_activity_row['date_started'] = gmdate( 'Y-m-d H:i:s', $result->activity_started );
				} else {
					$user_course_activity_row['date_started'] = '';
				}

				if ( ! empty( $result->activity_completed ) ) {
					$user_course_activity_row['date_completed'] = gmdate( 'Y-m-d H:i:s', $result->activity_completed );
				} else {
					$user_course_activity_row['date_completed'] = '';
				}

				if ( $result->activity_status ) {
					$has_graded = isset( $result->activity_meta['has_graded'] ) ? $result->activity_meta['has_graded'] : false;
					$score      = isset( $result->activity_meta['score'] ) ? (int) $result->activity_meta['score'] : 0;

					if ( $has_graded && 0 === $score ) {
						$user_course_activity_row['step_status'] = 'pending-review';
					} else {
						$user_course_activity_row['step_status'] = 'completed';
					}
				} else {
					$has_graded = isset( $result->activity_meta['has_graded'] ) ? $result->activity_meta['has_graded'] : false;
					$score      = isset( $result->activity_meta['score'] ) ? (int) $result->activity_meta['score'] : 0;
					$pass       = isset( $result->activity_meta['pass'] ) ? (int) $result->activity_meta['pass'] : 0;

					if ( $has_graded && 0 !== $score && 0 === $pass ) {
						$user_course_activity_row['step_status'] = 'failed';
					} elseif ( empty( $user_course_activity_row['date_started'] ) ) {
						$user_course_activity_row['step_status'] = 'not-started';
					} elseif ( empty( $user_course_activity_row['date_completed'] ) ) {
						$user_course_activity_row['step_status'] = 'in-progress';
					} else {
						$user_course_activity_row['step_status'] = 'completed';
					}
				}

				// If this activity row is for a quiz, build an attempt record from meta and timestamps.
				if ( 'sfwd-quiz' === $user_course_activity_row['post_type'] ) {
					$attempt = array(
						'id'            => isset( $result->activity_meta['quiz_key'] ) ? $result->activity_meta['quiz_key'] : '',
						'user'          => absint( $user_id ),
						'quiz'          => absint( $user_course_activity_row['step'] ),
						'course'        => absint( $course_id ),
						'percentage'    => isset( $result->activity_meta['percentage'] ) ? (float) $result->activity_meta['percentage'] : 0.0,
						'timespent'     => isset( $result->activity_meta['timespent'] ) ? (float) $result->activity_meta['timespent'] : 0.0,
						'has_graded'    => ! empty( $result->activity_meta['has_graded'] ),
						'graded'        => isset( $result->activity_meta['graded'] ) ? maybe_unserialize( $result->activity_meta['graded'] ) : array(),
						'points_scored' => isset( $result->activity_meta['points'] ) ? (int) $result->activity_meta['points'] : ( isset( $result->activity_meta['points_scored'] ) ? (int) $result->activity_meta['points_scored'] : 0 ),
						'points_total'  => isset( $result->activity_meta['total_points'] ) ? (int) $result->activity_meta['total_points'] : ( isset( $result->activity_meta['points_total'] ) ? (int) $result->activity_meta['points_total'] : 0 ),
						'statistic'     => isset( $result->activity_meta['statistic_ref_id'] ) ? (int) $result->activity_meta['statistic_ref_id'] : 0,
						'pass'          => isset( $result->activity_meta['pass'] ) ? (int) $result->activity_meta['pass'] : (int) ( ! empty( $result->activity_status ) ),
						'score'         => isset( $result->activity_meta['score'] ) ? (int) $result->activity_meta['score'] : 0,
						'lesson'        => isset( $result->activity_meta['lesson'] ) ? (int) $result->activity_meta['lesson'] : 0,
						'topic'         => isset( $result->activity_meta['topic'] ) ? (int) $result->activity_meta['topic'] : 0,
						'started'       => ! empty( $result->activity_started ) ? gmdate( 'Y-m-d H:i:s', $result->activity_started ) : '',
						'completed'     => ! empty( $result->activity_completed ) ? gmdate( 'Y-m-d H:i:s', $result->activity_completed ) : '',
					);

					$key = absint( $user_course_activity_row['step'] );
					if ( ! isset( $quiz_attempts_map[ $key ] ) ) {
						$quiz_attempts_map[ $key ] = array();
					}
					$quiz_attempts_map[ $key ][] = $attempt; // Accumulate attempts per quiz step.
				}

				$key              = absint( $result->post_id );
				$current_priority = isset( $user_course_activity[ $key ] ) ? ( $priority_map[ $user_course_activity[ $key ]['step_status'] ] ?? 0 ) : 0;
				$new_priority     = $priority_map[ $user_course_activity_row['step_status'] ] ?? 0;
				if ( $new_priority > $current_priority ) {
					$user_course_activity[ $key ] = $user_course_activity_row;
				}
			}

			// Attach aggregated quiz attempts to the corresponding quiz steps.
			if ( ! empty( $quiz_attempts_map ) ) {
				foreach ( $quiz_attempts_map as $quiz_id => $attempts ) {
					if ( ! isset( $user_course_activity[ $quiz_id ] ) ) {
						$user_course_activity[ $quiz_id ] = array(
							'step'           => absint( $quiz_id ),
							'post_type'      => 'sfwd-quiz',
							'step_title'     => wp_strip_all_tags( html_entity_decode( get_the_title( $quiz_id ), ENT_QUOTES, 'UTF-8' ) ),
							'step_status'    => '',
							'date_started'   => '',
							'date_completed' => '',
						);
					}
					$user_course_activity[ $quiz_id ]['quiz_attempts'] = array_values( $attempts ); // Ensure zero-based indexing.
				}
			}
		}

		return $user_course_activity;
	}


	/**
	 * Get all courses that a user is enrolled in based on sources.
	 *
	 * Mirrors the approach used in get_users_enrolled_in_course() but inverted to retrieve
	 * courses for a specific user. Supports two enrollment sources:
	 * - 'direct': Courses where the user has direct access via usermeta 'course_{ID}_access_from'
	 *   and courses whose access list includes the user (course meta setting 'course_access_list').
	 * - 'group': Courses that are assigned to any group the user belongs to via
	 *   postmeta 'learndash_group_enrolled_{group_id}'.
	 *
	 * @param int   $user_id User ID.
	 * @param array $sources Sources to include. Possible values: 'direct', 'group'.
	 * @return array<int> List of course IDs the user is enrolled in.
	 */
	public function get_courses_enrolled_by_user( $user_id, $sources = array( 'direct', 'group' ) ) {
		global $wpdb;

		$courses = array();
		$user_id = absint( $user_id );

		// Validate user ID.
		if ( empty( $user_id ) ) {
			return $courses;
		}

		// 1. Direct enrollments via usermeta 'course_{course_id}_access_from'.
		if ( in_array( 'direct', (array) $sources, true ) ) {
			$table = $wpdb->usermeta;
			// Find all meta_keys like 'course_%_access_from' for this user.
			$like     = $wpdb->esc_like( 'course_' ) . '%_access_from';
			$sql_like = $wpdb->prepare( "SELECT meta_key FROM $table WHERE user_id = %d AND meta_key LIKE %s", $user_id, $like );
			// Allow wildcard percent in LIKE to pass through untouched.
			$sql_like  = $wpdb->remove_placeholder_escape( $sql_like );
			$meta_keys = $wpdb->get_col( $sql_like, 0 );

			if ( ! empty( $meta_keys ) ) {
				foreach ( $meta_keys as $mk ) {
					// Expecting pattern 'course_{ID}_access_from'.
					$course_id = intval( filter_var( (string) $mk, FILTER_SANITIZE_NUMBER_INT ) );
					if ( $course_id > 0 ) {
						$courses[] = $course_id;
					}
				}
			}

			// Also include courses where the access list includes the user.
			$all_course_ids = get_posts(
				array(
					'post_type'   => 'sfwd-courses',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			);
			if ( ! empty( $all_course_ids ) ) {
				foreach ( $all_course_ids as $cid ) {
					$access_list = learndash_get_course_meta_setting( $cid, 'course_access_list' );
					if ( empty( $access_list ) || ! is_array( $access_list ) ) {
						continue; // No access list configured for this course.
					}
					$access_list = array_map( 'absint', $access_list );
					if ( in_array( $user_id, $access_list, true ) ) {
						$courses[] = absint( $cid );
					}
				}
			}
		}

		// 2. Group-based enrollments: user belongs to groups that are enrolled into courses.
		if ( in_array( 'group', (array) $sources, true ) ) {
			// Find group membership meta for this user: 'learndash_group_users_{group_id}'.
			$table     = $wpdb->usermeta;
			$like      = $wpdb->esc_like( 'learndash_group_users_' ) . '%';
			$sql_like  = $wpdb->prepare( "SELECT meta_key FROM $table WHERE user_id = %d AND meta_key LIKE %s", $user_id, $like );
			$sql_like  = $wpdb->remove_placeholder_escape( $sql_like );
			$group_mks = $wpdb->get_col( $sql_like, 0 );

			if ( ! empty( $group_mks ) ) {
				// For each group, find courses with postmeta 'learndash_group_enrolled_{group_id}'.
				$postmeta_table = $wpdb->postmeta;
				foreach ( $group_mks as $gmk ) {
					$group_id = intval( filter_var( (string) $gmk, FILTER_SANITIZE_NUMBER_INT ) );
					if ( $group_id <= 0 ) {
						continue; // Invalid group ID.
					}
					$meta_key             = 'learndash_group_enrolled_' . $group_id;
					$sql                  = $wpdb->prepare( "SELECT post_id FROM $postmeta_table WHERE meta_key = %s", $meta_key );
					$course_ids_for_group = $wpdb->get_col( $sql, 0 );
					if ( ! empty( $course_ids_for_group ) ) {
						$courses = array_merge( $courses, array_map( 'absint', $course_ids_for_group ) );
					}
				}
			}
		}

		$courses = array_values( array_unique( array_filter( array_map( 'absint', $courses ) ) ) );

		return apply_filters( 'ieltssci_filter_user_enrolled_courses', $courses, $user_id, $sources );
	}


	/**
	 * Get users who have enrolled in a course
	 *
	 * @param int   $course_id    ID of the course.
	 * @param array $sources    Sources to check for course enrollment. Possible values: 'direct', 'group'.
	 */
	public function get_users_enrolled_in_course( $course_id, $sources ) {
		global $wpdb;
		$users = array();

		// Check if empty course id.
		if ( empty( $course_id ) ) {
			return $users;
		}

		$course = get_post( $course_id );

		// Check for empty course post.
		if ( empty( $course ) ) {
			return $users;
		}

		// Check if course post type.
		if ( 'sfwd-courses' != $course->post_type ) {
			return $users;
		}

		// 1. Get Direct course access users.
		if ( in_array( 'direct', $sources ) ) {
			$table    = $wpdb->usermeta;
			$meta_key = 'course_' . $course_id . '_access_from';
			$sql      = $wpdb->prepare( "SELECT user_id FROM $table WHERE meta_key = %s", $meta_key );

			$result = $wpdb->get_col( $sql, 0 );

			if ( ! empty( $result ) ) {
				$users = array_merge( $users, $result );
			}
		}

		// 2. Access to course from groups
		if ( in_array( 'group', $sources ) ) {
			$table    = $wpdb->postmeta;
			$meta_key = 'learndash_group_enrolled_%';
			$sql      = $wpdb->remove_placeholder_escape(
				$wpdb->prepare(
					"SELECT meta_key FROM $table WHERE post_id = %d AND meta_key LIKE %s",
					$course_id,
					$meta_key
				)
			);

			$result = $wpdb->get_col( $sql, 0 );

			if ( ! empty( $result ) ) {
				$table = $wpdb->usermeta;

				foreach ( $result as $group ) {
					$group_id = intval( filter_var( $group, FILTER_SANITIZE_NUMBER_INT ) );
					if ( ! $group_id ) {
						continue;
					}
					$meta_key    = 'learndash_group_users_' . $group_id;
					$sql         = $wpdb->prepare( "SELECT user_id FROM $table WHERE meta_key = %s", $meta_key );
					$group_users = $wpdb->get_col( $sql, 0 );
					if ( empty( $group_users ) ) {
						continue;
					}
					$users = array_merge( $users, $group_users );
				}
			}
		}

		// 3. Course access list users
		if ( in_array( 'direct', $sources ) ) {
			$course_access_list = learndash_get_course_meta_setting( $course_id, 'course_access_list' );
			$users              = array_merge( $users, $course_access_list );
		}

		$users = array_unique( $users );

		return apply_filters( 'ieltssci_filter_course_enrolled_users', $users, $course_id, $sources );
	}

	/**
	 * Get all courses that a user is author of or admin of LD groups that have access to the courses.
	 *
	 * @param int   $user_id Optional. User ID. Defaults to current user.
	 * @param array $sources Optional. Sources to include. Possible values: 'author', 'group'. Defaults to both.
	 * @return array Array of course IDs.
	 */
	public function get_teacher_courses( $user_id = 0, $sources = array( 'author', 'group' ) ) {
		if ( empty( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$course_ids = array();

		// 1. Get courses where user is the author
		if ( in_array( 'author', $sources ) ) {
			$authored_courses = get_posts(
				array(
					'post_type'   => 'sfwd-courses',
					'author'      => $user_id,
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			);

			if ( ! empty( $authored_courses ) ) {
				$course_ids = array_merge( $course_ids, $authored_courses );
			}
		}

		// 2. Get courses from LD groups where user is admin
		if ( in_array( 'group', $sources ) ) {
			$group_course_ids = learndash_get_groups_courses_ids( $user_id );

			if ( ! empty( $group_course_ids ) ) {
				$course_ids = array_merge( $course_ids, $group_course_ids );
			}
		}

		// 3. Remove duplicates and return
		$course_ids = array_unique( $course_ids );

		return apply_filters( 'ieltssci_filter_teacher_courses', $course_ids, $user_id, $sources );
	}

	/**
	 * Get cached course title by course ID.
	 *
	 * Title is populated opportunistically from learndash_reports_get_activity() results.
	 * Avoids extra DB queries for performance. Falls back to post title if not cached.
	 *
	 * @param int $course_id Course ID.
	 * @return string Course title or empty string.
	 */
	protected function get_cached_course_title( $course_id ) {
		$course_id = absint( $course_id );
		if ( isset( $this->course_titles[ $course_id ] ) ) {
			return (string) $this->course_titles[ $course_id ];
		}
		// Fallback to post title if not cached.
		$title                             = html_entity_decode( get_the_title( $course_id ), ENT_QUOTES, 'UTF-8' );
		$this->course_titles[ $course_id ] = wp_strip_all_tags( $title );
		return $this->course_titles[ $course_id ];
	}
}
