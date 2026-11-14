<?php
/**
 * Media library assets (logos, backgrounds, watermarks, SVGs).
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Media;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A curated index over the WordPress media library so the studio can show
 * logos and backgrounds without loading the whole library.
 *
 * SVG is supported (logos are usually SVG) but only after sanitising: an SVG
 * is executable content, and a logo library is a common XSS vector.
 */
final class MediaLibrary {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Allowed mime types.
	 */
	const ALLOWED = array( 'image/jpeg', 'image/png', 'image/webp', 'image/svg+xml', 'image/gif' );

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Asset repository.
	 *
	 * @return \CPIC\Database\Repositories\AssetRepository
	 */
	public function repo() {
		return $this->container->get( 'assets' );
	}

	/**
	 * Browse assets.
	 *
	 * @param array<string,mixed> $args Filters (`kind`, `category`, `search`, `page`, `per`).
	 * @return array<string,mixed>
	 */
	public function browse( array $args = array() ) {
		$page = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$per  = min( 100, max( 1, isset( $args['per'] ) ? (int) $args['per'] : 40 ) );

		$rows = $this->repo()->search(
			array_merge(
				$args,
				array(
					'limit'  => $per,
					'offset' => ( $page - 1 ) * $per,
				)
			)
		);

		return array(
			'items'    => array_values( array_map( array( $this, 'hydrate' ), $rows ) ),
			'page'     => $page,
			'per_page' => $per,
			'total'    => $this->repo()->count(),
			'categories' => $this->repo()->categories(),
		);
	}

	/**
	 * Register a media library attachment as an asset.
	 *
	 * @param int                 $attachment_id Attachment id.
	 * @param array<string,mixed> $args          { kind, category, tags, alt }.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function register_attachment( $attachment_id, array $args = array() ) {
		$attachment_id = (int) $attachment_id;

		$existing = $this->repo()->find_by_attachment( $attachment_id );
		if ( $existing ) {
			return $this->hydrate( $existing );
		}

		$path = (string) get_attached_file( $attachment_id );
		$url  = (string) get_the_post_thumbnail_url( $attachment_id, 'full' );

		if ( '' === $path || ! file_exists( $path ) ) {
			return new \WP_Error( 'cpic_asset_missing', __( 'That media item could not be read from disk.', 'custom-post-image-creator' ), array( 'status' => 404 ) );
		}

		$mime = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $path ) : '';

		if ( '' === $mime ) {
			$check = wp_check_filetype( $path );
			$mime  = isset( $check['type'] ) ? (string) $check['type'] : '';
		}

		if ( ! in_array( $mime, self::ALLOWED, true ) ) {
			return new \WP_Error(
				'cpic_asset_type',
				__( 'Only JPEG, PNG, WebP, GIF and safe SVG files can be used in designs.', 'custom-post-image-creator' ),
				array( 'status' => 400 )
			);
		}

		if ( 'image/svg+xml' === $mime ) {
			$contents = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			if ( ! $this->svg_is_safe( $contents ) ) {
				return new \WP_Error( 'cpic_asset_svg', __( 'That SVG contains scripts or external references and cannot be used.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
			}
		}

		$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$id = $this->repo()->insert(
			array(
				'public_id'     => 'ast_' . substr( md5( $path . microtime( true ) ), 0, 20 ),
				'kind'          => isset( $args['kind'] ) ? sanitize_key( (string) $args['kind'] ) : 'image',
				'title'         => isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : basename( $path ),
				'alt'           => isset( $args['alt'] ) ? sanitize_text_field( (string) $args['alt'] ) : '',
				'attachment_id' => $attachment_id,
				'url'           => esc_url_raw( $url ),
				'path'          => $path,
				'mime'          => $mime,
				'width'         => $size ? (int) $size[0] : 0,
				'height'        => $size ? (int) $size[1] : 0,
				'bytes'         => (int) filesize( $path ),
				'checksum'      => (string) md5_file( $path ),
				'category'      => isset( $args['category'] ) ? sanitize_key( (string) $args['category'] ) : 'general',
				'tags'          => isset( $args['tags'] ) ? sanitize_text_field( (string) $args['tags'] ) : '',
				'usage_count'   => 0,
				'created_by'    => get_current_user_id(),
				'created_at'    => current_time( 'mysql' ),
			)
		);

		if ( ! $id ) {
			return new \WP_Error( 'cpic_asset_save', __( 'The asset could not be indexed.', 'custom-post-image-creator' ), array( 'status' => 500 ) );
		}

		return $this->hydrate( $this->repo()->find( $id ) );
	}

	/**
	 * Upload a file and index it (used by the studio asset picker).
	 *
	 * @param array<string,mixed> $file Uploaded file ($_FILES entry).
	 * @param array<string,mixed> $args Index args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function upload( array $file, array $args = array() ) {
		$max_mb = max( 1, (int) $this->container->get( 'settings' )->int( 'cpic_max_upload_mb', 8 ) );

		if ( isset( $file['size'] ) && (int) $file['size'] > $max_mb * MB_IN_BYTES ) {
			return new \WP_Error(
				'cpic_upload_size',
				sprintf(
					/* translators: %d: size in megabytes */
					__( 'Files must be smaller than %d MB.', 'custom-post-image-creator' ),
					$max_mb
				),
				array( 'status' => 413 )
			);
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_handle_sideload( $file, 0, isset( $args['title'] ) ? (string) $args['title'] : null );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return $this->register_attachment( (int) $attachment_id, $args );
	}

	/**
	 * Whether an SVG is safe to render.
	 *
	 * @param string $svg SVG markup.
	 * @return bool
	 */
	public function svg_is_safe( $svg ) {
		$svg = (string) $svg;

		if ( '' === trim( $svg ) ) {
			return false;
		}

		$forbidden = array(
			'/<script/i',
			'/<foreignObject/i',
			'/<iframe/i',
			'/<embed/i',
			'/<object/i',
			'/\son[a-z]+\s*=/i',       // inline event handlers
			'/javascript:/i',
			'/data:text\/html/i',
			'/<use[^>]+xlink:href\s*=\s*["\']?(?:https?:)?\/\//i', // remote refs
			'/<!ENTITY/i',
			'/<!DOCTYPE[^>]*\[/i',
		);

		foreach ( $forbidden as $pattern ) {
			if ( preg_match( $pattern, $svg ) ) {
				return false;
			}
		}

		/**
		 * Filter whether an SVG asset is considered safe.
		 *
		 * @param bool   $safe Whether safe.
		 * @param string $svg  SVG markup.
		 */
		return (bool) apply_filters( 'cpic/media/svg_is_safe', true, $svg );
	}

	/**
	 * Track usage so popular assets sort first and can be pruned safely.
	 *
	 * @param string $url Asset URL.
	 * @return void
	 */
	public function bump_usage( $url ) {
		$this->repo()->bump_usage( (string) $url );
	}

	/**
	 * Assets referenced by a document (so usage counts stay honest).
	 *
	 * @param array<string,mixed> $document Design document.
	 * @return void
	 */
	public function track_document( array $document ) {
		if ( ! empty( $document['canvas']['background_image'] ) ) {
			$this->bump_usage( (string) $document['canvas']['background_image'] );
		}

		if ( empty( $document['elements'] ) || ! is_array( $document['elements'] ) ) {
			return;
		}

		foreach ( $document['elements'] as $element ) {
			if ( ! empty( $element['url'] ) && in_array( ( $element['type'] ?? '' ), array( 'image', 'logo', 'watermark', 'svg' ), true ) ) {
				$this->bump_usage( (string) $element['url'] );
			}
		}
	}

	/**
	 * Hydrate a row for the studio.
	 *
	 * @param array<string,mixed>|null $row Row.
	 * @return array<string,mixed>|null
	 */
	public function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}

		$row['id']          = (int) $row['id'];
		$row['width']       = (int) $row['width'];
		$row['height']      = (int) $row['height'];
		$row['bytes']       = (int) $row['bytes'];
		$row['usage_count'] = (int) $row['usage_count'];
		$row['tags']        = array_values( array_filter( array_map( 'trim', explode( ',', (string) $row['tags'] ) ) ) );
		$row['is_svg']      = 'image/svg+xml' === (string) $row['mime'];
		$row['thumbnail']   = ! empty( $row['attachment_id'] ) ? (string) wp_get_attachment_image_url( (int) $row['attachment_id'], 'medium' ) : (string) $row['url'];

		return $row;
	}

	/**
	 * Library summary for diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public function summary() {
		global $wpdb;

		$table = \CPIC\Database\Schema::table( 'assets' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( "SELECT COUNT(*) AS total, COALESCE(SUM(bytes),0) AS bytes FROM {$table}", ARRAY_A );

		return array(
			'total'      => $row ? (int) $row['total'] : 0,
			'bytes'      => $row ? (int) $row['bytes'] : 0,
			'categories' => $this->repo()->categories(),
		);
	}
}
