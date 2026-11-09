<?php
/**
 * Settings REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/settings`
 */
final class SettingsController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/settings';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$manage = $this->requires( 'cpic_manage_settings' );

		$this->add(
			'',
			array(
				'GET'  => array( 'callback' => array( $this, 'index' ), 'permission' => $manage ),
				'POST' => array( 'callback' => array( $this, 'update' ), 'permission' => $manage ),
			)
		);

		$this->add(
			'/public',
			array( 'GET' => array( 'callback' => array( $this, 'public_view' ) ) )
		);

		$this->add(
			'/schema',
			array( 'GET' => array( 'callback' => array( $this, 'schema' ), 'permission' => $manage ) )
		);
	}

	/**
	 * All settings (secrets redacted).
	 *
	 * @return \WP_REST_Response
	 */
	public function index() {
		$settings = $this->container->get( 'settings' );

		return $this->ok(
			array(
				'settings' => $settings->all( true ),
				'sections' => $settings->sections(),
			)
		);
	}

	/**
	 * The settings schema, for rendering the form.
	 *
	 * @return \WP_REST_Response
	 */
	public function schema() {
		$settings = $this->container->get( 'settings' );

		$fields = array();
		foreach ( $settings->definitions() as $key => $definition ) {
			$fields[ $key ] = array(
				'type'    => $definition['type'],
				'default' => 'secret' === $definition['type'] ? '' : $definition['default'],
				'section' => $definition['section'],
				'label'   => isset( $definition['label'] ) ? $definition['label'] : $key,
			);
		}

		return $this->ok(
			array(
				'fields'   => $fields,
				'sections' => $settings->sections(),
			)
		);
	}

	/**
	 * Settings the studio needs (non-sensitive, safe for the editor role).
	 *
	 * @return \WP_REST_Response
	 */
	public function public_view() {
		$settings = $this->container->get( 'settings' );

		$keys = array(
			'cpic_default_format',
			'cpic_renderer',
			'cpic_allow_server_fallback',
			'cpic_max_upload_mb',
			'cpic_image_quality',
			'cpic_comment_message',
			'cpic_auto_comment',
			'cpic_first_comment_link',
			'cpic_ai_enabled',
			'cpic_enabled',
		);

		$out = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = $settings->get( $key );
		}

		return $this->ok(
			array(
				'settings'   => $out,
				'formats'    => \CPIC\Rendering\Formats::all(),
				'brands'     => $this->brand_list(),
				'providers'  => $this->container->get( 'publishers' )->descriptors(),
				'can'        => array(
					'publish'   => $this->container->get( 'capabilities' )->can( 'cpic_publish' ),
					'brands'    => $this->container->get( 'capabilities' )->can( 'cpic_manage_brands' ),
					'accounts'  => $this->container->get( 'capabilities' )->can( 'cpic_manage_accounts' ),
					'use_ai'    => $this->container->get( 'capabilities' )->can( 'cpic_use_ai' ),
				),
			)
		);
	}

	/**
	 * Update settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update( $request ) {
		$values = $request->get_param( 'settings' );

		if ( ! is_array( $values ) ) {
			return $this->fail( new \WP_Error( 'cpic_bad_payload', __( 'No settings were supplied.', 'custom-post-image-creator' ), array( 'status' => 400 ) ) );
		}

		$settings = $this->container->get( 'settings' );
		$result   = $settings->bulk_set( $values );

		$settings->flush_cache();

		$this->container->get( 'audit' )->record(
			'settings.updated',
			'system',
			0,
			sprintf(
				/* translators: %d: number of settings */
				__( 'Updated %d setting(s)', 'custom-post-image-creator' ),
				(int) $result['updated']
			),
			// Never log values: some are secrets.
			array( 'keys' => array_keys( $values ) )
		);

		return $this->ok( $result );
	}

	/**
	 * Brand list for pickers.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function brand_list() {
		$out = array();

		foreach ( $this->container->get( 'brands' )->select( array(), 'is_default DESC, name ASC' ) as $row ) {
			$brand = $this->container->get( 'brands' )->hydrate( $row );

			$out[] = array(
				'id'            => (int) $brand['id'],
				'name'          => (string) $brand['name'],
				'is_default'    => (int) $brand['is_default'],
				'primary_color' => (string) $brand['primary_color'],
				'accent_color'  => (string) $brand['accent_color'],
				'logo_primary'  => (string) $brand['logo_primary'],
			);
		}

		return $out;
	}
}
