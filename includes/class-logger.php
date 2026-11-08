<?php
/**
 * Structured logger – writes both free-form log entries and per-job
 * pipeline records to the cpic_logs table.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Logger {

    /**
     * Log a free-form entry.
     *
     * @param string $type    Log type (general|facebook|telegram|imagick|template|queue).
     * @param string $message Human-readable message.
     * @param mixed  $context Optional additional context (array/object/string).
     * @param int    $job_id  Optional job id to attach.
     */
    public static function log( $type, $message, $context = '', $job_id = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cpic_logs';

        $context = self::redact( $context );
        $message = self::redact( (string) $message );
        $data = array(
            'log_type'   => sanitize_text_field( $type ),
            'message'    => sanitize_textarea_field( $message ),
            'created_at' => current_time( 'mysql' ),
        );

        if ( '' !== $context ) {
            if ( is_array( $context ) || is_object( $context ) ) {
                $data['context'] = wp_json_encode( $context );
            } else {
                $data['context'] = sanitize_textarea_field( (string) $context );
            }
        }

        if ( $job_id ) {
            if ( isset( $data['context'] ) && $data['context'] ) {
                $decoded = json_decode( $data['context'], true );
                if ( ! is_array( $decoded ) ) {
                    $decoded = array( 'raw' => $data['context'] );
                }
                $decoded['job_id'] = (int) $job_id;
                $data['context']   = wp_json_encode( $decoded );
            } else {
                $data['context'] = wp_json_encode( array( 'job_id' => (int) $job_id ) );
            }
        }

        $formats = array_fill( 0, count( $data ), '%s' );
        $wpdb->insert( $table, $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /** Recursively remove credential values before persistence. */
    private static function redact( $value, $key = '' ) {
        if ( is_array( $value ) ) {
            $clean = array();
            foreach ( $value as $child_key => $child ) {
                $clean[ $child_key ] = self::redact( $child, (string) $child_key );
            }
            return $clean;
        }
        if ( is_object( $value ) ) {
            return self::redact( (array) $value, $key );
        }
        if ( preg_match( '/token|secret|authorization|password|credential/i', $key ) ) {
            return '[REDACTED]';
        }
        if ( ! is_string( $value ) ) {
            return $value;
        }
        $value = preg_replace( '/(access_token=)[^&\s]+/i', '$1[REDACTED]', $value );
        $value = preg_replace( '#(/bot)\d+:[A-Za-z0-9_-]+#', '$1[REDACTED]', $value );
        $value = preg_replace( '/\bEAA[A-Za-z0-9]{20,}\b/', '[REDACTED]', $value );
        $value = preg_replace( '/(Bearer\s+)[A-Za-z0-9._~-]+/i', '$1[REDACTED]', $value );
        return $value;
    }

    /**
     * Convenience wrapper matching the legacy static signature.
     *
     * @param string $type    Log type.
     * @param string $message Message.
     * @param mixed  $context Context.
     */
    public static function info( $type, $message, $context = '' ) {
        self::log( $type, $message, $context );
    }

    /**
     * Fetch recent logs (for admin dashboard).
     *
     * @param int    $limit Number of rows.
     * @param string $type  Optional type filter.
     * @return array
     */
    public static function get_logs( $limit = 20, $type = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cpic_logs';

        $sql = "SELECT id, log_type, message, context, created_at FROM {$table}";
        if ( $type ) {
            $sql .= $wpdb->prepare( ' WHERE log_type = %s', $type );
        }
        $sql .= $wpdb->prepare( ' ORDER BY id DESC LIMIT %d', max( 1, (int) $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Delete logs older than a given number of days.
     *
     * @param int $days Days to keep.
     * @return bool
     */
    public static function prune( $days = 30 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cpic_logs';
        $cut   = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cut ) );
    }
}