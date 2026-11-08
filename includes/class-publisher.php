<?php
/**
 * Publisher – orchestrates the full pipeline for a single job:
 *
 *   Resolve Template → Generate Photocard → Publish to Facebook
 *   → First Comment → Mark Published
 *
 * Each stage is resumable and idempotent: on retry the pipeline resumes
 * from the last completed stage instead of starting over.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Publisher {

    /**
     * Process a single job.
     *
     * @param array $job Job row.
     * @return bool True when the job reached a terminal state this run.
     */
    public function process_job( $job ) {
        global $wpdb;
        $job_id  = (int) $job['id'];
        $post_id = (int) $job['post_id'];
        $queue   = cpic()->queue;

        // Idempotency: already published → nothing to do.
        if ( 'published' === $job['status'] ) {
            return true;
        }

        // Atomic due/stale compare-and-swap claim prevents overlapping cron and admin workers.
        if ( ! $queue->claim_job( $job_id ) ) {
            return false;
        }
        $job = $queue->get_job( $job_id );

        try {
            return $this->run_pipeline( $job );
        } catch ( \Throwable $e ) {
            CPIC_Logger::log( 'publisher', __( 'Unhandled exception in pipeline.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'error' => $e->getMessage() ) );
            return $this->handle_failure( $job_id, __( 'Unexpected processing error. Check server logs for details.', 'custom-post-image-creator' ) );
        }
    }

    /**
     * The actual pipeline — separated so process_job can catch all exceptions.
     *
     * @param array $job Job row.
     * @return bool True when the job reached a terminal state this run.
     */
    private function run_pipeline( $job ) {
        $job_id  = (int) $job['id'];
        $post_id = (int) $job['post_id'];
        $queue   = cpic()->queue;

        $auto_fb      = isset( $job['auto_facebook'] ) ? (int) $job['auto_facebook'] : (int) CPIC_Settings::get_single( 'cpic_auto_fb', 0 );
        $auto_tg      = isset( $job['auto_telegram'] ) ? (int) $job['auto_telegram'] : (int) CPIC_Settings::get_single( 'cpic_auto_tg', 0 );
        // Default to enabled when the option has never been saved. This
        // keeps the article-link comment part of the normal publish flow.
        $auto_comment = (int) CPIC_Settings::get_single( 'cpic_auto_comment', 1 );

        /* ── Stage 1: Resolve template ────────────────── */
        if ( empty( $job['template_id'] ) ) {
            $tpl = cpic()->category_mapper->resolve_template_for_post( $post_id );
            if ( $tpl ) {
                $queue->update( $job_id, array( 'template_id' => (int) $tpl['id'], 'status' => 'processing' ) );
                $job = $queue->get_job( $job_id );
            }
        } else {
            $tpl = CPIC_Template_Manager::get_template( (int) $job['template_id'] );
            if ( ! $tpl || empty( $tpl['is_active'] ) ) {
                $tpl = cpic()->category_mapper->resolve_template_for_post( $post_id );
                if ( $tpl ) { $queue->update( $job_id, array( 'template_id' => (int) $tpl['id'] ) ); }
            }
        }

        if ( ! $tpl ) {
            CPIC_Logger::log( 'template', __( 'No active template resolved for job.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'post_id' => $post_id ) );
            return $this->handle_failure( $job_id, __( 'No active template found.', 'custom-post-image-creator' ) );
        }

        /* ── Stage 2: Consume the browser-created canonical PNG ── */
        if ( empty( $job['image_path'] ) || ! is_file( $job['image_path'] ) ) {
            $artifact = CPIC_Canvas_Artifacts::current_for_post( $post_id );
            if ( is_wp_error( $artifact ) ) {
                $wait_since = (int) get_post_meta( $post_id, '_cpic_render_wait_since', true );
                if ( ! $wait_since ) {
                    $wait_since = time();
                    update_post_meta( $post_id, '_cpic_render_wait_since', $wait_since );
                }
                if ( time() - $wait_since < 900 ) {
                    $queue->update( $job_id, array(
                        'status' => 'pending',
                        'last_error' => __( 'Waiting for the WordPress editor browser to upload the rendered PNG.', 'custom-post-image-creator' ),
                        'next_attempt_at' => time() + 60,
                    ) );
                    return false;
                }
                return $this->handle_failure( $job_id, $artifact->get_error_message(), true );
            }
            $queue->update( $job_id, array(
                'template_id' => $artifact['template_id'],
                'image_path' => $artifact['path'],
                'image_url' => $artifact['url'],
                'renderer_id' => $artifact['renderer'],
                'input_hash' => $artifact['input_hash'],
                'template_hash' => $artifact['template_hash'],
                'rendered_at' => current_time( 'mysql' ),
                'status' => 'generated',
            ) );
            CPIC_Logger::log( 'queue', __( 'Reused browser-rendered canonical PNG.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'post_id' => $post_id, 'renderer' => $artifact['renderer'] ) );
            $job = $queue->get_job( $job_id );
        }

        /* ── Stage 3: Publish to Facebook ─────────────── */
        if ( $auto_fb && empty( $job['facebook_post_id'] ) ) {
            $queue->update( $job_id, array( 'status' => 'publishing' ) );

            $result = cpic()->facebook_api->publish_photo( $post_id, $job['image_path'] );

            if ( is_wp_error( $result ) ) {
                CPIC_Logger::log( 'facebook', __( 'Facebook publish failed.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'error' => $result->get_error_message() ) );
                // Transport/API errors are ambiguous: the post may have been
                // created on Facebook even though we did not get a response.
                // Retrying could create a duplicate, so fail terminally.
                $terminal = in_array(
                    $result->get_error_code(),
                    array( 'fb_curl_error', 'fb_no_photo_id' ),
                    true
                );
                return $this->handle_failure( $job_id, $result->get_error_message(), $terminal );
            }

            $queue->update( $job_id, array(
                'facebook_post_id'    => $result['facebook_post_id'],
                'facebook_comment_id' => $result['facebook_comment_id'],
                'status'              => 'publishing',
            ) );
            CPIC_Logger::log( 'facebook', __( 'Facebook post created.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'facebook_post_id' => $result['facebook_post_id'] ) );
            $job = $queue->get_job( $job_id );
        }

        /* ── Stage 4: First comment (comment-only retry) ─ */
        if ( $auto_comment && $auto_fb && ! empty( $job['facebook_post_id'] ) && empty( $job['facebook_comment_id'] ) ) {
            $comment_id = cpic()->facebook_api->post_first_comment_once( $job['facebook_post_id'], $post_id );

            if ( is_wp_error( $comment_id ) ) {
                CPIC_Logger::log( 'facebook', __( 'First comment failed.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'error' => $comment_id->get_error_message() ) );
                // Transport/no-id failures are ambiguous and must not be
                // retried blindly. A definitive Graph/API rejection is safe
                // to retry because Facebook confirmed it did not create the
                // comment.
                $terminal_comment_error = in_array(
                    $comment_id->get_error_code(),
                    array( 'fb_comment_error', 'fb_comment_no_id' ),
                    true
                );
                return $this->handle_failure( $job_id, $comment_id->get_error_message(), $terminal_comment_error );
            }

            $queue->update( $job_id, array( 'facebook_comment_id' => $comment_id ) );
            CPIC_Logger::log( 'facebook', __( 'Article URL commented.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'facebook_comment_id' => $comment_id ) );
            $job = $queue->get_job( $job_id );
        }

        /* ── Stage 5: Telegram ────────────────────────── */
        if ( $auto_tg && empty( $job['telegram_message_id'] ) ) {
            // Recover a successful API response persisted before a process interruption.
            $known_message_id = get_post_meta( $post_id, 'cpic_telegram_message_id', true );
            if ( $known_message_id ) {
                $queue->update( $job_id, array( 'telegram_message_id' => (string) $known_message_id ) );
                $job = $queue->get_job( $job_id );
            } else {
                $queue->update( $job_id, array( 'status' => 'publishing' ) );
                $tg_result = cpic()->telegram_api->share( $post_id, $job['image_path'] );
                if ( is_wp_error( $tg_result ) ) {
                    CPIC_Logger::log( 'telegram', __( 'Automatic Telegram publish failed.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'error' => $tg_result->get_error_message() ) );
                    // A transport failure has an ambiguous outcome; never blindly duplicate a message.
                    return $this->handle_failure( $job_id, $tg_result->get_error_message(), 'tg_curl_error' === $tg_result->get_error_code() );
                }
                $queue->update( $job_id, array( 'telegram_message_id' => (string) ( $tg_result['message_id'] ?? '' ) ) );
                $job = $queue->get_job( $job_id );
                CPIC_Logger::log( 'telegram', __( 'Automatic Telegram message sent.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'message_id' => $job['telegram_message_id'] ) );
            }
        }

        /* ── Stage 6: Complete ────────────────────────── */
        $queue->update( $job_id, array( 'status' => 'published', 'last_error' => '', 'next_attempt_at' => 0 ) );
        CPIC_Logger::log( 'queue', __( 'Job completed.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'post_id' => $post_id ) );

        return true;
    }

    /**
     * Handle a stage failure with retry accounting.
     *
     * @param int    $job_id   Job ID.
     * @param string $error    Error message.
     * @param bool   $terminal When true the job is marked 'failed' without
     *                         auto-retry (used when a retry could duplicate a
     *                         Facebook-side side effect).
     * @return bool Always false (not completed).
     */
    private function handle_failure( $job_id, $error, $terminal = false ) {
        $queue = cpic()->queue;
        $job   = $queue->get_job( $job_id );

        if ( $terminal ) {
            $queue->bump_attempts( $job_id );
            $queue->update( $job_id, array( 'status' => 'failed', 'last_error' => $error ) );
            CPIC_Logger::log( 'queue', __( 'Job failed without retry (possible duplicate side effect).', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'error' => $error ) );
            return false;
        }

        $attempts = (int) $job['attempts'] + 1;
        $max      = (int) $job['max_retries'];

        $queue->bump_attempts( $job_id );

        if ( $attempts > $max ) {
            $queue->update( $job_id, array( 'status' => 'failed', 'last_error' => $error ) );
            CPIC_Logger::log( 'queue', __( 'Job failed after retries.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'attempts' => $attempts, 'max' => $max, 'error' => $error ) );
            return false;
        }

        $delay = min( 21600, 60 * ( 2 ** min( 8, max( 0, $attempts - 1 ) ) ) );
        $queue->update( $job_id, array( 'last_error' => $error, 'status' => 'pending', 'next_attempt_at' => time() + $delay ) );
        CPIC_Logger::log( 'queue', __( 'Job scheduled for retry.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'attempts' => $attempts, 'max' => $max, 'retry_in_seconds' => $delay ) );
        return false;
    }
}
