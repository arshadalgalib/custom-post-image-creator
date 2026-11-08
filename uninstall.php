<?php
/**
 * Uninstall handler – removes all plugin options, post meta and the
 * custom log table when the plugin is deleted from WordPress.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

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
);

foreach ( $cpic_options as $option ) {
    delete_option( $option );
}

// Clean up per-post meta.
delete_post_meta_by_key( 'cpic_last_shared_to_facebook' );
delete_post_meta_by_key( 'cpic_last_shared_to_telegram' );
delete_post_meta_by_key( 'cpic_auto_canvas_image_path' );

// Remove scheduled cron events.
wp_clear_scheduled_hook( 'cpic_cron_process_queue' );
wp_clear_scheduled_hook( 'cpic_cron_process_jobs' );

// Drop the custom tables.
$table = $wpdb->prefix . 'cpic_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

$table = $wpdb->prefix . 'cpic_templates';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

$table = $wpdb->prefix . 'cpic_category_mappings';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

$table = $wpdb->prefix . 'cpic_jobs';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
