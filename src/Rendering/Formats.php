<?php
/**
 * Output format presets.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Rendering;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Named canvas presets. Each preset carries its own safe area so layouts are
 * adapted, never blindly scaled.
 */
final class Formats {

	/**
	 * Format definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		$formats = array(
			'square' => array(
				'key'       => 'square',
				'label'     => __( 'Square (Feed)', 'custom-post-image-creator' ),
				'width'     => 1080,
				'height'    => 1080,
				'safe'      => array( 'top' => 64, 'right' => 64, 'bottom' => 64, 'left' => 64 ),
				'platforms' => array( 'facebook', 'instagram', 'telegram' ),
				'ratio'     => '1:1',
			),
			'portrait' => array(
				'key'       => 'portrait',
				'label'     => __( 'Portrait (Feed)', 'custom-post-image-creator' ),
				'width'     => 1080,
				'height'    => 1350,
				'safe'      => array( 'top' => 72, 'right' => 64, 'bottom' => 96, 'left' => 64 ),
				'platforms' => array( 'facebook', 'instagram' ),
				'ratio'     => '4:5',
			),
			'story' => array(
				'key'       => 'story',
				'label'     => __( 'Story / Reel', 'custom-post-image-creator' ),
				'width'     => 1080,
				'height'    => 1920,
				'safe'      => array( 'top' => 260, 'right' => 72, 'bottom' => 320, 'left' => 72 ),
				'platforms' => array( 'facebook', 'instagram' ),
				'ratio'     => '9:16',
			),
			'landscape' => array(
				'key'       => 'landscape',
				'label'     => __( 'Landscape (Link)', 'custom-post-image-creator' ),
				'width'     => 1200,
				'height'    => 675,
				'safe'      => array( 'top' => 48, 'right' => 56, 'bottom' => 56, 'left' => 56 ),
				'platforms' => array( 'facebook', 'telegram', 'twitter' ),
				'ratio'     => '16:9',
			),
			'wide' => array(
				'key'       => 'wide',
				'label'     => __( 'Wide banner', 'custom-post-image-creator' ),
				'width'     => 1600,
				'height'    => 900,
				'safe'      => array( 'top' => 64, 'right' => 64, 'bottom' => 64, 'left' => 64 ),
				'platforms' => array( 'facebook' ),
				'ratio'     => '16:9',
			),
		);

		/**
		 * Filter available output formats.
		 *
		 * @param array<string,array<string,mixed>> $formats Formats.
		 */
		return apply_filters( 'cpic/formats', $formats );
	}

	/**
	 * Get a single format, falling back to square.
	 *
	 * @param string $key Format key.
	 * @return array<string,mixed>
	 */
	public static function get( $key ) {
		$formats = self::all();
		$key     = (string) $key;

		if ( isset( $formats[ $key ] ) ) {
			return $formats[ $key ];
		}

		return isset( $formats['square'] ) ? $formats['square'] : reset( $formats );
	}

	/**
	 * Clamp custom dimensions into a safe range.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return array{width:int,height:int}
	 */
	public static function clamp( $width, $height ) {
		$width  = (int) $width;
		$height = (int) $height;

		$width  = max( 200, min( 4000, $width ) );
		$height = max( 200, min( 4000, $height ) );

		return array(
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * Determine the closest preset for arbitrary dimensions.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return string Format key.
	 */
	public static function nearest( $width, $height ) {
		$width  = max( 1, (int) $width );
		$height = max( 1, (int) $height );
		$ratio  = $width / $height;

		$best     = 'square';
		$best_dif = PHP_INT_MAX;

		foreach ( self::all() as $key => $format ) {
			$diff = abs( $ratio - ( $format['width'] / $format['height'] ) );
			if ( $diff < $best_dif ) {
				$best_dif = $diff;
				$best     = $key;
			}
		}

		return $best;
	}

	/**
	 * Keys only.
	 *
	 * @return array<int,string>
	 */
	public static function keys() {
		return array_keys( self::all() );
	}
}
