<?php
/**
 * Template library.
 *
 * @package CustomPostImageCreator
 *
 * @var \CPIC\Core\Container $container Container.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cpic_manager = $container->get( 'template_manager' );
$cpic_library = $container->get( 'starter_library' );

$cpic_filters = array(
	'search'     => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'collection' => isset( $_GET['collection'] ) ? sanitize_key( wp_unslash( (string) $_GET['collection'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'status'     => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	'favorites'  => ! empty( $_GET['favorites'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
);

$cpic_templates = $cpic_manager->all( $cpic_filters );
$cpic_meta        = $cpic_manager->library_meta();
$cpic_collections = $cpic_meta['collections'];

$cpic_notice = isset( $_GET['cpic_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['cpic_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$cpic_title    = __( 'Templates', 'custom-post-image-creator' );
$cpic_subtitle = sprintf(
	/* translators: %d: number of templates */
	_n( '%d design in the library', '%d designs in the library', count( $cpic_templates ), 'custom-post-image-creator' ),
	count( $cpic_templates )
);
$cpic_actions = array(
	array(
		'label'   => __( 'New design', 'custom-post-image-creator' ),
		'url'     => admin_url( 'admin.php?page=cpic-studio' ),
		'primary' => true,
	),
	array(
		'label' => __( 'Import JSON', 'custom-post-image-creator' ),
		'url'   => admin_url( 'admin.php?page=cpic-templates&view=import' ),
	),
);

require __DIR__ . '/_header.php';
?>
<div class="wrap cpic-wrap">

	<?php if ( 'deleted' === $cpic_notice ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'The design was deleted.', 'custom-post-image-creator' ); ?></p></div>
	<?php elseif ( 'duplicated' === $cpic_notice ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'A copy of the design was created.', 'custom-post-image-creator' ); ?></p></div>
	<?php elseif ( 'saved' === $cpic_notice ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'The design was saved.', 'custom-post-image-creator' ); ?></p></div>
	<?php endif; ?>

	<form class="cpic-toolbar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="cpic-templates" />
		<label class="screen-reader-text" for="cpic-template-search"><?php esc_html_e( 'Search designs', 'custom-post-image-creator' ); ?></label>
		<input id="cpic-template-search" type="search" name="s" value="<?php echo esc_attr( $cpic_filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search designs…', 'custom-post-image-creator' ); ?>" />

		<select name="collection">
			<option value=""><?php esc_html_e( 'All collections', 'custom-post-image-creator' ); ?></option>
			<?php foreach ( $cpic_meta['collections'] as $cpic_key => $cpic_label ) : ?>
				<option value="<?php echo esc_attr( (string) $cpic_key ); ?>" <?php selected( $cpic_filters['collection'], (string) $cpic_key ); ?>><?php echo esc_html( (string) $cpic_label ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="status">
			<option value=""><?php esc_html_e( 'Any status', 'custom-post-image-creator' ); ?></option>
			<option value="active" <?php selected( $cpic_filters['status'], 'active' ); ?>><?php esc_html_e( 'Active', 'custom-post-image-creator' ); ?></option>
			<option value="archived" <?php selected( $cpic_filters['status'], 'archived' ); ?>><?php esc_html_e( 'Archived', 'custom-post-image-creator' ); ?></option>
			<option value="draft" <?php selected( $cpic_filters['status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'custom-post-image-creator' ); ?></option>
		</select>

		<label class="cpic-check">
			<input type="checkbox" name="favorites" value="1" <?php checked( ! empty( $cpic_filters['favorites'] ) ); ?> />
			<?php esc_html_e( 'Favourites only', 'custom-post-image-creator' ); ?>
		</label>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'custom-post-image-creator' ); ?></button>
		<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=cpic-templates' ) ); ?>"><?php esc_html_e( 'Reset', 'custom-post-image-creator' ); ?></a>
	</form>

	<?php if ( empty( $cpic_templates ) ) : ?>
		<div class="cpic-empty">
			<h2><?php esc_html_e( 'No designs match this filter', 'custom-post-image-creator' ); ?></h2>
			<p><?php esc_html_e( 'Start from a starter design or build one from scratch in the studio.', 'custom-post-image-creator' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=cpic-studio' ) ); ?>"><?php esc_html_e( 'Open the studio', 'custom-post-image-creator' ); ?></a></p>
		</div>
	<?php else : ?>
		<div class="cpic-template-grid">
			<?php foreach ( $cpic_templates as $cpic_row ) : ?>
				<?php
				$cpic_template = $cpic_row; // all() already returns hydrated rows.
				$cpic_preview  = isset( $cpic_row['preview_url'] ) ? (string) $cpic_row['preview_url'] : '';
				$cpic_studio   = add_query_arg(
					array(
						'page'     => 'cpic-studio',
						'template' => (int) $cpic_template['id'],
					),
					admin_url( 'admin.php' )
				);
				?>
				<article class="cpic-template-card<?php echo $cpic_template['is_default'] ? ' is-default' : ''; ?>">
					<div class="cpic-template-card__preview">
						<?php if ( '' !== $cpic_preview ) : ?>
							<img src="<?php echo esc_url( $cpic_preview ); ?>" alt="" loading="lazy" />
						<?php else : ?>
							<span class="cpic-template-card__ratio">
								<?php echo esc_html( sprintf( '%d×%d', (int) $cpic_template['summary']['width'], (int) $cpic_template['summary']['height'] ) ); ?>
							</span>
						<?php endif; ?>

						<?php if ( $cpic_template['is_default'] ) : ?>
							<span class="cpic-badge cpic-badge--default"><?php esc_html_e( 'Default', 'custom-post-image-creator' ); ?></span>
						<?php endif; ?>
						<?php if ( 'archived' === (string) $cpic_template['status'] ) : ?>
							<span class="cpic-badge cpic-badge--archived"><?php esc_html_e( 'Archived', 'custom-post-image-creator' ); ?></span>
						<?php endif; ?>
					</div>

					<div class="cpic-template-card__body">
						<h2 class="cpic-template-card__title"><?php echo esc_html( (string) $cpic_template['name'] ); ?></h2>
						<p class="cpic-muted cpic-template-card__meta">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: revision number, 2: element count */
									__( 'Revision %1$d · %2$d elements', 'custom-post-image-creator' ),
									(int) $cpic_template['revision'],
									(int) $cpic_template['summary']['elements']
								)
							);
							?>
						</p>

						<?php if ( ! empty( $cpic_template['tags'] ) ) : ?>
							<p class="cpic-tags">
								<?php foreach ( $cpic_template['tags'] as $cpic_tag ) : ?>
									<span class="cpic-tag"><?php echo esc_html( (string) $cpic_tag ); ?></span>
								<?php endforeach; ?>
							</p>
						<?php endif; ?>
					</div>

					<footer class="cpic-template-card__actions">
						<a class="button button-small" href="<?php echo esc_url( $cpic_studio ); ?>"><?php esc_html_e( 'Edit', 'custom-post-image-creator' ); ?></a>

						<?php if ( $cpic_template['is_default'] ) : ?>
							<span class="cpic-muted"><?php esc_html_e( 'Used by default', 'custom-post-image-creator' ); ?></span>
						<?php else : ?>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'cpic_library_action', 'cpic_action' => 'default', 'template' => (int) $cpic_template['id'] ), admin_url( 'admin-post.php' ) ), 'cpic_template_default_' . (int) $cpic_template['id'] ) ); ?>"><?php esc_html_e( 'Make default', 'custom-post-image-creator' ); ?></a>
						<?php endif; ?>

						<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'cpic_library_action', 'cpic_action' => 'duplicate', 'template' => (int) $cpic_template['id'] ), admin_url( 'admin-post.php' ) ), 'cpic_template_duplicate_' . (int) $cpic_template['id'] ) ); ?>"><?php esc_html_e( 'Duplicate', 'custom-post-image-creator' ); ?></a>
					</footer>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php $cpic_starters = $cpic_library->designs(); ?>
	<?php if ( ! empty( $cpic_starters ) ) : ?>
		<section class="cpic-card">
			<h2><?php esc_html_e( 'Starter designs', 'custom-post-image-creator' ); ?></h2>
			<p class="cpic-muted"><?php esc_html_e( 'Pre-built layouts for common Bengali newsroom and campaign needs. Opening one in the studio copies its layout into a new design.', 'custom-post-image-creator' ); ?></p>
			<ul class="cpic-list cpic-list--starters">
				<?php foreach ( $cpic_starters as $cpic_starter_key => $cpic_starter ) : ?>
					<li class="cpic-list__item">
						<span class="cpic-list__title"><?php echo esc_html( (string) $cpic_starter['name'] ); ?></span>
						<span class="cpic-list__meta">
							<?php
							echo esc_html(
								isset( $cpic_collections[ (string) $cpic_starter['collection'] ] )
									? (string) $cpic_collections[ (string) $cpic_starter['collection'] ]
									: (string) $cpic_starter['collection']
							);
							?>
						</span>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'cpic-studio', 'starter' => rawurlencode( (string) $cpic_starter_key ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open in studio', 'custom-post-image-creator' ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>
</div>
