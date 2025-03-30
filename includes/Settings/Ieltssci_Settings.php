<?php
/**
 * IELTS Science LMS Settings
 *
 * Manages admin settings pages, UI components and settings registration.
 *
 * @package IELTS_Science_LMS
 * @subpackage Settings
 */

namespace IeltsScienceLMS\Settings;

use IeltsScienceLMS\ApiFeeds\Ieltssci_ApiFeeds_DB;
use WP_Error;

/**
 * Class Ieltssci_Settings
 *
 * Handles settings integration, UI rendering, and AJAX operations
 * for the IELTS Science LMS plugin administration area.
 *
 * @package IELTS_Science_LMS\Settings
 */
class Ieltssci_Settings {
	/**
	 * Settings configuration object.
	 *
	 * @var Ieltssci_Settings_Config
	 */
	private $settings_config;

	/**
	 * Constructor. Sets up action hooks.
	 *
	 * Initializes the settings configuration and registers all
	 * required WordPress hooks for the admin interface.
	 */
	public function __construct() {
		$this->settings_config = new Ieltssci_Settings_Config();
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_settings_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_ielts_create_page_ajax', array( $this, 'handle_create_page_ajax' ) );
	}

	/**
	 * Register admin menu and submenu pages.
	 *
	 * Creates the main admin menu for IELTS Science LMS and adds
	 * required submenu pages for settings and page management.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'IELTS Science LMS', 'ielts-science-lms' ),
			__( 'IELTS Science LMS', 'ielts-science-lms' ),
			'manage_options',
			'ielts-science-lms',
			array( $this, 'settings_page' ),
			'dashicons-welcome-learn-more',
			10
		);

		add_submenu_page(
			'ielts-science-lms',
			__( 'Pages', 'ielts-science-lms' ),
			__( 'Pages', 'ielts-science-lms' ),
			'manage_options',
			'ielts-science-lms-pages',
			array( $this, 'pages_settings_page' )
		);

		add_submenu_page(
			'ielts-science-lms',
			__( 'Settings', 'ielts-science-lms' ),
			__( 'Settings', 'ielts-science-lms' ),
			'manage_options',
			'ielts-science-lms-settings',
			array( $this, 'settings_page' )
		);
	}

	/**
	 * Enqueue common admin scripts and styles.
	 *
	 * Loads jQuery UI tabs, basic jQuery UI styles, and WordPress
	 * admin styles for consistent UI across settings pages.
	 */
	public function enqueue_admin_scripts() {
		wp_enqueue_script( 'jquery-ui-tabs' );
		wp_enqueue_style( 'jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css', array(), '1.12.1' );
		wp_enqueue_style( 'wp-admin' );
	}

	/**
	 * Enqueue assets specific to the settings pages.
	 *
	 * Loads JavaScript and CSS files needed for the current settings tab.
	 *
	 * @param string $admin_page The admin page hook name.
	 */
	public function enqueue_settings_assets( $admin_page ) {

		if ( 'ielts-science-lms_page_ielts-science-lms-settings' !== $admin_page ) {
			return;
		}

		// Get current tab.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'writing-apis';
		// Verify nonce for tab switching if it exists.
		if ( isset( $_GET['_wpnonce'] ) && ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ielts_tab_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ielts-science-lms' ) );
		}

		$tabs = $this->settings_config->get_settings_config(); // Get all tabs.
		foreach ( $tabs as $tab ) {
			if ( $tab['id'] === $current_tab ) {
				$current_tab_type = $tab['type'];
				break;
			}
		}

		// Get the appropriate script handle for this tab.
		$script_handle  = "ielts-science-wp-admin-{$current_tab_type}";
		$style_handle   = "{$script_handle}-css";
		$runtime_handle = 'ielts-science-wp-admin-runtime';

		// Enqueue the runtime script if it's registered.
		if ( wp_script_is( $runtime_handle, 'registered' ) ) {
			wp_enqueue_script( $runtime_handle );
		}

		// Enqueue the tab-specific script if it's registered.
		if ( wp_script_is( $script_handle, 'registered' ) ) {
			wp_enqueue_script( $script_handle );
		}

		// Enqueue the tab-specific style if it's registered.
		if ( wp_style_is( $style_handle, 'registered' ) ) {
			wp_enqueue_style( $style_handle );
		}

		wp_enqueue_style( 'wp-components' );

		match ( $current_tab_type ) {
			'api-feeds' => wp_localize_script(
				$script_handle,
				'ieltssciSettings', array(
					'apiRoot'        => esc_url_raw( rest_url() ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'settingsConfig' => $this->settings_config->get_settings_config( $current_tab ),
					'currentTab'     => $current_tab,
				)
			),
			'rate-limits' => wp_localize_script(
				$script_handle,
				'ieltssciRateLimits', array(
					'apiRoot' => esc_url_raw( rest_url() ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'roles'   => wp_roles()->roles,
					'feeds'   => array_reduce(
						( new Ieltssci_ApiFeeds_DB() )->get_api_feeds(
							array(
								'limit'   => 500,
								'include' => array( 'meta' ),
							)
						),
						function ( $result, $item ) {
								$id = $item['id'];
							if ( ! isset( $result[ $id ] ) ) {
								$result[ $id ] = $item;
							}
								return $result;
						},
						array()
					),
				)
			),
			'api-feeds-process-order' => wp_localize_script(
				$script_handle,
				'ieltssciProcessOrder', array(
					'apiRoot'        => esc_url_raw( rest_url() ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'settingsConfig' => $this->settings_config->get_settings_config( $current_tab ),
					'currentTab'     => $current_tab,
				)
			),
			default => wp_localize_script(
				$script_handle,
				'ieltssciSettings',
				array(
					'apiRoot'        => esc_url_raw( rest_url() ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'settingsConfig' => $this->settings_config->get_settings_config( $current_tab ),
					'currentTab'     => $current_tab,
				)
			),
		};
		// Localize the script with the REST API URL, nonce & settings config.
	}

	/**
	 * Render the main settings page.
	 *
	 * Outputs the HTML for the settings interface including tabs navigation
	 * and a container for React-based settings components.
	 */
	public function settings_page() {
		// Determine the current tab. Default to 'writing-apis' if none specified.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'writing-apis';
		// Verify nonce for tab switching if it exists.
		if ( isset( $_GET['_wpnonce'] ) && ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ielts_tab_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ielts-science-lms' ) );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'IELTS Science LMS Settings', 'ielts-science-lms' ); ?></h1>

			<?php $this->render_tabs( $current_tab ); ?>

			<?php
			// Check if current tab is a server-side rendered tab.
			if ( 'api-settings' === $current_tab ) {
				$this->render_api_settings_page();
			} elseif ( 'sample-results' === $current_tab ) {
				$this->render_sample_results_page();
			} else {
				?>
				<div id="ieltssci_settings_page"></div>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the Sample Results settings page.
	 */
	public function render_sample_results_page() {
		// Get existing sample results.
		$sample_results = get_option( 'ielts_science_sample_results', array() );

		// Apply filter to allow other modules to add their sample results sections.
		$sample_results_data = apply_filters( 'ieltssci_sample_results_data', array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sample Results Settings', 'ielts-science-lms' ); ?></h1>
			<p><?php esc_html_e( 'Configure sample result links that will be available in front-end applications.', 'ielts-science-lms' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'ielts_science_sample_results_group' ); ?>

				<?php
				// Loop Through Module Sample Results Data.
				foreach ( $sample_results_data as $module_data ) :
					?>
					<div class="card" style="max-width: none; margin-top: 20px;">
						<h2 class="title"><?php echo esc_html( $module_data['section_title'] ); ?></h2>
						<p><?php echo esc_html( $module_data['section_desc'] ); ?></p>
						<table class="form-table">
							<?php
							foreach ( $module_data['samples'] as $field_key => $field_data ) :
								$field_value = isset( $sample_results[ $field_key ] ) ? $sample_results[ $field_key ] : '';
								?>
								<tr>
									<th scope="row">
										<label for="ielts_science_sample_<?php echo esc_attr( $field_key ); ?>">
											<?php echo esc_html( $field_data['label'] ); ?>
										</label>
									</th>
									<td>
										<input type="url"
											name="ielts_science_sample_results[<?php echo esc_attr( $field_key ); ?>]"
											id="ielts_science_sample_<?php echo esc_attr( $field_key ); ?>"
											value="<?php echo esc_url( $field_value ); ?>"
											class="regular-text"
										/>
										<p class="description"><?php echo esc_html( $field_data['description'] ); ?></p>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					</div>
				<?php endforeach; ?>

				<?php if ( ! empty( $sample_results_data ) ) : ?>
					<?php submit_button(); ?>
				<?php else : ?>
					<div class="card" style="max-width: none; margin-top: 20px;">
						<p><?php esc_html_e( 'No sample results are currently configured. Modules can add their own sample result sections through the filter.', 'ielts-science-lms' ); ?></p>
					</div>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the API Settings page with server-side PHP
	 */
	public function render_api_settings_page() {
		// Get the current API settings.
		$api_settings = get_option(
			'ielts_science_api_settings',
			array(
				'max_concurrent_requests' => 5, // Default value.
			)
		);
		?>
		<div class="card" style="max-width: none; margin-top: 20px;">
			<h2><?php esc_html_e( 'API Settings', 'ielts-science-lms' ); ?></h2>
			<p><?php esc_html_e( 'Configure the API settings for IELTS Science LMS.', 'ielts-science-lms' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'ielts_science_api_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="ielts_science_api_max_concurrent"><?php esc_html_e( 'API Max Concurrent Requests', 'ielts-science-lms' ); ?></label>
						</th>
						<td>
							<input type="number"
								id="ielts_science_api_max_concurrent"
								name="ielts_science_api_settings[max_concurrent_requests]"
								value="<?php echo esc_attr( $api_settings['max_concurrent_requests'] ); ?>"
								min="1"
								max="20"
								step="1"
								class="regular-text"
							/>
							<p class="description"><?php esc_html_e( 'Maximum number of concurrent API requests allowed.', 'ielts-science-lms' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the tab navigation.
	 *
	 * @param string $current_tab The currently active tab.
	 */
	private function render_tabs( $current_tab ) {
		$tabs = $this->settings_config->get_settings_config();

		?>
		<nav class="nav-tab-wrapper">
			<?php
			foreach ( $tabs as $tab ) :
				$active_class = ( $current_tab === $tab['id'] ) ? 'nav-tab-active' : '';
				$url          = add_query_arg(
					array(
						'tab'      => $tab['id'],
						'_wpnonce' => wp_create_nonce( 'ielts_tab_nonce' ),
					),
					menu_page_url( 'ielts-science-lms-settings', false )
				); // Use menu_page_url.
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo esc_attr( $active_class ); ?>">
					<?php echo esc_html( $tab['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Register assets (scripts and styles) for the settings module.
	 *
	 * This function locates and registers JavaScript and CSS files required for the settings module.
	 * Asset files are expected to be in the 'admin/settings/build/' directory.
	 *
	 * @return void
	 */
	public function register_settings_assets() {
		$build_path  = plugin_dir_path( __FILE__ ) . '../../admin/settings/build/';
		$asset_files = glob( $build_path . '*.asset.php' );

		foreach ( $asset_files as $asset_file ) {
			$asset  = include $asset_file;
			$handle = 'ielts-science-wp-admin-' . basename( $asset_file, '.asset.php' );
			$src    = plugin_dir_url( __FILE__ ) . '../../admin/settings/build/' . basename( $asset_file, '.asset.php' ) . '.js';
			$deps   = $asset['dependencies'];
			$ver    = $asset['version'];

			wp_register_script( $handle, $src, $deps, $ver, true );

			$css_file = str_replace( '.asset.php', '.css', $asset_file );
			if ( file_exists( $css_file ) ) {
				$css_handle = $handle . '-css';
				$css_src    = plugin_dir_url( __FILE__ ) . '../../admin/settings/build/' . basename( $css_file );
				wp_register_style( $css_handle, $css_src, array(), $ver );
			}
		}
	}

	/**
	 * Renders the pages settings admin interface.
	 *
	 * Allows administrators to associate WordPress pages with various LMS functionality.
	 */
	public function pages_settings_page() {
		// Display notices if any.
		$this->display_notices();

		// Get current page assignments.
		$ielts_pages = get_option( 'ielts_science_lms_pages', array() );

		// Apply Filter for Module Page Settings.
		$module_pages_data = apply_filters( 'ieltssci_lms_module_pages_data', array() );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pages Settings', 'ielts-science-lms' ); ?></h1>
			<p><?php esc_html_e( 'Associate a WordPress page with each of the following pages.', 'ielts-science-lms' ); ?></p>

			<!-- Main Settings Form (for saving page selections) -->
			<form method="post" action="options.php">
				<?php settings_fields( 'ielts_science_lms_pages_group' ); ?>

				<?php
				// Loop Through Module Data.
				foreach ( $module_pages_data as $module_data ) :
					?>
					<div class="card" style="max-width: none;">
						<h2 class="title"><?php echo esc_html( $module_data['section_title'] ); ?></h2>
						<p><?php echo esc_html( $module_data['section_desc'] ); ?></p>
						<table class="form-table">
							<?php
							foreach ( $module_data['pages'] as $page_key => $page_label ) :
								$selected_page_id = isset( $ielts_pages[ $page_key ] ) ? $ielts_pages[ $page_key ] : 0;
								?>
								<tr>
									<th scope="row">
										<label
											for="ielts_science_lms_pages_<?php echo esc_attr( $page_key ); ?>"><?php echo esc_html( $page_label ); ?></label>
									</th>
									<td>
										<?php
										wp_dropdown_pages(
											array(
												'name'     => 'ielts_science_lms_pages[' . esc_attr( $page_key ) . ']',
												'id'       => 'ielts_science_lms_pages_' . esc_attr( $page_key ),
												'selected' => absint( $selected_page_id ),
												'show_option_none' => esc_html__( '- Select a page -', 'ielts-science-lms' ),
												'option_none_value' => '0',
												'echo'     => true,
											)
										);
										?>
										<?php if ( $selected_page_id && 'publish' === get_post_status( $selected_page_id ) ) : ?>
											<a href="<?php echo esc_url( get_permalink( $selected_page_id ) ); ?>" target="_blank"
												class="button button-secondary"><?php esc_html_e( 'View Page', 'ielts-science-lms' ); ?></a>
										<?php else : ?>
											<!-- Create Page Button with Data Attribute -->
											<button type="button" class="button button-secondary ielts-create-page-button"
												data-page-key="<?php echo esc_attr( $page_key ); ?>"
												data-module="<?php echo esc_attr( $module_data['module_name'] ); ?>"><?php esc_html_e( 'Create Page', 'ielts-science-lms' ); ?></button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					</div>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form> <!-- End of Main Settings Form -->
		</div>

		<script type="text/javascript">
			jQuery(document).ready(function ($) {
				$('.ielts-create-page-button').on('click', function () {
					var pageKey = $(this).data('page-key');
					var moduleName = $(this).data('module'); // Get the module name
					var $button = $(this);
					var nonce = '<?php echo esc_js( wp_create_nonce( 'ielts_create_page_nonce' ) ); ?>';

					$.ajax({
						url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
						type: 'POST',
						data: {
							action: 'ielts_create_page_ajax',
							page_key: pageKey,
							module: moduleName,
							_wpnonce: nonce // Add nonce for security
						},
						beforeSend: function () {
							$button.attr('disabled', 'disabled').text('<?php esc_html_e( 'Creating...', 'ielts-science-lms' ); ?>');
						},
						success: function (response) {
							if (response.success) {
								location.reload(); // Refresh the page.
							} else {
								alert('Error: ' + response.data.message);
							}
						},
						error: function (jqXHR, textStatus, errorThrown) {
							alert('AJAX Error: ' + textStatus + ' - ' + errorThrown);
						},
						complete: function () {
							$button.text('<?php esc_html_e( 'Create Page', 'ielts-science-lms' ); ?>').removeAttr('disabled');
						}
					});
				});
			});
		</script>
		<?php
	}

	/**
	 * Handle the AJAX request to create a page.
	 *
	 * Creates a new WordPress page with the appropriate title and template
	 * based on the page key and module provided in the request.
	 */
	public function handle_create_page_ajax() {
		// Check if user has the capability to manage options.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have sufficient permissions to access this page.', 'ielts-science-lms' ) ) );
		}

		// Verify nonce.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'ielts_create_page_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ielts-science-lms' ) ) );
		}

		$page_key    = isset( $_POST['page_key'] ) ? sanitize_text_field( wp_unslash( $_POST['page_key'] ) ) : '';
		$module_name = isset( $_POST['module'] ) ? sanitize_text_field( wp_unslash( $_POST['module'] ) ) : '';

		if ( empty( $page_key ) || empty( $module_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid page key or module name.', 'ielts-science-lms' ) ) );
		}

		// Use the filter to get all module pages data.
		$module_pages_data = apply_filters( 'ieltssci_lms_module_pages_data', array() );
		$page_title        = '';

		// Find the page title from the corresponding module.
		foreach ( $module_pages_data as $module_data ) {
			if ( $module_data['module_name'] === $module_name ) {
				if ( isset( $module_data['pages'][ $page_key ] ) ) {
					$page_title = $module_data['pages'][ $page_key ];
					break;
				}
			}
		}

		if ( empty( $page_title ) ) {
			wp_send_json_error( array( 'message' => __( 'Page title not found for the given key and module.', 'ielts-science-lms' ) ) );
		}

		$this->handle_page_action( $page_key, 'create_page', $page_title );

		// Assume the page creation was successful.
		wp_send_json_success( array( 'message' => __( 'Page created successfully!', 'ielts-science-lms' ) ) );
	}

	/**
	 * Handle actions for page creation.
	 *
	 * @param string $page_key   The key identifying the page type.
	 * @param string $action     The action to perform on the page.
	 * @param string $page_title The title for the new page.
	 */
	private function handle_page_action( $page_key, $action, $page_title ) {
		$page_slug = sanitize_title( $page_title );

		if ( 'create_page' === $action ) {
			$page_id = $this->create_page( $page_title, $page_slug );
		}

		if ( ! is_wp_error( $page_id ) ) {
			// Update the option for this page key.
			$ielts_pages              = get_option( 'ielts_science_lms_pages', array() );
			$ielts_pages[ $page_key ] = $page_id;
			update_option( 'ielts_science_lms_pages', $ielts_pages );

			// Set transient for success notice.
			set_transient( 'ielts_science_lms_page_action_success_' . $page_key, true, 5 );
		} else {
			// Set transient for error notice.
			set_transient( 'ielts_science_lms_page_action_error_' . $page_key, $page_id->get_error_message(), 5 );
		}
	}

	/**
	 * Create a new WordPress page.
	 *
	 * @param string $page_title The title for the new page.
	 * @param string $page_slug  The slug for the new page URL.
	 * @return int|WP_Error      The new page ID or WP_Error on failure.
	 */
	private function create_page( $page_title, $page_slug ) {
		$page_data = array(
			'post_type'     => 'page',
			'post_title'    => $page_title,
			'post_status'   => 'publish',
			'post_name'     => $page_slug,
			'page_template' => 'template-react-page.php',
		);

		$page_id = wp_insert_post( $page_data );

		return $page_id;
	}

	/**
	 * Display admin notices for page actions.
	 *
	 * Shows success or error notices after page creation attempts.
	 */
	private function display_notices() {
		$module_pages_data = apply_filters( 'ieltssci_lms_module_pages_data', array() );

		foreach ( $module_pages_data as $module_data ) {
			foreach ( $module_data['pages'] as $page_key => $page_label ) {
				// Check for success notice.
				if ( get_transient( 'ielts_science_lms_page_action_success_' . $page_key ) ) {
					?>
					<div class="notice notice-success is-dismissible">
						<p>
							<?php
							/* translators: %s: Page label */
							printf( esc_html__( '%s page created successfully!', 'ielts-science-lms' ), esc_html( $page_label ) );
							?>
						</p>
					</div>
					<?php
					delete_transient( 'ielts_science_lms_page_action_success_' . $page_key );
				}

				// Check for error notice.
				$error_message = get_transient( 'ielts_science_lms_page_action_error_' . $page_key );
				if ( $error_message ) {
					?>
					<div class="notice notice-error is-dismissible">
						<p>
							<?php
							/* translators: 1: Page label, 2: Error message */
							printf( esc_html__( 'Error creating %1$s page: %2$s', 'ielts-science-lms' ), esc_html( $page_label ), esc_html( $error_message ) );
							?>
						</p>
					</div>
					<?php
					delete_transient( 'ielts_science_lms_page_action_error_' . $page_key );
				}
			}
		}
	}

	/**
	 * Register plugin settings.
	 *
	 * Sets up the settings groups and sections used throughout the plugin.
	 */
	public function register_settings() {
		// Register settings group for pages.
		register_setting(
			'ielts_science_lms_pages_group', // Settings group name.
			'ielts_science_lms_pages',        // Option name.
			array( $this, 'sanitize_page_settings' )  // Sanitization callback.
		);

		// Register settings group for API settings.
		register_setting(
			'ielts_science_api_settings_group', // Settings group name.
			'ielts_science_api_settings',      // Option name.
			array( $this, 'sanitize_api_settings' ) // Sanitization callback.
		);

		// Register settings group for Sample Results.
		register_setting(
			'ielts_science_sample_results_group', // Settings group name.
			'ielts_science_sample_results',       // Option name.
			array( $this, 'sanitize_sample_results' ) // Sanitization callback.
		);
	}

	/**
	 * Sanitize the page settings before saving to database.
	 *
	 * Ensures all page IDs are integers and flushes rewrite rules.
	 *
	 * @param array $input The raw input array from the settings form.
	 * @return array       The sanitized settings array.
	 */
	public function sanitize_page_settings( $input ) {
		$sanitized_input = array();

		foreach ( $input as $key => $value ) {
			$sanitized_input[ $key ] = intval( $value ); // Ensure it's an integer.
		}

		// Flush rewrite rules after saving settings.
		// This ensures our custom rewrite rules take effect.
		flush_rewrite_rules();

		return $sanitized_input;
	}

	/**
	 * Sanitize the API settings before saving to database.
	 *
	 * @param array $input The raw input array from the settings form.
	 * @return array       The sanitized settings array.
	 */
	public function sanitize_api_settings( $input ) {
		$sanitized_input = array();

		// Sanitize and validate max_concurrent_requests.
		if ( isset( $input['max_concurrent_requests'] ) ) {
			$max_requests = intval( $input['max_concurrent_requests'] );
			// Ensure the value is between 1 and 20.
			$sanitized_input['max_concurrent_requests'] = min( 20, max( 1, $max_requests ) );
		} else {
			$sanitized_input['max_concurrent_requests'] = 5; // Default value.
		}

		return $sanitized_input;
	}

	/**
	 * Sanitize the sample results settings.
	 *
	 * @param array $input The raw input array from the settings form.
	 * @return array       The sanitized settings array.
	 */
	public function sanitize_sample_results( $input ) {
		$sanitized_input = array();

		if ( is_array( $input ) ) {
			foreach ( $input as $key => $value ) {
				// Sanitize URLs.
				$sanitized_input[ $key ] = esc_url_raw( $value );
			}
		}

		return $sanitized_input;
	}
}
