<?php
/**
 * Database schema definitions.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for table names and CREATE statements.
 *
 * All tables are additive; migrations never drop 6.x data.
 */
final class Schema {

	/**
	 * Table suffix => logical key.
	 */
	const TABLES = array(
		'logs'           => 'cpic_logs',
		'templates'      => 'cpic_templates',
		'versions'       => 'cpic_template_versions',
		'brands'         => 'cpic_brands',
		'assets'         => 'cpic_assets',
		'artifacts'      => 'cpic_artifacts',
		'destinations'   => 'cpic_destinations',
		'campaigns'      => 'cpic_campaigns',
		'campaign_posts' => 'cpic_campaign_posts',
		'runs'           => 'cpic_delivery_runs',
		'deliveries'     => 'cpic_deliveries',
		'jobs'           => 'cpic_jobs',
		'mappings'       => 'cpic_category_mappings',
		'audit'          => 'cpic_audit',
		'ai_usage'       => 'cpic_ai_usage',
		'metrics'        => 'cpic_metrics',
	);

	/**
	 * Fully-qualified table name.
	 *
	 * @param string $key Logical key.
	 * @return string
	 */
	public static function table( $key ) {
		global $wpdb;
		$suffix = isset( self::TABLES[ $key ] ) ? self::TABLES[ $key ] : $key;
		return $wpdb->prefix . $suffix;
	}

	/**
	 * All table names, keyed by logical key.
	 *
	 * @return array<string,string>
	 */
	public static function tables() {
		$out = array();
		foreach ( array_keys( self::TABLES ) as $key ) {
			$out[ $key ] = self::table( $key );
		}
		return $out;
	}

	/**
	 * CREATE TABLE statements.
	 *
	 * @return array<string,string>
	 */
	public static function definitions() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$t       = self::tables();
		$sql     = array();

		$sql['logs'] = "CREATE TABLE {$t['logs']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			log_type VARCHAR(50) NOT NULL DEFAULT 'general',
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			message TEXT NOT NULL,
			context LONGTEXT NULL,
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			job_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY log_type (log_type),
			KEY level (level),
			KEY post_id (post_id),
			KEY created_at (created_at)
		) {$charset};";

		$sql['templates'] = "CREATE TABLE {$t['templates']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			config LONGTEXT NOT NULL,
			description TEXT NULL,
			collection VARCHAR(64) NOT NULL DEFAULT 'general',
			category VARCHAR(64) NOT NULL DEFAULT 'general',
			tags TEXT NULL,
			brand_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			schema_version VARCHAR(20) NOT NULL DEFAULT '7.0',
			revision INT UNSIGNED NOT NULL DEFAULT 1,
			preview_artifact_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			preview_url VARCHAR(500) NOT NULL DEFAULT '',
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			is_favorite TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY is_default (is_default),
			KEY is_active (is_active),
			KEY collection (collection),
			KEY category (category),
			KEY status (status),
			KEY brand_id (brand_id)
		) {$charset};";

		$sql['versions'] = "CREATE TABLE {$t['versions']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			template_id BIGINT(20) UNSIGNED NOT NULL,
			revision INT UNSIGNED NOT NULL DEFAULT 1,
			config LONGTEXT NOT NULL,
			name VARCHAR(191) NOT NULL DEFAULT '',
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY template_id (template_id),
			KEY revision (revision)
		) {$charset};";

		$sql['brands'] = "CREATE TABLE {$t['brands']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			logo_primary VARCHAR(500) NOT NULL DEFAULT '',
			logo_secondary VARCHAR(500) NOT NULL DEFAULT '',
			logo_light VARCHAR(500) NOT NULL DEFAULT '',
			logo_dark VARCHAR(500) NOT NULL DEFAULT '',
			primary_color VARCHAR(32) NOT NULL DEFAULT '#0F172A',
			secondary_color VARCHAR(32) NOT NULL DEFAULT '#1E293B',
			accent_color VARCHAR(32) NOT NULL DEFAULT '#E11D48',
			background_palette TEXT NULL,
			typography TEXT NULL,
			heading_font VARCHAR(120) NOT NULL DEFAULT 'Noto Sans Bengali',
			body_font VARCHAR(120) NOT NULL DEFAULT 'Hind Siliguri',
			watermark_asset_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			website_url VARCHAR(500) NOT NULL DEFAULT '',
			socials TEXT NULL,
			footer_text VARCHAR(500) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY is_default (is_default)
		) {$charset};";

		$sql['assets'] = "CREATE TABLE {$t['assets']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id VARCHAR(64) NOT NULL DEFAULT '',
			kind VARCHAR(32) NOT NULL DEFAULT 'image',
			title VARCHAR(191) NOT NULL DEFAULT '',
			alt VARCHAR(255) NOT NULL DEFAULT '',
			attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			url VARCHAR(1000) NOT NULL DEFAULT '',
			path VARCHAR(1000) NOT NULL DEFAULT '',
			mime VARCHAR(100) NOT NULL DEFAULT '',
			width INT UNSIGNED NOT NULL DEFAULT 0,
			height INT UNSIGNED NOT NULL DEFAULT 0,
			bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			checksum VARCHAR(64) NOT NULL DEFAULT '',
			category VARCHAR(64) NOT NULL DEFAULT 'general',
			tags TEXT NULL,
			usage_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY kind (kind),
			KEY category (category),
			KEY attachment_id (attachment_id),
			KEY checksum (checksum)
		) {$charset};";

		$sql['artifacts'] = "CREATE TABLE {$t['artifacts']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id VARCHAR(64) NOT NULL DEFAULT '',
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			template_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			template_revision INT UNSIGNED NOT NULL DEFAULT 0,
			brand_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			format VARCHAR(32) NOT NULL DEFAULT 'square',
			width INT UNSIGNED NOT NULL DEFAULT 0,
			height INT UNSIGNED NOT NULL DEFAULT 0,
			renderer VARCHAR(64) NOT NULL DEFAULT '',
			render_signature VARCHAR(64) NOT NULL DEFAULT '',
			source_post_modified DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			source_content_hash VARCHAR(64) NOT NULL DEFAULT '',
			font_signature VARCHAR(64) NOT NULL DEFAULT '',
			file_path VARCHAR(1000) NOT NULL DEFAULT '',
			file_url VARCHAR(1000) NOT NULL DEFAULT '',
			attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			mime VARCHAR(100) NOT NULL DEFAULT 'image/png',
			bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			checksum_sha256 VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'ready',
			meta LONGTEXT NULL,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY template_id (template_id),
			KEY status (status),
			KEY render_signature (render_signature),
			KEY created_at (created_at),
			KEY cache_key (post_id,render_signature,format)
		) {$charset};";

		$sql['destinations'] = "CREATE TABLE {$t['destinations']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id VARCHAR(64) NOT NULL DEFAULT '',
			provider VARCHAR(32) NOT NULL DEFAULT 'facebook',
			label VARCHAR(191) NOT NULL DEFAULT '',
			account_id VARCHAR(191) NOT NULL DEFAULT '',
			account_name VARCHAR(191) NOT NULL DEFAULT '',
			credentials LONGTEXT NULL,
			config LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'connected',
			scopes VARCHAR(500) NOT NULL DEFAULT '',
			token_expires_at DATETIME NULL,
			last_validated_at DATETIME NULL,
			last_error TEXT NULL,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			priority INT NOT NULL DEFAULT 5,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY provider (provider),
			KEY status (status),
			KEY account_id (account_id)
		) {$charset};";

		$sql['campaigns'] = "CREATE TABLE {$t['campaigns']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL DEFAULT '',
			description TEXT NULL,
			start_at DATETIME NULL,
			end_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			brand_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			destinations TEXT NULL,
			templates TEXT NULL,
			tags TEXT NULL,
			notes TEXT NULL,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY start_at (start_at)
		) {$charset};";

		$sql['campaign_posts'] = "CREATE TABLE {$t['campaign_posts']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			template_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'assigned',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_post (campaign_id,post_id),
			KEY post_id (post_id)
		) {$charset};";

		$sql['runs'] = "CREATE TABLE {$t['runs']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id VARCHAR(64) NOT NULL DEFAULT '',
			post_id BIGINT(20) UNSIGNED NOT NULL,
			campaign_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			artifact_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			source_revision VARCHAR(64) NOT NULL DEFAULT '',
			template_revision INT UNSIGNED NOT NULL DEFAULT 0,
			caption_snapshot LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			scheduled_for DATETIME NULL,
			started_at DATETIME NULL,
			completed_at DATETIME NULL,
			trigger_source VARCHAR(32) NOT NULL DEFAULT 'auto',
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status (status),
			KEY campaign_id (campaign_id),
			KEY scheduled_for (scheduled_for)
		) {$charset};";

		$sql['deliveries'] = "CREATE TABLE {$t['deliveries']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id VARCHAR(64) NOT NULL DEFAULT '',
			run_id BIGINT(20) UNSIGNED NOT NULL,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			destination_id BIGINT(20) UNSIGNED NOT NULL,
			provider VARCHAR(32) NOT NULL DEFAULT '',
			idempotency_key VARCHAR(191) NOT NULL DEFAULT '',
			artifact_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			caption LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
			scheduled_for DATETIME NULL,
			remote_id VARCHAR(191) NOT NULL DEFAULT '',
			remote_url VARCHAR(1000) NOT NULL DEFAULT '',
			first_comment_id VARCHAR(191) NOT NULL DEFAULT '',
			error_code VARCHAR(64) NOT NULL DEFAULT '',
			last_error TEXT NULL,
			started_at DATETIME NULL,
			claimed_at DATETIME NULL,
			completed_at DATETIME NULL,
			metrics LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY run_id (run_id),
			KEY post_id (post_id),
			KEY destination_id (destination_id),
			KEY status (status),
			KEY scheduled_for (scheduled_for)
		) {$charset};";

		$sql['jobs'] = "CREATE TABLE {$t['jobs']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			job_key VARCHAR(191) NOT NULL DEFAULT '',
			dedupe_key VARCHAR(191) NOT NULL DEFAULT '',
			type VARCHAR(64) NOT NULL DEFAULT 'publish',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			priority INT NOT NULL DEFAULT 5,
			payload LONGTEXT NULL,
			template_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			image_path TEXT NULL,
			image_url TEXT NULL,
			facebook_post_id VARCHAR(191) NOT NULL DEFAULT '',
			facebook_comment_id VARCHAR(191) NOT NULL DEFAULT '',
			attempts INT NOT NULL DEFAULT 0,
			max_retries INT NOT NULL DEFAULT 5,
			available_at DATETIME NULL,
			locked_at DATETIME NULL,
			locked_by VARCHAR(64) NOT NULL DEFAULT '',
			lock_token VARCHAR(64) NOT NULL DEFAULT '',
			last_error TEXT NULL,
			last_error_code VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY job_key (job_key),
			KEY dedupe_key (dedupe_key),
			KEY post_id (post_id),
			KEY status (status),
			KEY type (type),
			KEY available_at (available_at),
			KEY created_at (created_at)
		) {$charset};";

		$sql['mappings'] = "CREATE TABLE {$t['mappings']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			term_id BIGINT(20) UNSIGNED NOT NULL,
			template_id BIGINT(20) UNSIGNED NOT NULL,
			destination_ids VARCHAR(191) NOT NULL DEFAULT '',
			priority INT NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY term_id (term_id),
			KEY template_id (template_id)
		) {$charset};";

		$sql['audit'] = "CREATE TABLE {$t['audit']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(64) NOT NULL DEFAULT '',
			object_type VARCHAR(64) NOT NULL DEFAULT '',
			object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			message TEXT NULL,
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY action (action),
			KEY object_type (object_type),
			KEY object_id (object_id),
			KEY created_at (created_at)
		) {$charset};";

		$sql['ai_usage'] = "CREATE TABLE {$t['ai_usage']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			provider VARCHAR(64) NOT NULL DEFAULT '',
			model VARCHAR(120) NOT NULL DEFAULT '',
			operation VARCHAR(64) NOT NULL DEFAULT '',
			tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
			tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
			cost DECIMAL(12,6) NOT NULL DEFAULT 0,
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'ok',
			error TEXT NULL,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY provider (provider),
			KEY operation (operation),
			KEY created_at (created_at)
		) {$charset};";

		$sql['metrics'] = "CREATE TABLE {$t['metrics']} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			metric VARCHAR(64) NOT NULL DEFAULT '',
			object_type VARCHAR(64) NOT NULL DEFAULT '',
			object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			value DOUBLE NOT NULL DEFAULT 0,
			meta TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY metric (metric),
			KEY object_type (object_type),
			KEY created_at (created_at)
		) {$charset};";

		/**
		 * Filter the schema so extensions can add columns without patching core.
		 *
		 * @param array<string,string> $sql CREATE statements keyed by table key.
		 */
		return apply_filters( 'cpic/database/schema', $sql );
	}

	/**
	 * Required column map used by the migrator to detect missing columns on
	 * tables that predate 7.0.0.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function required_columns() {
		return array(
			'logs'         => array(
				'level'    => "VARCHAR(20) NOT NULL DEFAULT 'info'",
				'post_id'  => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
				'job_id'   => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
			),
			'templates'    => array(
				'description'         => 'TEXT NULL',
				'collection'          => "VARCHAR(64) NOT NULL DEFAULT 'general'",
				'category'            => "VARCHAR(64) NOT NULL DEFAULT 'general'",
				'tags'                => 'TEXT NULL',
				'brand_id'            => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
				'schema_version'      => "VARCHAR(20) NOT NULL DEFAULT '7.0'",
				'revision'            => 'INT UNSIGNED NOT NULL DEFAULT 1',
				'preview_artifact_id' => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
				'preview_url'         => "VARCHAR(500) NOT NULL DEFAULT ''",
				'is_favorite'         => 'TINYINT(1) NOT NULL DEFAULT 0',
				'status'              => "VARCHAR(20) NOT NULL DEFAULT 'active'",
				'created_by'          => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
			),
			'mappings'     => array(
				'destination_ids' => "VARCHAR(191) NOT NULL DEFAULT ''",
			),
			'jobs'         => array(
				'dedupe_key'      => "VARCHAR(191) NOT NULL DEFAULT ''",
				'type'            => "VARCHAR(64) NOT NULL DEFAULT 'publish'",
				'priority'        => 'INT NOT NULL DEFAULT 5',
				'payload'         => 'LONGTEXT NULL',
				'available_at'    => 'DATETIME NULL',
				'locked_at'       => 'DATETIME NULL',
				'locked_by'       => "VARCHAR(64) NOT NULL DEFAULT ''",
				'lock_token'      => "VARCHAR(64) NOT NULL DEFAULT ''",
				'last_error_code' => "VARCHAR(64) NOT NULL DEFAULT ''",
			),
			'logs_context' => array(),
		);
	}

	/**
	 * Required indexes for the 7.0 job queue (SQLite/MySQL safe check).
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function required_indexes() {
		return array(
			'jobs'    => array(
				'dedupe_key'   => 'dedupe_key',
				'available_at' => 'available_at',
				'type'         => 'type',
			),
			'runs'    => array(
				'campaign_id'   => 'campaign_id',
				'scheduled_for' => 'scheduled_for',
			),
			'artifacts' => array(
				'render_signature' => 'render_signature',
				'cache_key'        => 'post_id,render_signature,format',
			),
		);
	}
}
