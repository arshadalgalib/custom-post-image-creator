/* Static regression guard for WordPress inline-data ordering on Canvas screens. */
'use strict';

const assert = require('assert');
const fs = require('fs');
const source = fs.readFileSync(require.resolve('../includes/class-admin-ui.php'), 'utf8');
const dataBlock = source.indexOf('$common_data = array(');
const editorBranch = source.indexOf('if ( ! $is_dashboard ) {');
assert(dataBlock >= 0 && editorBranch > dataBlock, 'shared cpicData must exist before the editor early-return');
assert.strictEqual((source.match(/wp_localize_script\( 'cpic-canvas', 'cpicData', \$common_data \);/g) || []).length, 3,
    'editor, dashboard, and template screens must localize cpicData on the Canvas dependency');

const canvasEnqueue = /wp_enqueue_script\( 'cpic-canvas',[\s\S]*?\);\s*wp_localize_script\( 'cpic-canvas', 'cpicData', \$common_data \);/g;
assert.strictEqual((source.match(canvasEnqueue) || []).length, 3,
    'each Canvas screen must enqueue its handle before attaching the configuration');
console.log('PASS: shared Canvas data is defined early and localized on all three bootstrapping screens.');
