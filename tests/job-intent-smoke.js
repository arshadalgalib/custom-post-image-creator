/* Guard the immutable delivery intent captured by each queued job. */
'use strict';

const assert = require('assert');
const fs = require('fs');
const queue = fs.readFileSync(require.resolve('../includes/class-job-queue.php'), 'utf8');
const schema = fs.readFileSync(require.resolve('../includes/class-installer.php'), 'utf8');
const publisher = fs.readFileSync(require.resolve('../includes/class-publisher.php'), 'utf8');
const admin = fs.readFileSync(require.resolve('../includes/class-admin-ui.php'), 'utf8');
const jobsUi = fs.readFileSync(require.resolve('../js/jobs.js'), 'utf8');

const insertStart = queue.indexOf('$wpdb->insert(');
const insertEnd = queue.indexOf('if ( false === $inserted )', insertStart);
const insert = queue.slice(insertStart, insertEnd);
for (const intent of ['auto_facebook', 'auto_telegram', 'auto_comment']) {
    assert(insert.includes(`'${intent}'`), `new jobs must snapshot ${intent}`);
}
assert.strictEqual((insert.match(/'%[ds]'/g) || []).length, 13,
    'the insert format list must stay aligned with the job row fields');
assert(schema.includes('auto_comment TINYINT(1) NULL DEFAULT NULL') && schema.includes("'auto_comment'"),
    'schema creation and repair must include the nullable auto_comment snapshot');
assert(publisher.includes("isset( $job['auto_comment'] ) ? (int) $job['auto_comment']"),
    'publishing must use the captured comment intent, while legacy null rows retain the setting fallback');
for (const intent of ['auto_facebook', 'auto_telegram', 'auto_comment']) {
    assert(admin.includes(`'${intent}'`), `the Jobs API must expose ${intent}`);
}
assert(jobsUi.includes('function deliveryIntent(job)'), 'the Jobs UI must make the captured delivery intent visible');
console.log('PASS: new jobs capture deterministic channel/comment intent and old jobs remain compatible.');
