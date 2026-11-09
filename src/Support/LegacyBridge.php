<?php
/**
 * 6.x compatibility layer.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Support;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sites upgrading from 6.5.x may have theme code, snippets or shortcodes that
 * call the old global `CPIC_*` classes and `cpic_*` options directly.
 *
 * This bridge keeps those call sites working by:
 *  - aliasing the old class names onto thin adapters over the new services,
 *  - registering `cpic_*` filters/actions that 6.x exposed,
 *  - importing the legacy single-account credentials into destinations.
 *
 * Sites that never used 6.x see nothing but a handful of no-op filters.
 */
final class LegacyBridge {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Class aliases created so far.
	 *
	 * @var array<string,string>
	 */
	private $aliases = array();

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Register the compatibility surface (called once from the LegacyProvider).
	 *
	 * @return void
	 */
	public function register_compat_classes() {
		$this->aliases = array(
			'CPIC_Template_Manager' => '\\CPIC\\Support\\Compat\\TemplateManagerAdapter',
			'CPIC_Image_Generator'  => '\\CPIC\\Support\\Compat\\ImageGeneratorAdapter',
			'CPIC_Facebook_Poster'  => '\\CPIC\\Support\\Compat\\FacebookAdapter',
			'CPIC_Telegram_Poster'  => '\\CPIC\\Support\\Compat\\TelegramAdapter',
			'CPIC_Settings'         => '\\CPIC\\Support\\Compat\\SettingsAdapter',
		);

		foreach ( $this->aliases as $legacy => $class ) {
			if ( class_exists( $legacy, false ) || ! class_exists( $class ) ) {
				continue;
			}
			class_alias( $class, $legacy );
		}

		/**
		 * Fires after the 6.x class aliases exist, so themes can adapt.
		 *
		 * @param array<string,string> $aliases Legacy => new class map.
		 */
		do_action( 'cpic/legacy/aliases', $this->aliases );
	}

	/**
	 * Legacy class aliases.
	 *
	 * @return array<string,string>
	 */
	public function aliases() {
		return $this->aliases;
	}

	/**
	 * Read a 6.x option through the new settings schema.
	 *
	 * Old option names are preserved in the settings schema (see
	 * `cpic_title_font` and friends), so this is mostly a pass-through with
	 * type coercion. Unknown keys return the caller's default so a theme never
	 * fatals on a removed option.
	 *
	 * @param string $key     Legacy option key (with or without `cpic_`).
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function option( $key, $default = '' ) {
		$key = (string) $key;

		if ( 0 !== strpos( $key, 'cpic_' ) ) {
			$key = 'cpic_' . $key;
		}

		$defs = $this->container->get( 'settings' )->definitions();

		if ( ! isset( $defs[ $key ] ) ) {
			return $default;
		}

		$value = $this->container->get( 'settings' )->get( $key );

		return '' === $value || null === $value ? $default : $value;
	}

	/**
	 * Import 6.x single-account credentials into the destinations table.
	 *
	 * Idempotent: an existing destination for the same provider + account is
	 * left untouched, and the plaintext options are never deleted.
	 *
	 * @return int Number of destinations created.
	 */
	public function import_credentials() {
		return (int) $this->container->get( 'accounts' )->import_legacy_credentials();
	}

	/**
	 * Render a post using the 6.x style call signature.
	 *
	 * @param int   $post_id   Post id.
	 * @param array $overrides Optional text/layout overrides.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function generate( $post_id, array $overrides = array() ) {
		return $this->container->get( 'render' )->render(
			(int) $post_id,
			array(
				'overrides'   => $overrides,
				'browser_only' => true,
			)
		);
	}

	/**
	 * The template the legacy settings describe, rebuilt as a 7.0 document.
	 *
	 * Used for sites that never saved a template but rely on the old
	 * "settings" screen layout.
	 *
	 * @return array<string,mixed>
	 */
	public function legacy_document() {
		$settings = $this->container->get( 'settings' );

		$legacy = array(
			'name'     => __( 'Imported 6.x layout', 'custom-post-image-creator' ),
			'canvas'   => array(
				'width'          => $settings->int( 'cpic_canvas_width', 1080 ),
				'height'         => $settings->int( 'cpic_canvas_height', 1080 ),
				'background_type' => ! empty( $settings->get( 'cpic_active_background' ) ) ? 'image' : 'gradient',
				'background_image' => (string) $settings->get( 'cpic_active_background' ),
				'padding'        => 40,
			),
			'elements' => array(
				'featured_image' => array(
					'enabled' => true,
					'x'       => $settings->int( 'cpic_feat_offset_x', 0 ),
					'y'       => $settings->int( 'cpic_feat_offset_y', 0 ),
					'width'   => $settings->int( 'cpic_feat_width', 1080 ),
					'height'  => $settings->int( 'cpic_feat_height', 650 ),
					'fit'     => 'fill',
					'z_index' => 10,
				),
				'headline'       => array(
					'enabled'    => true,
					'source'     => 'post_title',
					'font'       => (string) $settings->get( 'cpic_title_font', 'Noto Serif Bengali' ),
					'font_size'  => $settings->int( 'cpic_title_size', 42 ),
					'color'      => (string) $settings->get( 'cpic_title_color', '#FFFFFF' ),
					'align'      => 'left',
					'max_lines'  => 3,
					'x'          => $settings->int( 'cpic_title_offset_x', 40 ),
					'y'          => $settings->int( 'cpic_title_offset_y', 750 ),
					'z_index'    => 30,
				),
				'date'           => array(
					'enabled'          => true,
					'source'           => 'post_date',
					'font'             => (string) $settings->get( 'cpic_date_font', 'Hind Siliguri' ),
					'font_size'        => 28,
					'color'            => (string) $settings->get( 'cpic_date_color', '#FFFFFF' ),
					'background_color' => (string) $settings->get( 'cpic_date_bg_color', '#E53935' ),
					'x'                => $settings->int( 'cpic_date_offset_x', 40 ),
					'y'                => $settings->int( 'cpic_date_offset_y', 950 ),
					'z_index'          => 40,
				),
				'logo'           => array(
					'enabled' => '' !== (string) $settings->get( 'cpic_logo' ),
					'source'  => 'custom_url',
					'url'     => (string) $settings->get( 'cpic_logo' ),
					'x'       => $settings->int( 'cpic_logo_offset_x', 40 ),
					'y'       => $settings->int( 'cpic_logo_offset_y', 40 ),
					'width'   => $settings->int( 'cpic_logo_width', 120 ),
					'z_index' => 60,
				),
			),
		);

		return $this->container->get( 'template_schema' )->migrate_legacy( $legacy );
	}

	/**
	 * Notice-free no-op used by 6.x hook callbacks that expect a bool.
	 *
	 * @return bool
	 */
	public function deprecated( $feature ) {
		if ( $this->container->get( 'settings' )->is_enabled( 'cpic_debug' ) ) {
			$this->container->get( 'logger' )->debug(
				'legacy',
				/* translators: %s: feature name */
				sprintf( __( 'Deprecated CPIC API used: %s', 'custom-post-image-creator' ), (string) $feature )
			);
		}

		return true;
	}
}
