<?php
/**
 * Artifact storage: the canonical rendered image per (post, design, format).
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Rendering;

use CPIC\Core\Container;
use CPIC\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores uploaded browser renders as files + rows, computes checksums, and
 * exposes the *single* artifact the publishing pipeline is allowed to use.
 */
final class ArtifactManager {

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
	 * Repository.
	 *
	 * @return \CPIC\Database\Repositories\ArtifactRepository
	 */
	public function repo() {
		return $this->container->get( 'artifacts' );
	}

	/**
	 * Directory for artifact files.
	 *
	 * @return array{path:string,url:string}|\WP_Error
	 */
	public function directory() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'cpic_uploads', (string) $uploads['error'] );
		}

		$path = trailingslashit( $uploads['basedir'] ) . 'cpic-artifacts';
		$url  = trailingslashit( $uploads['baseurl'] ) . 'cpic-artifacts';

		if ( ! file_exists( $path ) ) {
			wp_mkdir_p( $path );
		}

		if ( ! is_dir( $path ) || ! is_writable( $path ) ) {
			return new \WP_Error( 'cpic_artifact_dir', __( 'The artifact directory is not writable. Check upload permissions.', 'custom-post-image-creator' ) );
		}

		// Deny direct PHP execution inside the artifact folder.
		$htaccess = $path . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "php_flag engine off\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<FilesMatch \"\\.(png|jpg|jpeg|webp)$\">\n<IfModule mod_authz_core.c>\nRequire all granted\n</IfModule>\n</FilesMatch>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		return array(
			'path' => $path,
			'url'  => $url,
		);
	}

	/**
	 * Store a browser-rendered PNG.
	 *
	 * @param array<string,mixed> $args {
	 *     @type int    $post_id    Post id (0 allowed for template previews).
	 *     @type int    $template_id Template id.
	 *     @type string $format     Format key.
	 *     @type array  $document   Design document that produced the image.
	 *     @type string $binary     Raw PNG bytes.
	 *     @type string $renderer   Renderer id.
	 *     @type array  $meta       Extra metadata.
	 *     @type bool   $attach     Create a media library attachment.
	 * }
	 * @return array<string,mixed>|\WP_Error
	 */
	public function store_browser_render( array $args ) {
		$binary = isset( $args['binary'] ) ? (string) $args['binary'] : '';

		if ( '' === $binary ) {
			return new \WP_Error( 'cpic_empty_render', __( 'The rendered image was empty.', 'custom-post-image-creator' ) );
		}

		if ( strlen( $binary ) > 32 * MB_IN_BYTES ) {
			return new \WP_Error( 'cpic_render_too_large', __( 'The rendered image is too large to store.', 'custom-post-image-creator' ) );
		}

		$info = @getimagesizefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! $info || IMAGETYPE_PNG !== $info[2] ) {
			return new \WP_Error( 'cpic_not_png', __( 'Only PNG renders are accepted.', 'custom-post-image-creator' ) );
		}

		$width  = (int) $info[0];
		$height = (int) $info[1];

		if ( $width < 50 || $height < 50 || $width > 4000 || $height > 4000 ) {
			return new \WP_Error( 'cpic_bad_render_size', __( 'The rendered image dimensions are outside the supported range.', 'custom-post-image-creator' ) );
		}

		$dir = $this->directory();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$post_id     = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$template_id = isset( $args['template_id'] ) ? (int) $args['template_id'] : 0;
		$format      = isset( $args['format'] ) ? sanitize_key( (string) $args['format'] ) : 'square';
		$document    = isset( $args['document'] ) && is_array( $args['document'] ) ? $args['document'] : array();

		$checksum = hash( 'sha256', $binary );

		$signature = $this->container->get( 'render_signature' )->compute( $document, $post_id, $format, isset( $args['meta'] ) ? (array) $args['meta'] : array() );

		// Cache hit: identical signature + checksum → reuse the stored row.
		$existing = $this->repo()->find_by_signature( $post_id, $signature['signature'], $format );
		if ( $existing && $existing['checksum_sha256'] === $checksum && file_exists( (string) $existing['file_path'] ) ) {
			return $this->hydrate( $existing );
		}

		$template = $template_id ? $this->container->get( 'templates' )->find( $template_id ) : null;

		$filename = sprintf(
			'cpic-%s-%s-%s-%s.png',
			$post_id ? 'post' . $post_id : 'preview',
			$format,
			substr( $signature['signature'], 0, 10 ),
			gmdate( 'Ymd' )
		);

		$path = trailingslashit( $dir['path'] ) . $filename;

		$written = file_put_contents( $path, $binary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( false === $written ) {
			return new \WP_Error( 'cpic_artifact_write', __( 'The rendered image could not be saved.', 'custom-post-image-creator' ) );
		}

		$post       = $post_id ? get_post( $post_id ) : null;
		$brand_id   = isset( $document['brand']['id'] ) ? (int) $document['brand']['id'] : 0;

		$row_id = $this->repo()->insert(
			array(
				'public_id'           => substr( md5( $checksum . microtime( true ) ), 0, 20 ),
				'post_id'             => $post_id,
				'template_id'         => $template_id,
				'template_revision'   => $template ? (int) $template['revision'] : 0,
				'brand_id'            => $brand_id,
				'format'              => $format,
				'width'               => $width,
				'height'              => $height,
				'renderer'            => isset( $args['renderer'] ) ? sanitize_key( (string) $args['renderer'] ) : 'browser',
				'render_signature'    => $signature['signature'],
				'source_post_modified' => $post ? (string) $post->post_modified : '0000-00-00 00:00:00',
				'source_content_hash' => $signature['content_hash'],
				'font_signature'      => $this->container->get( 'fonts' )->signature(),
				'file_path'           => $path,
				'file_url'            => trailingslashit( $dir['url'] ) . $filename,
				'attachment_id'       => 0,
				'mime'                => 'image/png',
				'bytes'               => (int) $written,
				'checksum_sha256'     => $checksum,
				'status'              => 'ready',
				'meta'                => wp_json_encode( isset( $args['meta'] ) ? (array) $args['meta'] : array() ),
				'created_by'          => get_current_user_id(),
				'created_at'          => current_time( 'mysql' ),
			)
		);

		if ( ! $row_id ) {
			return new \WP_Error( 'cpic_artifact_row', __( 'The image was saved but its database record failed.', 'custom-post-image-creator' ) );
		}

		$artifact = $this->repo()->find( $row_id );

		$this->container->get( 'audit' )->record(
			'artifact.created',
			'artifact',
			$row_id,
			sprintf(
				/* translators: 1: width, 2: height, 3: post id */
				__( 'Rendered %1$d×%2$d image for post %3$d', 'custom-post-image-creator' ),
				$width,
				$height,
				$post_id
			),
			array(
				'template_id' => $template_id,
				'format'      => $format,
				'checksum'    => $checksum,
			)
		);

		$this->container->get( 'events' )->dispatch(
			'artifact.created',
			array(
				'artifact_id' => $row_id,
				'post_id'     => $post_id,
				'format'      => $format,
			)
		);

		if ( ! empty( $args['attach'] ) && $post_id ) {
			$this->attach_to_media_library( $row_id, $path, $post_id, $width, $height );
		}

		return $this->hydrate( $artifact );
	}

	/**
	 * Store a server-rendered file (GD fallback or external renderer output).
	 *
	 * @param array<string,mixed> $args Post id, document, format, path, url.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function store_file( array $args ) {
		$path = isset( $args['path'] ) ? (string) $args['path'] : '';

		if ( '' === $path || ! file_exists( $path ) ) {
			return new \WP_Error( 'cpic_file_missing', __( 'The rendered file could not be found.', 'custom-post-image-creator' ) );
		}

		$binary = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( false === $binary ) {
			return new \WP_Error( 'cpic_file_read', __( 'The rendered file could not be read.', 'custom-post-image-creator' ) );
		}

		$args['binary'] = $binary;

		return $this->store_browser_render( $args );
	}

	/**
	 * Attach the artifact to the WordPress media library.
	 *
	 * @param int    $artifact_id Artifact id.
	 * @param string $path        File path.
	 * @param int    $post_id     Post id.
	 * @param int    $width       Width.
	 * @param int    $height      Height.
	 * @return int Attachment id.
	 */
	public function attach_to_media_library( $artifact_id, $path, $post_id, $width, $height ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$title = sprintf(
			/* translators: 1: post title, 2: format */
			__( '%1$s — social image (%2$s)', 'custom-post-image-creator' ),
			get_the_title( $post_id ),
			isset( $artifact_id ) ? '' : ''
		);

		$attachment = array(
			'post_mime_type' => 'image/png',
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => (int) $post_id,
		);

		$attachment_id = wp_insert_attachment( $attachment, $path, (int) $post_id );

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return 0;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $path );
		if ( ! is_wp_error( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		update_post_meta( $attachment_id, '_cpic_artifact_id', (int) $artifact_id );
		$this->repo()->update( (int) $artifact_id, array( 'attachment_id' => (int) $attachment_id ) );

		return (int) $attachment_id;
	}

	/**
	 * Latest usable artifact for a post.
	 *
	 * @param int    $post_id Post id.
	 * @param string $format  Format ('' = any).
	 * @return array<string,mixed>|null
	 */
	public function latest( $post_id, $format = '' ) {
		$row = $this->repo()->latest_for_post( (int) $post_id, (string) $format );

		if ( ! $row ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Latest artifact that still matches the current post + design.
	 *
	 * @param int                 $post_id  Post id.
	 * @param array<string,mixed> $document Document.
	 * @param string              $format   Format.
	 * @return array<string,mixed>|null
	 */
	public function fresh( $post_id, array $document, $format ) {
		$signature = $this->container->get( 'render_signature' )->compute( $document, (int) $post_id, (string) $format );

		$row = $this->repo()->find_by_signature( (int) $post_id, $signature['signature'], (string) $format );

		if ( ! $row ) {
			return null;
		}

		if ( empty( $row['file_path'] ) || ! file_exists( (string) $row['file_path'] ) ) {
			$this->mark_status( (int) $row['id'], 'missing' );
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Whether the stored artifact reflects the current content.
	 *
	 * @param array<string,mixed> $artifact Hydrated artifact.
	 * @param int                 $post_id  Post id.
	 * @return bool
	 */
	public function is_current( array $artifact, $post_id ) {
		$current = $this->container->get( 'render_signature' )->content_hash( (int) $post_id );

		return isset( $artifact['source_content_hash'] ) && hash_equals( (string) $artifact['source_content_hash'], $current );
	}

	/**
	 * Find one by id.
	 *
	 * @param int $id Artifact id.
	 * @return array<string,mixed>|null
	 */
	public function get( $id ) {
		return $this->hydrate( $this->repo()->find( (int) $id ) );
	}

	/**
	 * Find by public id.
	 *
	 * @param string $public_id Public id.
	 * @return array<string,mixed>|null
	 */
	public function get_by_public_id( $public_id ) {
		return $this->hydrate( $this->repo()->first( array( 'public_id' => (string) $public_id ) ) );
	}

	/**
	 * Update artifact status.
	 *
	 * @param int    $id     Artifact id.
	 * @param string $status ready|missing|rejected.
	 * @return void
	 */
	public function mark_status( $id, $status ) {
		$this->repo()->update( (int) $id, array( 'status' => sanitize_key( $status ) ) );
	}

	/**
	 * Delete an artifact and its file.
	 *
	 * @param int $id Artifact id.
	 * @return bool
	 */
	public function delete( $id ) {
		$artifact = $this->repo()->find( (int) $id );

		if ( ! $artifact ) {
			return false;
		}

		if ( ! empty( $artifact['file_path'] ) && file_exists( (string) $artifact['file_path'] ) ) {
			$uploads = wp_upload_dir();
			$base    = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
			$target  = realpath( (string) $artifact['file_path'] );

			// Never delete outside the uploads tree.
			if ( $base && $target && 0 === strpos( $target, $base ) ) {
				@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}

		$this->repo()->delete( (int) $id );

		return true;
	}

	/**
	 * Retention cleanup.
	 *
	 * @param int $days Keep artifacts newer than N days (0 = disabled).
	 * @return int Deleted rows.
	 */
	public function cleanup( $days = 0 ) {
		$days = (int) $days;

		if ( $days <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table = Schema::table( 'artifacts' );
		$cut   = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// Keep the newest artifact per post even when it is old.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id FROM {$table} a
				 WHERE a.created_at < %s
				   AND a.id <> (SELECT MAX(b.id) FROM {$table} b WHERE b.post_id = a.post_id)
				 LIMIT 500",
				$cut
			),
			ARRAY_A
		);

		$deleted = 0;
		foreach ( (array) $rows as $row ) {
			if ( $this->delete( (int) $row['id'] ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Add computed fields for API responses.
	 *
	 * @param array<string,mixed>|null $row Row.
	 * @return array<string,mixed>|null
	 */
	public function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}

		$row['id']          = (int) $row['id'];
		$row['post_id']     = (int) $row['post_id'];
		$row['template_id'] = (int) $row['template_id'];
		$row['width']       = (int) $row['width'];
		$row['height']      = (int) $row['height'];
		$row['bytes']       = (int) $row['bytes'];
		$row['exists']      = ! empty( $row['file_path'] ) && file_exists( (string) $row['file_path'] );
		$row['edit_url']    = $row['post_id'] ? get_edit_post_link( $row['post_id'], 'raw' ) : '';

		return $row;
	}

	/**
	 * Storage/health summary.
	 *
	 * @return array<string,mixed>
	 */
	public function summary() {
		$rows  = $this->repo()->storage_summary();
		$bytes = 0;
		$count = 0;

		foreach ( $rows as $status => $row ) {
			$bytes += (int) $row['bytes'];
			$count += (int) $row['count'];
		}

		return array(
			'total'   => $count,
			'bytes'   => $bytes,
			'by_status' => $rows,
			'dir'     => $this->directory(),
		);
	}
}
