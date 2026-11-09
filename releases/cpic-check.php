<?php
/**
 * Standalone self-check for Custom Post Image Creator.
 *
 * Upload this file to your WordPress root (next to wp-config.php) and open
 * https://your-site/cpic-check.php in a browser. It loads WordPress, then the
 * plugin, and reports exactly what fails - without needing the plugin to be
 * active or the admin area to work.
 *
 * DELETE IT AFTERWARDS. It reveals server paths to anyone who visits it.
 *
 * @package CustomPostImageCreator
 */

header( 'Content-Type: text/plain; charset=utf-8' );

// In check mode the harness has already loaded WordPress.
$cpic_check_mode = defined( 'CPIC_CHECK_MODE' ) && CPIC_CHECK_MODE;

if ( ! $cpic_check_mode ) {
	if ( ! file_exists( __DIR__ . '/wp-load.php' ) ) {
		echo "Put this file in your WordPress root (where wp-config.php lives).\n";
		exit( 1 );
	}

	require __DIR__ . '/wp-load.php';
}

// Test seam: the harness pre-loads WordPress and points the checker at the
// plugin without needing a real wp-content tree.
if ( defined( 'CPIC_CHECK_MODE' ) && CPIC_CHECK_MODE ) {
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) );
	}
}

echo "Custom Post Image Creator - self check\n";
echo str_repeat( '=', 46 ) . "\n\n";

printf( "PHP:        %s\n", PHP_VERSION );
printf( "WordPress:  %s\n", get_bloginfo( 'version' ) );
printf( "Plugin dir: %s\n", defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '?' );
echo "\n";

$dir = $cpic_check_mode
	? ( getenv( 'CPIC_PLUGIN_ROOT' ) ? rtrim( (string) getenv( 'CPIC_PLUGIN_ROOT' ), '/' ) : dirname( __DIR__, 2 ) )
	: WP_PLUGIN_DIR . '/custom-post-image-creator';

if ( ! is_dir( $dir ) ) {
	echo "!! The plugin folder is not at wp-content/plugins/custom-post-image-creator\n";
	echo "   If you renamed it, this check cannot run.\n";
	exit( 1 );
}

$file = $dir . '/custom-post-image-creator.php';

if ( ! file_exists( $file ) ) {
	echo "!! custom-post-image-creator.php is missing from the plugin folder.\n";
	exit( 1 );
}

// ── Files the plugin cannot work without ─────────────────────────────
echo "Required files:\n";

$required = array(
	'custom-post-image-creator.php',
	'uninstall.php',
	'src/Core/Autoloader.php',
	'src/Core/Plugin.php',
	'src/Core/Container.php',
	'src/Core/ModuleManager.php',
	'src/Publishing/PublishResult.php',
	'src/Support/Compat/bootstrap.php',
	'src/Support/Compat/TemplateManagerAdapter.php',
	'fonts/HindSiliguri-Regular.ttf',
);

// Assets are only asserted on a real site; the harness does not copy them.
if ( ! $cpic_check_mode ) {
	$required[] = 'resources/js/studio.js';
	$required[] = 'resources/css/admin.css';
}

$missing = 0;
foreach ( $required as $relative ) {
	$ok = file_exists( $dir . '/' . $relative );
	printf( "  %s %s\n", $ok ? '[ok]  ' : '[MISS]', $relative );
	$missing += $ok ? 0 : 1;
}

echo "\n";

if ( $missing > 0 ) {
	echo "!! {$missing} file(s) missing. The upload was incomplete - extract the ZIP again.\n";
	exit( 1 );
}

// ── Load the plugin and catch whatever it throws ─────────────────────
echo "Loading the plugin:\n";

try {
	require $file;
	echo "  [ok]   plugin file loaded\n";
} catch ( \Throwable $e ) {
	echo "  [FATAL] " . get_class( $e ) . ': ' . $e->getMessage() . "\n";
	echo '          at ' . $e->getFile() . ':' . $e->getLine() . "\n";
	exit( 1 );
}

if ( ! function_exists( 'cpic' ) ) {
	echo "  [MISS] cpic() was not defined\n";
	exit( 1 );
}

echo "  [ok]   cpic() defined\n";

$plugin = cpic();

if ( ! $plugin instanceof \CPIC\Core\Plugin ) {
	echo "  [warn] cpic() did not return the plugin object\n";
} else {
	echo "  [ok]   plugin object created\n";
}

// ── Module loading ───────────────────────────────────────────────────
echo "\nModules:\n";

$failures = method_exists( $plugin, 'load_failures' ) ? $plugin->load_failures() : array();

if ( empty( $failures ) ) {
	echo "  [ok]   every module loaded\n";
} else {
	foreach ( $failures as $id => $failure ) {
		if ( is_array( $failure ) ) {
			printf(
				"  [FAIL] %s during %s: %s %s\n",
				$id,
				isset( $failure['phase'] ) ? $failure['phase'] : 'boot',
				isset( $failure['message'] ) ? $failure['message'] : '',
				isset( $failure['file'] ) ? $failure['file'] : ''
			);
		} else {
			printf( "  [FAIL] %s: %s\n", $id, (string) $failure );
		}
	}
}

// ── Services ─────────────────────────────────────────────────────────
echo "\nServices:\n";

foreach ( array( 'settings', 'migrator', 'capabilities', 'queue', 'render', 'templates' ) as $id ) {
	try {
		echo null === $plugin->service( $id ) ? "  [MISS] {$id}\n" : "  [ok]   {$id}\n";
	} catch ( \Throwable $e ) {
		echo "  [FATAL] {$id}: " . $e->getMessage() . "\n";
	}
}

// ── Database ─────────────────────────────────────────────────────────
global $wpdb;

echo "\nDatabase:\n";

if ( empty( $wpdb->last_error ) ) {
	echo "  [ok]   WordPress connected\n";
} else {
	echo "  [FAIL] " . $wpdb->last_error . "\n";
}

$server = $wpdb->get_var( 'SELECT VERSION()' );
$is_maria = is_string( $server ) && false !== stripos( $server, 'mariadb' );
printf( "  %s %s version: %s\n", '', $is_maria ? 'MariaDB' : 'MySQL', $server ? $server : 'unknown' );

// Five tables use two CURRENT_TIMESTAMP columns, which needs MySQL 5.6.5+.
$modern = true;
if ( is_string( $server ) && preg_match( '/(\d+)\.(\d+)\.(\d+)/', $server, $m ) ) {
	$modern = version_compare( $m[1] . '.' . $m[2] . '.' . $m[3], '5.6.5', '>=' ) || $is_maria;
}
printf( "  %s CURRENT_TIMESTAMP support: %s\n", $modern ? '[ok]  ' : '[FAIL]', $modern ? 'yes' : 'NO - tables cannot be created' );

$table = $wpdb->prefix . 'cpic_templates';
$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

echo $exists
	? "  [ok]   {$table} exists (the plugin has been activated)\n"
	: "  [info] {$table} does not exist yet (normal before activation)\n";

$state = (string) get_option( 'cpic_installation_complete' );

if ( 'yes' === $state ) {
	echo "  [ok]   installation marked complete\n";
} elseif ( 'partial' === $state ) {
	echo "  [FAIL] activation was interrupted - tables or roles may be missing\n";
	$pending = get_option( 'cpic_activation_pending' );
	echo '         unfinished steps: ' . ( is_array( $pending ) ? implode( ', ', $pending ) : 'unknown' ) . "\n";
	echo "         load any wp-admin page while the plugin is active and it will finish itself\n";
} else {
	echo "  [info] installation not recorded yet\n";
}

$stored = get_option( 'cpic_activation_errors' );

if ( is_array( $stored ) && ! empty( $stored['errors'] ) ) {
	echo "\nRecorded failures:\n";
	foreach ( (array) $stored['errors'] as $step => $message ) {
		$text = is_array( $message ) ? implode( ' ', $message ) : (string) $message;
		echo "  [FAIL] {$step}: {$text}\n";
	}
}

// ── Extensions that change behaviour ─────────────────────────────────
echo "\nPHP extensions:\n";

foreach ( array( 'openssl' => 'credential encryption', 'mbstring' => 'Bengali text', 'gd' => 'server-side images', 'curl' => 'publishing' ) as $ext => $why ) {
	printf( "  %s %-9s (%s)\n", extension_loaded( $ext ) ? '[ok]  ' : '[warn]', $ext, $why );
}

// ── Server notes ─────────────────────────────────────────────────────
echo "\nServer:\n";
printf( "  memory_limit:      %s\n", ini_get( 'memory_limit' ) );
printf( "  max_execution_time: %s\n", ini_get( 'max_execution_time' ) );
printf( "  DISABLE_WP_CRON:   %s\n", defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'yes' : 'no' );

echo "\nDone. Delete this file when you are finished.\n";
