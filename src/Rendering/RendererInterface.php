<?php
/**
 * Renderer contract.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Rendering;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A renderer turns a render plan into a PNG file.
 *
 * The browser renderer produces images in the visitor's browser (correct
 * Bengali shaping, no server dependency). The GD renderer is a documented,
 * limited fallback for latin-only text. Third parties may register additional
 * renderers (for example a Chromium service) through
 * `cpic/renderers`.
 */
interface RendererInterface {

	/**
	 * Stable identifier, e.g. `browser`, `gd`.
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
	 * Whether this renderer can run in the current environment.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Whether this renderer can be executed from a background request (no
	 * browser). Used to decide between "render now" and "wait for browser".
	 *
	 * @return bool
	 */
	public function runs_in_background();

	/**
	 * Whether the renderer supports complex script shaping (Bengali etc.).
	 *
	 * @return bool
	 */
	public function supports_complex_scripts();

	/**
	 * Render a plan.
	 *
	 * @param array<string,mixed> $plan Render plan.
	 * @return array{path:string,url:string,width:int,height:int,checksum:string,mime:string}|\WP_Error
	 */
	public function render( array $plan );
}
