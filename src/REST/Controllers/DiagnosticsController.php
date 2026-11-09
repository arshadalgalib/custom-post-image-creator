<?php
/**
 * Diagnostics REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/diagnostics`
 *
 * Admin-only: this is where server configuration leaks would hurt most, so the
 * report is trimmed before it leaves the server.
 */
final class DiagnosticsController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/diagnostics';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$system = $this->requires( 'cpic_manage_system' );

		$this->add(
			'',
			array( 'GET' => array( 'callback' => array( $this, 'report' ), 'permission' => $system ) )
		);

		$this->add(
			'/health',
			array( 'GET' => array( 'callback' => array( $this, 'health' ), 'permission' => $system ) )
		);

		$this->add(
			'/logs',
			array( 'GET' => array( 'callback' => array( $this, 'logs' ), 'permission' => $system ) )
		);

		$this->add(
			'/repair',
			array( 'POST' => array( 'callback' => array( $this, 'repair' ), 'permission' => $system ) )
		);

		$this->add(
			'/export',
			array( 'GET' => array( 'callback' => array( $this, 'export' ), 'permission' => $system ) )
		);
	}

	/**
	 * Full report.
	 *
	 * @return \WP_REST_Response
	 */
	public function report() {
		return $this->ok( $this->sanitise( $this->container->get( 'health' )->report() ) );
	}

	/**
	 * Short health payload.
	 *
	 * @return \WP_REST_Response
	 */
	public function health() {
		return $this->ok( $this->container->get( 'health' )->summary() );
	}

	/**
	 * Recent log entries.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function logs( $request ) {
		return $this->ok(
			array(
				'logs' => $this->container->get( 'logger' )->recent(
					array(
						'per'    => $this->param_int( $request, 'per', 50 ),
						'level'  => $this->param( $request, 'level' ),
						'type'   => $this->param( $request, 'type' ),
					)
				),
			)
		);
	}

	/**
	 * Re-run the installer and reseed defaults. Safe to call repeatedly.
	 *
	 * @return \WP_REST_Response
	 */
	public function repair() {
		$migrator = $this->container->get( 'migrator' );
		$result   = $migrator->install();

		$this->container->get( 'settings' )->ensure_defaults();
		$this->container->get( 'brands' )->ensure_default();
		$this->container->get( 'starter_library' )->maybe_seed();

		return $this->ok(
			array(
				'migration' => $result,
				'tables'    => $migrator->verify_tables(),
			)
		);
	}

	/**
	 * Export settings + templates as one JSON bundle (secrets excluded).
	 *
	 * @return \WP_REST_Response
	 */
	public function export() {
		return $this->ok(
			array(
				'exported_at' => current_time( 'mysql' ),
				'settings'    => $this->container->get( 'settings' )->export(),
				'templates'   => $this->container->get( 'template_importer' )->export_all(),
			)
		);
	}

	/**
	 * Remove absolute server paths from a report.
	 *
	 * @param array<string,mixed> $report Report.
	 * @return array<string,mixed>
	 */
	private function sanitise( array $report ) {
		$walk = function ( $value ) use ( &$walk ) {
			if ( is_array( $value ) ) {
				$out = array();
				foreach ( $value as $key => $item ) {
					if ( in_array( $key, array( 'path', 'file_path', 'basedir', 'dir' ), true ) ) {
						continue;
					}
					$out[ $key ] = $walk( $item );
				}
				return $out;
			}

			if ( is_string( $value ) && preg_match( '#^(/|[A-Za-z]:\\\\)#', $value ) ) {
				return '[path hidden]';
			}

			return $value;
		};

		return $walk( $report );
	}
}
