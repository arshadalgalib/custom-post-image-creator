<?php
/**
 * Security helper – nonce verification, capability checks, sanitisation.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Security {

    /** Nonce action name. */
    const NONCE_ACTION = 'cpic_nonce_action';

    /** Nonce field name. */
    const NONCE_FIELD = 'cpic_nonce';

    /**
     * Verify the AJAX nonce and admin capability.
     * Sends wp_send_json_error and dies on failure.
     */
    public function verify_ajax_request() {
        if ( ! check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD, false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'custom-post-image-creator' ) ), 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'custom-post-image-creator' ) ), 403 );
        }
    }

    /**
     * Verify nonce + capability for a normal admin page request.
     *
     * @return bool
     */
    public function verify_admin_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'custom-post-image-creator' ) );
        }
        if ( isset( $_POST[ self::NONCE_FIELD ] ) ) {
            return wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION );
        }
        return false;
    }

    /* ─── Sanitisation helpers ───────────────────────────── */

    public function sanitize_text( $value ) {
        return sanitize_text_field( wp_unslash( $value ) );
    }

    public function sanitize_hex( $value ) {
        $color = sanitize_hex_color( $value );
        return $color ? $color : '#000000';
    }

    public function sanitize_int( $value ) {
        return intval( $value );
    }

    public function sanitize_url( $value ) {
        return esc_url_raw( wp_unslash( $value ) );
    }

    public function sanitize_textarea( $value ) {
        return sanitize_textarea_field( wp_unslash( $value ) );
    }

    /**
     * Log an error to the cpic_logs table.
     *
     * @param string $type    Log type (facebook|telegram|imagick|general).
     * @param string $message Human-readable message.
     * @param mixed  $context Optional additional data.
     */
    public static function log( $type, $message, $context = '' ) {
        CPIC_Logger::log( $type, $message, $context );
    }
}
