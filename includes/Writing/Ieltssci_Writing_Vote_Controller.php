<?php
/**
 * IELTS Science LMS - Writing Vote REST API
 *
 * REST controller for writing vote endpoint.
 *
 * @package IeltsScienceLMS
 * @subpackage Writing
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Writing;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_REST_Server;

/**
 * Class Ieltssci_Writing_Vote_Controller
 *
 * Handles Vote REST API endpoints for the IELTS Writing module.
 *
 * @package IeltsScienceLMS\Writing
 * @since 1.0.0
 */
class Ieltssci_Writing_Vote_Controller extends WP_REST_Controller {
	/**
	 * API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ieltssci/v1';

	/**
	 * API base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'writing/votes';

	/**
	 * Constructor.
	 *
	 * Initializes the REST API routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'args'                => $this->get_collection_params(),
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to read votes.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		// Anyone can get votes.
		return true;
	}

	/**
	 * Retrieves a collection of votes.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$cache_key = 'ielts_writing_votes_cache';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached ); // Return cached result if available.
		}

		$essay_db      = new \IeltsScienceLMS\Writing\Ieltssci_Essay_DB();
		$score_service = new \IeltsScienceLMS\Writing\Ieltssci_Writing_Score();

		// Fetch all confirmed essays.
		$essays = $essay_db->get_essays(
			array(
				'per_page'   => 9999,
				'meta_query' => array(
					'key'   => 'confirmed',
					'value' => '1',
				),
			)
		);

		$accurate_votes = array();
		$higher_votes   = array();
		$lower_votes    = array();

		if ( is_wp_error( $essays ) || empty( $essays ) ) {
			$result = array(
				'accurate_votes' => array(
					'count' => 0,
					'votes' => array(),
				),
				'higher_votes'   => array(
					'count' => 0,
					'votes' => array(),
				),
				'lower_votes'    => array(
					'count' => 0,
					'votes' => array(),
				),
			);
			set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS ); // Cache empty result for 10 minutes.
			return rest_ensure_response( $result );
		}

		foreach ( $essays as $essay ) {
			$final_score = $score_service->get_overall_score( $essay, 'final' );
			if ( ! $final_score || ! isset( $final_score['score'] ) || ! isset( $final_score['source'] ) ) {
				continue; // Skip if no valid final score.
			}

			if ( 'ai' === $final_score['source'] ) {
				// If final score is from AI, consider it accurate.
				$accurate_votes[] = array(
					'essay_id'      => $essay['id'],
					'question'      => isset( $essay['question'] ) ? $essay['question'] : null,
					'essay_content' => isset( $essay['essay_content'] ) ? $essay['essay_content'] : null,
					'created_at'    => isset( $essay['created_at'] ) ? $essay['created_at'] : null,
					'essay_type'    => isset( $essay['essay_type'] ) ? $essay['essay_type'] : null,
					'ai_score'      => $final_score['score'],
					'source'        => 'ai',
				);
				continue;
			}

			// If final score is from human, compare with initial AI score.
			$initial_score = $score_service->get_overall_score( $essay, 'initial' );
			if ( ! $initial_score || ! isset( $initial_score['score'] ) || 'ai' !== $initial_score['source'] ) {
				continue; // Skip if no valid initial AI score.
			}

			$diff = $initial_score['score'] - $final_score['score'];

			$vote_data = array(
				'essay_id'      => $essay['id'],
				'question'      => isset( $essay['question'] ) ? $essay['question'] : null,
				'essay_content' => isset( $essay['essay_content'] ) ? $essay['essay_content'] : null,
				'created_at'    => isset( $essay['created_at'] ) ? $essay['created_at'] : null,
				'essay_type'    => isset( $essay['essay_type'] ) ? $essay['essay_type'] : null,
				'human_score'   => $final_score['score'],
				'ai_score'      => $initial_score['score'],
				'diff'          => $diff,
			);

			if ( abs( $diff ) < 1.0 ) {
				$accurate_votes[] = $vote_data;
			} elseif ( $diff >= 1.0 ) {
				$higher_votes[] = $vote_data;
			} elseif ( $diff <= -1.0 ) {
				$lower_votes[] = $vote_data;
			}
		}

		$result = array(
			'accurate_votes' => array(
				'count' => count( $accurate_votes ),
				'votes' => $accurate_votes,
			),
			'higher_votes'   => array(
				'count' => count( $higher_votes ),
				'votes' => $higher_votes,
			),
			'lower_votes'    => array(
				'count' => count( $lower_votes ),
				'votes' => $lower_votes,
			),
		);

		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS ); // Cache for 5 minutes.
		return rest_ensure_response( $result );
	}

	/**
	 * Returns the collection parameters for the endpoint.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(); // No custom params yet.
	}

	/**
	 * Retrieves the schema for a vote response.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$schema       = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'writing_vote',
			'type'       => 'object',
			'properties' => array(
				'accurate_votes' => array(
					'description' => __( 'Votes where the AI and human scores are close or AI is the final score.', 'ielts-science-lms' ),
					'type'        => 'object',
					'properties'  => array(
						'count' => array(
							'description' => __( 'Number of accurate votes.', 'ielts-science-lms' ),
							'type'        => 'integer',
						),
						'votes' => array(
							'description' => __( 'List of accurate vote data.', 'ielts-science-lms' ),
							'type'        => 'array',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'essay_id'    => array( 'type' => 'integer' ),
									'essay_uuid'  => array( 'type' => array( 'string', 'null' ) ),
									'essay_type'  => array( 'type' => array( 'string', 'null' ) ),
									'ai_score'    => array( 'type' => array( 'number', 'null' ) ),
									'human_score' => array( 'type' => array( 'number', 'null' ) ),
									'diff'        => array( 'type' => array( 'number', 'null' ) ),
									'source'      => array( 'type' => array( 'string', 'null' ) ),
								),
							),
						),
					),
				),
				'higher_votes'   => array(
					'description' => __( 'Votes where the AI score is much higher than the human score.', 'ielts-science-lms' ),
					'type'        => 'object',
					'properties'  => array(
						'count' => array(
							'description' => __( 'Number of higher votes.', 'ielts-science-lms' ),
							'type'        => 'integer',
						),
						'votes' => array(
							'description' => __( 'List of higher vote data.', 'ielts-science-lms' ),
							'type'        => 'array',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'essay_id'    => array( 'type' => 'integer' ),
									'essay_uuid'  => array( 'type' => array( 'string', 'null' ) ),
									'essay_type'  => array( 'type' => array( 'string', 'null' ) ),
									'ai_score'    => array( 'type' => array( 'number', 'null' ) ),
									'human_score' => array( 'type' => array( 'number', 'null' ) ),
									'diff'        => array( 'type' => array( 'number', 'null' ) ),
								),
							),
						),
					),
				),
				'lower_votes'    => array(
					'description' => __( 'Votes where the AI score is much lower than the human score.', 'ielts-science-lms' ),
					'type'        => 'object',
					'properties'  => array(
						'count' => array(
							'description' => __( 'Number of lower votes.', 'ielts-science-lms' ),
							'type'        => 'integer',
						),
						'votes' => array(
							'description' => __( 'List of lower vote data.', 'ielts-science-lms' ),
							'type'        => 'array',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'essay_id'    => array( 'type' => 'integer' ),
									'essay_uuid'  => array( 'type' => array( 'string', 'null' ) ),
									'essay_type'  => array( 'type' => array( 'string', 'null' ) ),
									'ai_score'    => array( 'type' => array( 'number', 'null' ) ),
									'human_score' => array( 'type' => array( 'number', 'null' ) ),
									'diff'        => array( 'type' => array( 'number', 'null' ) ),
								),
							),
						),
					),
				),
			),
		);
		$this->schema = $schema;
		return $this->add_additional_fields_schema( $this->schema );
	}
}
