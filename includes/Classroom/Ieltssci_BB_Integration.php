<?php
/**
 * BuddyBoss Integration Module for IELTS Science LMS.
 *
 * This class handles integration with BuddyBoss platform, including extending
 * the REST API schema to include LearnDash course information in group data.
 *
 * @package IELTS_Science_LMS
 * @subpackage Classroom
 */

namespace IeltsScienceLMS\Classroom;

/**
 * Class for handling BuddyBoss integrations.
 */
class Ieltssci_BB_Integration {

	/**
	 * Initialize the BuddyBoss integration.
	 */
	public function __construct() {
		// Initialize BuddyBoss features if active.
		add_action( 'plugins_loaded', array( $this, 'init_buddyboss_features' ) );
	}

	/**
	 * Initialize BuddyBoss-specific features.
	 */
	public function init_buddyboss_features() {
		// Check if BuddyBoss is active.
		if ( ! $this->is_buddyboss_active() ) {
			add_action( 'admin_notices', array( $this, 'buddyboss_inactive_notice' ) );
			return;
		}

		// Hook into REST API schema and response filters.
		add_filter( 'bp_rest_groups_get_item_schema', array( $this, 'extend_group_schema' ) );
		add_filter( 'bp_rest_groups_prepare_value', array( $this, 'extend_group_response' ), 10, 3 );
	}

	/**
	 * Check if BuddyBoss is active.
	 *
	 * @return bool True if BuddyBoss is active, false otherwise.
	 */
	private function is_buddyboss_active() {
		return function_exists( 'bp_is_active' ) &&
				class_exists( 'BP_REST_Groups_Endpoint' ) &&
				function_exists( 'groups_get_group' );
	}

	/**
	 * Display notice when BuddyBoss is not active.
	 */
	public function buddyboss_inactive_notice() {
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				esc_html_e(
					'IELTS Science LMS: BuddyBoss integration features are disabled because BuddyBoss Platform is not active.',
					'ielts-science-lms'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Extend the BuddyBoss Groups REST API schema to include LearnDash course fields.
	 *
	 * @param array $schema The existing group schema.
	 * @return array Modified schema with LD course fields.
	 */
	public function extend_group_schema( $schema ) {
		// Add LearnDash course IDs field to the schema.
		$schema['properties']['ld_course_ids'] = array(
			'description' => __( 'Associated LearnDash course IDs for this group.', 'ielts-science-lms' ),
			'type'        => 'array',
			'context'     => array( 'view', 'edit' ),
			'readonly'    => true,
			'items'       => array(
				'type' => 'integer',
			),
		);

		return $schema;
	}

	/**
	 * Extend the BuddyBoss Groups REST API response to include LearnDash course data.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @param \WP_REST_Request  $request  The request object.
	 * @param \BP_Groups_Group  $group    The group object.
	 * @return \WP_REST_Response Modified response with LD course data.
	 */
	public function extend_group_response( $response, $request, $group ) {
		// Get the response data.
		$data = $response->get_data();

		// Fetch associated LearnDash course IDs for this group.
		$ld_course_ids = $this->get_ld_course_ids_for_group( $group->id );

		// Add course IDs to response.
		$data['ld_course_ids'] = $ld_course_ids;

		// Update the response data.
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Get LearnDash course IDs associated with a BuddyBoss group.
	 *
	 * @param int $group_id The BuddyBoss group ID.
	 * @return array Array of course IDs.
	 */
	private function get_ld_course_ids_for_group( $group_id ) {
		// Check if BuddyBoss LearnDash integration is available.
		if ( ! function_exists( 'bp_learndash_get_group_courses' ) ) {
			return array();
		}

		// Use BuddyBoss helper function to get courses for the group.
		$course_ids = bp_learndash_get_group_courses( $group_id );

		// Ensure we return an array of integers.
		return array_map( 'intval', (array) $course_ids );
	}
}
