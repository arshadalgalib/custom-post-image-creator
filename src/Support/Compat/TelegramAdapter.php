<?php
/**
 * 6.x compatibility shim: TelegramAdapter.
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
 * 6.x `CPIC_Telegram_Poster` shim.
 */
final class TelegramAdapter {

	/**
	 * Publish a post to the default Telegram chat.
	 *
	 * @param int   $post_id Post id.
	 * @param array $args    Options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function publish( $post_id, $args = array() ) {
		$args['providers'] = array( 'telegram' );

		return \CPIC\Core\Plugin::instance()->service( 'delivery_manager' )->publish_post( (int) $post_id, $args );
	}
}
