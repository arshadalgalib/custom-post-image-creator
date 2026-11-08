<?php
/**
 * Automatic publisher – hooks into publish_post and queues
 * photocard generation + social sharing via WP-Cron.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Auto_Publisher {

    public function __construct() {
        /*
         * Publishing is intentionally owned by CPIC_Automation and its
         * persistent jobs table.  This class used to register a second
         * transition hook and a separate option-backed queue; both workers
         * could upload the same photo before either persisted its post ID.
         *
         * Keep the class for backwards-compatible method availability, but
         * do not register the legacy hooks or AJAX canvas-capture flow.
         */
    }

    /**
     * Schedule the recurring cron worker once, after WP is initialised.
     */
    public function maybe_schedule_cron() {
        if ( ! wp_next_scheduled( 'cpic_cron_process_queue' ) ) {
            wp_schedule_event( time(), 'cpic_every_minute', 'cpic_cron_process_queue' );
        }
    }

    /**
     * Add a custom 1-minute interval.
     */
    public function add_cron_interval( $schedules ) {
        $schedules['cpic_every_minute'] = array(
            'interval' => 60,
            'display'  => __( 'Every Minute (CPIC)', 'custom-post-image-creator' ),
        );
        return $schedules;
    }

    /**
     * Fires when post status transitions.
     *
     * @param string  $new_status New post status.
     * @param string  $old_status Old post status.
     * @param WP_Post $post       Post object.
     */
    public function on_publish( $new_status, $old_status, $post ) {
        if ( 'publish' !== $new_status || 'publish' === $old_status ) {
            return;
        }

        if ( 'post' !== $post->post_type ) {
            return;
        }

        $auto = (int) cpic()->settings->get( 'cpic_auto_publish' );
        if ( ! $auto ) {
            return;
        }

        // The new template/job automation owns this post when enabled.
        if ( CPIC_Settings::get_single( 'cpic_auto_generate', 1 ) && cpic()->queue->get_job_by_post( $post->ID ) ) {
            return;
        }

        $this->delete_stored_auto_image( $post->ID );

        $delay = (int) cpic()->settings->get( 'cpic_auto_delay' );
        $run_at = time() + max( $delay, 0 );

        // Queue the task (upsert by post ID to avoid duplicates).
        $queue = get_option( 'cpic_publish_queue', array() );
        if ( ! is_array( $queue ) ) {
            $queue = array();
        }

        $filtered = array();
        foreach ( $queue as $task ) {
            $task_post_id = isset( $task['post_id'] ) ? (int) $task['post_id'] : 0;
            if ( $task_post_id !== (int) $post->ID ) {
                $filtered[] = $task;
            }
        }

        $filtered[] = array(
            'post_id'      => $post->ID,
            'scheduled_at' => $run_at,
            'auto_fb'      => (int) cpic()->settings->get( 'cpic_auto_fb' ),
            'auto_tg'      => (int) cpic()->settings->get( 'cpic_auto_tg' ),
        );
        update_option( 'cpic_publish_queue', $filtered );

        CPIC_Security::log( 'general', 'Auto-publish task queued.', array(
            'post_id'      => (int) $post->ID,
            'scheduled_at' => $run_at,
            'auto_fb'      => (int) cpic()->settings->get( 'cpic_auto_fb' ),
            'auto_tg'      => (int) cpic()->settings->get( 'cpic_auto_tg' ),
        ) );

        // Failsafe trigger so this specific publish gets picked up quickly.
        $single_at = time() + max( $delay, 0 ) + 5;
        if ( ! wp_next_scheduled( 'cpic_cron_process_queue' ) ) {
            wp_schedule_event( time(), 'cpic_every_minute', 'cpic_cron_process_queue' );
        }
        wp_schedule_single_event( $single_at, 'cpic_cron_process_queue' );

        // Kick cron in the background to improve reliability on shared hosting.
        $this->kick_cron_worker();

        // If delay is zero, try processing immediately so automation works even
        // when external cron is delayed or misconfigured.
        if ( $delay <= 0 ) {
            CPIC_Security::log( 'general', 'Immediate queue processing triggered on publish.', array( 'post_id' => (int) $post->ID ) );
            $this->process_queue();
        }
    }

    /**
     * Process queued publish tasks via WP-Cron.
     *
        * Fully automatic background sharing flow with editor-captured image.
        *
        * A browser-rendered image is stored at publish time and then shared by
        * WP-Cron, so no dashboard reload or manual share click is required.
     */
    public function process_queue() {
        // Simple mutex: process_queue can fire from publish, single events,
        // and the recurring cron. Prevent two workers from sharing the same
        // post concurrently (which would double-post to Facebook).
        $lock = (int) get_option( 'cpic_queue_processing_lock', 0 );
        if ( ( time() - $lock ) < 60 ) {
            return;
        }
        update_option( 'cpic_queue_processing_lock', time(), false );

        $queue = get_option( 'cpic_publish_queue', array() );
        if ( empty( $queue ) ) {
            CPIC_Security::log( 'general', 'Worker tick: queue empty.' );
            return;
        }

        CPIC_Security::log( 'general', 'Worker tick: queue processing started.', array( 'queue_count' => is_array( $queue ) ? count( $queue ) : 0 ) );

        $remaining = array();
        $now       = time();
        $capture_grace_seconds = 120;

        foreach ( $queue as $task ) {
            $task_scheduled_at = isset( $task['scheduled_at'] ) ? (int) $task['scheduled_at'] : $now;

            if ( $task_scheduled_at > $now ) {
                $remaining[] = $task;
                continue;
            }

            $post_id = (int) $task['post_id'];
            $post    = get_post( $post_id );
            if ( ! $post ) {
                CPIC_Security::log( 'general', 'Auto-publish task skipped because post was missing.', array( 'post_id' => $post_id ) );
                continue; // Post deleted — skip.
            }

            CPIC_Security::log( 'general', 'Auto-publish task processing started.', array( 'post_id' => $post_id ) );

            // Skip if already shared.
            $needs_fb = ! empty( $task['auto_fb'] ) && ! get_post_meta( $post_id, 'cpic_last_shared_to_facebook', true );
            $needs_tg = ! empty( $task['auto_tg'] ) && ! get_post_meta( $post_id, 'cpic_last_shared_to_telegram', true );

            if ( ! $needs_fb && ! $needs_tg ) {
                $this->delete_stored_auto_image( $post_id );
                CPIC_Security::log( 'general', 'Auto-publish task skipped because targets already completed.', array( 'post_id' => $post_id ) );
                continue;
            }

            // Prefer editor-rendered image when available; otherwise generate on server.
            $base_image_path = '';
            $generated_base  = false;

            $stored_image = get_post_meta( $post_id, 'cpic_auto_canvas_image_path', true );
            $has_stored_image = ! empty( $stored_image ) && file_exists( $stored_image );

            // Give editor capture time so Bangla text comes from browser canvas when possible.
            if ( ! $has_stored_image && $now < ( $task_scheduled_at + $capture_grace_seconds ) ) {
                if ( empty( $task['wait_logged'] ) ) {
                    CPIC_Security::log( 'general', 'Auto-publish waiting for editor-captured image.', array( 'post_id' => $post_id ) );
                }
                $task['wait_logged'] = 1;
                $remaining[] = $task;
                continue;
            }

            // Use stored image OR generate new one, with proper fallback.
            if ( $has_stored_image ) {
                $base_image_path = $stored_image;
                CPIC_Security::log( 'general', 'Auto-publish using stored editor image.', array( 'post_id' => $post_id, 'path' => $base_image_path ) );
            } else {
                // No stored image available - generate server-side.
                CPIC_Security::log( 'general', 'Auto-publish generating image on server (no browser capture).', array( 'post_id' => $post_id ) );
                $generated = cpic()->image_generator->generate( $post_id );
                if ( is_wp_error( $generated ) ) {
                    CPIC_Security::log( 'imagick', 'Auto-publish image generation failed for post ' . $post_id, $generated->get_error_message() );
                    // Don't keep task for retry if generation itself failed - will fail again.
                    continue;
                }

                $base_image_path = $generated['path'];
                $generated_base  = true;
                CPIC_Security::log( 'general', 'Auto-publish generated image on server.', array( 'post_id' => $post_id, 'path' => $base_image_path ) );
            }

            // Final check - ensure image path is valid before sharing.
            if ( empty( $base_image_path ) || ! file_exists( $base_image_path ) ) {
                CPIC_Security::log( 'imagick', 'Auto-publish image path invalid, skipping post.', array( 'post_id' => $post_id, 'path' => $base_image_path ) );
                continue;
            }

            if ( $needs_fb ) {
                $fb_image = $this->duplicate_stored_auto_image( $base_image_path, $post_id, 'fb' );
                if ( is_wp_error( $fb_image ) ) {
                    CPIC_Security::log( 'facebook', 'Auto-publish FB image copy failed for post ' . $post_id, $fb_image->get_error_message() );
                } else {
                    $fb_result = cpic()->facebook_api->share( $post_id, $fb_image );
                    if ( is_wp_error( $fb_result ) ) {
                        CPIC_Security::log( 'facebook', 'Auto-publish FB share failed for post ' . $post_id, $fb_result->get_error_message() );
                    }
                    cpic()->image_generator->cleanup( $fb_image );
                }
            }

            if ( $needs_tg ) {
                $tg_image = $this->duplicate_stored_auto_image( $base_image_path, $post_id, 'tg' );
                if ( is_wp_error( $tg_image ) ) {
                    CPIC_Security::log( 'telegram', 'Auto-publish TG image copy failed for post ' . $post_id, $tg_image->get_error_message() );
                } else {
                    $tg_result = cpic()->telegram_api->share( $post_id, $tg_image );
                    if ( is_wp_error( $tg_result ) ) {
                        CPIC_Security::log( 'telegram', 'Auto-publish TG share failed for post ' . $post_id, $tg_result->get_error_message() );
                    }
                    cpic()->image_generator->cleanup( $tg_image );
                }
            }

            // Remove generated base file after producing per-network copies.
            if ( $generated_base ) {
                cpic()->image_generator->cleanup( $base_image_path );
            }

            $fb_done = ! $task['auto_fb'] || get_post_meta( $post_id, 'cpic_last_shared_to_facebook', true );
            $tg_done = ! $task['auto_tg'] || get_post_meta( $post_id, 'cpic_last_shared_to_telegram', true );

            if ( $fb_done && $tg_done ) {
                $this->delete_stored_auto_image( $post_id );
                CPIC_Security::log( 'general', 'Auto-publish task completed.', array( 'post_id' => $post_id ) );
            } else {
                // Keep task for retry when network/API errors happen.
                $remaining[] = $task;
                CPIC_Security::log( 'general', 'Auto-publish task kept for retry.', array( 'post_id' => $post_id ) );
            }
        }

        update_option( 'cpic_publish_queue', $remaining );
        CPIC_Security::log( 'general', 'Worker tick: queue processing finished.', array( 'remaining_count' => count( $remaining ) ) );
    }

    /**
     * Store the browser-rendered auto-publish image for a post.
     */
    public function ajax_store_auto_image() {
        cpic()->security->verify_ajax_request();

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post.', 'custom-post-image-creator' ) ) );
        }

        if ( empty( $_FILES['canvas_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['canvas_file']['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing auto-publish image.', 'custom-post-image-creator' ) ) );
        }

        $bits = wp_upload_bits(
            'cpic-auto-' . $post_id . '-' . time() . '.png',
            null,
            file_get_contents( $_FILES['canvas_file']['tmp_name'] ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded temp file
        );

        if ( ! empty( $bits['error'] ) ) {
            wp_send_json_error( array( 'message' => $bits['error'] ) );
        }

        $this->delete_stored_auto_image( $post_id );
        update_post_meta( $post_id, 'cpic_auto_canvas_image_path', $bits['file'] );

        // Run queue now so due tasks do not wait solely on cron.
        CPIC_Security::log( 'general', 'Immediate queue processing triggered on image capture.', array( 'post_id' => $post_id ) );
        $this->process_queue();
        $this->kick_cron_worker();

        wp_send_json_success( array( 'message' => __( 'Auto-publish image stored.', 'custom-post-image-creator' ) ) );
    }

    /**
     * Trigger wp-cron in background to execute scheduled events soon.
     */
    private function kick_cron_worker() {
        $cron_url = site_url( '/wp-cron.php?doing_wp_cron=' . rawurlencode( (string) microtime( true ) ) );

        wp_remote_post( $cron_url, array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
        ) );
    }

    /**
     * AJAX: return queue monitor data.
     */
    public function ajax_queue_status() {
        cpic()->security->verify_ajax_request();
        wp_send_json_success( $this->get_queue_monitor_data() );
    }

    /**
     * AJAX: process queue immediately and return updated status.
     */
    public function ajax_queue_process_now() {
        cpic()->security->verify_ajax_request();

        $this->process_queue();

        wp_send_json_success( array(
            'message' => __( 'Queue processed.', 'custom-post-image-creator' ),
            'monitor' => $this->get_queue_monitor_data(),
        ) );
    }

    /**
     * Build queue monitor payload for the admin panel.
     *
     * @return array
     */
    private function get_queue_monitor_data() {
        global $wpdb;

        $queue   = get_option( 'cpic_publish_queue', array() );
        $now     = time();
        $due     = 0;
        $items   = array();
        $max_rows = 20;

        if ( is_array( $queue ) ) {
            usort( $queue, function( $a, $b ) {
                $a_time = isset( $a['scheduled_at'] ) ? (int) $a['scheduled_at'] : 0;
                $b_time = isset( $b['scheduled_at'] ) ? (int) $b['scheduled_at'] : 0;
                return $a_time <=> $b_time;
            } );

            foreach ( $queue as $task ) {
                $post_id      = isset( $task['post_id'] ) ? (int) $task['post_id'] : 0;
                $scheduled_at = isset( $task['scheduled_at'] ) ? (int) $task['scheduled_at'] : 0;
                $auto_fb      = ! empty( $task['auto_fb'] ) ? 1 : 0;
                $auto_tg      = ! empty( $task['auto_tg'] ) ? 1 : 0;

                if ( $scheduled_at <= $now ) {
                    $due++;
                }

                if ( count( $items ) >= $max_rows ) {
                    continue;
                }

                $post = $post_id ? get_post( $post_id ) : null;
                $items[] = array(
                    'post_id'      => $post_id,
                    'post_title'   => $post ? get_the_title( $post_id ) : __( '(missing post)', 'custom-post-image-creator' ),
                    'scheduled_at' => $scheduled_at,
                    'scheduled_at_human' => $scheduled_at ? wp_date( 'Y-m-d H:i:s', $scheduled_at ) : '-',
                    'auto_fb'      => $auto_fb,
                    'auto_tg'      => $auto_tg,
                    'status'       => ( $scheduled_at <= $now ) ? 'due' : 'waiting',
                );
            }
        }

        $logs = array();
        $table = $wpdb->prefix . 'cpic_logs';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists === $table ) {
            $rows = $wpdb->get_results(
                "SELECT id, log_type, message, created_at
                 FROM {$table}
                 WHERE log_type IN ('general','facebook','telegram','imagick')
                 ORDER BY id DESC
                 LIMIT 10",
                ARRAY_A
            );

            if ( is_array( $rows ) ) {
                $logs = $rows;
            }
        }

        return array(
            'pending_count' => is_array( $queue ) ? count( $queue ) : 0,
            'due_count'     => $due,
            'next_cron_at'  => wp_next_scheduled( 'cpic_cron_process_queue' ),
            'next_cron_human' => wp_next_scheduled( 'cpic_cron_process_queue' ) ? wp_date( 'Y-m-d H:i:s', wp_next_scheduled( 'cpic_cron_process_queue' ) ) : '-',
            'items'         => $items,
            'logs'          => $logs,
        );
    }

    /**
     * Duplicate the stored editor-rendered image so each network can clean up
     * its own temporary copy safely.
     *
     * @param string $source_path Original stored image.
     * @param int    $post_id     Post ID.
     * @param string $suffix      Network suffix.
     * @return string|WP_Error
     */
    private function duplicate_stored_auto_image( $source_path, $post_id, $suffix ) {
        if ( empty( $source_path ) || ! file_exists( $source_path ) ) {
            return new WP_Error( 'cpic_auto_image_missing', __( 'Stored auto-publish image not found.', 'custom-post-image-creator' ) );
        }

        $copy_path = trailingslashit( dirname( $source_path ) ) . 'cpic-auto-' . $post_id . '-' . $suffix . '-' . time() . '.png';
        if ( ! copy( $source_path, $copy_path ) ) {
            return new WP_Error( 'cpic_auto_image_copy', __( 'Could not duplicate auto-publish image.', 'custom-post-image-creator' ) );
        }

        return $copy_path;
    }

    /**
     * Delete stored auto image meta and underlying file if present.
     *
     * @param int $post_id Post ID.
     */
    private function delete_stored_auto_image( $post_id ) {
        $stored_path = get_post_meta( $post_id, 'cpic_auto_canvas_image_path', true );
        if ( $stored_path && file_exists( $stored_path ) ) {
            wp_delete_file( $stored_path );
        }
        delete_post_meta( $post_id, 'cpic_auto_canvas_image_path' );
    }
}
