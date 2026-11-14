<?php
/**
 * Settings registry and storage.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single schema (option key => definition) shared by the settings UI, the
 * REST API and validation helpers. Every setting has an explicit sanitizer,
 * so nothing is ever written unvalidated.
 */
final class Settings {

	/**
	 * Option that stores API version registry overrides.
	 */
	const OPTION_PREFIX = 'cpic_';

	/**
	 * Cached definition map.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private $definitions = null;

	/**
	 * Cached values.
	 *
	 * @var array<string,mixed>
	 */
	private $cache = array();

	/**
	 * Setting definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function definitions() {
		if ( null !== $this->definitions ) {
			return $this->definitions;
		}

		$defs = array(
			/* ---- General ---- */
			'cpic_enabled'                 => array( 'type' => 'bool', 'default' => 1, 'section' => 'general', 'label' => __( 'Enable plugin automation', 'custom-post-image-creator' ) ),
			'cpic_post_types'              => array( 'type' => 'csv', 'default' => 'post', 'section' => 'general', 'label' => __( 'Post types', 'custom-post-image-creator' ) ),
			'cpic_default_template_id'     => array( 'type' => 'int', 'default' => 0, 'section' => 'general', 'label' => __( 'Default template', 'custom-post-image-creator' ) ),
			'cpic_default_brand_id'        => array( 'type' => 'int', 'default' => 0, 'section' => 'general', 'label' => __( 'Default brand kit', 'custom-post-image-creator' ) ),
			'cpic_default_format'          => array( 'type' => 'key', 'default' => 'square', 'section' => 'general', 'label' => __( 'Default output format', 'custom-post-image-creator' ) ),
			'cpic_notify_email'            => array( 'type' => 'email', 'default' => '', 'section' => 'general', 'label' => __( 'Failure notification email', 'custom-post-image-creator' ) ),
			'cpic_require_approval'        => array( 'type' => 'bool', 'default' => 0, 'section' => 'general', 'label' => __( 'Require editorial approval', 'custom-post-image-creator' ) ),

			/* ---- Rendering ---- */
			'cpic_title_font'              => array( 'type' => 'text', 'default' => 'Noto Serif Bengali', 'section' => 'rendering', 'label' => __( 'Legacy headline font', 'custom-post-image-creator' ) ),
			'cpic_title_size'              => array( 'type' => 'int', 'default' => 42, 'section' => 'rendering', 'label' => __( 'Legacy headline size', 'custom-post-image-creator' ) ),
			'cpic_title_color'             => array( 'type' => 'color', 'default' => '#FFFFFF', 'section' => 'rendering', 'label' => __( 'Legacy headline colour', 'custom-post-image-creator' ) ),
			'cpic_title_offset_x'          => array( 'type' => 'int', 'default' => 40, 'section' => 'rendering' ),
			'cpic_title_offset_y'          => array( 'type' => 'int', 'default' => 750, 'section' => 'rendering' ),
			'cpic_date_font'               => array( 'type' => 'text', 'default' => 'Hind Siliguri', 'section' => 'rendering' ),
			'cpic_date_color'              => array( 'type' => 'color', 'default' => '#FFFFFF', 'section' => 'rendering' ),
			'cpic_date_bg_color'           => array( 'type' => 'color', 'default' => '#E53935', 'section' => 'rendering' ),
			'cpic_date_offset_x'           => array( 'type' => 'int', 'default' => 40, 'section' => 'rendering' ),
			'cpic_date_offset_y'           => array( 'type' => 'int', 'default' => 950, 'section' => 'rendering' ),
			'cpic_logo'                    => array( 'type' => 'url', 'default' => '', 'section' => 'rendering' ),
			'cpic_logo_width'              => array( 'type' => 'int', 'default' => 120, 'section' => 'rendering' ),
			'cpic_logo_offset_x'           => array( 'type' => 'int', 'default' => 40, 'section' => 'rendering' ),
			'cpic_logo_offset_y'           => array( 'type' => 'int', 'default' => 40, 'section' => 'rendering' ),
			'cpic_feat_offset_x'           => array( 'type' => 'int', 'default' => 0, 'section' => 'rendering' ),
			'cpic_feat_offset_y'           => array( 'type' => 'int', 'default' => 0, 'section' => 'rendering' ),
			'cpic_feat_width'              => array( 'type' => 'int', 'default' => 1080, 'section' => 'rendering' ),
			'cpic_feat_height'             => array( 'type' => 'int', 'default' => 650, 'section' => 'rendering' ),
			'cpic_canvas_width'            => array( 'type' => 'int', 'default' => 1080, 'section' => 'rendering' ),
			'cpic_canvas_height'           => array( 'type' => 'int', 'default' => 1080, 'section' => 'rendering' ),
			'cpic_background_images'       => array( 'type' => 'url_list', 'default' => array(), 'section' => 'rendering' ),
			'cpic_active_background'       => array( 'type' => 'url', 'default' => '', 'section' => 'rendering' ),
			'cpic_renderer'                => array( 'type' => 'key', 'default' => '', 'section' => 'rendering', 'label' => __( 'Preferred renderer (blank = pick the best server renderer)', 'custom-post-image-creator' ) ),
			'cpic_allow_server_fallback'   => array( 'type' => 'bool', 'default' => 1, 'section' => 'rendering', 'label' => __( 'Allow the latin-only GD fallback when no shaping rasteriser is installed', 'custom-post-image-creator' ) ),
			'cpic_image_quality'           => array( 'type' => 'int', 'default' => 92, 'section' => 'rendering' ),
			'cpic_webp_enabled'            => array( 'type' => 'bool', 'default' => 0, 'section' => 'rendering' ),
			'cpic_max_upload_mb'           => array( 'type' => 'int', 'default' => 8, 'section' => 'rendering' ),
			'cpic_render_cache'            => array( 'type' => 'bool', 'default' => 1, 'section' => 'rendering' ),

			/* ---- Captions / publishing defaults ---- */
			'cpic_comment_message'         => array( 'type' => 'text', 'default' => 'বিস্তারিত পড়ুন', 'section' => 'publishing' ),
			'cpic_caption_template'        => array( 'type' => 'textarea', 'default' => '', 'section' => 'publishing' ),
			'cpic_first_comment_link'      => array( 'type' => 'bool', 'default' => 1, 'section' => 'publishing' ),
			'cpic_hashtags'                => array( 'type' => 'csv', 'default' => '', 'section' => 'publishing' ),
			'cpic_publish_delay'           => array( 'type' => 'int', 'default' => 0, 'section' => 'publishing' ),

			/* ---- Automation ---- */
			'cpic_auto_publish'            => array( 'type' => 'bool', 'default' => 0, 'section' => 'automation' ),
			'cpic_auto_fb'                 => array( 'type' => 'bool', 'default' => 0, 'section' => 'automation' ),
			'cpic_auto_tg'                 => array( 'type' => 'bool', 'default' => 0, 'section' => 'automation' ),
			'cpic_auto_delay'              => array( 'type' => 'int', 'default' => 0, 'section' => 'automation' ),
			'cpic_auto_generate'           => array( 'type' => 'bool', 'default' => 1, 'section' => 'automation' ),
			'cpic_auto_comment'            => array( 'type' => 'bool', 'default' => 1, 'section' => 'automation' ),
			'cpic_max_retries'             => array( 'type' => 'int', 'default' => 5, 'section' => 'automation' ),
			'cpic_retry_base_delay'        => array( 'type' => 'int', 'default' => 60, 'section' => 'automation' ),
			'cpic_retry_max_delay'         => array( 'type' => 'int', 'default' => 3600, 'section' => 'automation' ),
			'cpic_batch_size'              => array( 'type' => 'int', 'default' => 5, 'section' => 'automation' ),
			'cpic_time_budget'             => array( 'type' => 'int', 'default' => 20, 'section' => 'automation' ),
			'cpic_scheduler'               => array( 'type' => 'key', 'default' => 'auto', 'section' => 'automation' ),
			'cpic_worker_lock_ttl'         => array( 'type' => 'int', 'default' => 300, 'section' => 'automation' ),
			'cpic_inline_processing'       => array( 'type' => 'bool', 'default' => 1, 'section' => 'automation' ),

			/* ---- AI ---- */
			'cpic_ai_enabled'              => array( 'type' => 'bool', 'default' => 0, 'section' => 'ai' ),
			'cpic_ai_provider'             => array( 'type' => 'key', 'default' => '', 'section' => 'ai' ),
			'cpic_ai_model'                => array( 'type' => 'text', 'default' => '', 'section' => 'ai' ),
			'cpic_ai_base_url'             => array( 'type' => 'url', 'default' => '', 'section' => 'ai' ),
			'cpic_ai_api_key'              => array( 'type' => 'secret', 'default' => '', 'section' => 'ai' ),
			'cpic_ai_timeout'              => array( 'type' => 'int', 'default' => 30, 'section' => 'ai' ),
			'cpic_ai_monthly_limit'        => array( 'type' => 'int', 'default' => 0, 'section' => 'ai' ),

			/* ---- Storage ---- */
			'cpic_artifact_retention_days' => array( 'type' => 'int', 'default' => 90, 'section' => 'storage' ),
			'cpic_log_retention_days'      => array( 'type' => 'int', 'default' => 30, 'section' => 'storage' ),
			'cpic_cleanup_on_uninstall'    => array( 'type' => 'bool', 'default' => 0, 'section' => 'storage' ),

			/* ---- Debug ---- */
			'cpic_debug'                   => array( 'type' => 'bool', 'default' => 0, 'section' => 'advanced' ),

			/* ---- Legacy credentials (kept for upgrades + single-account flows) ---- */
			'cpic_fb_app_id'               => array( 'type' => 'text', 'default' => '', 'section' => 'social' ),
			'cpic_fb_app_secret'           => array( 'type' => 'secret', 'default' => '', 'section' => 'social' ),
			'cpic_fb_page_id'              => array( 'type' => 'text', 'default' => '', 'section' => 'social' ),
			'cpic_fb_page_name'            => array( 'type' => 'text', 'default' => '', 'section' => 'social' ),
			'cpic_fb_page_token'           => array( 'type' => 'secret', 'default' => '', 'section' => 'social' ),
			'cpic_tg_bot_token'            => array( 'type' => 'secret', 'default' => '', 'section' => 'social' ),
			'cpic_tg_chat_id'              => array( 'type' => 'text', 'default' => '', 'section' => 'social' ),

			/* ---- API versions (centralised) ---- */
			'cpic_fb_graph_version'        => array( 'type' => 'key', 'default' => 'v23.0', 'section' => 'social' ),
			'cpic_tg_api_base'             => array( 'type' => 'url', 'default' => 'https://api.telegram.org', 'section' => 'social' ),
		);

		/**
		 * Filter the settings schema so add-ons can register fields.
		 *
		 * @param array<string,array<string,mixed>> $defs Definitions.
		 */
		$this->definitions = apply_filters( 'cpic/settings/schema', $defs );

		return $this->definitions;
	}

	/**
	 * Every option key the plugin owns (schema-driven, for uninstall/export).
	 *
	 * @return array<int,string>
	 */
	public static function option_keys() {
		// The schema has no constructor dependencies, so a throwaway instance is
		// safe here (uninstall.php calls this with no container available).
		$instance = ( new \ReflectionClass( __CLASS__ ) )->newInstanceWithoutConstructor();
		$keys     = array_keys( $instance->definitions() );

		/**
		 * Filter the option keys treated as plugin-owned.
		 *
		 * @param array<int,string> $keys Keys.
		 */
		return array_values( array_unique( (array) apply_filters( 'cpic/settings/option_keys', $keys ) ) );
	}

	/**
	 * Section labels.
	 *
	 * @return array<string,string>
	 */
	public function sections() {
		return array(
			'general'    => __( 'General', 'custom-post-image-creator' ),
			'rendering'  => __( 'Rendering', 'custom-post-image-creator' ),
			'publishing' => __( 'Publishing', 'custom-post-image-creator' ),
			'automation' => __( 'Automation', 'custom-post-image-creator' ),
			'ai'         => __( 'AI', 'custom-post-image-creator' ),
			'storage'    => __( 'Storage', 'custom-post-image-creator' ),
			'social'     => __( 'Social accounts', 'custom-post-image-creator' ),
			'advanced'   => __( 'Advanced', 'custom-post-image-creator' ),
		);
	}

	/**
	 * Get one setting with sanitisation on read.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Optional override for the default.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		if ( array_key_exists( $key, $this->cache ) ) {
			return $this->cache[ $key ];
		}

		$defs  = $this->definitions();
		$fallback = null !== $default
			? $default
			: ( isset( $defs[ $key ]['default'] ) ? $defs[ $key ]['default'] : '' );

		$value = get_option( $key, $fallback );

		if ( isset( $defs[ $key ] ) ) {
			$value = $this->sanitize( $defs[ $key ]['type'], $value );
		}

		$this->cache[ $key ] = $value;

		return $value;
	}

	/**
	 * Boolean helper.
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	public function is_enabled( $key ) {
		return (bool) $this->get( $key );
	}

	/**
	 * Get an integer setting.
	 *
	 * @param string $key     Option key.
	 * @param int    $default Fallback.
	 * @return int
	 */
	public function int( $key, $default = 0 ) {
		$value = $this->get( $key, $default );
		return is_numeric( $value ) ? (int) $value : (int) $default;
	}

	/**
	 * Get all settings (option keys only) as a flat array, secrets redacted.
	 *
	 * @param bool $redact Secrets redacted.
	 * @return array<string,mixed>
	 */
	public function all( $redact = true ) {
		$out = array();
		foreach ( $this->definitions() as $key => $def ) {
			$value = $this->get( $key );
			if ( $redact && isset( $def['type'] ) && 'secret' === $def['type'] ) {
				$value = '' === (string) $value ? '' : str_repeat( '•', 8 );
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Update a single setting after validation.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Raw value.
	 * @return bool
	 */
	public function set( $key, $value ) {
		$defs = $this->definitions();
		if ( ! isset( $defs[ $key ] ) ) {
			return false;
		}

		$clean = $this->sanitize( $defs[ $key ]['type'], $value );

		if ( 'secret' === $defs[ $key ]['type'] && '' === $clean && '' !== (string) $value ) {
			// Encryption unavailable: keep the previously stored secret.
			return false;
		}

		update_option( $key, $clean );
		$this->cache[ $key ] = $clean;

		return true;
	}

	/**
	 * Bulk update. Unknown keys are ignored.
	 *
	 * @param array<string,mixed> $values Key => raw value.
	 * @return array{updated:int,skipped:string[],errors:array<string,string>}
	 */
	public function bulk_set( array $values ) {
		$defs    = $this->definitions();
		$updated = 0;
		$skipped = array();
		$errors  = array();

		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $defs[ $key ] ) ) {
				$skipped[] = $key;
				continue;
			}

			/*
			 * A number field that cannot be read must be reported, not
			 * silently coerced. Saving 0 for "abc" turned Batch Size and Time
			 * Budget off without telling anyone, and a 0 batch size stops the
			 * queue entirely.
			 */
			if ( in_array( $defs[ $key ]['type'], array( 'int', 'float' ), true ) && ! is_numeric( $value ) ) {
				$errors[ $key ] = __( 'That value must be a number.', 'custom-post-image-creator' );
				continue;
			}

			$clean = $this->sanitize( $defs[ $key ]['type'], $value );

			if ( 'secret' === $defs[ $key ]['type'] && '' === $clean && '' !== (string) $value ) {
				$errors[ $key ] = __( 'Secret could not be stored securely.', 'custom-post-image-creator' );
				continue;
			}

			update_option( $key, $clean );
			$this->cache[ $key ] = $clean;
			$updated++;
		}

		return array(
			'updated' => $updated,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}

	/**
	 * Sanitise by declared type.
	 *
	 * @param string $type  Type.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	public function sanitize( $type, $value ) {
		switch ( $type ) {
			case 'bool':
				if ( is_string( $value ) ) {
					$value = in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true ) ? 1 : 0;
				}
				return $value ? 1 : 0;

			case 'int':
				return is_numeric( $value ) ? (int) $value : 0;

			case 'float':
				return is_numeric( $value ) ? (float) $value : 0.0;

			case 'color':
				$color = sanitize_hex_color( (string) $value );
				return $color ? $color : '';

			case 'url':
				return esc_url_raw( (string) $value );

			case 'email':
				$email = sanitize_email( (string) $value );
				return is_email( $email ) ? $email : '';

			case 'key':
				return preg_replace( '/[^A-Za-z0-9_\-\/\.:]/', '', (string) $value );

			case 'csv':
				if ( is_array( $value ) ) {
					$items = $value;
				} else {
					$items = explode( ',', (string) $value );
				}
				$items = array_filter( array_map( 'trim', array_map( 'sanitize_text_field', $items ) ) );
				return implode( ',', $items );

			case 'url_list':
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_values( array_filter( array_map( 'esc_url_raw', $value ) ) );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'secret':
				return self::protect_secret( (string) $value );

			case 'json':
				$decoded = is_array( $value ) ? $value : json_decode( (string) $value, true );
				return is_array( $decoded ) ? $decoded : array();

			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Encrypt-at-rest helper for secrets. Secrets are encrypted with the
	 * site's AUTH_KEY material and stored in options; they are never
	 * exposed to JavaScript.
	 *
	 * @param string $value Plain value.
	 * @return string
	 */
	public static function protect_secret( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		// Never persist a secret in plain text: with no OpenSSL in the PHP
		// build `encrypt()` fails closed and the caller keeps the old value.
		return (string) \CPIC\Security\Encryption::encrypt( $value );
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function reveal_secret( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		return \CPIC\Security\Encryption::decrypt( $stored );
	}

	/**
	 * Write defaults for keys that have no stored value.
	 *
	 * @return int Number of options written.
	 */
	public function ensure_defaults() {
		$written = 0;
		foreach ( $this->definitions() as $key => $def ) {
			$sentinel = '__cpic_absent__';
			$current  = get_option( $key, $sentinel );
			if ( $sentinel === $current ) {
				update_option( $key, $def['default'], false );
				$written++;
			}
		}
		$this->cache = array();
		return $written;
	}

	/**
	 * Export configuration for the "export settings" tool (secrets excluded).
	 *
	 * @return array<string,mixed>
	 */
	public function export() {
		$out = array();
		foreach ( $this->definitions() as $key => $def ) {
			if ( 'secret' === $def['type'] ) {
				continue;
			}
			$out[ $key ] = $this->get( $key );
		}

		return array(
			'version'  => CPIC_VERSION,
			'settings' => $out,
		);
	}

	/**
	 * Clear the in-request cache.
	 *
	 * @return void
	 */
	public function flush_cache() {
		$this->cache = array();
	}
}
