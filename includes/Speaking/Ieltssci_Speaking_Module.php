<?php
/**
 * IELTS Science Speaking Module
 *
 * This file contains the implementation of the Speaking Module for IELTS Science LMS.
 *
 * @package IeltsScienceLMS\Speaking
 */

namespace IeltsScienceLMS\Speaking;

use WP_REST_Users_Controller;
use WP_REST_Request;

/**
 * Class Ieltssci_Speaking_Module
 *
 * Handles the functionality for the IELTS Science Speaking Module.
 * Manages assets, routes, and data for the speaking module features.
 */
class Ieltssci_Speaking_Module {
	/**
	 * Constructor for the Ieltssci_Speaking_Module class.
	 *
	 * Initializes the speaking module by setting up hooks and loading dependencies.
	 */
	public function __construct() {
		new Ieltssci_Speaking_Settings();
		new Ieltssci_Speaking_REST();
		new Ieltssci_Speaking_SSE_REST();
		new Ieltssci_Speaking_Part_Submission_Controller();
		new Ieltssci_Speaking_Test_Submission_Controller();
		new Ieltssci_Speech_Attempt_Controller();

		// Register post meta for audio transcription.
		add_action( 'init', array( $this, 'register_audio_transcription_meta' ) );

		// Add meta box for audio transcription on attachment edit screen.
		add_action( 'add_meta_boxes', array( $this, 'add_audio_transcription_meta_box' ) );

		add_filter( 'ieltssci_lms_module_pages_data', array( $this, 'provide_module_pages_data' ) );

		// Add scripts for JSON viewer in admin.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_json_viewer_scripts' ) );

		// Initialize the speaking module assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_speaking_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_speaking_assets' ) );

		// Add custom rewrite rules for UUID child slugs.
		add_action( 'init', array( $this, 'register_custom_rewrite_rules' ) );
	}

	/**
	 * Enqueue scripts for JSON viewer
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_json_viewer_scripts( $hook ) {
		global $post;

		// Only load on post.php for attachment post type.
		if ( ! ( 'post.php' === $hook && isset( $post ) && 'attachment' === $post->post_type ) ) {
			return;
		}

		// Check if it's an audio attachment.
		$mime_type = get_post_mime_type( $post->ID );
		if ( strpos( $mime_type, 'audio/' ) !== 0 ) {
			return;
		}

		// Enqueue Prism.js for JSON syntax highlighting.
		wp_enqueue_style(
			'prism-css',
			'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css',
			array(),
			'1.29.0'
		);

		wp_enqueue_script(
			'prism-js',
			'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js',
			array(),
			'1.29.0',
			true
		);

		wp_enqueue_script(
			'prism-json',
			'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-json.min.js',
			array( 'prism-js' ),
			'1.29.0',
			true
		);

		// Add custom styles for the JSON viewer.
		wp_add_inline_style(
			'prism-css',
			'
			.ieltssci-json-viewer {
				max-height: 400px;
				overflow: auto;
				border: 1px solid #ddd;
				border-radius: 4px;
				background: #f5f5f5;
				margin: 10px 0;
				padding: 10px;
			}
			.ieltssci-json-viewer pre {
				margin: 0;
			}
			.ieltssci-download-btn {
				margin-top: 10px !important;
			}
		'
		);

		// Add custom script for the download button.
		wp_add_inline_script(
			'prism-json',
			'
			jQuery(document).ready(function($) {
				$(".ieltssci-download-btn").on("click", function(e) {
					e.preventDefault();

					var jsonContent = $("#ieltssci_audio_transcription_hidden").val();
					var fileName = "transcript-" + $(this).data("post-id") + ".json";

					// Create element with <a> tag
					var downloadLink = document.createElement("a");

					// Create a blog object with the file content
					var blob = new Blob([jsonContent], {type: "application/json"});

					// Create an object URL from the blob
					var url = URL.createObjectURL(blob);

					// Set link properties
					downloadLink.href = url;
					downloadLink.download = fileName;

					// Append to the body
					document.body.appendChild(downloadLink);

					// Trigger click event
					downloadLink.click();

					// Remove element
					document.body.removeChild(downloadLink);
				});
			});
		'
		);
	}

	/**
	 * Register post meta for audio transcription
	 */
	public function register_audio_transcription_meta() {
		register_post_meta(
			'attachment',
			'ieltssci_audio_transcription',
			array(
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => true,
					),
				),
				'single'            => true,
				'type'              => 'object',
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
				'sanitize_callback' => function ( $meta_value ) {
					// If it comes as a JSON string, parse it.
					if ( is_string( $meta_value ) ) {
						$decoded = json_decode( $meta_value, true );
						// If valid JSON, return the decoded object.
						if ( json_last_error() === JSON_ERROR_NONE ) {
							return $decoded;
						}
						// If not valid JSON but a string, return the raw string.
						return $meta_value;
					}
					// If already an array/object, return it as is.
					return $meta_value;
				},
				'description'       => __( 'IELTS Science Audio Transcription', 'ielts-science-lms' ),
			)
		);
	}

	/**
	 * Add meta box for audio transcription on attachment edit screen
	 */
	public function add_audio_transcription_meta_box() {
		// Only add meta box for audio attachments.
		global $post;

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return;
		}

		$mime_type = get_post_mime_type( $post->ID );
		if ( strpos( $mime_type, 'audio/' ) === 0 ) {
			add_meta_box(
				'ieltssci_audio_transcription',
				__( 'Audio Transcription', 'ielts-science-lms' ),
				array( $this, 'render_audio_transcription_meta_box' ),
				'attachment',
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render meta box content for audio transcription
	 *
	 * @param \WP_Post $post The current post object.
	 */
	public function render_audio_transcription_meta_box( $post ) {
		// Get the current transcription value.
		$transcription = get_post_meta( $post->ID, 'ieltssci_audio_transcription', true );

		// Initialize formatted JSON.
		$formatted_json = '';
		$is_valid_json  = false;
		$json_error     = '';

		// Process transcription data.
		if ( is_array( $transcription ) || is_object( $transcription ) ) {
			// If it's already an array or object, encode it to JSON.
			$formatted_json = wp_json_encode( $transcription, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$is_valid_json  = true;
		} elseif ( is_string( $transcription ) && ! empty( $transcription ) ) {
			// If it's a string, try to parse it as JSON.
			$decoded = json_decode( $transcription );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				// It's valid JSON, pretty print it.
				$formatted_json = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$is_valid_json  = true;
			} else {
				// Not valid JSON, display as is.
				$formatted_json = $transcription;
				$json_error     = json_last_error_msg();
			}
		}

		?>
		<div class="ieltssci-audio-transcription">
			<p><?php esc_html_e( 'This field displays the transcript data for this audio file in read-only format.', 'ielts-science-lms' ); ?></p>

			<?php if ( empty( $formatted_json ) ) : ?>
				<p><em><?php esc_html_e( 'No transcription data available for this audio file.', 'ielts-science-lms' ); ?></em></p>
			<?php else : ?>
				<?php if ( ! $is_valid_json ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
								// translators: %s:  is the JSON error message that explains why the transcription data is invalid.
								printf( esc_html__( 'Warning: The transcription data is not valid JSON. Error: %s', 'ielts-science-lms' ), esc_html( $json_error ) );
							?>
						</p>
					</div>
				<?php endif; ?>

				<div class="ieltssci-json-viewer">
					<pre><code class="language-json"><?php echo esc_html( $formatted_json ); ?></code></pre>
				</div>

				<!-- Hidden field to store the raw transcription data -->
				<input type="hidden" id="ieltssci_audio_transcription_hidden" value="<?php echo esc_attr( $formatted_json ); ?>">

				<!-- Download button -->
				<button class="button ieltssci-download-btn" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
					<?php esc_html_e( 'Download JSON', 'ielts-science-lms' ); ?>
				</button>

				<p class="description">
					<?php esc_html_e( 'This data is used by the IELTS Science Speaking module to display word-by-word transcription.', 'ielts-science-lms' ); ?>
					<?php esc_html_e( 'To modify this data, use the IELTS Science Speaking API endpoints.', 'ielts-science-lms' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Provide module pages data for the Speaking module.
	 *
	 * Adds the Speaking module page information to the overall module pages data.
	 *
	 * @param array $module_data Existing module data.
	 * @return array Updated module data with speaking module information.
	 */
	public function provide_module_pages_data( $module_data ) {
		$module_data['speaking_module'] = array(
			'module_name'   => 'speaking_module',
			'section_title' => __( 'Speaking Module Pages', 'ielts-science-lms' ),
			'section_desc'  => __( 'Select the pages for the Speaking Module.', 'ielts-science-lms' ),
			'pages'         => array(
				'speaking_submission' => __( 'IELTS Science Speaking', 'ielts-science-lms' ),
				'speaking_result'     => __( 'Speaking Results', 'ielts-science-lms' ),
				'speaking_history'    => __( 'Speaking History', 'ielts-science-lms' ),
			),
		);

		return $module_data;
	}

	/**
	 * Register speaking module assets
	 */
	public function register_speaking_assets() {
		$build_path  = plugin_dir_path( __FILE__ ) . '../../public/speaking/build/';
		$asset_files = glob( $build_path . '*.asset.php' );

		foreach ( $asset_files as $asset_file ) {
			$asset  = include $asset_file;
			$handle = 'ielts-science-speaking-' . basename( $asset_file, '.asset.php' );
			$src    = plugin_dir_url( __FILE__ ) . '../../public/speaking/build/' . basename( $asset_file, '.asset.php' ) . '.js';
			$deps   = $asset['dependencies'];
			$ver    = $asset['version'];

			wp_register_script( $handle, $src, $deps, $ver, true );
			wp_set_script_translations( $handle, 'ielts-science-lms', dirname( plugin_dir_path( __FILE__ ), 2 ) . '/languages' );

			$css_file = str_replace( '.asset.php', '.css', $asset_file );
			if ( file_exists( $css_file ) ) {
				$css_handle = $handle . '-css';
				$css_src    = plugin_dir_url( __FILE__ ) . '../../public/speaking/build/' . basename( $css_file );
				wp_register_style( $css_handle, $css_src, array(), $ver );
			}
		}
	}

	/**
	 * Enqueue speaking module assets
	 */
	public function enqueue_speaking_assets() {
		// Get the saved page settings.
		$ielts_pages = get_option( 'ielts_science_lms_pages', array() );

		// Get module pages data for the current module.
		$module_pages_data = $this->provide_module_pages_data( array() );

		// Extract speaking module pages.
		$speaking_module_pages = array();
		if ( isset( $module_pages_data['speaking_module']['pages'] ) ) {
			$speaking_module_pages = $module_pages_data['speaking_module']['pages'];
		}

		// Check if the current page is one of the assigned speaking module pages.
		$should_enqueue = false;
		if ( ! empty( $ielts_pages ) && ! empty( $speaking_module_pages ) ) {
			foreach ( $speaking_module_pages as $page_key => $page_label ) {
				if ( isset( $ielts_pages[ $page_key ] ) && is_page( $ielts_pages[ $page_key ] ) ) {
					$should_enqueue = true;
					break;
				}
			}
		}

		if ( $should_enqueue ) {
			// Define the handle for the index script and style.
			$script_handle  = 'ielts-science-speaking-index';
			$style_handle   = 'ielts-science-speaking-index-css';
			$runtime_handle = 'ielts-science-speaking-runtime';

			// Enqueue the runtime script if it's registered.
			if ( wp_script_is( $runtime_handle, 'registered' ) ) {
				wp_enqueue_script( $runtime_handle );
			}
			// Enqueue the index script if it's registered.
			if ( wp_script_is( $script_handle, 'registered' ) ) {
				wp_enqueue_script( $script_handle );
			}

			// Enqueue the index style if it's registered.
			if ( wp_style_is( $style_handle, 'registered' ) ) {
				wp_enqueue_style( $style_handle );
			}

			// Prepare data for localization using speaking module pages.
			$page_data_for_js = array();
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

			// Create a nonce.
			$nonce = wp_create_nonce( 'wp_rest' );

			// Get the REST API root URL.
			$root_url = rest_url();

			// --- Menu Retrieval and Localization ---

			// --- Header Menu ---
			$header_menu_name  = 'header-menu';
			$header_menu_items = array();

			$header_locations = get_nav_menu_locations();
			if ( isset( $header_locations[ $header_menu_name ] ) ) {
				$header_menu                 = wp_get_nav_menu_object( $header_locations[ $header_menu_name ] );
				$header_menu_items           = wp_get_nav_menu_items( $header_menu->term_id, array( 'order' => 'ASC' ) );
				$formatted_header_menu_items = $this->build_hierarchical_menu( $header_menu_items );
			} else {
				$formatted_header_menu_items = array(); // Empty array if menu not found.
			}

			// --- Account Menu ---
			$account_menu_name  = 'header-my-account';
			$account_menu_items = array();

			$account_locations = get_nav_menu_locations();
			if ( isset( $account_locations[ $account_menu_name ] ) ) {
				$account_menu                 = wp_get_nav_menu_object( $account_locations[ $account_menu_name ] );
				$account_menu_items           = wp_get_nav_menu_items( $account_menu->term_id, array( 'order' => 'ASC' ) );
				$formatted_account_menu_items = $this->build_hierarchical_menu( $account_menu_items );
			} else {
				$formatted_account_menu_items = array(); // Empty array if menu not found.
			}
			// --- End of Menu Retrieval ---

			// --- User Data ---
			$current_user            = wp_get_current_user();
			$user_link               = '';
			$display_name            = '';
			$user_mention            = '';
			$user_avatar             = '';
			$user_roles              = array(); // Initialize user roles array.
			$has_subscription_active = false; // Initialize subscription status.

			// Prepare safe user data using WordPress REST API user preparation.
			$safe_user_data = null;

			if ( is_user_logged_in() ) {
				$users_controller = new WP_REST_Users_Controller();
				$request          = new WP_REST_Request();
				$request->set_param( 'context', 'edit' ); // Use 'edit' context for more comprehensive data.

				// Prepare user data using WordPress's own REST API methods.
				$user_data      = $users_controller->prepare_item_for_response( $current_user, $request );
				$safe_user_data = $user_data->get_data();

				// BuddyBoss-specific functions (if BuddyBoss is active).
				if ( function_exists( 'bp_core_get_user_domain' ) ) {
					$user_link = bp_core_get_user_domain( $current_user->ID );
				} else {
					$user_link = get_author_posts_url( $current_user->ID );
				}

				if ( function_exists( 'bp_core_get_user_displayname' ) ) {
					$display_name = bp_core_get_user_displayname( $current_user->ID );
				} else {
					$display_name = $current_user->display_name;
				}

				if ( function_exists( 'bp_activity_get_user_mentionname' ) ) {
					$user_mention = '@' . bp_activity_get_user_mentionname( $current_user->ID );
				} else {
					$user_mention = '@' . $current_user->user_login;
				}

				$user_avatar = get_avatar_url( $current_user->ID, array( 'size' => 100 ) );

				// Get user roles.
				$user_roles = $current_user->roles;

				// Check if WooCommerce Subscriptions is active and user has active subscription.
				if ( class_exists( 'WC_Subscriptions' ) && function_exists( 'wcs_user_has_subscription' ) ) {
					// Check if user has any active subscription without specifying a product ID.
					$has_subscription_active = wcs_user_has_subscription( $current_user->ID, 0, 'active' );
				}
			}

			// Add WordPress login and register URLs.
			$login_url    = wp_login_url();
			$register_url = '';

			// Only include registration URL if registration is enabled.
			if ( get_option( 'users_can_register' ) ) {
				$register_url = wp_registration_url();
			}

			// Get current page information.
			$current_page = array(
				'id'    => get_queried_object_id(),
				'url'   => get_permalink( get_queried_object_id() ),
				'title' => get_the_title(),
				'slug'  => get_queried_object() ? get_queried_object()->post_name : '',
			);

			$setting_instance = new Ieltssci_Speaking_Settings();
			$feed_data        = $setting_instance->speaking_types();

			// Get API settings for max concurrent requests.
			$api_settings = get_option(
				'ielts_science_api_settings',
				array(
					'max_concurrent_requests' => 5, // Default value.
				)
			);

			// Get logo data.
			$show         = buddyboss_theme_get_option( 'logo_switch' );
			$show_dark    = buddyboss_theme_get_option( 'logo_dark_switch' );
			$logo_id      = buddyboss_theme_get_option( 'logo', 'id' );
			$logo_dark_id = buddyboss_theme_get_option( 'logo_dark', 'id' );

			// Get logo sizes.
			$logo_size        = buddyboss_theme_get_option( 'logo_size' );
			$mobile_logo_size = buddyboss_theme_get_option( 'mobile_logo_size' );

			// Default sizes if not set.
			$logo_size        = isset( $logo_size ) && ! empty( $logo_size ) ? $logo_size : '70';
			$mobile_logo_size = isset( $mobile_logo_size ) && ! empty( $mobile_logo_size ) ? $mobile_logo_size : '60';

			// Get logo URLs instead of HTML.
			$logo_url      = ( $show && $logo_id ) ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
			$logo_dark_url = ( $show && $show_dark && $logo_dark_id ) ? wp_get_attachment_image_url( $logo_dark_id, 'full' ) : '';

			// Get logo alt text.
			$logo_alt      = ( $logo_id ) ? get_post_meta( $logo_id, '_wp_attachment_image_alt', true ) : get_bloginfo( 'name' );
			$logo_dark_alt = ( $logo_dark_id ) ? get_post_meta( $logo_dark_id, '_wp_attachment_image_alt', true ) : get_bloginfo( 'name' );

			// Site information.
			$site_title    = get_bloginfo( 'name' );
			$site_url      = home_url( '/' );
			$front_page_id = get_option( 'page_on_front' );

			// Get Google Console client ID.
			$google_console_client_id = '';
			$api_keys_db              = new \IeltsScienceLMS\ApiKeys\Ieltssci_ApiKeys_DB();
			$google_console_key       = $api_keys_db->get_api_key(
				0,
				array(
					'provider' => 'google-console',
				)
			);
			if ( ! empty( $google_console_key ) && ! empty( $google_console_key['meta'] ) && ! empty( $google_console_key['meta']['client-id'] ) ) {
				$google_console_client_id = $google_console_key['meta']['client-id'];
			}

			// Combine all data to be localized.
			$localized_data = array(
				'pages'                    => $page_data_for_js,
				'nonce'                    => $nonce,
				'root_url'                 => $root_url,
				'is_logged_in'             => is_user_logged_in(),
				'current_user'             => $safe_user_data, // Use safe user data prepared by WordPress REST API.
				'header_menu'              => $formatted_header_menu_items,
				'account_menu'             => $formatted_account_menu_items,
				'user_link'                => $user_link,
				'user_display_name'        => $display_name,
				'user_mention'             => $user_mention,
				'user_avatar'              => $user_avatar,
				'user_roles'               => $user_roles,
				'has_subscription_active'  => $has_subscription_active, // Add subscription status.
				'feed_data'                => $feed_data,
				'max_concurrent_requests'  => $api_settings['max_concurrent_requests'],
				'login_url'                => $login_url,
				'register_url'             => $register_url,
				'ajax_url'                 => admin_url( 'admin-ajax.php' ), // Add AJAX URL for custom login.
				'current_page'             => $current_page,
				// New logo data.
				'site_logo_url'            => $logo_url,
				'site_logo_dark_url'       => $logo_dark_url,
				'logo_size'                => $logo_size,
				'mobile_logo_size'         => $mobile_logo_size,
				'logo_alt'                 => $logo_alt,
				'logo_dark_alt'            => $logo_dark_alt,
				'site_title'               => $site_title,
				'site_url'                 => $site_url,
				'front_page'               => $front_page_id,
				// WooCommerce data.
				'show_shopping_cart'       => buddyboss_theme_get_option( 'desktop_component_opt_multi_checkbox', 'desktop_shopping_cart' ) && class_exists( 'WooCommerce' ),
				'woocommerce_urls'         => array(
					'cart'     => class_exists( 'WooCommerce' ) ? wc_get_cart_url() : '#',
					'checkout' => class_exists( 'WooCommerce' ) ? wc_get_checkout_url() : '#',
				),
				// Footer data.
				'footer_copyright_text'    => do_shortcode( buddyboss_theme_get_option( 'copyright_text' ) ),
				'footer_description'       => buddyboss_theme_get_option( 'footer_description' ),
				'footer_tagline'           => buddyboss_theme_get_option( 'footer_tagline' ),
				'footer_style'             => (int) buddyboss_theme_get_option( 'footer_style' ),
				'footer_logo_url'          => wp_get_attachment_image_url( buddyboss_theme_get_option( 'footer_logo', 'id' ), 'full' ),
				'google_console_client_id' => $google_console_client_id,
			);

			// Get footer menu items.
			$footer_menu_name = 'footer-menu';
			$footer_locations = get_nav_menu_locations();
			if ( isset( $footer_locations[ $footer_menu_name ] ) ) {
				$footer_menu                   = wp_get_nav_menu_object( $footer_locations[ $footer_menu_name ] );
				$footer_menu_items             = wp_get_nav_menu_items( $footer_menu->term_id, array( 'order' => 'ASC' ) );
				$localized_data['footer_menu'] = $this->build_hierarchical_menu( $footer_menu_items );
			}

			// Get footer secondary menu items.
			$footer_secondary_menu_name = 'footer-secondary';
			if ( isset( $footer_locations[ $footer_secondary_menu_name ] ) ) {
				$footer_secondary_menu                   = wp_get_nav_menu_object( $footer_locations[ $footer_secondary_menu_name ] );
				$footer_secondary_menu_items             = wp_get_nav_menu_items( $footer_secondary_menu->term_id, array( 'order' => 'ASC' ) );
				$localized_data['footer_secondary_menu'] = $this->build_hierarchical_menu( $footer_secondary_menu_items );
			}

			// Get social links.
			$footer_socials = buddyboss_theme_get_option( 'boss_footer_social_links' );
			// Pass the social links object directly.
			$localized_data['footer_socials'] = is_array( $footer_socials ) ? $footer_socials : array();

			// Localize script (pass data to the React app).
			wp_localize_script( $script_handle, 'ielts_speaking_data', $localized_data );
		}
	}

	/**
	 * Register custom rewrite rules for UUID child slugs
	 */
	public function register_custom_rewrite_rules() {
		$ielts_pages = get_option( 'ielts_science_lms_pages', array() );

		// List of result pages to add rewrite rules for.
		$result_pages = array( 'speaking_result', 'speaking_history' );

		foreach ( $result_pages as $page_key ) {
			if ( ! empty( $ielts_pages[ $page_key ] ) ) {
				$result_page = get_post( $ielts_pages[ $page_key ] );

				if ( $result_page ) {
					$slug = $result_page->post_name;
					add_rewrite_rule(
						'^' . $slug . '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/?$',
						'index.php?pagename=' . $slug . '&entry_id=$matches[1]',
						'top'
					);
				}
			}
		}

		add_filter(
			'query_vars',
			function ( $query_vars ) {
				$query_vars[] = 'entry_id';
				return $query_vars;
			}
		);
	}

	/**
	 * Build hierarchical menu
	 *
	 * @param array $items Menu items.
	 * @param int   $parent_id Parent ID.
	 * @return array Hierarchical menu.
	 */
	private function build_hierarchical_menu( $items, $parent_id = 0 ) {
		$menu = array();
		foreach ( $items as $item ) {
			if ( (int) $item->menu_item_parent === (int) $parent_id ) {
				$menu_item = array(
					'id'       => $item->ID,
					'title'    => $item->title,
					'url'      => html_entity_decode( $item->url ),
					'children' => $this->build_hierarchical_menu( $items, $item->ID ),
					'icon'     => $this->get_menu_item_icon( $item ),
				);
				$menu[]    = $menu_item;
			}
		}
		return $menu;
	}

	/**
	 * Get menu item icon
	 *
	 * @param object $item Menu item.
	 * @return string Icon HTML.
	 */
	private function get_menu_item_icon( $item ) {
		$meta_file  = locate_template( 'inc/plugins/buddyboss-menu-icons/includes/meta.php' );
		$front_file = locate_template( 'inc/plugins/buddyboss-menu-icons/includes/front.php' );

		if ( file_exists( $meta_file ) ) {
			require_once $meta_file;
		}
		if ( file_exists( $front_file ) ) {
			require_once $front_file;
		}

		$icon = false;

		if ( class_exists( '\Menu_Icons_Meta' ) ) {
			$meta = \Menu_Icons_Meta::get( $item->ID );
			if ( class_exists( '\Menu_Icons_Front_End' ) ) {
				$icon = \Menu_Icons_Front_End::get_icon( $meta );
			}
		}

		if ( is_object( $icon ) && isset( $icon->html ) ) {
			$icon = $icon->html;
		} elseif ( is_array( $icon ) && isset( $icon['html'] ) ) {
			$icon = $icon['html'];
		}

		if ( $icon ) {
			$icon = wp_kses(
				$icon,
				array(
					'i'    => array( 'class' => array() ),
					'svg'  => array(
						'class'       => array(),
						'aria-hidden' => array(),
						'role'        => array(),
						'focusable'   => array(),
						'xmlns'       => array(),
						'width'       => array(),
						'height'      => array(),
						'viewbox'     => array(),
					),
					'path' => array(
						'd'    => array(),
						'fill' => array(),
					),
					'use'  => array(
						'xlink:href' => array(),
					),
				)
			);
		}

		return $icon;
	}
}
