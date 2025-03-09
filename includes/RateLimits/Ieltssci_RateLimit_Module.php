<?php
/**
 * Rate Limit Module
 *
 * Initializes the rate limiting functionality for the IELTS Science LMS.
 *
 * @package IELTS_Science_LMS
 * @subpackage RateLimits
 * @since 1.0.0
 */

namespace IeltsScienceLMS\RateLimits;

/**
 * Main Rate Limit Module Class
 *
 * Handles the initialization of rate limiting components.
 *
 * @package IELTS_Science_LMS
 * @subpackage RateLimits
 * @since 1.0.0
 */
class Ieltssci_RateLimit_Module {
	/**
	 * Constructor
	 *
	 * Initializes the rate limit settings and REST API handling.
	 */
	public function __construct() {
		new Ieltssci_RateLimit_Settings();
		new Ieltssci_RateLimit_REST();
	}
}
