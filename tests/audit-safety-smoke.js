/* Static guards for Unicode truncation, OAuth CSRF, UI escaping, and maintenance. */
'use strict';

const assert = require('assert');
const fs = require('fs');
const read = (name) => fs.readFileSync(require.resolve(name), 'utf8');

const facebook = read('../includes/class-facebook-api.php');
const telegram = read('../includes/class-telegram-api.php');
for (const [name, source] of [['Facebook', facebook], ['Telegram', telegram]]) {
    assert(source.includes("preg_match_all( '/\\X/u'"), `${name} caption truncation must use the PCRE grapheme-cluster escape`);
    assert(!source.includes("preg_match_all( '/\\\\X/u'"), `${name} must not over-escape \\X into a literal backslash pattern`);
}
assert(telegram.includes('DateTimeImmutable::createFromFormat( \'!Y-m-d H:i:s\', (string) $last, wp_timezone() )'),
    'Telegram duplicate-window comparison must parse saved site-local time in the WordPress timezone');

const adminUi = read('../includes/class-admin-ui.php');
const oauth = read('../includes/class-facebook-oauth.php');
const script = read('../js/cpic-script.js');
assert(adminUi.includes("'action' => 'cpic_fb_oauth_start', '_wpnonce' => wp_create_nonce( 'cpic_fb_oauth_start' )"), 'OAuth start URL must include a WordPress nonce without HTML-escaping its query separators');
assert(oauth.includes("check_admin_referer( 'cpic_fb_oauth_start' )"), 'OAuth start handler must verify its CSRF nonce');
assert(script.includes('window.open(cpicData.oauthStartUrl'), 'Facebook connect UI must use the protected OAuth URL');
assert(oauth.includes("'pages' => $stored_pages") && oauth.includes("'user_id' => get_current_user_id()"),
    'OAuth page access tokens must remain in a user-bound server transient');
assert(!oauth.includes('data-token=') && !oauth.includes('name="page_token"') && !oauth.includes("$_POST['page_token']"),
    'OAuth page selection must not put access tokens in the DOM or form body');
const settings = read('../includes/class-settings.php');
assert(settings.includes("unset( $settings['cpic_fb_app_secret'], $settings['cpic_fb_page_token'], $settings['cpic_tg_bot_token'] )"),
    'the settings read endpoint must omit secret credential values');
const facebookApi = read('../includes/class-facebook-api.php');
assert(facebookApi.includes("array( 'id' => $page['id'], 'name' => $page['name'] )") && facebookApi.includes("'selection_key' => $selection_key"),
    'the Facebook page-list endpoint must return names/IDs and a server-side token-selection handle, not page access tokens');
assert(facebookApi.includes("$_POST['selection_key']") && !facebookApi.includes("$_POST['page_token']"),
    'Facebook page selection must resolve page access tokens on the server');

assert(script.includes('escHtml(p.last_fb') && script.includes('escHtml(p.last_tg'), 'post metadata must be escaped before dashboard HTML insertion');
const jobsUi = read('../js/jobs.js');
assert(jobsUi.includes('safeHttpUrl(job.image_url)') && jobsUi.includes('escapeHtml(statusBadge(job.status))'),
    'job links and status labels must be scheme-checked and HTML-escaped');

const jobsHandler = adminUi.slice(adminUi.indexOf('public function ajax_get_jobs'), adminUi.indexOf('public function ajax_job_process'));
assert(!jobsHandler.includes('process_due_jobs'), 'loading the Jobs screen must not synchronously call remote publishers');
const automation = read('../includes/class-automation.php');
assert(automation.includes("wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'cpic_daily_maintenance' )"), 'daily maintenance must be scheduled');
assert(automation.includes('CPIC_Logger::prune( 30 )') && automation.includes('CPIC_Canvas_Artifacts::run_maintenance()'),
    'daily maintenance must bound log, archive, staging-chunk, and unreferenced-image growth');
console.log('PASS: caption Unicode, OAuth CSRF, admin UI escaping, async jobs, and daily maintenance guards.');
