<?php
/**
 * Renderer registry.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Rendering;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds renderer instances; extensions can add renderers through the
 * `cpic/renderers` filter (e.g. a Chromium worker for headless sites).
 */
final class RendererRegistry {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Cached renderers.
	 *
	 * @var array<string,RendererInterface>|null
	 */
	private $renderers = null;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * All renderers keyed by id.
	 *
	 * @return array<string,RendererInterface>
	 */
	public function all() {
		if ( null !== $this->renderers ) {
			return $this->renderers;
		}

		$browser = new BrowserRelayRenderer();

		$renderers = array(
			'browser' => $browser,
			'gd'      => new ServerRenderer(),
		);

		/**
		 * Register additional renderers.
		 *
		 * @param array<string,RendererInterface> $renderers Renderers keyed by id.
		 */
		$filtered = apply_filters( 'cpic/renderers', $renderers );

		$this->renderers = array();

		foreach ( (array) $filtered as $id => $renderer ) {
			if ( $renderer instanceof RendererInterface ) {
				$this->renderers[ (string) $id ] = $renderer;
			}
		}

		return $this->renderers;
	}

	/**
	 * Get one renderer.
	 *
	 * @param string $id Renderer id.
	 * @return RendererInterface|null
	 */
	public function get( $id ) {
		$all = $this->all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * The renderer that should produce final artifacts.
	 *
	 * @return RendererInterface
	 */
	public function primary() {
		$preferred = (string) $this->container->get( 'settings' )->get( 'cpic_renderer', 'browser' );
		$renderer  = $this->get( $preferred );

		if ( $renderer && $renderer->is_available() ) {
			return $renderer;
		}

		$all = $this->all();

		return isset( $all['browser'] ) ? $all['browser'] : reset( $all );
	}

	/**
	 * A renderer that can run inside a background request.
	 *
	 * @return RendererInterface|null
	 */
	public function background_capable() {
		foreach ( $this->all() as $renderer ) {
			if ( $renderer->is_available() && $renderer->runs_in_background() && $renderer->supports_complex_scripts() ) {
				return $renderer;
			}
		}

		// GD can run in the background but cannot shape Bengali; only offer it
		// when it may still be useful (latin-only), callers must check.
		foreach ( $this->all() as $renderer ) {
			if ( $renderer->is_available() && $renderer->runs_in_background() ) {
				return $renderer;
			}
		}

		return null;
	}

	/**
	 * Whether any background-capable complex-script renderer exists.
	 *
	 * @return bool
	 */
	public function has_full_background_renderer() {
		foreach ( $this->all() as $renderer ) {
			if ( $renderer->is_available() && $renderer->runs_in_background() && $renderer->supports_complex_scripts() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Status rows for diagnostics.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function status() {
		$rows = array();

		foreach ( $this->all() as $id => $renderer ) {
			$rows[] = array(
				'id'              => (string) $id,
				'label'           => $renderer->label(),
				'available'       => (bool) $renderer->is_available(),
				'background'      => (bool) $renderer->runs_in_background(),
				'complex_scripts' => (bool) $renderer->supports_complex_scripts(),
			);
		}

		return $rows;
	}
}
