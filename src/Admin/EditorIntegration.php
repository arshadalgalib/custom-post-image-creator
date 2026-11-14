<?php
/**
 * Block editor / classic editor integration.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Admin;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a sidebar panel in the block editor and a metabox in the classic editor
 * so authors can see (and regenerate) the social image without leaving the post.
 */
final class EditorIntegration {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'block_assets' ) );
		add_action( 'add_meta_boxes', array( $this, 'metabox' ) );
		add_action( 'admin_post_cpic_save_editor_image', array( $this, 'handle_metabox_save' ) );
	}

	/**
	 * Block editor panel.
	 *
	 * @return void
	 */
	public function block_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		$post_types = array_filter( array_map( 'trim', explode( ',', (string) $this->container->get( 'settings' )->get( 'cpic_post_types', 'post' ) ) ) );

		if ( ! empty( $post_types ) && ! in_array( (string) $screen->post_type, $post_types, true ) ) {
			return;
		}

		wp_enqueue_script(
			'cpic-editor-panel',
			CPIC_PLUGIN_URL . 'resources/js/editor.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'cpic-studio' ),
			CPIC_VERSION,
			true
		);
	}

	/**
	 * Classic editor metabox.
	 *
	 * @return void
	 */
	public function metabox() {
		$post_types = array_filter( array_map( 'trim', explode( ',', (string) $this->container->get( 'settings' )->get( 'cpic_post_types', 'post' ) ) ) );

		foreach ( (array) $post_types as $post_type ) {
			add_meta_box(
				'cpic-editor-image',
				__( 'Social image', 'custom-post-image-creator' ),
				array( $this, 'render_metabox' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the metabox.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function render_metabox( $post ) {
		$artifact = $this->container->get( 'artifact_manager' )->latest( (int) $post->ID );

		wp_nonce_field( 'cpic_editor_image_' . (int) $post->ID, 'cpic_editor_nonce' );

		echo '<div class="cpic-metabox">';

		if ( $artifact && ! empty( $artifact['exists'] ) ) {
			printf(
				'<p><img src="%s" alt="" style="max-width:100%%;height:auto;border-radius:4px" /></p>',
				esc_url( (string) $artifact['file_url'] )
			);

			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: width, 2: height, 3: date */
						__( '%1$d×%2$d, generated %3$s', 'custom-post-image-creator' ),
						(int) $artifact['width'],
						(int) $artifact['height'],
						(string) $artifact['created_at']
					)
				)
			);
		} else {
			echo '<p>' . esc_html__( 'No social image has been generated for this post yet.', 'custom-post-image-creator' ) . '</p>';
		}

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=cpic-studio&post=' . (int) $post->ID ) ),
			esc_html__( 'Open design studio', 'custom-post-image-creator' )
		);

		if ( $artifact && ! empty( $artifact['exists'] ) && $this->container->get( 'capabilities' )->can( 'cpic_publish' ) ) {
			printf(
				'<p><a class="button button-primary" href="%s">%s</a></p>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=cpic_publish_now&post_id=' . (int) $post->ID ),
						'cpic_publish_' . (int) $post->ID
					)
				),
				esc_html__( 'Publish image now', 'custom-post-image-creator' )
			);
		}

		echo '</div>';
	}

	/**
	 * Handle the metabox form (currently informational only).
	 *
	 * @return void
	 */
	public function handle_metabox_save() {
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		if ( ! $post_id || ! isset( $_POST['cpic_editor_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['cpic_editor_nonce'] ) ), 'cpic_editor_image_' . $post_id ) ) {
			wp_die( esc_html__( 'That request could not be verified. Reload the post and try again.', 'custom-post-image-creator' ), 403 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! $this->container->get( 'capabilities' )->can( 'cpic_publish' ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this post.', 'custom-post-image-creator' ), 403 );
		}

		wp_safe_redirect( add_query_arg( array( 'post' => $post_id, 'action' => 'edit' ), admin_url( 'post.php' ) ) );

		if ( ! defined( 'CPIC_TEST_ALLOW_EXIT' ) ) {
			exit;
		}
	}
}
