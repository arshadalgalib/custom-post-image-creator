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

        // Atomic compare-and-swap claim. Only ONE process may work a job.
        // Jobs currently in 'processing'/'publishing' are in-flight elsewhere
        // and are left untouched, so cron ticks cannot post duplicates while
        // a slow publish (up to 120s HTTP timeout) is still running.
        $table   = $wpdb->prefix . 'cpic_jobs';
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'processing', updated_at = %s
             WHERE id = %d AND status IN ('pending','generated','publishing','failed')",
            current_time( 'mysql' ),
            $job_id
        ) );

        if ( ! $claimed ) {
            // Another process already claimed it (or it reached published).
            return true;
        }

        $job = $queue->get_job( $job_id );

        try {
            return $this->run_pipeline( $job );
        } catch ( \Throwable $e ) {
            CPIC_Logger::log( 'publisher', __( 'Unhandled exception in pipeline.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'error' => $e->getMessage() ) );
            $queue->update( $job_id, array( 'status' => 'pending', 'last_error' => $e->getMessage() ) );
            $queue->bump_attempts( $job_id );
            return false;
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

        $auto_fb      = (int) CPIC_Settings::get_single( 'cpic_auto_fb', 0 );
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
        }

        if ( ! $tpl ) {
            CPIC_Logger::log( 'template', __( 'No active template resolved for job.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'post_id' => $post_id ) );
            return $this->handle_failure( $job_id, __( 'No active template found.', 'custom-post-image-creator' ) );
        }

        /* ── Stage 2: Generate photocard ──────────────── */
        if ( empty( $job['image_path'] ) || ! file_exists( $job['image_path'] ) ) {
            $queue->update( $job_id, array( 'status' => 'processing' ) );
            $img = cpic()->image_generator->generate_from_template( $post_id, $tpl['config'] );

            if ( is_wp_error( $img ) ) {
                CPIC_Logger::log( 'imagick', __( 'Generation failed.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'error' => $img->get_error_message() ) );
                return $this->handle_failure( $job_id, $img->get_error_message() );
            }

            $queue->update( $job_id, array(
                'image_path' => $img['path'],
                'image_url'  => $img['url'],
                'status'     => 'generated',
            ) );
            CPIC_Logger::log( 'queue', __( 'Photocard generated.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'post_id' => $post_id, 'image_url' => $img['url'] ) );
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
                    array( 'fb_curl_error', 'fb_api_error', 'fb_no_photo_id' ),
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

        /* ── Stage 5: Complete ────────────────────────── */
        $queue->update( $job_id, array( 'status' => 'published', 'last_error' => '' ) );
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
        $queue->update( $job_id, array( 'last_error' => $error, 'status' => 'pending' ) );

        if ( $attempts > $max ) {
            $queue->update( $job_id, array( 'status' => 'failed' ) );
            CPIC_Logger::log( 'queue', __( 'Job failed after retries.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'attempts' => $attempts, 'max' => $max, 'error' => $error ) );
            return false;
        }

        CPIC_Logger::log( 'queue', __( 'Job scheduled for retry.', 'custom-post-image-creator' ), array( 'job_id' => $job_id, 'attempts' => $attempts, 'max' => $max ) );
        return false;
    }
}
