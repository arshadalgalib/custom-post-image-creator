<?php
/**
 * Simple transient-based rate limiter for expensive operations.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Protects rendering, publishing, AI calls and imports from abusive loops.
 */
final class RateLimiter {

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
	 * Default windows per action: [ limit, window seconds ].
	 *
	 * @return array<string,array{0:int,1:int}>
	 */
	private function windows() {
		$windows = array(
			'render'          => array( 60, 60 ),
			'publish'         => array( 30, 60 ),
			'ai'              => array( 20, 60 ),
			'import'          => array( 30, 300 ),
			'upload'          => array( 40, 60 ),
			'oauth'           => array( 10, 300 ),
			'bulk'            => array( 5, 300 ),
			'queue_run'       => array( 30, 60 ),
		);

		/**
		 * Filter rate limit windows.
		 *
		 * @param array<string,array{0:int,1:int}> $windows Windows.
		 */
		return apply_filters( 'cpic/rate_limits', $windows );
	}

	/**
	 * Attempt an action.
	 *
	 * @param string $action  Action key.
	 * @param string $context Extra scope (post id, user id...).
	 * @return bool True when allowed.
	 */
	public function allow( $action, $context = '' ) {
		$windows = $this->windows();
		if ( ! isset( $windows[ $action ] ) ) {
			return true;
		}

		list( $limit, $window ) = $windows[ $action ];

		// Administrators with manage_options bypass the limiter, unless debugging.
		if ( current_user_can( 'manage_options' ) && ! $this->settings->is_enabled( 'cpic_debug' ) ) {
			return true;
		}

		$key   = 'cpic_rl_' . md5( $action . '|' . $context . '|' . get_current_user_id() . '|' . $this->client_ip() );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * Remaining calls (diagnostics).
	 *
	 * @param string $action  Action.
	 * @param string $context Context.
	 * @return int
	 */
	public function remaining( $action, $context = '' ) {
		$windows = $this->windows();
		if ( ! isset( $windows[ $action ] ) ) {
			return PHP_INT_MAX;
		}

		$key = 'cpic_rl_' . md5( $action . '|' . $context . '|' . get_current_user_id() . '|' . $this->client_ip() );

		return max( 0, $windows[ $action ][0] - (int) get_transient( $key ) );
	}

	/**
	 * Hashed client IP (never stored raw).
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' === $ip ? 'unknown' : substr( md5( $ip . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) ), 0, 12 );
	}

	/**
	 * Message for a throttled request.
	 *
	 * @return string
	 */
	public function message() {
		return __( 'Too many requests. Please wait a moment and try again.', 'custom-post-image-creator' );
	}
}
