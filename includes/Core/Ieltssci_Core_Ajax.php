<?php
/**
 * AJAX Handler for IELTS Science Writing Module
 *
 * This file contains the implementation of AJAX endpoints for the Writing Module.
 *
 * @package IeltsScienceLMS\Writing
 */

namespace IeltsScienceLMS\Core;

/**
 * Class Ieltssci_Writing_Ajax
 *
 * Handles AJAX requests for the IELTS Science Writing Module.
 */
class Ieltssci_Core_Ajax {

	/**
	 * Constructor for the Ieltssci_Writing_Ajax class.
	 */
	public function __construct() {
		// Register AJAX endpoints.
		add_action( 'wp_ajax_nopriv_ielts_science_login', array( $this, 'handle_login' ) );
		add_action( 'wp_ajax_ielts_science_login', array( $this, 'handle_login' ) );
	}

	/**
	 * Handle AJAX login requests
	 */
	public function handle_login() {
		// Check nonce for security.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wp_rest' ) ) {
			wp_send_json_error(
				array(
					'message' => 'Security check failed',
				),
				403
			);
			exit;
		}

		// Get login credentials.
		$username = sanitize_user( wp_unslash( $_POST['username'] ?? '' ) );
		$password = isset( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : '';
		$remember = isset( $_POST['remember'] ) ? (bool) sanitize_text_field( wp_unslash( $_POST['remember'] ) ) : false;

		// Validate required fields.
		if ( empty( $username ) || empty( $password ) ) {
			wp_send_json_error(
				array(
					'message' => 'Username and password are required',
				),
				400
			);
			exit;
		}

		// Attempt to sign the user in.
		$credentials = array(
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => $remember,
		);

		$user = wp_signon( $credentials, is_ssl() );

		// Check if the login was successful.
		if ( is_wp_error( $user ) ) {
			wp_send_json_error(
				array(
					'message' => 'Login failed. Please check your credentials.',
					'error'   => $user->get_error_message(),
				),
				401
			);
			exit;
		}

		// Get user data to return.
		$user_data = array(
			'ID'           => $user->ID,
			'display_name' => $user->display_name,
			'user_email'   => $user->user_email,
			'avatar'       => get_avatar_url( $user->ID, array( 'size' => 100 ) ),
		);

		// Add BuddyBoss specific data if available.
		if ( function_exists( 'bp_core_get_user_domain' ) ) {
			$user_data['profile_url'] = bp_core_get_user_domain( $user->ID );
		}

		// Login successful.
		wp_send_json_success(
			array(
				'message'   => 'Login successful',
				'user_data' => $user_data,
			)
		);
		exit;
	}
}
