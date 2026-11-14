<?php
/**
 * Queue: jobs, deliveries and manual runs.
 *
 * @package CustomPostImageCreator
 *
 * @var \CPIC\Core\Container $container Container.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cpic_queue      = $container->get( 'queue' );
$cpic_jobs_repo  = $container->get( 'jobs_repo' );
$cpic_deliveries = $container->get( 'deliveries' );
$cpic_stats      = $cpic_queue->stats();
$cpic_scheduler  = $container->get( 'scheduler' )->status();

$cpic_status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_page    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_jobs    = $cpic_jobs_repo->history(
	array(
		'status' => $cpic_status,
		'page'   => $cpic_page,
		'per'    => 25,
	)
);
$cpic_counts     = $cpic_jobs_repo->status_counts();
$cpic_publishing = $cpic_deliveries->status_counts();
$cpic_can_run    = $container->get( 'capabilities' )->can( 'cpic_run_queue' );
$cpic_notice     = isset( $_GET['cpic_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['cpic_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_error      = get_transient( 'cpic_admin_error_' . get_current_user_id() );

if ( $cpic_error ) {
	delete_transient( 'cpic_admin_error_' . get_current_user_id() );
}

$cpic_title    = __( 'Queue', 'custom-post-image-creator' );
$cpic_subtitle = __( 'Background work and publishing deliveries. Nothing here retries a post that may already be live without your confirmation.', 'custom-post-image-creator' );

require __DIR__ . '/_header.php';
?>
<div class="wrap cpic-wrap">

	<?php if ( 'published' === $cpic_notice ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'The post was sent to its destinations.', 'custom-post-image-creator' ); ?></p></div>
	<?php elseif ( 'queued' === $cpic_notice ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'The run was queued but nothing has been sent yet. It appears below with its current status.', 'custom-post-image-creator' ); ?></p></div>
	<?php elseif ( 'scheduled' === $cpic_notice ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'The run was scheduled. Nothing is sent until its scheduled time.', 'custom-post-image-creator' ); ?></p></div>
	<?php elseif ( 'failed' === $cpic_notice ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'Publishing could not start.', 'custom-post-image-creator' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $cpic_error ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( (string) $cpic_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $cpic_stats['healthy'] ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: pending jobs, 2: age in words */
						__( '%1$d jobs are waiting and the oldest has been queued for %2$s. WP-Cron may not be running on this site.', 'custom-post-image-creator' ),
						(int) $cpic_stats['pending'],
						human_time_diff( time() - (int) $cpic_stats['lag'] )
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="cpic-grid cpic-grid--stats">
		<?php
		$cpic_job_cards = array(
			'pending'  => __( 'Waiting', 'custom-post-image-creator' ),
			'claimed'  => __( 'Claimed', 'custom-post-image-creator' ),
			'completed' => __( 'Completed', 'custom-post-image-creator' ),
			'failed'   => __( 'Failed', 'custom-post-image-creator' ),
		);
		?>
		<?php foreach ( $cpic_job_cards as $cpic_key => $cpic_label ) : ?>
			<div class="cpic-card cpic-stat<?php echo 'failed' === $cpic_key && (int) $cpic_counts[ $cpic_key ] > 0 ? ' cpic-stat--alert' : ''; ?>">
				<span class="cpic-stat__value"><?php echo esc_html( number_format_i18n( (int) $cpic_counts[ $cpic_key ] ) ); ?></span>
				<span class="cpic-stat__label"><?php echo esc_html( $cpic_label ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<section class="cpic-card">
		<h2><?php esc_html_e( 'Publishing deliveries', 'custom-post-image-creator' ); ?></h2>
		<p class="cpic-muted">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: published, 2: pending, 3: failed */
					__( '%1$d published · %2$d waiting · %3$d failed', 'custom-post-image-creator' ),
					(int) $cpic_publishing['published'],
					(int) $cpic_publishing['pending'] + (int) $cpic_publishing['queued'] + (int) $cpic_publishing['processing'],
					(int) $cpic_publishing['failed']
				)
			);
			?>
		</p>
	</section>

	<section class="cpic-card">
		<h2><?php esc_html_e( 'Worker', 'custom-post-image-creator' ); ?></h2>
		<ul class="cpic-list cpic-list--plain">
			<li>
				<?php esc_html_e( 'WP-Cron', 'custom-post-image-creator' ); ?>:
				<strong>
					<?php
					echo esc_html(
						$cpic_scheduler['enabled']
							? __( 'handled by WP-Cron', 'custom-post-image-creator' )
							: __( 'disabled — run a real cron on the server', 'custom-post-image-creator' )
					);
					?>
				</strong>
			</li>
			<li>
				<?php esc_html_e( 'Queue lock', 'custom-post-image-creator' ); ?>:
				<strong><?php echo esc_html( $cpic_stats['locked'] ? __( 'held by a running worker', 'custom-post-image-creator' ) : __( 'free', 'custom-post-image-creator' ) ); ?></strong>
			</li>
			<?php foreach ( $cpic_scheduler['events'] as $cpic_event => $cpic_detail ) : ?>
				<li>
					<?php echo esc_html( ucfirst( (string) $cpic_event ) ); ?>:
					<strong>
						<?php
						echo esc_html(
							$cpic_detail['scheduled']
								/* translators: %s: human time difference */
								? sprintf( __( 'next run in %s', 'custom-post-image-creator' ), human_time_diff( time() + (int) $cpic_detail['offset'] ) )
								: __( 'not scheduled', 'custom-post-image-creator' )
						);
						?>
					</strong>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $cpic_can_run ) : ?>
			<p class="cpic-form__actions">
				<button type="button" class="button button-primary" id="cpic-run-queue" data-nonce="<?php echo esc_attr( wp_create_nonce( 'cpic_admin' ) ); ?>">
					<?php esc_html_e( 'Process the queue now', 'custom-post-image-creator' ); ?>
				</button>
				<span class="cpic-muted" id="cpic-run-queue-status" role="status" aria-live="polite"></span>
			</p>
		<?php endif; ?>
	</section>

	<nav class="cpic-tabs">
		<?php
		$cpic_tab_map = array_merge(
			array( '' => __( 'All', 'custom-post-image-creator' ) ),
			array_combine(
				\CPIC\Database\Repositories\JobRepository::STATUSES,
				array_map( 'ucfirst', \CPIC\Database\Repositories\JobRepository::STATUSES )
			)
		);
		?>
		<?php foreach ( $cpic_tab_map as $cpic_key => $cpic_label ) : ?>
			<a class="cpic-tab<?php echo $cpic_status === (string) $cpic_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'cpic-queue', 'status' => (string) $cpic_key ), admin_url( 'admin.php' ) ) ); ?>">
				<?php echo esc_html( (string) $cpic_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( empty( $cpic_jobs['items'] ) ) : ?>
		<p class="cpic-muted"><?php esc_html_e( 'No jobs match this filter.', 'custom-post-image-creator' ); ?></p>
	<?php else : ?>
		<table class="widefat striped cpic-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Job', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Post', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Attempts', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last error', 'custom-post-image-creator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Available', 'custom-post-image-creator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cpic_jobs['items'] as $cpic_job ) : ?>
					<tr>
						<td><code>#<?php echo esc_html( (string) (int) $cpic_job['id'] ); ?></code></td>
						<td><?php echo esc_html( (string) $cpic_job['type'] ); ?></td>
						<td>
							<?php if ( ! empty( $cpic_job['post_id'] ) ) : ?>
								<?php $cpic_edit = get_edit_post_link( (int) $cpic_job['post_id'] ); ?>
								<?php if ( $cpic_edit ) : ?>
									<a href="<?php echo esc_url( (string) $cpic_edit ); ?>"><?php echo esc_html( get_the_title( (int) $cpic_job['post_id'] ) ); ?></a>
								<?php else : ?>
									<span class="cpic-muted">#<?php echo esc_html( (string) (int) $cpic_job['post_id'] ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="cpic-muted">—</span>
							<?php endif; ?>
						</td>
						<td><span class="cpic-badge cpic-badge--<?php echo esc_attr( (string) $cpic_job['status'] ); ?>"><?php echo esc_html( (string) $cpic_job['status'] ); ?></span></td>
						<td><?php echo esc_html( sprintf( '%d/%d', (int) $cpic_job['attempts'], (int) $cpic_job['max_retries'] ) ); ?></td>
						<td>
							<?php if ( ! empty( $cpic_job['last_error'] ) ) : ?>
								<span class="cpic-error-text"><?php echo esc_html( (string) $cpic_job['last_error'] ); ?></span>
							<?php else : ?>
								<span class="cpic-muted">—</span>
							<?php endif; ?>
						</td>
						<td class="cpic-muted">
							<?php echo esc_html( (string) $cpic_job['available_at'] ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( (int) $cpic_jobs['pages'] > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => (int) $cpic_jobs['page'],
							'total'     => (int) $cpic_jobs['pages'],
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
