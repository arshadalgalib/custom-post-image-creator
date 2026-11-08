<?php
/**
 * Setup Guide – step-by-step instructions for configuring the plugin.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div id="cpic-setup-guide" class="wrap cpic-wrap">

    <h1>
        <span class="dashicons dashicons-sos" style="color:#2271b1;margin-right:6px;"></span>
        <?php esc_html_e( 'Setup Guide – Step by Step', 'custom-post-image-creator' ); ?>
    </h1>
    <p class="description" style="margin-bottom:20px;max-width:800px;">
        <?php esc_html_e( 'Follow these steps in order to get fully automatic photocard generation and social media publishing working.', 'custom-post-image-creator' ); ?>
    </p>

    <!-- ── STEP 1: Requirements ──────────────────────── -->
    <div class="cpic-guide-card" id="step-1">
        <h2 class="cpic-guide-step">
            <span class="cpic-guide-num">1</span>
            <?php esc_html_e( 'Check Server Requirements', 'custom-post-image-creator' ); ?>
        </h2>
        <div class="cpic-guide-body">
            <p><?php esc_html_e( 'Before you begin, make sure your server has the following:', 'custom-post-image-creator' ); ?></p>

            <table class="widefat cpic-guide-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Requirement', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Required', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'custom-post-image-creator' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>PHP 7.4+</strong></td>
                        <td><?php esc_html_e( 'Yes', 'custom-post-image-creator' ); ?></td>
                        <td><span class="cpic-status-dot <?php echo version_compare( PHP_VERSION, '7.4', '>=' ) ? 'cpic-ok' : 'cpic-fail'; ?>">
                            <?php echo esc_html( PHP_VERSION ); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'PHP Imagick Extension', 'custom-post-image-creator' ); ?></strong></td>
                        <td><?php esc_html_e( 'Yes', 'custom-post-image-creator' ); ?></td>
                        <td><span class="cpic-status-dot <?php echo class_exists( 'Imagick' ) ? 'cpic-ok' : 'cpic-fail'; ?>">
                            <?php esc_html_e( class_exists( 'Imagick' ) ? 'Loaded ✅' : 'Not installed ❌', 'custom-post-image-creator' ); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'PHP exec() function', 'custom-post-image-creator' ); ?></strong></td>
                        <td><?php esc_html_e( 'Yes', 'custom-post-image-creator' ); ?></td>
                        <td><span class="cpic-status-dot <?php echo function_exists( 'exec' ) ? 'cpic-ok' : 'cpic-fail'; ?>">
                            <?php esc_html_e( function_exists( 'exec' ) ? 'Available ✅' : 'Disabled ❌', 'custom-post-image-creator' ); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Python3 (for Bangla text)', 'custom-post-image-creator' ); ?></strong></td>
                        <td><?php esc_html_e( 'Recommended', 'custom-post-image-creator' ); ?></td>
                        <td><span class="cpic-status-dot <?php echo (int) @exec( 'which python3 2>/dev/null' ) ? 'cpic-ok' : 'cpic-warn'; ?>">
                            <?php esc_html_e( (int) @exec( 'which python3 2>/dev/null' ) ? 'Available ✅' : 'Not found ⚠️', 'custom-post-image-creator' ); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'ImageMagick CLI (magick)', 'custom-post-image-creator' ); ?></strong></td>
                        <td><?php esc_html_e( 'Recommended', 'custom-post-image-creator' ); ?></td>
                        <td><span class="cpic-status-dot <?php echo (int) @exec( 'which magick 2>/dev/null' ) ? 'cpic-ok' : 'cpic-warn'; ?>">
                            <?php esc_html_e( (int) @exec( 'which magick 2>/dev/null' ) ? 'Available ✅' : 'Not found ⚠️', 'custom-post-image-creator' ); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'cURL Extension', 'custom-post-image-creator' ); ?></strong></td>
                        <td><?php esc_html_e( 'Yes (for Facebook & Telegram)', 'custom-post-image-creator' ); ?></td>
                        <td><span class="cpic-status-dot <?php echo function_exists( 'curl_init' ) ? 'cpic-ok' : 'cpic-fail'; ?>">
                            <?php esc_html_e( function_exists( 'curl_init' ) ? 'Loaded ✅' : 'Not installed ❌', 'custom-post-image-creator' ); ?>
                        </span></td>
                    </tr>
                </tbody>
            </table>

            <div class="cpic-guide-note">
                <strong><?php esc_html_e( 'What to do if something is missing:', 'custom-post-image-creator' ); ?></strong>
                <ul>
                    <li><strong>Imagick:</strong> <?php esc_html_e( 'Ask your hosting provider to enable the php-imagick extension, or install it via your hosting control panel (cPanel → PHP Extensions).', 'custom-post-image-creator' ); ?></li>
                    <li><strong>exec():</strong> <?php esc_html_e( 'Some shared hosts disable it. Ask your host to enable it in php.ini or contact support.', 'custom-post-image-creator' ); ?></li>
                    <li><strong>Python3:</strong> <?php esc_html_e( 'Required only for perfect Bangla (Bengali) text shaping. If not available, the plugin will use ImageMagick pango as a fallback.', 'custom-post-image-creator' ); ?></li>
                    <li><strong>cURL:</strong> <?php esc_html_e( 'Required for sharing to Facebook and Telegram. Usually enabled by default on most hosts.', 'custom-post-image-creator' ); ?></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ── STEP 2: Templates ────────────────────────── -->
    <div class="cpic-guide-card" id="step-2">
        <h2 class="cpic-guide-step">
            <span class="cpic-guide-num">2</span>
            <?php esc_html_e( 'Configure Templates', 'custom-post-image-creator' ); ?>
        </h2>
        <div class="cpic-guide-body">
            <p><?php esc_html_e( 'All visual card settings are configured per template. The Dashboard no longer contains a second global image editor.', 'custom-post-image-creator' ); ?></p>

            <h3><?php esc_html_e( 'Canvas, Background, and Elements', 'custom-post-image-creator' ); ?></h3>
            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'Go to the', 'custom-post-image-creator' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=cpic-dashboard&tab=templates' ) ); ?>"><?php esc_html_e( 'Templates', 'custom-post-image-creator' ); ?></a> <?php esc_html_e( 'tab and edit or create a template.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Set each template’s canvas size, background, featured image, logo, headline, date, category, author, website name, and decorative shapes.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Each template stores its own settings; changing one template does not change another.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Use Preview Template, then Save Template.', 'custom-post-image-creator' ); ?></li>
            </ol>

            <div class="cpic-guide-tip">
                <strong><?php esc_html_e( '💡 Tip:', 'custom-post-image-creator' ); ?></strong>
                <?php esc_html_e( 'Use category mapping to choose which independent template is used for each news category.', 'custom-post-image-creator' ); ?>
            </div>
        </div>
    </div>

    <!-- ── STEP 3: Facebook Setup ────────────────────── -->
    <div class="cpic-guide-card" id="step-3">
        <h2 class="cpic-guide-step">
            <span class="cpic-guide-num">3</span>
            <?php esc_html_e( 'Connect Facebook Page', 'custom-post-image-creator' ); ?>
        </h2>
        <div class="cpic-guide-body">
            <p><?php esc_html_e( 'To auto-publish photocards to your Facebook Page, you need to create a Facebook App and get an access token.', 'custom-post-image-creator' ); ?></p>

            <!-- Phase A: Create App -->
            <h3><?php esc_html_e( 'Phase A: Create a Facebook App', 'custom-post-image-creator' ); ?></h3>
            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'Go to', 'custom-post-image-creator' ); ?> <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">https://developers.facebook.com/apps/</a></li>
                <li><?php esc_html_e( 'Click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Create App', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( '(top right button).', 'custom-post-image-creator' ); ?></li>
                <li>
                    <?php esc_html_e( 'Select app type:', 'custom-post-image-creator' ); ?>
                    <strong><?php esc_html_e( '"Business" or "None"', 'custom-post-image-creator' ); ?></strong>.
                    <?php esc_html_e( 'Click Continue.', 'custom-post-image-creator' ); ?>
                </li>
                <li>
                    <?php esc_html_e( 'Fill in:', 'custom-post-image-creator' ); ?>
                    <ul>
                        <li><strong><?php esc_html_e( 'App Name:', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'Anything you like, e.g. "My Blog Bot".', 'custom-post-image-creator' ); ?></li>
                        <li><strong><?php esc_html_e( 'App Contact Email:', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'Your email address.', 'custom-post-image-creator' ); ?></li>
                        <li><strong><?php esc_html_e( 'App Purpose:', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( '"Yourself or your business".', 'custom-post-image-creator' ); ?></li>
                    </ul>
                </li>
                <li><?php esc_html_e( 'Click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Create App', 'custom-post-image-creator' ); ?></strong>.</li>
            </ol>

            <!-- Phase B: Get App ID & Secret -->
            <h3><?php esc_html_e( 'Phase B: Get App ID & App Secret', 'custom-post-image-creator' ); ?></h3>
            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'After creating the app, you will see the app dashboard.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'On the left sidebar, click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Settings', 'custom-post-image-creator' ); ?></strong> → <strong><?php esc_html_e( 'Basic', 'custom-post-image-creator' ); ?></strong>.</li>
                <li><?php esc_html_e( 'Copy the', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'App ID', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'and paste it in the', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'App ID', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'field below.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Show', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'next to App Secret, then copy and paste it in the', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'App Secret', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'field.', 'custom-post-image-creator' ); ?></li>
            </ol>

            <!-- Phase C: Add Pages Permission -->
            <h3><?php esc_html_e( 'Phase C: Add Facebook Page Permissions', 'custom-post-image-creator' ); ?></h3>
            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'In your app dashboard, go to', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'App Review', 'custom-post-image-creator' ); ?></strong> → <strong><?php esc_html_e( 'Permissions and Features', 'custom-post-image-creator' ); ?></strong>.</li>
                <li><?php esc_html_e( 'Search for and add these permissions:', 'custom-post-image-creator' ); ?>
                    <ul>
                        <li><code>pages_show_list</code> – <?php esc_html_e( 'to see your Facebook Pages', 'custom-post-image-creator' ); ?></li>
                        <li><code>pages_read_engagement</code> – <?php esc_html_e( 'to read page data', 'custom-post-image-creator' ); ?></li>
                        <li><code>pages_manage_posts</code> – <?php esc_html_e( 'to publish posts to your page', 'custom-post-image-creator' ); ?></li>
                        <li><code>pages_manage_engagement</code> – <?php esc_html_e( 'to add the article URL as the first comment', 'custom-post-image-creator' ); ?></li>
                    </ul>
                </li>
                <li><?php esc_html_e( 'For development/testing, you may need to add yourself as a', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Test User', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'under', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Roles', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( '→', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Test Users', 'custom-post-image-creator' ); ?></strong>.</li>
            </ol>

            <!-- Phase D: Get User Access Token -->
            <h3><?php esc_html_e( 'Phase D: Get a User Access Token', 'custom-post-image-creator' ); ?></h3>
            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'You no longer need to manually copy tokens! The plugin handles this via OAuth.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Simply enter your App ID and App Secret in the Dashboard, then click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Connect Facebook', 'custom-post-image-creator' ); ?></strong>.</li>
                <li><?php esc_html_e( 'A Facebook popup will open — log in and grant the required permissions.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'If you manage multiple pages, select which one to connect.', 'custom-post-image-creator' ); ?></li>
            </ol>

            <!-- Phase E: Connect in Plugin -->
            <h3><?php esc_html_e( 'Phase E: Connect in This Plugin', 'custom-post-image-creator' ); ?></h3>
            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'In the', 'custom-post-image-creator' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=cpic-dashboard&tab=dashboard' ) ); ?>"><?php esc_html_e( 'Dashboard', 'custom-post-image-creator' ); ?></a> <?php esc_html_e( 'tab, find the', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Social Media Connections', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'section.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Enter your', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'App ID', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'and', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'App Secret', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'and click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Save Credentials', 'custom-post-image-creator' ); ?></strong>.</li>
                <li><?php esc_html_e( 'Click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Connect Facebook', 'custom-post-image-creator' ); ?></strong>. <?php esc_html_e( 'A Facebook popup will open.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Log in to Facebook and grant the requested permissions.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'If you manage multiple pages, select which page to connect.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'The popup will close and you should see the connected page name.', 'custom-post-image-creator' ); ?></li>
            </ol>

            <div class="cpic-guide-warning">
                <strong><?php esc_html_e( '⚠️ Important:', 'custom-post-image-creator' ); ?></strong>
                <ul>
                    <li><?php esc_html_e( 'The App Secret is stored securely on the server and never exposed to the browser.', 'custom-post-image-creator' ); ?></li>
                    <li><?php esc_html_e( 'Access tokens may expire. If sharing stops working, click "Reconnect" to re-authorize.', 'custom-post-image-creator' ); ?></li>
                    <li><?php esc_html_e( 'For public Page timeline visibility, keep the Meta app in Live mode and reconnect after changing permissions. Development-mode posts may be visible only to app roles.', 'custom-post-image-creator' ); ?></li>
                </ul>
            </div>

            <h3><?php esc_html_e( 'Facebook Connection Settings', 'custom-post-image-creator' ); ?></h3>
            <table class="widefat cpic-guide-table">
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e( 'Auto Comment', 'custom-post-image-creator' ); ?></strong></td>
                        <td><?php esc_html_e( 'When enabled, the plugin adds the article URL as one first comment after the single Facebook timeline post is created.', 'custom-post-image-creator' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Global Comment Message', 'custom-post-image-creator' ); ?></strong></td>
                        <td><?php esc_html_e( 'The message used in the article-link comment. Caption content is configured per template in Template Manager.', 'custom-post-image-creator' ); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── STEP 4: Telegram Setup ────────────────────── -->
    <div class="cpic-guide-card" id="step-4">
        <h2 class="cpic-guide-step">
            <span class="cpic-guide-num">4</span>
            <?php esc_html_e( 'Connect Telegram Channel', 'custom-post-image-creator' ); ?>
        </h2>
        <div class="cpic-guide-body">
            <p><?php esc_html_e( 'To auto-publish photocards to a Telegram channel or group, you need to create a Telegram Bot.', 'custom-post-image-creator' ); ?></p>

            <h3><?php esc_html_e( 'Phase A: Create a Telegram Bot', 'custom-post-image-creator' ); ?></h3>
            <ol class="cpic-guide-list">
                <li>
                    <?php esc_html_e( 'Open Telegram and search for', 'custom-post-image-creator' ); ?>
                    <a href="https://t.me/BotFather" target="_blank" rel="noopener"><strong>@BotFather</strong></a>.
                </li>
                <li><?php esc_html_e( 'Start a conversation and send:', 'custom-post-image-creator' ); ?> <code>/newbot</code></li>
                <li><?php esc_html_e( 'BotFather will ask for a name. Enter anything, e.g.', 'custom-post-image-creator' ); ?> <code>My Blog Bot</code>.</li>
                <li><?php esc_html_e( 'BotFather will ask for a username. It must end with', 'custom-post-image-creator' ); ?> <code>bot</code>, <?php esc_html_e( 'e.g.', 'custom-post-image-creator' ); ?> <code>my_blog_posting_bot</code>.</li>
                <li><?php esc_html_e( 'BotFather will give you a', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Bot Token', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( '(looks like', 'custom-post-image-creator' ); ?> <code>123456789:ABCdefGHIjklMNOpqrsTUVwxyz</code><?php esc_html_e( '). Copy it.', 'custom-post-image-creator' ); ?></li>
            </ol>

            <h3><?php esc_html_e( 'Phase B: Add Bot to Your Channel', 'custom-post-image-creator' ); ?></h3>
            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'Open your Telegram channel or group.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Go to', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Channel Info', 'custom-post-image-creator' ); ?></strong> → <strong><?php esc_html_e( 'Administrators', 'custom-post-image-creator' ); ?></strong>.</li>
                <li><?php esc_html_e( 'Click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Add Administrator', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'and search for your bot by its username.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Add the bot as an admin with', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( '"Post Messages"', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'permission.', 'custom-post-image-creator' ); ?></li>
                <li><strong><?php esc_html_e( 'For groups:', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'Simply add the bot to the group — it will automatically be able to post.', 'custom-post-image-creator' ); ?></li>
            </ol>

            <h3><?php esc_html_e( 'Phase C: Get Your Chat ID', 'custom-post-image-creator' ); ?></h3>
            <p><?php esc_html_e( 'The Chat ID depends on whether you use a channel or group:', 'custom-post-image-creator' ); ?></p>

            <h4><?php esc_html_e( 'For Channels:', 'custom-post-image-creator' ); ?></h4>
            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'Forward any message from your channel to', 'custom-post-image-creator' ); ?> <a href="https://t.me/userinfobot" target="_blank" rel="noopener"><strong>@userinfobot</strong></a> <?php esc_html_e( 'or', 'custom-post-image-creator' ); ?> <a href="https://t.me/getidsbot" target="_blank" rel="noopener"><strong>@getidsbot</strong></a>.</li>
                <li><?php esc_html_e( 'It will reply with the channel ID (starts with a negative number like', 'custom-post-image-creator' ); ?> <code>-1001234567890</code>).</li>
                <li><?php esc_html_e( 'Copy this number as your Chat ID.', 'custom-post-image-creator' ); ?></li>
            </ol>

            <h4><?php esc_html_e( 'Alternative Method (Any Chat):', 'custom-post-image-creator' ); ?></h4>
            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'Send any message to your bot in Telegram.', 'custom-post-image-creator' ); ?></li>
                <li>
                    <?php esc_html_e( 'Open this URL in your browser (replace', 'custom-post-image-creator' ); ?> <code>BOT_TOKEN</code> <?php esc_html_e( 'with your actual token):', 'custom-post-image-creator' ); ?>
                    <br><code>https://api.telegram.org/botBOT_TOKEN/getUpdates</code>
                </li>
                <li><?php esc_html_e( 'Look for', 'custom-post-image-creator' ); ?> <code>"chat":{"id":</code> <?php esc_html_e( 'in the JSON response — the number is your Chat ID.', 'custom-post-image-creator' ); ?></li>
            </ol>

            <h3><?php esc_html_e( 'Phase D: Configure in This Plugin', 'custom-post-image-creator' ); ?></h3>
            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'In the', 'custom-post-image-creator' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=cpic-dashboard&tab=dashboard' ) ); ?>"><?php esc_html_e( 'Dashboard', 'custom-post-image-creator' ); ?></a> <?php esc_html_e( 'tab, find the', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Telegram Channel', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'card.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Paste your', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Bot Token', 'custom-post-image-creator' ); ?></strong>.</li>
                <li><?php esc_html_e( 'Paste your', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Chat ID', 'custom-post-image-creator' ); ?></strong>.</li>
                <li><?php esc_html_e( 'Click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Test Connection', 'custom-post-image-creator' ); ?></strong>. <?php esc_html_e( 'You should see the bot username displayed.', 'custom-post-image-creator' ); ?></li>
            </ol>

            <div class="cpic-guide-note">
                <strong><?php esc_html_e( '💡 Note:', 'custom-post-image-creator' ); ?></strong>
                <?php esc_html_e( 'Unlike Facebook, Telegram Bot Tokens do not expire. Once set up, they work permanently.', 'custom-post-image-creator' ); ?>
            </div>
        </div>
    </div>

    <!-- ── STEP 5: Templates ─────────────────────────── -->
    <div class="cpic-guide-card" id="step-5">
        <h2 class="cpic-guide-step">
            <span class="cpic-guide-num">5</span>
            <?php esc_html_e( 'Create Templates', 'custom-post-image-creator' ); ?>
        </h2>
        <div class="cpic-guide-body">
            <p><?php esc_html_e( 'Templates define how your photocards look — layout, colors, fonts, element positions, and more.', 'custom-post-image-creator' ); ?></p>

            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'Go to the', 'custom-post-image-creator' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=cpic-dashboard&tab=templates' ) ); ?>"><?php esc_html_e( 'Templates', 'custom-post-image-creator' ); ?></a> <?php esc_html_e( 'tab.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Create New Template', 'custom-post-image-creator' ); ?></strong>.</li>
                <li><?php esc_html_e( 'Give your template a name (e.g. "Breaking News", "Feature Story").', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Design your template using the visual editor:', 'custom-post-image-creator' ); ?>
                    <ul>
                        <li><?php esc_html_e( 'Set canvas size and background color/image.', 'custom-post-image-creator' ); ?></li>
                        <li><?php esc_html_e( 'Add text elements (title, date, category, author, website) and set font, size, color, position.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Add image elements (featured image, logo) and set size/position.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Add shape elements (rectangles, lines) for visual dividers or backgrounds.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Under Facebook Caption Content, independently enable or disable News Headline and First Paragraph for this template.', 'custom-post-image-creator' ); ?></li>
                </ul>
                </li>
                <li><?php esc_html_e( 'Click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Preview', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'to test with sample data.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Save Template', 'custom-post-image-creator' ); ?></strong>.</li>
                <li><?php esc_html_e( 'Create as many templates as you need for different content types.', 'custom-post-image-creator' ); ?></li>
            </ol>

            <h3><?php esc_html_e( 'Move Templates to Another Site', 'custom-post-image-creator' ); ?></h3>
            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'Click Export beside a template to download its portable JSON file.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'On the new site, open Template Manager and click Import Template.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Choose the exported JSON file. The template is imported as a new active template with a unique name/slug.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Category mappings, default-template status, and database IDs are not transferred; configure those for the new site.', 'custom-post-image-creator' ); ?></li>
            </ol>
            <div class="cpic-guide-note">
                <strong><?php esc_html_e( 'Important:', 'custom-post-image-creator' ); ?></strong>
                <?php esc_html_e( 'Template JSON stores image URLs. If a logo or background belongs to the old site, copy that media to the new site and update the URL in the imported template.', 'custom-post-image-creator' ); ?>
            </div>

            <div class="cpic-guide-tip">
                <strong><?php esc_html_e( '💡 Tip:', 'custom-post-image-creator' ); ?></strong>
                <?php esc_html_e( 'You can duplicate an existing template and modify it for a new variant — much faster than starting from scratch.', 'custom-post-image-creator' ); ?>
            </div>
        </div>
    </div>

    <!-- ── STEP 6: Category Mapping ──────────────────── -->
    <div class="cpic-guide-card" id="step-6">
        <h2 class="cpic-guide-step">
            <span class="cpic-guide-num">6</span>
            <?php esc_html_e( 'Map Categories to Templates', 'custom-post-image-creator' ); ?>
        </h2>
        <div class="cpic-guide-body">
            <p><?php esc_html_e( 'Category Mapping tells the plugin which template to use for each WordPress category. This is how the automation knows which design to generate.', 'custom-post-image-creator' ); ?></p>

            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'Go to the', 'custom-post-image-creator' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=cpic-dashboard&tab=categories' ) ); ?>"><?php esc_html_e( 'Category Mapping', 'custom-post-image-creator' ); ?></a> <?php esc_html_e( 'tab.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'You will see a list of all your WordPress categories.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'For each category, select which template should be used from the dropdown.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Save Mappings', 'custom-post-image-creator' ); ?></strong>.</li>
            </ol>

            <div class="cpic-guide-note">
                <strong><?php esc_html_e( '💡 How it works:', 'custom-post-image-creator' ); ?></strong>
                <ul>
                    <li><?php esc_html_e( 'When a post is published, the plugin looks at its categories and finds the mapped template.', 'custom-post-image-creator' ); ?></li>
                    <li><?php esc_html_e( 'If a post has multiple categories, the template with the lowest priority number wins.', 'custom-post-image-creator' ); ?></li>
                    <li><?php esc_html_e( 'If no category is mapped, the Default Template (set in the Templates tab) is used.', 'custom-post-image-creator' ); ?></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ── STEP 7: Automation Settings ───────────────── -->
    <div class="cpic-guide-card" id="step-7">
        <h2 class="cpic-guide-step">
            <span class="cpic-guide-num">7</span>
            <?php esc_html_e( 'Enable Automation', 'custom-post-image-creator' ); ?>
        </h2>
        <div class="cpic-guide-body">
            <p><?php esc_html_e( 'The final step: enable the automation pipeline so everything runs automatically when you publish a post.', 'custom-post-image-creator' ); ?></p>

            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'Go to the', 'custom-post-image-creator' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=cpic-dashboard&tab=dashboard' ) ); ?>"><?php esc_html_e( 'Dashboard', 'custom-post-image-creator' ); ?></a> <?php esc_html_e( 'tab.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Scroll to', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Automation Settings', 'custom-post-image-creator' ); ?></strong>.</li>
                <li><?php esc_html_e( 'Enable the following options:', 'custom-post-image-creator' ); ?></li>
            </ol>

            <table class="widefat cpic-guide-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Setting', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'What It Does', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Recommended', 'custom-post-image-creator' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e( 'Auto Generate', 'custom-post-image-creator' ); ?></strong></td>
                        <td><?php esc_html_e( 'Automatically generates a photocard when any post is published. Uses the category-mapped template.', 'custom-post-image-creator' ); ?></td>
                        <td><span class="cpic-ok"><?php esc_html_e( 'ON ✅', 'custom-post-image-creator' ); ?></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Auto Comment', 'custom-post-image-creator' ); ?></strong></td>
                        <td><?php esc_html_e( 'Adds the article URL as one first comment after the single Facebook timeline post.', 'custom-post-image-creator' ); ?></td>
                        <td><span class="cpic-ok"><?php esc_html_e( 'ON ✅', 'custom-post-image-creator' ); ?></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Auto Facebook / Auto Telegram', 'custom-post-image-creator' ); ?></strong></td>
                        <td><?php esc_html_e( 'Select which connected destinations receive automatically generated cards.', 'custom-post-image-creator' ); ?></td>
                        <td><?php esc_html_e( 'As needed', 'custom-post-image-creator' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Publish Delay', 'custom-post-image-creator' ); ?></strong></td>
                        <td><?php esc_html_e( 'Waits the selected number of seconds after WordPress publication before processing the job.', 'custom-post-image-creator' ); ?></td>
                        <td><?php esc_html_e( '0 seconds', 'custom-post-image-creator' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Max Retries', 'custom-post-image-creator' ); ?></strong></td>
                        <td><?php esc_html_e( 'How many times to retry a failed job before marking it as failed. Default: 3.', 'custom-post-image-creator' ); ?></td>
                        <td><span class="cpic-ok"><?php esc_html_e( '3', 'custom-post-image-creator' ); ?></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Default Template', 'custom-post-image-creator' ); ?></strong></td>
                        <td><?php esc_html_e( 'The fallback template used when a post\'s category has no specific mapping.', 'custom-post-image-creator' ); ?></td>
                        <td><?php esc_html_e( 'Set to your most common template', 'custom-post-image-creator' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="cpic-guide-tip">
                <strong><?php esc_html_e( '💡 Shared Hosting Tip:', 'custom-post-image-creator' ); ?></strong>
                <?php esc_html_e( 'The plugin uses a built-in backup system for servers where WP-Cron doesn\'t work reliably. Pending jobs are automatically processed when you visit the admin dashboard — no extra server cron needed.', 'custom-post-image-creator' ); ?>
            </div>
        </div>
    </div>

    <!-- ── STEP 8: Test Everything ───────────────────── -->
    <div class="cpic-guide-card" id="step-8">
        <h2 class="cpic-guide-step">
            <span class="cpic-guide-num">8</span>
            <?php esc_html_e( 'Test Everything', 'custom-post-image-creator' ); ?>
        </h2>
        <div class="cpic-guide-body">
            <p><?php esc_html_e( 'Before going live, test the full workflow:', 'custom-post-image-creator' ); ?></p>

            <h3><?php esc_html_e( 'Manual Test from Dashboard', 'custom-post-image-creator' ); ?></h3>
            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'In the', 'custom-post-image-creator' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=cpic-dashboard&tab=dashboard' ) ); ?>"><?php esc_html_e( 'Dashboard', 'custom-post-image-creator' ); ?></a> <?php esc_html_e( 'tab, select a post from the list.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Preview Selected', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'to verify the image renders correctly.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Click', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Share to Facebook', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'or', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Share to Telegram', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'to test the sharing.', 'custom-post-image-creator' ); ?></li>
            </ol>

            <h3><?php esc_html_e( 'Full Automation Test', 'custom-post-image-creator' ); ?></h3>
            <ol class="cpic-guide-list">
                <li><?php esc_html_e( 'Create a new WordPress post with a featured image and a category that has a template mapped.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Publish the post.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'Go to the', 'custom-post-image-creator' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=cpic-dashboard&tab=jobs' ) ); ?>"><?php esc_html_e( 'Jobs & Logs', 'custom-post-image-creator' ); ?></a> <?php esc_html_e( 'tab.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'You should see a new job appear. Wait 30 seconds and refresh — it should show', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( '"Published"', 'custom-post-image-creator' ); ?></strong>.</li>
                <li><?php esc_html_e( 'Check the Facebook Page Posts/Timeline tab (not only Photos) and confirm there is exactly one post with the selected caption content and one article-link comment.', 'custom-post-image-creator' ); ?></li>
                <li><?php esc_html_e( 'If Telegram is enabled, check the connected channel as well.', 'custom-post-image-creator' ); ?></li>
            </ol>

            <h3><?php esc_html_e( 'Checking Logs', 'custom-post-image-creator' ); ?></h3>
            <p><?php esc_html_e( 'If something fails, the', 'custom-post-image-creator' ); ?> <strong><?php esc_html_e( 'Jobs & Logs', 'custom-post-image-creator' ); ?></strong> <?php esc_html_e( 'tab shows error messages for each job. Common issues:', 'custom-post-image-creator' ); ?></p>

            <table class="widefat cpic-guide-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Error', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Solution', 'custom-post-image-creator' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code><?php esc_html_e( 'No active template found', 'custom-post-image-creator' ); ?></code></td>
                        <td><?php esc_html_e( 'Map a template to the post\'s category in the Category Mapping tab, or set a Default Template.', 'custom-post-image-creator' ); ?></td>
                    </tr>
                    <tr>
                        <td><code><?php esc_html_e( 'Facebook Page is not connected', 'custom-post-image-creator' ); ?></code></td>
                        <td><?php esc_html_e( 'Reconnect your Facebook Page in the Dashboard tab (tokens may have expired).', 'custom-post-image-creator' ); ?></td>
                    </tr>
                    <tr>
                        <td><code><?php esc_html_e( 'Facebook rate limit', 'custom-post-image-creator' ); ?></code></td>
                        <td><?php esc_html_e( 'Wait a few minutes and try again. Avoid posting too frequently.', 'custom-post-image-creator' ); ?></td>
                    </tr>
                    <tr>
                        <td><code><?php esc_html_e( 'Photocard image not found', 'custom-post-image-creator' ); ?></code></td>
                        <td><?php esc_html_e( 'The image generation failed. Check that Imagick is installed and your server has enough memory.', 'custom-post-image-creator' ); ?></td>
                    </tr>
                    <tr>
                        <td><code><?php esc_html_e( 'Post not found', 'custom-post-image-creator' ); ?></code></td>
                        <td><?php esc_html_e( 'The post may have been deleted. Delete the job and try again.', 'custom-post-image-creator' ); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Quick Reference ───────────────────────────── -->
    <div class="cpic-guide-card cpic-guide-reference" id="step-reference">
        <h2 class="cpic-guide-step">
            <span class="cpic-guide-num" style="background:#2271b1;">★</span>
            <?php esc_html_e( 'Quick Reference', 'custom-post-image-creator' ); ?>
        </h2>
        <div class="cpic-guide-body">
            <h3><?php esc_html_e( 'Full Automation Flow', 'custom-post-image-creator' ); ?></h3>
            <div class="cpic-guide-flow">
                <div class="cpic-flow-step"><?php esc_html_e( 'Publish Post', 'custom-post-image-creator' ); ?></div>
                <span class="cpic-flow-arrow">→</span>
                <div class="cpic-flow-step"><?php esc_html_e( 'Resolve Template', 'custom-post-image-creator' ); ?></div>
                <span class="cpic-flow-arrow">→</span>
                <div class="cpic-flow-step"><?php esc_html_e( 'Generate Card', 'custom-post-image-creator' ); ?></div>
                <span class="cpic-flow-arrow">→</span>
                <div class="cpic-flow-step"><?php esc_html_e( 'Create One Facebook Timeline Post', 'custom-post-image-creator' ); ?></div>
                <span class="cpic-flow-arrow">→</span>
                <div class="cpic-flow-step"><?php esc_html_e( 'Add One Article-Link Comment', 'custom-post-image-creator' ); ?></div>
            </div>
            <div class="cpic-guide-note">
                <strong><?php esc_html_e( 'Facebook publishing rule:', 'custom-post-image-creator' ); ?></strong>
                <?php esc_html_e( 'The photocard image and caption are created as one public timeline post. The article URL is added once as its first comment; the plugin does not create a separate caption-only post.', 'custom-post-image-creator' ); ?>
            </div>

            <h3><?php esc_html_e( 'Job Statuses', 'custom-post-image-creator' ); ?></h3>
            <table class="widefat cpic-guide-table">
                <tbody>
                    <tr><td><span class="cpic-status-pending">Pending</span></td><td><?php esc_html_e( 'Waiting to be processed.', 'custom-post-image-creator' ); ?></td></tr>
                    <tr><td><span class="cpic-status-processing">Processing</span></td><td><?php esc_html_e( 'Currently being generated or published.', 'custom-post-image-creator' ); ?></td></tr>
                    <tr><td><span class="cpic-status-generated">Generated</span></td><td><?php esc_html_e( 'Image generated, waiting to share.', 'custom-post-image-creator' ); ?></td></tr>
                    <tr><td><span class="cpic-status-publishing">Publishing</span></td><td><?php esc_html_e( 'Sharing to social media.', 'custom-post-image-creator' ); ?></td></tr>
                    <tr><td><span class="cpic-status-published">Published</span></td><td><?php esc_html_e( 'Done! Successfully shared.', 'custom-post-image-creator' ); ?></td></tr>
                    <tr><td><span class="cpic-status-failed">Failed</span></td><td><?php esc_html_e( 'Something went wrong. Check the error message.', 'custom-post-image-creator' ); ?></td></tr>
                </tbody>
            </table>

            <h3><?php esc_html_e( 'Useful Links', 'custom-post-image-creator' ); ?></h3>
            <ul>
                <li><a href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener"><?php esc_html_e( 'Facebook Graph API Explorer', 'custom-post-image-creator' ); ?></a> – <?php esc_html_e( 'Generate and test access tokens', 'custom-post-image-creator' ); ?></li>
                <li><a href="https://t.me/BotFather" target="_blank" rel="noopener"><?php esc_html_e( 'Telegram BotFather', 'custom-post-image-creator' ); ?></a> – <?php esc_html_e( 'Create and manage Telegram bots', 'custom-post-image-creator' ); ?></li>
                <li><a href="https://developers.facebook.com/docs/pages-api/posts" target="_blank" rel="noopener"><?php esc_html_e( 'Facebook Pages API Docs', 'custom-post-image-creator' ); ?></a></li>
            </ul>
        </div>
    </div>

</div>
