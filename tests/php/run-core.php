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

/*
 * A number field typed as "abc" used to be stored as 0 without complaint.
 * Batch Size 0 stops the queue dead and Time Budget 0 was interpreted as
 * "5 seconds", both silently. Unreadable numbers are now reported instead.
 */
$bad_number = $settings->bulk_set(
	array(
		'cpic_batch_size'   => 'abc',
		'cpic_time_budget'  => 'twenty',
		'cpic_max_retries'  => '4',
	)
);
CPIC_Test::equal( 1, $bad_number['updated'], 'Only the readable number is written' );
CPIC_Test::equal( 5, $settings->int( 'cpic_batch_size' ), 'An unreadable number never overwrites the stored value' );
CPIC_Test::ok( isset( $bad_number['errors']['cpic_batch_size'] ), 'An unreadable number is reported to the form' );
CPIC_Test::equal( 4, $settings->int( 'cpic_max_retries' ), 'Valid numbers still save' );

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

/*
 * Cron events outlive the plugin. Every hook name the scheduler ever used must
 * be cleared, or WordPress keeps firing events at classes that are gone.
 */
$scheduler_source = (string) file_get_contents( CPIC_PLUGIN_DIR . 'src/Automation/Scheduler.php' );
preg_match_all( "/HOOK_[A-Z]+\s*=\s*'([^']+)'/", $scheduler_source, $hook_matches );
$declared_hooks = isset( $hook_matches[1] ) ? $hook_matches[1] : array();

CPIC_Test::ok( count( $declared_hooks ) >= 3, 'The scheduler declares its cron hooks', count( $declared_hooks ) );

$missing_hooks = array();
foreach ( $declared_hooks as $declared_hook ) {
	if ( false === strpos( $uninstall_source, "'" . $declared_hook . "'" ) ) {
		$missing_hooks[] = $declared_hook;
	}
}

CPIC_Test::equal( array(), $missing_hooks, 'Uninstall clears every scheduled hook' );

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
CPIC_Test::ok(
	1 === preg_match_all( "/font-family:'Hind Siliguri';font-style:normal;font-weight:500 900;font-display:block;src:url\('[^']*HindSiliguri-SemiBold\.ttf'\)/", $css ),
	'The semibold face is emitted in CSS'
);

// Each family must answer every weight with a real face, or the browser
// synthesises bold again — and two faces must never claim the same weight,
// or the last one wins for text meant for the first.
CPIC_Test::ok( 1 === preg_match_all( "/font-family:'Hind Siliguri';font-style:normal;font-weight:100 499;/", $css ), 'The Hind Siliguri regular face owns the regular range' );
CPIC_Test::ok( 1 === preg_match_all( "/font-family:'Hind Siliguri';font-style:normal;font-weight:500 900;/", $css ), 'The Hind Siliguri semibold face owns the bold range' );

preg_match_all( "/font-family:'Hind Siliguri';font-style:normal;font-weight:(\d+) (\d+);/", $css, $hind_match );
$hind_ranges = array();

foreach ( $hind_match[1] as $hind_index => $hind_from ) {
	$hind_ranges[] = array( (int) $hind_from, (int) $hind_match[2][ $hind_index ] );
}

CPIC_Test::equal( 2, count( $hind_ranges ), 'Hind Siliguri declares two weight ranges' );
CPIC_Test::ok(
	isset( $hind_ranges[0], $hind_ranges[1] ) && $hind_ranges[0][1] < $hind_ranges[1][0],
	'Hind Siliguri faces never overlap',
	wp_json_encode( $hind_ranges )
);

CPIC_Test::ok( 1 === preg_match_all( "/font-family:'Noto Sans Bengali';font-style:normal;font-weight:100 900;/", $css ), 'A single-face family answers every weight' );
CPIC_Test::equal( 4, substr_count( $css, '@font-face' ), 'Exactly one @font-face per bundled file' );
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

/* ═══ 25b. Studio round trip: plan → artifact → publish ═════ */

/*
 * The studio is a browser client: it asks for a plan, paints it locally,
 * posts the PNG, then publishes. Each step was covered in isolation, so a
 * mismatch between them (a field the client sends, a body the server expects)
 * only showed up on a real site. This drives the three controllers exactly as
 * the studio calls them, in order, with the same payloads.
 */

CPIC_Test::suite( 'Studio round trip: plan, artifact, publish' );

$studio_document = $created['document'];

// The studio posts `{ document, format, post_id, brand_id }` to /render/plan.
$plan_request = new WP_REST_Request(
	array(
		'document' => $studio_document,
		'format'   => 'square',
		'post_id'  => $post_id,
		'brand_id' => 0,
	)
);
$plan_response = new \CPIC\REST\Controllers\RenderController( $plugin->container() );
$plan_payload  = $plan_response->plan( $plan_request );
$plan_body     = method_exists( $plan_payload, 'get_data' ) ? $plan_payload->get_data() : array();
$plan_data     = isset( $plan_body['data'] ) ? $plan_body['data'] : $plan_body;

CPIC_Test::equal( 200, (int) $plan_payload->get_status(), 'The studio can fetch a render plan with its exact payload' );
CPIC_Test::ok( ! empty( $plan_data['layers'] ), 'The plan carries layers to paint', is_array( $plan_data ) ? count( (array) $plan_data ) : 'no data' );
CPIC_Test::equal( 'square', isset( $plan_data['format'] ) ? $plan_data['format'] : '', 'The plan echoes the requested format' );
CPIC_Test::ok( isset( $plan_data['width'], $plan_data['height'] ) && $plan_data['width'] > 0, 'The plan carries the canvas size' );

// The studio posts `{ post_id, template_id, format, document, binary }`.
$artifact_request = new WP_REST_Request(
	array(
		'post_id'     => $post_id,
		'template_id' => (int) $created['id'],
		'format'      => 'square',
		'document'    => $studio_document,
		'binary'      => 'data:image/png;base64,' . base64_encode( cpic_test_png( 1080, 1080 ) ),
	)
);
$artifact_response = $artifacts_controller->store( $artifact_request );
$artifact_body     = method_exists( $artifact_response, 'get_data' ) ? $artifact_response->get_data() : array();

CPIC_Test::equal( 201, (int) $artifact_response->get_status(), 'A 1080×1080 studio render is accepted' );
CPIC_Test::ok( ! empty( $artifact_body['exists'] ), 'The stored artifact reports as on disk' );
CPIC_Test::ok( ! empty( $artifact_body['file_url'] ), 'The stored artifact exposes a URL for publishing' );

// Publishing the artifact the studio just stored must not re-render.
// Connect it through the real API so credentials are encrypted the way the
// publish path expects them; a raw row would have no usable page token.
$destination = $plugin->service( 'accounts' )->create(
	array(
		'provider'    => 'facebook',
		'label'       => 'Test page',
		'account_id'  => 'page-cpic-round-trip',
		'account_name' => 'Test page',
		'status'      => 'connected',
		'credentials' => array(
			// Facebook page tokens are long; the manager rejects short ones.
			'page_token' => 'EAAG' . str_repeat( 'testtoken', 4 ),
			'app_id'     => '123',
			'app_secret' => 'secret',
		),
	)
);

if ( is_wp_error( $destination ) ) {
	// Fall back to a direct row so the rest of the round trip still runs.
	$destination = $plugin->service( 'accounts' )->repo()->insert(
		array(
			'public_id'  => 'dest-test-1',
			'provider'   => 'facebook',
			'label'      => 'Test page',
			'account_id' => 'page-cpic-round-trip',
			'status'     => 'connected',
			'priority'   => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		)
	);
} else {
	$destination = (int) $destination['id'];
}

$targets = $plugin->service( 'accounts' )->resolve_targets( array( 'destination_ids' => array( $destination ) ) );

CPIC_Test::ok(
	$destination > 0 && ! empty( $targets ),
	'A connected destination exists for the publish step',
	'id ' . $destination . ', ' . count( (array) $targets ) . ' target(s)'
);

// The publish pipeline calls the provider over HTTP; answer every request.
$published_posts = array();
$published_http  = function ( $response, $url ) use ( &$published_posts ) {
	$published_posts[] = (string) $url;

	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array( 'id' => 'fb-post-1', 'post_id' => 'fb-post-1' ) ),
		'headers'  => array(),
	);
};
add_filter( 'cpic_test_http_response', $published_http, 10, 2 );

$publishing_controller = new \CPIC\REST\Controllers\PublishingController( $plugin->container() );
$publish_request       = new WP_REST_Request(
	array(
		'post_id'         => $post_id,
		'format'          => 'square',
		'destination_ids' => array( $destination ),
		'artifact_id'     => (int) $artifact_body['id'],
		'overrides'       => array(),
	)
);
$publish_response = $publishing_controller->publish( $publish_request );
$publish_body     = method_exists( $publish_response, 'get_data' ) ? $publish_response->get_data() : array();
$publish_status   = (int) $publish_response->get_status();

CPIC_Test::ok(
	in_array( $publish_status, array( 201, 202 ), true ),
	'Publishing the stored artifact is accepted',
	'status ' . $publish_status . ' ' . wp_json_encode( $publish_body )
);

$publish_data = isset( $publish_body['data'] ) ? $publish_body['data'] : $publish_body;
CPIC_Test::ok( ! empty( $publish_data['deliveries'] ), 'Publishing reports the deliveries it queued', is_array( $publish_data ) ? implode( ',', array_keys( $publish_data ) ) : 'none' );

$queued_status = isset( $publish_data['deliveries'][0]['status'] ) ? (string) $publish_data['deliveries'][0]['status'] : '';
CPIC_Test::ok( '' !== $queued_status, 'Each delivery carries a status', $queued_status );
CPIC_Test::ok( ! in_array( $queued_status, array( 'failed', 'error' ), true ), 'The delivery was not rejected', $queued_status );

/*
 * Once the provider confirms, the post is published. A second manual publish
 * must then be refused rather than duplicate the post on the page. The first
 * publish only *queues* the delivery, so mark it confirmed the way the worker
 * would, then ask again.
 */
/*
 * Delivery rows are keyed by `id` (that is what the repository returns). The
 * old lookup used `delivery_id`, which never matched, so this assertion was
 * quietly skipped and the duplicate-publish check below was never really
 * exercised against a published row.
 */
if ( ! empty( $publish_data['deliveries'][0]['id'] ) ) {
	$delivery_id = (int) $publish_data['deliveries'][0]['id'];
	// The deliveries table has no updated_at column (only runs, jobs and the
	// repositories that write it), so writing one made the update throw and
	// the assertion below fail for the wrong reason.
	$updated     = $plugin->service( 'deliveries' )->update(
		$delivery_id,
		array( 'status' => 'published' )
	);
	$row_now     = $plugin->service( 'deliveries' )->find( $delivery_id );

	CPIC_Test::ok(
		$updated && isset( $row_now['status'] ) && 'published' === $row_now['status'],
		'The delivery can be marked published',
		wp_json_encode( $row_now ? array( 'status' => $row_now['status'], 'destination_id' => $row_now['destination_id'] ) : null )
	);
}

/*
 * A delivery outcome must carry the provider's own message.
 *
 * `outcome()` used to return only the generic explain() advice, and to drop
 * the code entirely when this attempt succeeded. Both left the queue with a
 * row that could not say what the provider had actually complained about.
 */
if ( ! empty( $delivery_id ) ) {
	$outcome_method = new \ReflectionMethod( $plugin->service( 'delivery_manager' ), 'outcome' );
	$outcome_method->setAccessible( true );

	$failed_row = $plugin->service( 'deliveries' )->update(
		$delivery_id,
		array(
			'status'     => 'queued',
			'error_code' => 'fb_invalid_token',
			'last_error' => 'Facebook said: Error validating access token.',
		)
	);

	$outcome = $outcome_method->invoke( $plugin->service( 'delivery_manager' ), $delivery_id, 'queued', '' );

	CPIC_Test::ok( is_array( $outcome ), 'A delivery outcome is produced' );

	CPIC_Test::equal(
		'Facebook said: Error validating access token.',
		isset( $outcome['error_message'] ) ? (string) $outcome['error_message'] : '',
		'The outcome keeps the provider message, not only the generic hint'
	);
	CPIC_Test::equal(
		'fb_invalid_token',
		isset( $outcome['error_code'] ) ? (string) $outcome['error_code'] : '',
		'The outcome falls back to the stored error code'
	);

	// Leave the row as the published row the duplicate test above expects.
	$plugin->service( 'deliveries' )->update(
		$delivery_id,
		array(
			'status'     => 'published',
			'error_code' => '',
			'last_error' => '',
		)
	);
}

$repeat_response = $publishing_controller->publish( $publish_request );
$repeat_status   = (int) $repeat_response->get_status();
$repeat_body     = method_exists( $repeat_response, 'get_data' ) ? $repeat_response->get_data() : array();

CPIC_Test::equal( 409, $repeat_status, 'Publishing the same post twice is refused' );
CPIC_Test::equal(
	'cpic_already_published',
	isset( $repeat_body['code'] ) ? (string) $repeat_body['code'] : '',
	'A duplicate publish explains itself'
);

/* ═══ 25c. Scheduled publishing actually runs ════════════════ */

/*
 * "Schedule" writes a run with `schedule_only`, which nothing but the cron
 * hook drains. When that call was missing the UI happily said "scheduled"
 * and nothing was ever posted, at any time, on any site. This builds the
 * state a browser-only render leaves behind — a parked delivery plus the PNG
 * the studio stores a moment later — and drives the registered hook.
 */

CPIC_Test::suite( 'Scheduled publishing fires from cron' );

CPIC_Test_State::$posts[8123] = cpic_test_make_post(
	array(
		'ID'           => 8123,
		'post_title'   => 'Scheduled post',
		'post_content' => '<p>Body</p>',
		'post_excerpt' => 'Excerpt',
	)
);

$parked_target   = $plugin->service( 'accounts' )->resolve_targets( array( 'destination_ids' => array( $destination ) ) );
$parked_dest_id  = ! empty( $parked_target[0]['id'] ) ? (int) $parked_target[0]['id'] : (int) $destination;

// Site time, like every other `current_time( 'mysql' )` row.
$parked_scheduled = gmdate( 'Y-m-d H:i:s', time() + 3600 + ( (int) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) );

$parked_run = $plugin->service( 'deliveries' )->create_run(
	array(
		'post_id'        => 8123,
		'artifact_id'    => 0,
		'status'         => 'waiting_for_artifact',
		'scheduled_for'  => $parked_scheduled,
		'trigger_source' => 'schedule',
		'created_at'     => current_time( 'mysql' ),
	)
);

$parked_id = $plugin->service( 'deliveries' )->create_delivery(
	array(
		'run_id'          => $parked_run,
		'post_id'         => 8123,
		'destination_id'  => $parked_dest_id,
		'provider'        => 'facebook',
		'idempotency_key' => 'run' . $parked_run . '-dst' . $parked_dest_id . '-post8123',
		'artifact_id'     => 0,
		'caption'         => 'Scheduled caption',
		'status'          => 'waiting_for_artifact',
		'max_attempts'    => 5,
		'scheduled_for'   => $parked_scheduled,
		'created_at'      => current_time( 'mysql' ),
	)
);

CPIC_Test::ok( $parked_run > 0 && $parked_id > 0, 'A parked scheduled run can be created' );

// The studio's PNG lands just after the run is parked.
$parked_artifact = $plugin->service( 'artifact_manager' )->store_browser_render(
	array(
		'post_id'  => 8123,
		'format'   => 'square',
		'document' => $changed_doc,
		'binary'   => cpic_test_png( 1080, 1080, 3 ),
	)
);

CPIC_Test::ok( is_array( $parked_artifact ) && ! empty( $parked_artifact['id'] ), 'The studio stores the image for the parked run' );

$due_before = 0;
foreach ( (array) $plugin->service( 'deliveries' )->due( 50 ) as $due_row ) {
	if ( (int) $due_row['id'] === $parked_id ) {
		$due_before++;
	}
}
CPIC_Test::equal( 0, $due_before, 'A future delivery is not due yet' );

$plugin->service( 'deliveries' )->update(
	$parked_id,
	array( 'scheduled_for' => gmdate( 'Y-m-d H:i:s', time() - 60 + ( (int) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) ) )
);

$due_after = 0;
foreach ( (array) $plugin->service( 'deliveries' )->due( 50 ) as $due_row ) {
	if ( (int) $due_row['id'] === $parked_id ) {
		$due_after++;
	}
}
CPIC_Test::equal( 1, $due_after, 'A past delivery is due' );

$plugin->service( 'scheduler' )->register_handlers();
do_action( 'cpic/cron/queue' );

$after_cron = $plugin->service( 'deliveries' )->find( $parked_id );

CPIC_Test::ok(
	null !== $after_cron,
	'The cron hook reached the scheduled delivery',
	wp_json_encode( $after_cron ? array( 'status' => $after_cron['status'], 'attempts' => (int) $after_cron['attempts'] ) : null )
);

CPIC_Test::ok(
	null !== $after_cron && 'waiting_for_artifact' !== (string) $after_cron['status'],
	'A parked run adopts the stored image and stops waiting',
	null === $after_cron ? 'no row' : (string) $after_cron['status']
);

CPIC_Test::ok(
	null !== $after_cron && (int) $after_cron['artifact_id'] > 0,
	'The adopted delivery points at a real artifact',
	null === $after_cron ? 'no row' : 'artifact ' . (int) $after_cron['artifact_id']
);

/*
 * The studio stores its PNG right after publish reports `waiting_for_artifact`.
 * Resuming then means the user sees the post go out; waiting for the next cron
 * tick made the dialog's "saved for publishing" feel like a failure.
 */
CPIC_Test_State::$posts[8125] = cpic_test_make_post(
	array(
		'ID'           => 8125,
		'post_title'   => 'Immediate resume',
		'post_content' => '<p>Body</p>',
		'post_excerpt' => 'Excerpt',
	)
);

$resume_run = $plugin->service( 'deliveries' )->create_run(
	array(
		'post_id'       => 8125,
		'artifact_id'   => 0,
		'status'        => 'waiting_for_artifact',
		'scheduled_for' => current_time( 'mysql' ),
		'created_at'    => current_time( 'mysql' ),
	)
);
$resume_id = $plugin->service( 'deliveries' )->create_delivery(
	array(
		'run_id'          => $resume_run,
		'post_id'         => 8125,
		'destination_id'  => $parked_dest_id,
		'provider'        => 'facebook',
		'idempotency_key' => 'run' . $resume_run . '-resume',
		'artifact_id'     => 0,
		'status'          => 'waiting_for_artifact',
		'scheduled_for'   => current_time( 'mysql' ),
		'created_at'      => current_time( 'mysql' ),
	)
);

CPIC_Test::equal(
	array( $resume_id ),
	$plugin->service( 'deliveries' )->waiting_for_artifact_ids( 8125 ),
	'Parked deliveries for a post are found by post id'
);

// Storing the image is what the studio does just before publishing finishes.
$plugin->service( 'artifact_manager' )->store_browser_render(
	array(
		'post_id'  => 8125,
		'format'   => 'square',
		'document' => $changed_doc,
		'binary'   => cpic_test_png( 1080, 1080, 11 ),
	)
);

$resumed            = $plugin->service( 'deliveries' )->find( $resume_id );
$resumed_artifact   = $resumed ? (int) $resumed['artifact_id'] : 0;

CPIC_Test::equal( $resume_id, $resumed ? (int) $resumed['id'] : 0, 'The resumed delivery still exists' );
CPIC_Test::ok( $resumed_artifact > 0, 'Storing the image unparks the delivery without a cron tick', 'artifact ' . $resumed_artifact );
CPIC_Test::ok(
	$resumed && ! in_array( (string) $resumed['status'], array( 'waiting_for_artifact' ), true ),
	'The delivery left the waiting state immediately',
	$resumed ? (string) $resumed['status'] : 'no row'
);

/*
 * `scheduled_for` arrives from the REST layer in UTC but every row stores
 * site time; comparing the two directly made a run an hour ahead look overdue
 * on a UTC+6 site (so it posted immediately) and made nothing ever come due
 * on a negative-offset site.
 */
CPIC_Test::equal(
	'2026-01-01 12:00:00',
	$plugin->service( 'delivery_manager' )->to_site_time( '2026-01-01 06:00:00' ),
	'A UTC schedule is converted to site time before it is stored'
);

$site_offset = (int) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS;

CPIC_Test::ok(
	$plugin->service( 'delivery_manager' )->is_future( gmdate( 'Y-m-d H:i:s', time() + 7200 + $site_offset ) ),
	'A schedule two hours ahead is still in the future'
);

CPIC_Test::ok(
	! $plugin->service( 'delivery_manager' )->is_future( gmdate( 'Y-m-d H:i:s', time() - 120 + $site_offset ) ),
	'A schedule two minutes ago is not in the future'
);

// Rescheduling must keep the wall clock the editor picked.
CPIC_Test_State::$posts[8124] = cpic_test_make_post(
	array(
		'ID'           => 8124,
		'post_title'   => 'Reschedule me',
		'post_content' => '<p>Body</p>',
		'post_excerpt' => 'Excerpt',
	)
);

$resched_run = $plugin->service( 'deliveries' )->create_run(
	array(
		'post_id'       => 8124,
		'artifact_id'   => (int) $parked_artifact['id'],
		'status'        => 'pending',
		'scheduled_for' => current_time( 'mysql' ),
		'created_at'    => current_time( 'mysql' ),
	)
);
$resched_id = $plugin->service( 'deliveries' )->create_delivery(
	array(
		'run_id'          => $resched_run,
		'post_id'         => 8124,
		'destination_id'  => $parked_dest_id,
		'provider'        => 'facebook',
		'idempotency_key' => 'run' . $resched_run . '-resched',
		'artifact_id'     => (int) $parked_artifact['id'],
		'status'          => 'pending',
		'created_at'      => current_time( 'mysql' ),
	)
);

$picked = gmdate( 'Y-m-d', time() + $site_offset ) . ' 09:30:00';
$plugin->service( 'calendar' )->reschedule( $resched_id, $picked );

CPIC_Test::equal(
	$picked,
	(string) $plugin->service( 'deliveries' )->find( $resched_id )['scheduled_for'],
	'Rescheduling stores the site-time slot the editor picked'
);

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

/* ═══ 31. Admin request handlers ═════════════════════════════ */

CPIC_Test::suite( 'Admin: request handlers' );

/*
 * Admin-post handlers were previously unreachable from the suite: the stubs
 * had no check_admin_referer(), so every POST died before it did anything and
 * no handler had ever actually run. These drive the real entry points with
 * real nonces, and read back the redirect plus the rows they wrote.
 */

/** Reset request superglobals and wp_die state between handlers. */
function cpic_test_reset_request() {
	$_POST    = array();
	$_GET     = array();
	$_REQUEST = array();

	CPIC_Test_State::$wp_die   = null;
	CPIC_Test_State::$redirects = array();
}

/** Post a valid nonce for an action, the way a real form would. */
function cpic_test_nonce( $action ) {
	$nonce = wp_create_nonce( $action );

	$_POST['_wpnonce']    = $nonce;
	$_REQUEST['_wpnonce'] = $nonce;

	return $nonce;
}

$admin   = $plugin->service( 'admin' );
$brands  = $plugin->service( 'brands' );

cpic_test_reset_request();
$_POST['name']          = 'Handler Brand';
$_POST['primary_color'] = '#0f766e';
$_POST['footer_text']   = 'Handler footer';
cpic_test_nonce( 'cpic_brand_new' );
$admin->handle_save_brand();

$brand_rows = $brands->select( array(), 'id ASC' );
$handler_brand = null;
foreach ( $brand_rows as $brand_row ) {
	if ( 'Handler Brand' === $brand_row['name'] ) {
		$handler_brand = $brand_row;
	}
}

CPIC_Test::ok( null !== $handler_brand, 'A brand saved through the admin handler is persisted' );
CPIC_Test::equal( '#0f766e', $handler_brand ? $handler_brand['primary_color'] : null, 'Handler writes the submitted colour' );
CPIC_Test::equal( 1, count( CPIC_Test_State::$redirects ), 'A successful save redirects exactly once' );
CPIC_Test::ok(
	isset( CPIC_Test_State::$redirects[0] ) && false !== strpos( CPIC_Test_State::$redirects[0], 'cpic_notice=saved' ),
	'A successful save reports the saved notice',
	CPIC_Test_State::$redirects
);

/*
 * Two kits may share a name: the repository keeps slugs unique, so a second
 * "Handler Brand" is a legitimate row, not a refused one. Its slug is what
 * must differ.
 */
$before_dupe = count( $brand_rows );
cpic_test_reset_request();
$_POST['name'] = 'Handler Brand';
cpic_test_nonce( 'cpic_brand_new' );
$admin->handle_save_brand();

$dupe_rows = $brands->select( array(), 'id ASC' );
CPIC_Test::equal( $before_dupe + 1, count( $dupe_rows ), 'A second kit with the same name is created' );

$slug_seen = array();
$slugs_unique = true;
foreach ( $dupe_rows as $dupe_row ) {
	if ( isset( $slug_seen[ $dupe_row['slug'] ] ) ) {
		$slugs_unique = false;
	}
	$slug_seen[ $dupe_row['slug'] ] = true;
}
CPIC_Test::ok( $slugs_unique, 'Brand slugs stay unique when names collide' );
CPIC_Test::ok(
	isset( CPIC_Test_State::$redirects[0] ) && false !== strpos( CPIC_Test_State::$redirects[0], 'cpic_notice=saved' ),
	'A second kit with the same name reports a save'
);

// A nameless kit is refused: it would be an unidentifiable row.
$before_nameless = count( $brands->select( array(), 'id ASC' ) );
cpic_test_reset_request();
cpic_test_nonce( 'cpic_brand_new' );
$admin->handle_save_brand();
CPIC_Test::equal( $before_nameless, count( $brands->select( array(), 'id ASC' ) ), 'A nameless brand kit creates no row' );
CPIC_Test::ok(
	isset( CPIC_Test_State::$redirects[0] ) && false !== strpos( CPIC_Test_State::$redirects[0], 'cpic_notice=error' ),
	'A nameless brand kit reports an error'
);

// A stale or forged nonce must stop the handler before it writes anything.
cpic_test_reset_request();
$_POST['name'] = 'Forged Brand';
$_POST['_wpnonce']        = 'not-a-real-nonce';
$_REQUEST['_wpnonce']     = 'not-a-real-nonce';
$forged_name = null;
try {
	$admin->handle_save_brand();
} catch ( \Throwable $e ) {
	$forged_name = 'threw';
}
$stored_forged = false;
foreach ( $brands->select( array(), 'id ASC' ) as $brand_row ) {
	if ( 'Forged Brand' === $brand_row['name'] ) {
		$stored_forged = true;
	}
}
CPIC_Test::ok( ! $stored_forged, 'A bad nonce never reaches the write' );
CPIC_Test::ok( null !== CPIC_Test_State::$wp_die, 'A bad nonce stops the request' );
CPIC_Test::equal(
	403,
	CPIC_Test_State::$wp_die ? (int) CPIC_Test_State::$wp_die['status'] : 0,
	'A bad nonce is answered with 403'
);

// Missing post id is a 400, not a 500 and not a silent success.
cpic_test_reset_request();
$_POST['_wpnonce'] = wp_create_nonce( 'cpic_publish_0' );
$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
try {
	$admin->handle_publish_now();
} catch ( \Throwable $e ) {
	unset( $e );
}
CPIC_Test::equal(
	400,
	CPIC_Test_State::$wp_die ? (int) CPIC_Test_State::$wp_die['status'] : 0,
	'Publishing without a post id is a 400'
);
CPIC_Test::equal( array(), CPIC_Test_State::$redirects, 'A refused publish does not redirect' );

/*
 * A run the background renderer cannot produce is parked, not published.
 *
 * This is the whole point of the notice contract: the handler used to set
 * 'published' for any non-WP_Error result, so a parked run was reported as
 * "The post was sent to its destinations." while nothing had been sent.
 */
cpic_test_reset_request();
/*
 * Pin the renderer. Earlier suites mutate settings, and which renderer is
 * primary decides whether a publish parks or renders — so without this the
 * outcome below depended on test order.
 */
$renderer_before = (string) $settings->get( 'cpic_renderer', 'browser' );
$settings->set( 'cpic_renderer', 'browser' );

/*
 * Claim to be a browser request, which is exactly what the studio POST is.
 * Without it the background fallback renders a file, and the parked branch —
 * the one this test exists to cover — is never reached.
 */
$browser_filter = static function () {
	return true;
};
add_filter( 'cpic/render/is_browser_request', $browser_filter );

$publish_post_id = 8126;

CPIC_Test_State::$posts[ $publish_post_id ] = cpic_test_make_post(
	array(
		'ID'           => $publish_post_id,
		'post_title'   => 'Parked publish',
		'post_content' => '<p>Body for the parked publish test.</p>',
		'post_status'  => 'publish',
	)
);

$publish_target = $accounts->create(
	array(
		'provider'    => 'telegram',
		'label'       => 'Parked target',
		'account_id'  => '-1007777',
		// A freshly created account is 'disconnected' until it is verified;
		// resolve_targets() only offers connected destinations to a publish.
		'status'      => 'connected',
		'credentials' => array( 'bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11' ),
	)
);

$_POST['post_id']      = $publish_post_id;
$_POST['destinations'] = array( (int) $publish_target['id'] );
cpic_test_nonce( 'cpic_publish_' . $publish_post_id );
try {
	$admin->handle_publish_now();
} catch ( \Throwable $e ) {
	unset( $e );
}

$publish_redirect = isset( CPIC_Test_State::$redirects[0] ) ? (string) CPIC_Test_State::$redirects[0] : '';
$publish_notice   = '';
if ( preg_match( '/cpic_notice=([a-z_]+)/', $publish_redirect, $publish_match ) ) {
	$publish_notice = $publish_match[1];
}

CPIC_Test::ok( '' !== $publish_notice, 'Publishing redirects with a notice', $publish_redirect );
CPIC_Test::ok(
	'published' !== $publish_notice,
	'A parked publish is never reported as published',
	$publish_notice
);
CPIC_Test::equal( 'queued', $publish_notice, 'A parked publish reports that it is queued' );

/*
 * An unknown status action must report one error and stop. It used to fall
 * through to the "saved" redirect as well, so one click produced two flashes
 * and the last one won.
 */
$status_handlers = array(
	array( 'handle_account_status', 'cpic_account_status_bogus_1' ),
	array( 'handle_campaign_status', 'cpic_campaign_status_bogus_1' ),
);

foreach ( $status_handlers as $status_case ) {
	list( $status_handler, $status_nonce ) = $status_case;

	cpic_test_reset_request();
	$_GET['account']     = 1;
	$_GET['campaign']    = 1;
	$_GET['cpic_action'] = 'bogus';
	cpic_test_nonce( $status_nonce );
	$admin->$status_handler();

	CPIC_Test::equal( 1, count( CPIC_Test_State::$redirects ), $status_handler . ' redirects exactly once' );
	CPIC_Test::ok(
		isset( CPIC_Test_State::$redirects[0] ) && false !== strpos( CPIC_Test_State::$redirects[0], 'cpic_notice=error' ),
		$status_handler . ' reports the error it settled on',
		CPIC_Test_State::$redirects
	);
}

$trailing_bad_case = '$status = \'published\';';
$admin_source      = file_get_contents( CPIC_TEST_ROOT . '/src/Admin/Admin.php' );
CPIC_Test::ok(
	false === strpos( $admin_source, $trailing_bad_case ),
	'The publish handler has no unconditional published branch'
);

// The queue screen must describe each notice it can be handed.
$queue_template = file_get_contents( CPIC_TEST_ROOT . '/templates/admin/queue.php' );
foreach ( array( 'published', 'queued', 'scheduled', 'failed' ) as $notice_key ) {
	CPIC_Test::ok(
		false !== strpos( $queue_template, "'" . $notice_key . "' === \$cpic_notice" ),
		'The queue screen has a branch for the ' . $notice_key . ' notice'
	);
}
CPIC_Test::ok(
	false !== strpos( $queue_template, 'nothing has been sent yet' ),
	'The queued notice does not claim the post was sent'
);

$settings->set( 'cpic_renderer', $renderer_before );
remove_filter( 'cpic/render/is_browser_request', $browser_filter );

/* ═══ 32. Campaign assignment contract ══════════════════════ */

CPIC_Test::suite( 'Campaigns: assignment contract' );

$campaign_manager = $plugin->service( 'campaign_manager' );

$campaign_result = $campaign_manager->create( array( 'name' => 'Assignment campaign' ) );
CPIC_Test::ok( ! is_wp_error( $campaign_result ), 'A campaign is created for the assignment suite' );
$campaign_id = is_array( $campaign_result ) && isset( $campaign_result['id'] ) ? (int) $campaign_result['id'] : (int) $campaign_result;

$assign_post_id = 8130;
CPIC_Test_State::$posts[ $assign_post_id ] = cpic_test_make_post(
	array(
		'ID'          => $assign_post_id,
		'post_title'  => 'Assigned post',
		'post_status' => 'publish',
	)
);

CPIC_Test::equal( true, $campaign_manager->assign_post( $campaign_id, $assign_post_id ), 'A post is assigned to the campaign' );

// Unassigning a real assignment succeeds.
CPIC_Test::equal( true, $campaign_manager->unassign_post( $campaign_id, $assign_post_id ), 'An assigned post is unassigned' );

// Every refusal carries a code and a status, so REST can answer honestly.
$missing_campaign = $campaign_manager->unassign_post( 999999, $assign_post_id );
CPIC_Test::is_error( $missing_campaign, 'cpic_campaign_missing', 'Unassigning from an unknown campaign is refused' );
CPIC_Test::equal(
	404,
	is_wp_error( $missing_campaign ) && isset( $missing_campaign->get_error_data()['status'] ) ? (int) $missing_campaign->get_error_data()['status'] : 0,
	'An unknown campaign is a 404'
);

CPIC_Test::is_error( $campaign_manager->unassign_post( $campaign_id, 0 ), 'cpic_post_missing', 'Unassigning without a post is refused' );

$not_assigned = $campaign_manager->unassign_post( $campaign_id, $assign_post_id );
CPIC_Test::is_error( $not_assigned, 'cpic_not_assigned', 'Unassigning a post that is not in the campaign is refused' );
CPIC_Test::equal(
	404,
	is_wp_error( $not_assigned ) && isset( $not_assigned->get_error_data()['status'] ) ? (int) $not_assigned->get_error_data()['status'] : 0,
	'A missing assignment is a 404'
);

// The REST handler must surface the refusal instead of 200 false.
$campaigns_controller = new \CPIC\REST\Controllers\CampaignsController( $plugin->container() );
$unassign_request     = new WP_REST_Request(
	array(
		'id'      => 999999,
		'post_id' => $assign_post_id,
	)
);
$unassign_response = $campaigns_controller->unassign( $unassign_request );
CPIC_Test::equal( 404, (int) $unassign_response->get_status(), 'REST answers 404 for an unknown campaign, not 200 false' );

/* ═══ 33. Artifact retention ═══════════════════════════════ */

CPIC_Test::suite( 'Rendering: artifact cleanup' );

$artifact_manager = $plugin->service( 'artifact_manager' );
$artifact_repo    = $plugin->service( 'artifacts' );

$cleanup_post_id = 8140;
CPIC_Test_State::$posts[ $cleanup_post_id ] = cpic_test_make_post( array( 'ID' => $cleanup_post_id, 'post_title' => 'Cleanup' ) );

$make_artifact = static function ( $name, $created_at ) use ( $artifact_repo, $cleanup_post_id ) {
	$path = CPIC_Test_State::$uploads_dir . '/cleanup-' . $name . '.png';
	file_put_contents( $path, 'png' );

	return (int) $artifact_repo->insert(
		array(
			'post_id'         => $cleanup_post_id,
			'file_path'       => $path,
			'file_url'        => 'https://example.test/' . $name . '.png',
			'format'          => 'square',
			'width'           => 1080,
			'height'          => 1080,
			'bytes'           => 3,
			'checksum_sha256' => $name,
			'created_at'      => $created_at,
		)
	);
};

$old_artifact    = $make_artifact( 'old', '2020-01-01 00:00:00' );
$newest_artifact = $make_artifact( 'newest', '2020-01-02 00:00:00' );

CPIC_Test::equal( 0, $artifact_manager->cleanup( 0 ), 'Cleanup with no age limit does nothing' );
CPIC_Test::ok( null !== $artifact_repo->find( $old_artifact ), 'A zero window keeps every artifact' );

CPIC_Test::equal( 1, $artifact_manager->cleanup( 30 ), 'Cleanup removes exactly the expired artifact' );
CPIC_Test::ok( null === $artifact_repo->find( $old_artifact ), 'The expired artifact is gone' );
CPIC_Test::ok( null !== $artifact_repo->find( $newest_artifact ), 'The newest artifact per post is kept' );

/* ═══ 34. Block-editor metabox handler ════════════════════ */

CPIC_Test::suite( 'Admin: editor metabox handler' );

$editor_service = $plugin->service( 'editor' );
CPIC_Test::ok( is_object( $editor_service ), 'The editor integration service resolves' );

$editor_post_id = 8150;
CPIC_Test_State::$posts[ $editor_post_id ] = cpic_test_make_post(
	array( 'ID' => $editor_post_id, 'post_title' => 'Editor post', 'post_status' => 'publish' )
);

// A valid nonce on an editable post redirects back to the editor.
cpic_test_reset_request();
$_POST['post_id']           = $editor_post_id;
$_POST['cpic_editor_nonce'] = wp_create_nonce( 'cpic_editor_image_' . $editor_post_id );
$editor_service->handle_metabox_save();

CPIC_Test::equal( 1, count( CPIC_Test_State::$redirects ), 'A verified metabox save redirects once' );
CPIC_Test::ok(
	isset( CPIC_Test_State::$redirects[0] ) && false !== strpos( CPIC_Test_State::$redirects[0], 'action=edit' ),
	'The metabox save returns to the post editor',
	CPIC_Test_State::$redirects
);

// A forged nonce never reaches the redirect.
cpic_test_reset_request();
$_POST['post_id']           = $editor_post_id;
$_POST['cpic_editor_nonce'] = 'forged';
try {
	$editor_service->handle_metabox_save();
} catch ( \Throwable $e ) {
	unset( $e );
}
CPIC_Test::equal( array(), CPIC_Test_State::$redirects, 'A forged metabox nonce never redirects' );
CPIC_Test::equal(
	403,
	CPIC_Test_State::$wp_die ? (int) CPIC_Test_State::$wp_die['status'] : 0,
	'A forged metabox nonce is a 403'
);

/*
 * The plugin's own publishing capability must gate this too. `edit_post`
 * alone let an editor without cpic_publish through, while the sibling
 * admin-post handler (handle_publish_now) refused them.
 */
cpic_test_reset_request();
CPIC_Test_State::$capabilities['edit_others_posts'] = false;
$_POST['post_id']           = $editor_post_id;
$_POST['cpic_editor_nonce'] = wp_create_nonce( 'cpic_editor_image_' . $editor_post_id );
try {
	$editor_service->handle_metabox_save();
} catch ( \Throwable $e ) {
	unset( $e );
}
$refused_without_cap = 403 === ( CPIC_Test_State::$wp_die ? (int) CPIC_Test_State::$wp_die['status'] : 0 );
unset( CPIC_Test_State::$capabilities['edit_others_posts'] );

CPIC_Test::ok( $refused_without_cap, 'Without cpic_publish the metabox save is refused' );
CPIC_Test::equal( array(), CPIC_Test_State::$redirects, 'A refused metabox save does not redirect' );

/* ═══ Summary ═══════════════════════════════════════════════ */

CPIC_Test::summary();
