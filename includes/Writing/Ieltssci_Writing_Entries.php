<?php
/**
 * IELTS Science Writing Entries Management.
 *
 * This file contains the implementation of the Writing entries management functionality.
 *
 * @package IeltsScienceLMS\Writing
 */

namespace IeltsScienceLMS\Writing;

/**
 * Class Ieltssci_Writing_Entries
 *
 * Handles the entries management for the IELTS Science Writing Module.
 * Registers tabs on the entries page and displays writing submissions data.
 */
class Ieltssci_Writing_Entries {
	/**
	 * Constructor for the Ieltssci_Writing_Entries class.
	 *
	 * Initializes the writing entries functionality by setting up hooks.
	 */
	public function __construct() {
		// Register the writing tab on the entries page.
		add_filter( 'ieltssci_entries_tabs', array( $this, 'register_writing_entries_tab' ) );

		// Add hook for enqueueing assets specific to the writing entries tab.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_entries_assets' ) );
	}

	/**
	 * Register the Writing entries tab on the entries page.
	 *
	 * @param array $tabs Existing tabs array.
	 * @return array Modified tabs array with writing tab added.
	 */
	public function register_writing_entries_tab( $tabs ) {
		$tabs['writing_entries'] = array(
			'title'    => __( 'Writing Submissions', 'ielts-science-lms' ),
			'callback' => array( $this, 'render_writing_entries_tab' ),
			'priority' => 10, // Standard priority.
		);

		return $tabs;
	}

	/**
	 * Render the Writing entries tab content.
	 *
	 * This is a placeholder that will be replaced with a React component later.
	 */
	public function render_writing_entries_tab() {
		?>
		<div class="wrap">
			<!-- React app mount point -->
			<div id="ieltssci-entries-app" data-module="writing">
				<div class="ieltssci-placeholder">
					<p><?php esc_html_e( 'Loading writing submissions...', 'ielts-science-lms' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue assets for the writing entries tab.
	 *
	 * Loads scripts and styles specifically for the writing entries tab.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_entries_assets( $hook ) {
		if ( 'ielts-science-lms_page_ielts-science-lms-entries' !== $hook ) {
			return;
		}

		// Get the current tab from URL.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'writing_entries';

		// Only enqueue assets for the writing entries tab.
		if ( 'writing_entries' !== $current_tab && empty( $current_tab ) ) {
			return;
		}

		// Verify nonce for tab switching if it exists.
		if ( isset( $_GET['_wpnonce'] ) && ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ielts_entries_tab_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ielts-science-lms' ) );
		}

		// Get the appropriate script handle for entries.
		$script_handle  = 'ielts-science-wp-admin-entries';
		$style_handle   = "{$script_handle}-css";
		$runtime_handle = 'ielts-science-wp-admin-runtime';

		// Enqueue the runtime script if it's registered.
		if ( wp_script_is( $runtime_handle, 'registered' ) ) {
			wp_enqueue_script( $runtime_handle );
		}

		// Enqueue the entries script if it's registered.
		if ( wp_script_is( $script_handle, 'registered' ) ) {
			wp_enqueue_script( $script_handle );

			// Localize script with data for the writing entries application.
			wp_localize_script(
				$script_handle,
				'ieltssciEntries',
				array(
					'apiRoot' => esc_url_raw( rest_url() ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'module'  => 'writing', // Specify the current module.
				)
			);
		}

		// Enqueue the style if it's registered.
		if ( wp_style_is( $style_handle, 'registered' ) ) {
			wp_enqueue_style( $style_handle );
		}

		// Add WordPress admin component styles.
		wp_enqueue_style( 'wp-components' );

		// Add basic styling for the placeholder.
		?>
		<style>
			.ieltssci-placeholder {
				padding: 40px;
				background: #f9f9f9;
				border: 1px solid #e5e5e5;
				text-align: center;
				border-radius: 4px;
				margin-top: 20px;
			}
			.ieltssci-placeholder p {
				font-size: 16px;
				color: #72777c;
			}
		</style>
		<?php
	}
}
