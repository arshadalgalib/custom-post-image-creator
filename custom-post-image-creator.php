<?php
/**
 * Plugin Name: Custom Post Image Creator
 * Plugin URI:  https://developer.wordpress.org/plugins/
 * Description: Template-driven social photocards with Unicode Bengali Canvas rendering, category mappings, Facebook and Telegram publishing, and a persistent queue that reuses browser-captured PNGs.
 * Version:     6.5.2
 * Author:      CPIC Dev Team
 * Author URI:  https://developer.wordpress.org/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: custom-post-image-creator
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ─── Constants ─────────────────────────────────────────────── */
define( 'CPIC_VERSION', '6.5.2' );
define( 'CPIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPIC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CPIC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/* ─── Autoload classes ──────────────────────────────────────── */
require_once CPIC_PLUGIN_DIR . 'includes/class-security.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-settings.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-bangla-date.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-logger.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-installer.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-template-manager.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-category-mapper.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-job-queue.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-renderer.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-canvas-artifacts.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-image-generator.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-facebook-api.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-facebook-oauth.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-telegram-api.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-publisher.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-automation.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-auto-publisher.php';
require_once CPIC_PLUGIN_DIR . 'includes/class-admin-ui.php';

/* ─── Main bootstrap class ──────────────────────────────────── */
final class Custom_Post_Image_Creator {

    /** @var self|null */
    private static $instance = null;

    /** Class singletons */
    public $security;
    public $settings;
    public $admin_ui;
    public $image_generator;
    public $facebook_api;
    public $facebook_oauth;
    public $telegram_api;
    public $auto_publisher;
    public $logger;
    public $renderer;
    public $template_manager;
    public $category_mapper;
    public $queue;
    public $publisher;
    public $automation;

    /**
     * Singleton accessor.
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->security        = new CPIC_Security();
        $this->settings        = new CPIC_Settings();
        $this->logger          = new CPIC_Logger();
        $this->renderer        = new CPIC_Renderer();
        $this->template_manager = new CPIC_Template_Manager();
        $this->category_mapper = new CPIC_Category_Mapper();
        $this->queue           = new CPIC_Queue();
        $this->image_generator = new CPIC_Image_Generator();
        $this->facebook_api    = new CPIC_Facebook_API();
        $this->facebook_oauth  = new CPIC_Facebook_OAuth();
        $this->telegram_api    = new CPIC_Telegram_API();
        $this->publisher       = new CPIC_Publisher();
        $this->automation      = new CPIC_Automation();
        $this->auto_publisher  = new CPIC_Auto_Publisher();
        $this->admin_ui        = new CPIC_Admin_UI();

        add_action( 'init', array( $this, 'load_textdomain' ) );

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        // Upgrade/migration check.
        add_action( 'admin_init', array( 'CPIC_Installer', 'maybe_upgrade' ) );
    }

    /**
     * Load plugin text domain.
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'custom-post-image-creator', false, dirname( CPIC_PLUGIN_BASENAME ) . '/languages' );
    }

    /**
     * Activation tasks.
     */
    public function activate() {
        CPIC_Installer::run();
    }

    /**
     * Deactivation tasks.
     */
    public function deactivate() {
        wp_clear_scheduled_hook( 'cpic_cron_process_queue' );
        wp_clear_scheduled_hook( 'cpic_cron_process_jobs' );
    }

    private function __clone() {}
    public function __wakeup() {
        throw new \Exception( 'Cannot unserialize singleton' );
    }
}

/**
 * Returns the main plugin instance.
 */
function cpic() {
    return Custom_Post_Image_Creator::get_instance();
}

// Boot.
cpic();
