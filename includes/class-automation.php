<?php
/**
 * Automation – detects post publishing and drives the background pipeline.
 *
 * Flow:
 *   WordPress Post Published
 *     → Create persistent job (idempotent)
 *     → Resolve template via category mapping
 *     → Generate photocard
 *     → Publish to Facebook
 *     → First comment with article URL
 *     → Log result
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Automation {

    public function __construct() {
        add_action( 'transition_post_status', array( $this, 'on_publish' ), 9, 3 );
        add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
        add_action( 'init', array( $this, 'maybe_run_cron_now' ), 1 );
        add_action( 'cpic_cron_process_jobs', array( $this, 'process_due_jobs' ) );
        add_action( 'cpic_process_single_job', array( $this, 'process_single_post' ) );

        add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
    }

    /**
     * Register the 1-minute interval if not already registered.
     */
    public function add_cron_interval( $schedules ) {
        $schedules['cpic_every_minute'] = array(
            'interval' => 60,
            'display'  => __( 'Every Minute (CPIC)', 'custom-post-image-creator' ),
        );
        return $schedules;
    }

    /**
     * Schedule the recurring worker once.
     */
    public function maybe_schedule_cron() {
        if ( ! wp_next_scheduled( 'cpic_cron_process_jobs' ) ) {
            wp_schedule_event( time(), 'cpic_every_minute', 'cpic_cron_process_jobs' );
        }
    }

    /**
     * Backup: process due jobs on every admin page load when wp-cron
     * is unreliable (shared hosting). Throttled to once per minute.
     */
    public function maybe_run_cron_now() {
        if ( ! is_admin() ) {
            return;
        }
        if ( ! CPIC_Settings::get_single( 'cpic_auto_generate', 1 ) ) {
            return;
        }
        $last = get_option( 'cpic_last_cron_run', 0 );
        if ( ( time() - (int) $last ) < 60 ) {
            return;
        }
        update_option( 'cpic_last_cron_run', time(), false );
        $this->process_due_jobs( 10 );
    }

    /**
     * Detect publish transitions (new publish, scheduled, draft → publish,
     * pending → publish). Skips when auto-generation is disabled.
     *
     * @param string  $new_status New status.
     * @param string  $old_status Old status.
     * @param WP_Post $post       Post object.
     */
    public function on_publish( $new_status, $old_status, $post ) {
        if ( 'publish' !== $new_status || 'publish' === $old_status ) {
            return;
        }

        if ( 'post' !== $post->post_type ) {
            return;
        }

        if ( ! CPIC_Settings::get_single( 'cpic_auto_generate', 1 ) ) {
            return;
        }

        $job = cpic()->queue->create_job( $post->ID );
        if ( ! $job ) {
            return;
        }

        CPIC_Logger::log( 'queue', __( 'Publish detected, job queued.', 'custom-post-image-creator' ), array( 'post_id' => $post->ID, 'job_id' => $job['id'] ) );

        if ( 'published' === $job['status'] ) {
            return;
        }

        // Schedule immediate processing via single event (safe for shared hosting).
        wp_schedule_single_event( time() + 5, 'cpic_process_single_job', array( $post->ID ) );

        // Backup: also run via init on next page load if cron fails.
        update_option( 'cpic_pending_process', $post->ID, false );
    }

    /**
     * Process a single post by ID (used by delayed single event).
     *
     * @param int $post_id Post ID.
     */
    public function process_single_post( $post_id ) {
        $job = cpic()->queue->create_job( $post_id );
        if ( ! $job ) {
            return;
        }
        if ( 'published' === $job['status'] ) {
            return;
        }
        cpic()->publisher->process_job( $job );
    }

    /**
     * Cron worker – processes due jobs.
     *
     * @param int $limit Maximum jobs per tick.
     */
    public function process_due_jobs( $limit = 25 ) {
        if ( ! CPIC_Settings::get_single( 'cpic_auto_generate', 1 ) ) {
            return;
        }

        $jobs = cpic()->queue->get_due_jobs( $limit );

        if ( empty( $jobs ) ) {
            return;
        }

        foreach ( $jobs as $job ) {
            // Re-fetch so two concurrent ticks never process the same job.
            $fresh = cpic()->queue->get_job( (int) $job['id'] );
            if ( ! $fresh || 'published' === $fresh['status'] ) {
                continue;
            }
            // Skip if another process is actively working on it (updated in
            // the last 3 minutes — a publish call can take up to 120s, so a
            // fresh 'publishing' job must never be picked up a second time).
            if ( in_array( $fresh['status'], array( 'processing', 'publishing' ), true ) && strtotime( $fresh['updated_at'] ) > ( time() - 180 ) ) {
                continue;
            }
            cpic()->publisher->process_job( $fresh );
        }
    }

    /**
     * Force processing of a single post immediately (used by admin action).
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public function process_post_now( $post_id ) {
        $job = cpic()->queue->create_job( $post_id );
        if ( ! $job ) {
            return false;
        }
        if ( 'published' === $job['status'] ) {
            return true;
        }
        return cpic()->publisher->process_job( $job );
    }
}