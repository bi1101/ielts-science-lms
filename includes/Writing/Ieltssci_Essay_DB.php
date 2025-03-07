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

			// Get the created essay
			$essay = $this->get_essays( [ 'id' => $essay_id ] )[0];

			$this->wpdb->query( 'COMMIT' );
			return $essay;
		} catch (\Exception $e) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * Get essays with flexible query arguments
	 *
	 * @param array $args {
	 *     Optional. Arguments to retrieve essays.
	 *     @type int|array    $id           Essay ID or array of IDs.
	 *     @type string|array $uuid         Essay UUID or array of UUIDs.
	 *     @type int|array    $original_id  Original essay ID or array of IDs.
	 *     @type string|array $essay_type   Essay type or array of types.
	 *     @type int|array    $created_by   User ID or array of user IDs who created the essays.
	 *     @type string       $search       Search term to look for in question or content.
	 *     @type array        $date_query   Query by date parameters (similar to WP_Query).
	 *     @type string       $orderby      Field to order results by. Default 'id'.
	 *                                      Accepts 'id', 'uuid', 'essay_type', 'created_at', 'created_by'.
	 *     @type string       $order        Order direction. Default 'DESC'.
	 *                                      Accepts 'ASC', 'DESC'.
	 *     @type int          $per_page     Number of essays to return per page. Default 10.
	 *     @type int          $page         Page number. Default 1.
	 *     @type bool         $count        If true, return only the count. Default false.
	 * }
	 * @return array|int|WP_Error Essays data, count, or error
	 */
	public function get_essays( $args = [] ) {
		$defaults = [ 
			'id' => null,
			'uuid' => null,
			'original_id' => null,
			'essay_type' => null,
			'created_by' => null,
			'search' => null,
			'date_query' => null,
			'orderby' => 'id',
			'order' => 'DESC',
			'per_page' => 10,
			'page' => 1,
			'count' => false
		];

		$args = wp_parse_args( $args, $defaults );
		$select = $args['count'] ? "COUNT(*)" : "*";
		$from = $this->essays_table;
		$where = [ "1=1" ];
		$prepare_values = [];

		// Process ID filter
		if ( ! is_null( $args['id'] ) ) {
			if ( is_array( $args['id'] ) ) {
				$placeholders = array_fill( 0, count( $args['id'] ), '%d' );
				$where[] = "id IN (" . implode( ',', $placeholders ) . ")";
				$prepare_values = array_merge( $prepare_values, $args['id'] );
			} else {
				$where[] = "id = %d";
				$prepare_values[] = $args['id'];
			}
		}

		// Process UUID filter
		if ( ! is_null( $args['uuid'] ) ) {
			if ( is_array( $args['uuid'] ) ) {
				$placeholders = array_fill( 0, count( $args['uuid'] ), '%s' );
				$where[] = "uuid IN (" . implode( ',', $placeholders ) . ")";
				$prepare_values = array_merge( $prepare_values, $args['uuid'] );
			} else {
				$where[] = "uuid = %s";
				$prepare_values[] = $args['uuid'];
			}
		}

		// Process original_id filter
		if ( ! is_null( $args['original_id'] ) ) {
			if ( is_array( $args['original_id'] ) ) {
				$placeholders = array_fill( 0, count( $args['original_id'] ), '%d' );
				$where[] = "original_id IN (" . implode( ',', $placeholders ) . ")";
				$prepare_values = array_merge( $prepare_values, $args['original_id'] );
			} else {
				$where[] = "original_id = %d";
				$prepare_values[] = $args['original_id'];
			}
		}

		// Process essay_type filter
		if ( ! is_null( $args['essay_type'] ) ) {
			if ( is_array( $args['essay_type'] ) ) {
				$placeholders = array_fill( 0, count( $args['essay_type'] ), '%s' );
				$where[] = "essay_type IN (" . implode( ',', $placeholders ) . ")";
				$prepare_values = array_merge( $prepare_values, $args['essay_type'] );
			} else {
				$where[] = "essay_type = %s";
				$prepare_values[] = $args['essay_type'];
			}
		}

		// Process created_by filter
		if ( ! is_null( $args['created_by'] ) ) {
			if ( is_array( $args['created_by'] ) ) {
				$placeholders = array_fill( 0, count( $args['created_by'] ), '%d' );
				$where[] = "created_by IN (" . implode( ',', $placeholders ) . ")";
				$prepare_values = array_merge( $prepare_values, $args['created_by'] );
			} else {
				$where[] = "created_by = %d";
				$prepare_values[] = $args['created_by'];
			}
		}

		// Process search
		if ( ! is_null( $args['search'] ) ) {
			$where[] = "(question LIKE %s OR essay_content LIKE %s)";
			$search_term = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
			$prepare_values[] = $search_term;
			$prepare_values[] = $search_term;
		}

		// Process date query (simplified approach)
		if ( ! is_null( $args['date_query'] ) && is_array( $args['date_query'] ) ) {
			if ( ! empty( $args['date_query']['after'] ) ) {
				$where[] = "created_at >= %s";
				$prepare_values[] = $args['date_query']['after'];
			}
			if ( ! empty( $args['date_query']['before'] ) ) {
				$where[] = "created_at <= %s";
				$prepare_values[] = $args['date_query']['before'];
			}
		}

		// Build query
		$sql = "SELECT $select FROM $from WHERE " . implode( ' AND ', $where );

		// Add order if not counting
		if ( ! $args['count'] ) {
			// Sanitize orderby field
			$allowed_orderby = [ 'id', 'uuid', 'essay_type', 'created_at', 'created_by' ];
			$orderby = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'id';

			// Sanitize order direction
			$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

			$sql .= " ORDER BY $orderby $order";

			// Add pagination
			$per_page = max( 1, intval( $args['per_page'] ) );
			$page = max( 1, intval( $args['page'] ) );
			$offset = ( $page - 1 ) * $per_page;

			$sql .= " LIMIT %d OFFSET %d";
			$prepare_values[] = $per_page;
			$prepare_values[] = $offset;
		}

		// Prepare and execute query
		$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );

		if ( $args['count'] ) {
			return (int) $this->wpdb->get_var( $prepared_sql );
		} else {
			$results = $this->wpdb->get_results( $prepared_sql, ARRAY_A );

			// Process array fields for each result
			foreach ( $results as &$essay ) {
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

			return $results;
		}
	}

}
