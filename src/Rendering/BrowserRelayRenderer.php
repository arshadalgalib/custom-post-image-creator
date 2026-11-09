<?php
/**
 * Browser renderer descriptor.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Rendering;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The browser renderer executes in the admin/editor UI with Canvas 2D, which
 * gives correct Bengali shaping for free. It cannot run inside WP-Cron, so this
 * class reports unavailability for background work and provides the manual
 * "render now" contract instead of pretending cron can capture a canvas.
 */
final class BrowserRelayRenderer implements RendererInterface {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'browser';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Browser (Canvas 2D, full Unicode shaping)', 'custom-post-image-creator' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		// Available to signed-in admins with capability in a browser context.
		return is_admin() && current_user_can( 'cpic_access' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function runs_in_background() {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_complex_scripts() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $plan ) {
		return new \WP_Error(
			'cpic_browser_only',
			__( 'The browser renderer runs in the WordPress editor. Server-side generation of Bengali artwork is intentionally not faked — use the Regenerate button, or configure an external renderer.', 'custom-post-image-creator' ),
			array( 'status' => 409 )
		);
	}
}
