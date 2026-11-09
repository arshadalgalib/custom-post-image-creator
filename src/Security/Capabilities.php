<?php
/**
 * Capabilities and roles.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Granular capabilities so editorial teams can be split across roles without
 * granting `manage_options`.
 */
final class Capabilities {

	/**
	 * Capability map.
	 */
	const CAPS = array(
		'cpic_access'             => 'read',
		'cpic_manage_templates'   => 'edit_posts',
		'cpic_manage_brands'      => 'manage_options',
		'cpic_manage_accounts'    => 'manage_options',
		'cpic_publish'            => 'edit_others_posts',
		'cpic_schedule'           => 'publish_posts',
		'cpic_manage_campaigns'   => 'edit_others_posts',
		'cpic_review_content'     => 'edit_others_posts',
		'cpic_approve_content'    => 'edit_others_posts',
		'cpic_view_analytics'     => 'edit_posts',
		'cpic_manage_settings'    => 'manage_options',
		'cpic_run_queue'          => 'manage_options',
		'cpic_manage_assets'      => 'upload_files',
		'cpic_use_ai'             => 'edit_posts',
		'cpic_manage_system'      => 'manage_options',
	);

	/**
	 * Grant defaults to administrator (and editor where appropriate).
	 *
	 * @return void
	 */
	public function install_roles() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_keys( self::CAPS ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		$editor = get_role( 'editor' );
		if ( $editor ) {
			foreach ( array( 'cpic_access', 'cpic_manage_templates', 'cpic_publish', 'cpic_schedule', 'cpic_manage_campaigns', 'cpic_review_content', 'cpic_approve_content', 'cpic_view_analytics', 'cpic_manage_assets', 'cpic_use_ai' ) as $cap ) {
				$editor->add_cap( $cap );
			}
		}

		$author = get_role( 'author' );
		if ( $author ) {
			foreach ( array( 'cpic_access', 'cpic_view_analytics' ) as $cap ) {
				$author->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove all plugin capabilities (used on uninstall).
	 *
	 * @return void
	 */
	public function remove_roles() {
		foreach ( array( 'administrator', 'editor', 'author', 'contributor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( array_keys( self::CAPS ) as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Capability check.
	 *
	 * @param string $cap Capability short name or full name.
	 * @return bool
	 */
	public function can( $cap ) {
		if ( 0 !== strpos( $cap, 'cpic_' ) ) {
			$cap = 'cpic_' . $cap;
		}
		return current_user_can( $cap );
	}

	/**
	 * Human label for a capability.
	 *
	 * @param string $cap Capability.
	 * @return string
	 */
	public function label( $cap ) {
		$labels = array(
			'cpic_access'           => __( 'Access Post Image Creator', 'custom-post-image-creator' ),
			'cpic_manage_templates' => __( 'Manage templates', 'custom-post-image-creator' ),
			'cpic_manage_brands'    => __( 'Manage brand kits', 'custom-post-image-creator' ),
			'cpic_manage_accounts'  => __( 'Manage social accounts', 'custom-post-image-creator' ),
			'cpic_publish'          => __( 'Publish content', 'custom-post-image-creator' ),
			'cpic_schedule'         => __( 'Schedule publishing', 'custom-post-image-creator' ),
			'cpic_manage_campaigns' => __( 'Manage campaigns', 'custom-post-image-creator' ),
			'cpic_review_content'   => __( 'Review content', 'custom-post-image-creator' ),
			'cpic_approve_content'  => __( 'Approve content', 'custom-post-image-creator' ),
			'cpic_view_analytics'   => __( 'View analytics', 'custom-post-image-creator' ),
			'cpic_manage_settings'  => __( 'Manage settings', 'custom-post-image-creator' ),
			'cpic_run_queue'        => __( 'Run background queue', 'custom-post-image-creator' ),
			'cpic_manage_assets'    => __( 'Manage media assets', 'custom-post-image-creator' ),
			'cpic_use_ai'           => __( 'Use AI assistance', 'custom-post-image-creator' ),
			'cpic_manage_system'    => __( 'Manage system / diagnostics', 'custom-post-image-creator' ),
		);

		if ( 0 !== strpos( $cap, 'cpic_' ) ) {
			$cap = 'cpic_' . $cap;
		}

		return isset( $labels[ $cap ] ) ? $labels[ $cap ] : $cap;
	}
}
