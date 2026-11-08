<?php
/**
 * Admin UI – menu page, tabs, asset enqueue, AJAX endpoints.
 *
 * Tabs: Dashboard | Templates | Categories | Jobs
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Admin_UI {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_cpic_get_posts', array( $this, 'ajax_get_posts' ) );

        /* Templates */
        add_action( 'wp_ajax_cpic_template_save', array( $this, 'ajax_template_save' ) );
        add_action( 'wp_ajax_cpic_template_delete', array( $this, 'ajax_template_delete' ) );
        add_action( 'wp_ajax_cpic_template_duplicate', array( $this, 'ajax_template_duplicate' ) );
        add_action( 'wp_ajax_cpic_template_toggle', array( $this, 'ajax_template_toggle' ) );
        add_action( 'wp_ajax_cpic_template_set_default', array( $this, 'ajax_template_set_default' ) );
        add_action( 'wp_ajax_cpic_template_preview', array( $this, 'ajax_template_preview' ) );
        add_action( 'wp_ajax_cpic_template_import', array( $this, 'ajax_template_import' ) );

        /* Category mappings */
        add_action( 'wp_ajax_cpic_category_save', array( $this, 'ajax_category_save' ) );

        /* Jobs */
        add_action( 'wp_ajax_cpic_get_jobs', array( $this, 'ajax_get_jobs' ) );
        add_action( 'wp_ajax_cpic_job_process', array( $this, 'ajax_job_process' ) );
        add_action( 'wp_ajax_cpic_job_delete', array( $this, 'ajax_job_delete' ) );
        add_action( 'wp_ajax_cpic_job_retry', array( $this, 'ajax_job_retry' ) );

        /* Browser-rendered canonical artifacts */
        add_action( 'wp_ajax_cpic_render_context', array( $this, 'ajax_render_context' ) );
        add_action( 'wp_ajax_cpic_store_rendered_image', array( $this, 'ajax_store_rendered_image' ) );
        add_action( 'wp_ajax_cpic_store_rendered_chunk', array( $this, 'ajax_store_rendered_chunk' ) );

        /* Dashboard preview */
        add_action( 'wp_ajax_cpic_preview_post', array( $this, 'ajax_preview_post' ) );
        add_action( 'wp_ajax_cpic_bulk_download', array( $this, 'ajax_bulk_download' ) );
    }

    /* ─── Menu ──────────────────────────────────────────── */

    public function add_menu() {
        add_menu_page(
            __( 'Post Image Creator', 'custom-post-image-creator' ),
            __( 'Post Image Creator', 'custom-post-image-creator' ),
            'manage_options',
            'cpic-dashboard',
            array( $this, 'render_page' ),
            'dashicons-format-image',
            30
        );
    }

    /**
     * Render the page shell + current tab.
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'custom-post-image-creator' ) );
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
        if ( ! in_array( $tab, array( 'dashboard', 'templates', 'categories', 'jobs', 'setup' ), true ) ) {
            $tab = 'dashboard';
        }

        $this->render_tabs( $tab );

        $template = '';
        switch ( $tab ) {
            case 'templates':
                $template = 'admin-templates.php';
                break;
            case 'categories':
                $template = 'admin-categories.php';
                break;
            case 'jobs':
                $template = 'admin-jobs.php';
                break;
            case 'setup':
                $template = 'admin-setup-guide.php';
                break;
            default:
                $template = 'admin-dashboard.php';
        }

        include CPIC_PLUGIN_DIR . 'templates/' . $template;
    }

    /**
     * Render tab navigation.
     *
     * @param string $active Active tab key.
     */
    private function render_tabs( $active ) {
        $tabs = array(
            'dashboard'  => __( 'Dashboard', 'custom-post-image-creator' ),
            'templates'  => __( 'Templates', 'custom-post-image-creator' ),
            'categories' => __( 'Category Mapping', 'custom-post-image-creator' ),
            'jobs'       => __( 'Jobs & Logs', 'custom-post-image-creator' ),
            'setup'      => __( 'Setup Guide', 'custom-post-image-creator' ),
        );

        echo '<div class="wrap"><h1 class="cpic-page-title"><span class="dashicons dashicons-format-image"></span> ';
        esc_html_e( 'Custom Post Image Creator', 'custom-post-image-creator' );
        echo ' <small>v' . esc_html( CPIC_VERSION ) . '</small></h1>';
        echo '<h2 class="nav-tab-wrapper cpic-tabs">';
        foreach ( $tabs as $key => $label ) {
            $url = add_query_arg( 'tab', $key, admin_url( 'admin.php?page=cpic-dashboard' ) );
            $cls = ( $key === $active ) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</h2></div>';
    }

    /* ─── Assets ─────────────────────────────────────────── */

    public function enqueue_assets( $hook ) {
        $screen       = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_dashboard = 'toplevel_page_cpic-dashboard' === $hook;
        $is_editor    = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && 'post' === $screen->post_type;

        if ( ! $is_dashboard && ! $is_editor ) {
            return;
        }

        $settings = cpic()->settings->get_all();

        // Remove sensitive tokens before sending to browser.
        $safe_settings = $settings;
        unset( $safe_settings['cpic_fb_page_token'] );
        unset( $safe_settings['cpic_fb_app_secret'] );
        unset( $safe_settings['cpic_tg_bot_token'] );
        unset( $safe_settings['cpic_tg_chat_id'] );


        /* ── Common data ───────────────────────────────── */
        $common_data = array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( CPIC_Security::NONCE_ACTION ),
            'nonceField' => CPIC_Security::NONCE_FIELD,
            'pluginUrl'  => CPIC_PLUGIN_URL,
            'settings'   => $safe_settings,
            'i18n'       => array(
                'processing'    => __( 'Processing…', 'custom-post-image-creator' ),
                'saved'         => __( 'Settings saved.', 'custom-post-image-creator' ),
                'error'         => __( 'An error occurred.', 'custom-post-image-creator' ),
                'confirmClear'  => __( 'Remove all backgrounds?', 'custom-post-image-creator' ),
                'noSelection'   => __( 'Please select at least one post.', 'custom-post-image-creator' ),
                'fbSuccess'     => __( 'Posted to Facebook.', 'custom-post-image-creator' ),
                'tgSuccess'     => __( 'Sent to Telegram.', 'custom-post-image-creator' ),
                'confirmDelete' => __( 'Delete this template? Category mappings will be removed too.', 'custom-post-image-creator' ),
                'canvasSaving'  => __( 'Saving browser-rendered card…', 'custom-post-image-creator' ),
                'canvasSaved'   => __( 'Browser-rendered card saved. Automatic publishing can now reuse this image.', 'custom-post-image-creator' ),
                'canvasSaveFailed' => __( 'Could not save the browser-rendered card.', 'custom-post-image-creator' ),
            ),
            'banglaDigits' => array( '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' ),
            'banglaMonths' => array(
                'January'   => 'জানুয়ারি',
                'February'  => 'ফেব্রুয়ারি',
                'March'     => 'মার্চ',
                'April'     => 'এপ্রিল',
                'May'       => 'মে',
                'June'      => 'জুন',
                'July'      => 'জুলাই',
                'August'    => 'আগস্ট',
                'September' => 'সেপ্টেম্বর',
                'October'   => 'অক্টোবর',
                'November'  => 'নভেম্বর',
                'December'  => 'ডিসেম্বর',
            ),
        );
        if ( ! $is_dashboard ) {
            if ( $is_editor ) {
                $common_data['isEditor'] = true;
                $common_data['postId'] = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
                wp_enqueue_script( 'cpic-canvas-renderer', CPIC_PLUGIN_URL . 'js/canvas-renderer.js', array(), CPIC_VERSION, true );
                wp_enqueue_script( 'cpic-canvas', CPIC_PLUGIN_URL . 'js/cpic-canvas.js', array( 'jquery', 'cpic-canvas-renderer', 'wp-data' ), CPIC_VERSION, true );
                wp_localize_script( 'cpic-canvas', 'cpicData', $common_data );
            }
            return;
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

        /* ── CSS ───────────────────────────────────────── */
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_style( 'cpic-style', CPIC_PLUGIN_URL . 'css/cpic-style.css', array(), CPIC_VERSION );



        /* ── Dashboard (legacy canvas editor) ───────────── */
        if ( 'dashboard' === $tab ) {
            wp_enqueue_media();
            wp_enqueue_script( 'wp-color-picker' );
            wp_enqueue_script( 'cpic-canvas-renderer', CPIC_PLUGIN_URL . 'js/canvas-renderer.js', array(), CPIC_VERSION, true );
            wp_enqueue_script( 'cpic-canvas', CPIC_PLUGIN_URL . 'js/cpic-canvas.js', array( 'jquery', 'cpic-canvas-renderer' ), CPIC_VERSION, true );
            wp_localize_script( 'cpic-canvas', 'cpicData', $common_data );
            wp_enqueue_script( 'cpic-script', CPIC_PLUGIN_URL . 'js/cpic-script.js', array( 'jquery', 'wp-color-picker', 'cpic-canvas' ), CPIC_VERSION, true );
            wp_enqueue_script( 'cpic-bulk', CPIC_PLUGIN_URL . 'js/bulk-processing.js', array( 'cpic-script' ), CPIC_VERSION, true );
            wp_localize_script( 'cpic-script', 'cpicData', $common_data );
            return;
        }

        if ( 'templates' === $tab ) {
            wp_enqueue_media();
            wp_enqueue_script( 'wp-color-picker' );
            wp_enqueue_script( 'cpic-canvas-renderer', CPIC_PLUGIN_URL . 'js/canvas-renderer.js', array(), CPIC_VERSION, true );
            wp_enqueue_script( 'cpic-canvas', CPIC_PLUGIN_URL . 'js/cpic-canvas.js', array( 'jquery', 'cpic-canvas-renderer' ), CPIC_VERSION, true );
            wp_localize_script( 'cpic-canvas', 'cpicData', $common_data );
            wp_enqueue_script( 'cpic-templates', CPIC_PLUGIN_URL . 'js/templates.js', array( 'jquery', 'wp-color-picker', 'cpic-canvas' ), CPIC_VERSION, true );

            $templates = CPIC_Template_Manager::get_templates();
            $template_data = array();
            foreach ( $templates as $tpl ) {
                $template_data[] = array(
                    'id'         => (int) $tpl['id'],
                    'name'       => $tpl['name'],
                    'slug'       => $tpl['slug'],
                    'is_default' => (int) $tpl['is_default'],
                    'is_active'  => (int) $tpl['is_active'],
                    'config'     => $tpl['config'],
                );
            }
            $common_data['templates']   = $template_data;
            $common_data['fontChoices'] = array( 'Noto Serif Bengali', 'Hind Siliguri', 'Noto Sans Bengali' );
            foreach ( array( 'SolaimanLipi' => 'SolaimanLipi.ttf', 'Siyam Rupali' => 'SiyamRupali.ttf' ) as $font_name => $font_file ) {
                if ( file_exists( CPIC_PLUGIN_DIR . 'fonts/' . $font_file ) ) { $common_data['fontChoices'][] = $font_name; }
            }
            $common_data['defaultTemplateId'] = (int) CPIC_Settings::get_single( 'cpic_default_template_id' );
            wp_localize_script( 'cpic-templates', 'cpicData', $common_data );
            return;
        }

        if ( 'categories' === $tab ) {
            wp_enqueue_script( 'cpic-categories', CPIC_PLUGIN_URL . 'js/categories.js', array( 'jquery' ), CPIC_VERSION, true );
            wp_localize_script( 'cpic-categories', 'cpicData', $common_data );
            return;
        }

        if ( 'jobs' === $tab ) {
            wp_enqueue_script( 'cpic-jobs', CPIC_PLUGIN_URL . 'js/jobs.js', array( 'jquery' ), CPIC_VERSION, true );
            wp_localize_script( 'cpic-jobs', 'cpicData', $common_data );
        }
    }

    /* ─── AJAX: fetch posts ────────────────────────────────── */

    public function ajax_get_posts() {
        cpic()->security->verify_ajax_request();

        $paged    = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
        $per_page = 20;

        $args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        // Date filtering.
        $date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
        $date_to   = isset( $_POST['date_to'] )   ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) )   : '';

        if ( empty( $date_from ) && empty( $date_to ) ) {
            // Default: last 24 hours.
            $args['date_query'] = array(
                array(
                    'after'     => '24 hours ago',
                    'inclusive' => true,
                ),
            );
        } else {
            $date_query = array( 'inclusive' => true );
            if ( $date_from ) {
                $date_query['after'] = $date_from;
            }
            if ( $date_to ) {
                $date_query['before'] = $date_to . ' 23:59:59';
            }
            $args['date_query'] = array( $date_query );
        }

        $query = new WP_Query( $args );
        $posts = array();

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $pid = get_the_ID();
                $posts[] = array(
                    'id'              => $pid,
                    'title'           => get_the_title(),
                    'author'          => get_the_author(),
                    'date'            => get_the_date( 'Y-m-d' ),
                    'bangla_date'     => CPIC_Bangla_Date::format( get_post_timestamp( $pid ) ),
                    'permalink'       => get_permalink(),
                    'featured_image'  => get_the_post_thumbnail_url( $pid, 'large' ) ?: '',
                    'excerpt'         => $this->get_post_summary( $pid ),
                    'last_fb'         => get_post_meta( $pid, 'cpic_last_shared_to_facebook', true ),
                    'last_tg'         => get_post_meta( $pid, 'cpic_last_shared_to_telegram', true ),
                );
            }
            wp_reset_postdata();
        }

        wp_send_json_success( array(
            'posts'       => $posts,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
            'page'        => $paged,
        ) );
    }

    /* ─── AJAX: templates ─────────────────────────────────── */

    public function ajax_template_save() {
        cpic()->security->verify_ajax_request();

        $id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $raw_json = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : '{}';
        $raw = json_decode( $raw_json, true );

        if ( empty( $name ) ) {
            wp_send_json_error( array( 'message' => __( 'Template name is required.', 'custom-post-image-creator' ) ) );
        }

        if ( ! is_array( $raw ) || JSON_ERROR_NONE !== json_last_error() ) {
            wp_send_json_error( array( 'message' => __( 'Template configuration is invalid JSON.', 'custom-post-image-creator' ) ) );
        }

        $config = CPIC_Template_Manager::normalize_config( $this->sanitize_config( $raw ) );

        if ( $id ) {
            $ok = CPIC_Template_Manager::update_template( $id, array( 'name' => $name, 'config' => $config ) );
            if ( ! $ok ) {
                wp_send_json_error( array( 'message' => __( 'Could not update template.', 'custom-post-image-creator' ) ) );
            }
        } else {
            $slug = sanitize_title( $name );
            $i    = 2;
            while ( CPIC_Template_Manager::get_template( $slug ) ) {
                $slug = sanitize_title( $name . '-' . $i );
                $i++;
            }
            $id = CPIC_Template_Manager::insert_template( $name, $slug, $config, false, 1 );
            if ( ! $id ) {
                wp_send_json_error( array( 'message' => __( 'Could not create template.', 'custom-post-image-creator' ) ) );
            }
        }

        wp_send_json_success( array(
            'message' => __( 'Template saved.', 'custom-post-image-creator' ),
            'id'      => $id,
        ) );
    }

    public function ajax_template_delete() {
        cpic()->security->verify_ajax_request();

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid template.', 'custom-post-image-creator' ) ) );
        }

        $ok = CPIC_Template_Manager::delete_template( $id );
        wp_send_json_success( array( 'message' => $ok ? __( 'Template deleted.', 'custom-post-image-creator' ) : __( 'Template not found.', 'custom-post-image-creator' ) ) );
    }

    public function ajax_template_duplicate() {
        cpic()->security->verify_ajax_request();

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $new = CPIC_Template_Manager::duplicate_template( $id );

        if ( ! $new ) {
            wp_send_json_error( array( 'message' => __( 'Could not duplicate template.', 'custom-post-image-creator' ) ) );
        }

        wp_send_json_success( array( 'message' => __( 'Template duplicated.', 'custom-post-image-creator' ), 'id' => $new ) );
    }

    /**
     * Import one portable template definition as a new template.
     * IDs, default state, and category mappings are deliberately not
     * imported because they are site-specific.
     */
    public function ajax_template_import() {
        cpic()->security->verify_ajax_request();

        $raw = isset( $_POST['template'] ) ? json_decode( wp_unslash( $_POST['template'] ), true ) : array();
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $raw ) || empty( $raw['config'] ) || ! is_array( $raw['config'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid template JSON. Export a template from this plugin first.', 'custom-post-image-creator' ) ) );
        }

        $name = isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '';
        if ( ! $name && isset( $raw['config']['name'] ) ) {
            $name = sanitize_text_field( $raw['config']['name'] );
        }
        if ( ! $name ) {
            $name = __( 'Imported Template', 'custom-post-image-creator' );
        }

        $config = CPIC_Template_Manager::normalize_config( $this->sanitize_config( $raw['config'] ) );
        $slug   = sanitize_title( $name ) ?: 'imported-template';
        $base   = $slug;
        $i      = 2;
        while ( CPIC_Template_Manager::get_template( $slug ) ) {
            $slug = $base . '-' . $i;
            $i++;
        }

        $id = CPIC_Template_Manager::insert_template( $name, $slug, $config, false, 1 );
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Could not import template.', 'custom-post-image-creator' ) ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Template imported.', 'custom-post-image-creator' ),
            'id'      => $id,
        ) );
    }

    public function ajax_template_toggle() {
        cpic()->security->verify_ajax_request();

        $id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $state = isset( $_POST['active'] ) ? (int) $_POST['active'] : 0;

        $ok = CPIC_Template_Manager::set_active( $id, $state );
        wp_send_json_success( array( 'message' => $ok ? __( 'Template updated.', 'custom-post-image-creator' ) : __( 'Update failed.', 'custom-post-image-creator' ) ) );
    }

    public function ajax_template_set_default() {
        cpic()->security->verify_ajax_request();

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $tpl = CPIC_Template_Manager::get_template( $id );

        if ( ! $tpl || empty( $tpl['is_active'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Select an active template to set it as default.', 'custom-post-image-creator' ) ) );
        }

        $ok = CPIC_Template_Manager::update_template( $id, array( 'is_active' => $tpl['is_active'] ), true );
        wp_send_json_success( array( 'message' => $ok ? __( 'Default template updated.', 'custom-post-image-creator' ) : __( 'Update failed.', 'custom-post-image-creator' ) ) );
    }

    /**
     * Render a template using the real server renderer with sample data.
     */
    public function ajax_template_preview() {
        cpic()->security->verify_ajax_request();

        $raw  = isset( $_POST['config'] ) ? json_decode( wp_unslash( $_POST['config'] ), true ) : array();
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $raw ) ) {
            wp_send_json_error( array( 'message' => __( 'Template configuration is invalid JSON.', 'custom-post-image-creator' ) ) );
        }
        $config = CPIC_Template_Manager::normalize_config( $this->sanitize_config( $raw ) );

        $data = CPIC_Template_Manager::sample_preview_data();
        if ( ! empty( $_POST['featured_image'] ) ) {
            $data['featured_image'] = esc_url_raw( wp_unslash( $_POST['featured_image'] ) );
        }

        wp_send_json_success( array(
            'config' => $config,
            'data' => $data,
            'template_hash' => hash( 'sha256', wp_json_encode( $config ) ),
            'input_hash' => hash( 'sha256', wp_json_encode( $data ) ),
            'renderer' => CPIC_Canvas_Artifacts::RENDERER,
        ) );
    }

    /* ─── AJAX: category mappings ─────────────────────────── */

    public function ajax_category_save() {
        cpic()->security->verify_ajax_request();

        $raw_mappings = isset( $_POST['mappings'] ) && is_array( $_POST['mappings'] ) ? $_POST['mappings'] : array();
        $default_id   = isset( $_POST['default_template_id'] ) ? absint( $_POST['default_template_id'] ) : 0;

        $count = 0;
        foreach ( $raw_mappings as $mapping ) {
            $term_id     = isset( $mapping['term_id'] ) ? absint( $mapping['term_id'] ) : 0;
            $template_id = isset( $mapping['template_id'] ) ? absint( $mapping['template_id'] ) : 0;
            $priority    = isset( $mapping['priority'] ) ? absint( $mapping['priority'] ) : 1;

            if ( ! $term_id ) {
                continue;
            }

            if ( ! $template_id ) {
                cpic()->category_mapper->remove_mapping( $term_id );
                continue;
            }

            if ( cpic()->category_mapper->set_mapping( $term_id, $template_id, $priority ) ) {
                $count++;
            }
        }

        if ( $default_id ) {
            $tpl = CPIC_Template_Manager::get_template( $default_id );
            if ( $tpl ) {
                CPIC_Template_Manager::update_template( $default_id, array( 'is_active' => $tpl['is_active'] ), true );
            }
        }

        wp_send_json_success( array(
            'message' => sprintf( __( '%d mappings saved.', 'custom-post-image-creator' ), $count ),
        ) );
    }

    /* ─── AJAX: jobs ──────────────────────────────────────── */

    public function ajax_get_jobs() {
        cpic()->security->verify_ajax_request();

        // Process any pending jobs while we're here (shared hosting cron backup).
        if ( CPIC_Settings::get_single( 'cpic_auto_generate', 0 ) ) {
            cpic()->automation->process_due_jobs( 5 );
        }

        $status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
        $jobs   = cpic()->queue->get_jobs( $status, 100, 0 );

        $items = array();
        foreach ( $jobs as $job ) {
            $post = get_post( (int) $job['post_id'] );
            $tpl_name = '';
            if ( (int) $job['template_id'] ) {
                $tpl = CPIC_Template_Manager::get_template( (int) $job['template_id'] );
                $tpl_name = $tpl ? $tpl['name'] : '';
            }
            $items[] = array(
                'id'                 => (int) $job['id'],
                'post_id'            => (int) $job['post_id'],
                'post_title'         => $post ? get_the_title( $post->ID ) : __( '(missing)', 'custom-post-image-creator' ),
                'template_id'        => (int) $job['template_id'],
                'template_name'      => $tpl_name,
                'status'             => $job['status'],
                'attempts'           => (int) $job['attempts'],
                'max_retries'        => (int) $job['max_retries'],
                'facebook_post_id'   => $job['facebook_post_id'],
                'facebook_comment_id'=> $job['facebook_comment_id'],
                'telegram_message_id' => $job['telegram_message_id'],
                'next_attempt_at'    => (int) $job['next_attempt_at'],
                'image_url'          => $job['image_url'],
                'last_error'         => $job['last_error'],
                'created_at'         => $job['created_at'],
                'updated_at'         => $job['updated_at'],
            );
        }

        wp_send_json_success( array(
            'jobs'  => $items,
            'counts' => cpic()->queue->get_status_counts(),
        ) );
    }

    public function ajax_job_process() {
        cpic()->security->verify_ajax_request();

        $job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
        $job    = cpic()->queue->get_job( $job_id );

        if ( ! $job ) {
            wp_send_json_error( array( 'message' => __( 'Job not found.', 'custom-post-image-creator' ) ) );
        }

        if ( in_array( $job['status'], array( 'processing', 'publishing' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'This job is currently being processed. Try again after it finishes or becomes stale.', 'custom-post-image-creator' ) ), 409 );
        }

        cpic()->publisher->process_job( $job );
        $fresh = cpic()->queue->get_job( $job_id );
        if ( $fresh && 'failed' === $fresh['status'] ) {
            wp_send_json_error( array( 'message' => $fresh['last_error'] ?: __( 'Job failed.', 'custom-post-image-creator' ) ) );
        }
        wp_send_json_success( array( 'message' => $fresh ? sprintf( __( 'Job state: %s', 'custom-post-image-creator' ), $fresh['status'] ) : __( 'Job processed.', 'custom-post-image-creator' ) ) );
    }

    public function ajax_job_retry() {
        cpic()->security->verify_ajax_request();

        $job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
        $job    = cpic()->queue->get_job( $job_id );

        if ( ! $job ) {
            wp_send_json_error( array( 'message' => __( 'Job not found.', 'custom-post-image-creator' ) ) );
        }

        if ( 'failed' !== $job['status'] ) {
            wp_send_json_error( array( 'message' => __( 'Only a failed job can be retried; active and pending jobs are left unchanged.', 'custom-post-image-creator' ) ), 409 );
        }
        if ( ! cpic()->queue->retry_failed_job( $job_id ) ) {
            wp_send_json_error( array( 'message' => __( 'The job changed state before retry could be claimed. Refresh the Jobs page.', 'custom-post-image-creator' ) ), 409 );
        }
        cpic()->publisher->process_job( cpic()->queue->get_job( $job_id ) );

        wp_send_json_success( array( 'message' => __( 'Job retried.', 'custom-post-image-creator' ) ) );
    }

    public function ajax_job_delete() {
        cpic()->security->verify_ajax_request();

        $job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
        $job = cpic()->queue->get_job( $job_id );
        if ( ! $job ) {
            wp_send_json_error( array( 'message' => __( 'Job not found.', 'custom-post-image-creator' ) ), 404 );
        }
        if ( in_array( $job['status'], array( 'processing', 'publishing' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'An active job cannot be deleted.', 'custom-post-image-creator' ) ), 409 );
        }
        $ok = cpic()->queue->delete_job( $job_id );
        if ( ! $ok ) {
            wp_send_json_error( array( 'message' => __( 'The job changed state and could not be safely deleted.', 'custom-post-image-creator' ) ), 409 );
        }
        wp_send_json_success( array( 'message' => __( 'Job deleted.', 'custom-post-image-creator' ) ) );
    }

    /** Verify a post-scoped renderer request without requiring administrator-only access. */
    private function verify_render_request( $post_id ) {
        if ( ! check_ajax_referer( CPIC_Security::NONCE_ACTION, CPIC_Security::NONCE_FIELD, false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'custom-post-image-creator' ) ), 403 );
        }
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'custom-post-image-creator' ) ), 403 );
        }
    }

    /** Return the sanitized template and post data for the canonical browser renderer. */
    public function ajax_render_context() {
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $this->verify_render_request( $post_id );
        if ( ! get_post( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post or permission denied.', 'custom-post-image-creator' ) ), 403 );
        }
        $context = CPIC_Canvas_Artifacts::context_for_post( $post_id );
        if ( is_wp_error( $context ) ) { wp_send_json_error( array( 'message' => $context->get_error_message() ) ); }
        $existing = CPIC_Canvas_Artifacts::current_for_post( $post_id );
        if ( ! is_wp_error( $existing ) ) { $context['existing_artifact'] = $existing; }
        wp_send_json_success( $context );
    }

    /** Persist a bounded PNG uploaded by the authenticated browser renderer. */
    public function ajax_store_rendered_image() {
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $this->verify_render_request( $post_id );
        if ( ! get_post( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post or permission denied.', 'custom-post-image-creator' ) ), 403 );
        }
        if ( empty( $_FILES['image']['tmp_name'] ) || ! isset( $_FILES['image']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['image']['error'] ) {
            wp_send_json_error( array( 'message' => __( 'Browser image upload failed. Check the host upload-size limit and try again.', 'custom-post-image-creator' ) ) );
        }
        $tmp_file = $_FILES['image']['tmp_name'];
        if ( ! is_uploaded_file( $tmp_file ) ) { wp_send_json_error( array( 'message' => __( 'Uploaded PNG could not be verified.', 'custom-post-image-creator' ) ) ); }
        $result = CPIC_Canvas_Artifacts::save_file_for_post(
            $post_id,
            $tmp_file,
            isset( $_POST['template_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['template_hash'] ) ) : '',
            isset( $_POST['input_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['input_hash'] ) ) : ''
        );
        if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
        wp_send_json_success( $result );
    }

    /** Receive small sequential chunks so shared-host PHP request limits need not accept a full PNG. */
    public function ajax_store_rendered_chunk() {
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $this->verify_render_request( $post_id );
        if ( ! get_post( $post_id ) ) { wp_send_json_error( array( 'message' => __( 'Invalid post or permission denied.', 'custom-post-image-creator' ) ), 403 ); }
        if ( empty( $_FILES['chunk']['tmp_name'] ) || ! isset( $_FILES['chunk']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['chunk']['error'] || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => __( 'A PNG upload chunk failed. Check the host upload-size limit and retry.', 'custom-post-image-creator' ) ) );
        }

        $chunk_bytes = 256 * 1024;
        $transfer = isset( $_POST['transfer_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['transfer_id'] ) ) ) : '';
        $transfer = preg_match( '/^[a-f0-9]{32}$/', $transfer ) ? $transfer : '';
        $index = isset( $_POST['chunk_index'] ) ? (int) wp_unslash( $_POST['chunk_index'] ) : -1;
        $count = isset( $_POST['chunk_count'] ) ? absint( $_POST['chunk_count'] ) : 0;
        $total_size = isset( $_POST['total_size'] ) ? absint( $_POST['total_size'] ) : 0;
        if ( ! $transfer || $total_size < 24 || $total_size > 15 * MB_IN_BYTES || $count < 1 || $count > 60 || $count !== (int) ceil( $total_size / $chunk_bytes ) || $index < 0 || $index >= $count ) {
            wp_send_json_error( array( 'message' => __( 'The PNG upload session is invalid. Render the image again.', 'custom-post-image-creator' ) ) );
        }
        $tmp_file = $_FILES['chunk']['tmp_name'];
        $expected_size = min( $chunk_bytes, $total_size - ( $index * $chunk_bytes ) );
        if ( (int) filesize( $tmp_file ) !== $expected_size ) { wp_send_json_error( array( 'message' => __( 'An upload chunk was incomplete. Render the image again.', 'custom-post-image-creator' ) ) ); }

        $upload = wp_upload_dir();
        $staging = trailingslashit( $upload['basedir'] ) . 'cpic-generated/.staging/';
        if ( ! wp_mkdir_p( $staging ) || ! is_writable( $staging ) ) { wp_send_json_error( array( 'message' => __( 'The temporary upload directory is not writable.', 'custom-post-image-creator' ) ) ); }
        if ( ! file_exists( $staging . 'index.php' ) ) { file_put_contents( $staging . 'index.php', "<?php\nexit;\n", LOCK_EX ); }
        if ( ! file_exists( $staging . '.htaccess' ) ) { file_put_contents( $staging . '.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n", LOCK_EX ); }
        if ( ! file_exists( $staging . 'web.config' ) ) { file_put_contents( $staging . 'web.config', "<?xml version=\"1.0\"?><configuration><system.webServer><security><authorization><clear/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>", LOCK_EX ); }
        foreach ( (array) glob( $staging . 'cpic-u*-p*-*' ) as $stale ) { if ( is_file( $stale ) && filemtime( $stale ) < time() - DAY_IN_SECONDS ) { wp_delete_file( $stale ); } }

        $prefix = 'cpic-u' . get_current_user_id() . '-p' . $post_id . '-' . $transfer;
        $part = $staging . $prefix . '-' . sprintf( '%03d', $index ) . '.part';
        if ( is_file( $part ) ) {
            if ( (int) filesize( $part ) !== $expected_size || hash_file( 'sha256', $part ) !== hash_file( 'sha256', $tmp_file ) ) { wp_send_json_error( array( 'message' => __( 'Conflicting data was received for an upload chunk.', 'custom-post-image-creator' ) ) ); }
        } elseif ( ! move_uploaded_file( $tmp_file, $part ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not store an upload chunk. Check uploads-directory permissions.', 'custom-post-image-creator' ) ) );
        }

        if ( $index !== $count - 1 ) { wp_send_json_success( array( 'received' => $index + 1, 'total' => $count ) ); }
        $assembled = $staging . $prefix . '-assembled.png';
        $output = fopen( $assembled, 'wb' );
        if ( ! $output ) { wp_send_json_error( array( 'message' => __( 'Could not assemble the PNG upload.', 'custom-post-image-creator' ) ) ); }
        $assembled_size = 0;
        for ( $part_index = 0; $part_index < $count; $part_index++ ) {
            $part_path = $staging . $prefix . '-' . sprintf( '%03d', $part_index ) . '.part';
            if ( ! is_file( $part_path ) ) { fclose( $output ); wp_delete_file( $assembled ); wp_send_json_error( array( 'message' => __( 'The PNG upload is missing a chunk. Render the image again.', 'custom-post-image-creator' ) ) ); }
            $input = fopen( $part_path, 'rb' );
            if ( ! $input ) { fclose( $output ); wp_delete_file( $assembled ); wp_send_json_error( array( 'message' => __( 'Could not read an upload chunk.', 'custom-post-image-creator' ) ) ); }
            $assembled_size += stream_copy_to_stream( $input, $output );
            fclose( $input );
        }
        fclose( $output );
        if ( $assembled_size !== $total_size ) { wp_delete_file( $assembled ); wp_send_json_error( array( 'message' => __( 'The assembled PNG size did not match. Render the image again.', 'custom-post-image-creator' ) ) ); }
        $result = CPIC_Canvas_Artifacts::save_file_for_post(
            $post_id,
            $assembled,
            isset( $_POST['template_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['template_hash'] ) ) : '',
            isset( $_POST['input_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['input_hash'] ) ) : ''
        );
        wp_delete_file( $assembled );
        for ( $part_index = 0; $part_index < $count; $part_index++ ) { wp_delete_file( $staging . $prefix . '-' . sprintf( '%03d', $part_index ) . '.part' ); }
        if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
        wp_send_json_success( $result );
    }

    /** Build an unsaved preview; the same browser renderer used for the final PNG. */
    public function ajax_preview_post() {
        $this->ajax_render_context();
    }

    /** Generate a server-side ZIP from the authoritative renderer. */
    public function ajax_bulk_download() {
        cpic()->security->verify_ajax_request();
        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_send_json_error( array( 'message' => __( 'The PHP ZipArchive extension is required for bulk downloads.', 'custom-post-image-creator' ) ) );
        }
        $ids = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ? array_values( array_unique( array_map( 'absint', wp_unslash( $_POST['post_ids'] ) ) ) ) : array();
        $ids = array_values( array_filter( $ids ) );
        if ( count( $ids ) > 100 ) {
            wp_send_json_error( array( 'message' => __( 'Bulk ZIP is limited to 100 posts per request.', 'custom-post-image-creator' ) ) );
        }
        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => __( 'Select at least one post (up to 100 per ZIP).', 'custom-post-image-creator' ) ) );
        }
        $upload = wp_upload_dir();
        $dir = trailingslashit( $upload['basedir'] ) . 'cpic-generated/';
        if ( ! wp_mkdir_p( $dir ) || ! is_writable( $dir ) ) {
            wp_send_json_error( array( 'message' => __( 'The generated-image directory is not writable.', 'custom-post-image-creator' ) ) );
        }
        $old_archives = glob( $dir . 'cpic-bulk-*.zip' );
        foreach ( is_array( $old_archives ) ? $old_archives : array() as $old_zip ) {
            if ( is_file( $old_zip ) && filemtime( $old_zip ) < time() - DAY_IN_SECONDS ) { wp_delete_file( $old_zip ); }
        }
        $zip_name = 'cpic-bulk-' . wp_generate_uuid4() . '.zip';
        $zip_path = $dir . $zip_name;
        $zip = new ZipArchive();
        if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not create the ZIP archive.', 'custom-post-image-creator' ) ) );
        }
        $failures = array();
        foreach ( $ids as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status || ! current_user_can( 'edit_post', $post_id ) ) {
                $failures[] = array( 'post_id' => $post_id, 'message' => __( 'Post unavailable or permission denied.', 'custom-post-image-creator' ) );
                continue;
            }
            $image = CPIC_Canvas_Artifacts::current_for_post( $post_id );
            if ( is_wp_error( $image ) || empty( $image['path'] ) || ! is_file( $image['path'] ) ) {
                $failures[] = array( 'post_id' => $post_id, 'message' => is_wp_error( $image ) ? $image->get_error_message() : __( 'Canonical PNG unavailable.', 'custom-post-image-creator' ) );
                continue;
            }
            if ( ! $zip->addFile( $image['path'], 'cpic-' . $post_id . '.png' ) ) {
                $failures[] = array( 'post_id' => $post_id, 'message' => __( 'Could not add image to the ZIP archive.', 'custom-post-image-creator' ) );
            }
        }
        $closed = $zip->close();
        if ( ! $closed || ! is_file( $zip_path ) || ! filesize( $zip_path ) ) {
            wp_delete_file( $zip_path );
            wp_send_json_error( array( 'message' => __( 'ZIP creation failed.', 'custom-post-image-creator' ), 'failures' => $failures ) );
        }
        wp_send_json_success( array(
            'url' => trailingslashit( $upload['baseurl'] ) . 'cpic-generated/' . rawurlencode( $zip_name ),
            'generated' => count( $ids ) - count( $failures ),
            'failures' => $failures,
        ) );
    }

    /* ─── Sanitisation ────────────────────────────────────── */

    /**
     * Sanitise a template config array (used for AJAX save/preview).
     *
     * @param array $config Raw config.
     * @return array
     */
    private function sanitize_config( $config ) {
        $clean = array();

        if ( isset( $config['canvas'] ) && is_array( $config['canvas'] ) ) {
            $canvas             = $config['canvas'];
            $clean['canvas']    = array(
                'width'            => isset( $canvas['width'] ) ? absint( $canvas['width'] ) : 1080,
                'height'           => isset( $canvas['height'] ) ? absint( $canvas['height'] ) : 1080,
                'background_type'  => sanitize_key( $canvas['background_type'] ?? 'gradient' ),
                'background_image' => esc_url_raw( $canvas['background_image'] ?? '' ),
                'background_color' => sanitize_hex_color( $canvas['background_color'] ?? '' ) ?: '#1a1a2e',
                'gradient_from'    => sanitize_hex_color( $canvas['gradient_from'] ?? '' ) ?: '#667eea',
                'gradient_to'      => sanitize_hex_color( $canvas['gradient_to'] ?? '' ) ?: '#764ba2',
                'padding'          => isset( $canvas['padding'] ) ? absint( $canvas['padding'] ) : 40,
            );
        }

        if ( isset( $config['caption'] ) && is_array( $config['caption'] ) ) {
            $clean['caption'] = array(
                'include_headline'        => ! empty( $config['caption']['include_headline'] ) ? 1 : 0,
                'include_first_paragraph' => ! empty( $config['caption']['include_first_paragraph'] ) ? 1 : 0,
            );
        }

        if ( isset( $config['elements'] ) && is_array( $config['elements'] ) ) {
            $clean['elements'] = array();
            foreach ( $config['elements'] as $key => $el ) {
                if ( ! is_array( $el ) ) {
                    continue;
                }

                $clean_el = array(
                    'enabled' => ! empty( $el['enabled'] ) ? 1 : 0,
                    'type'    => 'image' === ( $el['type'] ?? '' ) ? 'image' : 'text',
                    'z_index' => isset( $el['z_index'] ) ? absint( $el['z_index'] ) : 0,
                );

                foreach ( array( 'font', 'align', 'format' ) as $text_field ) {
                    if ( isset( $el[ $text_field ] ) ) {
                        $clean_el[ $text_field ] = sanitize_text_field( $el[ $text_field ] );
                    }
                }

                foreach ( array( 'color', 'background_color', 'shadow_color' ) as $color_field ) {
                    if ( isset( $el[ $color_field ] ) ) {
                        $clean_el[ $color_field ] = sanitize_hex_color( $el[ $color_field ] ) ?: sanitize_text_field( $el[ $color_field ] );
                    }
                }

                foreach ( array(
                    'x', 'y', 'width', 'height', 'font_size', 'min_font_size', 'max_font_size',
                    'max_lines', 'max_width', 'max_height', 'padding_x', 'padding_y', 'border_radius',
                    'opacity', 'shadow_offset',
                ) as $int_field ) {
                    if ( isset( $el[ $int_field ] ) ) {
                        $clean_el[ $int_field ] = absint( $el[ $int_field ] );
                    }
                }

                if ( isset( $el['line_height'] ) ) {
                    $clean_el['line_height'] = floatval( $el['line_height'] );
                }
                if ( isset( $el['letter_spacing'] ) ) {
                    $clean_el['letter_spacing'] = floatval( $el['letter_spacing'] );
                }
                if ( isset( $el['fit'] ) && in_array( $el['fit'], array( 'cover', 'contain', 'fill' ), true ) ) {
                    $clean_el['fit'] = $el['fit'];
                }
                if ( isset( $el['fallback'] ) && in_array( $el['fallback'], array( 'hide', 'template_image' ), true ) ) {
                    $clean_el['fallback'] = $el['fallback'];
                }
                if ( isset( $el['source'] ) ) {
                    $clean_el['source'] = esc_url_raw( $el['source'] );
                }
                if ( isset( $el['fallback_image'] ) ) {
                    $clean_el['fallback_image'] = esc_url_raw( $el['fallback_image'] );
                }
                if ( isset( $el['bold'] ) ) {
                    $clean_el['bold'] = ! empty( $el['bold'] ) ? 1 : 0;
                }
                if ( isset( $el['auto_scale'] ) ) {
                    $clean_el['auto_scale'] = ! empty( $el['auto_scale'] ) ? 1 : 0;
                }
                if ( isset( $el['text'] ) ) {
                    $clean_el['text'] = sanitize_text_field( $el['text'] );
                }

                $clean['elements'][ $key ] = $clean_el;
            }
        }

        if ( isset( $config['shapes'] ) && is_array( $config['shapes'] ) ) {
            $clean['shapes'] = array();
            foreach ( $config['shapes'] as $shape ) {
                if ( ! is_array( $shape ) ) {
                    continue;
                }
                $clean_shape = array(
                    'type'   => sanitize_key( $shape['type'] ?? 'rect' ),
                    'color'  => sanitize_hex_color( $shape['color'] ?? '' ) ?: '#000000',
                    'opacity' => isset( $shape['opacity'] ) ? absint( $shape['opacity'] ) : 100,
                    'z_index' => isset( $shape['z_index'] ) ? absint( $shape['z_index'] ) : 0,
                );
                foreach ( array( 'x', 'y', 'width', 'height', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'rx', 'ry' ) as $n_field ) {
                    if ( isset( $shape[ $n_field ] ) ) {
                        $clean_shape[ $n_field ] = absint( $shape[ $n_field ] );
                    }
                }
                if ( isset( $shape['width'] ) && 'line' === $clean_shape['type'] ) {
                    $clean_shape['width'] = absint( $shape['width'] );
                }
                $clean['shapes'][] = $clean_shape;
            }
        }

        return $clean;
    }

    /**
     * Build a ~50-word summary for a post (Bangla-safe).
     *
     * @param int $post_id Post ID.
     * @return string
     */
    public function get_post_summary( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return '';
        }

        // Priority 1: excerpt.
        $text = trim( $post->post_excerpt );

        // Priority 2: content.
        if ( empty( $text ) ) {
            $text = $post->post_content;
            $text = strip_shortcodes( $text );
            $text = wp_strip_all_tags( $text );
            $text = trim( $text );
        }

        // Trim to 50 words – wp_trim_words is multibyte-safe.
        $summary = wp_trim_words( $text, 50, '' );

        // Ensure at least 20 words; if less, return as-is.
        return sanitize_textarea_field( trim( $summary ) );
    }
}
