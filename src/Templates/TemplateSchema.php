<?php
/**
 * Design document schema, validation and sanitisation.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Templates;

use CPIC\Rendering\Formats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The design document is the single rendering contract:
 *
 *   {
 *     "schema": "7.0",
 *     "canvas": { "width": 1080, "height": 1080, "format": "square", ... },
 *     "layout": { "<variant>": { "elements": { "<id>": {...} } } },
 *     "elements": { "<id>": { "type": "text", "x": ..., ... } },
 *     "brand": { "id": 0, "overrides": {} }
 *   }
 *
 * `elements` is the base layout; `layout.<variant>.elements` overrides position
 * and sizing per output format so a square design is never just scaled.
 */
final class TemplateSchema {

	/**
	 * Current schema version.
	 */
	const VERSION = '7.0';

	/**
	 * Element types.
	 */
	const TYPES = array( 'text', 'image', 'logo', 'shape', 'group', 'watermark', 'svg', 'gradient', 'pattern' );

	/**
	 * Shape kinds.
	 */
	const SHAPES = array( 'rect', 'circle', 'ellipse', 'line' );

	/**
	 * Text value sources.
	 */
	const TEXT_SOURCES = array( 'static', 'dynamic', 'post_title', 'post_excerpt', 'post_author', 'post_date', 'post_category', 'site_name', 'site_url', 'post_permalink', 'custom_field' );

	/**
	 * Default canvas.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_canvas() {
		return array(
			'width'            => 1080,
			'height'           => 1080,
			'format'           => 'square',
			'background_type'  => 'gradient',
			'background_color' => '#0F172A',
			'gradient_from'    => '#667EEA',
			'gradient_to'      => '#764BA2',
			'gradient_angle'   => 135,
			'background_image' => '',
			'background_fit'   => 'cover',
			'padding'          => 48,
			'safe_area'        => array( 'top' => 64, 'right' => 64, 'bottom' => 64, 'left' => 64 ),
			'grid'             => 0,
			'rulers'           => 0,
			'overlay'          => array( 'enabled' => true, 'from' => 'rgba(9,12,24,0.05)', 'to' => 'rgba(9,12,24,0.82)', 'angle' => 90 ),
		);
	}

	/**
	 * Default text element.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_text() {
		return array(
			'id'             => '',
			'type'           => 'text',
			'name'           => 'Text',
			'text'           => '',
			'source'         => 'post_title',
			'dynamic_key'    => '',
			'fallback'       => '',
			'x'              => 64,
			'y'              => 640,
			'width'          => 952,
			'height'         => 0,
			'rotation'       => 0,
			'opacity'        => 1,
			'visible'        => true,
			'locked'         => false,
			'z'              => 30,
			'font_family'    => 'Noto Sans Bengali',
			'font_size'      => 52,
			'min_font_size'  => 22,
			'max_font_size'  => 108,
			'font_weight'    => 700,
			'font_style'     => 'normal',
			'line_height'    => 1.35,
			'letter_spacing' => 0,
			'align'          => 'left',
			'vertical_align' => 'top',
			'color'          => '#FFFFFF',
			'background'     => '',
			'padding_x'      => 0,
			'padding_y'      => 0,
			'border_radius'  => 0,
			'border_width'   => 0,
			'border_color'   => '#FFFFFF',
			'shadow_color'   => 'rgba(0,0,0,0.55)',
			'shadow_blur'    => 12,
			'shadow_offset_x' => 0,
			'shadow_offset_y' => 3,
			'stroke_color'   => '',
			'stroke_width'   => 0,
			'max_lines'      => 4,
			'auto_scale'     => true,
			'ellipsis'       => true,
			'uppercase'      => false,
			'trim_words'     => 0,
			'date_format'    => '',
			'transform'      => 'none',
			'conditions'     => array(),
		);
	}

	/**
	 * Default image element.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_image() {
		return array(
			'id'            => '',
			'type'          => 'image',
			'name'          => 'Image',
			'source'        => 'featured_image',
			'url'           => '',
			'asset_id'      => 0,
			'x'             => 0,
			'y'             => 0,
			'width'         => 1080,
			'height'        => 620,
			'rotation'      => 0,
			'opacity'       => 1,
			'visible'       => true,
			'locked'        => false,
			'z'             => 10,
			'fit'           => 'cover',
			'focus_x'       => 0.5,
			'focus_y'       => 0.5,
			'border_radius' => 0,
			'border_width'  => 0,
			'border_color'  => '#FFFFFF',
			'shadow_blur'   => 0,
			'shadow_color'  => 'rgba(0,0,0,0.35)',
			'fallback'      => 'gradient',
			'natural_ratio' => true,
			'conditions'    => array(),
		);
	}

	/**
	 * Default shape element.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_shape() {
		return array(
			'id'            => '',
			'type'          => 'shape',
			'name'          => 'Shape',
			'shape'         => 'rect',
			'x'             => 64,
			'y'             => 580,
			'width'         => 240,
			'height'        => 8,
			'rotation'      => 0,
			'opacity'       => 1,
			'visible'       => true,
			'locked'        => false,
			'z'             => 20,
			'fill'          => '#E11D48',
			'gradient_from' => '',
			'gradient_to'   => '',
			'gradient_angle' => 90,
			'stroke_color'  => '',
			'stroke_width'  => 0,
			'border_radius' => 4,
			'conditions'    => array(),
		);
	}

	/**
	 * Default group element.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_group() {
		return array(
			'id'      => '',
			'type'    => 'group',
			'name'    => 'Group',
			'x'       => 0,
			'y'       => 0,
			'width'   => 0,
			'height'  => 0,
			'rotation' => 0,
			'opacity' => 1,
			'visible' => true,
			'locked'  => false,
			'z'       => 5,
			'children' => array(),
		);
	}

	/**
	 * A blank but complete document.
	 *
	 * @param string $format Format key.
	 * @return array<string,mixed>
	 */
	public static function blank( $format = 'square' ) {
		$preset = Formats::get( $format );

		$canvas             = self::default_canvas();
		$canvas['format']   = $preset['key'];
		$canvas['width']    = $preset['width'];
		$canvas['height']   = $preset['height'];
		$canvas['safe_area'] = $preset['safe'];

		$elements = array();

		$headline                = self::default_text();
		$headline['id']          = 'headline';
		$headline['name']        = __( 'Headline', 'custom-post-image-creator' );
		$headline['source']      = 'post_title';
		$headline['y']           = (int) round( $preset['height'] * 0.62 );
		$headline['width']       = $preset['width'] - $preset['safe']['left'] - $preset['safe']['right'];
		$headline['x']           = $preset['safe']['left'];
		$headline['font_size']   = (int) round( $preset['width'] * 0.048 );
		$headline['max_font_size'] = (int) round( $preset['width'] * 0.075 );
		$headline['z']           = 40;
		$elements['headline']    = $headline;

		$eyebrow               = self::default_text();
		$eyebrow['id']         = 'category';
		$eyebrow['name']       = __( 'Category', 'custom-post-image-creator' );
		$eyebrow['source']     = 'post_category';
		$eyebrow['font_size']  = (int) round( $preset['width'] * 0.024 );
		$eyebrow['max_lines']  = 1;
		$eyebrow['x']          = $preset['safe']['left'];
		$eyebrow['y']          = (int) round( $preset['height'] * 0.55 );
		$eyebrow['background'] = '#E11D48';
		$eyebrow['padding_x']  = 18;
		$eyebrow['padding_y']  = 8;
		$eyebrow['border_radius'] = 6;
		$eyebrow['uppercase']  = true;
		$eyebrow['letter_spacing'] = 1;
		$eyebrow['z']          = 35;
		$elements['category']  = $eyebrow;

		$date                    = self::default_text();
		$date['id']              = 'date';
		$date['name']            = __( 'Date', 'custom-post-image-creator' );
		$date['source']          = 'post_date';
		$date['font_size']       = (int) round( $preset['width'] * 0.021 );
		$date['font_family']     = 'Hind Siliguri';
		$date['x']               = $preset['safe']['left'];
		$date['y']               = $preset['height'] - $preset['safe']['bottom'] - (int) round( $preset['width'] * 0.03 );
		$date['max_lines']       = 1;
		$date['z']               = 36;
		$date['date_format']     = 'bangla_long';
		$elements['date']        = $date;

		$featured                = self::default_image();
		$featured['id']          = 'featured_image';
		$featured['name']        = __( 'Featured image', 'custom-post-image-creator' );
		$featured['width']       = $preset['width'];
		$featured['height']      = (int) round( $preset['height'] * 0.62 );
		$featured['x']           = 0;
		$featured['y']           = 0;
		$featured['z']           = 10;
		$elements['featured_image'] = $featured;

		$logo                = self::default_image();
		$logo['id']          = 'logo';
		$logo['type']        = 'logo';
		$logo['name']        = __( 'Logo', 'custom-post-image-creator' );
		$logo['source']      = 'brand_logo';
		$logo['width']       = (int) round( $preset['width'] * 0.14 );
		$logo['height']      = 0;
		$logo['x']           = $preset['width'] - $preset['safe']['right'] - (int) round( $preset['width'] * 0.14 );
		$logo['y']           = $preset['safe']['top'];
		$logo['z']           = 45;
		$logo['natural_ratio'] = true;
		$elements['logo']    = $logo;

		$accent                 = self::default_shape();
		$accent['id']           = 'accent_bar';
		$accent['name']         = __( 'Accent bar', 'custom-post-image-creator' );
		$accent['x']            = $preset['safe']['left'];
		$accent['y']            = (int) round( $preset['height'] * 0.585 );
		$accent['width']        = (int) round( $preset['width'] * 0.12 );
		$accent['height']       = 8;
		$accent['fill']         = '#E11D48';
		$accent['z']            = 33;
		$elements['accent_bar'] = $accent;

		return array(
			'schema'   => self::VERSION,
			'canvas'   => $canvas,
			'elements' => $elements,
			'layout'   => self::default_layouts( $elements, $canvas ),
			'brand'    => array( 'id' => 0, 'overrides' => array() ),
			'caption'  => array(
				'include_headline'        => 1,
				'include_first_paragraph' => 1,
				'include_link'            => 1,
				'hashtags'                => '',
			),
			'meta'     => array(
				'created'  => current_time( 'mysql' ),
				'source'   => 'blank',
				'variants' => array( 'square' ),
			),
		);
	}

	/**
	 * Build per-format overrides from the base layout. Elements are reanchored
	 * relative to each format's safe area instead of being scaled.
	 *
	 * @param array<string,mixed> $elements Base elements.
	 * @param array<string,mixed> $canvas   Base canvas.
	 * @return array<string,mixed>
	 */
	public static function default_layouts( array $elements, array $canvas ) {
		// Element ids are the key here; never re-index the array (usort would).
		$layouts = array( (string) $canvas['format'] => array( 'elements' => array() ) );

		foreach ( Formats::all() as $key => $preset ) {
			if ( $key === $canvas['format'] ) {
				continue;
			}

			$override = array( 'elements' => array() );

			foreach ( $elements as $id => $element ) {
				$adapt = self::adapt_element( $element, $canvas, $preset );
				if ( ! empty( $adapt ) ) {
					$override['elements'][ $id ] = $adapt;
				}
			}

			$layouts[ $key ] = $override;
		}

		return $layouts;
	}

	/**
	 * Compute the per-format override for one element.
	 *
	 * @param array<string,mixed> $element Element definition.
	 * @param array<string,mixed> $from    Source canvas.
	 * @param array<string,mixed> $to      Target format preset.
	 * @return array<string,mixed>
	 */
	public static function adapt_element( array $element, array $from, array $to ) {
		$override = array();

		$from_w = max( 1, (int) $from['width'] );
		$from_h = max( 1, (int) $from['height'] );
		$to_w   = (int) $to['width'];
		$to_h   = (int) $to['height'];

		$type = isset( $element['type'] ) ? $element['type'] : 'text';

		$is_full_width = isset( $element['width'] ) && ( (int) $element['width'] >= $from_w - 4 );
		$is_full_height = isset( $element['height'] ) && (int) $element['height'] > 0 && ( (int) $element['height'] >= $from_h - 4 );

		if ( $is_full_width ) {
			$override['width'] = $to_w;
			$override['x']     = 0;
		} elseif ( isset( $element['width'] ) ) {
			$override['width'] = (int) round( ( (int) $element['width'] / $from_w ) * $to_w );
		}

		if ( $is_full_height ) {
			$override['height'] = (int) round( ( $from_h > 0 ? ( (int) $element['height'] / $from_h ) : 1 ) * $to_h );
		}

		// Vertical anchors: keep relative placement for hero/background layers,
		// re-anchor bottom-aligned elements to the new safe area.
		$relative_y = isset( $element['y'] ) ? ( (int) $element['y'] / $from_h ) : 0;

		if ( isset( $element['y'] ) ) {
			if ( in_array( $type, array( 'image' ), true ) && $relative_y < 0.05 ) {
				$override['y'] = (int) round( $relative_y * $to_h );
			} elseif ( $relative_y > 0.75 ) {
				$override['y'] = (int) round( $to_h - ( $from_h - (int) $element['y'] ) * ( $to_h / $from_h ) );
			} else {
				$override['y'] = (int) round( $relative_y * $to_h );
			}
			$override['y'] = max( 0, min( $to_h, (int) $override['y'] ) );
		}

		// Typography scale follows the canvas width, never the height.
		if ( 'text' === $type && isset( $element['font_size'] ) ) {
			$override['font_size'] = max( 12, (int) round( ( (int) $element['font_size'] / $from_w ) * $to_w ) );

			if ( isset( $element['max_font_size'] ) ) {
				$override['max_font_size'] = max( $override['font_size'], (int) round( ( (int) $element['max_font_size'] / $from_w ) * $to_w ) );
			}
			if ( isset( $element['min_font_size'] ) ) {
				$override['min_font_size'] = max( 10, (int) round( ( (int) $element['min_font_size'] / $from_w ) * $to_w ) );
			}
			if ( isset( $element['max_lines'] ) ) {
				$override['max_lines'] = $to_h > $from_h ? (int) $element['max_lines'] + 1 : (int) $element['max_lines'];
			}
		}

		return $override;
	}

	/**
	 * Validate + sanitise a document coming from the browser or an import.
	 *
	 * @param mixed $document Raw document (array or JSON string).
	 * @param bool  $strict   Drop unknown elements instead of coercing.
	 * @return array{ok:bool,errors:array<int,string>,document:array<string,mixed>,warnings:array<int,string>}
	 */
	public function validate( $document, $strict = false ) {
		$errors   = array();
		$warnings = array();

		if ( is_string( $document ) ) {
			$decoded = json_decode( $document, true );
			if ( null === $decoded ) {
				return array(
					'ok'       => false,
					'errors'   => array( __( 'The design document is not valid JSON.', 'custom-post-image-creator' ) ),
					'warnings' => array(),
					'document' => self::blank(),
				);
			}
			$document = $decoded;
		}

		if ( ! is_array( $document ) ) {
			return array(
				'ok'       => false,
				'errors'   => array( __( 'The design document must be an object.', 'custom-post-image-creator' ) ),
				'warnings' => array(),
				'document' => self::blank(),
			);
		}

		$canvas  = self::default_canvas();
		$raw     = isset( $document['canvas'] ) && is_array( $document['canvas'] ) ? $document['canvas'] : array();
		$clamped = Formats::clamp( isset( $raw['width'] ) ? $raw['width'] : $canvas['width'], isset( $raw['height'] ) ? $raw['height'] : $canvas['height'] );

		$canvas['width']  = $clamped['width'];
		$canvas['height'] = $clamped['height'];
		$canvas['format'] = isset( $raw['format'] ) ? sanitize_key( (string) $raw['format'] ) : Formats::nearest( $clamped['width'], $clamped['height'] );

		$canvas['background_type'] = isset( $raw['background_type'] ) && in_array( $raw['background_type'], array( 'color', 'gradient', 'image', 'pattern' ), true )
			? $raw['background_type']
			: 'gradient';

		$canvas['background_color'] = $this->color( isset( $raw['background_color'] ) ? $raw['background_color'] : '', '#0F172A' );
		$canvas['gradient_from']    = $this->color( isset( $raw['gradient_from'] ) ? $raw['gradient_from'] : '', '#667EEA' );
		$canvas['gradient_to']      = $this->color( isset( $raw['gradient_to'] ) ? $raw['gradient_to'] : '', '#764BA2' );
		$canvas['gradient_angle']   = isset( $raw['gradient_angle'] ) ? (int) $raw['gradient_angle'] : 135;
		$canvas['background_image'] = isset( $raw['background_image'] ) ? $this->safe_url( (string) $raw['background_image'] ) : '';
		$canvas['background_fit']   = isset( $raw['background_fit'] ) && in_array( $raw['background_fit'], array( 'cover', 'contain', 'stretch' ), true ) ? $raw['background_fit'] : 'cover';
		$canvas['padding']          = isset( $raw['padding'] ) ? max( 0, min( 400, (int) $raw['padding'] ) ) : 48;
		$canvas['grid']             = ! empty( $raw['grid'] ) ? 1 : 0;
		$canvas['rulers']           = ! empty( $raw['rulers'] ) ? 1 : 0;

		if ( isset( $raw['safe_area'] ) && is_array( $raw['safe_area'] ) ) {
			$canvas['safe_area'] = array(
				'top'    => max( 0, min( 600, (int) ( $raw['safe_area']['top'] ?? 64 ) ) ),
				'right'  => max( 0, min( 600, (int) ( $raw['safe_area']['right'] ?? 64 ) ) ),
				'bottom' => max( 0, min( 600, (int) ( $raw['safe_area']['bottom'] ?? 64 ) ) ),
				'left'   => max( 0, min( 600, (int) ( $raw['safe_area']['left'] ?? 64 ) ) ),
			);
		} else {
			$preset              = Formats::get( $canvas['format'] );
			$canvas['safe_area'] = $preset['safe'];
		}

		if ( isset( $raw['overlay'] ) && is_array( $raw['overlay'] ) ) {
			$canvas['overlay'] = array(
				'enabled' => ! empty( $raw['overlay']['enabled'] ),
				'from'    => $this->rgba( isset( $raw['overlay']['from'] ) ? $raw['overlay']['from'] : '', 'rgba(9,12,24,0.05)' ),
				'to'      => $this->rgba( isset( $raw['overlay']['to'] ) ? $raw['overlay']['to'] : '', 'rgba(9,12,24,0.82)' ),
				'angle'   => isset( $raw['overlay']['angle'] ) ? (int) $raw['overlay']['angle'] : 90,
			);
		}

		/* Elements */
		$raw_elements = isset( $document['elements'] ) && is_array( $document['elements'] ) ? $document['elements'] : array();
		$elements     = array();
		$used_ids     = array();

		foreach ( $raw_elements as $key => $element ) {
			$id = isset( $element['id'] ) ? preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $element['id'] ) : preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key );

			if ( '' === $id ) {
				$warnings[] = __( 'An element without an id was skipped.', 'custom-post-image-creator' );
				continue;
			}

			if ( isset( $used_ids[ $id ] ) ) {
				$id .= '_' . substr( md5( (string) $key ), 0, 4 );
			}
			$used_ids[ $id ] = true;

			$clean = $this->sanitize_element( $element, $canvas );

			if ( null === $clean ) {
				$warnings[] = sprintf(
					/* translators: %s: element id */
					__( 'Element "%s" was skipped because its type is not supported.', 'custom-post-image-creator' ),
					$id
				);
				continue;
			}

			$clean['id']      = $id;
			$elements[ $id ]  = $clean;
		}

		if ( empty( $elements ) ) {
			$blank            = self::blank( $canvas['format'] );
			$blank['canvas']  = $canvas;
			$elements         = $blank['elements'];
			$warnings[]       = __( 'The design contained no valid elements, so a default layout was used.', 'custom-post-image-creator' );
		}

		/* Per-format overrides */
		$layouts      = array();
		$raw_layouts  = isset( $document['layout'] ) && is_array( $document['layout'] ) ? $document['layout'] : array();
		$raw_layouts[ $canvas['format'] ] = array( 'elements' => array() );

		foreach ( $raw_layouts as $variant => $layout ) {
			$variant = sanitize_key( (string) $variant );
			if ( '' === $variant ) {
				continue;
			}

			$overrides = array();
			$raw_over  = isset( $layout['elements'] ) && is_array( $layout['elements'] ) ? $layout['elements'] : array();

			foreach ( $raw_over as $id => $override ) {
				if ( ! isset( $elements[ $id ] ) || ! is_array( $override ) ) {
					continue;
				}
				$clean = array();
				foreach ( array( 'x', 'y', 'width', 'height', 'font_size', 'max_font_size', 'min_font_size', 'rotation', 'z', 'opacity', 'border_radius', 'padding_x', 'padding_y', 'max_lines', 'letter_spacing' ) as $prop ) {
					if ( isset( $override[ $prop ] ) ) {
						$clean[ $prop ] = is_numeric( $override[ $prop ] ) ? (float) $override[ $prop ] : 0;
					}
				}
				foreach ( array( 'align', 'vertical_align', 'color', 'background', 'font_family' ) as $prop ) {
					if ( isset( $override[ $prop ] ) ) {
						$clean[ $prop ] = sanitize_text_field( (string) $override[ $prop ] );
					}
				}
				$overrides[ $id ] = $clean;
			}

			$layouts[ $variant ] = array( 'elements' => $overrides );
		}

		/* Brand reference */
		$brand = array( 'id' => 0, 'overrides' => array() );
		if ( isset( $document['brand'] ) && is_array( $document['brand'] ) ) {
			$brand['id'] = isset( $document['brand']['id'] ) ? max( 0, (int) $document['brand']['id'] ) : 0;
			if ( isset( $document['brand']['overrides'] ) && is_array( $document['brand']['overrides'] ) ) {
				foreach ( $document['brand']['overrides'] as $key => $value ) {
					$key = sanitize_key( (string) $key );
					if ( '' === $key ) {
						continue;
					}
					$brand['overrides'][ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
				}
			}
		}

		/* Caption settings */
		$caption = array(
			'include_headline'        => 1,
			'include_first_paragraph' => 1,
			'include_link'            => 1,
			'hashtags'                => '',
		);
		if ( isset( $document['caption'] ) && is_array( $document['caption'] ) ) {
			$caption['include_headline']        = ! empty( $document['caption']['include_headline'] ) ? 1 : 0;
			$caption['include_first_paragraph'] = ! empty( $document['caption']['include_first_paragraph'] ) ? 1 : 0;
			$caption['include_link']            = ! empty( $document['caption']['include_link'] ) ? 1 : 0;
			$caption['hashtags']                = isset( $document['caption']['hashtags'] ) ? sanitize_text_field( (string) $document['caption']['hashtags'] ) : '';
		}

		$meta = isset( $document['meta'] ) && is_array( $document['meta'] ) ? array() : array();
		if ( isset( $document['meta']['variants'] ) && is_array( $document['meta']['variants'] ) ) {
			$meta['variants'] = array_values( array_filter( array_map( 'sanitize_key', $document['meta']['variants'] ) ) );
		}
		$meta['source']   = isset( $document['meta']['source'] ) ? sanitize_key( (string) $document['meta']['source'] ) : 'studio';
		$meta['updated']  = current_time( 'mysql' );

		$clean_document = array(
			'schema'   => self::VERSION,
			'canvas'   => $canvas,
			'elements' => $elements,
			'layout'   => $layouts,
			'brand'    => $brand,
			'caption'  => $caption,
			'meta'     => $meta,
		);

		/**
		 * Filter a validated design document.
		 *
		 * @param array<string,mixed> $clean_document Document.
		 * @param array<string,mixed> $document       Original document.
		 */
		$clean_document = apply_filters( 'cpic/template/validated_document', $clean_document, $document );

		return array(
			'ok'       => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
			'document' => $clean_document,
		);
	}

	/**
	 * Sanitise one element.
	 *
	 * @param mixed               $element Raw element.
	 * @param array<string,mixed> $canvas  Canvas context.
	 * @return array<string,mixed>|null
	 */
	public function sanitize_element( $element, array $canvas ) {
		if ( ! is_array( $element ) ) {
			return null;
		}

		$type = isset( $element['type'] ) ? sanitize_key( (string) $element['type'] ) : 'text';

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return null;
		}

		switch ( $type ) {
			case 'image':
			case 'logo':
			case 'watermark':
			case 'svg':
				$clean = self::default_image();
				$clean['type'] = $type;
				break;
			case 'shape':
			case 'gradient':
			case 'pattern':
				$clean = self::default_shape();
				$clean['type'] = $type;
				break;
			case 'group':
				$clean = self::default_group();
				break;
			default:
				$clean = self::default_text();
		}

		foreach ( $clean as $key => $default ) {
			if ( ! array_key_exists( $key, $element ) ) {
				continue;
			}
			$value = $element[ $key ];

			switch ( $key ) {
				case 'name':
				case 'align':
				case 'vertical_align':
				case 'fit':
				case 'fallback':
				case 'source':
				case 'dynamic_key':
				case 'shape':
				case 'date_format':
				case 'transform':
				case 'font_style':
				case 'background_fit':
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;

				case 'text':
				case 'fallback_text':
					// Design text is painted onto a canvas, never rendered as
					// HTML, so all markup is stripped rather than kses-filtered.
					$clean[ $key ] = wp_strip_all_tags( (string) $value );
					break;

				case 'font_family':
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;

				case 'color':
				case 'border_color':
				case 'stroke_color':
				case 'fill':
				case 'shadow_color':
					$clean[ $key ] = $this->rgba( $value, (string) $default );
					break;

				case 'background':
				case 'gradient_from':
				case 'gradient_to':
					$clean[ $key ] = $this->rgba( $value, (string) $default );
					break;

				case 'url':
				case 'background_image':
					$clean[ $key ] = $this->safe_url( (string) $value );
					break;

				case 'visible':
				case 'locked':
				case 'auto_scale':
				case 'ellipsis':
				case 'uppercase':
				case 'natural_ratio':
					$clean[ $key ] = ! empty( $value ) ? true : false;
					break;

				case 'conditions':
					$clean[ $key ] = is_array( $value ) ? $this->sanitize_conditions( $value ) : array();
					break;

				case 'children':
					$clean[ $key ] = is_array( $value ) ? array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) ) : array();
					break;

				case 'opacity':
					$clean[ $key ] = max( 0, min( 1, (float) $value ) );
					break;

				case 'rotation':
					$clean[ $key ] = max( -360, min( 360, (float) $value ) );
					break;

				case 'focus_x':
				case 'focus_y':
					$clean[ $key ] = max( 0, min( 1, (float) $value ) );
					break;

				case 'x':
					$clean[ $key ] = max( 0, min( 6000, (float) $value ) );
					break;

				case 'y':
					$clean[ $key ] = max( 0, min( 6000, (float) $value ) );
					break;

				case 'width':
				case 'height':
					$clean[ $key ] = max( 0, min( 6000, (float) $value ) );
					break;

				case 'z':
					$clean[ $key ] = max( 0, min( 999, (int) $value ) );
					break;

				default:
					$clean[ $key ] = is_numeric( $value ) ? (float) $value : sanitize_text_field( (string) $value );
			}
		}

		// Element-specific hardening.
		if ( 'shape' === $clean['type'] && ! in_array( $clean['shape'], self::SHAPES, true ) ) {
			$clean['shape'] = 'rect';
		}

		if ( 'text' === $clean['type'] ) {
			$clean['font_size'] = max( 8, min( 400, (int) $clean['font_size'] ) );
			$clean['max_lines'] = max( 0, min( 20, (int) $clean['max_lines'] ) );
			$clean['max_font_size'] = max( $clean['font_size'], min( 400, (int) $clean['max_font_size'] ) );
			$clean['min_font_size'] = max( 6, min( $clean['font_size'], (int) $clean['min_font_size'] ) );

			if ( ! in_array( $clean['source'], self::TEXT_SOURCES, true ) ) {
				$clean['source'] = 'static';
			}
		}

		if ( in_array( $clean['type'], array( 'image', 'logo', 'watermark', 'svg' ), true ) ) {
			$clean['fit'] = in_array( $clean['fit'], array( 'cover', 'contain', 'fill', 'none' ), true ) ? $clean['fit'] : 'cover';
			$clean['fallback'] = in_array( $clean['fallback'], array( 'hide', 'gradient', 'blur' ), true ) ? $clean['fallback'] : 'gradient';
		}

		return $clean;
	}

	/**
	 * Only allow http(s), protocol-relative and site-relative image URLs.
	 *
	 * @param string $url Raw URL.
	 * @return string Empty string when the scheme is not acceptable.
	 */
	public function safe_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		// Block data: and every other scheme except http/https.
		if ( preg_match( '/^([a-z][a-z0-9+\-.]*):/i', $url, $matches ) ) {
			$scheme = strtolower( $matches[1] );

			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				return '';
			}
		}

		return (string) esc_url_raw( $url );
	}

	/**
	 * Sanitise display conditions.
	 *
	 * @param array<string,mixed> $conditions Raw conditions.
	 * @return array<string,mixed>
	 */
	public function sanitize_conditions( array $conditions ) {
		$clean = array();

		foreach ( $conditions as $key => $value ) {
			switch ( $key ) {
				case 'category_in':
				case 'category_not_in':
					$clean[ $key ] = array_values( array_filter( array_map( 'intval', (array) $value ) ) );
					break;
				case 'has_featured_image':
				case 'has_custom_field':
					$clean[ $key ] = ! empty( $value );
					break;
				case 'custom_field_key':
				case 'custom_field_value':
				case 'title_length_min':
				case 'title_length_max':
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;
				default:
					$clean[ sanitize_key( (string) $key ) ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			}
		}

		return $clean;
	}

	/**
	 * Keep a colour string safe for the renderer (hex, rgb, rgba, hsl, none).
	 *
	 * @param mixed  $value    Raw.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public function color( $value, $fallback = '#000000' ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return $fallback;
		}

		if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^rgba?\(\s*[\d\.]+\s*,\s*[\d\.]+\s*,\s*[\d\.]+\s*(,\s*[\d\.]+\s*)?\)$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^hsla?\(\s*[\d\.]+\s*,\s*[\d\.]+%\s*,\s*[\d\.]+%\s*(,\s*[\d\.]+\s*)?\)$/', $value ) ) {
			return $value;
		}

		if ( 'transparent' === strtolower( $value ) ) {
			return 'transparent';
		}

		return $fallback;
	}

	/**
	 * Colour helper that also accepts `rgba()`.
	 *
	 * @param mixed  $value    Raw.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public function rgba( $value, $fallback = '' ) {
		$clean = $this->color( $value, $fallback );
		return '' === $clean ? $fallback : $clean;
	}

	/**
	 * Migrate a 6.x template config into a 7.0 document.
	 *
	 * Legacy shape:
	 *   canvas{width,height,background_*}, elements{featured_image,headline,
	 *   category,date,logo,author,website_name}, shapes[]
	 *
	 * @param array<string,mixed> $legacy Legacy config.
	 * @return array<string,mixed> 7.0 document.
	 */
	public function migrate_legacy( array $legacy ) {
		$canvas_in = isset( $legacy['canvas'] ) && is_array( $legacy['canvas'] ) ? $legacy['canvas'] : array();

		$width  = isset( $canvas_in['width'] ) ? (int) $canvas_in['width'] : 1080;
		$height = isset( $canvas_in['height'] ) ? (int) $canvas_in['height'] : 1080;
		$format = Formats::nearest( $width, $height );

		$document = self::blank( $format );

		$document['canvas']['width']  = $width;
		$document['canvas']['height'] = $height;

		if ( ! empty( $canvas_in['background_type'] ) && 'image' === $canvas_in['background_type'] && ! empty( $canvas_in['background_image'] ) ) {
			$document['canvas']['background_type']  = 'image';
			$document['canvas']['background_image'] = $this->safe_url( (string) $canvas_in['background_image'] );
		} else {
			$document['canvas']['background_type'] = ! empty( $canvas_in['gradient_from'] ) ? 'gradient' : 'color';
		}

		$document['canvas']['background_color'] = $this->color( isset( $canvas_in['background_color'] ) ? $canvas_in['background_color'] : '', '#0F172A' );
		$document['canvas']['gradient_from']    = $this->color( isset( $canvas_in['gradient_from'] ) ? $canvas_in['gradient_from'] : '', '#667EEA' );
		$document['canvas']['gradient_to']      = $this->color( isset( $canvas_in['gradient_to'] ) ? $canvas_in['gradient_to'] : '', '#764BA2' );
		$document['canvas']['padding']          = isset( $canvas_in['padding'] ) ? (int) $canvas_in['padding'] : 48;

		$elements_in = isset( $legacy['elements'] ) && is_array( $legacy['elements'] ) ? $legacy['elements'] : array();

		/* Featured image */
		if ( isset( $elements_in['featured_image'] ) ) {
			$src = $elements_in['featured_image'];
			$el  = self::default_image();
			$el['id']            = 'featured_image';
			$el['name']          = __( 'Featured image', 'custom-post-image-creator' );
			$el['x']             = isset( $src['x'] ) ? (float) $src['x'] : 0;
			$el['y']             = isset( $src['y'] ) ? (float) $src['y'] : 0;
			$el['width']         = isset( $src['width'] ) ? (float) $src['width'] : $width;
			$el['height']        = isset( $src['height'] ) ? (float) $src['height'] : (int) round( $height * 0.6 );
			$el['fit']           = isset( $src['fit'] ) && 'fill' === $src['fit'] ? 'fill' : 'cover';
			$el['border_radius'] = isset( $src['border_radius'] ) ? (float) $src['border_radius'] : 0;
			$el['visible']       = ! isset( $src['enabled'] ) || ! empty( $src['enabled'] );
			$el['z']             = isset( $src['z_index'] ) ? (int) $src['z_index'] : 10;
			$elements['featured_image'] = $el;
			unset( $document['elements']['featured_image'] );
			$document['elements'] = array( 'featured_image' => $el ) + $document['elements'];
		}

		/* Headline */
		if ( isset( $elements_in['headline'] ) ) {
			$src = $elements_in['headline'];
			$el  = self::default_text();
			$el['id']            = 'headline';
			$el['name']          = __( 'Headline', 'custom-post-image-creator' );
			$el['source']        = 'post_title';
			$el['x']             = isset( $src['x'] ) ? (float) $src['x'] : 40;
			$el['y']             = isset( $src['y'] ) ? (float) $src['y'] : (int) round( $height * 0.7 );
			$el['width']         = isset( $src['max_width'] ) ? (float) $src['max_width'] : ( $width - (int) $el['x'] - 40 );
			$el['font_family']   = isset( $src['font'] ) ? (string) $src['font'] : 'Noto Serif Bengali';
			$el['font_size']     = isset( $src['font_size'] ) ? (int) $src['font_size'] : 42;
			$el['max_font_size'] = isset( $src['max_font_size'] ) ? (int) $src['max_font_size'] : $el['font_size'];
			$el['min_font_size'] = isset( $src['min_font_size'] ) ? (int) $src['min_font_size'] : 22;
			$el['color']         = $this->color( isset( $src['color'] ) ? $src['color'] : '', '#FFFFFF' );
			$el['align']         = isset( $src['align'] ) && in_array( $src['align'], array( 'left', 'center', 'right', 'justify' ), true ) ? $src['align'] : 'left';
			$el['max_lines']     = isset( $src['max_lines'] ) ? (int) $src['max_lines'] : 3;
			$el['line_height']   = isset( $src['line_height'] ) ? (float) $src['line_height'] : 1.35;
			$el['letter_spacing'] = isset( $src['letter_spacing'] ) ? (float) $src['letter_spacing'] : 0;
			$el['shadow_color']  = $this->rgba( isset( $src['shadow_color'] ) ? $src['shadow_color'] : '', 'rgba(0,0,0,0.55)' );
			$el['shadow_offset_y'] = isset( $src['shadow_offset'] ) ? (float) $src['shadow_offset'] : 2;
			$el['font_weight']   = ! empty( $src['bold'] ) ? 700 : 400;
			$el['visible']       = ! isset( $src['enabled'] ) || ! empty( $src['enabled'] );
			$el['z']             = isset( $src['z_index'] ) ? (int) $src['z_index'] : 30;
			$document['elements']['headline'] = $el;
		}

		/* Category */
		if ( isset( $elements_in['category'] ) ) {
			$src = $elements_in['category'];
			$el  = self::default_text();
			$el['id']         = 'category';
			$el['name']       = __( 'Category', 'custom-post-image-creator' );
			$el['source']     = 'post_category';
			$el['x']          = isset( $src['x'] ) ? (float) $src['x'] : 40;
			$el['y']          = isset( $src['y'] ) ? (float) $src['y'] : (int) round( $height * 0.55 );
			$el['width']      = isset( $src['max_width'] ) ? (float) $src['max_width'] : ( $width - 80 );
			$el['font_family'] = isset( $src['font'] ) ? (string) $src['font'] : 'Hind Siliguri';
			$el['font_size']  = isset( $src['font_size'] ) ? (int) $src['font_size'] : 24;
			$el['color']      = $this->color( isset( $src['color'] ) ? $src['color'] : '', '#FFFFFF' );
			$el['background'] = $this->rgba( isset( $src['background_color'] ) ? $src['background_color'] : '', '#E53935' );
			$el['padding_x']  = isset( $src['padding_x'] ) ? (float) $src['padding_x'] : 16;
			$el['padding_y']  = isset( $src['padding_y'] ) ? (float) $src['padding_y'] : 6;
			$el['border_radius'] = isset( $src['border_radius'] ) ? (float) $src['border_radius'] : 6;
			$el['max_lines']  = 1;
			$el['font_weight'] = ! empty( $src['bold'] ) ? 700 : 400;
			$el['visible']    = ! isset( $src['enabled'] ) || ! empty( $src['enabled'] );
			$el['z']          = isset( $src['z_index'] ) ? (int) $src['z_index'] : 20;
			$document['elements']['category'] = $el;
		}

		/* Date */
		if ( isset( $elements_in['date'] ) ) {
			$src = $elements_in['date'];
			$el  = self::default_text();
			$el['id']         = 'date';
			$el['name']       = __( 'Date', 'custom-post-image-creator' );
			$el['source']     = 'post_date';
			$el['x']          = isset( $src['x'] ) ? (float) $src['x'] : 40;
			$el['y']          = isset( $src['y'] ) ? (float) $src['y'] : (int) round( $height * 0.88 );
			$el['width']      = isset( $src['max_width'] ) ? (float) $src['max_width'] : ( $width - 80 );
			$el['font_family'] = isset( $src['font'] ) ? (string) $src['font'] : 'Hind Siliguri';
			$el['font_size']  = isset( $src['font_size'] ) ? (int) $src['font_size'] : 28;
			$el['color']      = $this->color( isset( $src['color'] ) ? $src['color'] : '', '#FFFFFF' );
			$el['background'] = $this->rgba( isset( $src['background_color'] ) ? $src['background_color'] : '', '#E53935' );
			$el['padding_x']  = isset( $src['padding_x'] ) ? (float) $src['padding_x'] : 16;
			$el['padding_y']  = isset( $src['padding_y'] ) ? (float) $src['padding_y'] : 6;
			$el['border_radius'] = isset( $src['border_radius'] ) ? (float) $src['border_radius'] : 6;
			$el['max_lines']  = 1;
			$el['date_format'] = isset( $src['format'] ) && 'bangla' === $src['format'] ? 'bangla_long' : 'site';
			$el['visible']    = ! isset( $src['enabled'] ) || ! empty( $src['enabled'] );
			$el['z']          = isset( $src['z_index'] ) ? (int) $src['z_index'] : 25;
			$document['elements']['date'] = $el;
		}

		/* Logo */
		if ( isset( $elements_in['logo'] ) ) {
			$src = $elements_in['logo'];
			$el  = self::default_image();
			$el['id']          = 'logo';
			$el['type']        = 'logo';
			$el['name']        = __( 'Logo', 'custom-post-image-creator' );
			$el['source']      = 'custom_url';
			$el['url']         = isset( $src['source'] ) ? $this->safe_url( (string) $src['source'] ) : '';
			$el['x']           = isset( $src['x'] ) ? (float) $src['x'] : 40;
			$el['y']           = isset( $src['y'] ) ? (float) $src['y'] : 40;
			$el['width']       = isset( $src['width'] ) ? (float) $src['width'] : 120;
			$el['height']      = 0;
			$el['visible']     = ! isset( $src['enabled'] ) || ! empty( $src['enabled'] );
			$el['z']           = isset( $src['z_index'] ) ? (int) $src['z_index'] : 5;
			if ( '' === $el['url'] ) {
				$el['source'] = 'brand_logo';
			}
			$document['elements']['logo'] = $el;
		}

		/* Author / site name */
		if ( isset( $elements_in['author'] ) && ! empty( $elements_in['author']['enabled'] ) ) {
			$src = $elements_in['author'];
			$el  = self::default_text();
			$el['id']     = 'author';
			$el['name']   = __( 'Author', 'custom-post-image-creator' );
			$el['source'] = 'post_author';
			$el['x']      = isset( $src['x'] ) ? (float) $src['x'] : 40;
			$el['y']      = isset( $src['y'] ) ? (float) $src['y'] : (int) round( $height * 0.92 );
			$el['width']  = $width - 80;
			$el['font_family'] = isset( $src['font'] ) ? (string) $src['font'] : 'Hind Siliguri';
			$el['font_size']   = isset( $src['font_size'] ) ? (int) $src['font_size'] : 20;
			$el['color']  = $this->color( isset( $src['color'] ) ? $src['color'] : '', '#FFFFFF' );
			$el['z']      = isset( $src['z_index'] ) ? (int) $src['z_index'] : 26;
			$document['elements']['author'] = $el;
		}

		if ( isset( $elements_in['website_name'] ) && ! empty( $elements_in['website_name']['enabled'] ) ) {
			$src = $elements_in['website_name'];
			$el  = self::default_text();
			$el['id']     = 'site_name';
			$el['name']   = __( 'Site name', 'custom-post-image-creator' );
			$el['source'] = 'site_name';
			$el['x']      = isset( $src['x'] ) ? (float) $src['x'] : 40;
			$el['y']      = isset( $src['y'] ) ? (float) $src['y'] : (int) round( $height * 0.95 );
			$el['width']  = $width - 80;
			$el['font_family'] = isset( $src['font'] ) ? (string) $src['font'] : 'Noto Serif Bengali';
			$el['font_size']   = isset( $src['font_size'] ) ? (int) $src['font_size'] : 20;
			$el['color']  = $this->color( isset( $src['color'] ) ? $src['color'] : '', '#FFFFFF' );
			$el['z']      = isset( $src['z_index'] ) ? (int) $src['z_index'] : 27;
			$document['elements']['site_name'] = $el;
		}

		/* Legacy decorative shapes */
		if ( ! empty( $legacy['shapes'] ) && is_array( $legacy['shapes'] ) ) {
			$index = 0;
			foreach ( $legacy['shapes'] as $shape ) {
				if ( ! is_array( $shape ) ) {
					continue;
				}
				$el = $this->sanitize_element( array_merge( array( 'type' => 'shape' ), $shape ), $document['canvas'] );
				if ( null === $el ) {
					continue;
				}
				$el['id']   = 'legacy_shape_' . $index;
				$el['name'] = sprintf(
					/* translators: %d: shape index */
					__( 'Legacy shape %d', 'custom-post-image-creator' ),
					$index + 1
				);
				$document['elements'][ $el['id'] ] = $el;
				$index++;
			}
		}

		/* Caption settings carry over */
		if ( isset( $legacy['caption'] ) && is_array( $legacy['caption'] ) ) {
			$document['caption']['include_headline']        = ! empty( $legacy['caption']['include_headline'] ) ? 1 : 0;
			$document['caption']['include_first_paragraph'] = isset( $legacy['caption']['include_first_paragraph'] ) ? ( ! empty( $legacy['caption']['include_first_paragraph'] ) ? 1 : 0 ) : 1;
		}

		$document['meta']['source'] = 'migrated-6.x';

		$validated = $this->validate( $document );

		return $validated['document'];
	}

	/**
	 * Effective element list for a format (base + overrides applied).
	 *
	 * @param array<string,mixed> $document Document.
	 * @param string              $format   Format key.
	 * @return array<int,array<string,mixed>> Elements sorted by z-index.
	 */
	public function elements_for_format( array $document, $format ) {
		$elements = isset( $document['elements'] ) && is_array( $document['elements'] ) ? $document['elements'] : array();
		$layout   = isset( $document['layout'][ $format ]['elements'] ) && is_array( $document['layout'][ $format ]['elements'] ) ? $document['layout'][ $format ]['elements'] : array();

		$out = array();

		foreach ( $elements as $id => $element ) {
			$merged = $element;
			if ( isset( $layout[ $id ] ) && is_array( $layout[ $id ] ) ) {
				$merged = array_merge( $element, $layout[ $id ] );
			}
			$merged['id'] = (string) $id;
			$out[]        = $merged;
		}

		usort(
			$out,
			static function ( $a, $b ) {
				$za = isset( $a['z'] ) ? (float) $a['z'] : 0;
				$zb = isset( $b['z'] ) ? (float) $b['z'] : 0;
				if ( $za === $zb ) {
					return 0;
				}
				return $za < $zb ? -1 : 1;
			}
		);

		return $out;
	}

	/**
	 * Canvas dimensions for a target format.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param string              $format   Format key.
	 * @return array{width:int,height:int,format:string,safe:array<string,int>}
	 */
	public function canvas_for_format( array $document, $format ) {
		$canvas = isset( $document['canvas'] ) && is_array( $document['canvas'] ) ? $document['canvas'] : self::default_canvas();
		$base   = isset( $canvas['format'] ) ? (string) $canvas['format'] : 'square';

		if ( $format === $base ) {
			return array(
				'width'  => (int) $canvas['width'],
				'height' => (int) $canvas['height'],
				'format' => $base,
				'safe'   => isset( $canvas['safe_area'] ) ? $canvas['safe_area'] : array( 'top' => 64, 'right' => 64, 'bottom' => 64, 'left' => 64 ),
			);
		}

		$preset = Formats::get( $format );

		return array(
			'width'  => (int) $preset['width'],
			'height' => (int) $preset['height'],
			'format' => (string) $preset['key'],
			'safe'   => $preset['safe'],
		);
	}

	/**
	 * Which formats a document can render.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return array<int,string>
	 */
	public function variants( array $document ) {
		$variants = array();

		if ( isset( $document['canvas']['format'] ) ) {
			$variants[] = (string) $document['canvas']['format'];
		}

		if ( isset( $document['layout'] ) && is_array( $document['layout'] ) ) {
			foreach ( array_keys( $document['layout'] ) as $key ) {
				if ( ! in_array( $key, $variants, true ) ) {
					$variants[] = (string) $key;
				}
			}
		}

		return array_values( array_filter( $variants ) );
	}

	/**
	 * Summary used by list screens.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return array<string,mixed>
	 */
	public function summary( array $document ) {
		$canvas = isset( $document['canvas'] ) ? $document['canvas'] : array();

		return array(
			'format'   => isset( $canvas['format'] ) ? $canvas['format'] : 'square',
			'width'    => isset( $canvas['width'] ) ? (int) $canvas['width'] : 1080,
			'height'   => isset( $canvas['height'] ) ? (int) $canvas['height'] : 1080,
			'elements' => isset( $document['elements'] ) ? count( $document['elements'] ) : 0,
			'variants' => $this->variants( $document ),
			'fonts'    => $this->fonts_used( $document ),
		);
	}

	/**
	 * Fonts referenced by a document (for preloading).
	 *
	 * @param array<string,mixed> $document Document.
	 * @return array<int,string>
	 */
	public function fonts_used( array $document ) {
		$fonts = array();

		if ( isset( $document['elements'] ) && is_array( $document['elements'] ) ) {
			foreach ( $document['elements'] as $element ) {
				if ( 'text' === ( $element['type'] ?? '' ) && ! empty( $element['font_family'] ) ) {
					if ( ! in_array( $element['font_family'], $fonts, true ) ) {
						$fonts[] = (string) $element['font_family'];
					}
				}
			}
		}

		return $fonts;
	}
}
