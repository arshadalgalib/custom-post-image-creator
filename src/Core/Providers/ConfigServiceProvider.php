<?php
/**
 * Container configuration provider.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Core\Providers;

use CPIC\Core\Container;
use CPIC\Core\ServiceProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers every service factory exactly once, up front. Nothing is built
 * here; factories stay lazy so a front-end request never instantiates the
 * design studio, the queue or the publishers.
 */
final class ConfigServiceProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'config';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	public function register( Container $c ) {
		$c->instance( 'container', $c );

		/* ─── Core ─────────────────────────────────────────── */
		$c->factory( 'events', static function () {
			return new \CPIC\Core\EventDispatcher();
		} );
		$c->factory( 'settings', static function () {
			return new \CPIC\Security\Settings();
		} );
		$c->factory( 'logger', static function ( Container $c ) {
			return new \CPIC\Support\Logger( $c->get( 'settings' ), $c->get( 'events' ) );
		} );
		$c->factory( 'capabilities', static function () {
			return new \CPIC\Security\Capabilities();
		} );
		$c->factory( 'encryption', static function () {
			return new \CPIC\Security\Encryption();
		} );
		$c->factory( 'nonce', static function () {
			return new \CPIC\Security\Nonce();
		} );
		$c->factory( 'rate_limiter', static function ( Container $c ) {
			return new \CPIC\Security\RateLimiter( $c->get( 'settings' ) );
		} );
		$c->factory( 'migrator', static function ( Container $c ) {
			return new \CPIC\Database\Migrator( $c );
		} );
		$c->factory( 'db', static function () {
			return new \CPIC\Database\Schema();
		} );

		/* ─── Repositories ─────────────────────────────────── */
		$c->factory( 'templates', static function ( Container $c ) {
			return new \CPIC\Database\Repositories\TemplateRepository( $c->get( 'db' ) );
		} );
		$c->factory( 'brands', static function ( Container $c ) {
			return new \CPIC\Database\Repositories\BrandRepository( $c->get( 'db' ) );
		} );
		$c->factory( 'assets', static function ( Container $c ) {
			return new \CPIC\Database\Repositories\AssetRepository( $c->get( 'db' ) );
		} );
		$c->factory( 'artifacts', static function ( Container $c ) {
			return new \CPIC\Database\Repositories\ArtifactRepository( $c->get( 'db' ) );
		} );
		$c->factory( 'destinations', static function ( Container $c ) {
			return new \CPIC\Database\Repositories\DestinationRepository( $c->get( 'db' ) );
		} );
		$c->factory( 'campaigns', static function ( Container $c ) {
			return new \CPIC\Database\Repositories\CampaignRepository( $c->get( 'db' ) );
		} );
		$c->factory( 'deliveries', static function ( Container $c ) {
			return new \CPIC\Database\Repositories\DeliveryRepository( $c->get( 'db' ) );
		} );
		$c->factory( 'jobs_repo', static function ( Container $c ) {
			return new \CPIC\Database\Repositories\JobRepository( $c->get( 'db' ) );
		} );
		$c->factory( 'mappings', static function ( Container $c ) {
			return new \CPIC\Database\Repositories\CategoryRepository( $c->get( 'db' ) );
		} );
		$c->factory( 'audit', static function ( Container $c ) {
			return new \CPIC\Database\Repositories\AuditRepository( $c->get( 'db' ) );
		} );
		$c->factory( 'analytics_repo', static function ( Container $c ) {
			return new \CPIC\Database\Repositories\AnalyticsRepository( $c->get( 'db' ) );
		} );

		/* ─── Support ──────────────────────────────────────── */
		$c->factory( 'dynamic_content', static function ( Container $c ) {
			return new \CPIC\Support\DynamicContent( $c );
		} );
		$c->factory( 'dates', static function () {
			return new \CPIC\Support\Dates();
		} );
		$c->factory( 'legacy', static function ( Container $c ) {
			return new \CPIC\Support\LegacyBridge( $c );
		} );

		/* ─── Rendering ────────────────────────────────────── */
		$c->factory( 'fonts', static function ( Container $c ) {
			return new \CPIC\Rendering\FontManager( $c->get( 'settings' ) );
		} );
		$c->factory( 'render_plan', static function ( Container $c ) {
			return new \CPIC\Rendering\RenderPlanBuilder( $c );
		} );
		$c->factory( 'svg_serializer', static function ( Container $c ) {
			return new \CPIC\Rendering\SvgPlanSerializer( $c );
		} );
		$c->factory( 'renderers', static function ( Container $c ) {
			return new \CPIC\Rendering\RendererRegistry( $c );
		} );
		$c->factory( 'render_signature', static function ( Container $c ) {
			return new \CPIC\Rendering\RenderSignature( $c );
		} );
		$c->factory( 'artifact_manager', static function ( Container $c ) {
			return new \CPIC\Rendering\ArtifactManager( $c );
		} );
		$c->factory( 'render', static function ( Container $c ) {
			return new \CPIC\Rendering\RenderService( $c );
		} );

		/* ─── Templates ────────────────────────────────────── */
		$c->factory( 'template_schema', static function () {
			return new \CPIC\Templates\TemplateSchema();
		} );
		$c->factory( 'template_manager', static function ( Container $c ) {
			return new \CPIC\Templates\TemplateManager( $c );
		} );
		$c->factory( 'template_importer', static function ( Container $c ) {
			return new \CPIC\Templates\TemplateImporter( $c );
		} );
		$c->factory( 'starter_library', static function ( Container $c ) {
			return new \CPIC\Templates\StarterLibrary( $c );
		} );

		/* ─── Publishing ───────────────────────────────────── */
		$c->factory( 'accounts', static function ( Container $c ) {
			return new \CPIC\Publishing\AccountManager( $c );
		} );
		$c->factory( 'facebook_oauth', static function ( Container $c ) {
			return new \CPIC\Publishing\FacebookOAuth( $c );
		} );
		$c->factory( 'captions', static function ( Container $c ) {
			return new \CPIC\Publishing\CaptionBuilder( $c );
		} );
		$c->factory( 'publishers', static function ( Container $c ) {
			return new \CPIC\Publishing\PublisherRegistry( $c );
		} );
		$c->factory( 'delivery_manager', static function ( Container $c ) {
			return new \CPIC\Publishing\DeliveryManager( $c );
		} );

		/* ─── Automation ───────────────────────────────────── */
		$c->factory( 'retry', static function ( Container $c ) {
			return new \CPIC\Automation\RetryManager( $c->get( 'settings' ) );
		} );
		$c->factory( 'scheduler', static function ( Container $c ) {
			return new \CPIC\Automation\Scheduler( $c );
		} );
		$c->factory( 'queue', static function ( Container $c ) {
			return new \CPIC\Automation\QueueManager( $c );
		} );
		$c->factory( 'job_runner', static function ( Container $c ) {
			return new \CPIC\Automation\JobRunner( $c );
		} );
		$c->factory( 'health', static function ( Container $c ) {
			return new \CPIC\Automation\HealthMonitor( $c );
		} );

		/* ─── Campaigns / calendar / analytics ─────────────── */
		$c->factory( 'campaign_manager', static function ( Container $c ) {
			return new \CPIC\Campaigns\CampaignManager( $c );
		} );
		$c->factory( 'calendar', static function ( Container $c ) {
			return new \CPIC\Campaigns\CalendarService( $c );
		} );
		$c->factory( 'analytics', static function ( Container $c ) {
			return new \CPIC\Analytics\AnalyticsService( $c );
		} );

		/* ─── AI ───────────────────────────────────────────── */
		$c->factory( 'ai', static function ( Container $c ) {
			return new \CPIC\AI\AiService( $c );
		} );

		/* ─── Media ────────────────────────────────────────── */
		$c->factory( 'media', static function ( Container $c ) {
			return new \CPIC\Media\MediaLibrary( $c );
		} );

		/* ─── Admin / REST / editor ────────────────────────── */
		$c->factory( 'admin', static function ( Container $c ) {
			return new \CPIC\Admin\Admin( $c );
		} );
		$c->factory( 'editor', static function ( Container $c ) {
			return new \CPIC\Admin\EditorIntegration( $c );
		} );
		$c->factory( 'rest', static function ( Container $c ) {
			return new \CPIC\REST\RestApi( $c );
		} );

		/**
		 * Fires after core services are registered, so add-ons can bind
		 * their own services into the container.
		 *
		 * @param Container $c Container.
		 */
		do_action( 'cpic/container/register', $c );
	}
}
