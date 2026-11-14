/**
 * Renders every admin screen against the real services and a seeded database,
 * so a template cannot ship broken. Development only.
 *
 * Usage: node tools/harness/render-admin-screens.mjs [repoRoot]
 */

import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createPluginSession, VM_ROOT } from './lib/php-runner.mjs';

const here = fileURLToPath(new URL('.', import.meta.url));
const repoRoot = (process.argv[2] || join(here, '..', '..')).replace(/\/+$/, '');

const session = await createPluginSession(repoRoot);

// No opening tag: the session prelude already opened PHP.
const script = `require '${VM_ROOT}/tests/php/bootstrap.php';

$GLOBALS['cpic'] = cpic_test_boot();
$cpic = $GLOBALS['cpic'];
$cpic->service( 'migrator' )->install();

/* ── Seed realistic content ─────────────────────────────────────── */

$post_id = 501;
CPIC_Test_State::$posts[ $post_id ] = cpic_test_make_post(
	array(
		'ID'           => $post_id,
		'post_title'   => 'বাংলাদেশের অর্থনীতি ঘুরে দাঁড়াচ্ছে',
		'post_content' => '<p>বাংলাদেশ ব্যাংকের তথ্য অনুযায়ী রপ্তানি আয় বেড়েছে।</p>',
		'post_excerpt' => 'রপ্তানি আয় বৃদ্ধি পেয়েছে।',
	)
);

$accounts = $cpic->service( 'accounts' );
$account  = $accounts->create(
	array(
		'provider'    => 'telegram',
		'label'       => 'Main channel',
		'account_id'  => '-1001234567890',
		'credentials' => array( 'bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11' ),
		'config'      => array(),
	)
);

$campaigns = $cpic->service( 'campaign_manager' );
$campaign  = $campaigns->create(
	array(
		'name'     => 'Budget coverage',
		'start_at' => current_time( 'mysql' ),
		'end_at'   => gmdate( 'Y-m-d H:i:s', time() + ( 86400 * 7 ) ),
	)
);

if ( ! is_wp_error( $campaign ) ) {
	$campaigns->assign_post( (int) $campaign['id'], $post_id );
}

$cpic->service( 'queue' )->enqueue_publish( $post_id, array(), 3600 );
$cpic->service( 'logger' )->info( 'publish', 'Test log entry rendered by the screen harness.', array( 'post_id' => $post_id ) );

echo 'seed account=' . ( is_wp_error( $account ) ? $account->get_error_message() : (int) $account['id'] ) . "\\n";

/* ── Render every screen through Admin::render_* ────────────────── */

$admin  = $cpic->service( 'admin' );
$screens = array(
	'dashboard'   => 'render_dashboard',
	'templates'   => 'render_templates',
	'brands'      => 'render_brands',
	'accounts'    => 'render_accounts',
	'campaigns'   => 'render_campaigns',
	'calendar'    => 'render_calendar',
	'queue'       => 'render_queue',
	'analytics'   => 'render_analytics',
	'settings'    => 'render_settings',
	'diagnostics' => 'render_diagnostics',
	'studio'      => 'render_studio',
);

$failures = 0;

foreach ( $screens as $view => $method ) {
	$level = ob_get_level();

	ob_start();
	try {
		$admin->{$method}();
		$html = ob_get_clean();
	} catch ( \\Throwable $e ) {
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		$failures++;
		echo 'FAIL ' . $view . ' :: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\\n";
		continue;
	}

	$problems = array();

	if ( strlen( $html ) < 400 ) {
		$problems[] = 'suspiciously short (' . strlen( $html ) . ' bytes)';
	}
	if ( false !== strpos( $html, 'screen is unavailable' ) ) {
		$problems[] = 'view file missing';
	}
	foreach ( array( 'Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Undefined' ) as $needle ) {
		if ( false !== strpos( $html, $needle ) ) {
			$problems[] = 'PHP diagnostic leaked: ' . $needle;
		}
	}

	if ( $problems ) {
		$failures++;
		echo 'FAIL ' . $view . ' :: ' . implode( ', ', $problems ) . "\\n";
		continue;
	}

	echo 'OK   ' . $view . ' (' . strlen( $html ) . " bytes)\\n";
}

echo $failures ? 'SCREENS FAILED ' . $failures . "\\n" : "ALL SCREENS RENDERED\\n";
`;

const [exitCode, output] = await session.run(script);

process.stdout.write(output);
process.exit(exitCode === 0 && /ALL SCREENS RENDERED/.test(output) ? 0 : 1);
