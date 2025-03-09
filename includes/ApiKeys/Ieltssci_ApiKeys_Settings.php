<?php

namespace IeltsScienceLMS\ApiKeys;

class Ieltssci_ApiKeys_Settings {
	public function __construct() {
		add_filter( 'ieltssci_settings_config', array( $this, 'register_settings_config' ) );
	}

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

	protected function get_api_keys_settings() {
		$settingsConfigInstance = new \IeltsScienceLMS\Settings\Ieltssci_Settings_Config();
		return array(
			'open-key-ai'    => array(
				'label'    => __( 'Open Key AI', 'ielts-science-lms' ),
				'multiKey' => true,
				'fields'   => array( $settingsConfigInstance->createField( 'api-key', 'password', 'API Key', 'Enter your Open Key AI API key.' ) ),
			),
			'open-ai'        => array(
				'label'    => __( 'Open AI', 'ielts-science-lms' ),
				'multiKey' => true,
				'fields'   => array( $settingsConfigInstance->createField( 'api-key', 'password', 'API Key', 'Enter your Open AI API key.' ) ),
			),
			'azure'          => array(
				'label'    => __( 'Azure', 'ielts-science-lms' ),
				'multiKey' => false,
				'fields'   => array(
					$settingsConfigInstance->createField(
						'api-key',
						'password',
						'API Key
				',
						'Enter your Azure API key.'
					),
					$settingsConfigInstance->createField( 'resource-name', 'text', 'Resource name', 'Enter your Azure resource name.' ),
					$settingsConfigInstance->createField( 'deployment-id', 'text', 'Deployment ID', 'Enter your Azure deployment ID.' ),
					$settingsConfigInstance->createField( 'api-version', 'text', 'API Version', 'Enter your Azure API version.' ),
				),
			),
			'google'         => array(
				'label'    => __( 'Google', 'ielts-science-lms' ),
				'multiKey' => false,
				'fields'   => array( $settingsConfigInstance->createField( 'api-key', 'password', 'API Key', 'Enter your Google API key.' ) ),
			),
			'huggingface'    => array(
				'label'    => __( 'Huggingface', 'ielts-science-lms' ),
				'multiKey' => false,
				'fields'   => array( $settingsConfigInstance->createField( 'api-key', 'password', 'API Key', 'Enter your Huggingface API key.' ) ),
			),
			'google-console' => array(
				'label'    => __( 'Google Console', 'ielts-science-lms' ),
				'multiKey' => false,
				'fields'   => array(
					$settingsConfigInstance->createField( 'api-key', 'password', 'API Key', 'Enter your Google Console API key.' ),
					$settingsConfigInstance->createField( 'client-id', 'text', 'Client ID', 'Enter your Google Console client ID.' ),
				),
			),
		);
	}
}
