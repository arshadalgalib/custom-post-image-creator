<?php
/**
 * Campaigns.
 *
 * @package CustomPostImageCreator
 *
 * @var \CPIC\Core\Container $container Container.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cpic_campaigns = $container->get( 'campaign_manager' );
$cpic_status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_rows      = $cpic_campaigns->all( array( 'status' => $cpic_status ) );
$cpic_templates = $container->get( 'templates' )->all( array( 'active' => true ) );
$cpic_can_edit  = $container->get( 'capabilities' )->can( 'cpic_manage_campaigns' );
$cpic_notice    = isset( $_GET['cpic_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['cpic_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_error     = get_transient( 'cpic_admin_error_' . get_current_user_id() );

if ( $cpic_error ) {
	delete_transient( 'cpic_admin_error_' . get_current_user_id() );
}

$cpic_title    = __( 'Campaigns', 'custom-post-image-creator' );
$cpic_subtitle = __( 'Group posts under one design and track what has actually been published.', 'custom-post-image-creator' );
$cpic_actions  = array(
	array( 'label' => __( 'Calendar', 'custom-post-image-creator' ), 'url' => admin_url( 'admin.php?page=cpic-calendar' ) ),
);

require __DIR__ . '/_header.php';
?>
<div class="wrap cpic-wrap">

	<?php if ( 'saved' === $cpic_notice ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'The campaign was saved.', 'custom-post-image-creator' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $cpic_error ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( (string) $cpic_error ); ?></p></div>
	<?php endif; ?>

	<nav class="cpic-tabs">
		<?php
		$cpic_tabs = array_merge( array( '' => __( 'All', 'custom-post-image-creator' ) ), array_combine(
			\CPIC\Campaigns\CampaignManager::STATUSES,
			array_map( 'ucfirst', \CPIC\Campaigns\CampaignManager::STATUSES )
		) );
		?>
		<?php foreach ( $cpic_tabs as $cpic_key => $cpic_label ) : ?>
			<a
				class="cpic-tab<?php echo $cpic_status === (string) $cpic_key ? ' is-active' : ''; ?>"
				href="<?php echo esc_url( add_query_arg( array( 'page' => 'cpic-campaigns', 'status' => (string) $cpic_key ), admin_url( 'admin.php' ) ) ); ?>"
			><?php echo esc_html( (string) $cpic_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( empty( $cpic_rows ) ) : ?>
		<div class="cpic-empty">
			<h2><?php esc_html_e( 'No campaigns yet', 'custom-post-image-creator' ); ?></h2>
			<p><?php esc_html_e( 'A campaign ties a set of posts to one design and one schedule window, so progress is measurable.', 'custom-post-image-creator' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped cpic-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Campaign', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Window', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Posts', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Published', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Pending', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Failed', 'custom-post-image-creator' ); ?></th>
					<?php if ( $cpic_can_edit ) : ?>
						<th scope="col"><?php esc_html_e( 'Actions', 'custom-post-image-creator' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cpic_rows as $cpic_campaign ) : ?>
					<?php $cpic_stats = isset( $cpic_campaign['stats'] ) ? $cpic_campaign['stats'] : array( 'posts' => 0, 'published' => 0, 'pending' => 0, 'failed' => 0 ); ?>
					<tr>
						<td>
							<strong><?php echo esc_html( (string) $cpic_campaign['name'] ); ?></strong>
							<?php if ( ! empty( $cpic_campaign['description'] ) ) : ?>
								<br /><span class="cpic-muted"><?php echo esc_html( wp_trim_words( (string) $cpic_campaign['description'], 14 ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><span class="cpic-badge cpic-badge--<?php echo esc_attr( (string) $cpic_campaign['status'] ); ?>"><?php echo esc_html( (string) $cpic_campaign['status'] ); ?></span></td>
						<td class="cpic-muted">
							<?php
							$cpic_window = array();
							if ( ! empty( $cpic_campaign['start_at'] ) ) {
								$cpic_window[] = mysql2date( (string) get_option( 'date_format', 'M j, Y' ), (string) $cpic_campaign['start_at'] );
							}
							if ( ! empty( $cpic_campaign['end_at'] ) ) {
								$cpic_window[] = mysql2date( (string) get_option( 'date_format', 'M j, Y' ), (string) $cpic_campaign['end_at'] );
							}
							echo esc_html( empty( $cpic_window ) ? __( 'No window set', 'custom-post-image-creator' ) : implode( ' → ', $cpic_window ) );
							?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (int) $cpic_stats['posts'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $cpic_stats['published'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $cpic_stats['pending'] ) ); ?></td>
						<td<?php echo (int) $cpic_stats['failed'] > 0 ? ' class="cpic-error-text"' : ''; ?>><?php echo esc_html( number_format_i18n( (int) $cpic_stats['failed'] ) ); ?></td>
						<?php if ( $cpic_can_edit ) : ?>
							<td class="cpic-table__actions">
								<?php
								$cpic_actions_map = array(
									'pause'    => __( 'Pause', 'custom-post-image-creator' ),
									'resume'   => __( 'Resume', 'custom-post-image-creator' ),
									'complete' => __( 'Complete', 'custom-post-image-creator' ),
									'archive'  => __( 'Archive', 'custom-post-image-creator' ),
									'delete'   => __( 'Delete', 'custom-post-image-creator' ),
								);
								?>
								<?php foreach ( $cpic_actions_map as $cpic_action => $cpic_label ) : ?>
									<?php
									if ( 'pause' === $cpic_action && 'active' !== (string) $cpic_campaign['status'] ) {
										continue;
									}
									if ( 'resume' === $cpic_action && 'paused' !== (string) $cpic_campaign['status'] ) {
										continue;
									}
									?>
									<a
										class="button button-small<?php echo 'delete' === $cpic_action ? ' cpic-danger' : ''; ?>"
										href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'cpic_campaign_status', 'cpic_action' => $cpic_action, 'campaign' => (int) $cpic_campaign['id'] ), admin_url( 'admin-post.php' ) ), 'cpic_campaign_status_' . $cpic_action . '_' . (int) $cpic_campaign['id'] ) ); ?>"
										<?php if ( 'delete' === $cpic_action ) : ?>
											onclick="return confirm('<?php echo esc_js( __( 'Delete this campaign? Posts keep their deliveries.', 'custom-post-image-creator' ) ); ?>');"
										<?php endif; ?>
									><?php echo esc_html( $cpic_label ); ?></a>
								<?php endforeach; ?>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $cpic_can_edit ) : ?>
		<section class="cpic-card">
			<h2><?php esc_html_e( 'New campaign', 'custom-post-image-creator' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cpic-form">
				<input type="hidden" name="action" value="cpic_save_campaign" />
				<input type="hidden" name="campaign" value="0" />
				<?php wp_nonce_field( 'cpic_campaign_0' ); ?>

				<div class="cpic-grid cpic-grid--two">
					<p class="cpic-field">
						<label for="cpic-campaign-name"><?php esc_html_e( 'Name', 'custom-post-image-creator' ); ?></label>
						<input id="cpic-campaign-name" type="text" name="name" required />
					</p>
					<p class="cpic-field">
						<label for="cpic-campaign-template"><?php esc_html_e( 'Default design', 'custom-post-image-creator' ); ?></label>
						<select id="cpic-campaign-template" name="template_id">
							<option value="0"><?php esc_html_e( 'Use the site default', 'custom-post-image-creator' ); ?></option>
							<?php foreach ( $cpic_templates as $cpic_template ) : ?>
								<option value="<?php echo esc_attr( (string) (int) $cpic_template['id'] ); ?>"><?php echo esc_html( (string) $cpic_template['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="cpic-field">
						<label for="cpic-campaign-start"><?php esc_html_e( 'Starts', 'custom-post-image-creator' ); ?></label>
						<input id="cpic-campaign-start" type="datetime-local" name="start_at" />
					</p>
					<p class="cpic-field">
						<label for="cpic-campaign-end"><?php esc_html_e( 'Ends', 'custom-post-image-creator' ); ?></label>
						<input id="cpic-campaign-end" type="datetime-local" name="end_at" />
					</p>
				</div>

				<p class="cpic-field">
					<label for="cpic-campaign-description"><?php esc_html_e( 'Description', 'custom-post-image-creator' ); ?></label>
					<textarea id="cpic-campaign-description" name="description" rows="3"></textarea>
				</p>

				<p class="cpic-form__actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create campaign', 'custom-post-image-creator' ); ?></button>
				</p>
			</form>
		</section>
	<?php endif; ?>
</div>
