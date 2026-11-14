<?php
/**
 * PHP compatibility scan.
 *
 * The verification VM only has PHP 8.0-8.4, so `token_get_all()` cannot reveal
 * syntax that is valid on 8.x but a parse error on the 7.4 floor this plugin
 * declares. This walks the token stream looking for every construct that was
 * introduced after 7.4, plus every runtime function that does not exist on
 * older builds.
 *
 * Run with: node smoke.mjs tools/harness/php-compat.php
 *
 * @package CustomPostImageCreator
 */

$cpic_root = getenv( 'CPIC_ROOT' ) ? rtrim( getenv( 'CPIC_ROOT' ), '/' ) : dirname( __DIR__, 2 );

/* Constructs that need more than PHP 7.4. */
$cpic_bad_tokens = array(
	T_MATCH                      => 'match expression (8.0)',
	T_ENUM                       => 'enum (8.1)',
	T_READONLY                   => 'readonly (8.1)',
	T_ATTRIBUTE                  => 'attribute (8.0)',
	T_NULLSAFE_OBJECT_OPERATOR   => 'nullsafe operator ?-> (8.0)',
);

/* Runtime functions that need more than PHP 7.4. */
$cpic_bad_functions = array(
	'str_contains'         => '8.0',
	'str_starts_with'      => '8.0',
	'str_ends_with'        => '8.0',
	'get_debug_type'       => '8.0',
	'fdiv'                 => '8.0',
	'preg_last_error_msg'  => '8.0',
	'array_is_list'        => '8.1',
	'enum_exists'          => '8.1',
	'array_find'           => '8.4',
	'array_any'            => '8.4',
	'array_all'            => '8.4',
	'mb_str_split'         => '7.4',
	'str_contains_ci'      => '8.0',
);

/**
 * Meaningful tokens only, so "previous token" questions are answerable.
 *
 * @param string $code Source.
 * @return array<int,array{0:int|string,1:string,2:int}> Tokens.
 */
function cpic_compat_tokens( $code ) {
	$raw = token_get_all( $code, TOKEN_PARSE );
	$out = array();

	foreach ( $raw as $token ) {
		if ( is_string( $token ) ) {
			$out[] = array( $token, $token, 0 );
			continue;
		}

		if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		$out[] = array( $token[0], $token[1], $token[2] );
	}

	return $out;
}

/**
 * Previous meaningful token.
 *
 * @param array<int,array{0:int|string,1:string,2:int}> $tokens Tokens.
 * @param int                                           $index  Index.
 * @return array{0:int|string,1:string,2:int}|null
 */
function cpic_compat_prev( array $tokens, $index ) {
	return $index > 0 && isset( $tokens[ $index - 1 ] ) ? $tokens[ $index - 1 ] : null;
}

$cpic_files = array();
$iterator   = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $cpic_root, FilesystemIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	$path = $file->getPathname();

	if ( false !== strpos( $path, '/node_modules/' ) || false !== strpos( $path, '/tests/' ) || false !== strpos( $path, '/tools/' ) ) {
		continue;
	}

	$cpic_files[] = $path;
}

$cpic_problems = array();
$cpic_scanned  = 0;

foreach ( $cpic_files as $path ) {
	$code = (string) file_get_contents( $path );

	try {
		$tokens = cpic_compat_tokens( $code );
	} catch ( \Throwable $e ) {
		$cpic_problems[] = str_replace( $cpic_root, '', $path ) . ' — does not parse: ' . $e->getMessage();
		continue;
	}

	$cpic_scanned++;
	$count = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		$where = str_replace( $cpic_root, '', $path ) . ':' . $token[2];

		/* ── Post-7.4 syntax ─────────────────────────────── */
		if ( is_int( $token[0] ) && isset( $cpic_bad_tokens[ $token[0] ] ) ) {
			$cpic_problems[] = $where . ' — ' . $cpic_bad_tokens[ $token[0] ];
			continue;
		}

		/* ── Non-capturing catch: catch (Type) ───────────── */
		if ( T_CATCH === $token[0] ) {
			// tokens: catch ( Type $var )  vs  catch ( Type )
			$depth  = 0;
			$hasvar = false;

			for ( $j = $i + 1; $j < $count; $j++ ) {
				$t = $tokens[ $j ];
				if ( '(' === $t[0] ) {
					$depth++;
				} elseif ( ')' === $t[0] ) {
					$depth--;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( T_VARIABLE === $t[0] ) {
					$hasvar = true;
				}
			}

			if ( ! $hasvar ) {
				$cpic_problems[] = $where . ' — non-capturing catch (8.0)';
			}
			continue;
		}

		/* ── Union / intersection types in signatures ───── */
		if ( T_FUNCTION === $token[0] ) {
			$depth       = 0;
			$in_signature = false;

			for ( $j = $i + 1; $j < $count; $j++ ) {
				$t = $tokens[ $j ];

				if ( '(' === $t[0] || ')' === $t[0] ) {
					$in_signature = true;
				}

				if ( '{' === $t[0] && $in_signature ) {
					break;
				}

				if ( in_array( $t[0], array( T_STRING, T_ARRAY, T_CALLABLE ), true ) && isset( $tokens[ $j + 1 ] ) && '|' === $tokens[ $j + 1 ][0] ) {
					$cpic_problems[] = $where . ' — union type (8.0)';
					break;
				}

				if ( T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG === ( is_int( $t[0] ) ? $t[0] : -1 ) ) {
					if ( isset( $tokens[ $j + 1 ] ) && in_array( $tokens[ $j + 1 ][0], array( T_STRING, T_ARRAY ), true ) ) {
						$cpic_problems[] = $where . ' — intersection type (8.1)';
						break;
					}
				}
			}
			continue;
		}

		/* ── Constructor property promotion (8.0) ────────── */
		if ( T_FUNCTION === $token[0] && isset( $tokens[ $i + 2 ] ) && T_STRING === $tokens[ $i + 2 ][0] && '__construct' === $tokens[ $i + 2 ][1] ) {
			for ( $j = $i + 3; $j < $count; $j++ ) {
				$t = $tokens[ $j ];
				if ( '{' === $t[0] ) {
					break;
				}
				if ( in_array( $t[0], array( T_PUBLIC, T_PRIVATE, T_PROTECTED ), true ) ) {
					$cpic_problems[] = $where . ' — constructor property promotion (8.0)';
					break;
				}
			}
			continue;
		}

		/* ── Version-specific runtime functions ──────────── */
		if ( T_STRING === $token[0] && isset( $cpic_bad_functions[ $token[1] ] ) ) {
			$prev = cpic_compat_prev( $tokens, $i );
			$next = isset( $tokens[ $i + 1 ] ) ? $tokens[ $i + 1 ] : null;

			// Ignore method calls / declarations.
			if ( $prev && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
				continue;
			}
			if ( $next && '(' === $next[0] ) {
				$cpic_problems[] = $where . ' — ' . $token[1] . '() needs PHP ' . $cpic_bad_functions[ $token[1] ];
			}
			continue;
		}

		/* ── First-class callable syntax: foo(...) ───────── */
		if ( T_ELLIPSIS === $token[0] ) {
			$prev = cpic_compat_prev( $tokens, $i );
			$next = isset( $tokens[ $i + 1 ] ) ? $tokens[ $i + 1 ] : null;

			if ( $next && ')' === $next[0] && $prev && in_array( $prev[0], array( T_STRING, T_VARIABLE ), true ) ) {
				$cpic_problems[] = $where . ' — first-class callable syntax (8.1)';
			}
			continue;
		}

	}
}

echo 'scanned=' . $cpic_scanned . ' files, problems=' . count( $cpic_problems ) . "\n";

if ( empty( $cpic_problems ) ) {
	echo "PHP 7.4 COMPATIBLE\n";
	exit( 0 );
}

foreach ( $cpic_problems as $problem ) {
	echo '  ! ' . $problem . "\n";
}

exit( 1 );
