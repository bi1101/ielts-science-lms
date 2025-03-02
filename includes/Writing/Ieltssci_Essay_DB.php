<?php

namespace IeltsScienceLMS\Writing;

use WP_Error;
use wpdb;

class Ieltssci_Essay_DB {
	private const TABLE_PREFIX = 'ieltssci_';
	private wpdb $wpdb;
	private string $essays_table;
	private string $segment_table;
	private string $segment_feedback_table;
	private string $essay_feedback_table;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;

		$this->essays_table = $this->wpdb->prefix . self::TABLE_PREFIX . 'essays';
		$this->segment_table = $this->wpdb->prefix . self::TABLE_PREFIX . 'segment';
		$this->segment_feedback_table = $this->wpdb->prefix . self::TABLE_PREFIX . 'segment_feedback';
		$this->essay_feedback_table = $this->wpdb->prefix . self::TABLE_PREFIX . 'essay_feedback';
	}

	/**
	 * Create a new essay
	 *
	 * @param array $essay_data Essay data
	 * @return array|WP_Error Created essay data or error
	 */
	public function create_essay( $essay_data ) {
		if ( empty( $essay_data['essay_type'] ) || empty( $essay_data['question'] ) || empty( $essay_data['essay_content'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields', [ 'status' => 400 ] );
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Generate UUID if not provided
			$uuid = ! empty( $essay_data['uuid'] ) ? $essay_data['uuid'] : wp_generate_uuid4();

			$ocr_image_ids = ! empty( $essay_data['ocr_image_ids'] ) ? json_encode( $essay_data['ocr_image_ids'] ) : null;
			$chart_image_ids = ! empty( $essay_data['chart_image_ids'] ) ? json_encode( $essay_data['chart_image_ids'] ) : null;

			$insert_data = [ 
				'uuid' => $uuid,
				'original_id' => $essay_data['original_id'] ?? null,
				'ocr_image_ids' => $ocr_image_ids,
				'chart_image_ids' => $chart_image_ids,
				'essay_type' => $essay_data['essay_type'],
				'question' => $essay_data['question'],
				'essay_content' => $essay_data['essay_content'],
				'created_by' => $essay_data['created_by'],
			];

			$result = $this->wpdb->insert(
				$this->essays_table,
				$insert_data,
				[ 
					'%s', // uuid
					'%d', // original_id
					'%s', // ocr_image_ids
					'%s', // chart_image_ids
					'%s', // essay_type
					'%s', // question
					'%s', // essay_content
					'%d', // created_by
				]
			);

			if ( $result === false ) {
				throw new \Exception( 'Failed to create essay: ' . $this->wpdb->last_error );
			}

			$essay_id = $this->wpdb->insert_id;

			// Create basic segments
			$this->create_basic_segments( $essay_id, $essay_data['essay_content'] );

			// Get the created essay
			$essay = $this->get_essay_by_id( $essay_id );

			$this->wpdb->query( 'COMMIT' );
			return $essay;
		} catch (\Exception $e) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * Update an existing essay
	 *
	 * @param int $essay_id Essay ID
	 * @param array $essay_data New essay data
	 * @return array|WP_Error Updated essay data or error
	 */
	public function update_essay( $essay_id, $essay_data ) {
		if ( empty( $essay_id ) ) {
			return new WP_Error( 'missing_id', 'Essay ID is required', [ 'status' => 400 ] );
		}

		// Check if essay exists
		$existing_essay = $this->get_essay_by_id( $essay_id );
		if ( is_wp_error( $existing_essay ) ) {
			return $existing_essay;
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			$update_data = [];
			$update_format = [];

			// Only update fields that are provided
			if ( isset( $essay_data['essay_type'] ) ) {
				$update_data['essay_type'] = $essay_data['essay_type'];
				$update_format[] = '%s';
			}

			if ( isset( $essay_data['question'] ) ) {
				$update_data['question'] = $essay_data['question'];
				$update_format[] = '%s';
			}

			if ( isset( $essay_data['essay_content'] ) ) {
				$update_data['essay_content'] = $essay_data['essay_content'];
				$update_format[] = '%s';
			}

			if ( isset( $essay_data['ocr_image_ids'] ) ) {
				$update_data['ocr_image_ids'] = json_encode( $essay_data['ocr_image_ids'] );
				$update_format[] = '%s';
			}

			if ( isset( $essay_data['chart_image_ids'] ) ) {
				$update_data['chart_image_ids'] = json_encode( $essay_data['chart_image_ids'] );
				$update_format[] = '%s';
			}

			if ( ! empty( $update_data ) ) {
				$result = $this->wpdb->update(
					$this->essays_table,
					$update_data,
					[ 'id' => $essay_id ],
					$update_format,
					[ '%d' ]
				);

				if ( $result === false ) {
					throw new \Exception( 'Failed to update essay: ' . $this->wpdb->last_error );
				}

				// Update segments if essay content changed
				if ( isset( $essay_data['essay_content'] ) ) {
					// First, delete existing segments
					$this->wpdb->delete(
						$this->segment_table,
						[ 'essay_id' => $essay_id ],
						[ '%d' ]
					);

					// Create new segments
					$this->create_basic_segments( $essay_id, $essay_data['essay_content'] );
				}
			}

			// Get the updated essay
			$essay = $this->get_essay_by_id( $essay_id );

			$this->wpdb->query( 'COMMIT' );
			return $essay;
		} catch (\Exception $e) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * Create basic segments for an essay
	 * 
	 * @param int $essay_id Essay ID
	 * @param string $content Essay content
	 * @return bool Success status
	 */
	private function create_basic_segments( $essay_id, $content ) {
		// For now, we'll create just one segment containing the entire essay
		// This can be enhanced later to parse the essay into proper segments
		$result = $this->wpdb->insert(
			$this->segment_table,
			[ 
				'essay_id' => $essay_id,
				'type' => 'MAIN_POINT', // Default type
				'order' => 1,
				'title' => 'Main Content',
				'content' => $content,
			],
			[ 
				'%d', // essay_id
				'%s', // type
				'%d', // order
				'%s', // title
				'%s', // content
			]
		);

		if ( $result === false ) {
			throw new \Exception( 'Failed to create segment: ' . $this->wpdb->last_error );
		}

		return true;
	}

	/**
	 * Get an essay by ID
	 * 
	 * @param int $essay_id Essay ID
	 * @return array|WP_Error Essay data or error
	 */
	public function get_essay_by_id( $essay_id ) {
		$essay = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->essays_table} WHERE id = %d",
				$essay_id
			),
			ARRAY_A
		);

		if ( ! $essay ) {
			return new WP_Error( 'not_found', 'Essay not found', [ 'status' => 404 ] );
		}

		// Process array fields
		if ( ! empty( $essay['ocr_image_ids'] ) ) {
			$essay['ocr_image_ids'] = json_decode( $essay['ocr_image_ids'], true );
		} else {
			$essay['ocr_image_ids'] = [];
		}

		if ( ! empty( $essay['chart_image_ids'] ) ) {
			$essay['chart_image_ids'] = json_decode( $essay['chart_image_ids'], true );
		} else {
			$essay['chart_image_ids'] = [];
		}

		// Get segments
		$essay['segments'] = $this->get_segments_by_essay_id( $essay_id );

		// Get feedback
		$essay['feedback'] = $this->get_essay_feedback( $essay_id );

		return $essay;
	}

	/**
	 * Get an essay by UUID
	 *
	 * @param string $uuid Essay UUID
	 * @return array|WP_Error Essay data or error
	 */
	public function get_essay_by_uuid( $uuid ) {
		$essay = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->essays_table} WHERE uuid = %s",
				$uuid
			),
			ARRAY_A
		);

		if ( ! $essay ) {
			return new WP_Error( 'not_found', 'Essay not found', [ 'status' => 404 ] );
		}

		return $this->get_essay_by_id( $essay['id'] );
	}

	/**
	 * Get segments by essay ID
	 *
	 * @param int $essay_id Essay ID
	 * @return array Array of segments with their feedback
	 */
	public function get_segments_by_essay_id( $essay_id ) {
		$segments = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->segment_table} WHERE essay_id = %d ORDER BY `order` ASC",
				$essay_id
			),
			ARRAY_A
		);

		if ( ! $segments ) {
			return [];
		}

		// Get feedback for each segment
		foreach ( $segments as &$segment ) {
			$segment['feedback'] = $this->get_segment_feedback( $segment['id'] );
		}

		return $segments;
	}

	/**
	 * Get feedback for a segment
	 *
	 * @param int $segment_id Segment ID
	 * @return array Array of feedback
	 */
	public function get_segment_feedback( $segment_id ) {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->segment_feedback_table} WHERE segment_id = %d ORDER BY created_at DESC",
				$segment_id
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Get feedback for an essay
	 *
	 * @param int $essay_id Essay ID
	 * @return array Array of feedback
	 */
	public function get_essay_feedback( $essay_id ) {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->essay_feedback_table} WHERE essay_id = %d ORDER BY created_at DESC",
				$essay_id
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Get essays with pagination
	 *
	 * @param array $args Query arguments
	 * @return array|WP_Error Essays data or error
	 */
	public function get_essays( $args = [] ) {
		$defaults = [ 
			'page' => 1,
			'per_page' => 10,
			'essay_type' => '',
			'user_id' => 0,
		];

		$args = wp_parse_args( $args, $defaults );

		try {
			$where = [];
			$where_format = [];

			// Filter by user if provided
			if ( ! empty( $args['user_id'] ) ) {
				$where[] = 'created_by = %d';
				$where_format[] = $args['user_id'];
			}

			// Filter by essay type if provided
			if ( ! empty( $args['essay_type'] ) ) {
				$where[] = 'essay_type = %s';
				$where_format[] = $args['essay_type'];
			}

			// Build WHERE clause
			$where_clause = '';
			if ( ! empty( $where ) ) {
				$where_clause = 'WHERE ' . implode( ' AND ', $where );
			}

			// Count total
			$count_query = "SELECT COUNT(*) FROM {$this->essays_table} $where_clause";
			if ( ! empty( $where_format ) ) {
				$count_query = $this->wpdb->prepare( $count_query, $where_format );
			}

			$total = (int) $this->wpdb->get_var( $count_query );

			// Pagination
			$offset = ( $args['page'] - 1 ) * $args['per_page'];

			// Get essays
			$query = "SELECT * FROM {$this->essays_table} $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
			$query_args = $where_format;
			$query_args[] = (int) $args['per_page'];
			$query_args[] = (int) $offset;

			$essays = $this->wpdb->get_results(
				$this->wpdb->prepare( $query, $query_args ),
				ARRAY_A
			);

			// Process array fields for each essay
			if ( $essays ) {
				foreach ( $essays as &$essay ) {
					if ( ! empty( $essay['ocr_image_ids'] ) ) {
						$essay['ocr_image_ids'] = json_decode( $essay['ocr_image_ids'], true );
					} else {
						$essay['ocr_image_ids'] = [];
					}

					if ( ! empty( $essay['chart_image_ids'] ) ) {
						$essay['chart_image_ids'] = json_decode( $essay['chart_image_ids'], true );
					} else {
						$essay['chart_image_ids'] = [];
					}
				}
			}

			return [ 
				'essays' => $essays ?: [],
				'total' => $total,
				'pages' => ceil( $total / $args['per_page'] ),
				'page' => (int) $args['page'],
				'per_page' => (int) $args['per_page'],
			];
		} catch (\Exception $e) {
			return new WP_Error( 'db_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * Add feedback to an essay
	 *
	 * @param array $feedback_data Feedback data
	 * @return array|WP_Error Created feedback data or error
	 */
	public function add_essay_feedback( $feedback_data ) {
		if ( empty( $feedback_data['essay_id'] ) || empty( $feedback_data['feedback_criteria'] ) ||
			empty( $feedback_data['feedback_language'] ) || empty( $feedback_data['source'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields', [ 'status' => 400 ] );
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			$result = $this->wpdb->insert(
				$this->essay_feedback_table,
				[ 
					'essay_id' => $feedback_data['essay_id'],
					'feedback_criteria' => $feedback_data['feedback_criteria'],
					'feedback_language' => $feedback_data['feedback_language'],
					'source' => $feedback_data['source'],
					'cot_content' => $feedback_data['cot_content'] ?? null,
					'score_content' => $feedback_data['score_content'] ?? null,
					'feedback_content' => $feedback_data['feedback_content'] ?? null,
					'created_by' => $feedback_data['created_by'],
				],
				[ 
					'%d', // essay_id
					'%s', // feedback_criteria
					'%s', // feedback_language
					'%s', // source
					'%s', // cot_content
					'%s', // score_content
					'%s', // feedback_content
					'%d', // created_by
				]
			);

			if ( $result === false ) {
				throw new \Exception( 'Failed to add feedback: ' . $this->wpdb->last_error );
			}

			$feedback_id = $this->wpdb->insert_id;

			$feedback = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->essay_feedback_table} WHERE id = %d",
					$feedback_id
				),
				ARRAY_A
			);

			$this->wpdb->query( 'COMMIT' );
			return $feedback;
		} catch (\Exception $e) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * Add feedback to a segment
	 *
	 * @param array $feedback_data Feedback data
	 * @return array|WP_Error Created feedback data or error
	 */
	public function add_segment_feedback( $feedback_data ) {
		if ( empty( $feedback_data['segment_id'] ) || empty( $feedback_data['feedback_criteria'] ) ||
			empty( $feedback_data['feedback_language'] ) || empty( $feedback_data['source'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields', [ 'status' => 400 ] );
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			$result = $this->wpdb->insert(
				$this->segment_feedback_table,
				[ 
					'segment_id' => $feedback_data['segment_id'],
					'feedback_criteria' => $feedback_data['feedback_criteria'],
					'feedback_language' => $feedback_data['feedback_language'],
					'source' => $feedback_data['source'],
					'cot_content' => $feedback_data['cot_content'] ?? null,
					'score_content' => $feedback_data['score_content'] ?? null,
					'feedback_content' => $feedback_data['feedback_content'] ?? null,
					'created_by' => $feedback_data['created_by'],
				],
				[ 
					'%d', // segment_id
					'%s', // feedback_criteria
					'%s', // feedback_language
					'%s', // source
					'%s', // cot_content
					'%s', // score_content
					'%s', // feedback_content
					'%d', // created_by
				]
			);

			if ( $result === false ) {
				throw new \Exception( 'Failed to add segment feedback: ' . $this->wpdb->last_error );
			}

			$feedback_id = $this->wpdb->insert_id;

			$feedback = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->segment_feedback_table} WHERE id = %d",
					$feedback_id
				),
				ARRAY_A
			);

			$this->wpdb->query( 'COMMIT' );
			return $feedback;
		} catch (\Exception $e) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}
}
