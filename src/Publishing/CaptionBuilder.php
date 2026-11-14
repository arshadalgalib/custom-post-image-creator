<?php
/**
 * Caption composition per platform.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Publishing;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the text that accompanies an image.
 *
 * Limits are enforced per platform (Telegram caps captions at 1024 characters
 * and silently truncates beyond that; Facebook tolerates much more but reads
 * badly). Every caption is built from the post itself — no invented claims.
 */
final class CaptionBuilder {

	/**
	 * Platform caption limits (characters).
	 */
	const LIMITS = array(
		'facebook' => 5000,
		'telegram' => 1024,
		'default'  => 2200,
	);

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
	 * Build a caption for a post.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $args {
	 *     @type string $provider          Platform (for the limit).
	 *     @type int    $include_headline  Include the title.
	 *     @type int    $include_first_paragraph Include the first paragraph.
	 *     @type int    $include_link      Append the permalink.
	 *     @type string $hashtags          Extra hashtags.
	 *     @type string $template          Caption template with {{tokens}}.
	 * }
	 * @param int                 $limit  Character limit override.
	 * @return string
	 */
	public function build( $post_id, array $args = array(), $limit = 0 ) {
		$post_id = (int) $post_id;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return '';
		}

		$provider = isset( $args['provider'] ) ? sanitize_key( (string) $args['provider'] ) : 'default';
		$limit    = $limit > 0 ? (int) $limit : ( isset( self::LIMITS[ $provider ] ) ? self::LIMITS[ $provider ] : self::LIMITS['default'] );

		$settings = $this->container->get( 'settings' );

		$include_headline = isset( $args['include_headline'] ) ? (bool) $args['include_headline'] : true;
		$include_first    = isset( $args['include_first_paragraph'] ) ? (bool) $args['include_first_paragraph'] : true;
		$include_link     = isset( $args['include_link'] ) ? (bool) $args['include_link'] : (bool) $settings->is_enabled( 'cpic_first_comment_link' );

		$template = isset( $args['template'] ) ? (string) $args['template'] : (string) $settings->get( 'cpic_caption_template', '' );

		$hashtags = isset( $args['hashtags'] ) ? (string) $args['hashtags'] : (string) $settings->get( 'cpic_hashtags', '' );

		$parts = array();

		if ( '' !== trim( $template ) ) {
			// An explicit template wins; it can reference any dynamic token.
			$context = $this->container->get( 'dynamic_content' )->context( $post_id );
			$parts[] = trim( $this->container->get( 'dynamic_content' )->render( $template, $context ) );
		} else {
			if ( $include_headline ) {
				$title = trim( wp_strip_all_tags( (string) $post->post_title ) );
				if ( '' !== $title ) {
					$parts[] = $title;
				}
			}

			if ( $include_first ) {
				$paragraph = $this->first_paragraph( (string) $post->post_content );
				if ( '' !== $paragraph ) {
					$parts[] = $paragraph;
				}
			}
		}

		$caption = implode( "\n\n", array_filter( $parts ) );

		if ( $include_link ) {
			$link = (string) get_permalink( $post_id );
			if ( '' !== $link ) {
				$caption .= ( '' === $caption ? '' : "\n\n" ) . $link;
			}
		}

		$tags = $this->normalise_hashtags( $hashtags );

		if ( '' !== $tags ) {
			$caption .= ( '' === $caption ? '' : "\n\n" ) . $tags;
		}

		/**
		 * Filter a composed caption.
		 *
		 * @param string              $caption  Caption text.
		 * @param int                 $post_id  Post id.
		 * @param array<string,mixed> $args     Arguments.
		 */
		$caption = (string) apply_filters( 'cpic/caption', $caption, $post_id, $args );

		return $this->limit( $caption, $limit );
	}

	/**
	 * Build a caption per destination, honouring per-account overrides.
	 *
	 * @param int                              $post_id      Post id.
	 * @param array<int,array<string,mixed>>   $destinations Destination rows.
	 * @param array<string,mixed>              $base         Base arguments.
	 * @return array<int,string> Keyed by destination id.
	 */
	public function build_for_destinations( $post_id, array $destinations, array $base = array() ) {
		$out = array();

		foreach ( $destinations as $destination ) {
			$config = json_decode( (string) ( isset( $destination['config'] ) ? $destination['config'] : '' ), true );
			$config = is_array( $config ) ? $config : array();

			$args           = $base;
			$args['provider'] = isset( $destination['provider'] ) ? (string) $destination['provider'] : 'default';

			foreach ( array( 'include_headline', 'include_first_paragraph', 'include_link', 'hashtags', 'template' ) as $key ) {
				if ( array_key_exists( $key, $config ) ) {
					$args[ $key ] = $config[ $key ];
				}
			}

			$out[ (int) $destination['id'] ] = $this->build( $post_id, $args );
		}

		return $out;
	}

	/**
	 * The "read more" first comment used by some pages.
	 *
	 * @param int    $post_id Post id.
	 * @param string $message Custom message.
	 * @return string
	 */
	public function first_comment( $post_id, $message = '' ) {
		$message = '' !== $message ? $message : (string) $this->container->get( 'settings' )->get( 'cpic_comment_message', '' );

		$link = (string) get_permalink( (int) $post_id );

		if ( '' === $link ) {
			return trim( (string) $message );
		}

		return trim( $message . ' ' . $link );
	}

	/**
	 * First meaningful paragraph of post content, markup removed.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function first_paragraph( $content ) {
		$content = strip_shortcodes( (string) $content );
		$content = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $content );
		$content = str_replace( array( '</p>', '<br />', '<br>' ), "\n", (string) $content );
		$content = wp_strip_all_tags( (string) $content );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		$content = trim( preg_replace( '/\s+/u', ' ', $content ) );

		if ( '' === $content ) {
			return '';
		}

		$sentences = preg_split( '/(?<=[।.!?])\s+/u', $content, 3 );

		return isset( $sentences[0] ) ? trim( (string) $sentences[0] ) : $content;
	}

	/**
	 * Normalise a hashtag list into `#tag #tag2`.
	 *
	 * @param mixed $hashtags Raw value (CSV string or array).
	 * @return string
	 */
	public function normalise_hashtags( $hashtags ) {
		if ( is_array( $hashtags ) ) {
			$items = $hashtags;
		} else {
			$items = preg_split( '/[,\s]+/', (string) $hashtags );
		}

		$out = array();

		foreach ( (array) $items as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}
			$item = ltrim( $item, '#' );
			// Bengali letters are allowed in tags.
			$item = preg_replace( '/[^\p{L}\p{N}_]/u', '', $item );
			if ( '' === $item ) {
				continue;
			}
			$out[] = '#' . $item;
		}

		return implode( ' ', array_unique( $out ) );
	}

	/**
	 * Trim to a character limit on a word boundary, adding an ellipsis.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Limit.
	 * @return string
	 */
	public function limit( $text, $limit ) {
		$text  = trim( (string) $text );
		$limit = (int) $limit;

		if ( $limit <= 0 || mb_strlen( $text, 'UTF-8' ) <= $limit ) {
			return $text;
		}

		$truncated = mb_substr( $text, 0, max( 0, $limit - 1 ), 'UTF-8' );
		$last_break = mb_strrpos( $truncated, ' ', 0, 'UTF-8' );

		if ( false !== $last_break && $last_break > $limit * 0.6 ) {
			$truncated = mb_substr( $truncated, 0, $last_break, 'UTF-8' );
		}

		return rtrim( $truncated ) . '…';
	}

	/**
	 * Limit for a provider.
	 *
	 * @param string $provider Provider.
	 * @return int
	 */
	public function limit_for( $provider ) {
		$provider = sanitize_key( (string) $provider );

		$limit = isset( self::LIMITS[ $provider ] ) ? self::LIMITS[ $provider ] : self::LIMITS['default'];

		/**
		 * Filter the caption character limit for a provider.
		 *
		 * @param int    $limit    Limit.
		 * @param string $provider Provider.
		 */
		return (int) apply_filters( 'cpic/caption/limit', $limit, $provider );
	}
}
