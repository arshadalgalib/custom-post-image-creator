<?php
/**
 * Immutable result of one publish attempt.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Publishing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object returned by every publisher.
 *
 * Distinguishing "definitely not created" from "unknown" matters: re-posting to
 * Facebook after a timeout can duplicate a post on the page. A publisher must
 * return `maybe_created` when it cannot prove the post was not published.
 */
final class PublishResult {

	/**
	 * Whether the remote created the post.
	 *
	 * @var bool
	 */
	private $success;

	/**
	 * Remote id.
	 *
	 * @var string
	 */
	private $remote_id;

	/**
	 * Remote URL.
	 *
	 * @var string
	 */
	private $remote_url;

	/**
	 * Error code.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Error message.
	 *
	 * @var string
	 */
	private $error_message;

	/**
	 * Whether the remote *may* have created the post.
	 *
	 * @var bool
	 */
	private $maybe_created;

	/**
	 * Metrics (width/height/bytes/permalink id…).
	 *
	 * @var array<string,mixed>
	 */
	private $metrics;

	/**
	 * First comment id (Facebook "see more" comment).
	 *
	 * @var string
	 */
	private $first_comment_id;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $args Result fields.
	 */
	private function __construct( array $args ) {
		$this->success          = ! empty( $args['success'] );
		$this->remote_id        = isset( $args['remote_id'] ) ? (string) $args['remote_id'] : '';
		$this->remote_url       = isset( $args['remote_url'] ) ? (string) $args['remote_url'] : '';
		$this->error_code       = isset( $args['error_code'] ) ? (string) $args['error_code'] : '';
		$this->error_message    = isset( $args['error_message'] ) ? (string) $args['error_message'] : '';
		$this->maybe_created    = ! empty( $args['maybe_created'] );
		$this->metrics          = isset( $args['metrics'] ) && is_array( $args['metrics'] ) ? $args['metrics'] : array();
		$this->first_comment_id = isset( $args['first_comment_id'] ) ? (string) $args['first_comment_id'] : '';
	}

	/**
	 * Success.
	 *
	 * @param array<string,mixed> $args Remote details.
	 * @return PublishResult
	 */
	public static function success( array $args = array() ) {
		$args['success'] = true;
		return new self( $args );
	}

	/**
	 * Definitive failure (safe to retry when the code is retryable).
	 *
	 * @param string              $code    Error code.
	 * @param string              $message Message.
	 * @param array<string,mixed> $extra   Extra fields.
	 * @return PublishResult
	 */
	public static function failure( $code, $message, array $extra = array() ) {
		$extra['success']       = false;
		$extra['error_code']    = (string) $code;
		$extra['error_message'] = (string) $message;

		return new self( $extra );
	}

	/**
	 * Unknown outcome: the post may exist remotely.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param string $remote_id Remote id if known.
	 * @return PublishResult
	 */
	public static function unknown( $code, $message, $remote_id = '' ) {
		return new self(
			array(
				'success'       => false,
				'maybe_created' => true,
				'error_code'    => (string) $code,
				'error_message' => (string) $message,
				'remote_id'     => (string) $remote_id,
			)
		);
	}

	/**
	 * Whether the publish succeeded.
	 *
	 * @return bool
	 */
	public function ok() {
		return $this->success;
	}

	/**
	 * Convert to an array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'success'          => $this->success,
			'remote_id'        => $this->remote_id,
			'remote_url'       => $this->remote_url,
			'error_code'       => $this->error_code,
			'error_message'    => $this->error_message,
			'maybe_created'    => $this->maybe_created,
			'metrics'          => $this->metrics,
			'first_comment_id' => $this->first_comment_id,
		);
	}

	/**
	 * Convert to WP_Error (for callers that prefer errors).
	 *
	 * @return \WP_Error|null
	 */
	public function to_wp_error() {
		if ( $this->success ) {
			return null;
		}

		return new \WP_Error(
			'' !== $this->error_code ? $this->error_code : 'cpic_publish_failed',
			$this->error_message,
			array( 'maybe_created' => $this->maybe_created )
		);
	}

	/**
	 * Remote id ('' when unknown).
	 *
	 * @return string
	 */
	public function remote_id() {
		return $this->remote_id;
	}

	/**
	 * Error code.
	 *
	 * @return string
	 */
	public function error_code() {
		return $this->error_code;
	}

	/**
	 * Error message.
	 *
	 * @return string
	 */
	public function error_message() {
		return $this->error_message;
	}

	/**
	 * Whether the remote may already have the post.
	 *
	 * @return bool
	 */
	public function maybe_created() {
		return $this->maybe_created;
	}
}
