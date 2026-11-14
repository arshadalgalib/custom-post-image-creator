<?php
/**
 * Publishing calendar.
 *
 * @package CustomPostImageCreator
 *
 * @var \CPIC\Core\Container $container Container.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cpic_calendar = $container->get( 'calendar' );
$cpic_month    = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['month'] ) ) : current_time( 'Y-m' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_view     = $cpic_calendar->month( $cpic_month );

$cpic_stamp   = (int) strtotime( $cpic_view['start'] );
$cpic_prev    = gmdate( 'Y-m', (int) strtotime( '-1 month', $cpic_stamp ) );
$cpic_next    = gmdate( 'Y-m', (int) strtotime( '+1 month', $cpic_stamp ) );
$cpic_weekday = (int) $cpic_view['week_start'];

$cpic_weekdays = array(
	__( 'Sun', 'custom-post-image-creator' ),
	__( 'Mon', 'custom-post-image-creator' ),
	__( 'Tue', 'custom-post-image-creator' ),
	__( 'Wed', 'custom-post-image-creator' ),
	__( 'Thu', 'custom-post-image-creator' ),
	__( 'Fri', 'custom-post-image-creator' ),
	__( 'Sat', 'custom-post-image-creator' ),
);
$cpic_weekdays = array_merge( array_slice( $cpic_weekdays, $cpic_weekday ), array_slice( $cpic_weekdays, 0, $cpic_weekday ) );

// Leading blanks so day 1 lands under the right weekday column.
$cpic_lead = ( (int) $cpic_view['days'][0]['weekday'] - 1 - $cpic_weekday + 7 ) % 7;

$cpic_title    = __( 'Calendar', 'custom-post-image-creator' );
$cpic_subtitle = __( 'Scheduled deliveries, published results and campaign windows.', 'custom-post-image-creator' );
$cpic_actions  = array(
	array( 'label' => __( 'Campaigns', 'custom-post-image-creator' ), 'url' => admin_url( 'admin.php?page=cpic-campaigns' ) ),
);

require __DIR__ . '/_header.php';
?>
<div class="wrap cpic-wrap">

	<div class="cpic-calendar__toolbar">
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'cpic-calendar', 'month' => $cpic_prev ), admin_url( 'admin.php' ) ) ); ?>">&larr; <?php esc_html_e( 'Previous', 'custom-post-image-creator' ); ?></a>
		<h2 class="cpic-calendar__label"><?php echo esc_html( mysql2date( 'F Y', $cpic_view['start'] ) ); ?></h2>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'cpic-calendar', 'month' => $cpic_next ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Next', 'custom-post-image-creator' ); ?> &rarr;</a>
		<a class="button-link" href="<?php echo esc_url( add_query_arg( array( 'page' => 'cpic-calendar' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Today', 'custom-post-image-creator' ); ?></a>

		<span class="cpic-calendar__totals">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: scheduled, 2: published, 3: failed */
					__( '%1$d scheduled · %2$d published · %3$d failed', 'custom-post-image-creator' ),
					(int) $cpic_view['totals']['scheduled'],
					(int) $cpic_view['totals']['published'],
					(int) $cpic_view['totals']['failed']
				)
			);
			?>
		</span>
	</div>

	<table class="cpic-calendar">
		<thead>
			<tr>
				<?php foreach ( $cpic_weekdays as $cpic_day_name ) : ?>
					<th scope="col"><?php echo esc_html( (string) $cpic_day_name ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<tr>
			<?php $cpic_column = 0; ?>

			<?php for ( $cpic_i = 0; $cpic_i < $cpic_lead; $cpic_i++ ) : ?>
				<td class="cpic-calendar__blank"></td>
				<?php $cpic_column++; ?>
			<?php endfor; ?>

			<?php foreach ( $cpic_view['days'] as $cpic_day ) : ?>
				<?php
				$cpic_classes = array( 'cpic-calendar__day' );
				if ( ! empty( $cpic_day['is_today'] ) ) {
					$cpic_classes[] = 'is-today';
				}
				if ( ! empty( $cpic_day['is_past'] ) ) {
					$cpic_classes[] = 'is-past';
				}
				if ( ! empty( $cpic_day['events'] ) ) {
					$cpic_classes[] = 'has-events';
				}
				?>
				<td class="<?php echo esc_attr( implode( ' ', $cpic_classes ) ); ?>">
					<span class="cpic-calendar__date"><?php echo esc_html( (string) $cpic_day['day'] ); ?></span>

					<?php foreach ( $cpic_day['events'] as $cpic_event ) : ?>
						<?php
						$cpic_link = ! empty( $cpic_event['post_id'] ) ? get_edit_post_link( (int) $cpic_event['post_id'] ) : '';
						$cpic_text = wp_trim_words( (string) $cpic_event['title'], 6 );
						?>
						<?php if ( $cpic_link ) : ?>
							<a class="cpic-event cpic-event--<?php echo esc_attr( (string) $cpic_event['status'] ); ?>" href="<?php echo esc_url( (string) $cpic_link ); ?>" title="<?php echo esc_attr( (string) $cpic_event['when'] ); ?>"><?php echo esc_html( $cpic_text ); ?></a>
						<?php else : ?>
							<span class="cpic-event cpic-event--<?php echo esc_attr( (string) $cpic_event['status'] ); ?>" title="<?php echo esc_attr( (string) $cpic_event['when'] ); ?>"><?php echo esc_html( $cpic_text ); ?></span>
						<?php endif; ?>
					<?php endforeach; ?>
				</td>

				<?php $cpic_column++; ?>
				<?php if ( 0 === $cpic_column % 7 ) : ?>
					</tr><tr>
				<?php endif; ?>
			<?php endforeach; ?>

			<?php while ( 0 !== $cpic_column % 7 ) : ?>
				<td class="cpic-calendar__blank"></td>
				<?php $cpic_column++; ?>
			<?php endwhile; ?>
			</tr>
		</tbody>
	</table>

	<section class="cpic-card">
		<h2><?php esc_html_e( 'Next up', 'custom-post-image-creator' ); ?></h2>
		<?php $cpic_upcoming = $cpic_calendar->upcoming( 10 ); ?>
		<?php if ( empty( $cpic_upcoming ) ) : ?>
			<p class="cpic-muted"><?php esc_html_e( 'Nothing is scheduled.', 'custom-post-image-creator' ); ?></p>
		<?php else : ?>
			<ul class="cpic-list">
				<?php foreach ( $cpic_upcoming as $cpic_event ) : ?>
					<li class="cpic-list__item">
						<span class="cpic-badge cpic-badge--<?php echo esc_attr( (string) $cpic_event['status'] ); ?>"><?php echo esc_html( (string) $cpic_event['status'] ); ?></span>
						<span class="cpic-list__title"><?php echo esc_html( (string) $cpic_event['title'] ); ?></span>
						<span class="cpic-list__meta">
							<?php echo esc_html( mysql2date( (string) get_option( 'date_format', 'M j, Y' ) . ' ' . (string) get_option( 'time_format', 'H:i' ), (string) $cpic_event['when'] ) ); ?>
							<?php if ( ! empty( $cpic_event['provider'] ) ) : ?>
								· <?php echo esc_html( (string) $cpic_event['provider'] ); ?>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
</div>
