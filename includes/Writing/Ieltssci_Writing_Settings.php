<?php
/**
 * Writing Settings Class
 *
 * Handles the configuration of writing-related settings for the IELTS Science LMS plugin.
 *
 * @package IeltsScienceLMS\Writing
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Writing;

use IeltsScienceLMS\ApiFeeds\Ieltssci_ApiFeeds_DB;

/**
 * Class Ieltssci_Writing_Settings
 *
 * Manages writing-related settings for the IELTS Science LMS.
 *
 * @package IeltsScienceLMS\Writing
 */
class Ieltssci_Writing_Settings {
	/**
	 * Database instance for API feeds.
	 *
	 * @var Ieltssci_ApiFeeds_DB
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * Sets up filters and initializes database connection.
	 */
	public function __construct() {
		add_filter( 'ieltssci_settings_config', array( $this, 'register_settings_config' ) );
		add_filter( 'ieltssci_sample_results_data', array( $this, 'register_sample_results_data' ) );
		$this->db = new Ieltssci_ApiFeeds_DB();
	}

	/**
	 * Register writing-related settings configuration.
	 *
	 * @param array $settings Existing settings configuration.
	 * @return array Modified settings configuration.
	 */
	public function register_settings_config( $settings ) {

		$writing_settings = array(
			'writing-apis'               => array(
				'tab_label' => __( 'Writing APIs', 'ielts-science-lms' ),
				'tab_type'  => 'api-feeds',
				'settings'  => $this->writing_apis_settings(),
			),
			'writing-apis-process-order' => array(
				'tab_label' => __( 'Writing APIs Process Order', 'ielts-science-lms' ),
				'tab_type'  => 'api-feeds-process-order',
				'settings'  => $this->essay_types(),
			),
		);

		return array_merge( $settings, $writing_settings );
	}

	/**
	 * Get essay types with process order configuration.
	 *
	 * @return array Essay types configuration.
	 */
	public function essay_types() {
		// Get all feeds with their essay types and process orders using get_api_feeds.
		$feeds = $this->db->get_api_feeds(
			array(
				'limit'   => 500, // High limit to get all feeds.
				'include' => array( 'essay_types' ), // Include essay types data.
			)
		);

		// Group feeds by essay type.
		$grouped_feeds = array();
		foreach ( $feeds as $feed ) {
			if ( empty( $feed['essay_types'] ) ) {
				continue;
			}

			foreach ( $feed['essay_types'] as $essay_type_data ) {
				$essay_type = $essay_type_data['essay_type'];

				if ( ! isset( $grouped_feeds[ $essay_type ] ) ) {
					$grouped_feeds[ $essay_type ] = array();
				}

				$grouped_feeds[ $essay_type ][] = array(
					'id'           => (int) $feed['id'],
					'feedName'     => $feed['feedback_criteria'],
					'feedTitle'    => $feed['feed_title'],
					'feedDesc'     => $feed['feed_desc'],
					'applyTo'      => $feed['apply_to'],
					'processOrder' => (int) $essay_type_data['process_order'],
					'dependencies' => $essay_type_data['dependencies'] ?? array(),
				);
			}
		}

		// Map to the required format.
		return array(
			array(
				'groupName'  => 'task-1',
				'groupTitle' => 'Task 1',
				'feeds'      => $grouped_feeds['task-1'] ?? array(),
			),
			array(
				'groupName'  => 'task-2',
				'groupTitle' => 'Task 2',
				'feeds'      => $grouped_feeds['task-2'] ?? array(),
			),
			array(
				'groupName'  => 'task-1-ocr',
				'groupTitle' => 'Task 1 OCR',
				'feeds'      => $grouped_feeds['task-1-ocr'] ?? array(),
			),
			array(
				'groupName'  => 'task-2-ocr',
				'groupTitle' => 'Task 2 OCR',
				'feeds'      => $grouped_feeds['task-2-ocr'] ?? array(),
			),
		);
	}

	/**
	 * Configure writing API settings.
	 *
	 * @return array Writing API settings configuration.
	 */
	protected function writing_apis_settings() {

		$settings_config_instance = new \IeltsScienceLMS\Settings\Ieltssci_Settings_Config();

		// Define writing specific merge tags.
		$writing_merge_tags = array(
			array(
				'groupLabel' => 'Essay level',
				'items'      => array(
					array(
						'label' => 'Essay Content',
						'info'  => '{|essay:essay_content|}',
						'value' => '{|essay:essay_content|}',
					),
					array(
						'label' => 'Question',
						'info'  => '{|essay:question|}',
						'value' => '{|essay:question|}',
					),
				),
			),
			array(
				'groupLabel' => 'Custom Instructions',
				'items'      => array(
					array(
						'label' => 'Feedback Style',
						'info'  => '{|feedback_style|}',
						'value' => '{|feedback_style|}',
					),
					array(
						'label' => 'Guide Score',
						'info'  => '{|guide_score|}',
						'value' => '{|guide_score|}',
					),
					array(
						'label' => 'Guide Feedback',
						'info'  => '{|guide_feedback|}',
						'value' => '{|guide_feedback|}',
					),
				),
			),
			array(
				'groupLabel' => 'Segment level (Hard_coded)',
				'items'      => array(
					array(
						'label' => 'Segment Title',
						'info'  => '{|segment_title|}',
						'value' => '{|segment_title|}',
					),
					array(
						'label' => 'Segment Content',
						'info'  => '{|segment_content|}',
						'value' => '{|segment_content|}',
					),
					array(
						'label' => 'Segment Type',
						'info'  => '{|segment_type|}',
						'value' => '{|segment_type|}',
					),
					array(
						'label' => 'Segment Order',
						'info'  => '{|segment_order|}',
						'value' => '{|segment_order|}',
					),
					array(
						'label' => 'Segment ID',
						'info'  => '{|segment_id|}',
						'value' => '{|segment_id|}',
					),
				),
			),
			array(
				'groupLabel' => 'Segment level (Dynamic)',
				'items'      => array(
					array(
						'label' => 'Introduction Content',
						'info'  => '{|segment:content[type:introduction]|}',
						'value' => '{|segment:content[type:introduction]|}',
					),
				),
			),
		);

		// Apply filter to allow other plugins to add more writing merge tags.
		$writing_merge_tags = apply_filters( 'ieltssci_writing_merge_tags', $writing_merge_tags );

		$default_model_options = $settings_config_instance->get_model_options(
			array(
				'open-key-ai'     => array(
					array(
						'label' => 'gpt-4o-mini',
						'value' => 'gpt-4o-mini',
					),
					array(
						'label' => 'gpt-4o',
						'value' => 'gpt-4o',
					),
					array(
						'label' => 'gemini-2.0-flash',
						'value' => 'gemini-2.0-flash',
					),
					array(
						'label' => 'gemini-2.0-flash-lite',
						'value' => 'gemini-2.0-flash-lite',
					),
				),
				'two-key-ai'      => array(
					array(
						'label' => 'gpt-4o-mini',
						'value' => 'gpt-4o-mini',
					),
					array(
						'label' => 'gpt-4o',
						'value' => 'gpt-4o',
					),
					array(
						'label' => 'gemini-2.0-flash',
						'value' => 'gemini-2.0-flash',
					),
					array(
						'label' => 'gemini-2.0-flash-lite',
						'value' => 'gemini-2.0-flash-lite',
					),
				),
				'gpt2-shupremium' => array(
					array(
						'label' => 'gpt-4o-mini',
						'value' => 'gpt-4o-mini',
					),
					array(
						'label' => 'gpt-4o',
						'value' => 'gpt-4o',
					),
					array(
						'label' => 'gemini-2.0-flash',
						'value' => 'gemini-2.0-flash',
					),
					array(
						'label' => 'gemini-2.0-flash-lite',
						'value' => 'gemini-2.0-flash-lite',
					),
				),
				'open-ai'         => array(
					array(
						'label' => 'gpt-4o-mini',
						'value' => 'gpt-4o-mini',
					),
					array(
						'label' => 'gpt-4o',
						'value' => 'gpt-4o',
					),
				),
				'google'          => array(
					array(
						'label' => 'gemini-1.5-flash',
						'value' => 'gemini-1.5-flash',
					),
					array(
						'label' => 'gemini-1.5-pro',
						'value' => 'gemini-1.5-pro',
					),
					array(
						'label' => 'gemini-2.0-flash',
						'value' => 'gemini-2.0-flash',
					),
					array(
						'label' => 'gemini-2.0-flash-lite',
						'value' => 'gemini-2.0-flash-lite',
					),
				),
				'azure'           => array(
					array(
						'label' => 'gpt-4o-mini',
						'value' => 'gpt-4o-mini',
					),
					array(
						'label' => 'gpt-4o',
						'value' => 'gpt-4o',
					),
				),
				'home-server'     => array(),
				'vllm'            => array(),
				'vllm2'           => array(),
				'slm'             => array(),
			)
		);

		$common_general_fields = array(
			$settings_config_instance->create_api_provider_field(),
			$settings_config_instance->create_model_picker_field( 'apiProvider', $default_model_options ),
			$settings_config_instance->create_field( 'enable_thinking', 'toggle', 'Enable Thinking', 'Enable the thinking process for this model. Only apply to vllm & slm endpoints & reasoning models', false ),
			$settings_config_instance->create_prompt_field( 'englishPrompt', 'English Prompt', 'Message sent to the model {|parameter_name|}', $writing_merge_tags ),
			$settings_config_instance->create_prompt_field( 'vietnamesePrompt', 'Vietnamese Prompt', 'Message sent to the model {|parameter_name|}', $writing_merge_tags ),
		);

		$common_advanced_fields = array(
			$settings_config_instance->create_field( 'maxToken', 'number', 'Max Token', 'The maximum number of tokens to generate.', 2048 ),
			$settings_config_instance->create_field( 'temperature', 'number', 'Temperature', 'The value used to module the next token probabilities.', 0.1 ),
			$settings_config_instance->create_field( 'guided_choice', 'text', 'Guided Choice', 'The output will be exactly one of the choices. Choices separate by `|` character', null ),
			$settings_config_instance->create_field( 'guided_regex', 'text', 'Guided Regex', 'The output will follow the regex pattern.', null ),
			$settings_config_instance->create_field( 'guided_json', 'textarea', 'Guided JSON', 'The output will follow the JSON schema. A valid schema must be provided.', null ),
			$settings_config_instance->create_field( 'guided_json_vi', 'textarea', 'Guided JSON (Vietnamese)', 'The output will follow the JSON schema for Vietnamese language. A valid schema must be provided.', null ),
		);

		$common_sections = array(
			$settings_config_instance->create_section( 'general-setting', $common_general_fields ),
			$settings_config_instance->create_section( 'advanced-setting', $common_advanced_fields ),
		);

		$scoring_sections = array(
			$settings_config_instance->create_section( 'general-setting', $common_general_fields ),
			$settings_config_instance->create_section(
				'advanced-setting',
				array(
					$settings_config_instance->create_field( 'maxToken', 'number', 'Max Token', 'The maximum number of tokens to generate.', 2048 ),
					$settings_config_instance->create_field( 'temperature', 'number', 'Temperature', 'The value used to module the next token probabilities.', 0.1 ),
					$settings_config_instance->create_field( 'scoreRegex', 'text', 'Score Regex', 'Regular expression to extract score from the model output.', '/\d+/' ),
					$settings_config_instance->create_field( 'guided_choice', 'text', 'Guided Choice', 'The output will be exactly one of the choices. Choices separate by `|` character', null ),
					$settings_config_instance->create_field( 'guided_regex', 'text', 'Guided Regex', 'The output will follow the regex pattern.', null ),
					$settings_config_instance->create_field( 'guided_json', 'textarea', 'Guided JSON', 'The output will follow the JSON schema. A valid schema must be provided.', null ),
					$settings_config_instance->create_field( 'guided_json_vi', 'textarea', 'Guided JSON (Vietnamese)', 'The output will follow the JSON schema for Vietnamese language. A valid schema must be provided.', null ),
				)
			),
		);

		$writing_apis_settings = array(
			array(
				'groupName'  => 'ocr',
				'groupTitle' => 'OCR',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'ocr-essay',
						'OCR Essay',
						'essay',
						array( 'task-1-ocr', 'task-2-ocr' ),
						array(
							$settings_config_instance->create_step(
								'feedback',
								array(
									$settings_config_instance->create_section(
										'general-setting',
										array(
											$settings_config_instance->create_api_provider_field(),
											$settings_config_instance->create_model_picker_field( 'apiProvider', $default_model_options ),
											// Toggle to enable/disable multi-modal field.
											$settings_config_instance->create_field( 'enableMultiModal', 'toggle', 'Enable Multi Modal Input', 'Enable Multi Modal input or not', false ),
											$settings_config_instance->create_field(
												'multiModalField',
												'form-token',
												'Multi Modal Field(s)',
												'',
												null,
												array(),
												'enableMultiModal',
												array(
													'suggestions' => array( 'ocr_image_ids', 'chart_image_ids' ),
												)
											),
											$settings_config_instance->create_prompt_field( 'englishPrompt', 'English Prompt', 'Message sent to the model {|parameter_name|}', $writing_merge_tags ),
											$settings_config_instance->create_prompt_field( 'vietnamesePrompt', 'Vietnamese Prompt', 'Message sent to the model {|parameter_name|}', $writing_merge_tags ),
										)
									),
									$settings_config_instance->create_section( 'advanced-setting', $common_advanced_fields ),
								)
							),
						)
					),
				),
			),
			array(
				'groupName'  => 'vocabulary-suggestions',
				'groupTitle' => 'Vocabulary Suggestions',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'vocabulary-suggestions',
						'Vocabulary Suggestions',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
				),
			),
			array(
				'groupName'  => 'vocabulary-range',
				'groupTitle' => 'Vocabulary Range',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'vocabulary-range',
						'Vocabulary Range',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
				),
			),
			array(
				'groupName'  => 'grammar-suggestions',
				'groupTitle' => 'Grammar Suggestions',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'grammar-suggestions',
						'Grammar Suggestions',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
				),
			),
			array(
				'groupName'  => 'grammar-range',
				'groupTitle' => 'Grammar Range',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'grammar-range',
						'Grammar Range',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
				),
			),
			array(
				'groupName'  => 'argument-enhance',
				'groupTitle' => 'Argument Enhance',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'segmenting',
						'Segmenting',
						'paragraph',
						array( 'task-2', 'task-2-ocr' ),
						array(
							$settings_config_instance->create_step(
								'output',
								array(
									$settings_config_instance->create_section(
										'general-setting',
										array(
											$settings_config_instance->create_api_provider_field( 'home-server' ),
											$settings_config_instance->create_model_picker_field( 'apiProvider', $default_model_options, 'bihungba1101/segmenting-paragraph' ),
											$settings_config_instance->create_prompt_field( 'englishPrompt', 'English Prompt', '{|each_paragraph_in_essay|}', $writing_merge_tags ),
											$settings_config_instance->create_prompt_field( 'vietnamesePrompt', 'Vietnamese Prompt', '{|each_paragraph_in_essay|}', $writing_merge_tags ),
										)
									),
									$settings_config_instance->create_section( 'advanced-setting', $common_advanced_fields ),
								)
							),
						)
					),
					$settings_config_instance->create_feed(
						'introduction-relevance',
						'Introduction Relevance',
						'introduction',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'introduction-clear-answer',
						'Introduction Clear Answer/Clear Opinion',
						'introduction',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'introduction-brief-overview',
						'Introduction Brief Overview',
						'introduction',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'introduction-rewrite',
						'Introduction Rewrite',
						'introduction',
						array( 'task-2', 'task-2-ocr' ),
						array(
							$settings_config_instance->create_step( 'feedback', array( $settings_config_instance->create_section( 'general-setting', $common_general_fields ), $settings_config_instance->create_section( 'advanced-setting', $common_advanced_fields ) ) ),
						)
					),
					$settings_config_instance->create_feed(
						'topic-sentence-linking',
						'Topic Sentence Linking',
						'topic-sentence',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'topic-sentence-relevance',
						'Topic Sentence Relevance',
						'topic-sentence',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'topic-sentence-rewrite',
						'Topic Sentence Rewrite',
						'topic-sentence',
						array( 'task-2', 'task-2-ocr' ),
						array(
							$settings_config_instance->create_step( 'feedback', array( $settings_config_instance->create_section( 'general-setting', $common_general_fields ), $settings_config_instance->create_section( 'advanced-setting', $common_advanced_fields ) ) ),
						)
					),
					$settings_config_instance->create_feed(
						'main-point-logic-depth',
						'Main Point Logic & Depth',
						'main-point',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'main-point-overgeneralize',
						'Main Point Overgeneralize',
						'main-point',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'main-point-relevance',
						'Main Point Relevance',
						'main-point',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'main-point-rewrite',
						'Main Point Rewrite',
						'main-point',
						array( 'task-2', 'task-2-ocr' ),
						array(
							$settings_config_instance->create_step( 'feedback', array( $settings_config_instance->create_section( 'general-setting', $common_general_fields ), $settings_config_instance->create_section( 'advanced-setting', $common_advanced_fields ) ) ),
						)
					),
					$settings_config_instance->create_feed(
						'conclusion-relevance',
						'Conclusion Relevance',
						'conclusion',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'conclusion-clear-answer',
						'Conclusion Clear Answer/Clear Opinion',
						'conclusion',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'conclusion-rewrite',
						'Conclusion Rewrite',
						'conclusion',
						array( 'task-2', 'task-2-ocr' ),
						array(
							$settings_config_instance->create_step( 'feedback', array( $settings_config_instance->create_section( 'general-setting', $common_general_fields ), $settings_config_instance->create_section( 'advanced-setting', $common_advanced_fields ) ) ),
						)
					),
				),
			),
			array(
				'groupName'  => 'lexical-resource',
				'groupTitle' => 'Lexical Resource',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'range-of-vocab',
						'Range of Vocab',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'word-choice-collocation-style',
						'Word choice, Collocation, Style',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'uncommon-vocab',
						'Uncommon vocab',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'spelling-word-form-error',
						'Spelling, Word Form Error',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'new-vocab',
						'New Vocabulary',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							$settings_config_instance->create_step(
								'feedback',
								array(
									$settings_config_instance->create_section( 'general-setting', $common_general_fields ),
									$settings_config_instance->create_section( 'advanced-setting', $common_advanced_fields ),
								)
							),
						)
					),
				),
			),
			array(
				'groupName'  => 'grammatical-range-accuracy',
				'groupTitle' => 'Grammatical Range & Accuracy',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'range-of-structures',
						'Range of Structures',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'grammar-accuracy',
						'Grammar Accuracy',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
				),
			),
			array(
				'groupName'  => 'coherence-cohesion',
				'groupTitle' => 'Coherence & Cohesion',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'flow',
						'Flow',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'paragraphing',
						'Paragraphing Task 2',
						'essay',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'paragraphing-task-1',
						'Paragraphing Task 1',
						'essay',
						array( 'task-1', 'task-1-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'referencing',
						'Referencing',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'use-of-cohesive-devices',
						'Use of Cohesive Devices',
						'essay',
						array( 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
				),
			),
			array(
				'groupName'  => 'task-response',
				'groupTitle' => 'Task Response',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'relevance',
						'Relevance',
						'essay',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'clear-opinion',
						'Clear Opinion',
						'essay',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'idea-development',
						'Idea Development',
						'essay',
						array( 'task-2', 'task-2-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
				),
			),
			array(
				'groupName'  => 'task-achievement',
				'groupTitle' => 'Task Achievement',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'use-data-accurately',
						'Use data accurately',
						'essay',
						array( 'task-1', 'task-1-ocr' ),
						array(
							$settings_config_instance->create_step(
								'chain-of-thought',
								array(
									$settings_config_instance->create_section(
										'general-setting',
										array(
											$settings_config_instance->create_api_provider_field(),
											$settings_config_instance->create_model_picker_field( 'apiProvider', $default_model_options ),
											// Toggle to enable/disable multi-modal field.
											$settings_config_instance->create_field( 'enableMultiModal', 'toggle', 'Enable Multi Modal Input', 'Enable Multi Modal input or not', false ),
											$settings_config_instance->create_field(
												'multiModalField',
												'form-token',
												'Multi Modal Field(s)',
												'',
												null,
												array(),
												'enableMultiModal',
												array(
													'suggestions' => array( 'ocr_image_ids', 'chart_image_ids' ),
												)
											),
											$settings_config_instance->create_prompt_field( 'englishPrompt', 'English Prompt', 'Message sent to the model {|parameter_name|}', $writing_merge_tags ),
											$settings_config_instance->create_prompt_field( 'vietnamesePrompt', 'Vietnamese Prompt', 'Message sent to the model {|parameter_name|}', $writing_merge_tags ),
										)
									),
									$settings_config_instance->create_section( 'advanced-setting', $common_advanced_fields ),
								)
							),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'present-key-features',
						'Present key features',
						'essay',
						array( 'task-1', 'task-1-ocr' ),
						array(
							$settings_config_instance->create_step(
								'chain-of-thought',
								array(
									$settings_config_instance->create_section(
										'general-setting',
										array(
											$settings_config_instance->create_api_provider_field(),
											$settings_config_instance->create_model_picker_field( 'apiProvider', $default_model_options ),
											// Toggle to enable/disable multi-modal field.
											$settings_config_instance->create_field( 'enableMultiModal', 'toggle', 'Enable Multi Modal Input', 'Enable Multi Modal input or not', false ),
											$settings_config_instance->create_field(
												'multiModalField',
												'form-token',
												'Multi Modal Field(s)',
												'',
												null,
												array(),
												'enableMultiModal',
												array(
													'suggestions' => array( 'ocr_image_ids', 'chart_image_ids' ),
												)
											),
											$settings_config_instance->create_prompt_field( 'englishPrompt', 'English Prompt', 'Message sent to the model {|parameter_name|}', $writing_merge_tags ),
											$settings_config_instance->create_prompt_field( 'vietnamesePrompt', 'Vietnamese Prompt', 'Message sent to the model {|parameter_name|}', $writing_merge_tags ),
										)
									),
									$settings_config_instance->create_section( 'advanced-setting', $common_advanced_fields ),
								)
							),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'use-data',
						'Use data',
						'essay',
						array( 'task-1', 'task-1-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'present-an-overview',
						'Present an overview',
						'essay',
						array( 'task-1', 'task-1-ocr' ),
						array(
							$settings_config_instance->create_step(
								'chain-of-thought',
								array(
									$settings_config_instance->create_section(
										'general-setting',
										array(
											$settings_config_instance->create_api_provider_field(),
											$settings_config_instance->create_model_picker_field( 'apiProvider', $default_model_options ),
											// Toggle to enable/disable multi-modal field.
											$settings_config_instance->create_field( 'enableMultiModal', 'toggle', 'Enable Multi Modal Input', 'Enable Multi Modal input or not', false ),
											$settings_config_instance->create_field(
												'multiModalField',
												'form-token',
												'Multi Modal Field(s)',
												'',
												null,
												array(),
												'enableMultiModal',
												array(
													'suggestions' => array( 'ocr_image_ids', 'chart_image_ids' ),
												)
											),
											$settings_config_instance->create_prompt_field( 'englishPrompt', 'English Prompt', 'Message sent to the model {|parameter_name|}', $writing_merge_tags ),
											$settings_config_instance->create_prompt_field( 'vietnamesePrompt', 'Vietnamese Prompt', 'Message sent to the model {|parameter_name|}', $writing_merge_tags ),
										)
									),
									$settings_config_instance->create_section( 'advanced-setting', $common_advanced_fields ),
								)
							),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'format',
						'Format',
						'essay',
						array( 'task-1', 'task-1-ocr' ),
						array(
							// $settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
				),
			),
			array(
				'groupName'  => 'improve-essay',
				'groupTitle' => 'Improve Essay',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'improve-essay-task-2',
						'Improve Essay Task 2',
						'essay',
						array( 'task-2', 'task-2-ocr' ),
						array(
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'improve-essay-task-1',
						'Improve Essay Task 1',
						'essay',
						array( 'task-1', 'task-1-ocr' ),
						array(
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
				),
			),

		);

		return $writing_apis_settings;
	}

	/**
	 * Register writing module sample results data.
	 *
	 * @param array $sample_results_data Existing sample results data.
	 * @return array Updated sample results data with writing module information.
	 */
	public function register_sample_results_data( $sample_results_data ) {
		$sample_results_data['writing_module'] = array(
			'module_name'   => 'writing_module',
			'section_title' => __( 'Writing Module Sample Results', 'ielts-science-lms' ),
			'section_desc'  => __( 'Configure sample result links for the Writing Module.', 'ielts-science-lms' ),
			'samples'       => array(
				'writing_task1_sample'   => array(
					'label'       => __( 'Writing Task 1 Sample Result', 'ielts-science-lms' ),
					'description' => __( 'URL to a sample Task 1 result page. Will be available in front-end apps.', 'ielts-science-lms' ),
				),
				'writing_task2_sample'   => array(
					'label'       => __( 'Writing Task 2 Sample Result', 'ielts-science-lms' ),
					'description' => __( 'URL to a sample Task 2 result page. Will be available in front-end apps.', 'ielts-science-lms' ),
				),
				'writing_general_sample' => array(
					'label'       => __( 'Writing General Essay Sample Result', 'ielts-science-lms' ),
					'description' => __( 'URL to a sample General Essay result page. Will be available in front-end apps.', 'ielts-science-lms' ),
				),
			),
		);

		return $sample_results_data;
	}
}
