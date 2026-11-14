<?php
/**
 * Server-side SVG renderer (shaping-capable hosts).
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Rendering;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a plan on the server by serialising it to SVG and rasterising it with
 * whatever shaping-capable tool the host provides.
 *
 * Why this exists: the browser renderer is correct but only available while an
 * admin has the studio open, so anything scheduled, automated, or published
 * from elsewhere had no way to produce a Bengali image. This renderer runs
 * inside a PHP request, which means WP-Cron, the REST API and the queue worker
 * can all produce a real artifact.
 *
 * Why it is not GD: `imagettftext()` writes one glyph per code point. Bengali
 * conjuncts and reordering vowel signs (`ি`) come out wrong, so GD refuses that
 * text outright. Every rasterizer below runs Pango or HarfBuzz, which shapes
 * correctly.
 *
 * Detection is deliberately strict: this renderer reports itself unavailable
 * unless a binary that can actually shape is present. It never produces a
 * wrong-looking image and calls it a success.
 */
final class SvgRenderer implements RendererInterface {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Cached detection result.
	 *
	 * @var array{id:string,binary:string}|null|false
	 */
	private $backend = false;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'svg';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Server SVG (Pango/HarfBuzz shaping)', 'custom-post-image-creator' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return null !== $this->backend();
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
		return true;
	}

	/**
	 * Which rasterizer this host has, if any.
	 *
	 * Order matters: `rsvg-convert` and `inkscape` are pure SVG tools and give
	 * the most predictable output; ImageMagick is far more common but only
	 * shapes correctly when its SVG delegate is librsvg (the MSVG internal
	 * renderer has no HarfBuzz and is explicitly rejected below).
	 *
	 * @return array{id:string,binary:string}|null
	 */
	public function backend() {
		if ( false !== $this->backend ) {
			return $this->backend;
		}

		/**
		 * Force a rasterizer, or disable this renderer with `false`.
		 *
		 * @param array{id:string,binary:string}|null $backend Backend or null.
		 */
		$filtered = apply_filters( 'cpic/svg/backend', null );

		/*
		 * A bare tool name is the shorthand from the docs, and it is an
		 * *instruction*, not a hint: if the named tool cannot be found the
		 * renderer reports itself unavailable rather than quietly rasterising
		 * with a different tool the admin did not choose.
		 */
		if ( is_string( $filtered ) && '' !== $filtered ) {
			$path = $this->locate( $filtered );

			$this->backend = '' === $path ? null : array( 'id' => $filtered, 'binary' => $path );

			return $this->backend;
		}

		if ( is_array( $filtered ) && isset( $filtered['id'], $filtered['binary'] ) ) {
			$this->backend = array(
				'id'     => (string) $filtered['id'],
				'binary' => (string) $filtered['binary'],
			);

			return $this->backend;
		}

		if ( false === $filtered ) {
			$this->backend = null;
			return null;
		}

		foreach ( array( 'rsvg-convert', 'inkscape' ) as $binary ) {
			$path = $this->locate( $binary );

			if ( '' !== $path ) {
				$this->backend = array( 'id' => $binary, 'binary' => $path );
				return $this->backend;
			}
		}

		$convert = $this->locate( 'convert' );

		if ( '' === $convert ) {
			$this->backend = null;
			return null;
		}

		// ImageMagick is only acceptable with a shaping-capable SVG delegate.
		$delegates = $this->imagemagick_svg_delegate( $convert );

		if ( ! in_array( $delegates, array( 'rsvg', 'librsvg' ), true ) ) {
			$this->backend = null;
			return null;
		}

		$this->backend = array( 'id' => 'convert', 'binary' => $convert );

		return $this->backend;
	}

	/**
	 * Human explanation of why this renderer cannot run, for diagnostics.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		if ( null !== $this->backend() ) {
			return '';
		}

		return __( 'No SVG rasterizer with text shaping was found on this server. Install librsvg (rsvg-convert), Inkscape, or ImageMagick built with the librsvg delegate, so Bengali text can be shaped in the background.', 'custom-post-image-creator' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $plan ) {
		$backend = $this->backend();

		if ( null === $backend ) {
			return new \WP_Error(
				'cpic_no_background_renderer',
				$this->unavailable_reason(),
				array( 'status' => 409 )
			);
		}

		$svg = $this->container->get( 'svg_serializer' )->serialize( $plan );

		$dir = $this->container->get( 'artifact_manager' )->directory();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$token    = substr( md5( (string) wp_rand() . microtime( true ) ), 0, 10 );
		$svg_path = trailingslashit( $dir['path'] ) . 'cpic-render-' . $token . '.svg';
		$png_path = trailingslashit( $dir['path'] ) . 'cpic-render-' . $token . '.png';

		$written = file_put_contents( $svg_path, $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( false === $written ) {
			return new \WP_Error( 'cpic_svg_write', __( 'The SVG document could not be written to disk.', 'custom-post-image-creator' ) );
		}

		$width  = max( 1, (int) ( isset( $plan['width'] ) ? $plan['width'] : 1080 ) );
		$height = max( 1, (int) ( isset( $plan['height'] ) ? $plan['height'] : 1080 ) );

		$command = $this->command( $backend['id'], $backend['binary'], $svg_path, $png_path, $width, $height );

		$output = array();
		$code   = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec,WordPress.PHP.NoSilencedErrors.Discouraged
		@exec( $command . ' 2>&1', $output, $code );

		@unlink( $svg_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink

		if ( 0 !== $code || ! file_exists( $png_path ) ) {
			$this->container->get( 'logger' )->error(
				'render',
				/* translators: %s: command output */
				sprintf( __( 'The SVG rasterizer failed: %s', 'custom-post-image-creator' ), implode( ' ', array_slice( (array) $output, -4 ) ) ),
				array( 'backend' => $backend['id'], 'exit' => $code )
			);

			@unlink( $png_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink

			return new \WP_Error(
				'cpic_svg_rasterize',
				sprintf(
					/* translators: %s: rasterizer name */
					__( 'The server could not rasterise the image with %s. Check that the tool runs from PHP, then try again.', 'custom-post-image-creator' ),
					$backend['id']
				),
				array( 'status' => 500, 'backend' => $backend['id'] )
			);
		}

		$info = @getimagesize( $png_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! $info || IMAGETYPE_PNG !== $info[2] ) {
			@unlink( $png_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink

			return new \WP_Error( 'cpic_not_png', __( 'The rasterizer did not produce a PNG.', 'custom-post-image-creator' ) );
		}

		$this->container->get( 'audit' )->record(
			'render.server',
			'artifact',
			0,
			/* translators: 1: width, 2: height, 3: renderer */
			sprintf( __( 'Rendered %1$d×%2$d on the server with %3$s', 'custom-post-image-creator' ), $width, $height, $backend['id'] )
		);

		return array(
			'path'     => $png_path,
			'url'      => trailingslashit( $dir['url'] ) . basename( $png_path ),
			'width'    => $width,
			'height'   => $height,
			'checksum' => (string) hash_file( 'sha256', $png_path ),
			'mime'     => 'image/png',
			// The artifact manager copies the bytes into its own directory.
			'cleanup'  => true,
		);
	}

	/**
	 * Build the rasterizer command line.
	 *
	 * Every path is escaped; nothing here is interpolated from user input
	 * beyond file names this class generated itself.
	 *
	 * @param string $id       Backend id.
	 * @param string $binary   Binary path.
	 * @param string $svg_path Input SVG.
	 * @param string $png_path Output PNG.
	 * @param int    $width    Width.
	 * @param int    $height   Height.
	 * @return string
	 */
	private function command( $id, $binary, $svg_path, $png_path, $width, $height ) {
		$in  = escapeshellarg( $svg_path );
		$out = escapeshellarg( $png_path );

		switch ( $id ) {
			case 'inkscape':
				return sprintf( '%s %s --export-type=png --export-filename=%s --export-width=%d --export-height=%d', escapeshellarg( $binary ), $in, $out, $width, $height );
			case 'convert':
				return sprintf( '%s -background none -density 144 %s -resize %dx%d %s', escapeshellarg( $binary ), $in, $width, $height, $out );
			case 'rsvg-convert':
			default:
				return sprintf( '%s --format=png --width=%d --height=%d --output=%s %s', escapeshellarg( $binary ), $width, $height, $out, $in );
		}
	}

	/**
	 * The rasteriser in use, by name.
	 *
	 * @return string Empty when nothing was detected.
	 */
	public function backend_name() {
		$backend = $this->backend();

		return null === $backend ? '' : (string) $backend['id'];
	}

	/**
	 * Locate an executable on PATH.
	 *
	 * @param string $binary Binary name.
	 * @return string Absolute path, or ''.
	 */
	private function locate( $binary ) {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		if ( in_array( 'exec', $disabled, true ) || ! function_exists( 'exec' ) ) {
			return '';
		}

		$command = sprintf( 'command -v %s 2>/dev/null', escapeshellarg( $binary ) );

		$output = array();
		$code   = 1;

		/*
		 * `exec()` raises "Unable to fork" as a *warning* when the host forbids
		 * process creation — before it even runs the command. That warning is
		 * printed into whatever produced it, which here means it would land in
		 * the middle of a PNG download or a JSON response. Capability
		 * detection is expected to fail quietly, so it is silenced and the
		 * failure is reported through the return value instead.
		 */
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec,WordPress.PHP.NoSilencedErrors.Discouraged
		@exec( $command, $output, $code );

		if ( 0 !== $code || empty( $output[0] ) ) {
			return '';
		}

		$path = trim( (string) $output[0] );

		return file_exists( $path ) ? $path : '';
	}

	/**
	 * ImageMagick's SVG delegate name.
	 *
	 * @param string $convert Path to `convert`.
	 * @return string Lower-cased delegate, or ''.
	 */
	private function imagemagick_svg_delegate( $convert ) {
		$output = array();
		$code   = 1;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec,WordPress.PHP.NoSilencedErrors.Discouraged
		@exec( escapeshellarg( $convert ) . ' -list delegate 2>/dev/null', $output, $code );

		if ( 0 !== $code ) {
			return '';
		}

		foreach ( (array) $output as $line ) {
			if ( preg_match( '/^\s*svg\s*=>\s*([a-z]+)/i', (string) $line, $matches ) ) {
				return strtolower( (string) $matches[1] );
			}

			if ( false !== stripos( (string) $line, 'rsvg' ) ) {
				return 'rsvg';
			}
		}

		return '';
	}
}
