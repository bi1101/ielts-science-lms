<?php
/**
 * Speaking Settings Class
 *
 * Handles the configuration of speaking-related settings for the IELTS Science LMS plugin.
 *
 * @package IeltsScienceLMS\Speaking
 * @since 1.0.0
 */

namespace IeltsScienceLMS\Speaking;

use IeltsScienceLMS\ApiFeeds\Ieltssci_ApiFeeds_DB;

/**
 * Class Ieltssci_Speaking_Settings
 *
 * Manages speaking-related settings for the IELTS Science LMS.
 *
 * @package IeltsScienceLMS\Speaking
 */
class Ieltssci_Speaking_Settings {
	/**
	 * Database instance for API feeds.
	 *
	 * @var Ieltssci_ApiFeeds_DB
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * Sets up filters for speaking settings.
	 */
	public function __construct() {
		add_filter( 'ieltssci_settings_config', array( $this, 'register_settings_config' ) );
		add_filter( 'ieltssci_sample_results_data', array( $this, 'register_sample_results_data' ) );
		$this->db = new Ieltssci_ApiFeeds_DB();
	}

	/**
	 * Register speaking-related settings configuration.
	 *
	 * @param array $settings Existing settings configuration.
	 * @return array Modified settings configuration.
	 */
	public function register_settings_config( $settings ) {
		$speaking_settings = array(
			'speaking-apis'               => array(
				'tab_label' => __( 'Speaking APIs', 'ielts-science-lms' ),
				'tab_type'  => 'api-feeds',
				'settings'  => $this->speaking_apis_settings(),
			),
			'speaking-apis-process-order' => array(
				'tab_label' => __( 'Speaking APIs Process Order', 'ielts-science-lms' ),
				'tab_type'  => 'api-feeds-process-order',
				'settings'  => $this->speaking_types(),
			),
		);

		return array_merge( $settings, $speaking_settings );
	}

	/**
	 * Get speaking types with process order configuration.
	 *
	 * @return array Speaking types configuration.
	 */
	public function speaking_types() {
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
					'dependencies' => isset( $essay_type_data['dependencies'] ) ? $essay_type_data['dependencies'] : array(), // Array of feed IDs that this feed depends on.
				);
			}
		}
		return array(
			array(
				'groupName'  => 'speaking',
				'groupTitle' => 'Speaking',
				'feeds'      => $grouped_feeds['speaking'] ?? array(),
			),
		);
	}

	/**
	 * Configure speaking API settings.
	 *
	 * @return array Speaking API settings configuration.
	 */
	protected function speaking_apis_settings() {
		// Get the settings config instance to use helper methods.
		$settings_config_instance = new \IeltsScienceLMS\Settings\Ieltssci_Settings_Config();

		// Define speaking specific merge tags.
		$speaking_merge_tags = array(
			array(
				'groupLabel' => 'Speech Data',
				'items'      => array(
					array(
						'label' => 'Speech Transcript Text',
						'info'  => 'Combined text from all audio transcriptions',
						'value' => '{|speech:transcript_text|}',
					),
				),
			),
			array(
				'groupLabel' => 'Attempt Data',
				'items'      => array(
					array(
						'label' => 'Attempt Title',
						'info'  => 'Title of the current attempt',
						'value' => '{|attempt_title|}',
					),
					array(
						'label' => 'Attempt Transcript',
						'info'  => 'Transcript text of the current attempt',
						'value' => '{|attempt_transcript|}',
					),
				),
			),
			array(
				'groupLabel' => 'Feedback Data',
				'items'      => array(
					array(
						'label' => 'Chain of Thought',
						'info'  => 'Chain of thought content',
						'value' => '{|speech_feedback:cot_content[feedback_criteria:FILL_IN]|}',
					),
					array(
						'label' => 'Score Content',
						'info'  => 'Scoring content',
						'value' => '{|speech_feedback:score_content[feedback_criteria:FILL_IN]|}',
					),
					array(
						'label' => 'Feedback Content',
						'info'  => 'Feedback content',
						'value' => '{|speech_feedback:feedback_content[feedback_criteria:FILL_IN]|}',
					),
				),
			),
			array(
				'groupLabel' => 'Guidance',
				'items'      => array(
					array(
						'label' => 'Feedback Style',
						'info'  => 'User provided feedback style',
						'value' => '{|feedback_style|}',
					),
					array(
						'label' => 'Guide Score',
						'info'  => 'Human-guided score',
						'value' => '{|guide_score|}',
					),
					array(
						'label' => 'Guide Feedback',
						'info'  => 'Human-guided feedback content',
						'value' => '{|guide_feedback|}',
					),
				),
			),
		);

		// Apply filter to allow other plugins to add more speaking merge tags.
		$speaking_merge_tags = apply_filters( 'ieltssci_speaking_merge_tags', $speaking_merge_tags );

		// Model options for different API providers.
		$default_model_options = $settings_config_instance->get_model_options(
			array(
				'open-key-ai' => array(
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
				'open-ai'     => array(
					array(
						'label' => 'gpt-4o-mini',
						'value' => 'gpt-4o-mini',
					),
					array(
						'label' => 'gpt-4o',
						'value' => 'gpt-4o',
					),
				),
				'two-key-ai'  => array(
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
				'google'      => array(
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
				'azure'       => array(
					array(
						'label' => 'gpt-4o-mini',
						'value' => 'gpt-4o-mini',
					),
					array(
						'label' => 'gpt-4o',
						'value' => 'gpt-4o',
					),
				),
				'home-server' => array(),
				'vllm'        => array(),
				'slm'         => array(),
				'lite-llm'    => array(
					array(
						'label' => 'gpt-4o-mini',
						'value' => 'gpt-4o-mini',
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
			)
		);

		$stt_model_options = $settings_config_instance->get_model_options(
			array(
				'open-ai'                         => array(
					array(
						'label' => 'whisper-1',
						'value' => 'whisper-1',
					),
					array(
						'label' => 'gpt-4o-mini-transcribe',
						'value' => 'gpt-4o-mini-transcribe',
					),
					array(
						'label' => 'gpt-4o-transcribe',
						'value' => 'gpt-4o-transcribe',
					),
				),
				'home-server-whisperx-api-server' => array(
					array(
						'label' => 'Systran/faster-whisper-large-v3',
						'value' => 'Systran/faster-whisper-large-v3',
					),
					array(
						'label' => 'Systran/faster-whisper-medium.en',
						'value' => 'Systran/faster-whisper-medium.en',
					),
					array(
						'label' => 'nyrahealth/faster_CrisperWhisper',
						'value' => 'nyrahealth/faster_CrisperWhisper',
					),
				),
			)
		);

		// Common fields for general settings.
		$common_general_fields = array(
			$settings_config_instance->create_api_provider_field(),
			$settings_config_instance->create_model_picker_field( 'apiProvider', $default_model_options ),
			$settings_config_instance->create_field( 'enable_thinking', 'toggle', 'Enable Thinking', 'Enable the thinking process for this model. Only apply to vllm & slm endpoints & reasoning models', false ),
			$settings_config_instance->create_prompt_field( 'englishPrompt', 'English Prompt', 'Message sent to the model {|parameter_name|}', $speaking_merge_tags ),
			$settings_config_instance->create_prompt_field( 'vietnamesePrompt', 'Vietnamese Prompt', 'Message sent to the model {|parameter_name|}', $speaking_merge_tags ),
		);

		// Common fields for advanced settings.
		$common_advanced_fields = array(
			$settings_config_instance->create_field( 'maxToken', 'number', 'Max Token', 'The maximum number of tokens to generate.', 2048 ),
			$settings_config_instance->create_field( 'temperature', 'number', 'Temperature', 'The value used to module the next token probabilities.', 0.1 ),
			$settings_config_instance->create_field( 'top_p', 'number', 'Top P', 'Float that controls the cumulative probability of the top tokens to consider. Must be in (0, 1]. Set to 1 to consider all tokens.', 0.8 ),
			$settings_config_instance->create_field( 'top_k', 'number', 'Top K', 'Integer that controls the number of top tokens to consider. Set to -1 to consider all tokens.', 20 ),
			$settings_config_instance->create_field( 'guided_choice', 'text', 'Guided Choice', 'The output will be exactly one of the choices. Choices separate by `|` character', null ),
			$settings_config_instance->create_field( 'guided_regex', 'text', 'Guided Regex', 'The output will follow the regex pattern.', null ),
			$settings_config_instance->create_field( 'guided_json', 'textarea', 'Guided JSON', 'The output will follow the JSON schema. A valid schema must be provided.', null ),
			$settings_config_instance->create_field( 'guided_json_vi', 'textarea', 'Guided JSON (Vietnamese)', 'The output will follow the JSON schema for Vietnamese language. A valid schema must be provided.', null ),
			$settings_config_instance->create_field( 'storing_json', 'textarea', 'Storing JSON Schema', 'Optional. The final JSON schema for aggregating parallel results. If provided, parallel responses will be merged into this structure.', null ),
		);

		// Common sections for most APIs.
		$common_sections = array(
			$settings_config_instance->create_section( 'general-setting', $common_general_fields ),
			$settings_config_instance->create_section( 'advanced-setting', $common_advanced_fields ),
		);

		// Scoring sections with score regex.
		$scoring_sections = array(
			$settings_config_instance->create_section( 'general-setting', $common_general_fields ),
			$settings_config_instance->create_section(
				'advanced-setting',
				array(
					$settings_config_instance->create_field( 'maxToken', 'number', 'Max Token', 'The maximum number of tokens to generate.', 2048 ),
					$settings_config_instance->create_field( 'temperature', 'number', 'Temperature', 'The value used to module the next token probabilities.', 0.1 ),
					$settings_config_instance->create_field( 'top_p', 'number', 'Top P', 'Float that controls the cumulative probability of the top tokens to consider. Must be in (0, 1]. Set to 1 to consider all tokens.', 0.8 ),
					$settings_config_instance->create_field( 'top_k', 'number', 'Top K', 'Integer that controls the number of top tokens to consider. Set to -1 to consider all tokens.', 20 ),
					$settings_config_instance->create_field( 'scoreRegex', 'text', 'Score Regex', 'Regular expression to extract score from the model output.', '/\d+/' ),
					$settings_config_instance->create_field( 'guided_choice', 'text', 'Guided Choice', 'The output will be exactly one of the choices. Choices separate by `|` character', null ),
					$settings_config_instance->create_field( 'guided_regex', 'text', 'Guided Regex', 'The output will follow the regex pattern.', null ),
					$settings_config_instance->create_field( 'guided_json', 'textarea', 'Guided JSON', 'The output will follow the JSON schema. A valid schema must be provided.', null ),
					$settings_config_instance->create_field( 'guided_json_vi', 'textarea', 'Guided JSON (Vietnamese)', 'The output will follow the JSON schema for Vietnamese language. A valid schema must be provided.', null ),
					$settings_config_instance->create_field( 'storing_json', 'textarea', 'Storing JSON Schema', 'Optional. The final JSON schema for aggregating parallel results. If provided, parallel responses will be merged into this structure.', null ),
				)
			),
		);

		// Placeholder configuration for speaking APIs settings.
		$speaking_apis_settings = array(
			array(
				'groupName'  => 'transcribe',
				'groupTitle' => 'Transcribe',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'transcribe',
						'Transcribe',
						'audio',
						array( 'speaking' ),
						array(
							$settings_config_instance->create_step(
								'transcribe',
								array(
									$settings_config_instance->create_section(
										'general-setting',
										array(
											$settings_config_instance->create_field(
												'apiProvider',
												'radio',
												'API Provider',
												'Select which API provider to use.',
												'home-server-whisperx-api-server',
												array(
													array(
														'label' => 'Open AI',
														'value' => 'open-ai',
													),
													array(
														'label' => 'Home Server Whisper X',
														'value' => 'home-server-whisperx-api-server',
													),
												)
											),
											$settings_config_instance->create_model_picker_field( 'apiProvider', $stt_model_options ),
											$settings_config_instance->create_prompt_field( 'prompt', 'Prompt', 'I is Vietnamese ESL learner so I makes lot of error grammar', $speaking_merge_tags ),
										)
									),
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
						'vocabulary-suggestions-speaking',
						'Vocabulary Suggestions Speaking',
						'attempt',
						array( 'speaking' ),
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
						'vocabulary-range-speaking',
						'Vocabulary Range Speaking',
						'attempt',
						array( 'speaking' ),
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
						'grammar-suggestions-speaking',
						'Grammar Suggestions Speaking',
						'attempt',
						array( 'speaking' ),
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
						'grammar-range-speaking',
						'Grammar Range Speaking',
						'attempt',
						array( 'speaking' ),
						array(
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
				),
			),
			array(
				'groupName'  => 'fluency-coherence',
				'groupTitle' => 'Fluency & Coherence',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'coherence',
						'Coherence',
						'speech',
						array( 'speaking' ),
						array(
							$settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
				),
			),
			array(
				'groupName'  => 'lexical-resource',
				'groupTitle' => 'Lexical Resource',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'range-of-vocab-speaking',
						'Range of Vocab Speaking',
						'speech',
						array( 'speaking' ),
						array(
							$settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'word-choice-collocation-style-speaking',
						'Word choice, Collocation, Style Speaking',
						'speech',
						array( 'speaking' ),
						array(
							$settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'uncommon-vocab-speaking',
						'Uncommon vocab Speaking',
						'speech',
						array( 'speaking' ),
						array(
							$settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'new-vocab-speaking',
						'New Vocabulary',
						'speech',
						array( 'speaking' ),
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
				'groupName'  => 'grammatical-range-speaking',
				'groupTitle' => 'Grammatical Range & Accuracy Speaking',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'range-of-structures-speaking',
						'Range of Structures Speaking',
						'speech',
						array( 'speaking' ),
						array(
							$settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
					$settings_config_instance->create_feed(
						'grammar-accuracy-speaking',
						'Grammar Accuracy Speaking',
						'speech',
						array( 'speaking' ),
						array(
							$settings_config_instance->create_step( 'chain-of-thought', $common_sections ),
							$settings_config_instance->create_step( 'scoring', $scoring_sections ),
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
				),
			),
			array(
				'groupName'  => 'improve-speech',
				'groupTitle' => 'Improve Speech',
				'feeds'      => array(
					$settings_config_instance->create_feed(
						'improve-speech',
						'Improve Speech',
						'attempt',
						array( 'speaking' ),
						array(
							$settings_config_instance->create_step( 'feedback', $common_sections ),
						)
					),
				),
			),
		);

		return $speaking_apis_settings;
	}

	/**
	 * Register speaking module sample results data.
	 *
	 * @param array $sample_results_data Existing sample results data.
	 * @return array Updated sample results data with speaking module information.
	 */
	public function register_sample_results_data( $sample_results_data ) {
		$sample_results_data['speaking_module'] = array(
			'module_name'   => 'speaking_module',
			'section_title' => __( 'Speaking Module Sample Results', 'ielts-science-lms' ),
			'section_desc'  => __( 'Configure sample result links for the Speaking Module.', 'ielts-science-lms' ),
			'samples'       => array(
				'speaking_sample' => array(
					'label'       => __( 'Speaking Sample Result', 'ielts-science-lms' ),
					'description' => __( 'URL to a sample result page. Will be available in front-end apps.', 'ielts-science-lms' ),
				),
			),
		);

		return $sample_results_data;
	}
}
