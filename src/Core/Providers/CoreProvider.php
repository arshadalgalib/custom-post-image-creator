<?php
/**
 * Core module: lifecycle, i18n and housekeeping.
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
 * Wires the always-on hooks: translations, upgrade checks, cron heartbeats,
 * log/artifact retention and the admin capability install.
 */
final class CoreProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'core';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	public function boot( Container $c ) {
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'custom-post-image-creator', false, dirname( CPIC_PLUGIN_BASENAME ) . '/languages' );
			},
			1
		);

		// Schema upgrades must work on headless installs too.
		add_action(
			'admin_init',
			static function () use ( $c ) {
				$c->get( 'migrator' )->maybe_upgrade();
			},
			1
		);

		add_action(
			'cpic/cron/heartbeat',
			static function () use ( $c ) {
				$c->get( 'migrator' )->maybe_upgrade();
				$c->get( 'render' )->cleanup_artifacts();
				$c->get( 'logger' )->prune( (int) $c->get( 'settings' )->int( 'cpic_log_retention_days', 30 ) );
			}
		);

		// Keep the plugin's capabilities present after a role reset.
		add_action(
			'admin_init',
			static function () use ( $c ) {
				if ( ! get_option( 'cpic_roles_installed' ) ) {
					$c->get( 'capabilities' )->install_roles();
					update_option( 'cpic_roles_installed', CPIC_VERSION, false );
				}
			},
			2
		);
	}
}
