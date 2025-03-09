<?php
/**
 * API Feed Module
 *
 * Handles the initialization of API feed functionality.
 *
 * @package IeltsScienceLMS
 * @subpackage ApiFeeds
 */

namespace IeltsScienceLMS\ApiFeeds;

/**
 * API Feed Module Class
 *
 * Main class that initializes API feed components.
 */
class Ieltssci_ApiFeed_Module {
	/**
	 * Constructor
	 *
	 * Initializes the REST API and Database components.
	 */
	public function __construct() {
		new Ieltssci_ApiFeeds_REST();
		new Ieltssci_ApiFeeds_DB();
	}
}
