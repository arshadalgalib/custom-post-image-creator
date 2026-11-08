<?php
/**
 * Installer – creates custom tables, runs migrations and seeds the
 * initial template set from the legacy (v5) global settings.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Installer {

    /**
     * Run activation / upgrade tasks.
     */
    public static function run() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        self::create_log_table();
        self::create_templates_table();
        self::create_category_mappings_table();
        self::create_jobs_table();

        self::ensure_upload_dirs();

        self::maybe_migrate();

        update_option( 'cpic_db_version', CPIC_VERSION );
    }

    /* ─── Tables ──────────────────────────────────────── */

    private static function create_log_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'cpic_logs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            log_type VARCHAR(50) NOT NULL DEFAULT 'error',
            message TEXT NOT NULL,
            context TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY log_type (log_type),
            KEY created_at (created_at)
        ) {$charset};";
        dbDelta( $sql );
    }

    /**
     * Templates table (dynamic photocard templates, JSON config).
     */
    private static function create_templates_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'cpic_templates';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            config LONGTEXT NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY is_default (is_default),
            KEY is_active (is_active)
        ) {$charset};";
        dbDelta( $sql );
    }

    /**
     * Category → template mapping table.
     */
    private static function create_category_mappings_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'cpic_category_mappings';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            term_id BIGINT(20) UNSIGNED NOT NULL,
            template_id BIGINT(20) UNSIGNED NOT NULL,
            priority INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY term_id (term_id),
            KEY template_id (template_id)
        ) {$charset};";
        dbDelta( $sql );
    }

    /**
     * Persistent generation / publishing job table.
     */
    private static function create_jobs_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'cpic_jobs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            job_key VARCHAR(191) NOT NULL,
            template_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            image_path TEXT,
            image_url TEXT,
            facebook_post_id VARCHAR(191) NOT NULL DEFAULT '',
            facebook_comment_id VARCHAR(191) NOT NULL DEFAULT '',
            attempts INT NOT NULL DEFAULT 0,
            max_retries INT NOT NULL DEFAULT 3,
            last_error TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY job_key (job_key),
            KEY post_id (post_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};";
        dbDelta( $sql );
    }

    /**
     * Ensure cpic upload directories exist.
     */
    private static function ensure_upload_dirs() {
        $upload_dir = wp_upload_dir();
        foreach ( array( 'cpic-temp', 'cpic-generated', 'cpic-previews' ) as $dir ) {
            $path = $upload_dir['basedir'] . '/' . $dir;
            if ( ! file_exists( $path ) ) {
                wp_mkdir_p( $path );
            }
        }
    }

    /**
     * Migrate legacy global settings into a template record and seed
     * sample templates, but only when no templates exist yet.
     */
    public static function maybe_migrate() {
        global $wpdb;
        $table = $wpdb->prefix . 'cpic_templates';

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) {
            return;
        }

        $config = self::migrate_legacy_config();

        // The default template (keeps backward-compatible behaviour).
        $default_id = CPIC_Template_Manager::insert_template(
            __( 'Default Template', 'custom-post-image-creator' ),
            'default',
            $config,
            true,
            1
        );

        // Seed sample category templates (dynamic DB records, editable).
        $samples = array(
            __( 'National', 'custom-post-image-creator' )       => 'national',
            __( 'Politics', 'custom-post-image-creator' )       => 'politics',
            __( 'Entertainment', 'custom-post-image-creator' )  => 'entertainment',
            __( 'Sports', 'custom-post-image-creator' )         => 'sports',
            __( 'International', 'custom-post-image-creator' )  => 'international',
            __( 'Technology', 'custom-post-image-creator' )     => 'technology',
        );

        foreach ( $samples as $name => $slug ) {
            $sample_config           = $config;
            $sample_config['name']   = $name;
            CPIC_Template_Manager::insert_template( $name, $slug, $sample_config, false, 1 );
        }

        if ( $default_id ) {
            CPIC_Settings::set_single( 'cpic_default_template_id', (int) $default_id );
        }
    }

    /**
     * Build a template config array from the legacy v5 options.
     *
     * @return array
     */
    public static function migrate_legacy_config() {
        $s = cpic()->settings->get_all();

        $headline_font = ( 'Hind Siliguri' === $s['cpic_title_font'] ) ? 'Hind Siliguri' : 'Noto Serif Bengali';

        return array(
            'name'     => __( 'Default Template', 'custom-post-image-creator' ),
            'canvas'   => array(
                'width'            => (int) $s['cpic_canvas_width'],
                'height'           => (int) $s['cpic_canvas_height'],
                'background_type'  => $s['cpic_active_background'] ? 'image' : 'gradient',
                'background_image' => (string) $s['cpic_active_background'],
                'background_color' => '#1a1a2e',
                'gradient_from'    => '#667eea',
                'gradient_to'      => '#764ba2',
                'padding'          => 40,
            ),
            'elements' => array(
                'featured_image' => array(
                    'enabled'       => true,
                    'type'          => 'image',
                    'x'             => (int) $s['cpic_feat_offset_x'],
                    'y'             => (int) $s['cpic_feat_offset_y'],
                    'width'         => (int) $s['cpic_feat_width'],
                    'height'        => (int) $s['cpic_feat_height'],
                    'fit'           => 'fill',
                    'border_radius' => 0,
                    'opacity'       => 100,
                    'fallback'      => 'hide',
                    'fallback_image'=> '',
                    'z_index'       => 10,
                ),
                'headline' => array(
                    'enabled'        => true,
                    'type'           => 'text',
                    'font'           => $headline_font,
                    'font_size'      => (int) $s['cpic_title_size'],
                    'min_font_size'  => 22,
                    'max_font_size'  => 56,
                    'color'          => (string) $s['cpic_title_color'],
                    'align'          => 'left',
                    'max_lines'      => 3,
                    'max_width'      => (int) $s['cpic_canvas_width'] - (int) $s['cpic_title_offset_x'] - 40,
                    'line_height'    => 1.35,
                    'letter_spacing' => 0,
                    'x'              => (int) $s['cpic_title_offset_x'],
                    'y'              => (int) $s['cpic_title_offset_y'],
                    'shadow_color'   => 'rgba(0,0,0,0.65)',
                    'shadow_offset'  => 2,
                    'auto_scale'     => true,
                    'bold'           => true,
                    'z_index'        => 30,
                ),
                'category' => array(
                    'enabled'         => true,
                    'type'            => 'text',
                    'font'            => 'Hind Siliguri',
                    'font_size'       => 24,
                    'color'           => '#FFFFFF',
                    'background_color'=> '#E53935',
                    'x'               => (int) $s['cpic_title_offset_x'],
                    'y'               => (int) $s['cpic_title_offset_y'] - 60,
                    'align'           => 'left',
                    'padding_x'       => 16,
                    'padding_y'       => 6,
                    'border_radius'   => 6,
                    'bold'            => true,
                    'z_index'         => 20,
                ),
                'date' => array(
                    'enabled'         => true,
                    'type'            => 'text',
                    'font'            => (string) $s['cpic_date_font'],
                    'font_size'       => 28,
                    'color'           => (string) $s['cpic_date_color'],
                    'background_color'=> (string) $s['cpic_date_bg_color'],
                    'x'               => (int) $s['cpic_date_offset_x'],
                    'y'               => (int) $s['cpic_date_offset_y'],
                    'align'           => 'left',
                    'padding_x'       => 16,
                    'padding_y'       => 6,
                    'border_radius'   => 6,
                    'format'          => 'bangla',
                    'bold'            => true,
                    'z_index'         => 25,
                ),
                'logo' => array(
                    'enabled' => true,
                    'type'    => 'image',
                    'source'  => (string) $s['cpic_logo'],
                    'x'       => (int) $s['cpic_logo_offset_x'],
                    'y'       => (int) $s['cpic_logo_offset_y'],
                    'width'   => (int) $s['cpic_logo_width'],
                    'height'  => 0,
                    'opacity' => 100,
                    'z_index' => 5,
                ),
                'author' => array(
                    'enabled'     => false,
                    'type'        => 'text',
                    'font'        => 'Hind Siliguri',
                    'font_size'   => 20,
                    'color'       => '#FFFFFF',
                    'align'       => 'left',
                    'x'           => (int) $s['cpic_title_offset_x'],
                    'y'           => (int) $s['cpic_date_offset_y'] + 60,
                    'z_index'     => 26,
                ),
                'website_name' => array(
                    'enabled'     => false,
                    'type'        => 'text',
                    'font'        => 'Noto Serif Bengali',
                    'font_size'   => 20,
                    'color'       => '#FFFFFF',
                    'align'       => 'left',
                    'x'           => (int) $s['cpic_logo_offset_x'],
                    'y'           => (int) $s['cpic_logo_offset_y'] + 100,
                    'z_index'     => 27,
                ),
            ),
            'shapes'   => array(),
        );
    }

    /**
     * Check whether a schema upgrade is required (plugin version changed).
     */
    public static function maybe_upgrade() {
        $db_version = get_option( 'cpic_db_version', '' );
        if ( version_compare( $db_version, CPIC_VERSION, '<' ) ) {
            self::run();
        }
    }
}
