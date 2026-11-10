<?php
/**
 * Multi-account management for Facebook pages and Telegram chats.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Publishing;

use CPIC\Core\Container;
use CPIC\Security\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns destination records and their credentials.
 *
 * Credentials are encrypted at rest with the site's AUTH_KEY material and are
 * never serialised into REST responses, admin page HTML or JavaScript. The
 * only place they are decrypted is immediately before an API call.
 */
final class AccountManager {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Supported providers.
	 *
	 * @var array<string,string>
	 */
	private $providers = array(
		'facebook' => 'Facebook Page',
		'telegram' => 'Telegram Channel',
	);

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Repository.
	 *
	 * @return \CPIC\Database\Repositories\DestinationRepository
	 */
	public function repo() {
		return $this->container->get( 'destinations' );
	}

	/**
	 * Provider map (id => label), filterable for add-ons.
	 *
	 * @return array<string,string>
	 */
	public function providers() {
		/**
		 * Filter the available publishing providers.
		 *
		 * @param array<string,string> $providers id => label.
		 */
		return (array) apply_filters( 'cpic/publishing/providers', $this->providers );
	}

	/**
	 * Create a destination.
	 *
	 * @param array<string,mixed> $data {
	 *     @type string $provider    facebook|telegram.
	 *     @type string $label       Human label.
	 *     @type string $account_id  Page id / chat id.
	 *     @type string $account_name Remote name.
	 *     @type array  $credentials Secrets (page token, bot token, app secret).
	 *     @type array  $config      Non-secret options.
	 *     @type string $status      connected|disconnected|error.
	 *     @type int    $priority    Ordering.
	 * }
	 * @return array<string,mixed>|\WP_Error
	 */
	public function create( array $data ) {
		$provider = isset( $data['provider'] ) ? sanitize_key( (string) $data['provider'] ) : '';

		if ( ! isset( $this->providers()[ $provider ] ) ) {
			return new \WP_Error( 'cpic_bad_provider', __( 'Unknown publishing provider.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		$account_id = isset( $data['account_id'] ) ? trim( (string) $data['account_id'] ) : '';

		if ( '' === $account_id ) {
			return new \WP_Error( 'cpic_missing_account', __( 'A page or channel id is required.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		$existing = $this->repo()->find_by_account( $provider, $account_id );

		if ( $existing ) {
			return new \WP_Error(
				'cpic_account_exists',
				__( 'That account is already connected.', 'custom-post-image-creator' ),
				array( 'status' => 409, 'destination_id' => (int) $existing['id'] )
			);
		}

		$credentials = isset( $data['credentials'] ) && is_array( $data['credentials'] ) ? $data['credentials'] : array();
		$credentials = $this->validate_credentials( $provider, $credentials );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		if ( ! Encryption::is_available() ) {
			return new \WP_Error( 'cpic_no_openssl', __( 'Credentials cannot be stored because this server\'s PHP build has no OpenSSL extension. Ask your host to enable it, then reconnect the account.', 'custom-post-image-creator' ), array( 'status' => 500 ) );
		}

		$label = isset( $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : '';
		if ( '' === $label ) {
			$label = isset( $data['account_name'] ) && '' !== $data['account_name']
				? sanitize_text_field( (string) $data['account_name'] )
				/* translators: %s: account id */
				: sprintf( __( 'Account %s', 'custom-post-image-creator' ), $account_id );
		}

		$id = $this->repo()->insert(
			array(
				'public_id'    => $this->repo()->public_id( 'dst' ),
				'provider'     => $provider,
				'label'        => $label,
				'account_id'   => $account_id,
				'account_name' => isset( $data['account_name'] ) ? sanitize_text_field( (string) $data['account_name'] ) : '',
				'credentials'  => $this->encode( $credentials ),
				'config'       => wp_json_encode( isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : array() ),
				'status'       => isset( $data['status'] ) && in_array( sanitize_key( (string) $data['status'] ), array( 'connected', 'disconnected', 'error', 'expired' ), true ) ? sanitize_key( (string) $data['status'] ) : 'disconnected',
				'scopes'       => isset( $data['scopes'] ) ? sanitize_text_field( (string) $data['scopes'] ) : '',
				'is_default'   => 0,
				'priority'     => isset( $data['priority'] ) ? (int) $data['priority'] : 5,
				'created_by'   => get_current_user_id(),
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			)
		);

		if ( ! $id ) {
			return new \WP_Error( 'cpic_account_save', __( 'The account could not be saved.', 'custom-post-image-creator' ), array( 'status' => 500 ) );
		}

		$row = $this->repo()->find( $id );

		$this->container->get( 'audit' )->record(
			'account.created',
			'destination',
			$id,
			$label,
			// Never log credentials.
			array( 'provider' => $provider, 'account_id' => $account_id )
		);

		$this->container->get( 'events' )->dispatch(
			'account.created',
			array(
				'destination_id' => $id,
				'provider'       => $provider,
			)
		);

		return $this->hydrate( $row );
	}

	/**
	 * Update a destination (label, config, priority, credentials, status).
	 *
	 * @param int                 $id   Destination id.
	 * @param array<string,mixed> $data Fields.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function update( $id, array $data ) {
		$row = $this->repo()->find( (int) $id );

		if ( ! $row ) {
			return new \WP_Error( 'cpic_account_missing', __( 'That account no longer exists.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$update = array( 'updated_at' => current_time( 'mysql' ) );

		foreach ( array( 'label', 'account_name' ) as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$update[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}
		}

		if ( isset( $data['config'] ) && is_array( $data['config'] ) ) {
			$update['config'] = wp_json_encode( $data['config'] );
		}

		if ( isset( $data['priority'] ) ) {
			$update['priority'] = (int) $data['priority'];
		}

		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'connected', 'disconnected', 'error', 'expired' ), true ) ) {
			$update['status'] = (string) $data['status'];
		}

		if ( isset( $data['credentials'] ) && is_array( $data['credentials'] ) ) {
			if ( ! Encryption::is_available() ) {
				return new \WP_Error( 'cpic_no_openssl', __( 'Credentials cannot be stored because this server\'s PHP build has no OpenSSL extension.', 'custom-post-image-creator' ), array( 'status' => 500 ) );
			}

			$merged = array_merge( $this->decode( (string) $row['credentials'] ), $data['credentials'] );
			$merged = $this->validate_credentials( (string) $row['provider'], $merged );

			if ( is_wp_error( $merged ) ) {
				return $merged;
			}

			$update['credentials'] = $this->encode( $merged );
			$update['last_validated_at'] = current_time( 'mysql' );
		}

		$this->repo()->update( (int) $id, $update );

		$this->container->get( 'audit' )->record( 'account.updated', 'destination', (int) $id, isset( $update['label'] ) ? $update['label'] : (string) $row['label'] );

		return $this->hydrate( $this->repo()->find( (int) $id ) );
	}

	/**
	 * Delete a destination (deliveries keep their history).
	 *
	 * @param int $id Destination id.
	 * @return bool|\WP_Error
	 */
	public function delete( $id ) {
		$row = $this->repo()->find( (int) $id );

		if ( ! $row ) {
			return new \WP_Error( 'cpic_account_missing', __( 'That account no longer exists.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$this->repo()->delete( (int) $id );

		$this->container->get( 'audit' )->record( 'account.deleted', 'destination', (int) $id, (string) $row['label'] );
		$this->container->get( 'events' )->dispatch( 'account.deleted', array( 'destination_id' => (int) $id, 'provider' => (string) $row['provider'] ) );

		return true;
	}

	/**
	 * Mark a destination as failing (called by publishers on auth errors).
	 *
	 * @param int    $id      Destination id.
	 * @param string $status  New status.
	 * @param string $message Last error.
	 * @return void
	 */
	public function set_status( $id, $status, $message = '' ) {
		$this->repo()->update(
			(int) $id,
			array(
				'status'       => in_array( $status, array( 'connected', 'disconnected', 'error', 'expired' ), true ) ? $status : 'error',
				'last_error'   => '' === $message ? null : sanitize_textarea_field( $message ),
				'updated_at'   => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Read one credential (decrypted) for an outgoing API call.
	 *
	 * @param array<string,mixed>|int $destination Destination row or id.
	 * @param string                  $key         Credential key.
	 * @return string
	 */
	public function credential( $destination, $key ) {
		$destination = $this->raw_row( $destination );

		if ( ! $destination ) {
			return '';
		}

		$credentials = $this->decode( isset( $destination['credentials'] ) ? (string) $destination['credentials'] : '' );

		return isset( $credentials[ $key ] ) ? (string) $credentials[ $key ] : '';
	}

	/**
	 * Non-secret configuration for a destination.
	 *
	 * @param array<string,mixed>|int $destination Destination or id.
	 * @param string                  $key         Key.
	 * @param mixed                   $default     Default.
	 * @return mixed
	 */
	public function config( $destination, $key, $default = null ) {
		$destination = $this->raw_row( $destination );

		if ( ! $destination ) {
			return $default;
		}

		$config = json_decode( (string) $destination['config'], true );

		if ( ! is_array( $config ) ) {
			return $default;
		}

		return array_key_exists( $key, $config ) ? $config[ $key ] : $default;
	}

	/**
	 * Destination list with credentials stripped (for REST + admin).
	 *
	 * @param array<string,mixed> $args Filters: provider, status.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( array $args = array() ) {
		$args = wp_parse_args( $args, array( 'provider' => '', 'status' => '' ) );

		$where = array();
		if ( '' !== $args['provider'] ) {
			$where['provider'] = (string) $args['provider'];
		}
		if ( '' !== $args['status'] ) {
			$where['status'] = (string) $args['status'];
		}

		$rows = $this->repo()->select( $where, 'priority ASC, id ASC' );

		return array_values( array_map( array( $this, 'hydrate' ), $rows ) );
	}

	/**
	 * Get one destination (credentials stripped).
	 *
	 * @param int|string $id_or_public Id or public id.
	 * @return array<string,mixed>|null
	 */
	public function get( $id_or_public ) {
		$row = is_numeric( $id_or_public )
			? $this->repo()->find( (int) $id_or_public )
			: $this->repo()->find_by_public_id( (string) $id_or_public );

		return $this->hydrate( $row );
	}

	/**
	 * Safe representation: no credential values, only which ones are set.
	 *
	 * @param array<string,mixed>|null $row Row.
	 * @return array<string,mixed>|null
	 */
	public function public_view( $row ) {
		return $this->hydrate( $row );
	}

	/**
	 * Resolve the destinations a publish call should target.
	 *
	 * @param array<string,mixed> $args {
	 *     @type array<int,int>    $destination_ids Explicit ids.
	 *     @type array<int,string> $providers       Provider filter.
	 *     @type bool              $include_defaults Fall back to defaults per provider.
	 * }
	 * @return array<int,array<string,mixed>> Raw rows.
	 */
	public function resolve_targets( array $args = array() ) {
		$ids = array();

		if ( ! empty( $args['destination_ids'] ) ) {
			foreach ( (array) $args['destination_ids'] as $id ) {
				$ids[] = (int) $id;
			}
		}

		if ( ! empty( $ids ) ) {
			return $this->repo()->enabled( $ids );
		}

		$providers = ! empty( $args['providers'] ) ? array_map( 'sanitize_key', (array) $args['providers'] ) : array();

		if ( ! empty( $providers ) ) {
			$rows = array();
			foreach ( $providers as $provider ) {
				$rows = array_merge( $rows, $this->repo()->for_provider( $provider, true ) );
			}

			return $rows;
		}

		return $this->repo()->enabled();
	}

	/**
	 * Import 6.x single-account credentials (idempotent).
	 *
	 * @return int Destinations created.
	 */
	public function import_legacy_credentials() {
		$settings = $this->container->get( 'settings' );
		$created  = 0;

		$page_id    = (string) $settings->get( 'cpic_fb_page_id', '' );
		$page_token = \CPIC\Security\Settings::reveal_secret( (string) get_option( 'cpic_fb_page_token', '' ) );
		$app_id     = (string) $settings->get( 'cpic_fb_app_id', '' );
		$app_secret = \CPIC\Security\Settings::reveal_secret( (string) get_option( 'cpic_fb_app_secret', '' ) );

		if ( '' !== $page_id && '' !== $page_token && ! $this->repo()->find_by_account( 'facebook', $page_id ) ) {
			$result = $this->create(
				array(
					'provider'     => 'facebook',
					'label'        => (string) $settings->get( 'cpic_fb_page_name', '' ),
					'account_id'   => $page_id,
					'account_name' => (string) $settings->get( 'cpic_fb_page_name', '' ),
					'credentials'  => array_filter(
						array(
							'page_token' => $page_token,
							'app_id'     => $app_id,
							'app_secret' => $app_secret,
						)
					),
					'config'       => array( 'graph_version' => (string) $settings->get( 'cpic_fb_graph_version', 'v23.0' ) ),
					'status'       => 'connected',
					'priority'     => 1,
				)
			);

			if ( ! is_wp_error( $result ) ) {
				$created++;
			}
		}

		$chat_id   = (string) $settings->get( 'cpic_tg_chat_id', '' );
		$bot_token = \CPIC\Security\Settings::reveal_secret( (string) get_option( 'cpic_tg_bot_token', '' ) );

		if ( '' !== $chat_id && '' !== $bot_token && ! $this->repo()->find_by_account( 'telegram', $chat_id ) ) {
			$result = $this->create(
				array(
					'provider'    => 'telegram',
					'label'       => (string) $settings->get( 'cpic_tg_chat_id', '' ),
					'account_id'  => $chat_id,
					'credentials' => array( 'bot_token' => $bot_token ),
					'config'      => array( 'api_base' => (string) $settings->get( 'cpic_tg_api_base', 'https://api.telegram.org' ) ),
					'status'      => 'connected',
					'priority'    => 2,
				)
			);

			if ( ! is_wp_error( $result ) ) {
				$created++;
			}
		}

		if ( $created > 0 ) {
			$this->container->get( 'audit' )->record(
				'account.imported',
				'system',
				0,
				/* translators: %d: number of accounts */
				sprintf( __( 'Imported %d legacy account(s)', 'custom-post-image-creator' ), $created )
			);
		}

		return $created;
	}

	/**
	 * Validate provider credentials.
	 *
	 * @param string              $provider    Provider.
	 * @param array<string,mixed> $credentials Credentials.
	 * @return array<string,string>|\WP_Error
	 */
	private function validate_credentials( $provider, array $credentials ) {
		$clean = array();

		foreach ( $credentials as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$clean[ sanitize_key( (string) $key ) ] = trim( (string) $value );
			}
		}

		if ( 'facebook' === $provider ) {
			if ( empty( $clean['page_token'] ) ) {
				return new \WP_Error( 'cpic_missing_token', __( 'A Facebook page access token is required.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
			}

			if ( strlen( $clean['page_token'] ) < 20 ) {
				return new \WP_Error( 'cpic_token_format', __( 'That does not look like a Facebook page access token.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
			}
		}

		if ( 'telegram' === $provider ) {
			if ( empty( $clean['bot_token'] ) ) {
				return new \WP_Error( 'cpic_missing_token', __( 'A Telegram bot token is required.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
			}

			if ( ! preg_match( '/^\d+:[\w-]{30,}$/', $clean['bot_token'] ) ) {
				return new \WP_Error( 'cpic_token_format', __( 'The Telegram bot token format is invalid (expected 123456:ABC-DEF…).', 'custom-post-image-creator' ), array( 'status' => 400 ) );
			}
		}

		/**
		 * Filter validated destination credentials.
		 *
		 * @param array<string,string> $clean       Validated credentials.
		 * @param string               $provider    Provider.
		 * @param array<string,mixed>  $credentials Input.
		 */
		return (array) apply_filters( 'cpic/accounts/credentials', $clean, $provider, $credentials );
	}

	/**
	 * Prefix marking a base64-encoded payload. Ciphertext can legitimately
	 * start with `/` (and then look like protocol-relative legacy plain text),
	 * so encoded payloads are tagged with this marker first.
	 */
	const ENCODED_PREFIX = 'cpic:';

	/**
	 * Encrypt credentials for storage.
	 *
	 * @param array<string,string> $credentials Credentials.
	 * @return string
	 */
	private function encode( array $credentials ) {
		$encrypted = Encryption::encrypt( (string) wp_json_encode( $credentials ) );

		if ( '' === $encrypted ) {
			// Encryption is unavailable; refuse to write plain text.
			return '';
		}

		return self::ENCODED_PREFIX . base64_encode( $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt stored credentials.
	 *
	 * @param string $stored Stored value.
	 * @return array<string,string>
	 */
	private function decode( $stored ) {
		$stored = (string) $stored;

		if ( '' === $stored ) {
			return array();
		}

		// New rows are tagged + base64 (see encode()); anything else that is not
		// ciphertext is a 6.x row stored as plain JSON.
		if ( 0 === strpos( $stored, self::ENCODED_PREFIX ) ) {
			$stored = (string) base64_decode( substr( $stored, strlen( self::ENCODED_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}

		$plain = Encryption::is_encrypted( $stored ) ? Encryption::decrypt( $stored ) : $stored;

		$decoded = json_decode( (string) $plain, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Fetch the stored row for a destination, accepting an id, a stored row or
	 * a hydrated row (which has no credentials key at all).
	 *
	 * @param array<string,mixed>|int $destination Destination.
	 * @return array<string,mixed>|null
	 */
	private function raw_row( $destination ) {
		if ( is_array( $destination ) ) {
			if ( isset( $destination['credentials'] ) ) {
				return $destination;
			}

			return isset( $destination['id'] ) ? $this->repo()->find( (int) $destination['id'] ) : null;
		}

		return $this->repo()->find( (int) $destination );
	}

	/**
	 * Strip secrets and add computed fields.
	 *
	 * @param array<string,mixed>|null $row Row.
	 * @return array<string,mixed>|null
	 */
	public function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}

		// Idempotent: hydrate may be called on an already-hydrated row
		// (e.g. public_view() after create()), so tolerate array values.
		$credentials_raw = isset( $row['credentials'] ) ? $row['credentials'] : '';
		$credentials_str = is_array( $credentials_raw ) ? '' : (string) $credentials_raw;
		$credentials = $this->decode( $credentials_str );

		$config_raw = isset( $row['config'] ) ? $row['config'] : '';
		if ( is_array( $config_raw ) ) {
			$config = $config_raw;
		} else {
			$config = json_decode( (string) $config_raw, true );
		}

		$row['id']         = (int) $row['id'];
		$row['priority']   = (int) $row['priority'];
		$row['is_default'] = (int) $row['is_default'];
		$row['config']     = is_array( $config ) ? $config : array();

		// Credentials are replaced by a presence map — never the values.
		$row['credentials_set'] = array();
		foreach ( array_keys( $credentials ) as $key ) {
			$row['credentials_set'][] = (string) $key;
		}
		unset( $row['credentials'] );

		$row['provider_label'] = isset( $this->providers()[ $row['provider'] ] ) ? $this->providers()[ $row['provider'] ] : (string) $row['provider'];
		$row['connected']      = 'connected' === (string) $row['status'];
		$row['token_expiring'] = ! empty( $row['token_expires_at'] ) && strtotime( (string) $row['token_expires_at'] ) < time() + ( 7 * DAY_IN_SECONDS );

		return $row;
	}

	/**
	 * Provider + connection summary for diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public function summary() {
		$counts = $this->repo()->status_summary();
		$out    = array();

		foreach ( $this->providers() as $provider => $label ) {
			$out[ $provider ] = array(
				'label'     => $label,
				'connected' => isset( $counts[ $provider ]['connected'] ) ? (int) $counts[ $provider ]['connected'] : 0,
				'total'     => isset( $counts[ $provider ] ) ? array_sum( $counts[ $provider ] ) : 0,
			);
		}

		return $out;
	}
}
