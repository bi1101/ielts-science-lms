<?php
/**
 * Auto-create course when LearnDash group is created.
 *
 * This class automatically creates a course with the same name as a group
 * when a new LearnDash group is created and associates them together.
 *
 * @package IELTS_Science_LMS
 * @subpackage Classroom
 */

namespace IeltsScienceLMS\Classroom;

use WP_Error;
use WP_Post;

/**
 * Class for auto-creating courses when groups are created.
 */
class Ieltssci_Group_Course_Auto_Creator {

	/**
	 * Initialize the auto-creator.
	 */
	public function __construct() {
		// Hook into WordPress save_post action for groups.
		add_action( 'save_post', array( $this, 'auto_create_course_for_group' ), 10, 3 );

		// Hook for admin notices.
		add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );
	}

	/**
	 * Auto-create course when a new group is created.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 */
	public function auto_create_course_for_group( $post_id, $post, $update ) {
		// Only process groups post type.
		if ( 'groups' !== $post->post_type ) {
			return;
		}

		// Only process new posts, not updates.
		if ( $update ) {
			return;
		}

		// Only process published posts.
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Check if LearnDash is active.
		if ( ! $this->is_learndash_active() ) {
			$this->add_admin_notice( 'error', 'LearnDash is not active. Cannot create course for group.' );
			return;
		}

		// Create the course.
		$course_id = $this->create_course_for_group( $post_id, $post->post_title, $post->post_content );

		if ( is_wp_error( $course_id ) ) {
			$this->add_admin_notice( 'error', 'Failed to create course for group: ' . $course_id->get_error_message() );
			return;
		}

		// Associate course with group.
		$association_result = $this->associate_course_with_group( $course_id, $post_id );

		if ( is_wp_error( $association_result ) ) {
			$this->add_admin_notice( 'error', 'Course created but failed to associate with group: ' . $association_result->get_error_message() );
			return;
		}

		// Success notice.
		$course_edit_link = admin_url( 'post.php?post=' . $course_id . '&action=edit' );
		$this->add_admin_notice(
			'success',
			sprintf(
				'Course "%s" was automatically created and associated with this group. <a href="%s">Edit Course</a>',
				get_the_title( $course_id ),
				$course_edit_link
			)
		);
	}

	/**
	 * Create a new course for the group using LearnDash REST API.
	 *
	 * @param int    $group_id      Group ID.
	 * @param string $group_title   Group title.
	 * @param string $group_content Group content/description.
	 * @return int|WP_Error Course ID on success, WP_Error on failure.
	 */
	private function create_course_for_group( $group_id, $group_title, $group_content ) {
		// Prepare course data for REST API.
		$course_data = array(
			'title'      => sanitize_text_field( $group_title ),
			'content'    => wp_kses_post( $group_content ),
			'status'     => 'publish',
			'author'     => get_current_user_id(),
			'price_type' => 'closed',
		);

		// Create the course via REST API.
		$course_id = $this->create_course_via_rest_api( $course_data );

		if ( is_wp_error( $course_id ) ) {
			return $course_id;
		}

		/**
		 * Fires after a course is auto-created for a group.
		 *
		 * @param int $course_id Course ID that was created.
		 * @param int $group_id  Group ID that triggered the creation.
		 */
		do_action( 'ieltssci_course_auto_created_for_group', $course_id, $group_id );

		return $course_id;
	}

	/**
	 * Create course using LearnDash REST API internally.
	 *
	 * @param array $course_data Course data array.
	 * @return int|WP_Error Course ID on success, WP_Error on failure.
	 */
	private function create_course_via_rest_api( $course_data ) {
		// Create a REST request internally.
		$request = new \WP_REST_Request( 'POST', '/ldlms/v2/sfwd-courses' );

		// Set the course data.
		foreach ( $course_data as $key => $value ) {
			$request->set_param( $key, $value );
		}

		// Get the REST server.
		$server = rest_get_server();

		// Dispatch the request.
		$response = $server->dispatch( $request );

		// Check for HTTP errors.
		if ( $response->get_status() >= 400 ) {
			$response_data = $response->get_data();
			$error_message = isset( $response_data['message'] ) ? $response_data['message'] : 'Unknown error occurred';

			return new WP_Error(
				'course_creation_failed',
				'Failed to create course via REST API: ' . $error_message,
				array( 'status' => $response->get_status() )
			);
		}

		$course_data_response = $response->get_data();

		if ( ! isset( $course_data_response['id'] ) ) {
			return new WP_Error( 'invalid_response', 'Invalid response from REST API.' );
		}

		return (int) $course_data_response['id'];
	}

	/**
	 * Associate the course with the group using LearnDash functions.
	 *
	 * @param int $course_id Course ID.
	 * @param int $group_id  Group ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function associate_course_with_group( $course_id, $group_id ) {
		// Validate inputs.
		if ( empty( $course_id ) || empty( $group_id ) ) {
			return new WP_Error( 'invalid_ids', 'Invalid course or group ID.' );
		}

		// Use LearnDash's function to associate course with group.
		// The third parameter 'false' means we're adding (not removing) the association.
		$result = ld_update_course_group_access( $course_id, $group_id, false );

		if ( ! $result ) {
			return new WP_Error( 'association_failed', 'Failed to associate course with group.' );
		}

		return true;
	}

	/**
	 * Check if LearnDash is active and functions are available.
	 *
	 * @return bool True if LearnDash is active, false otherwise.
	 */
	private function is_learndash_active() {
		return function_exists( 'learndash_get_post_type_slug' ) &&
				function_exists( 'learndash_update_setting' ) &&
				function_exists( 'ld_update_course_group_access' );
	}

	/**
	 * Add admin notice to be displayed.
	 *
	 * @param string $type    Notice type (success, error, warning, info).
	 * @param string $message Notice message.
	 */
	private function add_admin_notice( $type, $message ) {
		// Get existing notices or initialize as empty array.
		$notices = get_transient( 'ieltssci_group_course_admin_notices' );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( 'ieltssci_group_course_admin_notices', $notices, 60 );
	}

	/**
	 * Display admin notices.
	 */
	public function display_admin_notices() {
		$notices = get_transient( 'ieltssci_group_course_admin_notices' );

		if ( empty( $notices ) ) {
			return;
		}

		foreach ( $notices as $notice ) {
			$class = 'notice notice-' . $notice['type'] . ' is-dismissible';
			printf(
				'<div class="%s"><p>%s</p></div>',
				esc_attr( $class ),
				wp_kses_post( $notice['message'] )
			);
		}

		// Clear notices after displaying them.
		delete_transient( 'ieltssci_group_course_admin_notices' );
	}
}
