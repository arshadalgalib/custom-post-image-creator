<?php
/**
 * 6.x compatibility shim: TemplateManagerAdapter.
 *
 * One class per file so the autoloader can find it; the shared helper lives in
 * `bootstrap.php` and is required below.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Support\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

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
