<?php
/**
 * ACF (Advanced Custom Fields) integration for IELTS Science LMS plugin.
 *
 * @package IELTS_Science_LMS
 * @subpackage Core
 */

namespace IeltsScienceLMS\Core;

/**
 * ACF integration class handling field groups, post types, and taxonomies.
 *
 * This class manages the registration of ACF field groups, custom post types,
 * and taxonomies for the writing module functionality.
 */
class Ieltssci_ACF {

	/**
	 * Initialize the ACF integration.
	 */
	public function __construct() {
		add_action( 'acf/include_fields', array( $this, 'register_field_groups' ) );
		add_action( 'init', array( $this, 'register_taxonomies' ) );
		add_action( 'init', array( $this, 'register_post_types' ) );
	}

	/**
	 * Register ACF field groups for writing tasks and tests.
	 */
	public function register_field_groups() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		// Writing Task fields.
		acf_add_local_field_group(
			array(
				'key'                   => 'group_6842b1a0c4f8b',
				'title'                 => 'Writing Task fields',
				'fields'                => array(
					array(
						'key'               => 'field_6842bd34f902c',
						'label'             => 'Chart',
						'name'              => 'chart',
						'aria-label'        => '',
						'type'              => 'image',
						'instructions'      => '',
						'required'          => 0,
						'conditional_logic' => 0,
						'wrapper'           => array(
							'width' => '',
							'class' => '',
							'id'    => '',
						),
						'return_format'     => 'array',
						'library'           => 'uploadedTo',
						'min_width'         => '',
						'min_height'        => '',
						'min_size'          => '',
						'max_width'         => '',
						'max_height'        => '',
						'max_size'          => '',
						'mime_types'        => '',
						'allow_in_bindings' => 0,
						'preview_size'      => 'medium',
					),
					array(
						'key'                => 'field_6842b1a368f32',
						'label'              => 'Writing Question',
						'name'               => 'writing_question',
						'aria-label'         => '',
						'type'               => 'textarea',
						'instructions'       => '',
						'required'           => 0,
						'conditional_logic'  => 0,
						'wrapper'            => array(
							'width' => '',
							'class' => '',
							'id'    => '',
						),
						'acfbs_allow_search' => 1,
						'default_value'      => '',
						'maxlength'          => '',
						'allow_in_bindings'  => 0,
						'rows'               => '',
						'placeholder'        => '',
						'new_lines'          => '',
					),
				),
				'location'              => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'writing-task',
						),
					),
				),
				'menu_order'            => 0,
				'position'              => 'normal',
				'style'                 => 'default',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'hide_on_screen'        => '',
				'active'                => true,
				'description'           => '',
				'show_in_rest'          => 1,
			)
		);

		// Writing Test fields.
		acf_add_local_field_group(
			array(
				'key'                   => 'group_6842c0c48be70',
				'title'                 => 'Writing Test fields',
				'fields'                => array(
					array(
						'key'                  => 'field_6842c0c624599',
						'label'                => 'Writing Tasks',
						'name'                 => 'writing_tasks',
						'aria-label'           => '',
						'type'                 => 'relationship',
						'instructions'         => '',
						'required'             => 0,
						'conditional_logic'    => 0,
						'wrapper'              => array(
							'width' => '',
							'class' => '',
							'id'    => '',
						),
						'post_type'            => array(
							0 => 'writing-task',
						),
						'post_status'          => '',
						'taxonomy'             => '',
						'filters'              => array(
							0 => 'search',
							1 => 'taxonomy',
						),
						'return_format'        => 'object',
						'min'                  => '',
						'max'                  => '',
						'allow_in_bindings'    => 0,
						'elements'             => '',
						'bidirectional'        => 0,
						'bidirectional_target' => array(),
					),
				),
				'location'              => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'writing-test',
						),
					),
				),
				'menu_order'            => 0,
				'position'              => 'normal',
				'style'                 => 'default',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'hide_on_screen'        => '',
				'active'                => true,
				'description'           => '',
				'show_in_rest'          => 1,
			)
		);

		acf_add_local_field_group(
			array(
				'key'                   => 'group_685beb5653abf',
				'title'                 => 'Speaking Parts Fields',
				'fields'                => array(
					array(
						'key'                  => 'field_68d3c16503dd6',
						'label'                => 'Speaking Questions',
						'name'                 => 'speaking_questions',
						'aria-label'           => '',
						'type'                 => 'relationship',
						'instructions'         => '',
						'required'             => 1,
						'conditional_logic'    => 0,
						'wrapper'              => array(
							'width' => '',
							'class' => '',
							'id'    => '',
						),
						'post_type'            => array(
							0 => 'speaking-question',
						),
						'post_status'          => array(
							0 => 'publish',
						),
						'taxonomy'             => '',
						'filters'              => array(
							0 => 'search',
							1 => 'post_type',
							2 => 'taxonomy',
						),
						'return_format'        => 'object',
						'min'                  => '',
						'max'                  => '',
						'allow_in_bindings'    => 0,
						'elements'             => '',
						'bidirectional'        => 0,
						'bidirectional_target' => array(),
					),
				),
				'location'              => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'speaking-part',
						),
					),
				),
				'menu_order'            => 0,
				'position'              => 'normal',
				'style'                 => 'default',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'hide_on_screen'        => '',
				'active'                => true,
				'description'           => '',
				'show_in_rest'          => 1,
			)
		);

		acf_add_local_field_group(
			array(
				'key'                   => 'group_68d4d291eb9e2',
				'title'                 => 'Speaking Question Fields',
				'fields'                => array(
					array(
						'key'                => 'field_68d4d2993d5a8',
						'label'              => 'Question Audio',
						'name'               => 'question_audio',
						'aria-label'         => '',
						'type'               => 'file',
						'instructions'       => '',
						'required'           => 0,
						'conditional_logic'  => 0,
						'wrapper'            => array(
							'width' => '',
							'class' => '',
							'id'    => '',
						),
						'acfbs_allow_search' => 0,
						'return_format'      => 'array',
						'library'            => 'uploadedTo',
						'min_size'           => '',
						'max_size'           => '',
						'mime_types'         => '',
						'allow_in_bindings'  => 0,
					),
				),
				'location'              => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'speaking-question',
						),
					),
				),
				'menu_order'            => 0,
				'position'              => 'normal',
				'style'                 => 'default',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'hide_on_screen'        => '',
				'active'                => true,
				'description'           => '',
				'show_in_rest'          => 1,
			)
		);

		acf_add_local_field_group(
			array(
				'key'                   => 'group_68d4dec41e65e',
				'title'                 => 'Speaking Test Fields',
				'fields'                => array(
					array(
						'key'                  => 'field_68d4decb298e3',
						'label'                => 'Speaking Parts',
						'name'                 => 'speaking_parts',
						'aria-label'           => '',
						'type'                 => 'relationship',
						'instructions'         => '',
						'required'             => 0,
						'conditional_logic'    => 0,
						'wrapper'              => array(
							'width' => '',
							'class' => '',
							'id'    => '',
						),
						'acfbs_allow_search'   => 0,
						'post_type'            => array(
							0 => 'speaking-part',
						),
						'post_status'          => array(
							0 => 'publish',
						),
						'taxonomy'             => '',
						'filters'              => array(
							0 => 'search',
							1 => 'post_type',
							2 => 'taxonomy',
						),
						'return_format'        => 'object',
						'min'                  => '',
						'max'                  => '',
						'allow_in_bindings'    => 0,
						'elements'             => '',
						'bidirectional'        => 0,
						'bidirectional_target' => array(),
					),
				),
				'location'              => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'speaking-test',
						),
					),
				),
				'menu_order'            => 0,
				'position'              => 'normal',
				'style'                 => 'default',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'hide_on_screen'        => '',
				'active'                => true,
				'description'           => '',
				'show_in_rest'          => 1,
			)
		);
	}

	/**
	 * Register custom taxonomies for writing tasks and tests.
	 */
	public function register_taxonomies() {
		// Writing Tags taxonomy.
		register_taxonomy(
			'writing-tag',
			array(
				0 => 'writing-task',
			),
			array(
				'labels'       => array(
					'name'                       => 'Writing Tags',
					'singular_name'              => 'Writing Tag',
					'menu_name'                  => 'Writing Task Tags',
					'all_items'                  => 'All Writing Task Tags',
					'edit_item'                  => 'Edit Writing Task Tag',
					'view_item'                  => 'View Writing Task Tag',
					'update_item'                => 'Update Writing Task Tag',
					'add_new_item'               => 'Add New Writing Task Tag',
					'new_item_name'              => 'New Writing Task Tag Name',
					'search_items'               => 'Search Writing Task Tags',
					'popular_items'              => 'Popular Writing Task Tags',
					'separate_items_with_commas' => 'Separate writing task tags with commas',
					'add_or_remove_items'        => 'Add or remove writing task tags',
					'choose_from_most_used'      => 'Choose from the most used writing task tags',
					'not_found'                  => 'No writing task tags found',
					'no_terms'                   => 'No writing task tags',
					'items_list_navigation'      => 'Writing Task Tags list navigation',
					'items_list'                 => 'Writing Task Tags list',
					'back_to_items'              => '← Go to writing task tags',
					'item_link'                  => 'Writing Task Tag Link',
					'item_link_description'      => 'A link to a writing task tag',
				),
				'public'       => true,
				'show_in_menu' => true,
				'show_in_rest' => true,
			)
		);

		// Writing Task Type taxonomy.
		register_taxonomy(
			'writing-task-type',
			array(
				0 => 'writing-task',
			),
			array(
				'labels'       => array(
					'name'                       => 'Writing Task Types',
					'singular_name'              => 'Writing Task Type',
					'menu_name'                  => 'Writing Task Types',
					'all_items'                  => 'All Writing Task Types',
					'edit_item'                  => 'Edit Writing Task Type',
					'view_item'                  => 'View Writing Task Type',
					'update_item'                => 'Update Writing Task Type',
					'add_new_item'               => 'Add New Writing Task Type',
					'new_item_name'              => 'New Writing Task Type Name',
					'search_items'               => 'Search Writing Task Types',
					'popular_items'              => 'Popular Writing Task Types',
					'separate_items_with_commas' => 'Separate writing task types with commas',
					'add_or_remove_items'        => 'Add or remove writing task types',
					'choose_from_most_used'      => 'Choose from the most used writing task types',
					'not_found'                  => 'No writing task types found',
					'no_terms'                   => 'No writing task types',
					'items_list_navigation'      => 'Writing Task Types list navigation',
					'items_list'                 => 'Writing Task Types list',
					'back_to_items'              => '← Go to writing task types',
					'item_link'                  => 'Writing Task Type Link',
					'item_link_description'      => 'A link to a writing task type',
				),
				'public'       => true,
				'show_in_menu' => true,
				'show_in_rest' => true,
			)
		);

		// Writing Test Tag taxonomy.
		register_taxonomy(
			'writing-test-tag',
			array(
				0 => 'writing-test',
			),
			array(
				'labels'       => array(
					'name'                       => 'Writing Test Tags',
					'singular_name'              => 'Writing Test Tag',
					'menu_name'                  => 'Writing Test Tags',
					'all_items'                  => 'All Writing Test Tags',
					'edit_item'                  => 'Edit Writing Test Tag',
					'view_item'                  => 'View Writing Test Tag',
					'update_item'                => 'Update Writing Test Tag',
					'add_new_item'               => 'Add New Writing Test Tag',
					'new_item_name'              => 'New Writing Test Tag Name',
					'search_items'               => 'Search Writing Test Tags',
					'popular_items'              => 'Popular Writing Test Tags',
					'separate_items_with_commas' => 'Separate writing test tags with commas',
					'add_or_remove_items'        => 'Add or remove writing test tags',
					'choose_from_most_used'      => 'Choose from the most used writing test tags',
					'not_found'                  => 'No writing test tags found',
					'no_terms'                   => 'No writing test tags',
					'items_list_navigation'      => 'Writing Test Tags list navigation',
					'items_list'                 => 'Writing Test Tags list',
					'back_to_items'              => '← Go to writing test tags',
					'item_link'                  => 'Writing Test Tag Link',
					'item_link_description'      => 'A link to a writing test tag',
				),
				'public'       => true,
				'show_in_menu' => true,
				'show_in_rest' => true,
			)
		);

		register_taxonomy(
			'speaking-part-tag',
			array(
				0 => 'speaking-part',
			),
			array(
				'labels'       => array(
					'name'                       => 'Speaking Part Tags',
					'singular_name'              => 'Speaking Part Tag',
					'menu_name'                  => 'Speaking Part Tags',
					'all_items'                  => 'All Speaking Part Tags',
					'edit_item'                  => 'Edit Speaking Part Tag',
					'view_item'                  => 'View Speaking Part Tag',
					'update_item'                => 'Update Speaking Part Tag',
					'add_new_item'               => 'Add New Speaking Part Tag',
					'new_item_name'              => 'New Speaking Part Tag Name',
					'search_items'               => 'Search Speaking Part Tags',
					'popular_items'              => 'Popular Speaking Part Tags',
					'separate_items_with_commas' => 'Separate speaking part tags with commas',
					'add_or_remove_items'        => 'Add or remove speaking part tags',
					'choose_from_most_used'      => 'Choose from the most used speaking part tags',
					'not_found'                  => 'No speaking part tags found',
					'no_terms'                   => 'No speaking part tags',
					'items_list_navigation'      => 'Speaking Part Tags list navigation',
					'items_list'                 => 'Speaking Part Tags list',
					'back_to_items'              => '← Go to speaking part tags',
					'item_link'                  => 'Speaking Part Tag Link',
					'item_link_description'      => 'A link to a speaking part tag',
				),
				'public'       => true,
				'show_in_menu' => true,
				'show_in_rest' => true,
			)
		);

		register_taxonomy(
			'speaking-part-type',
			array(
				0 => 'speaking-part',
			),
			array(
				'labels'       => array(
					'name'                       => 'Speaking Part Types',
					'singular_name'              => 'Speaking Part Type',
					'menu_name'                  => 'Speaking Part Types',
					'all_items'                  => 'All Speaking Part Types',
					'edit_item'                  => 'Edit Speaking Part Type',
					'view_item'                  => 'View Speaking Part Type',
					'update_item'                => 'Update Speaking Part Type',
					'add_new_item'               => 'Add New Speaking Part Type',
					'new_item_name'              => 'New Speaking Part Type Name',
					'search_items'               => 'Search Speaking Part Types',
					'popular_items'              => 'Popular Speaking Part Types',
					'separate_items_with_commas' => 'Separate speaking part types with commas',
					'add_or_remove_items'        => 'Add or remove speaking part types',
					'choose_from_most_used'      => 'Choose from the most used speaking part types',
					'not_found'                  => 'No speaking part types found',
					'no_terms'                   => 'No speaking part types',
					'items_list_navigation'      => 'Speaking Part Types list navigation',
					'items_list'                 => 'Speaking Part Types list',
					'back_to_items'              => '← Go to speaking part types',
					'item_link'                  => 'Speaking Part Type Link',
					'item_link_description'      => 'A link to a speaking part type',
				),
				'public'       => true,
				'show_in_menu' => true,
				'show_in_rest' => true,
			)
		);

		register_taxonomy(
			'speaking-test-tag',
			array(
				0 => 'speaking-test',
			),
			array(
				'labels'       => array(
					'name'                       => 'Speaking Test Tags',
					'singular_name'              => 'Speaking Test Tag',
					'menu_name'                  => 'Speaking Test Tags',
					'all_items'                  => 'All Speaking Test Tags',
					'edit_item'                  => 'Edit Speaking Test Tag',
					'view_item'                  => 'View Speaking Test Tag',
					'update_item'                => 'Update Speaking Test Tag',
					'add_new_item'               => 'Add New Speaking Test Tag',
					'new_item_name'              => 'New Speaking Test Tag Name',
					'search_items'               => 'Search Speaking Test Tags',
					'popular_items'              => 'Popular Speaking Test Tags',
					'separate_items_with_commas' => 'Separate speaking test tags with commas',
					'add_or_remove_items'        => 'Add or remove speaking test tags',
					'choose_from_most_used'      => 'Choose from the most used speaking test tags',
					'not_found'                  => 'No speaking test tags found',
					'no_terms'                   => 'No speaking test tags',
					'items_list_navigation'      => 'Speaking Test Tags list navigation',
					'items_list'                 => 'Speaking Test Tags list',
					'back_to_items'              => '← Go to speaking test tags',
					'item_link'                  => 'Speaking Test Tag Link',
					'item_link_description'      => 'A link to a speaking test tag',
				),
				'public'       => true,
				'show_in_menu' => true,
				'show_in_rest' => true,
			)
		);

		register_taxonomy(
			'writing-test-tag',
			array(
				0 => 'writing-test',
			),
			array(
				'labels'       => array(
					'name'                       => 'Writing Test Tags',
					'singular_name'              => 'Writing Test Tag',
					'menu_name'                  => 'Writing Test Tags',
					'all_items'                  => 'All Writing Test Tags',
					'edit_item'                  => 'Edit Writing Test Tag',
					'view_item'                  => 'View Writing Test Tag',
					'update_item'                => 'Update Writing Test Tag',
					'add_new_item'               => 'Add New Writing Test Tag',
					'new_item_name'              => 'New Writing Test Tag Name',
					'search_items'               => 'Search Writing Test Tags',
					'popular_items'              => 'Popular Writing Test Tags',
					'separate_items_with_commas' => 'Separate writing test tags with commas',
					'add_or_remove_items'        => 'Add or remove writing test tags',
					'choose_from_most_used'      => 'Choose from the most used writing test tags',
					'not_found'                  => 'No writing test tags found',
					'no_terms'                   => 'No writing test tags',
					'items_list_navigation'      => 'Writing Test Tags list navigation',
					'items_list'                 => 'Writing Test Tags list',
					'back_to_items'              => '← Go to writing test tags',
					'item_link'                  => 'Writing Test Tag Link',
					'item_link_description'      => 'A link to a writing test tag',
				),
				'public'       => true,
				'show_in_menu' => true,
				'show_in_rest' => true,
			)
		);
	}

	/**
	 * Register custom post types for writing tasks and tests.
	 */
	public function register_post_types() {
		// Writing Task post type.
		register_post_type(
			'writing-task',
			array(
				'labels'           => array(
					'name'                     => 'Writing Tasks',
					'singular_name'            => 'Writing Task',
					'menu_name'                => 'Writing Tasks',
					'all_items'                => 'All Writing Tasks',
					'edit_item'                => 'Edit Writing Task',
					'view_item'                => 'View Writing Task',
					'view_items'               => 'View Writing Tasks',
					'add_new_item'             => 'Add New Writing Task',
					'add_new'                  => 'Add New Writing Task',
					'new_item'                 => 'New Writing Task',
					'parent_item_colon'        => 'Parent Writing Task:',
					'search_items'             => 'Search Writing Tasks',
					'not_found'                => 'No writing tasks found',
					'not_found_in_trash'       => 'No writing tasks found in Trash',
					'archives'                 => 'Writing Task Archives',
					'attributes'               => 'Writing Task Attributes',
					'insert_into_item'         => 'Insert into writing task',
					'uploaded_to_this_item'    => 'Uploaded to this writing task',
					'filter_items_list'        => 'Filter writing tasks list',
					'filter_by_date'           => 'Filter writing tasks by date',
					'items_list_navigation'    => 'Writing Tasks list navigation',
					'items_list'               => 'Writing Tasks list',
					'item_published'           => 'Writing Task published.',
					'item_published_privately' => 'Writing Task published privately.',
					'item_reverted_to_draft'   => 'Writing Task reverted to draft.',
					'item_scheduled'           => 'Writing Task scheduled.',
					'item_updated'             => 'Writing Task updated.',
					'item_link'                => 'Writing Task Link',
					'item_link_description'    => 'A link to a writing task.',
				),
				'public'           => true,
				'show_in_rest'     => true,
				'menu_position'    => 10,
				'menu_icon'        => 'dashicons-editor-justify',
				'capability_type'  => array(
					0 => 'quiz',
					1 => 'quizzes',
				),
				'map_meta_cap'     => true,
				'supports'         => array(
					0 => 'title',
					1 => 'author',
					2 => 'comments',
					3 => 'editor',
					4 => 'revisions',
					5 => 'thumbnail',
					6 => 'custom-fields',
				),
				'taxonomies'       => array(
					0 => 'writing-task-type',
				),
				'has_archive'      => 'writing-task-library',
				'rewrite'          => array(
					'feeds' => false,
				),
				'delete_with_user' => false,
			)
		);

		// Writing Test post type.
		register_post_type(
			'writing-test',
			array(
				'labels'           => array(
					'name'                     => 'Writing Tests',
					'singular_name'            => 'Writing Test',
					'menu_name'                => 'Writing Tests',
					'all_items'                => 'All Writing Tests',
					'edit_item'                => 'Edit Writing Test',
					'view_item'                => 'View Writing Test',
					'view_items'               => 'View Writing Tests',
					'add_new_item'             => 'Add New Writing Test',
					'add_new'                  => 'Add New Writing Test',
					'new_item'                 => 'New Writing Test',
					'parent_item_colon'        => 'Parent Writing Test:',
					'search_items'             => 'Search Writing Tests',
					'not_found'                => 'No writing tests found',
					'not_found_in_trash'       => 'No writing tests found in Trash',
					'archives'                 => 'Writing Test Archives',
					'attributes'               => 'Writing Test Attributes',
					'insert_into_item'         => 'Insert into writing test',
					'uploaded_to_this_item'    => 'Uploaded to this writing test',
					'filter_items_list'        => 'Filter writing tests list',
					'filter_by_date'           => 'Filter writing tests by date',
					'items_list_navigation'    => 'Writing Tests list navigation',
					'items_list'               => 'Writing Tests list',
					'item_published'           => 'Writing Test published.',
					'item_published_privately' => 'Writing Test published privately.',
					'item_reverted_to_draft'   => 'Writing Test reverted to draft.',
					'item_scheduled'           => 'Writing Test scheduled.',
					'item_updated'             => 'Writing Test updated.',
					'item_link'                => 'Writing Test Link',
					'item_link_description'    => 'A link to a writing test.',
				),
				'public'           => true,
				'show_in_rest'     => true,
				'menu_position'    => 10,
				'menu_icon'        => 'dashicons-format-aside',
				'capability_type'  => array(
					0 => 'quiz',
					1 => 'quizzes',
				),
				'map_meta_cap'     => true,
				'supports'         => array(
					0 => 'title',
					1 => 'author',
					2 => 'comments',
					3 => 'editor',
					4 => 'revisions',
					5 => 'thumbnail',
					6 => 'custom-fields',
				),
				'taxonomies'       => array(
					0 => 'writing-test-tag',
				),
				'has_archive'      => 'writing-test-library',
				'rewrite'          => array(
					'feeds' => false,
				),
				'delete_with_user' => false,
			)
		);

		register_post_type(
			'speaking-part',
			array(
				'labels'           => array(
					'name'                     => 'Speaking Parts',
					'singular_name'            => 'Speaking Part',
					'menu_name'                => 'Speaking Parts',
					'all_items'                => 'All Speaking Parts',
					'edit_item'                => 'Edit Speaking Part',
					'view_item'                => 'View Speaking Part',
					'view_items'               => 'View Speaking Parts',
					'add_new_item'             => 'Add New Speaking Part',
					'add_new'                  => 'Add New Speaking Part',
					'new_item'                 => 'New Speaking Part',
					'parent_item_colon'        => 'Parent Speaking Part:',
					'search_items'             => 'Search Speaking Parts',
					'not_found'                => 'No speaking parts found',
					'not_found_in_trash'       => 'No speaking parts found in Trash',
					'archives'                 => 'Speaking Part Archives',
					'attributes'               => 'Speaking Part Attributes',
					'insert_into_item'         => 'Insert into speaking part',
					'uploaded_to_this_item'    => 'Uploaded to this speaking part',
					'filter_items_list'        => 'Filter speaking parts list',
					'filter_by_date'           => 'Filter speaking parts by date',
					'items_list_navigation'    => 'Speaking Parts list navigation',
					'items_list'               => 'Speaking Parts list',
					'item_published'           => 'Speaking Part published.',
					'item_published_privately' => 'Speaking Part published privately.',
					'item_reverted_to_draft'   => 'Speaking Part reverted to draft.',
					'item_scheduled'           => 'Speaking Part scheduled.',
					'item_updated'             => 'Speaking Part updated.',
					'item_link'                => 'Speaking Part Link',
					'item_link_description'    => 'A link to a speaking part.',
				),
				'public'           => true,
				'show_in_rest'     => true,
				'menu_position'    => 10,
				'menu_icon'        => 'dashicons-microphone',
				'capability_type'  => array(
					0 => 'quiz',
					1 => 'quizzes',
				),
				'map_meta_cap'     => true,
				'supports'         => array(
					0 => 'title',
					1 => 'author',
					2 => 'comments',
					3 => 'editor',
					4 => 'revisions',
					5 => 'thumbnail',
					6 => 'custom-fields',
				),
				'has_archive'      => 'speaking-part-library',
				'rewrite'          => array(
					'feeds' => false,
				),
				'delete_with_user' => false,
			)
		);

		register_post_type(
			'speaking-question',
			array(
				'labels'           => array(
					'name'                     => 'Speaking Questions',
					'singular_name'            => 'Speaking Question',
					'menu_name'                => 'Speaking Questions',
					'all_items'                => 'All Speaking Questions',
					'edit_item'                => 'Edit Speaking Question',
					'view_item'                => 'View Speaking Question',
					'view_items'               => 'View Speaking Questions',
					'add_new_item'             => 'Add New Speaking Question',
					'add_new'                  => 'Add New Speaking Question',
					'new_item'                 => 'New Speaking Question',
					'parent_item_colon'        => 'Parent Speaking Question:',
					'search_items'             => 'Search Speaking Questions',
					'not_found'                => 'No speaking questions found',
					'not_found_in_trash'       => 'No speaking questions found in Trash',
					'archives'                 => 'Speaking Question Archives',
					'attributes'               => 'Speaking Question Attributes',
					'insert_into_item'         => 'Insert into speaking question',
					'uploaded_to_this_item'    => 'Uploaded to this speaking question',
					'filter_items_list'        => 'Filter speaking questions list',
					'filter_by_date'           => 'Filter speaking questions by date',
					'items_list_navigation'    => 'Speaking Questions list navigation',
					'items_list'               => 'Speaking Questions list',
					'item_published'           => 'Speaking Question published.',
					'item_published_privately' => 'Speaking Question published privately.',
					'item_reverted_to_draft'   => 'Speaking Question reverted to draft.',
					'item_scheduled'           => 'Speaking Question scheduled.',
					'item_updated'             => 'Speaking Question updated.',
					'item_link'                => 'Speaking Question Link',
					'item_link_description'    => 'A link to a speaking question.',
				),
				'public'           => true,
				'show_in_rest'     => true,
				'menu_position'    => 10,
				'menu_icon'        => 'dashicons-format-status',
				'capability_type'  => array(
					0 => 'quiz',
					1 => 'quizzes',
				),
				'map_meta_cap'     => true,
				'supports'         => array(
					0 => 'title',
					1 => 'author',
					2 => 'editor',
				),
				'delete_with_user' => false,
			)
		);

		register_post_type(
			'speaking-test',
			array(
				'labels'           => array(
					'name'                     => 'Speaking Tests',
					'singular_name'            => 'Speaking Test',
					'menu_name'                => 'Speaking Tests',
					'all_items'                => 'All Speaking Tests',
					'edit_item'                => 'Edit Speaking Test',
					'view_item'                => 'View Speaking Test',
					'view_items'               => 'View Speaking Tests',
					'add_new_item'             => 'Add New Speaking Test',
					'add_new'                  => 'Add New Speaking Test',
					'new_item'                 => 'New Speaking Test',
					'parent_item_colon'        => 'Parent Speaking Test:',
					'search_items'             => 'Search Speaking Tests',
					'not_found'                => 'No speaking tests found',
					'not_found_in_trash'       => 'No speaking tests found in Trash',
					'archives'                 => 'Speaking Test Archives',
					'attributes'               => 'Speaking Test Attributes',
					'insert_into_item'         => 'Insert into speaking test',
					'uploaded_to_this_item'    => 'Uploaded to this speaking test',
					'filter_items_list'        => 'Filter speaking tests list',
					'filter_by_date'           => 'Filter speaking tests by date',
					'items_list_navigation'    => 'Speaking Tests list navigation',
					'items_list'               => 'Speaking Tests list',
					'item_published'           => 'Speaking Test published.',
					'item_published_privately' => 'Speaking Test published privately.',
					'item_reverted_to_draft'   => 'Speaking Test reverted to draft.',
					'item_scheduled'           => 'Speaking Test scheduled.',
					'item_updated'             => 'Speaking Test updated.',
					'item_link'                => 'Speaking Test Link',
					'item_link_description'    => 'A link to a speaking test.',
				),
				'public'           => true,
				'show_in_rest'     => true,
				'menu_position'    => 10,
				'menu_icon'        => 'dashicons-format-chat',
				'capability_type'  => array(
					0 => 'quiz',
					1 => 'quizzes',
				),
				'map_meta_cap'     => true,
				'supports'         => array(
					0 => 'title',
					1 => 'author',
					2 => 'comments',
					3 => 'editor',
					4 => 'revisions',
					5 => 'thumbnail',
					6 => 'custom-fields',
				),
				'has_archive'      => 'speaking-test-library',
				'rewrite'          => array(
					'feeds' => false,
				),
				'delete_with_user' => false,
			)
		);
	}
}
