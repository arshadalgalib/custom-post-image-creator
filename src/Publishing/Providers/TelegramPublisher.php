<?php
/**
 * Telegram channel publisher (Bot API).
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Publishing\Providers;

use CPIC\Publishing\PublishResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes a photo with a caption to a Telegram channel through `sendPhoto`.
 *
 * Telegram captions are hard-capped at 1024 characters; the API returns an
 * error rather than truncating, so captions are trimmed before the call.
 */
final class TelegramPublisher extends AbstractPublisher {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'telegram';
	}

	/**
	 * {@inheritDoc} 
	 */
	public function label() {
		return __( 'Telegram Channel', 'custom-post-image-creator' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function credential_fields() {
		return array( 'bot_token' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function caption_limit() {
		return 1024;
	}

	/**
	 * {@inheritDoc}
	 */
	public function preferred_format() {
		return 'portrait';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured( array $destination ) {
		return '' !== $this->credential( $destination, 'bot_token' ) && '' !== (string) $destination['account_id'];
	}

	/**
	 * Bot API base URL.
	 *
	 * @param array<string,mixed> $destination Destination.
	 * @return string
	 */
	private function base( array $destination ) {
		$base = (string) $this->config( $destination, 'api_base', '' );

		if ( '' === $base ) {
			$base = (string) $this->container->get( 'settings' )->get( 'cpic_tg_api_base', 'https://api.telegram.org' );
		}

		return rtrim( '' !== $base ? $base : 'https://api.telegram.org', '/' );
	}

	/**
	 * Endpoint URL for a method.
	 *
	 * @param array<string,mixed> $destination Destination.
	 * @param string              $method      Bot API method.
	 * @return string
	 */
	private function endpoint( array $destination, $method ) {
		return $this->base( $destination ) . '/bot' . $this->credential( $destination, 'bot_token' ) . '/' . $method;
	}

	/**
	 * {@inheritDoc}
	 */
	public function verify( array $destination ) {
		$token = $this->credential( $destination, 'bot_token' );

		if ( '' === $token ) {
			return new \WP_Error( 'tg_invalid_token', __( 'No bot token is stored for this channel.', 'custom-post-image-creator' ) );
		}

		$response = $this->request( $this->endpoint( $destination, 'getMe' ), array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] || empty( $response['data']['ok'] ) ) {
			return new \WP_Error( $this->classify( $response ), $this->extract_error( $response ) );
		}

		return array(
			'account_name' => isset( $response['data']['result']['username'] ) ? '@' . (string) $response['data']['result']['username'] : (string) $destination['account_name'],
			'account_id'   => (string) $destination['account_id'],
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function publish( array $destination, array $payload ) {
		$artifact = isset( $payload['artifact'] ) && is_array( $payload['artifact'] ) ? $payload['artifact'] : array();
		$chat_id  = (string) $destination['account_id'];
		$token    = $this->credential( $destination, 'bot_token' );

		if ( '' === $token ) {
			return PublishResult::failure( 'tg_invalid_token', __( 'No bot token is stored for this channel.', 'custom-post-image-creator' ) );
		}

		if ( '' === $chat_id ) {
			return PublishResult::failure( 'invalid_destination', __( 'This destination has no Telegram chat id.', 'custom-post-image-creator' ) );
		}

		$bytes = $this->artifact_bytes( $artifact );

		if ( is_wp_error( $bytes ) ) {
			return PublishResult::failure( $bytes->get_error_code(), $bytes->get_error_message() );
		}

		$caption = isset( $payload['caption'] ) ? (string) $payload['caption'] : '';

		if ( mb_strlen( $caption, 'UTF-8' ) > $this->caption_limit() ) {
			$caption = $this->container->get( 'captions' )->limit( $caption, $this->caption_limit() );
		}

		$boundary = 'cpic' . md5( (string) wp_rand() . microtime( true ) );

		$body = $this->multipart(
			$boundary,
			array(
				'chat_id'    => $chat_id,
				'caption'    => $caption,
				'parse_mode' => 'HTML' === (string) $this->config( $destination, 'parse_mode', '' ) ? 'HTML' : '',
			),
			$bytes,
			'cpic.png'
		);

		$response = $this->request(
			$this->endpoint( $destination, 'sendPhoto' ),
			array(
				'method'  => 'POST',
				'timeout' => 60,
				'headers' => array(
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			),
			'POST'
		);

		if ( is_wp_error( $response ) ) {
			return PublishResult::unknown( 'network_error', $response->get_error_message() );
		}

		$data = is_array( $response['data'] ) ? $response['data'] : array();

		if ( 200 !== $response['code'] || empty( $data['ok'] ) ) {
			$code    = $this->classify( $response );
			$message = $this->extract_error( $response );

			if ( $this->is_ambiguous( $response['code'], 'POST' ) ) {
				return PublishResult::unknown( $code, $message );
			}

			if ( 'tg_invalid_token' === $code || 'tg_forbidden' === $code ) {
				$this->container->get( 'accounts' )->set_status( (int) $destination['id'], 'error', $message );
			}

			return PublishResult::failure( $code, $message );
		}

		$result = isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : array();

		$message_id = isset( $result['message_id'] ) ? (string) $result['message_id'] : '';

		if ( '' === $message_id ) {
			return PublishResult::unknown( 'tg_unexpected_response', __( 'Telegram accepted the photo but did not return a message id.', 'custom-post-image-creator' ) );
		}

		// Telegram needs the numeric chat id (minus the -100 prefix) for t.me URLs.
		$clean_chat = preg_replace( '/^-100/', '', $chat_id );
		$url        = preg_match( '/^-?\d+$/', $chat_id ) ? 'https://t.me/c/' . $clean_chat . '/' . $message_id : '';

		$metrics = $this->base_metrics( $artifact );
		$metrics['chat_id'] = $chat_id;

		return PublishResult::success(
			array(
				'remote_id'  => $message_id,
				'remote_url' => $url,
				'metrics'    => $metrics,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( array $destination, $remote_id ) {
		$response = $this->request(
			$this->endpoint( $destination, 'deleteMessage' ),
			array(
				'timeout' => 20,
				'body'    => array(
					'chat_id'    => (string) $destination['account_id'],
					'message_id' => (int) $remote_id,
				),
			),
			'POST'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['code'] || empty( $response['data']['ok'] ) ) {
			return new \WP_Error( $this->classify( $response ), $this->extract_error( $response ) );
		}

		return true;
	}

	/**
	 * Map a Bot API failure onto the retry taxonomy.
	 *
	 * @param array<string,mixed> $response Response.
	 * @return string
	 */
	private function classify( array $response ) {
		$data        = is_array( $response['data'] ) ? $response['data'] : array();
		$description = isset( $data['description'] ) ? strtolower( (string) $data['description'] ) : '';
		$code        = isset( $data['error_code'] ) ? (int) $data['error_code'] : (int) $response['code'];

		if ( 401 === $code || false !== strpos( $description, 'unauthorized' ) ) {
			return 'tg_invalid_token';
		}

		if ( 403 === $code || false !== strpos( $description, 'not enough rights' ) || false !== strpos( $description, 'bot was kicked' ) ) {
			return 'tg_forbidden';
		}

		if ( 400 === $code && false !== strpos( $description, 'chat not found' ) ) {
			return 'tg_chat_not_found';
		}

		if ( 429 === $code || false !== strpos( $description, 'too many requests' ) ) {
			return 'tg_rate_limit';
		}

		if ( 400 === $code && false !== strpos( $description, 'caption' ) ) {
			return 'tg_bad_request';
		}

		if ( $code >= 500 ) {
			return 'tg_server_error';
		}

		return $this->status_code( $code, 'tg' );
	}

	/**
	 * Human message from a Bot API response.
	 *
	 * @param array<string,mixed> $response Response.
	 * @return string
	 */
	private function extract_error( array $response ) {
		if ( isset( $response['data']['description'] ) ) {
			return (string) $response['data']['description'];
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
			if ( '' === (string) $value ) {
				continue;
			}
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
			$body .= (string) $value . "\r\n";
		}

		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="photo"; filename="' . $filename . '"' . "\r\n";
		$body .= "Content-Type: image/png\r\n\r\n";
		$body .= $file . "\r\n";
		$body .= '--' . $boundary . "--\r\n";

		return $body;
	}
}
