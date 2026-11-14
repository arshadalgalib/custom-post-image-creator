<?php
/**
 * Dashboard screen.
 *
 * @package CustomPostImageCreator
 *
 * @var \CPIC\Core\Container $container Container.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cpic_health    = $container->get( 'health' )->summary();
$cpic_queue     = $container->get( 'queue' )->stats();
$cpic_artifacts = $container->get( 'artifact_manager' )->summary();
$cpic_accounts  = $container->get( 'accounts' )->summary();
$cpic_publish   = $container->get( 'deliveries' )->status_counts();
$cpic_upcoming  = $container->get( 'calendar' )->upcoming( 5 );
$cpic_recent    = $container->get( 'artifacts' )->select( array(), 'id DESC', 6 );

$cpic_title    = __( 'Post Image Creator', 'custom-post-image-creator' );
$cpic_subtitle = sprintf(
	/* translators: 1: plugin version, 2: database version */
	__( 'Version %1$s · schema %2$s', 'custom-post-image-creator' ),
	CPIC_VERSION,
	(string) get_option( 'cpic_db_version', '—' )
);
$cpic_actions = array(
	array( 'label' => __( 'Open design studio', 'custom-post-image-creator' ), 'url' => admin_url( 'admin.php?page=cpic-studio' ), 'primary' => true ),
	array( 'label' => __( 'Templates', 'custom-post-image-creator' ), 'url' => admin_url( 'admin.php?page=cpic-templates' ) ),
);

require __DIR__ . '/_header.php';
?>
<div class="wrap cpic-wrap">

	<?php if ( ! empty( $cpic_health['problems'] ) ) : ?>
		<div class="notice notice-warning inline">
			<ul class="cpic-notice-list">
				<?php foreach ( $cpic_health['problems'] as $cpic_problem ) : ?>
					<li><?php echo esc_html( $cpic_problem ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<div class="cpic-grid cpic-grid--stats">
		<div class="cpic-card cpic-stat">
			<span class="cpic-stat__value"><?php echo esc_html( number_format_i18n( (int) $cpic_artifacts['total'] ) ); ?></span>
			<span class="cpic-stat__label"><?php esc_html_e( 'Images generated', 'custom-post-image-creator' ); ?></span>
			<span class="cpic-stat__meta"><?php echo esc_html( size_format( (int) $cpic_artifacts['bytes'] ) ); ?></span>
		</div>
		<div class="cpic-card cpic-stat">
			<span class="cpic-stat__value"><?php echo esc_html( number_format_i18n( (int) $cpic_publish['published'] ) ); ?></span>
			<span class="cpic-stat__label"><?php esc_html_e( 'Published deliveries', 'custom-post-image-creator' ); ?></span>
			<span class="cpic-stat__meta">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: failed, 2: queued */
						__( '%1$d failed · %2$d waiting', 'custom-post-image-creator' ),
						(int) $cpic_publish['failed'],
						(int) $cpic_publish['queued'] + (int) $cpic_publish['pending']
					)
				);
				?>
			</span>
		</div>
		<div class="cpic-card cpic-stat">
			<span class="cpic-stat__value"><?php echo esc_html( number_format_i18n( (int) $cpic_queue['pending'] ) ); ?></span>
			<span class="cpic-stat__label"><?php esc_html_e( 'Jobs in queue', 'custom-post-image-creator' ); ?></span>
			<span class="cpic-stat__meta">
				<?php
				echo esc_html(
					$cpic_queue['lag'] > 0
						/* translators: %s: human time difference */
						? sprintf( __( 'oldest waiting %s', 'custom-post-image-creator' ), human_time_diff( time() - (int) $cpic_queue['lag'] ) )
						: __( 'queue is clear', 'custom-post-image-creator' )
				);
				?>
			</span>
		</div>
		<div class="cpic-card cpic-stat">
			<span class="cpic-stat__value"><?php echo esc_html( (string) count( $cpic_accounts ) ); ?></span>
			<span class="cpic-stat__label"><?php esc_html_e( 'Connected platforms', 'custom-post-image-creator' ); ?></span>
			<span class="cpic-stat__meta">
				<?php
				$cpic_connected = 0;
				foreach ( $cpic_accounts as $cpic_provider ) {
					$cpic_connected += (int) $cpic_provider['connected'];
				}
				echo esc_html(
					sprintf(
						/* translators: %d: number of connected accounts */
						_n( '%d account connected', '%d accounts connected', $cpic_connected, 'custom-post-image-creator' ),
						$cpic_connected
					)
				);
				?>
			</span>
		</div>
	</div>

	<div class="cpic-grid cpic-grid--two">
		<section class="cpic-card">
			<h2><?php esc_html_e( 'Coming up', 'custom-post-image-creator' ); ?></h2>

			<?php if ( empty( $cpic_upcoming ) ) : ?>
				<p class="cpic-muted"><?php esc_html_e( 'Nothing is scheduled in the next two weeks.', 'custom-post-image-creator' ); ?></p>
			<?php else : ?>
				<ul class="cpic-list">
					<?php foreach ( $cpic_upcoming as $cpic_event ) : ?>
						<li class="cpic-list__item">
							<span class="cpic-badge cpic-badge--<?php echo esc_attr( (string) $cpic_event['status'] ); ?>"><?php echo esc_html( (string) $cpic_event['status'] ); ?></span>
							<span class="cpic-list__title"><?php echo esc_html( (string) $cpic_event['title'] ); ?></span>
							<span class="cpic-list__meta">
								<?php
								echo esc_html(
									mysql2date(
										(string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'H:i' ),
										(string) $cpic_event['when']
									)
								);
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=cpic-calendar' ) ); ?>"><?php esc_html_e( 'View calendar', 'custom-post-image-creator' ); ?></a></p>
			<?php endif; ?>
		</section>

		<section class="cpic-card">
			<h2><?php esc_html_e( 'Recent images', 'custom-post-image-creator' ); ?></h2>

			<?php if ( empty( $cpic_recent ) ) : ?>
				<p class="cpic-muted"><?php esc_html_e( 'No images have been generated yet. Open a post and design one.', 'custom-post-image-creator' ); ?></p>
			<?php else : ?>
				<div class="cpic-thumbs">
					<?php foreach ( $cpic_recent as $cpic_row ) : ?>
						<?php $cpic_row = $container->get( 'artifact_manager' )->hydrate( $cpic_row ); ?>
						<figure class="cpic-thumb">
							<?php if ( ! empty( $cpic_row['exists'] ) ) : ?>
								<img src="<?php echo esc_url( (string) $cpic_row['file_url'] ); ?>" alt="" loading="lazy" />
							<?php else : ?>
								<span class="cpic-thumb__missing"><?php esc_html_e( 'file missing', 'custom-post-image-creator' ); ?></span>
							<?php endif; ?>
							<figcaption>
								<?php echo esc_html( sprintf( '%d×%d', (int) $cpic_row['width'], (int) $cpic_row['height'] ) ); ?>
								<?php if ( ! empty( $cpic_row['post_id'] ) ) : ?>
									· <a href="<?php echo esc_url( (string) $cpic_row['edit_url'] ); ?>"><?php esc_html_e( 'Edit post', 'custom-post-image-creator' ); ?></a>
								<?php endif; ?>
							</figcaption>
						</figure>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
	</div>
</div>
