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
        if ( ! $post_id || ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post or permission denied.', 'custom-post-image-creator' ) ), 403 );
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

        if ( empty( $generated_path ) || ! file_exists( $generated_path ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not generate photocard image.', 'custom-post-image-creator' ) ) );
        }

        try {
            $result = $this->share( $post_id, $generated_path );
        } finally {
            if ( $generated_path && file_exists( $generated_path ) ) {
                cpic()->image_generator->cleanup( $generated_path );
            }
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
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

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $http_code < 200 || $http_code >= 300 || ! is_array( $body ) || empty( $body['ok'] ) ) {
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
            if ( ! $tpl || empty( $tpl['config'] ) ) {
                return new WP_Error( 'tg_template_missing', __( 'No active template is available for this post.', 'custom-post-image-creator' ) );
            }
            $img = cpic()->image_generator->generate_from_template( $post_id, $tpl['config'] );
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

        $lock_key = 'cpic_tg_publish_lock_' . (int) $post_id;
        $lock_value = (string) time();
        $existing_lock = get_option( $lock_key, false );
        if ( $existing_lock ) {
            if ( $generated_path ) { cpic()->image_generator->cleanup( $generated_path ); }
            $message = ( time() - (int) $existing_lock ) > 300
                ? __( 'A previous Telegram request has an ambiguous outcome. Check the destination before clearing its delivery lock.', 'custom-post-image-creator' )
                : __( 'A Telegram publication for this post is already in progress.', 'custom-post-image-creator' );
            return new WP_Error( 'tg_busy', $message );
        }
        if ( ! add_option( $lock_key, $lock_value, '', false ) ) {
            if ( $generated_path ) { cpic()->image_generator->cleanup( $generated_path ); }
            return new WP_Error( 'tg_busy', __( 'A Telegram publication for this post is already in progress.', 'custom-post-image-creator' ) );
        }

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
        $http_code    = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        if ( $generated_path ) { cpic()->image_generator->cleanup( $generated_path ); }
        if ( $curl_error ) {
            // Keep the lock after an ambiguous transport outcome to avoid a duplicate message.
            CPIC_Logger::log( 'telegram', __( 'Telegram transport failed; outcome is ambiguous.', 'custom-post-image-creator' ), array( 'post_id' => (int) $post_id ) );
            return new WP_Error( 'tg_curl_error', __( 'Telegram request failed at the transport layer; check the channel before retrying.', 'custom-post-image-creator' ) );
        }

        $resp_body = json_decode( (string) $raw_response, true );
        if ( $http_code < 200 || $http_code >= 300 || ! is_array( $resp_body ) || empty( $resp_body['ok'] ) || empty( $resp_body['result']['message_id'] ) ) {
            delete_option( $lock_key );
            $description = is_array( $resp_body ) && ! empty( $resp_body['description'] ) ? sanitize_text_field( $resp_body['description'] ) : __( 'Telegram returned an invalid response.', 'custom-post-image-creator' );
            CPIC_Logger::log( 'telegram', __( 'Telegram API rejected the photo request.', 'custom-post-image-creator' ), array( 'post_id' => (int) $post_id, 'http_code' => $http_code, 'description' => $description ) );
            return new WP_Error( 'tg_api_error', $description );
        }

        $message_id = (string) $resp_body['result']['message_id'];
        update_post_meta( $post_id, 'cpic_last_shared_to_telegram', current_time( 'mysql' ) );
        update_post_meta( $post_id, 'cpic_telegram_message_id', $message_id );
        delete_option( $lock_key );

        return array(
            'message'    => __( 'Sent to Telegram.', 'custom-post-image-creator' ),
            'message_id' => $message_id,
        );
    }

    /* ─── Caption builder ─────────────────────────────── */

    private function build_caption( $post_id ) {
        $title = wp_strip_all_tags( (string) get_the_title( $post_id ) );
        $comment = wp_strip_all_tags( (string) cpic()->settings->get( 'cpic_comment_message' ) );
        $permalink = (string) get_permalink( $post_id );
        // Reserve room for the URL and separators first, then the optional message, then the title.
        $permalink = $this->utf8_slice( $permalink, 900 );
        $remaining = max( 0, 1024 - $this->utf8_length( $permalink ) - 3 );
        $comment = $this->utf8_slice( $comment, min( 300, max( 0, $remaining ) ) );
        $remaining = max( 0, $remaining - $this->utf8_length( $comment ) );
        $title = $this->utf8_slice( $title, $remaining );
        $caption = $title . "\n\n" . $comment . "\n" . $permalink;

        // Escape only after truncation to avoid cutting through an HTML entity.
        return htmlspecialchars( $caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }

    /** Count Unicode code points without requiring the optional mbstring extension. */
    private function utf8_length( $value ) {
        if ( function_exists( 'mb_strlen' ) ) { return mb_strlen( $value, 'UTF-8' ); }
        if ( preg_match_all( '/./us', (string) $value, $matches ) ) { return count( $matches[0] ); }
        return strlen( (string) $value );
    }

    /** Truncate on grapheme boundaries while respecting a code-point limit. */
    private function utf8_slice( $value, $length ) {
        $length = max( 0, (int) $length );
        if ( preg_match_all( '/\\X/u', (string) $value, $matches ) ) {
            $result = '';
            $used = 0;
            foreach ( $matches[0] as $grapheme ) {
                $points = preg_match_all( '/./us', $grapheme, $point_matches );
                if ( false === $points || $used + $points > $length ) { break; }
                $result .= $grapheme;
                $used += $points;
            }
            return $result;
        }
        if ( function_exists( 'mb_substr' ) ) { return mb_substr( $value, 0, $length, 'UTF-8' ); }
        return substr( (string) $value, 0, $length );
    }

}
