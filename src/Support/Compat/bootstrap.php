<?php
/**
 * Shared helper for the 6.x class adapters.
 *
 * The adapters were once one physical file, which meant none of them could be
 * autoloaded: loading any single shim required guessing the filename of an
 * unrelated class. They are now one class per file (PSR-4), with this helper
 * kept separate so every adapter can require it safely.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Support\Compat;

use CPIC\Support\LegacyBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service access for the shims.
 *
 * @return LegacyBridge
 */
function bridge() {
	return \CPIC\Core\Plugin::instance()->service( 'legacy' );
}
