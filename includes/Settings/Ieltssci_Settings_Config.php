<?php

namespace IeltsScienceLMS\Settings;

class Ieltssci_Settings_Config {
	/**
	 * Generates a field configuration.
	 *
	 * @param string $id           Field ID.
	 * @param string $type         Field type (e.g., 'radio', 'textarea', 'number', 'modelPicker').
	 * @param string $label        Field label.
	 * @param string $help         (Optional) Help text.
	 * @param mixed  $default      (Optional) Default value.
	 * @param array  $options      (Optional) Options for radio buttons, modelPicker, etc.
	 * @param string $dependency   (Optional) Field dependency.
	 * @param array  $extra_attrs  (Optional) Additional attributes for further customization.
	 *
	 * @return array The field configuration array.
	 */
	public function createField( string $id, string $type, string $label, string $help = '', $default = null, array $options = [], string $dependency = '', array $extra_attrs = [] ): array {
		$field = [ 
			'id' => $id,
			'type' => $type,
			'label' => $label,
		];

		if ( $help ) {
			$field['help'] = $help;
		}
		if ( $default !== null ) {
			$field['default'] = $default;
		} // Handle 0, false, etc. correctly
		if ( $options ) {
			$field['options'] = $options;
		}
		if ( $dependency ) {
			$field['dependency'] = $dependency;
		}
		if ( $extra_attrs ) {
			$field = array_merge( $field, $extra_attrs );
		} // Allow other attributes

		return $field;
	}

	/**
	 * Generates an API provider field configuration (reusable radio button group).
	 *
	 * @param mixed $default (Optional) Default value.
	 * @return array The API provider field configuration.
	 */
	public function createApiProviderField( $default = 'open-key-ai' ): array {
		return $this->createField(
			'apiProvider',
			'radio',
			'API Provider',
			'Select which API provider to use.',
			$default,
			[ 
				[ 'label' => 'Open Key AI', 'value' => 'open-key-ai' ],
				[ 'label' => 'Open AI', 'value' => 'open-ai' ],
				[ 'label' => 'Google', 'value' => 'google' ],
				[ 'label' => 'Azure', 'value' => 'azure' ],
				[ 'label' => 'Home Server', 'value' => 'home-server' ],
			]
		);
	}

	/**
	 * Generates a model picker field configuration.
	 *
	 * @param string $dependency The field this model picker depends on.
	 * @param array  $options    The model options.
	 * @param mixed  $default    (Optional) Default value.
	 *
	 * @return array The model picker field configuration.
	 */
	public function createModelPickerField( string $dependency, array $options, $default = 'gpt-4o-mini' ): array {
		return $this->createField(
			'model',
			'modelPicker',
			'Model',
			'',  // No help text in the original for model
			$default,
			$options,
			$dependency
		);
	}

	/**
	 * Generates common model options based on API provider.  Handles the "Other:" option.
	 *
	 * @param array $providerOptions Model options specific to a provider.
	 * @return array Combined options, including the "Other:" option.
	 */
	public function getModelOptions( array $providerOptions ): array {
		$options = [];
		foreach ( $providerOptions as $provider => $models ) {
			$options[ $provider ] = $models;
			// Check if 'Other:' already exists, if not add. Avoid duplicates
			$hasOther = false;
			foreach ( $models as $model ) {
				if ( $model['value'] === 'other' ) {
					$hasOther = true;
					break;
				}
			}
			if ( ! $hasOther ) {
				$options[ $provider ][] = [ 'label' => 'Other:', 'value' => 'other' ];
			}
		}
		return $options;
	}
	/**
	 * Generates a prompt field configuration.
	 *
	 * @param string $id         Field ID (e.g., 'englishPrompt', 'vietnamesePrompt').
	 * @param string $label      Field label (e.g., 'English Prompt', 'Vietnamese Prompt').
	 * @param string $default    Default prompt text.
	 * @param array  $merge_tags (Optional) Merge tags array.
	 *
	 * @return array The prompt field configuration.
	 */
	public function createPromptField( string $id, string $label, string $default, array $merge_tags = [] ): array {
		$default_merge_tags = [ 
			[ 
				'groupLabel' => 'General',
				'items' => [ 
					[ 'label' => 'Site URL', 'info' => '{site_url}', 'value' => '{site_url}' ],
				],
			]
		];
		// Get default merge tags through filter
		$filtered_merge_tags = apply_filters( 'ieltssci_merge_tags', $default_merge_tags );

		// Merge provided tags with filtered tags
		$combined_merge_tags = array_merge( $filtered_merge_tags, $merge_tags );

		return $this->createField(
			$id,
			'textarea',
			$label,
			'The message to send to LLM',
			$default,
			[],
			'',
			[ 'merge_tags' => $combined_merge_tags ]
		);
	}

	/**
	 * Generates a section configuration.
	 *
	 * @param string $sectionName  Section name (e.g., 'general-setting', 'advanced-setting').
	 * @param array  $fields       Array of field configurations.
	 *
	 * @return array The section configuration.
	 */
	public function createSection( string $sectionName, array $fields ): array {
		return [ 
			'section' => $sectionName,
			'fields' => $fields,
		];
	}

	/**
	 * Generates a step configuration.
	 *
	 * @param string $stepName    Step name (e.g., 'chain-of-thought', 'scoring', 'feedback').
	 * @param array  $sections    Array of section configurations.
	 *
	 * @return array The step configuration.
	 */
	public function createStep( string $stepName, array $sections ): array {
		return [ 
			'step' => $stepName,
			'sections' => $sections,
		];
	}

	/**
	 * Creates a feed configuration.
	 *
	 * @param string $feedName
	 * @param string $feedTitle
	 * @param string $applyTo
	 * @param array $essayType
	 * @param array $steps
	 * @return array
	 */
	public function createFeed( string $feedName, string $feedTitle, string $applyTo, array $essayType, array $steps ): array {
		return [ 
			'feedName' => $feedName,
			'feedTitle' => $feedTitle,
			'applyTo' => $applyTo,
			'essayType' => $essayType,
			'steps' => $steps
		];
	}

	/**
	 * Generates the main settings configuration.
	 *
	 * @param string|null $tab The tab ID to retrieve settings for, or null for the tab list.
	 *
	 * @return array The settings configuration, or null if the tab is not found.
	 */
	public function get_settings_config( $tab = null ) {
		$all_settings = apply_filters( 'ieltssci_settings_config', [] );

		if ( $tab === null ) {
			// Return only the tab list (id and label)
			$tab_list = [];
			foreach ( $all_settings as $tab_id => $tab_data ) {
				$tab_list[] = [ 
					'id' => $tab_id,
					'label' => $tab_data['tab_label'],
					'type' => $tab_data['tab_type'],
				];
			}
			return $tab_list;
		} else {
			// Return the full settings for the specified tab
			if ( isset( $all_settings[ $tab ] ) && isset( $all_settings[ $tab ]['settings'] ) ) {
				return $all_settings[ $tab ]['settings'];
			} else {
				return [];
			}
		}
	}
}
