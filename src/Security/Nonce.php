<?php
/**
 * Nonce helpers.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central nonce action names so JS and PHP cannot drift apart.
 */
final class Nonce {

	/**
	 * Generic admin AJAX action.
	 */
	const AJAX = 'cpic_nonce_action';

	/**
	 * Field name used by AJAX requests.
	 */
	const FIELD = 'cpic_nonce';

	/**
	 * REST nonce action (`wp_rest`).
	 */
	const REST = 'wp_rest';

	/**
	 * Nonce for the design studio autosave channel.
	 */
	const STUDIO = 'cpic_studio_nonce';

	/**
	 * Create the AJAX nonce.
	 *
	 * @return string
	 */
	public function create_ajax() {
		return wp_create_nonce( self::AJAX );
	}

	/**
	 * Create a REST nonce.
	 *
	 * @return string
	 */
	public function create_rest() {
		return wp_create_nonce( self::REST );
	}

	/**
	 * Verify an AJAX nonce from `$_REQUEST`.
	 *
	 * @return bool
	 */
	public function verify_ajax() {
		$nonce = isset( $_REQUEST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return (bool) wp_verify_nonce( $nonce, self::AJAX );
	}

	/**
	 * Verify a REST nonce from the request headers.
	 *
	 * @param \WP_REST_Request|null $request Request.
	 * @return bool
	 */
	public function verify_rest( $request = null ) {
		if ( null === $request ) {
			return false;
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			return false;
		}
		return (bool) wp_verify_nonce( $nonce, self::REST );
	}
}
