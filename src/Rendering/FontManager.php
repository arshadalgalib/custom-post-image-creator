<?php
/**
 * Font discovery, manifests and readiness reporting.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Rendering;

use CPIC\Security\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The browser is the shaping engine; this class only describes which files
 * exist, how they are named in CSS, and whether each one can render Bengali.
 */
final class FontManager {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Cached manifest.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private $manifest = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Bundled font definitions. `unicode_range` is intentionally omitted for
	 * Bengali faces so the browser always attempts the face for Bengali text.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function bundled() {
		$fonts = array(
			array(
				'family'     => 'Noto Sans Bengali',
				'weight'     => 400,
				'style'      => 'normal',
				'file'       => 'fonts/NotoSansBengali-Regular.ttf',
				'script'     => 'beng',
				'bengali'    => true,
				'label'      => 'Noto Sans Bengali',
			),
			array(
				'family'     => 'Noto Serif Bengali',
				'weight'     => 400,
				'style'      => 'normal',
				'file'       => 'fonts/NotoSerifBengali-Regular.ttf',
				'script'     => 'beng',
				'bengali'    => true,
				'label'      => 'Noto Serif Bengali',
			),
			array(
				'family'     => 'Hind Siliguri',
				'weight'     => 400,
				'style'      => 'normal',
				'file'       => 'fonts/HindSiliguri-Regular.ttf',
				'script'     => 'beng',
				'bengali'    => true,
				'label'      => 'Hind Siliguri',
			),
			array(
				'family'     => 'Hind Siliguri',
				'weight'     => 600,
				'style'      => 'normal',
				'file'       => 'fonts/HindSiliguri-Regular.ttf',
				'script'     => 'beng',
				'bengali'    => true,
				'label'      => 'Hind Siliguri Semibold',
				'synthetic'  => true,
			),
		);

		/**
		 * Filter bundled fonts so themes can add their own Bengali faces.
		 *
		 * @param array<int,array<string,mixed>> $fonts Fonts.
		 */
		return apply_filters( 'cpic/fonts', $fonts );
	}

	/**
	 * Manifest of fonts with availability + URL info.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function manifest() {
		if ( null !== $this->manifest ) {
			return $this->manifest;
		}

		$out = array();

		foreach ( $this->bundled() as $font ) {
			$path = CPIC_PLUGIN_DIR . ltrim( $font['file'], '/' );
			$url  = CPIC_PLUGIN_URL . ltrim( $font['file'], '/' );

			$font['available'] = is_readable( $path );
			$font['url']       = $font['available'] ? $url : '';
			$font['bytes']     = $font['available'] ? (int) @filesize( $path ) : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$font['checksum']  = $font['available'] ? (string) md5_file( $path ) : '';
			$font['format']    = 'truetype';

			$out[] = $font;
		}

		/**
		 * Filter the resolved font manifest.
		 *
		 * @param array<int,array<string,mixed>> $out Manifest rows.
		 */
		$this->manifest = apply_filters( 'cpic/font_manifest', $out );

		return $this->manifest;
	}

	/**
	 * Distinct family names available.
	 *
	 * @return array<int,string>
	 */
	public function families() {
		$families = array();
		foreach ( $this->manifest() as $font ) {
			if ( empty( $font['available'] ) ) {
				continue;
			}
			if ( ! in_array( $font['family'], $families, true ) ) {
				$families[] = $font['family'];
			}
		}
		return $families;
	}

	/**
	 * Resolve a family name to the best available face.
	 *
	 * @param string $family Requested family.
	 * @return array<string,mixed>|null
	 */
	public function resolve( $family ) {
		foreach ( $this->manifest() as $font ) {
			if ( $font['available'] && $font['family'] === $family ) {
				return $font;
			}
		}

		// Graceful fallback to the first available Bengali face.
		foreach ( $this->manifest() as $font ) {
			if ( $font['available'] && ! empty( $font['bengali'] ) ) {
				return $font;
			}
		}

		return null;
	}

	/**
	 * A stable signature of the font setup so artifacts can be invalidated
	 * when a font file changes.
	 *
	 * @return string
	 */
	public function signature() {
		$parts = array();
		foreach ( $this->manifest() as $font ) {
			$parts[] = $font['family'] . ':' . ( $font['available'] ? $font['checksum'] : 'missing' );
		}
		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Font status for the diagnostics page (no filesystem paths exposed).
	 *
	 * @return array<string,mixed>
	 */
	public function status() {
		$rows    = array();
		$missing = 0;

		foreach ( $this->manifest() as $font ) {
			if ( empty( $font['available'] ) ) {
				$missing++;
			}
			$rows[] = array(
				'family'    => $font['family'],
				'weight'    => $font['weight'],
				'available' => (bool) $font['available'],
				'bengali'   => ! empty( $font['bengali'] ),
				'bytes'     => (int) $font['bytes'],
			);
		}

		return array(
			'ok'       => 0 === $missing,
			'families' => $this->families(),
			'missing'  => $missing,
			'fonts'    => $rows,
		);
	}

	/**
	 * CSS `@font-face` block for editor + preview screens.
	 *
	 * @return string
	 */
	public function css() {
		$css = '';

		foreach ( $this->manifest() as $font ) {
			if ( empty( $font['available'] ) ) {
				continue;
			}
			$css .= sprintf(
				"@font-face{font-family:'%s';font-style:%s;font-weight:%d;font-display:block;src:url('%s') format('%s');}\n",
				str_replace( array( "'", '\n' ), '', (string) $font['family'] ),
				str_replace( array( "'", '\n' ), '', (string) $font['style'] ),
				(int) $font['weight'],
				esc_url( $font['url'] ),
				'truetype' === $font['format'] ? 'truetype' : str_replace( array( "'", '\n' ), '', (string) $font['format'] )
			);
		}

		return $css;
	}
}
