<?php
/**
 * Query Handler for IELTS Science LMS Essays
 *
 * Handles database queries for essay submissions.
 *
 * @package IELTS_Science_LMS
 * @subpackage UsersInsights
 */

namespace IeltsScienceLMS\UsersInsights;

use USIN_Query_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query handler for essay data.
 */
class USIN_IeltsScience_Query {

	/**
	 * Essays table name.
	 *
	 * @var string
	 */
	protected $essays_table;

	/**
	 * Track if join has been applied.
	 *
	 * @var bool
	 */
	protected $has_essays_join_applied = false;

	/**
	 * Track if essay type filter join applied.
	 *
	 * @var bool
	 */
	protected $has_essay_type_join_applied = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->essays_table = $wpdb->prefix . 'ieltssci_essays';
	}

	/**
	 * Initialize query filters.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'usin_db_map', array( $this, 'filter_db_map' ) );
		add_filter( 'usin_query_join_table', array( $this, 'filter_query_joins' ), 10, 2 );
		add_filter( 'usin_custom_query_filter', array( $this, 'apply_filters' ), 10, 2 );
		add_filter( 'usin_custom_select', array( $this, 'filter_query_select' ), 10, 2 );
	}

	/**
	 * Map custom fields to database columns.
	 *
	 * @param array $db_map Existing database map.
	 * @return array Modified database map.
	 */
	public function filter_db_map( $db_map ) {
		$db_map['ielts_essays_submitted'] = array(
			'db_ref'       => 'essays_count',
			'db_table'     => 'ielts_essays',
			'null_to_zero' => true,
			'set_alias'    => true,
		);

		$db_map['ielts_last_essay_date'] = array(
			'db_ref'     => 'last_essay',
			'db_table'   => 'ielts_essays',
			'nulls_last' => true,
			'cast'       => 'DATETIME',
		);

		$db_map['ielts_first_essay_date'] = array(
			'db_ref'     => 'first_essay',
			'db_table'   => 'ielts_essays',
			'nulls_last' => true,
			'cast'       => 'DATETIME',
		);

		$db_map['ielts_submitted_essay'] = array(
			'db_ref'    => '',
			'db_table'  => '',
			'no_select' => true,
		);

		$db_map['ielts_task1_count'] = array(
			'db_ref'       => 'task1_count',
			'db_table'     => 'ielts_essays',
			'null_to_zero' => true,
			'set_alias'    => true,
		);

		$db_map['ielts_task2_count'] = array(
			'db_ref'       => 'task2_count',
			'db_table'     => 'ielts_essays',
			'null_to_zero' => true,
			'set_alias'    => true,
		);

		$db_map['ielts_avg_word_count'] = array(
			'db_ref'        => 'avg_words',
			'db_table'      => 'ielts_essays',
			'null_to_zero'  => true,
			'custom_select' => true,
			'set_alias'     => true,
		);

		return $db_map;
	}

	/**
	 * Custom select for calculated fields.
	 *
	 * @param string $query_select Current select query.
	 * @param string $field Field name.
	 * @return string Modified select query.
	 */
	public function filter_query_select( $query_select, $field ) {
		if ( 'ielts_avg_word_count' === $field ) {
			$query_select = 'CAST(IFNULL(ielts_essays.avg_words, 0) AS UNSIGNED)';
		}
		return $query_select;
	}

	/**
	 * Add custom joins to query.
	 *
	 * @param string $query_joins Current joins.
	 * @param string $table Table being joined.
	 * @return string Modified joins.
	 */
	public function filter_query_joins( $query_joins, $table ) {
		global $wpdb;

		if ( 'ielts_essays' === $table ) {
			$query_joins .= ' LEFT JOIN (' . $this->get_essays_select() . ') AS ielts_essays ON ' . $wpdb->users . '.ID = ielts_essays.user_id';
		}

		return $query_joins;
	}

	/**
	 * Get the essays aggregation query.
	 *
	 * @return string SQL query.
	 */
	protected function get_essays_select() {
		global $wpdb;

		$first_essay_select = USIN_Query_Helper::get_gmt_offset_date_select( 'MIN(created_at)' );
		$last_essay_select  = USIN_Query_Helper::get_gmt_offset_date_select( 'MAX(created_at)' );

		return "SELECT
			COUNT(id) as essays_count,
			$first_essay_select as first_essay,
			$last_essay_select as last_essay,
			SUM(CASE WHEN essay_type = 'task-1' THEN 1 ELSE 0 END) as task1_count,
			SUM(CASE WHEN essay_type = 'task-2' THEN 1 ELSE 0 END) as task2_count,
			AVG(
				LENGTH(essay_content) - LENGTH(REPLACE(essay_content, ' ', '')) + 1
			) as avg_words,
			created_by as user_id
		FROM {$this->essays_table}
		GROUP BY created_by";
	}

	/**
	 * Apply custom filters.
	 *
	 * @param array  $custom_query_data Query data.
	 * @param object $filter Filter object.
	 * @return array Modified query data.
	 */
	public function apply_filters( $custom_query_data, $filter ) {
		global $wpdb;

		if ( 'ielts_submitted_essay' === $filter->by ) {
			if ( ! $this->has_essay_type_join_applied ) {
				$custom_query_data['joins']       .= " INNER JOIN {$this->essays_table} AS ielts_essay_filter ON {$wpdb->users}.ID = ielts_essay_filter.created_by";
				$this->has_essay_type_join_applied = true;
			}

			// Handle date filter.
			if ( ! empty( $filter->condition['date'] ) ) {
				$date_column = USIN_Query_Helper::get_gmt_offset_date_select( 'ielts_essay_filter.created_at' );

				if ( isset( $filter->condition['date'][0] ) ) {
					$custom_query_data['where'] .= $wpdb->prepare(
						' AND DATE(' . $date_column . ') >= %s',
						$filter->condition['date'][0]
					);
				}

				if ( isset( $filter->condition['date'][1] ) ) {
					$custom_query_data['where'] .= $wpdb->prepare(
						' AND DATE(' . $date_column . ') <= %s',
						$filter->condition['date'][1]
					);
				}
			}

			// Handle essay type filter.
			if ( ! empty( $filter->condition['essay_type'] ) ) {
				$custom_query_data['where'] .= $wpdb->prepare(
					' AND ielts_essay_filter.essay_type = %s',
					$filter->condition['essay_type']
				);
			}
		}

		return $custom_query_data;
	}
}
