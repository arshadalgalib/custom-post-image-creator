<?php
/**
 * Render plan → SVG document.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Rendering;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serialises a render plan into an SVG document.
 *
 * Why SVG, and not GD: Bengali needs a shaping engine. GD's `imagettftext()`
 * writes glyphs one code point at a time, so conjuncts (ক্ষ, ঞ্জ) and vowel
 * signs that reorder around their consonant come out wrong or missing. Every
 * host rasterizer worth using — ImageMagick with its SVG delegate, librsvg,
 * Inkscape, headless Chromium — runs Pango/HarfBuzz, so a *text* element in an
 * SVG is shaped correctly by the host.
 *
 * This class therefore emits real `<text>`, never pre-positioned glyph runs:
 * the whole point is to hand the shaping to the rasterizer.
 *
 * Coordinates in a plan are CSS pixels relative to the canvas, which is also
 * SVG's user space, so no transformation is needed beyond the canvas box.
 */
final class SvgPlanSerializer {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Filters collected while walking layers.
	 *
	 * `<defs>` is emitted near the top of the document but a filter can only be
	 * described once its layer is known, so they are buffered here and flushed
	 * into the final `<defs>` before the document is assembled.
	 *
	 * @var array<int,string>
	 */
	private $filters = array();

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Serialise a plan.
	 *
	 * @param array<string,mixed> $plan Render plan.
	 * @return string SVG markup.
	 */
	public function serialize( array $plan ) {
		$this->filters = array();

		$width  = max( 1, (int) ( isset( $plan['width'] ) ? $plan['width'] : 1080 ) );
		$height = max( 1, (int) ( isset( $plan['height'] ) ? $plan['height'] : 1080 ) );

		$parts = array();

		$parts[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$parts[] = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d">',
			$width,
			$height
		);

		// Layers are serialised first because they may register filters.
		$layers = array();

		foreach ( $this->layers( $plan ) as $layer ) {
			$layers[] = $this->layer( $layer );
		}

		$parts[] = $this->defs( $plan );
		$parts[] = $this->background( $plan, $width, $height );

		foreach ( $layers as $layer ) {
			$parts[] = $layer;
		}

		$parts[] = '</svg>';

		return implode( "\n", array_filter( $parts, static function ( $part ) {
			return '' !== $part;
		} ) );
	}

	/**
	 * Layers in paint order.
	 *
	 * @param array<string,mixed> $plan Plan.
	 * @return array<int,array<string,mixed>>
	 */
	private function layers( array $plan ) {
		$layers = isset( $plan['layers'] ) && is_array( $plan['layers'] ) ? $plan['layers'] : array();

		usort(
			$layers,
			static function ( $a, $b ) {
				return ( isset( $a['z'] ) ? (float) $a['z'] : 0 ) <=> ( isset( $b['z'] ) ? (float) $b['z'] : 0 );
			}
		);

		return $layers;
	}

	/**
	 * Shared gradient definitions.
	 *
	 * @param array<string,mixed> $plan Plan.
	 * @return string
	 */
	private function defs( array $plan ) {
		$canvas  = isset( $plan['canvas'] ) && is_array( $plan['canvas'] ) ? $plan['canvas'] : array();
		$defs    = array();
		$overlay = isset( $canvas['overlay'] ) && is_array( $canvas['overlay'] ) ? $canvas['overlay'] : array();

		foreach ( $this->filters as $filter ) {
			$defs[] = $filter;
		}

		if ( 'gradient' === $this->canvas_type( $canvas ) ) {
			$defs[] = sprintf(
				'<linearGradient id="cpic-canvas-gradient" gradientTransform="rotate(%s 0.5 0.5)">%s%s</linearGradient>',
				esc_attr( $this->num( isset( $canvas['gradient_angle'] ) ? $canvas['gradient_angle'] : 135 ) ),
				$this->stop( 0, isset( $canvas['gradient_from'] ) ? (string) $canvas['gradient_from'] : '#667EEA' ),
				$this->stop( 1, isset( $canvas['gradient_to'] ) ? (string) $canvas['gradient_to'] : '#764BA2' )
			);
		}

		if ( ! empty( $overlay['enabled'] ) ) {
			$defs[] = sprintf(
				'<linearGradient id="cpic-canvas-overlay" x1="0" y1="0" x2="0" y2="1">%s%s</linearGradient>',
				$this->stop( 0, isset( $overlay['from'] ) ? (string) $overlay['from'] : 'rgba(9,12,24,0.05)' ),
				$this->stop( 1, isset( $overlay['to'] ) ? (string) $overlay['to'] : 'rgba(9,12,24,0.82)' )
			);
		}

		// One gradient per layer that asks for one.
		foreach ( $this->layers( $plan ) as $layer ) {
			$shape = isset( $layer['shape'] ) && is_array( $layer['shape'] ) ? $layer['shape'] : array();

			if ( empty( $shape['gradient_from'] ) || empty( $shape['gradient_to'] ) ) {
				continue;
			}

			$defs[] = sprintf(
				'<linearGradient id="cpic-fill-%1$s" gradientTransform="rotate(%2$s 0.5 0.5)">%3$s%4$s</linearGradient>',
				esc_attr( $this->safe_id( isset( $layer['id'] ) ? (string) $layer['id'] : 'layer' ) ),
				esc_attr( $this->num( isset( $shape['gradient_angle'] ) ? $shape['gradient_angle'] : 90 ) ),
				$this->stop( 0, (string) $shape['gradient_from'] ),
				$this->stop( 1, (string) $shape['gradient_to'] )
			);
		}

		return empty( $defs ) ? '' : '<defs>' . implode( '', $defs ) . '</defs>';
	}

	/**
	 * One gradient stop.
	 *
	 * @param float  $offset Offset 0..1.
	 * @param string $colour Colour.
	 * @return string
	 */
	private function stop( $offset, $colour ) {
		return sprintf(
			'<stop offset="%s" stop-color="%s" stop-opacity="%s"/>',
			esc_attr( $this->num( $offset ) ),
			esc_attr( $this->colour_for_css( $colour ) ),
			esc_attr( $this->num( $this->colour_opacity( $colour ) ) )
		);
	}

	/**
	 * Canvas background.
	 *
	 * @param array<string,mixed> $plan   Plan.
	 * @param int                 $width  Width.
	 * @param int                 $height Height.
	 * @return string
	 */
	private function background( array $plan, $width, $height ) {
		$canvas = isset( $plan['canvas'] ) && is_array( $plan['canvas'] ) ? $plan['canvas'] : array();
		$type   = $this->canvas_type( $canvas );
		$parts  = array();

		if ( 'image' === $type && ! empty( $canvas['background_image'] ) ) {
			$path = $this->resolve_local( (string) $canvas['background_image'] );

			if ( '' !== $path ) {
				$parts[] = sprintf(
					'<image x="0" y="0" width="%s" height="%s" preserveAspectRatio="xMidYMid slice" xlink:href="%s"/>',
					esc_attr( (string) $width ),
					esc_attr( (string) $height ),
					esc_attr( $path )
				);
			}
		}

		if ( 'gradient' === $type ) {
			$parts[] = sprintf( '<rect x="0" y="0" width="%1$s" height="%2$s" fill="url(#cpic-canvas-gradient)"/>', esc_attr( (string) $width ), esc_attr( (string) $height ) );
		} elseif ( 'image' !== $type || empty( $parts ) ) {
			$colour = isset( $canvas['background_color'] ) ? (string) $canvas['background_color'] : '#0F172A';
			$parts[] = sprintf(
				'<rect x="0" y="0" width="%1$s" height="%2$s" fill="%3$s" fill-opacity="%4$s"/>',
				esc_attr( (string) $width ),
				esc_attr( (string) $height ),
				esc_attr( $this->colour_for_css( $colour ) ),
				esc_attr( $this->num( $this->colour_opacity( $colour ) ) )
			);
		}

		$overlay = isset( $canvas['overlay'] ) && is_array( $canvas['overlay'] ) ? $canvas['overlay'] : array();

		if ( ! empty( $overlay['enabled'] ) ) {
			$parts[] = sprintf( '<rect x="0" y="0" width="%1$s" height="%2$s" fill="url(#cpic-canvas-overlay)"/>', esc_attr( (string) $width ), esc_attr( (string) $height ) );
		}

		return implode( "\n", $parts );
	}

	/**
	 * Canvas background type.
	 *
	 * @param array<string,mixed> $canvas Canvas config.
	 * @return string
	 */
	private function canvas_type( array $canvas ) {
		$type = isset( $canvas['background_type'] ) ? (string) $canvas['background_type'] : 'gradient';

		return in_array( $type, array( 'gradient', 'image', 'color' ), true ) ? $type : 'gradient';
	}

	/**
	 * One layer.
	 *
	 * @param array<string,mixed> $layer Layer.
	 * @return string
	 */
	private function layer( array $layer ) {
		$type      = isset( $layer['type'] ) ? (string) $layer['type'] : '';
		$opacity   = $this->num( isset( $layer['opacity'] ) ? $layer['opacity'] : 1 );
		$transform = $this->transform( $layer );

		$inner = '';

		switch ( $type ) {
			case 'text':
				$inner = $this->text( $layer );
				break;
			case 'shape':
			case 'gradient':
			case 'pattern':
				$inner = $this->shape( $layer );
				break;
			case 'image':
			case 'logo':
			case 'watermark':
			case 'svg':
				$inner = $this->image( $layer );
				break;
		}

		if ( '' === $inner ) {
			return '';
		}

		return sprintf(
			'<g id="%s" opacity="%s"%s>%s</g>',
			esc_attr( $this->safe_id( isset( $layer['id'] ) ? (string) $layer['id'] : 'layer' ) ),
			esc_attr( $opacity ),
			$transform,
			$inner
		);
	}

	/**
	 * Rotation transform, if the layer has one.
	 *
	 * @param array<string,mixed> $layer Layer.
	 * @return string
	 */
	private function transform( array $layer ) {
		$rotation = isset( $layer['rotation'] ) ? (float) $layer['rotation'] : 0.0;

		if ( 0.0 === $rotation ) {
			return '';
		}

		$cx = (float) ( isset( $layer['x'] ) ? $layer['x'] : 0 ) + ( (float) ( isset( $layer['width'] ) ? $layer['width'] : 0 ) / 2 );
		$cy = (float) ( isset( $layer['y'] ) ? $layer['y'] : 0 ) + ( (float) ( isset( $layer['height'] ) ? $layer['height'] : 0 ) / 2 );

		return sprintf( ' transform="rotate(%s %s %s)"', esc_attr( $this->num( $rotation ) ), esc_attr( $this->num( $cx ) ), esc_attr( $this->num( $cy ) ) );
	}

	/**
	 * A text layer.
	 *
	 * Emitted as a real `<text>` element with `<tspan>` lines. Nothing here
	 * positions individual glyphs: that is the shaping engine's job, and doing
	 * it ourselves is exactly what produced broken Bengali.
	 *
	 * @param array<string,mixed> $layer Layer.
	 * @return string
	 */
	private function text( array $layer ) {
		$text  = isset( $layer['text'] ) && is_array( $layer['text'] ) ? $layer['text'] : array();
		$value = isset( $text['value'] ) ? trim( (string) $text['value'] ) : '';

		// An empty block paints nothing at all — not even its background plate.
		// The studio's canvas painter returns before drawing for the same
		// reason, and a plate with no text on it is not part of the design.
		if ( '' === $value ) {
			return '';
		}

		$box_x     = (float) ( isset( $layer['x'] ) ? $layer['x'] : 0 );
		$box_y     = (float) ( isset( $layer['y'] ) ? $layer['y'] : 0 );
		$box_w     = (float) ( isset( $layer['width'] ) ? $layer['width'] : 0 );
		$box_h     = (float) ( isset( $layer['height'] ) ? $layer['height'] : 0 );
		$factor    = (float) ( isset( $text['line_height'] ) ? $text['line_height'] : 1.3 );
		$align     = isset( $text['align'] ) ? (string) $text['align'] : 'left';
		$colour    = isset( $text['color'] ) ? (string) $text['color'] : '#FFFFFF';
		$padding_x = (float) ( isset( $text['padding_x'] ) ? $text['padding_x'] : 0 );
		$padding_y = (float) ( isset( $text['padding_y'] ) ? $text['padding_y'] : 0 );
		$parts     = array();

		if ( ! empty( $text['background'] ) ) {
			$bg      = (string) $text['background'];
			$parts[] = sprintf(
				'<rect x="%1$s" y="%2$s" width="%3$s" height="%4$s" fill="%5$s" fill-opacity="%6$s"%7$s/>',
				esc_attr( $this->num( $box_x ) ),
				esc_attr( $this->num( $box_y ) ),
				esc_attr( $this->num( max( 1.0, $box_w ) ) ),
				esc_attr( $this->num( max( $box_h, 1.0 ) ) ),
				esc_attr( $this->colour_for_css( $bg ) ),
				esc_attr( $this->num( $this->colour_opacity( $bg ) ) ),
				$this->radius_attr( isset( $text['border_radius'] ) ? (float) $text['border_radius'] : 0 )
			);
		}

		if ( ! empty( $text['uppercase'] ) ) {
			$value = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
		}

		$font_file = $this->font_file( isset( $text['font_family'] ) ? (string) $text['font_family'] : '' );

		// Mirrors the canvas painter: padding shrinks the box, a zero height
		// means "no box" so the block can never overflow it.
		$inner_w = max( 1.0, $box_w - $padding_x * 2 );
		$inner_h = $box_h > 0 ? $box_h : INF;

		$fit = $this->fit_text( $value, $text, $inner_w, $inner_h, $font_file );

		$size   = $fit['size'];
		$lines  = $fit['lines'];
		$spans  = '';
		$line_h = $size * $factor;

		/*
		 * Vertical alignment inside the box, matching the canvas painter.
		 *
		 * A box taller than the block offers spare room; middle and bottom
		 * alignment consume it. An auto-height box has no spare room, so the
		 * block simply starts where it was placed.
		 */
		$inner_top = $box_y + $padding_y;

		if ( $box_h > ( count( $lines ) * $line_h ) + $padding_y * 2 ) {
			$valign = isset( $text['vertical_align'] ) ? (string) $text['vertical_align'] : 'top';

			if ( 'middle' === $valign || 'center' === $valign ) {
				$inner_top = $box_y + ( ( $box_h - count( $lines ) * $line_h ) / 2 );
			} elseif ( 'bottom' === $valign ) {
				$inner_top = $box_y + $box_h - count( $lines ) * $line_h - $padding_y;
			}
		}

		// The first baseline sits one font-size below the block top, which is
		// where both the canvas painter and a shaping engine put it.
		$first_baseline = $inner_top + $size;
		$text_x         = $box_x + $padding_x;

		if ( 'center' === $align || 'middle' === $align ) {
			$anchor = 'middle';
			$text_x = $box_x + $padding_x + ( $inner_w / 2 );
		} elseif ( 'right' === $align ) {
			$anchor = 'end';
			$text_x = $box_x + $padding_x + $inner_w;
		} else {
			$anchor = 'start';
		}

		$font_family = isset( $text['font_family'] ) ? (string) $text['font_family'] : '';
		$weight      = isset( $text['font_weight'] ) ? (int) $text['font_weight'] : 400;

		$attributes = array(
			'x="' . esc_attr( $this->num( $text_x ) ) . '"',
			'y="' . esc_attr( $this->num( $first_baseline ) ) . '"',
			'font-family="' . esc_attr( $this->font_stack( $font_family ) ) . '"',
			'font-size="' . esc_attr( $this->num( $size ) ) . '"',
			'font-weight="' . esc_attr( (string) $weight ) . '"',
			'fill="' . esc_attr( $this->colour_for_css( $colour ) ) . '"',
			'fill-opacity="' . esc_attr( $this->num( $this->colour_opacity( $colour ) ) ) . '"',
			'text-anchor="' . esc_attr( $anchor ) . '"',
			'xml:space="preserve"',
		);

		if ( ! empty( $text['letter_spacing'] ) ) {
			$attributes[] = 'letter-spacing="' . esc_attr( $this->num( $text['letter_spacing'] ) ) . '"';
		}

		if ( 'italic' === ( isset( $text['font_style'] ) ? (string) $text['font_style'] : 'normal' ) ) {
			$attributes[] = 'font-style="italic"';
		}

		// Shadow, stroke and faux-bold are decoration, not layout, so they are
		// applied to the finished groups rather than to each line.
		$decoration = $this->text_decoration( $text, $size );

		if ( '' !== $decoration ) {
			$attributes[] = $decoration;
		}

		foreach ( $lines as $index => $line ) {
			$spans .= sprintf(
				'<tspan x="%s" dy="%s">%s</tspan>',
				esc_attr( $this->num( $text_x ) ),
				esc_attr( $this->num( 0 === $index ? 0 : $line_h ) ),
				esc_html( (string) $line )
			);
		}

		$parts[] = '<text ' . implode( ' ', $attributes ) . '>' . $spans . '</text>';

		return implode( "\n", $parts );
	}

	/**
	 * Find the font size and line breaks that fit a text block.
	 *
	 * This is a direct port of the studio's canvas painter, so a design laid
	 * out in the browser and the same design rendered on the server break lines
	 * and choose a size identically. Any divergence here shows up as a design
	 * that reflows the moment it is published.
	 *
	 * @param string              $value     Text.
	 * @param array<string,mixed> $text      Text properties.
	 * @param float               $inner_w   Usable width.
	 * @param float               $inner_h   Usable height (INF for auto).
	 * @param string              $font_file Font file for metrics.
	 * @return array{size:float,lines:array<int,string>}
	 */
	private function fit_text( $value, array $text, $inner_w, $inner_h, $font_file ) {
		$size      = max( 1.0, (float) ( isset( $text['font_size'] ) ? $text['font_size'] : 40 ) );
		$min_size  = max( 6.0, (float) ( isset( $text['min_font_size'] ) ? $text['min_font_size'] : max( 10.0, $size * 0.4 ) ) );
		$max_size  = max( $size, (float) ( isset( $text['max_font_size'] ) ? $text['max_font_size'] : $size ) );
		$factor    = (float) ( isset( $text['line_height'] ) ? $text['line_height'] : 1.3 );
		$max_lines = isset( $text['max_lines'] ) ? (int) $text['max_lines'] : 0;

		// A single word wider than the box cannot be wrapped, so it has to be
		// shrunk; once at the minimum size it is broken mid-word instead.
		$fits = function ( $candidate ) use ( $value, $inner_w, $inner_h, $factor, $max_lines, $font_file ) {
			$lines  = $this->wrap( $value, $candidate, $inner_w, $max_lines, $font_file );
			$widest = 0.0;

			foreach ( $lines as $line ) {
				$widest = max( $widest, $this->estimate_width( $line, $candidate, $font_file ) );
			}

			return array(
				'lines'    => $lines,
				'widest'   => $widest,
				'too_wide' => $widest > $inner_w + 1,
				'overflow' => ( count( $lines ) * $factor * $candidate ) > $inner_h + 1,
			);
		};

		$fit = $fits( $size );

		// Grow into a box that has room, as the canvas does. Without a box
		// there is nothing to grow into, so the authored size is kept.
		if ( ! empty( $text['auto_scale'] ) && is_finite( $inner_h ) && $size < $max_size ) {
			$grown = $size;

			for ( $candidate = $size + 2; $candidate <= $max_size; $candidate += 2 ) {
				$trial = $fits( $candidate );

				if ( $trial['overflow'] || $trial['too_wide'] ) {
					break;
				}

				$grown = $candidate;
			}

			if ( $grown !== $size ) {
				$size = $grown;
				$fit  = $fits( $size );
			}
		}

		// Shrink until it fits, never below the minimum.
		while ( $size > $min_size && ( $fit['overflow'] || $fit['too_wide'] ) ) {
			$size = max( $min_size, $size - 2 );
			$fit  = $fits( $size );
		}

		return array(
			'size'  => $size,
			'lines' => $fit['lines'],
		);
	}

	/**
	 * Break text into lines that fit a width, exactly as the canvas does.
	 *
	 * Wrapping happens here rather than in the rasterizer because SVG has no
	 * automatic line breaking: a `<text>` element would otherwise run off the
	 * edge in one long line.
	 *
	 * @param string $text      Text.
	 * @param float  $size      Font size.
	 * @param float  $max_width Width budget.
	 * @param int    $max_lines Line limit (0 = unlimited).
	 * @param string $font_file Font file for metrics.
	 * @return array<int,string>
	 */
	private function wrap( $text, $size, $max_width, $max_lines = 0, $font_file = '' ) {
		$max_width = (float) $max_width;

		if ( $max_width <= 0 ) {
			return array( (string) $text );
		}

		$lines = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $paragraph ) {
			$words = preg_split( '/\s+/u', (string) $paragraph, -1, PREG_SPLIT_NO_EMPTY );

			if ( ! is_array( $words ) || array() === $words ) {
				$lines[] = '';
				continue;
			}

			$current = '';

			foreach ( $words as $word ) {
				$candidate = '' === $current ? $word : $current . ' ' . $word;

				if ( $this->estimate_width( $candidate, $size, $font_file ) <= $max_width ) {
					$current = $candidate;
					continue;
				}

				// A word too long for the line is broken by character, which
				// Bengali runs and URLs both need.
				if ( '' === $current ) {
					$pieces = $this->break_word( $word, $size, $max_width, $font_file );

					foreach ( array_slice( $pieces, 0, -1 ) as $piece ) {
						$lines[] = $piece;
					}

					$current = (string) end( $pieces );
					continue;
				}

				$lines[] = $current;

				if ( $this->estimate_width( $word, $size, $font_file ) > $max_width ) {
					$pieces = $this->break_word( $word, $size, $max_width, $font_file );

					foreach ( array_slice( $pieces, 0, -1 ) as $piece ) {
						$lines[] = $piece;
					}

					$current = (string) end( $pieces );
					continue;
				}

				$current = $word;
			}

			if ( '' !== $current ) {
				$lines[] = $current;
			}
		}

		if ( $max_lines > 0 && count( $lines ) > $max_lines ) {
			$lines = array_slice( $lines, 0, $max_lines );

			// An ellipsis tells the reader the post title was cut short,
			// rather than silently dropping the end of the sentence.
			$last              = count( $lines ) - 1;
			$lines[ $last ]    = preg_replace( '/\s+\S*$/u', '', (string) $lines[ $last ] ) . '…';
		}

		return array() === $lines ? array( (string) $text ) : $lines;
	}

	/**
	 * Split one over-long word into pieces that each fit.
	 *
	 * @param string $word      Word.
	 * @param float  $size      Font size.
	 * @param float  $max_width Width budget.
	 * @param string $font_file Font file for metrics.
	 * @return array<int,string>
	 */
	private function break_word( $word, $size, $max_width, $font_file ) {
		$characters = function_exists( 'mb_str_split' ) ? mb_str_split( (string) $word, 1, 'UTF-8' ) : str_split( (string) $word );
		$pieces     = array();
		$piece      = '';

		foreach ( (array) $characters as $character ) {
			$candidate = $piece . $character;

			if ( '' !== $piece && $this->estimate_width( $candidate, $size, $font_file ) > $max_width ) {
				$pieces[] = $piece;
				$piece    = $character;
				continue;
			}

			$piece = $candidate;
		}

		if ( '' !== $piece ) {
			$pieces[] = $piece;
		}

		return array() === $pieces ? array( (string) $word ) : $pieces;
	}

	/**
	 * Shadow, stroke and faux-bold for a text block.
	 *
	 * @param array<string,mixed> $text Text properties.
	 * @param float               $size Font size.
	 * @return string Attribute fragment, empty when there is nothing to add.
	 */
	private function text_decoration( array $text, $size ) {
		$attributes = array();

		$blur = (float) ( isset( $text['shadow_blur'] ) ? $text['shadow_blur'] : 0 );

		if ( ! empty( $text['shadow_color'] ) && $blur > 0 ) {
			$id    = 'cpic-shadow-' . ( count( $this->filters ) + 1 );
			$dx    = (float) ( isset( $text['shadow_offset_x'] ) ? $text['shadow_offset_x'] : 0 );
			$dy    = (float) ( isset( $text['shadow_offset_y'] ) ? $text['shadow_offset_y'] : 0 );
			$alpha = $this->colour_opacity( (string) $text['shadow_color'] );

			/*
			 * Built from primitives rather than `feDropShadow`, because older
			 * librsvg builds do not implement it and would drop the text
			 * entirely instead of ignoring the filter.
			 *
			 * The filter region is padded so a blur is not clipped at the
			 * element bounds — the default region would cut a 12px shadow off
			 * at the edge of every line.
			 */
			$this->filters[] = sprintf(
				'<filter id="%1$s" x="-50%%" y="-50%%" width="200%%" height="200%%" color-interpolation-filters="sRGB">'
					. '<feGaussianBlur in="SourceAlpha" stdDeviation="%2$s" result="cpic-blur"/>'
					. '<feOffset in="cpic-blur" dx="%3$s" dy="%4$s" result="cpic-offset"/>'
					. '<feFlood flood-color="%5$s" flood-opacity="%6$s" result="cpic-colour"/>'
					. '<feComposite in="cpic-colour" in2="cpic-offset" operator="in" result="cpic-shadow"/>'
					. '<feMerge><feMergeNode in="cpic-shadow"/><feMergeNode in="SourceGraphic"/></feMerge>'
					. '</filter>',
				esc_attr( $id ),
				esc_attr( $this->num( $blur / 2 ) ),
				esc_attr( $this->num( $dx ) ),
				esc_attr( $this->num( $dy ) ),
				esc_attr( $this->colour_for_css( (string) $text['shadow_color'] ) ),
				esc_attr( $this->num( $alpha ) )
			);

			$attributes[] = 'filter="url(#' . esc_attr( $id ) . ')"';
		}

		if ( ! empty( $text['stroke_color'] ) && (float) ( isset( $text['stroke_width'] ) ? $text['stroke_width'] : 0 ) > 0 ) {
			$attributes[] = 'stroke="' . esc_attr( $this->colour_for_css( (string) $text['stroke_color'] ) ) . '"';
			$attributes[] = 'stroke-opacity="' . esc_attr( $this->num( $this->colour_opacity( (string) $text['stroke_color'] ) ) ) . '"';
			$attributes[] = 'stroke-width="' . esc_attr( $this->num( (float) $text['stroke_width'] ) ) . '"';
		}

		return implode( ' ', $attributes );
	}

	/**
	 * A shape layer.
	 *
	 * @param array<string,mixed> $layer Layer.
	 * @return string
	 */
	private function shape( array $layer ) {
		$shape = isset( $layer['shape'] ) && is_array( $layer['shape'] ) ? $layer['shape'] : array();

		$x = (float) ( isset( $layer['x'] ) ? $layer['x'] : 0 );
		$y = (float) ( isset( $layer['y'] ) ? $layer['y'] : 0 );
		$w = max( 0.0, (float) ( isset( $layer['width'] ) ? $layer['width'] : 0 ) );
		$h = max( 0.0, (float) ( isset( $layer['height'] ) ? $layer['height'] : 0 ) );

		$paint = array( $this->fill_attr( $layer, $shape ) );

		if ( ! empty( $shape['stroke_color'] ) && ! empty( $shape['stroke_width'] ) ) {
			$paint[] = sprintf(
				'stroke="%s" stroke-width="%s"',
				esc_attr( $this->colour_for_css( (string) $shape['stroke_color'] ) ),
				esc_attr( $this->num( $shape['stroke_width'] ) )
			);
		}

		$paint = implode( ' ', array_filter( $paint ) );
		$kind  = isset( $shape['shape'] ) ? (string) $shape['shape'] : 'rect';

		if ( 'circle' === $kind || 'ellipse' === $kind ) {
			return sprintf(
				'<ellipse cx="%s" cy="%s" rx="%s" ry="%s" %s/>',
				esc_attr( $this->num( $x + ( $w / 2 ) ) ),
				esc_attr( $this->num( $y + ( $h / 2 ) ) ),
				esc_attr( $this->num( $w / 2 ) ),
				esc_attr( $this->num( $h / 2 ) ),
				$paint
			);
		}

		if ( 'line' === $kind ) {
			return sprintf(
				'<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width="%s"/>',
				esc_attr( $this->num( $x ) ),
				esc_attr( $this->num( $y ) ),
				esc_attr( $this->num( $x + $w ) ),
				esc_attr( $this->num( $y + $h ) ),
				esc_attr( $this->colour_for_css( ! empty( $shape['stroke_color'] ) ? (string) $shape['stroke_color'] : ( isset( $shape['fill'] ) ? (string) $shape['fill'] : '#FFFFFF' ) ) ),
				esc_attr( $this->num( max( 1.0, ! empty( $shape['stroke_width'] ) ? (float) $shape['stroke_width'] : $h ) ) )
			);
		}

		return sprintf(
			'<rect x="%s" y="%s" width="%s" height="%s"%s %s/>',
			esc_attr( $this->num( $x ) ),
			esc_attr( $this->num( $y ) ),
			esc_attr( $this->num( $w ) ),
			esc_attr( $this->num( $h ) ),
			$this->radius_attr( isset( $shape['border_radius'] ) ? (float) $shape['border_radius'] : 0 ),
			$paint
		);
	}

	/**
	 * Paint attribute for a shape.
	 *
	 * @param array<string,mixed> $layer Layer.
	 * @param array<string,mixed> $shape Shape.
	 * @return string
	 */
	private function fill_attr( array $layer, array $shape ) {
		if ( ! empty( $shape['gradient_from'] ) && ! empty( $shape['gradient_to'] ) ) {
			return sprintf( 'fill="url(#cpic-fill-%s)"', esc_attr( $this->safe_id( isset( $layer['id'] ) ? (string) $layer['id'] : 'layer' ) ) );
		}

		$colour = isset( $shape['fill'] ) ? (string) $shape['fill'] : '#FFFFFF';

		return sprintf(
			'fill="%s" fill-opacity="%s"',
			esc_attr( $this->colour_for_css( $colour ) ),
			esc_attr( $this->num( $this->colour_opacity( $colour ) ) )
		);
	}

	/**
	 * An image layer.
	 *
	 * @param array<string,mixed> $layer Layer.
	 * @return string
	 */
	private function image( array $layer ) {
		$url = isset( $layer['image']['url'] ) ? (string) $layer['image']['url'] : '';

		if ( '' === $url ) {
			return '';
		}

		$path = $this->resolve_local( $url );

		if ( '' === $path ) {
			return '';
		}

		$x      = (float) ( isset( $layer['x'] ) ? $layer['x'] : 0 );
		$y      = (float) ( isset( $layer['y'] ) ? $layer['y'] : 0 );
		$width  = max( 0.0, (float) ( isset( $layer['width'] ) ? $layer['width'] : 0 ) );
		$height = max( 0.0, (float) ( isset( $layer['height'] ) ? $layer['height'] : 0 ) );
		$fit    = isset( $layer['image']['fit'] ) ? (string) $layer['image']['fit'] : 'cover';

		$aspect = 'cover' === $fit ? 'xMidYMid slice' : 'xMidYMid meet';

		$markup = sprintf(
			'<image x="%s" y="%s" width="%s" height="%s" preserveAspectRatio="%s" xlink:href="%s"/>',
			esc_attr( $this->num( $x ) ),
			esc_attr( $this->num( $y ) ),
			esc_attr( $this->num( $width ) ),
			esc_attr( $this->num( $height ) ),
			esc_attr( $aspect ),
			esc_attr( $path )
		);

		$radius = isset( $layer['image']['border_radius'] ) ? (float) $layer['image']['border_radius'] : 0.0;

		if ( $radius > 0 ) {
			$clip_id = 'cpic-clip-' . $this->safe_id( isset( $layer['id'] ) ? (string) $layer['id'] : 'layer' );

			// A clipPath must live in <defs> to be reliable across rasterizers,
			// but referencing one defined later in the document is legal, and
			// keeping it inline keeps this function self-contained.
			$markup = sprintf(
				'<clipPath id="%1$s"><rect x="%2$s" y="%3$s" width="%4$s" height="%5$s" rx="%6$s" ry="%6$s"/></clipPath><g clip-path="url(#%1$s)">%7$s</g>',
				esc_attr( $clip_id ),
				esc_attr( $this->num( $x ) ),
				esc_attr( $this->num( $y ) ),
				esc_attr( $this->num( $width ) ),
				esc_attr( $this->num( $height ) ),
				esc_attr( $this->num( $radius ) ),
				$markup
			);
		}

		return $markup;
	}

	/**
	 * Rounded-corner attribute, when asked for.
	 *
	 * @param float $radius Radius.
	 * @return string
	 */
	private function radius_attr( $radius ) {
		$radius = (float) $radius;

		if ( $radius <= 0 ) {
			return '';
		}

		return sprintf( ' rx="%1$s" ry="%1$s"', esc_attr( $this->num( $radius ) ) );
	}

	/**
	 * Turn a URL into something a local rasterizer can read.
	 *
	 * Rasterizers run on the same machine as WordPress, so a file path is both
	 * faster and more reliable than an HTTP fetch (which fails on loopback,
	 * private TLS and password-protected sites).
	 *
	 * @param string $url URL or path.
	 * @return string Local path, or the original URL when no file exists.
	 */
	private function resolve_local( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		if ( 0 === strpos( $url, 'data:' ) ) {
			return $url;
		}

		if ( file_exists( $url ) ) {
			return $url;
		}

		$uploads = wp_upload_dir();

		if ( empty( $uploads['error'] ) && ! empty( $uploads['baseurl'] ) && ! empty( $uploads['basedir'] ) ) {
			$base = (string) $uploads['baseurl'];

			if ( 0 === strpos( $url, $base ) ) {
				$candidate = (string) $uploads['basedir'] . substr( $url, strlen( $base ) );

				if ( file_exists( $candidate ) ) {
					return $candidate;
				}
			}
		}

		$content = content_url();

		if ( 0 === strpos( $url, $content ) ) {
			$candidate = WP_CONTENT_DIR . substr( $url, strlen( $content ) );

			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		// Nothing local: leave the URL alone and let the rasterizer fetch it.
		return $url;
	}

	/**
	 * CSS font stack for a family, with bundled Bengali fallbacks.
	 *
	 * @param string $family Family.
	 * @return string
	 */
	private function font_stack( $family ) {
		$families = array();

		if ( '' !== (string) $family ) {
			$families[] = (string) $family;
		}

		foreach ( array( 'Noto Sans Bengali', 'Hind Siliguri', 'DejaVu Sans', 'sans-serif' ) as $fallback ) {
			if ( ! in_array( $fallback, $families, true ) ) {
				$families[] = $fallback;
			}
		}

		return implode( ', ', $families );
	}

	/**
	 * Split a colour into a CSS colour plus opacity.
	 *
	 * @param string $colour Colour.
	 * @return float Opacity 0..1.
	 */
	private function colour_opacity( $colour ) {
		$rgba = $this->parse_rgba( (string) $colour );

		return null === $rgba ? 1.0 : (float) $rgba['a'];
	}

	/**
	 * CSS-safe colour (drops the alpha from an rgba()).
	 *
	 * @param string $colour Colour.
	 * @return string
	 */
	private function colour_for_css( $colour ) {
		$colour = trim( (string) $colour );

		if ( '' === $colour ) {
			return '#000000';
		}

		$rgba = $this->parse_rgba( $colour );

		if ( null !== $rgba ) {
			return sprintf( 'rgb(%d,%d,%d)', $rgba['r'], $rgba['g'], $rgba['b'] );
		}

		return $colour;
	}

	/**
	 * Parse an rgba()/rgb() string.
	 *
	 * @param string $colour Colour.
	 * @return array{r:int,g:int,b:int,a:float}|null
	 */
	private function parse_rgba( $colour ) {
		if ( ! preg_match( '/^rgba?\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*(?:,\s*([0-9.]+)\s*)?\)$/i', $colour, $matches ) ) {
			return null;
		}

		return array(
			'r' => max( 0, min( 255, (int) $matches[1] ) ),
			'g' => max( 0, min( 255, (int) $matches[2] ) ),
			'b' => max( 0, min( 255, (int) $matches[3] ) ),
			'a' => isset( $matches[4] ) && '' !== $matches[4] ? max( 0.0, min( 1.0, (float) $matches[4] ) ) : 1.0,
		);
	}

	/**
	 * Width of a string at a given font size.
	 *
	 * Real advance widths are read from the font file when it can be found, so
	 * lines break where the rasterizer will break them. Without a font file the
	 * estimate falls back to an average advance, deliberately rounding up: an
	 * early break is cosmetic, an overflowing line is not.
	 *
	 * @param string $text      Text.
	 * @param float  $size      Font size in px.
	 * @param string $font_file Font file.
	 * @return float Width in px.
	 */
	private function estimate_width( $text, $size, $font_file = '' ) {
		// Zero-width characters a shaping engine collapses.
		$text = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string) $text );

		if ( ! is_string( $text ) || '' === $text ) {
			return 0.0;
		}

		if ( '' !== (string) $font_file ) {
			return FontMetrics::text_width( (string) $font_file, $text, (float) $size );
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );

		// 0.62em is the average advance of the bundled Bengali faces.
		return (float) $length * (float) $size * 0.62;
	}

	/**
	 * Absolute path to a bundled font file.
	 *
	 * @param string $family Family.
	 * @return string Empty when the family has no file.
	 */
	private function font_file( $family ) {
		$font = $this->container->get( 'fonts' )->resolve( (string) $family );

		if ( ! $font || empty( $font['available'] ) || empty( $font['file'] ) ) {
			return '';
		}

		$path = CPIC_PLUGIN_DIR . ltrim( (string) $font['file'], '/' );

		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Numeric attribute with no trailing zeros.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function num( $value ) {
		$number = round( (float) $value, 3 );

		if ( (float) (int) $number === $number ) {
			return (string) (int) $number;
		}

		return rtrim( rtrim( number_format( $number, 3, '.', '' ), '0' ), '.' );
	}

	/**
	 * Safe XML id fragment.
	 *
	 * @param string $id Raw id.
	 * @return string
	 */
	private function safe_id( $id ) {
		$safe = preg_replace( '/[^A-Za-z0-9_-]/', '-', (string) $id );

		return '' === (string) $safe ? 'layer' : (string) $safe;
	}
}
