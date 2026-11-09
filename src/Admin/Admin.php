<?php
/**
 * Admin screens.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Admin;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the menu, enqueues the studio bundle and renders the screens.
 *
 * The PHP side only renders shells; all interaction happens through the REST
 * API, so a screen never needs a full page reload and the same endpoints serve
 * the block editor.
 */
final class Admin {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Menu slug.
	 */
	const MENU = 'cpic-dashboard';

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Attach admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_head', array( $this, 'print_font_faces' ) );
		add_filter( 'plugin_action_links_' . CPIC_PLUGIN_BASENAME, array( $this, 'action_links' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_post_cpic_library_action', array( $this, 'handle_library_action' ) );
		add_action( 'admin_post_cpic_publish_now', array( $this, 'handle_publish_now' ) );
		add_action( 'admin_post_cpic_save_brand', array( $this, 'handle_save_brand' ) );
		add_action( 'admin_post_cpic_brand_default', array( $this, 'handle_brand_default' ) );
		add_action( 'admin_post_cpic_save_account', array( $this, 'handle_save_account' ) );
		add_action( 'admin_post_cpic_account_status', array( $this, 'handle_account_status' ) );
		add_action( 'admin_post_cpic_save_campaign', array( $this, 'handle_save_campaign' ) );
		add_action( 'admin_post_cpic_campaign_status', array( $this, 'handle_campaign_status' ) );
		add_action( 'admin_post_cpic_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_cpic_repair', array( $this, 'handle_repair' ) );
		add_action( 'wp_ajax_cpic_run_queue', array( $this, 'ajax_run_queue' ) );

		// Post list column + row action.
		add_filter( 'manage_posts_columns', array( $this, 'post_column' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'render_post_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'post_row_actions' ), 10, 2 );
	}

	/**
	 * Register the menu.
	 *
	 * @return void
	 */
	public function menu() {
		$cap = $this->container->get( 'capabilities' )->can( 'cpic_access' ) ? 'cpic_access' : 'manage_options';

		add_menu_page(
			__( 'Post Images', 'custom-post-image-creator' ),
			__( 'Post Images', 'custom-post-image-creator' ),
			$cap,
			self::MENU,
			array( $this, 'render_dashboard' ),
			'dashicons-format-image',
			26
		);

		$pages = array(
			'cpic-studio'       => array( __( 'Design Studio', 'custom-post-image-creator' ), 'render_studio', 'cpic_manage_templates' ),
			'cpic-templates'    => array( __( 'Templates', 'custom-post-image-creator' ), 'render_templates', 'cpic_manage_templates' ),
			'cpic-brands'       => array( __( 'Brand Kits', 'custom-post-image-creator' ), 'render_brands', 'cpic_manage_brands' ),
			'cpic-accounts'     => array( __( 'Social Accounts', 'custom-post-image-creator' ), 'render_accounts', 'cpic_manage_accounts' ),
			'cpic-campaigns'    => array( __( 'Campaigns', 'custom-post-image-creator' ), 'render_campaigns', 'cpic_manage_campaigns' ),
			'cpic-calendar'     => array( __( 'Calendar', 'custom-post-image-creator' ), 'render_calendar', 'cpic_schedule' ),
			'cpic-queue'        => array( __( 'Queue', 'custom-post-image-creator' ), 'render_queue', 'cpic_run_queue' ),
			'cpic-analytics'    => array( __( 'Analytics', 'custom-post-image-creator' ), 'render_analytics', 'cpic_view_analytics' ),
			'cpic-settings'     => array( __( 'Settings', 'custom-post-image-creator' ), 'render_settings', 'cpic_manage_settings' ),
			'cpic-diagnostics'  => array( __( 'Diagnostics', 'custom-post-image-creator' ), 'render_diagnostics', 'cpic_manage_system' ),
		);

		foreach ( $pages as $slug => $page ) {
			// Register with the plugin capability; WordPress resolves it through
			// the role map installed by Capabilities::install_roles(). Pages the
			// current user cannot reach are hidden rather than shown and failing.
			$capability = $page[2];

			add_submenu_page(
				self::MENU,
				$page[0],
				$page[0],
				$capability,
				$slug,
				array( $this, $page[1] )
			);
		}

		// The first submenu duplicates the parent label; rename it.
		global $submenu;
		if ( isset( $submenu[ self::MENU ][0][0] ) ) {
			$submenu[ self::MENU ][0][0] = __( 'Dashboard', 'custom-post-image-creator' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}

	/**
	 * Enqueue the admin bundle on plugin screens, the editor and the media modal.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		$is_plugin_screen = false !== strpos( (string) $hook, 'cpic-' );
		$is_editor        = $screen && in_array( (string) $screen->base, array( 'post', 'post-new' ), true );

		if ( ! $is_plugin_screen && ! $is_editor && 'post.php' !== $hook ) {
			return;
		}

		$version = CPIC_VERSION;

		wp_enqueue_style( 'cpic-admin', CPIC_PLUGIN_URL . 'resources/css/admin.css', array(), $version );
		wp_enqueue_style( 'cpic-studio', CPIC_PLUGIN_URL . 'resources/css/studio.css', array( 'cpic-admin' ), $version );

		wp_enqueue_media();

		wp_enqueue_script(
			'cpic-studio',
			CPIC_PLUGIN_URL . 'resources/js/studio.js',
			array( 'wp-api-fetch', 'wp-i18n', 'media-editor' ),
			$version,
			true
		);

		if ( $is_editor ) {
			wp_enqueue_script(
				'cpic-editor',
				CPIC_PLUGIN_URL . 'resources/js/editor.js',
				array( 'cpic-studio', 'wp-data', 'wp-edit-post' ),
				$version,
				true
			);
		}

		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_localize_script(
			'cpic-studio',
			'CPIC',
			array(
				'version'     => $version,
				'restPath'    => '/' . CPIC_REST_NAMESPACE . '/',
				'restRoot'    => esc_url_raw( rest_url() ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'adminUrl'    => admin_url(),
				'postId'      => $post_id,
				'userId'      => get_current_user_id(),
				'can'         => array(
					'publish'  => $this->container->get( 'capabilities' )->can( 'cpic_publish' ),
					'brands'   => $this->container->get( 'capabilities' )->can( 'cpic_manage_brands' ),
					'accounts' => $this->container->get( 'capabilities' )->can( 'cpic_manage_accounts' ),
					'ai'       => $this->container->get( 'capabilities' )->can( 'cpic_use_ai' ),
					'system'   => $this->container->get( 'capabilities' )->can( 'cpic_manage_system' ),
				),
				'formats'     => \CPIC\Rendering\Formats::all(),
				'fontUrl'     => CPIC_PLUGIN_URL . 'fonts/',
				'previewSize' => 520,
				'strings'     => $this->strings(),
			)
		);
	}

	/**
	 * UI strings (translatable, one place).
	 *
	 * @return array<string,string>
	 */
	private function strings() {
		return array(
			'saving'        => __( 'Saving…', 'custom-post-image-creator' ),
			'saved'         => __( 'Saved', 'custom-post-image-creator' ),
			'saveFailed'    => __( 'Could not save', 'custom-post-image-creator' ),
			'rendering'     => __( 'Rendering…', 'custom-post-image-creator' ),
			'renderFailed'  => __( 'Rendering failed', 'custom-post-image-creator' ),
			'publishing'    => __( 'Publishing…', 'custom-post-image-creator' ),
			'published'     => __( 'Published', 'custom-post-image-creator' ),
			'confirmDelete' => __( 'Delete this design? This cannot be undone.', 'custom-post-image-creator' ),
			'confirmRetry'  => __( 'Retry this delivery?', 'custom-post-image-creator' ),
			'noSelection'   => __( 'Select an element on the canvas to edit it.', 'custom-post-image-creator' ),
			'browserRender' => __( 'Bengali text is shaped by your browser. Keep this tab open while saving.', 'custom-post-image-creator' ),
			'offline'       => __( 'You appear to be offline. Changes are kept locally until you reconnect.', 'custom-post-image-creator' ),
		);
	}

	/**
	 * Print @font-face rules so the studio canvas matches the render exactly.
	 *
	 * @return void
	 */
	public function print_font_faces() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ( false === strpos( (string) $screen->id, 'cpic-' ) && ! in_array( (string) $screen->base, array( 'post', 'post-new' ), true ) ) ) {
			return;
		}

		// Escaped inside FontManager.
		echo '<style id="cpic-fonts">' . $this->container->get( 'fonts' )->css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Plugin row links.
	 *
	 * @param array<int,string> $links Links.
	 * @return array<int,string>
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=cpic-studio' ) ), esc_html__( 'Design Studio', 'custom-post-image-creator' ) )
		);

		return $links;
	}

	/**
	 * Admin notices for real problems only.
	 *
	 * @return void
	 */
	public function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';

		// The failure notice follows the user: plugin screens, and the Plugins
		// list where they land right after an activation attempt fails.
		$is_plugin_screen = ( false !== strpos( $id, 'cpic-' ) );
		$is_plugins_list  = ( 'plugins' === $id || 'plugins-network' === $id );

		if ( ! $is_plugin_screen && ! $is_plugins_list ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->activation_notice();

		if ( ! $is_plugin_screen ) {
			return;
		}

		$report = $this->container->get( 'health' )->summary();

		if ( ! empty( $report['problems'] ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Post Image Creator needs attention:', 'custom-post-image-creator' ) . '</strong></p><ul class="cpic-notice-list">';
			foreach ( $report['problems'] as $problem ) {
				echo '<li>' . esc_html( $problem ) . '</li>';
			}
			echo '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=cpic-diagnostics' ) ) . '">' . esc_html__( 'Open diagnostics', 'custom-post-image-creator' ) . '</a></p></div>';
		}
	}

	/**
	 * Add an "Image" column to the post list.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function post_column( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['cpic_image'] = __( 'Social image', 'custom-post-image-creator' );
			}
		}

		return $new;
	}

	/**
	 * Render the post list column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post id.
	 * @return void
	 */
	public function render_post_column( $column, $post_id ) {
		if ( 'cpic_image' !== $column ) {
			return;
		}

		$artifact = $this->container->get( 'artifact_manager' )->latest( (int) $post_id );

		if ( $artifact && ! empty( $artifact['exists'] ) ) {
			printf(
				'<img src="%s" alt="" width="60" height="60" style="object-fit:cover;border-radius:4px" />',
				esc_url( (string) $artifact['file_url'] )
			);
			return;
		}

		echo '<span class="cpic-muted">' . esc_html__( 'None yet', 'custom-post-image-creator' ) . '</span>';
	}

	/**
	 * "Create image" row action.
	 *
	 * @param array<string,string> $actions Actions.
	 * @param \WP_Post             $post    Post.
	 * @return array<string,string>
	 */
	public function post_row_actions( $actions, $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$actions['cpic_studio'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=cpic-studio&post=' . (int) $post->ID ) ),
			esc_html__( 'Design image', 'custom-post-image-creator' )
		);

		return $actions;
	}

	/**
	 * Surface a failed activation step.
	 *
	 * Activation no longer fatals (see `Plugin::on_activate()`), so without
	 * this the site owner would see a plugin that half-installed with no
	 * explanation. The notice names the step, the error and the PHP version so
	 * a support request carries the facts.
	 *
	 * @return void
	 */
	private function activation_notice() {
		$report = get_option( 'cpic_activation_errors' );

		// Stored activation failures (a step or module failed earlier) ...
		$errors = is_array( $report ) && ! empty( $report['errors'] ) ? (array) $report['errors'] : array();

		// ... plus anything that failed while loading on this very request, so a
		// module that breaks only on some hosts is still visible.
		$plugin = \CPIC\Core\Plugin::loaded();

		if ( $plugin && method_exists( $plugin, 'load_failures' ) ) {
			foreach ( $plugin->load_failures() as $id => $failure ) {
				if ( ! isset( $errors[ $id ] ) ) {
					$errors[ $id ] = $failure;
				}
			}
		}

		if ( empty( $errors ) ) {
			return;
		}

		$report = array_merge(
			is_array( $report ) ? $report : array(),
			array( 'errors' => $errors )
		);

		echo '<div class="notice notice-error"><p><strong>';
		esc_html_e( 'Post Image Creator could not finish setting up.', 'custom-post-image-creator' );
		echo '</strong></p><ul class="cpic-notice-list">';

		foreach ( (array) $report['errors'] as $step => $message ) {
			// Module failures arrive as arrays; activation steps as strings.
			if ( is_array( $message ) ) {
				$message = sprintf(
					/* translators: 1: phase, 2: message, 3: file:line */
					__( '%1$s failed during %2$s (%3$s)', 'custom-post-image-creator' ),
					(string) $step,
					isset( $message['phase'] ) ? (string) $message['phase'] : 'boot',
					( isset( $message['message'] ) ? (string) $message['message'] : '' ) . ' ' . ( isset( $message['file'] ) ? (string) $message['file'] : '' )
				);
				$step = 'module';
			}

			echo '<li><code>' . esc_html( (string) $step ) . '</code>: ' . esc_html( (string) $message ) . '</li>';
		}

		echo '</ul><p>';
		printf(
			/* translators: %s: PHP version */
			esc_html__( 'PHP %s. Fix the cause above, then deactivate and reactivate the plugin.', 'custom-post-image-creator' ),
			esc_html( isset( $report['php'] ) ? (string) $report['php'] : PHP_VERSION )
		);
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=cpic-diagnostics' ) ) . '">' . esc_html__( 'Open diagnostics', 'custom-post-image-creator' ) . '</a></p></div>';
	}

	/**
	 * Render a view with a consistent header.
	 *
	 * @param string              $view View file (without extension).
	 * @param array<string,mixed> $args View arguments.
	 * @return void
	 */
	private function view( $view, array $args = array() ) {
		$file = CPIC_PLUGIN_DIR . 'templates/admin/' . $view . '.php';

		if ( ! file_exists( $file ) ) {
			printf(
				'<div class="wrap"><p>%s</p></div>',
				esc_html__( 'This screen is unavailable because a plugin file is missing.', 'custom-post-image-creator' )
			);
			return;
		}

		$container = $this->container; // Available to views.

		require $file;
	}

	/**
	 * Dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$this->view( 'dashboard' );
	}

	/**
	 * Design studio.
	 *
	 * @return void
	 */
	public function render_studio() {
		$this->view( 'studio' );
	}

	/**
	 * Template library.
	 *
	 * @return void
	 */
	public function render_templates() {
		$this->view( 'templates' );
	}

	/**
	 * Brand kits.
	 *
	 * @return void
	 */
	public function render_brands() {
		$this->view( 'brands' );
	}

	/**
	 * Social accounts.
	 *
	 * @return void
	 */
	public function render_accounts() {
		$this->view( 'accounts' );
	}

	/**
	 * Campaigns.
	 *
	 * @return void
	 */
	public function render_campaigns() {
		$this->view( 'campaigns' );
	}

	/**
	 * Calendar.
	 *
	 * @return void
	 */
	public function render_calendar() {
		$this->view( 'calendar' );
	}

	/**
	 * Queue.
	 *
	 * @return void
	 */
	public function render_queue() {
		$this->view( 'queue' );
	}

	/**
	 * Analytics.
	 *
	 * @return void
	 */
	public function render_analytics() {
		$this->view( 'analytics' );
	}

	/**
	 * Settings.
	 *
	 * @return void
	 */
	public function render_settings() {
		$this->view( 'settings' );
	}

	/**
	 * Diagnostics.
	 *
	 * @return void
	 */
	public function render_diagnostics() {
		$this->view( 'diagnostics' );
	}

	/**
	 * Library row actions from the templates screen (nonce-protected).
	 *
	 * The list screens are plain links, so every state change goes through
	 * admin-post.php with an explicit nonce and a capability check.
	 *
	 * @return void
	 */
	public function handle_library_action() {
		$action = isset( $_GET['cpic_action'] ) ? sanitize_key( wp_unslash( (string) $_GET['cpic_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = isset( $_GET['template'] ) ? (int) $_GET['template'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $action || $id <= 0 ) {
			wp_die( esc_html__( 'Nothing to do.', 'custom-post-image-creator' ), 400 );
		}

		check_admin_referer( 'cpic_template_' . $action . '_' . $id );

		if ( ! $this->container->get( 'capabilities' )->can( 'cpic_manage_templates' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage templates.', 'custom-post-image-creator' ), 403 );
		}

		$manager = $this->container->get( 'template_manager' );
		$notice  = 'saved';

		switch ( $action ) {
			case 'default':
				$result = $manager->set_default( $id );
				break;
			case 'duplicate':
				$result = $manager->duplicate( $id );
				$notice = 'duplicated';
				break;
			case 'archive':
				$result = $manager->archive( $id );
				break;
			case 'restore':
				$result = $manager->restore( $id );
				break;
			case 'delete':
				$result = $manager->delete( $id, true );
				$notice = 'deleted';
				break;
			default:
				$result = new \WP_Error( 'cpic_template_action', __( 'Unknown action.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		if ( is_wp_error( $result ) ) {
			$notice = 'error';
			set_transient( 'cpic_admin_error_' . get_current_user_id(), $result->get_error_message(), 60 );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'cpic-templates',
					'cpic_notice'  => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Publish a post's image right now (admin-post handler used by the queue
	 * and post screens).
	 *
	 * @return void
	 */
	public function handle_publish_now() {
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$format  = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( (string) $_POST['format'] ) ) : 'square';
		$targets = isset( $_POST['destinations'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['destinations'] ) ) : array();

		if ( $post_id <= 0 ) {
			wp_die( esc_html__( 'A post is required.', 'custom-post-image-creator' ), 400 );
		}

		check_admin_referer( 'cpic_publish_' . $post_id );

		if ( ! $this->container->get( 'capabilities' )->can( 'cpic_publish' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to publish this post.', 'custom-post-image-creator' ), 403 );
		}

		$result = $this->container->get( 'delivery_manager' )->publish_post(
			$post_id,
			array(
				'format'          => $format,
				'destination_ids' => $targets,
			)
		);

		if ( is_wp_error( $result ) ) {
			set_transient( 'cpic_admin_error_' . get_current_user_id(), $result->get_error_message(), 60 );
			$status = 'failed';
		} else {
			$status = 'published';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'cpic-queue',
					'cpic_notice' => $status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Process the queue from the dashboard button (AJAX, nonce + capability).
	 *
	 * @return void
	 */
	public function ajax_run_queue() {
		check_ajax_referer( 'cpic_admin', 'nonce' );

		if ( ! $this->container->get( 'capabilities' )->can( 'cpic_run_queue' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to run the queue.', 'custom-post-image-creator' ) ), 403 );
		}

		$result = $this->container->get( 'queue' )->run( 'manual' );

		wp_send_json_success(
			array(
				'processed' => (int) ( isset( $result['processed'] ) ? $result['processed'] : 0 ),
				'failed'    => (int) ( isset( $result['failed'] ) ? $result['failed'] : 0 ),
				'stats'     => $this->container->get( 'queue' )->stats(),
			)
		);
	}

	/**
	 * Redirect back to a plugin screen with a notice code.
	 *
	 * @param string $page    Menu slug.
	 * @param string $notice  Notice code.
	 * @param string $message Optional message (stored in a short transient).
	 * @return void
	 */
	private function back_to( $page, $notice, $message = '' ) {
		if ( '' !== $message ) {
			set_transient( 'cpic_admin_error_' . get_current_user_id(), $message, 120 );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'cpic_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Require a capability or stop with a 403.
	 *
	 * @param string $capability Capability.
	 * @return void
	 */
	private function require_cap( $capability ) {
		if ( ! $this->container->get( 'capabilities' )->can( $capability ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'custom-post-image-creator' ), 403 );
		}
	}

	/**
	 * Save (create or update) a brand kit.
	 *
	 * @return void
	 */
	public function handle_save_brand() {
		$id = isset( $_POST['brand_id'] ) ? (int) $_POST['brand_id'] : 0;

		check_admin_referer( 0 < $id ? 'cpic_brand_' . $id : 'cpic_brand_new' );
		$this->require_cap( 'cpic_manage_brands' );

		$fields = array( 'name', 'footer_text', 'website_url', 'heading_font', 'body_font', 'primary_color', 'secondary_color', 'accent_color', 'logo_primary', 'logo_secondary', 'logo_light', 'logo_dark' );
		$data   = array();

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = wp_unslash( (string) $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}

		$repo = $this->container->get( 'brands' );

		if ( $id > 0 ) {
			if ( ! $repo->find( $id ) ) {
				$this->back_to( 'cpic-brands', 'error', __( 'That brand kit no longer exists.', 'custom-post-image-creator' ) );
			}
			$repo->update( $id, $data );
		} else {
			$created = $repo->create( $data );
			if ( ! $created ) {
				$this->back_to( 'cpic-brands', 'error', __( 'The brand kit could not be created. Check that the name is unique.', 'custom-post-image-creator' ) );
			}
		}

		$this->back_to( 'cpic-brands', 'saved' );
	}

	/**
	 * Mark a brand kit as the default.
	 *
	 * @return void
	 */
	public function handle_brand_default() {
		$id = isset( $_GET['brand'] ) ? (int) $_GET['brand'] : 0;

		check_admin_referer( 'cpic_brand_default_' . $id );
		$this->require_cap( 'cpic_manage_brands' );

		$repo = $this->container->get( 'brands' );

		if ( ! $repo->find( $id ) ) {
			$this->back_to( 'cpic-brands', 'error', __( 'That brand kit no longer exists.', 'custom-post-image-creator' ) );
		}

		$repo->clear_default();
		$repo->update( $id, array( 'is_default' => 1 ) );

		$this->back_to( 'cpic-brands', 'saved' );
	}

	/**
	 * Connect or update a social account.
	 *
	 * Credentials arrive as write-only fields: an empty value keeps the stored
	 * secret, and values are never echoed back to the form.
	 *
	 * @return void
	 */
	public function handle_save_account() {
		$id = isset( $_POST['account'] ) ? (int) $_POST['account'] : 0;

		check_admin_referer( 'cpic_account_' . $id );
		$this->require_cap( 'cpic_manage_accounts' );

		$provider    = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( (string) $_POST['provider'] ) ) : '';
		$credentials = array();

		foreach ( array( 'page_token', 'bot_token', 'app_secret' ) as $key ) {
			$value = isset( $_POST[ $key ] ) ? trim( wp_unslash( (string) $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( '' !== $value ) {
				$credentials[ $key ] = $value;
			}
		}

		$data = array(
			'provider'    => $provider,
			'label'       => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '',
			'account_id'  => isset( $_POST['account_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['account_id'] ) ) : '',
			'account_name' => isset( $_POST['account_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['account_name'] ) ) : '',
		);

		if ( ! empty( $credentials ) ) {
			$data['credentials'] = $credentials;
		}

		if ( isset( $_POST['chat_id'] ) ) {
			$data['config'] = array( 'chat_id' => sanitize_text_field( wp_unslash( (string) $_POST['chat_id'] ) ) );
		}

		$accounts = $this->container->get( 'accounts' );
		$result   = $id > 0 ? $accounts->update( $id, $data ) : $accounts->create( $data );

		if ( is_wp_error( $result ) ) {
			$this->back_to( 'cpic-accounts', 'error', $result->get_error_message() );
		}

		$this->back_to( 'cpic-accounts', 'saved' );
	}

	/**
	 * Verify, disable or delete a connected account.
	 *
	 * @return void
	 */
	public function handle_account_status() {
		$id     = isset( $_GET['account'] ) ? (int) $_GET['account'] : 0;
		$action = isset( $_GET['cpic_action'] ) ? sanitize_key( wp_unslash( (string) $_GET['cpic_action'] ) ) : '';

		check_admin_referer( 'cpic_account_status_' . $action . '_' . $id );
		$this->require_cap( 'cpic_manage_accounts' );

		$accounts = $this->container->get( 'accounts' );

		switch ( $action ) {
			case 'disable':
				$accounts->set_status( $id, 'disconnected' );
				break;
			case 'enable':
				$accounts->set_status( $id, 'connected' );
				break;
			case 'delete':
				$accounts->delete( $id );
				break;
			default:
				$this->back_to( 'cpic-accounts', 'error', __( 'Unknown action.', 'custom-post-image-creator' ) );
		}

		$this->back_to( 'cpic-accounts', 'saved' );
	}

	/**
	 * Create or update a campaign.
	 *
	 * @return void
	 */
	public function handle_save_campaign() {
		$id = isset( $_POST['campaign'] ) ? (int) $_POST['campaign'] : 0;

		check_admin_referer( 'cpic_campaign_' . $id );
		$this->require_cap( 'cpic_manage_campaigns' );

		$data = array(
			'name'        => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '',
			'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['description'] ) ) : '',
			'start_at'    => isset( $_POST['start_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['start_at'] ) ) : '',
			'end_at'      => isset( $_POST['end_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['end_at'] ) ) : '',
			'template_id' => isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0,
		);

		$campaigns = $this->container->get( 'campaign_manager' );
		$result    = $id > 0 ? $campaigns->update( $id, $data ) : $campaigns->create( $data );

		if ( is_wp_error( $result ) ) {
			$this->back_to( 'cpic-campaigns', 'error', $result->get_error_message() );
		}

		$this->back_to( 'cpic-campaigns', 'saved' );
	}

	/**
	 * Pause, resume, complete or delete a campaign.
	 *
	 * @return void
	 */
	public function handle_campaign_status() {
		$id     = isset( $_GET['campaign'] ) ? (int) $_GET['campaign'] : 0;
		$action = isset( $_GET['cpic_action'] ) ? sanitize_key( wp_unslash( (string) $_GET['cpic_action'] ) ) : '';

		check_admin_referer( 'cpic_campaign_status_' . $action . '_' . $id );
		$this->require_cap( 'cpic_manage_campaigns' );

		$campaigns = $this->container->get( 'campaign_manager' );
		$result    = true;

		switch ( $action ) {
			case 'pause':
				$result = $campaigns->pause( $id );
				break;
			case 'resume':
				$result = $campaigns->resume( $id );
				break;
			case 'complete':
				$result = $campaigns->set_status( $id, 'completed' );
				break;
			case 'archive':
				$result = $campaigns->set_status( $id, 'archived' );
				break;
			case 'delete':
				$result = $campaigns->delete( $id );
				break;
			default:
				$result = new \WP_Error( 'cpic_campaign_action', __( 'Unknown action.', 'custom-post-image-creator' ) );
		}

		if ( is_wp_error( $result ) ) {
			$this->back_to( 'cpic-campaigns', 'error', $result->get_error_message() );
		}

		$this->back_to( 'cpic-campaigns', 'saved' );
	}

	/**
	 * Save settings from the settings screen (schema-driven, secret-safe).
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		check_admin_referer( 'cpic_save_settings' );
		$this->require_cap( 'cpic_manage_settings' );

		$settings = $this->container->get( 'settings' );
		$posted   = isset( $_POST['cpic'] ) && is_array( $_POST['cpic'] ) ? wp_unslash( $_POST['cpic'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$values   = array();
		$defs     = $settings->definitions();

		// Checkboxes: an unchecked box is absent from the POST body, so every
		// boolean in the submitted section is written explicitly.
		$section = isset( $_POST['cpic_section'] ) ? sanitize_key( wp_unslash( (string) $_POST['cpic_section'] ) ) : '';
		foreach ( $defs as $key => $def ) {
			if ( '' !== $section && ( ! isset( $def['section'] ) || $section !== $def['section'] ) ) {
				continue;
			}
			if ( 'bool' === $def['type'] ) {
				$values[ $key ] = ! empty( $posted[ $key ] ) ? 1 : 0;
			}
		}

		foreach ( (array) $posted as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( isset( $defs[ $key ] ) && 'bool' !== $defs[ $key ]['type'] ) {
				$values[ $key ] = $value;
			}
		}

		$result = $settings->bulk_set( $values );

		$this->back_to( 'cpic-settings', empty( $result['errors'] ) ? 'saved' : 'error', empty( $result['errors'] ) ? '' : implode( ' ', $result['errors'] ) );
	}

	/**
	 * Run the safe diagnostics repairs (schema, stale locks, orphaned files).
	 *
	 * @return void
	 */
	public function handle_repair() {
		check_admin_referer( 'cpic_repair' );
		$this->require_cap( 'cpic_manage_system' );

		$steps = array();

		$steps['schema'] = $this->container->get( 'migrator' )->install();
		$this->container->get( 'queue' )->release_lock();
		$this->container->get( 'scheduler' )->install_events();

		$this->container->get( 'audit' )->record( 'system.repair', 'system', 0, __( 'Manual repair run from diagnostics.', 'custom-post-image-creator' ) );

		if ( isset( $steps['schema']['error'] ) ) {
			$this->back_to( 'cpic-diagnostics', 'error', (string) $steps['schema']['error'] );
		}

		$this->back_to( 'cpic-diagnostics', 'repaired' );
	}
}
