<?php
/**
 * Editor module.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Core\Providers;

use CPIC\Core\Container;
use CPIC\Core\ServiceProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post editor integration (block editor panel + classic metabox) and the
 * front-end post image helper.
 */
final class EditorProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'editor';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	public function boot( Container $c ) {
		$c->get( 'editor' )->register();

		// Serve the social image to themes/social plugins without extra queries.
		add_filter(
			'cpic/post_image_url',
			static function ( $url, $post_id ) use ( $c ) {
				if ( '' !== (string) $url ) {
					return $url;
				}

				$artifact = $c->get( 'artifact_manager' )->latest( (int) $post_id );

				return $artifact && ! empty( $artifact['exists'] ) ? (string) $artifact['file_url'] : '';
			},
			10,
			2
		);
	}
}
