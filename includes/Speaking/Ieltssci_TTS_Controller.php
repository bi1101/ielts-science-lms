<?php
/**
 * REST API Controller for Text-to-Speech (TTS) API
 *
 * Handles text-to-speech synthesis and saves the audio as WordPress attachments.
 *
 * @package IELTS_Science_LMS
 * @subpackage Speaking
 */

namespace IeltsScienceLMS\Speaking;

use WP_REST_Attachments_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Post;
use WP_Error;
use IeltsScienceLMS\API\Ieltssci_API_Client;
use IeltsScienceLMS\API\Ieltssci_Message_Handler;

/**
 * Class Ieltssci_TTS_Controller
 *
 * REST API controller for text-to-speech synthesis.
 *
 * @since 1.0.0
 */
class Ieltssci_TTS_Controller extends WP_REST_Attachments_Controller {
	/**
	 * Namespace for REST routes.
	 *
	 * @var string
	 */
	protected $namespace = 'ieltssci/v1';

	/**
	 * Resource name.
	 *
	 * @var string
	 */
	protected $rest_base = 'tts';

	/**
	 * API Client.
	 *
	 * @var Ieltssci_API_Client
	 */
	protected $api_client;

	/**
	 * Constructor.
	 *
	 * Initializes the controller and registers routes.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Call parent constructor with 'attachment' post type.
		parent::__construct( 'attachment' );

		// Override namespace and rest_base.
		$this->namespace = 'ieltssci/v1';
		$this->rest_base = 'tts';

		// Initialize API client with message handler (no-op callback for non-streaming endpoint).
		$message_handler  = new Ieltssci_Message_Handler(
			function ( $event_type, $data, $is_error, $is_done ) {
				// No-op callback for REST endpoint.
			}
		);
		$this->api_client = new Ieltssci_API_Client( $message_handler );

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * Registers the TTS synthesis endpoint.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'synthesize_speech' ),
					'permission_callback' => array( $this, 'tts_permissions_check' ),
					'args'                => $this->get_tts_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Check permissions for TTS endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool|WP_Error True if allowed, WP_Error if not.
	 */
	public function tts_permissions_check( $request ) {
		// Only logged-in users can use the TTS API.
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				'You must be logged in to use the text-to-speech API.',
				array( 'status' => 401 )
			);
		}

		// Check if user can upload files.
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'rest_cannot_upload',
				'Sorry, you are not allowed to upload files.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Synthesize speech from text.
	 *
	 * Converts text to speech audio and saves it as a WordPress attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function synthesize_speech( WP_REST_Request $request ) {
		$input           = $request->get_param( 'input' );
		$model           = $request->get_param( 'model' );
		$voice           = $request->get_param( 'voice' );
		$response_format = $request->get_param( 'response_format' );
		$speed           = $request->get_param( 'speed' );

		// Validate input parameter.
		if ( empty( $input ) ) {
			return new WP_Error(
				'missing_input',
				'Input text parameter is required.',
				array( 'status' => 400 )
			);
		}

		// Check if an attachment with the same TTS metadata already exists.
		$existing_attachment_id = $this->find_existing_tts_attachment( $input, $model, $voice, $speed );

		if ( $existing_attachment_id ) {
			// Return the existing attachment.
			$attachment = get_post( $existing_attachment_id );

			if ( $attachment ) {
				$request->set_param( 'context', 'edit' );
				$response = parent::prepare_item_for_response( $attachment, $request );

				// Add custom TTS metadata to the response.
				$data               = $response->get_data();
				$data['tts_meta']   = array(
					'input' => get_post_meta( $attachment->ID, '_ieltssci_tts_input', true ),
					'model' => get_post_meta( $attachment->ID, '_ieltssci_tts_model', true ),
					'voice' => get_post_meta( $attachment->ID, '_ieltssci_tts_voice', true ),
					'speed' => get_post_meta( $attachment->ID, '_ieltssci_tts_speed', true ),
				);
				$data['from_cache'] = true;
				$response->set_data( $data );

				$response->set_status( 200 );

				return $response;
			}
		}

		// Call the TTS API.
		$audio_data = $this->api_client->make_tts_api_call( $input, $model, $voice, $response_format, $speed );

		// Check for errors.
		if ( is_wp_error( $audio_data ) ) {
			return new WP_Error(
				'tts_api_error',
				$audio_data->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Save the audio file to WordPress media library.
		$attachment_id = $this->save_audio_to_library( $audio_data, $input, $model, $voice, $response_format, $speed );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Get the attachment post.
		$attachment = get_post( $attachment_id );

		if ( ! $attachment ) {
			return new WP_Error(
				'attachment_error',
				'Failed to retrieve attachment after creation.',
				array( 'status' => 500 )
			);
		}

		// Use parent's prepare_item_for_response method.
		$request->set_param( 'context', 'edit' );
		$response = parent::prepare_item_for_response( $attachment, $request );

		// Add custom TTS metadata to the response.
		$data             = $response->get_data();
		$data['tts_meta'] = array(
			'input' => get_post_meta( $attachment->ID, '_ieltssci_tts_input', true ),
			'model' => get_post_meta( $attachment->ID, '_ieltssci_tts_model', true ),
			'voice' => get_post_meta( $attachment->ID, '_ieltssci_tts_voice', true ),
			'speed' => get_post_meta( $attachment->ID, '_ieltssci_tts_speed', true ),
		);
		$response->set_data( $data );

		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', 'wp/v2', 'media', $attachment_id ) ) );

		/**
		 * Fires after a TTS audio file is created via REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_Post         $attachment The created attachment post.
		 * @param WP_REST_Request $request    Request used to generate the audio.
		 */
		do_action( 'ieltssci_rest_create_tts_audio', $attachment, $request );

		return $response;
	}

	/**
	 * Save audio data to WordPress media library.
	 *
	 * @since 1.0.0
	 *
	 * @param string $audio_data Binary audio data.
	 * @param string $input The input text.
	 * @param string $model The TTS model used.
	 * @param string $voice The voice used.
	 * @param string $response_format Audio format.
	 * @param float  $speed Speech speed.
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	protected function save_audio_to_library( $audio_data, $input, $model, $voice, $response_format, $speed ) {
		// Include required WordPress files.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Generate a filename.
		$filename = 'tts-' . sanitize_title( substr( $input, 0, 50 ) ) . '-' . time() . '.' . $response_format;

		// Get upload directory.
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error(
				'upload_dir_error',
				$upload_dir['error'],
				array( 'status' => 500 )
			);
		}

		// Create a temporary file.
		$tmp_file = $upload_dir['path'] . '/' . wp_unique_filename( $upload_dir['path'], $filename );

		// Write audio data to file.
		$bytes_written = file_put_contents( $tmp_file, $audio_data );

		if ( false === $bytes_written ) {
			return new WP_Error(
				'file_write_error',
				'Failed to write audio file to disk.',
				array( 'status' => 500 )
			);
		}

		// Determine MIME type.
		$mime_type = 'audio/' . $response_format;
		if ( 'mp3' === $response_format ) {
			$mime_type = 'audio/mpeg';
		}

		// Prepare attachment data.
		$attachment_data = array(
			'post_mime_type' => $mime_type,
			'post_title'     => sanitize_text_field( substr( $input, 0, 100 ) ),
			'post_content'   => '',
			'post_excerpt'   => sprintf( 'TTS audio generated with model: %s, voice: %s', $model, $voice ),
			'post_status'    => 'inherit',
		);

		// Insert attachment.
		$attachment_id = wp_insert_attachment( $attachment_data, $tmp_file, 0, true );

		if ( is_wp_error( $attachment_id ) ) {
			// Clean up the file if attachment creation failed.
			if ( file_exists( $tmp_file ) ) {
				unlink( $tmp_file );
			}
			return $attachment_id;
		}

		// Generate and update attachment metadata.
		$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $tmp_file );
		wp_update_attachment_metadata( $attachment_id, $attachment_metadata );

		// Add custom meta data for TTS parameters.
		update_post_meta( $attachment_id, '_ieltssci_tts_input', $input );
		update_post_meta( $attachment_id, '_ieltssci_tts_model', $model );
		update_post_meta( $attachment_id, '_ieltssci_tts_voice', $voice );
		update_post_meta( $attachment_id, '_ieltssci_tts_speed', $speed );

		return $attachment_id;
	}

	/**
	 * Find an existing TTS attachment with matching metadata.
	 *
	 * Searches for an attachment that was generated with the same TTS parameters.
	 *
	 * @since 1.0.0
	 *
	 * @param string $input The input text.
	 * @param string $model The TTS model.
	 * @param string $voice The voice.
	 * @param float  $speed The speech speed.
	 * @return int|false Attachment ID if found, false otherwise.
	 */
	protected function find_existing_tts_attachment( $input, $model, $voice, $speed ) {
		global $wpdb;

		// Query for attachments with matching TTS metadata.
		$query = $wpdb->prepare(
			"SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_ieltssci_tts_input'
			INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_ieltssci_tts_model'
			INNER JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_ieltssci_tts_voice'
			INNER JOIN {$wpdb->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = '_ieltssci_tts_speed'
			WHERE p.post_type = 'attachment'
			AND p.post_status = 'inherit'
			AND pm1.meta_value = %s
			AND pm2.meta_value = %s
			AND pm3.meta_value = %s
			AND pm4.meta_value = %s
			LIMIT 1",
			$input,
			$model,
			$voice,
			$speed
		);

		$attachment_id = $wpdb->get_var( $query );

		if ( $attachment_id ) {
			// Verify the attachment file still exists.
			$file_path = get_attached_file( $attachment_id );
			if ( $file_path && file_exists( $file_path ) ) {
				return (int) $attachment_id;
			} else {
				// File doesn't exist, delete the attachment record.
				wp_delete_attachment( $attachment_id, true );
				return false;
			}
		}

		return false;
	}

	/**
	 * Prepare a single attachment for the REST API response.
	 *
	 * Extends parent method to add TTS metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post         $item    Attachment post object.
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $item, $request ) {
		// Get parent response.
		$response = parent::prepare_item_for_response( $item, $request );

		// Add TTS metadata if this attachment has TTS meta.
		$tts_input = get_post_meta( $item->ID, '_ieltssci_tts_input', true );
		if ( ! empty( $tts_input ) ) {
			$data             = $response->get_data();
			$data['tts_meta'] = array(
				'input' => $tts_input,
				'model' => get_post_meta( $item->ID, '_ieltssci_tts_model', true ),
				'voice' => get_post_meta( $item->ID, '_ieltssci_tts_voice', true ),
				'speed' => get_post_meta( $item->ID, '_ieltssci_tts_speed', true ),
			);
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Get the attachment schema, conforming to JSON Schema.
	 *
	 * Extends parent schema to add TTS metadata.
	 *
	 * @since 1.0.0
	 *
	 * @return array Item schema as an array.
	 */
	public function get_item_schema() {
		$schema = parent::get_item_schema();

		// Add TTS metadata schema.
		$schema['properties']['tts_meta'] = array(
			'description' => 'TTS synthesis metadata.',
			'type'        => 'object',
			'context'     => array( 'view', 'edit' ),
			'readonly'    => true,
			'properties'  => array(
				'input' => array(
					'description' => 'The input text used for synthesis.',
					'type'        => 'string',
				),
				'model' => array(
					'description' => 'The TTS model used.',
					'type'        => 'string',
				),
				'voice' => array(
					'description' => 'The voice used for synthesis.',
					'type'        => 'string',
				),
				'speed' => array(
					'description' => 'The speech speed used.',
					'type'        => 'number',
				),
			),
		);

		return $schema;
	}

	/**
	 * Get the query parameters for TTS endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return array Query parameters.
	 */
	public function get_tts_params() {
		return array(
			'input'           => array(
				'description'       => 'The text to convert to speech.',
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'model'           => array(
				'description'       => 'The TTS model to use.',
				'type'              => 'string',
				'default'           => 'kokoro',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'voice'           => array(
				'description'       => 'The voice to use for synthesis.',
				'type'              => 'string',
				'default'           => 'af_heart',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'response_format' => array(
				'description'       => 'The audio format for the output.',
				'type'              => 'string',
				'default'           => 'mp3',
				'enum'              => array( 'mp3', 'wav', 'opus', 'flac' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'speed'           => array(
				'description' => 'The speed of the synthesized speech (0.25 to 4.0).',
				'type'        => 'number',
				'default'     => 1.0,
				'minimum'     => 0.25,
				'maximum'     => 4.0,
			),
		);
	}
}
