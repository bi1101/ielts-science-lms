<?php

namespace IeltsScienceLMS\Core;

class Ieltssci_Settings {
	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'register_settings_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_ielts_create_page_ajax', [ $this, 'handle_create_page_ajax' ] );
		add_action( 'ieltssci_activate', [ $this, 'on_activate' ] );
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

	/**
	 * Get the script handle for the current tab.
	 *
	 * @param string $current_tab
	 * @return string
	 */
	private function get_tab_script_handle( $current_tab ) {
		$tab_scripts = [ 
			'writing-apis' => 'ielts-science-wp-admin-writing-apis',
			'another-tab' => 'ielts-science-wp-admin-another-tab',
			// Add more tab-script mappings as needed
		];

		return isset( $tab_scripts[ $current_tab ] ) ? $tab_scripts[ $current_tab ] : 'ielts-science-wp-admin-index';
	}

	public function enqueue_settings_assets( $admin_page ) {
		if ( 'ielts-science-lms_page_ielts-science-lms-settings' !== $admin_page ) {
			return;
		}

		// Get current tab
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'writing-apis';

		// Get the appropriate script handle for this tab
		$script_handle = $this->get_tab_script_handle( $current_tab );
		$style_handle = $script_handle . '-css';
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

		// Localize the script with the REST API URL, nonce & settings config
		wp_localize_script( $script_handle, 'ieltssciSettings', [ 
			'apiRoot' => esc_url_raw( rest_url() ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'settingsConfig' => $this->get_settings_config( $current_tab ),
			'currentTab' => $current_tab
		] );
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
		$tabs = $this->get_settings_config(); // Get the tab list

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

		return $sanitized_input;
	}

	private function create_api_feeds_table() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ieltssci_api_feed';
		$charset_collate = $wpdb->get_charset_collate();
		$db_version = '0.0.1';
		$current_version = get_option( 'ieltssci_api_feed_db_version' );

		// If table exists and versions match, return early
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name
			&& $current_version === $db_version ) {
			return;
		}

		$sql = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			feedback_criteria varchar(191) NOT NULL,
			feed_title varchar(191) NOT NULL,
			feed_desc text DEFAULT NULL,
			process_order int(11) UNSIGNED NOT NULL DEFAULT 0,
			essay_type varchar(50) NOT NULL,
			apply_to varchar(50) NOT NULL,
			meta longtext NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY feedback_criteria (feedback_criteria),
			KEY essay_type (essay_type)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		// Update version if needed
		if ( $current_version !== $db_version ) {
			update_option( 'ieltssci_api_feed_db_version', $db_version );
		}
	}

	public function on_activate() {
		$this->create_api_feeds_table();
	}

	/**
	 * Generates a field configuration.
	 *
	 * @param string $id           Field ID.
	 * @param string $type         Field type (e.g., 'radio', 'textarea', 'number', 'modelPicker').
	 * @param string $label        Field label.
	 * @param string $help         (Optional) Help text.
	 * @param mixed  $default      (Optional) Default value.
	 * @param array  $options      (Optional) Options for radio buttons, modelPicker, etc.
	 * @param string $dependency   (Optional) Field dependency.
	 * @param array  $extra_attrs  (Optional) Additional attributes for further customization.
	 *
	 * @return array The field configuration array.
	 */
	public function createField( string $id, string $type, string $label, string $help = '', $default = null, array $options = [], string $dependency = '', array $extra_attrs = [] ): array {
		$field = [ 
			'id' => $id,
			'type' => $type,
			'label' => $label,
		];

		if ( $help ) {
			$field['help'] = $help;
		}
		if ( $default !== null ) {
			$field['default'] = $default;
		} // Handle 0, false, etc. correctly
		if ( $options ) {
			$field['options'] = $options;
		}
		if ( $dependency ) {
			$field['dependency'] = $dependency;
		}
		if ( $extra_attrs ) {
			$field = array_merge( $field, $extra_attrs );
		} // Allow other attributes

		return $field;
	}

	/**
	 * Generates an API provider field configuration (reusable radio button group).
	 *
	 * @param mixed $default (Optional) Default value.
	 * @return array The API provider field configuration.
	 */
	public function createApiProviderField( $default = 'open-key-ai' ): array {
		return $this->createField(
			'apiProvider',
			'radio',
			'API Provider',
			'Select which API provider to use.',
			$default,
			[ 
				[ 'label' => 'Open Key AI', 'value' => 'open-key-ai' ],
				[ 'label' => 'Open AI', 'value' => 'open-ai' ],
				[ 'label' => 'Google', 'value' => 'google' ],
				[ 'label' => 'Azure', 'value' => 'azure' ],
				[ 'label' => 'Home Server', 'value' => 'home-server' ],
			]
		);
	}

	/**
	 * Generates a model picker field configuration.
	 *
	 * @param string $dependency The field this model picker depends on.
	 * @param array  $options    The model options.
	 * @param mixed  $default    (Optional) Default value.
	 *
	 * @return array The model picker field configuration.
	 */
	public function createModelPickerField( string $dependency, array $options, $default = 'gpt-4o-mini' ): array {
		return $this->createField(
			'model',
			'modelPicker',
			'Model',
			'',  // No help text in the original for model
			$default,
			$options,
			$dependency
		);
	}

	/**
	 * Generates common model options based on API provider.  Handles the "Other:" option.
	 *
	 * @param array $providerOptions Model options specific to a provider.
	 * @return array Combined options, including the "Other:" option.
	 */
	public function getModelOptions( array $providerOptions ): array {
		$options = [];
		foreach ( $providerOptions as $provider => $models ) {
			$options[ $provider ] = $models;
			// Check if 'Other:' already exists, if not add. Avoid duplicates
			$hasOther = false;
			foreach ( $models as $model ) {
				if ( $model['value'] === 'other' ) {
					$hasOther = true;
					break;
				}
			}
			if ( ! $hasOther ) {
				$options[ $provider ][] = [ 'label' => 'Other:', 'value' => 'other' ];
			}
		}
		return $options;
	}
	/**
	 * Generates a prompt field configuration.
	 *
	 * @param string $id         Field ID (e.g., 'englishPrompt', 'vietnamesePrompt').
	 * @param string $label      Field label (e.g., 'English Prompt', 'Vietnamese Prompt').
	 * @param string $default    Default prompt text.
	 *
	 * @return array The prompt field configuration.
	 */
	public function createPromptField( string $id, string $label, string $default ): array {
		return $this->createField(
			$id,
			'textarea',
			$label,
			'The message to send to LLM',
			$default
		);
	}

	/**
	 * Generates a section configuration.
	 *
	 * @param string $sectionName  Section name (e.g., 'general-setting', 'advanced-setting').
	 * @param array  $fields       Array of field configurations.
	 *
	 * @return array The section configuration.
	 */
	public function createSection( string $sectionName, array $fields ): array {
		return [ 
			'section' => $sectionName,
			'fields' => $fields,
		];
	}

	/**
	 * Generates a step configuration.
	 *
	 * @param string $stepName    Step name (e.g., 'chain-of-thought', 'scoring', 'feedback').
	 * @param array  $sections    Array of section configurations.
	 *
	 * @return array The step configuration.
	 */
	public function createStep( string $stepName, array $sections ): array {
		return [ 
			'step' => $stepName,
			'sections' => $sections,
		];
	}

	/**
	 * Creates a feed configuration.
	 *
	 * @param string $feedName
	 * @param string $feedTitle
	 * @param string $applyTo
	 * @param array $essayType
	 * @param array $steps
	 * @return array
	 */
	public function createFeed( string $feedName, string $feedTitle, string $applyTo, array $essayType, array $steps ): array {
		return [ 
			'feedName' => $feedName,
			'feedTitle' => $feedTitle,
			'applyTo' => $applyTo,
			'essayType' => $essayType,
			'steps' => $steps
		];
	}

	/**
	 * Generates the main settings configuration.
	 *
	 * @param string|null $tab The tab ID to retrieve settings for, or null for the tab list.
	 *
	 * @return array The settings configuration, or null if the tab is not found.
	 */
	public function get_settings_config( $tab = null ) {
		$all_settings = apply_filters( 'ieltssci_settings_config', [] );

		if ( $tab === null ) {
			// Return only the tab list (id and label)
			$tab_list = [];
			foreach ( $all_settings as $tab_id => $tab_data ) {
				$tab_list[] = [ 
					'id' => $tab_id,
					'label' => $tab_data['tab_label'],
				];
			}
			return $tab_list;
		} else {
			// Return the full settings for the specified tab
			if ( isset( $all_settings[ $tab ] ) ) {
				return $all_settings[ $tab ]['settings'];
			} else {
				return []; // Or null, depending on how you want to handle it in JS
			}
		}
	}
}