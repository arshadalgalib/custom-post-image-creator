<?php
/**
 * Retry policy: error classification and exponential backoff.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Automation;

use CPIC\Security\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One place decides whether an error is retried and how long to wait.
 *
 * Remote APIs fail in three very different ways: transient (rate limit,
 * network glitch) — retry soon; credential (invalid token, revoked scope) —
 * do not hammer the API, ask a human; and semantic (duplicate post, rejected
 * media) — retrying can never help, and for Facebook it risks a duplicate
 * post. Every publisher maps its failures onto these buckets.
 */
final class RetryManager {

	/**
	 * Errors worth retrying automatically.
	 */
	const RETRYABLE = array(
		'fb_rate_limit',
		'fb_temporary',
		'fb_timeout',
		'fb_server_error',
		'tg_rate_limit',
		'tg_timeout',
		'tg_server_error',
		'network_error',
		'http_429',
		'http_500',
		'http_502',
		'http_503',
		'http_504',
		'artifact_missing',
		'render_failed',
		'db_busy',
		'queue_locked',
	);

	/**
	 * Errors that need a human, not another attempt.
	 */
	const TERMINAL = array(
		'fb_invalid_token',
		'fb_permission',
		'fb_duplicate_risk',
		'fb_unsupported_media',
		'fb_page_unavailable',
		'tg_invalid_token',
		'tg_chat_not_found',
		'tg_forbidden',
		'tg_bad_request',
		'cpic_not_png',
		'cpic_complex_script',
		'cpic_temp_render_unavailable',
		'account_disconnected',
		'invalid_destination',
		'content_rejected',
	);

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Backoff delay in seconds for a given attempt number.
	 *
	 * Attempt 1 waits `base`, then doubles, capped at `max`, with up to 20%
	 * jitter so a retry storm does not hit the API in lockstep.
	 *
	 * @param int $attempt Attempt number (1-based).
	 * @return int Seconds.
	 */
	public function delay_for( $attempt ) {
		$attempt = max( 1, (int) $attempt );

		$base = max( 1, $this->settings->int( 'cpic_retry_base_delay', 60 ) );
		$max  = max( $base, $this->settings->int( 'cpic_retry_max_delay', 3600 ) );

		$delay = (int) min( $max, $base * pow( 2, $attempt - 1 ) );

		// Deterministic-but-spread jitter (0–20%).
		$jitter = (int) round( $delay * 0.2 * ( wp_rand( 0, 1000 ) / 1000 ) );

		return (int) min( $max, $delay + $jitter );
	}

	/**
	 * Timestamp for the next attempt.
	 *
	 * @param int $attempt Attempt number (1-based).
	 * @return string MySQL datetime.
	 */
	public function next_attempt_at( $attempt ) {
		return gmdate( 'Y-m-d H:i:s', time() + $this->delay_for( $attempt ) );
	}

	/**
	 * Whether an error code should be retried.
	 *
	 * @param string $code Error code.
	 * @return bool
	 */
	public function is_retryable( $code ) {
		$code = (string) $code;

		if ( '' === $code ) {
			return false;
		}

		if ( in_array( $code, self::TERMINAL, true ) ) {
			return false;
		}

		/**
		 * Filter whether an error code is retryable.
		 *
		 * @param bool   $retryable Whether to retry.
		 * @param string $code      Error code.
		 */
		return (bool) apply_filters( 'cpic/retry/is_retryable', in_array( $code, self::RETRYABLE, true ), $code );
	}

	/**
	 * Whether an error is terminal (never retried).
	 *
	 * @param string $code Error code.
	 * @return bool
	 */
	public function is_terminal( $code ) {
		$code = (string) $code;

		if ( in_array( $code, self::TERMINAL, true ) ) {
			return true;
		}

		return ! $this->is_retryable( $code );
	}

	/**
	 * Human explanation for a failure code (surfaced in the UI).
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public function explain( $code ) {
		$messages = array(
			'fb_invalid_token'      => __( 'The Facebook page token is no longer valid. Reconnect the account.', 'custom-post-image-creator' ),
			'fb_permission'         => __( 'The Facebook app lacks the pages_manage_posts permission for this page.', 'custom-post-image-creator' ),
			'fb_duplicate_risk'     => __( 'Facebook may already have this post. Check the page before retrying.', 'custom-post-image-creator' ),
			'fb_rate_limit'         => __( 'Facebook rate limit reached. The post will be retried automatically.', 'custom-post-image-creator' ),
			'tg_invalid_token'      => __( 'The Telegram bot token was rejected. Update it in the account settings.', 'custom-post-image-creator' ),
			'tg_chat_not_found'     => __( 'The Telegram channel was not found, or the bot is not an admin there.', 'custom-post-image-creator' ),
			'cpic_complex_script'   => __( 'This text needs a browser renderer; the server cannot shape Bengali reliably.', 'custom-post-image-creator' ),
			'cpic_not_png'          => __( 'The renderer produced a file that is not a PNG.', 'custom-post-image-creator' ),
			'account_disconnected'  => __( 'The destination is disconnected.', 'custom-post-image-creator' ),
			'artifact_missing'      => __( 'The rendered image is missing from disk; it will be rendered again.', 'custom-post-image-creator' ),
		);

		/**
		 * Filter the user-facing explanation for an error code.
		 *
		 * @param string $message Message.
		 * @param string $code    Code.
		 */
		$message = isset( $messages[ $code ] )
			? $messages[ $code ]
			: __( 'The delivery failed and will be retried.', 'custom-post-image-creator' );

		return (string) apply_filters( 'cpic/retry/explain', $message, $code );
	}

	/**
	 * Remaining attempts for a row.
	 *
	 * @param int $attempts     Attempts used.
	 * @param int $max_attempts Attempt limit.
	 * @return int
	 */
	public function remaining( $attempts, $max_attempts ) {
		return max( 0, (int) $max_attempts - (int) $attempts );
	}

	/**
	 * Maximum attempts allowed, from settings.
	 *
	 * @return int
	 */
	public function max_attempts() {
		return max( 1, min( 20, $this->settings->int( 'cpic_max_retries', 5 ) ) );
	}
}
