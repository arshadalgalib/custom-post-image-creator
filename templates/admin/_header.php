<?php
/**
 * Shared admin screen header.
 *
 * @package CustomPostImageCreator
 *
 * @var string $cpic_title    Screen title.
 * @var string $cpic_subtitle Optional subtitle.
 * @var array  $cpic_actions  Optional header actions (label => url or ['label'=>,'url'=>,'primary'=>]).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cpic_title    = isset( $cpic_title ) ? (string) $cpic_title : '';
$cpic_subtitle = isset( $cpic_subtitle ) ? (string) $cpic_subtitle : '';
$cpic_actions  = isset( $cpic_actions ) ? (array) $cpic_actions : array();
?>
<div class="cpic-header">
	<div class="cpic-header__text">
		<h1 class="cpic-header__title"><?php echo esc_html( $cpic_title ); ?></h1>
		<?php if ( '' !== $cpic_subtitle ) : ?>
			<p class="cpic-header__subtitle"><?php echo esc_html( $cpic_subtitle ); ?></p>
		<?php endif; ?>
	</div>
	<?php if ( ! empty( $cpic_actions ) ) : ?>
		<div class="cpic-header__actions">
			<?php foreach ( $cpic_actions as $cpic_action ) : ?>
				<?php
				$cpic_action = is_array( $cpic_action ) ? $cpic_action : array( 'label' => $cpic_action, 'url' => '' );
				$cpic_class  = ! empty( $cpic_action['primary'] ) ? 'button button-primary' : 'button';
				?>
				<a class="<?php echo esc_attr( $cpic_class ); ?>" href="<?php echo esc_url( (string) $cpic_action['url'] ); ?>">
					<?php echo esc_html( (string) $cpic_action['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
