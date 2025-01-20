<?php

namespace IeltsScienceLMS\Core;

class Ieltssci_CoreModule {
	public function __construct() {
		new \IeltsScienceLMS\Writing\Ieltssci_WritingModule();
		new \IeltsScienceLMS\Core\Ieltssci_Settings();
		add_filter( 'theme_page_templates', [ $this, 'add_custom_page_template' ] );
		add_filter( 'template_include', [ $this, 'load_custom_page_template' ] );
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
		// Create the writing page
		// ( new \IeltsScienceLMS\Writing\Ieltssci_WritingModule() )->create_writing_page();
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

}
