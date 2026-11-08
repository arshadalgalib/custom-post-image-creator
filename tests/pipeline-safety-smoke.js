/* Static regression guards for stale-image and canonical-artifact safety. */
'use strict';

const assert = require('assert');
const fs = require('fs');
const publisher = fs.readFileSync(require.resolve('../includes/class-publisher.php'), 'utf8');
const queue = fs.readFileSync(require.resolve('../includes/class-job-queue.php'), 'utf8');
const artifacts = fs.readFileSync(require.resolve('../includes/class-canvas-artifacts.php'), 'utf8');
const admin = fs.readFileSync(require.resolve('../includes/class-admin-ui.php'), 'utf8');
const uninstall = fs.readFileSync(require.resolve('../uninstall.php'), 'utf8');

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
assert(artifacts.includes('if ( is_link( $file ) ) { wp_delete_file( $file ); continue; }'),
    'artifact pruning must unlink symlinks instead of resolving and deleting their targets');
assert(admin.includes('if ( is_link( $staging_root ) )'), 'chunk uploads must reject a symlinked staging directory');
assert(uninstall.includes('if ( is_link( $path ) )') && uninstall.indexOf('if ( is_link( $path ) )') < uninstall.indexOf('$root = realpath( $path )'),
    'full uninstall must not traverse a symlinked plugin uploads directory');
console.log('PASS: publishing validates fresh artifacts and artifact cleanup preserves canonical and external files.');
