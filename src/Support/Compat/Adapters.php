<?php
/**
 * 6.x class adapters.
 *
 * Deliberately one file: these are tiny shims that must exist before any theme
 * code runs, and keeping them together documents the whole legacy surface.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Support\Compat;

use CPIC\Support\LegacyBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service access for the shims.
 *
 * @return LegacyBridge
 */
function bridge() {
	return \CPIC\Core\Plugin::instance()->service( 'legacy' );
}

/**
 * 6.x `CPIC_Template_Manager` shim.
 */
final class TemplateManagerAdapter {

	/**
	 * All templates (legacy keyed array).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_templates() {
		$out = array();

		foreach ( \CPIC\Core\Plugin::instance()->service( 'template_manager' )->all() as $template ) {
			$out[ $template['slug'] ] = $template;
		}

		return $out;
	}

	/**
	 * One template by slug.
	 *
	 * @param string $slug Slug.
	 * @return array<string,mixed>|null
	 */
	public static function get_template( $slug ) {
		return \CPIC\Core\Plugin::instance()->service( 'template_manager' )->get( (string) $slug );
	}

	/**
	 * The active template document.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_active_template() {
		$template = \CPIC\Core\Plugin::instance()->service( 'template_manager' )->default_template();

		return $template ? $template['document'] : bridge()->legacy_document();
	}

	/**
	 * Save a legacy config array as a template.
	 *
	 * @param string $name   Name.
	 * @param array  $config Legacy config.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function save_template( $name, $config ) {
		$schema    = \CPIC\Core\Plugin::instance()->service( 'template_schema' );
		$document  = $schema->migrate_legacy( is_array( $config ) ? $config : array() );

		return \CPIC\Core\Plugin::instance()->service( 'template_manager' )->create(
			array(
				'name'     => (string) $name,
				'document' => $document,
			)
		);
	}
}

/**
 * 6.x `CPIC_Image_Generator` shim.
 */
final class ImageGeneratorAdapter {

	/**
	 * Generate an image for a post (legacy signature).
	 *
	 * @param int   $post_id   Post id.
	 * @param array $template  Optional legacy template config.
	 * @param array $overrides Overrides.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function generate( $post_id, $template = array(), $overrides = array() ) {
		$args = array();

		if ( is_array( $template ) && ! empty( $template ) ) {
			$args['document'] = \CPIC\Core\Plugin::instance()->service( 'template_schema' )->migrate_legacy( $template );
		}

		$args['overrides'] = is_array( $overrides ) ? $overrides : array();

		return \CPIC\Core\Plugin::instance()->service( 'render' )->render( (int) $post_id, $args );
	}

	/**
	 * The render plan for a post (used by 6.x previews).
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function plan( $post_id ) {
		return \CPIC\Core\Plugin::instance()->service( 'render' )->plan( (int) $post_id );
	}
}

/**
 * 6.x `CPIC_Facebook_Poster` shim.
 */
final class FacebookAdapter {

	/**
	 * Publish a post to the default Facebook page.
	 *
	 * @param int   $post_id Post id.
	 * @param array $args    Options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function publish( $post_id, $args = array() ) {
		$args['providers'] = array( 'facebook' );

		return \CPIC\Core\Plugin::instance()->service( 'delivery_manager' )->publish_post( (int) $post_id, $args );
	}
}

/**
 * 6.x `CPIC_Telegram_Poster` shim.
 */
final class TelegramAdapter {

	/**
	 * Publish a post to the default Telegram chat.
	 *
	 * @param int   $post_id Post id.
	 * @param array $args    Options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function publish( $post_id, $args = array() ) {
		$args['providers'] = array( 'telegram' );

		return \CPIC\Core\Plugin::instance()->service( 'delivery_manager' )->publish_post( (int) $post_id, $args );
	}
}

/**
 * 6.x `CPIC_Settings` shim.
 */
final class SettingsAdapter {

	/**
	 * Get a legacy option.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		return bridge()->option( $key, $default );
	}

	/**
	 * Update a legacy option through the validated schema.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public static function update( $key, $value ) {
		$key = 0 === strpos( (string) $key, 'cpic_' ) ? (string) $key : 'cpic_' . (string) $key;

		return \CPIC\Core\Plugin::instance()->service( 'settings' )->set( $key, $value );
	}
}
