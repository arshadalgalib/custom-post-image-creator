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
     *
     * @return bool True after schema and legacy-data migrations are confirmed complete.
     */
    public static function run() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        self::create_log_table();
        self::create_templates_table();
        self::create_category_mappings_table();
        self::create_jobs_table();

        // Do not run migrations or record the new version if a host rejected
        // any CREATE/ALTER query. The next admin request will retry safely.
        if ( ! self::schema_is_ready() ) {
            return false;
        }

        self::ensure_upload_dirs();
        self::migrate_automation_setting();
        self::maybe_migrate();
        $templates_table = $wpdb->prefix . 'cpic_templates';
        if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$templates_table}" ) < 1 || ! self::import_legacy_queue() ) {
            return false;
        }

        update_option( 'cpic_db_version', CPIC_VERSION );
        return true;
    }

    /** Confirm required schema columns before migrations mark the database current. */
    private static function schema_is_ready() {
        global $wpdb;
        $required = array(
            'cpic_logs' => array( 'id', 'log_type', 'message', 'context', 'created_at' ),
            'cpic_templates' => array( 'id', 'name', 'slug', 'config', 'is_default', 'is_active', 'created_at', 'updated_at' ),
            'cpic_category_mappings' => array( 'id', 'term_id', 'template_id', 'priority', 'created_at' ),
            'cpic_jobs' => array( 'id', 'post_id', 'job_key', 'template_id', 'status', 'image_path', 'image_url', 'renderer_id', 'input_hash', 'template_hash', 'rendered_at', 'facebook_post_id', 'facebook_comment_id', 'telegram_message_id', 'auto_facebook', 'auto_telegram', 'next_attempt_at', 'attempts', 'max_retries', 'last_error', 'created_at', 'updated_at' ),
        );
        foreach ( $required as $suffix => $required_columns ) {
            $table = $wpdb->prefix . $suffix;
            // The table names come only from the fixed plugin suffixes above.
            $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ( ! is_array( $columns ) || array_diff( $required_columns, $columns ) ) {
                return false;
            }
        }
        return true;
    }

    /* ─── Tables ──────────────────────────────────────── */

    private static function create_log_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'cpic_logs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
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

        $sql = "CREATE TABLE {$table} (
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

        $sql = "CREATE TABLE {$table} (
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

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            job_key VARCHAR(191) NOT NULL,
            template_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            image_path TEXT,
            image_url TEXT,
            renderer_id VARCHAR(50) NOT NULL DEFAULT '',
            input_hash VARCHAR(64) NOT NULL DEFAULT '',
            template_hash VARCHAR(64) NOT NULL DEFAULT '',
            rendered_at DATETIME NULL DEFAULT NULL,
            facebook_post_id VARCHAR(191) NOT NULL DEFAULT '',
            facebook_comment_id VARCHAR(191) NOT NULL DEFAULT '',
            telegram_message_id VARCHAR(191) NOT NULL DEFAULT '',
            auto_facebook TINYINT(1) NULL DEFAULT NULL,
            auto_telegram TINYINT(1) NULL DEFAULT NULL,
            next_attempt_at BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            attempts INT NOT NULL DEFAULT 0,
            max_retries INT NOT NULL DEFAULT 3,
            last_error TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY job_key (job_key),
            KEY post_id (post_id),
            KEY status (status),
            KEY next_attempt_at (next_attempt_at),
            KEY created_at (created_at)
        ) {$charset};";
        dbDelta( $sql );
    }

    /**
     * Ensure cpic upload directories exist.
     */
    private static function ensure_upload_dirs() {
        $upload_dir = wp_upload_dir();
        // Only the canonical generated-image directory is needed by 6.5+.
        // Legacy cache/fontconfig folders are left untouched on upgrades.
        foreach ( array( 'cpic-generated' ) as $dir ) {
            $path = trailingslashit( $upload_dir['basedir'] ) . $dir;
            if ( ! is_dir( $path ) ) {
                wp_mkdir_p( $path );
            }
            if ( ! is_dir( $path ) ) { continue; }
            if ( ! file_exists( $path . '/index.php' ) ) {
                file_put_contents( $path . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            }
            if ( ! file_exists( $path . '/.htaccess' ) ) {
                $rules = 'Options -Indexes' . ( in_array( $dir, array( 'cpic-temp', 'cpic-previews', 'cpic-fontconfig' ), true )
                    ? "\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>"
                    : '' );
                file_put_contents( $path . '/.htaccess', $rules . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            }
            if ( ! file_exists( $path . '/web.config' ) ) {
                $rules = '<configuration><system.webServer><directoryBrowse enabled="false" />';
                if ( in_array( $dir, array( 'cpic-temp', 'cpic-previews', 'cpic-fontconfig' ), true ) ) {
                    $rules .= '<security><authorization><clear /><add accessType="Deny" users="*" /></authorization></security>';
                }
                file_put_contents( $path . '/web.config', $rules . '</system.webServer></configuration>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            }
        }
    }

    /** Import pending option-backed tasks into the durable queue exactly once. */
    private static function import_legacy_queue() {
        if ( get_option( 'cpic_legacy_queue_migrated', false ) ) {
            return true;
        }
        $legacy = get_option( 'cpic_publish_queue', array() );
        $complete = true;
        if ( is_array( $legacy ) ) {
            foreach ( $legacy as $task ) {
                if ( ! is_array( $task ) ) { continue; }
                $post_id = isset( $task['post_id'] ) ? absint( $task['post_id'] ) : 0;
                $post = $post_id ? get_post( $post_id ) : null;
                if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
                    continue;
                }
                $job = cpic()->queue->create_job( $post_id );
                if ( ! $job ) {
                    $complete = false;
                    continue;
                }
                if ( 'published' === $job['status'] ) {
                    continue;
                }
                $update = array(
                    'auto_facebook' => isset( $task['auto_fb'] ) ? (int) ! empty( $task['auto_fb'] ) : (int) CPIC_Settings::get_single( 'cpic_auto_fb', 0 ),
                    'auto_telegram' => isset( $task['auto_tg'] ) ? (int) ! empty( $task['auto_tg'] ) : (int) CPIC_Settings::get_single( 'cpic_auto_tg', 0 ),
                );
                if ( isset( $task['scheduled_at'] ) ) {
                    $update['next_attempt_at'] = max( 0, (int) $task['scheduled_at'] );
                }
                if ( ! cpic()->queue->update( (int) $job['id'], $update ) ) {
                    $complete = false;
                }
            }
        }
        if ( $complete ) {
            update_option( 'cpic_legacy_queue_migrated', 1, false );
            return (bool) get_option( 'cpic_legacy_queue_migrated', false );
        }
        return false;
    }

    /** Preserve the old master automation switch when migrating to the new pipeline. */
    private static function migrate_automation_setting() {
        if ( false !== get_option( 'cpic_auto_generate', false ) ) {
            return;
        }
        $legacy_enabled = get_option( 'cpic_auto_publish', 0 );
        CPIC_Settings::set_single( 'cpic_auto_generate', $legacy_enabled ? 1 : 0 );
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
        if ( version_compare( $db_version, CPIC_VERSION, '<' ) || ! self::schema_is_ready() ) {
            self::run();
        }
    }
}
