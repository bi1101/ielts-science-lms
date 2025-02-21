<?php

namespace IeltsScienceLMS\ApiKeys;

class Ieltssci_ApiKeys_Settings {
	public function __construct() {
		add_filter( 'ieltssci_settings_config', [ $this, 'register_settings_config' ] );
	}

	public function register_settings_config( $settings ) {
		$api_keys_settings = [ 
			'api-keys' => [ 
				'tab_label' => __( 'API Keys', 'ielts-science-lms' ),
				'tab_type' => 'api-keys',
				'settings' => $this->get_api_keys_settings(),
			],
		];

		return array_merge( $settings, $api_keys_settings );
	}

	protected function get_api_keys_settings() {
		$settingsConfigInstance = new \IeltsScienceLMS\Settings\Ieltssci_Settings_Config();
		return [ 
			'open-key-ai' => [ 
				'label' => __( 'Open Key AI', 'ielts-science-lms' ),
				'multiKey' => true,
				'fields' => [ $settingsConfigInstance->createField( 'api-key', 'password', 'API Key', 'Enter your Open Key AI API key.' ) ]
			],
			'open-ai' => [ 
				'label' => __( 'Open AI', 'ielts-science-lms' ),
				'multiKey' => true,
				'fields' => [ $settingsConfigInstance->createField( 'api-key', 'password', 'API Key', 'Enter your Open AI API key.' ) ]
			],
			'azure' => [ 
				'label' => __( 'Azure', 'ielts-science-lms' ),
				'multiKey' => false,
				'fields' => [ 
					$settingsConfigInstance->createField( 'api-key', 'password', 'API Key
				', 'Enter your Azure API key.' ),
					$settingsConfigInstance->createField( 'resource-name', 'text', 'Resource name', 'Enter your Azure resource name.' ),
					$settingsConfigInstance->createField( 'deployment-id', 'text', 'Deployment ID', 'Enter your Azure deployment ID.' ),
					$settingsConfigInstance->createField( 'api-version', 'text', 'API Version', 'Enter your Azure API version.' ),
				]
			],
			'google' => [ 
				'label' => __( 'Google', 'ielts-science-lms' ),
				'multiKey' => false,
				'fields' => [ $settingsConfigInstance->createField( 'api-key', 'password', 'API Key', 'Enter your Google API key.' ) ]
			],
			'huggingface' => [ 
				'label' => __( 'Huggingface', 'ielts-science-lms' ),
				'multiKey' => false,
				'fields' => [ $settingsConfigInstance->createField( 'api-key', 'password', 'API Key', 'Enter your Huggingface API key.' ) ]
			],
		];
	}
}
