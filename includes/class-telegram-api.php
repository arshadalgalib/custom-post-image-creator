<?php
/**
 * Telegram Bot API integration.
 *
 * • Send photo + caption to a Telegram channel / group
 * • Caption: Post Title · Custom Message · Post Link (≤ 1024 chars)
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Telegram_API {

    /** @var string */
    const API_BASE = 'https://api.telegram.org/bot';

    public function __construct() {
        add_action( 'wp_ajax_cpic_tg_share', array( $this, 'ajax_share' ) );
        add_action( 'wp_ajax_cpic_tg_test',  array( $this, 'ajax_test' ) );
    }

    /* ─── AJAX: share ─────────────────────────────────── */

    public function ajax_share() {
        cpic()->security->verify_ajax_request();

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post.', 'custom-post-image-creator' ) ) );
        }

        // Server-side template generation (bypasses client-side canvas).
        $tpl = cpic()->category_mapper->resolve_template_for_post( $post_id );
        $generated_path = '';

        if ( $tpl && ! empty( $tpl['config'] ) ) {
            $img = cpic()->image_generator->generate_from_template( $post_id, $tpl['config'] );
            if ( ! is_wp_error( $img ) ) {
                $generated_path = $img['path'];
            }
        }

        // Fallback: legacy generate when no template resolved.
        if ( empty( $generated_path ) || ! file_exists( $generated_path ) ) {
            $img = cpic()->image_generator->generate( $post_id );
            if ( ! is_wp_error( $img ) ) {
                $generated_path = $img['path'];
            }
        }

        if ( empty( $generated_path ) || ! file_exists( $generated_path ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not generate photocard image.', 'custom-post-image-creator' ) ) );
        }

        $result = $this->share( $post_id, $generated_path );

        // Remove generated temp file.
        if ( $generated_path && file_exists( $generated_path ) ) {
            wp_delete_file( $generated_path );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * Create a temp PNG file from a canvas data URL.
     *
     * @param string $data_url Canvas data URL.
     * @param int    $post_id  Post ID.
     * @return string|WP_Error
     */
    private function create_temp_png_from_data_url( $data_url, $post_id ) {
        $data_url = trim( (string) $data_url );
        if ( empty( $data_url ) || strpos( $data_url, 'data:image/png;base64,' ) !== 0 ) {
            return new WP_Error( 'tg_canvas_invalid', __( 'Invalid canvas data.', 'custom-post-image-creator' ) );
        }

        $raw = substr( $data_url, strlen( 'data:image/png;base64,' ) );
        $bin = base64_decode( str_replace( ' ', '+', $raw ), true );
        if ( false === $bin ) {
            return new WP_Error( 'tg_canvas_decode', __( 'Could not decode canvas image.', 'custom-post-image-creator' ) );
        }

        $filename = 'cpic-canvas-' . $post_id . '-' . time() . '.png';
        $saved    = wp_upload_bits( $filename, null, $bin );

        if ( ! empty( $saved['error'] ) ) {
            return new WP_Error( 'tg_canvas_save', $saved['error'] );
        }

        return $saved['file'];
    }

    /**
     * Store uploaded preview canvas file in uploads.
     *
     * @param array $file    Uploaded file array.
     * @param int   $post_id Post ID.
     * @return string|WP_Error
     */
    private function create_temp_png_from_upload( $file, $post_id ) {
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'tg_canvas_upload', __( 'Invalid uploaded canvas file.', 'custom-post-image-creator' ) );
        }

        $filename = 'cpic-canvas-' . $post_id . '-' . time() . '.png';
        $bits     = wp_upload_bits( $filename, null, file_get_contents( $file['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded temp file

        if ( ! empty( $bits['error'] ) ) {
            return new WP_Error( 'tg_canvas_save', $bits['error'] );
        }

        return $bits['file'];
    }

    /**
     * Sanitize image settings overrides sent from the admin UI.
     *
     * @param array $settings Raw settings.
     * @return array
     */
    private function sanitize_settings_overrides( $settings ) {
        $clean      = array();
        $text_keys  = array( 'cpic_title_font', 'cpic_date_font', 'cpic_comment_message' );
        $color_keys = array( 'cpic_title_color', 'cpic_date_color', 'cpic_date_bg_color' );
        $int_keys   = array(
            'cpic_title_size', 'cpic_title_offset_x', 'cpic_title_offset_y',
            'cpic_date_offset_x', 'cpic_date_offset_y',
            'cpic_logo_width', 'cpic_logo_offset_x', 'cpic_logo_offset_y',
            'cpic_feat_offset_x', 'cpic_feat_offset_y', 'cpic_feat_width', 'cpic_feat_height',
            'cpic_canvas_width', 'cpic_canvas_height',
        );
        $url_keys   = array( 'cpic_logo', 'cpic_active_background' );

        foreach ( $text_keys as $key ) {
            if ( isset( $settings[ $key ] ) ) {
                $clean[ $key ] = sanitize_text_field( $settings[ $key ] );
            }
        }

        foreach ( $color_keys as $key ) {
            if ( isset( $settings[ $key ] ) ) {
                $clean[ $key ] = sanitize_hex_color( $settings[ $key ] ) ?: '';
            }
        }

        foreach ( $int_keys as $key ) {
            if ( isset( $settings[ $key ] ) ) {
                $clean[ $key ] = intval( $settings[ $key ] );
            }
        }

        foreach ( $url_keys as $key ) {
            if ( isset( $settings[ $key ] ) ) {
                $clean[ $key ] = esc_url_raw( $settings[ $key ] );
            }
        }

        return $clean;
    }

    /* ─── AJAX: test connection ───────────────────────── */

    public function ajax_test() {
        cpic()->security->verify_ajax_request();

        $token = cpic()->settings->get( 'cpic_tg_bot_token' );
        if ( empty( $token ) ) {
            wp_send_json_error( array( 'message' => __( 'Bot token not set.', 'custom-post-image-creator' ) ) );
        }

        $url      = self::API_BASE . $token . '/getMe';
        $response = wp_remote_get( $url, array( 'timeout' => 15 ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['ok'] ) ) {
            wp_send_json_error( array( 'message' => $body['description'] ?? __( 'Connection failed.', 'custom-post-image-creator' ) ) );
        }

        wp_send_json_success( array( 'bot' => $body['result'] ) );
    }

    /* ─── Public share method ─────────────────────────── */

    /**
     * Send photocard to Telegram.
     *
     * @param int    $post_id    WP Post ID.
     * @param string $image_path Optional pre-generated image path.
     * @return array|WP_Error
     */
    public function share( $post_id, $image_path = '', $overrides = array() ) {
        $token   = cpic()->settings->get( 'cpic_tg_bot_token' );
        $chat_id = cpic()->settings->get( 'cpic_tg_chat_id' );

        if ( empty( $token ) || empty( $chat_id ) ) {
            return new WP_Error( 'tg_not_configured', __( 'Telegram is not configured.', 'custom-post-image-creator' ) );
        }

        if ( ! function_exists( 'curl_init' ) || ! class_exists( 'CURLFile' ) ) {
            return new WP_Error( 'tg_curl_missing', __( 'The cURL PHP extension is required for Telegram sharing.', 'custom-post-image-creator' ) );
        }

        // Duplicate check.
        $last = get_post_meta( $post_id, 'cpic_last_shared_to_telegram', true );
        if ( $last && ( time() - strtotime( $last ) ) < 60 ) {
            return new WP_Error( 'tg_duplicate', __( 'This post was shared to Telegram less than a minute ago.', 'custom-post-image-creator' ) );
        }

        $generated_path = '';
        if ( empty( $image_path ) || ! file_exists( $image_path ) ) {
            $tpl = cpic()->category_mapper->resolve_template_for_post( $post_id );
            if ( $tpl && ! empty( $tpl['config'] ) ) {
                $img = cpic()->image_generator->generate_from_template( $post_id, $tpl['config'] );
            } else {
                $img = cpic()->image_generator->generate( $post_id );
            }
            if ( is_wp_error( $img ) ) {
                return $img;
            }
            $image_path     = $img['path'];
            $generated_path = $img['path'];
        }

        // Build caption (max 1024 chars).
        $caption = $this->build_caption( $post_id );

        // Send photo via cURL with CURLFile for reliable binary handling.
        $url = self::API_BASE . $token . '/sendPhoto';

        $ch = curl_init( $url );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, array(
            'chat_id'    => $chat_id,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
            'photo'      => new \CURLFile( $image_path, 'image/png', basename( $image_path ) ),
        ) );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 30 );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
        $raw_response = curl_exec( $ch );
        $curl_error   = curl_error( $ch );
        curl_close( $ch );

        // Cleanup (only if we generated it).
        if ( $generated_path ) {
            cpic()->image_generator->cleanup( $generated_path );
        }

        if ( $curl_error ) {
            CPIC_Security::log( 'telegram', 'cURL error', $curl_error );
            return new WP_Error( 'tg_curl_error', $curl_error );
        }

        $resp_body = json_decode( $raw_response, true );

        if ( empty( $resp_body['ok'] ) ) {
            CPIC_Security::log( 'telegram', 'API error', $resp_body );
            return new WP_Error( 'tg_api_error', $resp_body['description'] ?? __( 'Telegram API error.', 'custom-post-image-creator' ) );
        }

        update_post_meta( $post_id, 'cpic_last_shared_to_telegram', current_time( 'mysql' ) );

        return array(
            'message'    => __( 'Sent to Telegram.', 'custom-post-image-creator' ),
            'message_id' => $resp_body['result']['message_id'] ?? '',
        );
    }

    /* ─── Caption builder ─────────────────────────────── */

    private function build_caption( $post_id ) {
        $title     = get_the_title( $post_id );
        $comment   = cpic()->settings->get( 'cpic_comment_message' );
        $permalink = get_permalink( $post_id );

        // Escape for HTML parse mode so titles containing &, <, > do not break the API.
        $title   = htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' );
        $comment = htmlspecialchars( $comment, ENT_QUOTES, 'UTF-8' );

        $caption = $title . "\n\n" . $comment . "\n" . $permalink;

        // Telegram caption limit.
        if ( mb_strlen( $caption, 'UTF-8' ) > 1024 ) {
            $allowed = 1024 - mb_strlen( "\n\n" . $comment . "\n" . $permalink, 'UTF-8' );
            $title   = mb_substr( $title, 0, max( $allowed, 10 ), 'UTF-8' ) . '…';
            $caption = $title . "\n\n" . $comment . "\n" . $permalink;
        }

        return $caption;
    }

}
