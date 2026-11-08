<?php
/**
 * Persistent job queue – stores a state machine per post so the same post
 * is never processed twice, and failures can be retried stage-by-stage.
 *
 * Status flow:
 *   pending → processing → generated → publishing → published
 *                                   ↘ failed
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Queue {

    /** Valid job statuses. */
    const STATUSES = array( 'pending', 'processing', 'generated', 'publishing', 'published', 'failed' );

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cpic_jobs';
    }

    /* ─── Job creation (idempotent) ────────────────────── */

    /**
     * Create a job for a post. If a job already exists and is not in a
     * terminal success state, it is reused (no duplicates).
     *
     * @param int $post_id Post ID.
     * @return array Job row.
     */
    public function create_job( $post_id ) {
        global $wpdb;
        $table = self::table();

        $post_id = (int) $post_id;
        $job_key = 'cpic-job-' . $post_id;

        $existing = $this->get_job_by_post( $post_id );
        if ( $existing ) {
            // Repeated publish hooks must never alter an active or completed job.
            return $existing;
        }

        $max_retries = max( 0, (int) CPIC_Settings::get_single( 'cpic_max_retries', 3 ) );
        $delay = max( 0, (int) CPIC_Settings::get_single( 'cpic_auto_delay', 0 ) );
        // Freeze the intended destinations when the job is created so retries
        // are deterministic even if global settings change while it waits.
        $auto_facebook = CPIC_Settings::get_single( 'cpic_auto_fb', 0 ) ? 1 : 0;
        $auto_telegram = CPIC_Settings::get_single( 'cpic_auto_tg', 0 ) ? 1 : 0;
        $auto_comment = CPIC_Settings::get_single( 'cpic_auto_comment', 1 ) ? 1 : 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert(
            $table,
            array(
                'post_id'       => $post_id,
                'job_key'       => $job_key,
                'template_id'   => 0,
                'status'        => 'pending',
                'attempts'      => 0,
                'max_retries'   => $max_retries,
                'auto_facebook' => $auto_facebook,
                'auto_telegram' => $auto_telegram,
                'auto_comment'  => $auto_comment,
                'next_attempt_at' => time() + $delay,
                'last_error'    => '',
                'created_at'    => current_time( 'mysql' ),
                'updated_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            // A concurrent request may have won the unique job_key insert.
            return $this->get_job_by_post( $post_id );
        }

        $job = $this->get_job( (int) $wpdb->insert_id );
        CPIC_Logger::log( 'queue', __( 'Job created.', 'custom-post-image-creator' ), array( 'post_id' => $post_id, 'job_id' => $job ? $job['id'] : 0 ) );
        return $job;
    }

    /* ─── Read ─────────────────────────────────────────── */

    public function get_job( $job_id ) {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $job_id ), ARRAY_A );
        return $row ? $row : null;
    }

    public function get_job_by_post( $post_id ) {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY id ASC LIMIT 1", (int) $post_id ), ARRAY_A );
        return $row ? $row : null;
    }

    public function get_job_by_key( $job_key ) {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_key = %s", $job_key ), ARRAY_A );
        return $row ? $row : null;
    }

    /**
     * Fetch jobs with optional status filter.
     *
     * @param string $status  Status filter ('' = all).
     * @param int    $limit   Row limit.
     * @param int    $offset  Offset.
     * @return array
     */
    public function get_jobs( $status = '', $limit = 50, $offset = 0 ) {
        global $wpdb;
        $table = self::table();

        $sql = "SELECT * FROM {$table}";
        $args = array();

        if ( $status && in_array( $status, self::STATUSES, true ) ) {
            $sql   .= ' WHERE status = %s';
            $args[] = $status;
        }

        $sql .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $args[] = max( 1, (int) $limit );
        $args[] = max( 0, (int) $offset );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Jobs that are due for processing: pending/generated jobs or stale
     * processing/publishing claims (recovered after fifteen minutes).
     *
     * @param int $limit Row limit.
     * @return array
     */
    public function get_due_jobs( $limit = 25 ) {
        global $wpdb;
        $table = self::table();

        // Recover abandoned claims after fifteen minutes.
        $stale = wp_date( 'Y-m-d H:i:s', time() - 900 );

        $sql = "SELECT * FROM {$table}
                WHERE (
                    (status IN ('pending','generated') AND next_attempt_at <= %d)
                    OR (status IN ('processing','publishing') AND updated_at < %s AND next_attempt_at <= %d)
                )
                  AND attempts <= max_retries
                ORDER BY id ASC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, time(), $stale, time(), max( 1, (int) $limit ) ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Count jobs grouped by status.
     *
     * @return array status => count
     */
    public function get_status_counts() {
        global $wpdb;
        $table = self::table();
        $counts = array_fill_keys( self::STATUSES, 0 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A );
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                if ( isset( $counts[ $row['status'] ] ) ) {
                    $counts[ $row['status'] ] = (int) $row['c'];
                }
            }
        }

        return $counts;
    }

    /* ─── Write ────────────────────────────────────────── */

    /**
     * Update job fields.
     *
     * @param int   $job_id Job ID.
     * @param array $data   Fields to update.
     * @return bool
     */
    public function update( $job_id, $data ) {
        global $wpdb;
        $table = self::table();

        if ( ! is_array( $data ) || empty( $data ) ) {
            return false;
        }

        $fields = array(
            'template_id'         => '%d',
            'status'              => '%s',
            'image_path'          => '%s',
            'image_url'           => '%s',
            'renderer_id'         => '%s',
            'input_hash'          => '%s',
            'template_hash'       => '%s',
            'rendered_at'         => '%s',
            'facebook_post_id'    => '%s',
            'facebook_comment_id' => '%s',
            'telegram_message_id' => '%s',
            'auto_facebook'       => '%d',
            'auto_telegram'       => '%d',
            'auto_comment'        => '%d',
            'next_attempt_at'     => '%d',
            'attempts'            => '%d',
            'max_retries'         => '%d',
            'last_error'          => '%s',
        );

        $clean = array();
        $fmt   = array();

        foreach ( $data as $key => $value ) {
            if ( ! isset( $fields[ $key ] ) ) {
                continue;
            }
            if ( in_array( $key, array( 'attempts', 'template_id', 'max_retries', 'next_attempt_at', 'auto_facebook', 'auto_telegram', 'auto_comment' ), true ) ) {
                $value = (int) $value;
            } else {
                $value = (string) $value;
            }
            $clean[ $key ] = $value;
            $fmt[]         = $fields[ $key ];
        }

        if ( empty( $clean ) ) {
            return false;
        }

        $clean['updated_at'] = current_time( 'mysql' );
        $fmt[]               = '%s';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->update( $table, $clean, array( 'id' => (int) $job_id ), $fmt, array( '%d' ) );
        return false !== $result;
    }

    /** Atomically reset one failed job for a deliberate user retry. */
    public function retry_failed_job( $job_id ) {
        global $wpdb;
        $table = self::table();
        // Compare-and-swap: concurrent retry requests cannot reopen a claimed job.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'pending', attempts = 0, last_error = '', next_attempt_at = %d, updated_at = %s
             WHERE id = %d AND status = 'failed'",
            time(), current_time( 'mysql' ), (int) $job_id
        ) );
        return 1 === (int) $updated;
    }

    /** Atomically claim a due job, recovering only genuinely stale workers. */
    public function claim_job( $job_id, $stale_after = 900 ) {
        global $wpdb;
        $table = self::table();
        $stale = wp_date( 'Y-m-d H:i:s', time() - max( 60, (int) $stale_after ) );
        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'processing', updated_at = %s
             WHERE id = %d AND next_attempt_at <= %d AND (
                status IN ('pending','generated') OR
                (status IN ('processing','publishing') AND updated_at < %s)
             ) AND attempts <= max_retries",
            current_time( 'mysql' ), (int) $job_id, $now, $stale
        ) );
        return 1 === (int) $claimed;
    }

    /** Increment the attempt counter.
     * @param int $job_id Job ID.
     * @return bool
     */
    public function bump_attempts( $job_id ) {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1, updated_at = %s WHERE id = %d", current_time( 'mysql' ), (int) $job_id ) );
        return false !== $result;
    }

    /**
     * Mark a job failed with an error message.
     *
     * @param int    $job_id Job ID.
     * @param string $error  Error message.
     * @return bool
     */
    public function mark_failed( $job_id, $error ) {
        $this->bump_attempts( $job_id );
        return $this->update( $job_id, array( 'status' => 'failed', 'last_error' => $error ) );
    }

    /**
     * Delete a job row.
     *
     * @param int $job_id Job ID.
     * @return bool
     */
    public function delete_job( $job_id ) {
        global $wpdb;
        $table = self::table();
        // Atomic condition prevents deleting an active claim between a UI check and this request.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND status NOT IN ('processing','publishing')", (int) $job_id ) );
        // Canonical artifacts are also referenced by post metadata and may be
        // needed for manual sharing or a later job. Leave file reclamation to
        // the age- and reference-aware artifact pruner.
        return 1 === (int) $deleted;
    }

    /**
     * Delete jobs for a post.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public function delete_jobs_for_post( $post_id ) {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->delete( $table, array( 'post_id' => (int) $post_id ), array( '%d' ) );
    }
}