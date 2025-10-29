<?php
/**
 * IELTS Science LMS Speaking Submission Database Handler.
 *
 * Manages speaking test submissions and part submissions, including metadata.
 * Mirrors the structure and behavior of the Writing submission DB but targets speaking tables.
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
 * Class Ieltssci_Submission_DB
 *
 * Handles database operations for IELTS Science LMS Speaking test and part submissions.
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
	 * Speaking test submissions table name.
	 *
	 * @var string
	 */
	private $test_submissions_table;

	/**
	 * Speaking part submissions table name.
	 *
	 * @var string
	 */
	private $part_submissions_table;

	/**
	 * Speaking test submission meta table name.
	 *
	 * @var string
	 */
	private $test_submission_meta_table;

	/**
	 * Speaking part submission meta table name.
	 *
	 * @var string
	 */
	private $part_submission_meta_table;

	/**
	 * Speech attempt table name.
	 *
	 * @var string
	 */
	private $speech_attempt_table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;

		$this->test_submissions_table     = $this->wpdb->prefix . self::TABLE_PREFIX . 'speaking_test_submissions';
		$this->part_submissions_table     = $this->wpdb->prefix . self::TABLE_PREFIX . 'speaking_part_submissions';
		$this->test_submission_meta_table = $this->wpdb->prefix . self::TABLE_PREFIX . 'speaking_test_submission_meta';
		$this->part_submission_meta_table = $this->wpdb->prefix . self::TABLE_PREFIX . 'speaking_part_submission_meta';
		$this->speech_attempt_table       = $this->wpdb->prefix . self::TABLE_PREFIX . 'speech_attempt';

		// Register meta tables with WordPress metadata API.
		$this->register_meta_tables();
	}

	/**
	 * Registers custom meta tables with WordPress metadata API.
	 *
	 * Following WordPress pattern for custom meta tables as described in:
	 * https://www.ibenic.com/working-with-custom-tables-in-wordpress-meta-tables/.
	 */
	private function register_meta_tables() {
		global $wpdb;

		// Register speaking test submission meta table.
		$wpdb->ieltssci_speaking_test_submissionmeta = $this->test_submission_meta_table; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase.

		// Register speaking part submission meta table.
		$wpdb->ieltssci_speaking_part_submissionmeta = $this->part_submission_meta_table; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase.
	}

	// == Speaking Test Submissions == //

	/**
	 * Add a new speaking test submission.
	 *
	 * @param array $data {
	 *     Data for the new test submission.
	 *     @var int    $test_id      ID of the speaking test.
	 *     @var int    $user_id      ID of the user.
	 *     @var string $uuid         Optional. Custom UUID for the submission. If not provided, generated automatically.
	 *     @var string $status       Optional. Submission status. Default 'in-progress'.
	 *     @var string $started_at   Optional. GMT start timestamp in 'Y-m-d H:i:s' format. Default now (GMT).
	 *     @var string $completed_at Optional. GMT completion timestamp in 'Y-m-d H:i:s' format.
	 * }
	 * @throws Exception When a database error occurs.
	 * @return int|WP_Error New submission ID on success, or WP_Error on failure.
	 */
	public function add_test_submission( $data ) {
		if ( empty( $data['test_id'] ) || empty( $data['user_id'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields: test_id and user_id.', array( 'status' => 400 ) );
		}

		$data = wp_parse_args(
			$data,
			array(
				'uuid'         => wp_generate_uuid4(),
				'status'       => 'in-progress',
				'started_at'   => current_time( 'mysql', true ), // GMT.
				'completed_at' => null,
			)
		);

		$insert = array(
			'uuid'       => $data['uuid'],
			'test_id'    => (int) $data['test_id'],
			'user_id'    => (int) $data['user_id'],
			'status'     => (string) $data['status'],
			'started_at' => $data['started_at'],
			'updated_at' => current_time( 'mysql', true ), // GMT.
		);
		$format = array( '%s', '%d', '%d', '%s', '%s', '%s' );
		if ( ! empty( $data['completed_at'] ) ) {
			$insert['completed_at'] = $data['completed_at'];
			$format[]               = '%s';
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$result = $this->wpdb->insert( $this->test_submissions_table, $insert, $format );
			if ( false === $result ) {
				throw new Exception( 'Failed to insert speaking test submission: ' . $this->wpdb->last_error );
			}

			$id = (int) $this->wpdb->insert_id;
			$this->wpdb->query( 'COMMIT' );
			return $id;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update an existing speaking test submission.
	 *
	 * @param int   $submission_id Submission ID.
	 * @param array $data {
	 *     Fields to update.
	 *
	 *     @var int    $test_id      The ID of the test.
	 *     @var int    $user_id      The ID of the user who owns the submission.
	 *     @var string $status       The submission status.
	 *     @var string $started_at   The timestamp when the submission was started.
	 *     @var string $completed_at The timestamp when the submission was completed.
	 * }
	 * @throws Exception When a database error occurs.
	 * @return bool|WP_Error True on success, or WP_Error on failure.
	 */
	public function update_test_submission( $submission_id, $data ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}

		$existing = $this->get_test_submission( $submission_id );
		if ( is_wp_error( $existing ) ) {
			return $existing; // Propagate error.
		}
		if ( empty( $existing ) ) {
			return new WP_Error( 'not_found', 'Submission not found.', array( 'status' => 404 ) );
		}

		if ( empty( $data ) || ! is_array( $data ) ) {
			return true; // Nothing to update.
		}

		$data['updated_at'] = current_time( 'mysql', true ); // GMT.

		$allowed = array( 'test_id', 'user_id', 'status', 'started_at', 'completed_at', 'updated_at' );
		$update  = array();
		$format  = array();
		foreach ( $allowed as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$update[ $key ] = $data[ $key ];
				$format[]       = in_array( $key, array( 'test_id', 'user_id' ), true ) ? '%d' : '%s';
			}
		}

		if ( empty( $update ) ) {
			return true; // Nothing to update.
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$result = $this->wpdb->update( $this->test_submissions_table, $update, array( 'id' => $submission_id ), $format, array( '%d' ) );
			if ( false === $result ) {
				throw new Exception( 'Failed to update speaking test submission: ' . $this->wpdb->last_error );
			}
			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Retrieve a single speaking test submission by ID.
	 *
	 * @param int $submission_id Submission ID.
	 * @return array|null|WP_Error Submission data or null if not found, or WP_Error on failure.
	 */
	public function get_test_submission( $submission_id ) {
		$submissions = $this->get_test_submissions( array( 'id' => absint( $submission_id ) ) );
		if ( is_wp_error( $submissions ) ) {
			return $submissions; // Return error.
		}
		if ( empty( $submissions ) ) {
			return null;
		}
		return $submissions[0];
	}

	/**
	 * Get speaking test submissions with flexible query args.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @var int|int[]    $id         Submission ID(s).
	 *     @var string|string[] $uuid    Submission UUID(s).
	 *     @var int|int[]    $test_id    Test ID(s).
	 *     @var int|int[]    $user_id    User ID(s).
	 *     @var string|string[] $status  Submission status(es).
	 *     @var array        $date_query {
	 *         Date query parameters.
	 *
	 *         @var string $after  Date string for after filter (compared against started_at).
	 *         @var string $before Date string for before filter (compared against started_at).
	 *     }
	 *     @var string       $orderby    Sort field. Default 'id'.
	 *                                   Accepts: 'id', 'uuid', 'test_id', 'user_id', 'status',
	 *                                   'started_at', 'completed_at', 'updated_at'.
	 *     @var string       $order      Sort order. Default 'DESC'. Accepts 'ASC', 'DESC'.
	 *     @var int          $number     Number of results to return. Default 20.
	 *     @var int          $offset     Number of results to skip. Default 0.
	 *     @var bool         $count      Return count instead of results. Default false.
	 * }
	 * @throws Exception When a database error occurs.
	 * @return array|int|WP_Error Array of rows, count, or WP_Error on failure.
	 */
	public function get_test_submissions( $args = array() ) {
		try {
			$defaults = array(
				'id'         => null,
				'uuid'       => null,
				'test_id'    => null,
				'user_id'    => null,
				'status'     => null,
				'date_query' => null, // ['after' => 'Y-m-d H:i:s', 'before' => 'Y-m-d H:i:s'] compared against started_at.
				'orderby'    => 'id',
				'order'      => 'DESC',
				'number'     => 20,
				'offset'     => 0,
				'count'      => false,
			);
			$args     = wp_parse_args( $args, $defaults );

			$select = $args['count'] ? 'COUNT(*)' : '*';
			$from   = $this->test_submissions_table;
			$where  = array( '1=1' );
			$vals   = array();

			// id filter.
			if ( ! is_null( $args['id'] ) ) {
				if ( is_array( $args['id'] ) ) {
					$placeholders = array_fill( 0, count( $args['id'] ), '%d' );
					$where[]      = 'id IN (' . implode( ',', $placeholders ) . ')';
					$vals         = array_merge( $vals, array_map( 'absint', $args['id'] ) );
				} else {
					$where[] = 'id = %d';
					$vals[]  = absint( $args['id'] );
				}
			}

			// uuid filter.
			if ( ! is_null( $args['uuid'] ) ) {
				if ( is_array( $args['uuid'] ) ) {
					$placeholders = array_fill( 0, count( $args['uuid'] ), '%s' );
					$where[]      = 'uuid IN (' . implode( ',', $placeholders ) . ')';
					$vals         = array_merge( $vals, $args['uuid'] );
				} else {
					$where[] = 'uuid = %s';
					$vals[]  = (string) $args['uuid'];
				}
			}

			// test_id filter.
			if ( ! is_null( $args['test_id'] ) ) {
				if ( is_array( $args['test_id'] ) ) {
					$placeholders = array_fill( 0, count( $args['test_id'] ), '%d' );
					$where[]      = 'test_id IN (' . implode( ',', $placeholders ) . ')';
					$vals         = array_merge( $vals, array_map( 'absint', $args['test_id'] ) );
				} else {
					$where[] = 'test_id = %d';
					$vals[]  = absint( $args['test_id'] );
				}
			}

			// user_id filter.
			if ( ! is_null( $args['user_id'] ) ) {
				if ( is_array( $args['user_id'] ) ) {
					$placeholders = array_fill( 0, count( $args['user_id'] ), '%d' );
					$where[]      = 'user_id IN (' . implode( ',', $placeholders ) . ')';
					$vals         = array_merge( $vals, array_map( 'absint', $args['user_id'] ) );
				} else {
					$where[] = 'user_id = %d';
					$vals[]  = absint( $args['user_id'] );
				}
			}

			// status filter.
			if ( ! is_null( $args['status'] ) ) {
				if ( is_array( $args['status'] ) ) {
					$placeholders = array_fill( 0, count( $args['status'] ), '%s' );
					$where[]      = 'status IN (' . implode( ',', $placeholders ) . ')';
					$vals         = array_merge( $vals, array_map( 'strval', $args['status'] ) );
				} else {
					$where[] = 'status = %s';
					$vals[]  = (string) $args['status'];
				}
			}

			// date_query on started_at.
			if ( ! is_null( $args['date_query'] ) && is_array( $args['date_query'] ) ) {
				if ( ! empty( $args['date_query']['after'] ) ) {
					$where[] = 'started_at >= %s';
					$vals[]  = $args['date_query']['after'];
				}
				if ( ! empty( $args['date_query']['before'] ) ) {
					$where[] = 'started_at <= %s';
					$vals[]  = $args['date_query']['before'];
				}
			}

			$sql = 'SELECT ' . $select . ' FROM ' . $from . ' WHERE ' . implode( ' AND ', $where );

			if ( ! $args['count'] ) {
				$allowed_orderby = array( 'id', 'uuid', 'test_id', 'user_id', 'status', 'started_at', 'completed_at', 'updated_at' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
				$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
				$sql            .= " ORDER BY $orderby $order";
				$sql            .= ' LIMIT %d OFFSET %d';
				$vals[]          = max( 1, (int) $args['number'] );
				$vals[]          = max( 0, (int) $args['offset'] );
			}

			$prepared = $this->wpdb->prepare( $sql, $vals );

			if ( $args['count'] ) {
				$count = $this->wpdb->get_var( $prepared );
				if ( null === $count && $this->wpdb->last_error ) {
					throw new Exception( $this->wpdb->last_error );
				}
				return (int) $count;
			}

			$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
			if ( null === $rows && $this->wpdb->last_error ) {
				throw new Exception( $this->wpdb->last_error );
			}
			return $rows;
		} catch ( Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve speaking test submissions: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete a speaking test submission by ID.
	 *
	 * @param int $submission_id Submission ID.
	 * @throws Exception When a database error occurs.
	 * @return bool|WP_Error True on success, or WP_Error on failure.
	 */
	public function delete_test_submission( $submission_id ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}

		$existing = $this->get_test_submission( $submission_id );
		if ( is_wp_error( $existing ) ) {
			return $existing; // Propagate error.
		}
		if ( empty( $existing ) ) {
			return new WP_Error( 'not_found', 'Submission not found.', array( 'status' => 404 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			// Deleting parent will cascade to part submissions and meta by FK constraints.
			$deleted = $this->wpdb->delete( $this->test_submissions_table, array( 'id' => $submission_id ), array( '%d' ) );
			if ( false === $deleted ) {
				throw new Exception( 'Failed to delete speaking test submission: ' . $this->wpdb->last_error );
			}
			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	// == Speaking Test Submission Meta == //

	/**
	 * Add meta to a speaking test submission.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $meta_key      Meta key.
	 * @param mixed  $meta_value    Meta value.
	 * @param bool   $unique        Whether the meta key should be unique.
	 * @throws Exception When a database error occurs.
	 * @return int|WP_Error Meta ID on success, or WP_Error on failure.
	 */
	public function add_test_submission_meta( $submission_id, $meta_key, $meta_value, $unique = false ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			$mid = add_metadata( 'ieltssci_speaking_test_submission', $submission_id, $meta_key, $meta_value, $unique );
			if ( ! $mid ) {
				throw new Exception( 'Failed to add meta.' );
			}
			return (int) $mid;
		} catch ( Exception $e ) {
			return new WP_Error( 'meta_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update meta for a speaking test submission.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $meta_key      Meta key.
	 * @param mixed  $meta_value    New meta value.
	 * @param mixed  $prev_value    Optional. Previous value to check.
	 * @throws Exception When a database error occurs.
	 * @return bool|WP_Error True on success, or WP_Error on failure.
	 */
	public function update_test_submission_meta( $submission_id, $meta_key, $meta_value, $prev_value = '' ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			$ok = update_metadata( 'ieltssci_speaking_test_submission', $submission_id, $meta_key, $meta_value, $prev_value );
			if ( ! $ok ) {
				throw new Exception( 'Failed to update meta.' );
			}
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'meta_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get meta for a speaking test submission.
	 *
	 * @param int         $submission_id Submission ID.
	 * @param string|null $key           Optional. Meta key.
	 * @param bool        $single        Whether to return a single value.
	 * @return mixed|WP_Error Meta value(s) or WP_Error on failure.
	 */
	public function get_test_submission_meta( $submission_id, $key = '', $single = false ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			return get_metadata( 'ieltssci_speaking_test_submission', $submission_id, $key, $single );
		} catch ( Exception $e ) {
			return new WP_Error( 'meta_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete meta for a speaking test submission.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $meta_key      Meta key.
	 * @param mixed  $meta_value    Optional. Meta value to match.
	 * @throws Exception When a database error occurs.
	 * @return bool|WP_Error True on success, or WP_Error on failure.
	 */
	public function delete_test_submission_meta( $submission_id, $meta_key, $meta_value = '' ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			$ok = delete_metadata( 'ieltssci_speaking_test_submission', $submission_id, $meta_key, $meta_value );
			if ( ! $ok ) {
				throw new Exception( 'Failed to delete meta.' );
			}
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'meta_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	// == Speaking Part Submissions == //

	/**
	 * Add a new speaking part submission.
	 *
	 * @param array $data {
	 *     Required. Data for the new part submission.
	 *     @var int         $user_id            ID of the user.
	 *     @var int         $part_id            ID of the speaking part.
	 *     @var int         $speech_id          ID of the speech in ieltssci_speech.
	 *     @var int|null    $test_submission_id Optional. ID of parent speaking test submission.
	 *     @var string      $uuid               Optional. Custom UUID. If not provided, generated automatically.
	 *     @var string      $status             Optional. Submission status. Default 'in-progress'.
	 *     @var string      $started_at         Optional. GMT start timestamp. Default now (GMT).
	 *     @var string|null $completed_at       Optional. GMT completion timestamp.
	 * }
	 * @throws Exception When a database error occurs.
	 * @return int|WP_Error New submission ID on success, or WP_Error on failure.
	 */
	public function add_part_submission( $data ) {
		if ( empty( $data['user_id'] ) || empty( $data['part_id'] ) || empty( $data['speech_id'] ) ) {
			return new WP_Error( 'missing_required', 'Missing required fields: user_id, part_id, and speech_id.', array( 'status' => 400 ) );
		}

		$data = wp_parse_args(
			$data,
			array(
				'test_submission_id' => null,
				'uuid'               => wp_generate_uuid4(),
				'status'             => 'in-progress',
				'started_at'         => current_time( 'mysql', true ), // GMT.
				'completed_at'       => null,
			)
		);

		$insert = array(
			'uuid'       => (string) $data['uuid'],
			'user_id'    => (int) $data['user_id'],
			'part_id'    => (int) $data['part_id'],
			'status'     => (string) $data['status'],
			'speech_id'  => (int) $data['speech_id'],
			'started_at' => $data['started_at'],
			'updated_at' => current_time( 'mysql', true ), // GMT.
		);

		// Conditionally include nullable fields so they become SQL NULLs instead of empty strings or zeros.
		$format = array( '%s', '%d', '%d', '%s', '%d', '%s', '%s' );
		if ( ! is_null( $data['test_submission_id'] ) ) {
			$insert['test_submission_id'] = (int) $data['test_submission_id'];
			$format[]                     = '%d';
		}
		if ( ! empty( $data['completed_at'] ) ) {
			$insert['completed_at'] = $data['completed_at'];
			$format[]               = '%s';
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$result = $this->wpdb->insert( $this->part_submissions_table, $insert, $format );
			if ( false === $result ) {
				throw new Exception( 'Failed to insert speaking part submission: ' . $this->wpdb->last_error );
			}
			$id = (int) $this->wpdb->insert_id;
			$this->wpdb->query( 'COMMIT' );
			return $id;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update an existing speaking part submission.
	 *
	 * @param int   $submission_id Submission ID.
	 * @param array $data {
	 *     Fields to update.
	 *
	 *     @var int    $test_submission_id The ID of the parent test submission.
	 *     @var int    $user_id            The ID of the user who owns the submission.
	 *     @var int    $part_id            The ID of the speaking part.
	 *     @var string $status             The submission status.
	 *     @var int    $speech_id          The ID of the speech associated with the submission.
	 *     @var string $started_at         The timestamp when the submission was started.
	 *     @var string $completed_at       The timestamp when the submission was completed.
	 * }
	 * @throws Exception When a database error occurs.
	 * @return bool|WP_Error True on success, or WP_Error on failure.
	 */
	public function update_part_submission( $submission_id, $data ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}

		$existing = $this->get_part_submission( $submission_id );
		if ( is_wp_error( $existing ) ) {
			return $existing; // Propagate error.
		}
		if ( empty( $existing ) ) {
			return new WP_Error( 'not_found', 'Submission not found.', array( 'status' => 404 ) );
		}

		if ( empty( $data ) || ! is_array( $data ) ) {
			return true; // Nothing to update.
		}

		$data['updated_at'] = current_time( 'mysql', true ); // GMT.

		$allowed = array( 'test_submission_id', 'user_id', 'part_id', 'status', 'speech_id', 'started_at', 'completed_at', 'updated_at' );
		$update  = array();
		$format  = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$update[ $key ] = $data[ $key ];
				$format[]       = in_array( $key, array( 'test_submission_id', 'user_id', 'part_id', 'speech_id' ), true ) ? '%d' : '%s';
			}
		}

		if ( empty( $update ) ) {
			return true; // Nothing to update.
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$result = $this->wpdb->update( $this->part_submissions_table, $update, array( 'id' => $submission_id ), $format, array( '%d' ) );
			if ( false === $result ) {
				throw new Exception( 'Failed to update speaking part submission: ' . $this->wpdb->last_error );
			}
			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Retrieve a single speaking part submission by ID.
	 *
	 * @param int $submission_id Submission ID.
	 * @return array|null|WP_Error Submission data or null if not found, or WP_Error on failure.
	 */
	public function get_part_submission( $submission_id ) {
		$submissions = $this->get_part_submissions( array( 'id' => absint( $submission_id ) ) );
		if ( is_wp_error( $submissions ) ) {
			return $submissions; // Return error.
		}
		if ( empty( $submissions ) ) {
			return null;
		}
		return $submissions[0];
	}

	/**
	 * Get speaking part submissions with flexible query args.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @var int          $id                 Submission ID.
	 *     @var string       $uuid               Submission UUID.
	 *     @var int          $test_submission_id Test submission ID.
	 *     @var int          $user_id            User ID.
	 *     @var int          $part_id            Part ID.
	 *     @var int          $speech_id          Speech ID.
	 *     @var string       $status             Submission status.
	 *     @var array        $date_query {
	 *         Date query parameters.
	 *
	 *         @var string $after  Date string for after filter (compared against started_at).
	 *         @var string $before Date string for before filter (compared against started_at).
	 *     }
	 *     @var string       $orderby            Sort field. Default 'id'.
	 *                                           Accepts: 'id', 'uuid', 'test_submission_id', 'user_id',
	 *                                           'part_id', 'speech_id', 'status', 'started_at',
	 *                                           'completed_at', 'updated_at'.
	 *     @var string       $order              Sort order. Default 'DESC'. Accepts 'ASC', 'DESC'.
	 *     @var int          $number             Number of results to return. Default 20.
	 *     @var int          $offset             Number of results to skip. Default 0.
	 *     @var bool         $count              Return count instead of results. Default false.
	 * }
	 * @throws Exception When a database error occurs.
	 * @return array|int|WP_Error Array of rows, count, or WP_Error on failure.
	 */
	public function get_part_submissions( $args = array() ) {
		try {
			$defaults = array(
				'id'                 => null,
				'uuid'               => null,
				'test_submission_id' => null,
				'user_id'            => null,
				'part_id'            => null,
				'speech_id'          => null,
				'status'             => null,
				'date_query'         => null, // compared against started_at.
				'orderby'            => 'id',
				'order'              => 'DESC',
				'number'             => 20,
				'offset'             => 0,
				'count'              => false,
			);
			$args     = wp_parse_args( $args, $defaults );

			$select = $args['count'] ? 'COUNT(*)' : '*';
			$from   = $this->part_submissions_table;
			$where  = array( '1=1' );
			$vals   = array();

			$map = array(
				'id'                 => array(
					'col'  => 'id',
					'type' => '%d',
				),
				'uuid'               => array(
					'col'  => 'uuid',
					'type' => '%s',
				),
				'test_submission_id' => array(
					'col'  => 'test_submission_id',
					'type' => '%d',
				),
				'user_id'            => array(
					'col'  => 'user_id',
					'type' => '%d',
				),
				'part_id'            => array(
					'col'  => 'part_id',
					'type' => '%d',
				),
				'speech_id'          => array(
					'col'  => 'speech_id',
					'type' => '%d',
				),
				'status'             => array(
					'col'  => 'status',
					'type' => '%s',
				),
			);

			foreach ( $map as $key => $cfg ) {
				if ( ! is_null( $args[ $key ] ) ) {
					if ( is_array( $args[ $key ] ) ) {
						$placeholders = array_fill( 0, count( $args[ $key ] ), $cfg['type'] );
						$where[]      = $cfg['col'] . ' IN (' . implode( ',', $placeholders ) . ')';
						$vals         = array_merge( $vals, $args[ $key ] );
					} else {
						$where[] = $cfg['col'] . ' = ' . $cfg['type'];
						$vals[]  = $args[ $key ];
					}
				}
			}

			if ( ! is_null( $args['date_query'] ) && is_array( $args['date_query'] ) ) {
				if ( ! empty( $args['date_query']['after'] ) ) {
					$where[] = 'started_at >= %s';
					$vals[]  = $args['date_query']['after'];
				}
				if ( ! empty( $args['date_query']['before'] ) ) {
					$where[] = 'started_at <= %s';
					$vals[]  = $args['date_query']['before'];
				}
			}

			$sql = 'SELECT ' . $select . ' FROM ' . $from . ' WHERE ' . implode( ' AND ', $where );

			if ( ! $args['count'] ) {
				$allowed_orderby = array( 'id', 'uuid', 'test_submission_id', 'user_id', 'part_id', 'speech_id', 'status', 'started_at', 'completed_at', 'updated_at' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
				$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
				$sql            .= " ORDER BY $orderby $order";
				$sql            .= ' LIMIT %d OFFSET %d';
				$vals[]          = max( 1, (int) $args['number'] );
				$vals[]          = max( 0, (int) $args['offset'] );
			}

			$prepared = $this->wpdb->prepare( $sql, $vals );

			if ( $args['count'] ) {
				$count = $this->wpdb->get_var( $prepared );
				if ( null === $count && $this->wpdb->last_error ) {
					throw new Exception( $this->wpdb->last_error );
				}
				return (int) $count;
			}

			$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
			if ( null === $rows && $this->wpdb->last_error ) {
				throw new Exception( $this->wpdb->last_error );
			}
			return $rows;
		} catch ( Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve speaking part submissions: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete a speaking part submission by ID.
	 *
	 * @param int $submission_id Submission ID.
	 * @throws Exception When a database error occurs.
	 * @return bool|WP_Error True on success, or WP_Error on failure.
	 */
	public function delete_part_submission( $submission_id ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}

		$existing = $this->get_part_submission( $submission_id );
		if ( is_wp_error( $existing ) ) {
			return $existing; // Propagate error.
		}
		if ( empty( $existing ) ) {
			return new WP_Error( 'not_found', 'Submission not found.', array( 'status' => 404 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$deleted = $this->wpdb->delete( $this->part_submissions_table, array( 'id' => $submission_id ), array( '%d' ) );
			if ( false === $deleted ) {
				throw new Exception( 'Failed to delete speaking part submission: ' . $this->wpdb->last_error );
			}
			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	// == Speaking Part Submission Meta == //

	/**
	 * Add meta to a speaking part submission.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $meta_key      Meta key.
	 * @param mixed  $meta_value    Meta value.
	 * @param bool   $unique        Whether the meta key should be unique.
	 * @throws Exception When a database error occurs.
	 * @return int|WP_Error Meta ID on success, or WP_Error on failure.
	 */
	public function add_part_submission_meta( $submission_id, $meta_key, $meta_value, $unique = false ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			$mid = add_metadata( 'ieltssci_speaking_part_submission', $submission_id, $meta_key, $meta_value, $unique );
			if ( ! $mid ) {
				throw new Exception( 'Failed to add meta.' );
			}
			return (int) $mid;
		} catch ( Exception $e ) {
			return new WP_Error( 'meta_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	// == Forking Utilities == //

	/**
	 * Fork (duplicate) a test submission and optionally its part submissions, speech, and meta.
	 *
	 * This creates a new test submission with a new UUID. By default, it can copy the test
	 * submission meta and fork all related part submissions. Each part submission can also
	 * fork its associated speech and copy its meta, controlled via options.
	 *
	 * @param int   $test_submission_id The ID of the test submission to fork.
	 * @param int   $user_id            Optional. The user ID to own the fork. Defaults to current user.
	 * @param array $options            Optional. Control cloning behavior.
	 *     @var bool $copy_meta              Copy test submission meta to the new submission. Default true.
	 *     @var bool $copy_part_submissions Fork related part submissions. Default true.
	 *     @var bool $fork_speech           When forking parts, also fork the associated speech. Default true.
	 *     @var bool $copy_speech_feedback    When forking parts and speech, also copy speech feedback meta. Default true.
	 *     @var bool $copy_part_meta        Copy each part submission's meta. Default true.
	 *     @var bool $keep_status           Keep the original test submission status. If false, set to 'in-progress'. Default true.
	 *     @var bool $keep_part_status      Keep the original part submission statuses. Default true.
	 * @return array|WP_Error Details of the forked test submission and children or WP_Error on failure.
	 * @throws Exception When forking fails.
	 */
	public function fork_test_submission( $test_submission_id, $user_id = null, $options = array() ) {
		// Validate input ID.
		$test_submission_id = absint( $test_submission_id );
		if ( ! $test_submission_id ) {
			return new WP_Error( 'invalid_test_submission_id', 'Invalid test submission ID provided.', array( 'status' => 400 ) );
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
			'copy_meta'             => true,
			'copy_part_submissions' => true,
			'fork_speech'           => true,
			'copy_speech_feedback'  => true,
			'copy_part_meta'        => true,
			'keep_status'           => true,
			'keep_part_status'      => true,
		);
		$options  = wp_parse_args( $options, $defaults );

		// Start transaction.
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Load original test submission.
			$original = $this->get_test_submission( $test_submission_id );
			if ( is_wp_error( $original ) || empty( $original ) ) {
				throw new Exception( is_wp_error( $original ) ? $original->get_error_message() : 'Original test submission not found.' );
			}

			// Create the new test submission.
			$new_status        = $options['keep_status'] ? $original['status'] : 'in-progress';
			$new_submission_id = $this->add_test_submission(
				array(
					'test_id' => absint( $original['test_id'] ),
					'user_id' => absint( $user_id ),
					'status'  => sanitize_text_field( $new_status ),
					// Let add_test_submission set started_at and updated_at.
				)
			);
			if ( is_wp_error( $new_submission_id ) ) {
				throw new Exception( 'Failed to create forked test submission: ' . $new_submission_id->get_error_message() );
			}

			// Add original_id meta to link back to the original.
			$add_meta_result = $this->add_test_submission_meta( $new_submission_id, 'original_id', $test_submission_id );
			if ( is_wp_error( $add_meta_result ) ) {
				throw new Exception( 'Failed to add original_id meta: ' . $add_meta_result->get_error_message() );
			}

			// Copy test submission meta if requested.
			$copied_meta = array();
			if ( $options['copy_meta'] ) {
				$all_meta = $this->get_test_submission_meta( $test_submission_id );
				if ( is_wp_error( $all_meta ) ) {
					throw new Exception( 'Failed to read test submission meta: ' . $all_meta->get_error_message() );
				}
				if ( is_array( $all_meta ) ) {
					foreach ( $all_meta as $meta_key => $values ) {
						$values = is_array( $values ) ? $values : array( $values );
						foreach ( $values as $v ) {
							$add_result = $this->add_test_submission_meta( $new_submission_id, $meta_key, $v );
							if ( is_wp_error( $add_result ) ) {
								throw new Exception( 'Failed to copy test submission meta: ' . $add_result->get_error_message() );
							}
						}
						$copied_meta[] = $meta_key;
					}
				}
			}

			// Fork related part submissions if requested.
			$forked_parts = array();
			if ( $options['copy_part_submissions'] ) {
				$parts = $this->get_part_submissions(
					array(
						'test_submission_id' => $test_submission_id,
						'number'             => 1000,
						'offset'             => 0,
					)
				);
				if ( is_wp_error( $parts ) ) {
					throw new Exception( 'Failed to retrieve related part submissions: ' . $parts->get_error_message() );
				}
				if ( ! empty( $parts ) ) {
					foreach ( $parts as $part ) {
						$fork_result = $this->fork_part_submission(
							$part['id'],
							$user_id,
							array(
								'test_submission_id'   => $new_submission_id,
								'fork_speech'          => (bool) $options['fork_speech'],
								'copy_part_meta'       => (bool) $options['copy_part_meta'],
								'keep_status'          => (bool) $options['keep_part_status'],
								'copy_speech_feedback' => (bool) $options['copy_speech_feedback'],
							)
						);
						if ( is_wp_error( $fork_result ) ) {
							throw new Exception( 'Failed to fork related part submission: ' . $fork_result->get_error_message() );
						}
						$forked_parts[] = array(
							'original_id' => $part['id'],
							'result'      => $fork_result,
						);
					}
				}
			}

			// Load the newly created submission data for return.
			$new_submission = $this->get_test_submission( $new_submission_id );

			$this->wpdb->query( 'COMMIT' );
			return array(
				'test_submission'  => $new_submission,
				'copied_meta_keys' => $copied_meta,
				'forked_parts'     => $forked_parts,
			);
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'fork_test_submission_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Fork (duplicate) a speaking part submission and optionally its related speech and meta.
	 *
	 * Creates a new part submission with a new UUID. Optionally clones the associated speech and copies
	 * part meta. Can attach to a provided test submission or keep the original relationship.
	 *
	 * @param int   $part_submission_id The ID of the part submission to fork.
	 * @param int   $user_id            Optional. The user ID to own the fork. Defaults to current user.
	 * @param array $options            Optional. Control cloning behavior.
	 *     @var int|null $test_submission_id   Attach the new part to this test submission ID. Default keeps original.
	 *     @var bool     $fork_speech           Fork associated speech. Default true.
	 *     @var bool     $copy_speech_feedback  Copy feedback when forking speech. Default true.
	 *     @var bool     $fork_speech_attempts  Fork speech attempts when forking speech. Default true.
	 *     @var bool     $copy_attempt_feedback Copy attempt feedback when forking attempts. Default true.
	 *     @var bool     $copy_attachment_meta  Copy attachment metadata when forking attempts. Default true.
	 *     @var bool     $copy_part_meta        Copy part submission meta. Default true.
	 *     @var bool     $keep_status           Keep the original part submission status. If false, set to 'in-progress'. Default true.
	 * @throws Exception When a database error occurs.
	 * @return array|WP_Error Details of the forked part submission or WP_Error on failure.
	 */
	public function fork_part_submission( $part_submission_id, $user_id = null, $options = array() ) {
		$part_submission_id = absint( $part_submission_id );
		if ( ! $part_submission_id ) {
			return new WP_Error( 'invalid_id', 'Invalid part submission ID.', array( 'status' => 400 ) );
		}

		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'invalid_user', 'Invalid user ID.', array( 'status' => 400 ) );
		}

		$defaults = array(
			'test_submission_id'    => null,
			'fork_speech'           => true,
			'copy_part_meta'        => true,
			'keep_status'           => true,
			'copy_speech_feedback'  => true,
			'fork_speech_attempts'  => true,
			'copy_attempt_feedback' => true,
			'copy_attachment_meta'  => true,
		);
		$options  = wp_parse_args( $options, $defaults );

		$original = $this->get_part_submission( $part_submission_id );
		if ( is_wp_error( $original ) ) {
			return $original; // Propagate error.
		}
		if ( empty( $original ) ) {
			return new WP_Error( 'not_found', 'Original part submission not found.', array( 'status' => 404 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$forked_speech = null;
			$copied_meta   = array();

			$new_status       = $options['keep_status'] ? $original['status'] : 'in-progress';
			$new_completed_at = $options['keep_status'] ? $original['completed_at'] : null;
			$new_uuid         = wp_generate_uuid4();
			$new_test_id      = is_null( $options['test_submission_id'] ) ? ( (int) $original['test_submission_id'] ? (int) $original['test_submission_id'] : null ) : (int) $options['test_submission_id'];

			// Create new part submission first with original speech_id.
			$data = array(
				'user_id'            => $user_id,
				'part_id'            => (int) $original['part_id'],
				'speech_id'          => (int) $original['speech_id'], // Temporarily use original speech_id.
				'test_submission_id' => $new_test_id,
				'uuid'               => $new_uuid,
				'status'             => $new_status,
				'started_at'         => current_time( 'mysql', true ),
				'completed_at'       => $new_completed_at,
			);

			$result = $this->add_part_submission( $data );
			if ( is_wp_error( $result ) ) {
				throw new Exception( $result->get_error_message() );
			}
			$new_part_id = $result;

			// Now fork speech if requested, associating attempts with the new part submission.
			$new_speech_id = (int) $original['speech_id'];
			if ( $options['fork_speech'] && ! empty( $original['speech_id'] ) ) {
				$speech_db     = new Ieltssci_Speech_DB();
				$orig_speeches = $speech_db->get_speeches( array( 'id' => (int) $original['speech_id'] ) );
				if ( is_wp_error( $orig_speeches ) ) {
					throw new Exception( $orig_speeches->get_error_message() );
				}
				if ( empty( $orig_speeches ) ) {
					throw new Exception( 'Original speech not found for part submission.' );
				}

				// Use centralized speech forking to keep logic consistent and support feedback copying.
				$forked = $speech_db->fork_speech(
					(int) $original['speech_id'],
					$user_id,
					array(
						'copy_speech_feedback'  => (bool) $options['copy_speech_feedback'],
						'fork_speech_attempts'  => (bool) $options['fork_speech_attempts'],
						'copy_attempt_feedback' => (bool) $options['copy_attempt_feedback'],
						'copy_attachment_meta'  => (bool) $options['copy_attachment_meta'],
						'submission_id'         => $new_part_id, // Associate forked attempts with the new part submission.
					)
				);
				if ( is_wp_error( $forked ) ) {
					throw new Exception( $forked->get_error_message() );
				}
				$forked_speech = $forked;
				$new_speech_id = isset( $forked['speech']['id'] ) ? (int) $forked['speech']['id'] : 0;
				if ( ! $new_speech_id ) {
					throw new Exception( 'Failed to fork speech for part submission.' );
				}

				// Update the part submission with the new speech_id.
				$update_result = $this->update_part_submission( $new_part_id, array( 'speech_id' => $new_speech_id ) );
				if ( is_wp_error( $update_result ) ) {
					throw new Exception( 'Failed to update part submission with new speech ID: ' . $update_result->get_error_message() );
				}
			}

			// Add original_id meta to link back to the original.
			$add_meta_result = $this->add_part_submission_meta( $new_part_id, 'original_id', $part_submission_id );
			if ( is_wp_error( $add_meta_result ) ) {
				throw new Exception( 'Failed to add original_id meta: ' . $add_meta_result->get_error_message() );
			}

			if ( $options['copy_part_meta'] ) {
				$part_meta = get_metadata( 'ieltssci_speaking_part_submission', $part_submission_id );
				if ( is_array( $part_meta ) ) {
					foreach ( $part_meta as $mk => $mvals ) {
						foreach ( (array) $mvals as $mvv ) {
							add_metadata( 'ieltssci_speaking_part_submission', $new_part_id, $mk, maybe_unserialize( $mvv ), false );
						}
						$copied_meta[] = $mk;
					}
				}
			}

			$this->wpdb->query( 'COMMIT' );
			return array(
				'part_submission'  => $this->get_part_submission( $new_part_id ),
				'forked_speech'    => $forked_speech,
				'copied_meta_keys' => $copied_meta,
			);
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'fork_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update meta for a speaking part submission.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $meta_key      Meta key.
	 * @param mixed  $meta_value    New meta value.
	 * @param mixed  $prev_value    Optional. Previous value to check.
	 * @throws Exception When a database error occurs.
	 * @return bool|WP_Error True on success, or WP_Error on failure.
	 */
	public function update_part_submission_meta( $submission_id, $meta_key, $meta_value, $prev_value = '' ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			$ok = update_metadata( 'ieltssci_speaking_part_submission', $submission_id, $meta_key, $meta_value, $prev_value );
			if ( ! $ok ) {
				throw new Exception( 'Failed to update meta.' );
			}
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'meta_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get meta for a speaking part submission.
	 *
	 * @param int         $submission_id Submission ID.
	 * @param string|null $key           Optional. Meta key.
	 * @param bool        $single        Whether to return a single value.
	 * @throws Exception When a database error occurs.
	 * @return mixed|WP_Error Meta value(s) or WP_Error on failure.
	 */
	public function get_part_submission_meta( $submission_id, $key = '', $single = false ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			return get_metadata( 'ieltssci_speaking_part_submission', $submission_id, $key, $single );
		} catch ( Exception $e ) {
			return new WP_Error( 'meta_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete meta for a speaking part submission.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $meta_key      Meta key.
	 * @param mixed  $meta_value    Optional. Meta value to match.
	 * @throws Exception When a database error occurs.
	 * @return bool|WP_Error True on success, or WP_Error on failure.
	 */
	public function delete_part_submission_meta( $submission_id, $meta_key, $meta_value = '' ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_id', 'Invalid submission ID.', array( 'status' => 400 ) );
		}
		try {
			$ok = delete_metadata( 'ieltssci_speaking_part_submission', $submission_id, $meta_key, $meta_value );
			if ( ! $ok ) {
				throw new Exception( 'Failed to delete meta.' );
			}
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'meta_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	// == Speech Attempt CRUD == //

	/**
	 * Create a new speech attempt.
	 *
	 * @param array $data {
	 *     Required fields.
	 *     @var int|null $submission_id Optional. Speaking part submission ID.
	 *     @var int|null $question_id   Optional. Associated question ID.
	 *     @var int      $audio_id      Attachment post ID for uploaded audio.
	 *     @var int      $created_by    User ID who created this attempt.
	 * }
	 * @throws Exception When a database error occurs.
	 * @return int|WP_Error Inserted attempt ID or WP_Error on failure.
	 */
	public function add_speech_attempt( $data ) {
		$required = array( 'audio_id', 'created_by' );
		foreach ( $required as $key ) {
			if ( empty( $data[ $key ] ) ) {
				return new WP_Error( 'missing_required', 'Missing required field: ' . $key . '.', array( 'status' => 400 ) );
			}
		}

		$insert = array(
			'audio_id'   => (int) $data['audio_id'],
			'created_by' => (int) $data['created_by'],
		);
		$format = array( '%d', '%d' );

		if ( isset( $data['submission_id'] ) && ! empty( $data['submission_id'] ) ) {
			$insert['submission_id'] = (int) $data['submission_id'];
			$format[]                = '%d';
		}

		if ( isset( $data['question_id'] ) && ! empty( $data['question_id'] ) ) {
			$insert['question_id'] = (int) $data['question_id'];
			$format[]              = '%d';
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$res = $this->wpdb->insert( $this->speech_attempt_table, $insert, $format );
			if ( false === $res ) {
				throw new Exception( 'Failed to insert speech attempt: ' . $this->wpdb->last_error );
			}
			$id = (int) $this->wpdb->insert_id;
			$this->wpdb->query( 'COMMIT' );
			return $id;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update a speech attempt.
	 *
	 * @param int   $attempt_id Attempt ID.
	 * @param array $data       {
	 *     Fields to update.
	 *
	 *     @var int|null $submission_id The ID of the speaking part submission.
	 *     @var int|null $question_id   The ID of the question associated with the attempt.
	 *     @var int      $audio_id      The ID of the audio file for the attempt.
	 * }
	 * @throws Exception When a database error occurs.
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	public function update_speech_attempt( $attempt_id, $data ) {
		$attempt_id = absint( $attempt_id );
		if ( ! $attempt_id ) {
			return new WP_Error( 'invalid_id', 'Invalid attempt ID.', array( 'status' => 400 ) );
		}

		$allowed = array(
			'submission_id' => '%d',
			'question_id'   => '%d',
			'audio_id'      => '%d',
		);
		$update  = array();
		$format  = array();
		foreach ( $allowed as $key => $fmt ) {
			if ( array_key_exists( $key, $data ) ) {
				if ( in_array( $key, array( 'submission_id', 'question_id' ) ) ) {
					$update[ $key ] = isset( $data[ $key ] ) && ! empty( $data[ $key ] ) ? (int) $data[ $key ] : null;
					$format[]       = '%d';
				} else {
					$update[ $key ] = (int) $data[ $key ];
					$format[]       = $fmt;
				}
			}
		}
		if ( empty( $update ) ) {
			return new WP_Error( 'nothing_to_update', 'No valid fields to update.', array( 'status' => 400 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$res = $this->wpdb->update( $this->speech_attempt_table, $update, array( 'id' => $attempt_id ), $format, array( '%d' ) );
			if ( false === $res ) {
				throw new Exception( 'Failed to update speech attempt: ' . $this->wpdb->last_error );
			}
			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get a single speech attempt by ID.
	 *
	 * @param int $attempt_id Attempt ID.
	 * @return array|null|WP_Error Row array, null if not found, or WP_Error.
	 */
	public function get_speech_attempt( $attempt_id ) {
		$attempts = $this->get_speech_attempts(
			array(
				'id'     => absint( $attempt_id ),
				'number' => 1,
			)
		);
		if ( is_wp_error( $attempts ) ) {
			return $attempts; // Pass through error.
		}
		if ( empty( $attempts ) ) {
			return null;
		}
		return $attempts[0];
	}

	/**
	 * Retrieve a list of speech attempts with flexible filters.
	 *
	 * Allows filtering by attempt ID, submission ID, question ID, audio ID, creator, and date range.
	 * Supports ordering, pagination, and counting results.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *     @var int    $id            Attempt ID or array of IDs to filter.
	 *     @var int    $submission_id Submission ID to filter.
	 *     @var int    $question_id   Question ID to filter.
	 *     @var int    $audio_id      Audio attachment ID to filter.
	 *     @var int    $created_by    User ID who created the attempt.
	 *     @var array        $date_query    Optional. Array with 'after' and/or 'before' keys for created_at filtering.
	 *     @var string       $orderby       Field to order by. Default 'id'.
	 *     @var string       $order         Sort order. 'ASC' or 'DESC'. Default 'DESC'.
	 *     @var int          $number        Number of results to return. Default 20.
	 *     @var int          $offset        Offset for pagination. Default 0.
	 *     @var bool         $count         Whether to return a count instead of results. Default false.
	 * }
	 * @throws Exception When a database error occurs.
	 * @return array|int|WP_Error Array of rows, count, or WP_Error on failure.
	 */
	public function get_speech_attempts( $args = array() ) {
		try {
			$defaults = array(
				'id'            => null,
				'submission_id' => null,
				'question_id'   => null,
				'audio_id'      => null,
				'created_by'    => null,
				'date_query'    => null, // compared against created_at.
				'orderby'       => 'id',
				'order'         => 'DESC',
				'number'        => 20,
				'offset'        => 0,
				'count'         => false,
			);
			$args     = wp_parse_args( $args, $defaults );

			$select = $args['count'] ? 'COUNT(*)' : '*';
			$from   = $this->speech_attempt_table;
			$where  = array( '1=1' );
			$vals   = array();

			$map = array(
				'id'            => array(
					'col'  => 'id',
					'type' => '%d',
				),
				'submission_id' => array(
					'col'  => 'submission_id',
					'type' => '%d',
				),
				'question_id'   => array(
					'col'  => 'question_id',
					'type' => '%d',
				),
				'audio_id'      => array(
					'col'  => 'audio_id',
					'type' => '%d',
				),
				'created_by'    => array(
					'col'  => 'created_by',
					'type' => '%d',
				),
			);

			foreach ( $map as $key => $cfg ) {
				if ( ! is_null( $args[ $key ] ) ) {
					if ( is_array( $args[ $key ] ) ) {
						$placeholders = array_fill( 0, count( $args[ $key ] ), $cfg['type'] );
						$where[]      = $cfg['col'] . ' IN (' . implode( ',', $placeholders ) . ')';
						$vals         = array_merge( $vals, $args[ $key ] );
					} else {
						$where[] = $cfg['col'] . ' = ' . $cfg['type'];
						$vals[]  = $args[ $key ];
					}
				}
			}

			if ( ! is_null( $args['date_query'] ) && is_array( $args['date_query'] ) ) {
				if ( ! empty( $args['date_query']['after'] ) ) {
					$where[] = 'created_at >= %s';
					$vals[]  = $args['date_query']['after'];
				}
				if ( ! empty( $args['date_query']['before'] ) ) {
					$where[] = 'created_at <= %s';
					$vals[]  = $args['date_query']['before'];
				}
			}

			$sql = 'SELECT ' . $select . ' FROM ' . $from . ' WHERE ' . implode( ' AND ', $where );

			if ( ! $args['count'] ) {
				$allowed_orderby = array( 'id', 'submission_id', 'question_id', 'audio_id', 'created_by', 'created_at' );
				$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
				$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
				$sql            .= " ORDER BY $orderby $order";
				$sql            .= ' LIMIT %d OFFSET %d';
				$vals[]          = max( 1, (int) $args['number'] );
				$vals[]          = max( 0, (int) $args['offset'] );
			}

			$prepared = $this->wpdb->prepare( $sql, $vals );

			if ( $args['count'] ) {
				$count = $this->wpdb->get_var( $prepared );
				if ( null === $count && $this->wpdb->last_error ) {
					throw new Exception( $this->wpdb->last_error );
				}
				return (int) $count;
			}

			$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
			if ( null === $rows && $this->wpdb->last_error ) {
				throw new Exception( $this->wpdb->last_error );
			}
			return $rows;
		} catch ( Exception $e ) {
			return new WP_Error( 'database_error', 'Failed to retrieve speech attempts: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete a speech attempt.
	 *
	 * @param int $attempt_id Attempt ID.
	 * @throws Exception When a database error occurs.
	 * @return bool|WP_Error True on success, or WP_Error on failure.
	 */
	public function delete_speech_attempt( $attempt_id ) {
		$attempt_id = absint( $attempt_id );
		if ( ! $attempt_id ) {
			return new WP_Error( 'invalid_id', 'Invalid attempt ID.', array( 'status' => 400 ) );
		}

		$existing = $this->get_speech_attempt( $attempt_id );
		if ( is_wp_error( $existing ) ) {
			return $existing; // Propagate error.
		}
		if ( empty( $existing ) ) {
			return new WP_Error( 'not_found', 'Attempt not found.', array( 'status' => 404 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$deleted = $this->wpdb->delete( $this->speech_attempt_table, array( 'id' => $attempt_id ), array( '%d' ) );
			if ( false === $deleted ) {
				throw new Exception( 'Failed to delete speech attempt: ' . $this->wpdb->last_error );
			}
			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Fork (duplicate) a speech attempt and optionally its feedback.
	 *
	 * Creates a new speech attempt with a new audio attachment that references the same file on disk.
	 * This is disk-space efficient as it reuses the original audio file. Optionally copies all
	 * feedback entries associated with the original attempt.
	 *
	 * @param int   $attempt_id The ID of the speech attempt to fork.
	 * @param int   $user_id    Optional. The user ID to own the fork. Defaults to current user.
	 * @param array $options    Optional. Control forking behavior.
	 *     @var int|null $submission_id         Attach to a different part submission. Default keeps original.
	 *     @var int|null $question_id           Attach to a different question. Default keeps original.
	 *     @var bool     $copy_feedback         Copy all attempt feedback entries. Default true.
	 *     @var bool     $copy_attachment_meta  Copy WordPress attachment metadata. Default true.
	 * @throws Exception When a database error occurs.
	 * @return array|WP_Error Details of the forked attempt or WP_Error on failure.
	 */
	public function fork_speech_attempt( $attempt_id, $user_id = null, $options = array() ) {
		$attempt_id = absint( $attempt_id );
		if ( ! $attempt_id ) {
			return new WP_Error( 'invalid_id', 'Invalid speech attempt ID.', array( 'status' => 400 ) );
		}

		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'invalid_user', 'Invalid user ID.', array( 'status' => 400 ) );
		}

		$defaults = array(
			'submission_id'        => null,
			'question_id'          => null,
			'copy_feedback'        => true,
			'copy_attachment_meta' => true,
		);
		$options  = wp_parse_args( $options, $defaults );

		$original = $this->get_speech_attempt( $attempt_id );
		if ( is_wp_error( $original ) ) {
			return $original; // Propagate error.
		}
		if ( empty( $original ) ) {
			return new WP_Error( 'not_found', 'Original speech attempt not found.', array( 'status' => 404 ) );
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			// Get original attachment details.
			$original_audio = get_post( $original['audio_id'] );
			if ( ! $original_audio ) {
				throw new Exception( 'Original audio attachment not found.' );
			}

			// Get the file path (but don't copy the file).
			$file_path = get_attached_file( $original['audio_id'] );
			if ( ! $file_path ) {
				throw new Exception( 'Could not retrieve file path for original audio.' );
			}

			// Create new attachment post with SAME file path (disk-space efficient).
			$new_attachment = array(
				'post_mime_type' => $original_audio->post_mime_type,
				'post_title'     => $original_audio->post_title,
				'post_content'   => $original_audio->post_content,
				'post_status'    => 'inherit',
				'post_parent'    => $original_audio->post_parent,
				'guid'           => $original_audio->guid,
			);

			$new_audio_id = wp_insert_attachment( $new_attachment, $file_path );
			if ( is_wp_error( $new_audio_id ) ) {
				throw new Exception( 'Failed to create new audio attachment: ' . $new_audio_id->get_error_message() );
			}
			if ( ! $new_audio_id ) {
				throw new Exception( 'Failed to create new audio attachment.' );
			}

			// Copy attachment metadata if requested.
			if ( $options['copy_attachment_meta'] ) {
				$attachment_meta = wp_get_attachment_metadata( $original['audio_id'] );
				if ( $attachment_meta ) {
					wp_update_attachment_metadata( $new_audio_id, $attachment_meta );
				}

				// Copy other post meta (excluding _wp_attachment_metadata which was already copied above).
				$all_meta = get_post_meta( $original['audio_id'] );
				if ( is_array( $all_meta ) ) {
					foreach ( $all_meta as $key => $values ) {
						if ( '_wp_attachment_metadata' !== $key ) {
							foreach ( (array) $values as $value ) {
								add_post_meta( $new_audio_id, $key, maybe_unserialize( $value ) );
							}
						}
					}
				}
			}

			// Add meta to link the new attachment back to the original.
			add_post_meta( $new_audio_id, '_forked_from_attachment_id', $original['audio_id'] );

			// Determine submission_id and question_id for new attempt.
			$new_submission_id = is_null( $options['submission_id'] )
				? ( ! empty( $original['submission_id'] ) ? (int) $original['submission_id'] : null )
				: (int) $options['submission_id'];

			$new_question_id = is_null( $options['question_id'] )
				? ( ! empty( $original['question_id'] ) ? (int) $original['question_id'] : null )
				: (int) $options['question_id'];

			// Create new speech attempt.
			$attempt_data = array(
				'audio_id'   => $new_audio_id,
				'created_by' => $user_id,
			);

			if ( ! is_null( $new_submission_id ) ) {
				$attempt_data['submission_id'] = $new_submission_id;
			}
			if ( ! is_null( $new_question_id ) ) {
				$attempt_data['question_id'] = $new_question_id;
			}

			$new_attempt_id = $this->add_speech_attempt( $attempt_data );
			if ( is_wp_error( $new_attempt_id ) ) {
				// Clean up the attachment we created.
				wp_delete_attachment( $new_audio_id, true );
				throw new Exception( 'Failed to create new speech attempt: ' . $new_attempt_id->get_error_message() );
			}

			// Copy feedback if requested.
			$forked_feedbacks = array();
			if ( $options['copy_feedback'] ) {
				$speech_db = new Ieltssci_Speech_DB();
				$feedbacks = $speech_db->get_speech_attempt_feedbacks(
					array(
						'attempt_id'       => $attempt_id,
						'limit'            => 999,
						'order'            => 'ASC',
						'include_cot'      => true,
						'include_score'    => true,
						'include_feedback' => true,
					)
				);

				if ( is_wp_error( $feedbacks ) ) {
					// Don't fail the entire operation, just log.
					error_log( 'Failed to retrieve feedbacks for forking: ' . $feedbacks->get_error_message() );
				} elseif ( ! empty( $feedbacks ) && is_array( $feedbacks ) ) {
					foreach ( $feedbacks as $feedback ) {
						$feedback_data = array(
							'attempt_id'        => $new_attempt_id,
							'feedback_criteria' => $feedback['feedback_criteria'],
							'feedback_language' => $feedback['feedback_language'],
							'source'            => $feedback['source'],
							'created_by'        => $feedback['created_by'],
							'cot_content'       => $feedback['cot_content'] ?? null,
							'score_content'     => $feedback['score_content'] ?? null,
							'feedback_content'  => $feedback['feedback_content'] ?? null,
							'is_preferred'      => $feedback['is_preferred'] ?? 0,
						);

						$new_feedback = $speech_db->create_speech_attempt_feedback( $feedback_data );
						if ( ! is_wp_error( $new_feedback ) ) {
							$forked_feedbacks[] = $new_feedback;
						} else {
							// Log but continue with other feedbacks.
							error_log( 'Failed to fork feedback: ' . $new_feedback->get_error_message() );
						}
					}
				}
			}

			$this->wpdb->query( 'COMMIT' );

			return array(
				'speech_attempt'    => $this->get_speech_attempt( $new_attempt_id ),
				'new_audio_id'      => $new_audio_id,
				'original_audio_id' => $original['audio_id'],
				'forked_feedbacks'  => $forked_feedbacks,
				'feedbacks_count'   => count( $forked_feedbacks ),
			);
		} catch ( Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			// Clean up attachment if it was created.
			if ( isset( $new_audio_id ) && $new_audio_id ) {
				wp_delete_attachment( $new_audio_id, true );
			}
			return new WP_Error( 'fork_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
