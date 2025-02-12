<?php

namespace IeltsScienceLMS\Writing;

class Ieltssci_Writing_Settings {
	public function __construct() {
		add_filter( 'ieltssci_settings_config', [ $this, 'register_settings_config' ] );
	}

	public function register_settings_config( $settings ) {

		$writing_settings = [ 
			'writing-apis' => [ 
				'tab_label' => __( 'Writing APIs', 'ielts-science-lms' ),
				'settings' => $this->writing_apis_settings(),
			],
		];

		return array_merge( $settings, $writing_settings );
	}

	protected function writing_apis_settings() {

		$settingsInstance = new \IeltsScienceLMS\Core\Ieltssci_Settings();

		$defaultModelOptions = $settingsInstance->getModelOptions( [ 
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
			],
			'azure' => [ 
				[ 'label' => 'gpt-4o-mini', 'value' => 'gpt-4o-mini' ],
				[ 'label' => 'gpt-4o', 'value' => 'gpt-4o' ],
			],
			'home-server' => [],
		] );

		$commonGeneralFields = [ 
			$settingsInstance->createApiProviderField(),
			$settingsInstance->createModelPickerField( 'apiProvider', $defaultModelOptions ),
			$settingsInstance->createPromptField( 'englishPrompt', 'English Prompt', 'Message sent to the model {|parameter_name|}' ),
			$settingsInstance->createPromptField( 'vietnamesePrompt', 'Vietnamese Prompt', 'Message sent to the model {|parameter_name|}' ),
		];

		$commonAdvancedFields = [ 
			$settingsInstance->createField( 'maxToken', 'number', 'Max Token', 'The maximum number of tokens to generate.', 2048 ),
			$settingsInstance->createField( 'temperature', 'number', 'Temperature', 'The value used to module the next token probabilities.', 0.1 ),
		];

		$commonSections = [ 
			$settingsInstance->createSection( 'general-setting', $commonGeneralFields ),
			$settingsInstance->createSection( 'advanced-setting', $commonAdvancedFields ),
		];

		$writing_apis_settings = [ 
			[ 
				'groupName' => 'vocabulary-suggestions',
				'groupTitle' => 'Vocabulary Suggestions',
				'feeds' => [ 
					$settingsInstance->createFeed(
						'vocabulary-suggestions',
						'Vocabulary Suggestions',
						'essay',
						[ 'task-2', 'task-2-ocr' ],
						[ 
							$settingsInstance->createStep( 'feedback', $commonSections )
						] ),
				],
			],
			[ 
				'groupName' => 'grammar-suggestions',
				'groupTitle' => 'Grammar Suggestions',
				'feeds' => [ 
					$settingsInstance->createFeed(
						'grammar-suggestions',
						'Grammar Suggestions',
						'essay',
						[ 'task-2', 'task-2-ocr' ],
						[ 
							$settingsInstance->createStep( 'feedback', $commonSections )
						] ),
				],
			],
			[ 
				'groupName' => 'argument-enhance',
				'groupTitle' => 'Argument Enhance',
				'feeds' => [ 
					$settingsInstance->createFeed(
						'segmenting',
						'Segmenting',
						'essay',
						[ 'task-2', 'task-2-ocr' ],
						[ $settingsInstance->createStep( 'output', [ 
							$settingsInstance->createSection( 'general-setting', [ 
								$settingsInstance->createApiProviderField( 'home-server' ),
								$settingsInstance->createModelPickerField( 'apiProvider', $defaultModelOptions, 'bihungba1101/segmenting-paragraph' ),
								$settingsInstance->createPromptField( 'englishPrompt', 'English Prompt', '{|each_paragraph_in_essay|}' ),
								$settingsInstance->createPromptField( 'vietnamesePrompt', 'Vietnamese Prompt', '{|each_paragraph_in_essay|}' ),
							] ),
							$settingsInstance->createSection( 'advanced-setting', $commonAdvancedFields )
						] )
						] ),
					$settingsInstance->createFeed( 'introduction-relevance', 'Introduction Relevance', 'introduction', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'introduction-clear-answer', 'Introduction Clear Answer/Clear Opinion', 'introduction', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'introduction-brief-overview', 'Introduction Brief Overview', 'introduction', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'introduction-rewrite', 'Introduction Rewrite', 'introduction', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'feedback', [ $settingsInstance->createSection( 'general-setting', $commonGeneralFields ), $settingsInstance->createSection( 'advanced-setting', $commonAdvancedFields ) ] )
					] ),
					$settingsInstance->createFeed( 'topic-sentence-linking', 'Topic Sentence Linking', 'topic-sentence', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'topic-sentence-relevance', 'Topic Sentence Relevance', 'topic-sentence', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'topic-sentence-rewrite', 'Topic Sentence Rewrite', 'topic-sentence', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'feedback', [ $settingsInstance->createSection( 'general-setting', $commonGeneralFields ), $settingsInstance->createSection( 'advanced-setting', $commonAdvancedFields ) ] )
					] ),
					$settingsInstance->createFeed( 'main-point-logic-depth', 'Main Point Logic & Depth', 'main-point', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'main-point-overgeneralize', 'Main Point Overgeneralize', 'main-point', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'main-point-relevance', 'Main Point Relevance', 'main-point', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'main-point-rewrite', 'Main Point Rewrite', 'main-point', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'feedback', [ $settingsInstance->createSection( 'general-setting', $commonGeneralFields ), $settingsInstance->createSection( 'advanced-setting', $commonAdvancedFields ) ] )
					] ),
					$settingsInstance->createFeed( 'conclusion-relevance', 'Conclusion Relevance', 'conclusion', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'conclusion-clear-answer', 'Conclusion Clear Answer/Clear Opinion', 'conclusion', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'conclusion-rewrite', 'Conclusion Rewrite', 'conclusion', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'feedback', [ $settingsInstance->createSection( 'general-setting', $commonGeneralFields ), $settingsInstance->createSection( 'advanced-setting', $commonAdvancedFields ) ] )
					] ),
				],
			],
			[ 
				'groupName' => 'lexical-resource',
				'groupTitle' => 'Lexical Resource',
				'feeds' => [ 
					$settingsInstance->createFeed( 'range-of-vocab', 'Range of Vocab', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'word-choice-collocation-style', 'Word choice, Collocation, Style', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'uncommon-vocab', 'Uncommon vocab', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'spelling-word-form-error', 'Spelling, Word Form Error', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
				],
			],
			[ 
				'groupName' => 'grammatical-range-accuracy',
				'groupTitle' => 'Grammatical Range & Accuracy',
				'feeds' => [ 
					$settingsInstance->createFeed( 'range-of-structures', 'Range of Structures', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'grammar-accuracy', 'Grammar Accuracy', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
				],
			],
			[ 
				'groupName' => 'coherence-cohesion',
				'groupTitle' => 'Coherence & Cohesion',
				'feeds' => [ 
					$settingsInstance->createFeed( 'flow', 'Flow', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'paragraphing', 'Paragraphing', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'referencing', 'Referencing', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'use-of-cohesive-devices', 'Use of Cohesive Devices', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
				],
			],
			[ 
				'groupName' => 'task-response',
				'groupTitle' => 'Task Response',
				'feeds' => [ 
					$settingsInstance->createFeed( 'relevance', 'Relevance', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'clear-opinion', 'Clear Opinion', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
					$settingsInstance->createFeed( 'idea-development', 'Idea Development', 'essay', [ 'task-2', 'task-2-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections ),
					] ),
				],
			],
			[ 
				'groupName' => 'task-achievement',
				'groupTitle' => 'Task Achievement',
				'feeds' => [ 
					$settingsInstance->createFeed( 'use-data-accurately', 'Use data accurately', 'essay', [ 'task-1', 'task-1-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections )
					] ),
					$settingsInstance->createFeed( 'present-key-features', 'Present key features', 'essay', [ 'task-1', 'task-1-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections )
					] ),
					$settingsInstance->createFeed( 'use-data', 'Use data', 'essay', [ 'task-1', 'task-1-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections )
					] ),
					$settingsInstance->createFeed( 'present-an-overview', 'Present an overview', 'essay', [ 'task-1', 'task-1-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections )
					] ),
					$settingsInstance->createFeed( 'format', 'Format', 'essay', [ 'task-1', 'task-1-ocr' ], [ 
						$settingsInstance->createStep( 'chain-of-thought', $commonSections ),
						$settingsInstance->createStep( 'scoring', $commonSections ),
						$settingsInstance->createStep( 'feedback', $commonSections )
					] ),
				],
			],
			[ 
				'groupName' => 'improve-essay',
				'groupTitle' => 'Improve Essay',
				'feeds' => [ 
					$settingsInstance->createFeed(
						'improve-essay-task-2',
						'Improve Essay Task 2',
						'essay',
						[ 'task-2', 'task-2-ocr' ],
						[ 
							$settingsInstance->createStep( 'feedback', $commonSections )
						]
					),
					$settingsInstance->createFeed(
						'improve-essay-task-1',
						'Improve Essay Task 1',
						'essay',
						[ 'task-1', 'task-1-ocr' ],
						[ 
							$settingsInstance->createStep( 'feedback', $commonSections )
						]
					),
				],
			],

		];

		return $writing_apis_settings;
	}
}