<?php
/**
 * REST API surface.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers `cpic/v1`. Every route declares an explicit permission callback —
 * there is no "public" endpoint, and no route accepts a capability from the
 * request body.
 */
final class RestApi {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Controllers.
	 *
	 * @var array<string,\CPIC\REST\Controllers\BaseController>|null
	 */
	private $controllers = null;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Controllers, instantiated lazily per request.
	 *
	 * @return array<string,\CPIC\REST\Controllers\BaseController>
	 */
	public function controllers() {
		if ( null !== $this->controllers ) {
			return $this->controllers;
		}

		$c = $this->container;

		$this->controllers = array(
			'templates'   => new Controllers\TemplatesController( $c ),
			'artifacts'   => new Controllers\ArtifactsController( $c ),
			'render'      => new Controllers\RenderController( $c ),
			'publishing'  => new Controllers\PublishingController( $c ),
			'accounts'    => new Controllers\AccountsController( $c ),
			'jobs'        => new Controllers\JobsController( $c ),
			'campaigns'   => new Controllers\CampaignsController( $c ),
			'calendar'    => new Controllers\CalendarController( $c ),
			'analytics'   => new Controllers\AnalyticsController( $c ),
			'diagnostics' => new Controllers\DiagnosticsController( $c ),
			'settings'    => new Controllers\SettingsController( $c ),
			'media'       => new Controllers\MediaController( $c ),
			'ai'          => new Controllers\AiController( $c ),
			'brands'      => new Controllers\BrandsController( $c ),
		);

		return $this->controllers;
	}

	/**
	 * Register every route.
	 *
	 * @return void
	 */
	public function register_routes() {
		foreach ( $this->controllers() as $controller ) {
			$controller->register();
		}

		/**
		 * Register additional REST routes on `cpic/v1`.
		 *
		 * @param RestApi $api API.
		 */
		do_action( 'cpic/rest/register', $this );
	}

	/**
	 * Register a route with the plugin's shared argument defaults.
	 *
	 * @param string $route      Route (no namespace).
	 * @param array  $definition Route definition.
	 * @return void
	 */
	public function route( $route, array $definition ) {
		register_rest_route( CPIC_REST_NAMESPACE, $route, $definition );
	}

	/**
	 * The REST base for client code.
	 *
	 * @return string
	 */
	public function base_url() {
		return rest_url( CPIC_REST_NAMESPACE . '/' );
	}
}
