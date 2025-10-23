<?php
/**
 * IELTS Science LMS Speech Database Handler
 *
 * This file contains the database operations class for managing IELTS speaking records,
 * including creating, retrieving, and managing speech data and feedback.
 *
 * @package IELTS_Science_LMS
 * @subpackage Speaking
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Speaking;

use WP_Error;
use wpdb;
use Exception;

/**
 * Class Ieltssci_Speech_DB
 *
 * Handles database operations for IELTS Science LMS speaking recordings.
 *
 * @since 1.0.0
 */
class Ieltssci_Speech_DB {
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
	 * Speech table name.
	 *
	 * @var string
	 */
	private $speech_table;

	/**
	 * Speech feedback table name.
	 *
	 * @var string
	 */
	private $speech_feedback_table;

	/**
	 * Speech meta table name.
	 *
	 * @var string
	 */
	private $speech_meta_table;

	/**
	 * Speech attempt table name.
	 *
	 * @var string
	 */
	private $speech_attempt_table;

	/**
	 * Speech attempt feedback table name.
	 *
	 * @var string
	 */
	private $speech_attempt_feedback_table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;

		$this->speech_table                  = $this->wpdb->prefix . self::TABLE_PREFIX . 'speech';
		$this->speech_feedback_table         = $this->wpdb->prefix . self::TABLE_PREFIX . 'speech_feedback';
		$this->speech_meta_table             = $this->wpdb->prefix . self::TABLE_PREFIX . 'speech_meta';
		$this->speech_attempt_table          = $this->wpdb->prefix . self::TABLE_PREFIX . 'speech_attempt';
		$this->speech_attempt_feedback_table = $this->wpdb->prefix . self::TABLE_PREFIX . 'speech_attempt_feedback';

		// Register meta tables with WordPress metadata API.
		$this->register_meta_tables();
	}

	/**
	 * Registers custom meta tables with WordPress metadata API.
	 *
	 * Following WordPress pattern for custom meta tables as described in:
	 * https://www.ibenic.com/working-with-custom-tables-in-wordpress-meta-tables/
	 */
	private function register_meta_tables() {
		// Register speech meta table.
		$this->wpdb->ieltssci_speechmeta = $this->speech_meta_table;
	}

	/**
	 * Create a new speech recording.
	 *
	 * @param array $speech_data {
	 *     Speech data for creating a new recording.
	 *
	 *     @var int[]  $audio_ids  Optional. Array of attachment IDs for audio files.
	 *     @var array  $transcript Optional. Array of transcripts keyed by attachment ID.
	 *     @var string $uuid       Optional. UUID for the speech recording. Generated if not provided.
	 *     @var int    $created_by Optional. User ID who created the speech. Defaults to current user.
	 * }
	 * @return array|WP_Error Created speech data or error.
	 * @throws Exception If there is a database error.
	 */
	public function create_speech( $speech_data ) {
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Generate UUID if not provided.
			$uuid = ! empty( $speech_data['uuid'] ) ? $speech_data['uuid'] : wp_generate_uuid4();

			// Set created_by to current user if not provided.
			$created_by = ! empty( $speech_data['created_by'] ) ? $speech_data['created_by'] : get_current_user_id();

			$data = array(
				'uuid'       => $uuid,
				'audio_ids'  => isset( $speech_data['audio_ids'] ) && is_array( $speech_data['audio_ids'] ) ? json_encode( $speech_data['audio_ids'] ) : json_encode( array() ),
				'created_by' => $created_by,
			);

			$format = array(
				'%s', // uuid.
				'%s', // audio_ids.
				'%d', // created_by.
			);

			// Create new speech.
			$result = $this->wpdb->insert( $this->speech_table, $data, $format );

			if ( false === $result ) {
				throw new Exception( 'Failed to create speech: ' . $this->wpdb->last_error );
			}

			$speech_id = $this->wpdb->insert_id;

			// Handle transcript data in post meta if provided.
			if ( ! empty( $speech_data['transcript'] ) && is_array( $speech_data['transcript'] ) ) {
				// Decode the audio_ids from the data array.
				$audio_ids = json_decode( $data['audio_ids'], true );

				foreach ( $speech_data['transcript'] as $attachment_id => $transcript_data ) {
					// Make sure the attachment ID is in the audio_ids array.
					if ( in_array( (int) $attachment_id, $audio_ids, true ) ) {
						update_post_meta( (int) $attachment_id, 'ieltssci_audio_transcription', $transcript_data ); // Store transcript per attachment.
					}
				}
			}

			// Get the created speech.
			$speech = $this->get_speeches( array( 'id' => $speech_id ) );

			if ( empty( $speech ) ) {
				throw new Exception( 'Failed to retrieve speech after creation.' );
			}

			$this->wpdb->query( 'COMMIT' );
			$result_speech = $speech[0]; // The first (and should be only) speech.

			// Add transcript data from post meta.
			$result_speech['transcript'] = $this->get_speech_transcript( $result_speech );

			return $result_speech;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update an existing speech recording by ID or UUID.
	 *
	 * @param array $where {
	 *     Required. Identifier for the speech to update. Must contain either 'id' or 'uuid'.
	 *
	 *     @var int    $id   Optional. Speech ID to update.
	 *     @var string $uuid Optional. Speech UUID to update.
	 * }
	 * @param array $speech_data {
	 *     Optional. Speech data to update. All fields are optional and will fall back to existing values if not provided.
	 *
	 *     @var int[]  $audio_ids  Optional. Array of attachment IDs for audio files.
	 *     @var array  $transcript Optional. Array of transcripts keyed by attachment ID.
	 *     @var string $uuid       Optional. New UUID for the speech recording.
	 *     @var int    $created_by Optional. New user ID who created the speech.
	 * }
	 * @return array|WP_Error Updated speech data or error.
	 * @throws Exception If there is a database error.
	 */
	public function update_speech( $where, $speech_data = array() ) {
		if ( empty( $where['id'] ) && empty( $where['uuid'] ) ) {
			return new WP_Error( 'missing_identifier', 'Missing speech identifier (id or uuid).', array( 'status' => 400 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Find the existing speech.
			$finder = array();
			if ( ! empty( $where['id'] ) ) {
				$finder['id'] = absint( $where['id'] );
			}
			if ( ! empty( $where['uuid'] ) ) {
				$finder['uuid'] = $where['uuid'];
			}

			$existing_list = $this->get_speeches( array_merge( $finder, array( 'per_page' => 1 ) ) );
			if ( is_wp_error( $existing_list ) || empty( $existing_list ) ) {
				throw new Exception( 'Speech not found for update.' );
			}
			$existing = $existing_list[0];

			// Prepare new values, falling back to existing values if not provided.
			$new_uuid    = ! empty( $speech_data['uuid'] ) ? $speech_data['uuid'] : $existing['uuid'];
			$new_audio   = array_key_exists( 'audio_ids', $speech_data )
				? ( is_array( $speech_data['audio_ids'] ) ? json_encode( $speech_data['audio_ids'] ) : $speech_data['audio_ids'] )
				: json_encode( is_array( $existing['audio_ids'] ) ? $existing['audio_ids'] : array() );
			$new_creator = array_key_exists( 'created_by', $speech_data ) ? (int) $speech_data['created_by'] : (int) $existing['created_by'];

			$data   = array(
				'uuid'       => $new_uuid,
				'audio_ids'  => $new_audio,
				'created_by' => $new_creator,
			);
			$format = array( '%s', '%s', '%d' );

			$result = $this->wpdb->update(
				$this->speech_table,
				$data,
				array( 'id' => (int) $existing['id'] ),
				$format,
				array( '%d' )
			);

			if ( false === $result ) {
				throw new Exception( 'Failed to update speech: ' . $this->wpdb->last_error );
			}

			// Handle transcript data in post meta if provided.
			if ( ! empty( $speech_data['transcript'] ) && is_array( $speech_data['transcript'] ) ) {
				// Resolve the latest set of audio IDs to validate transcript keys.
				$final_audio_ids = json_decode( $data['audio_ids'], true );
				foreach ( $speech_data['transcript'] as $attachment_id => $transcript_data ) {
					if ( in_array( (int) $attachment_id, $final_audio_ids, true ) ) {
						update_post_meta( (int) $attachment_id, 'ieltssci_audio_transcription', $transcript_data ); // Update transcript per attachment.
					}
				}
			}

			// Get the updated speech.
			$speech = $this->get_speeches( array( 'id' => (int) $existing['id'] ) );

			if ( empty( $speech ) ) {
				throw new Exception( 'Failed to retrieve speech after update.' );
			}

			$this->wpdb->query( 'COMMIT' );
			$result_speech               = $speech[0];
			$result_speech['transcript'] = $this->get_speech_transcript( $result_speech );
			return $result_speech;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get speech recordings with flexible query arguments.
	 *
	 * Retrieves speech records from the database based on the provided query arguments.
	 * Supports filtering by ID, UUID, creator, and date ranges. Also offers pagination,
	 * ordering, and counting functionality.
	 *
	 * @param array $args {
	 *     Optional. Arguments to filter and control speech record retrieval.
	 *
	 *     @var int|array|null     $id         Optional. Speech ID(s) to filter by.
	 *     @var string|array|null  $uuid       Optional. Speech UUID(s) to filter by.
	 *     @var int|array|null     $created_by Optional. User ID(s) who created the speech.
	 *     @var array|null         $date_query Optional. Date query parameters.
	 *         @var string         $after      Optional. Retrieve records created after this date.
	 *         @var string         $before     Optional. Retrieve records created before this date.
	 *     @var string             $orderby    Optional. Field to order results by. Accepts 'id', 'uuid',
	 *                                          created_at', or 'created_by'. Default 'id'.
	 *     @var string             $order      Optional. Order direction. Accepts 'ASC' or 'DESC'. Default 'DESC'.
	 *     @var int                $per_page   Optional.   Number of records per page. Default 10.
	 *     @var int                $page       Optional.   Page number for pagination. Default 1.
	 *     @var bool               $count      Optional.   Whether to return only the count. Default false.
	 * }
	 * @return array|int|WP_Error Speech data array with transcript info, count of records, or error.
	 * @throws Exception If there is a database error.
	 */
	public function get_speeches( $args = array() ) {
		try {
			$defaults = array(
				'id'         => null,
				'uuid'       => null,
				'created_by' => null,
				'date_query' => null,
				'orderby'    => 'id',
				'order'      => 'DESC',
				'per_page'   => 10,
				'page'       => 1,
				'count'      => false,
			);

			$args = wp_parse_args( $args, $defaults );

			// Determine what to select.
			$select         = $args['count'] ? 'COUNT(*)' : '*';
			$from           = $this->speech_table;
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

			// Process date query.
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
				$allowed_orderby = array( 'id', 'uuid', 'created_at', 'created_by' );
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
					throw new Exception( $this->wpdb->last_error );
				}
				return (int) $result;
			} else {
				$results = $this->wpdb->get_results( $prepared_sql, ARRAY_A );
				if ( null === $results && $this->wpdb->last_error ) {
					throw new Exception( $this->wpdb->last_error );
				}

				// Process array fields for each result.
				foreach ( $results as &$speech ) {
					if ( ! empty( $speech['audio_ids'] ) ) {
						$speech['audio_ids'] = json_decode( $speech['audio_ids'], true );

						// Add transcript data from post meta.
						$speech['transcript'] = $this->get_speech_transcript( $speech );
					} else {
						$speech['audio_ids']  = array();
						$speech['transcript'] = null;
					}
				}

				return $results;
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve speeches: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get speech feedbacks with flexible query arguments.
	 *
	 * @param array $args Optional. Query arguments.
	 * @return array|int|WP_Error Feedbacks data, count, or error.
	 * @throws Exception If there is a database error.
	 */
	public function get_speech_feedbacks( $args = array() ) {
		try {
			$defaults = array(
				'feedback_id'       => null,
				'speech_id'         => null,
				'source'            => null,
				'feedback_criteria' => null,
				'feedback_language' => null,
				'created_by'        => null,
				'date_query'        => null,
				'orderby'           => 'id',
				'order'             => 'DESC',
				'number'            => 10,
				'offset'            => 0,
				'include_cot'       => true,
				'include_score'     => true,
				'include_feedback'  => true,
				'count'             => false,
			);

			$args = wp_parse_args( $args, $defaults );

			// Determine fields to select.
			if ( $args['count'] ) {
				$select = 'COUNT(*)';
			} else {
				$fields = array( 'id', 'feedback_criteria', 'speech_id', 'feedback_language', 'source', 'created_at', 'created_by' );
				if ( $args['include_cot'] ) {
					$fields[] = 'cot_content';
				}
				if ( $args['include_score'] ) {
					$fields[] = 'score_content';
				}
				if ( $args['include_feedback'] ) {
					$fields[] = 'feedback_content';
				}
				$select = implode( ', ', $fields );
			}

			$from           = $this->speech_feedback_table;
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

			// Build where clauses and prepare values.
			$field_types = array(
				'speech_id'         => '%d',
				'source'            => '%s',
				'feedback_criteria' => '%s',
				'feedback_language' => '%s',
				'created_by'        => '%d',
			);

			foreach ( $field_types as $field => $format ) {
				if ( ! is_null( $args[ $field ] ) ) {
					if ( is_array( $args[ $field ] ) ) {
						$placeholders   = array_fill( 0, count( $args[ $field ] ), $format );
						$where[]        = "$field IN (" . implode( ',', $placeholders ) . ')';
						$prepare_values = array_merge( $prepare_values, $args[ $field ] );
					} else {
						$where[]          = "$field = $format";
						$prepare_values[] = $args[ $field ];
					}
				}
			}

			// Process date query.
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

			if ( ! $args['count'] ) {
				// Add order.
				$allowed_orderby = array( 'id', 'speech_id', 'created_at' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
				$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
				$sql            .= " ORDER BY $orderby $order";

				// Add pagination.
				$sql             .= ' LIMIT %d OFFSET %d';
				$prepare_values[] = $args['number'];
				$prepare_values[] = $args['offset'];
			}

			$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );

			return $args['count']
				? (int) $this->wpdb->get_var( $prepared_sql )
				: $this->wpdb->get_results( $prepared_sql, ARRAY_A );

		} catch ( Exception $e ) {
			return new WP_Error( 'database_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create a speech feedback entry.
	 *
	 * @param array $feedback_data {
	 *     Required. Speech feedback data.
	 *
	 *     @var int    $speech_id          Required. ID of the associated speech.
	 *     @var string $feedback_criteria  Required. Criteria for the feedback.
	 *     @var string $feedback_language  Required. Language of the feedback.
	 *     @var string $source             Required. Source of the feedback ('ai' or 'human').
	 *     @var int    $created_by         Optional. User ID who created the feedback.
	 *     @var string $cot_content        Optional. Chain-of-thought content.
	 *     @var string $score_content      Optional. Scoring content.
	 *     @var string $feedback_content   Optional. Feedback content.
	 *     @var bool   $is_preferred       Optional. Whether this feedback is preferred.
	 * }
	 * @return array|WP_Error Created feedback data or error.
	 * @throws Exception If there is a database error.
	 */
	public function create_speech_feedback( $feedback_data ) {
		if ( empty( $feedback_data['speech_id'] ) ||
			empty( $feedback_data['feedback_criteria'] ) ||
			empty( $feedback_data['feedback_language'] ) ||
			empty( $feedback_data['source'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields.', array( 'status' => 400 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			$data = array(
				'speech_id'         => $feedback_data['speech_id'],
				'feedback_criteria' => $feedback_data['feedback_criteria'],
				'feedback_language' => $feedback_data['feedback_language'],
				'source'            => $feedback_data['source'],
				'created_by'        => $feedback_data['created_by'] ?? get_current_user_id(),
			);

			// Optional fields.
			$optional_fields = array( 'cot_content', 'score_content', 'feedback_content', 'is_preferred' );
			foreach ( $optional_fields as $field ) {
				if ( isset( $feedback_data[ $field ] ) ) {
					$data[ $field ] = $feedback_data[ $field ];
				}
			}

			$result      = $this->wpdb->insert(
				$this->speech_feedback_table,
				$data
			);
			$feedback_id = $this->wpdb->insert_id;

			if ( false === $result ) {
				throw new Exception( $this->wpdb->last_error );
			}

			$feedback = $this->get_speech_feedbacks( array( 'feedback_id' => $feedback_id ) )[0];

			$this->wpdb->query( 'COMMIT' );
			return $feedback;

		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update an existing speech feedback entry.
	 *
	 * @param int   $feedback_id   Required. ID of the feedback to update.
	 * @param array $feedback_data {
	 *     Optional. Fields to update for this feedback.
	 *
	 *     @var int    $speech_id          Optional. ID of the associated speech.
	 *     @var string $feedback_criteria  Optional. Criteria for the feedback.
	 *     @var string $feedback_language  Optional. Language of the feedback.
	 *     @var string $source             Optional. Source of the feedback ('ai' or 'human').
	 *     @var int    $created_by         Optional. User ID who created the feedback.
	 *     @var string $cot_content        Optional. Chain-of-thought content.
	 *     @var string $score_content      Optional. Scoring content.
	 *     @var string $feedback_content   Optional. Feedback content.
	 *     @var bool   $is_preferred       Optional. Whether this feedback is preferred.
	 * }
	 * @return array|WP_Error Updated feedback data or error.
	 * @throws Exception If there is a database error.
	 */
	public function update_speech_feedback( $feedback_id, $feedback_data = array() ) {
		$feedback_id = absint( $feedback_id );
		if ( ! $feedback_id ) {
			return new WP_Error( 'missing_id', 'Missing or invalid feedback ID.', array( 'status' => 400 ) );
		}

		// Whitelist fields that can be updated.
		$updatable_fields = array( 'speech_id', 'feedback_criteria', 'feedback_language', 'source', 'created_by', 'cot_content', 'score_content', 'feedback_content', 'is_preferred' );
		$data             = array();
		foreach ( $updatable_fields as $field ) {
			if ( array_key_exists( $field, $feedback_data ) ) {
				$data[ $field ] = $feedback_data[ $field ];
			}
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'no_changes', 'No fields provided to update.', array( 'status' => 400 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			$result = $this->wpdb->update(
				$this->speech_feedback_table,
				$data,
				array( 'id' => $feedback_id ),
				null,
				array( '%d' )
			);

			if ( false === $result ) {
				throw new Exception( $this->wpdb->last_error );
			}

			$feedback = $this->get_speech_feedbacks( array( 'feedback_id' => $feedback_id ) )[0];

			$this->wpdb->query( 'COMMIT' );
			return $feedback;

		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Set a feedback as preferred for its associated speech and criteria.
	 *
	 * @param int $feedback_id ID of the speech feedback to set as preferred.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 * @throws Exception If there is a database error.
	 */
	public function set_preferred_speech_feedback( $feedback_id ) {
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			$feedback = $this->get_speech_feedbacks( array( 'feedback_id' => $feedback_id ) )[0];
			if ( empty( $feedback ) ) {
				throw new Exception( 'Feedback not found' );
			}

			// Unset other preferred feedbacks.
			$this->wpdb->update(
				$this->speech_feedback_table,
				array( 'is_preferred' => 0 ),
				array(
					'speech_id'         => $feedback['speech_id'],
					'feedback_criteria' => $feedback['feedback_criteria'],
					'is_preferred'      => 1,
				)
			);

			// Set this feedback as preferred.
			$result = $this->wpdb->update(
				$this->speech_feedback_table,
				array( 'is_preferred' => 1 ),
				array( 'id' => $feedback_id )
			);

			if ( false === $result ) {
				throw new Exception( $this->wpdb->last_error );
			}

			$this->wpdb->query( 'COMMIT' );
			return true;

		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get speech attempt feedbacks with flexible query arguments.
	 *
	 * Supports filtering by feedback ID, attempt, creator, criteria, language, and date ranges.
	 * Can also filter by a parent speech using speech_id or speech_uuid by linking through attempts' audio IDs.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *     @var int|array       $feedback_id       Optional. Feedback ID(s) to filter by.
	 *     @var int|array       $attempt_id        Optional. Attempt ID(s) to filter by.
	 *     @var int|array       $created_by        Optional. User ID(s) who created the feedback.
	 *     @var string|array    $feedback_criteria Optional. Criteria of the feedback.
	 *     @var string|array    $feedback_language Optional. Language of the feedback.
	 *     @var string|array    $source            Optional. Source of the feedback ('ai'|'human'|'guided').
	 *     @var array|null      $date_query        Optional. Date query with 'after' and/or 'before'.
	 *     @var int|string|null $speech_id         Optional. Parent speech ID to constrain results via attempts' audio IDs.
	 *     @var string|null     $speech_uuid       Optional. Parent speech UUID to constrain results via attempts' audio IDs.
	 *     @var string          $orderby           Optional. Field to order by. One of 'id','attempt_id','created_at'. Default 'id'.
	 *     @var string          $order             Optional. 'ASC' or 'DESC'. Default 'DESC'.
	 *     @var int             $limit             Optional. Number of results to return. Default 10.
	 *     @var int             $offset            Optional. Offset for pagination. Default 0.
	 *     @var bool            $include_cot       Optional. Include Chain-of-thought content. Default true.
	 *     @var bool            $include_score     Optional. Include score content. Default true.
	 *     @var bool            $include_feedback  Optional. Include feedback content. Default true.
	 *     @var bool            $count             Optional. If true, return only the count. Default false.
	 * }
	 * @return array|int|WP_Error Feedbacks data, count, or error.
	 */
	public function get_speech_attempt_feedbacks( $args = array() ) {
		try {
			$defaults = array(
				'feedback_id'       => null,
				'attempt_id'        => null,
				'created_by'        => null,
				'feedback_criteria' => null,
				'feedback_language' => null,
				'source'            => null,
				'date_query'        => null,
				'speech_id'         => null,
				'speech_uuid'       => null,
				'orderby'           => 'id',
				'order'             => 'DESC',
				'limit'             => 10,
				'offset'            => 0,
				'include_cot'       => true,
				'include_score'     => true,
				'include_feedback'  => true,
				'count'             => false,
			);

			$args = wp_parse_args( $args, $defaults );

			// Determine fields to select.
			if ( $args['count'] ) {
				$select = 'COUNT(*)';
			} else {
				$fields = array( 'id', 'attempt_id', 'feedback_criteria', 'feedback_language', 'source', 'created_at', 'created_by', 'is_preferred' );
				if ( $args['include_cot'] ) {
					$fields[] = 'cot_content';
				}
				if ( $args['include_score'] ) {
					$fields[] = 'score_content';
				}
				if ( $args['include_feedback'] ) {
					$fields[] = 'feedback_content';
				}
				$select = implode( ', ', $fields );
			}

			$from           = $this->speech_attempt_feedback_table . ' saf';
			$where          = array( '1=1' );
			$prepare_values = array();

			// Optional constraint by parent speech via attempts' audio IDs.
			if ( ! empty( $args['speech_id'] ) || ! empty( $args['speech_uuid'] ) ) {
				$speeches = $this->get_speeches(
					array(
						'id'       => $args['speech_id'] ?? null,
						'uuid'     => $args['speech_uuid'] ?? null,
						'per_page' => 1,
					)
				);
				if ( ! empty( $speeches ) && ! is_wp_error( $speeches ) ) {
					$audio_ids = $speeches[0]['audio_ids'] ?? array();
					if ( ! empty( $audio_ids ) ) {
						$placeholders   = array_fill( 0, count( $audio_ids ), '%d' );
						$where[]        = 'saf.attempt_id IN (SELECT id FROM ' . $this->speech_attempt_table . ' WHERE audio_id IN (' . implode( ',', $placeholders ) . '))';
						$prepare_values = array_merge( $prepare_values, array_map( 'intval', $audio_ids ) );
					} else {
						// No audio in speech means no attempt feedback.
						return $args['count'] ? 0 : array();
					}
				} else {
					// No speech found, return empty.
					return $args['count'] ? 0 : array();
				}
			}

			// Process feedback_id filter.
			if ( ! is_null( $args['feedback_id'] ) ) {
				if ( is_array( $args['feedback_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['feedback_id'] ), '%d' );
					$where[]        = 'saf.id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['feedback_id'] );
				} else {
					$where[]          = 'saf.id = %d';
					$prepare_values[] = $args['feedback_id'];
				}
			}

			$field_types = array(
				'attempt_id'        => '%d',
				'created_by'        => '%d',
				'feedback_criteria' => '%s',
				'feedback_language' => '%s',
				'source'            => '%s',
			);
			foreach ( $field_types as $field => $fmt ) {
				if ( ! is_null( $args[ $field ] ) ) {
					if ( is_array( $args[ $field ] ) ) {
						$placeholders   = array_fill( 0, count( $args[ $field ] ), $fmt );
						$where[]        = 'saf.' . $field . ' IN (' . implode( ',', $placeholders ) . ')';
						$prepare_values = array_merge( $prepare_values, $args[ $field ] );
					} else {
						$where[]          = 'saf.' . $field . ' = ' . $fmt;
						$prepare_values[] = $args[ $field ];
					}
				}
			}

			// Process date query.
			if ( ! is_null( $args['date_query'] ) && is_array( $args['date_query'] ) ) {
				if ( ! empty( $args['date_query']['after'] ) ) {
					$where[]          = 'saf.created_at >= %s';
					$prepare_values[] = $args['date_query']['after'];
				}
				if ( ! empty( $args['date_query']['before'] ) ) {
					$where[]          = 'saf.created_at <= %s';
					$prepare_values[] = $args['date_query']['before'];
				}
			}

			// Build query.
			$sql = 'SELECT ' . $select . ' FROM ' . $from . ' WHERE ' . implode( ' AND ', $where );

			if ( ! $args['count'] ) {
				$allowed_orderby = array( 'id', 'attempt_id', 'created_at' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
				$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
				$sql            .= " ORDER BY saf.$orderby $order";

				$sql             .= ' LIMIT %d OFFSET %d';
				$prepare_values[] = max( 1, (int) $args['limit'] );
				$prepare_values[] = max( 0, (int) $args['offset'] );
			}

			$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );
			return $args['count'] ? (int) $this->wpdb->get_var( $prepared_sql ) : $this->wpdb->get_results( $prepared_sql, ARRAY_A );
		} catch ( Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve speech attempt feedbacks: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create a speech attempt feedback entry.
	 *
	 * @param array $feedback_data {
	 *     Required. Attempt feedback data.
	 *     @var int    $attempt_id        Required. ID of the associated attempt.
	 *     @var string $feedback_criteria Required. Criteria for the feedback.
	 *     @var string $feedback_language Required. Language of the feedback.
	 *     @var string $source            Required. Source of the feedback ('ai' or 'human').
	 *     @var int    $created_by        Optional. User ID who created the feedback.
	 *     @var string $cot_content       Optional. Chain-of-thought content.
	 *     @var string $score_content     Optional. Scoring content.
	 *     @var string $feedback_content  Optional. Feedback content.
	 *     @var bool   $is_preferred      Optional. Whether this feedback is preferred.
	 * }
	 * @return array|WP_Error Created feedback data or error.
	 *
	 * @throws Exception If there is a database error.
	 */
	public function create_speech_attempt_feedback( $feedback_data ) {
		if ( empty( $feedback_data['attempt_id'] ) || empty( $feedback_data['feedback_criteria'] ) || empty( $feedback_data['feedback_language'] ) || empty( $feedback_data['source'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields.', array( 'status' => 400 ) );
		}

		// Validate attempt exists quickly without depending on Submission DB class.
		$attempt_exists = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . $this->speech_attempt_table . ' WHERE id = %d LIMIT 1', (int) $feedback_data['attempt_id'] ) );
		if ( ! $attempt_exists ) {
			return new WP_Error( 'attempt_not_found', 'Speech attempt not found.', array( 'status' => 404 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$data = array(
				'attempt_id'        => (int) $feedback_data['attempt_id'],
				'feedback_criteria' => $feedback_data['feedback_criteria'],
				'feedback_language' => $feedback_data['feedback_language'],
				'source'            => $feedback_data['source'],
				'created_by'        => $feedback_data['created_by'] ?? get_current_user_id(),
			);

			// Optional fields.
			$optional_fields = array( 'cot_content', 'score_content', 'feedback_content', 'is_preferred' );
			foreach ( $optional_fields as $field ) {
				if ( isset( $feedback_data[ $field ] ) ) {
					$data[ $field ] = $feedback_data[ $field ];
				}
			}

			$result = $this->wpdb->insert( $this->speech_attempt_feedback_table, $data );
			if ( false === $result ) {
				throw new Exception( $this->wpdb->last_error );
			}
			$feedback_id = (int) $this->wpdb->insert_id;

			$feedback = $this->get_speech_attempt_feedbacks( array( 'feedback_id' => $feedback_id ) );
			$feedback = is_array( $feedback ) && ! empty( $feedback ) ? $feedback[0] : null;

			$this->wpdb->query( 'COMMIT' );
			return $feedback;

		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update an existing speech attempt feedback.
	 *
	 * @param int   $feedback_id   Required. ID of the feedback to update.
	 * @param array $feedback_data {
	 *     Optional. Fields to update.
	 *
	 *     @var int    $attempt_id        The ID of the speech attempt this feedback belongs to.
	 *     @var string $feedback_criteria The criteria for the feedback.
	 *     @var string $feedback_language The language of the feedback.
	 *     @var string $source            The source of the feedback.
	 *     @var int    $created_by        The ID of the user who created the feedback.
	 *     @var string $cot_content       Chain of thought content.
	 *     @var string $score_content     Score content.
	 *     @var string $feedback_content  The actual feedback content.
	 *     @var bool   $is_preferred      Whether this feedback is preferred.
	 * }
	 * @return array|WP_Error Updated feedback data or error.
	 *
	 * @throws Exception If there is a database error.
	 */
	public function update_speech_attempt_feedback( $feedback_id, $feedback_data = array() ) {
		$feedback_id = absint( $feedback_id );
		if ( ! $feedback_id ) {
			return new WP_Error( 'missing_id', 'Missing or invalid feedback ID.', array( 'status' => 400 ) );
		}

		$updatable_fields = array( 'attempt_id', 'feedback_criteria', 'feedback_language', 'source', 'created_by', 'cot_content', 'score_content', 'feedback_content', 'is_preferred' );
		$data             = array();
		foreach ( $updatable_fields as $field ) {
			if ( array_key_exists( $field, $feedback_data ) ) {
				$data[ $field ] = $feedback_data[ $field ];
			}
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'no_changes', 'No fields provided to update.', array( 'status' => 400 ) );
		}

		// If updating attempt_id, validate the attempt exists.
		if ( isset( $data['attempt_id'] ) ) {
			$attempt_exists = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . $this->speech_attempt_table . ' WHERE id = %d LIMIT 1', (int) $data['attempt_id'] ) );
			if ( ! $attempt_exists ) {
				return new WP_Error( 'attempt_not_found', 'Speech attempt not found.', array( 'status' => 404 ) );
			}
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$result = $this->wpdb->update( $this->speech_attempt_feedback_table, $data, array( 'id' => $feedback_id ), null, array( '%d' ) );
			if ( false === $result ) {
				throw new Exception( $this->wpdb->last_error );
			}

			$feedback = $this->get_speech_attempt_feedbacks( array( 'feedback_id' => $feedback_id ) );
			$feedback = is_array( $feedback ) && ! empty( $feedback ) ? $feedback[0] : null;

			$this->wpdb->query( 'COMMIT' );
			return $feedback;

		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Set a feedback as preferred for its associated attempt and criteria.
	 *
	 * @param int $feedback_id ID of the speech attempt feedback to set as preferred.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 *
	 * @throws Exception If there is a database error.
	 */
	public function set_preferred_speech_attempt_feedback( $feedback_id ) {
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$feedback = $this->get_speech_attempt_feedbacks( array( 'feedback_id' => $feedback_id ) );
			$feedback = is_array( $feedback ) && ! empty( $feedback ) ? $feedback[0] : null;
			if ( empty( $feedback ) ) {
				throw new Exception( 'Feedback not found.' );
			}

			// Unset other preferred feedbacks.
			$this->wpdb->update(
				$this->speech_attempt_feedback_table,
				array( 'is_preferred' => 0 ),
				array(
					'attempt_id'        => $feedback['attempt_id'],
					'feedback_criteria' => $feedback['feedback_criteria'],
					'is_preferred'      => 1,
				)
			);

			// Set this feedback as preferred.
			$result = $this->wpdb->update( $this->speech_attempt_feedback_table, array( 'is_preferred' => 1 ), array( 'id' => $feedback_id ) );
			if ( false === $result ) {
				throw new Exception( $this->wpdb->last_error );
			}

			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Fork (duplicate) a speech recording.
	 *
	 * @param int      $speech_id ID of the speech to fork.
	 * @param int|null $user_id Optional. User ID creating the fork.
	 * @param array    $options Optional. Fork options.
	 *     @var bool $copy_speech_feedback   Whether to copy speech feedback. Default true.
	 *     @var bool $fork_speech_attempts    Whether to fork associated speech attempts. Default true.
	 *     @var bool $copy_attempt_feedback   Whether to copy attempt feedback when forking attempts. Default true.
	 *     @var bool $copy_attachment_meta    Whether to copy attachment metadata when forking attempts. Default true.
	 *     @var int  $submission_id           Optional. Submission ID to associate forked attempts with. If not provided, uses original attempt's submission_id.
	 * @return array|WP_Error New speech data or error.
	 * @throws Exception If there is a database error.
	 */
	public function fork_speech( $speech_id, $user_id = null, $options = array() ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'no_user', 'No user ID provided', array( 'status' => 400 ) );
		}

		// Set default options.
		$defaults = array(
			'copy_speech_feedback'  => true,
			'fork_speech_attempts'  => true,
			'copy_attempt_feedback' => true,
			'copy_attachment_meta'  => true,
			'submission_id'         => null,
		);
		$options  = wp_parse_args( $options, $defaults );

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Get original speech.
			$original = $this->get_speeches( array( 'id' => $speech_id ) )[0];
			if ( empty( $original ) ) {
				throw new Exception( 'Original speech not found' );
			}

			// Create new speech.
			$new_speech = $this->create_speech(
				array(
					'uuid'       => wp_generate_uuid4(),
					'audio_ids'  => $original['audio_ids'],
					'created_by' => $user_id,
				)
			);

			if ( is_wp_error( $new_speech ) ) {
				throw new Exception( $new_speech->get_error_message() );
			}

			// Add meta to track the original speech.
			$meta_result = $this->add_speech_meta( $new_speech['id'], 'original_speech', $speech_id, true );
			if ( is_wp_error( $meta_result ) ) {
				// Log but don't fail the operation.
				error_log( 'Failed to add fork meta to speech ' . $new_speech['id'] . ': ' . $meta_result->get_error_message() );
			}

			// Copy feedback if requested.
			$result = array(
				'speech'   => $new_speech,
				'feedback' => array(),
				'attempts' => array(),
			);

			if ( $options['copy_speech_feedback'] ) {
				$feedbacks = $this->get_speech_feedbacks( array( 'speech_id' => $speech_id ) );
				foreach ( $feedbacks as $feedback ) {
					$new_feedback = $this->create_speech_feedback(
						array(
							'speech_id'         => $new_speech['id'],
							'feedback_criteria' => $feedback['feedback_criteria'],
							'feedback_language' => $feedback['feedback_language'],
							'source'            => $feedback['source'],
							'cot_content'       => $feedback['cot_content'],
							'score_content'     => $feedback['score_content'],
							'feedback_content'  => $feedback['feedback_content'],
							'created_by'        => $user_id,
						)
					);
					if ( is_wp_error( $new_feedback ) ) {
						throw new Exception( $new_feedback->get_error_message() );
					}
					$result['feedback'][] = $new_feedback;
				}
			}

			// Fork speech attempts if requested.
			if ( $options['fork_speech_attempts'] && ! empty( $original['audio_ids'] ) ) {
				// Get submission DB instance to access attempt methods.
				$submission_db = new Ieltssci_Submission_DB();

				// Find all attempts associated with the original speech's audio_ids.
				$attempts = $submission_db->get_speech_attempts(
					array(
						'audio_id' => $original['audio_ids'],
						'number'   => 999,
					)
				);

				if ( is_wp_error( $attempts ) ) {
					throw new Exception( 'Failed to retrieve speech attempts: ' . $attempts->get_error_message() );
				}

				if ( ! empty( $attempts ) && is_array( $attempts ) ) {
					// Track new audio IDs created from forked attempts.
					$new_audio_ids = array();

					foreach ( $attempts as $attempt ) {
						$forked_attempt = $submission_db->fork_speech_attempt(
							$attempt['id'],
							$user_id,
							array(
								'submission_id'        => $options['submission_id'] ?? $attempt['submission_id'], // Keep same submission if any.
								'question_id'          => $attempt['question_id'],   // Keep same question if any.
								'copy_feedback'        => (bool) $options['copy_attempt_feedback'],
								'copy_attachment_meta' => (bool) $options['copy_attachment_meta'],
							)
						);

						if ( is_wp_error( $forked_attempt ) ) {
							// Log but don't fail the entire operation.
							error_log( 'Failed to fork speech attempt ' . $attempt['id'] . ': ' . $forked_attempt->get_error_message() );
						} else {
							$result['attempts'][] = array(
								'original_attempt_id' => $attempt['id'],
								'forked_attempt'      => $forked_attempt,
							);

							// Collect the new audio ID from the forked attempt.
							if ( ! empty( $forked_attempt['new_audio_id'] ) ) {
								$new_audio_ids[] = (int) $forked_attempt['new_audio_id'];
							}
						}
					}

					// Update the new speech with the new audio IDs from forked attempts.
					if ( ! empty( $new_audio_ids ) ) {
						$update_result = $this->update_speech(
							array( 'id' => $new_speech['id'] ),
							array( 'audio_ids' => $new_audio_ids )
						);

						if ( is_wp_error( $update_result ) ) {
							throw new Exception( 'Failed to update speech with new audio IDs: ' . $update_result->get_error_message() );
						}

						// Update the result speech data with new audio IDs.
						$result['speech']['audio_ids'] = $new_audio_ids;
					}
				}
			}

			$this->wpdb->query( 'COMMIT' );
			return $result;

		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'fork_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get speech transcript from post meta of all audio attachments
	 *
	 * @param array $speech The speech data including audio_ids.
	 * @return array|null An array of transcripts keyed by attachment_id or null if none found
	 */
	private function get_speech_transcript( $speech ) {
		if ( empty( $speech['audio_ids'] ) || ! is_array( $speech['audio_ids'] ) ) {
			return null;
		}

		$transcripts = array();

		// Get transcript from each audio attachment.
		foreach ( $speech['audio_ids'] as $attachment_id ) {
			$transcript = get_post_meta( $attachment_id, 'ieltssci_audio_transcription', true );
			if ( ! empty( $transcript ) ) {
				$transcripts[ $attachment_id ] = $transcript;
			}
		}

		return ! empty( $transcripts ) ? $transcripts : null;
	}

	/**
	 * Count distinct speech entries that have speech feedback based on specified criteria.
	 *
	 * @param array $args {
	 *     Optional. Arguments to count distinct speech entries with speech feedback.
	 *     @var int    $created_by        User ID of the feedback creator.
	 *     @var string $feedback_criteria Feedback criteria.
	 *     @var string $date_from         Start date for feedback creation (Y-m-d H:i:s).
	 *     @var string $date_to           End date for feedback creation (Y-m-d H:i:s).
	 * }
	 * @return int|WP_Error Count of distinct speech entries or WP_Error on failure.
	 * @throws Exception If there is a database error.
	 */
	public function count_distinct_speech_with_speech_feedback( $args = array() ) {
		try {
			$defaults = array(
				'created_by'        => null,
				'feedback_criteria' => null,
				'date_from'         => null,
				'date_to'           => null,
			);
			$args     = wp_parse_args( $args, $defaults );

			$where          = array( '1=1' );
			$prepare_values = array();

			if ( ! is_null( $args['created_by'] ) ) {
				$where[]          = 'created_by = %d';
				$prepare_values[] = absint( $args['created_by'] );
			}

			if ( ! is_null( $args['feedback_criteria'] ) ) {
				$where[]          = 'feedback_criteria = %s';
				$prepare_values[] = sanitize_text_field( $args['feedback_criteria'] );
			}

			if ( ! is_null( $args['date_from'] ) ) {
				$where[]          = 'created_at >= %s';
				$prepare_values[] = $args['date_from'];
			}

			if ( ! is_null( $args['date_to'] ) ) {
				$where[]          = 'created_at <= %s';
				$prepare_values[] = $args['date_to'];
			}

			$sql = "SELECT COUNT(DISTINCT speech_id)
					FROM {$this->speech_feedback_table}
					WHERE " . implode( ' AND ', $where );

			if ( ! empty( $prepare_values ) ) {
				$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );
			} else {
				$prepared_sql = $sql;
			}

			$count = $this->wpdb->get_var( $prepared_sql );

			if ( is_null( $count ) && $this->wpdb->last_error ) {
				// This indicates a potential DB error during get_var!
				return new WP_Error( 'database_error', 'Failed to count distinct speech entries with speech feedback: ' . $this->wpdb->last_error, array( 'status' => 500 ) );
			}

			return (int) $count;

		} catch ( Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to count distinct speech entries with speech feedback: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Adds meta data to a speech.
	 *
	 * @param int    $speech_id  The speech ID.
	 * @param string $meta_key   The meta key.
	 * @param mixed  $meta_value The meta value.
	 * @param bool   $unique     Whether the meta key should be unique.
	 * @return int|WP_Error Meta ID on success, WP_Error on failure.
	 */
	public function add_speech_meta( $speech_id, $meta_key, $meta_value, $unique = false ) {
		$speech_id = absint( $speech_id );
		if ( ! $speech_id ) {
			return new WP_Error( 'invalid_speech_id', 'Invalid speech ID.', array( 'status' => 400 ) );
		}
		try {
			$result = add_metadata( 'ieltssci_speech', $speech_id, $meta_key, $meta_value, $unique );

			if ( false === $result ) {
				return new WP_Error( 'meta_add_failed', 'Failed to add speech meta.', array( 'status' => 500 ) );
			}
			return $result;
		} catch ( Exception $e ) {
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Updates meta data for a speech.
	 *
	 * @param int    $speech_id  The speech ID.
	 * @param string $meta_key   The meta key.
	 * @param mixed  $meta_value The new meta value.
	 * @param mixed  $prev_value Optional. Previous value to check before updating.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function update_speech_meta( $speech_id, $meta_key, $meta_value, $prev_value = '' ) {
		$speech_id = absint( $speech_id );
		if ( ! $speech_id ) {
			return new WP_Error( 'invalid_speech_id', 'Invalid speech ID.', array( 'status' => 400 ) );
		}
		try {
			$result = update_metadata( 'ieltssci_speech', $speech_id, $meta_key, $meta_value, $prev_value );
			if ( false === $result ) {
				return new WP_Error( 'meta_update_failed', 'Failed to update speech meta.', array( 'status' => 500 ) );
			}
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Retrieves meta data for a speech.
	 *
	 * @param int    $speech_id The speech ID.
	 * @param string $key       Optional. The meta key to retrieve. If empty, returns all meta.
	 * @param bool   $single    Whether to return a single value.
	 * @return mixed|WP_Error The meta value(s) on success, WP_Error on failure.
	 */
	public function get_speech_meta( $speech_id, $key = '', $single = false ) {
		$speech_id = absint( $speech_id );
		if ( ! $speech_id ) {
			return new WP_Error( 'invalid_speech_id', 'Invalid speech ID.', array( 'status' => 400 ) );
		}
		try {
			$result = get_metadata( 'ieltssci_speech', $speech_id, $key, $single );
			// WordPress get_metadata returns false for invalid object_id, but we want to return empty for consistency.
			if ( false === $result && ! empty( $key ) && $single ) {
				return '';
			}
			return $result;
		} catch ( Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve speech meta: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Deletes meta data for a speech.
	 *
	 * @param int    $speech_id  The speech ID.
	 * @param string $meta_key   The meta key to delete.
	 * @param mixed  $meta_value Optional. Value to match before deleting.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function delete_speech_meta( $speech_id, $meta_key, $meta_value = '' ) {
		$speech_id = absint( $speech_id );
		if ( ! $speech_id ) {
			return new WP_Error( 'invalid_speech_id', 'Invalid speech ID.', array( 'status' => 400 ) );
		}
		try {
			$result = delete_metadata( 'ieltssci_speech', $speech_id, $meta_key, $meta_value );
			if ( false === $result ) {
				return new WP_Error( 'meta_delete_failed', 'Failed to delete speech meta.', array( 'status' => 500 ) );
			}
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
