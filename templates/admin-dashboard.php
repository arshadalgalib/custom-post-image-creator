<?php
/**
 * Admin Dashboard Template
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div id="cpic-app" class="wrap cpic-wrap">

    <!-- Header -->
    <div class="cpic-header">
        <h1>
            <span class="dashicons dashicons-format-image"></span>
            <?php esc_html_e( 'Dashboard', 'custom-post-image-creator' ); ?>
        </h1>
        <button type="button" id="cpic-dark-toggle" class="button" title="Toggle Dark Mode">
            <span class="dashicons dashicons-visibility"></span>
        </button>
    </div>

    <!-- Automation stats -->
    <?php
    $cpic_counts = cpic()->queue->get_status_counts();
    $cpic_tpl_total = count( CPIC_Template_Manager::get_templates() );
    $cpic_mappings  = count( cpic()->category_mapper->get_mappings() );
    $cpic_engines   = CPIC_Renderer::text_engine_report();
    ?>
    <div class="cpic-section">
        <h2><?php esc_html_e( 'Bangla Text Engine Status', 'custom-post-image-creator' ); ?></h2>
        <div class="cpic-row cpic-stats">
            <div class="cpic-card cpic-stat">
                <span class="cpic-stat-num"><?php echo esc_html( $cpic_engines['exec'] ? '✓' : '✗' ); ?></span>
                <span class="cpic-stat-label">PHP exec()</span>
            </div>
            <div class="cpic-card cpic-stat">
                <span class="cpic-stat-num"><?php echo esc_html( $cpic_engines['python'] ? '✓' : '✗' ); ?></span>
                <span class="cpic-stat-label">python3 (<?php echo esc_html( $cpic_engines['python'] ?: 'n/a' ); ?>)</span>
            </div>
            <div class="cpic-card cpic-stat">
                <span class="cpic-stat-num"><?php echo esc_html( $cpic_engines['pango_python'] ? '✓' : '✗' ); ?></span>
                <span class="cpic-stat-label">Pango (python)</span>
            </div>
            <div class="cpic-card cpic-stat">
                <span class="cpic-stat-num"><?php echo esc_html( $cpic_engines['im_pango'] ? '✓' : '✗' ); ?></span>
                <span class="cpic-stat-label">IM pango delegate</span>
            </div>
        </div>
    </div>

    <!-- Automation stats -->
    <?php
    $cpic_counts = cpic()->queue->get_status_counts();
    $cpic_tpl_total = count( CPIC_Template_Manager::get_templates() );
    $cpic_mappings  = count( cpic()->category_mapper->get_mappings() );
    ?>
    <div class="cpic-section">
        <h2><?php esc_html_e( 'Automation Status', 'custom-post-image-creator' ); ?></h2>
        <div class="cpic-row cpic-stats">
            <div class="cpic-card cpic-stat">
                <span class="cpic-stat-num"><?php echo esc_html( $cpic_tpl_total ); ?></span>
                <span class="cpic-stat-label"><?php esc_html_e( 'Templates', 'custom-post-image-creator' ); ?></span>
            </div>
            <div class="cpic-card cpic-stat">
                <span class="cpic-stat-num"><?php echo esc_html( $cpic_mappings ); ?></span>
                <span class="cpic-stat-label"><?php esc_html_e( 'Category Mappings', 'custom-post-image-creator' ); ?></span>
            </div>
            <div class="cpic-card cpic-stat">
                <span class="cpic-stat-num"><?php echo esc_html( $cpic_counts['generated'] + $cpic_counts['published'] ); ?></span>
                <span class="cpic-stat-label"><?php esc_html_e( 'Generated Cards', 'custom-post-image-creator' ); ?></span>
            </div>
            <div class="cpic-card cpic-stat">
                <span class="cpic-stat-num"><?php echo esc_html( $cpic_counts['published'] ); ?></span>
                <span class="cpic-stat-label"><?php esc_html_e( 'Published', 'custom-post-image-creator' ); ?></span>
            </div>
            <div class="cpic-card cpic-stat">
                <span class="cpic-stat-num"><?php echo esc_html( $cpic_counts['failed'] ); ?></span>
                <span class="cpic-stat-label"><?php esc_html_e( 'Failed', 'custom-post-image-creator' ); ?></span>
            </div>
            <div class="cpic-card cpic-stat">
                <span class="cpic-stat-num"><?php echo esc_html( $cpic_counts['pending'] + $cpic_counts['processing'] + $cpic_counts['publishing'] ); ?></span>
                <span class="cpic-stat-label"><?php esc_html_e( 'Pending / Processing', 'custom-post-image-creator' ); ?></span>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- SECTION 1: Social Media Connections                          -->
    <!-- ============================================================ -->
    <div class="cpic-section cpic-connections">
        <h2><?php esc_html_e( 'Social Media Connections', 'custom-post-image-creator' ); ?></h2>
        <div class="cpic-row">

            <!-- Facebook -->
            <div class="cpic-col cpic-card">
                <h3><span class="dashicons dashicons-facebook"></span> Facebook Page</h3>
                <div id="cpic-fb-status">
                    <?php
                    $fb_page_name = cpic()->settings->get( 'cpic_fb_page_name' );
                    if ( $fb_page_name ) :
                    ?>
                        <p class="cpic-connected"><?php echo esc_html( sprintf( __( 'Connected: %s', 'custom-post-image-creator' ), $fb_page_name ) ); ?></p>
                        <button type="button" id="cpic-fb-reconnect" class="button"><?php esc_html_e( 'Reconnect', 'custom-post-image-creator' ); ?></button>
                        <button type="button" id="cpic-fb-disconnect" class="button button-secondary"><?php esc_html_e( 'Disconnect', 'custom-post-image-creator' ); ?></button>
                    <?php else : ?>
                        <p class="cpic-disconnected"><?php esc_html_e( 'Not connected', 'custom-post-image-creator' ); ?></p>
                    <?php endif; ?>
                </div>
                <div class="cpic-fb-fields">
                    <label><?php esc_html_e( 'App ID', 'custom-post-image-creator' ); ?>
                        <input type="text" id="cpic_fb_app_id" value="<?php echo esc_attr( cpic()->settings->get( 'cpic_fb_app_id' ) ); ?>" placeholder="e.g. 123456789012345" />
                    </label>
                    <label><?php esc_html_e( 'App Secret', 'custom-post-image-creator' ); ?>
                        <input type="password" id="cpic_fb_app_secret" autocomplete="new-password" placeholder="<?php echo esc_attr( cpic()->settings->get( 'cpic_fb_app_secret' ) ? '••••••••••••' : 'Enter App Secret…' ); ?>" />
                        <small class="cpic-hint"><?php esc_html_e( 'Stored securely on server. Leave blank to keep the saved secret.', 'custom-post-image-creator' ); ?></small>
                    </label>
                    <button type="button" id="cpic-fb-save-creds" class="button"><?php esc_html_e( 'Save Credentials', 'custom-post-image-creator' ); ?></button>
                    <hr style="margin:12px 0;border-color:#eee;" />
                    <button type="button" id="cpic-fb-oauth-btn" class="button button-primary" style="font-size:14px;padding:8px 20px;">
                        <span class="dashicons dashicons-facebook" style="margin-top:4px;"></span>
                        <?php esc_html_e( 'Connect Facebook', 'custom-post-image-creator' ); ?>
                    </button>
                    <p class="description" style="margin-top:8px;"><?php esc_html_e( 'Click to authorize via Facebook OAuth popup. No manual tokens needed.', 'custom-post-image-creator' ); ?></p>
                </div>
            </div>

            <!-- Telegram -->
            <div class="cpic-col cpic-card">
                <h3><span class="dashicons dashicons-share"></span> Telegram Channel</h3>
                <div class="cpic-tg-fields">
                    <label><?php esc_html_e( 'Bot Token', 'custom-post-image-creator' ); ?>
                        <input type="password" id="cpic_tg_bot_token" autocomplete="new-password" placeholder="<?php echo esc_attr( cpic()->settings->get( 'cpic_tg_bot_token' ) ? '••••••••••••' : '' ); ?>" />
                        <small class="cpic-hint"><?php esc_html_e( 'Leave blank to keep the saved token.', 'custom-post-image-creator' ); ?></small>
                    </label>
                    <label><?php esc_html_e( 'Chat ID', 'custom-post-image-creator' ); ?>
                        <input type="text" id="cpic_tg_chat_id" value="<?php echo esc_attr( cpic()->settings->get( 'cpic_tg_chat_id' ) ); ?>" />
                    </label>
                    <button type="button" id="cpic-tg-test" class="button"><?php esc_html_e( 'Test Connection', 'custom-post-image-creator' ); ?></button>
                    <span id="cpic-tg-status"></span>
                </div>
            </div>

        </div>
    </div>

    <!-- ============================================================ -->
    <!-- SECTION 2: Select Posts                                      -->
    <!-- ============================================================ -->
    <div class="cpic-section cpic-posts-section">
        <h2><?php esc_html_e( 'Select Posts', 'custom-post-image-creator' ); ?></h2>

        <div class="cpic-filter-bar">
            <label><?php esc_html_e( 'From', 'custom-post-image-creator' ); ?>
                <input type="date" id="cpic-date-from" class="cpic-date-field" />
            </label>
            <label><?php esc_html_e( 'To', 'custom-post-image-creator' ); ?>
                <input type="date" id="cpic-date-to" class="cpic-date-field" />
            </label>
            <button type="button" id="cpic-filter-posts" class="button"><?php esc_html_e( 'Filter', 'custom-post-image-creator' ); ?></button>
            <button type="button" id="cpic-load-all" class="button"><?php esc_html_e( 'Load All', 'custom-post-image-creator' ); ?></button>
        </div>

        <div class="cpic-table-wrapper">
            <table id="cpic-posts-table" class="widefat striped">
                <thead>
                    <tr>
                        <th class="cpic-check-col"><input type="checkbox" id="cpic-select-all" /></th>
                        <th class="cpic-sortable" data-sort="title"><?php esc_html_e( 'Title', 'custom-post-image-creator' ); ?></th>
                        <th class="cpic-sortable" data-sort="author"><?php esc_html_e( 'Author', 'custom-post-image-creator' ); ?></th>
                        <th class="cpic-sortable" data-sort="date"><?php esc_html_e( 'Publish Date', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Featured Image', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Last FB Share', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Last TG Share', 'custom-post-image-creator' ); ?></th>
                    </tr>
                </thead>
                <tbody id="cpic-posts-body">
                    <tr><td colspan="7"><?php esc_html_e( 'Loading…', 'custom-post-image-creator' ); ?></td></tr>
                </tbody>
            </table>
        </div>

        <div id="cpic-pagination" class="cpic-pagination"></div>
    </div>

    <!-- Global settings -->
    <div class="cpic-card">
        <h3><?php esc_html_e( 'Global Caption / Comment Settings', 'custom-post-image-creator' ); ?></h3>
        <label><?php esc_html_e( 'First comment message', 'custom-post-image-creator' ); ?>
            <input type="text" id="cpic_comment_message" value="<?php echo esc_attr( cpic()->settings->get( 'cpic_comment_message' ) ); ?>" />
        </label>
        <p class="cpic-preview-hint"><?php esc_html_e( 'Caption content (headline and first paragraph) is configured independently for each template in Template Manager.', 'custom-post-image-creator' ); ?></p>
    </div>

    <div class="cpic-card">
        <h3><?php esc_html_e( 'Publishing Destinations', 'custom-post-image-creator' ); ?></h3>
        <label>
            <input type="checkbox" id="cpic_auto_fb" value="1" <?php checked( cpic()->settings->get( 'cpic_auto_fb' ), 1 ); ?> />
            <?php esc_html_e( 'Auto post to Facebook', 'custom-post-image-creator' ); ?>
        </label>
        <label>
            <input type="checkbox" id="cpic_auto_tg" value="1" <?php checked( cpic()->settings->get( 'cpic_auto_tg' ), 1 ); ?> />
            <?php esc_html_e( 'Auto post to Telegram', 'custom-post-image-creator' ); ?>
        </label>
    </div>

    <div class="cpic-card">
        <h3><?php esc_html_e( 'Automation Settings', 'custom-post-image-creator' ); ?></h3>
        <label>
            <input type="checkbox" id="cpic_auto_generate" value="1" <?php checked( CPIC_Settings::get_single( 'cpic_auto_generate', 0 ), 1 ); ?> />
            <?php esc_html_e( 'Enable automatic generation & publishing', 'custom-post-image-creator' ); ?>
        </label>
        <label>
            <input type="checkbox" id="cpic_auto_comment" value="1" <?php checked( CPIC_Settings::get_single( 'cpic_auto_comment', 1 ), 1 ); ?> />
            <?php esc_html_e( 'Enable automatic first comment (article URL)', 'custom-post-image-creator' ); ?>
        </label>
        <label><?php esc_html_e( 'Delay after publish (seconds)', 'custom-post-image-creator' ); ?>
            <input type="number" id="cpic_auto_delay" min="0" max="3600" value="<?php echo esc_attr( cpic()->settings->get( 'cpic_auto_delay' ) ); ?>" />
        </label>
        <label><?php esc_html_e( 'Maximum retry attempts', 'custom-post-image-creator' ); ?>
            <input type="number" id="cpic_max_retries" min="0" max="10" value="<?php echo esc_attr( CPIC_Settings::get_single( 'cpic_max_retries', 3 ) ); ?>" />
        </label>
        <label><?php esc_html_e( 'Default template', 'custom-post-image-creator' ); ?>
            <select id="cpic_default_template_id">
                <option value="0"><?php esc_html_e( '— Auto (first active) —', 'custom-post-image-creator' ); ?></option>
                <?php foreach ( CPIC_Template_Manager::get_templates() as $cpic_tpl ) : ?>
                    <option value="<?php echo esc_attr( (int) $cpic_tpl['id'] ); ?>" <?php selected( CPIC_Settings::get_single( 'cpic_default_template_id' ), (int) $cpic_tpl['id'] ); ?>>
                        <?php echo esc_html( $cpic_tpl['name'] ); ?><?php echo ! empty( $cpic_tpl['is_default'] ) ? ' (' . esc_html__( 'Default', 'custom-post-image-creator' ) . ')' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="cpic-danger-setting">
            <input type="checkbox" id="cpic_delete_data_on_uninstall" value="1" <?php checked( CPIC_Settings::get_single( 'cpic_delete_data_on_uninstall', 0 ), 1 ); ?> />
            <?php esc_html_e( 'Delete plugin settings, templates, jobs, logs and generated images when uninstalling', 'custom-post-image-creator' ); ?>
        </label>
        <p class="cpic-preview-hint"><?php esc_html_e( 'All plugin data is preserved on uninstall by default. Enable this only if you explicitly want a full data purge.', 'custom-post-image-creator' ); ?></p>
    </div>

    <p>
        <button type="button" id="cpic-save-settings" class="button button-primary button-hero"><?php esc_html_e( 'Save Global Settings', 'custom-post-image-creator' ); ?></button>
        <span id="cpic-save-status"></span>
    </p>

    <!-- ============================================================ -->
    <!-- SECTION 4: Image Preview                                     -->
    <!-- ============================================================ -->
    <div class="cpic-section cpic-preview-section">
        <h2><?php esc_html_e( 'Image Preview', 'custom-post-image-creator' ); ?></h2>
        <div class="cpic-canvas-container">
            <img id="cpic-server-preview" src="" alt="<?php esc_attr_e( 'Template preview', 'custom-post-image-creator' ); ?>" style="display:none;max-width:100%;height:auto;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.35);" />
            <p id="cpic-preview-info" class="cpic-preview-hint" style="display:none;"></p>
        </div>
        <p class="cpic-preview-hint"><?php esc_html_e( 'Select a post to preview the photocard its category template will produce (server-side render, identical to automatic output).', 'custom-post-image-creator' ); ?></p>
    </div>

    <!-- ============================================================ -->
    <!-- SECTION 5: Actions Panel                                     -->
    <!-- ============================================================ -->
    <div class="cpic-section cpic-actions">
        <h2><?php esc_html_e( 'Actions', 'custom-post-image-creator' ); ?></h2>
        <div class="cpic-action-buttons">
            <button type="button" id="cpic-preview-btn" class="button"><?php esc_html_e( 'Preview Selected', 'custom-post-image-creator' ); ?></button>
            <button type="button" id="cpic-download-btn" class="button"><?php esc_html_e( 'Download Image', 'custom-post-image-creator' ); ?></button>
            <button type="button" id="cpic-bulk-download" class="button"><?php esc_html_e( 'Bulk Download ZIP', 'custom-post-image-creator' ); ?></button>
            <button type="button" id="cpic-fb-share-btn" class="button button-primary"><?php esc_html_e( 'Share to Facebook', 'custom-post-image-creator' ); ?></button>
            <button type="button" id="cpic-tg-share-btn" class="button button-primary"><?php esc_html_e( 'Share to Telegram', 'custom-post-image-creator' ); ?></button>
            <button type="button" id="cpic-bulk-fb" class="button"><?php esc_html_e( 'Bulk Facebook Share', 'custom-post-image-creator' ); ?></button>
            <button type="button" id="cpic-bulk-tg" class="button"><?php esc_html_e( 'Bulk Telegram Share', 'custom-post-image-creator' ); ?></button>
        </div>

        <!-- Progress bar -->
        <div id="cpic-progress" class="cpic-progress" style="display:none;">
            <div class="cpic-progress-bar">
                <div class="cpic-progress-fill" id="cpic-progress-fill"></div>
            </div>
            <p id="cpic-progress-text"></p>
        </div>

        <!-- Status log -->
        <div id="cpic-status-log" class="cpic-status-log"></div>

        <!-- Queue monitor -->
        <div class="cpic-card cpic-queue-monitor">
            <h3><?php esc_html_e( 'Queue Monitor', 'custom-post-image-creator' ); ?></h3>
            <p>
                <strong><?php esc_html_e( 'Pending', 'custom-post-image-creator' ); ?>:</strong>
                <span id="cpic-queue-pending">0</span>
                &nbsp;|&nbsp;
                <strong><?php esc_html_e( 'Due', 'custom-post-image-creator' ); ?>:</strong>
                <span id="cpic-queue-due">0</span>
                &nbsp;|&nbsp;
                <strong><?php esc_html_e( 'Next Cron', 'custom-post-image-creator' ); ?>:</strong>
                <span id="cpic-queue-next-cron">-</span>
            </p>
            <p>
                <button type="button" id="cpic-queue-refresh" class="button"><?php esc_html_e( 'Refresh Queue', 'custom-post-image-creator' ); ?></button>
                <button type="button" id="cpic-queue-process-now" class="button button-secondary"><?php esc_html_e( 'Process Now', 'custom-post-image-creator' ); ?></button>
            </p>

            <div class="cpic-table-wrapper">
                <table class="widefat striped" id="cpic-queue-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Post ID', 'custom-post-image-creator' ); ?></th>
                            <th><?php esc_html_e( 'Title', 'custom-post-image-creator' ); ?></th>
                            <th><?php esc_html_e( 'Scheduled At', 'custom-post-image-creator' ); ?></th>
                            <th><?php esc_html_e( 'Targets', 'custom-post-image-creator' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'custom-post-image-creator' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="cpic-queue-body">
                        <tr><td colspan="5"><?php esc_html_e( 'Loading queue…', 'custom-post-image-creator' ); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <h4><?php esc_html_e( 'Recent Worker Logs', 'custom-post-image-creator' ); ?></h4>
            <div id="cpic-queue-logs" class="cpic-status-log visible"></div>
        </div>
    </div>

</div>
