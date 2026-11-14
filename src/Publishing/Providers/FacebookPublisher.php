<?php
/**
 * Facebook Page publisher (Graph API).
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Publishing\Providers;

use CPIC\Publishing\PublishResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes a photo to a Facebook Page via `/PAGE_ID/photos`.
 *
 * Two facts drive the design:
 *  - Page tokens expire. A `fb_invalid_token` is terminal: retrying with the
 *    same token just burns rate limit, so the destination is flagged and a
 *    human is asked to reconnect.
 *  - A timeout after the upload may mean the photo *was* published. We report
 *    `maybe_created` and never auto-retry in that case, because a duplicate
 *    post on a news page is a visible, embarrassing failure.
 */
final class FacebookPublisher extends AbstractPublisher {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'facebook';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Facebook Page', 'custom-post-image-creator' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function credential_fields() {
		return array( 'page_token', 'app_id', 'app_secret' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function caption_limit() {
		return 5000;
	}

	/**
	 * {@inheritDoc}
	 */
	public function preferred_format() {
		return 'square';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured( array $destination ) {
		return '' !== $this->credential( $destination, 'page_token' ) && '' !== (string) $destination['account_id'];
	}

	/**
	 * Graph API version for a destination.
	 *
	 * @param array<string,mixed> $destination Destination.
	 * @return string
	 */
	private function graph_version( array $destination ) {
		$version = (string) $this->config( $destination, 'graph_version', '' );

		if ( '' === $version ) {
			$version = (string) $this->container->get( 'settings' )->get( 'cpic_fb_graph_version', 'v23.0' );
		}

		return preg_match( '/^v\d+\.\d+$/', $version ) ? $version : 'v23.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function verify( array $destination ) {
		$token = $this->credential( $destination, 'page_token' );

		if ( '' === $token ) {
			return new \WP_Error( 'fb_invalid_token', __( 'No page access token is stored for this page.', 'custom-post-image-creator' ) );
		}

		$url = sprintf(
			'https://graph.facebook.com/%s/me?fields=id,name,access_token&access_token=%s',
			$this->graph_version( $destination ),
			rawurlencode( $token )
		);

		$response = $this->request( $url, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] || ! is_array( $response['data'] ) ) {
			$message = $this->extract_error( $response );

			return new \WP_Error( $this->status_code( $response['code'], 'fb' ), $message );
		}

		return array(
			'account_id'   => isset( $response['data']['id'] ) ? (string) $response['data']['id'] : (string) $destination['account_id'],
			'account_name' => isset( $response['data']['name'] ) ? (string) $response['data']['name'] : (string) $destination['account_name'],
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function publish( array $destination, array $payload ) {
		$token    = $this->credential( $destination, 'page_token' );
		$page_id  = (string) $destination['account_id'];
		$artifact = isset( $payload['artifact'] ) && is_array( $payload['artifact'] ) ? $payload['artifact'] : array();

		if ( '' === $token ) {
			return PublishResult::failure( 'fb_invalid_token', __( 'No page access token is stored for this page.', 'custom-post-image-creator' ) );
		}

		if ( '' === $page_id ) {
			return PublishResult::failure( 'invalid_destination', __( 'This destination has no Facebook page id.', 'custom-post-image-creator' ) );
		}

		$bytes = $this->artifact_bytes( $artifact );

		if ( is_wp_error( $bytes ) ) {
			return PublishResult::failure( $bytes->get_error_code(), $bytes->get_error_message() );
		}

		$boundary = 'cpic' . md5( (string) wp_rand() . microtime( true ) );

		$body = $this->multipart(
			$boundary,
			array(
				'message'      => isset( $payload['caption'] ) ? (string) $payload['caption'] : '',
				'access_token' => $token,
				'published'    => 'true',
			),
			$bytes,
			'cpic-' . ( isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0 ) . '.png'
		);

		$url = sprintf( 'https://graph.facebook.com/%s/%s/photos', $this->graph_version( $destination ), rawurlencode( $page_id ) );

		$response = $this->request(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => 60,
				'headers' => array(
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
					'Accept'       => 'application/json',
				),
				'body'    => $body,
			),
			'POST'
		);

		if ( is_wp_error( $response ) ) {
			// A transport failure after the bytes left is genuinely ambiguous.
			return PublishResult::unknown( 'network_error', $response->get_error_message() );
		}

		$data = is_array( $response['data'] ) ? $response['data'] : array();

		if ( 200 !== $response['code'] && 201 !== $response['code'] ) {
			$code    = $this->classify_error( $data, $response['code'] );
			$message = $this->extract_error( $response );

			if ( $this->is_ambiguous( $response['code'], 'POST' ) ) {
				return PublishResult::unknown( $code, $message );
			}

			if ( 'fb_invalid_token' === $code || 'fb_permission' === $code ) {
				$this->container->get( 'accounts' )->set_status( (int) $destination['id'], 'error', $message );
			}

			return PublishResult::failure( $code, $message );
		}

		$remote_id = isset( $data['id'] ) ? (string) $data['id'] : ( isset( $data['post_id'] ) ? (string) $data['post_id'] : '' );

		if ( '' === $remote_id ) {
			return PublishResult::unknown( 'fb_unexpected_response', __( 'Facebook accepted the upload but did not return a post id.', 'custom-post-image-creator' ) );
		}

		$metrics = $this->base_metrics( $artifact );

		$result = PublishResult::success(
			array(
				'remote_id'  => $remote_id,
				'remote_url' => 'https://www.facebook.com/' . rawurlencode( $remote_id ),
				'metrics'    => $metrics,
			)
		);

		// Optional "read more" first comment.
		if ( ! empty( $payload['first_comment'] ) ) {
			$comment_id = $this->add_comment( $destination, $remote_id, (string) $payload['first_comment'] );

			if ( '' !== $comment_id ) {
				$data = $result->to_array();
				$data['first_comment_id'] = $comment_id;

				return PublishResult::success( $data );
			}
		}

		return $result;
	}

	/**
	 * Post a comment on a published photo.
	 *
	 * @param array<string,mixed> $destination Destination.
	 * @param string              $remote_id   Post id.
	 * @param string              $message     Comment text.
	 * @return string Comment id ('' on failure — the post itself succeeded).
	 */
	private function add_comment( array $destination, $remote_id, $message ) {
		$response = $this->request(
			sprintf( 'https://graph.facebook.com/%s/%s/comments', $this->graph_version( $destination ), rawurlencode( (string) $remote_id ) ),
			array(
				'timeout' => 20,
				'body'    => array(
					'message'      => $message,
					'access_token' => $this->credential( $destination, 'page_token' ),
				),
			),
			'POST'
		);

		if ( is_wp_error( $response ) || 200 !== $response['code'] ) {
			$this->container->get( 'logger' )->warning(
				'publish',
				__( 'The Facebook post was published but the first comment could not be added.', 'custom-post-image-creator' ),
				array( 'destination_id' => (int) $destination['id'], 'remote_id' => (string) $remote_id )
			);

			return '';
		}

		return isset( $response['data']['id'] ) ? (string) $response['data']['id'] : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( array $destination, $remote_id ) {
		$response = $this->request(
			sprintf(
				'https://graph.facebook.com/%s/%s?access_token=%s',
				$this->graph_version( $destination ),
				rawurlencode( (string) $remote_id ),
				rawurlencode( $this->credential( $destination, 'page_token' ) )
			),
			array( 'timeout' => 20 ),
			'POST'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] ) {
			return new \WP_Error( $this->status_code( $response['code'], 'fb' ), $this->extract_error( $response ) );
		}

		return true;
	}

	/**
	 * Classify a Graph API error payload.
	 *
	 * @param array<string,mixed> $data Decoded body.
	 * @param int                 $http HTTP status.
	 * @return string
	 */
	private function classify_error( $data, $http ) {
		$error = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();

		$code      = isset( $error['code'] ) ? (int) $error['code'] : 0;
		$subcode   = isset( $error['error_subcode'] ) ? (int) $error['error_subcode'] : 0;
		$type      = isset( $error['type'] ) ? (string) $error['type'] : '';

		// 190 = invalid/expired access token (463 expired, 467 invalid).
		if ( 190 === $code || 102 === $code || in_array( $subcode, array( 463, 467, 458 ), true ) ) {
			return 'fb_invalid_token';
		}

		// Rate limiting.
		if ( 4 === $code || 17 === $code || 32 === $code || 613 === $code || 429 === (int) $http ) {
			return 'fb_rate_limit';
		}

		// Permissions.
		if ( 10 === $code || 200 === $code || 294 === $code || 'OAuthException' === $type && 0 === $code ) {
			return 'fb_permission';
		}

		// Duplicate content detection (Facebook dedupes identical images).
		if ( 506 === $code ) {
			return 'fb_duplicate_risk';
		}

		// Unsupported media.
		if ( 100 === $code && in_array( $subcode, array( 1366046, 1366051, 1487079 ), true ) ) {
			return 'fb_unsupported_media';
		}

		if ( (int) $http >= 500 ) {
			return 'fb_server_error';
		}

		return $this->status_code( $http, 'fb' );
	}

	/**
	 * Human message from a Graph error payload.
	 *
	 * @param array<string,mixed> $response Response.
	 * @return string
	 */
	private function extract_error( array $response ) {
		if ( isset( $response['data']['error']['message'] ) ) {
			return (string) $response['data']['error']['message'];
		}

		return trim( substr( (string) $response['body'], 0, 400 ) );
	}

	/**
	 * Build a multipart/form-data body.
	 *
	 * @param string              $boundary Boundary.
	 * @param array<string,mixed> $fields   Text fields.
	 * @param string              $file     File bytes.
	 * @param string              $filename File name.
	 * @return string
	 */
	private function multipart( $boundary, array $fields, $file, $filename ) {
		$body = '';

		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
			$body .= (string) $value . "\r\n";
		}

		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="source"; filename="' . $filename . '"' . "\r\n";
		$body .= "Content-Type: image/png\r\n\r\n";
		$body .= $file . "\r\n";
		$body .= '--' . $boundary . "--\r\n";

		return $body;
	}
}
