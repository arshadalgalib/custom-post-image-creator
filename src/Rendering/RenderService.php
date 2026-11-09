<?php
/**
 * Render orchestration: plan → artifact, with honest renderer fallbacks.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Rendering;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single entry point the editor, REST API, automation and CLI use to turn
 * a design document into a stored artifact.
 *
 * Rendering honesty rules:
 *  - The browser renderer is the only one that can shape Bengali/Arabic/
 *    Devanagari text, and it cannot run inside a PHP request.
 *  - The GD renderer can run in the background, but only for latin-only
 *    designs; it refuses complex scripts rather than shipping broken glyphs.
 *  - When a background render is required and no capable renderer exists, the
 *    caller gets an explicit, actionable error (and the job is parked), never
 *    a silently wrong image.
 */
final class RenderService {

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
	 * Build a render plan for a post.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $args {
	 *     @type array  $document  Design document (defaults to the post's template).
	 *     @type int    $template_id Template id.
	 *     @type string $format    Format key.
	 *     @type array  $overrides Per-element text overrides.
	 *     @type int    $scale     Pixel ratio.
	 * }
	 * @return array<string,mixed>|\WP_Error
	 */
	public function plan( $post_id, array $args = array() ) {
		$post_id = (int) $post_id;

		$document = isset( $args['document'] ) && is_array( $args['document'] ) ? $args['document'] : null;

		if ( null === $document ) {
			$template = null;

			if ( ! empty( $args['template_id'] ) ) {
				$template = $this->container->get( 'template_manager' )->get( (int) $args['template_id'] );
			}

			if ( ! $template && $post_id ) {
				$template = $this->container->get( 'template_manager' )->for_post( $post_id );
			}

			if ( ! $template ) {
				$template = $this->container->get( 'template_manager' )->default_template();
			}

			if ( ! $template ) {
				return new \WP_Error( 'cpic_no_template', __( 'No template is available for this post.', 'custom-post-image-creator' ) );
			}

			$document = $template['document'];
		}

		$format = isset( $args['format'] ) ? sanitize_key( (string) $args['format'] ) : (string) $this->container->get( 'settings' )->get( 'cpic_default_format', 'square' );

		return $this->container->get( 'render_plan' )->build(
			array(
				'document'  => $document,
				'format'    => $format,
				'post_id'   => $post_id,
				'brand_id'  => isset( $args['brand_id'] ) ? (int) $args['brand_id'] : 0,
				'overrides' => isset( $args['overrides'] ) ? (array) $args['overrides'] : array(),
				'scale'     => isset( $args['scale'] ) ? (int) $args['scale'] : 1,
				'data'      => isset( $args['data'] ) ? (array) $args['data'] : array(),
			)
		);
	}

	/**
	 * Render a post through the best available renderer.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $args    Same as plan() plus `force` (bypass cache).
	 * @return array<string,mixed>|\WP_Error Artifact row.
	 */
	public function render( $post_id, array $args = array() ) {
		$post_id = (int) $post_id;

		$plan = $this->plan( $post_id, $args );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		return $this->render_plan( $plan, $post_id, $args );
	}

	/**
	 * Render an already-built plan.
	 *
	 * @param array<string,mixed> $plan    Render plan.
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $args    Options (`force`, `template_id`, `meta`).
	 * @return array<string,mixed>|\WP_Error
	 */
	public function render_plan( array $plan, $post_id = 0, array $args = array() ) {
		$document = isset( $args['document'] ) && is_array( $args['document'] ) ? $args['document'] : array();

		$force = ! empty( $args['force'] );

		// Cache hit: signature + checksum must both match and the file must exist.
		if ( ! $force && $this->container->get( 'settings' )->is_enabled( 'cpic_render_cache' ) && $post_id && $document ) {
			$fresh = $this->container->get( 'artifact_manager' )->fresh( $post_id, $document, (string) $plan['format'] );

			if ( $fresh ) {
				$fresh['cached'] = true;
				return $fresh;
			}
		}

		$registry = $this->container->get( 'renderers' );
		$primary  = $registry->primary();

		$complex = $this->plan_has_complex_script( $plan );

		// Preferred renderer cannot run here (browser in a PHP request).
		if ( 'browser' === $primary->id() || ! $primary->runs_in_background() ) {
			if ( ! empty( $args['browser_only'] ) || $this->is_browser_request() ) {
				return new \WP_Error(
					'cpic_browser_only',
					__( 'This design must be rendered in the browser.', 'custom-post-image-creator' ),
					array( 'status' => 409, 'plan' => $plan )
				);
			}

			$fallback = $registry->background_capable();

			if ( ! $fallback ) {
				return new \WP_Error(
					'cpic_no_background_renderer',
					__( 'No renderer can produce this image in the background. Open the design in the studio to render it.', 'custom-post-image-creator' ),
					array( 'status' => 409 )
				);
			}

			if ( $complex && ! $fallback->supports_complex_scripts() ) {
				return new \WP_Error(
					'cpic_complex_script',
					__( 'This design contains Bengali or another complex script. Open the post in the studio so the browser can render it.', 'custom-post-image-creator' ),
					array( 'status' => 409, 'renderer' => $fallback->id() )
				);
			}

			$primary = $fallback;
		}

		if ( $complex && ! $primary->supports_complex_scripts() ) {
			return new \WP_Error(
				'cpic_complex_script',
				__( 'The selected renderer cannot shape this script. Choose another renderer.', 'custom-post-image-creator' ),
				array( 'status' => 409, 'renderer' => $primary->id() )
			);
		}

		if ( ! $primary->is_available() ) {
			return new \WP_Error(
				'cpic_renderer_unavailable',
				__( 'The selected renderer is not available on this server.', 'custom-post-image-creator' ),
				array( 'status' => 500, 'renderer' => $primary->id() )
			);
		}

		$result = $primary->render( $plan );

		if ( is_wp_error( $result ) ) {
			$this->container->get( 'logger' )->error(
				'render',
				/* translators: %s: error message */
				sprintf( __( 'Render failed: %s', 'custom-post-image-creator' ), $result->get_error_message() ),
				array(
					'post_id'  => (int) $post_id,
					'renderer' => $primary->id(),
					'code'     => $result->get_error_code(),
				)
			);
			return $result;
		}

		$stored = empty( $result['path'] )
			? new \WP_Error( 'cpic_empty_render', __( 'The renderer returned no file.', 'custom-post-image-creator' ) )
			: $this->container->get( 'artifact_manager' )->store_file(
				array(
					'path'        => (string) $result['path'],
					'post_id'     => (int) $post_id,
					'template_id' => isset( $args['template_id'] ) ? (int) $args['template_id'] : 0,
					'format'      => (string) $plan['format'],
					'document'    => $document,
					'renderer'    => $primary->id(),
					'meta'        => isset( $args['meta'] ) ? (array) $args['meta'] : array(),
					'attach'      => ! empty( $args['attach'] ),
				)
			);

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$stored['cached'] = false;

		if ( ! empty( $result['cleanup'] ) && file_exists( (string) $result['path'] ) ) {
			@unlink( (string) $result['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		$this->container->get( 'events' )->dispatch(
			'render.completed',
			array(
				'artifact_id' => (int) $stored['id'],
				'post_id'     => (int) $post_id,
				'format'      => (string) $plan['format'],
				'renderer'    => $primary->id(),
				'cached'      => false,
			)
		);

		return $stored;
	}

	/**
	 * Whether a plan contains text a shaping engine must handle.
	 *
	 * @param array<string,mixed> $plan Plan.
	 * @return bool
	 */
	public function plan_has_complex_script( array $plan ) {
		$gd = new ServerRenderer();

		if ( ! empty( $plan['layers'] ) && is_array( $plan['layers'] ) ) {
			foreach ( $plan['layers'] as $layer ) {
				if ( isset( $layer['text']['value'] ) && $gd->needs_shaping( (string) $layer['text']['value'] ) ) {
					return true;
				}
			}
		}

		foreach ( array( 'title', 'name' ) as $key ) {
			if ( ! empty( $plan['post'][ $key ] ) && $gd->needs_shaping( (string) $plan['post'][ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current request came from a browser (admin-ajax / REST UI).
	 *
	 * @return bool
	 */
	private function is_browser_request() {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}

		return (bool) apply_filters( 'cpic/render/is_browser_request', false );
	}

	/**
	 * Render every format variant for a post.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<int,string>   $formats Formats (default: the template's variants).
	 * @param array<string,mixed> $args    Options.
	 * @return array<string,mixed>|\WP_Error Keyed by format.
	 */
	public function render_all( $post_id, array $formats = array(), array $args = array() ) {
		$post_id = (int) $post_id;

		$plan = $this->plan( $post_id, $args );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$document = isset( $args['document'] ) ? (array) $args['document'] : array();

		if ( empty( $formats ) ) {
			$formats = $this->container->get( 'template_schema' )->variants( $document );
		}

		if ( empty( $formats ) ) {
			$formats = array( (string) $plan['format'] );
		}

		$out = array();

		foreach ( $formats as $format ) {
			$variant_args          = $args;
			$variant_args['format'] = $format;

			$variant_plan = $this->plan( $post_id, $variant_args );

			if ( is_wp_error( $variant_plan ) ) {
				$out[ $format ] = $variant_plan;
				continue;
			}

			$out[ $format ] = $this->render_plan( $variant_plan, $post_id, $variant_args );
		}

		return $out;
	}

	/**
	 * Apply retention to stored artifacts.
	 *
	 * @return int Deleted rows.
	 */
	public function cleanup_artifacts() {
		$days = (int) $this->container->get( 'settings' )->int( 'cpic_artifact_retention_days', 90 );

		return (int) $this->container->get( 'artifact_manager' )->cleanup( $days );
	}

	/**
	 * Renderer + capability status for diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public function status() {
		$registry = $this->container->get( 'renderers' );

		return array(
			'renderers'          => $registry->status(),
			'preferred'          => (string) $this->container->get( 'settings' )->get( 'cpic_renderer', 'browser' ),
			'background_capable' => $registry->has_full_background_renderer(),
			'fonts'              => $this->container->get( 'fonts' )->status(),
		);
	}
}
