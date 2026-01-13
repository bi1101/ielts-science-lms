<?php
/**
 * API Keys Settings Class
 *
 * This file contains the API keys settings configuration for the IELTS Science LMS plugin.
 *
 * @package IELTS_Science_LMS
 * @subpackage ApiKeys
 * @since 1.0.0
 */

namespace IeltsScienceLMS\ApiKeys;

/**
 * API Keys Settings Class
 *
 * Handles the configuration and registration of API key settings
 * for various services used within the IELTS Science LMS plugin.
 *
 * @since 1.0.0
 */
class Ieltssci_ApiKeys_Settings {
	/**
	 * Constructor
	 *
	 * Registers the filter hook to add API key settings to the settings configuration.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_filter( 'ieltssci_settings_config', array( $this, 'register_settings_config' ) );
	}

	/**
	 * Register Settings Configuration
	 *
	 * Adds the API keys settings to the plugin settings configuration.
	 *
	 * @since 1.0.0
	 * @param array $settings The existing settings configuration.
	 * @return array Modified settings with API keys tab added.
	 */
	public function register_settings_config( $settings ) {
		$api_keys_settings = array(
			'api-keys' => array(
				'tab_label' => __( 'API Keys', 'ielts-science-lms' ),
				'tab_type'  => 'api-keys',
				'settings'  => $this->get_api_keys_settings(),
			),
		);

		return array_merge( $settings, $api_keys_settings );
	}

	/**
	 * Get API Keys Settings
	 *
	 * Defines the configuration for various API key settings including
	 * Open Key AI, Open AI, Azure, Google, Huggingface, and Google Console.
	 *
	 * @since 1.0.0
	 * @return array The API keys settings configuration.
	 */
	protected function get_api_keys_settings() {
		$settings_config_instance = new \IeltsScienceLMS\Settings\Ieltssci_Settings_Config();
		return array(
			'lite-llm'       => array(
				'label'    => __( 'Lite LLM', 'ielts-science-lms' ),
				'multiKey' => false,
				'fields'   => array( $settings_config_instance->create_field( 'api-key', 'password', 'API Key', 'Enter your Lite LLM proxy API key.' ) ),
			),
			'open-ai'        => array(
				'label'    => __( 'Open AI', 'ielts-science-lms' ),
				'multiKey' => true,
				'fields'   => array( $settings_config_instance->create_field( 'api-key', 'password', 'API Key', 'Enter your Open AI API key.' ) ),
			),
			'vllm'           => array(
				'label'    => __( 'VLLM', 'ielts-science-lms' ),
				'multiKey' => true,
				'fields'   => array( $settings_config_instance->create_field( 'api-key', 'password', 'API Key', 'Enter your VLLM API key.' ) ),
			),
			'vllm2'          => array(
				'label'    => __( 'VLLM2', 'ielts-science-lms' ),
				'multiKey' => true,
				'fields'   => array( $settings_config_instance->create_field( 'api-key', 'password', 'API Key', 'Enter your VLLM2 API key.' ) ),
			),
			'slm'            => array(
				'label'    => __( 'SLM', 'ielts-science-lms' ),
				'multiKey' => true,
				'fields'   => array( $settings_config_instance->create_field( 'api-key', 'password', 'API Key', 'Enter your SLM API key.' ) ),
			),
			'azure'          => array(
				'label'    => __( 'Azure', 'ielts-science-lms' ),
				'multiKey' => false,
				'fields'   => array(
					$settings_config_instance->create_field(
						'api-key',
						'password',
						'API Key
				',
						'Enter your Azure API key.'
					),
					$settings_config_instance->create_field( 'resource-name', 'text', 'Resource name', 'Enter your Azure resource name.' ),
					$settings_config_instance->create_field( 'deployment-id', 'text', 'Deployment ID', 'Enter your Azure deployment ID.' ),
					$settings_config_instance->create_field( 'api-version', 'text', 'API Version', 'Enter your Azure API version.' ),
				),
			),
			'google'         => array(
				'label'    => __( 'Google', 'ielts-science-lms' ),
				'multiKey' => false,
				'fields'   => array( $settings_config_instance->create_field( 'api-key', 'password', 'API Key', 'Enter your Google API key.' ) ),
			),
			'huggingface'    => array(
				'label'    => __( 'Huggingface', 'ielts-science-lms' ),
				'multiKey' => false,
				'fields'   => array( $settings_config_instance->create_field( 'api-key', 'password', 'API Key', 'Enter your Huggingface API key.' ) ),
			),
			'google-console' => array(
				'label'    => __( 'Google Console', 'ielts-science-lms' ),
				'multiKey' => false,
				'fields'   => array(
					$settings_config_instance->create_field( 'api-key', 'password', 'API Key', 'Enter your Google Console API key.' ),
					$settings_config_instance->create_field( 'client-id', 'text', 'Client ID', 'Enter your Google Console client ID.' ),
				),
			),
			'facebook'       => array(
				'label'    => __( 'Facebook', 'ielts-science-lms' ),
				'multiKey' => false,
				'fields'   => array(
					$settings_config_instance->create_field( 'app-id', 'text', 'App ID', 'Enter your Facebook App ID.' ),
				),
			),
		);
	}
}
