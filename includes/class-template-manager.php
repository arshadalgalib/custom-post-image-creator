<?php
/**
 * Template manager – CRUD for dynamic photocard templates stored as JSON
 * configuration records in the cpic_templates table.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Template_Manager {

    /* ─── Table helpers ────────────────────────────────── */

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cpic_templates';
    }

    /* ─── Default config ───────────────────────────────── */

    /**
     * Return a full default template config array.
     *
     * @return array
     */
    public static function default_config() {
        $legacy = CPIC_Installer::migrate_legacy_config();
        return $legacy;
    }

    /**
     * Normalise a template config (fills missing keys with defaults).
     *
     * @param array $config Raw config.
     * @return array
     */
    public static function normalize_config( $config ) {
        $base = self::default_config();
        if ( ! is_array( $config ) ) {
            return $base;
        }

        $merged          = array_merge( $base, $config );
        $merged['canvas'] = array_merge( $base['canvas'], isset( $config['canvas'] ) && is_array( $config['canvas'] ) ? $config['canvas'] : array() );
        // Bound dimensions to a practical range to prevent malformed imports exhausting memory.
        $merged['canvas']['width'] = max( 64, min( 4096, absint( $merged['canvas']['width'] ) ) );
        $merged['canvas']['height'] = max( 64, min( 4096, absint( $merged['canvas']['height'] ) ) );
        $merged['canvas']['padding'] = max( 0, min( 512, absint( $merged['canvas']['padding'] ) ) );
        $merged['canvas']['background_type'] = in_array( $merged['canvas']['background_type'], array( 'gradient', 'color', 'image' ), true ) ? $merged['canvas']['background_type'] : 'gradient';

        // Caption composition is template-specific. Keep the historical
        // behaviour (first paragraph on, headline off) for existing and new
        // templates unless an administrator changes these switches.
        $caption_defaults = array(
            'include_headline'        => 0,
            'include_first_paragraph' => 1,
        );
        $merged['caption'] = array_merge(
            $caption_defaults,
            isset( $config['caption'] ) && is_array( $config['caption'] ) ? $config['caption'] : array()
        );
        $merged['caption']['include_headline']        = ! empty( $merged['caption']['include_headline'] ) ? 1 : 0;
        $merged['caption']['include_first_paragraph'] = ! empty( $merged['caption']['include_first_paragraph'] ) ? 1 : 0;

        foreach ( $base['elements'] as $key => $default ) {
            if ( isset( $config['elements'][ $key ] ) && is_array( $config['elements'][ $key ] ) ) {
                $merged['elements'][ $key ] = array_merge( $default, $config['elements'][ $key ] );
                $merged['elements'][ $key ]['font_size'] = max( 1, min( 500, absint( $merged['elements'][ $key ]['font_size'] ?? 28 ) ) );
                if ( isset( $merged['elements'][ $key ]['min_font_size'] ) ) { $merged['elements'][ $key ]['min_font_size'] = max( 1, min( 500, absint( $merged['elements'][ $key ]['min_font_size'] ) ) ); }
                if ( isset( $merged['elements'][ $key ]['max_font_size'] ) ) { $merged['elements'][ $key ]['max_font_size'] = max( 1, min( 500, absint( $merged['elements'][ $key ]['max_font_size'] ) ) ); }
                if ( isset( $merged['elements'][ $key ]['max_width'] ) ) { $merged['elements'][ $key ]['max_width'] = max( 1, min( 4096, absint( $merged['elements'][ $key ]['max_width'] ) ) ); }
                if ( isset( $merged['elements'][ $key ]['max_lines'] ) ) { $merged['elements'][ $key ]['max_lines'] = max( 1, min( 30, absint( $merged['elements'][ $key ]['max_lines'] ) ) ); }
                if ( isset( $merged['elements'][ $key ]['line_height'] ) ) { $merged['elements'][ $key ]['line_height'] = max( 0.8, min( 3.0, (float) $merged['elements'][ $key ]['line_height'] ) ); }
                if ( isset( $merged['elements'][ $key ]['letter_spacing'] ) ) { $merged['elements'][ $key ]['letter_spacing'] = max( -10, min( 50, (float) $merged['elements'][ $key ]['letter_spacing'] ) ); }
                foreach ( array( 'x', 'y' ) as $position_key ) {
                    if ( isset( $merged['elements'][ $key ][ $position_key ] ) ) { $merged['elements'][ $key ][ $position_key ] = max( 0, min( 4096, absint( $merged['elements'][ $key ][ $position_key ] ) ) ); }
                }
                foreach ( array( 'width', 'height' ) as $dimension_key ) {
                    if ( isset( $merged['elements'][ $key ][ $dimension_key ] ) ) { $merged['elements'][ $key ][ $dimension_key ] = max( 0, min( 4096, absint( $merged['elements'][ $key ][ $dimension_key ] ) ) ); }
                }
                foreach ( array( 'padding_x', 'padding_y' ) as $padding_key ) {
                    if ( isset( $merged['elements'][ $key ][ $padding_key ] ) ) { $merged['elements'][ $key ][ $padding_key ] = max( 0, min( 512, absint( $merged['elements'][ $key ][ $padding_key ] ) ) ); }
                }
                if ( isset( $merged['elements'][ $key ]['border_radius'] ) ) { $merged['elements'][ $key ]['border_radius'] = max( 0, min( 2048, absint( $merged['elements'][ $key ]['border_radius'] ) ) ); }
                if ( isset( $merged['elements'][ $key ]['max_height'] ) ) { $merged['elements'][ $key ]['max_height'] = max( 0, min( 4096, absint( $merged['elements'][ $key ]['max_height'] ) ) ); }
                if ( isset( $merged['elements'][ $key ]['opacity'] ) ) { $merged['elements'][ $key ]['opacity'] = max( 0, min( 100, absint( $merged['elements'][ $key ]['opacity'] ) ) ); }
                if ( isset( $merged['elements'][ $key ]['shadow_offset'] ) ) { $merged['elements'][ $key ]['shadow_offset'] = max( 0, min( 100, absint( $merged['elements'][ $key ]['shadow_offset'] ) ) ); }
            } else {
                $merged['elements'][ $key ] = $default;
            }
        }

        $merged['shapes'] = isset( $config['shapes'] ) && is_array( $config['shapes'] ) ? $config['shapes'] : array();

        return $merged;
    }

    /* ─── CRUD ─────────────────────────────────────────── */

    /**
     * Insert a new template.
     *
     * @param string $name       Display name.
     * @param string $slug       Unique slug.
     * @param array  $config     Template config array.
     * @param bool   $is_default Whether this becomes the default template.
     * @param int    $is_active  1 or 0.
     * @return int Template ID or 0.
     */
    public static function insert_template( $name, $slug, $config, $is_default = false, $is_active = 1 ) {
        global $wpdb;
        $table = self::table();

        $slug = sanitize_title( $slug );
        if ( empty( $slug ) ) {
            $slug = 'template-' . time();
        }

        $normalized = self::normalize_config( $config );

        if ( $is_default ) {
            self::clear_default();
        }

        $result = $wpdb->insert(
            $table,
            array(
                'name'       => sanitize_text_field( $name ),
                'slug'       => $slug,
                'config'     => wp_json_encode( $normalized ),
                'is_default' => $is_default ? 1 : 0,
                'is_active'  => $is_active ? 1 : 0,
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
        );

        if ( false === $result ) {
            return 0;
        }

        $id = (int) $wpdb->insert_id;

        if ( $is_default ) {
            CPIC_Settings::set_single( 'cpic_default_template_id', $id );
        }

        return $id;
    }

    /**
     * Update an existing template.
     *
     * @param int    $id       Template ID.
     * @param array  $data     Fields: name, slug, config, is_active.
     * @param bool   $default  Whether to set as default.
     * @return bool
     */
    public static function update_template( $id, $data = array(), $default = false ) {
        global $wpdb;
        $table = self::table();

        $id = (int) $id;
        if ( ! $id ) {
            return false;
        }

        $existing = self::get_template( $id );
        if ( ! $existing ) {
            return false;
        }

        $update = array( 'updated_at' => current_time( 'mysql' ) );
        $fmt    = array( '%s' );

        if ( isset( $data['name'] ) ) {
            $update['name'] = sanitize_text_field( $data['name'] );
            $fmt[]          = '%s';
        }

        if ( isset( $data['slug'] ) ) {
            $slug = sanitize_title( $data['slug'] );
            if ( $slug ) {
                $collision = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id != %d LIMIT 1", $slug, $id ) );
                if ( $collision ) { return false; }
                $update['slug'] = $slug;
                $fmt[] = '%s';
            }
        }

        if ( isset( $data['config'] ) ) {
            $update['config'] = wp_json_encode( self::normalize_config( $data['config'] ) );
            $fmt[]            = '%s';
        }

        if ( isset( $data['is_active'] ) ) {
            $update['is_active'] = $data['is_active'] ? 1 : 0;
            $fmt[]               = '%d';
        }

        if ( $default ) {
            self::clear_default();
            $update['is_default'] = 1;
            $fmt[]                = '%d';
            CPIC_Settings::set_single( 'cpic_default_template_id', $id );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->update( $table, $update, array( 'id' => $id ), $fmt, array( '%d' ) );
        return false !== $result;
    }

    /**
     * Fetch a single template.
     *
     * @param int|string $id_or_slug Template ID or slug.
     * @return array|null
     */
    public static function get_template( $id_or_slug ) {
        global $wpdb;
        $table = self::table();

        if ( is_numeric( $id_or_slug ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id_or_slug ), ARRAY_A );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", sanitize_title( $id_or_slug ) ), ARRAY_A );
        }

        if ( ! $row ) {
            return null;
        }

        $row['config']      = json_decode( $row['config'], true );
        $row['config']      = is_array( $row['config'] ) ? $row['config'] : array();
        $row['config']      = self::normalize_config( $row['config'] );
        $row['config']['name'] = $row['name'];

        return $row;
    }

    /**
     * Fetch all templates.
     *
     * @param bool $active_only Only active templates.
     * @return array
     */
    public static function get_templates( $active_only = false ) {
        global $wpdb;
        $table = self::table();

        $sql = "SELECT * FROM {$table}";
        if ( $active_only ) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY is_default DESC, id ASC';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        foreach ( $rows as &$row ) {
            $row['config']      = json_decode( $row['config'], true );
            $row['config']      = is_array( $row['config'] ) ? $row['config'] : array();
            $row['config']      = self::normalize_config( $row['config'] );
            $row['config']['name'] = $row['name'];
        }
        unset( $row );

        return $rows;
    }

    /**
     * Get the default template (fallback when no category mapping matches).
     *
     * @return array|null
     */
    public static function get_default_template() {
        global $wpdb;
        $table = self::table();

        $default_id = (int) CPIC_Settings::get_single( 'cpic_default_template_id' );
        if ( $default_id ) {
            $tpl = self::get_template( $default_id );
            if ( $tpl && ! empty( $tpl['is_active'] ) ) {
                return $tpl;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( "SELECT * FROM {$table} WHERE is_default = 1 AND is_active = 1 ORDER BY id ASC LIMIT 1", ARRAY_A );
        if ( $row ) {
            $row['config'] = json_decode( $row['config'], true );
            $row['config'] = is_array( $row['config'] ) ? self::normalize_config( $row['config'] ) : self::normalize_config( array() );
            return $row;
        }

        // Last resort: first active template.
        $row = $wpdb->get_row( "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY id ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $row ) {
            $row['config'] = json_decode( $row['config'], true );
            $row['config'] = is_array( $row['config'] ) ? self::normalize_config( $row['config'] ) : self::normalize_config( array() );
            return $row;
        }

        return null;
    }

    /**
     * Delete a template. If it is the default, reassign the default.
     * If it is mapped to categories, mappings are removed first.
     *
     * @param int $id Template ID.
     * @return bool
     */
    public static function delete_template( $id ) {
        global $wpdb;
        $table = self::table();

        $id   = (int) $id;
        $tpl  = self::get_template( $id );
        if ( ! $tpl ) {
            return false;
        }

        // Remove category mappings pointing at this template.
        cpic()->category_mapper->remove_mappings_for_template( $id );

        // Reassign default if needed.
        if ( ! empty( $tpl['is_default'] ) ) {
            CPIC_Settings::set_single( 'cpic_default_template_id', 0 );
            $templates = self::get_templates( true );
            foreach ( $templates as $candidate ) {
                if ( (int) $candidate['id'] !== $id ) {
                    self::update_template( $candidate['id'], array( 'is_active' => $candidate['is_active'] ), true );
                    break;
                }
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
    }

    /**
     * Duplicate a template (including its config).
     *
     * @param int $id Template ID.
     * @return int New template ID or 0.
     */
    public static function duplicate_template( $id ) {
        $tpl = self::get_template( $id );
        if ( ! $tpl ) {
            return 0;
        }

        $slug = sanitize_title( $tpl['slug'] . '-copy' );
        $i    = 2;
        while ( self::get_template( $slug ) ) {
            $slug = sanitize_title( $tpl['slug'] . '-copy-' . $i );
            $i++;
        }

        $config = $tpl['config'];
        $config['name'] = $tpl['name'] . ' (Copy)';

        return self::insert_template( $config['name'], $slug, $config, false, (int) $tpl['is_active'] );
    }

    /**
     * Set a template as active/inactive.
     *
     * @param int $id    Template ID.
     * @param int $state 1 or 0.
     * @return bool
     */
    public static function set_active( $id, $state ) {
        return self::update_template( $id, array( 'is_active' => $state ? 1 : 0 ) );
    }

    /**
     * Clear the current default template flag.
     */
    private static function clear_default() {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "UPDATE {$table} SET is_default = 0 WHERE is_default = 1" );
    }

    /* ─── Sample preview data ──────────────────────────── */

    /**
     * Sample post data used by the preview renderer so templates can be
     * previewed without a real post.
     *
     * @return array
     */
    public static function sample_preview_data() {
        return array(
            'title'       => __( 'নতুন অর্থনৈতিক নীতি ঘোষণা সরকারের — সংস্কার ও প্রবৃদ্ধির লক্ষ্যমাত্রা নির্ধারণ', 'custom-post-image-creator' ),
            'category'    => __( 'জাতীয়', 'custom-post-image-creator' ),
            'date'        => '১৪ আগস্ট ২০২৬',
            'author'      => __( 'সম্পাদকীয় ডেস্ক', 'custom-post-image-creator' ),
            'website'     => get_bloginfo( 'name' ),
            'featured_image' => '',
        );
    }
}
