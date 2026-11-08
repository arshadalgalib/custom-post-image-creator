<?php
/**
 * Synchronous server renderer compatibility facade.
 *
 * The canonical renderer is Canvas 2D in the authenticated WordPress browser.
 * Server-side PHP must never substitute an unshaped font engine: it returns an
 * already-captured artifact when present, otherwise an actionable error.
 *
 * @package CustomPostImageCreator
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CPIC_Renderer {
    /** Retained for compatibility with integrations inspecting old diagnostics. */
    public static $last_text_mode = 'browser-canvas';
    public static $last_typography = array();

    /** Return the browser-created canonical file; never render a second copy. */
    public function render_for_post( $post_id, $config = array() ) {
        return CPIC_Canvas_Artifacts::current_for_post( absint( $post_id ) );
    }

    /** PHP cannot perform the canonical browser render. */
    public function render( $data, $config, $post_id = 0 ) {
        return new WP_Error(
            'browser_render_required',
            __( 'This template must be rendered in the WordPress browser using Canvas 2D. Open the post editor or use the dashboard preview; background PHP does not replace the browser renderer.', 'custom-post-image-creator' )
        );
    }

    /** Diagnostic replacement for the removed Pango/Imagick engine report. */
    public static function text_engine_report() {
        return array(
            'renderer' => CPIC_Canvas_Artifacts::RENDERER,
            'location' => 'browser',
            'server_rendering' => false,
        );
    }
}
