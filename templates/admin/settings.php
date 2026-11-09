<?php
/**
 * Settings: rendered from the schema, so every field exists exactly once.
 *
 * @package CustomPostImageCreator
 *
 * @var \CPIC\Core\Container $container Container.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cpic_settings = $container->get( 'settings' );
$cpic_defs     = $cpic_settings->definitions();
$cpic_sections = $cpic_settings->sections();
$cpic_active   = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( (string) $_GET['section'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_can_edit = $container->get( 'capabilities' )->can( 'cpic_manage_settings' );
$cpic_notice   = isset( $_GET['cpic_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['cpic_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_error    = get_transient( 'cpic_admin_error_' . get_current_user_id() );

if ( $cpic_error ) {
	delete_transient( 'cpic_admin_error_' . get_current_user_id() );
}

if ( ! isset( $cpic_sections[ $cpic_active ] ) ) {
	$cpic_active = 'general';
}

$cpic_fields = array();
foreach ( $cpic_defs as $cpic_key => $cpic_def ) {
	if ( isset( $cpic_def['section'] ) && $cpic_active === $cpic_def['section'] ) {
		$cpic_fields[ $cpic_key ] = $cpic_def;
	}
}

/**
 * Field label, falling back to a readable version of the option name.
 *
 * @param string              $key  Option key.
 * @param array<string,mixed> $def  Definition.
 * @return string
 */
$cpic_field_label = static function ( $key, array $def ) {
	if ( ! empty( $def['label'] ) ) {
		return (string) $def['label'];
	}

	return ucfirst( trim( str_replace( array( 'cpic_', '_' ), array( '', ' ' ), $key ) ) );
};

$cpic_title    = __( 'Settings', 'custom-post-image-creator' );
$cpic_subtitle = __( 'Only settings that change behaviour are listed. Secrets are stored encrypted and are never sent to the browser.', 'custom-post-image-creator' );

require __DIR__ . '/_header.php';
?>
<div class="wrap cpic-wrap">

	<?php if ( 'saved' === $cpic_notice ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'Settings saved.', 'custom-post-image-creator' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $cpic_error ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( (string) $cpic_error ); ?></p></div>
	<?php endif; ?>

	<nav class="cpic-tabs">
		<?php foreach ( $cpic_sections as $cpic_slug => $cpic_label ) : ?>
			<a
				class="cpic-tab<?php echo $cpic_active === (string) $cpic_slug ? ' is-active' : ''; ?>"
				href="<?php echo esc_url( add_query_arg( array( 'page' => 'cpic-settings', 'section' => (string) $cpic_slug ), admin_url( 'admin.php' ) ) ); ?>"
			><?php echo esc_html( (string) $cpic_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( 'social' === $cpic_active ) : ?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'These are the 6.x single-account fields. They are kept so upgrades keep working; new connections should be added under Social accounts, where each page and channel is listed separately.', 'custom-post-image-creator' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cpic-form">
		<input type="hidden" name="action" value="cpic_save_settings" />
		<input type="hidden" name="cpic_section" value="<?php echo esc_attr( $cpic_active ); ?>" />
		<?php wp_nonce_field( 'cpic_save_settings' ); ?>

		<?php if ( empty( $cpic_fields ) ) : ?>
			<p class="cpic-muted"><?php esc_html_e( 'This section has no settings.', 'custom-post-image-creator' ); ?></p>
		<?php else : ?>
			<?php foreach ( $cpic_fields as $cpic_key => $cpic_def ) : ?>
				<?php
				$cpic_value = $cpic_settings->get( $cpic_key );
				$cpic_type  = isset( $cpic_def['type'] ) ? (string) $cpic_def['type'] : 'text';
				$cpic_id    = 'cpic-setting-' . $cpic_key;
				?>
				<p class="cpic-field cpic-field--<?php echo esc_attr( $cpic_type ); ?>">
					<?php if ( 'bool' === $cpic_type ) : ?>
						<label for="<?php echo esc_attr( $cpic_id ); ?>">
							<input
								id="<?php echo esc_attr( $cpic_id ); ?>"
								type="checkbox"
								name="cpic[<?php echo esc_attr( $cpic_key ); ?>]"
								value="1"
								<?php checked( ! empty( $cpic_value ) ); ?>
								<?php disabled( ! $cpic_can_edit ); ?>
							/>
							<?php echo esc_html( $cpic_field_label( $cpic_key, $cpic_def ) ); ?>
						</label>
					<?php else : ?>
						<label for="<?php echo esc_attr( $cpic_id ); ?>"><?php echo esc_html( $cpic_field_label( $cpic_key, $cpic_def ) ); ?></label>

						<?php if ( 'textarea' === $cpic_type ) : ?>
							<textarea id="<?php echo esc_attr( $cpic_id ); ?>" name="cpic[<?php echo esc_attr( $cpic_key ); ?>]" rows="4" <?php disabled( ! $cpic_can_edit ); ?>><?php echo esc_textarea( is_scalar( $cpic_value ) ? (string) $cpic_value : '' ); ?></textarea>
						<?php elseif ( 'color' === $cpic_type ) : ?>
							<input id="<?php echo esc_attr( $cpic_id ); ?>" type="color" name="cpic[<?php echo esc_attr( $cpic_key ); ?>]" value="<?php echo esc_attr( (string) $cpic_value ); ?>" <?php disabled( ! $cpic_can_edit ); ?> />
						<?php elseif ( 'secret' === $cpic_type ) : ?>
							<input
								id="<?php echo esc_attr( $cpic_id ); ?>"
								type="password"
								name="cpic[<?php echo esc_attr( $cpic_key ); ?>]"
								value=""
								autocomplete="new-password"
								placeholder="<?php echo '' !== (string) $cpic_value ? esc_attr__( 'A value is stored — leave blank to keep it', 'custom-post-image-creator' ) : esc_attr__( 'Not set', 'custom-post-image-creator' ); ?>"
								<?php disabled( ! $cpic_can_edit ); ?>
							/>
						<?php elseif ( 'csv' === $cpic_type || 'url_list' === $cpic_type ) : ?>
							<input
								id="<?php echo esc_attr( $cpic_id ); ?>"
								type="text"
								name="cpic[<?php echo esc_attr( $cpic_key ); ?>]"
								value="<?php echo esc_attr( is_array( $cpic_value ) ? implode( ', ', $cpic_value ) : (string) $cpic_value ); ?>"
								<?php disabled( ! $cpic_can_edit ); ?>
							/>
						<?php elseif ( 'bool' !== $cpic_type && in_array( $cpic_type, array( 'int', 'float' ), true ) ) : ?>
							<input id="<?php echo esc_attr( $cpic_id ); ?>" type="number" step="<?php echo 'float' === $cpic_type ? '0.01' : '1'; ?>" name="cpic[<?php echo esc_attr( $cpic_key ); ?>]" value="<?php echo esc_attr( (string) $cpic_value ); ?>" <?php disabled( ! $cpic_can_edit ); ?> />
						<?php else : ?>
							<input
								id="<?php echo esc_attr( $cpic_id ); ?>"
								type="<?php echo in_array( $cpic_type, array( 'url', 'email' ), true ) ? esc_attr( $cpic_type ) : 'text'; ?>"
								name="cpic[<?php echo esc_attr( $cpic_key ); ?>]"
								value="<?php echo esc_attr( is_scalar( $cpic_value ) ? (string) $cpic_value : '' ); ?>"
								<?php disabled( ! $cpic_can_edit ); ?>
							/>
						<?php endif; ?>

						<code class="cpic-field__key"><?php echo esc_html( (string) $cpic_key ); ?></code>
					<?php endif; ?>
				</p>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( $cpic_can_edit ) : ?>
			<p class="cpic-form__actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'custom-post-image-creator' ); ?></button>
			</p>
		<?php endif; ?>
	</form>

	<section class="cpic-card">
		<h2><?php esc_html_e( 'Where the important switches live', 'custom-post-image-creator' ); ?></h2>
		<ul class="cpic-list cpic-list--plain">
			<li>
				<?php esc_html_e( 'Automatic publishing', 'custom-post-image-creator' ); ?>:
				<strong><?php echo esc_html( $cpic_settings->is_enabled( 'cpic_auto_publish' ) ? __( 'on', 'custom-post-image-creator' ) : __( 'off — posts are published only when you press Publish', 'custom-post-image-creator' ) ); ?></strong>
			</li>
			<li>
				<?php esc_html_e( 'AI assistance', 'custom-post-image-creator' ); ?>:
				<strong><?php echo esc_html( $cpic_settings->is_enabled( 'cpic_ai_enabled' ) ? __( 'on for permitted users', 'custom-post-image-creator' ) : __( 'off', 'custom-post-image-creator' ) ); ?></strong>
			</li>
			<li>
				<?php esc_html_e( 'Preferred renderer', 'custom-post-image-creator' ); ?>:
				<strong><?php echo esc_html( (string) $cpic_settings->get( 'cpic_renderer' ) ); ?></strong>
			</li>
		</ul>
	</section>
</div>
