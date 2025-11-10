<?php
/**
 * Core module for IELTS Science LMS plugin.
 *
 * @package IELTS_Science_LMS
 * @subpackage Core
 */

namespace IeltsScienceLMS\Core;

use WP_Post;

/**
 * Core module class handling basic plugin functionality.
 *
 * This class initializes core plugin components and handles basic plugin operations
 * such as activation, deactivation, and template management.
 */
class Ieltssci_Core_Module {
	/**
	 * Database schema instance.
	 *
	 * @var Ieltssci_Database_Schema
	 */
	private $db_schema;

	/**
	 * Initialize the core module.
	 */
	public function __construct() {
		// Instantiate DB Schema early.
		$this->db_schema = new Ieltssci_Database_Schema();

		new Ieltssci_Core_Ajax();
		new Ieltssci_ACF();
		new \IeltsScienceLMS\Settings\Ieltssci_Settings();
		new \IeltsScienceLMS\ApiFeeds\Ieltssci_ApiFeed_Module();
		new \IeltsScienceLMS\RateLimits\Ieltssci_RateLimit();
		new \IeltsScienceLMS\ApiKeys\Ieltssci_ApiKeys();
		new \IeltsScienceLMS\Writing\Ieltssci_Writing_Module();
		new \IeltsScienceLMS\Speaking\Ieltssci_Speaking_Module();
		new \IeltsScienceLMS\Dashboard\Ieltssci_Dashboard_Module();
		new \IeltsScienceLMS\Classroom\Ieltssci_LD_Integration();
		new \IeltsScienceLMS\Classroom\Ieltssci_BB_Integration();

		// Initialize Users Insights integration if plugin is active.
		add_action( 'plugins_loaded', array( $this, 'init_users_insights_module' ), 20 );

		// Hook for running DB updates.
		add_action( 'plugins_loaded', array( $this, 'run_database_updates' ), 5 ); // Run early.

		// Hook for displaying database update messages.
		add_action( 'admin_notices', array( $this, 'display_db_update_messages' ) );

		add_filter( 'theme_page_templates', array( $this, 'add_custom_page_template' ) );
		add_filter( 'template_include', array( $this, 'load_custom_page_template' ) );
		add_filter( 'display_post_states', array( $this, 'add_module_page_post_state' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_assets_for_react_template' ), 100 );

		// Add template overrides for writing tasks and tests.
		add_filter( 'single_template', array( $this, 'override_post_templates' ) );
		add_filter( 'archive_template', array( $this, 'override_archive_templates' ) );
		add_action( 'ieltssci_process_db_update', array( $this, 'process_db_update' ) );
		add_filter(
			'rest_endpoints',
			function ( $endpoints ) {
				if ( isset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] ) ) {
					foreach ( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] as &$endpoint ) {
						if ( isset( $endpoint['permission_callback'] ) ) {
							// Override permission callback to always return true.
							$endpoint['permission_callback'] = '__return_true';
						}
					}
				}
				return $endpoints;
			}
		);
		add_filter(
			'rest_user_query',
			function ( $args, $request ) {
				// Remove the has_published_posts restriction for list members to invite to groups.
				unset( $args['has_published_posts'] );
				// Allow searching by user_email for all REST API users.
				if ( ! empty( $args['search'] ) ) {
					// Always allow searching by user_email.
					$args['search_columns'][] = 'user_email';
					// Remove duplicates, just in case.
					$args['search_columns'] = array_unique( $args['search_columns'] );
				}
				return $args;
			},
			10,
			2
		);
		add_filter(
			'groups_valid_status',
			function ( $statuses ) {
				$statuses[] = 'archived'; // Add the "archived" status.
				return $statuses;
			}
		);
		add_filter(
			'bp_loggedin_user_id',
			function ( $id ) {
				// If BuddyBoss returns 0, try WordPress's get_current_user_id().
				if ( 0 === (int) $id ) {
					$wp_id = get_current_user_id();
					if ( $wp_id ) {
						return $wp_id;
					}
				}
				return $id;
			}
		);
	}

	/**
	 * Activate the core module.
	 * Runs on plugin activation. Ensures tables exist and sets initial DB version if needed.
	 */
	public function activate() {
		$this->check_wp_version();

		// Ensure base tables exist. create_tables uses "IF NOT EXISTS".
		$creation_result = $this->db_schema->create_tables();
		if ( is_wp_error( $creation_result ) ) {
			// Log error or display admin notice on next load.
			error_log( 'IELTS Science LMS Activation Error (create_tables): ' . $creation_result->get_error_message() );
			// Optionally add a transient to show an admin notice.
			return;
		}

		// Check if this is the very first activation (no version set).
		$current_version = get_option( 'ieltssci_db_version', false );
		if ( false === $current_version ) {
			// Set the initial version after successful table creation.
			update_option( 'ieltssci_db_version', $this->db_schema->get_db_version() );
		} else {
			// If version exists, run updates immediately on activation as well.
			// This handles cases where the plugin was deactivated, updated, then reactivated.
			$this->run_database_updates();
		}

		// Trigger other activation actions.
		do_action( 'ieltssci_activate' );
	}

	/**
	 * Run database schema updates if needed.
	 * Hooked into 'plugins_loaded'.
	 */
	public function run_database_updates() {
		if ( ! $this->db_schema->needs_upgrade() ) {
			return;
		}

		// Prefer immediate async dispatch if Action Scheduler is present.
		if ( function_exists( 'as_enqueue_async_action' ) && ! defined( 'WP_INSTALLING' ) ) {

			// Avoid duplicates (covers pending, in-progress, or failed).
			if ( ! as_has_scheduled_action( 'ieltssci_process_db_update' ) ) {
				as_enqueue_async_action( 'ieltssci_process_db_update' );
				// Show notice if in admin.
				if ( is_admin() && current_user_can( 'manage_options' ) ) {
					add_action( 'admin_notices', array( $this, 'display_update_scheduled_notice' ) );
				}
			}
			return;
		}

		// Fallback to delayed scheduling if async helper absent.
		if ( function_exists( 'as_schedule_single_action' ) && ! defined( 'WP_INSTALLING' ) ) {

			if ( ! as_has_scheduled_action( 'ieltssci_process_db_update' ) ) {
				as_schedule_single_action( time() + 30, 'ieltssci_process_db_update' );
				if ( is_admin() && current_user_can( 'manage_options' ) ) {
					add_action( 'admin_notices', array( $this, 'display_update_scheduled_notice' ) );
				}
			}

			// Safety fallback: if an older pending action exists too long, run inline.
			$cutoff        = time() - 300; // 5 minutes.
			$stale_actions = as_get_scheduled_actions(
				array(
					'hook'         => 'ieltssci_process_db_update',
					'status'       => 'pending',
					'date'         => gmdate( 'Y-m-d H:i:s', $cutoff ),
					'date_compare' => '<=',
				),
				'ids'
			);
			if ( ! empty( $stale_actions ) ) {
				$this->process_db_update(); // Run directly as a fallback.
			}

			return;
		}

		// Ultimate fallback: run inline if Action Scheduler unavailable.
		$this->process_db_update();
	}

	/**
	 * Process database updates - called directly or via action scheduler.
	 */
	public function process_db_update() {
		$update_result = $this->db_schema->run_updates();
		if ( is_wp_error( $update_result ) ) {
			// Log the error.
			error_log( 'IELTS Science LMS Update Error (run_updates): ' . $update_result->get_error_message() );

			// Store the error in an option for displaying to admin later.
			update_option( 'ieltssci_db_update_error', $update_result->get_error_message(), false );
		} else {
			// Store success message.
			update_option( 'ieltssci_db_update_success', true, false );
		}
	}

	/**
	 * Display notice that database updates are scheduled.
	 */
	public function display_update_scheduled_notice() {
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php esc_html_e( 'IELTS Science LMS is updating your database in the background. This may take a few moments.', 'ielts-science-lms' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Display database update messages on admin screens.
	 * Hooked into admin_notices.
	 */
	public function display_db_update_messages() {
		// Check for error message.
		$error = get_option( 'ieltssci_db_update_error' );
		if ( $error ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %s: Error message */
						esc_html__( 'IELTS Science LMS Error: Database update failed. %s', 'ielts-science-lms' ),
						esc_html( $error )
					);
					?>
				</p>
			</div>
			<?php
			// Clear the error after displaying it.
			delete_option( 'ieltssci_db_update_error' );
		}

		// Check for success message.
		$success = get_option( 'ieltssci_db_update_success' );
		if ( $success ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'IELTS Science LMS: Database updated successfully.', 'ielts-science-lms' ); ?></p>
			</div>
			<?php
			// Clear the success flag after displaying it.
			delete_option( 'ieltssci_db_update_success' );
		}
	}

	/**
	 * Vô hiệu hóa plugin.
	 * Chứa các hành động cần thực hiện khi plugin bị vô hiệu hóa.
	 *
	 * @return void
	 */
	public function deactivate() {
		// Actions to perform on plugin deactivation.
	}

	/**
	 * Kiểm tra phiên bản WordPress hiện tại so với phiên bản yêu cầu.
	 * Nếu phiên bản hiện tại thấp hơn phiên bản yêu cầu, plugin sẽ bị vô hiệu hóa
	 * và một thông báo quản trị sẽ được thêm vào.
	 *
	 * @global string $wp_version Phiên bản WordPress hiện tại.
	 */
	public function check_wp_version() {
		global $wp_version;
		$required_wp_version = '6.0';

		if ( version_compare( $wp_version, $required_wp_version, '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			add_action( 'admin_notices', array( $this, 'wp_version_notice' ) );
		}
	}

	/**
	 * Displays an admin notice if the WordPress version is below the required version.
	 *
	 * This function outputs an error message in the WordPress admin area
	 * indicating that the IELTS Science LMS plugin requires WordPress version 6.0 or higher.
	 * If the WordPress version is below 6.0, the plugin will be deactivated.
	 *
	 * @return void
	 */
	public function wp_version_notice() {
		echo '<div class="error"><p><strong>' . esc_html__( 'IELTS Science LMS', 'ielts-science-lms' ) . '</strong> ' . esc_html__( 'requires WordPress version 6.0 or higher. The plugin has been deactivated.', 'ielts-science-lms' ) . '</p></div>';
	}

	/**
	 * Add custom page template to the template list.
	 *
	 * @param array $templates List of page templates.
	 * @return array Modified list of page templates.
	 */
	public function add_custom_page_template( $templates ) {
		$templates['template-react-page.php'] = 'React Page Template';
		return $templates;
	}

	/**
	 * Load custom page template when needed.
	 *
	 * @param string $template Current template path.
	 * @return string Modified template path.
	 */
	public function load_custom_page_template( $template ) {
		if ( is_page_template( 'template-react-page.php' ) ) {
			$template = plugin_dir_path( __FILE__ ) . '../templates/template-react-page.php';
		}
		return $template;
	}

	/**
	 * Override writing and speaking post templates to use React template.
	 *
	 * @param string $template Current template path.
	 * @return string Modified template path for writing-task, writing-test, speaking-part, and speaking-test posts.
	 */
	public function override_post_templates( $template ) {
		global $post;

		// Check if we're viewing a writing-task, writing-test, speaking-part, or speaking-test post.
		if ( is_singular( array( 'writing-task', 'writing-test', 'speaking-part', 'speaking-test' ) ) ) {
			$react_template = plugin_dir_path( __FILE__ ) . '../templates/template-react-page.php';

			// Check if our React template file exists.
			if ( file_exists( $react_template ) ) {
				return $react_template;
			}
		}

		return $template;
	}

	/**
	 * Override writing and speaking archive templates to use React template.
	 *
	 * @param string $template Current template path.
	 * @return string Modified template path for writing-task, writing-test, speaking-part, and speaking-test archives.
	 */
	public function override_archive_templates( $template ) {
		// Check if we're viewing writing-task, writing-test, speaking-part, or speaking-test archive pages.
		if ( is_post_type_archive( array( 'writing-task', 'writing-test', 'speaking-part', 'speaking-test' ) ) ) {
			$react_template = plugin_dir_path( __FILE__ ) . '../templates/template-react-page.php';

			// Check if our React template file exists.
			if ( file_exists( $react_template ) ) {
				return $react_template;
			}
		}

		return $template;
	}

	/**
	 * Dequeue unnecessary assets for React template.
	 */
	public function dequeue_assets_for_react_template() {
		if ( is_page_template( 'template-react-page.php' ) ||
			is_singular( array( 'writing-task', 'writing-test', 'speaking-part', 'speaking-test' ) ) ||
			is_post_type_archive( array( 'writing-task', 'writing-test', 'speaking-part', 'speaking-test' ) ) ) {

			// Dequeue Stylesheets.
			wp_dequeue_style( 'bb_theme_block-buddypanel-style-css' );
			wp_dequeue_style( 'buddyboss-theme-buddypress' );
			wp_dequeue_style( 'buddyboss-theme-css' );
			wp_dequeue_style( 'buddyboss-theme-fonts' );
			wp_dequeue_style( 'buddyboss-theme-magnific-popup-css' );
			wp_dequeue_style( 'buddyboss-theme-select2-css' );
			wp_dequeue_style( 'buddyboss-theme-template' );
			wp_dequeue_style( 'buddyboss_legacy' );
			wp_dequeue_style( 'redux-extendify-styles' );

			// Dequeue JavaScripts.
			wp_dequeue_script( 'boss-fitvids-js' );
			wp_dequeue_script( 'boss-jssocials-js' );
			wp_dequeue_script( 'boss-menu-js' );
			wp_dequeue_script( 'boss-panelslider-js' );
			wp_dequeue_script( 'boss-slick-js' );
			wp_dequeue_script( 'boss-sticky-js' );
			wp_dequeue_script( 'boss-validate-js' );
			wp_dequeue_script( 'buddyboss-theme-main-js' );
			wp_dequeue_script( 'mousewheel-js' );
			wp_dequeue_script( 'progressbar-js' );
			wp_dequeue_script( 'select2-js' );
			wp_dequeue_script( 'buddyboss-theme-woocommerce-js' );
		}
	}

	/**
	 * Add custom post state for module pages.
	 *
	 * @param array   $post_states Array of post states.
	 * @param WP_Post $post        Current post object.
	 * @return array Modified post states array.
	 */
	public function add_module_page_post_state( $post_states, $post ) {
		// Get the saved page settings.
		$ielts_pages = get_option( 'ielts_science_lms_pages', array() );

		// Check if this post's ID is one of the assigned pages.
		if ( ! empty( $ielts_pages ) && in_array( $post->ID, $ielts_pages, true ) ) {
			// Get the module pages data.
			$module_pages_data = apply_filters( 'ieltssci_lms_module_pages_data', array() );

			// Find the page key and label for this post's ID.
			foreach ( $module_pages_data as $module_data ) {
				foreach ( $module_data['pages'] as $page_key => $page_label ) {
					if ( isset( $ielts_pages[ $page_key ] ) && $ielts_pages[ $page_key ] === $post->ID ) {
						// Add the page label as a custom post state.
						$post_states[] = esc_html( $page_label );
						break 2; // Exit both loops once the page is found.
					}
				}
			}
		}
		return $post_states;
	}

	/**
	 * Initialize Users Insights module.
	 *
	 * @return void
	 */
	public function init_users_insights_module() {
		// Check if Users Insights is active.
		if ( ! class_exists( 'USIN_Plugin_Module' ) ) {
			return;
		}

		// Require the module files.
		require_once plugin_dir_path( __DIR__ ) . 'UsersInsights/USIN_IeltsScience_Query.php';
		require_once plugin_dir_path( __DIR__ ) . 'UsersInsights/USIN_IeltsScience_User_Activity.php';

		// Require loader classes.
		require_once plugin_dir_path( __DIR__ ) . 'UsersInsights/reports/loaders/ielts-essay-submissions-loader.php';
		require_once plugin_dir_path( __DIR__ ) . 'UsersInsights/reports/loaders/ielts-new-writers-loader.php';
		require_once plugin_dir_path( __DIR__ ) . 'UsersInsights/reports/loaders/ielts-essay-types-loader.php';
		require_once plugin_dir_path( __DIR__ ) . 'UsersInsights/reports/loaders/ielts-essays-per-user-loader.php';
		require_once plugin_dir_path( __DIR__ ) . 'UsersInsights/reports/loaders/ielts-word-count-distribution-loader.php';
		require_once plugin_dir_path( __DIR__ ) . 'UsersInsights/reports/loaders/ielts-top-writers-loader.php';

		require_once plugin_dir_path( __DIR__ ) . 'UsersInsights/reports/USIN_IeltsScience_Reports.php';
		require_once plugin_dir_path( __DIR__ ) . 'UsersInsights/USIN_IeltsScience.php';

		// Instantiate the module.
		new \IeltsScienceLMS\UsersInsights\USIN_IeltsScience();
	}
}
