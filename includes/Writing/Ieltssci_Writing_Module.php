<?php
/**
 * IELTS Science Writing Module
 *
 * This file contains the implementation of the Writing Module for IELTS Science LMS.
 *
 * @package IeltsScienceLMS\Writing
 */

namespace IeltsScienceLMS\Writing;

use WP_REST_Users_Controller;
use WP_REST_Request;

/**
 * Class Ieltssci_Writing_Module
 *
 * Handles the functionality for the IELTS Science Writing Module.
 * Manages assets, routes, and data for the writing module features.
 */
class Ieltssci_Writing_Module {
	/**
	 * Constructor for the Ieltssci_Writing_Module class.
	 *
	 * Initializes the writing module by setting up hooks and loading dependencies.
	 */
	public function __construct() {
		new Ieltssci_Writing_SSE_REST();
		new Ieltssci_Writing_Essay_Controller();
		new Ieltssci_Writing_Settings();
		new Ieltssci_Writing_Entries();
		new Ieltssci_Submission_DB();
		new Ieltssci_Writing_Task_Submission_Controller(); // Register REST controller via constructor.
		new Ieltssci_Writing_Test_Submission_Controller(); // Register REST controller for writing test submissions.
		new Ieltssci_Writing_Vote_Controller();
		// Initialize the writing module.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_writing_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_writing_assets' ), 100 );
		add_filter( 'ieltssci_lms_module_pages_data', array( $this, 'provide_module_pages_data' ) );

		// Add custom rewrite rules for UUID child slugs.
		add_action( 'init', array( $this, 'register_custom_rewrite_rules' ) );
	}

	/**
	 * Register assets (scripts and styles) for the writing module.
	 *
	 * This function locates and registers JavaScript and CSS files required for the writing module.
	 * Asset files are expected to be in the 'public/writing/build/' directory.
	 *
	 * @return void
	 */
	public function register_writing_assets() {
		$build_path  = plugin_dir_path( __FILE__ ) . '../../public/writing/build/';
		$asset_files = glob( $build_path . '*.asset.php' );

		foreach ( $asset_files as $asset_file ) {
			$asset  = include $asset_file;
			$handle = 'ielts-science-writing-' . basename( $asset_file, '.asset.php' );
			$src    = plugin_dir_url( __FILE__ ) . '../../public/writing/build/' . basename( $asset_file, '.asset.php' ) . '.js';
			$deps   = $asset['dependencies'];
			$ver    = $asset['version'];

			wp_register_script( $handle, $src, $deps, $ver, true );
			wp_set_script_translations( $handle, 'ielts-science-lms', dirname( plugin_dir_path( __FILE__ ), 2 ) . '/languages' );

			$css_file = str_replace( '.asset.php', '.css', $asset_file );
			if ( file_exists( $css_file ) ) {
				$css_handle = $handle . '-css';
				$css_src    = plugin_dir_url( __FILE__ ) . '../../public/writing/build/' . basename( $css_file );
				wp_register_style( $css_handle, $css_src, array(), $ver );
			}
		}
	}

	/**
	 * Enqueue assets for the writing module based on the current page.
	 *
	 * Loads necessary scripts and styles when the user is on a writing module page.
	 * Provides localized data to the JavaScript including page routes, user info, and menus.
	 *
	 * @return void
	 */
	public function enqueue_writing_assets() {
		// Get the saved page settings.
		$ielts_pages = get_option( 'ielts_science_lms_pages', array() );

		// Get module pages data for current module.
		$module_pages_data = $this->provide_module_pages_data( array() );

		// Also get dashboard module pages data.
		$dashboard_module  = new \IeltsScienceLMS\Dashboard\Ieltssci_Dashboard_Module();
		$module_pages_data = $dashboard_module->provide_module_pages_data( $module_pages_data );

		// Extract writing module pages.
		$writing_module_pages = array();
		if ( isset( $module_pages_data['writing_module']['pages'] ) ) {
			$writing_module_pages = $module_pages_data['writing_module']['pages'];
		}

		// Extract dashboard module pages.
		$dashboard_module_pages = array();
		if ( isset( $module_pages_data['dashboard_module']['pages'] ) ) {
			$dashboard_module_pages = $module_pages_data['dashboard_module']['pages'];
		}

		// Check if current page is one of the assigned writing module pages.
		$should_enqueue = false;
		$script_type    = 'index'; // Default script type.

		if ( ! empty( $ielts_pages ) && ! empty( $writing_module_pages ) ) {
			foreach ( $writing_module_pages as $page_key => $page_label ) {
				if ( isset( $ielts_pages[ $page_key ] ) && is_page( $ielts_pages[ $page_key ] ) ) {
					$should_enqueue = true;
					// Set specific script types for practice pages.
					if ( 'writing_task_practice' === $page_key ) {
						$script_type = 'writing-task-practice';
					} elseif ( 'writing_test_practice' === $page_key ) {
						$script_type = 'writing-test-practice';
					} elseif ( 'writing_practice' === $page_key ) {
						$script_type = 'writing-practice';
					} elseif ( 'result_writing_test' === $page_key ) {
						$script_type = 'result-writing-test';
					}
					break;
				}
			}
		}

		// Check for writing task/test pages and archives.
		if ( ! $should_enqueue ) {
			if ( is_singular( 'writing-task' ) ) {
				// Dequeue styles with handle matching 'elementor-post-*'.
				global $wp_styles;
				if ( isset( $wp_styles->registered ) ) {
					foreach ( $wp_styles->registered as $handle => $style ) {
						// Dequeue if handle starts with 'elementor-post-'.
						if ( strpos( $handle, 'elementor-post-' ) === 0 ) {
							wp_dequeue_style( $handle ); // Dequeue matching style.
						}
					}
				}
				$should_enqueue = true;
				$script_type    = 'writing-task-single';
			} elseif ( is_singular( 'writing-test' ) ) {
				// Dequeue styles with handle matching 'elementor-post-*'.
				global $wp_styles;
				if ( isset( $wp_styles->registered ) ) {
					foreach ( $wp_styles->registered as $handle => $style ) {
						// Dequeue if handle starts with 'elementor-post-'.
						if ( strpos( $handle, 'elementor-post-' ) === 0 ) {
							wp_dequeue_style( $handle ); // Dequeue matching style.
						}
					}
				}
				$should_enqueue = true;
				$script_type    = 'writing-test-single';
			} elseif ( is_post_type_archive( 'writing-task' ) || ( is_home() && get_option( 'page_for_posts' ) && get_post_type( get_option( 'page_for_posts' ) ) === 'writing-task' ) ) {
				// Dequeue styles with handle matching 'elementor-post-*'.
				global $wp_styles;
				if ( isset( $wp_styles->registered ) ) {
					foreach ( $wp_styles->registered as $handle => $style ) {
						// Dequeue if handle starts with 'elementor-post-'.
						if ( strpos( $handle, 'elementor-post-' ) === 0 ) {
							wp_dequeue_style( $handle ); // Dequeue matching style.
						}
					}
				}
				$should_enqueue = true;
				$script_type    = 'writing-task-archive';
			} elseif ( is_post_type_archive( 'writing-test' ) || ( is_home() && get_option( 'page_for_posts' ) && get_post_type( get_option( 'page_for_posts' ) ) === 'writing-test' ) ) {
				// Dequeue styles with handle matching 'elementor-post-*'.
				global $wp_styles;
				if ( isset( $wp_styles->registered ) ) {
					foreach ( $wp_styles->registered as $handle => $style ) {
						// Dequeue if handle starts with 'elementor-post-'.
						if ( strpos( $handle, 'elementor-post-' ) === 0 ) {
							wp_dequeue_style( $handle ); // Dequeue matching style.
						}
					}
				}
				$should_enqueue = true;
				$script_type    = 'writing-test-archive';
			} elseif ( is_tax( 'writing-task-collection' ) ) {
				// Writing Task Collection taxonomy archive.
				global $wp_styles;
				if ( isset( $wp_styles->registered ) ) {
					foreach ( $wp_styles->registered as $handle => $style ) {
						// Dequeue if handle starts with 'elementor-post-'.
						if ( strpos( $handle, 'elementor-post-' ) === 0 ) {
							wp_dequeue_style( $handle ); // Dequeue matching style.
						}
					}
				}
				$should_enqueue = true;
				$script_type    = 'writing-task-collection-archive';
			}
		}

		if ( $should_enqueue ) {
			// Define the handle for the script and style based on type.
			$script_handle  = 'ielts-science-writing-' . $script_type;
			$style_handle   = 'ielts-science-writing-' . $script_type . '-css';
			$runtime_handle = 'ielts-science-writing-runtime';

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

			// --- Post Data Retrieval ---.
			$post_data = null;
			if ( is_singular( array( 'writing-task', 'writing-test' ) ) ) {
				$current_post = get_queried_object();

				if ( $current_post && is_a( $current_post, 'WP_Post' ) ) {
					try {
						// Build the proper REST route.
						$route = '/wp/v2/' . $current_post->post_type . '/' . $current_post->ID;

						// Create a REST request that mimics a normal API request.
						$request = new WP_REST_Request( 'GET', $route );
						$request->set_param( 'acf_format', 'standard' ); // Ensure ACF fields are included in the response.
						// Set the route on the request.
						$request->set_route( $route );

						// Dispatch the request through the REST server to get ACF fields.
						$response = rest_do_request( $request );

						if ( ! is_wp_error( $response ) && $response->get_status() === 200 ) {
							$server    = rest_get_server();
							$post_data = $server->response_to_data( $response, true );

							// Add permalink to post data.
							$post_data['permalink_template'] = get_permalink( $current_post->ID, true );
						}
					} catch ( \Exception $e ) {
						// Log error but continue execution.
						error_log( 'Post data preparation failed: ' . $e->getMessage() );
					}
				}
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

				// WordPress REST API automatically excludes sensitive data like passwords.
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
			$queried_object = get_queried_object();
			$slug           = '';
			$page_id        = 0;
			$page_url       = '';
			$page_title     = '';
			$page_path      = ''; // Initialize page path.

			if ( $queried_object ) {
				if ( is_a( $queried_object, 'WP_Post' ) ) {
					// It's a post object.
					$slug       = $queried_object->post_name;
					$page_id    = get_queried_object_id();
					$page_url   = get_permalink( $page_id );
					$page_path  = wp_make_link_relative( $page_url );
					$page_title = get_the_title();
				} elseif ( is_post_type_archive() ) {
					$page_id    = 0; // Archive pages don't have a specific ID.
					$page_url   = get_post_type_archive_link( $queried_object->name );
					$page_title = $queried_object->labels->name ?? $queried_object->label ?? '';
					$page_path  = wp_make_link_relative( $page_url ); // Get relative path.
					$slug       = $queried_object->has_archive ? $queried_object->has_archive : $queried_object->name;
				} elseif ( is_tax() || is_category() || is_tag() ) {
					$page_id    = $queried_object->term_id;
					$page_url   = get_term_link( $queried_object );
					$page_path  = wp_make_link_relative( $page_url ); // Get relative path.
					$page_title = $queried_object->name;
					$slug       = $queried_object->slug;
				}
			}

			$current_page = array(
				'id'    => $page_id,
				'url'   => $page_url,
				'title' => $page_title,
				'path'  => $page_path, // Use relative path.
				'slug'  => $slug,
			);

			$setting_instance = new Ieltssci_Writing_Settings();
			$feed_data        = $setting_instance->essay_types();

			// Get API settings for max concurrent requests.
			$api_settings = get_option(
				'ielts_science_api_settings',
				array(
					'max_concurrent_requests' => 5, // Default value.
				)
			);

			// Get Google Console client ID.
			$google_console_client_id = '';
			$facebook_app_id          = '';
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
			// Get Facebook App ID.
			$facebook_key = $api_keys_db->get_api_key(
				0,
				array(
					'provider' => 'facebook',
				)
			);
			if ( ! empty( $facebook_key ) && ! empty( $facebook_key['meta'] ) && ! empty( $facebook_key['meta']['app-id'] ) ) {
				$facebook_app_id = $facebook_key['meta']['app-id'];
			}

			// Get logo data.
			$show         = buddyboss_theme_get_option( 'logo_switch' );
			$show_dark    = buddyboss_theme_get_option( 'logo_dark_switch' );
			$logo_id      = buddyboss_theme_get_option( 'logo', 'id' );
			$logo_dark_id = buddyboss_theme_get_option( 'logo_dark', 'id' );

			// Get logo sizes.
			$logo_size        = buddyboss_theme_get_option( 'logo_size' );
			$mobile_logo_size = buddyboss_theme_get_option( 'mobile_logo_size' );

			// Default sizes if not set.
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

			// Get sample results data.
			$sample_results = get_option( 'ielts_science_sample_results', array() );

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
				'post_data'                => $post_data,
				// Add sample results data.
				'sample_results'           => $sample_results,
				// Check if Nextend Social Login plugin is active.
				'social_login_active'      => class_exists( 'NextendSocialLogin' ),
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
					'shop'     => class_exists( 'WooCommerce' ) ? wc_get_page_permalink( 'shop' ) : '#',
				),
				// Footer data.
				'footer_copyright_text'    => do_shortcode( buddyboss_theme_get_option( 'copyright_text' ) ),
				'footer_description'       => buddyboss_theme_get_option( 'footer_description' ),
				'footer_tagline'           => buddyboss_theme_get_option( 'footer_tagline' ),
				'footer_style'             => (int) buddyboss_theme_get_option( 'footer_style' ),
				'footer_logo_url'          => wp_get_attachment_image_url( buddyboss_theme_get_option( 'footer_logo', 'id' ), 'full' ),
				// Google Console client ID.
				'google_console_client_id' => $google_console_client_id,
				// Facebook App ID.
				'facebook_app_id'          => $facebook_app_id,
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

			// Localize writing collections for archive page.
			if ( 'writing-task-archive' === $script_type ) {
				$collections                   = get_terms(
					array(
						'taxonomy'   => 'writing-task-collection',
						'hide_empty' => true,
						'orderby'    => 'menu_order',
						'order'      => 'ASC',
					)
				);
				$localized_collections         = array_map(
					function ( $term ) {
						return array(
							'id'          => $term->term_id,
							'name'        => $term->name,
							'slug'        => $term->slug,
							'description' => $term->description,
							'link'        => get_term_link( $term ),
						);
					},
					$collections
				);
				$localized_data['collections'] = $localized_collections;
			}

			// Localize current collection data for collection archive page.
			if ( 'writing-task-collection-archive' === $script_type ) {
				$current_term = get_queried_object();
				if ( $current_term && is_a( $current_term, 'WP_Term' ) ) {
					$localized_data['current_collection'] = array(
						'id'          => $current_term->term_id,
						'name'        => $current_term->name,
						'slug'        => $current_term->slug,
						'description' => $current_term->description,
						'count'       => $current_term->count,
						'link'        => get_term_link( $current_term ),
					);
				}
			}

			// Localize script (pass data to the React app).
			wp_localize_script( $script_handle, 'ielts_writing_data', $localized_data );
		}
	}

	/**
	 * Build the hierarchical menu structure.
	 *
	 * Creates a nested array of menu items based on parent-child relationships.
	 *
	 * @param array $items  Menu items.
	 * @param int   $parent_id Parent ID to filter by.
	 * @return array Hierarchical menu structure.
	 */
	private function build_hierarchical_menu( $items, $parent_id = 0 ) {
		$menu = array();
		foreach ( $items as $item ) {
			if ( (int) $item->menu_item_parent === (int) $parent_id ) {
				$menu_item = array(
					'id'       => $item->ID,
					'title'    => $item->title,
					'url'      => html_entity_decode( $item->url ),
					'children' => $this->build_hierarchical_menu( $items, $item->ID ), // Recursively find children.
					'icon'     => $this->get_menu_item_icon( $item ), // Get the icon data.
					// ... add other fields you need from the menu item object (e.g., classes, target, etc.) ...
				);
				$menu[] = $menu_item;
			}
		}
		return $menu;
	}

	/**
	 * Get the icon for a menu item.
	 *
	 * Retrieves the icon from BuddyBoss menu icons if available.
	 *
	 * @param object $item Menu item object.
	 * @return string|false Icon HTML or false if not found.
	 */
	private function get_menu_item_icon( $item ) {
		// Use locate_template to find the class files, respecting child themes.
		$meta_file  = locate_template( 'inc/plugins/buddyboss-menu-icons/includes/meta.php' );
		$front_file = locate_template( 'inc/plugins/buddyboss-menu-icons/includes/front.php' );

		// Include the necessary class files if they exist.
		if ( file_exists( $meta_file ) ) {
			require_once $meta_file;
		}
		if ( file_exists( $front_file ) ) {
			require_once $front_file;
		}

		$icon = false;

		// Use fully qualified class names with leading backslash to indicate global namespace.
		if ( class_exists( '\Menu_Icons_Meta' ) ) {
			$meta = \Menu_Icons_Meta::get( $item->ID );
			if ( class_exists( '\Menu_Icons_Front_End' ) ) {
				$icon = \Menu_Icons_Front_End::get_icon( $meta );
			}
		}

		// Handle cases where the icon might be an HTML string or an object.
		if ( is_object( $icon ) && isset( $icon->html ) ) {
			$icon = $icon->html;
		} elseif ( is_array( $icon ) && isset( $icon['html'] ) ) {
			$icon = $icon['html'];
		}

		// Sanitize the icon HTML.
		if ( $icon ) {
			$icon = wp_kses(
				$icon,
				array(
					'i'    => array(
						'class' => array(),
					),
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

	/**
	 * Register custom rewrite rules for UUID child slugs.
	 *
	 * Sets up rewrite rules to support URL patterns with UUIDs for entry identification.
	 *
	 * @return void
	 */
	public function register_custom_rewrite_rules() {
		$ielts_pages = get_option( 'ielts_science_lms_pages', array() );

		// List of result pages and submission pages to add rewrite rules for.
		$uuid_pages = array(
			'result_task_2',
			'result_task_1',
			'result_general_essay',
			'result_writing_test',
			'writing_task_practice',
			'writing_test_practice',
			'writing_practice',
		);

		foreach ( $uuid_pages as $page_key ) {
			// Check if the page is set.
			if ( ! empty( $ielts_pages[ $page_key ] ) ) {
				$page = get_post( $ielts_pages[ $page_key ] );

				if ( $page ) {
					$slug = $page->post_name;

					// Add rewrite rule for UUIDs (8-4-4-4-12 format).
					add_rewrite_rule(
						'^' . $slug . '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/?$',
						'index.php?pagename=' . $slug . '&entry_id=$matches[1]',
						'top'
					);
				}
			}
		}

		// Register the query var only once.
		add_filter(
			'query_vars',
			function ( $query_vars ) {
				$query_vars[] = 'entry_id';
				return $query_vars;
			}
		);
	}

	/**
	 * Provide module pages data for the Writing module.
	 *
	 * Adds the Writing module page information to the overall module pages data.
	 *
	 * @param array $module_data Existing module data.
	 * @return array Updated module data with writing module information.
	 */
	public function provide_module_pages_data( $module_data ) {
		$module_data['writing_module'] = array(
			'module_name'   => 'writing_module',
			'section_title' => __( 'Writing Module Pages', 'ielts-science-lms' ),
			'section_desc'  => __( 'Select the pages for the Writing Module.', 'ielts-science-lms' ),
			'pages'         => array(
				'writing_submission'    => __( 'IELTS Science Writing', 'ielts-science-lms' ),
				'result_task_2'         => __( 'Result Task 2', 'ielts-science-lms' ),
				'result_task_1'         => __( 'Result Task 1', 'ielts-science-lms' ),
				'result_general_essay'  => __( 'Result General Essay', 'ielts-science-lms' ),
				'result_writing_test'   => __( 'Result Writing Test', 'ielts-science-lms' ),
				'evaluation_history'    => __( 'Evaluation History', 'ielts-science-lms' ),
				'writing_task_practice' => __( 'Writing Task Practice', 'ielts-science-lms' ),
				'writing_test_practice' => __( 'Writing Test Practice', 'ielts-science-lms' ),
				'writing_practice'      => __( 'Writing Practice', 'ielts-science-lms' ),
			),
		);

		return $module_data;
	}
}
