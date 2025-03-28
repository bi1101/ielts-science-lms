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
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;

		$this->speech_table          = $this->wpdb->prefix . self::TABLE_PREFIX . 'speech';
		$this->speech_feedback_table = $this->wpdb->prefix . self::TABLE_PREFIX . 'speech_feedback';
	}

	/**
	 * Create a new speech recording or update an existing one based on UUID.
	 *
	 * @param array $speech_data Speech data including audio_ids and transcript.
	 * @return array|WP_Error Created/updated speech data or error.
	 * @throws Exception If there is a database error.
	 */
	public function create_update_speech( $speech_data ) {
		if ( empty( $speech_data['audio_ids'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required audio IDs.', array( 'status' => 400 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Generate UUID if not provided.
			$uuid = ! empty( $speech_data['uuid'] ) ? $speech_data['uuid'] : wp_generate_uuid4();

			// Check if speech with this UUID already exists.
			$existing_speech = null;
			if ( ! empty( $speech_data['uuid'] ) ) {
				$existing_speeches = $this->get_speeches( array( 'uuid' => $uuid ) );
				if ( ! empty( $existing_speeches ) && ! is_wp_error( $existing_speeches ) ) {
					$existing_speech = $existing_speeches[0];
				}
			}

			// Set created_by to current user if not provided.
			$created_by = ! empty( $speech_data['created_by'] ) ? $speech_data['created_by'] : get_current_user_id();

			$data = array(
				'uuid'       => $uuid,
				'audio_ids'  => is_array( $speech_data['audio_ids'] ) ? json_encode( $speech_data['audio_ids'] ) : $speech_data['audio_ids'],
				'created_by' => $created_by,
			);

			$format = array(
				'%s', // uuid.
				'%s', // audio_ids.
				'%d', // created_by.
			);

			if ( $existing_speech ) {
				// Update existing speech.
				$result = $this->wpdb->update(
					$this->speech_table,
					$data,
					array( 'id' => $existing_speech['id'] ),
					$format,
					array( '%d' ) // id format.
				);

				if ( false === $result ) {
					throw new Exception( 'Failed to update speech: ' . $this->wpdb->last_error );
				}

				$speech_id = $existing_speech['id'];
			} else {
				// Create new speech.
				$result = $this->wpdb->insert(
					$this->speech_table,
					$data,
					$format
				);

				if ( false === $result ) {
					throw new Exception( 'Failed to create speech: ' . $this->wpdb->last_error );
				}

				$speech_id = $this->wpdb->insert_id;
			}

			// Handle transcript data in post meta if provided.
			if ( ! empty( $speech_data['transcript'] ) && is_array( $speech_data['transcript'] ) ) {
				foreach ( $speech_data['transcript'] as $attachment_id => $transcript_data ) {
					// Make sure the attachment ID is in the audio_ids array.
					$audio_ids = is_array( $speech_data['audio_ids'] ) ? $speech_data['audio_ids'] : json_decode( $speech_data['audio_ids'], true );

					if ( in_array( (int) $attachment_id, $audio_ids, true ) ) {
						update_post_meta( (int) $attachment_id, 'ieltssci_audio_transcription', $transcript_data );
					}
				}
			}

			// Get the created/updated speech.
			$speech = $this->get_speeches( array( 'id' => $speech_id ) );

			if ( empty( $speech ) ) {
				throw new Exception( 'Failed to retrieve speech after creation/update.' );
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
	 * Get speech recordings with flexible query arguments.
	 *
	 * Retrieves speech records from the database based on the provided query arguments.
	 * Supports filtering by ID, UUID, creator, and date ranges. Also offers pagination,
	 * ordering, and counting functionality.
	 *
	 * @param array $args {
	 *     Optional. Arguments to filter and control speech record retrieval.
	 *
	 *     @type int|array|null     $id         Optional. Speech ID(s) to filter by.
	 *     @type string|array|null  $uuid       Optional. Speech UUID(s) to filter by.
	 *     @type int|array|null     $created_by Optional. User ID(s) who created the speech.
	 *     @type array|null         $date_query Optional. Date query parameters.
	 *         @type string         $after      Optional. Retrieve records created after this date.
	 *         @type string         $before     Optional. Retrieve records created before this date.
	 *     @type string             $orderby    Optional. Field to order results by. Accepts 'id', 'uuid',
	 *                                          created_at', or 'created_by'. Default 'id'.
	 *     @type string             $order      Optional. Order direction. Accepts 'ASC' or 'DESC'. Default 'DESC'.
	 *     @type int                $per_page   Optional.   Number of records per page. Default 10.
	 *     @type int                $page       Optional.   Page number for pagination. Default 1.
	 *     @type bool               $count      Optional.   Whether to return only the count. Default false.
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

			// Build where clauses and prepare values.
			$field_types = array(
				'feedback_id'       => '%d',
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
	 * Create or update a speech feedback.
	 *
	 * @param array $feedback_data Required. Speech feedback data.
	 * @return array|WP_Error Created/updated feedback data or error.
	 * @throws Exception If there is a database error.
	 */
	public function create_update_speech_feedback( $feedback_data ) {
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

			if ( isset( $feedback_data['id'] ) ) {
				$result      = $this->wpdb->update(
					$this->speech_feedback_table,
					$data,
					array( 'id' => $feedback_data['id'] ),
					null,
					array( '%d' )
				);
				$feedback_id = $feedback_data['id'];
			} else {
				$result      = $this->wpdb->insert(
					$this->speech_feedback_table,
					$data
				);
				$feedback_id = $this->wpdb->insert_id;
			}

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
	 * Fork (duplicate) a speech recording.
	 *
	 * @param int      $speech_id ID of the speech to fork.
	 * @param int|null $user_id Optional. User ID creating the fork.
	 * @param array    $options Optional. Fork options.
	 *     @var bool $copy_speech_feedback  Whether to copy speech feedback. Default true.
	 *     @var bool $generate_new_uuid    Whether to generate a new UUID. Default true.
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
			'copy_speech_feedback' => true,
			'generate_new_uuid'    => true,
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
			$new_speech = $this->create_update_speech(
				array(
					'uuid'       => $options['generate_new_uuid'] ? wp_generate_uuid4() : $original['uuid'],
					'audio_ids'  => $original['audio_ids'],
					'created_by' => $user_id,
				)
			);

			if ( is_wp_error( $new_speech ) ) {
				throw new Exception( $new_speech->get_error_message() );
			}

			// Copy feedback if requested.
			$result = array(
				'speech'   => $new_speech,
				'feedback' => array(),
			);

			if ( $options['copy_speech_feedback'] ) {
				$feedbacks = $this->get_speech_feedbacks( array( 'speech_id' => $speech_id ) );
				foreach ( $feedbacks as $feedback ) {
					$new_feedback = $this->create_update_speech_feedback(
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
}
