<?php

namespace IeltsScienceLMS\Writing;

use WP_Query;

class Ieltssci_WritingModule {
	public function __construct() {
		// Initialize the writing module
		add_action( 'wp_enqueue_scripts', [ $this, 'register_writing_assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_writing_assets' ] );
		add_filter( 'display_post_states', [ $this, 'add_custom_post_state' ], 10, 2 );
		add_filter( 'ielts_science_lms_module_pages_data', [ $this, 'provide_module_pages_data' ] );
	}

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

	public function add_custom_post_state( $post_states, $post ) {
		if ( get_post_meta( $post->ID, 'ieltssci_flag', true ) === 'IELTS Science Writing' ) {
			$post_states[] = 'IELTS Science Writing';
		}
		return $post_states;
	}

	public function enqueue_writing_assets() {
		if ( is_singular( 'page' ) && get_post_meta( get_the_ID(), 'ieltssci_flag', true ) === 'IELTS Science Writing' ) {
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
		}
	}

	public function provide_module_pages_data( $module_data ) {
		$module_data['writing_module'] = [ 
			'module_name' => 'writing_module',
			'section_title' => __( 'Writing Module Pages', 'ielts-science-lms' ),
			'section_desc' => __( 'Select the pages for the Writing Module.', 'ielts-science-lms' ),
			'pages' => [ 
				'writing_submission' => __( 'Writing Submission', 'ielts-science-lms' ),
				'result_task_2' => __( 'Result Task 2', 'ielts-science-lms' ),
				'result_task_1' => __( 'Result Task 1', 'ielts-science-lms' ),
				'result_general_essay' => __( 'Result General Essay', 'ielts-science-lms' ),
			],
		];

		return $module_data;
	}

}