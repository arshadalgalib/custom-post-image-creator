<?php
/**
 * Safe dynamic content engine.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Support;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves `{{post.title}}`-style tokens against an allow-list of WordPress
 * data. There is no expression evaluator: only `namespace.key` lookups with an
 * optional `|filter:arg` chain are supported, so a malicious template cannot
 * execute PHP or JavaScript.
 */
final class DynamicContent {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Token pattern.
	 */
	const TOKEN = '/\{\{\s*([a-zA-Z0-9_\.]+)\s*(?:\|\s*([a-zA-Z0-9_\|\:\-,\s]+))?\s*\}\}/';

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Available variables with labels (for the Inspector UI).
	 *
	 * @return array<string,string>
	 */
	public function variables() {
		$variables = array(
			'post.title'          => __( 'Post title', 'custom-post-image-creator' ),
			'post.excerpt'        => __( 'Excerpt / summary', 'custom-post-image-creator' ),
			'post.author'         => __( 'Author name', 'custom-post-image-creator' ),
			'post.date'           => __( 'Post date (site format)', 'custom-post-image-creator' ),
			'post.date_bangla'    => __( 'Post date (Bengali)', 'custom-post-image-creator' ),
			'post.category'       => __( 'Primary category', 'custom-post-image-creator' ),
			'post.categories'     => __( 'All categories', 'custom-post-image-creator' ),
			'post.featured_image' => __( 'Featured image URL', 'custom-post-image-creator' ),
			'post.permalink'      => __( 'Permalink', 'custom-post-image-creator' ),
			'post.id'             => __( 'Post ID', 'custom-post-image-creator' ),
			'post.reading_time'   => __( 'Estimated reading time', 'custom-post-image-creator' ),
			'site.name'           => __( 'Site name', 'custom-post-image-creator' ),
			'site.url'            => __( 'Site URL', 'custom-post-image-creator' ),
			'site.tagline'        => __( 'Site tagline', 'custom-post-image-creator' ),
			'campaign.name'       => __( 'Campaign name', 'custom-post-image-creator' ),
			'brand.name'          => __( 'Brand name', 'custom-post-image-creator' ),
			'brand.website'       => __( 'Brand website', 'custom-post-image-creator' ),
			'date.today'          => __( 'Today', 'custom-post-image-creator' ),
			'date.today_bangla'   => __( 'Today (Bengali)', 'custom-post-image-creator' ),
		);

		/**
		 * Register additional dynamic variables. Keys must map to data that the
		 * callback resolves; arbitrary user input is never executed.
		 *
		 * @param array<string,string> $variables Variables.
		 */
		return apply_filters( 'cpic/dynamic_variables', $variables );
	}

	/**
	 * Build the context array for a post.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $extra   Extra context (brand, campaign...).
	 * @return array<string,mixed>
	 */
	public function context( $post_id, array $extra = array() ) {
		$post_id = (int) $post_id;
		$post    = $post_id ? get_post( $post_id ) : null;

		$dates = $this->container->get( 'dates' );

		$context = array(
			'post' => array(
				'id'             => $post_id,
				'title'          => $post ? (string) $post->post_title : '',
				'excerpt'        => $post ? $this->summary( $post ) : '',
				'author'         => $post ? (string) get_the_author_meta( 'display_name', (int) $post->post_author ) : '',
				'date'           => $post ? mysql2date( (string) get_option( 'date_format', 'F j, Y' ), $post->post_date ) : '',
				'date_bangla'    => $post ? $dates->format_post_date( $post_id ) : '',
				'category'       => $this->primary_category( $post_id ),
				'categories'     => implode( ', ', $this->category_names( $post_id ) ),
				'featured_image' => $post_id ? (string) get_the_post_thumbnail_url( $post_id, 'full' ) : '',
				'permalink'      => $post_id ? (string) get_permalink( $post_id ) : '',
				'reading_time'   => $post ? $this->reading_time( (string) $post->post_content ) : '',
			),
			'site' => array(
				'name'    => (string) get_bloginfo( 'name' ),
				'url'     => (string) home_url( '/' ),
				'tagline' => (string) get_bloginfo( 'description' ),
			),
			'date' => array(
				'today'        => $dates->format( time(), 'site' ),
				'today_bangla' => $dates->format( time(), 'bangla_long' ),
			),
		);

		foreach ( array( 'campaign', 'brand' ) as $key ) {
			$context[ $key ] = isset( $extra[ $key ] ) && is_array( $extra[ $key ] ) ? $extra[ $key ] : array();
		}

		if ( $post && $post_id ) {
			$meta = get_post_meta( $post_id );
			$context['post']['meta'] = array();
			foreach ( $meta as $key => $values ) {
				if ( 0 === strpos( (string) $key, '_' ) ) {
					continue;
				}
				$context['post']['meta'][ (string) $key ] = is_array( $values ) ? (string) reset( $values ) : (string) $values;
			}
		}

		/**
		 * Filter the dynamic context before rendering.
		 *
		 * @param array<string,mixed> $context Context.
		 * @param int                 $post_id Post id.
		 */
		return apply_filters( 'cpic/dynamic_context', $context, $post_id );
	}

	/**
	 * Resolve a single variable path.
	 *
	 * @param string              $path    Dotted path, e.g. `post.title`.
	 * @param array<string,mixed> $context Context.
	 * @param string              $default Fallback.
	 * @return string
	 */
	public function resolve( $path, array $context, $default = '' ) {
		$path = trim( (string) $path );

		if ( '' === $path ) {
			return $default;
		}

		// Custom fields: {{post.meta.key_name}} or {{custom_field:key}}.
		if ( 0 === strpos( $path, 'meta:' ) || 0 === strpos( $path, 'custom_field:' ) ) {
			$key = substr( $path, strpos( $path, ':' ) + 1 );
			$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', $key );
			$value = isset( $context['post']['meta'][ $key ] ) ? $context['post']['meta'][ $key ] : '';
			return '' !== (string) $value ? (string) $value : $default;
		}

		$segments = explode( '.', $path );
		$value    = $context;

		foreach ( $segments as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
				continue;
			}
			if ( is_object( $value ) && isset( $value->$segment ) ) {
				$value = $value->$segment;
				continue;
			}
			return $default;
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return $default;
		}

		$value = (string) $value;

		return '' !== $value ? $value : $default;
	}

	/**
	 * Render a text pattern containing tokens and filters.
	 *
	 * Supported filters: upper, lower, title, trim, strip_html, words:N,
	 * chars:N, bangla_digits, ascii_digits, nl2br, default:VALUE.
	 *
	 * @param string              $pattern Pattern text.
	 * @param array<string,mixed> $context Context.
	 * @return string
	 */
	public function render( $pattern, array $context ) {
		$pattern = (string) $pattern;

		if ( false === strpos( $pattern, '{{' ) ) {
			return $pattern;
		}

		$result = preg_replace_callback(
			self::TOKEN,
			function ( $matches ) use ( $context ) {
				$value = $this->resolve( $matches[1], $context );
				return $this->apply_filters( $value, isset( $matches[2] ) ? $matches[2] : '' );
			},
			$pattern
		);

		if ( null === $result ) {
			return $pattern;
		}

		// Anything still wrapped in braces is not a token we understand (an
		// unknown namespace, a call expression, a typo). Never echo it: this
		// text is painted onto canvas output and shown to editors verbatim.
		$result = (string) preg_replace( '/\{\{.*?\}\}/s', '', $result );

		/** This filter is documented in src/Support/DynamicContent.php */
		return apply_filters( 'cpic/dynamic_render', $result, $pattern, $context );
	}

	/**
	 * Apply a `|`-separated filter chain.
	 *
	 * @param string $value       Raw value.
	 * @param string $filter_spec Filter specification.
	 * @return string
	 */
	public function apply_filters( $value, $filter_spec ) {
		$value       = (string) $value;
		$filter_spec = trim( (string) $filter_spec );

		if ( '' === $filter_spec ) {
			return $value;
		}

		foreach ( explode( '|', $filter_spec ) as $filter ) {
			$filter = trim( $filter );
			if ( '' === $filter ) {
				continue;
			}

			$arg = '';
			if ( false !== strpos( $filter, ':' ) ) {
				list( $filter, $arg ) = explode( ':', $filter, 2 );
				$filter = trim( $filter );
				$arg    = trim( $arg );
			}

			switch ( $filter ) {
				case 'upper':
					$value = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
					break;

				case 'lower':
					$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
					break;

				case 'title':
					$value = function_exists( 'mb_convert_case' ) ? mb_convert_case( $value, MB_CASE_TITLE, 'UTF-8' ) : ucwords( $value );
					break;

				case 'trim':
					$value = trim( $value );
					break;

				case 'strip_html':
					$value = trim( wp_strip_all_tags( $value ) );
					break;

				case 'words':
					$count = max( 1, (int) $arg );
					$value = $this->truncate_words( $value, $count );
					break;

				case 'chars':
					$count = max( 1, (int) $arg );
					$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $count, 'UTF-8' ) : substr( $value, 0, $count );
					break;

				case 'bangla_digits':
					$value = $this->container->get( 'dates' )->to_bengali_digits( $value );
					break;

				case 'ascii_digits':
					$value = $this->container->get( 'dates' )->to_ascii_digits( $value );
					break;

				case 'nl2br':
					$value = nl2br( $value );
					break;

				case 'default':
					if ( '' === trim( $value ) ) {
						$value = $arg;
					}
					break;

				default:
					/**
					 * Custom filter hook: `cpic/dynamic_filter/<name>`.
					 *
					 * @param string $value Value.
					 * @param string $arg   Filter argument.
					 */
					$value = (string) apply_filters( 'cpic/dynamic_filter/' . sanitize_key( $filter ), $value, $arg );
			}
		}

		return $value;
	}

	/**
	 * Truncate to N words (Bengali safe: splits on Unicode whitespace).
	 *
	 * @param string $text  Text.
	 * @param int    $words Word count.
	 * @return string
	 */
	public function truncate_words( $text, $words ) {
		$text  = trim( wp_strip_all_tags( (string) $text ) );
		$parts = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) || count( $parts ) <= $words ) {
			return $text;
		}

		return implode( ' ', array_slice( $parts, 0, $words ) ) . '…';
	}

	/**
	 * Post summary (first paragraph / excerpt), used for captions and subtitles.
	 *
	 * @param \WP_Post $post Post.
	 * @param int      $words Maximum words.
	 * @return string
	 */
	public function summary( $post, $words = 45 ) {
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$excerpt = trim( (string) $post->post_excerpt );

		if ( '' === $excerpt ) {
			$content = (string) $post->post_content;
			$content = preg_replace( '/<!--.*?-->/s', ' ', $content );
			$content = preg_replace( '/\[[^\]]+\]/', ' ', $content );
			$content = wp_strip_all_tags( $content );
			$content = trim( preg_replace( '/\s+/u', ' ', $content ) );

			$paragraphs = preg_split( '/\n\s*\n/u', $content );
			$excerpt    = ! empty( $paragraphs ) ? trim( (string) $paragraphs[0] ) : $content;
		}

		return $this->truncate_words( $excerpt, $words );
	}

	/**
	 * Primary category name.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function primary_category( $post_id ) {
		$names = $this->category_names( $post_id, 1 );

		return $names ? $names[0] : '';
	}

	/**
	 * Category names.
	 *
	 * @param int $post_id Post id.
	 * @param int $limit   Limit (0 = all).
	 * @return array<int,string>
	 */
	public function category_names( $post_id, $limit = 0 ) {
		$terms = get_the_category( (int) $post_id );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$names = array();
		foreach ( $terms as $term ) {
			$names[] = (string) $term->name;
		}

		if ( $limit > 0 ) {
			$names = array_slice( $names, 0, $limit );
		}

		return $names;
	}

	/**
	 * Reading time estimate.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function reading_time( $content ) {
		$words = preg_split( '/\s+/u', trim( wp_strip_all_tags( (string) $content ) ), -1, PREG_SPLIT_NO_EMPTY );
		$count = is_array( $words ) ? count( $words ) : 0;
		$mins  = max( 1, (int) ceil( $count / 180 ) );

		/* translators: %d: minutes */
		$label = $mins . ' ' . __( 'min read', 'custom-post-image-creator' );

		return $this->container->get( 'dates' )->to_bengali_digits( (string) $mins ) . ' ' . __( 'মিনিট পাঠ', 'custom-post-image-creator' );
	}

	/**
	 * Evaluate element display conditions.
	 *
	 * @param array<string,mixed> $conditions Conditions.
	 * @param int                 $post_id    Post id.
	 * @return bool
	 */
	public function conditions_match( array $conditions, $post_id ) {
		if ( empty( $conditions ) ) {
			return true;
		}

		$post_id = (int) $post_id;

		if ( isset( $conditions['category_in'] ) && is_array( $conditions['category_in'] ) && ! empty( $conditions['category_in'] ) ) {
			$terms = wp_get_post_categories( $post_id );
			$terms = is_array( $terms ) ? array_map( 'intval', $terms ) : array();
			if ( empty( array_intersect( $terms, $conditions['category_in'] ) ) ) {
				return false;
			}
		}

		if ( isset( $conditions['category_not_in'] ) && is_array( $conditions['category_not_in'] ) && ! empty( $conditions['category_not_in'] ) ) {
			$terms = wp_get_post_categories( $post_id );
			$terms = is_array( $terms ) ? array_map( 'intval', $terms ) : array();
			if ( ! empty( array_intersect( $terms, $conditions['category_not_in'] ) ) ) {
				return false;
			}
		}

		if ( ! empty( $conditions['has_featured_image'] ) && ! get_post_thumbnail_id( $post_id ) ) {
			return false;
		}

		if ( ! empty( $conditions['custom_field_key'] ) ) {
			$key   = (string) $conditions['custom_field_key'];
			$value = get_post_meta( $post_id, $key, true );

			if ( '' === (string) $value ) {
				return false;
			}

			if ( isset( $conditions['custom_field_value'] ) && '' !== (string) $conditions['custom_field_value'] ) {
				return (string) $conditions['custom_field_value'] === (string) $value;
			}
		}

		$title_length = function_exists( 'mb_strlen' ) ? mb_strlen( (string) get_the_title( $post_id ), 'UTF-8' ) : strlen( (string) get_the_title( $post_id ) );

		if ( isset( $conditions['title_length_min'] ) && '' !== (string) $conditions['title_length_min'] && $title_length < (int) $conditions['title_length_min'] ) {
			return false;
		}

		if ( isset( $conditions['title_length_max'] ) && '' !== (string) $conditions['title_length_max'] && $title_length > (int) $conditions['title_length_max'] ) {
			return false;
		}

		/**
		 * Filter whether an element's conditions match.
		 *
		 * @param bool                $matches    Result.
		 * @param array<string,mixed> $conditions Conditions.
		 * @param int                 $post_id    Post id.
		 */
		return (bool) apply_filters( 'cpic/conditions_match', true, $conditions, $post_id );
	}
}
