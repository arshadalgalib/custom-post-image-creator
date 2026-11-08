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
assert(installer.indexOf('if ( ! self::schema_is_ready() )') < installer.indexOf("update_option( 'cpic_db_version', CPIC_VERSION )"),
    'a failed table creation must not mark the schema version as current');
assert(installer.includes('|| ! self::schema_is_ready()'), 'an existing install with missing tables/columns must retry schema repair');
assert(installer.indexOf('! self::import_legacy_queue()') < installer.indexOf("update_option( 'cpic_db_version', CPIC_VERSION )"),
    'the database version must not advance while legacy queue import is incomplete');
console.log('PASS: dbDelta-compatible schemas and failed schema/data migrations remain retryable.');
