<?php
/**
 * IELTS Science LMS Rate Limit Settings
 *
 * @package IELTS_Science_LMS
 * @subpackage RateLimits
 * @since 1.0.0
 */

namespace IeltsScienceLMS\RateLimits;

/**
 * Class for managing rate limit settings in the IELTS Science LMS plugin.
 *
 * This class handles the registration and configuration of rate limit settings.
 *
 * @since 1.0.0
 */
class Ieltssci_RateLimit_Settings {
	/**
	 * Constructor.
	 *
	 * Hooks into the settings configuration filter.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_filter( 'ieltssci_settings_config', array( $this, 'register_settings_config' ) );
	}

	/**
	 * Register rate limit settings configuration.
	 *
	 * Adds the rate limit tab to the settings configuration.
	 *
	 * @since 1.0.0
	 * @param array $settings The existing settings configuration.
	 * @return array Modified settings configuration with rate limit settings.
	 */
	public function register_settings_config( $settings ) {
		$rate_limit_settings = array(
			'rate-limit' => array(
				'tab_label' => __( 'Rate Limits', 'ielts-science-lms' ),
				'tab_type'  => 'rate-limits',
			),
		);

		return array_merge( $settings, $rate_limit_settings );
	}
}
