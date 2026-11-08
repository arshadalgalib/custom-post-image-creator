<?php
/**
 * Plugin settings – load / save all options via WP Options API.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Settings {

    /**
     * Default option values.
     *
     * @var array
     */
    private $defaults = array(
        /* Title */
        'cpic_title_font'       => 'Noto Serif Bengali',
        'cpic_title_size'       => 42,
        'cpic_title_color'      => '#FFFFFF',
        'cpic_title_offset_x'   => 40,
        'cpic_title_offset_y'   => 750,

        /* Date */
        'cpic_date_font'        => 'Hind Siliguri',
        'cpic_date_color'       => '#FFFFFF',
        'cpic_date_bg_color'    => '#E53935',
        'cpic_date_offset_x'    => 40,
        'cpic_date_offset_y'    => 950,

        /* Logo */
        'cpic_logo'             => '',
        'cpic_logo_width'       => 120,
        'cpic_logo_offset_x'    => 40,
        'cpic_logo_offset_y'    => 40,

        /* Featured image */
        'cpic_feat_offset_x'    => 0,
        'cpic_feat_offset_y'    => 0,
        'cpic_feat_width'       => 1080,
        'cpic_feat_height'      => 650,

        /* Canvas size */
        'cpic_canvas_width'     => 1080,
        'cpic_canvas_height'    => 1080,

        /* Background images (serialised array) */
        'cpic_background_images' => array(),

        /* Active background URL */
        'cpic_active_background' => '',

        /* Comment / caption */
        'cpic_comment_message'  => 'বিস্তারিত পড়ুন',

        /* Facebook */
        'cpic_fb_app_id'        => '',
        'cpic_fb_app_secret'    => '',
        'cpic_fb_page_id'       => '',
        'cpic_fb_page_name'     => '',
        'cpic_fb_page_token'    => '',

        /* Telegram */
        'cpic_tg_bot_token'     => '',
        'cpic_tg_chat_id'       => '',

        /* Auto-publish */
        'cpic_auto_publish'      => 0,
        'cpic_auto_fb'           => 0,
        'cpic_auto_tg'           => 0,
        'cpic_auto_delay'        => 0,  // seconds after publish

        /* New automation system */
        'cpic_auto_generate'     => 0,  // explicit opt-in to the automatic pipeline
        'cpic_auto_comment'      => 1,  // post article URL as first comment
        'cpic_max_retries'       => 3,  // retry count
        'cpic_default_template_id' => 0,
        'cpic_delete_data_on_uninstall' => 0,
    );

    /**
     * Static read helper (usable before class instantiation).
     *
     * @param string $key     Option key.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public static function get_single( $key, $default = '' ) {
        $value = get_option( $key, false );
        if ( false === $value && 'cpic_auto_generate' === $key ) {
            // Preserve the old master switch even before the admin upgrade hook runs.
            return get_option( 'cpic_auto_publish', $default );
        }
        return false === $value ? $default : $value;
    }

    /**
     * Static write helper.
     *
     * @param string $key   Option key.
     * @param mixed  $value Value.
     */
    public static function set_single( $key, $value ) {
        update_option( $key, $value );
    }

    public function __construct() {
        add_action( 'wp_ajax_cpic_save_settings', array( $this, 'ajax_save' ) );
        add_action( 'wp_ajax_cpic_get_settings',  array( $this, 'ajax_get' ) );
        add_action( 'wp_ajax_cpic_save_backgrounds', array( $this, 'ajax_save_backgrounds' ) );
        add_action( 'wp_ajax_cpic_clear_backgrounds', array( $this, 'ajax_clear_backgrounds' ) );
    }

    /**
     * Get a single option value.
     *
     * @param string $key Option key.
     * @return mixed
     */
    public function get( $key ) {
        $default = isset( $this->defaults[ $key ] ) ? $this->defaults[ $key ] : '';
        return get_option( $key, $default );
    }

    /**
     * Get all settings as an associative array.
     *
     * @return array
     */
    public function get_all() {
        $values = array();
        foreach ( $this->defaults as $key => $default ) {
            $values[ $key ] = get_option( $key, $default );
        }
        return $values;
    }

    /**
     * Save a single option.
     *
     * @param string $key   Option key.
     * @param mixed  $value Value.
     */
    public function set( $key, $value ) {
        update_option( $key, $value );
    }

    /* ─── AJAX: save settings ─────────────────────────────── */

    public function ajax_save() {
        cpic()->security->verify_ajax_request();

        $fields_text = array(
            'cpic_title_font', 'cpic_title_color', 'cpic_date_font',
            'cpic_date_color', 'cpic_date_bg_color', 'cpic_comment_message',
        );

        $fields_int = array(
            'cpic_title_size',
            'cpic_title_offset_x', 'cpic_title_offset_y',
            'cpic_date_offset_x',  'cpic_date_offset_y',
            'cpic_logo_width',     'cpic_logo_offset_x', 'cpic_logo_offset_y',
            'cpic_feat_offset_x',  'cpic_feat_offset_y',
            'cpic_feat_width',     'cpic_feat_height',
            'cpic_canvas_width',   'cpic_canvas_height',
            'cpic_auto_publish',   'cpic_auto_fb', 'cpic_auto_tg',
            'cpic_auto_delay',
            'cpic_auto_generate',  'cpic_auto_comment', 'cpic_max_retries',
            'cpic_default_template_id', 'cpic_delete_data_on_uninstall',
        );

        $fields_url = array( 'cpic_logo', 'cpic_active_background' );

        $sec = cpic()->security;

        foreach ( $fields_text as $f ) {
            if ( isset( $_POST[ $f ] ) ) {
                $val = $sec->sanitize_text( $_POST[ $f ] );
                // Re-sanitize colours.
                if ( strpos( $f, 'color' ) !== false ) {
                    $val = $sec->sanitize_hex( $val );
                }
                $this->set( $f, $val );
            }
        }

        foreach ( $fields_int as $f ) {
            if ( isset( $_POST[ $f ] ) ) {
                $value = $sec->sanitize_int( $_POST[ $f ] );
                if ( 'cpic_auto_delay' === $f ) { $value = max( 0, min( 3600, $value ) ); }
                if ( 'cpic_max_retries' === $f ) { $value = max( 0, min( 10, $value ) ); }
                if ( in_array( $f, array( 'cpic_auto_publish', 'cpic_auto_fb', 'cpic_auto_tg', 'cpic_auto_generate', 'cpic_auto_comment', 'cpic_delete_data_on_uninstall' ), true ) ) { $value = $value ? 1 : 0; }
                $this->set( $f, $value );
            }
        }

        foreach ( $fields_url as $f ) {
            if ( isset( $_POST[ $f ] ) ) {
                $this->set( $f, $sec->sanitize_url( $_POST[ $f ] ) );
            }
        }

        // Facebook credentials.
        $fb_fields = array( 'cpic_fb_app_id', 'cpic_fb_app_secret', 'cpic_fb_page_id', 'cpic_fb_page_name', 'cpic_fb_page_token' );
        foreach ( $fb_fields as $f ) {
            if ( isset( $_POST[ $f ] ) ) {
                $this->set( $f, $sec->sanitize_text( $_POST[ $f ] ) );
            }
        }

        // Telegram credentials.
        $tg_fields = array( 'cpic_tg_bot_token', 'cpic_tg_chat_id' );
        foreach ( $tg_fields as $f ) {
            if ( isset( $_POST[ $f ] ) ) {
                $this->set( $f, $sec->sanitize_text( $_POST[ $f ] ) );
            }
        }

        wp_send_json_success( array( 'message' => __( 'Settings saved.', 'custom-post-image-creator' ) ) );
    }

    /* ─── AJAX: get settings ──────────────────────────────── */

    public function ajax_get() {
        cpic()->security->verify_ajax_request();
        wp_send_json_success( $this->get_all() );
    }

    /* ─── AJAX: save backgrounds ──────────────────────────── */

    public function ajax_save_backgrounds() {
        cpic()->security->verify_ajax_request();

        $raw = isset( $_POST['backgrounds'] ) ? $_POST['backgrounds'] : array();
        $clean = array();

        if ( is_array( $raw ) ) {
            foreach ( $raw as $url ) {
                $sanitized = esc_url_raw( wp_unslash( $url ) );
                if ( $sanitized ) {
                    $clean[] = $sanitized;
                }
            }
        }

        $this->set( 'cpic_background_images', $clean );
        wp_send_json_success( array( 'backgrounds' => $clean ) );
    }

    /* ─── AJAX: clear backgrounds ─────────────────────────── */

    public function ajax_clear_backgrounds() {
        cpic()->security->verify_ajax_request();
        $this->set( 'cpic_background_images', array() );
        wp_send_json_success();
    }
}
