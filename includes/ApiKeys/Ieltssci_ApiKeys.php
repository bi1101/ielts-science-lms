<?php
/**
 * API Keys main class
 *
 * @package IELTS_Science_LMS
 * @subpackage ApiKeys
 * @since 1.0.0
 */

namespace IeltsScienceLMS\ApiKeys;

/**
 * Class Ieltssci_ApiKeys
 *
 * Main class for handling API Keys functionality
 *
 * @package IELTS_Science_LMS\ApiKeys
 */
class Ieltssci_ApiKeys {
	/**
	 * Constructor
	 *
	 * Initialize API Keys components
	 */
	public function __construct() {
		new Ieltssci_ApiKeys_REST();
		new Ieltssci_ApiKeys_Settings();
		new Ieltssci_ApiKeys_DB();
	}
}
