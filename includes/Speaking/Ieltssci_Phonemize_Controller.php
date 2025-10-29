<?php
/**
 * REST API Controller for Phonemize API
 *
 * Handles phonemization of text to phonetic representations.
 *
 * @package IELTS_Science_LMS
 * @subpackage Speaking
 */

namespace IeltsScienceLMS\Speaking;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use IeltsScienceLMS\API\Ieltssci_API_Client;
use IeltsScienceLMS\API\Ieltssci_Message_Handler;

/**
 * Class Ieltssci_Phonemize_Controller
 *
 * REST API controller for phonemization.
 *
 * @since 1.0.0
 */
class Ieltssci_Phonemize_Controller extends WP_REST_Controller {
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
	protected $rest_base = 'phonemize';

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
	 * Registers the phonemize endpoint.
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
					'callback'            => array( $this, 'phonemize_text' ),
					'permission_callback' => array( $this, 'phonemize_permissions_check' ),
					'args'                => $this->get_phonemize_params(),
				),
				'schema' => array( $this, 'get_phonemize_schema' ),
			)
		);
	}

	/**
	 * Check permissions for phonemize endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool|WP_Error True if allowed, WP_Error if not.
	 */
	public function phonemize_permissions_check( $request ) {
		// Only logged-in users can use the phonemize API.
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				'You must be logged in to use the phonemize API.',
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Phonemize text.
	 *
	 * Converts text to phonetic representation.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function phonemize_text( WP_REST_Request $request ) {
		$text     = $request->get_param( 'text' );
		$language = $request->get_param( 'language' );

		// Validate text parameter.
		if ( empty( $text ) ) {
			return new WP_Error(
				'missing_text',
				'Text parameter is required.',
				array( 'status' => 400 )
			);
		}

		// Call the phonemize API.
		$result = $this->api_client->make_phonemize_api_call( $text, $language );

		// Check for errors.
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'phonemize_api_error',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Prepare response data.
		$response_data = array(
			'text'     => $text,
			'language' => $language,
			'phonemes' => isset( $result['phonemes'] ) ? $result['phonemes'] : '',
			'tokens'   => isset( $result['tokens'] ) ? $result['tokens'] : array(),
		);

		// Create response.
		$response = rest_ensure_response( $response_data );

		/**
		 * Filter phonemize data returned from the REST API.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_REST_Response $response The response object.
		 * @param array            $result   Raw phonemize result data.
		 * @param WP_REST_Request  $request  Request used to generate the response.
		 */
		return apply_filters( 'ieltssci_rest_phonemize_response', $response, $result, $request );
	}

	/**
	 * Get the query parameters for phonemize endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return array Query parameters.
	 */
	public function get_phonemize_params() {
		return array(
			'text'     => array(
				'description'       => 'The text to convert to phonemes.',
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'language' => array(
				'description'       => 'The language code for phonemization.',
				'type'              => 'string',
				'default'           => 'a',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Get the phonemize schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Schema array.
	 */
	public function get_phonemize_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'phonemize',
			'type'       => 'object',
			'properties' => array(
				'text'     => array(
					'description' => 'The original text that was phonemized.',
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'language' => array(
					'description' => 'The language code used for phonemization.',
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'phonemes' => array(
					'description' => 'The phonetic representation of the text.',
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'tokens'   => array(
					'description' => 'Tokenized representation (if available).',
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);
	}
}
