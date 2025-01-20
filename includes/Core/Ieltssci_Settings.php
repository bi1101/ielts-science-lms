<?php

namespace IeltsScienceLMS\Core;

class Ieltssci_Settings {
	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
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

	public function settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'IELTS Science LMS Settings', 'ielts-science-lms' ); ?></h1>
			<div id="tabs">
				<ul>
					<li><a href="#general"><?php esc_html_e( 'General', 'ielts-science-lms' ); ?></a></li>
					<li><a href="#advanced"><?php esc_html_e( 'Advanced', 'ielts-science-lms' ); ?></a></li>
				</ul>
				<div id="general">
					<form method="post" action="options.php">
						<?php
						settings_fields( 'ielts_science_lms_settings_group' );
						do_settings_sections( 'ielts_science_lms_settings_group' );
						submit_button();
						?>
					</form>
				</div>
				<div id="advanced">
					<p><?php esc_html_e( 'Advanced settings go here.', 'ielts-science-lms' ); ?></p>
				</div>
			</div>
		</div>
		<script>
			jQuery(document).ready(function ($) {
				$("#tabs").tabs();
			});
		</script>
		<?php
	}

	public function pages_settings_page() {
		// Display notices if any
		$this->display_notices();

		// Get current page assignments
		$ielts_pages = get_option( 'ielts_science_lms_pages', [] );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pages Settings', 'ielts-science-lms' ); ?></h1>
			<p><?php esc_html_e( 'Associate a WordPress page with each of the following pages.', 'ielts-science-lms' ); ?></p>

			<!-- Main Settings Form (for saving page selections) -->
			<form method="post" action="options.php">
				<?php settings_fields( 'ielts_science_lms_pages_group' ); ?>
				<table class="form-table">
					<?php
					$pages_data = $this->get_pages_data();

					foreach ( $pages_data as $page_key => $page_data ) :
						$selected_page_id = isset( $ielts_pages[ $page_key ] ) ? $ielts_pages[ $page_key ] : 0;
						?>
						<tr>
							<th scope="row">
								<label
									for="ielts_science_lms_pages_<?php echo esc_attr( $page_key ); ?>"><?php echo esc_html( $page_data['label'] ); ?></label>
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
										data-page-key="<?php echo esc_attr( $page_key ); ?>"><?php esc_html_e( 'Create Page', 'ielts-science-lms' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form> <!-- End of Main Settings Form -->
		</div>

		<script type="text/javascript">
			jQuery(document).ready(function ($) {
				$('.ielts-create-page-button').on('click', function () {
					var pageKey = $(this).data('page-key');
					var $button = $(this); // Store reference to the button

					$.ajax({
						url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
						type: 'POST',
						data: {
							action: 'ielts_create_page_ajax', // AJAX action name
							page_key: pageKey
						},
						beforeSend: function () {
							// Disable the button and change the text
							$button.attr('disabled', 'disabled').text('<?php esc_html_e( 'Creating...', 'ielts-science-lms' ); ?>');
						},
						success: function (response) {
							location.reload(); // Simplest way to refresh the page and show the new link
						},
						error: function (jqXHR, textStatus, errorThrown) {
							alert('AJAX Error: ' + textStatus + ' - ' + errorThrown);
						},
						complete: function () {
							// Re-enable the button and change the text back
							$button.text('<?php esc_html_e( 'Create Page', 'ielts-science-lms' ); ?>');
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

		if ( empty( $page_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid page key.', 'ielts-science-lms' ) ] );
		}

		$this->handle_page_action( $page_key, 'create_page' );

		// Assume the page creation was successful
		wp_send_json_success( [ 'message' => __( 'Page created successfully!', 'ielts-science-lms' ) ] );
	}

	// Function to handle page creation
	private function handle_page_action( $page_key, $action ) {
		$pages_data = $this->get_pages_data();
		if ( ! isset( $pages_data[ $page_key ] ) ) {
			return; // Invalid page key
		}

		$page_title = $pages_data[ $page_key ]['label'];
		$page_content = ''; // Content is not needed
		$page_slug = sanitize_title( $page_title );

		if ( $action === 'create_page' ) {
			$page_id = $this->create_page( $page_title, $page_content, $page_slug );
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
	private function create_page( $page_title, $page_content, $page_slug ) {
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

	// Helper function to get pages data
	private function get_pages_data() {
		return [ 
			'writing_submission' => [ 
				'label' => __( 'Writing Submission', 'ielts-science-lms' ),
			],
			'result_task_2' => [ 
				'label' => __( 'Result Task 2', 'ielts-science-lms' ),
			],
			'result_task_1' => [ 
				'label' => __( 'Result Task 1', 'ielts-science-lms' ),
			],
			'result_general_essay' => [ 
				'label' => __( 'Result General Essay', 'ielts-science-lms' ),
			],
		];
	}

	private function display_notices() {
		$pages_data = $this->get_pages_data();

		foreach ( $pages_data as $page_key => $page_data ) {
			// Check for success notice
			if ( get_transient( 'ielts_science_lms_page_action_success_' . $page_key ) ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php printf( esc_html__( '%s page created successfully!', 'ielts-science-lms' ), $page_data['label'] ); ?></p>
				</div>
				<?php
				delete_transient( 'ielts_science_lms_page_action_success_' . $page_key );
			}

			// Check for error notice
			$error_message = get_transient( 'ielts_science_lms_page_action_error_' . $page_key );
			if ( $error_message ) {
				?>
				<div class="notice notice-error is-dismissible">
					<p><?php printf( esc_html__( 'Error creating %s page: %s', 'ielts-science-lms' ), $page_data['label'], $error_message ); ?>
					</p>
				</div>
				<?php
				delete_transient( 'ielts_science_lms_page_action_error_' . $page_key );
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
}