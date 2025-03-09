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

		$this->essays_table           = $this->wpdb->prefix . self::TABLE_PREFIX . 'essays';
		$this->segment_table          = $this->wpdb->prefix . self::TABLE_PREFIX . 'segment';
		$this->segment_feedback_table = $this->wpdb->prefix . self::TABLE_PREFIX . 'segment_feedback';
		$this->essay_feedback_table   = $this->wpdb->prefix . self::TABLE_PREFIX . 'essay_feedback';
	}

	/**
	 * Create a new essay
	 *
	 * @param array $essay_data Essay data
	 * @return array|WP_Error Created essay data or error
	 */
	public function create_essay( $essay_data ) {
		if ( empty( $essay_data['essay_type'] ) || empty( $essay_data['question'] ) || empty( $essay_data['essay_content'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields', array( 'status' => 400 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Generate UUID if not provided
			$uuid = ! empty( $essay_data['uuid'] ) ? $essay_data['uuid'] : wp_generate_uuid4();

			$ocr_image_ids   = ! empty( $essay_data['ocr_image_ids'] ) ? json_encode( $essay_data['ocr_image_ids'] ) : null;
			$chart_image_ids = ! empty( $essay_data['chart_image_ids'] ) ? json_encode( $essay_data['chart_image_ids'] ) : null;

			$insert_data = array(
				'uuid'            => $uuid,
				'original_id'     => $essay_data['original_id'] ?? null,
				'ocr_image_ids'   => $ocr_image_ids,
				'chart_image_ids' => $chart_image_ids,
				'essay_type'      => $essay_data['essay_type'],
				'question'        => $essay_data['question'],
				'essay_content'   => $essay_data['essay_content'],
				'created_by'      => $essay_data['created_by'],
			);

			$result = $this->wpdb->insert(
				$this->essays_table,
				$insert_data,
				array(
					'%s', // uuid
					'%d', // original_id
					'%s', // ocr_image_ids
					'%s', // chart_image_ids
					'%s', // essay_type
					'%s', // question
					'%s', // essay_content
					'%d', // created_by
				)
			);

			if ( $result === false ) {
				throw new \Exception( 'Failed to create essay: ' . $this->wpdb->last_error );
			}

			$essay_id = $this->wpdb->insert_id;

			// Get the created essay
			$essay = $this->get_essays( array( 'id' => $essay_id ) )[0];

			$this->wpdb->query( 'COMMIT' );
			return $essay;
		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
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
	public function get_essays( $args = array() ) {
		try {
			$defaults = array(
				'id'          => null,
				'uuid'        => null,
				'original_id' => null,
				'essay_type'  => null,
				'created_by'  => null,
				'search'      => null,
				'date_query'  => null,
				'orderby'     => 'id',
				'order'       => 'DESC',
				'per_page'    => 10,
				'page'        => 1,
				'count'       => false,
			);

			$args           = wp_parse_args( $args, $defaults );
			$select         = $args['count'] ? 'COUNT(*)' : '*';
			$from           = $this->essays_table;
			$where          = array( '1=1' );
			$prepare_values = array();

			// Process ID filter
			if ( ! is_null( $args['id'] ) ) {
				if ( is_array( $args['id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['id'] ), '%d' );
					$where[]        = 'id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['id'] );
				} else {
					$where[]          = 'id = %d';
					$prepare_values[] = $args['id'];
				}
			}

			// Process UUID filter
			if ( ! is_null( $args['uuid'] ) ) {
				if ( is_array( $args['uuid'] ) ) {
					$placeholders   = array_fill( 0, count( $args['uuid'] ), '%s' );
					$where[]        = 'uuid IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['uuid'] );
				} else {
					$where[]          = 'uuid = %s';
					$prepare_values[] = $args['uuid'];
				}
			}

			// Process original_id filter
			if ( ! is_null( $args['original_id'] ) ) {
				if ( is_array( $args['original_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['original_id'] ), '%d' );
					$where[]        = 'original_id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['original_id'] );
				} else {
					$where[]          = 'original_id = %d';
					$prepare_values[] = $args['original_id'];
				}
			}

			// Process essay_type filter
			if ( ! is_null( $args['essay_type'] ) ) {
				if ( is_array( $args['essay_type'] ) ) {
					$placeholders   = array_fill( 0, count( $args['essay_type'] ), '%s' );
					$where[]        = 'essay_type IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['essay_type'] );
				} else {
					$where[]          = 'essay_type = %s';
					$prepare_values[] = $args['essay_type'];
				}
			}

			// Process created_by filter
			if ( ! is_null( $args['created_by'] ) ) {
				if ( is_array( $args['created_by'] ) ) {
					$placeholders   = array_fill( 0, count( $args['created_by'] ), '%d' );
					$where[]        = 'created_by IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['created_by'] );
				} else {
					$where[]          = 'created_by = %d';
					$prepare_values[] = $args['created_by'];
				}
			}

			// Process search
			if ( ! is_null( $args['search'] ) ) {
				$where[]          = '(question LIKE %s OR essay_content LIKE %s)';
				$search_term      = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
				$prepare_values[] = $search_term;
				$prepare_values[] = $search_term;
			}

			// Process date query (simplified approach)
			if ( ! is_null( $args['date_query'] ) && is_array( $args['date_query'] ) ) {
				if ( ! empty( $args['date_query']['after'] ) ) {
					$where[]          = 'created_at >= %s';
					$prepare_values[] = $args['date_query']['after'];
				}
				if ( ! empty( $args['date_query']['before'] ) ) {
					$where[]          = 'created_at <= %s';
					$prepare_values[] = $args['date_query']['before'];
				}
			}

			// Build query
			$sql = "SELECT $select FROM $from WHERE " . implode( ' AND ', $where );

			// Add order if not counting
			if ( ! $args['count'] ) {
				// Sanitize orderby field
				$allowed_orderby = array( 'id', 'uuid', 'essay_type', 'created_at', 'created_by' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'id';

				// Sanitize order direction
				$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

				$sql .= " ORDER BY $orderby $order";

				// Add pagination
				$per_page = max( 1, intval( $args['per_page'] ) );
				$page     = max( 1, intval( $args['page'] ) );
				$offset   = ( $page - 1 ) * $per_page;

				$sql             .= ' LIMIT %d OFFSET %d';
				$prepare_values[] = $per_page;
				$prepare_values[] = $offset;
			}

			// Prepare and execute query
			$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );

			if ( $args['count'] ) {
				$result = $this->wpdb->get_var( $prepared_sql );
				if ( $result === null && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return (int) $result;
			} else {
				$results = $this->wpdb->get_results( $prepared_sql, ARRAY_A );
				if ( $results === null && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}

				// Process array fields for each result
				foreach ( $results as &$essay ) {
					if ( ! empty( $essay['ocr_image_ids'] ) ) {
						$essay['ocr_image_ids'] = json_decode( $essay['ocr_image_ids'], true );
					} else {
						$essay['ocr_image_ids'] = array();
					}

					if ( ! empty( $essay['chart_image_ids'] ) ) {
						$essay['chart_image_ids'] = json_decode( $essay['chart_image_ids'], true );
					} else {
						$essay['chart_image_ids'] = array();
					}
				}

				return $results;
			}
		} catch ( \Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve essays: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get segments with flexible query arguments
	 *
	 * @param array $args {
	 *     Optional. Arguments to retrieve segments.
	 *     @type int|array    $segment_id    Segment ID or array of IDs.
	 *     @type int|array    $essay_id      Essay ID or array of IDs.
	 *     @type string|array $type          Segment type or array of types.
	 *     @type int|array    $order         Order position or array of positions.
	 *     @type string       $search        Search term to look for in title or content.
	 *     @type string       $orderby       Field to order results by. Default 'order'.
	 *                                       Accepts 'id', 'essay_id', 'type', 'order'.
	 *     @type string       $order         Order direction. Default 'ASC'.
	 *                                       Accepts 'ASC', 'DESC'.
	 *     @type int          $number        Number of segments to return. Default 10.
	 *     @type int          $offset        Offset for pagination. Default 0.
	 *     @type string|array $fields        Fields to return. Default 'all'.
	 *     @type bool         $count         If true, return only the count. Default false.
	 * }
	 * @return array|int|WP_Error Segments data, count, or error
	 */
	public function get_segments( $args = array() ) {
		try {
			$defaults = array(
				'segment_id' => null,
				'essay_id'   => null,
				'type'       => null,
				'search'     => null,
				'orderby'    => 'order',
				'order'      => 'ASC',
				'number'     => 10,
				'offset'     => 0,
				'fields'     => 'all',
				'count'      => false,
			);

			$args = wp_parse_args( $args, $defaults );

			// Determine what to select
			if ( $args['count'] ) {
				$select = 'COUNT(*)';
			} elseif ( $args['fields'] !== 'all' && is_array( $args['fields'] ) ) {
				// Sanitize field names
				$allowed_fields = array( 'id', 'essay_id', 'type', 'order', 'title', 'content' );
				$fields         = array_intersect( $args['fields'], $allowed_fields );
				if ( empty( $fields ) ) {
					return new WP_Error( 'invalid_fields', 'No valid fields specified', array( 'status' => 400 ) );
				}
				$select = implode( ', ', $fields );
			} else {
				$select = '*';
			}

			$from           = $this->segment_table;
			$where          = array( '1=1' );
			$prepare_values = array();

			// Process segment_id filter
			if ( ! is_null( $args['segment_id'] ) ) {
				if ( is_array( $args['segment_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['segment_id'] ), '%d' );
					$where[]        = 'id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['segment_id'] );
				} else {
					$where[]          = 'id = %d';
					$prepare_values[] = $args['segment_id'];
				}
			}

			// Process essay_id filter
			if ( ! is_null( $args['essay_id'] ) ) {
				if ( is_array( $args['essay_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['essay_id'] ), '%d' );
					$where[]        = 'essay_id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['essay_id'] );
				} else {
					$where[]          = 'essay_id = %d';
					$prepare_values[] = $args['essay_id'];
				}
			}

			// Process type filter
			if ( ! is_null( $args['type'] ) ) {
				if ( is_array( $args['type'] ) ) {
					$placeholders   = array_fill( 0, count( $args['type'] ), '%s' );
					$where[]        = 'type IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['type'] );
				} else {
					$where[]          = 'type = %s';
					$prepare_values[] = $args['type'];
				}
			}

			// Process order filter
			if ( ! is_null( $args['order'] ) && ! in_array( strtoupper( $args['order'] ), array( 'ASC', 'DESC' ) ) ) {
				// If order is used as a filter not as sort direction
				if ( is_array( $args['order'] ) ) {
					$placeholders   = array_fill( 0, count( $args['order'] ), '%d' );
					$where[]        = '`order` IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['order'] );
				} else {
					$where[]          = '`order` = %d';
					$prepare_values[] = $args['order'];
				}
			}

			// Process search
			if ( ! is_null( $args['search'] ) ) {
				$where[]          = '(title LIKE %s OR content LIKE %s)';
				$search_term      = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
				$prepare_values[] = $search_term;
				$prepare_values[] = $search_term;
			}

			// Build query
			$sql = "SELECT $select FROM $from WHERE " . implode( ' AND ', $where );

			// Add order if not counting
			if ( ! $args['count'] ) {
				// Sanitize orderby field
				$allowed_orderby = array( 'id', 'essay_id', 'type', 'order' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'order';

				// For 'order' field, we need to escape it as it's a reserved word in SQL
				if ( $orderby === 'order' ) {
					$orderby = '`order`';
				}

				// Sanitize order direction
				$order_dir = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

				$sql .= " ORDER BY $orderby $order_dir";

				// Add pagination
				$number = max( 1, intval( $args['number'] ) );
				$offset = max( 0, intval( $args['offset'] ) );

				$sql             .= ' LIMIT %d OFFSET %d';
				$prepare_values[] = $number;
				$prepare_values[] = $offset;
			}

			// Prepare and execute query
			$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );

			if ( $args['count'] ) {
				$result = $this->wpdb->get_var( $prepared_sql );
				if ( $result === null && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return (int) $result;
			} else {
				$results = $this->wpdb->get_results( $prepared_sql, ARRAY_A );
				if ( $results === null && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return $results;
			}
		} catch ( \Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve segments: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get essay feedbacks with flexible query arguments
	 *
	 * @param array $args {
	 *     Optional. Arguments to retrieve essay feedbacks.
	 *     @type int|array    $feedback_id       Feedback ID or array of IDs.
	 *     @type int|array    $essay_id          Essay ID or array of IDs.
	 *     @type string|array $source            Feedback source or array of sources.
	 *     @type string|array $feedback_criteria Feedback criteria or array of criteria.
	 *     @type string|array $feedback_language Feedback language or array of languages.
	 *     @type int|array    $created_by        User ID or array of user IDs who created the feedback.
	 *     @type array        $date_query        Query by date parameters (similar to WP_Query).
	 *     @type string       $date_from         Get feedback after this date.
	 *     @type string       $date_to           Get feedback before this date.
	 *     @type string       $orderby           Field to order results by. Default 'id'.
	 *                                           Accepts 'id', 'essay_id', 'created_at'.
	 *     @type string       $order             Order direction. Default 'DESC'.
	 *                                           Accepts 'ASC', 'DESC'.
	 *     @type int          $number            Number of feedbacks to return. Default 10.
	 *     @type int          $offset            Offset for pagination. Default 0.
	 *     @type string|array $fields            Fields to return. Default 'all'.
	 *     @type bool         $count             If true, return only the count. Default false.
	 *     @type bool         $no_found_rows     Skip counting total rows. Default false.
	 * }
	 * @return array|int|WP_Error Feedbacks data, count, or error
	 */
	public function get_essay_feedbacks( $args = array() ) {
		try {
			$defaults = array(
				'feedback_id'       => null,
				'essay_id'          => null,
				'source'            => null,
				'feedback_criteria' => null,
				'feedback_language' => null,
				'created_by'        => null,
				'date_query'        => null,
				'date_from'         => null,
				'date_to'           => null,
				'orderby'           => 'id',
				'order'             => 'DESC',
				'number'            => 10,
				'offset'            => 0,
				'fields'            => 'all',
				'count'             => false,
				'no_found_rows'     => false,
			);

			$args = wp_parse_args( $args, $defaults );

			// Determine what to select
			if ( $args['count'] ) {
				$select = 'COUNT(*)';
			} elseif ( $args['fields'] !== 'all' && is_array( $args['fields'] ) ) {
				// Sanitize field names
				$allowed_fields = array(
					'id',
					'feedback_criteria',
					'essay_id',
					'feedback_language',
					'source',
					'cot_content',
					'score_content',
					'feedback_content',
					'created_at',
					'created_by',
				);
				$fields         = array_intersect( $args['fields'], $allowed_fields );
				if ( empty( $fields ) ) {
					return new WP_Error( 'invalid_fields', 'No valid fields specified', array( 'status' => 400 ) );
				}
				$select = implode( ', ', $fields );
			} else {
				$select = '*';
			}

			$from           = $this->essay_feedback_table;
			$where          = array( '1=1' );
			$prepare_values = array();

			// Process feedback_id filter
			if ( ! is_null( $args['feedback_id'] ) ) {
				if ( is_array( $args['feedback_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['feedback_id'] ), '%d' );
					$where[]        = 'id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['feedback_id'] );
				} else {
					$where[]          = 'id = %d';
					$prepare_values[] = $args['feedback_id'];
				}
			}

			// Process essay_id filter
			if ( ! is_null( $args['essay_id'] ) ) {
				if ( is_array( $args['essay_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['essay_id'] ), '%d' );
					$where[]        = 'essay_id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['essay_id'] );
				} else {
					$where[]          = 'essay_id = %d';
					$prepare_values[] = $args['essay_id'];
				}
			}

			// Process source filter
			if ( ! is_null( $args['source'] ) ) {
				if ( is_array( $args['source'] ) ) {
					$placeholders   = array_fill( 0, count( $args['source'] ), '%s' );
					$where[]        = 'source IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['source'] );
				} else {
					$where[]          = 'source = %s';
					$prepare_values[] = $args['source'];
				}
			}

			// Process feedback_criteria filter
			if ( ! is_null( $args['feedback_criteria'] ) ) {
				if ( is_array( $args['feedback_criteria'] ) ) {
					$placeholders   = array_fill( 0, count( $args['feedback_criteria'] ), '%s' );
					$where[]        = 'feedback_criteria IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['feedback_criteria'] );
				} else {
					$where[]          = 'feedback_criteria = %s';
					$prepare_values[] = $args['feedback_criteria'];
				}
			}

			// Process feedback_language filter
			if ( ! is_null( $args['feedback_language'] ) ) {
				if ( is_array( $args['feedback_language'] ) ) {
					$placeholders   = array_fill( 0, count( $args['feedback_language'] ), '%s' );
					$where[]        = 'feedback_language IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['feedback_language'] );
				} else {
					$where[]          = 'feedback_language = %s';
					$prepare_values[] = $args['feedback_language'];
				}
			}

			// Process created_by filter
			if ( ! is_null( $args['created_by'] ) ) {
				if ( is_array( $args['created_by'] ) ) {
					$placeholders   = array_fill( 0, count( $args['created_by'] ), '%d' );
					$where[]        = 'created_by IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['created_by'] );
				} else {
					$where[]          = 'created_by = %d';
					$prepare_values[] = $args['created_by'];
				}
			}

			// Process date filters
			// Simple date range filters
			if ( ! is_null( $args['date_from'] ) ) {
				$where[]          = 'created_at >= %s';
				$prepare_values[] = $args['date_from'];
			}
			if ( ! is_null( $args['date_to'] ) ) {
				$where[]          = 'created_at <= %s';
				$prepare_values[] = $args['date_to'];
			}

			// Process WP-style date query (more advanced)
			if ( ! is_null( $args['date_query'] ) && is_array( $args['date_query'] ) ) {
				if ( ! empty( $args['date_query']['after'] ) ) {
					$where[]          = 'created_at >= %s';
					$prepare_values[] = $args['date_query']['after'];
				}
				if ( ! empty( $args['date_query']['before'] ) ) {
					$where[]          = 'created_at <= %s';
					$prepare_values[] = $args['date_query']['before'];
				}
				// Could add more complex date query handling here if needed
			}

			// Build query
			$sql = "SELECT $select FROM $from WHERE " . implode( ' AND ', $where );

			// Add order if not counting
			if ( ! $args['count'] ) {
				// Sanitize orderby field
				$allowed_orderby = array( 'id', 'essay_id', 'created_at' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'id';

				// Sanitize order direction
				$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

				$sql .= " ORDER BY $orderby $order";

				// Add pagination
				$number = max( 1, intval( $args['number'] ) );
				$offset = max( 0, intval( $args['offset'] ) );

				$sql             .= ' LIMIT %d OFFSET %d';
				$prepare_values[] = $number;
				$prepare_values[] = $offset;
			}

			// Prepare and execute query
			$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );

			if ( $args['count'] ) {
				$result = $this->wpdb->get_var( $prepared_sql );
				if ( $result === null && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return (int) $result;
			} else {
				$results = $this->wpdb->get_results( $prepared_sql, ARRAY_A );
				if ( $results === null && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return $results;
			}
		} catch ( \Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve essay feedbacks: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get segment feedbacks with flexible query arguments
	 *
	 * @param array $args {
	 *     Optional. Arguments to retrieve segment feedbacks.
	 *     @type int|array    $feedback_id       Feedback ID or array of IDs.
	 *     @type int|array    $segment_id        Segment ID or array of IDs.
	 *     @type string|array $feedback_criteria Feedback criteria or array of criteria.
	 *     @type string|array $feedback_language Feedback language or array of languages.
	 *     @type string|array $source            Feedback source or array of sources.
	 *     @type int|array    $created_by        User ID or array of user IDs who created the feedback.
	 *     @type array        $date_query        Query by date parameters (similar to WP_Query).
	 *     @type string       $date_from         Get feedback after this date.
	 *     @type string       $date_to           Get feedback before this date.
	 *     @type string       $orderby           Field to order results by. Default 'id'.
	 *                                           Accepts 'id', 'segment_id', 'created_at'.
	 *     @type string       $order             Order direction. Default 'DESC'.
	 *                                           Accepts 'ASC', 'DESC'.
	 *     @type int          $limit             Number of feedbacks to return. Default 10.
	 *     @type int          $offset            Offset for pagination. Default 0.
	 *     @type string|array $fields            Fields to return. Default 'all'.
	 *     @type bool         $include_cot       Whether to include Chain of Thought content. Default true.
	 *     @type bool         $include_score     Whether to include score content. Default true.
	 *     @type bool         $include_feedback  Whether to include feedback content. Default true.
	 *     @type bool         $count             If true, return only the count. Default false.
	 *     @type bool         $no_found_rows     Skip counting total rows. Default false.
	 * }
	 * @return array|int|WP_Error Segment feedbacks data, count, or error
	 */
	public function get_segment_feedbacks( $args = array() ) {
		try {
			$defaults = array(
				'feedback_id'       => null,
				'segment_id'        => null,
				'feedback_criteria' => null,
				'feedback_language' => null,
				'source'            => null,
				'created_by'        => null,
				'date_query'        => null,
				'date_from'         => null,
				'date_to'           => null,
				'orderby'           => 'id',
				'order'             => 'DESC',
				'limit'             => 10,
				'offset'            => 0,
				'fields'            => 'all',
				'include_cot'       => true,
				'include_score'     => true,
				'include_feedback'  => true,
				'count'             => false,
				'no_found_rows'     => false,
			);

			$args = wp_parse_args( $args, $defaults );

			// Determine what to select
			if ( $args['count'] ) {
				$select = 'COUNT(*)';
			} elseif ( $args['fields'] !== 'all' && is_array( $args['fields'] ) ) {
				// Sanitize field names
				$allowed_fields = array(
					'id',
					'feedback_criteria',
					'segment_id',
					'feedback_language',
					'source',
					'cot_content',
					'score_content',
					'feedback_content',
					'created_at',
					'created_by',
				);
				$fields         = array_intersect( $args['fields'], $allowed_fields );

				// Handle content selection
				if ( ! $args['include_cot'] && in_array( 'cot_content', $fields ) ) {
					$fields = array_diff( $fields, array( 'cot_content' ) );
				}
				if ( ! $args['include_score'] && in_array( 'score_content', $fields ) ) {
					$fields = array_diff( $fields, array( 'score_content' ) );
				}
				if ( ! $args['include_feedback'] && in_array( 'feedback_content', $fields ) ) {
					$fields = array_diff( $fields, array( 'feedback_content' ) );
				}

				if ( empty( $fields ) ) {
					return new WP_Error( 'invalid_fields', 'No valid fields specified', array( 'status' => 400 ) );
				}
				$select = implode( ', ', $fields );
			} else {
				// Handle selective content fields
				$select_fields   = array();
				$select_fields[] = 'id';
				$select_fields[] = 'feedback_criteria';
				$select_fields[] = 'segment_id';
				$select_fields[] = 'feedback_language';
				$select_fields[] = 'source';
				if ( $args['include_cot'] ) {
					$select_fields[] = 'cot_content';
				}
				if ( $args['include_score'] ) {
					$select_fields[] = 'score_content';
				}
				if ( $args['include_feedback'] ) {
					$select_fields[] = 'feedback_content';
				}
				$select_fields[] = 'created_at';
				$select_fields[] = 'created_by';

				$select = implode( ', ', $select_fields );
			}

			$from           = $this->segment_feedback_table;
			$where          = array( '1=1' );
			$prepare_values = array();

			// Process feedback_id filter
			if ( ! is_null( $args['feedback_id'] ) ) {
				if ( is_array( $args['feedback_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['feedback_id'] ), '%d' );
					$where[]        = 'id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['feedback_id'] );
				} else {
					$where[]          = 'id = %d';
					$prepare_values[] = $args['feedback_id'];
				}
			}

			// Process segment_id filter
			if ( ! is_null( $args['segment_id'] ) ) {
				if ( is_array( $args['segment_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['segment_id'] ), '%d' );
					$where[]        = 'segment_id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['segment_id'] );
				} else {
					$where[]          = 'segment_id = %d';
					$prepare_values[] = $args['segment_id'];
				}
			}

			// Process feedback_criteria filter
			if ( ! is_null( $args['feedback_criteria'] ) ) {
				if ( is_array( $args['feedback_criteria'] ) ) {
					$placeholders   = array_fill( 0, count( $args['feedback_criteria'] ), '%s' );
					$where[]        = 'feedback_criteria IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['feedback_criteria'] );
				} else {
					$where[]          = 'feedback_criteria = %s';
					$prepare_values[] = $args['feedback_criteria'];
				}
			}

			// Process feedback_language filter
			if ( ! is_null( $args['feedback_language'] ) ) {
				if ( is_array( $args['feedback_language'] ) ) {
					$placeholders   = array_fill( 0, count( $args['feedback_language'] ), '%s' );
					$where[]        = 'feedback_language IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['feedback_language'] );
				} else {
					$where[]          = 'feedback_language = %s';
					$prepare_values[] = $args['feedback_language'];
				}
			}

			// Process source filter
			if ( ! is_null( $args['source'] ) ) {
				if ( is_array( $args['source'] ) ) {
					$placeholders   = array_fill( 0, count( $args['source'] ), '%s' );
					$where[]        = 'source IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['source'] );
				} else {
					$where[]          = 'source = %s';
					$prepare_values[] = $args['source'];
				}
			}

			// Process created_by filter
			if ( ! is_null( $args['created_by'] ) ) {
				if ( is_array( $args['created_by'] ) ) {
					$placeholders   = array_fill( 0, count( $args['created_by'] ), '%d' );
					$where[]        = 'created_by IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['created_by'] );
				} else {
					$where[]          = 'created_by = %d';
					$prepare_values[] = $args['created_by'];
				}
			}

			// Process date filters
			// Simple date range filters
			if ( ! is_null( $args['date_from'] ) ) {
				$where[]          = 'created_at >= %s';
				$prepare_values[] = $args['date_from'];
			}
			if ( ! is_null( $args['date_to'] ) ) {
				$where[]          = 'created_at <= %s';
				$prepare_values[] = $args['date_to'];
			}

			// Process WP-style date query
			if ( ! is_null( $args['date_query'] ) && is_array( $args['date_query'] ) ) {
				if ( ! empty( $args['date_query']['after'] ) ) {
					$where[]          = 'created_at >= %s';
					$prepare_values[] = $args['date_query']['after'];
				}
				if ( ! empty( $args['date_query']['before'] ) ) {
					$where[]          = 'created_at <= %s';
					$prepare_values[] = $args['date_query']['before'];
				}
			}

			// Build query
			$sql = "SELECT $select FROM $from WHERE " . implode( ' AND ', $where );

			// Add order if not counting
			if ( ! $args['count'] ) {
				// Sanitize orderby field
				$allowed_orderby = array( 'id', 'segment_id', 'created_at' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'id';

				// Sanitize order direction
				$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

				$sql .= " ORDER BY $orderby $order";

				// Add pagination
				$limit  = max( 1, intval( $args['limit'] ) );
				$offset = max( 0, intval( $args['offset'] ) );

				$sql             .= ' LIMIT %d OFFSET %d';
				$prepare_values[] = $limit;
				$prepare_values[] = $offset;
			}

			// Prepare and execute query
			$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );

			if ( $args['count'] ) {
				$result = $this->wpdb->get_var( $prepared_sql );
				if ( $result === null && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return (int) $result;
			} else {
				$results = $this->wpdb->get_results( $prepared_sql, ARRAY_A );
				if ( $results === null && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return $results;
			}
		} catch ( \Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve segment feedbacks: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
