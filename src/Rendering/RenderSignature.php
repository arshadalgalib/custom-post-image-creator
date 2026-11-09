<?php
/**
 * Render signature (cache key) computation.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Rendering;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A render signature captures everything that can change the pixels: the
 * design document, the post revision, the images it references, the resolved
 * fonts and the renderer settings. Identical signature => reuse the stored
 * artifact instead of re-rendering.
 */
final class RenderSignature {

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
	 * Compute the signature.
	 *
	 * @param array<string,mixed> $document Design document.
	 * @param int                 $post_id  Post id.
	 * @param string              $format   Format key.
	 * @param array<string,mixed> $extra    Extra inputs (e.g. overrides).
	 * @return array{signature:string,content_hash:string,parts:array<string,mixed>}
	 */
	public function compute( array $document, $post_id, $format, array $extra = array() ) {
		$post_id = (int) $post_id;

		$content_hash = $this->content_hash( $post_id );

		$parts = array(
			'document'  => $this->hash_array( $document ),
			'format'    => (string) $format,
			'post'      => $post_id,
			'content'   => $content_hash,
			'images'    => $this->image_fingerprints( $document, $post_id ),
			'fonts'     => $this->container->get( 'fonts' )->signature(),
			'settings'  => $this->settings_fingerprint(),
			'extra'     => $this->hash_array( $extra ),
			'engine'    => CPIC_VERSION,
		);

		$signature = hash( 'sha256', wp_json_encode( $parts ) );

		return array(
			'signature'    => $signature,
			'content_hash' => $content_hash,
			'parts'        => $parts,
		);
	}

	/**
	 * Hash the post's render-relevant content.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function content_hash( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! $post_id ) {
			return 'preview';
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return 'missing';
		}

		$terms = get_the_category( $post_id );
		$cats  = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$cats[] = (string) $term->name;
			}
		}

		$payload = array(
			'title'     => (string) $post->post_title,
			'excerpt'   => (string) $post->post_excerpt,
			'content'   => md5( (string) $post->post_content ),
			'modified'  => (string) $post->post_modified_gmt,
			'author'    => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'date'      => (string) $post->post_date,
			'thumbnail' => (int) get_post_thumbnail_id( $post_id ),
			'cats'      => $cats,
			'terms'     => $this->term_fingerprint( $post_id ),
		);

		/**
		 * Filter the data that makes up the content hash.
		 *
		 * @param array<string,mixed> $payload Hash payload.
		 * @param int                 $post_id Post id.
		 */
		$payload = apply_filters( 'cpic/render_content_hash', $payload, $post_id );

		return hash( 'sha256', wp_json_encode( $payload ) );
	}

	/**
	 * Taxonomy fingerprint (custom taxonomies may feed layouts).
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>
	 */
	private function term_fingerprint( $post_id ) {
		$out    = array();
		$taxes  = get_object_taxonomies( get_post_type( $post_id ) ? get_post_type( $post_id ) : 'post' );

		foreach ( (array) $taxes as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( is_array( $terms ) ) {
				$out[ $taxonomy ] = wp_list_pluck( $terms, 'slug' );
			}
		}

		return $out;
	}

	/**
	 * Fingerprints of every image the design references.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param int                 $post_id  Post id.
	 * @return array<string,string>
	 */
	public function image_fingerprints( array $document, $post_id ) {
		$urls = array();

		if ( ! empty( $document['canvas']['background_image'] ) ) {
			$urls[] = (string) $document['canvas']['background_image'];
		}

		if ( isset( $document['elements'] ) && is_array( $document['elements'] ) ) {
			foreach ( $document['elements'] as $element ) {
				if ( ! empty( $element['url'] ) ) {
					$urls[] = (string) $element['url'];
				}
				if ( 'featured_image' === ( $element['source'] ?? '' ) && $post_id ) {
					$thumb = get_the_post_thumbnail_url( (int) $post_id, 'full' );
					if ( $thumb ) {
						$urls[] = $thumb;
					}
				}
			}
		}

		$out = array();

		foreach ( array_unique( $urls ) as $url ) {
			$out[ $url ] = $this->url_fingerprint( $url );
		}

		return $out;
	}

	/**
	 * Fingerprint an image URL: attachment modification time, or a HEAD-style
	 * HTTP check for local files only (never arbitrary remote fetching).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function url_fingerprint( $url ) {
		$url = (string) $url;

		if ( '' === $url ) {
			return '';
		}

		$attachment_id = attachment_url_to_postid( $url );

		if ( $attachment_id ) {
			$file  = get_attached_file( $attachment_id );
			$mtime = $file && file_exists( $file ) ? (string) filemtime( $file ) : '';
			$size  = $file && file_exists( $file ) ? (string) filesize( $file ) : '';

			return md5( $attachment_id . '|' . $mtime . '|' . $size );
		}

		// Same-origin uploads file.
		$uploads = wp_upload_dir();
		if ( empty( $uploads['error'] ) && 0 === strpos( $url, trailingslashit( $uploads['baseurl'] ) ) ) {
			$path = trailingslashit( $uploads['basedir'] ) . substr( $url, strlen( trailingslashit( $uploads['baseurl'] ) ) );
			if ( file_exists( $path ) ) {
				return md5( (string) filemtime( $path ) . '|' . (string) filesize( $path ) );
			}
		}

		return md5( $url );
	}

	/**
	 * Settings that influence rendering output.
	 *
	 * @return string
	 */
	private function settings_fingerprint() {
		$settings = $this->container->get( 'settings' );

		return md5(
			wp_json_encode(
				array(
					'quality' => $settings->int( 'cpic_image_quality', 92 ),
					'webp'    => $settings->is_enabled( 'cpic_webp_enabled' ),
					'renderer' => $settings->get( 'cpic_renderer', 'browser' ),
					'font_ok' => $settings->int( 'cpic_canvas_width', 1080 ),
				)
			)
		);
	}

	/**
	 * Stable hash for a nested array.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function hash_array( $value ) {
		return hash( 'sha256', (string) wp_json_encode( $this->sort_recursive( $value ) ) );
	}

	/**
	 * Recursively sort keys so array order cannot change the signature.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->sort_recursive( $item );
		}

		if ( ! $is_list ) {
			ksort( $value );
		}

		return $value;
	}
}
