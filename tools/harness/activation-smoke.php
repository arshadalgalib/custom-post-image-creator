<?php
/**
 * Activation smoke test.
 *
 * Simulates what WordPress does on the Plugins screen: load the real
 * bootstrap file (custom-post-image-creator.php) from a fresh process, fire the
 * registered activation hook, then assert the site is actually set up.
 *
 * This differs from the unit suite on purpose:
 *  - the unit suite builds the plugin via the autoloader directly;
 *  - this one goes through the shipped entry file, like a real activation.
 *
 * @package CustomPostImageCreator
 */

require dirname( __DIR__, 2 ) . '/tests/php/bootstrap.php';

$cpic_problems = array();

/**
 * Record a problem instead of throwing, so one failure does not hide the rest.
 *
 * @param string $message Message.
 * @return void
 */
function cpic_smoke_fail( $message ) {
	global $cpic_problems;
	$cpic_problems[] = $message;
	echo "  ✗ {$message}\n";
}

/**
 * Record a pass.
 *
 * @param string $message Message.
 * @return void
 */
function cpic_smoke_pass( $message ) {
	echo "  ✓ {$message}\n";
}

echo "\n== Activation: fresh install ==\n";

// A fresh site: stubs + empty SQLite database, but the plugin is NOT built yet.
cpic_test_boot_db();

$GLOBALS['cpic_test_roles'] = array(
	'administrator' => array( 'manage_options' => true ),
	'editor'        => array(),
	'author'        => array(),
	'contributor'   => array(),
);

/**
 * Capture the activation callback registered by the plugin, the same way
 * `register_activation_hook()` stores it in the `active_plugins` flow.
 *
 * @return array<int,mixed>|null
 */
function cpic_smoke_activation_hook() {
	$hooks = isset( CPIC_Test_State::$hooks['__activate'] ) ? (array) CPIC_Test_State::$hooks['__activate'] : array();

	foreach ( $hooks as $entries ) {
		foreach ( (array) $entries as $entry ) {
			if ( isset( $entry[0] ) && is_array( $entry[0] ) && isset( $entry[0][1] ) && 'on_activate' === $entry[0][1] ) {
				return $entry[0];
			}
		}
	}

	return null;
}

// Load the shipped entry file, exactly like WordPress includes it.
// (The entry file defines these itself; define nothing ahead of it so a
// duplicate-constant bug would surface here.)
define( 'CPIC_ENTRY_FILE', dirname( __DIR__, 2 ) . '/custom-post-image-creator.php' );
require CPIC_ENTRY_FILE;

if ( ! function_exists( 'cpic' ) ) {
	cpic_smoke_fail( 'custom-post-image-creator.php did not define cpic()' );
	exit( 1 );
} else {
	cpic_smoke_pass( 'Entry file loads and defines cpic()' );
}

$plugin = cpic();

if ( ! $plugin instanceof \CPIC\Core\Plugin ) {
	cpic_smoke_fail( 'cpic() did not return the plugin object' );
} else {
	cpic_smoke_pass( 'cpic() returns the plugin singleton' );
}

// Every service the activation handler touches must resolve BEFORE any hook
// fires — this is the usual cause of "could not be activated" fatals.
foreach ( array( 'migrator', 'scheduler', 'capabilities', 'settings' ) as $cpic_id ) {
	$service = $plugin->service( $cpic_id );

	if ( null === $service ) {
		cpic_smoke_fail( sprintf( 'Activation service "%s" does not resolve', $cpic_id ) );
		continue;
	}

	cpic_smoke_pass( sprintf( 'Activation service "%s" resolves (%s)', $cpic_id, is_object( $service ) ? get_class( $service ) : gettype( $service ) ) );
}

// Fire the real activation hook.
$cpic_hook = cpic_smoke_activation_hook();

if ( null === $cpic_hook ) {
	cpic_smoke_fail( 'No activation hook was registered for on_activate()' );
} else {
	cpic_smoke_pass( 'Activation hook is registered' );
}

try {
	call_user_func( $cpic_hook );
	cpic_smoke_pass( 'Activation handler completes without a fatal error' );
} catch ( \Throwable $cpic_error ) {
	cpic_smoke_fail( 'Activation handler threw: ' . $cpic_error->getMessage() . ' @ ' . $cpic_error->getFile() . ':' . $cpic_error->getLine() );
}

echo "\n== Activation: database ==\n";

global $wpdb;

$cpic_tables = array();
foreach ( (array) $wpdb->get_results( "SELECT name FROM sqlite_master WHERE type = 'table'" ) as $cpic_row ) {
	$cpic_tables[] = is_array( $cpic_row ) ? ( $cpic_row['name'] ?? '' ) : ( $cpic_row->name ?? '' );
}

// Drop SQLite internals before comparing.
$cpic_tables = array_values( array_filter( $cpic_tables, static function ( $name ) {
	return 0 !== strpos( (string) $name, 'sqlite_' );
} ) );

$cpic_expected = array_values( \CPIC\Database\Schema::tables() );

if ( count( $cpic_tables ) === count( $cpic_expected ) ) {
	cpic_smoke_pass( sprintf( 'All %d schema tables exist', count( $cpic_expected ) ) );
} else {
	cpic_smoke_fail( sprintf( 'Table count mismatch: %d created, %d expected', count( $cpic_tables ), count( $cpic_expected ) ) );
}

foreach ( array( 'cpic_templates', 'cpic_artifacts', 'cpic_delivery_runs' ) as $cpic_table ) {
	$cpic_table = $wpdb->prefix . $cpic_table;
	if ( in_array( $cpic_table, $cpic_tables, true ) ) {
		cpic_smoke_pass( sprintf( 'Table %s exists', $cpic_table ) );
	} else {
		cpic_smoke_fail( sprintf( 'Table %s is missing', $cpic_table ) );
	}
}

echo "\n== Activation: options, capabilities, cron ==\n";

$cpic_option_count = count( \CPIC\Security\Settings::option_keys() );
$cpic_missing      = array();

foreach ( \CPIC\Security\Settings::option_keys() as $cpic_key ) {
	if ( null === get_option( $cpic_key, null ) ) {
		$cpic_missing[] = $cpic_key;
	}
}

if ( empty( $cpic_missing ) ) {
	cpic_smoke_pass( sprintf( 'All %d schema options were written', $cpic_option_count ) );
} else {
	cpic_smoke_fail( sprintf( '%d option(s) missing after activation: %s', count( $cpic_missing ), implode( ', ', array_slice( $cpic_missing, 0, 5 ) ) ) );
}

$cpic_admin_caps = isset( $GLOBALS['cpic_test_roles']['administrator'] ) ? (array) $GLOBALS['cpic_test_roles']['administrator'] : array();
$cpic_admin_cpic = array_filter( array_keys( $cpic_admin_caps ), static function ( $cap ) {
	return 0 === strpos( (string) $cap, 'cpic_' );
} );

if ( ! empty( $cpic_admin_cpic ) ) {
	cpic_smoke_pass( sprintf( 'Administrator received %d cpic_* capabilities', count( $cpic_admin_cpic ) ) );
} else {
	cpic_smoke_fail( 'Administrator did not receive any cpic_* capabilities' );
}

$cpic_crons = (array) CPIC_Test_State::$scheduled;
if ( ! empty( $cpic_crons ) ) {
	cpic_smoke_pass( sprintf( '%d cron event(s) scheduled', count( $cpic_crons ) ) );
} else {
	cpic_smoke_fail( 'No cron events were scheduled' );
}

// Re-activation must be idempotent: WordPress runs the hook again if the user
// deactivates and reactivates, and a duplicate table attempt can fatal.
echo "\n== Activation: idempotency ==\n";

try {
	call_user_func( $cpic_hook );
	cpic_smoke_pass( 'Second activation run does not fatal' );
} catch ( \Throwable $cpic_error ) {
	cpic_smoke_fail( 'Second activation run threw: ' . $cpic_error->getMessage() );
}

// A broken step must be recorded, not fatal.
echo "\n== Activation: failure isolation ==\n";

delete_option( 'cpic_activation_errors' );
$GLOBALS['cpic_test_fail_rewrite_flush'] = true;

try {
	call_user_func( $cpic_hook );
	cpic_smoke_pass( 'A throwing step does not fatal activation' );

	$cpic_errors = get_option( 'cpic_activation_errors' );

	if ( ! is_array( $cpic_errors ) || empty( $cpic_errors['errors'] ) ) {
		cpic_smoke_fail( 'The failing step was not recorded for the admin notice' );
	} else {
		cpic_smoke_pass( 'The failed step is recorded (' . implode( ', ', array_keys( (array) $cpic_errors['errors'] ) ) . ')' );

		if ( isset( $cpic_errors['errors']['rewrites'] ) ) {
			cpic_smoke_pass( 'The recorded step names the actual failure' );
		} else {
			cpic_smoke_fail( 'The recorded error does not name the rewrites step' );
		}
	}
} catch ( \Throwable $cpic_error ) {
	cpic_smoke_fail( 'Activation still fatals when a step throws: ' . $cpic_error->getMessage() );
}

// Recovery: once the host problem is gone, reactivation must clear the notice.
$GLOBALS['cpic_test_fail_rewrite_flush'] = false;

try {
	call_user_func( $cpic_hook );

	if ( false === get_option( 'cpic_activation_errors' ) ) {
		cpic_smoke_pass( 'A clean reactivation clears the recorded errors' );
	} else {
		cpic_smoke_fail( 'A clean reactivation left stale activation errors behind' );
	}
} catch ( \Throwable $cpic_error ) {
	cpic_smoke_fail( 'Recovery activation threw: ' . $cpic_error->getMessage() );
}

echo "\n";

if ( empty( $cpic_problems ) ) {
	echo "ACTIVATION OK\n";
	exit( 0 );
}

echo 'ACTIVATION FAILED: ' . count( $cpic_problems ) . " problem(s)\n";
exit( 1 );
