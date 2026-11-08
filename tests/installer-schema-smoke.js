/* Regression guard for dbDelta-compatible schema creation on WordPress 5.8+. */
'use strict';

const assert = require('assert');
const fs = require('fs');
const installer = fs.readFileSync(require.resolve('../includes/class-installer.php'), 'utf8');

const statements = [...installer.matchAll(/CREATE TABLE\s+([^\s(]+)/g)];
assert.strictEqual(statements.length, 4, 'all four plugin tables must have a creation statement');
for (const statement of statements) {
    // WordPress 5.8 dbDelta parses `CREATE TABLE ([^ ]*)`; IF NOT EXISTS
    // makes it mistake IF for the table name and skip creation on fresh sites.
    assert.strictEqual(statement[1], '{$table}', 'dbDelta must see the actual interpolated table name immediately after CREATE TABLE');
}
assert.strictEqual((installer.match(/dbDelta\(\s*\$sql\s*\);/g) || []).length, 4,
    'each schema definition must be reconciled through dbDelta');
console.log('PASS: all four installer schemas use WordPress dbDelta-compatible CREATE TABLE syntax.');
