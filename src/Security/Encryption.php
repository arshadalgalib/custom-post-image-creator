<?php
/**
 * Credential encryption at rest.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AES-256-GCM (with a CBC fallback) keyed from the WordPress salts.
 *
 * Ciphertext format: `cpic1:<base64(iv|tag|payload)>`. Anything that does not
 * carry the prefix is treated as legacy plain text and returned as-is so that
 * existing 6.x installation data keeps working after the upgrade.
 */
final class Encryption {

	/**
	 * Ciphertext prefix.
	 */
	const PREFIX = 'cpic1:';

	/**
	 * Derive the encryption key.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function key() {
		$material = '';

		if ( defined( 'AUTH_KEY' ) ) {
			$material .= AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$material .= SECURE_AUTH_KEY;
		}
		if ( defined( 'NONCE_SALT' ) ) {
			$material .= NONCE_SALT;
		}
		if ( '' === $material ) {
			// Extremely defensive: never leave credentials in plain text, even
			// when salts are missing (a misconfigured install).
			$material = 'cpic-fallback-' . ( defined( 'DB_NAME' ) ? DB_NAME : 'db' );
		}

		return hash( 'sha256', 'cpic-encryption|' . $material, true );
	}

	/**
	 * Encrypt a string.
	 *
	 * @param string $plain Plain text.
	 * @return string Ciphertext with prefix.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}

		$key = self::key();

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );

			if ( false !== $cipher ) {
				return self::PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}

			// CBC fallback (HMAC authenticated).
			$iv     = random_bytes( 16 );
			$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

			if ( false !== $cipher ) {
				$mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );
				return 'cpic1c:' . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}

		// This PHP build cannot encrypt (OpenSSL missing). Fail closed: never
		// write a credential with reversible obfuscation that the admin screen
		// would present as encryption. Callers surface a notice instead.
		return '';
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $stored Stored value.
	 * @return string Plain text (or the input when it is legacy plain text).
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}

		$key = self::key();

		if ( 0 === strpos( $stored, self::PREFIX ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) < 29 ) {
				return '';
			}

			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );

			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}

			$plain = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

			return false === $plain ? '' : $plain;
		}

		if ( 0 === strpos( $stored, 'cpic1c:' ) ) {
			$raw = base64_decode( substr( $stored, 7 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) < 33 ) {
				return '';
			}

			$iv     = substr( $raw, 0, 16 );
			$mac    = substr( $raw, 16, 32 );
			$cipher = substr( $raw, 48 );

			if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $key, true ), $mac ) ) {
				return '';
			}

			$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

			return false === $plain ? '' : $plain;
		}

		// Ciphertext written by an earlier build that only had the obfuscation
		// fallback. Read-only support so those installs can migrate.
		if ( 0 === strpos( $stored, 'cpic0:' ) ) {
			$raw = base64_decode( substr( $stored, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw ) {
				return '';
			}
			return $raw ^ str_repeat( substr( $key, 0, 16 ), (int) ceil( strlen( $raw ) / 16 ) );
		}

		// Legacy plain-text value from 6.x.
		return $stored;
	}

	/**
	 * Whether a stored value looks encrypted.
	 *
	 * @param string $stored Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $stored ) {
		$stored = (string) $stored;
		return 0 === strpos( $stored, 'cpic1' ) || 0 === strpos( $stored, 'cpic0:' );
	}

	/**
	 * Whether this PHP build can actually encrypt.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Redact a value for logs / API output.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function redact( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		$len = strlen( $value );
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}
		return substr( $value, 0, 3 ) . str_repeat( '•', 6 ) . substr( $value, -2 );
	}
}
