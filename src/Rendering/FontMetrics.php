<?php
/**
 * TrueType metrics reader.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Rendering;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads glyph advance widths out of a TrueType/OpenType file.
 *
 * Why this exists: server-side SVG rendering has to wrap text into a box
 * before a shaping engine ever sees it, and guessing a per-character width
 * made the published image break lines differently from the studio preview.
 * That is the one thing this plugin promises not to do.
 *
 * This reads the real `hmtx` advances (mapped through `cmap` format 4, which
 * covers the BMP including the whole Bengali block). The values are *unshaped*
 * advances, which is the correct basis for wrapping: shaping rearranges marks
 * but barely changes the total run length.
 *
 * Results are cached per file, because a wrapper may wrap a dozen layers.
 */
final class FontMetrics {

	/**
	 * Parsed fonts keyed by "path:size".
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private static $cache = array();

	/**
	 * Unicode code point (BMP) => advance width in font units.
	 *
	 * @param string $path Font file.
	 * @return array<int,int>|null Null when the file cannot be used.
	 */
	public static function advances( $path ) {
		$path = (string) $path;

		if ( isset( self::$cache[ $path ] ) ) {
			return self::$cache[ $path ];
		}

		$metrics = self::parse( $path );

		self::$cache[ $path ] = is_array( $metrics ) ? $metrics : array();

		return self::$cache[ $path ];
	}

	/**
	 * Width of a string in pixels.
	 *
	 * Characters with no glyph fall back to a measured default rather than
	 * zero, so an unknown script still reserves space instead of collapsing.
	 *
	 * @param string $path Font file.
	 * @param string $text Text.
	 * @param float  $size Font size in px.
	 * @return float
	 */
	public static function text_width( $path, $text, $size ) {
		$text = (string) $text;
		$size = (float) $size;

		if ( '' === $text || $size <= 0 ) {
			return 0.0;
		}

		$font = self::advances( $path );

		if ( null === $font ) {
			return self::fallback_width( $text, $size );
		}

		$characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $characters ) ) {
			return self::fallback_width( $text, $size );
		}

		// No usable metrics: fall back rather than read absent keys, which
		// would warn on every string and still return a nonsense width.
		if ( empty( $font['advances'] ) || empty( $font['units_per_em'] ) ) {
			return self::fallback_width( $text, $size );
		}

		$default = isset( $font['default'] ) ? (int) $font['default'] : (int) $font['units_per_em'];
		$total   = 0;

		foreach ( $characters as $character ) {
			// ZWJ/ZWNJ/ZWNBSP occupy no space.
			$code = self::codepoint( $character );

			if ( null === $code || in_array( $code, array( 0x200B, 0x200C, 0x200D, 0xFEFF ), true ) ) {
				continue;
			}

			$total += isset( $font['advances'][ $code ] ) ? $font['advances'][ $code ] : $default;
		}

		return (float) $total * ( $size / max( 1, (int) $font['units_per_em'] ) );
	}

	/**
	 * Width estimate when no metrics are available.
	 *
	 * @param string $text Text.
	 * @param float  $size Size.
	 * @return float
	 */
	private static function fallback_width( $text, $size ) {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );

		// 0.6em is the average advance of the bundled Bengali faces.
		return (float) $length * $size * 0.6;
	}

	/**
	 * Code point of a single UTF-8 character.
	 *
	 * @param string $character Character.
	 * @return int|null
	 */
	private static function codepoint( $character ) {
		$bytes = array_values( unpack( 'C*', (string) $character ) );

		if ( empty( $bytes ) ) {
			return null;
		}

		$count = count( $bytes );

		if ( 1 === $count ) {
			return $bytes[0];
		}

		if ( 2 === $count ) {
			return ( ( $bytes[0] & 0x1F ) << 6 ) | ( $bytes[1] & 0x3F );
		}

		if ( 3 === $count ) {
			return ( ( $bytes[0] & 0x0F ) << 12 ) | ( ( $bytes[1] & 0x3F ) << 6 ) | ( $bytes[2] & 0x3F );
		}

		if ( 4 === $count ) {
			return ( ( $bytes[0] & 0x07 ) << 18 ) | ( ( $bytes[1] & 0x3F ) << 12 ) | ( ( $bytes[2] & 0x3F ) << 6 ) | ( $bytes[3] & 0x3F );
		}

		return null;
	}

	/**
	 * Parse a font file.
	 *
	 * @param string $path Path.
	 * @return array{units_per_em:int,default:int,advances:array<int,int>}|null
	 */
	private static function parse( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$data = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents, WordPress.PHP.NoSilencedErrors

		if ( false === $data || strlen( $data ) < 12 ) {
			return null;
		}

		$tables = self::table_directory( $data );

		if ( ! isset( $tables['head'], $tables['hhea'], $tables['hmtx'] ) ) {
			return null;
		}

		$units_per_em = self::uint16( $data, $tables['head'] + 18 );

		if ( $units_per_em <= 0 ) {
			return null;
		}

		$h_metrics = self::uint16( $data, $tables['hhea'] + 34 );

		if ( $h_metrics <= 0 ) {
			return null;
		}

		// Advance widths: numberOfHMetrics entries, then a repeat of the last.
		$advances_by_glyph = array();
		$last              = 0;

		for ( $index = 0; $index < $h_metrics; $index++ ) {
			$value = self::uint16( $data, $tables['hmtx'] + ( $index * 4 ) );

			if ( null === $value ) {
				break;
			}

			$advances_by_glyph[ $index ] = $value;
			$last                        = $value;
		}

		if ( array() === $advances_by_glyph ) {
			return null;
		}

		$default = $last;

		$cmap = self::cmap_map( $data, $tables );

		if ( array() === $cmap ) {
			return null;
		}

		$advances = array();

		foreach ( $cmap as $codepoint => $glyph ) {
			$advances[ $codepoint ] = isset( $advances_by_glyph[ $glyph ] ) ? $advances_by_glyph[ $glyph ] : $default;
		}

		return array(
			'units_per_em' => (int) $units_per_em,
			'default'      => (int) $default,
			'advances'     => $advances,
		);
	}

	/**
	 * Table directory: tag => offset.
	 *
	 * @param string $data Font bytes.
	 * @return array<string,int>
	 */
	private static function table_directory( $data ) {
		$count = self::uint16( $data, 4 );

		if ( null === $count ) {
			return array();
		}

		$tables = array();
		$offset = 12;

		for ( $index = 0; $index < $count; $index++ ) {
			$tag = substr( $data, $offset, 4 );

			if ( strlen( $tag ) < 4 ) {
				break;
			}

			$table_offset = self::uint32( $data, $offset + 8 );

			if ( null !== $table_offset ) {
				$tables[ trim( $tag ) ] = $table_offset;
			}

			$offset += 16;
		}

		return $tables;
	}

	/**
	 * Code point => glyph id, via the best available cmap subtable.
	 *
	 * @param string              $data   Font bytes.
	 * @param array<string,int>   $tables Table offsets.
	 * @return array<int,int>
	 */
	private static function cmap_map( $data, array $tables ) {
		if ( ! isset( $tables['cmap'] ) ) {
			return array();
		}

		$base       = $tables['cmap'];
		$num_tables = self::uint16( $data, $base + 2 );

		if ( null === $num_tables ) {
			return array();
		}

		$best = null;

		for ( $index = 0; $index < $num_tables; $index++ ) {
			$record = $base + 4 + ( $index * 8 );
			$platform = self::uint16( $data, $record );
			$encoding = self::uint16( $data, $record + 2 );
			$offset   = self::uint32( $data, $record + 4 );

			if ( null === $platform || null === $encoding || null === $offset ) {
				continue;
			}

			$subtable = $base + $offset;
			$format   = self::uint16( $data, $subtable );

			// Format 4 is the BMP map; it is what Bengali uses. Prefer the
			// Windows Unicode (3,1) and Unicode (0,x) encodings.
			if ( 4 !== $format ) {
				continue;
			}

			$score = ( 3 === $platform && 1 === $encoding ) ? 3 : ( 0 === $platform ? 2 : 1 );

			if ( null === $best || $score > $best['score'] ) {
				$best = array( 'score' => $score, 'offset' => $subtable );
			}
		}

		if ( null === $best ) {
			return array();
		}

		return self::cmap_format_4( $data, $best['offset'] );
	}

	/**
	 * Parse a cmap format 4 subtable.
	 *
	 * @param string $data   Font bytes.
	 * @param int    $offset Subtable offset.
	 * @return array<int,int>
	 */
	private static function cmap_format_4( $data, $offset ) {
		$seg_x2 = self::uint16( $data, $offset + 6 );

		if ( null === $seg_x2 || $seg_x2 <= 0 ) {
			return array();
		}

		$segments  = (int) ( $seg_x2 / 2 );
		$end_base  = $offset + 14;
		$start_base = $end_base + $seg_x2 + 2;
		$delta_base = $start_base + $seg_x2;
		$range_base = $delta_base + $seg_x2;

		$map = array();

		for ( $segment = 0; $segment < $segments; $segment++ ) {
			$end   = self::uint16( $data, $end_base + ( $segment * 2 ) );
			$start = self::uint16( $data, $start_base + ( $segment * 2 ) );

			if ( null === $end || null === $start || $start > $end ) {
				continue;
			}

			// 0xFFFF terminates the table.
			if ( 0xFFFF === $start ) {
				continue;
			}

			$delta = self::uint16( $data, $delta_base + ( $segment * 2 ) );
			$range = self::uint16( $data, $range_base + ( $segment * 2 ) );

			if ( null === $delta || null === $range ) {
				continue;
			}

			$delta = $delta > 0x7FFF ? $delta - 0x10000 : $delta;

			for ( $code = $start; $code <= $end; $code++ ) {
				if ( 0 === $range ) {
					$glyph = ( $code + $delta ) & 0xFFFF;
				} else {
					$glyph_address = $range_base + ( $segment * 2 ) + $range + ( ( $code - $start ) * 2 );
					$glyph         = self::uint16( $data, $glyph_address );

					if ( null === $glyph ) {
						continue;
					}

					if ( 0 !== $glyph ) {
						$glyph = ( $glyph + $delta ) & 0xFFFF;
					}
				}

				if ( 0 !== $glyph ) {
					$map[ $code ] = $glyph;
				}
			}
		}

		return $map;
	}

	/**
	 * Big-endian unsigned 16-bit read.
	 *
	 * @param string $data   Bytes.
	 * @param int    $offset Offset.
	 * @return int|null
	 */
	private static function uint16( $data, $offset ) {
		$offset = (int) $offset;

		if ( $offset < 0 || $offset + 2 > strlen( $data ) ) {
			return null;
		}

		$bytes = unpack( 'n', substr( $data, $offset, 2 ) );

		return isset( $bytes[1] ) ? (int) $bytes[1] : null;
	}

	/**
	 * Big-endian unsigned 32-bit read.
	 *
	 * @param string $data   Bytes.
	 * @param int    $offset Offset.
	 * @return int|null
	 */
	private static function uint32( $data, $offset ) {
		$offset = (int) $offset;

		if ( $offset < 0 || $offset + 4 > strlen( $data ) ) {
			return null;
		}

		$bytes = unpack( 'N', substr( $data, $offset, 4 ) );

		return isset( $bytes[1] ) ? (int) $bytes[1] : null;
	}

	/**
	 * Forget cached metrics (tests change font files).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = array();
	}
}
