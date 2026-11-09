<?php
/**
 * GD-based server renderer (limited, latin-only fallback).
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Rendering;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IMPORTANT LIMITATION
 * --------------------
 * PHP's GD cannot shape Bengali conjuncts or vowel signs. This renderer
 * therefore refuses text that contains complex-script code points and reports
 * that clearly instead of silently producing broken Bengali. It exists so that
 * latin-only decks still work on a host where no browser capture has happened,
 * and so scheduled posts with no artifact can fail with a precise reason.
 */
final class ServerRenderer implements RendererInterface {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'gd';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Server GD renderer (latin only)', 'custom-post-image-creator' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagettftext' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function runs_in_background() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_complex_scripts() {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $plan ) {
		if ( ! $this->is_available() ) {
			return new \WP_Error( 'cpic_gd_missing', __( 'The GD extension with FreeType support is required for server-side rendering.', 'custom-post-image-creator' ) );
		}

		$width  = isset( $plan['width'] ) ? (int) $plan['width'] : 1080;
		$height = isset( $plan['height'] ) ? (int) $plan['height'] : 1080;

		if ( $width < 1 || $height < 1 || $width > 4000 || $height > 4000 ) {
			return new \WP_Error( 'cpic_bad_dimensions', __( 'The requested image dimensions are not supported.', 'custom-post-image-creator' ) );
		}

		// Refuse complex scripts up-front with an actionable error.
		foreach ( $plan['layers'] as $layer ) {
			if ( isset( $layer['text']['value'] ) && $this->needs_shaping( $layer['text']['value'] ) ) {
				return new \WP_Error(
					'cpic_complex_script',
					__( 'This design contains Bengali (or another complex script). GD cannot shape it correctly, so the plugin will not produce a broken image. Generate it in the browser with "Regenerate" or connect an external renderer.', 'custom-post-image-creator' ),
					array( 'layer' => $layer['id'] )
				);
			}
		}

		$canvas = imagecreatetruecolor( $width, $height );

		if ( ! $canvas ) {
			return new \WP_Error( 'cpic_gd_canvas', __( 'Could not allocate the image canvas (memory limit?).', 'custom-post-image-creator' ) );
		}

		imagealphablending( $canvas, true );
		imagesavealpha( $canvas, true );

		$this->paint_background( $canvas, $plan, $width, $height );

		foreach ( $plan['layers'] as $layer ) {
			$this->paint_layer( $canvas, $layer );
		}

		$dir = $this->target_dir();
		if ( is_wp_error( $dir ) ) {
			imagedestroy( $canvas );
			return $dir;
		}

		$filename = 'cpic-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( (string) wp_rand() . microtime( true ) ), 0, 8 ) . '.png';
		$path     = trailingslashit( $dir['path'] ) . $filename;

		imagepng( $canvas, $path, 6 );
		imagedestroy( $canvas );

		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'cpic_gd_write', __( 'The rendered image could not be written to disk.', 'custom-post-image-creator' ) );
		}

		return array(
			'path'     => $path,
			'url'      => trailingslashit( $dir['url'] ) . $filename,
			'width'    => $width,
			'height'   => $height,
			'checksum' => (string) hash_file( 'sha256', $path ),
			'mime'     => 'image/png',
			// The artifact manager copies the bytes into its own directory.
			'cleanup'  => true,
		);
	}

	/**
	 * Detect characters requiring complex text shaping.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public function needs_shaping( $text ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return false;
		}

		// Bengali block + common Indic ranges + Arabic + combining marks.
		return (bool) preg_match( '/[\x{0900}-\x{0DFF}\x{0E00}-\x{0EFF}\x{0F00}-\x{0FFF}\x{1000}-\x{109F}\x{1700}-\x{17FF}\x{1A00}-\x{1CFF}\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{0300}-\x{036F}]/u', $text );
	}

	/**
	 * Paint the canvas background.
	 *
	 * @param resource|\GdImage     $canvas Canvas.
	 * @param array<string,mixed>  $plan   Plan.
	 * @param int                  $width  Width.
	 * @param int                  $height Height.
	 * @return void
	 */
	private function paint_background( $canvas, array $plan, $width, $height ) {
		$config = isset( $plan['canvas'] ) ? $plan['canvas'] : array();
		$type   = isset( $config['background_type'] ) ? (string) $config['background_type'] : 'gradient';

		if ( 'image' === $type && ! empty( $config['background_image'] ) ) {
			$path = $this->url_to_path( $config['background_image'] );
			if ( $path ) {
				$image = $this->load_image( $path );
				if ( $image ) {
					$source_w = imagesx( $image );
					$source_h = imagesy( $image );
					$scale    = max( $width / max( 1, $source_w ), $height / max( 1, $source_h ) );
					$draw_w   = (int) ceil( $source_w * $scale );
					$draw_h   = (int) ceil( $source_h * $scale );
					imagecopyresampled( $canvas, $image, (int) ( ( $width - $draw_w ) / 2 ), (int) ( ( $height - $draw_h ) / 2 ), 0, 0, $draw_w, $draw_h, $source_w, $source_h );
					imagedestroy( $image );
					return;
				}
			}
		}

		if ( 'gradient' === $type ) {
			$from = $this->allocate( $canvas, isset( $config['gradient_from'] ) ? $config['gradient_from'] : '#667EEA' );
			$to   = $this->allocate( $canvas, isset( $config['gradient_to'] ) ? $config['gradient_to'] : '#764BA2' );

			for ( $y = 0; $y < $height; $y++ ) {
				$ratio = $height > 1 ? $y / ( $height - 1 ) : 0;
				$color = imagecolorallocate(
					$canvas,
					(int) round( $from['r'] + ( $to['r'] - $from['r'] ) * $ratio ),
					(int) round( $from['g'] + ( $to['g'] - $from['g'] ) * $ratio ),
					(int) round( $from['b'] + ( $to['b'] - $from['b'] ) * $ratio )
				);
				imageline( $canvas, 0, $y, $width, $y, $color );
			}

			// Overlay gradient for text contrast.
			if ( ! empty( $config['overlay']['enabled'] ) ) {
				$top = imagecolorallocatealpha( $canvas, 9, 12, 24, 110 );
				imagefilledrectangle( $canvas, 0, (int) ( $height * 0.35 ), $width, $height, $top );
			}

			return;
		}

		$color = $this->allocate( $canvas, isset( $config['background_color'] ) ? $config['background_color'] : '#0F172A' );
		$fill  = imagecolorallocate( $canvas, $color['r'], $color['g'], $color['b'] );
		imagefilledrectangle( $canvas, 0, 0, $width, $height, $fill );
	}

	/**
	 * Paint a single layer.
	 *
	 * @param resource|\GdImage    $canvas Canvas.
	 * @param array<string,mixed>  $layer  Layer.
	 * @return void
	 */
	private function paint_layer( $canvas, array $layer ) {
		$type = isset( $layer['type'] ) ? (string) $layer['type'] : '';

		switch ( $type ) {
			case 'image':
			case 'logo':
			case 'watermark':
			case 'svg':
				$this->paint_image( $canvas, $layer );
				break;
			case 'shape':
			case 'gradient':
			case 'pattern':
				$this->paint_shape( $canvas, $layer );
				break;
			case 'text':
				$this->paint_text( $canvas, $layer );
				break;
		}
	}

	/**
	 * Paint an image layer.
	 *
	 * @param resource|\GdImage    $canvas Canvas.
	 * @param array<string,mixed>  $layer  Layer.
	 * @return void
	 */
	private function paint_image( $canvas, array $layer ) {
		$url = isset( $layer['image']['url'] ) ? (string) $layer['image']['url'] : '';
		if ( '' === $url ) {
			return;
		}

		$path = $this->url_to_path( $url );
		if ( ! $path ) {
			return;
		}

		$image = $this->load_image( $path );
		if ( ! $image ) {
			return;
		}

		$box_w = (int) ( isset( $layer['width'] ) ? $layer['width'] : 0 );
		$box_h = (int) ( isset( $layer['height'] ) ? $layer['height'] : 0 );

		$source_w = imagesx( $image );
		$source_h = imagesy( $image );

		if ( $box_w <= 0 ) {
			$box_w = $source_w;
		}
		if ( $box_h <= 0 ) {
			$box_h = (int) round( $source_h * ( $box_w / max( 1, $source_w ) ) );
		}

		$fit = isset( $layer['image']['fit'] ) ? (string) $layer['image']['fit'] : 'cover';

		$dst_w = $box_w;
		$dst_h = $box_h;
		$offset_x = 0;
		$offset_y = 0;
		$src_x = 0;
		$src_y = 0;
		$src_w = $source_w;
		$src_h = $source_h;

		if ( 'contain' === $fit || 'cover' === $fit ) {
			$scale = 'cover' === $fit
				? max( $box_w / max( 1, $source_w ), $box_h / max( 1, $source_h ) )
				: min( $box_w / max( 1, $source_w ), $box_h / max( 1, $source_h ) );

			$dst_w = (int) ceil( $source_w * $scale );
			$dst_h = (int) ceil( $source_h * $scale );
			$offset_x = (int) ( ( $box_w - $dst_w ) / 2 );
			$offset_y = (int) ( ( $box_h - $dst_h ) / 2 );
		}

		imagecopyresampled(
			$canvas,
			$image,
			(int) $layer['x'] + $offset_x,
			(int) $layer['y'] + $offset_y,
			$src_x,
			$src_y,
			$dst_w,
			$dst_h,
			$src_w,
			$src_h
		);

		imagedestroy( $image );
	}

	/**
	 * Paint a shape layer.
	 *
	 * @param resource|\GdImage    $canvas Canvas.
	 * @param array<string,mixed>  $layer  Layer.
	 * @return void
	 */
	private function paint_shape( $canvas, array $layer ) {
		$shape = isset( $layer['shape'] ) ? $layer['shape'] : array();
		$x     = (int) $layer['x'];
		$y     = (int) $layer['y'];
		$w     = (int) $layer['width'];
		$h     = (int) $layer['height'];

		$color = $this->allocate( $canvas, isset( $shape['fill'] ) ? $shape['fill'] : '#FFFFFF' );
		$fill  = imagecolorallocatealpha( $canvas, $color['r'], $color['g'], $color['b'], (int) round( ( 1 - $layer['opacity'] ) * 127 ) );

		switch ( isset( $shape['shape'] ) ? $shape['shape'] : 'rect' ) {
			case 'circle':
			case 'ellipse':
				imagefilledellipse( $canvas, $x + (int) ( $w / 2 ), $y + (int) ( $h / 2 ), max( 1, $w ), max( 1, $h ), $fill );
				break;
			case 'line':
				imagesetthickness( $canvas, max( 1, (int) $h ) );
				imageline( $canvas, $x, $y, $x + $w, $y, $fill );
				break;
			default:
				imagefilledrectangle( $canvas, $x, $y, $x + max( 1, $w ), $y + max( 1, $h ), $fill );
		}
	}

	/**
	 * Paint a text layer (latin only; complex scripts are refused earlier).
	 *
	 * @param resource|\GdImage    $canvas Canvas.
	 * @param array<string,mixed>  $layer  Layer.
	 * @return void
	 */
	private function paint_text( $canvas, array $layer ) {
		$text = isset( $layer['text'] ) ? $layer['text'] : array();
		$value = isset( $text['value'] ) ? trim( (string) $text['value'] ) : '';

		if ( '' === $value && empty( $text['background'] ) ) {
			return;
		}

		$font_size = (int) round( isset( $text['font_size'] ) ? $text['font_size'] : 40 );
		$max_width = (int) $layer['width'];

		$font_file = $this->font_file( isset( $text['font_family'] ) ? $text['font_family'] : '' );

		if ( ! $font_file ) {
			return;
		}

		$lines = $this->wrap( $value, $font_file, $font_size, $max_width );

		if ( ! empty( $text['max_lines'] ) && count( $lines ) > (int) $text['max_lines'] ) {
			$lines = array_slice( $lines, 0, (int) $text['max_lines'] );
		}

		$line_height = (int) round( $font_size * ( isset( $text['line_height'] ) ? $text['line_height'] : 1.3 ) );

		$x = (int) $layer['x'];
		$y = (int) $layer['y'];

		$text_color = $this->allocate( $canvas, isset( $text['color'] ) ? $text['color'] : '#FFFFFF' );
		$color      = imagecolorallocatealpha( $canvas, $text_color['r'], $text_color['g'], $text_color['b'], (int) round( ( 1 - $layer['opacity'] ) * 127 ) );

		if ( ! empty( $text['background'] ) ) {
			$padding_x = (int) ( isset( $text['padding_x'] ) ? $text['padding_x'] : 0 );
			$padding_y = (int) ( isset( $text['padding_y'] ) ? $text['padding_y'] : 0 );
			$box       = $this->measure_block( $lines, $font_file, $font_size, $line_height );
			$bg        = $this->allocate( $canvas, $text['background'] );
			$bg_color  = imagecolorallocate( $canvas, $bg['r'], $bg['g'], $bg['b'] );
			imagefilledrectangle( $canvas, $x - $padding_x, $y - $padding_y, $x + $box['width'] + $padding_x, $y + $box['height'] + $padding_y, $bg_color );
		}

		foreach ( $lines as $index => $line ) {
			imagettftext( $canvas, $font_size, 0, $x, $y + (int) ( $font_size + $index * $line_height ), $color, $font_file, $line );
		}
	}

	/**
	 * Measure a text block.
	 *
	 * @param array<int,string> $lines       Lines.
	 * @param string            $font_file   Font.
	 * @param int               $font_size   Size.
	 * @param int               $line_height Line height px.
	 * @return array{width:int,height:int}
	 */
	private function measure_block( array $lines, $font_file, $font_size, $line_height ) {
		$width = 0;
		foreach ( $lines as $line ) {
			$box = imagettfbbox( $font_size, 0, $font_file, $line );
			$width = max( $width, abs( $box[2] - $box[0] ) );
		}
		return array(
			'width'  => (int) $width,
			'height' => (int) ( $font_size + max( 0, count( $lines ) - 1 ) * $line_height ),
		);
	}

	/**
	 * Word wrap for GD text.
	 *
	 * @param string $text      Text.
	 * @param string $font_file Font.
	 * @param int    $font_size Size.
	 * @param int    $max_width Max width.
	 * @return array<int,string>
	 */
	private function wrap( $text, $font_file, $font_size, $max_width ) {
		$words = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$lines = array();
		$line  = '';

		foreach ( (array) $words as $word ) {
			$test = '' === $line ? $word : $line . ' ' . $word;
			$box  = imagettfbbox( $font_size, 0, $font_file, $test );
			$w    = abs( $box[2] - $box[0] );

			if ( $w > $max_width && '' !== $line ) {
				$lines[] = $line;
				$line    = $word;
			} else {
				$line = $test;
			}
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Resolve a bundled font file path.
	 *
	 * @param string $family Family.
	 * @return string
	 */
	private function font_file( $family ) {
		$manager = \CPIC\Core\Plugin::instance()->service( 'fonts' );
		$font    = $manager->resolve( (string) $family );

		if ( $font && ! empty( $font['available'] ) ) {
			return CPIC_PLUGIN_DIR . ltrim( $font['file'], '/' );
		}

		return '';
	}

	/**
	 * Load an image resource from disk.
	 *
	 * @param string $path Path.
	 * @return resource|\GdImage|false
	 */
	private function load_image( $path ) {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info ) {
			return false;
		}

		switch ( $info[2] ) {
			case IMAGETYPE_PNG:
				return imagecreatefrompng( $path );
			case IMAGETYPE_JPEG:
				return imagecreatefromjpeg( $path );
			case IMAGETYPE_GIF:
				return imagecreatefromgif( $path );
			case IMAGETYPE_WEBP:
				return function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $path ) : false;
		}

		return false;
	}

	/**
	 * Allocate a colour from a hex/rgb string.
	 *
	 * @param resource|\GdImage $canvas Canvas.
	 * @param string            $value  Colour.
	 * @return array{r:int,g:int,b:int}
	 */
	private function allocate( $canvas, $value ) {
		$value = trim( (string) $value );

		if ( preg_match( '/^rgba?\(\s*([\d\.]+)\s*,\s*([\d\.]+)\s*,\s*([\d\.]+)/', $value, $m ) ) {
			return array(
				'r' => (int) $m[1],
				'g' => (int) $m[2],
				'b' => (int) $m[3],
			);
		}

		$value = str_replace( '#', '', $value );

		if ( 3 === strlen( $value ) ) {
			$value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
		}

		if ( 8 === strlen( $value ) ) {
			$value = substr( $value, 0, 6 );
		}

		if ( 6 !== strlen( $value ) || ! ctype_xdigit( $value ) ) {
			return array( 'r' => 0, 'g' => 0, 'b' => 0 );
		}

		return array(
			'r' => (int) hexdec( substr( $value, 0, 2 ) ),
			'g' => (int) hexdec( substr( $value, 2, 2 ) ),
			'b' => (int) hexdec( substr( $value, 4, 2 ) ),
		);
	}

	/**
	 * Map an uploads URL onto the filesystem (same-origin only).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function url_to_path( $url ) {
		$url     = (string) $url;
		$uploads = wp_upload_dir();

		if ( empty( $url ) || ! empty( $uploads['error'] ) ) {
			return '';
		}

		$base_url = trailingslashit( $uploads['baseurl'] );
		$base_dir = trailingslashit( $uploads['basedir'] );

		if ( 0 === strpos( $url, $base_url ) ) {
			$candidate = $base_dir . substr( $url, strlen( $base_url ) );
			return file_exists( $candidate ) ? $candidate : '';
		}

		// Attachment URL served from another scheme/host variant.
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id ) {
			$path = get_attached_file( $attachment_id );
			return $path && file_exists( $path ) ? $path : '';
		}

		return '';
	}

	/**
	 * Target directory for rendered files.
	 *
	 * @return array{path:string,url:string}|\WP_Error
	 */
	private function target_dir() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'cpic_uploads', (string) $uploads['error'] );
		}

		$path = trailingslashit( $uploads['basedir'] ) . 'cpic-generated';
		if ( ! file_exists( $path ) ) {
			wp_mkdir_p( $path );
		}

		if ( ! is_dir( $path ) || ! is_writable( $path ) ) {
			return new \WP_Error( 'cpic_uploads_unwritable', __( 'The generated-image directory is not writable.', 'custom-post-image-creator' ) );
		}

		return array(
			'path' => $path,
			'url'  => trailingslashit( $uploads['baseurl'] ) . 'cpic-generated',
		);
	}
}
