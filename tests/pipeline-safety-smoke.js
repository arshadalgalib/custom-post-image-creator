/* Static regression guards for stale-image and canonical-artifact safety. */
'use strict';

const assert = require('assert');
const fs = require('fs');
const publisher = fs.readFileSync(require.resolve('../includes/class-publisher.php'), 'utf8');
const queue = fs.readFileSync(require.resolve('../includes/class-job-queue.php'), 'utf8');

const stage2 = publisher.indexOf('Stage 2: Consume the current browser-created canonical PNG');
const stage3 = publisher.indexOf('Stage 3: Publish to Facebook');
assert(stage2 >= 0 && stage3 > stage2, 'canonical artifact validation must occur before publishing');
const artifactCheck = publisher.indexOf('CPIC_Canvas_Artifacts::current_for_post( $post_id )', stage2);
const facebookPublish = publisher.indexOf('facebook_api->publish_photo', stage3);
const telegramPublish = publisher.indexOf('telegram_api->share', stage3);
assert(artifactCheck > stage2 && artifactCheck < facebookPublish,
    'an unposted job must validate the current artifact before Facebook publishing');
assert(artifactCheck < telegramPublish,
    'an unposted job must validate the current artifact before Telegram publishing');
assert(publisher.includes('$has_destination_side_effect'),
    'partial jobs must preserve their original canonical PNG across destinations');

const deleteMethod = queue.slice(queue.indexOf('public function delete_job'), queue.indexOf('public function delete_jobs_for_post'));
assert(deleteMethod.includes('reference-aware artifact pruner'), 'job deletion must leave canonical artifact reclamation to the safe pruner');
assert(!deleteMethod.includes('wp_delete_file'), 'deleting a job must not remove a post-referenced canonical PNG');
console.log('PASS: publishing validates fresh artifacts and job deletion preserves canonical PNGs.');
