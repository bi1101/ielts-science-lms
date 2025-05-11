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

use WP_Error;
use IeltsScienceLMS\Writing\Ieltssci_Essay_DB;
use IeltsScienceLMS\ApiFeeds\Ieltssci_ApiFeeds_DB;

/**
 * Main Rate Limit Module Class
 *
 * Handles the initialization of rate limiting components.
 *
 * @package IELTS_Science_LMS
 * @subpackage RateLimits
 * @since 1.0.0
 */
class Ieltssci_RateLimit {
	/**
	 * Constructor
	 *
	 * Initializes the rate limit settings and REST API handling.
	 */
	public function __construct() {
		new Ieltssci_RateLimit_Settings();
		new Ieltssci_RateLimit_REST();
	}

	/**
	 * Check rate limit for an action
	 *
	 * Uses the feed ID to get feed data and checks the rate limit based on action and the user who created the essay/speech.
	 *
	 * @param string $action       The action being rate limited (e.g., 'essay_feedback').
	 * @param string $uuid         The UUID of the essay or speech.
	 * @param number $feed_id      Feed ID for specific limits.
	 * @param int    $segment_order Optional. The segment order to check.
	 *
	 * @return WP_Error|true Returns a 429 Too Many Requests error if the rate limit is exceeded, or true if the rate limit is not exceeded.
	 */
	public function check_rate_limit( $action, $uuid, $feed_id, $segment_order = null ) {
		$current_user_id = get_current_user_id();
		$creator_id      = null;
		$user_roles      = array();

		// Get the feed data using the feed_id.
		$api_feeds_db = new Ieltssci_ApiFeeds_DB();
		$feed_data    = null;

		if ( $feed_id ) {
			$feeds = $api_feeds_db->get_api_feeds(
				array(
					'feed_id' => $feed_id,
					'include' => array( 'rate_limits' ),
					'limit'   => 1,
				)
			);

			if ( ! is_wp_error( $feeds ) && ! empty( $feeds ) ) {
				$feed_data = $feeds[0];
			}
		}

		// If no feed data or no rate limits, allow the action.
		if ( ! $feed_data || empty( $feed_data['rate_limits'] ) ) {
			return true;
		}

		// Get the creator of the essay/speech and store relevant IDs.
		$essay_id = null;
		if ( ! empty( $uuid ) ) {
			if ( in_array( $action, array( 'essay_feedback', 'segment_feedback' ) ) ) {
				// Initialize Essay DB.
				$essay_db = new Ieltssci_Essay_DB();

				// Get essay data from UUID, including creator ID.
				$essays = $essay_db->get_essays(
					array(
						'uuid'     => $uuid,
						'per_page' => 1,
						'fields'   => array( 'id', 'created_by' ),
					)
				);

				if ( ! is_wp_error( $essays ) && ! empty( $essays ) ) {
					$creator_id = $essays[0]['created_by'];
					$essay_id   = $essays[0]['id']; // Store essay ID for later use.
				}
			} elseif ( 'speech_feedback' === $action ) {
				// Include speech DB class.
				if ( class_exists( '\IeltsScienceLMS\Speaking\Ieltssci_Speech_DB' ) ) {
					$speech_db = new \IeltsScienceLMS\Speaking\Ieltssci_Speech_DB();

					// Get speech data from UUID, including creator ID.
					$speeches = $speech_db->get_speeches(
						array(
							'uuid'     => $uuid,
							'per_page' => 1,
							'fields'   => array( 'id', 'created_by' ),
						)
					);

					if ( ! is_wp_error( $speeches ) && ! empty( $speeches ) ) {
						$creator_id = $speeches[0]['created_by'];
					}
				}
			}

			// If we found a creator ID, get their user roles.
			if ( $creator_id ) {
				$creator = get_userdata( $creator_id );
				if ( $creator ) {
					$user_roles = $creator->roles;
				}
			} else {
				// If creator ID not found, default to current user.
				$creator_id = $current_user_id;
				$creator    = get_userdata( $creator_id );
				if ( $creator ) {
					$user_roles = $creator->roles;
				}
			}
		} else {
			// If no UUID provided, default to current user.
			$creator_id = $current_user_id;
			$creator    = get_userdata( $creator_id );
			if ( $creator ) {
				$user_roles = $creator->roles;
			}
		}

		// Extract feedback criteria from feed data.
		$feedback_criteria = isset( $feed_data['feedback_criteria'] ) ? $feed_data['feedback_criteria'] : 'general';

		// Check if feedback already exists for this feed and essay or speech.
		if ( isset( $feed_data['apply_to'] ) && ! empty( $uuid ) ) {
			// For essay/writing-related actions.
			if ( in_array( $action, array( 'essay_feedback', 'segment_feedback' ) ) ) {
				// Initialize Essay DB.
				$essay_db = new Ieltssci_Essay_DB();

				// Get essay ID from UUID.
				$essays = $essay_db->get_essays(
					array(
						'uuid'     => $uuid,
						'per_page' => 1,
						'fields'   => array( 'id' ),
					)
				);

				if ( ! is_wp_error( $essays ) && ! empty( $essays ) ) {
					$essay_id          = $essays[0]['id'];
					$feedback_criteria = isset( $feed_data['feedback_criteria'] ) ? $feed_data['feedback_criteria'] : 'general';

					// Check for existing feedback based on apply_to.
					switch ( $feed_data['apply_to'] ) {
						case 'essay':
							// Check if essay feedback already exists.
							$existing_feedback = $essay_db->get_essay_feedbacks(
								array(
									'essay_id'          => $essay_id,
									'feedback_criteria' => $feedback_criteria,
									'per_page'          => 1,
								)
							);

							if ( ! is_wp_error( $existing_feedback ) && ! empty( $existing_feedback ) ) {
								// Feedback already exists, no need to check rate limits.
								return true;
							}
							break;

						case 'paragraph':
							// Check if segments already exist for this essay.
							$existing_segments = $essay_db->get_segments(
								array(
									'essay_id' => $essay_id,
									'per_page' => 1,
								)
							);

							if ( ! is_wp_error( $existing_segments ) && ! empty( $existing_segments ) ) {
								// Segments already exist, no need to check rate limits.
								return true;
							}
							break;

						case 'introduction':
						case 'topic-sentence':
						case 'main-point':
						case 'conclusion':
							// Check specific segment feedback if segment_order is provided.
							if ( null !== $segment_order ) {
								// First get the segment with the specified order.
								$segment = $essay_db->get_segments(
									array(
										'essay_id' => $essay_id,
										'order'    => $segment_order,
										'per_page' => 1,
									)
								);

								if ( ! is_wp_error( $segment ) && ! empty( $segment ) ) {
									// Now check if feedback exists for this segment.
									$existing_feedback = $essay_db->get_segment_feedbacks(
										array(
											'segment_id' => $segment[0]['id'],
											'feedback_criteria' => $feedback_criteria,
											'per_page'   => 1,
										)
									);

									if ( ! is_wp_error( $existing_feedback ) && ! empty( $existing_feedback ) ) {
										// Feedback already exists for this segment, no need to check rate limits.
										return true;
									}
								}
							}
							break;
					}
				}
			} elseif ( 'speech_feedback' === $action ) { // For speech/speaking-related actions.
				// Include speech DB class.
				if ( class_exists( '\IeltsScienceLMS\Speaking\Ieltssci_Speech_DB' ) ) {
					$speech_db = new \IeltsScienceLMS\Speaking\Ieltssci_Speech_DB();

					// Get speech ID from UUID.
					$speeches = $speech_db->get_speeches(
						array(
							'uuid'     => $uuid,
							'per_page' => 1,
						)
					);

					if ( ! is_wp_error( $speeches ) && ! empty( $speeches ) ) {
						$speech_id = $speeches[0]['id'];

						// Check if speech feedback already exists.
						$existing_feedback = $speech_db->get_speech_feedbacks(
							array(
								'speech_id'         => $speech_id,
								'feedback_criteria' => $feedback_criteria,
								'number'            => 1,
							)
						);

						if ( ! is_wp_error( $existing_feedback ) && ! empty( $existing_feedback ) ) {
							// Feedback already exists, no need to check rate limits.
							return true;
						}
					}
				}
			}
		}

		// If use is logged out, return 401 error.
		if ( empty( $current_user_id ) ) {
			return new WP_Error(
				'unauthorized',
				'You must be logged in to use this feature.',
				array(
					'status'  => 401,
					'title'   => 'Unauthorized',
					'message' => 'You must be logged in to use this feature.',
				)
			);
		}

		// Get applicable rate limits for the creator of the content.
		$applicable_limits = array();

		if ( isset( $feed_data['rate_limits'] ) && is_array( $feed_data['rate_limits'] ) ) {
			foreach ( $feed_data['rate_limits'] as $rate_limit ) {

				// Check if any of the user's roles are in the rate limit roles.
				$applies_to_user = false;
				if ( isset( $rate_limit['roles'] ) && is_array( $rate_limit['roles'] ) ) {
					foreach ( $user_roles as $user_role ) {
						if ( in_array( $user_role, $rate_limit['roles'], true ) ) {
							$applies_to_user = true;

							break;
						}
					}

					// If no user role matches or user has no roles but 'subscriber' is in the limit roles,
					// consider the rate limit applicable to logged-out users.
					if ( ( empty( $user_roles ) && ! $applies_to_user ) && in_array( 'subscriber', $rate_limit['roles'], true ) ) {
						$applies_to_user = true;

					}
				}

				if ( $applies_to_user ) {
					// Add the relevant rate limit information.
					$applicable_limits[] = array(
						'rate_limit'       => $rate_limit['rate_limit'],
						'time_period_type' => $rate_limit['time_period_type'],
						'limit_rule'       => $rate_limit['limit_rule'],
						'message'          => $rate_limit['message'],
					);
				}
			}
		}

		// If no applicable rate limits found, allow the action.
		if ( empty( $applicable_limits ) ) {
			return true;
		}

		// Initialize Essay DB for usage counting if not already initialized.
		if ( ! isset( $essay_db ) ) {
			$essay_db = new Ieltssci_Essay_DB();
		}

		// Track usage and check limits.
		$usage_exceeded = false;
		$exceeded_limit = null; // The limit rule that was exceeded.
		$current_usage  = array();

		foreach ( $applicable_limits as $limit ) {
			// Get date constraints based on time period type.
			$date_constraints = $this->get_date_constraints( $limit['time_period_type'], $limit['limit_rule'] );
			$max_allowed      = isset( $limit['rate_limit'] ) ? (int) $limit['rate_limit'] : 0;

			// Count usage based on action type.
			$usage_count = 0;

			if ( 'essay_feedback' === $action ) {
				// Count essay feedbacks created by the content creator.
				$query_args = array(
					'created_by'        => $creator_id,
					'feedback_criteria' => $feedback_criteria,
					'count'             => true,
				);

				// Add date constraints if set.
				if ( isset( $date_constraints['date_from'] ) ) {
					$query_args['date_from'] = $date_constraints['date_from'];
				}
				if ( isset( $date_constraints['date_to'] ) ) {
					$query_args['date_to'] = $date_constraints['date_to'];
				}

				$usage_count = $essay_db->get_essay_feedbacks( $query_args );

				// Add current usage to the tracking array.
				$current_usage[ $limit['time_period_type'] ] = array(
					'count'       => is_wp_error( $usage_count ) ? 0 : $usage_count,
					'max_allowed' => $max_allowed,
					'type'        => 'essay_feedback',
				);

			} elseif ( 'segment_feedback' === $action ) {
				// Count segment feedbacks created by the content creator.
				$query_args = array(
					'created_by'        => $creator_id,
					'feedback_criteria' => $feedback_criteria,
					'count'             => true,
				);

				// Add date constraints if set.
				if ( isset( $date_constraints['date_from'] ) ) {
					$query_args['date_from'] = $date_constraints['date_from'];
				}
				if ( isset( $date_constraints['date_to'] ) ) {
					$query_args['date_to'] = $date_constraints['date_to'];
				}

				$usage_count = $essay_db->get_segment_feedbacks( $query_args );

				// Add current usage to the tracking array.
				$current_usage[ $limit['time_period_type'] ] = array(
					'count'       => is_wp_error( $usage_count ) ? 0 : $usage_count,
					'max_allowed' => $max_allowed,
					'type'        => 'segment_feedback',
				);
			} elseif ( 'speech_feedback' === $action ) {
				// Count speech feedbacks created by the content creator.
				if ( class_exists( '\IeltsScienceLMS\Speaking\Ieltssci_Speech_DB' ) ) {
					$speech_db = new \IeltsScienceLMS\Speaking\Ieltssci_Speech_DB();

					$query_args = array(
						'created_by'        => $creator_id,
						'feedback_criteria' => $feedback_criteria,
						'count'             => true,
					);

					// Add date constraints if set.
					if ( isset( $date_constraints['date_from'] ) ) {
						$query_args['date_from'] = $date_constraints['date_from'];
					}
					if ( isset( $date_constraints['date_to'] ) ) {
						$query_args['date_to'] = $date_constraints['date_to'];
					}

					$usage_count = $speech_db->get_speech_feedbacks( $query_args );

					// Add current usage to the tracking array.
					$current_usage[ $limit['time_period_type'] ] = array(
						'count'       => is_wp_error( $usage_count ) ? 0 : $usage_count,
						'max_allowed' => $max_allowed,
						'type'        => 'speech_feedback',
					);
				}
			}

			// Check if usage exceeds limit.
			if ( ! is_wp_error( $usage_count ) && $max_allowed > 0 && $usage_count >= $max_allowed ) {
				$usage_exceeded = true;
				$exceeded_limit = $limit;
				break;
			}
		}

		// If usage exceeded, return error with details.
		if ( $usage_exceeded && $exceeded_limit ) {
			return new WP_Error(
				'rate_limit_exceeded',
				$exceeded_limit['message']['message'] ?? 'You have reached the limit for this feature. Please try again later.',
				array(
					'status'        => 429,
					'title'         => $exceeded_limit['message']['title'] ?? 'Too Many Requests',
					'message'       => $exceeded_limit['message']['message'] ?? 'You have reached the limit for this feature. Please try again later.',
					'cta_title'     => $exceeded_limit['message']['ctaTitle'] ?? '',
					'cta_link'      => $exceeded_limit['message']['ctaLink'] ?? '',
					'limit_info'    => $exceeded_limit,
					'current_usage' => $current_usage,
				)
			);
		}

		// If we reach here, none of the rate limits were exceeded.
		return true;
	}

	/**
	 * Get date constraints based on time period type
	 *
	 * @param string $time_period_type The type of time period.
	 * @param array  $limit_rule       The limit rule with unit and count.
	 *
	 * @return array Date constraints with 'date_from' and 'date_to' keys.
	 */
	private function get_date_constraints( $time_period_type, $limit_rule ) {
		$constraints = array(
			'date_from' => null,
			'date_to'   => null,
		);

		// Current time.
		$now = current_time( 'mysql' );

		switch ( $time_period_type ) {
			case 'forever':
				// No date constraints for 'forever'.
				break;

			case 'time_period':
				// Calculate date from based on the time period.
				if ( isset( $limit_rule['unit'] ) && isset( $limit_rule['count'] ) ) {
					$unit  = $limit_rule['unit'];
					$count = (int) $limit_rule['count'];

					// Create date interval based on unit and count.
					switch ( $unit ) {
						case 'seconds':
							$constraints['date_from'] = gmdate( 'Y-m-d H:i:s', strtotime( "-$count seconds" ) );
							break;
						case 'minutes':
							$constraints['date_from'] = gmdate( 'Y-m-d H:i:s', strtotime( "-$count minutes" ) );
							break;
						case 'hours':
							$constraints['date_from'] = gmdate( 'Y-m-d H:i:s', strtotime( "-$count hours" ) );
							break;
						case 'days':
							$constraints['date_from'] = gmdate( 'Y-m-d H:i:s', strtotime( "-$count days" ) );
							break;
						case 'weeks':
							$constraints['date_from'] = gmdate( 'Y-m-d H:i:s', strtotime( "-$count weeks" ) );
							break;
						case 'months':
							$constraints['date_from'] = gmdate( 'Y-m-d H:i:s', strtotime( "-$count months" ) );
							break;
						case 'years':
							$constraints['date_from'] = gmdate( 'Y-m-d H:i:s', strtotime( "-$count years" ) );
							break;
					}

					$constraints['date_to'] = $now;
				}
				break;

			case 'calendar_period':
				// Calculate start of current period.
				if ( isset( $limit_rule['period'] ) ) {
					$period = $limit_rule['period'];

					switch ( $period ) {
						case 'day':
							$constraints['date_from'] = gmdate( 'Y-m-d 00:00:00' ); // Start of today.
							break;
						case 'week':
							// Get start of current week (Monday).
							$constraints['date_from'] = gmdate( 'Y-m-d 00:00:00', strtotime( 'this week Monday' ) );
							break;
						case 'month':
							$constraints['date_from'] = gmdate( 'Y-m-01 00:00:00' ); // Start of month.
							break;
						case 'year':
							$constraints['date_from'] = gmdate( 'Y-01-01 00:00:00' ); // Start of year.
							break;
					}

					$constraints['date_to'] = $now;
				}
				break;
		}

		return $constraints;
	}
}
