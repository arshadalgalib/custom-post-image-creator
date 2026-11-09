<?php
/**
 * Template service: CRUD, revisions, migration, defaults.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Templates;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates the template repository, schema and version history.
 */
final class TemplateManager {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Schema helper.
	 *
	 * @return TemplateSchema
	 */
	public function schema() {
		return $this->container->get( 'template_schema' );
	}

	/**
	 * Repository.
	 *
	 * @return \CPIC\Database\Repositories\TemplateRepository
	 */
	public function repo() {
		return $this->container->get( 'templates' );
	}

	/**
	 * Hydrate a raw row into an API-friendly template.
	 *
	 * @param array<string,mixed>|null $row Row.
	 * @return array<string,mixed>|null
	 */
	public function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}

		// Idempotent: a row may already be hydrated (config holds the document).
		$document = is_array( $row['config'] ) ? $row['config'] : json_decode( (string) $row['config'], true );

		if ( ! is_array( $document ) ) {
			$document = TemplateSchema::blank();
		}

		// Defensive: ensure the document is always valid when read.
		$validated = $this->schema()->validate( $document );
		$document  = $validated['document'];

		$row['id']         = (int) $row['id'];
		$row['is_default'] = (int) $row['is_default'];
		$row['is_active']  = (int) $row['is_active'];
		$row['is_favorite'] = isset( $row['is_favorite'] ) ? (int) $row['is_favorite'] : 0;
		$row['brand_id']   = isset( $row['brand_id'] ) ? (int) $row['brand_id'] : 0;
		$row['revision']   = isset( $row['revision'] ) ? (int) $row['revision'] : 1;
		if ( is_array( $row['tags'] ) ) {
			$row['tags'] = array_values( array_filter( array_map( 'trim', $row['tags'] ) ) );
		} else {
			$row['tags'] = ! empty( $row['tags'] ) ? array_values( array_filter( array_map( 'trim', explode( ',', (string) $row['tags'] ) ) ) ) : array();
		}
		$row['document']   = $document;
		$row['config']     = $document;
		$row['summary']    = $this->schema()->summary( $document );
		$row['edit_url']   = add_query_arg(
			array(
				'page'     => 'cpic-studio',
				'template' => (int) $row['id'],
			),
			admin_url( 'admin.php' )
		);

		unset( $row['config_json'] );

		return $row;
	}

	/**
	 * Fetch a template.
	 *
	 * @param int|string $id_or_slug Id or slug.
	 * @return array<string,mixed>|null
	 */
	public function get( $id_or_slug ) {
		$row = is_numeric( $id_or_slug )
			? $this->repo()->find( (int) $id_or_slug )
			: $this->repo()->find_by_slug( (string) $id_or_slug );

		return $this->hydrate( $row );
	}

	/**
	 * List templates.
	 *
	 * @param array<string,mixed> $args Filters.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( array $args = array() ) {
		$rows = $this->repo()->all( $args );

		return array_values( array_filter( array_map( array( $this, 'hydrate' ), $rows ) ) );
	}

	/**
	 * Create a template.
	 *
	 * @param array<string,mixed> $data {
	 *     @type string $name        Name.
	 *     @type string $slug        Slug.
	 *     @type array  $document    Design document.
	 *     @type string $description Description.
	 *     @type string $collection  Collection.
	 *     @type string $category    Category.
	 *     @type array  $tags        Tags.
	 *     @type int    $brand_id    Brand id.
	 *     @type bool   $is_default  Default.
	 *     @type int    $is_active   Active.
	 * }
	 * @return array<string,mixed>|\WP_Error
	 */
	public function create( array $data ) {
		$name = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';

		if ( '' === $name ) {
			return new \WP_Error( 'cpic_template_name', __( 'A template name is required.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		$document = isset( $data['document'] ) ? $data['document'] : TemplateSchema::blank();
		$validated = $this->schema()->validate( $document );

		if ( ! $validated['ok'] ) {
			return new \WP_Error( 'cpic_template_invalid', implode( ' ', $validated['errors'] ), array( 'status' => 400 ) );
		}

		$slug = isset( $data['slug'] ) && '' !== $data['slug'] ? sanitize_title( (string) $data['slug'] ) : sanitize_title( $name );
		$slug = $this->unique_slug( $slug );

		$is_default = ! empty( $data['is_default'] );

		$row = array(
			'name'           => $name,
			'slug'           => $slug,
			'config'         => wp_json_encode( $validated['document'] ),
			'description'    => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : '',
			'collection'     => isset( $data['collection'] ) ? sanitize_key( (string) $data['collection'] ) : 'general',
			'category'       => isset( $data['category'] ) ? sanitize_key( (string) $data['category'] ) : 'general',
			'tags'           => $this->tags_to_string( isset( $data['tags'] ) ? $data['tags'] : array() ),
			'brand_id'       => isset( $data['brand_id'] ) ? max( 0, (int) $data['brand_id'] ) : 0,
			'schema_version' => TemplateSchema::VERSION,
			'revision'       => 1,
			'preview_url'    => isset( $data['preview_url'] ) ? esc_url_raw( (string) $data['preview_url'] ) : '',
			'is_default'     => $is_default ? 1 : 0,
			'is_active'      => isset( $data['is_active'] ) ? ( ! empty( $data['is_active'] ) ? 1 : 0 ) : 1,
			'is_favorite'    => ! empty( $data['is_favorite'] ) ? 1 : 0,
			'status'         => isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'active',
			'created_by'     => get_current_user_id(),
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		);

		if ( $is_default ) {
			$this->repo()->clear_default();
		}

		$id = $this->repo()->insert( $row );

		if ( ! $id ) {
			return new \WP_Error( 'cpic_template_save', __( 'The template could not be saved.', 'custom-post-image-creator' ), array( 'status' => 500 ) );
		}

		$this->repo()->save_version( $id, 1, $row['config'], $name, get_current_user_id() );

		if ( $is_default ) {
			update_option( 'cpic_default_template_id', (int) $id );
		}

		$this->container->get( 'audit' )->record( 'template.created', 'template', $id, $name );
		$this->container->get( 'events' )->dispatch( 'template.created', array( 'template_id' => $id ) );

		return $this->get( $id );
	}

	/**
	 * Update a template (bumps revision + snapshots the previous version).
	 *
	 * @param int                 $id   Template id.
	 * @param array<string,mixed> $data Fields.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function update( $id, array $data ) {
		$existing = $this->get( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'cpic_template_missing', __( 'Template not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$update = array( 'updated_at' => current_time( 'mysql' ) );

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( (string) $data['name'] );
			if ( '' === $name ) {
				return new \WP_Error( 'cpic_template_name', __( 'A template name is required.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
			}
			$update['name'] = $name;
		}

		if ( isset( $data['slug'] ) && '' !== $data['slug'] ) {
			$slug = sanitize_title( (string) $data['slug'] );
			if ( $slug !== $existing['slug'] ) {
				$update['slug'] = $this->unique_slug( $slug, (int) $id );
			}
		}

		if ( isset( $data['document'] ) || isset( $data['config'] ) ) {
			$document  = isset( $data['document'] ) ? $data['document'] : $data['config'];
			$validated = $this->schema()->validate( $document );

			if ( ! $validated['ok'] ) {
				return new \WP_Error( 'cpic_template_invalid', implode( ' ', $validated['errors'] ), array( 'status' => 400 ) );
			}

			$update['config']         = wp_json_encode( $validated['document'] );
			$update['schema_version'] = TemplateSchema::VERSION;
		}

		foreach ( array( 'description' => 'sanitize_textarea_field', 'collection' => 'sanitize_key', 'category' => 'sanitize_key', 'status' => 'sanitize_key' ) as $field => $sanitizer ) {
			if ( isset( $data[ $field ] ) ) {
				$update[ $field ] = call_user_func( $sanitizer, (string) $data[ $field ] );
			}
		}

		if ( isset( $data['tags'] ) ) {
			$update['tags'] = $this->tags_to_string( $data['tags'] );
		}
		if ( isset( $data['brand_id'] ) ) {
			$update['brand_id'] = max( 0, (int) $data['brand_id'] );
		}
		if ( isset( $data['is_active'] ) ) {
			$update['is_active'] = ! empty( $data['is_active'] ) ? 1 : 0;
		}
		if ( isset( $data['is_favorite'] ) ) {
			$update['is_favorite'] = ! empty( $data['is_favorite'] ) ? 1 : 0;
		}
		if ( isset( $data['preview_url'] ) ) {
			$update['preview_url'] = esc_url_raw( (string) $data['preview_url'] );
		}
		if ( isset( $data['preview_artifact_id'] ) ) {
			$update['preview_artifact_id'] = max( 0, (int) $data['preview_artifact_id'] );
		}
		if ( ! empty( $data['is_default'] ) ) {
			$this->repo()->clear_default();
			$update['is_default'] = 1;
			update_option( 'cpic_default_template_id', (int) $id );
		}

		// Snapshot the outgoing version before writing (never lose a design).
		if ( isset( $update['config'] ) ) {
			$this->repo()->save_version( (int) $id, (int) $existing['revision'], wp_json_encode( $existing['document'] ), (string) $existing['name'], get_current_user_id() );
			$update['revision'] = $this->repo()->bump_revision( (int) $id );
		}

		$this->repo()->update( (int) $id, $update );

		$this->container->get( 'audit' )->record( 'template.updated', 'template', (int) $id, isset( $update['name'] ) ? $update['name'] : $existing['name'] );
		$this->container->get( 'events' )->dispatch(
			'template.updated',
			array(
				'template_id' => (int) $id,
				'revision'    => isset( $update['revision'] ) ? (int) $update['revision'] : (int) $existing['revision'],
			)
		);

		return $this->get( $id );
	}

	/**
	 * Duplicate a template.
	 *
	 * @param int    $id   Template id.
	 * @param string $name Optional new name.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function duplicate( $id, $name = '' ) {
		$existing = $this->get( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'cpic_template_missing', __( 'Template not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$new_name = '' !== $name
			? $name
			/* translators: %s: original template name */
			: sprintf( __( '%s (copy)', 'custom-post-image-creator' ), $existing['name'] );

		return $this->create(
			array(
				'name'        => $new_name,
				'document'    => $existing['document'],
				'description' => $existing['description'],
				'collection'  => $existing['collection'],
				'category'    => $existing['category'],
				'tags'        => $existing['tags'],
				'brand_id'    => $existing['brand_id'],
				'is_active'   => $existing['is_active'],
			)
		);
	}

	/**
	 * Archive instead of deleting (data safety).
	 *
	 * @param int $id Template id.
	 * @return bool
	 */
	public function archive( $id ) {
		$existing = $this->get( $id );
		if ( ! $existing ) {
			return false;
		}

		$this->repo()->update(
			(int) $id,
			array(
				'status'    => 'archived',
				'is_active' => 0,
			)
		);

		$this->container->get( 'audit' )->record( 'template.archived', 'template', (int) $id, $existing['name'] );
		$this->container->get( 'events' )->dispatch( 'template.archived', array( 'template_id' => (int) $id ) );

		return true;
	}

	/**
	 * Restore an archived template.
	 *
	 * @param int $id Template id.
	 * @return bool
	 */
	public function restore( $id ) {
		$this->repo()->update(
			(int) $id,
			array(
				'status'    => 'active',
				'is_active' => 1,
			)
		);
		$this->container->get( 'events' )->dispatch( 'template.restored', array( 'template_id' => (int) $id ) );
		return true;
	}

	/**
	 * Hard delete (only from the archive, and never the default template).
	 *
	 * @param int  $id          Template id.
	 * @param bool $force_delete Allow deleting non-archived templates.
	 * @return bool|\WP_Error
	 */
	public function delete( $id, $force_delete = false ) {
		$existing = $this->get( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'cpic_template_missing', __( 'Template not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		if ( ! empty( $existing['is_default'] ) ) {
			return new \WP_Error( 'cpic_template_default', __( 'Choose another default template before deleting this one.', 'custom-post-image-creator' ), array( 'status' => 409 ) );
		}

		if ( ! $force_delete && 'archived' !== $existing['status'] ) {
			return new \WP_Error( 'cpic_template_not_archived', __( 'Archive the template first, or pass force=true.', 'custom-post-image-creator' ), array( 'status' => 409 ) );
		}

		$this->container->get( 'mappings' )->remove_for_template( (int) $id );
		$this->repo()->delete_versions( (int) $id );
		$this->repo()->delete( (int) $id );

		$this->container->get( 'audit' )->record( 'template.deleted', 'template', (int) $id, $existing['name'] );
		$this->container->get( 'events' )->dispatch( 'template.deleted', array( 'template_id' => (int) $id ) );

		return true;
	}

	/**
	 * Set the default template.
	 *
	 * @param int $id Template id.
	 * @return bool
	 */
	public function set_default( $id ) {
		$existing = $this->get( $id );
		if ( ! $existing ) {
			return false;
		}

		$this->repo()->set_default( (int) $id );
		update_option( 'cpic_default_template_id', (int) $id );

		$this->container->get( 'events' )->dispatch( 'template.default_changed', array( 'template_id' => (int) $id ) );

		return true;
	}

	/**
	 * The default template row (falls back to the first active template).
	 *
	 * @return array<string,mixed>|null
	 */
	public function default_template() {
		$configured = (int) get_option( 'cpic_default_template_id', 0 );
		if ( $configured ) {
			$template = $this->get( $configured );
			if ( $template && ! empty( $template['is_active'] ) ) {
				return $template;
			}
		}

		$rows = $this->repo()->select( array( 'is_active' => 1 ), 'is_default DESC, id ASC', 1 );

		return $rows ? $this->hydrate( $rows[0] ) : null;
	}

	/**
	 * Resolve the template for a post: category routing first, then default.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>|null
	 */
	public function for_post( $post_id ) {
		$post_id = (int) $post_id;

		/**
		 * Short-circuit template resolution.
		 *
		 * @param array|null $template Template or null.
		 * @param int        $post_id  Post id.
		 */
		$forced = apply_filters( 'cpic/template/resolve', null, $post_id );
		if ( is_array( $forced ) ) {
			return $this->hydrate( $forced );
		}

		$override = (int) get_post_meta( $post_id, '_cpic_template_id', true );
		if ( $override ) {
			$template = $this->get( $override );
			if ( $template && ! empty( $template['is_active'] ) ) {
				return $template;
			}
		}

		$mappings = $this->container->get( 'mappings' );
		$terms    = get_the_category( $post_id );
		$best     = null;
		$best_pri = PHP_INT_MAX;

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$mapping = $mappings->for_term( (int) $term->term_id );
				if ( ! $mapping ) {
					continue;
				}
				$priority = (int) $mapping['priority'];
				if ( $priority < $best_pri ) {
					$template = $this->get( (int) $mapping['template_id'] );
					if ( $template && ! empty( $template['is_active'] ) ) {
						$best     = $template;
						$best_pri = $priority;
					}
				}
			}
		}

		if ( $best ) {
			return $best;
		}

		return $this->default_template();
	}

	/**
	 * Version history.
	 *
	 * @param int $id Template id.
	 * @return array<int,array<string,mixed>>
	 */
	public function history( $id ) {
		return $this->repo()->versions( (int) $id );
	}

	/**
	 * Restore a specific revision (creates a new revision on top).
	 *
	 * @param int $id         Template id.
	 * @param int $version_id Version row id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function restore_version( $id, $version_id ) {
		$version = $this->repo()->version( (int) $version_id );
		if ( ! $version || (int) $version['template_id'] !== (int) $id ) {
			return new \WP_Error( 'cpic_version_missing', __( 'That version could not be found.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$document = json_decode( (string) $version['config'], true );

		return $this->update(
			(int) $id,
			array(
				'document' => is_array( $document ) ? $document : TemplateSchema::blank(),
				'name'     => (string) $version['name'],
			)
		);
	}

	/**
	 * Migrate every legacy config in the database to the 7.0 document schema.
	 *
	 * @return array{scanned:int,updated:int}
	 */
	public function migrate_all_configs() {
		$rows    = $this->repo()->select( array(), 'id ASC' );
		$scanned = 0;
		$updated = 0;

		foreach ( $rows as $row ) {
			$scanned++;
			$raw = json_decode( (string) $row['config'], true );

			if ( ! is_array( $raw ) ) {
				$raw = array();
			}

			$is_v7 = isset( $raw['schema'] ) && version_compare( (string) $raw['schema'], '7.0', '>=' );

			$document = $is_v7
				? $this->schema()->validate( $raw )['document']
				: $this->schema()->migrate_legacy( $raw );

			$encoded = wp_json_encode( $document );

			if ( $encoded !== (string) $row['config'] || empty( $row['schema_version'] ) || '7.0' !== $row['schema_version'] ) {
				$this->repo()->update(
					(int) $row['id'],
					array(
						'config'         => $encoded,
						'schema_version' => TemplateSchema::VERSION,
						'updated_at'     => current_time( 'mysql' ),
					)
				);
				$updated++;
			}
		}

		return array(
			'scanned' => $scanned,
			'updated' => $updated,
		);
	}

	/**
	 * Snapshot every template into the version table (pre-migration safety).
	 *
	 * @param string $label Snapshot label recorded in the version name.
	 * @return int Number of snapshots created.
	 */
	public function snapshot_all( $label = '' ) {
		$rows  = $this->repo()->select( array(), 'id ASC' );
		$count = 0;

		foreach ( $rows as $row ) {
			$revision = isset( $row['revision'] ) ? (int) $row['revision'] : 1;
			$existing = $this->repo()->versions( (int) $row['id'], 200 );

			$already = false;
			foreach ( $existing as $version ) {
				if ( (int) $version['revision'] === $revision ) {
					$already = true;
					break;
				}
			}

			if ( $already ) {
				continue;
			}

			$name = $label ? sprintf( '%s [%s]', $row['name'], $label ) : (string) $row['name'];
			$this->repo()->save_version( (int) $row['id'], $revision, (string) $row['config'], $name, get_current_user_id() );
			$count++;
		}

		return $count;
	}

	/**
	 * Ensure a slug is unique.
	 *
	 * @param string $slug    Base slug.
	 * @param int    $exclude Id to exclude.
	 * @return string
	 */
	public function unique_slug( $slug, $exclude = 0 ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			$slug = 'template';
		}

		$candidate = $slug;
		$i         = 2;

		while ( true ) {
			$found = $this->repo()->find_by_slug( $candidate );
			if ( ! $found || (int) $found['id'] === (int) $exclude ) {
				return $candidate;
			}
			$candidate = $slug . '-' . $i;
			$i++;
			if ( $i > 200 ) {
				return $slug . '-' . substr( md5( (string) microtime( true ) ), 0, 6 );
			}
		}
	}

	/**
	 * Convert tags to a comma-separated string.
	 *
	 * @param mixed $tags Array or string.
	 * @return string
	 */
	public function tags_to_string( $tags ) {
		if ( is_string( $tags ) ) {
			$tags = explode( ',', $tags );
		}
		if ( ! is_array( $tags ) ) {
			return '';
		}
		$tags = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', $tags ) ) );

		return implode( ',', array_unique( $tags ) );
	}

	/**
	 * Library filter metadata.
	 *
	 * @return array<string,mixed>
	 */
	public function library_meta() {
		$repo = $this->repo();

		return array(
			'collections' => $repo->collections(),
			'categories'  => $repo->categories(),
			'total'       => $repo->count(),
			'favorites'   => $repo->count( array( 'is_favorite' => 1 ) ),
			'archived'    => $repo->count( array( 'status' => 'archived' ) ),
		);
	}
}
