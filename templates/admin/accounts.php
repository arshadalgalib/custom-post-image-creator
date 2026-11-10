<?php
/**
 * Social accounts — simple two-panel UI (Facebook + Telegram).
 *
 * Matches the design in the screenshot: Facebook Page (OAuth + App ID/Secret)
 * on the left, Telegram Channel (Bot Token + Chat ID) on the right.
 *
 * @package CustomPostImageCreator
 *
 * @var \CPIC\Core\Container $container Container.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cpic_settings = $container->get( 'settings' );
$cpic_accounts = $container->get( 'accounts' );
$cpic_can_edit = $container->get( 'capabilities' )->can( 'cpic_manage_accounts' );
$cpic_notice   = isset( $_GET['cpic_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['cpic_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_error    = get_transient( 'cpic_admin_error_' . get_current_user_id() );
if ( $cpic_error ) {
	delete_transient( 'cpic_admin_error_' . get_current_user_id() );
}

// Facebook single-connection view (first connected Facebook destination).
$cpic_fb_rows = $cpic_accounts->all( array( 'provider' => 'facebook' ) );
$cpic_fb_connected = null;
foreach ( $cpic_fb_rows as $r ) {
	if ( 'connected' === (string) $r['status'] ) {
		$cpic_fb_connected = $r;
		break;
	}
}
if ( ! $cpic_fb_connected && ! empty( $cpic_fb_rows ) ) {
	$cpic_fb_connected = $cpic_fb_rows[0];
}

// Telegram — single channel (first).
$cpic_tg_rows = $cpic_accounts->all( array( 'provider' => 'telegram' ) );
$cpic_tg_connected = ! empty( $cpic_tg_rows ) ? $cpic_tg_rows[0] : null;

// Current credential values (for display).
$cpic_fb_app_id     = (string) $cpic_settings->get( 'cpic_fb_app_id', '' );
$cpic_fb_app_secret = (string) $cpic_settings->get( 'cpic_fb_app_secret', '' );
$cpic_tg_bot_token  = (string) $cpic_settings->get( 'cpic_tg_bot_token', '' );
$cpic_tg_chat_id    = (string) $cpic_settings->get( 'cpic_tg_chat_id', '' );

// Also hydrate from destinations if present (they may have newer tokens).
if ( $cpic_tg_connected ) {
	if ( '' === $cpic_tg_chat_id ) {
		$cpic_tg_chat_id = (string) $cpic_tg_connected['account_id'];
	}
}

$cpic_title = __( 'Social Media Connections', 'custom-post-image-creator' );

?>
<div class="wrap cpic-wrap">

	<h1 class="cpic-connections-title"><?php echo esc_html( $cpic_title ); ?></h1>
	<hr class="cpic-connections-hr">

	<?php if ( 'saved' === $cpic_notice ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'Saved.', 'custom-post-image-creator' ); ?></p></div>
	<?php elseif ( 'disconnected' === $cpic_notice ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'Disconnected.', 'custom-post-image-creator' ); ?></p></div>
	<?php elseif ( 'tested' === $cpic_notice ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'Connection test succeeded.', 'custom-post-image-creator' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $cpic_error ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( (string) $cpic_error ); ?></p></div>
	<?php endif; ?>

	<div class="cpic-connections">

		<!-- Facebook Page -->
		<div class="cpic-connection-card">
			<h2 class="cpic-connection-card__head"><span class="dashicons dashicons-facebook" style="font-size:18px"></span> <?php esc_html_e( 'Facebook Page', 'custom-post-image-creator' ); ?></h2>

			<?php if ( $cpic_fb_connected ) : ?>
				<p class="cpic-connected-line">
					<?php
					$label = ! empty( $cpic_fb_connected['account_name'] ) ? $cpic_fb_connected['account_name'] : $cpic_fb_connected['label'];
					echo esc_html( sprintf( __( 'Connected: %s', 'custom-post-image-creator' ), $label ) );
					?>
				</p>
				<p>
					<a href="#" id="cpic-fb-reconnect" class="button button-small cpic-btn-outline"><?php esc_html_e( 'Reconnect', 'custom-post-image-creator' ); ?></a>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'cpic_disconnect_facebook' ), admin_url( 'admin-post.php' ) ), 'cpic_disconnect_facebook' ) ); ?>" class="button button-small cpic-btn-outline" onclick="return confirm('<?php echo esc_js( __( 'Disconnect this Facebook Page?', 'custom-post-image-creator' ) ); ?>')"><?php esc_html_e( 'Disconnect', 'custom-post-image-creator' ); ?></a>
				</p>
			<?php else : ?>
				<p class="cpic-muted"><?php esc_html_e( 'Not connected yet.', 'custom-post-image-creator' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off" class="cpic-form">
				<input type="hidden" name="action" value="cpic_save_facebook_creds">
				<?php wp_nonce_field( 'cpic_save_facebook_creds' ); ?>

				<p class="cpic-field">
					<label for="cpic-fb-app-id"><strong><?php esc_html_e( 'App ID', 'custom-post-image-creator' ); ?></strong></label>
					<input id="cpic-fb-app-id" type="text" name="cpic_fb_app_id" value="<?php echo esc_attr( $cpic_fb_app_id ); ?>" placeholder="532597385947040" <?php disabled( ! $cpic_can_edit ); ?>>
				</p>

				<p class="cpic-field">
					<label for="cpic-fb-app-secret"><strong><?php esc_html_e( 'App Secret', 'custom-post-image-creator' ); ?></strong></label>
					<input id="cpic-fb-app-secret" type="password" name="cpic_fb_app_secret" value="" placeholder="<?php echo '' !== $cpic_fb_app_secret ? '••••••••••' : ''; ?>" autocomplete="new-password" <?php disabled( ! $cpic_can_edit ); ?>>
					<span class="cpic-muted"><?php esc_html_e( 'Stored securely on server. Leave blank to keep the saved secret.', 'custom-post-image-creator' ); ?></span>
				</p>

				<?php if ( $cpic_can_edit ) : ?>
					<p><button type="submit" class="button cpic-btn-outline"><?php esc_html_e( 'Save Credentials', 'custom-post-image-creator' ); ?></button></p>
				<?php endif; ?>
			</form>

			<hr class="cpic-card-sep">

			<p>
				<button type="button" id="cpic-fb-oauth-btn" class="button button-primary cpic-btn-facebook" <?php disabled( ! $cpic_can_edit ); ?>>
					<span class="dashicons dashicons-facebook" style="font-size:16px;margin-top:2px"></span> <?php esc_html_e( 'Connect Facebook', 'custom-post-image-creator' ); ?>
				</button>
				<span id="cpic-fb-oauth-status" class="cpic-muted" role="status" aria-live="polite" style="margin-left:8px"></span>
			</p>
			<p class="cpic-muted"><?php esc_html_e( 'Click to authorize via Facebook OAuth popup. No manual tokens needed.', 'custom-post-image-creator' ); ?></p>

		</div>

		<!-- Telegram Channel -->
		<div class="cpic-connection-card">
			<h2 class="cpic-connection-card__head"><span class="dashicons dashicons-share" style="font-size:18px"></span> <?php esc_html_e( 'Telegram Channel', 'custom-post-image-creator' ); ?></h2>

			<?php if ( $cpic_tg_connected && 'connected' === (string) $cpic_tg_connected['status'] ) : ?>
				<p class="cpic-connected-line"><?php echo esc_html( sprintf( __( 'Connected: %s', 'custom-post-image-creator' ), $cpic_tg_connected['account_id'] ) ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off" class="cpic-form" id="cpic-tg-form">
				<input type="hidden" name="action" value="cpic_save_telegram">
				<?php wp_nonce_field( 'cpic_save_telegram' ); ?>

				<p class="cpic-field">
					<label for="cpic-tg-bot-token"><strong><?php esc_html_e( 'Bot Token', 'custom-post-image-creator' ); ?></strong></label>
					<input id="cpic-tg-bot-token" type="text" name="cpic_tg_bot_token" value="" placeholder="<?php echo '' !== $cpic_tg_bot_token ? '••••••••••' : ''; ?>" autocomplete="new-password" <?php disabled( ! $cpic_can_edit ); ?>>
					<span class="cpic-muted"><?php esc_html_e( 'Leave blank to keep the saved token.', 'custom-post-image-creator' ); ?></span>
				</p>

				<p class="cpic-field">
					<label for="cpic-tg-chat-id"><strong><?php esc_html_e( 'Chat ID', 'custom-post-image-creator' ); ?></strong></label>
					<input id="cpic-tg-chat-id" type="text" name="cpic_tg_chat_id" value="<?php echo esc_attr( $cpic_tg_chat_id ); ?>" placeholder="@channel or -100..." <?php disabled( ! $cpic_can_edit ); ?>>
				</p>

				<?php if ( $cpic_can_edit ) : ?>
					<p style="display:flex;gap:8px;align-items:center">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Telegram', 'custom-post-image-creator' ); ?></button>
						<button type="button" id="cpic-tg-test-btn" class="button cpic-btn-outline"><?php esc_html_e( 'Test Connection', 'custom-post-image-creator' ); ?></button>
						<span id="cpic-tg-test-status" class="cpic-muted" role="status" aria-live="polite"></span>
					</p>
				<?php else : ?>
					<p><button type="button" id="cpic-tg-test-btn" class="button cpic-btn-outline"><?php esc_html_e( 'Test Connection', 'custom-post-image-creator' ); ?></button> <span id="cpic-tg-test-status" class="cpic-muted"></span></p>
				<?php endif; ?>
			</form>

		</div>

	</div>
</div>
