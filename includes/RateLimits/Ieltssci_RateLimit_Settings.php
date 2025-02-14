<?php

namespace IeltsScienceLMS\RateLimits;

class Ieltssci_RateLimit_Settings {
	public function __construct() {
		add_filter( 'ieltssci_settings_config', [ $this, 'register_settings_config' ] );
	}

	public function register_settings_config( $settings ) {
		$rate_limit_settings = [ 
			'rate-limit' => [ 
				'tab_label' => __( 'Rate Limits', 'ielts-science-lms' ),
				'tab_type' => 'rate-limits',
			],
		];

		return array_merge( $settings, $rate_limit_settings );
	}
}