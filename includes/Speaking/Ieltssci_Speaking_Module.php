<?php
/**
 * IELTS Science Speaking Module
 *
 * This file contains the implementation of the Speaking Module for IELTS Science LMS.
 *
 * @package IeltsScienceLMS\Speaking
 */

namespace IeltsScienceLMS\Speaking;

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

		// Register post meta for audio transcription.
		add_action( 'init', array( $this, 'register_audio_transcription_meta' ) );

		// Add meta box for audio transcription on attachment edit screen.
		add_action( 'add_meta_boxes', array( $this, 'add_audio_transcription_meta_box' ) );

		add_filter( 'ieltssci_lms_module_pages_data', array( $this, 'provide_module_pages_data' ) );

		// Add scripts for JSON viewer in admin.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_json_viewer_scripts' ) );
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
				'speaking_practice' => __( 'IELTS Science Speaking', 'ielts-science-lms' ),
				'speaking_result'   => __( 'Speaking Results', 'ielts-science-lms' ),
				'speaking_history'  => __( 'Speaking History', 'ielts-science-lms' ),
			),
		);

		return $module_data;
	}
}
