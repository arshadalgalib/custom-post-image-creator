<?php
/**
 * Social accounts.
 *
 * @package CustomPostImageCreator
 *
 * @var \CPIC\Core\Container $container Container.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cpic_accounts = $container->get( 'accounts' );
$cpic_rows     = $cpic_accounts->all();
$cpic_providers = $cpic_accounts->providers();
$cpic_summary   = $cpic_accounts->summary();
$cpic_can_edit  = $container->get( 'capabilities' )->can( 'cpic_manage_accounts' );
$cpic_notice    = isset( $_GET['cpic_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['cpic_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_error     = get_transient( 'cpic_admin_error_' . get_current_user_id() );

if ( $cpic_error ) {
	delete_transient( 'cpic_admin_error_' . get_current_user_id() );
}

$cpic_title    = __( 'Social accounts', 'custom-post-image-creator' );
$cpic_subtitle = __( 'Where images and captions are published. Credentials are stored encrypted and never shown again after saving.', 'custom-post-image-creator' );

require __DIR__ . '/_header.php';
?>
<div class="wrap cpic-wrap">

	<?php if ( 'saved' === $cpic_notice ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'The account was saved.', 'custom-post-image-creator' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $cpic_error ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( (string) $cpic_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( empty( $cpic_rows ) ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'No publishing destination is connected yet. Images can still be designed and downloaded, but nothing can be posted automatically.', 'custom-post-image-creator' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="cpic-grid cpic-grid--stats">
		<?php foreach ( $cpic_providers as $cpic_key => $cpic_label ) : ?>
			<?php $cpic_provider_summary = isset( $cpic_summary[ $cpic_key ] ) ? $cpic_summary[ $cpic_key ] : array( 'total' => 0, 'connected' => 0 ); ?>
			<div class="cpic-card cpic-stat">
				<span class="cpic-stat__value"><?php echo esc_html( number_format_i18n( (int) $cpic_provider_summary['connected'] ) ); ?></span>
				<span class="cpic-stat__label"><?php echo esc_html( (string) $cpic_label ); ?></span>
				<span class="cpic-stat__meta">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: total accounts */
							_n( '%d account', '%d accounts', (int) $cpic_provider_summary['total'], 'custom-post-image-creator' ),
							(int) $cpic_provider_summary['total']
						)
					);
					?>
				</span>
			</div>
		<?php endforeach; ?>
	</div>

	<?php foreach ( $cpic_rows as $cpic_account ) : ?>
		<section class="cpic-card cpic-account">
			<header class="cpic-account__header">
				<h2>
					<?php echo esc_html( '' !== (string) $cpic_account['label'] ? (string) $cpic_account['label'] : (string) $cpic_account['account_name'] ); ?>
					<span class="cpic-badge cpic-badge--<?php echo esc_attr( (string) $cpic_account['status'] ); ?>"><?php echo esc_html( (string) $cpic_account['status'] ); ?></span>
					<?php if ( ! empty( $cpic_account['is_default'] ) ) : ?>
						<span class="cpic-badge cpic-badge--default"><?php esc_html_e( 'Default', 'custom-post-image-creator' ); ?></span>
					<?php endif; ?>
				</h2>
				<p class="cpic-muted">
					<?php echo esc_html( (string) $cpic_account['provider_label'] ); ?>
					· <code><?php echo esc_html( (string) $cpic_account['account_id'] ); ?></code>
					<?php if ( ! empty( $cpic_account['last_validated_at'] ) ) : ?>
						· <?php echo esc_html( sprintf( /* translators: %s: datetime */ __( 'checked %s', 'custom-post-image-creator' ), (string) $cpic_account['last_validated_at'] ) ); ?>
					<?php endif; ?>
				</p>
				<?php if ( ! empty( $cpic_account['last_error'] ) ) : ?>
					<p class="cpic-error-text"><?php echo esc_html( (string) $cpic_account['last_error'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $cpic_account['token_expiring'] ) ) : ?>
					<p class="cpic-error-text"><?php esc_html_e( 'The access token expires within a week. Reconnect to avoid failed posts.', 'custom-post-image-creator' ); ?></p>
				<?php endif; ?>
			</header>

			<details class="cpic-disclosure">
				<summary><?php esc_html_e( 'Edit connection', 'custom-post-image-creator' ); ?></summary>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cpic-form" autocomplete="off">
					<input type="hidden" name="action" value="cpic_save_account" />
					<input type="hidden" name="account" value="<?php echo esc_attr( (string) $cpic_account['id'] ); ?>" />
					<input type="hidden" name="provider" value="<?php echo esc_attr( (string) $cpic_account['provider'] ); ?>" />
					<?php wp_nonce_field( 'cpic_account_' . (int) $cpic_account['id'] ); ?>

					<div class="cpic-grid cpic-grid--two">
						<p class="cpic-field">
							<label for="cpic-account-label-<?php echo esc_attr( (string) $cpic_account['id'] ); ?>"><?php esc_html_e( 'Label', 'custom-post-image-creator' ); ?></label>
							<input id="cpic-account-label-<?php echo esc_attr( (string) $cpic_account['id'] ); ?>" type="text" name="label" value="<?php echo esc_attr( (string) $cpic_account['label'] ); ?>" <?php disabled( ! $cpic_can_edit ); ?> />
						</p>

						<p class="cpic-field">
							<label for="cpic-account-id-<?php echo esc_attr( (string) $cpic_account['id'] ); ?>">
								<?php
								echo esc_html(
									'facebook' === $cpic_account['provider']
										? __( 'Page ID', 'custom-post-image-creator' )
										: __( 'Chat ID', 'custom-post-image-creator' )
								);
								?>
							</label>
							<input id="cpic-account-id-<?php echo esc_attr( (string) $cpic_account['id'] ); ?>" type="text" name="account_id" value="<?php echo esc_attr( (string) $cpic_account['account_id'] ); ?>" <?php disabled( ! $cpic_can_edit ); ?> />
						</p>
					</div>

					<p class="cpic-field">
						<label for="cpic-account-secret-<?php echo esc_attr( (string) $cpic_account['id'] ); ?>">
							<?php
							echo esc_html(
								'facebook' === $cpic_account['provider']
									? __( 'Page access token', 'custom-post-image-creator' )
									: __( 'Bot token', 'custom-post-image-creator' )
							);
							?>
						</label>
						<input
							id="cpic-account-secret-<?php echo esc_attr( (string) $cpic_account['id'] ); ?>"
							type="password"
							name="<?php echo esc_attr( 'facebook' === $cpic_account['provider'] ? 'page_token' : 'bot_token' ); ?>"
							value=""
							placeholder="<?php esc_attr_e( 'Leave blank to keep the stored token', 'custom-post-image-creator' ); ?>"
							autocomplete="new-password"
							<?php disabled( ! $cpic_can_edit ); ?>
						/>
						<span class="cpic-muted">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: comma separated credential names */
									__( 'Stored: %s', 'custom-post-image-creator' ),
									empty( $cpic_account['credentials_set'] ) ? __( 'none', 'custom-post-image-creator' ) : implode( ', ', (array) $cpic_account['credentials_set'] )
								)
							);
							?>
						</span>
					</p>

					<?php if ( $cpic_can_edit ) : ?>
						<p class="cpic-form__actions">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save connection', 'custom-post-image-creator' ); ?></button>
						</p>
					<?php endif; ?>
				</form>
			</details>

			<?php if ( $cpic_can_edit ) : ?>
				<footer class="cpic-account__actions">
					<?php if ( 'connected' === (string) $cpic_account['status'] ) : ?>
						<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'cpic_account_status', 'cpic_action' => 'disable', 'account' => (int) $cpic_account['id'] ), admin_url( 'admin-post.php' ) ), 'cpic_account_status_disable_' . (int) $cpic_account['id'] ) ); ?>"><?php esc_html_e( 'Pause posting', 'custom-post-image-creator' ); ?></a>
					<?php else : ?>
						<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'cpic_account_status', 'cpic_action' => 'enable', 'account' => (int) $cpic_account['id'] ), admin_url( 'admin-post.php' ) ), 'cpic_account_status_enable_' . (int) $cpic_account['id'] ) ); ?>"><?php esc_html_e( 'Re-enable posting', 'custom-post-image-creator' ); ?></a>
					<?php endif; ?>

					<a
						class="button button-small cpic-danger"
						href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'cpic_account_status', 'cpic_action' => 'delete', 'account' => (int) $cpic_account['id'] ), admin_url( 'admin-post.php' ) ), 'cpic_account_status_delete_' . (int) $cpic_account['id'] ) ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'Remove this account? Delivery history is kept.', 'custom-post-image-creator' ) ); ?>');"
					><?php esc_html_e( 'Remove', 'custom-post-image-creator' ); ?></a>
				</footer>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>

	<?php if ( $cpic_can_edit ) : ?>
		<section class="cpic-card">
			<h2><?php esc_html_e( 'Connect an account', 'custom-post-image-creator' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cpic-form" autocomplete="off">
				<input type="hidden" name="action" value="cpic_save_account" />
				<input type="hidden" name="account" value="0" />
				<?php wp_nonce_field( 'cpic_account_0' ); ?>

				<p class="cpic-field">
					<label for="cpic-new-provider"><?php esc_html_e( 'Platform', 'custom-post-image-creator' ); ?></label>
					<select id="cpic-new-provider" name="provider">
						<?php foreach ( $cpic_providers as $cpic_key => $cpic_label ) : ?>
							<option value="<?php echo esc_attr( (string) $cpic_key ); ?>"><?php echo esc_html( (string) $cpic_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<div class="cpic-grid cpic-grid--two">
					<p class="cpic-field">
						<label for="cpic-new-label"><?php esc_html_e( 'Label', 'custom-post-image-creator' ); ?></label>
						<input id="cpic-new-label" type="text" name="label" placeholder="<?php esc_attr_e( 'Main page', 'custom-post-image-creator' ); ?>" />
					</p>
					<p class="cpic-field">
						<label for="cpic-new-account-id"><?php esc_html_e( 'Page ID / Chat ID', 'custom-post-image-creator' ); ?></label>
						<input id="cpic-new-account-id" type="text" name="account_id" required />
					</p>
				</div>

				<p class="cpic-field">
					<label for="cpic-new-secret"><?php esc_html_e( 'Page access token (Facebook) or bot token (Telegram)', 'custom-post-image-creator' ); ?></label>
					<input id="cpic-new-secret" type="password" name="page_token" value="" autocomplete="new-password" />
					<span class="cpic-muted"><?php esc_html_e( 'Send a bot token in the field above when connecting Telegram.', 'custom-post-image-creator' ); ?></span>
				</p>

				<p class="cpic-field">
					<label for="cpic-new-bot"><?php esc_html_e( 'Telegram bot token', 'custom-post-image-creator' ); ?></label>
					<input id="cpic-new-bot" type="password" name="bot_token" value="" autocomplete="new-password" placeholder="123456:ABC-DEF…" />
				</p>

				<p class="cpic-form__actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Connect account', 'custom-post-image-creator' ); ?></button>
				</p>
			</form>
		</section>
	<?php endif; ?>
</div>
