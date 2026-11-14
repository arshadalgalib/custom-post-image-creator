<?php
/**
 * AI assistance framework.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\AI;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional headline/subtitle suggestion.
 *
 * Design rules:
 *  - Off by default. Nothing is sent anywhere until an administrator enables
 *    it and provides a key.
 *  - Every request is explicit: the user asks for suggestions for a specific
 *    post; there is no background scraping of site content.
 *  - Suggestions are returned as *suggestions* and never auto-applied to a
 *    template — a human reviews and picks.
 *  - Provider errors are surfaced, not silently swallowed.
 */
final class AiService {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Supported operations and their instructions.
	 */
	const OPERATIONS = array(
		'headlines' => 'Write short, factual headline variations for a social post image. Do not invent facts.',
		'subtitle'  => 'Write a one-line subtitle that clarifies the headline without adding new claims.',
		'hashtags'  => 'Suggest relevant hashtags based on the post content only.',
		'alt_text'  => 'Write concise alt text describing the image for accessibility.',
		'shorten'   => 'Shorten the given headline to fit a compact image layout, keeping its meaning.',
	);

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Available providers.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function providers() {
		$providers = array(
			'openai' => array(
				'label'   => __( 'OpenAI-compatible API', 'custom-post-image-creator' ),
				'base'    => 'https://api.openai.com/v1',
				'default_model' => 'gpt-4o-mini',
			),
			'custom' => array(
				'label'   => __( 'Custom endpoint', 'custom-post-image-creator' ),
				'base'    => '',
				'default_model' => '',
			),
		);

		/**
		 * Filter AI providers.
		 *
		 * @param array<string,array<string,mixed>> $providers Providers.
		 */
		return (array) apply_filters( 'cpic/ai/providers', $providers );
	}

	/**
	 * Whether the service is enabled and configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		$settings = $this->container->get( 'settings' );

		if ( ! $settings->is_enabled( 'cpic_ai_enabled' ) ) {
			return false;
		}

		$provider = (string) $settings->get( 'cpic_ai_provider', '' );

		if ( ! isset( $this->providers()[ $provider ] ) ) {
			return false;
		}

		return '' !== \CPIC\Security\Settings::reveal_secret( (string) get_option( 'cpic_ai_api_key', '' ) );
	}

	/**
	 * Prompt text for an operation (available even when AI is off, so the UI
	 * can show what would be sent).
	 *
	 * @param string              $operation Operation.
	 * @param array<string,mixed> $context   { post_id, title, excerpt }.
	 * @return string
	 */
	public function prompt_for( $operation, array $context = array() ) {
		$operation = sanitize_key( (string) $operation );

		$instruction = isset( self::OPERATIONS[ $operation ] ) ? self::OPERATIONS[ $operation ] : '';

		$post_id = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		$title   = isset( $context['title'] ) ? (string) $context['title'] : ( $post ? (string) $post->post_title : '' );
		$excerpt = isset( $context['excerpt'] ) ? (string) $context['excerpt'] : ( $post ? wp_strip_all_tags( (string) $post->post_excerpt ) : '' );

		$lines = array(
			$instruction,
			'',
			__( 'Reply with a numbered list, one suggestion per line, no commentary.', 'custom-post-image-creator' ),
			'',
			__( 'Post title:', 'custom-post-image-creator' ) . ' ' . $title,
		);

		if ( '' !== $excerpt ) {
			$lines[] = __( 'Summary:', 'custom-post-image-creator' ) . ' ' . $excerpt;
		}

		return trim( implode( "\n", array_filter( $lines ) ) );
	}

	/**
	 * Request suggestions.
	 *
	 * @param string              $operation Operation key.
	 * @param array<string,mixed> $context   Context (`post_id`, `count`).
	 * @return array<string,mixed>|\WP_Error
	 */
	public function suggest( $operation, array $context = array() ) {
		$operation = sanitize_key( (string) $operation );

		if ( ! isset( self::OPERATIONS[ $operation ] ) ) {
			return new \WP_Error( 'cpic_ai_operation', __( 'Unknown AI operation.', 'custom-post-image-creator' ), array( 'status' => 400 ) );
		}

		if ( ! $this->container->get( 'settings' )->is_enabled( 'cpic_ai_enabled' ) ) {
			return new \WP_Error(
				'cpic_ai_disabled',
				__( 'AI assistance is turned off. Enable it in Settings → AI if you want suggestions.', 'custom-post-image-creator' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->is_configured() ) {
			return new \WP_Error(
				'cpic_ai_unconfigured',
				__( 'Add an API key and choose a provider before using AI suggestions.', 'custom-post-image-creator' ),
				array( 'status' => 409 )
			);
		}

		if ( ! $this->container->get( 'capabilities' )->can( 'cpic_use_ai' ) ) {
			return new \WP_Error( 'cpic_ai_forbidden', __( 'You are not allowed to use AI assistance.', 'custom-post-image-creator' ), array( 'status' => 403 ) );
		}

		$monthly = (int) $this->container->get( 'settings' )->int( 'cpic_ai_monthly_limit', 0 );

		if ( $monthly > 0 && $this->usage_this_month() >= $monthly ) {
			return new \WP_Error(
				'cpic_ai_limit',
				__( 'The monthly AI request limit has been reached.', 'custom-post-image-creator' ),
				array( 'status' => 429 )
			);
		}

		$settings = $this->container->get( 'settings' );
		$provider = (string) $settings->get( 'cpic_ai_provider', '' );
		$model    = (string) $settings->get( 'cpic_ai_model', '' );

		if ( '' === $model ) {
			$model = isset( $this->providers()[ $provider ]['default_model'] ) ? (string) $this->providers()[ $provider ]['default_model'] : '';
		}

		$base = (string) $settings->get( 'cpic_ai_base_url', '' );
		if ( '' === $base && isset( $this->providers()[ $provider ]['base'] ) ) {
			$base = (string) $this->providers()[ $provider ]['base'];
		}

		if ( '' === $base ) {
			return new \WP_Error( 'cpic_ai_unconfigured', __( 'No API endpoint is configured for AI suggestions.', 'custom-post-image-creator' ), array( 'status' => 409 ) );
		}

		$prompt = $this->prompt_for( $operation, $context );

		$response = wp_remote_post(
			rtrim( $base, '/' ) . '/chat/completions',
			array(
				'timeout' => max( 5, (int) $settings->int( 'cpic_ai_timeout', 30 ) ),
				'headers' => array(
					'Authorization' => 'Bearer ' . \CPIC\Security\Settings::reveal_secret( (string) get_option( 'cpic_ai_api_key', '' ) ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'messages'    => array(
							array(
								'role'    => 'system',
								'content' => 'You help a Bengali newsroom write social image headlines. Be concise and factual. Never invent facts.',
							),
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
						'temperature' => 0.5,
						'max_tokens'  => 400,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->record_usage( $provider, $model, $operation, 0, 0, 'error', $response->get_error_message() );

			return new \WP_Error( 'cpic_ai_network', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			$message = is_array( $body ) && isset( $body['error']['message'] )
				? (string) $body['error']['message']
				: sprintf(
					/* translators: %d: HTTP status */
					__( 'The AI provider returned HTTP %d.', 'custom-post-image-creator' ),
					$code
				);

			$this->record_usage( $provider, $model, $operation, 0, 0, 'error', $message );

			return new \WP_Error( 'cpic_ai_http', $message, array( 'status' => 502, 'http' => $code ) );
		}

		$text = isset( $body['choices'][0]['message']['content'] ) ? (string) $body['choices'][0]['message']['content'] : '';

		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'cpic_ai_empty', __( 'The AI provider returned no text.', 'custom-post-image-creator' ), array( 'status' => 502 ) );
		}

		$tokens_in  = isset( $body['usage']['prompt_tokens'] ) ? (int) $body['usage']['prompt_tokens'] : 0;
		$tokens_out = isset( $body['usage']['completion_tokens'] ) ? (int) $body['usage']['completion_tokens'] : 0;

		$this->record_usage( $provider, $model, $operation, $tokens_in, $tokens_out, 'ok', '' );

		return array(
			'operation'      => $operation,
			'suggestions'    => $this->parse_suggestions( $text ),
			'raw'            => $text,
			// Always true: suggestions never write themselves into a design.
			'requires_review' => true,
			'usage'          => array(
				'tokens_in'  => $tokens_in,
				'tokens_out' => $tokens_out,
			),
		);
	}

	/**
	 * Split model output into clean suggestions.
	 *
	 * @param string $text Raw text.
	 * @return array<int,string>
	 */
	public function parse_suggestions( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );

		$out = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );

			// Strip "1.", "1)", "-", "*" prefixes.
			$line = preg_replace( '/^\s*(?:\d+[\.\)]|[-*•])\s*/u', '', $line );
			$line = trim( $line, " \t\"'" );

			if ( '' === $line || mb_strlen( $line, 'UTF-8' ) < 2 ) {
				continue;
			}

			if ( ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}

			if ( count( $out ) >= 8 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Record a request in the usage table (never the prompt or the key).
	 *
	 * @param string $provider   Provider.
	 * @param string $model      Model.
	 * @param string $operation  Operation.
	 * @param int    $tokens_in  Input tokens.
	 * @param int    $tokens_out Output tokens.
	 * @param string $status     ok|error.
	 * @param string $error      Error message.
	 * @return void
	 */
	private function record_usage( $provider, $model, $operation, $tokens_in, $tokens_out, $status, $error ) {
		global $wpdb;

		$table = \CPIC\Database\Schema::table( 'ai_usage' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'provider'    => substr( (string) $provider, 0, 64 ),
				'model'       => substr( (string) $model, 0, 120 ),
				'operation'   => substr( (string) $operation, 0, 64 ),
				'tokens_in'   => max( 0, (int) $tokens_in ),
				'tokens_out'  => max( 0, (int) $tokens_out ),
				'cost'        => 0,
				'post_id'     => 0,
				'status'      => (string) $status,
				'error'       => '' === $error ? null : substr( (string) $error, 0, 500 ),
				'created_by'  => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Requests made this calendar month.
	 *
	 * @return int
	 */
	public function usage_this_month() {
		global $wpdb;

		$table = \CPIC\Database\Schema::table( 'ai_usage' );
		$start = current_time( 'Y-m' ) . '-01 00:00:00';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $start )
		);
	}

	/**
	 * Usage summary for the diagnostics screen.
	 *
	 * @return array<string,mixed>
	 */
	public function usage() {
		global $wpdb;

		$table = \CPIC\Database\Schema::table( 'ai_usage' );
		$start = current_time( 'Y-m' ) . '-01 00:00:00';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS requests, COALESCE(SUM(tokens_in),0) AS tokens_in, COALESCE(SUM(tokens_out),0) AS tokens_out
				 FROM {$table} WHERE created_at >= %s",
				$start
			),
			ARRAY_A
		);

		return array(
			'enabled'     => $this->container->get( 'settings' )->is_enabled( 'cpic_ai_enabled' ),
			'configured'  => $this->is_configured(),
			'month'       => current_time( 'Y-m' ),
			'requests'    => $row ? (int) $row['requests'] : 0,
			'tokens_in'   => $row ? (int) $row['tokens_in'] : 0,
			'tokens_out'  => $row ? (int) $row['tokens_out'] : 0,
			'limit'       => (int) $this->container->get( 'settings' )->int( 'cpic_ai_monthly_limit', 0 ),
		);
	}
}
