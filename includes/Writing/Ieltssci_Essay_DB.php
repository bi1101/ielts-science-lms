<?php
/**
 * IELTS Science LMS Essay Database Handler
 *
 * This file contains the database operations class for managing IELTS essays,
 * including creating, retrieving, and managing essay data, segments, and feedback.
 *
 * @package IELTS_Science_LMS
 * @subpackage Writing
 * @since 1.0.0
 * @version 1.0.0
 */

namespace IeltsScienceLMS\Writing;

use WP_Error;
use wpdb;

/**
 * Class Ieltssci_Essay_DB
 *
 * Handles database operations for IELTS Science LMS essays.
 *
 * This class provides methods to:
 * - Create and retrieve essays
 * - Manage essay segments
 * - Handle essay and segment feedback
 * - Query essay data with flexible parameters
 *
 * @since 1.0.0
 */
class Ieltssci_Essay_DB {
	/**
	 * Table prefix constant.
	 */
	const TABLE_PREFIX = 'ieltssci_';

	/**
	 * WordPress database object.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Essays table name.
	 *
	 * @var string
	 */
	private $essays_table;

	/**
	 * Segment table name.
	 *
	 * @var string
	 */
	private $segment_table;

	/**
	 * Segment feedback table name.
	 *
	 * @var string
	 */
	private $segment_feedback_table;

	/**
	 * Essay feedback table name.
	 *
	 * @var string
	 */
	private $essay_feedback_table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;

		$this->essays_table           = $this->wpdb->prefix . self::TABLE_PREFIX . 'essays';
		$this->segment_table          = $this->wpdb->prefix . self::TABLE_PREFIX . 'segment';
		$this->segment_feedback_table = $this->wpdb->prefix . self::TABLE_PREFIX . 'segment_feedback';
		$this->essay_feedback_table   = $this->wpdb->prefix . self::TABLE_PREFIX . 'essay_feedback';
	}

	/**
	 * Create a new essay or update an existing one based on UUID.
	 *
	 * @param array $essay_data Essay data.
	 * @return array|WP_Error Created/updated essay data or error.
	 * @throws \Exception If there is a database error.
	 */
	public function create_essay( $essay_data ) {
		if ( empty( $essay_data['essay_type'] ) || empty( $essay_data['question'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields.', array( 'status' => 400 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Generate UUID if not provided.
			$uuid = ! empty( $essay_data['uuid'] ) ? $essay_data['uuid'] : wp_generate_uuid4();

			// Check if an essay with this UUID already exists.
			$existing_essay = null;
			if ( ! empty( $essay_data['uuid'] ) ) {
				$existing_essays = $this->get_essays( array( 'uuid' => $uuid ) );
				if ( ! empty( $existing_essays ) && ! is_wp_error( $existing_essays ) ) {
					$existing_essay = $existing_essays[0];
				}
			}

			$ocr_image_ids   = ! empty( $essay_data['ocr_image_ids'] ) ? wp_json_encode( $essay_data['ocr_image_ids'] ) : null;
			$chart_image_ids = ! empty( $essay_data['chart_image_ids'] ) ? wp_json_encode( $essay_data['chart_image_ids'] ) : null;

			$data = array(
				'uuid'            => $uuid,
				'original_id'     => $essay_data['original_id'] ?? null,
				'ocr_image_ids'   => $ocr_image_ids,
				'chart_image_ids' => $chart_image_ids,
				'essay_type'      => $essay_data['essay_type'],
				'question'        => $essay_data['question'],
				'essay_content'   => $essay_data['essay_content'] ?? '',
				'created_by'      => $essay_data['created_by'],
			);

			$format = array(
				'%s', // uuid.
				'%d', // original_id.
				'%s', // ocr_image_ids.
				'%s', // chart_image_ids.
				'%s', // essay_type.
				'%s', // question.
				'%s', // essay_content.
				'%d', // created_by.
			);

			$essay_id = null;

			if ( $existing_essay ) {
				// Update existing essay.
				$result = $this->wpdb->update(
					$this->essays_table,
					$data,
					array( 'id' => $existing_essay['id'] ),
					$format,
					array( '%d' ) // id format.
				);

				if ( false === $result ) {
					throw new \Exception( 'Failed to update essay: ' . $this->wpdb->last_error );
				}

				$essay_id = $existing_essay['id'];
			} else {
				// Create new essay.
				$result = $this->wpdb->insert(
					$this->essays_table,
					$data,
					$format
				);

				if ( false === $result ) {
					throw new \Exception( 'Failed to create essay: ' . $this->wpdb->last_error );
				}

				$essay_id = $this->wpdb->insert_id;
			}

			// Get the created/updated essay.
			$essay = $this->get_essays( array( 'id' => $essay_id ) )[0];

			$this->wpdb->query( 'COMMIT' );
			return $essay;
		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get essays with flexible query arguments.
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
	 * @return array|int|WP_Error Essays data, count, or error.
	 * @throws \Exception If there is a database error.
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

			// Process ID filter.
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

			// Process UUID filter.
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

			// Process original_id filter.
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

			// Process essay_type filter.
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

			// Process created_by filter.
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

			// Process search.
			if ( ! is_null( $args['search'] ) ) {
				$where[]          = '(question LIKE %s OR essay_content LIKE %s)';
				$search_term      = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
				$prepare_values[] = $search_term;
				$prepare_values[] = $search_term;
			}

			// Process date query (simplified approach).
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

			// Build query.
			$sql = "SELECT $select FROM $from WHERE " . implode( ' AND ', $where );

			// Add order if not counting.
			if ( ! $args['count'] ) {
				// Sanitize orderby field.
				$allowed_orderby = array( 'id', 'uuid', 'essay_type', 'created_at', 'created_by' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';

				// Sanitize order direction.
				$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

				$sql .= " ORDER BY $orderby $order";

				// Add pagination.
				$per_page = max( 1, intval( $args['per_page'] ) );
				$page     = max( 1, intval( $args['page'] ) );
				$offset   = ( $page - 1 ) * $per_page;

				$sql             .= ' LIMIT %d OFFSET %d';
				$prepare_values[] = $per_page;
				$prepare_values[] = $offset;
			}

			// Prepare and execute query.
			$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );

			if ( $args['count'] ) {
				$result = $this->wpdb->get_var( $prepared_sql );
				if ( null === $result && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return (int) $result;
			} else {
				$results = $this->wpdb->get_results( $prepared_sql, ARRAY_A );
				if ( null === $results && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}

				// Process array fields for each result.
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
	 * Get segments with flexible query arguments.
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
	 * @return array|int|WP_Error Segments data, count, or error.
	 * @throws \Exception If there is a database error.
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

			// Determine what to select.
			if ( $args['count'] ) {
				$select = 'COUNT(*)';
			} elseif ( 'all' !== $args['fields'] && is_array( $args['fields'] ) ) {
				// Sanitize field names.
				$allowed_fields = array( 'id', 'essay_id', 'type', 'order', 'title', 'content' );
				$fields         = array_intersect( $args['fields'], $allowed_fields );
				if ( empty( $fields ) ) {
					return new WP_Error( 'invalid_fields', 'No valid fields specified.', array( 'status' => 400 ) );
				}
				$select = implode( ', ', $fields );
			} else {
				$select = '*';
			}

			$from           = $this->segment_table;
			$where          = array( '1=1' );
			$prepare_values = array();

			// Process segment_id filter.
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

			// Process essay_id filter.
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

			// Process type filter.
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

			// Process order filter.
			if ( ! is_null( $args['order'] ) && ! in_array( strtoupper( $args['order'] ), array( 'ASC', 'DESC' ), true ) ) {
				// If order is used as a filter not as sort direction.
				if ( is_array( $args['order'] ) ) {
					$placeholders   = array_fill( 0, count( $args['order'] ), '%d' );
					$where[]        = '`order` IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['order'] );
				} else {
					$where[]          = '`order` = %d';
					$prepare_values[] = $args['order'];
				}
			}

			// Process search.
			if ( ! is_null( $args['search'] ) ) {
				$where[]          = '(title LIKE %s OR content LIKE %s)';
				$search_term      = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
				$prepare_values[] = $search_term;
				$prepare_values[] = $search_term;
			}

			// Build query.
			$sql = "SELECT $select FROM $from WHERE " . implode( ' AND ', $where );

			// Add order if not counting.
			if ( ! $args['count'] ) {
				// Sanitize orderby field.
				$allowed_orderby = array( 'id', 'essay_id', 'type', 'order' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'order';

				// For 'order' field, we need to escape it as it's a reserved word in SQL.
				if ( 'order' === $orderby ) {
					$orderby = '`order`';
				}

				// Sanitize order direction.
				$order_dir = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

				$sql .= " ORDER BY $orderby $order_dir";

				// Add pagination.
				$number = max( 1, intval( $args['number'] ) );
				$offset = max( 0, intval( $args['offset'] ) );

				$sql             .= ' LIMIT %d OFFSET %d';
				$prepare_values[] = $number;
				$prepare_values[] = $offset;
			}

			// Prepare and execute query.
			$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );

			if ( $args['count'] ) {
				$result = $this->wpdb->get_var( $prepared_sql );
				if ( null === $result && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return (int) $result;
			} else {
				$results = $this->wpdb->get_results( $prepared_sql, ARRAY_A );
				if ( null === $results && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return $results;
			}
		} catch ( \Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve segments: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create or update a segment.
	 *
	 * @param array $segment_data {
	 *     Required. Segment data to create or update.
	 *     @type int    $id             Optional. ID of the segment to update. If not provided, a new segment will be created.
	 *     @type int    $essay_id       Required. ID of the essay this segment belongs to.
	 *     @type string $type           Required. Type of segment (e.g., 'introduction', 'topic-sentence', 'main-point', 'conclusion').
	 *     @type int    $order          Required. Order/position of the segment in the essay.
	 *     @type string $title          Required. Title of the segment (e.g., 'Introduction', 'Topic Sentence', 'Main Point 1', 'Conclusion').
	 *     @type string $content        Required. Content of the segment.
	 * }
	 * @return array|WP_Error Created or updated segment data or error.
	 * @throws \Exception If there is a database error.
	 */
	public function create_update_segment( $segment_data ) {
		// Validate required fields.
		if ( empty( $segment_data['essay_id'] ) ||
			empty( $segment_data['type'] ) ||
			! isset( $segment_data['order'] ) ||
			empty( $segment_data['title'] ) ||
			empty( $segment_data['content'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields.', array( 'status' => 400 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Prepare data for insertion/update.
			$data = array(
				'essay_id' => $segment_data['essay_id'],
				'type'     => $segment_data['type'],
				'order'    => $segment_data['order'],
				'title'    => $segment_data['title'],
				'content'  => $segment_data['content'],
			);

			$format = array(
				'%d', // essay_id.
				'%s', // type.
				'%d', // order.
				'%s', // title.
				'%s', // content.
			);

			// Check if the essay exists.
			$essay = $this->get_essays( array( 'id' => $segment_data['essay_id'] ) );
			if ( empty( $essay ) ) {
				throw new \Exception( 'Essay not found.' );
			}

			// Determine if we're creating or updating.
			if ( ! empty( $segment_data['id'] ) ) {
				// Update existing segment.
				$segment_id = $segment_data['id'];

				// Check if segment exists.
				$existing_segment = $this->get_segments( array( 'segment_id' => $segment_id ) );
				if ( empty( $existing_segment ) ) {
					throw new \Exception( 'Segment not found.' );
				}

				$result = $this->wpdb->update(
					$this->segment_table,
					$data,
					array( 'id' => $segment_id ),
					$format,
					array( '%d' )
				);

				if ( false === $result ) {
					throw new \Exception( 'Failed to update segment: ' . $this->wpdb->last_error );
				}
			} else {
				// Create new segment.
				$result = $this->wpdb->insert(
					$this->segment_table,
					$data,
					$format
				);

				if ( false === $result ) {
					throw new \Exception( 'Failed to create segment: ' . $this->wpdb->last_error );
				}

				$segment_id = $this->wpdb->insert_id;
			}

			// Get the created/updated segment.
			$segment = $this->get_segments( array( 'segment_id' => $segment_id ) );

			if ( empty( $segment ) ) {
				throw new \Exception( 'Failed to retrieve segment after creation/update.' );
			}

			$this->wpdb->query( 'COMMIT' );
			return $segment[0]; // Return the first (and should be only) segment.
		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get essay feedbacks with flexible query arguments.
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
	 * @return array|int|WP_Error Feedbacks data, count, or error.
	 * @throws \Exception If there is a database error.
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

			// Determine what to select.
			if ( $args['count'] ) {
				$select = 'COUNT(*)';
			} elseif ( 'all' !== $args['fields'] && is_array( $args['fields'] ) ) {
				// Sanitize field names.
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
					return new WP_Error( 'invalid_fields', 'No valid fields specified.', array( 'status' => 400 ) );
				}
				$select = implode( ', ', $fields );
			} else {
				$select = '*';
			}

			$from           = $this->essay_feedback_table;
			$where          = array( '1=1' );
			$prepare_values = array();

			// Process feedback_id filter.
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

			// Process essay_id filter.
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

			// Process source filter.
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

			// Process feedback_criteria filter.
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

			// Process feedback_language filter.
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

			// Process created_by filter.
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

			// Process date filters.
			// Simple date range filters.
			if ( ! is_null( $args['date_from'] ) ) {
				$where[]          = 'created_at >= %s';
				$prepare_values[] = $args['date_from'];
			}
			if ( ! is_null( $args['date_to'] ) ) {
				$where[]          = 'created_at <= %s';
				$prepare_values[] = $args['date_to'];
			}

			// Process WP-style date query (more advanced).
			if ( ! is_null( $args['date_query'] ) && is_array( $args['date_query'] ) ) {
				if ( ! empty( $args['date_query']['after'] ) ) {
					$where[]          = 'created_at >= %s';
					$prepare_values[] = $args['date_query']['after'];
				}
				if ( ! empty( $args['date_query']['before'] ) ) {
					$where[]          = 'created_at <= %s';
					$prepare_values[] = $args['date_query']['before'];
				}
				// Could add more complex date query handling here if needed.
			}

			// Build query.
			$sql = "SELECT $select FROM $from WHERE " . implode( ' AND ', $where );

			// Add order if not counting.
			if ( ! $args['count'] ) {
				// Sanitize orderby field.
				$allowed_orderby = array( 'id', 'essay_id', 'created_at' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';

				// Sanitize order direction.
				$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

				$sql .= " ORDER BY $orderby $order";

				// Add pagination.
				$number = max( 1, intval( $args['number'] ) );
				$offset = max( 0, intval( $args['offset'] ) );

				$sql             .= ' LIMIT %d OFFSET %d';
				$prepare_values[] = $number;
				$prepare_values[] = $offset;
			}

			// Prepare and execute query.
			$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );

			if ( $args['count'] ) {
				$result = $this->wpdb->get_var( $prepared_sql );
				if ( null === $result && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return (int) $result;
			} else {
				$results = $this->wpdb->get_results( $prepared_sql, ARRAY_A );
				if ( null === $results && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return $results;
			}
		} catch ( \Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve essay feedbacks: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get segment feedbacks with flexible query arguments.
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
	 * @return array|int|WP_Error Segment feedbacks data, count, or error.
	 * @throws \Exception If there is a database error.
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

			// Determine what to select.
			if ( $args['count'] ) {
				$select = 'COUNT(*)';
			} elseif ( 'all' !== $args['fields'] && is_array( $args['fields'] ) ) {
				// Sanitize field names.
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

				// Handle content selection.
				if ( ! $args['include_cot'] && in_array( 'cot_content', $fields, true ) ) {
					$fields = array_diff( $fields, array( 'cot_content' ) );
				}
				if ( ! $args['include_score'] && in_array( 'score_content', $fields, true ) ) {
					$fields = array_diff( $fields, array( 'score_content' ) );
				}
				if ( ! $args['include_feedback'] && in_array( 'feedback_content', $fields, true ) ) {
					$fields = array_diff( $fields, array( 'feedback_content' ) );
				}

				if ( empty( $fields ) ) {
					return new WP_Error( 'invalid_fields', 'No valid fields specified.', array( 'status' => 400 ) );
				}
				$select = implode( ', ', $fields );
			} else {
				// Handle selective content fields.
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

			// Process feedback_id filter.
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

			// Process segment_id filter.
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

			// Process feedback_criteria filter.
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

			// Process feedback_language filter.
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

			// Process source filter.
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

			// Process created_by filter.
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

			// Process date filters.
			// Simple date range filters.
			if ( ! is_null( $args['date_from'] ) ) {
				$where[]          = 'created_at >= %s';
				$prepare_values[] = $args['date_from'];
			}
			if ( ! is_null( $args['date_to'] ) ) {
				$where[]          = 'created_at <= %s';
				$prepare_values[] = $args['date_to'];
			}

			// Process WP-style date query.
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

			// Build query.
			$sql = "SELECT $select FROM $from WHERE " . implode( ' AND ', $where );

			// Add order if not counting.
			if ( ! $args['count'] ) {
				// Sanitize orderby field.
				$allowed_orderby = array( 'id', 'segment_id', 'created_at' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';

				// Sanitize order direction.
				$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

				$sql .= " ORDER BY $orderby $order";

				// Add pagination.
				$limit  = max( 1, intval( $args['limit'] ) );
				$offset = max( 0, intval( $args['offset'] ) );

				$sql             .= ' LIMIT %d OFFSET %d';
				$prepare_values[] = $limit;
				$prepare_values[] = $offset;
			}

			// Prepare and execute query.
			$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );

			if ( $args['count'] ) {
				$result = $this->wpdb->get_var( $prepared_sql );
				if ( null === $result && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return (int) $result;
			} else {
				$results = $this->wpdb->get_results( $prepared_sql, ARRAY_A );
				if ( null === $results && $this->wpdb->last_error ) {
					throw new \Exception( $this->wpdb->last_error );
				}
				return $results;
			}
		} catch ( \Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve segment feedbacks: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create or update an essay feedback.
	 *
	 * @param array $feedback_data {
	 *     Required. Essay feedback data to create or update.
	 *     @type int    $id               Optional. ID of the feedback to update. If not provided, a new feedback will be created.
	 *     @type int    $essay_id         Required. ID of the essay this feedback is for.
	 *     @type string $feedback_criteria Required. Criteria for the feedback (e.g., 'coherence', 'lexical-resource', 'task-achievement', 'grammar').
	 *     @type string $feedback_language Required. Language of the feedback (e.g., 'en', 'vi').
	 *     @type string $source           Required. Source of the feedback (e.g., 'ai', 'human', 'guided').
	 *     @type string $cot_content      Optional. Chain of Thought content explaining the reasoning.
	 *     @type string $score_content    Optional. Scoring content or numerical evaluation.
	 *     @type string $feedback_content Optional. The actual feedback content.
	 *     @type bool   $is_preferred     Optional. Whether this feedback is the preferred default. Defaults to false.
	 *     @type int    $created_by       Optional. User ID who created the feedback. Defaults to current user ID.
	 * }
	 * @return array|WP_Error Created or updated essay feedback data or error.
	 * @throws \Exception If there is a database error.
	 */
	public function create_update_essay_feedback( $feedback_data ) {
		// Validate required fields.
		if ( empty( $feedback_data['essay_id'] ) ||
			empty( $feedback_data['feedback_criteria'] ) ||
			empty( $feedback_data['feedback_language'] ) ||
			empty( $feedback_data['source'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields for essay feedback.', array( 'status' => 400 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Check if the essay exists.
			$essay = $this->get_essays( array( 'id' => $feedback_data['essay_id'] ) );
			if ( empty( $essay ) ) {
				throw new \Exception( 'Essay not found.' );
			}

			// Set created_by to current user if not provided.
			if ( empty( $feedback_data['created_by'] ) ) {
				$feedback_data['created_by'] = get_current_user_id();
			}

			// Prepare data for insertion/update.
			$data = array(
				'essay_id'          => $feedback_data['essay_id'],
				'feedback_criteria' => $feedback_data['feedback_criteria'],
				'feedback_language' => $feedback_data['feedback_language'],
				'source'            => $feedback_data['source'],
				'created_by'        => $feedback_data['created_by'],
			);

			$format = array(
				'%d', // essay_id.
				'%s', // feedback_criteria.
				'%s', // feedback_language.
				'%s', // source.
				'%d', // created_by.
			);

			// Add is_preferred if provided.
			if ( isset( $feedback_data['is_preferred'] ) ) {
				$data['is_preferred'] = $feedback_data['is_preferred'] ? 1 : 0;
				$format[]             = '%d';
			}

			// Add optional fields if provided.
			if ( isset( $feedback_data['cot_content'] ) ) {
				$data['cot_content'] = $feedback_data['cot_content'];
				$format[]            = '%s';
			}

			if ( isset( $feedback_data['score_content'] ) ) {
				$data['score_content'] = $feedback_data['score_content'];
				$format[]              = '%s';
			}

			if ( isset( $feedback_data['feedback_content'] ) ) {
				$data['feedback_content'] = $feedback_data['feedback_content'];
				$format[]                 = '%s';
			}

			// Determine if we're creating or updating.
			if ( ! empty( $feedback_data['id'] ) ) {
				// Update existing feedback.
				$feedback_id = $feedback_data['id'];

				// Check if feedback exists.
				$existing_feedback = $this->get_essay_feedbacks( array( 'feedback_id' => $feedback_id ) );
				if ( empty( $existing_feedback ) ) {
					throw new \Exception( 'Essay feedback not found.' );
				}

				$result = $this->wpdb->update(
					$this->essay_feedback_table,
					$data,
					array( 'id' => $feedback_id ),
					$format,
					array( '%d' )
				);

				if ( false === $result ) {
					throw new \Exception( 'Failed to update essay feedback: ' . $this->wpdb->last_error );
				}
			} else {
				// Create new feedback.
				$result = $this->wpdb->insert(
					$this->essay_feedback_table,
					$data,
					$format
				);

				if ( false === $result ) {
					throw new \Exception( 'Failed to create essay feedback: ' . $this->wpdb->last_error );
				}

				$feedback_id = $this->wpdb->insert_id;
			}

			// Get the created/updated feedback.
			$feedback = $this->get_essay_feedbacks( array( 'feedback_id' => $feedback_id ) );

			if ( empty( $feedback ) ) {
				throw new \Exception( 'Failed to retrieve essay feedback after creation/update.' );
			}

			$this->wpdb->query( 'COMMIT' );
			return $feedback[0]; // Return the first (and should be only) feedback.
		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create or update a segment feedback.
	 *
	 * @param array $feedback_data {
	 *     Required. Segment feedback data to create or update.
	 *     @type int    $id               Optional. ID of the feedback to update. If not provided, a new feedback will be created.
	 *     @type int    $segment_id       Required. ID of the segment this feedback is for.
	 *     @type string $feedback_criteria Required. Criteria for the feedback (e.g., 'coherence', 'grammar', 'vocabulary', 'relevance').
	 *     @type string $feedback_language Required. Language of the feedback (e.g., 'en', 'vi').
	 *     @type string $source           Required. Source of the feedback (e.g., 'ai', 'human', 'guided').
	 *     @type string $cot_content      Optional. Chain of Thought content explaining the reasoning.
	 *     @type string $score_content    Optional. Scoring content or numerical evaluation.
	 *     @type string $feedback_content Optional. The actual feedback content.
	 *     @type bool   $is_preferred     Optional. Whether this feedback is the preferred default. Defaults to false.
	 *     @type int    $created_by       Optional. User ID who created the feedback. Defaults to current user ID.
	 * }
	 * @return array|WP_Error Created or updated segment feedback data or error.
	 * @throws \Exception If there is a database error.
	 */
	public function create_update_segment_feedback( $feedback_data ) {
		// Validate required fields.
		if ( empty( $feedback_data['segment_id'] ) ||
			empty( $feedback_data['feedback_criteria'] ) ||
			empty( $feedback_data['feedback_language'] ) ||
			empty( $feedback_data['source'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields for segment feedback.', array( 'status' => 400 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Check if the segment exists.
			$segment = $this->get_segments( array( 'segment_id' => $feedback_data['segment_id'] ) );
			if ( empty( $segment ) ) {
				throw new \Exception( 'Segment not found.' );
			}

			// Set created_by to current user if not provided.
			if ( empty( $feedback_data['created_by'] ) ) {
				$feedback_data['created_by'] = get_current_user_id();
			}

			// Prepare data for insertion/update.
			$data = array(
				'segment_id'        => $feedback_data['segment_id'],
				'feedback_criteria' => $feedback_data['feedback_criteria'],
				'feedback_language' => $feedback_data['feedback_language'],
				'source'            => $feedback_data['source'],
				'created_by'        => $feedback_data['created_by'],
			);

			$format = array(
				'%d', // segment_id.
				'%s', // feedback_criteria.
				'%s', // feedback_language.
				'%s', // source.
				'%d', // created_by.
			);

			// Add is_preferred if provided.
			if ( isset( $feedback_data['is_preferred'] ) ) {
				$data['is_preferred'] = $feedback_data['is_preferred'] ? 1 : 0;
				$format[]             = '%d';
			}

			// Add optional fields if provided.
			if ( isset( $feedback_data['cot_content'] ) ) {
				$data['cot_content'] = $feedback_data['cot_content'];
				$format[]            = '%s';
			}

			if ( isset( $feedback_data['score_content'] ) ) {
				$data['score_content'] = $feedback_data['score_content'];
				$format[]              = '%s';
			}

			if ( isset( $feedback_data['feedback_content'] ) ) {
				$data['feedback_content'] = $feedback_data['feedback_content'];
				$format[]                 = '%s';
			}

			// Determine if we're creating or updating.
			if ( ! empty( $feedback_data['id'] ) ) {
				// Update existing feedback.
				$feedback_id = $feedback_data['id'];

				// Check if feedback exists.
				$existing_feedback = $this->get_segment_feedbacks( array( 'feedback_id' => $feedback_id ) );
				if ( empty( $existing_feedback ) ) {
					throw new \Exception( 'Segment feedback not found.' );
				}

				$result = $this->wpdb->update(
					$this->segment_feedback_table,
					$data,
					array( 'id' => $feedback_id ),
					$format,
					array( '%d' )
				);

				if ( false === $result ) {
					throw new \Exception( 'Failed to update segment feedback: ' . $this->wpdb->last_error );
				}
			} else {
				// Create new feedback.
				$result = $this->wpdb->insert(
					$this->segment_feedback_table,
					$data,
					$format
				);

				if ( false === $result ) {
					throw new \Exception( 'Failed to create segment feedback: ' . $this->wpdb->last_error );
				}

				$feedback_id = $this->wpdb->insert_id;
			}

			// Get the created/updated feedback.
			$feedback = $this->get_segment_feedbacks( array( 'feedback_id' => $feedback_id ) );

			if ( empty( $feedback ) ) {
				throw new \Exception( 'Failed to retrieve segment feedback after creation/update.' );
			}

			$this->wpdb->query( 'COMMIT' );
			return $feedback[0]; // Return the first (and should be only) feedback.
		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Set a feedback as preferred for its associated essay and criteria.
	 * This will unset any other preferred feedback for the same essay and criteria.
	 *
	 * @param int $feedback_id ID of the essay feedback to set as preferred.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 * @throws \Exception If there is a database error.
	 */
	public function set_preferred_essay_feedback( $feedback_id ) {
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Get the feedback to validate and get essay_id and criteria.
			$feedback = $this->get_essay_feedbacks( array( 'feedback_id' => $feedback_id ) );
			if ( empty( $feedback ) ) {
				throw new \Exception( 'Essay feedback not found.' );
			}

			$feedback = $feedback[0];
			$essay_id = $feedback['essay_id'];
			$criteria = $feedback['feedback_criteria'];

			// First unset any other preferred feedback for this essay and criteria.
			$this->wpdb->update(
				$this->essay_feedback_table,
				array( 'is_preferred' => 0 ),
				array(
					'essay_id'          => $essay_id,
					'feedback_criteria' => $criteria,
					'is_preferred'      => 1,
				),
				array( '%d' ),
				array( '%d', '%s', '%d' )
			);

			// Now set this feedback as preferred.
			$result = $this->wpdb->update(
				$this->essay_feedback_table,
				array( 'is_preferred' => 1 ),
				array( 'id' => $feedback_id ),
				array( '%d' ),
				array( '%d' )
			);

			if ( false === $result ) {
				throw new \Exception( 'Failed to set preferred essay feedback: ' . $this->wpdb->last_error );
			}

			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Set a feedback as preferred for its associated segment and criteria.
	 * This will unset any other preferred feedback for the same segment and criteria.
	 *
	 * @param int $feedback_id ID of the segment feedback to set as preferred.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 * @throws \Exception If there is a database error.
	 */
	public function set_preferred_segment_feedback( $feedback_id ) {
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Get the feedback to validate and get segment_id and criteria.
			$feedback = $this->get_segment_feedbacks( array( 'feedback_id' => $feedback_id ) );
			if ( empty( $feedback ) ) {
				throw new \Exception( 'Segment feedback not found.' );
			}

			$feedback   = $feedback[0];
			$segment_id = $feedback['segment_id'];
			$criteria   = $feedback['feedback_criteria'];

			// First unset any other preferred feedback for this segment and criteria.
			$this->wpdb->update(
				$this->segment_feedback_table,
				array( 'is_preferred' => 0 ),
				array(
					'segment_id'        => $segment_id,
					'feedback_criteria' => $criteria,
					'is_preferred'      => 1,
				),
				array( '%d' ),
				array( '%d', '%s', '%d' )
			);

			// Now set this feedback as preferred.
			$result = $this->wpdb->update(
				$this->segment_feedback_table,
				array( 'is_preferred' => 1 ),
				array( 'id' => $feedback_id ),
				array( '%d' ),
				array( '%d' )
			);

			if ( false === $result ) {
				throw new \Exception( 'Failed to set preferred segment feedback: ' . $this->wpdb->last_error );
			}

			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Fork (duplicate) an essay along with all its related data.
	 *
	 * This function creates a copy of an existing essay including its segments,
	 * segment feedback, and essay feedback. The new essay will reference the original
	 * essay via the original_id field.
	 *
	 * @param int   $essay_id    ID of the essay to fork.
	 * @param int   $user_id     ID of the user creating the fork. If null, uses current user.
	 * @param array $options     Optional. Additional options for forking.
	 *     @var bool $copy_segments        Whether to copy segments. Default true.
	 *     @var bool $copy_segment_feedback Whether to copy segment feedback. Default true.
	 *     @var bool $copy_essay_feedback  Whether to copy essay feedback. Default true.
	 *     @var bool $generate_new_uuid    Whether to generate a new UUID. Default true.
	 *
	 * @return array|WP_Error Array containing the new essay ID and related data, or WP_Error on failure.
	 * @throws \Exception If there is a database error.
	 */
	public function fork_essay( $essay_id, $user_id = null, $options = array() ) {
		// 1. Validate inputs.
		if ( empty( $essay_id ) ) {
			return new WP_Error( 'invalid_essay_id', 'Invalid essay ID provided.', array( 'status' => 400 ) );
		}

		// Set user_id to current user if not provided.
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
			if ( ! $user_id ) {
				return new WP_Error( 'no_user', 'No user ID provided and no current user.', array( 'status' => 400 ) );
			}
		}

		// 2. Set default options.
		$defaults = array(
			'copy_segments'         => true,
			'copy_segment_feedback' => true,
			'copy_essay_feedback'   => true,
			'generate_new_uuid'     => true,
		);
		$options  = wp_parse_args( $options, $defaults );

		// 3. Start database transaction.
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// 4. Get the original essay data.
			$original_essay = $this->get_essays( array( 'id' => $essay_id ) );
			if ( empty( $original_essay ) ) {
				throw new \Exception( 'Original essay not found.' );
			}
			$original_essay = $original_essay[0];

			// 5. Create a copy of the essay.
			$new_essay_data = array(
				'uuid'            => $options['generate_new_uuid'] ? wp_generate_uuid4() : $original_essay['uuid'],
				'original_id'     => $original_essay['id'],
				'ocr_image_ids'   => $original_essay['ocr_image_ids'],
				'chart_image_ids' => $original_essay['chart_image_ids'],
				'essay_type'      => $original_essay['essay_type'],
				'question'        => $original_essay['question'],
				'essay_content'   => $original_essay['essay_content'],
				'created_by'      => $user_id,
			);

			$new_essay = $this->create_essay( $new_essay_data );
			if ( is_wp_error( $new_essay ) ) {
				throw new \Exception( 'Failed to create forked essay: ' . $new_essay->get_error_message() );
			}

			$result = array(
				'essay'            => $new_essay,
				'copied_segments'  => array(),
				'segment_feedback' => array(),
				'essay_feedback'   => array(),
			);

			// 6. Copy segments if specified.
			if ( $options['copy_segments'] ) {
				$original_segments = $this->get_segments(
					array(
						'essay_id' => $essay_id,
						'orderby'  => 'order',
						'order'    => 'ASC',
						'number'   => 100, // Get all segments.
					)
				);

				if ( ! empty( $original_segments ) && ! is_wp_error( $original_segments ) ) {
					foreach ( $original_segments as $segment ) {
						$new_segment_data = array(
							'essay_id' => $new_essay['id'],
							'type'     => $segment['type'],
							'order'    => $segment['order'],
							'title'    => $segment['title'],
							'content'  => $segment['content'],
						);

						$new_segment = $this->create_update_segment( $new_segment_data );
						if ( is_wp_error( $new_segment ) ) {
							throw new \Exception( 'Failed to create segment copy: ' . $new_segment->get_error_message() );
						}

						// Add to mapping of original to new segment IDs.
						$result['copied_segments'][ $segment['id'] ] = $new_segment;

						// 7. Copy segment feedback if specified.
						if ( $options['copy_segment_feedback'] ) {
							$segment_feedbacks = $this->get_segment_feedbacks(
								array(
									'segment_id' => $segment['id'],
									'limit'      => 100, // Get all feedback.
								)
							);

							if ( ! empty( $segment_feedbacks ) && ! is_wp_error( $segment_feedbacks ) ) {
								foreach ( $segment_feedbacks as $feedback ) {
									$new_feedback_data = array(
										'segment_id'       => $new_segment['id'],
										'feedback_criteria' => $feedback['feedback_criteria'],
										'feedback_language' => $feedback['feedback_language'],
										'source'           => $feedback['source'],
										'cot_content'      => $feedback['cot_content'],
										'score_content'    => $feedback['score_content'],
										'feedback_content' => $feedback['feedback_content'],
										'is_preferred'     => $feedback['is_preferred'],
										'created_by'       => $user_id,
									);

									$new_feedback = $this->create_update_segment_feedback( $new_feedback_data );
									if ( is_wp_error( $new_feedback ) ) {
										throw new \Exception( 'Failed to create segment feedback copy: ' . $new_feedback->get_error_message() );
									}
									$result['segment_feedback'][] = $new_feedback;
								}
							}
						}
					}
				}
			}

			// 8. Copy essay feedback if specified.
			if ( $options['copy_essay_feedback'] ) {
				$essay_feedbacks = $this->get_essay_feedbacks(
					array(
						'essay_id' => $essay_id,
						'number'   => 100, // Get all feedback.
					)
				);

				if ( ! empty( $essay_feedbacks ) && ! is_wp_error( $essay_feedbacks ) ) {
					foreach ( $essay_feedbacks as $feedback ) {
						$new_feedback_data = array(
							'essay_id'          => $new_essay['id'],
							'feedback_criteria' => $feedback['feedback_criteria'],
							'feedback_language' => $feedback['feedback_language'],
							'source'            => $feedback['source'],
							'cot_content'       => $feedback['cot_content'],
							'score_content'     => $feedback['score_content'],
							'feedback_content'  => $feedback['feedback_content'],
							'is_preferred'      => $feedback['is_preferred'],
							'created_by'        => $user_id,
						);

						$new_feedback = $this->create_update_essay_feedback( $new_feedback_data );
						if ( is_wp_error( $new_feedback ) ) {
							throw new \Exception( 'Failed to create essay feedback copy: ' . $new_feedback->get_error_message() );
						}
						$result['essay_feedback'][] = $new_feedback;
					}
				}
			}

			// 9. Commit transaction.
			$this->wpdb->query( 'COMMIT' );
			return $result;
		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'fork_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
