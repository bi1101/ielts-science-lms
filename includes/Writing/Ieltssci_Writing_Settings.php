<?php

namespace IeltsScienceLMS\Writing;

use IeltsScienceLMS\ApiFeeds\Ieltssci_ApiFeeds_DB;

class Ieltssci_Writing_Settings {
	private $db;

	public function __construct() {
		add_filter( 'ieltssci_settings_config', [ $this, 'register_settings_config' ] );
		$this->db = new Ieltssci_ApiFeeds_DB();
	}

	public function register_settings_config( $settings ) {

		$writing_settings = [ 
			'writing-apis' => [ 
				'tab_label' => __( 'Writing APIs', 'ielts-science-lms' ),
				'tab_type' => 'api-feeds',
				'settings' => $this->writing_apis_settings(),
			],
			'writing-apis-process-order' => [ 
				'tab_label' => __( 'Writing APIs Process Order', 'ielts-science-lms' ),
				'tab_type' => 'api-feeds-process-order',
				'settings' => $this->essay_types(),
			]
		];

		return array_merge( $settings, $writing_settings );
	}

	protected function essay_types() {
		// Get all feeds with their essay types and process orders using get_api_feeds
		$feeds = $this->db->get_api_feeds( [ 
			'limit' => 500, // High limit to get all feeds
			'include' => [ 'essay_types' ] // Include essay types data
		] );

		// Group feeds by essay type
		$grouped_feeds = [];
		foreach ( $feeds as $feed ) {
			if ( empty( $feed['essay_types'] ) ) {
				continue;
			}

			foreach ( $feed['essay_types'] as $essay_type_data ) {
				$essay_type = $essay_type_data['essay_type'];

				if ( ! isset( $grouped_feeds[ $essay_type ] ) ) {
					$grouped_feeds[ $essay_type ] = [];
				}

				$grouped_feeds[ $essay_type ][] = [ 
					'id' => (int) $feed['id'],
					'feedName' => $feed['feedback_criteria'],
					'feedTitle' => $feed['feed_title'],
					'feedDesc' => $feed['feed_desc'],
					'processOrder' => (int) $essay_type_data['process_order']
				];
			}
		}

		// Map to the required format
		return [ 
			[ 
				'groupName' => 'task-1',
				'groupTitle' => 'Task 1',
				'feeds' => $grouped_feeds['task-1'] ?? []
			],
			[ 
				'groupName' => 'task-2',
				'groupTitle' => 'Task 2',
				'feeds' => $grouped_feeds['task-2'] ?? []
			],
			[ 
				'groupName' => 'task-1-ocr',
				'groupTitle' => 'Task 1 OCR',
				'feeds' => $grouped_feeds['task-1-ocr'] ?? []
			],
			[ 
				'groupName' => 'task-2-ocr',
				'groupTitle' => 'Task 2 OCR',
				'feeds' => $grouped_feeds['task-2-ocr'] ?? []
			],
		];
	}

	protected function writing_apis_settings() {

		$settingsConfigInstance = new \IeltsScienceLMS\Settings\Ieltssci_Settings_Config();

		// Define writing specific merge tags.
		$writingMergeTags = [ 
			[ 
				'groupLabel' => 'Essay level',
				'items' => [ 
					[ 'label' => 'Essay Content', 'info' => '{|essay:essay_content|}', 'value' => '{|essay:essay_content|}' ],
					[ 'label' => 'Question', 'info' => '{|essay:question|}', 'value' => '{|essay:question|}' ],
				],
			],
			[ 
				'groupLabel' => 'Segment level',
				'items' => [ 
					[ 'label' => 'Introduction Content', 'info' => '{|segment:content[type:introduction]|}', 'value' => '{|segment:content[type:introduction]|}' ],
				],
			]
		];

		// Apply filter to allow other plugins to add more writing merge tags.
		$writingMergeTags = apply_filters( 'ieltssci_writing_merge_tags', $writingMergeTags );

		$defaultModelOptions = $settingsConfigInstance->getModelOptions( [ 
			'open-key-ai' => [ 
				[ 'label' => 'gpt-4o-mini', 'value' => 'gpt-4o-mini' ],
				[ 'label' => 'gpt-4o', 'value' => 'gpt-4o' ],
			],
			'open-ai' => [ 
				[ 'label' => 'gpt-4o-mini', 'value' => 'gpt-4o-mini' ],
				[ 'label' => 'gpt-4o', 'value' => 'gpt-4o' ],
			],
			'google' => [ 
				[ 'label' => 'gemini-1.5-flash', 'value' => 'gemini-1.5-flash' ],
				[ 'label' => 'gemini-1.5-pro', 'value' => 'gemini-1.5-pro' ],
				[ 'label' => 'gemini-2.0-flash', 'value' => 'gemini-2.0-flash' ],
			],
			'azure' => [ 
				[ 'label' => 'gpt-4o-mini', 'value' => 'gpt-4o-mini' ],
				[ 'label' => 'gpt-4o', 'value' => 'gpt-4o' ],
			],
			'home-server' => [],
		] );

		$commonGeneralFields = [ 
			$settingsConfigInstance->createApiProviderField(),
			$settingsConfigInstance->createModelPickerField( 'apiProvider', $defaultModelOptions ),
			$settingsConfigInstance->createPromptField( 'englishPrompt', 'English Prompt', 'Message sent to the model {|parameter_name|}', $writingMergeTags ),
			$settingsConfigInstance->createPromptField( 'vietnamesePrompt', 'Vietnamese Prompt', 'Message sent to the model {|parameter_name|}', $writingMergeTags ),
		];

		$commonAdvancedFields = [ 
			$settingsConfigInstance->createField( 'maxToken', 'number', 'Max Token', 'The maximum number of tokens to generate.', 2048 ),
			$settingsConfigInstance->createField( 'temperature', 'number', 'Temperature', 'The value used to module the next token probabilities.', 0.1 ),
		];

		$commonSections = [ 
			$settingsConfigInstance->createSection( 'general-setting', $commonGeneralFields ),
			$settingsConfigInstance->createSection( 'advanced-setting', $commonAdvancedFields ),
		];

		$writing_apis_settings = [ 
			[ 
				'groupName' => 'ocr',
				'groupTitle' => 'OCR',
				'feeds' => [ 
					$settingsConfigInstance->createFeed(
						'ocr-essay',
						'OCR Essay',
						'essay',
						[ 'task-1-ocr', 'task-2-ocr' ],
						[ 
							$settingsConfigInstance->createStep( 'feedback', [ 
								$settingsConfigInstance->createSection( 'general-setting', [ 
									$settingsConfigInstance->createApiProviderField(),
									$settingsConfigInstance->createModelPickerField( 'apiProvider', $defaultModelOptions ),
									// Toggle to enable/disable multi-modal field
									$settingsConfigInstance->createField( 'enableMultiModal', 'toggle', 'Enable Multi Modal Input', 'Enable Multi Modal input or not', false ),
									$settingsConfigInstance->createField( 'multiModalField', 'form-token', 'Multi Modal Field(s)', '', null, [], 'enableMultiModal', [ 
										'suggestions' => [ 'ocr_image_ids', 'chart_image_ids' ],
									] ),
									$settingsConfigInstance->createPromptField( 'englishPrompt', 'English Prompt', 'Message sent to the model {|parameter_name|}', $writingMergeTags ),
									$settingsConfigInstance->createPromptField( 'vietnamesePrompt', 'Vietnamese Prompt', 'Message sent to the model {|parameter_name|}', $writingMergeTags ),
								] ),
								$settingsConfigInstance->createSection( 'advanced-setting', $commonAdvancedFields )
							] )
						] ),
				],
			],
			[ 
				'groupName' => 'vocabulary-suggestions',
				'groupTitle' => 'Vocabulary Suggestions',
				'feeds' => [ 
					$settingsConfigInstance->createFeed(
						'vocabulary-suggestions',
						'Vocabulary Suggestions',
						'essay',
						[ 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ],
						[ 
							$settingsConfigInstance->createStep( 'feedback', $commonSections )
						] ),
				],
			],
			[ 
				'groupName' => 'grammar-suggestions',
				'groupTitle' => 'Grammar Suggestions',
				'feeds' => [ 
					$settingsConfigInstance->createFeed(
						'grammar-suggestions',
						'Grammar Suggestions',
						'essay',
						[ 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ],
						[ 
							$settingsConfigInstance->createStep( 'feedback', $commonSections )
						] ),
				],
			],
			[ 
				'groupName' => 'argument-enhance',
				'groupTitle' => 'Argument Enhance',
				'feeds' => [ 
					$settingsConfigInstance->createFeed(
						'segmenting',
						'Segmenting',
						'paragraph',
						[ 'task-2', 'task-2-ocr' ],
						[ $settingsConfigInstance->createStep( 'output', [ 
							$settingsConfigInstance->createSection( 'general-setting', [ 
								$settingsConfigInstance->createApiProviderField( 'home-server' ),
								$settingsConfigInstance->createModelPickerField( 'apiProvider', $defaultModelOptions, 'bihungba1101/segmenting-paragraph' ),
								$settingsConfigInstance->createPromptField( 'englishPrompt', 'English Prompt', '{|each_paragraph_in_essay|}', $writingMergeTags ),
								$settingsConfigInstance->createPromptField( 'vietnamesePrompt', 'Vietnamese Prompt', '{|each_paragraph_in_essay|}', $writingMergeTags ),
							] ),
							$settingsConfigInstance->createSection( 'advanced-setting', $commonAdvancedFields )
						] )
						] ),
					$settingsConfigInstance->createFeed( 'introduction-relevance', 'Introduction Relevance', 'introduction', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'introduction-clear-answer', 'Introduction Clear Answer/Clear Opinion', 'introduction', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'introduction-brief-overview', 'Introduction Brief Overview', 'introduction', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'introduction-rewrite', 'Introduction Rewrite', 'introduction', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'feedback', [ $settingsConfigInstance->createSection( 'general-setting', $commonGeneralFields ), $settingsConfigInstance->createSection( 'advanced-setting', $commonAdvancedFields ) ] )
					] ),
					$settingsConfigInstance->createFeed( 'topic-sentence-linking', 'Topic Sentence Linking', 'topic-sentence', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'topic-sentence-relevance', 'Topic Sentence Relevance', 'topic-sentence', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'topic-sentence-rewrite', 'Topic Sentence Rewrite', 'topic-sentence', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'feedback', [ $settingsConfigInstance->createSection( 'general-setting', $commonGeneralFields ), $settingsConfigInstance->createSection( 'advanced-setting', $commonAdvancedFields ) ] )
					] ),
					$settingsConfigInstance->createFeed( 'main-point-logic-depth', 'Main Point Logic & Depth', 'main-point', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'main-point-overgeneralize', 'Main Point Overgeneralize', 'main-point', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'main-point-relevance', 'Main Point Relevance', 'main-point', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'main-point-rewrite', 'Main Point Rewrite', 'main-point', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'feedback', [ $settingsConfigInstance->createSection( 'general-setting', $commonGeneralFields ), $settingsConfigInstance->createSection( 'advanced-setting', $commonAdvancedFields ) ] )
					] ),
					$settingsConfigInstance->createFeed( 'conclusion-relevance', 'Conclusion Relevance', 'conclusion', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'conclusion-clear-answer', 'Conclusion Clear Answer/Clear Opinion', 'conclusion', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'conclusion-rewrite', 'Conclusion Rewrite', 'conclusion', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'feedback', [ $settingsConfigInstance->createSection( 'general-setting', $commonGeneralFields ), $settingsConfigInstance->createSection( 'advanced-setting', $commonAdvancedFields ) ] )
					] ),
				],
			],
			[ 
				'groupName' => 'lexical-resource',
				'groupTitle' => 'Lexical Resource',
				'feeds' => [ 
					$settingsConfigInstance->createFeed( 'range-of-vocab', 'Range of Vocab', 'essay', [ 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'word-choice-collocation-style', 'Word choice, Collocation, Style', 'essay', [ 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'uncommon-vocab', 'Uncommon vocab', 'essay', [ 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'spelling-word-form-error', 'Spelling, Word Form Error', 'essay', [ 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
				],
			],
			[ 
				'groupName' => 'grammatical-range-accuracy',
				'groupTitle' => 'Grammatical Range & Accuracy',
				'feeds' => [ 
					$settingsConfigInstance->createFeed( 'range-of-structures', 'Range of Structures', 'essay', [ 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'grammar-accuracy', 'Grammar Accuracy', 'essay', [ 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
				],
			],
			[ 
				'groupName' => 'coherence-cohesion',
				'groupTitle' => 'Coherence & Cohesion',
				'feeds' => [ 
					$settingsConfigInstance->createFeed( 'flow', 'Flow', 'essay', [ 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'paragraphing', 'Paragraphing', 'essay', [ 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'referencing', 'Referencing', 'essay', [ 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'use-of-cohesive-devices', 'Use of Cohesive Devices', 'essay', [ 'task-2', 'task-2-ocr', 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
				],
			],
			[ 
				'groupName' => 'task-response',
				'groupTitle' => 'Task Response',
				'feeds' => [ 
					$settingsConfigInstance->createFeed( 'relevance', 'Relevance', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'clear-opinion', 'Clear Opinion', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsConfigInstance->createFeed( 'idea-development', 'Idea Development', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections ),
					] ),
				],
			],
			[ 
				'groupName' => 'task-achievement',
				'groupTitle' => 'Task Achievement',
				'feeds' => [ 
					$settingsConfigInstance->createFeed( 'use-data-accurately', 'Use data accurately', 'essay', [ 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections )
					] ),
					$settingsConfigInstance->createFeed( 'present-key-features', 'Present key features', 'essay', [ 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections )
					] ),
					$settingsConfigInstance->createFeed( 'use-data', 'Use data', 'essay', [ 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections )
					] ),
					$settingsConfigInstance->createFeed( 'present-an-overview', 'Present an overview', 'essay', [ 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections )
					] ),
					$settingsConfigInstance->createFeed( 'format', 'Format', 'essay', [ 'task-1', 'task-1-ocr' ], [ 
						$settingsConfigInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsConfigInstance->createStep( 'scoring', $commonSections ),
						$settingsConfigInstance->createStep( 'feedback', $commonSections )
					] ),
				],
			],
			[ 
				'groupName' => 'improve-essay',
				'groupTitle' => 'Improve Essay',
				'feeds' => [ 
					$settingsConfigInstance->createFeed(
						'improve-essay-task-2',
						'Improve Essay Task 2',
						'essay',
						[ 'task-2', 'task-2-ocr' ],
						[ 
							$settingsConfigInstance->createStep( 'feedback', $commonSections )
						]
					),
					$settingsConfigInstance->createFeed(
						'improve-essay-task-1',
						'Improve Essay Task 1',
						'essay',
						[ 'task-1', 'task-1-ocr' ],
						[ 
							$settingsConfigInstance->createStep( 'feedback', $commonSections )
						]
					),
				],
			],

		];

		return $writing_apis_settings;
	}
}