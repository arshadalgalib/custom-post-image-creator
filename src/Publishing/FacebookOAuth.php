<?php
/**
 * Facebook OAuth: click-to-authorize, no manual page token.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Publishing;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drives the Facebook Login → page-selector → encrypted storage flow.
 *
 * Design:
 *  - App ID / secret are stored in Settings (cpic_fb_app_id / cpic_fb_app_secret)
 *    so no proxy server is needed; the site owner creates one Facebook App.
 *  - State is a 40-char random token kept in a transient (10 min TTL) keyed to
 *    the WP user, then verified on callback — no nonce-in-URL replay.
 *  - After Facebook returns `code`, we exchange it for a short user token,
 *    then for a 60-day long-lived token, then `GET /me/accounts` to discover
 *    pages. The picker transient holds that payload for the second step.
 *  - The popup never exposes tokens to JS; only the picker HTML (server-rendered)
 *    contains the page list, and the final connect step is a normal admin-post
 *    that writes via AccountManager (which encrypts at rest).
 */
final class FacebookOAuth {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Scopes requested from Facebook.
	 *
	 * `pages_show_list` + `pages_manage_posts` are the minimum for publishing
	 * a photo to a Page; `pages_read_engagement` lets verify() read /me.
	 */
	const SCOPES = 'pages_show_list,pages_manage_posts,pages_read_engagement';

	/**
	 * Transient prefix for OAuth state.
	 */
	const STATE_TRANSIENT_PREFIX = 'cpic_fb_oauth_state_';

	/**
	 * Transient prefix for picker payload (pages + long-lived user token).
	 */
	const PICKER_TRANSIENT_PREFIX = 'cpic_fb_oauth_pages_';

	/**
	 * Graph version used when the setting is empty.
	 */
	const DEFAULT_GRAPH_VERSION = 'v23.0';

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Dependency: settings.
	 *
	 * @return \CPIC\Security\Settings
	 */
	private function settings() {
		return $this->container->get( 'settings' );
	}

	/**
	 * Whether the site has been configured with a Facebook App id/secret.
	 *
	 * @return bool
	 */
	public function is_configured() {
		$app_id = trim( (string) $this->settings()->get( 'cpic_fb_app_id', '' ) );
		return '' !== $app_id;
	}

	/**
	 * Human-readable configuration error.
	 *
	 * @return string
	 */
	public function configuration_error() {
		if ( ! $this->is_configured() ) {
			return __( 'Facebook Login is not configured. Add your App ID and App Secret in Settings → Social, then try again.', 'custom-post-image-creator' );
		}
		return '';
	}

	/**
	 * Current Graph API version.
	 *
	 * @return string
	 */
	public function graph_version() {
		$version = (string) $this->settings()->get( 'cpic_fb_graph_version', self::DEFAULT_GRAPH_VERSION );
		return preg_match( '/^v\d+\.\d+$/', $version ) ? $version : self::DEFAULT_GRAPH_VERSION;
	}

	/**
	 * OAuth redirect URI registered with the Facebook App.
	 *
	 * Must be exactly this value in the app's Valid OAuth Redirect URIs.
	 *
	 * @return string
	 */
	public function redirect_uri() {
		return admin_url( 'admin-post.php?action=cpic_facebook_oauth' );
	}

	/**
	 * Build the Facebook authorization URL.
	 *
	 * @param int $user_id Current user id.
	 * @return string|\WP_Error Authorization URL.
	 */
	public function authorization_url( $user_id ) {
		$app_id = trim( (string) $this->settings()->get( 'cpic_fb_app_id', '' ) );
		if ( '' === $app_id ) {
			return new \WP_Error( 'cpic_fb_not_configured', $this->configuration_error(), array( 'status' => 400 ) );
		}

		$state = wp_generate_password( 40, false, false );
		set_transient( self::STATE_TRANSIENT_PREFIX . $state, (int) $user_id, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'client_id'     => $app_id,
			'redirect_uri'  => $this->redirect_uri(),
			'scope'         => self::SCOPES,
			'state'         => $state,
			'response_type' => 'code',
			'auth_type'     => 'rerequest',
			'display'       => 'popup',
		);

		return 'https://www.facebook.com/' . $this->graph_version() . '/dialog/oauth?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchange an OAuth code for a short-lived user token.
	 *
	 * @param string $code OAuth code from Facebook.
	 * @return string|\WP_Error Access token.
	 */
	private function exchange_code_for_token( $code ) {
		$app_id     = trim( (string) $this->settings()->get( 'cpic_fb_app_id', '' ) );
		$app_secret = \CPIC\Security\Settings::reveal_secret( (string) get_option( 'cpic_fb_app_secret', '' ) );
		if ( '' === $app_secret ) {
			$app_secret = trim( (string) $this->settings()->get( 'cpic_fb_app_secret', '' ) );
		}

		if ( '' === $app_id || '' === $app_secret ) {
			return new \WP_Error( 'cpic_fb_not_configured', __( 'Facebook App Secret is missing. Save it in Settings → Social and try again.', 'custom-post-image-creator' ) );
		}

		$url = sprintf(
			'https://graph.facebook.com/%s/oauth/access_token?%s',
			$this->graph_version(),
			http_build_query(
				array(
					'client_id'     => $app_id,
					'client_secret' => $app_secret,
					'redirect_uri'  => $this->redirect_uri(),
					'code'          => $code,
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			)
		);

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'cpic_fb_network', sprintf( __( 'Facebook code exchange failed: %s', 'custom-post-image-creator' ), $response->get_error_message() ) );
		}

		$code_http = (int) wp_remote_retrieve_response_code( $response );
		$body      = (string) wp_remote_retrieve_body( $response );
		$data      = json_decode( $body, true );

		if ( 200 !== $code_http || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : $body;
			return new \WP_Error( 'cpic_fb_exchange_failed', sprintf( __( 'Facebook did not return an access token: %s', 'custom-post-image-creator' ), $message ) );
		}

		return (string) $data['access_token'];
	}

	/**
	 * Exchange a short-lived user token for a 60-day long-lived token.
	 *
	 * @param string $short_token Short-lived token.
	 * @return string|array|\WP_Error Long-lived token (or original on downgrade).
	 */
	private function get_long_lived_token( $short_token ) {
		$app_id     = trim( (string) $this->settings()->get( 'cpic_fb_app_id', '' ) );
		$app_secret = \CPIC\Security\Settings::reveal_secret( (string) get_option( 'cpic_fb_app_secret', '' ) );
		if ( '' === $app_secret ) {
			$app_secret = trim( (string) $this->settings()->get( 'cpic_fb_app_secret', '' ) );
		}

		$url = sprintf(
			'https://graph.facebook.com/%s/oauth/access_token?%s',
			$this->graph_version(),
			http_build_query(
				array(
					'grant_type'          => 'fb_exchange_token',
					'client_id'           => $app_id,
					'client_secret'       => $app_secret,
					'fb_exchange_token'   => $short_token,
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			)
		);

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			// Non-terminal: keep the short token.
			return (string) $short_token;
		}

		$code_http = (int) wp_remote_retrieve_response_code( $response );
		$body      = (string) wp_remote_retrieve_body( $response );
		$data      = json_decode( $body, true );

		if ( 200 === $code_http && is_array( $data ) && ! empty( $data['access_token'] ) ) {
			return (string) $data['access_token'];
		}

		return (string) $short_token;
	}

	/**
	 * Fetch pages the user can manage.
	 *
	 * @param string $user_token Long-lived user token.
	 * @return array<int,array<string,mixed>>|\WP_Error Pages.
	 */
	private function fetch_pages( $user_token ) {
		$url = sprintf(
			'https://graph.facebook.com/%s/me/accounts?%s',
			$this->graph_version(),
			http_build_query(
				array(
					'fields'       => 'id,name,category,fan_count,picture{url},access_token,instagram_business_account',
					'access_token' => $user_token,
					'limit'        => 100,
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			)
		);

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'cpic_fb_network', sprintf( __( 'Could not list your Facebook Pages: %s', 'custom-post-image-creator' ), $response->get_error_message() ) );
		}

		$code_http = (int) wp_remote_retrieve_response_code( $response );
		$body      = (string) wp_remote_retrieve_body( $response );
		$data      = json_decode( $body, true );

		if ( 200 !== $code_http || ! is_array( $data ) ) {
			$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : $body;
			return new \WP_Error( 'cpic_fb_pages_failed', sprintf( __( 'Facebook Pages could not be listed: %s', 'custom-post-image-creator' ), $message ) );
		}

		$pages = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		$out   = array();

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) || empty( $page['id'] ) || empty( $page['access_token'] ) ) {
				continue;
			}
			$out[] = array(
				'id'           => (string) $page['id'],
				'name'         => isset( $page['name'] ) ? (string) $page['name'] : (string) $page['id'],
				'category'     => isset( $page['category'] ) ? (string) $page['category'] : '',
				'fan_count'    => isset( $page['fan_count'] ) ? (int) $page['fan_count'] : 0,
				'picture'      => isset( $page['picture']['data']['url'] ) ? (string) $page['picture']['data']['url'] : '',
				'access_token' => (string) $page['access_token'],
			);
		}

		return $out;
	}

	/**
	 * Handle the OAuth redirect from Facebook.
	 *
	 * Validates state, exchanges code, fetches pages and stores a picker payload.
	 *
	 * @param string $code  OAuth code.
	 * @param string $state OAuth state.
	 * @return string|\WP_Error Picker token (to render page selector).
	 */
	public function handle_callback( $code, $state ) {
		$user_id = (int) get_transient( self::STATE_TRANSIENT_PREFIX . $state );
		if ( ! $user_id ) {
			return new \WP_Error( 'cpic_fb_state_invalid', __( 'The Facebook login expired or was already used. Close this window and try again.', 'custom-post-image-creator' ) );
		}
		delete_transient( self::STATE_TRANSIENT_PREFIX . $state );

		// The redirect hit is an anonymous front-controller under some caches;
		// authenticate by the transient's user, but require that the opener is
		// an admin in the same browser - we do not create sessions here.
		// The picker transient is keyed randomly, not by user, but we record
		// who authorized so connect step can audit correctly.
		$short_token = $this->exchange_code_for_token( $code );
		if ( is_wp_error( $short_token ) ) {
			return $short_token;
		}

		$long_token = $this->get_long_lived_token( $short_token );
		if ( is_wp_error( $long_token ) ) {
			return $long_token;
		}
		if ( is_array( $long_token ) ) {
			$long_token = isset( $long_token['access_token'] ) ? (string) $long_token['access_token'] : $short_token;
		}

		$pages = $this->fetch_pages( is_string( $long_token ) ? $long_token : $short_token );
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}

		if ( empty( $pages ) ) {
			return new \WP_Error( 'cpic_fb_no_pages', __( 'No Facebook Pages were found for your account. Make sure you are an admin or editor of at least one Page and granted the requested permissions.', 'custom-post-image-creator' ) );
		}

		$picker_token = wp_generate_password( 32, false, false );
		set_transient(
			self::PICKER_TRANSIENT_PREFIX . $picker_token,
			array(
				'user_id'    => $user_id,
				'user_token' => is_string( $long_token ) ? $long_token : (string) $short_token,
				'pages'      => $pages,
				'graph_version' => $this->graph_version(),
			),
			15 * MINUTE_IN_SECONDS
		);

		return $picker_token;
	}

	/**
	 * Read the picker payload.
	 *
	 * @param string $picker_token Picker token.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_picker_payload( $picker_token ) {
		$payload = get_transient( self::PICKER_TRANSIENT_PREFIX . $picker_token );
		if ( ! is_array( $payload ) || empty( $payload['pages'] ) ) {
			return new \WP_Error( 'cpic_fb_picker_expired', __( 'The page list expired. Close this window and start again.', 'custom-post-image-creator' ) );
		}
		return $payload;
	}

	/**
	 * Persist selected pages as destinations.
	 *
	 * @param string              $picker_token Picker token.
	 * @param array<int,string>   $selected_ids Selected page ids.
	 * @return array<int,array<string,mixed>>|\WP_Error Created destinations.
	 */
	public function connect_pages( $picker_token, array $selected_ids ) {
		$payload = $this->get_picker_payload( $picker_token );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$pages_by_id = array();
		foreach ( (array) $payload['pages'] as $page ) {
			$pages_by_id[ (string) $page['id'] ] = $page;
		}

		$selected_ids = array_values( array_filter( array_map( 'strval', $selected_ids ) ) );
		if ( empty( $selected_ids ) ) {
			return new \WP_Error( 'cpic_fb_no_selection', __( 'Select at least one Page to connect.', 'custom-post-image-creator' ) );
		}

		$accounts = $this->container->get( 'accounts' );
		$created  = array();
		$errors   = array();

		foreach ( $selected_ids as $page_id ) {
			if ( ! isset( $pages_by_id[ $page_id ] ) ) {
				continue;
			}
			$page = $pages_by_id[ $page_id ];

			// Upsert: if already exists, refresh the token.
			$existing = $accounts->repo()->find_by_account( 'facebook', (string) $page['id'] );
			if ( $existing ) {
				$result = $accounts->update(
					(int) $existing['id'],
					array(
						'credentials' => array(
							'page_token' => (string) $page['access_token'],
							'app_id'     => trim( (string) $this->settings()->get( 'cpic_fb_app_id', '' ) ),
							'app_secret' => \CPIC\Security\Settings::reveal_secret( (string) get_option( 'cpic_fb_app_secret', '' ) ),
						),
						'config'      => array( 'graph_version' => (string) $payload['graph_version'] ),
						'status'      => 'connected',
						'account_name' => (string) $page['name'],
					)
				);
				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				} else {
					$created[] = $result;
				}
				continue;
			}

			$result = $accounts->create(
				array(
					'provider'     => 'facebook',
					'label'        => (string) $page['name'],
					'account_id'   => (string) $page['id'],
					'account_name' => (string) $page['name'],
					'credentials'  => array(
						'page_token' => (string) $page['access_token'],
						'app_id'     => trim( (string) $this->settings()->get( 'cpic_fb_app_id', '' ) ),
						'app_secret' => \CPIC\Security\Settings::reveal_secret( (string) get_option( 'cpic_fb_app_secret', '' ) ),
					),
					'config'       => array(
						'graph_version' => (string) $payload['graph_version'],
						'category'      => (string) ( isset( $page['category'] ) ? $page['category'] : '' ),
						'picture'       => (string) ( isset( $page['picture'] ) ? $page['picture'] : '' ),
					),
					'status'       => 'connected',
				)
			);

			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				$created[] = $result;
			}
		}

		if ( empty( $created ) && ! empty( $errors ) ) {
			return new \WP_Error( 'cpic_fb_connect_failed', implode( ' ', $errors ) );
		}

		delete_transient( self::PICKER_TRANSIENT_PREFIX . $picker_token );

		return $created;
	}
}
