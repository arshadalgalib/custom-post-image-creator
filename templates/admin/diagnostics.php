<?php
/**
 * Diagnostics: the honest state of the installation.
 *
 * @package CustomPostImageCreator
 *
 * @var \CPIC\Core\Container $container Container.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cpic_report = $container->get( 'health' )->report();
$cpic_logs   = $container->get( 'logger' )->recent( array( 'limit' => 25 ) );
$cpic_audit  = $container->get( 'audit' )->recent( 15 );
$cpic_notice = isset( $_GET['cpic_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['cpic_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_error  = get_transient( 'cpic_admin_error_' . get_current_user_id() );

if ( $cpic_error ) {
	delete_transient( 'cpic_admin_error_' . get_current_user_id() );
}

$cpic_title    = __( 'Diagnostics', 'custom-post-image-creator' );
$cpic_subtitle = __( 'What this installation can and cannot do right now. No passwords, tokens or server paths are shown here.', 'custom-post-image-creator' );

require __DIR__ . '/_header.php';
?>
<div class="wrap cpic-wrap">

	<?php if ( 'repaired' === $cpic_notice ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'Repair finished: the schema was verified, the queue lock was released and scheduled events were rebuilt.', 'custom-post-image-creator' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $cpic_error ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( (string) $cpic_error ); ?></p></div>
	<?php endif; ?>

	<div class="cpic-status cpic-status--<?php echo $cpic_report['ok'] ? 'ok' : 'attention'; ?>">
		<strong>
			<?php
			echo esc_html(
				$cpic_report['ok']
					? __( 'No problems detected.', 'custom-post-image-creator' )
					: __( 'Some things need attention.', 'custom-post-image-creator' )
			);
			?>
		</strong>
		<?php if ( ! empty( $cpic_report['problems'] ) ) : ?>
			<ul class="cpic-notice-list">
				<?php foreach ( $cpic_report['problems'] as $cpic_problem ) : ?>
					<li><?php echo esc_html( (string) $cpic_problem ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>

	<div class="cpic-grid cpic-grid--two">
		<section class="cpic-card">
			<h2><?php esc_html_e( 'Versions', 'custom-post-image-creator' ); ?></h2>
			<ul class="cpic-list cpic-list--plain">
				<li><?php esc_html_e( 'Plugin', 'custom-post-image-creator' ); ?>: <strong><?php echo esc_html( (string) $cpic_report['plugin']['version'] ); ?></strong></li>
				<li><?php esc_html_e( 'Database schema', 'custom-post-image-creator' ); ?>: <strong><?php echo esc_html( (string) $cpic_report['plugin']['db_version'] ); ?></strong> (<?php echo esc_html( (string) $cpic_report['database']['expected'] ); ?>)</li>
				<li><?php esc_html_e( 'PHP', 'custom-post-image-creator' ); ?>: <strong><?php echo esc_html( (string) $cpic_report['plugin']['php'] ); ?></strong></li>
				<li><?php esc_html_e( 'WordPress', 'custom-post-image-creator' ); ?>: <strong><?php echo esc_html( (string) $cpic_report['plugin']['wordpress'] ); ?></strong></li>
			</ul>
		</section>

		<section class="cpic-card">
			<h2><?php esc_html_e( 'Database', 'custom-post-image-creator' ); ?></h2>
			<ul class="cpic-list cpic-list--plain">
				<li><?php esc_html_e( 'All tables present', 'custom-post-image-creator' ); ?>: <strong><?php echo esc_html( empty( $cpic_report['database']['missing'] ) ? __( 'yes', 'custom-post-image-creator' ) : implode( ', ', (array) $cpic_report['database']['missing'] ) ); ?></strong></li>
				<li><?php esc_html_e( 'Last migration', 'custom-post-image-creator' ); ?>: <strong><?php echo esc_html( '' !== (string) $cpic_report['database']['last_run'] ? (string) $cpic_report['database']['last_run'] : __( 'unknown', 'custom-post-image-creator' ) ); ?></strong></li>
			</ul>
			<p class="cpic-muted"><?php esc_html_e( 'Repair re-runs the schema installer. It never deletes content.', 'custom-post-image-creator' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cpic_repair" />
				<?php wp_nonce_field( 'cpic_repair' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Run repair', 'custom-post-image-creator' ); ?></button>
			</form>
		</section>
	</div>

	<section class="cpic-card">
		<h2><?php esc_html_e( 'Rendering', 'custom-post-image-creator' ); ?></h2>
		<p>
			<strong><?php echo esc_html( (string) $cpic_report['renderer']['complex_script_mode'] ); ?></strong>
			—
			<?php
			echo esc_html(
				! empty( $cpic_report['renderer']['background_capable'] )
					? __( 'Complex scripts can be rendered in the background.', 'custom-post-image-creator' )
					: __( 'Bengali text is shaped by the browser. Background publishing of Bengali images needs an editor session, so scheduled posts that need one will report that clearly instead of producing broken text.', 'custom-post-image-creator' )
			);
			?>
		</p>
		<table class="widefat striped cpic-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Renderer', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Available', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Complex scripts', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Background', 'custom-post-image-creator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cpic_report['renderer']['renderers'] as $cpic_renderer ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( isset( $cpic_renderer['label'] ) ? $cpic_renderer['label'] : $cpic_renderer['id'] ) ); ?></td>
						<td><?php echo esc_html( ! empty( $cpic_renderer['available'] ) ? __( 'yes', 'custom-post-image-creator' ) : __( 'no', 'custom-post-image-creator' ) ); ?></td>
						<td><?php echo esc_html( ! empty( $cpic_renderer['complex_scripts'] ) ? __( 'yes', 'custom-post-image-creator' ) : __( 'no', 'custom-post-image-creator' ) ); ?></td>
						<td><?php echo esc_html( ! empty( $cpic_renderer['background'] ) ? __( 'yes', 'custom-post-image-creator' ) : __( 'no', 'custom-post-image-creator' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<div class="cpic-grid cpic-grid--two">
		<section class="cpic-card">
			<h2><?php esc_html_e( 'Fonts', 'custom-post-image-creator' ); ?></h2>
			<?php if ( ! empty( $cpic_report['fonts']['ok'] ) ) : ?>
				<p><?php esc_html_e( 'All bundled Bengali faces are present.', 'custom-post-image-creator' ); ?></p>
			<?php else : ?>
				<p class="cpic-error-text">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: missing font count */
							_n( '%d font file is missing. Bengali text will fall back to the browser default.', '%d font files are missing. Bengali text will fall back to the browser default.', (int) $cpic_report['fonts']['missing'], 'custom-post-image-creator' ),
							(int) $cpic_report['fonts']['missing']
						)
					);
					?>
				</p>
			<?php endif; ?>
			<ul class="cpic-list cpic-list--plain">
				<?php foreach ( (array) $cpic_report['fonts']['families'] as $cpic_family ) : ?>
					<li><?php echo esc_html( (string) $cpic_family ); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>

		<section class="cpic-card">
			<h2><?php esc_html_e( 'Publishing', 'custom-post-image-creator' ); ?></h2>
			<ul class="cpic-list cpic-list--plain">
				<?php foreach ( (array) $cpic_report['publishing']['providers'] as $cpic_provider => $cpic_row ) : ?>
					<li>
						<?php echo esc_html( (string) $cpic_row['label'] ); ?>:
						<strong><?php echo esc_html( sprintf( '%d / %d', (int) $cpic_row['connected'], (int) $cpic_row['total'] ) ); ?></strong>
						<?php esc_html_e( 'connected', 'custom-post-image-creator' ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( (int) $cpic_report['publishing']['expiring'] > 0 ) : ?>
				<p class="cpic-error-text">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: count */
							_n( '%d access token expires within a week.', '%d access tokens expire within a week.', (int) $cpic_report['publishing']['expiring'], 'custom-post-image-creator' ),
							(int) $cpic_report['publishing']['expiring']
						)
					);
					?>
				</p>
			<?php endif; ?>
		</section>
	</div>

	<section class="cpic-card">
		<h2><?php esc_html_e( 'Recent activity', 'custom-post-image-creator' ); ?></h2>

		<?php if ( empty( $cpic_logs ) ) : ?>
			<p class="cpic-muted"><?php esc_html_e( 'No log entries yet.', 'custom-post-image-creator' ); ?></p>
		<?php else : ?>
			<table class="widefat striped cpic-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'When', 'custom-post-image-creator' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Level', 'custom-post-image-creator' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'custom-post-image-creator' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Message', 'custom-post-image-creator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $cpic_logs as $cpic_log ) : ?>
						<tr>
							<td class="cpic-muted"><?php echo esc_html( (string) $cpic_log['created_at'] ); ?></td>
							<td><span class="cpic-badge cpic-badge--<?php echo esc_attr( (string) $cpic_log['level'] ); ?>"><?php echo esc_html( (string) $cpic_log['level'] ); ?></span></td>
							<td><code><?php echo esc_html( (string) $cpic_log['log_type'] ); ?></code></td>
							<td><?php echo esc_html( (string) $cpic_log['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="cpic-card">
		<h2><?php esc_html_e( 'Who changed what', 'custom-post-image-creator' ); ?></h2>

		<?php if ( empty( $cpic_audit ) ) : ?>
			<p class="cpic-muted"><?php esc_html_e( 'No audited actions yet.', 'custom-post-image-creator' ); ?></p>
		<?php else : ?>
			<ul class="cpic-list">
				<?php foreach ( $cpic_audit as $cpic_entry ) : ?>
					<li class="cpic-list__item">
						<span class="cpic-list__title"><code><?php echo esc_html( (string) $cpic_entry['action'] ); ?></code></span>
						<span class="cpic-list__meta">
							<?php echo esc_html( (string) $cpic_entry['message'] ); ?>
							· <?php echo esc_html( (string) $cpic_entry['created_at'] ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
</div>
