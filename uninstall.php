<?php
/**
 * Uninstall handler – preserves all data by default; an explicit admin option
 * enables removal of plugin options, post meta, tables and generated files.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Preserve templates, mappings, jobs, credentials, settings, post metadata and
// generated images unless an administrator explicitly opted into a full purge.
wp_clear_scheduled_hook( 'cpic_cron_process_queue' );
wp_clear_scheduled_hook( 'cpic_cron_process_jobs' );
if ( ! get_option( 'cpic_delete_data_on_uninstall', 0 ) ) {
    return;
}

$cpic_options = array(
    'cpic_title_font',
    'cpic_title_size',
    'cpic_title_color',
    'cpic_title_offset_x',
    'cpic_title_offset_y',
    'cpic_date_font',
    'cpic_date_color',
    'cpic_date_bg_color',
    'cpic_date_offset_x',
    'cpic_date_offset_y',
    'cpic_logo',
    'cpic_logo_width',
    'cpic_logo_offset_x',
    'cpic_logo_offset_y',
    'cpic_feat_offset_x',
    'cpic_feat_offset_y',
    'cpic_feat_width',
    'cpic_feat_height',
    'cpic_canvas_width',
    'cpic_canvas_height',
    'cpic_background_images',
    'cpic_active_background',
    'cpic_comment_message',
    'cpic_fb_app_id',
    'cpic_fb_app_secret',
    'cpic_fb_page_id',
    'cpic_fb_page_name',
    'cpic_fb_page_token',
    'cpic_tg_bot_token',
    'cpic_tg_chat_id',
    'cpic_auto_publish',
    'cpic_auto_fb',
    'cpic_auto_tg',
    'cpic_auto_delay',
    'cpic_publish_queue',

    /* v6 automation */
    'cpic_auto_generate',
    'cpic_auto_comment',
    'cpic_max_retries',
    'cpic_default_template_id',
    'cpic_db_version',
    'cpic_delete_data_on_uninstall',
    'cpic_legacy_queue_event_cleared',
    'cpic_pending_process',
    'cpic_legacy_queue_migrated',
    'cpic_last_cron_run',
);

foreach ( $cpic_options as $option ) {
    delete_option( $option );
}

// Clean up per-post meta.
delete_post_meta_by_key( 'cpic_last_shared_to_facebook' );
delete_post_meta_by_key( 'cpic_last_shared_to_telegram' );
delete_post_meta_by_key( 'cpic_auto_canvas_image_path' );
delete_post_meta_by_key( '_cpic_rendered_image_path' );
delete_post_meta_by_key( '_cpic_rendered_image_url' );
delete_post_meta_by_key( '_cpic_render_template_hash' );
delete_post_meta_by_key( '_cpic_render_input_hash' );
delete_post_meta_by_key( '_cpic_render_renderer' );
delete_post_meta_by_key( '_cpic_render_artifact_sha256' );
delete_post_meta_by_key( '_cpic_rendered_at' );
delete_post_meta_by_key( '_cpic_render_wait_since' );
delete_post_meta_by_key( 'cpic_telegram_message_id' );
delete_post_meta_by_key( 'cpic_fb_post_id' );
delete_post_meta_by_key( 'cpic_fb_pending_media_id' );
delete_post_meta_by_key( 'cpic_fb_comment_id' );
delete_post_meta_by_key( 'cpic_fb_comment_lock' );
delete_post_meta_by_key( 'cpic_fb_publish_lock' );

// Remove plugin-owned upload/cache directories only for an explicit full purge.
$upload = wp_upload_dir();
foreach ( array( 'cpic-temp', 'cpic-generated', 'cpic-previews', 'cpic-fontconfig' ) as $cpic_dir ) {
    $path = trailingslashit( $upload['basedir'] ) . $cpic_dir;
    $root = realpath( $path );
    if ( ! $root || ! is_dir( $root ) ) { continue; }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $iterator as $item ) {
        if ( $item->isLink() || $item->isFile() ) {
            wp_delete_file( $item->getPathname() );
        } elseif ( $item->isDir() ) {
            @rmdir( $item->getPathname() );
        }
    }
    @rmdir( $root );
}

// Drop the custom tables.
$table = $wpdb->prefix . 'cpic_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

$table = $wpdb->prefix . 'cpic_templates';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

$table = $wpdb->prefix . 'cpic_category_mappings';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

$table = $wpdb->prefix . 'cpic_jobs';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
