<?php
/**
 * Static integrity check for the shipped plugin.
 *
 * Two guarantees:
 *  1. every `CPIC\...` class declared in `src/` maps to a file the autoloader
 *     can actually load (a missing file is a guaranteed activation fatal);
 *  2. every container service id resolves (a broken binding is a fatal the
 *     moment the activation hook touches it).
 *
 * No regex archaeology: the tokenizer finds declarations, then real autoloading
 * proves they work.
 *
 * @package CustomPostImageCreator
 */

$cpic_root = getenv( 'CPIC_ROOT' ) ? rtrim( getenv( 'CPIC_ROOT' ), '/' ) : dirname( __DIR__, 2 );

// Every shipped file bails out without ABSPATH, and the autoloader needs
// CPIC_PLUGIN_DIR at runtime.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/cpic-wp/' );
}
if ( ! defined( 'CPIC_PLUGIN_DIR' ) ) {
	define( 'CPIC_PLUGIN_DIR', $cpic_root . '/' );
}

require_once $cpic_root . '/src/Core/Autoloader.php';

/**
 * Declared classes, interfaces and traits in a single file.
 *
 * @param string $path File.
 * @return array<int,string> Fully-qualified names.
 */
function cpic_declared_symbols( $path ) {
	$tokens = token_get_all( (string) file_get_contents( $path ), TOKEN_PARSE );
	$out    = array();
	$ns     = '';
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) ) {
			continue;
		}

		if ( T_NAMESPACE === $token[0] ) {
			$ns  = '';
			$j   = $i + 1;
			while ( isset( $tokens[ $j ] ) ) {
				$t = $tokens[ $j ];
				if ( is_string( $t ) && ( ';' === $t || '{' === $t ) ) {
					break;
				}
				if ( is_array( $t ) && in_array( $t[0], array( T_STRING, T_NAME_QUALIFIED ), true ) ) {
					$ns .= $t[1];
				}
				$j++;
			}
			continue;
		}

		if ( ! in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
			continue;
		}

		// Skip `::class` and anonymous classes.
		if ( isset( $tokens[ $i - 1 ] ) && is_array( $tokens[ $i - 1 ] ) && T_DOUBLE_COLON === $tokens[ $i - 1 ][0] ) {
			continue;
		}

		for ( $j = $i + 1; $j < $count && $j < $i + 4; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
				$out[] = ( '' !== $ns ? $ns . '\\' : '' ) . $tokens[ $j ][1];
				break;
			}
		}
	}

	return $out;
}

/* ─── 1. Class files ─────────────────────────────────────────────── */

$cpic_symbols = array();
$cpic_files   = 0;

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $cpic_root . '/src', FilesystemIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	$cpic_files++;

	foreach ( cpic_declared_symbols( $file->getPathname() ) as $symbol ) {
		$cpic_symbols[ $symbol ] = str_replace( $cpic_root, '', $file->getPathname() );
	}
}

echo 'src files=' . $cpic_files . ' symbols=' . count( $cpic_symbols ) . "\n";

\CPIC\Core\Autoloader::register();

$cpic_broken = array();

foreach ( $cpic_symbols as $symbol => $where ) {
	try {
		$loaded = class_exists( $symbol ) || interface_exists( $symbol ) || trait_exists( $symbol );
	} catch ( \Throwable $e ) {
		$cpic_broken[ $symbol ] = $where . ' — threw ' . $e->getMessage();
		continue;
	}

	if ( ! $loaded ) {
		$cpic_broken[ $symbol ] = $where . ' — autoloader returned no such class';
	}
}

/* ─── 2. Container services ──────────────────────────────────────── */

$cpic_services = array();
$cpic_service_failures = array();

if ( file_exists( $cpic_root . '/tests/php/bootstrap.php' ) ) {
	// Boot through the real container using the test stubs.
	require_once $cpic_root . '/tests/php/bootstrap.php';
	$cpic_plugin = cpic_test_boot();

	$cpic_ids = method_exists( $cpic_plugin->container(), 'ids' ) ? $cpic_plugin->container()->ids() : array();

	foreach ( (array) $cpic_ids as $cpic_id ) {
		try {
			$cpic_value = $cpic_plugin->container()->get( $cpic_id );
			$cpic_services[ $cpic_id ] = is_object( $cpic_value ) ? get_class( $cpic_value ) : gettype( $cpic_value );
		} catch ( \Throwable $e ) {
			$cpic_service_failures[ $cpic_id ] = $e->getMessage();
		}
	}

	echo 'services=' . count( $cpic_services ) . ' failed=' . count( $cpic_service_failures ) . "\n";
} else {
	echo "services=skipped (no test bootstrap in this tree)\n";
}

/* ─── Report ─────────────────────────────────────────────────────── */

if ( empty( $cpic_broken ) && empty( $cpic_service_failures ) ) {
	echo "INTEGRITY OK\n";
	exit( 0 );
}

foreach ( $cpic_broken as $symbol => $detail ) {
	echo '  ! class ' . $symbol . ' (' . $detail . ")\n";
}
foreach ( $cpic_service_failures as $id => $detail ) {
	echo '  ! service ' . $id . ': ' . $detail . "\n";
}

echo 'INTEGRITY FAILED: ' . ( count( $cpic_broken ) + count( $cpic_service_failures ) ) . " problem(s)\n";
exit( 1 );
