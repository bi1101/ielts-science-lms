<?php
/**
 * IELTS Science LMS Submission Database Handler
 *
 * This file contains the database operations class for managing test and task submissions.
 * Handles tracking of user submissions, statuses, and metadata for writing tests and tasks.
 *
 * @package IELTS_Science_LMS
 * @subpackage Writing
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Writing;

use WP_Error;
use wpdb;
use Exception;

/**
 * Class Ieltssci_Submission_DB
 *
 * Handles database operations for IELTS Science LMS Writing test and task submissions.
 *
 * @since 1.0.0
 */
class Ieltssci_Submission_DB {
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
	 * Test submissions table name.
	 *
	 * @var string
	 */
	private $test_submissions_table;

	/**
	 * Task submissions table name.
	 *
	 * @var string
	 */
	private $task_submissions_table;

	/**
	 * Test submission meta table name.
	 *
	 * @var string
	 */
	private $test_submission_meta_table;

	/**
	 * Task submission meta table name.
	 *
	 * @var string
	 */
	private $task_submission_meta_table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;

		$this->test_submissions_table     = $this->wpdb->prefix . self::TABLE_PREFIX . 'writing_test_submissions';
		$this->task_submissions_table     = $this->wpdb->prefix . self::TABLE_PREFIX . 'writing_task_submissions';
		$this->test_submission_meta_table = $this->wpdb->prefix . self::TABLE_PREFIX . 'writing_test_submission_meta';
		$this->task_submission_meta_table = $this->wpdb->prefix . self::TABLE_PREFIX . 'writing_task_submission_meta';

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
		global $wpdb;

		// Register test submission meta table.
		$wpdb->ieltssci_writing_test_submissionmeta = $this->test_submission_meta_table;

		// Register task submission meta table.
		$wpdb->ieltssci_writing_task_submissionmeta = $this->task_submission_meta_table;
	}

	/**
	 * Adds a new test submission.
	 *
	 * @param array $data {
	 *     Required. Data for the new test submission.
	 *     @type int    $test_id      ID of the test (from wp_posts).
	 *     @type int    $user_id      ID of the user submitting.
	 *     @type string $uuid         Optional. Custom UUID for the submission. If not provided, one will be generated.
	 *     @type string $status       Optional. Submission status. Default 'in-progress'.
	 *     @type string $started_at   Optional. Start timestamp in 'Y-m-d H:i:s' format. Default current time.
	 * }
	 * @return int|WP_Error The new submission ID on success, or WP_Error on failure.
	 * @throws Exception When database operations fail.
	 */
	public function add_test_submission( $data ) {
		// Validate required fields.
		if ( empty( $data['test_id'] ) || empty( $data['user_id'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields: test_id or user_id.', array( 'status' => 400 ) );
		}

		// Set defaults.
		$data = wp_parse_args(
			$data,
			array(
				'status'     => 'in-progress',
				'started_at' => current_time( 'mysql', true ), // Use GMT time.
			)
		);

		// Start transaction.
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Generate UUID or use provided one.
			$uuid = ! empty( $data['uuid'] ) ? sanitize_text_field( $data['uuid'] ) : wp_generate_uuid4();

			// Validate UUID format if provided.
			if ( ! empty( $data['uuid'] ) && ! wp_is_uuid( $uuid ) ) {
				throw new Exception( 'Invalid UUID format provided.' );
			}

			// Check for UUID uniqueness.
			$existing_uuid = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM {$this->test_submissions_table} WHERE uuid = %s",
					$uuid
				)
			);

			if ( $existing_uuid ) {
				throw new Exception( 'UUID already exists. Please provide a unique UUID or let the system generate one.' );
			}

			// Prepare data for insertion.
			$insert_data = array(
				'uuid'       => $uuid,
				'test_id'    => absint( $data['test_id'] ),
				'user_id'    => absint( $data['user_id'] ),
				'status'     => sanitize_text_field( $data['status'] ),
				'started_at' => $data['started_at'],
				'updated_at' => current_time( 'mysql', true ), // Use GMT time.
			);

			$format = array(
				'%s', // uuid.
				'%d', // test_id.
				'%d', // user_id.
				'%s', // status.
				'%s', // started_at.
				'%s', // updated_at.
			);

			// Insert the submission.
			$result = $this->wpdb->insert(
				$this->test_submissions_table,
				$insert_data,
				$format
			);

			if ( false === $result ) {
				throw new Exception( 'Failed to create test submission: ' . $this->wpdb->last_error );
			}

			$submission_id = $this->wpdb->insert_id;

			$this->wpdb->query( 'COMMIT' );
			return $submission_id;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Updates an existing test submission.
	 *
	 * @param int   $submission_id The ID of the submission to update.
	 * @param array $data {
	 *     Data to update. Only provided fields will be updated.
	 *     @type int    $test_id      Optional. ID of the test (from wp_posts where post_type='writing-test').
	 *     @type int    $user_id      Optional. ID of the user who submitted the test.
	 *     @type string $status       Optional. Submission status (e.g., 'in-progress', 'completed', 'graded').
	 *     @type string $started_at   Optional. Start timestamp in 'Y-m-d H:i:s' format.
	 *     @type string $completed_at Optional. Completion timestamp in 'Y-m-d H:i:s' format.
	 * }
	 * @return bool|WP_Error True on success, or WP_Error on failure.
	 * @throws Exception When database operations fail.
	 */
	public function update_test_submission( $submission_id, $data ) {
		// Validate submission ID.
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}

		// Check if submission exists.
		$existing = $this->get_test_submission( $submission_id );
		if ( ! $existing ) {
			return new WP_Error( 'submission_not_found', 'Test submission not found.', array( 'status' => 404 ) );
		}

		// If no data to update, return true.
		if ( empty( $data ) ) {
			return true;
		}

		// Start transaction.
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Prepare update data.
			$update_data = array();
			$format      = array();

			// Process fields that can be updated.
			if ( isset( $data['test_id'] ) ) {
				$update_data['test_id'] = absint( $data['test_id'] );
				$format[]               = '%d';
			}

			if ( isset( $data['user_id'] ) ) {
				$update_data['user_id'] = absint( $data['user_id'] );
				$format[]               = '%d';
			}

			if ( isset( $data['status'] ) ) {
				$update_data['status'] = sanitize_text_field( $data['status'] );
				$format[]              = '%s';
			}

			if ( isset( $data['started_at'] ) ) {
				$update_data['started_at'] = $data['started_at'];
				$format[]                  = '%s';
			}

			if ( isset( $data['completed_at'] ) ) {
				$update_data['completed_at'] = $data['completed_at'];
				$format[]                    = '%s';
			}

			// If no data to update after processing, return true.
			if ( empty( $update_data ) ) {
				$this->wpdb->query( 'COMMIT' );
				return true;
			}

			// Always update the updated_at timestamp.
			$update_data['updated_at'] = current_time( 'mysql', true ); // Use GMT time.
			$format[]                  = '%s';

			// Perform the update.
			$result = $this->wpdb->update(
				$this->test_submissions_table,
				$update_data,
				array( 'id' => $submission_id ),
				$format,
				array( '%d' )
			);

			if ( false === $result ) {
				throw new Exception( 'Failed to update test submission: ' . $this->wpdb->last_error );
			}

			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Retrieves a single test submission by its ID.
	 *
	 * A simple wrapper for get_test_submissions().
	 *
	 * @param int $submission_id The ID of the submission to retrieve.
	 * @return array|null|WP_Error The submission data, or null if not found, or WP_Error on failure.
	 */
	public function get_test_submission( $submission_id ) {
		$submissions = $this->get_test_submissions( array( 'id' => $submission_id ) );

		if ( is_wp_error( thing: $submissions ) || empty( $submissions ) ) {
			return $submissions;
		}

		return $submissions[0];
	}

	/**
	 * Retrieves test submissions based on a set of arguments.
	 *
	 * @param array $args {
	 *     Optional. Arguments to retrieve submissions.
	 *     @type int|array    $id           Submission ID or array of IDs.
	 *     @type string|array $uuid         Submission UUID or array of UUIDs.
	 *     @type int|array    $test_id      Test ID or array of IDs.
	 *     @type int|array    $user_id      User ID or array of IDs.
	 *     @type string|array $status       Status or array of statuses.
	 *     @type array        $date_query   Query by date parameters (similar to WP_Query).
	 *     @type string       $orderby      Field to order by. Default 'id'.
	 *     @type string       $order        Order direction ('ASC' or 'DESC'). Default 'DESC'.
	 *     @type int          $number       Number of items to return. Default 20.
	 *     @type int          $offset       Offset for pagination.
	 *     @type bool         $count        If true, returns only the count.
	 *     @type array        $meta_query   Query by meta fields (similar to WP_Meta_Query).
	 *     @type array|bool   $include_meta Array of meta keys to include in results. If false, no meta included, if true all are included. Default false.
	 * }
	 * @return array|int|WP_Error An array of submission objects, a count, or WP_Error on failure.
	 */
	public function get_test_submissions( $args = array() ) {
		try {
			$defaults = array(
				'id'           => null,
				'uuid'         => null,
				'test_id'      => null,
				'user_id'      => null,
				'status'       => null,
				'date_query'   => null,
				'orderby'      => 'id',
				'order'        => 'DESC',
				'number'       => 20,
				'offset'       => 0,
				'count'        => false,
				'meta_query'   => null,
				'include_meta' => false,
			);

			$args = wp_parse_args( $args, $defaults );

			$select = $args['count'] ? 'COUNT(*)' : 'ts.*';
			$from   = $this->test_submissions_table . ' ts';
			$join   = '';

			$where          = array( '1=1' );
			$prepare_values = array();

			// Process ID filter.
			if ( ! is_null( $args['id'] ) ) {
				if ( is_array( $args['id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['id'] ), '%d' );
					$where[]        = 'ts.id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['id'] );
				} else {
					$where[]          = 'ts.id = %d';
					$prepare_values[] = $args['id'];
				}
			}

			// Process UUID filter.
			if ( ! is_null( $args['uuid'] ) ) {
				if ( is_array( $args['uuid'] ) ) {
					$placeholders   = array_fill( 0, count( $args['uuid'] ), '%s' );
					$where[]        = 'ts.uuid IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['uuid'] );
				} else {
					$where[]          = 'ts.uuid = %s';
					$prepare_values[] = $args['uuid'];
				}
			}

			// Process test_id filter.
			if ( ! is_null( $args['test_id'] ) ) {
				if ( is_array( $args['test_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['test_id'] ), '%d' );
					$where[]        = 'ts.test_id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['test_id'] );
				} else {
					$where[]          = 'ts.test_id = %d';
					$prepare_values[] = $args['test_id'];
				}
			}

			// Process user_id filter.
			if ( ! is_null( $args['user_id'] ) ) {
				if ( is_array( $args['user_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['user_id'] ), '%d' );
					$where[]        = 'ts.user_id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['user_id'] );
				} else {
					$where[]          = 'ts.user_id = %d';
					$prepare_values[] = $args['user_id'];
				}
			}

			// Process status filter.
			if ( ! is_null( $args['status'] ) ) {
				if ( is_array( $args['status'] ) ) {
					$placeholders   = array_fill( 0, count( $args['status'] ), '%s' );
					$where[]        = 'ts.status IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['status'] );
				} else {
					$where[]          = 'ts.status = %s';
					$prepare_values[] = $args['status'];
				}
			}

			// Process date query (simplified approach).
			if ( ! is_null( $args['date_query'] ) && is_array( $args['date_query'] ) ) {
				if ( ! empty( $args['date_query']['after'] ) ) {
					$where[]          = 'ts.started_at >= %s';
					$prepare_values[] = $args['date_query']['after'];
				}
				if ( ! empty( $args['date_query']['before'] ) ) {
					$where[]          = 'ts.started_at <= %s';
					$prepare_values[] = $args['date_query']['before'];
				}
			}

			// Process meta query.
			if ( ! is_null( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
				$meta_join_alias = 'tsm';
				$join           .= " LEFT JOIN {$this->test_submission_meta_table} {$meta_join_alias} ON ts.id = {$meta_join_alias}.test_submission_id";

				foreach ( $args['meta_query'] as $meta_query_item ) {
					if ( ! empty( $meta_query_item['key'] ) ) {
						$where[]          = "{$meta_join_alias}.meta_key = %s";
						$prepare_values[] = $meta_query_item['key'];

						if ( ! empty( $meta_query_item['value'] ) ) {
							$compare = ! empty( $meta_query_item['compare'] ) ? $meta_query_item['compare'] : '=';
							if ( 'LIKE' === $compare ) {
								$where[]          = "{$meta_join_alias}.meta_value LIKE %s";
								$prepare_values[] = '%' . $this->wpdb->esc_like( $meta_query_item['value'] ) . '%';
							} else {
								$where[]          = "{$meta_join_alias}.meta_value = %s";
								$prepare_values[] = $meta_query_item['value'];
							}
						}
					}
				}
			}

			// Build query.
			$sql = "SELECT $select FROM $from $join WHERE " . implode( ' AND ', $where );

			// Add order if not counting.
			if ( ! $args['count'] ) {
				// Sanitize orderby field.
				$allowed_orderby = array( 'id', 'uuid', 'test_id', 'user_id', 'status', 'started_at', 'completed_at' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';

				// Add table prefix for clarity.
				$orderby = 'ts.' . $orderby;

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
			if ( ! empty( $prepare_values ) ) {
				$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );
			} else {
				$prepared_sql = $sql;
			}

			if ( $args['count'] ) {
				$result = $this->wpdb->get_var( $prepared_sql );
				if ( null === $result && $this->wpdb->last_error ) {
					return new WP_Error( 'db_error', 'Database error: ' . $this->wpdb->last_error, array( 'status' => 500 ) );
				}
				return (int) $result;
			} else {
				$results = $this->wpdb->get_results( $prepared_sql, ARRAY_A );
				if ( null === $results && $this->wpdb->last_error ) {
					return new WP_Error( 'db_error', 'Database error: ' . $this->wpdb->last_error, array( 'status' => 500 ) );
				}

				// Process datetime fields for each result.
				foreach ( $results as &$submission ) {
					// Convert timestamps from GMT to site's timezone.
					if ( ! empty( $submission['started_at'] ) ) {
						$submission['started_at'] = get_date_from_gmt( $submission['started_at'] );
					}
					if ( ! empty( $submission['completed_at'] ) ) {
						$submission['completed_at'] = get_date_from_gmt( $submission['completed_at'] );
					}
					if ( ! empty( $submission['updated_at'] ) ) {
						$submission['updated_at'] = get_date_from_gmt( $submission['updated_at'] );
					}

					// Include meta data if requested.
					if ( $args['include_meta'] ) {
						if ( is_array( $args['include_meta'] ) ) {
							// Include specific meta keys.
							$submission['meta'] = array();
							foreach ( $args['include_meta'] as $meta_key ) {
								$submission['meta'][ $meta_key ] = $this->get_test_submission_meta( $submission['id'], $meta_key );
							}
						} elseif ( true === $args['include_meta'] ) {
							// Include all meta data.
							$submission['meta'] = $this->get_test_submission_meta( $submission['id'] );
						}
					}
				}

				return $results;
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve test submissions: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Deletes a test submission.
	 *
	 * This will also delete associated meta data and related task submissions.
	 *
	 * @param int $submission_id The ID of the submission to delete.
	 * @return bool|WP_Error True on success, or WP_Error on failure.
	 * @throws Exception When database operations fail.
	 */
	public function delete_test_submission( $submission_id ) {
		// Validate submission ID.
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}

		// Check if submission exists.
		$existing = $this->get_test_submission( $submission_id );
		if ( ! $existing ) {
			return new WP_Error( 'submission_not_found', 'Test submission not found.', array( 'status' => 404 ) );
		}

		// Start transaction.
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Delete related task submissions (cascade will handle task submission meta).
			$task_submissions = $this->get_task_submissions( array( 'test_submission_id' => $submission_id ) );
			if ( ! is_wp_error( $task_submissions ) && ! empty( $task_submissions ) ) {
				foreach ( $task_submissions as $task_submission ) {
					$delete_result = $this->delete_task_submission( $task_submission['id'] );
					if ( is_wp_error( $delete_result ) ) {
						throw new Exception( 'Failed to delete related task submission: ' . $delete_result->get_error_message() );
					}
				}
			}

			// Delete test submission meta data.
			$meta_result = $this->wpdb->delete(
				$this->test_submission_meta_table,
				array( 'ieltssci_writing_test_submission_id' => $submission_id ),
				array( '%d' )
			);

			if ( false === $meta_result ) {
				throw new Exception( 'Failed to delete test submission meta: ' . $this->wpdb->last_error );
			}

			// Delete the test submission.
			$result = $this->wpdb->delete(
				$this->test_submissions_table,
				array( 'id' => $submission_id ),
				array( '%d' )
			);

			if ( false === $result ) {
				throw new Exception( 'Failed to delete test submission: ' . $this->wpdb->last_error );
			}

			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	// == Test Submission Meta Methods == //

	/**
	 * Adds meta data to a test submission.
	 *
	 * @param int    $submission_id The submission ID.
	 * @param string $meta_key      The meta key.
	 * @param mixed  $meta_value    The meta value.
	 * @param bool   $unique        Whether the meta key should be unique.
	 * @return int|WP_Error Meta ID on success, WP_Error on failure.
	 */
	public function add_test_submission_meta( $submission_id, $meta_key, $meta_value, $unique = false ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}

		try {
			$result = add_metadata( 'ieltssci_writing_test_submission', $submission_id, $meta_key, $meta_value, $unique );

			if ( false === $result ) {
				return new WP_Error( 'meta_add_failed', 'Failed to add test submission meta.', array( 'status' => 500 ) );
			}

			return $result;
		} catch ( Exception $e ) {
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Updates meta data for a test submission.
	 *
	 * @param int    $submission_id The submission ID.
	 * @param string $meta_key      The meta key.
	 * @param mixed  $meta_value    The new meta value.
	 * @param mixed  $prev_value    Optional. Previous value to check before updating.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function update_test_submission_meta( $submission_id, $meta_key, $meta_value, $prev_value = '' ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			$result = update_metadata( 'ieltssci_writing_test_submission', $submission_id, $meta_key, $meta_value, $prev_value );

			if ( false === $result ) {
				return new WP_Error( 'meta_update_failed', 'Failed to update test submission meta.', array( 'status' => 500 ) );
			}

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Retrieves meta data for a test submission.
	 *
	 * @param int    $submission_id The submission ID.
	 * @param string $key           Optional. The meta key to retrieve. If empty, returns all meta.
	 * @param bool   $single        Whether to return a single value.
	 * @return mixed|WP_Error The meta value(s) on success, WP_Error on failure.
	 */
	public function get_test_submission_meta( $submission_id, $key = '', $single = false ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}

		try {
			$result = get_metadata( 'ieltssci_writing_test_submission', $submission_id, $key, $single );

			// WordPress get_metadata returns false for invalid object_id, but we want to return empty for consistency.
			if ( false === $result && ! empty( $key ) && $single ) {
				return '';
			}

			return $result;
		} catch ( Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve test submission meta: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Deletes meta data for a test submission.
	 *
	 * @param int    $submission_id The submission ID.
	 * @param string $meta_key      The meta key to delete.
	 * @param mixed  $meta_value    Optional. Value to match before deleting.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function delete_test_submission_meta( $submission_id, $meta_key, $meta_value = '' ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			$result = delete_metadata( 'ieltssci_writing_test_submission', $submission_id, $meta_key, $meta_value );

			if ( false === $result ) {
				return new WP_Error( 'meta_delete_failed', 'Failed to delete test submission meta.', array( 'status' => 500 ) );
			}

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	// == Task Submission Methods == //

	/**
	 * Adds a new task submission.
	 *
	 * @param array $data {
	 *     Required. Data for the new task submission.
	 *     @type int    $user_id            ID of the user submitting.
	 *     @type int    $task_id            ID of the task (from wp_posts).
	 *     @type int    $essay_id           ID of the associated essay.
	 *     @type string $uuid               Optional. Custom UUID for the submission. If not provided, one will be generated.
	 *     @type int    $test_submission_id Optional. ID of the parent test submission.
	 *     @type string $status             Optional. Submission status. Default 'in-progress'.
	 *     @type string $started_at         Optional. Start timestamp in 'Y-m-d H:i:s' format. Default current time.
	 * }
	 * @return int|WP_Error The new submission ID on success, or WP_Error on failure.
	 * @throws Exception When database operations fail.
	 */
	public function add_task_submission( $data ) {
		// Validate required fields.
		if ( empty( $data['user_id'] ) || empty( $data['task_id'] ) || empty( $data['essay_id'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields: user_id, task_id, or essay_id.', array( 'status' => 400 ) );
		}

		// Set defaults.
		$data = wp_parse_args(
			$data,
			array(
				'status'     => 'in-progress',
				'started_at' => current_time( 'mysql', true ), // Use GMT time.
			)
		);

		// Start transaction.
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Generate UUID or use provided one.
			$uuid = ! empty( $data['uuid'] ) ? sanitize_text_field( $data['uuid'] ) : wp_generate_uuid4();

			// Validate UUID format if provided.
			if ( ! empty( $data['uuid'] ) && ! wp_is_uuid( $uuid ) ) {
				throw new Exception( 'Invalid UUID format provided.' );
			}

			// Check for UUID uniqueness.
			$existing_uuid = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM {$this->task_submissions_table} WHERE uuid = %s",
					$uuid
				)
			);

			if ( $existing_uuid ) {
				throw new Exception( 'UUID already exists. Please provide a unique UUID or let the system generate one.' );
			}

			// Prepare data for insertion.
			$insert_data = array(
				'uuid'       => $uuid,
				'user_id'    => absint( $data['user_id'] ),
				'task_id'    => absint( $data['task_id'] ),
				'essay_id'   => absint( $data['essay_id'] ),
				'status'     => sanitize_text_field( $data['status'] ),
				'started_at' => $data['started_at'],
				'updated_at' => current_time( 'mysql', true ), // Use GMT time.
			);

			$format = array(
				'%s', // uuid.
				'%d', // user_id.
				'%d', // task_id.
				'%d', // essay_id.
				'%s', // status.
				'%s', // started_at.
				'%s', // updated_at.
			);

			// Add optional test_submission_id if provided.
			if ( ! empty( $data['test_submission_id'] ) ) {
				$insert_data['test_submission_id'] = absint( $data['test_submission_id'] );
				$format[]                          = '%d';
			}

			// Insert the submission.
			$result = $this->wpdb->insert(
				$this->task_submissions_table,
				$insert_data,
				$format
			);

			if ( false === $result ) {
				throw new Exception( 'Failed to create task submission: ' . $this->wpdb->last_error );
			}

			$submission_id = $this->wpdb->insert_id;

			$this->wpdb->query( 'COMMIT' );
			return $submission_id;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Updates an existing task submission.
	 *
	 * @param int   $submission_id The ID of the submission to update.
	 * @param array $data {
	 *     Data to update. Only provided fields will be updated.
	 *     @type int    $test_submission_id Optional. ID of the parent test submission (from ieltssci_writing_test_submissions).
	 *     @type int    $user_id            Optional. ID of the user who submitted the task.
	 *     @type int    $task_id            Optional. ID of the writing task (from wp_posts where post_type='writing-task').
	 *     @type string $status             Optional. Submission status (e.g., 'in-progress', 'completed', 'graded').
	 *     @type int    $essay_id           Optional. ID of the essay (from ieltssci_essays).
	 *     @type string $started_at         Optional. Start timestamp in 'Y-m-d H:i:s' format.
	 *     @type string $completed_at       Optional. Completion timestamp in 'Y-m-d H:i:s' format.
	 * }
	 * @return bool|WP_Error True on success, or WP_Error on failure.
	 * @throws Exception When database operations fail.
	 */
	public function update_task_submission( $submission_id, $data ) {
		// Validate submission ID.
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}

		// Check if submission exists.
		$existing = $this->get_task_submission( $submission_id );
		if ( ! $existing ) {
			return new WP_Error( 'submission_not_found', 'Task submission not found.', array( 'status' => 404 ) );
		}

		// If no data to update, return true.
		if ( empty( $data ) ) {
			return true;
		}

		// Start transaction.
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Prepare update data.
			$update_data = array();
			$format      = array();

			// Process fields that can be updated.
			if ( isset( $data['test_submission_id'] ) ) {
				$update_data['test_submission_id'] = absint( $data['test_submission_id'] );
				$format[]                          = '%d';
			}

			if ( isset( $data['user_id'] ) ) {
				$update_data['user_id'] = absint( $data['user_id'] );
				$format[]               = '%d';
			}

			if ( isset( $data['task_id'] ) ) {
				$update_data['task_id'] = absint( $data['task_id'] );
				$format[]               = '%d';
			}

			if ( isset( $data['status'] ) ) {
				$update_data['status'] = sanitize_text_field( $data['status'] );
				$format[]              = '%s';
			}

			if ( isset( $data['essay_id'] ) ) {
				$update_data['essay_id'] = absint( $data['essay_id'] );
				$format[]                = '%d';
			}

			if ( isset( $data['started_at'] ) ) {
				$update_data['started_at'] = $data['started_at'];
				$format[]                  = '%s';
			}

			if ( isset( $data['completed_at'] ) ) {
				$update_data['completed_at'] = $data['completed_at'];
				$format[]                    = '%s';
			}

			// If no data to update after processing, return true.
			if ( empty( $update_data ) ) {
				$this->wpdb->query( 'COMMIT' );
				return true;
			}

			// Always update the updated_at timestamp.
			$update_data['updated_at'] = current_time( 'mysql', true ); // Use GMT time.
			$format[]                  = '%s';

			// Perform the update.
			$result = $this->wpdb->update(
				$this->task_submissions_table,
				$update_data,
				array( 'id' => $submission_id ),
				$format,
				array( '%d' )
			);

			if ( false === $result ) {
				throw new Exception( 'Failed to update task submission: ' . $this->wpdb->last_error );
			}

			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Retrieves a single task submission by its ID.
	 *
	 * @param int $submission_id The ID of the submission to retrieve.
	 * @return array|null|WP_Error The submission data if found, null if not found, or WP_Error on failure.
	 */
	public function get_task_submission( $submission_id ) {
		$submissions = $this->get_task_submissions( array( 'id' => $submission_id ) );

		if ( is_wp_error( $submissions ) || empty( $submissions ) ) {
			return $submissions;
		}

		return $submissions[0];
	}

	/**
	 * Fork (duplicate) a task submission and optionally its related essay and meta.
	 *
	 * This creates a new task submission record with a new UUID. By default it will fork
	 * the associated essay using Ieltssci_Essay_DB::fork_essay() and copy all task submission
	 * meta. The new submission will reference either the forked essay or the original essay
	 * depending on options. The new submission's user_id defaults to the current user if not
	 * provided. Timestamps are reset; started_at will be set to now and completed_at will be null.
	 *
	 * @param int   $task_submission_id The ID of the task submission to fork.
	 * @param int   $user_id            Optional. The user ID to own the fork. Defaults to current user.
	 * @param array $options            Optional. Control cloning behavior.
	 *     @var bool      $fork_essay              Whether to fork the related essay. Default true.
	 *     @var bool      $copy_segments           When forking the essay, also copy segments. Default true.
	 *     @var bool      $copy_segment_feedback   When forking the essay, also copy segment feedback. Default true.
	 *     @var bool      $copy_essay_feedback     When forking the essay, also copy essay feedback. Default true.
	 *     @var bool      $copy_meta               Copy task submission meta to the new submission. Default true.
	 *     @var bool      $keep_status             Keep the original status. If false, set to 'in-progress'. Default false.
	 *     @var int|null  $test_submission_id      Override parent test submission ID. Default keeps original value.
	 * @return array|WP_Error Details of the forked submission (and essay if forked) or WP_Error on failure.
	 * @throws Exception When database operations fail.
	 */
	public function fork_task_submission( $task_submission_id, $user_id = null, $options = array() ) {
		// Validate input ID.
		$task_submission_id = absint( $task_submission_id );
		if ( ! $task_submission_id ) {
			return new WP_Error( 'invalid_task_submission_id', 'Invalid task submission ID provided.', array( 'status' => 400 ) );
		}

		// Resolve user.
		if ( null === $user_id ) {
			$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
			if ( ! $user_id ) {
				return new WP_Error( 'no_user', 'No user ID provided and no current user.', array( 'status' => 400 ) );
			}
		}

		// Defaults.
		$defaults = array(
			'fork_essay'            => true,
			'copy_segments'         => true,
			'copy_segment_feedback' => true,
			'copy_essay_feedback'   => true,
			'copy_meta'             => true,
			'keep_status'           => true,
			'test_submission_id'    => null,
		);
		$options  = wp_parse_args( $options, $defaults );

		// Start transaction.
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Load original submission.
			$original = $this->get_task_submission( $task_submission_id );
			if ( is_wp_error( $original ) || empty( $original ) ) {
				throw new Exception( is_wp_error( $original ) ? $original->get_error_message() : 'Original task submission not found.' );
			}

			// Optionally fork the essay.
			$forked_essay = null;
			$new_essay_id = intval( $original['essay_id'] );
			if ( $options['fork_essay'] && ! empty( $original['essay_id'] ) ) {
				$essay_db     = new Ieltssci_Essay_DB();
				$essay_result = $essay_db->fork_essay(
					$original['essay_id'],
					$user_id,
					array(
						'copy_segments'         => (bool) $options['copy_segments'],
						'copy_segment_feedback' => (bool) $options['copy_segment_feedback'],
						'copy_essay_feedback'   => (bool) $options['copy_essay_feedback'],
					)
				);
				if ( is_wp_error( $essay_result ) ) {
					throw new Exception( 'Failed to fork essay: ' . $essay_result->get_error_message() );
				}
				$forked_essay = $essay_result;
				if ( ! empty( $essay_result['essay'] ) && ! empty( $essay_result['essay']['id'] ) ) {
					$new_essay_id = intval( $essay_result['essay']['id'] );
				}
			}

			// Build new submission data.
			$new_status = $options['keep_status'] ? $original['status'] : 'in-progress';
			$insert     = array(
				'user_id'  => absint( maybeint: $user_id ),
				'task_id'  => absint( $original['task_id'] ),
				'essay_id' => absint( $new_essay_id ),
				'status'   => sanitize_text_field( $new_status ),
				// Let add_task_submission set started_at and updated_at.
			);

			// Preserve or override parent test submission ID.
			if ( isset( $options['test_submission_id'] ) && null !== $options['test_submission_id'] ) {
				$insert['test_submission_id'] = absint( $options['test_submission_id'] );
			} elseif ( ! empty( $original['test_submission_id'] ) ) {
				$insert['test_submission_id'] = absint( $original['test_submission_id'] );
			}

			// Create the new task submission.
			$new_submission_id = $this->add_task_submission( $insert );
			if ( is_wp_error( $new_submission_id ) ) {
				throw new Exception( 'Failed to create forked task submission: ' . $new_submission_id->get_error_message() );
			}

			// Copy meta if requested.
			$copied_meta = array();
			if ( $options['copy_meta'] ) {
				$all_meta = $this->get_task_submission_meta( $task_submission_id );
				if ( is_wp_error( $all_meta ) ) {
					throw new Exception( 'Failed to read task submission meta: ' . $all_meta->get_error_message() );
				}
				if ( is_array( $all_meta ) ) {
					foreach ( $all_meta as $meta_key => $values ) {
						// get_metadata returns arrays of values for each key.
						$values = is_array( $values ) ? $values : array( $values );
						foreach ( $values as $v ) {
							$add_result = $this->add_task_submission_meta( $new_submission_id, $meta_key, $v );
							if ( is_wp_error( $add_result ) ) {
								throw new Exception( 'Failed to copy task submission meta: ' . $add_result->get_error_message() );
							}
						}
						$copied_meta[] = $meta_key;
					}
				}
			}

			// Load the newly created submission data for return.
			$new_submission = $this->get_task_submission( $new_submission_id );

			$this->wpdb->query( 'COMMIT' );
			return array(
				'task_submission'  => $new_submission,
				'forked_essay'     => $forked_essay,
				'copied_meta_keys' => $copied_meta,
			);
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'fork_task_submission_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Retrieves task submissions based on a set of arguments.
	 *
	 * @param array $args {
	 *     Optional. Arguments to retrieve submissions.
	 *     @type int|array    $id                 Submission ID or array of IDs.
	 *     @type string|array $uuid               Submission UUID or array of UUIDs.
	 *     @type int|array    $test_submission_id Parent test submission ID or array of IDs.
	 *     @type int|array    $user_id            User ID or array of IDs.
	 *     @type int|array    $task_id            Task ID or array of IDs.
	 *     @type int|array    $essay_id           Essay ID or array of IDs.
	 *     @type string|array $status             Status or array of statuses.
	 *     @type array        $date_query         Query by date parameters.
	 *     @type string       $orderby            Field to order by. Default 'id'.
	 *     @type string       $order              Order direction ('ASC' or 'DESC'). Default 'DESC'.
	 *     @type int          $number             Number of items to return. Default 20.
	 *     @type int          $offset             Offset for pagination.
	 *     @type bool         $count              If true, returns only the count.
	 *     @type array        $meta_query         Query by meta fields.
	 *     @type array|bool   $include_meta       Array of meta keys to include in results. If false, no meta included, if true all are included. Default false.
	 * }
	 * @return array|int|WP_Error An array of submission objects, a count, or WP_Error on failure.
	 */
	public function get_task_submissions( $args = array() ) {
		try {
			$defaults = array(
				'id'                 => null,
				'uuid'               => null,
				'test_submission_id' => null,
				'user_id'            => null,
				'task_id'            => null,
				'essay_id'           => null,
				'status'             => null,
				'date_query'         => null,
				'orderby'            => 'id',
				'order'              => 'DESC',
				'number'             => 20,
				'offset'             => 0,
				'count'              => false,
				'meta_query'         => null,
				'include_meta'       => false,
			);

			$args = wp_parse_args( $args, $defaults );

			$select = $args['count'] ? 'COUNT(*)' : 'ts.*';
			$from   = $this->task_submissions_table . ' ts';
			$join   = '';

			$where          = array( '1=1' );
			$prepare_values = array();

			// Process ID filter.
			if ( ! is_null( $args['id'] ) ) {
				if ( is_array( $args['id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['id'] ), '%d' );
					$where[]        = 'ts.id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['id'] );
				} else {
					$where[]          = 'ts.id = %d';
					$prepare_values[] = $args['id'];
				}
			}

			// Process UUID filter.
			if ( ! is_null( $args['uuid'] ) ) {
				if ( is_array( $args['uuid'] ) ) {
					$placeholders   = array_fill( 0, count( $args['uuid'] ), '%s' );
					$where[]        = 'ts.uuid IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['uuid'] );
				} else {
					$where[]          = 'ts.uuid = %s';
					$prepare_values[] = $args['uuid'];
				}
			}

			// Process test_submission_id filter.
			if ( ! is_null( $args['test_submission_id'] ) ) {
				if ( is_array( $args['test_submission_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['test_submission_id'] ), '%d' );
					$where[]        = 'ts.test_submission_id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['test_submission_id'] );
				} else {
					$where[]          = 'ts.test_submission_id = %d';
					$prepare_values[] = $args['test_submission_id'];
				}
			}

			// Process user_id filter.
			if ( ! is_null( $args['user_id'] ) ) {
				if ( is_array( $args['user_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['user_id'] ), '%d' );
					$where[]        = 'ts.user_id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['user_id'] );
				} else {
					$where[]          = 'ts.user_id = %d';
					$prepare_values[] = $args['user_id'];
				}
			}

			// Process task_id filter.
			if ( ! is_null( $args['task_id'] ) ) {
				if ( is_array( $args['task_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['task_id'] ), '%d' );
					$where[]        = 'ts.task_id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['task_id'] );
				} else {
					$where[]          = 'ts.task_id = %d';
					$prepare_values[] = $args['task_id'];
				}
			}

			// Process essay_id filter.
			if ( ! is_null( $args['essay_id'] ) ) {
				if ( is_array( $args['essay_id'] ) ) {
					$placeholders   = array_fill( 0, count( $args['essay_id'] ), '%d' );
					$where[]        = 'ts.essay_id IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['essay_id'] );
				} else {
					$where[]          = 'ts.essay_id = %d';
					$prepare_values[] = $args['essay_id'];
				}
			}

			// Process status filter.
			if ( ! is_null( $args['status'] ) ) {
				if ( is_array( $args['status'] ) ) {
					$placeholders   = array_fill( 0, count( $args['status'] ), '%s' );
					$where[]        = 'ts.status IN (' . implode( ',', $placeholders ) . ')';
					$prepare_values = array_merge( $prepare_values, $args['status'] );
				} else {
					$where[]          = 'ts.status = %s';
					$prepare_values[] = $args['status'];
				}
			}

			// Process date query.
			if ( ! is_null( $args['date_query'] ) && is_array( $args['date_query'] ) ) {
				if ( ! empty( $args['date_query']['after'] ) ) {
					$where[]          = 'ts.started_at >= %s';
					$prepare_values[] = $args['date_query']['after'];
				}
				if ( ! empty( $args['date_query']['before'] ) ) {
					$where[]          = 'ts.started_at <= %s';
					$prepare_values[] = $args['date_query']['before'];
				}
			}

			// Process meta query.
			if ( ! is_null( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
				$meta_join_alias = 'tsm';
				$join           .= " LEFT JOIN {$this->task_submission_meta_table} {$meta_join_alias} ON ts.id = {$meta_join_alias}.task_submission_id";

				foreach ( $args['meta_query'] as $meta_query_item ) {
					if ( ! empty( $meta_query_item['key'] ) ) {
						$where[]          = "{$meta_join_alias}.meta_key = %s";
						$prepare_values[] = $meta_query_item['key'];

						if ( ! empty( $meta_query_item['value'] ) ) {
							$compare = ! empty( $meta_query_item['compare'] ) ? $meta_query_item['compare'] : '=';
							if ( 'LIKE' === $compare ) {
								$where[]          = "{$meta_join_alias}.meta_value LIKE %s";
								$prepare_values[] = '%' . $this->wpdb->esc_like( $meta_query_item['value'] ) . '%';
							} else {
								$where[]          = "{$meta_join_alias}.meta_value = %s";
								$prepare_values[] = $meta_query_item['value'];
							}
						}
					}
				}
			}

			// Build query.
			$sql = "SELECT $select FROM $from $join WHERE " . implode( ' AND ', $where );

			// Add order if not counting.
			if ( ! $args['count'] ) {
				// Sanitize orderby field.
				$allowed_orderby = array( 'id', 'uuid', 'test_submission_id', 'user_id', 'task_id', 'essay_id', 'status', 'started_at', 'completed_at' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';

				// Add table prefix for clarity.
				$orderby = 'ts.' . $orderby;

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
			if ( ! empty( $prepare_values ) ) {
				$prepared_sql = $this->wpdb->prepare( $sql, $prepare_values );
			} else {
				$prepared_sql = $sql;
			}

			if ( $args['count'] ) {
				$result = $this->wpdb->get_var( $prepared_sql );
				if ( null === $result && $this->wpdb->last_error ) {
					return new WP_Error( 'db_error', 'Database error: ' . $this->wpdb->last_error, array( 'status' => 500 ) );
				}
				return (int) $result;
			} else {
				$results = $this->wpdb->get_results( $prepared_sql, ARRAY_A );
				if ( null === $results && $this->wpdb->last_error ) {
					return new WP_Error( 'db_error', 'Database error: ' . $this->wpdb->last_error, array( 'status' => 500 ) );
				}

				// Process datetime fields for each result.
				foreach ( $results as &$submission ) {
					// Convert timestamps from GMT to site's timezone.
					if ( ! empty( $submission['started_at'] ) ) {
						$submission['started_at'] = get_date_from_gmt( $submission['started_at'] );
					}
					if ( ! empty( $submission['completed_at'] ) ) {
						$submission['completed_at'] = get_date_from_gmt( $submission['completed_at'] );
					}
					if ( ! empty( $submission['updated_at'] ) ) {
						$submission['updated_at'] = get_date_from_gmt( $submission['updated_at'] );
					}

					// Include meta data if requested.
					if ( $args['include_meta'] ) {
						if ( is_array( $args['include_meta'] ) ) {
							// Include specific meta keys.
							$submission['meta'] = array();
							foreach ( $args['include_meta'] as $meta_key ) {
								$submission['meta'][ $meta_key ] = $this->get_task_submission_meta( $submission['id'], $meta_key );
							}
						} elseif ( true === $args['include_meta'] ) {
							// Include all meta data.
							$submission['meta'] = $this->get_task_submission_meta( $submission['id'] );
						}
					}
				}

				return $results;
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve task submissions: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Deletes a task submission.
	 *
	 * This will also delete associated meta data.
	 *
	 * @param int $submission_id The ID of the submission to delete.
	 * @return bool|WP_Error True on success, or WP_Error on failure.
	 * @throws Exception When database operations fail.
	 */
	public function delete_task_submission( $submission_id ) {
		// Validate submission ID.
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}

		// Check if submission exists.
		$existing = $this->get_task_submission( $submission_id );
		if ( ! $existing ) {
			return new WP_Error( 'submission_not_found', 'Task submission not found.', array( 'status' => 404 ) );
		}

		// Start transaction.
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Delete task submission meta data.
			$meta_result = $this->wpdb->delete(
				$this->task_submission_meta_table,
				array( 'ieltssci_writing_task_submission_id' => $submission_id ),
				array( '%d' )
			);

			if ( false === $meta_result ) {
				throw new Exception( 'Failed to delete task submission meta: ' . $this->wpdb->last_error );
			}

			// Delete the task submission.
			$result = $this->wpdb->delete(
				$this->task_submissions_table,
				array( 'id' => $submission_id ),
				array( '%d' )
			);

			if ( false === $result ) {
				throw new Exception( 'Failed to delete task submission: ' . $this->wpdb->last_error );
			}

			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	// == Task Submission Meta Methods == //

	/**
	 * Adds meta data to a task submission.
	 *
	 * @param int    $submission_id The submission ID.
	 * @param string $meta_key      The meta key.
	 * @param mixed  $meta_value    The meta value.
	 * @param bool   $unique        Whether the meta key should be unique.
	 * @return int|WP_Error Meta ID on success, WP_Error on failure.
	 */
	public function add_task_submission_meta( $submission_id, $meta_key, $meta_value, $unique = false ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			$result = add_metadata( 'ieltssci_writing_task_submission', $submission_id, $meta_key, $meta_value, $unique );

			if ( false === $result ) {
				return new WP_Error( 'meta_add_failed', 'Failed to add task submission meta.', array( 'status' => 500 ) );
			}

			return $result;
		} catch ( Exception $e ) {
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Updates meta data for a task submission.
	 *
	 * @param int    $submission_id The submission ID.
	 * @param string $meta_key      The meta key.
	 * @param mixed  $meta_value    The new meta value.
	 * @param mixed  $prev_value    Optional. Previous value to check before updating.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function update_task_submission_meta( $submission_id, $meta_key, $meta_value, $prev_value = '' ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			$result = update_metadata( 'ieltssci_writing_task_submission', $submission_id, $meta_key, $meta_value, $prev_value );

			if ( false === $result ) {
				return new WP_Error( 'meta_update_failed', 'Failed to update task submission meta.', array( 'status' => 500 ) );
			}

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Retrieves meta data for a task submission.
	 *
	 * @param int    $submission_id The submission ID.
	 * @param string $key           Optional. The meta key to retrieve. If empty, returns all meta.
	 * @param bool   $single        Whether to return a single value.
	 * @return mixed|WP_Error The meta value(s) on success, WP_Error on failure.
	 */
	public function get_task_submission_meta( $submission_id, $key = '', $single = false ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			$result = get_metadata( 'ieltssci_writing_task_submission', $submission_id, $key, $single );

			// WordPress get_metadata returns false for invalid object_id, but we want to return empty for consistency.
			if ( false === $result && ! empty( $key ) && $single ) {
				return '';
			}

			return $result;
		} catch ( Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve task submission meta: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Deletes meta data for a task submission.
	 *
	 * @param int    $submission_id The submission ID.
	 * @param string $meta_key      The meta key to delete.
	 * @param mixed  $meta_value    Optional. Value to match before deleting.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function delete_task_submission_meta( $submission_id, $meta_key, $meta_value = '' ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			$result = delete_metadata( 'ieltssci_writing_task_submission', $submission_id, $meta_key, $meta_value );

			if ( false === $result ) {
				return new WP_Error( 'meta_delete_failed', 'Failed to delete task submission meta.', array( 'status' => 500 ) );
			}

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
