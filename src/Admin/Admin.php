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
		add_action( 'admin_post_cpic_facebook_oauth', array( $this, 'handle_facebook_oauth' ) );
		add_action( 'admin_post_cpic_facebook_connect_pages', array( $this, 'handle_facebook_connect_pages' ) );
		// Facebook OAuth popup lands on this URL without a user nonce; state transient is the CSRF token.
		add_action( 'admin_post_nopriv_cpic_facebook_oauth', array( $this, 'handle_facebook_oauth' ) );
		add_action( 'admin_post_cpic_save_facebook_creds', array( $this, 'handle_save_facebook_creds' ) );
		add_action( 'admin_post_cpic_save_telegram', array( $this, 'handle_save_telegram' ) );
		add_action( 'admin_post_cpic_test_telegram', array( $this, 'handle_test_telegram' ) );
		add_action( 'admin_post_cpic_disconnect_facebook', array( $this, 'handle_disconnect_facebook' ) );
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

		// Facebook OAuth helper on Social Accounts (popup + page picker).
		if ( $is_plugin_screen ) {
			$screen_id = $screen ? (string) $screen->id : '';
			if ( false !== strpos( $screen_id, 'cpic-accounts' ) || false !== strpos( (string) $hook, 'cpic-accounts' ) ) {
				wp_enqueue_script(
					'cpic-accounts',
					CPIC_PLUGIN_URL . 'resources/js/admin-accounts.js',
					array( 'wp-api-fetch' ),
					$version,
					true
				);
				wp_localize_script(
					'cpic-accounts',
					'CPICAccounts',
					array(
						'restRoot'      => esc_url_raw( rest_url() ),
						'nonce'         => wp_create_nonce( 'wp_rest' ),
						'canManage'     => $this->container->get( 'capabilities' )->can( 'cpic_manage_accounts' ),
						'fbConfigured'  => $this->container->get( 'facebook_oauth' )->is_configured(),
						'fbSetupUrl'    => admin_url( 'admin.php?page=cpic-settings' ),
						'redirectUri'   => $this->container->get( 'facebook_oauth' )->redirect_uri(),
						'i18n'          => array(
							'connecting'    => __( 'Contacting Facebook…', 'custom-post-image-creator' ),
							'popupBlocked'  => __( 'Your browser blocked the popup. Allow popups for this site and try again.', 'custom-post-image-creator' ),
							'notConfigured' => $this->container->get( 'facebook_oauth' )->configuration_error(),
							'failed'        => __( 'Facebook authorization failed.', 'custom-post-image-creator' ),
							'connectFb'     => __( 'Connect with Facebook', 'custom-post-image-creator' ),
						),
					)
				);
			}
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
			'offline'       => __( 'The server could not be reached. Check your connection and try again.', 'custom-post-image-creator' ),
			'timedOut'      => __( 'The server did not respond in time. Check your connection and try again.', 'custom-post-image-creator' ),
			'badResponse'   => __( 'The server sent a response the editor could not read. Reload the page and try again.', 'custom-post-image-creator' ),
			'renderFallback' => __( 'Some layers were drawn without their content. Check the highlighted layers before publishing.', 'custom-post-image-creator' ),
			'noPlan'        => __( 'The design could not be prepared for export. Check your connection, then reload the page.', 'custom-post-image-creator' ),
			'publishedStored' => __( 'Image saved — nothing was posted.', 'custom-post-image-creator' ),
			'publishedWaiting' => __( 'Saved for publishing.', 'custom-post-image-creator' ),
			'bindFailed'    => __( 'One part of the editor did not start. Reloading the page usually fixes it.', 'custom-post-image-creator' ),
			'requestFailed' => __( 'Request failed', 'custom-post-image-creator' ),
			'tooLargeResponse' => __( 'The server refused the request because it was too large (HTTP 413). Raise post_max_size and upload_max_filesize, then try again.', 'custom-post-image-creator' ),
			'sessionExpired' => __( 'WordPress refused the request. Your session may have expired — reload the page and try again.', 'custom-post-image-creator' ),
			'serverError'   => __( 'The server returned an error page instead of a response. Check the debug log; a PHP error usually explains this.', 'custom-post-image-creator' ),
			'imageTooLarge' => __( 'The image is too large for this server to accept. Ask your host to raise the upload limit, or pick a smaller format.', 'custom-post-image-creator' ),
			'stageRender'   => __( '(while rendering the image on the server)', 'custom-post-image-creator' ),
			'stageStore'    => __( '(while storing the image on the server)', 'custom-post-image-creator' ),
			'stageUpload'   => __( '(while storing the image on the server)', 'custom-post-image-creator' ),
			'noArtifact'    => __( 'The server did not return a stored image. Reload the page and try again.', 'custom-post-image-creator' ),
			'publishFailed' => __( 'Publishing failed', 'custom-post-image-creator' ),
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

		if ( ! defined( 'CPIC_TEST_ALLOW_EXIT' ) ) {
			exit;
		}

		return;
	}

	/**
	 * Map a publish result onto the queue notice that describes it.
	 *
	 * @param array<string,mixed> $result Value returned by DeliveryManager::publish_post().
	 * @return string One of 'published', 'scheduled', 'queued', 'publishing' or 'failed'.
	 */
	private function publish_outcome_notice( array $result ) {
		if ( ! empty( $result['waiting_render'] ) ) {
			return 'queued';
		}

		if ( ! empty( $result['scheduled_for'] ) && empty( $result['results'] ) ) {
			return 'scheduled';
		}

		if ( ! isset( $result['results'] ) ) {
			// Created but not dispatched in this request: the queue will run it.
			return 'queued';
		}

		$statuses = array();

		foreach ( (array) $result['results'] as $row ) {
			if ( is_array( $row ) ) {
				$statuses[] = isset( $row['status'] ) ? (string) $row['status'] : '';
			}
		}

		if ( array() === $statuses ) {
			return 'queued';
		}

		if ( in_array( 'published', $statuses, true ) ) {
			// A mixed run is still a real send; the queue lists the rest.
			return 'published';
		}

		foreach ( array( 'queued', 'pending', 'waiting_for_artifact', 'retrying' ) as $waiting ) {
			if ( in_array( $waiting, $statuses, true ) ) {
				return 'queued';
			}
		}

		return 'failed';
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

		/*
		 * Only claim "published" when a destination actually confirmed it.
		 *
		 * A run parked on `waiting_for_artifact` has not sent anything yet, and
		 * a run whose every dispatch failed has sent nothing either. Both used
		 * to land here as a bare non-WP_Error and were reported as "The post
		 * was sent to its destinations." — the same misleading success the
		 * studio showed before it learned to read per-delivery outcomes.
		 */
		if ( is_wp_error( $result ) ) {
			set_transient( 'cpic_admin_error_' . get_current_user_id(), $result->get_error_message(), 60 );
			$status = 'failed';
		} else {
			$status = $this->publish_outcome_notice( (array) $result );
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

		if ( ! defined( 'CPIC_TEST_ALLOW_EXIT' ) ) {
			exit;
		}

		return;
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

		/*
		 * `exit` is what a real request does, but it also ends the test process
		 * (and, under php-wasm, silently poisons the interpreter for the next
		 * script). The harness defines CPIC_TEST_ALLOW_EXIT so the handler can
		 * return and its redirect + side effects can be asserted.
		 */
		if ( ! defined( 'CPIC_TEST_ALLOW_EXIT' ) ) {
			exit;
		}

		return;
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

		if ( '' === trim( (string) ( isset( $data['name'] ) ? $data['name'] : '' ) ) ) {
			$this->back_to( 'cpic-brands', 'error', __( 'A brand kit needs a name.', 'custom-post-image-creator' ) );
			return;
		}

		$repo = $this->container->get( 'brands' );

		if ( $id > 0 ) {
			if ( ! $repo->find( $id ) ) {
				$this->back_to( 'cpic-brands', 'error', __( 'That brand kit no longer exists.', 'custom-post-image-creator' ) );
				return;
			}
			$repo->update( $id, $data );
		} else {
			$created = $repo->create( $data );
			if ( ! $created ) {
				// Names may repeat (the slug is what stays unique), so the old
				// "check that the name is unique" advice sent people in circles.
				$this->back_to( 'cpic-brands', 'error', __( 'The brand kit could not be created. Please try again.', 'custom-post-image-creator' ) );
				return;
			}
		}

		$this->back_to( 'cpic-brands', 'saved' );
		return;
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
			return;
		}

		$repo->clear_default();
		$repo->update( $id, array( 'is_default' => 1 ) );

		$this->back_to( 'cpic-brands', 'saved' );
		return;
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
			return;
		}

		$this->back_to( 'cpic-accounts', 'saved' );
		return;
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
				return;
		}

		$this->back_to( 'cpic-accounts', 'saved' );
		return;
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
			return;
		}

		$this->back_to( 'cpic-campaigns', 'saved' );
		return;
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
			return;
		}

		$this->back_to( 'cpic-campaigns', 'saved' );
		return;
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
		return;
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
			return;
		}

		$this->back_to( 'cpic-diagnostics', 'repaired' );
		return;
	}

	/**
	 * Facebook OAuth: handle redirect from Facebook (popup).
	 *
	 * This is intentionally not nonce-checked via check_admin_referer; the
	 * `state` param is the CSRF token (stored as a transient). We still gate
	 * writes behind the transient's user but render all output safely.
	 *
	 * @return void
	 */
	public function handle_facebook_oauth() {
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error_description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error_description'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $error ) {
			$this->render_oauth_error( $error_description ? $error_description : $error );
			return;
		}

		if ( '' === $code || '' === $state ) {
			$this->render_oauth_error( __( 'Facebook did not return an authorization code. Please try again.', 'custom-post-image-creator' ) );
			return;
		}

		$oauth = $this->container->get( 'facebook_oauth' );
		$picker_token = $oauth->handle_callback( $code, $state );

		if ( is_wp_error( $picker_token ) ) {
			$this->render_oauth_error( $picker_token->get_error_message() );
			return;
		}

		$this->render_oauth_picker( $picker_token );
	}

	/**
	 * Facebook OAuth: persist selected pages from the picker.
	 *
	 * @return void
	 */
	public function handle_facebook_connect_pages() {
		$picker_token = isset( $_POST['picker_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['picker_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $picker_token ) {
			$this->render_oauth_error( __( 'Missing session token. Close this window and start again.', 'custom-post-image-creator' ) );
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), 'cpic_fb_connect_' . $picker_token ) ) {
			$this->render_oauth_error( __( 'Session expired. Close this window and start again.', 'custom-post-image-creator' ) );
			return;
		}

		$raw = isset( $_POST['page_ids'] ) ? wp_unslash( $_POST['page_ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$page_ids = is_array( $raw ) ? array_map( 'sanitize_text_field', $raw ) : array( sanitize_text_field( (string) $raw ) );

		$result = $this->container->get( 'facebook_oauth' )->connect_pages( $picker_token, $page_ids );

		if ( is_wp_error( $result ) ) {
			$this->render_oauth_error( $result->get_error_message(), $picker_token );
			return;
		}

		$this->render_oauth_success( count( $result ) );
	}

	/**
	 * Render an OAuth error inside the popup.
	 *
	 * @param string $message      Error message.
	 * @param string $picker_token Optional picker token to re-show form.
	 * @return void
	 */
	private function render_oauth_error( $message, $picker_token = '' ) {
		$retry_url = admin_url( 'admin.php?page=cpic-accounts' );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width">
			<title><?php esc_html_e( 'Facebook connection', 'custom-post-image-creator' ); ?></title>
			<style>
				body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:24px;color:#1d2327}
				.cpic-oauth__card{max-width:520px;margin:40px auto;border:1px solid #dcdcde;border-radius:8px;padding:24px;background:#fff}
				.cpic-oauth__error{color:#b32d2e}
				.button{margin-top:16px}
			</style>
		</head>
		<body>
			<div class="cpic-oauth__card">
				<h2><?php esc_html_e( 'Facebook connection failed', 'custom-post-image-creator' ); ?></h2>
				<p class="cpic-oauth__error"><?php echo esc_html( $message ); ?></p>
				<p class="cpic-muted"><?php esc_html_e( 'You can close this window and try again. Your Facebook App redirect URI must exactly match the one shown in Social Accounts.', 'custom-post-image-creator' ); ?></p>
				<?php if ( '' !== $picker_token ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=cpic_facebook_connect_pages' ) ); ?>">
						<?php wp_nonce_field( 'cpic_fb_connect_' . $picker_token ); ?>
						<input type="hidden" name="picker_token" value="<?php echo esc_attr( $picker_token ); ?>">
						<p><a href="#" onclick="history.back();return false;" class="button"><?php esc_html_e( 'Go back', 'custom-post-image-creator' ); ?></a></p>
					</form>
				<?php else : ?>
					<p><button type="button" class="button" onclick="window.close()"><?php esc_html_e( 'Close window', 'custom-post-image-creator' ); ?></button></p>
				<?php endif; ?>
			</div>
		</body>
		</html>
		<?php

		if ( ! defined( 'CPIC_TEST_ALLOW_EXIT' ) ) {
			exit;
		}
	}

	/**
	 * Render the page picker inside the popup.
	 *
	 * @param string $picker_token Picker token.
	 * @return void
	 */
	private function render_oauth_picker( $picker_token ) {
		$oauth   = $this->container->get( 'facebook_oauth' );
		$payload = $oauth->get_picker_payload( $picker_token );

		if ( is_wp_error( $payload ) ) {
			$this->render_oauth_error( $payload->get_error_message() );
			return;
		}

		$pages = (array) $payload['pages'];
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width">
			<title><?php esc_html_e( 'Choose Facebook Pages', 'custom-post-image-creator' ); ?></title>
			<style>
				body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:16px;color:#1d2327;background:#f0f0f1}
				.cpic-oauth__card{max-width:560px;margin:20px auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px}
				.cpic-oauth__page{display:flex;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid #f0f0f1}
				.cpic-oauth__page img{width:36px;height:36px;border-radius:50%;object-fit:cover;background:#f6f7f7}
				.cpic-oauth__meta{color:#646970;font-size:12px}
				.cpic-oauth__actions{display:flex;gap:8px;margin-top:16px}
			</style>
		</head>
		<body>
			<div class="cpic-oauth__card">
				<h2 style="margin-top:0"><?php esc_html_e( 'Choose Pages to connect', 'custom-post-image-creator' ); ?></h2>
				<p class="cpic-muted"><?php esc_html_e( 'Only the Pages you select will be connected. You can add more later.', 'custom-post-image-creator' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=cpic_facebook_connect_pages' ) ); ?>">
					<?php wp_nonce_field( 'cpic_fb_connect_' . $picker_token ); ?>
					<input type="hidden" name="picker_token" value="<?php echo esc_attr( $picker_token ); ?>">
					<?php foreach ( $pages as $page ) : ?>
						<label class="cpic-oauth__page">
							<input type="checkbox" name="page_ids[]" value="<?php echo esc_attr( (string) $page['id'] ); ?>" checked>
							<?php if ( ! empty( $page['picture'] ) ) : ?>
								<img src="<?php echo esc_url( (string) $page['picture'] ); ?>" alt="">
							<?php endif; ?>
							<span>
								<strong><?php echo esc_html( (string) $page['name'] ); ?></strong><br>
								<span class="cpic-oauth__meta"><?php echo esc_html( (string) ( isset( $page['category'] ) ? $page['category'] : '' ) ); ?> · <code><?php echo esc_html( (string) $page['id'] ); ?></code><?php echo ! empty( $page['fan_count'] ) ? ' · ' . esc_html( number_format_i18n( (int) $page['fan_count'] ) . ' fans' ) : ''; ?></span>
							</span>
						</label>
					<?php endforeach; ?>
					<div class="cpic-oauth__actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Connect selected Pages', 'custom-post-image-creator' ); ?></button>
						<a href="#" onclick="window.close();return false;" class="button"><?php esc_html_e( 'Cancel', 'custom-post-image-creator' ); ?></a>
					</div>
				</form>
			</div>
		</body>
		</html>
		<?php

		if ( ! defined( 'CPIC_TEST_ALLOW_EXIT' ) ) {
			exit;
		}
	}

	/**
	 * Render success and close the popup, asking the opener to reload.
	 *
	 * @param int $count Number of pages connected.
	 * @return void
	 */
	/**
	 * Save Facebook App ID / Secret from the simple connections screen.
	 *
	 * @return void
	 */
	public function handle_save_facebook_creds() {
		check_admin_referer( 'cpic_save_facebook_creds' );
		$this->require_cap( 'cpic_manage_accounts' );

		$settings = $this->container->get( 'settings' );
		$app_id   = isset( $_POST['cpic_fb_app_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['cpic_fb_app_id'] ) ) : '';

		$result = $settings->bulk_set( array( 'cpic_fb_app_id' => $app_id ) );

		// App Secret is secret type — only set if non-empty.
		$app_secret = isset( $_POST['cpic_fb_app_secret'] ) ? trim( wp_unslash( (string) $_POST['cpic_fb_app_secret'] ) ) : '';
		if ( '' !== $app_secret ) {
			$settings->set( 'cpic_fb_app_secret', $app_secret );
		}

		if ( ! empty( $result['errors'] ) ) {
			$this->back_to( 'cpic-accounts', 'error', implode( ' ', $result['errors'] ) );
			return;
		}
		$this->back_to( 'cpic-accounts', 'saved' );
		return;
	}

	/**
	 * Save Telegram Bot Token / Chat ID from the simple connections screen.
	 *
	 * @return void
	 */
	public function handle_save_telegram() {
		check_admin_referer( 'cpic_save_telegram' );
		$this->require_cap( 'cpic_manage_accounts' );

		$settings  = $this->container->get( 'settings' );
		$chat_id   = isset( $_POST['cpic_tg_chat_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['cpic_tg_chat_id'] ) ) : '';
		$bot_token = isset( $_POST['cpic_tg_bot_token'] ) ? trim( wp_unslash( (string) $_POST['cpic_tg_bot_token'] ) ) : '';

		$settings->bulk_set( array( 'cpic_tg_chat_id' => $chat_id ) );
		if ( '' !== $bot_token ) {
			$settings->set( 'cpic_tg_bot_token', $bot_token );
			// Also keep destinations in sync for publishing.
			$this->sync_telegram_destination( $chat_id, $bot_token );
		} elseif ( '' !== $chat_id ) {
			// Chat ID changed but token kept — ensure destination exists.
			$existing = (string) $settings->get( 'cpic_tg_bot_token', '' );
			if ( '' === $existing ) {
				$existing = \CPIC\Security\Settings::reveal_secret( (string) get_option( 'cpic_tg_bot_token', '' ) );
			}
			if ( '' !== $existing ) {
				$this->sync_telegram_destination( $chat_id, $existing );
			}
		}

		$this->back_to( 'cpic-accounts', 'saved' );
		return;
	}

	/**
	 * Ensure a Telegram destination exists for the given chat/bot.
	 *
	 * @param string $chat_id   Chat ID.
	 * @param string $bot_token Bot token.
	 * @return void
	 */
	private function sync_telegram_destination( $chat_id, $bot_token ) {
		if ( '' === trim( (string) $chat_id ) || '' === trim( (string) $bot_token ) ) {
			return;
		}
		$accounts = $this->container->get( 'accounts' );
		$existing = $accounts->repo()->find_by_account( 'telegram', (string) $chat_id );
		if ( $existing ) {
			$accounts->update( (int) $existing['id'], array( 'credentials' => array( 'bot_token' => $bot_token ), 'status' => 'connected' ) );
			return;
		}
		// Keep single Telegram channel for simple UI — remove old disconnected ones.
		$accounts->create( array( 'provider' => 'telegram', 'label' => (string) $chat_id, 'account_id' => (string) $chat_id, 'credentials' => array( 'bot_token' => $bot_token ), 'status' => 'connected' ) );
	}

	/**
	 * Disconnect Facebook Page (delete first facebook destination).
	 *
	 * @return void
	 */
	public function handle_disconnect_facebook() {
		check_admin_referer( 'cpic_disconnect_facebook' );
		$this->require_cap( 'cpic_manage_accounts' );
		$rows = $this->container->get( 'accounts' )->all( array( 'provider' => 'facebook' ) );
		foreach ( $rows as $row ) {
			$this->container->get( 'accounts' )->delete( (int) $row['id'] );
		}
		$this->back_to( 'cpic-accounts', 'disconnected' );
		return;
	}

	/**
	 * Test Telegram connection via getMe / send test.
	 * Called via admin-post?cpic_test_telegram (AJAX-like but simple).
	 *
	 * @return void
	 */
	public function handle_test_telegram() {
		check_admin_referer( 'cpic_test_telegram' );
		$this->require_cap( 'cpic_manage_accounts' );

		$settings  = $this->container->get( 'settings' );
		$bot_token = \CPIC\Security\Settings::reveal_secret( (string) get_option( 'cpic_tg_bot_token', '' ) );
		if ( '' === $bot_token ) {
			$bot_token = trim( (string) $settings->get( 'cpic_tg_bot_token', '' ) );
		}
		$chat_id = isset( $_POST['cpic_tg_chat_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['cpic_tg_chat_id'] ) ) : (string) $settings->get( 'cpic_tg_chat_id', '' );

		if ( '' === $bot_token ) {
			$this->back_to( 'cpic-accounts', 'error', __( 'Bot Token is missing.', 'custom-post-image-creator' ) );
			return;
		}

		// Minimal getMe check.
		$url      = 'https://api.telegram.org/bot' . $bot_token . '/getMe';
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			$this->back_to( 'cpic-accounts', 'error', $response->get_error_message() );
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $body['ok'] ) ) {
			$msg = isset( $body['description'] ) ? (string) $body['description'] : __( 'Telegram test failed.', 'custom-post-image-creator' );
			$this->back_to( 'cpic-accounts', 'error', $msg );
			return;
		}

		$this->back_to( 'cpic-accounts', 'tested' );
		return;
	}

	private function render_oauth_success( $count ) {
		$accounts_url = admin_url( 'admin.php?page=cpic-accounts&cpic_notice=saved' );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<title><?php esc_html_e( 'Connected', 'custom-post-image-creator' ); ?></title>
			<style>body{font-family:-apple-system,sans-serif;padding:24px;text-align:center}</style>
		</head>
		<body>
			<h2><?php echo esc_html( sprintf( _n( '%d Page connected', '%d Pages connected', $count, 'custom-post-image-creator' ), $count ) ); ?></h2>
			<p><?php esc_html_e( 'You can close this window.', 'custom-post-image-creator' ); ?></p>
			<script>
				try {
					if (window.opener && !window.opener.closed) {
						window.opener.location.href = <?php echo wp_json_encode( $accounts_url ); ?>;
						window.opener.focus();
					}
				} catch(e) {}
				setTimeout(function(){ window.close(); }, 1200);
			</script>
			<p><a href="<?php echo esc_url( $accounts_url ); ?>" target="_blank" class="button button-primary"><?php esc_html_e( 'Open Social Accounts', 'custom-post-image-creator' ); ?></a></p>
		</body>
		</html>
		<?php

		if ( ! defined( 'CPIC_TEST_ALLOW_EXIT' ) ) {
			exit;
		}
	}
}
