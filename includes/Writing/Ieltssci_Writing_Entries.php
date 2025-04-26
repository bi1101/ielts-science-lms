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
}
