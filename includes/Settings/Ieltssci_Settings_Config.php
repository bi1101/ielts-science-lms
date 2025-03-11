<?php
/**
 * Settings Configuration Manager
 *
 * This file contains the Ieltssci_Settings_Config class which manages the settings
 * configuration for the IELTS Science LMS plugin.
 *
 * @package IELTS_Science_LMS
 * @subpackage Settings
 */

namespace IeltsScienceLMS\Settings;

/**
 * Class Ieltssci_Settings_Config
 *
 * Handles the configuration of settings for the IELTS Science LMS plugin.
 * Includes methods for creating fields, sections, steps, and complete feeds.
 *
 * @package IELTS_Science_LMS\Settings
 */
class Ieltssci_Settings_Config {
	/**
	 * Generates a field configuration.
	 *
	 * @param string $id           Field ID.
	 * @param string $type         Field type (e.g., 'radio', 'textarea', 'number', 'modelPicker').
	 * @param string $label        Field label.
	 * @param string $help         (Optional) Help text.
	 * @param mixed  $default_value (Optional) Default value.
	 * @param array  $options      (Optional) Options for radio buttons, modelPicker, etc.
	 * @param string $dependency   (Optional) Field dependency.
	 * @param array  $extra_attrs  (Optional) Additional attributes for further customization.
	 *
	 * @return array The field configuration array.
	 */
	public function create_field( string $id, string $type, string $label, string $help = '', $default_value = null, array $options = array(), string $dependency = '', array $extra_attrs = array() ): array {
		$field = array(
			'id'    => $id,
			'type'  => $type,
			'label' => $label,
		);

		if ( $help ) {
			$field['help'] = $help;
		}
		if ( null !== $default_value ) {
			$field['default'] = $default_value;
		} // Handle 0, false, etc. correctly.
		if ( $options ) {
			$field['options'] = $options;
		}
		if ( $dependency ) {
			$field['dependency'] = $dependency;
		}
		if ( $extra_attrs ) {
			$field = array_merge( $field, $extra_attrs );
		} // Allow other attributes.

		return $field;
	}

	/**
	 * Generates an API provider field configuration (reusable radio button group).
	 *
	 * @param mixed $default_value (Optional) Default value.
	 * @return array The API provider field configuration.
	 */
	public function create_api_provider_field( $default_value = 'open-key-ai' ): array {
		return $this->create_field(
			'apiProvider',
			'radio',
			'API Provider',
			'Select which API provider to use.',
			$default_value,
			array(
				array(
					'label' => 'Open Key AI',
					'value' => 'open-key-ai',
				),
				array(
					'label' => 'Open AI',
					'value' => 'open-ai',
				),
				array(
					'label' => 'Google',
					'value' => 'google',
				),
				array(
					'label' => 'Azure',
					'value' => 'azure',
				),
				array(
					'label' => 'Home Server',
					'value' => 'home-server',
				),
			)
		);
	}

	/**
	 * Generates a model picker field configuration.
	 *
	 * @param string $dependency The field this model picker depends on.
	 * @param array  $options    The model options.
	 * @param mixed  $default_value (Optional) Default value.
	 *
	 * @return array The model picker field configuration.
	 */
	public function create_model_picker_field( string $dependency, array $options, $default_value = 'gpt-4o-mini' ): array {
		return $this->create_field(
			'model',
			'modelPicker',
			'Model',
			'',  // No help text in the original for model.
			$default_value,
			$options,
			$dependency
		);
	}

	/**
	 * Generates common model options based on API provider.  Handles the "Other:" option.
	 *
	 * @param array $provider_options Model options specific to a provider.
	 * @return array Combined options, including the "Other:" option.
	 */
	public function get_model_options( array $provider_options ): array {
		$options = array();
		foreach ( $provider_options as $provider => $models ) {
			$options[ $provider ] = $models;
			// Check if 'Other:' already exists, if not add. Avoid duplicates.
			$has_other = false;
			foreach ( $models as $model ) {
				if ( 'other' === $model['value'] ) {
					$has_other = true;
					break;
				}
			}
			if ( ! $has_other ) {
				$options[ $provider ][] = array(
					'label' => 'Other:',
					'value' => 'other',
				);
			}
		}
		return $options;
	}
	/**
	 * Generates a prompt field configuration.
	 *
	 * @param string $id         Field ID (e.g., 'englishPrompt', 'vietnamesePrompt').
	 * @param string $label      Field label (e.g., 'English Prompt', 'Vietnamese Prompt').
	 * @param string $default_value Default prompt text.
	 * @param array  $merge_tags (Optional) Merge tags array.
	 *
	 * @return array The prompt field configuration.
	 */
	public function create_prompt_field( string $id, string $label, string $default_value, array $merge_tags = array() ): array {
		$default_merge_tags = array(
			array(
				'groupLabel' => 'General',
				'items'      => array(
					array(
						'label' => 'Site URL',
						'info'  => '{|site_url|}',
						'value' => '{|site_url|}',
					),
				),
			),
		);
		// Get default merge tags through filter.
		$filtered_merge_tags = apply_filters( 'ieltssci_merge_tags', $default_merge_tags );

		// Merge provided tags with filtered tags.
		$combined_merge_tags = array_merge( $filtered_merge_tags, $merge_tags );

		return $this->create_field(
			$id,
			'textarea',
			$label,
			'The message to send to LLM',
			$default_value,
			array(),
			'',
			array( 'merge_tags' => $combined_merge_tags )
		);
	}

	/**
	 * Generates a section configuration.
	 *
	 * @param string $section_name  Section name (e.g., 'general-setting', 'advanced-setting').
	 * @param array  $fields       Array of field configurations.
	 *
	 * @return array The section configuration.
	 */
	public function create_section( string $section_name, array $fields ): array {
		return array(
			'section' => $section_name,
			'fields'  => $fields,
		);
	}

	/**
	 * Generates a step configuration.
	 *
	 * @param string $step_name    Step name (e.g., 'chain-of-thought', 'scoring', 'feedback').
	 * @param array  $sections    Array of section configurations.
	 *
	 * @return array The step configuration.
	 */
	public function create_step( string $step_name, array $sections ): array {
		return array(
			'step'     => $step_name,
			'sections' => $sections,
		);
	}

	/**
	 * Creates a feed configuration.
	 *
	 * @param string $feed_name    Feed name identifier.
	 * @param string $feed_title   Human-readable title for the feed.
	 * @param string $apply_to     Where this feed applies to.
	 * @param array  $essay_type   Array of essay types this feed supports.
	 * @param array  $steps        Configuration steps for the feed.
	 * @return array The complete feed configuration.
	 */
	public function create_feed( string $feed_name, string $feed_title, string $apply_to, array $essay_type, array $steps ): array {
		return array(
			'feedName'  => $feed_name,
			'feedTitle' => $feed_title,
			'applyTo'   => $apply_to,
			'essayType' => $essay_type,
			'steps'     => $steps,
		);
	}

	/**
	 * Generates the main settings configuration.
	 *
	 * @param string|null $tab The tab ID to retrieve settings for, or null for the tab list.
	 *
	 * @return array The settings configuration, or null if the tab is not found.
	 */
	public function get_settings_config( $tab = null ) {
		$all_settings = apply_filters( 'ieltssci_settings_config', array() );

		if ( null === $tab ) {
			// Return only the tab list (id and label).
			$tab_list = array();
			foreach ( $all_settings as $tab_id => $tab_data ) {
				$tab_list[] = array(
					'id'    => $tab_id,
					'label' => $tab_data['tab_label'],
					'type'  => $tab_data['tab_type'],
				);
			}
			return $tab_list;
		} elseif ( isset( $all_settings[ $tab ] ) && isset( $all_settings[ $tab ]['settings'] ) ) {
			// Return the full settings for the specified tab.
			return $all_settings[ $tab ]['settings'];
		} else {
			return array();
		}
	}
}
