<?php

namespace IeltsScienceLMS\Writing;

class Ieltssci_Writing_Module {
	public function __construct() {
		new Ieltssci_Writing_SSE_REST();
		// Initialize the writing module
		add_action( 'wp_enqueue_scripts', array( $this, 'register_writing_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_writing_assets' ) );
		add_filter( 'ielts_science_lms_module_pages_data', array( $this, 'provide_module_pages_data' ) );

		// Add custom rewrite rules for UUID child slugs
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
			$handle = 'ielts-science-' . basename( $asset_file, '.asset.php' );
			$src    = plugin_dir_url( __FILE__ ) . '../../public/writing/build/' . basename( $asset_file, '.asset.php' ) . '.js';
			$deps   = $asset['dependencies'];
			$ver    = $asset['version'];

			wp_register_script( $handle, $src, $deps, $ver, true );

			$css_file = str_replace( '.asset.php', '.css', $asset_file );
			if ( file_exists( $css_file ) ) {
				$css_handle = $handle . '-css';
				$css_src    = plugin_dir_url( __FILE__ ) . '../../public/writing/build/' . basename( $css_file );
				wp_register_style( $css_handle, $css_src, array(), $ver );
			}
		}
	}

	public function enqueue_writing_assets() {
		// Get the saved page settings
		$ielts_pages = get_option( 'ielts_science_lms_pages', array() );

		// Get module pages data for current module
		$module_pages_data = $this->provide_module_pages_data( array() );

		// Extract writing module pages
		$writing_module_pages = array();
		if ( isset( $module_pages_data['writing_module']['pages'] ) ) {
			$writing_module_pages = $module_pages_data['writing_module']['pages'];
		}

		// Check if current page is one of the assigned writing module pages
		$should_enqueue = false;
		if ( ! empty( $ielts_pages ) && ! empty( $writing_module_pages ) ) {
			foreach ( $writing_module_pages as $page_key => $page_label ) {
				if ( isset( $ielts_pages[ $page_key ] ) && is_page( $ielts_pages[ $page_key ] ) ) {
					$should_enqueue = true;
					break;
				}
			}
		}

		if ( $should_enqueue ) {
			// Define the handle for the index script and style.
			$script_handle  = 'ielts-science-index';
			$style_handle   = 'ielts-science-index-css';
			$runtime_handle = 'ielts-science-runtime';

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

			// Prepare data for localization using writing module pages
			$page_data_for_js = array();
			foreach ( $writing_module_pages as $page_key => $page_label ) {
				if ( isset( $ielts_pages[ $page_key ] ) ) {
					$page_id = $ielts_pages[ $page_key ];
					// Check if this page is set as the front page
					$is_front_page = ( $page_id == get_option( 'page_on_front' ) );
					// Use empty string for homepage URI to match root route
					$uri                           = $is_front_page ? '' : get_page_uri( $page_id );
					$page_data_for_js[ $page_key ] = $uri;
				}
			}

			// Create a nonce
			$nonce = wp_create_nonce( 'wp_rest' );

			// Get the REST API root URL
			$root_url = rest_url();

			// --- Menu Retrieval and Localization ---

			// Function to get the menu item icon
			function get_menu_item_icon( $item ) {
				// Use locate_template to find the class files, respecting child themes
				$meta_file  = locate_template( 'inc/plugins/buddyboss-menu-icons/includes/meta.php' );
				$front_file = locate_template( 'inc/plugins/buddyboss-menu-icons/includes/front.php' );

				// Include the necessary class files if they exist
				if ( file_exists( $meta_file ) ) {
					require_once $meta_file;
				}
				if ( file_exists( $front_file ) ) {
					require_once $front_file;
				}

				$icon = false;

				// Use fully qualified class names with leading backslash to indicate global namespace
				if ( class_exists( '\Menu_Icons_Meta' ) ) {
					$meta = \Menu_Icons_Meta::get( $item->ID );
					if ( class_exists( '\Menu_Icons_Front_End' ) ) {
						$icon = \Menu_Icons_Front_End::get_icon( $meta );
					}
				}

				// Handle cases where the icon might be an HTML string or an object
				if ( is_object( $icon ) && isset( $icon->html ) ) {
					$icon = $icon->html;
				} elseif ( is_array( $icon ) && isset( $icon['html'] ) ) {
					$icon = $icon['html'];
				}

				// Sanitize the icon HTML
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

			// Function to build the hierarchical menu structure
			function build_hierarchical_menu( $items, $parent = 0 ) {
				$menu = array();
				foreach ( $items as $item ) {
					if ( $item->menu_item_parent == $parent ) {
						$menu_item = array(
							'id'       => $item->ID,
							'title'    => $item->title,
							'url'      => html_entity_decode( $item->url ),
							'children' => build_hierarchical_menu( $items, $item->ID ), // Recursively find children
							'icon'     => get_menu_item_icon( $item ), // Get the icon data
							// ... add other fields you need from the menu item object (e.g., classes, target, etc.) ...
						);
						$menu[] = $menu_item;
					}
				}
				return $menu;
			}

			// --- Header Menu ---
			$header_menu_name  = 'header-menu';
			$header_menu_items = array();

			if ( ( $header_locations = get_nav_menu_locations() ) && isset( $header_locations[ $header_menu_name ] ) ) {
				$header_menu                 = wp_get_nav_menu_object( $header_locations[ $header_menu_name ] );
				$header_menu_items           = wp_get_nav_menu_items( $header_menu->term_id, array( 'order' => 'ASC' ) );
				$formatted_header_menu_items = build_hierarchical_menu( $header_menu_items );
			} else {
				$formatted_header_menu_items = array(); // Empty array if menu not found
			}

			// --- Account Menu ---
			$account_menu_name  = 'header-my-account';
			$account_menu_items = array();

			if ( ( $account_locations = get_nav_menu_locations() ) && isset( $account_locations[ $account_menu_name ] ) ) {
				$account_menu                 = wp_get_nav_menu_object( $account_locations[ $account_menu_name ] );
				$account_menu_items           = wp_get_nav_menu_items( $account_menu->term_id, array( 'order' => 'ASC' ) );
				$formatted_account_menu_items = build_hierarchical_menu( $account_menu_items );
			} else {
				$formatted_account_menu_items = array();
			}
			// --- End of Menu Retrieval ---

			// --- User Data ---
			$current_user = wp_get_current_user();
			$user_link    = '';
			$display_name = '';
			$user_mention = '';
			$user_avatar  = '';

			if ( is_user_logged_in() ) {
				// BuddyBoss-specific functions (if BuddyBoss is active)
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
			}

			// Combine all data to be localized
			$localized_data = array(
				'pages'             => $page_data_for_js,
				'nonce'             => $nonce,
				'root_url'          => $root_url,
				'is_logged_in'      => is_user_logged_in(),
				'header_menu'       => $formatted_header_menu_items,
				'account_menu'      => $formatted_account_menu_items,
				'user_link'         => $user_link,
				'user_display_name' => $display_name,
				'user_mention'      => $user_mention,
				'user_avatar'       => $user_avatar,
			);

			// Localize script (pass data to the React app)
			wp_localize_script( $script_handle, 'ielts_writing_data', $localized_data );
		}
	}

	/**
	 * Register custom rewrite rules for UUID child slugs
	 *
	 * @return void
	 */
	public function register_custom_rewrite_rules() {
		$ielts_pages = get_option( 'ielts_science_lms_pages', array() );

		// Check if result_task_2 page is set
		if ( ! empty( $ielts_pages['result_task_2'] ) ) {
			$result_task_2_page = get_post( $ielts_pages['result_task_2'] );

			if ( $result_task_2_page ) {
				$slug = $result_task_2_page->post_name;

				// Add rewrite rule for UUIDs (8-4-4-4-12 format)
				add_rewrite_rule(
					'^' . $slug . '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/?$',
					'index.php?pagename=' . $slug . '&entry_id=$matches[1]',
					'top'
				);

				// Register the query var
				add_filter(
					'query_vars',
					function ( $query_vars ) {
						$query_vars[] = 'entry_id';
						return $query_vars;
					}
				);
			}
		}
	}

	public function provide_module_pages_data( $module_data ) {
		$module_data['writing_module'] = array(
			'module_name'   => 'writing_module',
			'section_title' => __( 'Writing Module Pages', 'ielts-science-lms' ),
			'section_desc'  => __( 'Select the pages for the Writing Module.', 'ielts-science-lms' ),
			'pages'         => array(
				'writing_submission'   => __( 'IELTS Science Writing', 'ielts-science-lms' ),
				'result_task_2'        => __( 'Result Task 2', 'ielts-science-lms' ),
				'result_task_1'        => __( 'Result Task 1', 'ielts-science-lms' ),
				'result_general_essay' => __( 'Result General Essay', 'ielts-science-lms' ),
			),
		);

		return $module_data;
	}
}
