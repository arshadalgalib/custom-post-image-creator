<?php
/**
 * Jobs & Logs admin view.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$cpic_job_counts = cpic()->queue->get_status_counts();
?>
<div class="cpic-wrap">
    <div class="cpic-section">
        <h2><?php esc_html_e( 'Jobs & Logs', 'custom-post-image-creator' ); ?></h2>

        <div class="cpic-row cpic-stats">
            <?php foreach ( $cpic_job_counts as $cpic_status => $cpic_count ) : ?>
                <div class="cpic-card cpic-stat">
                    <span class="cpic-stat-num"><?php echo esc_html( $cpic_count ); ?></span>
                    <span class="cpic-stat-label"><?php echo esc_html( ucfirst( $cpic_status ) ); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="cpic-filter-bar" style="margin-top:16px;">
            <label><?php esc_html_e( 'Filter', 'custom-post-image-creator' ); ?>
                <select id="cpic-jobs-filter">
                    <option value=""><?php esc_html_e( 'All jobs', 'custom-post-image-creator' ); ?></option>
                    <?php foreach ( CPIC_Queue::STATUSES as $cpic_s ) : ?>
                        <option value="<?php echo esc_attr( $cpic_s ); ?>"><?php echo esc_html( ucfirst( $cpic_s ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="button" id="cpic-jobs-refresh" class="button"><?php esc_html_e( 'Refresh', 'custom-post-image-creator' ); ?></button>
        </div>

        <div class="cpic-table-wrapper">
            <table class="widefat striped" id="cpic-jobs-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Post', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Template', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Retries', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Facebook', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Error', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Updated', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'custom-post-image-creator' ); ?></th>
                    </tr>
                </thead>
                <tbody id="cpic-jobs-body">
                    <tr><td colspan="8"><?php esc_html_e( 'Loading jobs…', 'custom-post-image-creator' ); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>