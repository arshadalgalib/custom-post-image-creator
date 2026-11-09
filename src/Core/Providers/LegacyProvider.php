<?php
/**
 * 6.x compatibility module.
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
 * Keeps 6.5.x call sites alive and imports old credentials exactly once.
 */
final class LegacyProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'legacy';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	public function boot( Container $c ) {
		$bridge = $c->get( 'legacy' );

		// Class aliases must exist before theme code runs on `after_setup_theme`.
		$bridge->register_compat_classes();

		add_action(
			'admin_init',
			static function () use ( $c, $bridge ) {
				if ( get_option( 'cpic_legacy_imported' ) ) {
					return;
				}

				$created = $bridge->import_credentials();

				update_option( 'cpic_legacy_imported', $created > 0 ? $created : 'none', false );
			},
			20
		);
	}
}
