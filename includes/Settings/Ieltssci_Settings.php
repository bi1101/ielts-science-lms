<?php

namespace IeltsScienceLMS\Settings;

class Ieltssci_Settings {
	private $settings_config;

	public function __construct() {
		$this->settings_config = new Ieltssci_Settings_Config();
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'register_settings_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_ielts_create_page_ajax', [ $this, 'handle_create_page_ajax' ] );
	}

	public function register_admin_menu() {
		add_menu_page(
			__( 'IELTS Science LMS', 'ielts-science-lms' ),
			__( 'IELTS Science LMS', 'ielts-science-lms' ),
			'manage_options',
			'ielts-science-lms',
			[ $this, 'settings_page' ],
			'dashicons-welcome-learn-more',
			10
		);

		add_submenu_page(
			'ielts-science-lms',
			__( 'Pages', 'ielts-science-lms' ),
			__( 'Pages', 'ielts-science-lms' ),
			'manage_options',
			'ielts-science-lms-pages',
			[ $this, 'pages_settings_page' ]
		);

		add_submenu_page(
			'ielts-science-lms',
			__( 'Settings', 'ielts-science-lms' ),
			__( 'Settings', 'ielts-science-lms' ),
			'manage_options',
			'ielts-science-lms-settings',
			[ $this, 'settings_page' ]
		);
	}

	public function enqueue_admin_scripts() {
		wp_enqueue_script( 'jquery-ui-tabs' );
		wp_enqueue_style( 'jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );
		wp_enqueue_style( 'wp-admin' );
	}

	public function enqueue_settings_assets( $admin_page ) {

		if ( 'ielts-science-lms_page_ielts-science-lms-settings' !== $admin_page ) {
			return;
		}

		// Get current tab
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'writing-apis';

		$tabs = $this->settings_config->get_settings_config(); // Get all tabs
		foreach ( $tabs as $tab ) {
			if ( $tab['id'] === $current_tab ) {
				$current_tab_type = $tab['type'];
				break;
			}
		}

		// Get the appropriate script handle for this tab
		$script_handle = "ielts-science-wp-admin-{$current_tab_type}";
		$style_handle = "{$script_handle}-css";
		$runtime_handle = 'ielts-science-wp-admin-runtime';

		// Enqueue the runtime script if it's registered
		if ( wp_script_is( $runtime_handle, 'registered' ) ) {
			wp_enqueue_script( $runtime_handle );
		}

		// Enqueue the tab-specific script if it's registered
		if ( wp_script_is( $script_handle, 'registered' ) ) {
			wp_enqueue_script( $script_handle );
		}

		// Enqueue the tab-specific style if it's registered
		if ( wp_style_is( $style_handle, 'registered' ) ) {
			wp_enqueue_style( $style_handle );
		}

		wp_enqueue_style( 'wp-components' );

		match ( $current_tab_type ) {
			'api-feeds' => wp_localize_script( $script_handle, 'ieltssciSettings', [ 
				'apiRoot' => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'settingsConfig' => $this->settings_config->get_settings_config( $current_tab ),
				'currentTab' => $current_tab
			] ),
			'rate-limits' => wp_localize_script( $script_handle, 'ieltssciRateLimits', [ 
				'apiRoot' => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'roles' => wp_roles()->roles,
				'feeds' => array_reduce(
					( new \IeltsScienceLMS\ApiFeeds\Ieltssci_ApiFeeds_DB() )->get_all_api_feeds_settings(),
					function ($result, $item) {
							$id = $item['id'];
							if ( ! isset( $result[ $id ] ) ) {
								$result[ $id ] = $item;
							}
							return $result;
						},
					[]
				),
			] ),
			'api-feeds-process-order' => wp_localize_script( $script_handle, 'ieltssciProcessOrder', [ 
				'apiRoot' => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'settingsConfig' => $this->settings_config->get_settings_config( $current_tab ),
				'currentTab' => $current_tab
			] ),
			default => wp_localize_script( $script_handle, 'ieltssciSettings', [ 
				'apiRoot' => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'settingsConfig' => $this->settings_config->get_settings_config( $current_tab ),
				'currentTab' => $current_tab
			] ),
		};
		// Localize the script with the REST API URL, nonce & settings config

	}

	public function settings_page() {
		// Determine the current tab. Default to 'writing-apis' if none specified.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'writing-apis';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'IELTS Science LMS Settings', 'ielts-science-lms' ); ?></h1>

			<?php $this->render_tabs( $current_tab ); ?>

			<div id="ieltssci_settings_page"></div>
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
			<?php foreach ( $tabs as $tab ) :
				$active_class = ( $current_tab === $tab["id"] ) ? 'nav-tab-active' : '';
				$url = add_query_arg( 'tab', $tab["id"], menu_page_url( 'ielts-science-lms-settings', false ) ); // Use menu_page_url
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo esc_attr( $active_class ); ?>">
					<?php echo esc_html( $tab["label"] ); ?>
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
		$build_path = plugin_dir_path( __FILE__ ) . '../../admin/settings/build/';
		$asset_files = glob( $build_path . '*.asset.php' );

		foreach ( $asset_files as $asset_file ) {
			$asset = include( $asset_file );
			$handle = 'ielts-science-wp-admin-' . basename( $asset_file, '.asset.php' );
			$src = plugin_dir_url( __FILE__ ) . '../../admin/settings/build/' . basename( $asset_file, '.asset.php' ) . '.js';
			$deps = $asset['dependencies'];
			$ver = $asset['version'];

			wp_register_script( $handle, $src, $deps, $ver, true );

			$css_file = str_replace( '.asset.php', '.css', $asset_file );
			if ( file_exists( $css_file ) ) {
				$css_handle = $handle . '-css';
				$css_src = plugin_dir_url( __FILE__ ) . '../../admin/settings/build/' . basename( $css_file );
				wp_register_style( $css_handle, $css_src, [], $ver );
			}
		}
	}

	public function pages_settings_page() {
		// Display notices if any
		$this->display_notices();

		// Get current page assignments
		$ielts_pages = get_option( 'ielts_science_lms_pages', [] );

		// *** Apply Filter for Module Page Settings ***
		$module_pages_data = apply_filters( 'ielts_science_lms_module_pages_data', [] );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pages Settings', 'ielts-science-lms' ); ?></h1>
			<p><?php esc_html_e( 'Associate a WordPress page with each of the following pages.', 'ielts-science-lms' ); ?></p>

			<!-- Main Settings Form (for saving page selections) -->
			<form method="post" action="options.php">
				<?php settings_fields( 'ielts_science_lms_pages_group' ); ?>

				<?php // *** Loop Through Module Data ***
						foreach ( $module_pages_data as $module_data ) : ?>
					<div class="card" style="max-width: none;">
						<h2 class="title"><?php echo esc_html( $module_data['section_title'] ); ?></h2>
						<p><?php echo esc_html( $module_data['section_desc'] ); ?></p>
						<table class="form-table">
							<?php foreach ( $module_data['pages'] as $page_key => $page_label ) :
								$selected_page_id = isset( $ielts_pages[ $page_key ] ) ? $ielts_pages[ $page_key ] : 0;
								?>
								<tr>
									<th scope="row">
										<label
											for="ielts_science_lms_pages_<?php echo esc_attr( $page_key ); ?>"><?php echo esc_html( $page_label ); ?></label>
									</th>
									<td>
										<?php
										$dropdown_args = [ 
											'name' => "ielts_science_lms_pages[{$page_key}]",
											'id' => "ielts_science_lms_pages_" . esc_attr( $page_key ),
											'selected' => $selected_page_id,
											'show_option_none' => __( '- Select a page -', 'ielts-science-lms' ),
											'option_none_value' => '0',
										];
										wp_dropdown_pages( $dropdown_args );
										?>
										<?php if ( $selected_page_id && get_post_status( $selected_page_id ) === 'publish' ) : ?>
											<a href="<?php echo get_permalink( $selected_page_id ); ?>" target="_blank"
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

					$.ajax({
						url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
						type: 'POST',
						data: {
							action: 'ielts_create_page_ajax',
							page_key: pageKey,
							module: moduleName // Pass the module name to the AJAX handler
						},
						beforeSend: function () {
							$button.attr('disabled', 'disabled').text('<?php esc_html_e( 'Creating...', 'ielts-science-lms' ); ?>');
						},
						success: function (response) {
							if (response.success) {
								location.reload(); // Refresh the page
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

	public function handle_create_page_ajax() {
		// Check if user has the capability to manage options
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have sufficient permissions to access this page.', 'ielts-science-lms' ) ] );
		}

		$page_key = isset( $_POST['page_key'] ) ? sanitize_text_field( $_POST['page_key'] ) : '';
		$module_name = isset( $_POST['module'] ) ? sanitize_text_field( $_POST['module'] ) : '';

		if ( empty( $page_key ) || empty( $module_name ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid page key or module name.', 'ielts-science-lms' ) ] );
		}

		// Use the filter to get all module pages data
		$module_pages_data = apply_filters( 'ielts_science_lms_module_pages_data', [] );
		$page_title = '';

		// Find the page title from the corresponding module
		foreach ( $module_pages_data as $module_data ) {
			if ( $module_data['module_name'] === $module_name ) {
				if ( isset( $module_data['pages'][ $page_key ] ) ) {
					$page_title = $module_data['pages'][ $page_key ];
					break;
				}
			}
		}

		if ( empty( $page_title ) ) {
			wp_send_json_error( [ 'message' => __( 'Page title not found for the given key and module.', 'ielts-science-lms' ) ] );
		}

		$this->handle_page_action( $page_key, 'create_page', $page_title );

		// Assume the page creation was successful
		wp_send_json_success( [ 'message' => __( 'Page created successfully!', 'ielts-science-lms' ) ] );
	}

	// Function to handle page creation
	private function handle_page_action( $page_key, $action, $page_title ) {
		$page_slug = sanitize_title( $page_title );

		if ( $action === 'create_page' ) {
			$page_id = $this->create_page( $page_title, $page_slug );
		}

		if ( ! is_wp_error( $page_id ) ) {
			// Update the option for this page key
			$ielts_pages = get_option( 'ielts_science_lms_pages', [] );
			$ielts_pages[ $page_key ] = $page_id;
			update_option( 'ielts_science_lms_pages', $ielts_pages );

			// Set transient for success notice
			set_transient( 'ielts_science_lms_page_action_success_' . $page_key, true, 5 );
		} else {
			// Set transient for error notice
			set_transient( 'ielts_science_lms_page_action_error_' . $page_key, $page_id->get_error_message(), 5 );
		}
	}

	// Function to create a new page
	private function create_page( $page_title, $page_slug ) {
		$page_data = [ 
			'post_type' => 'page',
			'post_title' => $page_title,
			'post_status' => 'publish',
			'post_name' => $page_slug,
			'page_template' => 'template-react-page.php',
		];

		$page_id = wp_insert_post( $page_data );

		return $page_id;
	}

	private function display_notices() {
		$module_pages_data = apply_filters( 'ielts_science_lms_module_pages_data', [] );

		foreach ( $module_pages_data as $module_data ) {
			foreach ( $module_data['pages'] as $page_key => $page_label ) {
				// Check for success notice
				if ( get_transient( 'ielts_science_lms_page_action_success_' . $page_key ) ) {
					?>
					<div class="notice notice-success is-dismissible">
						<p>
							<?php printf( esc_html__( '%s page created successfully!', 'ielts-science-lms' ), $page_label ); ?>
						</p>
					</div>
					<?php
					delete_transient( 'ielts_science_lms_page_action_success_' . $page_key );
				}

				// Check for error notice
				$error_message = get_transient( 'ielts_science_lms_page_action_error_' . $page_key );
				if ( $error_message ) {
					?>
					<div class="notice notice-error is-dismissible">
						<p>
							<?php printf( esc_html__( 'Error creating %s page: %s', 'ielts-science-lms' ), $page_label, $error_message ); ?>
						</p>
					</div>
					<?php
					delete_transient( 'ielts_science_lms_page_action_error_' . $page_key );
				}
			}
		}
	}

	public function register_settings() {
		// Register settings group for pages
		register_setting(
			'ielts_science_lms_pages_group', // Settings group name
			'ielts_science_lms_pages',        // Option name
			[ $this, 'sanitize_page_settings' ]  // Sanitization callback
		);
	}

	public function sanitize_page_settings( $input ) {
		$sanitized_input = [];

		foreach ( $input as $key => $value ) {
			$sanitized_input[ $key ] = intval( $value ); // Ensure it's an integer
		}

		// Flush rewrite rules after saving settings
		// This ensures our custom rewrite rules take effect
		flush_rewrite_rules();

		return $sanitized_input;
	}
}