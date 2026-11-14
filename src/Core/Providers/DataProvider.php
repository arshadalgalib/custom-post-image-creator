<?php
/**
 * Data module: template seeding and design-library upkeep.
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
 * Seeds the starter library after the schema is installed and keeps legacy
 * template configuration migrated.
 */
final class DataProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'data';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	public function boot( Container $c ) {
		add_action(
			'cpic/database/installed',
			static function () use ( $c ) {
				$c->get( 'starter_library' )->maybe_seed();
				$c->get( 'brands' )->ensure_default();
			}
		);

		// One-off 6.x → 7.0 template document migration after upgrade.
		add_action(
			'cpic/database/upgraded',
			static function () use ( $c ) {
				$c->get( 'template_manager' )->snapshot_all( 'pre-7.0 backup' );
				$c->get( 'template_manager' )->migrate_all_configs();
				$c->get( 'starter_library' )->maybe_seed();
			}
		);

		add_action(
			'cpic/cron/heartbeat',
			static function () use ( $c ) {
				$c->get( 'starter_library' )->maybe_seed();
			}
		);
	}
}
