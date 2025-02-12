<?php

namespace IeltsScienceLMS\Core;

class Ieltssci_Core_Module {
	public function __construct() {
		new \IeltsScienceLMS\Writing\Ieltssci_Writing_Module();
		new \IeltsScienceLMS\Writing\Ieltssci_Writing_Settings();
		new \IeltsScienceLMS\Settings\Ieltssci_Settings();
		new \IeltsScienceLMS\Settings\Ieltssci_Settings_REST();
		add_filter( 'theme_page_templates', [ $this, 'add_custom_page_template' ] );
		add_filter( 'template_include', [ $this, 'load_custom_page_template' ] );
		add_filter( 'display_post_states', [ $this, 'add_module_page_post_state' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'dequeue_assets_for_react_template' ], 100 );
	}

	/**
	 * Kích hoạt mô-đun lõi.
	 * Hàm này được gọi khi plugin được kích hoạt. Nó thực hiện các kiểm tra cần thiết
	 * để đảm bảo tương thích với phiên bản WordPress hiện tại.
	 *
	 * @return void
	 */
	public function activate() {
		$this->check_wp_version();
		// Trigger settings table creation
		do_action( 'ieltssci_activate' );
	}


	/**
	 * Vô hiệu hóa plugin.
	 * Chứa các hành động cần thực hiện khi plugin bị vô hiệu hóa.
	 *
	 * @return void
	 */
	public function deactivate() {
		// Actions to perform on plugin deactivation
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
	public function add_custom_page_template( $templates ) {
		$templates['template-react-page.php'] = 'React Page Template';
		return $templates;
	}

	public function load_custom_page_template( $template ) {
		if ( is_page_template( 'template-react-page.php' ) ) {
			$template = plugin_dir_path( __FILE__ ) . '../templates/template-react-page.php';
		}
		return $template;
	}

	public function dequeue_assets_for_react_template() {
		if ( is_page_template( 'template-react-page.php' ) ) {

			// Dequeue Stylesheets
			wp_dequeue_style( 'bb_theme_block-buddypanel-style-css' );
			wp_dequeue_style( 'buddyboss-theme-buddypress' );
			wp_dequeue_style( 'buddyboss-theme-css' );
			wp_dequeue_style( 'buddyboss-theme-fonts' );
			wp_dequeue_style( 'buddyboss-theme-magnific-popup-css' );
			wp_dequeue_style( 'buddyboss-theme-select2-css' );
			wp_dequeue_style( 'buddyboss-theme-template' );
			wp_dequeue_style( 'buddyboss_legacy' );
			wp_dequeue_style( 'redux-extendify-styles' );

			// Dequeue JavaScripts
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
		}
	}

	public function add_module_page_post_state( $post_states, $post ) {
		// Get the saved page settings
		$ielts_pages = get_option( 'ielts_science_lms_pages', [] );

		// Check if this post's ID is one of the assigned pages
		if ( ! empty( $ielts_pages ) && in_array( $post->ID, $ielts_pages ) ) {
			// Get the module pages data
			$module_pages_data = apply_filters( 'ielts_science_lms_module_pages_data', [] );

			// Find the page key and label for this post's ID
			foreach ( $module_pages_data as $module_data ) {
				foreach ( $module_data['pages'] as $page_key => $page_label ) {
					if ( isset( $ielts_pages[ $page_key ] ) && $ielts_pages[ $page_key ] == $post->ID ) {
						// Add the page label as a custom post state
						$post_states[] = esc_html( $page_label );
						break 2; // Exit both loops once the page is found
					}
				}
			}
		}
		return $post_states;
	}

}
