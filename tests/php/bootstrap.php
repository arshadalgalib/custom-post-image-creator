<?php
/**
 * Test bootstrap: boots the plugin inside the WordPress stub.
 *
 * @package CustomPostImageCreator
 */

// Absolute plugin root (passed by the harness).
$cpic_root = getenv( 'CPIC_ROOT' );
if ( ! $cpic_root ) {
	$cpic_root = dirname( __DIR__, 2 );
}
$cpic_root = rtrim( $cpic_root, '/' );

if ( ! defined( 'CPIC_TEST_ROOT' ) ) {
	define( 'CPIC_TEST_ROOT', $cpic_root );
}

/*
 * Admin-post handlers end in `exit`, which under php-wasm silently poisons
 * the interpreter for every later script in the session. The suite runs under
 * this flag so a handler returns instead, and its redirect plus side effects
 * can be asserted; production runs never define it, so nothing changes there.
 */
if ( ! defined( 'CPIC_TEST_ALLOW_EXIT' ) ) {
	define( 'CPIC_TEST_ALLOW_EXIT', true );
}

// WordPress stubs + SQLite $wpdb.
require_once __DIR__ . '/lib/wp-stubs.php';
require_once __DIR__ . '/lib/class-test-wpdb.php';

/**
 * Create a fresh SQLite database for the run.
 *
 * @return CPIC_Test_WPDB
 */
function cpic_test_boot_db() {
	global $wpdb;

	$dir = getenv( 'CPIC_TEST_TMP' ) ? getenv( 'CPIC_TEST_TMP' ) : sys_get_temp_dir() . '/cpic-tests';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}

	CPIC_Test_State::$uploads_dir = $dir . '/uploads';
	if ( ! is_dir( CPIC_Test_State::$uploads_dir ) ) {
		mkdir( CPIC_Test_State::$uploads_dir, 0777, true );
	}

	cpic_test_prepare_wp_tree();

	$file = $dir . '/test.sqlite';
	if ( file_exists( $file ) ) {
		unlink( $file );
	}

	$pdo  = new PDO( 'sqlite:' . $file );
	$wpdb = new CPIC_Test_WPDB( $pdo ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride

	return $wpdb;
}

/**
 * Boot the plugin.
 *
 * @return \CPIC\Core\Plugin
 */
function cpic_test_boot() {
	global $wpdb;

	$cpic_root = CPIC_TEST_ROOT;

	cpic_test_boot_db();

	// Roles used by the capabilities installer.
	$GLOBALS['cpic_test_roles'] = array(
		'administrator' => array(),
		'editor'        => array(),
		'author'        => array(),
		'contributor'   => array(),
	);

	// The default test user is an administrator.
	CPIC_Test_State::$user_id = 1;
	CPIC_Test_State::$capabilities = array();

	CPIC_Test_State::$options['gmt_offset'] = 6;
	CPIC_Test_State::$options['date_format'] = 'F j, Y';

	if ( ! defined( 'CPIC_PLUGIN_FILE' ) ) {
		define( 'CPIC_PLUGIN_FILE', $cpic_root . '/custom-post-image-creator.php' );
	}
	if ( ! defined( 'CPIC_PLUGIN_BASENAME' ) ) {
		define( 'CPIC_PLUGIN_BASENAME', 'custom-post-image-creator/custom-post-image-creator.php' );
	}

	require_once $cpic_root . '/src/Core/Autoloader.php';
	\CPIC\Core\Autoloader::register();

	return \CPIC\Core\Plugin::instance();
}

/**
 * Simple assertion helpers.
 */
final class CPIC_Test {

	/** @var int */
	public static $passed = 0;

	/** @var array<int,string> */
	public static $failures = array();

	/** @var string */
	public static $suite = '';

	/**
	 * Start a suite.
	 *
	 * @param string $name Name.
	 * @return void
	 */
	public static function suite( $name ) {
		self::$suite = $name;
		echo "\n== " . $name . " ==\n";
	}

	/**
	 * Assert a condition.
	 *
	 * @param bool   $condition Condition.
	 * @param string $message   Message.
	 * @param mixed  $context   Context for failures.
	 * @return bool
	 */
	public static function ok( $condition, $message, $context = null ) {
		if ( $condition ) {
			self::$passed++;
			echo "  ✓ " . $message . "\n";
			return true;
		}

		$detail = null === $context ? '' : ' :: ' . ( is_scalar( $context ) ? (string) $context : json_encode( $context ) );
		self::$failures[] = self::$suite . ' — ' . $message . $detail;
		echo "  ✗ " . $message . $detail . "\n";

		return false;
	}

	/**
	 * Assert equality.
	 *
	 * @param mixed  $expected Expected.
	 * @param mixed  $actual   Actual.
	 * @param string $message  Message.
	 * @return bool
	 */
	public static function equal( $expected, $actual, $message ) {
		return self::ok( $expected === $actual, $message, array( 'expected' => $expected, 'actual' => $actual ) );
	}

	/**
	 * Assert a value is an error with a given code.
	 *
	 * @param mixed  $value Value.
	 * @param string $code  Expected code ('' = any).
	 * @param string $message Message.
	 * @return bool
	 */
	public static function is_error( $value, $code = '', $message = '' ) {
		if ( ! $value instanceof WP_Error ) {
			return self::ok( false, $message . ' (not a WP_Error)', gettype( $value ) );
		}
		if ( '' === $code ) {
			return self::ok( true, $message );
		}
		return self::ok( $value->get_error_code() === $code, $message, array( 'expected' => $code, 'actual' => $value->get_error_code() . ': ' . $value->get_error_message() ) );
	}

	/**
	 * Summary + exit code.
	 *
	 * @return void
	 */
	public static function summary() {
		echo "\n";
		echo 'PASSED: ' . self::$passed . "\n";
		echo 'FAILED: ' . count( self::$failures ) . "\n";

		foreach ( self::$failures as $failure ) {
			echo '  ✗ ' . $failure . "\n";
		}

		echo count( self::$failures ) === 0 ? "\nALL TESTS PASSED\n" : "\nTESTS FAILED\n";
	}
}
