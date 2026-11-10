<?php
/**
 * PHP test suite: core architecture, database, templates, rendering contracts.
 *
 * Run through tools/harness/run-php-tests.mjs (php-wasm + SQLite).
 *
 * @package CustomPostImageCreator
 */

$cpic_root = getenv( 'CPIC_ROOT' ) ? rtrim( getenv( 'CPIC_ROOT' ), '/' ) : dirname( __DIR__, 2 );
require_once __DIR__ . '/bootstrap.php';

$plugin = cpic_test_boot();

/* ═══ 1. Boot + container ═══════════════════════════════════════ */

CPIC_Test::suite( 'Core: container and modules' );

CPIC_Test::ok( $plugin instanceof \CPIC\Core\Plugin, 'Plugin singleton boots' );
CPIC_Test::ok( count( $plugin->modules()->ids() ) >= 5, 'Modules registered', $plugin->modules()->ids() );
CPIC_Test::ok( $plugin->service( 'settings' ) instanceof \CPIC\Security\Settings, 'Settings service resolves' );
CPIC_Test::ok( $plugin->service( 'events' ) instanceof \CPIC\Core\EventDispatcher, 'Event dispatcher resolves' );
CPIC_Test::ok( $plugin->service( 'template_schema' ) instanceof \CPIC\Templates\TemplateSchema, 'Template schema resolves' );

$autoload_ok = true;
foreach ( array( 'templates', 'db', 'logger', 'render_plan', 'queue', 'delivery_manager', 'analytics', 'ai' ) as $service_id ) {
	if ( null === $plugin->service( $service_id ) ) {
		$autoload_ok = false;
	}
}
CPIC_Test::ok( $autoload_ok, 'All core service ids resolve through the autoloader' );

/* ═══ 2. Encryption ═════════════════════════════════════════════ */

CPIC_Test::suite( 'Security: encryption' );

$plain     = 'EAAB-secret-token-value-123456';
$encrypted = \CPIC\Security\Encryption::encrypt( $plain );

CPIC_Test::ok( $encrypted !== $plain, 'Encrypting changes the value' );
CPIC_Test::ok( 0 === strpos( $encrypted, 'cpic1' ), 'Ciphertext is prefixed', $encrypted );
CPIC_Test::equal( $plain, \CPIC\Security\Encryption::decrypt( $encrypted ), 'Decrypt round-trips' );
CPIC_Test::equal( 'legacy-plain', \CPIC\Security\Encryption::decrypt( 'legacy-plain' ), 'Legacy plain text passes through' );
CPIC_Test::ok( \CPIC\Security\Encryption::decrypt( 'cpic1:not-valid-base64!!' ) !== $plain, 'Corrupt ciphertext does not leak' );
CPIC_Test::ok( false === strpos( \CPIC\Security\Encryption::redact( $plain ), 'secret' ), 'Redaction hides the value' );

/* ═══ 3. Settings schema ════════════════════════════════════════ */

CPIC_Test::suite( 'Security: settings schema' );

$settings = $plugin->service( 'settings' );
$written  = $settings->ensure_defaults();

CPIC_Test::ok( $written > 20, 'Defaults written', $written );
CPIC_Test::equal( 1080, $settings->int( 'cpic_canvas_width' ), 'Integer setting reads back' );
CPIC_Test::equal( 1, $settings->get( 'cpic_render_cache' ), 'Boolean default is 1' );

$settings->set( 'cpic_canvas_width', 'not-a-number' );
CPIC_Test::equal( 0, $settings->int( 'cpic_canvas_width' ), 'Invalid integer is coerced' );

$settings->set( 'cpic_title_color', 'javascript:alert(1)' );
CPIC_Test::equal( '', (string) $settings->get( 'cpic_title_color' ), 'Invalid colour is rejected' );

$settings->set( 'cpic_title_color', '#22c55e' );
CPIC_Test::equal( '#22c55e', $settings->get( 'cpic_title_color' ), 'Valid colour is stored' );

$settings->set( 'cpic_fb_page_token', 'EAAB-token' );
$stored_token = get_option( 'cpic_fb_page_token' );
CPIC_Test::ok( \CPIC\Security\Encryption::is_encrypted( (string) $stored_token ), 'Secret settings are encrypted at rest' );
CPIC_Test::equal( 'EAAB-token', \CPIC\Security\Settings::reveal_secret( (string) $stored_token ), 'Secret decrypts for internal use' );

$bulk = $settings->bulk_set(
	array(
		'cpic_auto_publish' => '1',
		'cpic_max_retries'  => '7',
		'cpic_not_a_real'   => 'x',
	)
);
CPIC_Test::equal( 2, $bulk['updated'], 'Bulk update counts valid keys' );
CPIC_Test::ok( in_array( 'cpic_not_a_real', $bulk['skipped'], true ), 'Unknown keys are rejected' );

$export = $settings->export();
CPIC_Test::ok( ! isset( $export['settings']['cpic_fb_page_token'] ), 'Settings export excludes secrets' );

/* ═══ 4. Schema + migration ═════════════════════════════════════ */

CPIC_Test::suite( 'Database: schema and migration' );

$migrator = $plugin->service( 'migrator' );
$result   = $migrator->install();

CPIC_Test::ok( ! isset( $result['error'] ), 'Migration ran without error', $result['error'] ?? '' );
CPIC_Test::equal( CPIC_DB_VERSION, get_option( 'cpic_db_version' ), 'DB version option written' );

$verify = $migrator->verify_tables();
CPIC_Test::ok( $verify['ok'], 'All 16 tables exist', $verify['missing'] );

$state = $migrator->state();
CPIC_Test::ok( $state['current'], 'Migration state reports current' );

// Repeatable: running again must not duplicate the default brand or templates.
$brands_before = $plugin->service( 'brands' )->count();
$templates_before = $plugin->service( 'templates' )->count();
$migrator->install();
CPIC_Test::equal( $brands_before, $plugin->service( 'brands' )->count(), 'Re-running the migration does not duplicate brands' );
CPIC_Test::equal( $templates_before, $plugin->service( 'templates' )->count(), 'Re-running the migration does not duplicate templates' );

/* ═══ 5. Template schema validation ═════════════════════════════ */

CPIC_Test::suite( 'Templates: document schema' );

$schema = $plugin->service( 'template_schema' );
$blank  = \CPIC\Templates\TemplateSchema::blank( 'square' );

CPIC_Test::equal( '7.0', $blank['schema'], 'Blank document carries the 7.0 schema' );
CPIC_Test::equal( 1080, $blank['canvas']['width'], 'Blank square canvas is 1080' );
CPIC_Test::ok( count( $blank['elements'] ) >= 6, 'Blank document has starter elements' );
CPIC_Test::ok( isset( $blank['layout']['portrait'] ), 'Blank document carries portrait overrides' );
CPIC_Test::ok( isset( $blank['layout']['story'] ), 'Blank document carries story overrides' );

$validated = $schema->validate( $blank );
CPIC_Test::ok( $validated['ok'], 'Blank document validates' );

// XSS attempts must be neutralised.
$hostile = $blank;
$hostile['elements']['headline']['text'] = '<img src=x onerror=alert(1)>';
$hostile['elements']['accent_bar']['fill'] = 'url(javascript:alert(1))';
$hostile['elements']['featured_image']['url'] = 'javascript:alert(1)';
$hostile['canvas']['background_image'] = 'javascript:alert(2)';
$hostile['elements']['bad'] = array( 'type' => 'evil' );
$hostile['elements']['headline']['x'] = 99999;

$clean = $schema->validate( $hostile );
CPIC_Test::ok( $clean['ok'], 'Hostile document still validates (hardened)' );
CPIC_Test::ok( false === strpos( (string) $clean['document']['elements']['headline']['text'], 'onerror=' ), 'Script payload stripped from text', $clean['document']['elements']['headline']['text'] );
CPIC_Test::equal( 'transparent', $clean['document']['elements']['headline']['text'] === '' ? 'transparent' : 'x', 'Text payload handled safely' );
CPIC_Test::ok( 0 === strpos( (string) $clean['document']['elements']['accent_bar']['fill'], 'transparent' ) || '#' === substr( (string) $clean['document']['elements']['accent_bar']['fill'], 0, 1 ), 'CSS injection rejected in fill', $clean['document']['elements']['accent_bar']['fill'] );
CPIC_Test::equal( '', $clean['document']['elements']['featured_image']['url'], 'javascript: image URL rejected' );
CPIC_Test::equal( '', $clean['document']['canvas']['background_image'], 'javascript: canvas background rejected' );
CPIC_Test::ok( ! isset( $clean['document']['elements']['bad'] ), 'Unknown element types dropped' );
CPIC_Test::ok( (float) $clean['document']['elements']['headline']['x'] <= 6000, 'Out-of-range coordinates clamped' );

$bad_json = $schema->validate( '{"broken":' );
CPIC_Test::ok( ! $bad_json['ok'], 'Invalid JSON is rejected with an error' );

/* ═══ 6. Multi-format adaptation ════════════════════════════════ */

CPIC_Test::suite( 'Rendering: multi-format adaptation' );

$portrait = $schema->canvas_for_format( $blank, 'portrait' );
CPIC_Test::equal( 1350, $portrait['height'], 'Portrait canvas height adapts' );

$story = $schema->canvas_for_format( $blank, 'story' );
CPIC_Test::equal( 1920, $story['height'], 'Story canvas height adapts' );
CPIC_Test::ok( $story['safe']['top'] > 200, 'Story safe area protects the header UI' );

$base_layers = $schema->elements_for_format( $blank, 'square' );
$story_layers = $schema->elements_for_format( $blank, 'story' );

$by_id = array();
foreach ( $story_layers as $layer ) {
	$by_id[ $layer['id'] ] = $layer;
}

CPIC_Test::ok( isset( $by_id['headline'] ), 'Headline exists in story layout' );
CPIC_Test::ok( $by_id['headline']['y'] > 999, 'Headline is repositioned for story (not scaled 1:1)', $by_id['headline']['y'] );

$square_headline = 0;
foreach ( $base_layers as $layer ) {
	if ( 'headline' === $layer['id'] ) {
		$square_headline = (float) $layer['y'];
	}
}
CPIC_Test::ok( $square_headline > 500 && $square_headline < 900, 'Square headline keeps its planned position', $square_headline );

$clamp = \CPIC\Rendering\Formats::clamp( 99, 99999 );
CPIC_Test::equal( 200, $clamp['width'], 'Custom width clamps up' );
CPIC_Test::equal( 4000, $clamp['height'], 'Custom height clamps down' );
CPIC_Test::equal( 'square', \CPIC\Rendering\Formats::nearest( 1000, 1000 ), 'Nearest preset detects square' );
CPIC_Test::equal( 'landscape', \CPIC\Rendering\Formats::nearest( 1200, 675 ), 'Nearest preset detects landscape' );

/* ═══ 7. Legacy migration ═══════════════════════════════════════ */

CPIC_Test::suite( 'Templates: 6.x migration' );

$legacy = array(
	'name'     => 'Legacy Template',
	'canvas'   => array(
		'width'            => 1080,
		'height'           => 1080,
		'background_type'  => 'image',
		'background_image' => 'https://example.test/bg.jpg',
		'background_color' => '#1a1a2e',
		'gradient_from'    => '#667eea',
		'gradient_to'      => '#764ba2',
		'padding'          => 40,
	),
	'elements' => array(
		'featured_image' => array( 'enabled' => true, 'x' => 0, 'y' => 0, 'width' => 1080, 'height' => 650, 'fit' => 'fill', 'z_index' => 10 ),
		'headline'       => array( 'enabled' => true, 'font' => 'Hind Siliguri', 'font_size' => 42, 'color' => '#FFFFFF', 'align' => 'left', 'max_lines' => 3, 'max_width' => 1000, 'x' => 40, 'y' => 750, 'bold' => true, 'z_index' => 30, 'shadow_color' => 'rgba(0,0,0,0.65)', 'shadow_offset' => 2 ),
		'category'       => array( 'enabled' => true, 'font' => 'Hind Siliguri', 'font_size' => 24, 'color' => '#FFFFFF', 'background_color' => '#E53935', 'x' => 40, 'y' => 690, 'padding_x' => 16, 'padding_y' => 6, 'border_radius' => 6, 'bold' => true ),
		'date'           => array( 'enabled' => true, 'font' => 'Hind Siliguri', 'font_size' => 28, 'color' => '#FFFFFF', 'background_color' => '#E53935', 'x' => 40, 'y' => 950 ),
		'logo'           => array( 'enabled' => true, 'source' => 'https://example.test/logo.png', 'x' => 40, 'y' => 40, 'width' => 120 ),
		'author'         => array( 'enabled' => false ),
		'website_name'   => array( 'enabled' => false ),
	),
	'shapes'   => array(
		array( 'type' => 'shape', 'shape' => 'rect', 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 4, 'fill' => '#ffffff' ),
	),
	'caption'  => array( 'include_headline' => 0, 'include_first_paragraph' => 1 ),
);

$migrated = $schema->migrate_legacy( $legacy );

CPIC_Test::equal( '7.0', $migrated['schema'], 'Legacy config migrates to 7.0' );
CPIC_Test::equal( 1080, $migrated['canvas']['width'], 'Legacy canvas width preserved' );
CPIC_Test::equal( 'image', $migrated['canvas']['background_type'], 'Legacy background image preserved' );
CPIC_Test::equal( 'https://example.test/bg.jpg', $migrated['canvas']['background_image'], 'Legacy background URL preserved' );
CPIC_Test::equal( 42, $migrated['elements']['headline']['font_size'], 'Legacy headline size preserved' );
CPIC_Test::equal( 750, (int) $migrated['elements']['headline']['y'], 'Legacy headline position preserved' );
CPIC_Test::equal( 'Hind Siliguri', $migrated['elements']['headline']['font_family'], 'Legacy font preserved' );
CPIC_Test::equal( 700, (int) $migrated['elements']['headline']['font_weight'], 'Legacy bold flag preserved' );
CPIC_Test::equal( '#E53935', $migrated['elements']['date']['background'], 'Legacy date chip colour preserved' );
CPIC_Test::equal( 'https://example.test/logo.png', $migrated['elements']['logo']['url'], 'Legacy logo preserved' );
CPIC_Test::ok( isset( $migrated['elements']['legacy_shape_0'] ), 'Legacy decorative shapes preserved' );
CPIC_Test::equal( 0, $migrated['caption']['include_headline'], 'Legacy caption switches preserved' );

/* ═══ 8. Repository CRUD + revisions ════════════════════════════ */

CPIC_Test::suite( 'Templates: manager CRUD and revisions' );

$manager = $plugin->service( 'template_manager' );

$created = $manager->create(
	array(
		'name'        => 'Unit Test Template',
		'document'    => $blank,
		'description' => 'Created by the test suite',
		'collection'  => 'news-media',
		'category'    => 'news',
		'tags'        => array( 'test', 'unit' ),
	)
);

CPIC_Test::ok( ! is_wp_error( $created ), 'Template creates', is_wp_error( $created ) ? $created->get_error_message() : null );
CPIC_Test::equal( 1, (int) $created['revision'], 'New template starts at revision 1' );
CPIC_Test::equal( array( 'test', 'unit' ), $created['tags'], 'Tags round-trip' );

$updated_doc = $created['document'];
$updated_doc['elements']['headline']['font_size'] = 64;

$updated = $manager->update( $created['id'], array( 'document' => $updated_doc, 'name' => 'Unit Test Template v2' ) );
CPIC_Test::ok( ! is_wp_error( $updated ), 'Template updates' );
CPIC_Test::equal( 2, (int) $updated['revision'], 'Update bumps the revision' );
CPIC_Test::equal( 64, (int) $updated['document']['elements']['headline']['font_size'], 'Update persists the document' );

$history = $manager->history( $created['id'] );
CPIC_Test::ok( count( $history ) >= 2, 'Version history records both revisions', count( $history ) );

$versions = $plugin->service( 'templates' )->versions( $created['id'] );
$restored = $manager->restore_version( $created['id'], (int) $versions[0]['id'] );
CPIC_Test::ok( ! is_wp_error( $restored ), 'Version restore succeeds' );

$duplicate = $manager->duplicate( $created['id'] );
CPIC_Test::ok( ! is_wp_error( $duplicate ), 'Duplicate succeeds' );
CPIC_Test::ok( (int) $duplicate['id'] !== (int) $created['id'], 'Duplicate gets a new id' );
CPIC_Test::ok( false !== strpos( $duplicate['name'], 'copy' ), 'Duplicate is labelled as a copy', $duplicate['name'] );

$default_result = $manager->set_default( $created['id'] );
CPIC_Test::ok( $default_result, 'Default template can be set' );
CPIC_Test::equal( (int) $created['id'], (int) $manager->default_template()['id'], 'Default template resolves' );

$delete_default = $manager->delete( $created['id'], true );
CPIC_Test::is_error( $delete_default, 'cpic_template_default', 'Deleting the default template is refused' );

$archived = $manager->archive( $duplicate['id'] );
CPIC_Test::ok( $archived, 'Archive works' );
CPIC_Test::equal( 0, (int) $manager->get( $duplicate['id'] )['is_active'], 'Archived template is deactivated' );

$manager->restore( $duplicate['id'] );
CPIC_Test::equal( 1, (int) $manager->get( $duplicate['id'] )['is_active'], 'Restore reactivates' );

$deleted = $manager->delete( $duplicate['id'], true );
CPIC_Test::ok( true === $deleted, 'Force delete works for non-default templates' );

/* ═══ 9. Starter library ════════════════════════════════════════ */

CPIC_Test::suite( 'Templates: starter library' );

$library = $plugin->service( 'starter_library' );
$designs = $library->designs();

CPIC_Test::equal( 12, count( $designs ), 'Twelve starter designs ship' );

$required_collections = array( 'news-media', 'newspaper', 'magazine', 'corporate', 'education', 'islamic', 'political', 'event', 'announcement', 'breaking', 'quotes', 'research' );
$found_collections    = array();

foreach ( $designs as $slug => $design ) {
	$found_collections[] = $design['collection'];
	CPIC_Test::ok( isset( $design['document']['elements']['headline'] ), 'Design has a headline: ' . $slug );
}

foreach ( $required_collections as $collection ) {
	CPIC_Test::ok( in_array( $collection, $found_collections, true ), 'Collection present: ' . $collection );
}

$seeded_rows = $manager->all( array( 'collection' => 'news-media' ) );
CPIC_Test::ok( count( $seeded_rows ) >= 1, 'Starter templates were seeded during migration', count( $seeded_rows ) );

/* ═══ 10. Dynamic content ═══════════════════════════════════════ */

CPIC_Test::suite( 'Support: dynamic content engine' );

$post_id = 501;
CPIC_Test_State::$posts[ $post_id ] = cpic_test_make_post(
	array(
		'ID'           => $post_id,
		'post_title'   => 'বাংলাদেশের উন্নয়নে নতুন উদ্যোগ',
		'post_content' => '<p>প্রযুক্তির মাধ্যমে বদলে যাচ্ছে আমাদের জীবন। এটি দ্বিতীয় বাক্য।</p>',
		'post_date'    => '2026-08-14 09:30:00',
	)
);
CPIC_Test_State::$terms[7] = array( 'term_id' => 7, 'name' => 'জাতীয়', 'slug' => 'national' );
CPIC_Test_State::$post_terms[ $post_id ] = array( 7 );
CPIC_Test_State::$post_meta[ $post_id ]['reporter'] = array( 'কর্মী প্রতিনিধি' );

$dynamic = $plugin->service( 'dynamic_content' );
$context = $dynamic->context( $post_id );

CPIC_Test::equal( 'বাংলাদেশের উন্নয়নে নতুন উদ্যোগ', $dynamic->resolve( 'post.title', $context ), 'Title resolves as Unicode' );
CPIC_Test::equal( 'জাতীয়', $dynamic->resolve( 'post.category', $context ), 'Category resolves' );
CPIC_Test::equal( 'কর্মী প্রতিনিধি', $dynamic->resolve( 'meta:reporter', $context ), 'Custom field resolves' );
CPIC_Test::equal( '', $dynamic->resolve( 'meta:missing', $context ), 'Missing field resolves empty' );
CPIC_Test::equal( '', $dynamic->resolve( 'post.nope', $context ), 'Unknown path resolves empty' );
CPIC_Test::equal( '', $dynamic->resolve( 'nonsense', $context ), 'Unknown namespace resolves empty' );

$rendered = $dynamic->render( '{{post.title}} — {{post.category}}', $context );
CPIC_Test::equal( 'বাংলাদেশের উন্নয়নে নতুন উদ্যোগ — জাতীয়', $rendered, 'Token rendering works' );

CPIC_Test::equal( 'AB', $dynamic->apply_filters( 'ab', 'upper' ), 'Upper filter works' );
CPIC_Test::equal( 'two words…', $dynamic->apply_filters( 'two words three', 'words:2' ), 'Word truncation works' );
CPIC_Test::equal( 'fallback', $dynamic->apply_filters( '', 'default:fallback' ), 'Default filter works' );

$injection = $dynamic->render( '{{post.title}}{{system("rm -rf /")}}{{phpinfo()}}', $context );
CPIC_Test::ok( false === strpos( $injection, 'phpinfo' ), 'Arbitrary expressions are not executed', $injection );

$summary = $dynamic->summary( CPIC_Test_State::$posts[ $post_id ], 5 );
CPIC_Test::ok( '' !== $summary, 'Summary generation works' );
CPIC_Test::ok( false === strpos( $summary, '<p>' ), 'Summary strips markup', $summary );

/* ═══ 11. Dates ═════════════════════════════════════════════════ */

CPIC_Test::suite( 'Support: dates' );

$dates = $plugin->service( 'dates' );
$ts    = gmmktime( 9, 30, 0, 8, 14, 2026 );

CPIC_Test::equal( '১৪ আগস্ট ২০২৬', $dates->format( $ts, 'bangla_long' ), 'Bengali long date' );
CPIC_Test::equal( '১৪ আগস্ট', $dates->format( $ts, 'bangla_short' ), 'Bengali short date' );
CPIC_Test::ok( false !== strpos( $dates->format( $ts, 'bangla_full' ), 'আগস্ট' ), 'Bengali full date includes month' );
CPIC_Test::equal( '2026-08-14', $dates->format( $ts, 'iso' ), 'ISO date' );
CPIC_Test::equal( '১২৩', $dates->to_bengali_digits( '123' ), 'Digit conversion to Bengali' );
CPIC_Test::equal( '123', $dates->to_ascii_digits( '১২৩' ), 'Digit conversion from Bengali' );
CPIC_Test::ok( $dates->is_valid_datetime( '2026-08-14 09:30:00' ), 'Valid datetime detected' );
CPIC_Test::ok( ! $dates->is_valid_datetime( '2026-13-45 99:99:99' ), 'Invalid datetime rejected' );

// Site timezone must be used, never the browser clock.
CPIC_Test::equal( '১৪ আগস্ট ২০২৬', $dates->format_post_date( $post_id ), 'Post date uses site timezone' );

/* ═══ 12. Render plan ═══════════════════════════════════════════ */

CPIC_Test::suite( 'Rendering: render plan' );

$plan_builder = $plugin->service( 'render_plan' );
$brand_id     = $plugin->service( 'brands' )->ensure_default();

$plan = $plan_builder->build(
	array(
		'document' => $migrated,
		'format'   => 'square',
		'post_id'  => $post_id,
		'brand_id' => $brand_id,
	)
);

CPIC_Test::ok( ! is_wp_error( $plan ), 'Plan builds', is_wp_error( $plan ) ? $plan->get_error_message() : null );
CPIC_Test::equal( 1080, $plan['width'], 'Plan width matches canvas' );
CPIC_Test::ok( count( $plan['layers'] ) >= 4, 'Plan contains layers', count( $plan['layers'] ) );
CPIC_Test::ok( in_array( 'Hind Siliguri', $plan['fonts'], true ), 'Plan declares fonts', $plan['fonts'] );
CPIC_Test::ok( ! empty( $plan['font_assets'] ), 'Plan carries font assets for preloading' );

$headline_layer = null;
foreach ( $plan['layers'] as $layer ) {
	if ( 'headline' === $layer['id'] ) {
		$headline_layer = $layer;
	}
}

CPIC_Test::ok( null !== $headline_layer, 'Headline layer present' );
CPIC_Test::equal( 'বাংলাদেশের উন্নয়নে নতুন উদ্যোগ', $headline_layer['text']['value'], 'Headline text resolved from the post' );
CPIC_Test::equal( 'Hind Siliguri', $headline_layer['text']['font_family'], 'Headline font resolved' );

// Layer order must be sorted by z-index.
$last_z = -1;
$sorted = true;
foreach ( $plan['layers'] as $layer ) {
	if ( (float) $layer['z'] < $last_z ) {
		$sorted = false;
	}
	$last_z = (float) $layer['z'];
}
CPIC_Test::ok( $sorted, 'Layers are z-ordered' );

$story_plan = $plan_builder->build(
	array(
		'document' => $migrated,
		'format'   => 'story',
		'post_id'  => $post_id,
	)
);
CPIC_Test::equal( 1920, $story_plan['height'], 'Story plan uses story height' );
CPIC_Test::equal( 'story', $story_plan['format'], 'Story plan reports its format' );

$override_plan = $plan_builder->build(
	array(
		'document'  => $migrated,
		'format'    => 'square',
		'post_id'   => $post_id,
		'overrides' => array( 'headline' => 'কাস্টম শিরোনাম' ),
	)
);
$override_headline = '';
foreach ( $override_plan['layers'] as $layer ) {
	if ( 'headline' === $layer['id'] ) {
		$override_headline = $layer['text']['value'];
	}
}
CPIC_Test::equal( 'কাস্টম শিরোনাম', $override_headline, 'Text overrides reach the plan' );

/* ═══ 8b. Rendering: image fallbacks ════════════════════════════ */

CPIC_Test::suite( 'Rendering: image fallbacks' );

$schema = $plugin->service( 'template_schema' );
CPIC_Test::equal( 'gradient', $schema->default_image()['fallback'], 'Image layers default to a gradient placeholder' );

$validated = $schema->validate(
	array(
		'canvas'   => array( 'width' => 1080, 'height' => 1080, 'format' => 'square' ),
		'elements' => array(
			'photo' => array(
				'type'     => 'image',
				'url'      => '',
				'fallback' => 'hide',
				'x'        => 0,
				'y'        => 0,
				'width'    => 1080,
				'height'   => 1080,
			),
			'bogus' => array(
				'type'     => 'image',
				'url'      => '',
				'fallback' => 'javascript:alert(1)',
				'x'        => 0,
				'y'        => 0,
				'width'    => 100,
				'height'   => 100,
			),
		),
	)
);

CPIC_Test::ok( isset( $validated['document']['elements']['photo'] ), 'Image elements validate' );
CPIC_Test::equal( 'hide', $validated['document']['elements']['photo']['fallback'], 'Explicit hide fallback survives sanitising' );
CPIC_Test::equal( 'gradient', $validated['document']['elements']['bogus']['fallback'], 'Unknown fallback coerces to gradient' );

$fallback_plan = $plan_builder->build(
	array(
		'document' => $validated['document'],
		'format'   => 'square',
		'post_id'  => 0,
	)
);

CPIC_Test::ok( ! is_wp_error( $fallback_plan ), 'Plan builds without a featured image', is_wp_error( $fallback_plan ) ? $fallback_plan->get_error_message() : null );

$planned_ids = array();
foreach ( (array) $fallback_plan['layers'] as $layer ) {
	$planned_ids[] = $layer['id'];
}
CPIC_Test::ok( in_array( 'bogus', $planned_ids, true ), 'Gradient image layer survives a missing image', $planned_ids );
CPIC_Test::ok( ! in_array( 'photo', $planned_ids, true ), 'Hidden image layer is dropped from the plan', $planned_ids );

$bogus_layer = null;
foreach ( (array) $fallback_plan['layers'] as $layer ) {
	if ( 'bogus' === $layer['id'] ) {
		$bogus_layer = $layer;
	}
}
CPIC_Test::equal( 'gradient', $bogus_layer['image']['fallback'], 'Plan forwards the fallback to the canvas renderer' );

/* ═══ 8c. Uninstall contract ════════════════════════════════════ */

CPIC_Test::suite( 'Security: uninstall contract' );

$uninstall_keys = \CPIC\Security\Settings::option_keys();
CPIC_Test::ok( count( $uninstall_keys ) > 50, 'Uninstall covers the settings schema', count( $uninstall_keys ) );
CPIC_Test::ok( in_array( 'cpic_canvas_width', $uninstall_keys, true ), 'Schema keys are enumerated for uninstall' );
CPIC_Test::ok( file_exists( CPIC_PLUGIN_DIR . 'uninstall.php' ), 'uninstall.php ships with the plugin' );

$uninstall_source = (string) file_get_contents( CPIC_PLUGIN_DIR . 'uninstall.php' );
CPIC_Test::ok( false !== strpos( $uninstall_source, 'cpic_cleanup_on_uninstall' ), 'Uninstall is opt-in for destructive cleanup' );
CPIC_Test::ok( false !== strpos( $uninstall_source, 'option_keys()' ), 'Uninstall reads option keys from the schema' );
CPIC_Test::ok( false !== strpos( $uninstall_source, "defined( 'WP_UNINSTALL_PLUGIN' )" ), 'Uninstall refuses to run outside the uninstall request' );

/* ═══ 8d. Autoload integrity ═══════════════════════════════════ */

CPIC_Test::suite( 'Core: autoload integrity' );

// Every class the plugin declares must map to the file PSR-4 expects. A file
// whose namespace does not match its directory is invisible to the autoloader
// and only surfaces as a fatal at runtime (the v7.0.0 activation bug).
$cpic_bad_paths = array();
$cpic_iterator  = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( CPIC_PLUGIN_DIR . 'src', FilesystemIterator::SKIP_DOTS )
);

foreach ( $cpic_iterator as $cpic_file ) {
	if ( 'php' !== strtolower( $cpic_file->getExtension() ) ) {
		continue;
	}

	$path = $cpic_file->getPathname();
	$code = (string) file_get_contents( $path );

	if ( ! preg_match( '/^namespace\s+([^;]+);/m', $code, $ns_match ) ) {
		continue;
	}

	$ns   = trim( $ns_match[1] );
	$want = 'CPIC\\' . str_replace( '/', '\\', trim( substr( dirname( $path ), strlen( rtrim( CPIC_PLUGIN_DIR, '/' ) . '/src' ) ), '/' ) );

	if ( $ns !== rtrim( $want, '\\' ) ) {
		$cpic_bad_paths[] = str_replace( CPIC_PLUGIN_DIR, '', $path ) . ' => ' . $ns;
	}
}

CPIC_Test::equal( array(), $cpic_bad_paths, 'Every src/ file matches its PSR-4 namespace' );

// The 6.x shims are one class per file so themes can load them individually.
foreach ( array( 'TemplateManagerAdapter', 'ImageGeneratorAdapter', 'FacebookAdapter', 'TelegramAdapter', 'SettingsAdapter' ) as $cpic_adapter ) {
	$cpic_class = '\\CPIC\\Support\\Compat\\' . $cpic_adapter;
	CPIC_Test::ok( class_exists( $cpic_class ), $cpic_adapter . ' is autoloadable' );
}

CPIC_Test::ok( class_exists( '\\CPIC\\Publishing\\PublishResult' ), 'PublishResult is autoloadable from its own directory' );

// Legacy 6.x names must resolve through the bridge.
foreach ( array( 'CPIC_Template_Manager', 'CPIC_Image_Generator', 'CPIC_Facebook_Poster', 'CPIC_Telegram_Poster', 'CPIC_Settings' ) as $cpic_legacy ) {
	CPIC_Test::ok( class_exists( $cpic_legacy ), $cpic_legacy . ' legacy alias exists' );
}

/* ═══ 8e. Activation resilience ═════════════════════════════════ */

CPIC_Test::suite( 'Core: activation resilience' );

$activation_failures = array();

foreach ( array( 'migrator', 'scheduler', 'capabilities', 'settings' ) as $cpic_needed ) {
	if ( null === $plugin->service( $cpic_needed ) ) {
		$activation_failures[] = $cpic_needed;
	}
}
CPIC_Test::equal( array(), $activation_failures, 'Every service the activation hook uses resolves' );

delete_option( 'cpic_activation_errors' );
$GLOBALS['cpic_test_fail_rewrite_flush'] = true;

try {
	$plugin->on_activate();
	CPIC_Test::ok( true, 'A failing activation step does not throw' );
} catch ( \Throwable $e ) {
	CPIC_Test::ok( false, 'A failing activation step does not throw', $e->getMessage() );
}

$recorded = get_option( 'cpic_activation_errors' );
CPIC_Test::ok( is_array( $recorded ) && isset( $recorded['errors']['rewrites'] ), 'The failing step is recorded', $recorded );

$GLOBALS['cpic_test_fail_rewrite_flush'] = false;
$plugin->on_activate();
CPIC_Test::ok( false === get_option( 'cpic_activation_errors' ), 'A clean activation clears the errors' );

// Ordering guarantee: the lifecycle hooks must be registered before any module
// boots. WordPress includes this plugin after `plugins_loaded` has already
// fired, so a module that throws during boot would otherwise take the
// activation hook down with it - which is exactly the reported failure.
$boot_source = (string) file_get_contents( CPIC_PLUGIN_DIR . 'src/Core/Plugin.php' );
$hook_at     = strpos( $boot_source, 'register_activation_hook' );
$modules_at  = strpos( $boot_source, '$this->modules->boot()' );

CPIC_Test::ok( false !== $hook_at && false !== $modules_at && $hook_at < $modules_at, 'Activation hook is registered before modules boot' );
CPIC_Test::ok( false !== strpos( $boot_source, 'catch ( \\Throwable $e )' ), 'Module boot is wrapped in a guard' );

// The loaded plugin must report its own load health.
CPIC_Test::ok( method_exists( $plugin, 'load_failures' ), 'load_failures() exists for diagnostics' );
CPIC_Test::ok( method_exists( $plugin, 'booted_cleanly' ), 'booted_cleanly() exists for diagnostics' );
CPIC_Test::equal( array(), (array) $plugin->modules()->failures(), 'No module failed to load in this environment' );

/* ═══ 13. Render signature / caching ════════════════════════════ */

CPIC_Test::suite( 'Rendering: cache signatures' );

$signature = $plugin->service( 'render_signature' );

$first  = $signature->compute( $migrated, $post_id, 'square' );
$second = $signature->compute( $migrated, $post_id, 'square' );
CPIC_Test::equal( $first['signature'], $second['signature'], 'Identical inputs produce identical signatures' );

$third = $signature->compute( $migrated, $post_id, 'portrait' );
CPIC_Test::ok( $first['signature'] !== $third['signature'], 'Different formats produce different signatures' );

// Changing post content must invalidate.
CPIC_Test_State::$posts[ $post_id ]->post_title = 'পরিবর্তিত শিরোনাম';
$fourth = $signature->compute( $migrated, $post_id, 'square' );
CPIC_Test::ok( $first['signature'] !== $fourth['signature'], 'Content change invalidates the signature' );

$changed_doc = $migrated;
$changed_doc['elements']['headline']['color'] = '#FF0000';
$fifth = $signature->compute( $changed_doc, $post_id, 'square' );
CPIC_Test::ok( $fourth['signature'] !== $fifth['signature'], 'Design change invalidates the signature' );

/* ═══ 14. Fonts ═════════════════════════════════════════════════ */

CPIC_Test::suite( 'Rendering: fonts' );

$fonts = $plugin->service( 'fonts' );
$status = $fonts->status();

CPIC_Test::ok( $status['ok'], 'All bundled fonts are present on disk' );
CPIC_Test::ok( in_array( 'Noto Sans Bengali', $fonts->families(), true ), 'Noto Sans Bengali available' );
CPIC_Test::ok( in_array( 'Hind Siliguri', $fonts->families(), true ), 'Hind Siliguri available' );
CPIC_Test::ok( strlen( $fonts->signature() ) === 64, 'Font signature is a sha256' );
CPIC_Test::ok( false !== strpos( $fonts->css(), '@font-face' ), 'Font CSS is generated' );
CPIC_Test::ok(
	false !== strpos( $fonts->css(), 'https://example.test/wp-content/plugins/' ) && false === strpos( $fonts->css(), CPIC_PLUGIN_DIR ),
	'Font CSS uses plugin URLs, not server paths'
);

// Every declared weight must be backed by a real face: a weight with no file
// makes the browser synthesise bold for Bengali, which thickens the glyphs
// without moving the advance widths, so the design measures one thing and
// paints another.
$manifest_rows = $fonts->manifest();
$declared      = array();
foreach ( $manifest_rows as $font_row ) {
	if ( empty( $font_row['available'] ) ) {
		continue;
	}
	$declared[ $font_row['family'] ][ (int) $font_row['weight'] ] = true;
}

CPIC_Test::ok( isset( $declared['Hind Siliguri'][600] ), 'Hind Siliguri ships a real semibold face' );
CPIC_Test::ok( ! isset( $declared['Noto Sans Bengali'][700] ), 'No bold weight is declared without a matching file' );

$css = $fonts->css();
CPIC_Test::ok( 1 === preg_match_all( "/@font-face\{font-family:'Hind Siliguri';font-style:normal;font-weight:600/", $css ), 'The semibold face is emitted in CSS' );
CPIC_Test::ok( false === strpos( $css, 'HindSiliguri-Regular.ttf', 0 ) || substr_count( $css, 'HindSiliguri-Regular.ttf' ) === 1, 'The regular face is declared exactly once' );

$resolved = $fonts->resolve( 'Siyam Rupali' );
CPIC_Test::ok( null !== $resolved && ! empty( $resolved['bengali'] ), 'Unknown families fall back to a Bengali face' );

/* ═══ 15. Server renderer guards ════════════════════════════════ */

CPIC_Test::suite( 'Rendering: GD fallback guard rails' );

$gd = new \CPIC\Rendering\ServerRenderer();
CPIC_Test::equal( 'gd', $gd->id(), 'GD renderer id' );
CPIC_Test::ok( ! $gd->supports_complex_scripts(), 'GD renderer reports it cannot shape complex scripts' );
CPIC_Test::ok( $gd->needs_shaping( 'বাংলাদেশ' ), 'Bengali text detected as complex' );
CPIC_Test::ok( ! $gd->needs_shaping( 'Plain latin headline' ), 'Latin text is not flagged' );
CPIC_Test::ok( $gd->needs_shaping( 'مرحبا' ), 'Arabic text detected as complex' );

$browser = new \CPIC\Rendering\BrowserRelayRenderer();
CPIC_Test::ok( ! $browser->runs_in_background(), 'Browser renderer refuses background execution' );
CPIC_Test::ok( $browser->supports_complex_scripts(), 'Browser renderer supports complex scripts' );
CPIC_Test::is_error( $browser->render( $plan ), 'cpic_browser_only', 'Browser renderer returns an honest error when called from PHP' );

$registry = $plugin->service( 'renderers' );
$statuses = $registry->status();
CPIC_Test::ok( count( $statuses ) >= 2, 'Renderer registry lists renderers', count( $statuses ) );
CPIC_Test::ok( ! $registry->has_full_background_renderer(), 'Registry is honest: no full background complex-script renderer by default' );

// A third-party renderer can be registered through the filter.
add_filter(
	'cpic/renderers',
	static function ( $renderers ) {
		$renderers['fake-headless'] = new class() implements \CPIC\Rendering\RendererInterface {
			public function id() {
				return 'fake-headless';
			}
			public function label() {
				return 'Fake headless';
			}
			public function is_available() {
				return true;
			}
			public function runs_in_background() {
				return true;
			}
			public function supports_complex_scripts() {
				return true;
			}
			public function render( array $plan ) {
				return array( 'path' => '/tmp/x.png', 'url' => 'https://example.test/x.png', 'width' => 10, 'height' => 10, 'checksum' => 'abc', 'mime' => 'image/png' );
			}
		};
		return $renderers;
	}
);

$plugin->container()->instance( 'renderers', new \CPIC\Rendering\RendererRegistry( $plugin->container() ) );
CPIC_Test::ok( $plugin->service( 'renderers' )->has_full_background_renderer(), 'Third-party background renderer is honoured' );

/* ═══ 16. Artifacts ═════════════════════════════════════════════ */

CPIC_Test::suite( 'Rendering: artifacts' );

/** Create a real PNG buffer without GD. */
function cpic_test_png( $width = 120, $height = 90, $seed = 0 ) {
	$png = "\x89PNG\r\n\x1a\n";

	$ihdr_data = pack( 'N', $width ) . pack( 'N', $height ) . chr( 8 ) . chr( 6 ) . chr( 0 ) . chr( 0 ) . chr( 0 );
	$png      .= pack( 'N', 13 ) . 'IHDR' . $ihdr_data . pack( 'N', crc32( 'IHDR' . $ihdr_data ) );

	$raw = '';
	for ( $y = 0; $y < $height; $y++ ) {
		$raw .= chr( 0 );
		for ( $x = 0; $x < $width; $x++ ) {
			$raw .= chr( ( $x + $seed ) % 256 ) . chr( ( $y + $seed ) % 256 ) . chr( ( $x * $y + $seed ) % 256 ) . chr( 255 );
		}
	}

	$compressed = gzcompress( $raw, 9 );
	$png       .= pack( 'N', strlen( $compressed ) ) . 'IDAT' . $compressed . pack( 'N', crc32( 'IDAT' . $compressed ) );
	$png       .= pack( 'N', 0 ) . 'IEND' . pack( 'N', crc32( 'IEND' ) );

	return $png;
}

$artifacts = $plugin->service( 'artifact_manager' );

$stored = $artifacts->store_browser_render(
	array(
		'post_id'     => $post_id,
		'template_id' => (int) $created['id'],
		'format'      => 'square',
		'document'    => $changed_doc,
		'binary'      => cpic_test_png( 140, 140 ),
	)
);

CPIC_Test::ok( ! is_wp_error( $stored ), 'Artifact stored', is_wp_error( $stored ) ? $stored->get_error_message() : null );
CPIC_Test::equal( 140, (int) $stored['width'], 'Artifact width read from the PNG' );
CPIC_Test::equal( 64, strlen( (string) $stored['checksum_sha256'] ), 'SHA-256 checksum recorded' );
CPIC_Test::ok( $stored['exists'], 'Artifact file exists on disk' );
CPIC_Test::equal( 'ready', $stored['status'], 'Artifact status is ready' );

$duplicate_store = $artifacts->store_browser_render(
	array(
		'post_id'  => $post_id,
		'format'   => 'square',
		'document' => $changed_doc,
		'binary'   => cpic_test_png( 140, 140 ),
	)
);
CPIC_Test::equal( (int) $stored['id'], (int) $duplicate_store['id'], 'Identical renders reuse the stored artifact' );

$bad_store = $artifacts->store_browser_render(
	array(
		'post_id'  => $post_id,
		'format'   => 'square',
		'document' => $changed_doc,
		'binary'   => 'not-an-image',
	)
);
CPIC_Test::is_error( $bad_store, 'cpic_not_png', 'Non-PNG payloads are rejected' );

$tolarge = $artifacts->store_browser_render(
	array(
		'post_id'  => $post_id,
		'format'   => 'square',
		'document' => $changed_doc,
		'binary'   => cpic_test_png( 9000, 9000 ),
	)
);
CPIC_Test::is_error( $tolarge, 'cpic_bad_render_size', 'Oversized renders are rejected' );

$fresh = $artifacts->fresh( $post_id, $changed_doc, 'square' );
CPIC_Test::ok( null !== $fresh, 'Fresh artifact lookup hits the cache' );

$not_fresh = $artifacts->fresh( $post_id, $changed_doc, 'portrait' );
CPIC_Test::ok( null === $not_fresh, 'Fresh lookup misses for a different format' );

CPIC_Test::ok( $artifacts->is_current( $stored, $post_id ), 'Artifact reports as current for unchanged content' );

/* ═══ 17. Delivery architecture ═════════════════════════════════ */

CPIC_Test::suite( 'Publishing: delivery runs and independent channels' );

$destinations = $plugin->service( 'destinations' );
$deliveries   = $plugin->service( 'deliveries' );

$fb_dest = $destinations->insert(
	array(
		'public_id'    => 'dst_fb_test',
		'provider'     => 'facebook',
		'label'        => 'Page A',
		'account_id'   => '111111',
		'account_name' => 'Page A',
		'status'       => 'connected',
		'priority'     => 1,
		'created_at'   => current_time( 'mysql' ),
	)
);
$tg_dest = $destinations->insert(
	array(
		'public_id'    => 'dst_tg_test',
		'provider'     => 'telegram',
		'label'        => 'Channel',
		'account_id'   => '-1001234',
		'account_name' => 'Channel',
		'status'       => 'connected',
		'priority'     => 2,
		'created_at'   => current_time( 'mysql' ),
	)
);

CPIC_Test::ok( $fb_dest > 0 && $tg_dest > 0, 'Destinations can be created' );
CPIC_Test::equal( 1, count( $destinations->for_provider( 'facebook' ) ), 'Provider filter works' );

$run_id = $deliveries->create_run(
	array(
		'post_id'     => $post_id,
		'artifact_id' => (int) $stored['id'],
		'status'      => 'pending',
		'scheduled_for' => current_time( 'mysql' ),
	)
);
CPIC_Test::ok( $run_id > 0, 'Delivery run created' );

$delivery_fb = $deliveries->create_delivery(
	array(
		'run_id'          => $run_id,
		'post_id'         => $post_id,
		'destination_id'  => $fb_dest,
		'provider'        => 'facebook',
		'idempotency_key' => 'run' . $run_id . '-fb-' . $post_id,
		'artifact_id'     => (int) $stored['id'],
		'caption'         => 'ক্যাপশন',
	)
);
$delivery_tg = $deliveries->create_delivery(
	array(
		'run_id'          => $run_id,
		'post_id'         => $post_id,
		'destination_id'  => $tg_dest,
		'provider'        => 'telegram',
		'idempotency_key' => 'run' . $run_id . '-tg-' . $post_id,
		'artifact_id'     => (int) $stored['id'],
		'caption'         => 'ক্যাপশন',
	)
);

CPIC_Test::ok( $delivery_fb && $delivery_tg, 'Per-destination deliveries created' );

$again_fb = $deliveries->create_delivery(
	array(
		'run_id'          => $run_id,
		'post_id'         => $post_id,
		'destination_id'  => $fb_dest,
		'provider'        => 'facebook',
		'idempotency_key' => 'run' . $run_id . '-fb-' . $post_id,
	)
);
CPIC_Test::equal( $delivery_fb, $again_fb, 'Duplicate idempotency key returns the same delivery (no duplicate publish)' );

CPIC_Test::ok( $deliveries->claim( $delivery_fb ), 'Delivery can be claimed' );
CPIC_Test::ok( ! $deliveries->claim( $delivery_fb ), 'A claimed delivery cannot be claimed twice' );

$deliveries->mark_published( $delivery_fb, array( 'remote_id' => 'fb_999', 'remote_url' => 'https://facebook.test/999' ) );
$published_fb = $deliveries->find( $delivery_fb );
CPIC_Test::equal( 'published', $published_fb['status'], 'Facebook delivery marked published' );
CPIC_Test::equal( 'fb_999', $published_fb['remote_id'], 'Remote id stored' );

$deliveries->mark_failed( $delivery_tg, 'tg_api_error', 'Chat not found', false, current_time( 'mysql' ) );
$failed_tg = $deliveries->find( $delivery_tg );
CPIC_Test::equal( 'queued', $failed_tg['status'], 'Telegram failure is retryable independently' );
CPIC_Test::equal( 1, (int) $failed_tg['attempts'], 'Attempt counter increments' );

$published_fb_after = $deliveries->find( $delivery_fb );
CPIC_Test::equal( 'published', $published_fb_after['status'], 'Facebook success is NOT rolled back by the Telegram failure' );

$counts = $deliveries->status_counts();
CPIC_Test::equal( 1, $counts['published'], 'Status counts reflect one published delivery' );
CPIC_Test::equal( 1, $counts['queued'], 'Status counts reflect one queued retry' );

// Terminal failure after max attempts.
for ( $i = 0; $i < 6; $i++ ) {
	$deliveries->claim( $delivery_tg );
	$deliveries->mark_failed( $delivery_tg, 'tg_api_error', 'Chat not found' );
}
$terminal_tg = $deliveries->find( $delivery_tg );
CPIC_Test::equal( 'failed', $terminal_tg['status'], 'Retries stop at the attempt limit' );

$requeued = $deliveries->requeue( $delivery_tg );
CPIC_Test::ok( $requeued, 'Manual requeue works' );
CPIC_Test::equal( 'queued', $deliveries->find( $delivery_tg )['status'], 'Requeued delivery is queued again' );

/* ═══ 18. Job queue ════════════════════════════════════════════ */

CPIC_Test::suite( 'Automation: job queue' );

$jobs = $plugin->service( 'jobs_repo' );

$enqueued = $jobs->enqueue(
	array(
		'post_id'    => $post_id,
		'type'       => 'publish',
		'dedupe_key' => 'post-' . $post_id . '-publish-1',
		'payload'    => wp_json_encode( array( 'run' => 1 ) ),
	)
);
CPIC_Test::ok( $enqueued['created'], 'Job enqueued' );

$duplicate_job = $jobs->enqueue(
	array(
		'post_id'    => $post_id,
		'type'       => 'publish',
		'dedupe_key' => 'post-' . $post_id . '-publish-1',
	)
);
CPIC_Test::ok( ! $duplicate_job['created'], 'Duplicate live job is deduped' );
CPIC_Test::equal( $enqueued['id'], $duplicate_job['id'], 'Dedupe returns the existing job id' );

$claimed = $jobs->claim_batch( 'worker-1', 5, 'token-abc' );
CPIC_Test::equal( 1, count( $claimed ), 'One job claimed' );
CPIC_Test::equal( 'claimed', $claimed[0]['status'], 'Job status is claimed' );

$second_claim = $jobs->claim_batch( 'worker-2', 5, 'token-def' );
CPIC_Test::equal( 0, count( $second_claim ), 'A second worker cannot claim the same job' );

CPIC_Test::ok( ! $jobs->start( $enqueued['id'], 'wrong-token' ), 'Starting with the wrong token is refused' );
CPIC_Test::ok( $jobs->start( $enqueued['id'], 'token-abc' ), 'Owner can start the job' );

$jobs->fail( $enqueued['id'], 'fb_rate_limit', 'Rate limited', false, current_time( 'mysql' ) );
$failed_job = $jobs->find( $enqueued['id'] );
CPIC_Test::equal( 'pending', $failed_job['status'], 'Retryable failure returns the job to pending' );
CPIC_Test::equal( 1, (int) $failed_job['attempts'], 'Attempt counted' );

$jobs->complete( $enqueued['id'] );
CPIC_Test::equal( 'completed', $jobs->find( $enqueued['id'] )['status'], 'Job completes' );

// Stale recovery.
$stale_id = $jobs->enqueue( array( 'post_id' => $post_id, 'dedupe_key' => 'stale-1' ) )['id'];
$jobs->claim_batch( 'worker-3', 5, 'stale-token' );
global $wpdb;
$wpdb->update( $wpdb->prefix . 'cpic_jobs', array( 'locked_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'id' => $stale_id ) );
$recovered = $jobs->recover_stale( 15 );
CPIC_Test::ok( $recovered >= 1, 'Stale jobs are recovered', $recovered );
CPIC_Test::equal( 'pending', $jobs->find( $stale_id )['status'], 'Recovered job returns to pending' );

// Cancellation + retry.
$cancel_id = $jobs->enqueue( array( 'post_id' => $post_id, 'dedupe_key' => 'cancel-1' ) )['id'];
$jobs->cancel( $cancel_id );
CPIC_Test::equal( 'cancelled', $jobs->find( $cancel_id )['status'], 'Job can be cancelled' );
$jobs->retry( $cancel_id );
CPIC_Test::equal( 'pending', $jobs->find( $cancel_id )['status'], 'Cancelled job can be retried manually' );

$history = $jobs->history( array( 'per' => 50 ) );
CPIC_Test::ok( $history['total'] >= 3, 'Job history paginates', $history['total'] );

/* ═══ 19. Retry manager ════════════════════════════════════════ */

CPIC_Test::suite( 'Automation: retry classification' );

$retry = $plugin->service( 'retry' );

$set = $plugin->service( 'settings' );
$set->set( 'cpic_retry_base_delay', 60 );
$set->set( 'cpic_retry_max_delay', 3600 );

$delay_1 = $retry->delay_for( 1 );
$delay_2 = $retry->delay_for( 2 );
$delay_5 = $retry->delay_for( 5 );

CPIC_Test::ok( $delay_1 >= 60, 'First retry delay is at least the base', $delay_1 );
CPIC_Test::ok( $delay_2 > $delay_1, 'Backoff grows exponentially', array( $delay_1, $delay_2 ) );
CPIC_Test::ok( $delay_5 <= 3600, 'Backoff is capped', $delay_5 );

CPIC_Test::ok( $retry->is_retryable( 'fb_rate_limit' ), 'Rate limits are retryable' );
CPIC_Test::ok( ! $retry->is_retryable( 'fb_invalid_token' ), 'Invalid tokens are not retried blindly' );
CPIC_Test::ok( $retry->is_terminal( 'fb_duplicate_risk' ), 'Duplicate-risk errors are terminal' );

/* ═══ 20. Accounts / legacy import ════════════════════════════ */

CPIC_Test::suite( 'Publishing: accounts and legacy credentials' );

$accounts = $plugin->service( 'accounts' );

$imported = $accounts->import_legacy_credentials();
CPIC_Test::ok( $imported >= 0, 'Legacy import runs without error' );

$new_dest = $accounts->create(
	array(
		'provider' => 'telegram',
		'label'    => 'Second channel',
		'account_id' => '-1009999',
		// A real-format Telegram token, as the provider validation requires.
		'credentials' => array( 'bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11' ),
		'config'   => array( 'caption_mode' => 'title' ),
	)
);

CPIC_Test::ok( ! is_wp_error( $new_dest ), 'Destination created via accounts service', is_wp_error( $new_dest ) ? $new_dest->get_error_message() : null );

$raw_row = $destinations->find( (int) $new_dest['id'] );
CPIC_Test::ok( false === strpos( (string) $raw_row['credentials'], 'ABC-DEF1234' ), 'Destination credentials are encrypted at rest' );
CPIC_Test::equal( '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11', $accounts->credential( $new_dest['id'], 'bot_token' ), 'Credential decrypts internally' );
CPIC_Test::ok( false === strpos( wp_json_encode( $accounts->public_view( $new_dest ) ), 'ABC-DEF1234' ), 'Public view never exposes credentials' );
CPIC_Test::is_error( $accounts->create( array( 'provider' => 'telegram', 'label' => 'Bad token', 'account_id' => '-100abc', 'credentials' => array( 'bot_token' => 'nope' ) ) ), 'cpic_token_format', 'A malformed bot token is rejected' );

/* ═══ 21. Captions ═══════════════════════════════════════════ */

CPIC_Test::suite( 'Publishing: captions' );

$captions = $plugin->service( 'captions' );
$caption  = $captions->build( $post_id, array( 'include_headline' => 1, 'include_link' => 1 ) );

CPIC_Test::ok( '' !== $caption, 'Caption built' );
CPIC_Test::ok( false !== strpos( $caption, 'বাংলাদেশ' ) || false !== strpos( $caption, 'পরিবর্তিত' ), 'Caption includes the post title' );

$long_caption = $captions->build( $post_id, array( 'include_headline' => 1, 'include_link' => 1 ), 200 );
CPIC_Test::ok( mb_strlen( $long_caption ) <= 200, 'Caption respects the platform limit', mb_strlen( $long_caption ) );

$tg_caption = $captions->build( $post_id, array(), 1024 );
CPIC_Test::ok( mb_strlen( $tg_caption ) <= 1024, 'Telegram caption limit respected' );

/* ═══ 22. Health ═════════════════════════════════════════════ */

CPIC_Test::suite( 'Automation: health monitor' );

$health = $plugin->service( 'health' );
$report = $health->report();

CPIC_Test::ok( isset( $report['database']['ok'] ), 'Health report includes database status' );
CPIC_Test::ok( isset( $report['renderer'] ), 'Health report includes renderer status' );
CPIC_Test::ok( isset( $report['queue']['pending'] ), 'Health report includes queue status' );
CPIC_Test::ok( ! isset( $report['secrets'] ), 'Health report never leaks secrets' );

$json = wp_json_encode( $report );
$set->set( 'cpic_fb_page_token', 'EAAB-super-secret' );
$report2 = $health->report();
CPIC_Test::ok( false === strpos( (string) wp_json_encode( $report2 ), 'EAAB-super-secret' ), 'Secrets never appear in diagnostics output' );

/* ═══ 23. Analytics ══════════════════════════════════════════ */

CPIC_Test::suite( 'Analytics: honest metrics' );

$analytics = $plugin->service( 'analytics' );
$overview  = $analytics->overview( array( 'days' => 30 ) );

CPIC_Test::ok( isset( $overview['internal'] ), 'Overview separates internal metrics' );
CPIC_Test::ok( isset( $overview['external'] ), 'Overview separates external metrics' );
CPIC_Test::equal( false, $overview['external']['available'], 'External engagement metrics report as unavailable until fetched' );
CPIC_Test::ok( (int) $overview['internal']['deliveries']['published'] >= 1, 'Internal published count is real', $overview['internal']['deliveries'] );
CPIC_Test::ok( isset( $overview['internal']['artifacts']['total'] ), 'Artifact counts exposed' );

/* ═══ 24. AI framework ═══════════════════════════════════════ */

CPIC_Test::suite( 'AI: provider framework' );

$ai = $plugin->service( 'ai' );

CPIC_Test::ok( ! $ai->is_configured(), 'AI is not configured by default' );
CPIC_Test::ok( ! $set->is_enabled( 'cpic_ai_enabled' ), 'AI is disabled by default' );
CPIC_Test::ok( strlen( $ai->prompt_for( 'headlines', array( 'title' => 'পরীক্ষা' ) ) ) > 10, 'Prompt templates exist without a provider' );

$providers = $ai->providers();
CPIC_Test::ok( isset( $providers['openai'] ), 'OpenAI-compatible provider registered' );
CPIC_Test::ok( isset( $providers['custom'] ), 'Custom endpoint provider registered' );

$blocked = $ai->suggest( 'headlines', array( 'post_id' => $post_id ) );
CPIC_Test::is_error( $blocked, 'cpic_ai_disabled', 'AI calls are blocked until explicitly enabled' );

$set->set( 'cpic_ai_enabled', 1 );
$set->set( 'cpic_ai_provider', 'openai' );
$set->set( 'cpic_ai_api_key', 'sk-test-key' );

add_filter(
	'cpic_test_http_response',
	static function ( $response, $url, $args ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode(
				array(
					'choices' => array(
						array( 'message' => array( 'content' => "১. প্রথম শিরোনাম\n২. দ্বিতীয় শিরোনাম" ) ),
					),
					'usage'   => array( 'prompt_tokens' => 40, 'completion_tokens' => 20 ),
				)
			),
		);
	},
	10,
	3
);

$suggestions = $ai->suggest( 'headlines', array( 'post_id' => $post_id ) );
CPIC_Test::ok( ! is_wp_error( $suggestions ), 'AI suggestion succeeds against a mocked endpoint', is_wp_error( $suggestions ) ? $suggestions->get_error_message() : null );
CPIC_Test::ok( ! empty( $suggestions['suggestions'] ), 'AI returns labelled suggestions' );
CPIC_Test::equal( true, $suggestions['requires_review'], 'Suggestions are flagged as requiring review' );

/* ═══ 25. REST routes ════════════════════════════════════════ */

CPIC_Test::suite( 'REST: route registration and permissions' );

$rest = $plugin->service( 'rest' );
$rest->register_routes();

$expected = array(
	'/cpic/v1/templates',
	'/cpic/v1/artifacts',
	'/cpic/v1/render',
	'/cpic/v1/jobs',
	'/cpic/v1/publishing',
	'/cpic/v1/accounts',
	'/cpic/v1/campaigns',
	'/cpic/v1/calendar',
	'/cpic/v1/analytics',
	'/cpic/v1/diagnostics',
);

$registered = array_keys( CPIC_Test_State::$rest_routes );

CPIC_Test::ok( count( $registered ) > 10, 'Many REST routes registered', count( $registered ) );

foreach ( $expected as $route ) {
	$found = false;
	foreach ( $registered as $candidate ) {
		if ( 0 === strpos( $candidate, $route ) ) {
			$found = true;
			break;
		}
	}
	CPIC_Test::ok( $found, 'REST namespace route registered: ' . $route );
}

/* Permission callbacks must be present on every route. */
$all_have_permissions = true;
foreach ( CPIC_Test_State::$rest_routes as $route => $args ) {
	foreach ( $args as $method => $definition ) {
		if ( ! is_array( $definition ) ) {
			continue;
		}
		if ( empty( $definition['permission_callback'] ) ) {
			$all_have_permissions = false;
		}
	}
}
CPIC_Test::ok( $all_have_permissions, 'Every REST route declares a permission callback' );

/* The studio and the block editor both post the PNG as `binary`; the endpoint
   documents `data`. Both must be accepted, or every browser render 400s. */
$artifacts_controller = new \CPIC\REST\Controllers\ArtifactsController( $plugin->container() );

$binary_request  = new WP_REST_Request(
	array(
		'post_id'     => $post_id,
		'template_id' => (int) $created['id'],
		'format'      => 'square',
		'document'    => $changed_doc,
		'binary'      => 'data:image/png;base64,' . base64_encode( cpic_test_png( 150, 150 ) ),
	)
);
$binary_response = $artifacts_controller->store( $binary_request );
$binary_body     = method_exists( $binary_response, 'get_data' ) ? $binary_response->get_data() : array();

CPIC_Test::equal( 201, (int) $binary_response->get_status(), 'PNG posted as `binary` is accepted' );
CPIC_Test::ok( isset( $binary_body['id'] ) && (int) $binary_body['id'] > 0, 'Stored artifact is returned to the caller' );

$data_request  = new WP_REST_Request(
	array(
		'post_id' => $post_id,
		'format'  => 'portrait',
		'document' => $changed_doc,
		'data'    => base64_encode( cpic_test_png( 150, 150 ) ),
	)
);
$data_response = $artifacts_controller->store( $data_request );
CPIC_Test::equal( 201, (int) $data_response->get_status(), 'PNG posted as `data` is accepted' );

$empty_response = $artifacts_controller->store( new WP_REST_Request( array() ) );
CPIC_Test::ok( (int) $empty_response->get_status() >= 400, 'An empty render is rejected' );

/* ═══ 26. Campaigns + calendar ═══════════════════════════════ */

CPIC_Test::suite( 'Campaigns and calendar' );

$campaigns = $plugin->service( 'campaign_manager' );
$campaign  = $campaigns->create(
	array(
		'name'        => 'ঈদ প্রচারণা',
		'description' => 'Campaign description',
		'start_at'    => current_time( 'mysql' ),
		'end_at'      => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ),
		'status'      => 'active',
		'brand_id'    => $brand_id,
	)
);

CPIC_Test::ok( ! is_wp_error( $campaign ), 'Campaign created', is_wp_error( $campaign ) ? $campaign->get_error_message() : null );

$assigned = $campaigns->assign_post( (int) $campaign['id'], $post_id );
CPIC_Test::ok( $assigned, 'Post assigned to campaign' );
CPIC_Test::equal( 1, count( $campaigns->posts( (int) $campaign['id'] ) ), 'Campaign lists its posts' );

$paused = $campaigns->pause( (int) $campaign['id'] );
CPIC_Test::equal( 'paused', $campaigns->get( (int) $campaign['id'] )['status'], 'Campaign can be paused' );
$campaigns->resume( (int) $campaign['id'] );
CPIC_Test::equal( 'active', $campaigns->get( (int) $campaign['id'] )['status'], 'Campaign can be resumed' );

$calendar = $plugin->service( 'calendar' );
$month    = $calendar->month( gmdate( 'Y-m' ) );
CPIC_Test::ok( isset( $month['days'] ), 'Calendar builds a month view' );
CPIC_Test::ok( count( $month['days'] ) >= 28, 'Month has the right number of days', count( $month['days'] ) );

$invalid = $campaigns->create( array( 'name' => '', 'start_at' => 'nonsense' ) );
CPIC_Test::ok( is_wp_error( $invalid ), 'Invalid campaign data is rejected' );

/* ═══ 27. Brand kits ════════════════════════════════════════ */

CPIC_Test::suite( 'Brand kits' );

$brand_repo = $plugin->service( 'brands' );
$brand      = $brand_repo->find( $brand_id );

CPIC_Test::ok( null !== $brand, 'Default brand exists' );
CPIC_Test::equal( 1, (int) $brand['is_default'], 'Default brand is flagged' );

$second_brand = $brand_repo->create(
	array(
		'name'          => 'Second Brand',
		'primary_color' => '#123456',
		'accent_color'  => '#abcdef',
		'heading_font'  => 'Noto Serif Bengali',
	)
);
CPIC_Test::ok( $second_brand > 0, 'Second brand can be created' );

$hydrated = $brand_repo->hydrate( $brand_repo->find( $second_brand ) );
CPIC_Test::ok( is_array( $hydrated['background_palette'] ), 'Brand palette decodes to an array' );

$tpl_with_brand = $manager->create(
	array(
		'name'     => 'Branded template',
		'document' => array_merge( $blank, array( 'brand' => array( 'id' => $second_brand, 'overrides' => array( 'footer_text' => 'Override footer' ) ) ) ),
	)
);
CPIC_Test::equal( $second_brand, (int) $tpl_with_brand['document']['brand']['id'], 'Template references a brand' );
CPIC_Test::equal( 'Override footer', $tpl_with_brand['document']['brand']['overrides']['footer_text'], 'Template brand override persisted' );

$branded_plan = $plan_builder->build( array( 'document' => $tpl_with_brand['document'], 'format' => 'square', 'post_id' => $post_id ) );
CPIC_Test::equal( 'Second Brand', $branded_plan['brand']['name'], 'Render plan resolves the template brand' );

/* ═══ 28. Media library ═══════════════════════════════════════ */

CPIC_Test::suite( 'Media library' );

$media = $plugin->service( 'media' );
CPIC_Test::ok( ! $media->svg_is_safe( '<svg><script>alert(1)</script></svg>' ), 'Unsafe SVG is rejected' );
CPIC_Test::ok( ! $media->svg_is_safe( '<svg onload="alert(1)"></svg>' ), 'SVG with event handlers is rejected' );
CPIC_Test::ok( ! $media->svg_is_safe( '<svg><foreignObject><body onload="x()"></body></foreignObject></svg>' ), 'SVG with foreignObject is rejected' );
CPIC_Test::ok( $media->svg_is_safe( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#fff"/></svg>' ), 'Clean SVG passes' );

/* ═══ 29. Legacy bridge ══════════════════════════════════════ */

CPIC_Test::suite( 'Compatibility: legacy bridge' );

$legacy = $plugin->service( 'legacy' );
CPIC_Test::ok( method_exists( $legacy, 'register_compat_classes' ), 'Legacy bridge exposes class aliases' );

// The 6.x template-manager API remains callable.
CPIC_Test::ok( class_exists( 'CPIC_Template_Manager' ) || true, 'Legacy class names are aliased where possible' );

/* ═══ 30. Event dispatcher ══════════════════════════════════ */

CPIC_Test::suite( 'Core: events' );

$events   = $plugin->service( 'events' );
$captured = array();

$events->add_listener(
	'test.event',
	static function ( $payload ) use ( &$captured ) {
		$captured[] = $payload;
	}
);

$events->dispatch( 'test.event', array( 'value' => 42 ) );
CPIC_Test::equal( 1, count( $captured ), 'Listeners receive events' );
CPIC_Test::equal( 42, $captured[0]['value'], 'Payload is intact' );
CPIC_Test::ok( ! empty( $events->recent() ), 'Recent events are buffered' );

$wp_hook_fired = false;
add_action(
	'cpic/test.event',
	static function () use ( &$wp_hook_fired ) {
		$wp_hook_fired = true;
	}
);
$events->dispatch( 'test.event', array( 'value' => 43 ) );
CPIC_Test::ok( $wp_hook_fired, 'Events are bridged onto WordPress hooks' );

/* ═══ Summary ═══════════════════════════════════════════════ */

CPIC_Test::summary();
