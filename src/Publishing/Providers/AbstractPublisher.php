<?php
/**
 * Shared HTTP + error-classification behaviour for publishers.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Publishing\Providers;

use CPIC\Core\Container;
use CPIC\Publishing\PublishResult;
use CPIC\Publishing\PublisherInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every provider talks HTTP the same way and maps transport failures onto the
 * retry taxonomy consistently.
 */
abstract class AbstractPublisher implements PublisherInterface {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	protected $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Non-secret settings for a destination.
	 *
	 * @param array<string,mixed> $destination Destination.
	 * @param string              $key         Key.
	 * @param mixed               $default     Default.
	 * @return mixed
	 */
	protected function config( array $destination, $key, $default = null ) {
		$config = json_decode( (string) ( isset( $destination['config'] ) ? $destination['config'] : '' ), true );

		if ( ! is_array( $config ) ) {
			return $default;
		}

		return array_key_exists( $key, $config ) ? $config[ $key ] : $default;
	}

	/**
	 * Read a decrypted credential.
	 *
	 * @param array<string,mixed> $destination Destination row.
	 * @param string              $key         Key.
	 * @return string
	 */
	protected function credential( array $destination, $key ) {
		return (string) $this->container->get( 'accounts' )->credential( $destination, $key );
	}

	/**
	 * Read the artifact file bytes for upload.
	 *
	 * @param array<string,mixed> $artifact Artifact row.
	 * @return string|\WP_Error
	 */
	protected function artifact_bytes( array $artifact ) {
		$path = isset( $artifact['file_path'] ) ? (string) $artifact['file_path'] : '';

		if ( '' === $path || ! is_readable( $path ) ) {
			return new \WP_Error( 'artifact_missing', __( 'The rendered image is no longer on disk.', 'custom-post-image-creator' ) );
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( false === $bytes || '' === $bytes ) {
			return new \WP_Error( 'artifact_unreadable', __( 'The rendered image could not be read.', 'custom-post-image-creator' ) );
		}

		return $bytes;
	}

	/**
	 * Perform an HTTP request and return the decoded body.
	 *
	 * @param string              $url    URL.
	 * @param array<string,mixed> $args   Request args.
	 * @param string              $method GET|POST.
	 * @return array{code:int,body:string,data:mixed}|\WP_Error
	 */
	protected function request( $url, array $args = array(), $method = 'GET' ) {
		$defaults = array(
			'timeout' => 30,
			'headers' => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		$response = 'POST' === $method
			? wp_remote_post( $url, $args )
			: wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'network_error',
				/* translators: %s: transport error message */
				sprintf( __( 'Network error: %s', 'custom-post-image-creator' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		$data = json_decode( $body, true );

		return array(
			'code' => $code,
			'body' => $body,
			'data' => is_array( $data ) ? $data : null,
		);
	}

	/**
	 * Map an HTTP status to the retry taxonomy.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $prefix  Provider prefix (`fb`|`tg`).
	 * @return string
	 */
	protected function status_code( $code, $prefix ) {
		$code = (int) $code;

		if ( 429 === $code ) {
			return $prefix . '_rate_limit';
		}

		if ( 401 === $code || 403 === $code ) {
			return $prefix . '_permission';
		}

		if ( 404 === $code ) {
			return $prefix . '_not_found';
		}

		if ( $code >= 500 ) {
			return $prefix . '_server_error';
		}

		return $prefix . '_bad_request';
	}

	/**
	 * Whether a failure result should be treated as "unknown outcome".
	 *
	 * A 5xx or a timeout on a POST means the provider *may* have created the
	 * post; a 4xx means it definitely did not.
	 *
	 * @param int    $code   HTTP status.
	 * @param string $method HTTP method.
	 * @return bool
	 */
	protected function is_ambiguous( $code, $method ) {
		if ( 'POST' !== strtoupper( (string) $method ) ) {
			return false;
		}

		return 0 === (int) $code || (int) $code >= 500;
	}

	/**
	 * Metrics recorded with a delivery.
	 *
	 * @param array<string,mixed> $artifact Artifact row.
	 * @return array<string,mixed>
	 */
	protected function base_metrics( array $artifact ) {
		return array(
			'width'  => isset( $artifact['width'] ) ? (int) $artifact['width'] : 0,
			'height' => isset( $artifact['height'] ) ? (int) $artifact['height'] : 0,
			'bytes'  => isset( $artifact['bytes'] ) ? (int) $artifact['bytes'] : 0,
		);
	}
}
