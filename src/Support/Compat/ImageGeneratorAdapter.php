<?php
/**
 * 6.x compatibility shim: ImageGeneratorAdapter.
 *
 * One class per file so the autoloader can find it; the shared helper lives in
 * `bootstrap.php` and is required below.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Support\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

/**
 * 6.x `CPIC_Image_Generator` shim.
 */
final class ImageGeneratorAdapter {

	/**
	 * Generate an image for a post (legacy signature).
	 *
	 * @param int   $post_id   Post id.
	 * @param array $template  Optional legacy template config.
	 * @param array $overrides Overrides.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function generate( $post_id, $template = array(), $overrides = array() ) {
		$args = array();

		if ( is_array( $template ) && ! empty( $template ) ) {
			$args['document'] = \CPIC\Core\Plugin::instance()->service( 'template_schema' )->migrate_legacy( $template );
		}

		$args['overrides'] = is_array( $overrides ) ? $overrides : array();

		return \CPIC\Core\Plugin::instance()->service( 'render' )->render( (int) $post_id, $args );
	}

	/**
	 * The render plan for a post (used by 6.x previews).
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function plan( $post_id ) {
		return \CPIC\Core\Plugin::instance()->service( 'render' )->plan( (int) $post_id );
	}
}
