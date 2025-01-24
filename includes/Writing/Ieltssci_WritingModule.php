<?php

namespace IeltsScienceLMS\Writing;

use WP_Query;

class Ieltssci_WritingModule {
	public function __construct() {
		// Initialize the writing module
		add_action( 'wp_enqueue_scripts', [ $this, 'register_writing_assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_writing_assets' ] );
		add_filter( 'ielts_science_lms_module_pages_data', [ $this, 'provide_module_pages_data' ] );
	}

	/**
	 * Đăng ký các tài nguyên (assets) cho module viết.
	 *
	 * Hàm này sẽ tìm và đăng ký các tệp JavaScript và CSS cần thiết cho module viết.
	 * Các tệp tài nguyên được tìm kiếm trong thư mục 'public/writing/build/'.
	 * 
	 * @return void
	 */
	public function register_writing_assets() {
		$build_path = plugin_dir_path( __FILE__ ) . '../../public/writing/build/';
		$asset_files = glob( $build_path . '*.asset.php' );

		foreach ( $asset_files as $asset_file ) {
			$asset = include( $asset_file );
			$handle = 'ielts-science-' . basename( $asset_file, '.asset.php' );
			$src = plugin_dir_url( __FILE__ ) . '../../public/writing/build/' . basename( $asset_file, '.asset.php' ) . '.js';
			$deps = $asset['dependencies'];
			$ver = $asset['version'];

			wp_register_script( $handle, $src, $deps, $ver, true );

			$css_file = str_replace( '.asset.php', '.css', $asset_file );
			if ( file_exists( $css_file ) ) {
				$css_handle = $handle . '-css';
				$css_src = plugin_dir_url( __FILE__ ) . '../../public/writing/build/' . basename( $css_file );
				wp_register_style( $css_handle, $css_src, [], $ver );
			}
		}
	}

	public function create_writing_page() {
		$page_slug = 'ielts-science-writing';

		// Check if a post with the 'ieltssci_flag' meta key already exists
		$meta_query = new WP_Query( array(
			'post_type' => 'page',
			'meta_key' => 'ieltssci_flag',
			'meta_value' => 'IELTS Science Writing',
		) );

		if ( ! $meta_query->have_posts() ) {
			$page = array(
				'post_title' => 'IELTS Science Writing',
				'post_name' => $page_slug,
				'post_type' => 'page',
				'meta_input' => array(
					'ieltssci_flag' => 'IELTS Science Writing',
				),
				'page_template' => 'template-react-page.php',
			);
			wp_insert_post( $page );
		}

	}

	public function enqueue_writing_assets() {
		// Get the saved page settings
		$ielts_pages = get_option( 'ielts_science_lms_pages', [] );

		// Get module pages data for current module
		$module_pages_data = $this->provide_module_pages_data( [] );

		// Extract writing module pages
		$writing_module_pages = [];
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
			$script_handle = 'ielts-science-index';
			$style_handle = 'ielts-science-index-css';
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
			$page_data_for_js = [];
			foreach ( $writing_module_pages as $page_key => $page_label ) {
				if ( isset( $ielts_pages[ $page_key ] ) ) {
					$page_id = $ielts_pages[ $page_key ];
					// Check if this page is set as the front page
					$is_front_page = ( $page_id == get_option( 'page_on_front' ) );
					// Use empty string for homepage URI to match root route
					$uri = $is_front_page ? '' : get_page_uri( $page_id );
					$page_data_for_js[ $page_key ] = $uri;
				}
			}

			// Create a nonce
			$nonce = wp_create_nonce( 'wp_rest' );

			// Get the REST API root URL
			$root_url = rest_url();

			// Combine all data to be localized
			$localized_data = [ 
				'pages' => $page_data_for_js,
				'nonce' => $nonce,
				'root_url' => $root_url,
			];

			// Localize script (pass data to the React app)
			wp_localize_script( $script_handle, 'ielts_writing_data', $localized_data );
		}
	}

	public function provide_module_pages_data( $module_data ) {
		$module_data['writing_module'] = [ 
			'module_name' => 'writing_module',
			'section_title' => __( 'Writing Module Pages', 'ielts-science-lms' ),
			'section_desc' => __( 'Select the pages for the Writing Module.', 'ielts-science-lms' ),
			'pages' => [ 
				'writing_submission' => __( 'IELTS Science Writing', 'ielts-science-lms' ),
				'result_task_2' => __( 'Result Task 2', 'ielts-science-lms' ),
				'result_task_1' => __( 'Result Task 1', 'ielts-science-lms' ),
				'result_general_essay' => __( 'Result General Essay', 'ielts-science-lms' ),
			],
		];

		return $module_data;
	}

}