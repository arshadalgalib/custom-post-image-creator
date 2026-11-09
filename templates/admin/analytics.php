<?php
/**
 * Analytics: only what the plugin actually knows.
 *
 * @package CustomPostImageCreator
 *
 * @var \CPIC\Core\Container $container Container.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cpic_days  = isset( $_GET['days'] ) ? max( 1, min( 365, (int) $_GET['days'] ) ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_data  = $container->get( 'analytics' )->overview( array( 'days' => $cpic_days ) );

$cpic_title    = __( 'Analytics', 'custom-post-image-creator' );
$cpic_subtitle = __( 'Delivery and rendering numbers come from this site. Platform reach is only shown once it has actually been fetched.', 'custom-post-image-creator' );

require __DIR__ . '/_header.php';
?>
<div class="wrap cpic-wrap">

	<nav class="cpic-tabs">
		<?php foreach ( array( 7, 30, 90, 365 ) as $cpic_option ) : ?>
			<a class="cpic-tab<?php echo (int) $cpic_days === (int) $cpic_option ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'cpic-analytics', 'days' => (int) $cpic_option ), admin_url( 'admin.php' ) ) ); ?>">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of days */
						_n( '%d day', '%d days', (int) $cpic_option, 'custom-post-image-creator' ),
						(int) $cpic_option
					)
				);
				?>
			</a>
		<?php endforeach; ?>
	</nav>

	<p class="cpic-muted">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: start date, 2: end date */
				__( 'Showing %1$s to %2$s (site time).', 'custom-post-image-creator' ),
				mysql2date( (string) get_option( 'date_format', 'M j, Y' ), (string) $cpic_data['range']['from'] ),
				mysql2date( (string) get_option( 'date_format', 'M j, Y' ), (string) $cpic_data['range']['to'] )
			)
		);
		?>
	</p>

	<h2><?php esc_html_e( 'Measured on this site', 'custom-post-image-creator' ); ?></h2>

	<div class="cpic-grid cpic-grid--stats">
		<div class="cpic-card cpic-stat">
			<span class="cpic-stat__value"><?php echo esc_html( number_format_i18n( (int) $cpic_data['internal']['deliveries']['published'] ) ); ?></span>
			<span class="cpic-stat__label"><?php esc_html_e( 'Published deliveries', 'custom-post-image-creator' ); ?></span>
		</div>
		<div class="cpic-card cpic-stat<?php echo (int) $cpic_data['internal']['deliveries']['failed'] > 0 ? ' cpic-stat--alert' : ''; ?>">
			<span class="cpic-stat__value"><?php echo esc_html( number_format_i18n( (int) $cpic_data['internal']['deliveries']['failed'] ) ); ?></span>
			<span class="cpic-stat__label"><?php esc_html_e( 'Failed deliveries', 'custom-post-image-creator' ); ?></span>
		</div>
		<div class="cpic-card cpic-stat">
			<span class="cpic-stat__value"><?php echo esc_html( '' === (string) $cpic_data['internal']['success_rate'] ? '—' : (string) $cpic_data['internal']['success_rate'] . '%' ); ?></span>
			<span class="cpic-stat__label"><?php esc_html_e( 'Success rate', 'custom-post-image-creator' ); ?></span>
			<span class="cpic-stat__meta"><?php esc_html_e( 'published / (published + failed)', 'custom-post-image-creator' ); ?></span>
		</div>
		<div class="cpic-card cpic-stat">
			<span class="cpic-stat__value"><?php echo esc_html( number_format_i18n( (int) $cpic_data['internal']['artifacts']['created'] ) ); ?></span>
			<span class="cpic-stat__label"><?php esc_html_e( 'Images generated', 'custom-post-image-creator' ); ?></span>
			<span class="cpic-stat__meta"><?php echo esc_html( size_format( (int) $cpic_data['internal']['artifacts']['bytes'] ) ); ?></span>
		</div>
	</div>

	<div class="cpic-grid cpic-grid--two">
		<section class="cpic-card">
			<h2><?php esc_html_e( 'By platform', 'custom-post-image-creator' ); ?></h2>

			<?php if ( empty( $cpic_data['providers'] ) ) : ?>
				<p class="cpic-muted"><?php esc_html_e( 'No deliveries in this period.', 'custom-post-image-creator' ); ?></p>
			<?php else : ?>
				<table class="widefat striped cpic-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Platform', 'custom-post-image-creator' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Published', 'custom-post-image-creator' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Failed', 'custom-post-image-creator' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Total', 'custom-post-image-creator' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $cpic_data['providers'] as $cpic_provider => $cpic_row ) : ?>
							<tr>
								<td><?php echo esc_html( ucfirst( (string) $cpic_provider ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $cpic_row['published'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $cpic_row['failed'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $cpic_row['total'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<section class="cpic-card">
			<h2><?php esc_html_e( 'Why deliveries failed', 'custom-post-image-creator' ); ?></h2>

			<?php if ( empty( $cpic_data['failures'] ) ) : ?>
				<p class="cpic-muted"><?php esc_html_e( 'No failures in this period.', 'custom-post-image-creator' ); ?></p>
			<?php else : ?>
				<ul class="cpic-list">
					<?php foreach ( $cpic_data['failures'] as $cpic_failure ) : ?>
						<li class="cpic-list__item">
							<code><?php echo esc_html( (string) $cpic_failure['code'] ); ?></code>
							<span class="cpic-list__meta"><?php echo esc_html( number_format_i18n( (int) $cpic_failure['total'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	</div>

	<section class="cpic-card">
		<h2><?php esc_html_e( 'Platform engagement', 'custom-post-image-creator' ); ?></h2>

		<?php if ( empty( $cpic_data['external']['available'] ) ) : ?>
			<p class="cpic-muted"><?php echo esc_html( (string) $cpic_data['external']['message'] ); ?></p>
			<p class="cpic-muted"><?php esc_html_e( 'These numbers are never estimated. They appear after a delivery\'s metrics are fetched from the platform.', 'custom-post-image-creator' ); ?></p>
		<?php else : ?>
			<table class="widefat striped cpic-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Metric', 'custom-post-image-creator' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Total', 'custom-post-image-creator' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Samples', 'custom-post-image-creator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $cpic_data['external']['metrics'] as $cpic_metric => $cpic_values ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $cpic_metric ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $cpic_values['total'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $cpic_values['samples'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="cpic-card">
		<h2><?php esc_html_e( 'Render performance', 'custom-post-image-creator' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: milliseconds */
					__( 'Average browser render: %s ms', 'custom-post-image-creator' ),
					number_format_i18n( (float) $cpic_data['internal']['render_ms'], 1 )
				)
			);
			?>
		</p>
		<p class="cpic-muted">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: retry count */
					_n( '%d automatic retry in this period.', '%d automatic retries in this period.', (int) $cpic_data['internal']['retries'], 'custom-post-image-creator' ),
					(int) $cpic_data['internal']['retries']
				)
			);
			?>
		</p>
	</section>
</div>
