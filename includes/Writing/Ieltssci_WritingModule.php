<?php

namespace IeltsScienceLMS\Writing;

use WP_Query;

class Ieltssci_WritingModule {
	public function __construct() {
		// Initialize the writing module
		add_action( 'wp_enqueue_scripts', [ $this, 'register_writing_assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_writing_assets' ] );
		add_filter( 'display_post_states', [ $this, 'add_custom_post_state' ], 10, 2 );
		add_filter( 'theme_page_templates', [ $this, 'add_custom_page_template' ] );
		add_filter( 'template_include', [ $this, 'load_custom_page_template' ] );
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
				'page_template' => '../templates/template-react-page.php',
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
			$build_path = plugin_dir_path( __FILE__ ) . '../../public/writing/build/';
			$asset_files = glob( $build_path . '*.asset.php' );

			foreach ( $asset_files as $asset_file ) {
				$asset = include( $asset_file );
				$handle = 'ielts-science-' . basename( $asset_file, '.asset.php' );
				$src = plugin_dir_url( __FILE__ ) . '../../public/writing/build/' . basename( $asset_file, '.asset.php' ) . '.js';
				$deps = $asset['dependencies'];
				$ver = $asset['version'];

				wp_enqueue_script( $handle, $src, $deps, $ver, true );

				$css_file = str_replace( '.asset.php', '.css', $asset_file );
				if ( file_exists( $css_file ) ) {
					$css_handle = $handle . '-css';
					$css_src = plugin_dir_url( __FILE__ ) . '../../public/writing/build/' . basename( $css_file );
					wp_enqueue_style( $css_handle, $css_src, [], $ver );
				}
			}
		}
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