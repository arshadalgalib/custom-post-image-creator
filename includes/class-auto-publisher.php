<?php
/**
 * Deprecated compatibility shim for the pre-v6 option-backed queue.
 *
 * New work is owned exclusively by CPIC_Automation + CPIC_Queue. Keeping the
 * class name avoids breaking code that asks for the old singleton property,
 * but it never registers a second worker or accepts browser canvas uploads.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Auto_Publisher {

    /** Intentionally no hooks: CPIC_Automation is the sole worker. */
    public function __construct() {}

    /** Compatibility delegate. */
    public function maybe_schedule_cron() {
        return cpic()->automation->maybe_schedule_cron();
    }

    /** Compatibility delegate. */
    public function add_cron_interval( $schedules ) {
        return cpic()->automation->add_cron_interval( $schedules );
    }

    /** Compatibility delegate; no second queue is created. */
    public function on_publish( $new_status, $old_status, $post ) {
        return cpic()->automation->on_publish( $new_status, $old_status, $post );
    }

    /** Compatibility delegate for integrations that invoked the legacy worker directly. */
    public function process_queue() {
        return cpic()->automation->process_due_jobs( 25 );
    }

    /** Legacy canvas capture is intentionally disabled; generated cards are server-authoritative. */
    public function ajax_store_auto_image() {
        cpic()->security->verify_ajax_request();
        wp_send_json_error( array( 'message' => __( 'Browser-captured images are no longer accepted. The canonical server renderer generates publishing images.', 'custom-post-image-creator' ) ), 410 );
    }

    /** Read current persistent-queue status for compatibility callers. */
    public function ajax_queue_status() {
        cpic()->security->verify_ajax_request();
        wp_send_json_success( array( 'counts' => cpic()->queue->get_status_counts() ) );
    }

    /** Process due persistent jobs using the authoritative worker. */
    public function ajax_queue_process_now() {
        cpic()->security->verify_ajax_request();
        cpic()->automation->process_due_jobs( 10 );
        wp_send_json_success( array( 'counts' => cpic()->queue->get_status_counts() ) );
    }
}
