<?php
/**
 * Term functions for IELTS Science LMS plugin.
 *
 * @package IELTS_Science_LMS
 * @subpackage Core
 */

namespace IeltsScienceLMS\Core;

/**
 * Term ordering functions class.
 *
 * Handles term ordering for collection taxonomies similar to WooCommerce.
 */
class Ieltssci_Term_Functions {

	/**
	 * Sortable taxonomies.
	 *
	 * @var array
	 */
	private $sortable_taxonomies = array(
		'speaking-part-collection',
		'writing-task-collection',
	);

	/**
	 * Initialize the term functions.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_term_ordering_scripts' ) );
		add_action( 'wp_ajax_ieltssci_term_ordering', array( $this, 'term_ordering_ajax' ) );
		add_filter( 'pre_get_terms', array( $this, 'change_pre_get_terms' ), 10, 1 );
		add_filter( 'terms_clauses', array( $this, 'terms_clauses' ), 99, 3 );
	}

	/**
	 * Get sortable taxonomies.
	 *
	 * @return array Sortable taxonomies.
	 */
	public function get_sortable_taxonomies() {
		return apply_filters( 'ieltssci_sortable_taxonomies', $this->sortable_taxonomies );
	}

	/**
	 * Enqueue term ordering scripts.
	 */
	public function enqueue_term_ordering_scripts() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_text_field( wp_unslash( $_GET['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Only load on taxonomy edit screens for sortable taxonomies.
		if ( ! empty( $taxonomy ) && in_array( $taxonomy, $this->get_sortable_taxonomies(), true ) && ! isset( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$plugin_root = dirname( dirname( __DIR__ ) );
			$plugin_main = $plugin_root . '/ielts-science-lms.php';
			$js_file     = $plugin_root . '/admin/js/term-ordering.js';
			$css_file    = $plugin_root . '/admin/css/term-ordering.css';

			wp_enqueue_script(
				'ieltssci-term-ordering',
				plugins_url( 'admin/js/term-ordering.js', $plugin_main ),
				array( 'jquery-ui-sortable' ),
				file_exists( $js_file ) ? filemtime( $js_file ) : false,
				true
			);

			wp_localize_script(
				'ieltssci-term-ordering',
				'ieltssci_term_ordering_params',
				array(
					'taxonomy' => $taxonomy,
					'nonce'    => wp_create_nonce( 'ieltssci_term_ordering' ),
				)
			);

			// Enqueue term ordering styles.
			wp_enqueue_style(
				'ieltssci-term-ordering',
				plugins_url( 'admin/css/term-ordering.css', $plugin_main ),
				array(),
				file_exists( $css_file ) ? filemtime( $css_file ) : false
			);
		}
	}

	/**
	 * AJAX handler for term ordering.
	 */
	public function term_ordering_ajax() {
		// Verify nonce.
		check_ajax_referer( 'ieltssci_term_ordering', 'security' );

		// Check permissions.
		if ( ! current_user_can( 'manage_categories' ) || empty( $_POST['id'] ) ) {
			wp_die( -1 );
		}

		$id       = (int) $_POST['id'];
		$next_id  = isset( $_POST['nextid'] ) && (int) $_POST['nextid'] ? (int) $_POST['nextid'] : null;
		$taxonomy = isset( $_POST['thetaxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['thetaxonomy'] ) ) : null;
		$term     = get_term_by( 'id', $id, $taxonomy );

		if ( ! $id || ! $term || ! $taxonomy ) {
			wp_die( 0 );
		}

		// Verify this is a sortable taxonomy.
		if ( ! in_array( $taxonomy, $this->get_sortable_taxonomies(), true ) ) {
			wp_die( 0 );
		}

		$this->reorder_terms( $term, $next_id, $taxonomy );

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'child_of'   => $id,
				'hide_empty' => false,
			)
		);

		$children_count = is_countable( $children ) ? count( $children ) : 0;
		if ( $term && $children_count ) {
			echo 'children';
			wp_die();
		}

		wp_die();
	}

	/**
	 * Move a term before the given element of its hierarchy level.
	 *
	 * @param \WP_Term $the_term Term object.
	 * @param int|null $next_id  The id of the next sibling element in save hierarchy level.
	 * @param string   $taxonomy Taxonomy.
	 * @param int      $index    Term index (default: 0).
	 * @param array    $terms    List of terms. (default: null).
	 * @return int
	 */
	public function reorder_terms( $the_term, $next_id, $taxonomy, $index = 0, $terms = null ) {
		if ( ! $terms ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'parent'     => 0,
					'orderby'    => 'menu_order',
					'order'      => 'ASC',
				)
			);
		}
		if ( empty( $terms ) ) {
			return $index;
		}

		$id = intval( $the_term->term_id );

		$term_in_level = false; // Flag: is our term to order in this level of terms.

		foreach ( $terms as $term ) {
			$term_id = intval( $term->term_id );

			if ( $term_id === $id ) { // Our term to order, we skip.
				$term_in_level = true;
				continue; // Our term to order, we skip.
			}
			// the nextid of our term to order, lets move our term here.
			if ( null !== $next_id && $term_id === $next_id ) {
				++$index;
				$index = $this->set_term_order( $id, $index, $taxonomy, true );
			}

			// Set order.
			++$index;
			$index = $this->set_term_order( $term_id, $index, $taxonomy );

			/**
			 * After a term has had its order set.
			 *
			 * @param \WP_Term $term     Term object.
			 * @param int      $index    Term index.
			 * @param string   $taxonomy Taxonomy.
			 */
			do_action( 'ieltssci_after_set_term_order', $term, $index, $taxonomy );

			// If that term has children we walk through them.
			$children = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $term_id,
					'hide_empty' => false,
					'orderby'    => 'menu_order',
					'order'      => 'ASC',
				)
			);
			if ( ! empty( $children ) ) {
				$index = $this->reorder_terms( $the_term, $next_id, $taxonomy, $index, $children );
			}
		}

		// No nextid meaning our term is in last position.
		if ( $term_in_level && null === $next_id ) {
			$index = $this->set_term_order( $id, $index + 1, $taxonomy, true );
		}

		return $index;
	}

	/**
	 * Set the sort order of a term.
	 *
	 * @param int    $term_id   Term ID.
	 * @param int    $index     Index.
	 * @param string $taxonomy  Taxonomy.
	 * @param bool   $recursive Recursive (default: false).
	 * @return int
	 */
	public function set_term_order( $term_id, $index, $taxonomy, $recursive = false ) {
		$term_id = (int) $term_id;
		$index   = (int) $index;

		update_term_meta( $term_id, 'order', $index );

		if ( ! $recursive ) {
			return $index;
		}

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'orderby'    => 'menu_order',
				'order'      => 'ASC',
			)
		);

		foreach ( $children as $term ) {
			++$index;
			$index = $this->set_term_order( $term->term_id, $index, $taxonomy, true );
		}

		clean_term_cache( $term_id, $taxonomy );

		return $index;
	}

	/**
	 * Filter term orderby to use menu_order for sortable taxonomies.
	 *
	 * @param string   $orderby    Order by clause.
	 * @param array    $query_vars Query variables.
	 * @param string[] $taxonomies Array of taxonomy names.
	 * @return string
	 */
	public function get_terms_orderby( $orderby, $query_vars, $taxonomies ) {
		global $wpdb;

		// Check if any of the taxonomies are sortable.
		$sortable_taxonomies = $this->get_sortable_taxonomies();
		$is_sortable         = false;

		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array( $taxonomy, $sortable_taxonomies, true ) ) {
				$is_sortable = true;
				break;
			}
		}

		if ( ! $is_sortable ) {
			return $orderby;
		}

		// Only modify if not already ordering by something specific.
		if ( empty( $query_vars['orderby'] ) || 'name' === $query_vars['orderby'] ) {
			// Order by term meta 'order' field, fallback to term_id for terms without order meta.
			$orderby = "CAST((SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = t.term_id AND meta_key = 'order' LIMIT 1) AS UNSIGNED), t.term_id";
		}

		return $orderby;
	}

	/**
	 * Adds support to get_terms for menu_order argument.
	 *
	 * @param \WP_Term_Query $terms_query Instance of WP_Term_Query.
	 */
	public function change_pre_get_terms( $terms_query ) {
		$args = &$terms_query->query_vars;

		// Put back valid orderby values.
		if ( 'menu_order' === $args['orderby'] ) {
			$args['orderby']               = 'name';
			$args['force_menu_order_sort'] = true;
		}

		// When COUNTING, disable custom sorting.
		if ( 'count' === $args['fields'] ) {
			return;
		}

		// Support menu_order arg used in previous versions.
		if ( ! empty( $args['menu_order'] ) ) {
			$args['order']                 = 'DESC' === strtoupper( $args['menu_order'] ) ? 'DESC' : 'ASC';
			$args['force_menu_order_sort'] = true;
		}

		if ( ! empty( $args['force_menu_order_sort'] ) ) {
			$args['orderby']  = 'meta_value_num';
			$args['meta_key'] = 'order'; // phpcs:ignore
			$terms_query->meta_query->parse_query_vars( $args );
		}
	}

	/**
	 * Adjust term query to handle custom sorting parameters.
	 *
	 * @param array $clauses    Clauses.
	 * @param array $taxonomies Taxonomies.
	 * @param array $args       Arguments.
	 * @return array
	 */
	public function terms_clauses( $clauses, $taxonomies, $args ) {
		global $wpdb;

		// Check if any of the taxonomies are sortable.
		$sortable_taxonomies = $this->get_sortable_taxonomies();
		$is_sortable         = false;

		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array( $taxonomy, $sortable_taxonomies, true ) ) {
				$is_sortable = true;
				break;
			}
		}

		if ( ! $is_sortable ) {
			return $clauses;
		}

		// No need to filter when counting.
		if ( strpos( $clauses['fields'], 'COUNT(*)' ) !== false ) {
			return $clauses;
		}

		// For sorting, force left join in case order meta is missing.
		if ( ! empty( $args['force_menu_order_sort'] ) ) {
			$clauses['join']    = str_replace( "INNER JOIN {$wpdb->termmeta} ON ( t.term_id = {$wpdb->termmeta}.term_id )", "LEFT JOIN {$wpdb->termmeta} ON ( t.term_id = {$wpdb->termmeta}.term_id AND {$wpdb->termmeta}.meta_key='order')", $clauses['join'] );
			$clauses['where']   = str_replace( "{$wpdb->termmeta}.meta_key = 'order'", "( {$wpdb->termmeta}.meta_key = 'order' OR {$wpdb->termmeta}.meta_key IS NULL )", $clauses['where'] );
			$clauses['orderby'] = 'DESC' === $args['order'] ? str_replace( 'meta_value+0', 'meta_value+0 DESC, t.name', $clauses['orderby'] ) : str_replace( 'meta_value+0', 'meta_value+0 ASC, t.name', $clauses['orderby'] );
		}

		return $clauses;
	}
}
