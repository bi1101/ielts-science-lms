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
use IeltsScienceLMS\Writing\Ieltssci_Submission_DB as Writing_Submission_DB;
use IeltsScienceLMS\Speaking\Ieltssci_Submission_DB as Speaking_Submission_DB;
use IeltsScienceLMS\Speaking\Ieltssci_Speech_DB;

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
		$feed_data = $this->get_feed_data( $feed_id );

		// If no feed data or no rate limits, allow the action.
		if ( ! $feed_data || empty( $feed_data['rate_limits'] ) ) {
			return true;
		}

		// Get the details of the essay/speech and store relevant IDs.
		$content_details = $this->get_content_details( $action, $uuid, $current_user_id );
		$creator_id      = $content_details['creator_id'];
		$user_roles      = $content_details['user_roles'];
		$content_id      = $content_details['content_id'];

		// Extract feedback criteria from feed data.
		$feedback_criteria = isset( $feed_data['feedback_criteria'] ) ? $feed_data['feedback_criteria'] : 'general';

		// If this is a segment_feedback action, check if the essay already has segment feedback by this creator and criteria.
		// If so, allow the action as the essay's "slot" in the rate limit is already consumed.
		if ( 'segment_feedback' === $action && $content_id && $creator_id ) {
			$essay_db               = new Ieltssci_Essay_DB();
			$has_existing_for_essay = $essay_db->has_any_segment_feedback_for_essay(
				array(
					'essay_id'          => $content_id,
					'created_by'        => $creator_id,
					'feedback_criteria' => $feedback_criteria,
				)
			);
			if ( true === $has_existing_for_essay ) {
				return true; // Already counted, allow further segment feedbacks for this essay.
			}
		}

		// Check if feedback already exists for this feed and essay or speech.
		if ( $this->has_existing_feedback( $action, $uuid, $content_id, $feed_data, $segment_order, $feedback_criteria ) ) {
			return true;
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
		$applicable_limits = $this->filter_applicable_rate_limits( $feed_data['rate_limits'], $user_roles );

		// If no applicable rate limits found, allow the action.
		if ( empty( $applicable_limits ) ) {
			return true;
		}

		// Verify usage against applicable rate limits.
		$usage_verification = $this->verify_usage_against_limits( $action, $creator_id, $feedback_criteria, $applicable_limits );

		// If usage verification returns an error, return the error.
		if ( is_wp_error( $usage_verification ) ) {
			return $usage_verification;
		}

		// If we reach here, none of the rate limits were exceeded.
		return true;
	}

	/**
	 * Get content details for an essay/speech based on UUID
	 *
	 * Determines the creator ID, user roles, and content ID for the given action and UUID.
	 * Falls back to current user if content creator cannot be determined.
	 *
	 * @param string $action         The action being rate limited.
	 * @param string $uuid           The UUID of the essay/speech.
	 * @param int    $current_user_id The current user ID.
	 *
	 * @return array Array containing creator_id, user_roles, and content_id.
	 */
	private function get_content_details( $action, $uuid, $current_user_id ) {
		$creator_id = null;
		$user_roles = array();
		$content_id = null;

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
					$content_id = $essays[0]['id']; // Store essay ID for later use.

					// Get associated task submission if any.
					if ( isset( $essays[0]['id'] ) && $essays[0]['id'] ) {
						$submission_db   = new Writing_Submission_DB();
						$task_submission = $submission_db->get_task_submissions(
							array(
								'essay_id' => $essays[0]['id'],
							)
						);
						if ( is_wp_error( $task_submission ) ) {
							$task_submission = null;
						}
						// Use instructor ID from task submission as creator so that students can use their instructor's rate limits.
						if ( $task_submission && is_array( $task_submission ) && ! empty( $task_submission ) && isset( $task_submission[0]['id'] ) && ! empty( $task_submission[0]['id'] ) ) {
							$instructor_id = $submission_db->get_task_submission_meta( $task_submission[0]['id'], 'instructor_id', true );
							$creator_id    = $instructor_id ? (int) $instructor_id : $creator_id;
						}
					}
				}
			} elseif ( 'speech_feedback' === $action ) {
				// Include speech DB class.
				$speech_db = new Ieltssci_Speech_DB();

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
					$content_id = $speeches[0]['id'];

					// Get associated part submission if any.
					if ( isset( $speeches[0]['id'] ) && $speeches[0]['id'] ) {
						$submission_db   = new Speaking_Submission_DB();
						$part_submission = $submission_db->get_part_submissions(
							array(
								'speech_id' => $speeches[0]['id'],
							)
						);
						if ( is_wp_error( $part_submission ) ) {
							$part_submission = null;
						}
						// Use instructor ID from part submission as creator so that students can use their instructor's rate limits.
						if ( $part_submission && is_array( $part_submission ) && ! empty( $part_submission ) && isset( $part_submission[0]['id'] ) && ! empty( $part_submission[0]['id'] ) ) {
							$instructor_id = $submission_db->get_part_submission_meta( $part_submission[0]['id'], 'instructor_id', true );
							$creator_id    = $instructor_id ? (int) $instructor_id : $creator_id;
						}
					}
				}
			} elseif ( 'speech_attempt_feedback' === $action ) {
				// Handle speech attempt feedback.
				$submission_db = new Speaking_Submission_DB();

				// Extract attempt ID from UUID (format: 'attempt_X').
				$attempt_id = (int) str_replace( 'attempt_', '', $uuid );
				$attempt    = $submission_db->get_speech_attempt( $attempt_id );

				if ( ! is_wp_error( $attempt ) && ! empty( $attempt ) ) {
					$creator_id = $attempt['created_by'];
					$content_id = $attempt['id'];

					// Check for instructor ID in submission meta.
					if ( isset( $attempt['submission_id'] ) && $attempt['submission_id'] ) {
						$instructor_id = $submission_db->get_part_submission_meta( $attempt['submission_id'], 'instructor_id', true );
						$creator_id    = $instructor_id ? (int) $instructor_id : $creator_id;
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

		return array(
			'creator_id' => $creator_id,
			'user_roles' => $user_roles,
			'content_id' => $content_id,
		);
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

	/**
	 * Check if feedback already exists for essay or speech.
	 *
	 * @param string $action           The action being rate limited.
	 * @param string $uuid             The UUID of the essay or speech.
	 * @param int    $content_id       The ID of the essay or speech.
	 * @param array  $feed_data        The feed data.
	 * @param int    $segment_order    Optional. The segment order to check.
	 * @param string $feedback_criteria The feedback criteria.
	 *
	 * @return bool True if feedback already exists, false otherwise.
	 */
	private function has_existing_feedback( $action, $uuid, $content_id, $feed_data, $segment_order = null, $feedback_criteria = 'general' ) {
		// If apply_to is not set or UUID is empty, no existing feedback to check.
		if ( ! isset( $feed_data['apply_to'] ) || empty( $uuid ) ) {
			return false;
		}

		// For essay/writing-related actions.
		if ( in_array( $action, array( 'essay_feedback', 'segment_feedback' ) ) ) {
			// Initialize Essay DB.
			$essay_db = new Ieltssci_Essay_DB();

			if ( $content_id ) {
				// Check for existing feedback based on apply_to.
				switch ( $feed_data['apply_to'] ) {
					case 'essay':
						// Check if essay feedback already exists.
						$existing_feedback = $essay_db->get_essay_feedbacks(
							array(
								'essay_id'          => $content_id,
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
								'essay_id' => $content_id,
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
									'essay_id' => $content_id,
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
			$speech_db = new Ieltssci_Speech_DB();

			if ( $content_id ) {
				// Check if speech feedback already exists.
				$existing_feedback = $speech_db->get_speech_feedbacks(
					array(
						'speech_id'         => $content_id,
						'feedback_criteria' => $feedback_criteria,
						'number'            => 1,
					)
				);

				if ( ! is_wp_error( $existing_feedback ) && ! empty( $existing_feedback ) ) {
					// Feedback already exists, no need to check rate limits.
					return true;
				}
			}
		} elseif ( 'speech_attempt_feedback' === $action ) { // For speech attempt feedback.
			// Include speech DB class.
			$speech_db = new Ieltssci_Speech_DB();

			if ( $content_id ) {
				// Check if speech attempt feedback already exists.
				$existing_feedback = $speech_db->get_speech_attempt_feedbacks(
					array(
						'attempt_id'        => $content_id,
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

		return false;
	}

	/**
	 * Get feed data by feed ID.
	 *
	 * @param int $feed_id Feed ID for specific limits.
	 *
	 * @return array|null Feed data array or null if not found.
	 */
	private function get_feed_data( $feed_id ) {
		if ( empty( $feed_id ) ) {
			return null;
		}

		$api_feeds_db = new Ieltssci_ApiFeeds_DB();
		$feeds        = $api_feeds_db->get_api_feeds(
			array(
				'feed_id' => $feed_id,
				'include' => array( 'rate_limits' ),
				'limit'   => 1,
			)
		);

		if ( ! is_wp_error( $feeds ) && ! empty( $feeds ) ) {
			return $feeds[0];
		}

		return null;
	}

	/**
	 * Filter rate limits to find those applicable to the user.
	 *
	 * Determines which rate limits apply to the current user based on their roles.
	 *
	 * @param array $rate_limits All available rate limits from the feed data.
	 * @param array $user_roles  The user's roles.
	 *
	 * @return array Array of applicable rate limits.
	 */
	private function filter_applicable_rate_limits( $rate_limits, $user_roles ) {
		$applicable_limits = array();

		if ( ! is_array( $rate_limits ) ) {
			return $applicable_limits;
		}

		foreach ( $rate_limits as $rate_limit ) {
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

		return $applicable_limits;
	}

	/**
	 * Verify the usage against applicable rate limits.
	 *
	 * Checks if the current usage exceeds any of the applicable rate limits and returns the result.
	 *
	 * @param string $action           The action being rate limited.
	 * @param int    $creator_id       The ID of the content creator.
	 * @param string $feedback_criteria The feedback criteria being used.
	 * @param array  $applicable_limits The applicable rate limits to check against.
	 *
	 * @return WP_Error|true True if no limits exceeded, or WP_Error if a limit is exceeded.
	 */
	private function verify_usage_against_limits( $action, $creator_id, $feedback_criteria, $applicable_limits ) {
		// Initialize Essay DB for usage counting.
		$essay_db = new Ieltssci_Essay_DB();

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
				);

				// Add date constraints if set.
				if ( isset( $date_constraints['date_from'] ) ) {
					$query_args['date_from'] = $date_constraints['date_from'];
				}
				if ( isset( $date_constraints['date_to'] ) ) {
					$query_args['date_to'] = $date_constraints['date_to'];
				}

				$usage_count = $essay_db->count_distinct_essays_with_essay_feedback( $query_args );

				// Add current usage to the tracking array.
				$current_usage[ $limit['time_period_type'] ] = array(
					'count'       => is_wp_error( $usage_count ) ? 0 : $usage_count,
					'max_allowed' => $max_allowed,
					'type'        => 'essay_feedback',
				);

			} elseif ( 'segment_feedback' === $action ) {
				// Count distinct essays with segment feedbacks created by the content creator.
				$query_args = array(
					'created_by'        => $creator_id,
					'feedback_criteria' => $feedback_criteria,
					// 'count' is implicit in the new method.
				);

				// Add date constraints if set.
				if ( isset( $date_constraints['date_from'] ) ) {
					$query_args['date_from'] = $date_constraints['date_from'];
				}
				if ( isset( $date_constraints['date_to'] ) ) {
					$query_args['date_to'] = $date_constraints['date_to'];
				}

				$usage_count = $essay_db->count_distinct_essays_with_segment_feedback( $query_args );

				// Add current usage to the tracking array.
				$current_usage[ $limit['time_period_type'] ] = array(
					'count'       => is_wp_error( $usage_count ) ? 0 : $usage_count,
					'max_allowed' => $max_allowed,
					'type'        => 'segment_feedback',
				);
			} elseif ( 'speech_feedback' === $action ) {
				// Count speech feedbacks created by the content creator.
				$speech_db = new Ieltssci_Speech_DB();

				$query_args = array(
					'created_by'        => $creator_id,
					'feedback_criteria' => $feedback_criteria,
				);

				// Add date constraints if set.
				if ( isset( $date_constraints['date_from'] ) ) {
					$query_args['date_from'] = $date_constraints['date_from'];
				}
				if ( isset( $date_constraints['date_to'] ) ) {
					$query_args['date_to'] = $date_constraints['date_to'];
				}

				$usage_count = $speech_db->count_distinct_speech_with_speech_feedback( $query_args );

				// Add current usage to the tracking array.
				$current_usage[ $limit['time_period_type'] ] = array(
					'count'       => is_wp_error( $usage_count ) ? 0 : $usage_count,
					'max_allowed' => $max_allowed,
					'type'        => 'speech_feedback',
				);
			} elseif ( 'speech_attempt_feedback' === $action ) {
				// Count speech attempt feedbacks created by the content creator.
				$speech_db = new Ieltssci_Speech_DB();

				$query_args = array(
					'created_by'        => $creator_id,
					'feedback_criteria' => $feedback_criteria,
				);

				// Add date constraints if set.
				if ( isset( $date_constraints['date_from'] ) ) {
					$query_args['date_from'] = $date_constraints['date_from'];
				}
				if ( isset( $date_constraints['date_to'] ) ) {
					$query_args['date_to'] = $date_constraints['date_to'];
				}

				$usage_count = $speech_db->count_distinct_speech_attempts_with_attempt_feedback( $query_args );

				// Add current usage to the tracking array.
				$current_usage[ $limit['time_period_type'] ] = array(
					'count'       => is_wp_error( $usage_count ) ? 0 : $usage_count,
					'max_allowed' => $max_allowed,
					'type'        => 'speech_attempt_feedback',
				);
			}

			// Check if usage exceeds limit.
			if ( ! is_wp_error( $usage_count ) && ( ( $max_allowed > 0 && $usage_count >= $max_allowed ) || 0 === $max_allowed ) ) {
				$usage_exceeded = true;
				$exceeded_limit = $limit;
				break;
			}
		}

		// If usage exceeded, return error with details.
		if ( $usage_exceeded && $exceeded_limit ) {
			// Get current usage count and max allowed.
			$current_count = isset( $current_usage[ $exceeded_limit['time_period_type'] ] ) ?
				$current_usage[ $exceeded_limit['time_period_type'] ]['count'] : 0;
			$max_allowed   = isset( $exceeded_limit['rate_limit'] ) ? (int) $exceeded_limit['rate_limit'] : 0;

			// Process merge tags in the error messages.
			$merge_tags_processor = new \IeltsScienceLMS\MergeTags\Ieltssci_Merge_Tags_Processor();

			// Process message components.
			$title = isset( $exceeded_limit['message']['title'] ) ?
				$merge_tags_processor->process_rate_limit_message_tags(
					$exceeded_limit['message']['title'],
					$current_count,
					$max_allowed
				) : 'Too Many Requests';

			$message = isset( $exceeded_limit['message']['message'] ) ?
				$merge_tags_processor->process_rate_limit_message_tags(
					$exceeded_limit['message']['message'],
					$current_count,
					$max_allowed
				) : 'You have reached the limit for this feature. Please try again later.';

			$cta_title = isset( $exceeded_limit['message']['ctaTitle'] ) ?
				$merge_tags_processor->process_rate_limit_message_tags(
					$exceeded_limit['message']['ctaTitle'],
					$current_count,
					$max_allowed
				) : '';

			$cta_link = isset( $exceeded_limit['message']['ctaLink'] ) ?
				$merge_tags_processor->process_rate_limit_message_tags(
					$exceeded_limit['message']['ctaLink'],
					$current_count,
					$max_allowed
				) : '';

			return new WP_Error(
				'rate_limit_exceeded',
				$message,
				array(
					'status'        => 429,
					'title'         => $title,
					'message'       => $message,
					'cta_title'     => $cta_title,
					'cta_link'      => $cta_link,
					'limit_info'    => $exceeded_limit,
					'current_usage' => $current_usage,
				)
			);
		}

		// If we reach here, none of the rate limits were exceeded.
		return true;
	}
}
