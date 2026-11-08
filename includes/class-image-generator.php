<?php
/**
 * Server-side image generation using Imagick.
 *
 * Generates a 1080×1080 (or custom size) photocard layering:
 *   Background → Featured Image → Title → Date → Logo → Caption
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Image_Generator {

    public function __construct() {
        add_action( 'wp_ajax_cpic_generate_image', array( $this, 'ajax_generate' ) );
    }

    /* ─── AJAX endpoint ───────────────────────────────── */

    public function ajax_generate() {
        cpic()->security->verify_ajax_request();

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post.', 'custom-post-image-creator' ) ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied or post is not published.', 'custom-post-image-creator' ) ), 403 );
        }
        $tpl = cpic()->category_mapper->resolve_template_for_post( $post_id );
        if ( ! $tpl || empty( $tpl['config'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No active template is available for this post.', 'custom-post-image-creator' ) ) );
        }
        $result = $this->generate_from_template( $post_id, $tpl['config'] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /* ─── Template-driven generation ──────────────────── */

    /**
     * Generate a photocard from a template config.
     *
     * Conceptually: generate( $post_id, $template_config ).
     *
     * @param int   $post_id         Post ID.
     * @param array $template_config Template configuration array.
     * @return array|WP_Error Array with 'url' and 'path' keys, or WP_Error.
     */
    public function generate_from_template( $post_id, $template_config = array() ) {
        $renderer = cpic()->renderer;
        return $renderer->render_for_post( $post_id, $template_config );
    }

    /**
     * Generate a preview photocard from a template config using sample data.
     *
     * @param array $template_config Template configuration array.
     * @param array $data            Optional preview data overrides.
     * @return array|WP_Error
     */
    public function generate_template_preview( $template_config = array(), $data = array() ) {
        $renderer = cpic()->renderer;
        $sample   = wp_parse_args( $data, CPIC_Template_Manager::sample_preview_data() );
        return $renderer->render( $sample, $template_config, 0 );
    }

    /* ─── Public generate method ──────────────────────── */

    /**
     * Generate a photocard image for a post (legacy global-settings path).
     *
     * @param int   $post_id  The post ID.
     * @param array $overrides Optional settings overrides.
     * @return array|WP_Error  Array with 'url' and 'path' keys, or WP_Error.
     */
    public function generate( $post_id, $overrides = array() ) {
        // Backward-compatible entry point; configuration comes from the active
        // template/category resolver rather than the retired global renderer.
        $template = cpic()->category_mapper->resolve_template_for_post( (int) $post_id );
        if ( ! $template || empty( $template['config'] ) ) {
            return new WP_Error( 'template_missing', __( 'No active template is available for this post.', 'custom-post-image-creator' ) );
        }
        return $this->generate_from_template( (int) $post_id, $template['config'] );
    }

    public function cleanup( $path ) {
        if ( $path && file_exists( $path ) ) {
            wp_delete_file( $path );
        }
    }
}
