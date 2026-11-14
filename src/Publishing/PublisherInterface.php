<?php
/**
 * Publisher contract.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Publishing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A provider (Facebook page, Telegram channel, third-party add-on).
 */
interface PublisherInterface {

	/**
	 * Provider id, e.g. `facebook`.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Credential keys this provider needs.
	 *
	 * @return array<int,string>
	 */
	public function credential_fields();

	/**
	 * Maximum caption length.
	 *
	 * @return int
	 */
	public function caption_limit();

	/**
	 * Required image format ('' = square PNG is fine).
	 *
	 * @return string
	 */
	public function preferred_format();

	/**
	 * Whether the provider can be configured without a browser round-trip.
	 *
	 * @return bool
	 */
	public function is_configured( array $destination );

	/**
	 * Validate credentials against the remote API.
	 *
	 * @param array<string,mixed> $destination Raw destination row.
	 * @return array<string,mixed>|\WP_Error {account_name, account_id, scopes…}
	 */
	public function verify( array $destination );

	/**
	 * Publish one delivery.
	 *
	 * @param array<string,mixed> $destination Raw destination row.
	 * @param array<string,mixed> $payload     {
	 *     @type array $artifact Artifact row (file_path, mime, width, height).
	 *     @type string $caption Caption text.
	 *     @type int    $post_id Post id.
	 *     @type string $first_comment Optional first comment.
	 *     @type string $link    Permalink.
	 * }
	 * @return PublishResult
	 */
	public function publish( array $destination, array $payload );

	/**
	 * Delete a published post (used by "undo publish").
	 *
	 * @param array<string,mixed> $destination Raw destination row.
	 * @param string              $remote_id   Remote post id.
	 * @return bool|\WP_Error
	 */
	public function delete( array $destination, $remote_id );
}
