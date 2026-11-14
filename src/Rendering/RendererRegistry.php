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
			// Shapes Bengali/Arabic correctly in a PHP request, so scheduled
			// and automated publishing work without an admin's browser.
			'svg'     => new SvgRenderer( $this->container ),
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
		$preferred = (string) $this->container->get( 'settings' )->get( 'cpic_renderer', '' );
		$renderer  = '' === $preferred ? null : $this->get( $preferred );

		/*
		 * An explicit choice wins only when it can actually run in the
		 * background. The browser cannot produce an image from cron, from the
		 * REST API, or from a queued job, so honouring it here would mean the
		 * same design renders one way on screen and another way in automation.
		 */
		if ( $renderer && $renderer->is_available() && $renderer->runs_in_background() ) {
			return $renderer;
		}

		/*
		 * The preferred renderer is not usable here. A shaping-capable renderer
		 * that runs in the background beats the browser even when the browser
		 * is "available", because the browser cannot produce an image outside
		 * an open admin screen — which is how a scheduled post used to end up
		 * parked forever.
		 */
		foreach ( $this->all() as $candidate ) {
			if ( $candidate->is_available() && $candidate->runs_in_background() && $candidate->supports_complex_scripts() ) {
				return $candidate;
			}
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
