<?php
/**
 * Templates REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/templates`
 */
final class TemplatesController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/templates';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$this->add(
			'',
			array(
				'GET'  => array( 'callback' => array( $this, 'index' ) ),
				'POST' => array(
					'callback'   => array( $this, 'create' ),
					'permission' => $this->requires( 'cpic_manage_templates' ),
				),
			)
		);

		$this->add(
			'/(?P<id>\d+)',
			array(
				'GET'    => array( 'callback' => array( $this, 'show' ) ),
				'PUT'    => array(
					'callback'   => array( $this, 'update' ),
					'permission' => $this->requires( 'cpic_manage_templates' ),
				),
				'DELETE' => array(
					'callback'   => array( $this, 'destroy' ),
					'permission' => $this->requires( 'cpic_manage_templates' ),
				),
			)
		);

		$this->add(
			'/(?P<id>\d+)/duplicate',
			array(
				'POST' => array(
					'callback'   => array( $this, 'duplicate' ),
					'permission' => $this->requires( 'cpic_manage_templates' ),
				),
			)
		);

		$this->add(
			'/(?P<id>\d+)/default',
			array(
				'POST' => array(
					'callback'   => array( $this, 'make_default' ),
					'permission' => $this->requires( 'cpic_manage_brands' ),
				),
			)
		);

		$this->add(
			'/(?P<id>\d+)/versions',
			array(
				'GET' => array( 'callback' => array( $this, 'versions' ) ),
			)
		);

		$this->add(
			'/(?P<id>\d+)/versions/(?P<version>\d+)/restore',
			array(
				'POST' => array(
					'callback'   => array( $this, 'restore_version' ),
					'permission' => $this->requires( 'cpic_manage_templates' ),
				),
			)
		);

		$this->add(
			'/import',
			array(
				'POST' => array(
					'callback'   => array( $this, 'import' ),
					'permission' => $this->requires( 'cpic_manage_templates' ),
				),
			)
		);

		$this->add(
			'/(?P<id>\d+)/export',
			array(
				'GET' => array( 'callback' => array( $this, 'export' ) ),
			)
		);

		$this->add(
			'/preview',
			array(
				'POST' => array( 'callback' => array( $this, 'preview_plan' ) ),
			)
		);
	}

	/**
	 * List templates.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index( $request ) {
		$manager = $this->container->get( 'template_manager' );

		$templates = $manager->all(
			array(
				'collection' => $this->param( $request, 'collection' ),
				'category'   => $this->param( $request, 'category' ),
				'status'     => $this->param( $request, 'status' ),
				'search'     => $this->param( $request, 'search' ),
				'active'     => $this->param_bool( $request, 'active' ),
				'favorites'  => $this->param_bool( $request, 'favorites' ),
				'brand_id'   => $this->param_int( $request, 'brand_id' ),
			)
		);

		return $this->ok(
			array(
				'templates' => $templates,
				'meta'      => $manager->library_meta(),
				'collections' => $this->container->get( 'starter_library' )->collections(),
			)
		);
	}

	/**
	 * One template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function show( $request ) {
		$template = $this->container->get( 'template_manager' )->get( $this->param_int( $request, 'id' ) );

		if ( ! $template ) {
			return $this->fail( new \WP_Error( 'cpic_template_missing', __( 'Template not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		return $this->ok( $template );
	}

	/**
	 * Create.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create( $request ) {
		$result = $this->container->get( 'template_manager' )->create(
			array(
				'name'        => $this->param( $request, 'name' ),
				'slug'        => $this->param( $request, 'slug' ),
				'document'    => $request->get_param( 'document' ),
				'config'      => $request->get_param( 'config' ),
				'description' => $this->param( $request, 'description' ),
				'collection'  => $this->param( $request, 'collection', 'general' ),
				'category'    => $this->param( $request, 'category', 'general' ),
				'tags'        => $request->get_param( 'tags' ),
				'brand_id'    => $this->param_int( $request, 'brand_id' ),
				'is_default'  => $this->param_bool( $request, 'is_default' ),
			)
		);

		return $this->maybe( $result, 201 );
	}

	/**
	 * Update.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update( $request ) {
		$data = array();

		foreach ( array( 'name', 'slug', 'description', 'collection', 'category', 'status' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$data[ $key ] = $request->get_param( $key );
			}
		}

		if ( null !== $request->get_param( 'document' ) ) {
			$data['document'] = $request->get_param( 'document' );
		}
		if ( null !== $request->get_param( 'tags' ) ) {
			$data['tags'] = $request->get_param( 'tags' );
		}
		if ( null !== $request->get_param( 'brand_id' ) ) {
			$data['brand_id'] = $this->param_int( $request, 'brand_id' );
		}
		if ( null !== $request->get_param( 'is_active' ) ) {
			$data['is_active'] = $this->param_bool( $request, 'is_active' );
		}
		if ( null !== $request->get_param( 'is_favorite' ) ) {
			$data['is_favorite'] = $this->param_bool( $request, 'is_favorite' );
		}

		return $this->maybe( $this->container->get( 'template_manager' )->update( $this->param_int( $request, 'id' ), $data ) );
	}

	/**
	 * Delete.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function destroy( $request ) {
		return $this->maybe(
			$this->container->get( 'template_manager' )->delete(
				$this->param_int( $request, 'id' ),
				$this->param_bool( $request, 'force' )
			)
		);
	}

	/**
	 * Duplicate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function duplicate( $request ) {
		return $this->maybe(
			$this->container->get( 'template_manager' )->duplicate(
				$this->param_int( $request, 'id' ),
				$this->param( $request, 'name' )
			),
			201
		);
	}

	/**
	 * Set as default.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function make_default( $request ) {
		$result = $this->container->get( 'template_manager' )->set_default( $this->param_int( $request, 'id' ) );

		return $result
			? $this->ok( array( 'default_template_id' => $this->param_int( $request, 'id' ) ) )
			: $this->fail( new \WP_Error( 'cpic_template_missing', __( 'Template not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
	}

	/**
	 * Version history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function versions( $request ) {
		$manager = $this->container->get( 'template_manager' );
		$id      = $this->param_int( $request, 'id' );

		if ( ! $manager->get( $id ) ) {
			return $this->fail( new \WP_Error( 'cpic_template_missing', __( 'Template not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) ) );
		}

		$versions = array();
		foreach ( $manager->history( $id ) as $version ) {
			$versions[] = array(
				'id'         => (int) $version['id'],
				'revision'   => (int) $version['revision'],
				'name'       => (string) $version['name'],
				'created_at' => (string) $version['created_at'],
				'created_by' => (int) $version['created_by'],
			);
		}

		return $this->ok( array( 'versions' => $versions ) );
	}

	/**
	 * Restore a version.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function restore_version( $request ) {
		return $this->maybe(
			$this->container->get( 'template_manager' )->restore_version(
				$this->param_int( $request, 'id' ),
				$this->param_int( $request, 'version' )
			)
		);
	}

	/**
	 * Import a template JSON payload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function import( $request ) {
		$payload = $request->get_param( 'template' );

		if ( null === $payload ) {
			$payload = $request->get_param( 'json' );
		}

		return $this->maybe(
			$this->container->get( 'template_importer' )->import(
				$payload,
				array(
					'collection'   => $this->param( $request, 'collection' ),
					'category'     => $this->param( $request, 'category' ),
					'suffix_import' => true,
				)
			),
			201
		);
	}

	/**
	 * Export a template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function export( $request ) {
		return $this->maybe(
			$this->container->get( 'template_importer' )->export(
				$this->param_int( $request, 'id' ),
				$this->param_bool( $request, 'versions' )
			)
		);
	}

	/**
	 * Build a plan for an unsaved document (live studio preview).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function preview_plan( $request ) {
		$schema   = $this->container->get( 'template_schema' );
		$validated = $schema->validate( $request->get_param( 'document' ) );

		if ( ! $validated['ok'] ) {
			return $this->fail( new \WP_Error( 'cpic_template_invalid', implode( ' ', $validated['errors'] ), array( 'status' => 400 ) ) );
		}

		$plan = $this->container->get( 'render_plan' )->build(
			array(
				'document'  => $validated['document'],
				'format'    => $this->param( $request, 'format', 'square' ),
				'post_id'   => $this->param_int( $request, 'post_id' ),
				'overrides' => (array) $request->get_param( 'overrides' ),
				'scale'     => $this->param_int( $request, 'scale', 1 ),
			)
		);

		return $this->maybe( $plan );
	}
}
