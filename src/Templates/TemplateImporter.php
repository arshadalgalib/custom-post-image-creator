<?php
/**
 * Template import / export.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Templates;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Templates travel between sites as a small JSON document.
 *
 * Import is deliberately defensive: the file is size-limited, validated
 * through TemplateSchema (which strips scripts and clamps geometry), and
 * imported templates never become the default — an importer should not be able
 * to silently change how every post on the site looks.
 */
final class TemplateImporter {

	/**
	 * Export format version.
	 */
	const FORMAT = 'cpic-template/1';

	/**
	 * Maximum import size (bytes).
	 */
	const MAX_BYTES = 2097152;

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
	 * Export one template.
	 *
	 * @param int  $template_id Template id.
	 * @param bool $include_versions Include version history.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function export( $template_id, $include_versions = false ) {
		$template = $this->container->get( 'template_manager' )->get( (int) $template_id );

		if ( ! $template ) {
			return new \WP_Error( 'cpic_template_missing', __( 'Template not found.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$payload = array(
			'format'    => self::FORMAT,
			'version'   => CPIC_VERSION,
			'exported'  => current_time( 'mysql' ),
			'site'      => (string) home_url( '/' ),
			'template'  => array(
				'name'        => $template['name'],
				'slug'        => $template['slug'],
				'description' => $template['description'],
				'collection'  => $template['collection'],
				'category'    => $template['category'],
				'tags'        => $template['tags'],
				'document'    => $template['document'],
			),
		);

		if ( $include_versions ) {
			$payload['versions'] = array();

			foreach ( $this->container->get( 'template_manager' )->history( (int) $template_id ) as $version ) {
				$payload['versions'][] = array(
					'revision'   => (int) $version['revision'],
					'name'       => (string) $version['name'],
					'config'     => json_decode( (string) $version['config'], true ),
					'created_at' => (string) $version['created_at'],
				);
			}
		}

		return $payload;
	}

	/**
	 * Export every template as one bundle.
	 *
	 * @return array<string,mixed>
	 */
	public function export_all() {
		$templates = array();

		foreach ( $this->container->get( 'template_manager' )->all() as $template ) {
			$templates[] = array(
				'name'        => $template['name'],
				'slug'        => $template['slug'],
				'description' => $template['description'],
				'collection'  => $template['collection'],
				'category'    => $template['category'],
				'tags'        => $template['tags'],
				'document'    => $template['document'],
			);
		}

		return array(
			'format'    => self::FORMAT,
			'version'   => CPIC_VERSION,
			'exported'  => current_time( 'mysql' ),
			'site'      => (string) home_url( '/' ),
			'templates' => $templates,
		);
	}

	/**
	 * Import a payload (array or JSON string).
	 *
	 * @param mixed               $payload Raw payload.
	 * @param array<string,mixed> $args    { collection, category, activate, prefix }.
	 * @return array<string,mixed>|\WP_Error { imported[], skipped[], errors[] }
	 */
	public function import( $payload, array $args = array() ) {
		if ( is_string( $payload ) ) {
			if ( strlen( $payload ) > self::MAX_BYTES ) {
				return new \WP_Error( 'cpic_import_large', __( 'That file is too large to import.', 'custom-post-image-creator' ), array( 'status' => 413 ) );
			}

			$decoded = json_decode( $payload, true );

			if ( null === $decoded ) {
				return new \WP_Error( 'cpic_import_json', __( 'The file is not valid JSON.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
			}

			$payload = $decoded;
		}

		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'cpic_import_shape', __( 'The import file has an unexpected shape.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		$format = isset( $payload['format'] ) ? (string) $payload['format'] : '';

		if ( self::FORMAT !== $format ) {
			return new \WP_Error(
				'cpic_import_format',
				__( 'This file was not exported by Custom Post Image Creator 7.0.', 'custom-post-image-creator' ),
				array( 'status' => 400 )
			);
		}

		$entries = array();

		if ( isset( $payload['template'] ) && is_array( $payload['template'] ) ) {
			$entries[] = $payload['template'];
		}

		if ( isset( $payload['templates'] ) && is_array( $payload['templates'] ) ) {
			foreach ( $payload['templates'] as $entry ) {
				if ( is_array( $entry ) ) {
					$entries[] = $entry;
				}
			}
		}

		if ( empty( $entries ) ) {
			return new \WP_Error( 'cpic_import_empty', __( 'The file contains no templates.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		$manager  = $this->container->get( 'template_manager' );
		$schema   = $this->container->get( 'template_schema' );

		$imported = array();
		$skipped  = array();
		$errors   = array();

		foreach ( $entries as $entry ) {
			$name = isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : '';

			if ( '' === $name ) {
				$errors[] = __( 'A template in the file has no name.', 'custom-post-image-creator' );
				continue;
			}

			$raw = isset( $entry['document'] ) ? $entry['document'] : ( isset( $entry['config'] ) ? $entry['config'] : array() );

			// Accept both 7.0 documents and 6.x configs.
			$is_v7 = is_array( $raw ) && isset( $raw['schema'] ) && version_compare( (string) $raw['schema'], '7.0', '>=' );

			$document = $is_v7
				? $schema->validate( $raw )['document']
				: $schema->migrate_legacy( is_array( $raw ) ? $raw : array() );

			$result = $manager->create(
				array(
					'name'        => $name,
					'slug'        => $this->unique_slug( $manager, isset( $entry['slug'] ) ? (string) $entry['slug'] : $name, ! empty( $args['suffix_import'] ) ),
					'document'    => $document,
					'description' => isset( $entry['description'] ) ? (string) $entry['description'] : '',
					'collection'  => isset( $args['collection'] ) && '' !== $args['collection']
						? (string) $args['collection']
						: ( isset( $entry['collection'] ) ? (string) $entry['collection'] : 'general' ),
					'category'    => isset( $args['category'] ) && '' !== $args['category']
						? (string) $args['category']
						: ( isset( $entry['category'] ) ? (string) $entry['category'] : 'general' ),
					'tags'        => isset( $entry['tags'] ) ? $entry['tags'] : array(),
					// Never import a default: that is an explicit admin decision.
					'is_default'  => false,
					'is_active'   => 1,
				)
			);

			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}

			$imported[] = array(
				'id'        => (int) $result['id'],
				'name'      => $result['name'],
				'slug'      => $result['slug'],
				'revisions' => 1,
			);

			// Optional version history (best effort, never fails the import).
			if ( ! empty( $payload['versions'] ) && is_array( $payload['versions'] ) ) {
				foreach ( $payload['versions'] as $version ) {
					if ( ! is_array( $version ) || empty( $version['config'] ) ) {
						continue;
					}
					$manager->repo()->save_version(
						(int) $result['id'],
						isset( $version['revision'] ) ? (int) $version['revision'] : 1,
						wp_json_encode( $schema->validate( $version['config'] )['document'] ),
						isset( $version['name'] ) ? (string) $version['name'] : (string) $result['name'],
						get_current_user_id()
					);
				}
			}
		}

		$this->container->get( 'audit' )->record(
			'template.imported',
			'system',
			0,
			sprintf(
				/* translators: 1: imported count, 2: skipped count */
				__( 'Imported %1$d template(s), skipped %2$d', 'custom-post-image-creator' ),
				count( $imported ),
				count( $skipped )
			)
		);

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
			'count'    => count( $imported ),
		);
	}

	/**
	 * Slug that will not collide with an existing template.
	 *
	 * @param \CPIC\Templates\TemplateManager $manager Manager.
	 * @param string                          $slug    Base slug.
	 * @param bool                            $suffix  Append a source suffix.
	 * @return string
	 */
	private function unique_slug( $manager, $slug, $suffix = false ) {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			$slug = 'imported-template';
		}

		if ( $suffix ) {
			$slug .= '-imported';
		}

		return $manager->unique_slug( $slug );
	}

	/**
	 * Download an export as a JSON string.
	 *
	 * @param array<string,mixed> $payload Export payload.
	 * @return string
	 */
	public function to_json( array $payload ) {
		return (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
