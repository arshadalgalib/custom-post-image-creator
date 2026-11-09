<?php
/**
 * REST module.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Core\Providers;

use CPIC\Core\Container;
use CPIC\Core\ServiceProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers `cpic/v1` on `rest_api_init` and nothing else on the front end.
 */
final class RestProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'rest';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	public function boot( Container $c ) {
		add_action(
			'rest_api_init',
			function () use ( $c ) {
				// Ensure the schema exists before the API answers (cheap no-op
				// once installed — this covers sites upgraded via file copy).
				$c->get( 'migrator' )->maybe_upgrade();
				$c->get( 'rest' )->register_routes();
			}
		);

		// Drop the plugin's REST cache headers on its own namespace only.
		add_filter(
			'rest_post_dispatch',
			function ( $response, $server, $request ) {
				$route = (string) $request->get_route();

				if ( 0 === strpos( $route, '/' . CPIC_REST_NAMESPACE ) ) {
					$response->header( 'X-CPIC-Version', CPIC_VERSION );
					$response->header( 'Cache-Control', 'no-store, max-age=0' );
				}

				return $response;
			},
			10,
			3
		);
	}
}
