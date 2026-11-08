<?php
/**
 * Browser-generated PNG context and safe artifact persistence.
 *
 * @package CustomPostImageCreator
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CPIC_Canvas_Artifacts {
    const RENDERER = 'cpic-canvas-1';

    public static function context_for_post( $post_id ) {
        $post_id = absint( $post_id );
        $post = get_post( $post_id );
        if ( ! $post ) { return new WP_Error( 'invalid_post', __( 'Post not found.', 'custom-post-image-creator' ) ); }
        $template = cpic()->category_mapper->resolve_template_for_post( $post_id );
        if ( ! $template || empty( $template['config'] ) ) { return new WP_Error( 'template_missing', __( 'No active template resolved for this post.', 'custom-post-image-creator' ) ); }
        $config = CPIC_Template_Manager::normalize_config( $template['config'] );
        $date_format = isset( $config['elements']['date']['format'] ) ? $config['elements']['date']['format'] : 'bangla';
        $data = array(
            'title' => get_the_title( $post_id ),
            'category' => cpic()->category_mapper->resolve_category_label( $post_id ),
            'date' => 'bangla' === $date_format ? CPIC_Bangla_Date::format( get_post_timestamp( $post_id ) ) : get_the_date( '', $post_id ),
            'author' => get_the_author_meta( 'display_name', (int) $post->post_author ),
            'website' => get_bloginfo( 'name' ),
            'featured_image' => get_the_post_thumbnail_url( $post_id, 'full' ) ?: '',
        );
        $template_hash = hash( 'sha256', wp_json_encode( array( 'id' => (int) $template['id'], 'updated_at' => $template['updated_at'] ?? '', 'config' => $config ) ) );
        $input_hash = hash( 'sha256', wp_json_encode( $data ) );
        return array(
            'post_id' => $post_id,
            'template_id' => (int) $template['id'],
            'template_name' => $template['name'],
            'config' => $config,
            'data' => $data,
            'template_hash' => $template_hash,
            'input_hash' => $input_hash,
            'renderer' => self::RENDERER,
        );
    }

    /** Validate, persist, and associate a browser-generated PNG with a post. */
    public static function save_for_post( $post_id, $png_data, $template_hash, $input_hash ) {
        $context = self::context_for_post( $post_id );
        if ( is_wp_error( $context ) ) { return $context; }
        if ( ! hash_equals( $context['template_hash'], (string) $template_hash ) || ! hash_equals( $context['input_hash'], (string) $input_hash ) ) {
            return new WP_Error( 'stale_render', __( 'The post or template changed while rendering. Refresh and render again.', 'custom-post-image-creator' ) );
        }
        if ( ! is_string( $png_data ) || strlen( $png_data ) < 24 || strlen( $png_data ) > 15 * MB_IN_BYTES ) {
            return new WP_Error( 'invalid_image_size', __( 'PNG must be between 24 bytes and 15 MB.', 'custom-post-image-creator' ) );
        }
        if ( substr( $png_data, 0, 8 ) !== "\x89PNG\r\n\x1a\n" || ! function_exists( 'getimagesizefromstring' ) ) {
            return new WP_Error( 'invalid_png', __( 'The uploaded image is not a valid PNG.', 'custom-post-image-creator' ) );
        }
        $image_info = @getimagesizefromstring( $png_data );
        if ( ! is_array( $image_info ) || IMAGETYPE_PNG !== (int) $image_info[2] || $image_info[0] < 64 || $image_info[1] < 64 || $image_info[0] > 4096 || $image_info[1] > 4096 ) {
            return new WP_Error( 'invalid_png_dimensions', __( 'PNG dimensions must be between 64 and 4096 pixels.', 'custom-post-image-creator' ) );
        }
        $upload = wp_upload_dir();
        $dir = trailingslashit( $upload['basedir'] ) . 'cpic-generated/';
        if ( ! wp_mkdir_p( $dir ) || ! is_writable( $dir ) ) { return new WP_Error( 'upload_dir_unwritable', __( 'The generated-image directory is not writable.', 'custom-post-image-creator' ) ); }
        $sha = hash( 'sha256', $png_data );
        $filename = 'cpic-' . absint( $post_id ) . '-' . substr( $sha, 0, 20 ) . '.png';
        $path = $dir . $filename;
        if ( is_link( $path ) ) { return new WP_Error( 'unsafe_image_path', __( 'Generated-image path is not a regular file.', 'custom-post-image-creator' ) ); }
        if ( ! is_file( $path ) || hash_file( 'sha256', $path ) !== $sha ) {
            $written = @file_put_contents( $path, $png_data, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            if ( false === $written || $written !== strlen( $png_data ) || hash_file( 'sha256', $path ) !== $sha ) { return new WP_Error( 'image_write_failed', __( 'Could not save generated PNG.', 'custom-post-image-creator' ) ); }
        }
        $url = trailingslashit( $upload['baseurl'] ) . 'cpic-generated/' . rawurlencode( $filename );
        update_post_meta( $post_id, '_cpic_rendered_image_path', $path );
        update_post_meta( $post_id, '_cpic_rendered_image_url', esc_url_raw( $url ) );
        update_post_meta( $post_id, '_cpic_render_template_hash', $context['template_hash'] );
        update_post_meta( $post_id, '_cpic_render_input_hash', $context['input_hash'] );
        update_post_meta( $post_id, '_cpic_render_renderer', self::RENDERER );
        update_post_meta( $post_id, '_cpic_render_artifact_sha256', $sha );
        update_post_meta( $post_id, '_cpic_rendered_at', time() );
        $was_waiting = (int) get_post_meta( $post_id, '_cpic_render_wait_since', true );
        delete_post_meta( $post_id, '_cpic_render_wait_since' );

        $job = cpic()->queue->get_job_by_post( $post_id );
        if ( $job && ! in_array( $job['status'], array( 'published', 'failed', 'processing', 'publishing' ), true ) && empty( $job['facebook_post_id'] ) && empty( $job['telegram_message_id'] ) ) {
            cpic()->queue->update( (int) $job['id'], array(
                'template_id' => $context['template_id'],
                'image_path' => $path,
                'image_url' => $url,
                'renderer_id' => self::RENDERER,
                'input_hash' => $context['input_hash'],
                'template_hash' => $context['template_hash'],
                'rendered_at' => current_time( 'mysql' ),
                'status' => 'generated',
                'last_error' => '',
                'next_attempt_at' => $was_waiting ? time() : max( time(), (int) $job['next_attempt_at'] ),
            ) );
            $run_at = $was_waiting ? time() + 5 : max( time() + 5, (int) $job['next_attempt_at'] );
            wp_schedule_single_event( $run_at, 'cpic_process_single_job', array( absint( $post_id ) ) );
        }
        self::prune_stale_files();
        return array( 'url' => $url, 'path' => $path, 'sha256' => $sha, 'renderer' => self::RENDERER, 'template_hash' => $context['template_hash'], 'input_hash' => $context['input_hash'] );
    }

    /** Remove only old files that are no longer referenced by posts or jobs. */
    private static function prune_stale_files() {
        if ( get_transient( 'cpic_canvas_prune_lock' ) ) { return; }
        set_transient( 'cpic_canvas_prune_lock', 1, DAY_IN_SECONDS );
        $upload = wp_upload_dir();
        $dir = trailingslashit( $upload['basedir'] ) . 'cpic-generated/';
        if ( ! is_dir( $dir ) ) { return; }
        global $wpdb;
        $references = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_cpic_rendered_image_path' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $table = $wpdb->prefix . 'cpic_jobs';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $exists ) {
            $job_paths = $wpdb->get_col( "SELECT image_path FROM {$table} WHERE image_path IS NOT NULL AND image_path != ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $references = array_merge( is_array( $references ) ? $references : array(), is_array( $job_paths ) ? $job_paths : array() );
        }
        $keep = array();
        foreach ( is_array( $references ) ? $references : array() as $reference ) {
            $real = realpath( $reference );
            if ( $real ) { $keep[ $real ] = true; }
        }
        foreach ( (array) glob( $dir . 'cpic-*.png' ) as $file ) {
            $real = realpath( $file );
            if ( $real && empty( $keep[ $real ] ) && filemtime( $real ) < time() - ( 30 * DAY_IN_SECONDS ) ) { wp_delete_file( $real ); }
        }
        foreach ( (array) glob( $dir . 'cpic-bulk-*.zip' ) as $archive ) {
            if ( is_file( $archive ) && filemtime( $archive ) < time() - DAY_IN_SECONDS ) { wp_delete_file( $archive ); }
        }
    }

    public static function current_for_post( $post_id ) {
        $context = self::context_for_post( $post_id );
        if ( is_wp_error( $context ) ) { return $context; }
        $path = get_post_meta( $post_id, '_cpic_rendered_image_path', true );
        $url = get_post_meta( $post_id, '_cpic_rendered_image_url', true );
        $upload = wp_upload_dir();
        $base = realpath( trailingslashit( $upload['basedir'] ) . 'cpic-generated' );
        $real = $path ? realpath( $path ) : false;
        $root_prefix = $base ? trailingslashit( $base ) : '';
        $saved_sha = get_post_meta( $post_id, '_cpic_render_artifact_sha256', true );
        $actual_sha = $real && is_file( $real ) ? (string) hash_file( 'sha256', $real ) : '';
        $expected_url = $real ? trailingslashit( $upload['baseurl'] ) . 'cpic-generated/' . rawurlencode( basename( $real ) ) : '';
        if ( ! $real || ! $base || 0 !== strpos( $real, $root_prefix ) || ! $url || $url !== $expected_url || ! $saved_sha || ! hash_equals( $saved_sha, $actual_sha ) || get_post_meta( $post_id, '_cpic_render_template_hash', true ) !== $context['template_hash'] || get_post_meta( $post_id, '_cpic_render_input_hash', true ) !== $context['input_hash'] ) {
            return new WP_Error( 'browser_artifact_missing', __( 'No current browser-rendered PNG is available. Open and save this post in the WordPress editor (or render it from the dashboard) before publishing.', 'custom-post-image-creator' ) );
        }
        return array( 'path' => $real, 'url' => $url, 'template_id' => $context['template_id'], 'template_hash' => $context['template_hash'], 'input_hash' => $context['input_hash'], 'renderer' => self::RENDERER );
    }
}
