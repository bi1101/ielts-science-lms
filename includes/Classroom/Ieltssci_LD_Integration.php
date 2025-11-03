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

use WP_REST_Request;
use WP_REST_Users_Controller;

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

		new LDIntegration\Ieltssci_LD_Question_Settings();

		new LDIntegration\Ieltssci_LD_Sync_Writing_Submissions();

		// Initialize Speaking submissions sync to LearnDash.
		new LDIntegration\Ieltssci_LD_Sync_Speaking_Submissions();

		// Initialize Teacher Dashboard integration.
		new LDIntegration\Ieltssci_LD_Teacher_Dashboard_Controller();

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
		$build_path  = plugin_dir_path( dirname( __DIR__, 1 ) ) . 'public/ld_integration/build/';
		$asset_files = glob( $build_path . '*.asset.php' );

		foreach ( $asset_files as $asset_file ) {
			$asset  = include $asset_file;
			$handle = 'ielts-science-ld-integration-' . basename( $asset_file, '.asset.php' );
			$src    = plugin_dir_url( dirname( __DIR__, 1 ) ) . 'public/ld_integration/build/' . basename( $asset_file, '.asset.php' ) . '.js';
			$deps   = $asset['dependencies'];
			$deps[] = 'wpProQuiz_front_javascript'; // Add ProQuiz front-end script as dependency.
			$ver    = $asset['version'];

			wp_register_script( $handle, $src, $deps, $ver, true );
			wp_set_script_translations( $handle, 'ielts-science-lms', dirname( plugin_dir_path( __FILE__ ), 2 ) . '/languages' );

			$css_file = str_replace( '.asset.php', '.css', $asset_file );
			if ( file_exists( $css_file ) ) {
				$css_handle = $handle . '-css';
				$css_src    = plugin_dir_url( dirname( __DIR__, 1 ) ) . 'public/ld_integration/build/' . basename( $css_file );
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

		// Also get speaking module pages data.
		$speaking_module   = new \IeltsScienceLMS\Speaking\Ieltssci_Speaking_Module();
		$module_pages_data = $speaking_module->provide_module_pages_data( $module_pages_data );

		// Also get dashboard module pages data.
		$dashboard_module  = new \IeltsScienceLMS\Dashboard\Ieltssci_Dashboard_Module();
		$module_pages_data = $dashboard_module->provide_module_pages_data( $module_pages_data );

		// Extract writing module pages.
		$writing_module_pages = array();
		if ( isset( $module_pages_data['writing_module']['pages'] ) ) {
			$writing_module_pages = $module_pages_data['writing_module']['pages'];
		}

		// Extract speaking module pages.
		$speaking_module_pages = array();
		if ( isset( $module_pages_data['speaking_module']['pages'] ) ) {
			$speaking_module_pages = $module_pages_data['speaking_module']['pages'];
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

			// Add speaking module pages to the localized data.
			foreach ( $speaking_module_pages as $page_key => $page_label ) {
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
}
