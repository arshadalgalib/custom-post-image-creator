<?php
/**
 * Admin module.
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
 * Admin-only hooks. Nothing here is loaded on front-end requests.
 */
final class AdminProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	public function boot( Container $c ) {
		if ( ! is_admin() ) {
			return;
		}

		$c->get( 'admin' )->register();

		add_action(
			'current_screen',
			function () use ( $c ) {
				// Upgrade once per admin request at most (cheap option read).
				$c->get( 'migrator' )->maybe_upgrade();
			}
		);
	}
}
