<?php
/**
 * Brand kits.
 *
 * @package CustomPostImageCreator
 *
 * @var \CPIC\Core\Container $container Container.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cpic_repo    = $container->get( 'brands' );
$cpic_rows    = $cpic_repo->select( array(), 'is_default DESC, name ASC' );
$cpic_brands  = array();
$cpic_fonts   = $container->get( 'fonts' )->families();
$cpic_can_edit = $container->get( 'capabilities' )->can( 'cpic_manage_brands' );

foreach ( $cpic_rows as $cpic_row ) {
	$cpic_brands[] = $cpic_repo->hydrate( $cpic_row );
}

$cpic_title    = __( 'Brand kits', 'custom-post-image-creator' );
$cpic_subtitle = __( 'Colours, logos and type that designs inherit. Templates can override individual values.', 'custom-post-image-creator' );

require __DIR__ . '/_header.php';
?>
<div class="wrap cpic-wrap">

	<?php if ( ! $cpic_can_edit ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'You can review brand kits but not change them.', 'custom-post-image-creator' ); ?></p></div>
	<?php endif; ?>

	<?php foreach ( $cpic_brands as $cpic_brand ) : ?>
		<section class="cpic-card cpic-brand" id="cpic-brand-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>">
			<header class="cpic-brand__header">
				<h2>
					<?php echo esc_html( (string) $cpic_brand['name'] ); ?>
					<?php if ( ! empty( $cpic_brand['is_default'] ) ) : ?>
						<span class="cpic-badge cpic-badge--default"><?php esc_html_e( 'Default', 'custom-post-image-creator' ); ?></span>
					<?php endif; ?>
				</h2>
				<code class="cpic-muted"><?php echo esc_html( (string) $cpic_brand['slug'] ); ?></code>
			</header>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cpic-form">
				<input type="hidden" name="action" value="cpic_save_brand" />
				<input type="hidden" name="brand_id" value="<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>" />
				<?php wp_nonce_field( 'cpic_brand_' . (int) $cpic_brand['id'] ); ?>

				<div class="cpic-grid cpic-grid--two">
					<fieldset class="cpic-fieldset">
						<legend><?php esc_html_e( 'Identity', 'custom-post-image-creator' ); ?></legend>

						<p class="cpic-field">
							<label for="cpic-brand-name-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>"><?php esc_html_e( 'Name', 'custom-post-image-creator' ); ?></label>
							<input id="cpic-brand-name-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>" type="text" name="name" value="<?php echo esc_attr( (string) $cpic_brand['name'] ); ?>" <?php disabled( ! $cpic_can_edit ); ?> />
						</p>

						<p class="cpic-field">
							<label for="cpic-brand-footer-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>"><?php esc_html_e( 'Footer line on images', 'custom-post-image-creator' ); ?></label>
							<input id="cpic-brand-footer-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>" type="text" name="footer_text" value="<?php echo esc_attr( (string) $cpic_brand['footer_text'] ); ?>" <?php disabled( ! $cpic_can_edit ); ?> />
						</p>

						<p class="cpic-field">
							<label for="cpic-brand-site-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>"><?php esc_html_e( 'Website', 'custom-post-image-creator' ); ?></label>
							<input id="cpic-brand-site-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>" type="url" name="website_url" value="<?php echo esc_attr( (string) $cpic_brand['website_url'] ); ?>" <?php disabled( ! $cpic_can_edit ); ?> />
						</p>
					</fieldset>

					<fieldset class="cpic-fieldset">
						<legend><?php esc_html_e( 'Typography', 'custom-post-image-creator' ); ?></legend>

						<p class="cpic-field">
							<label for="cpic-brand-heading-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>"><?php esc_html_e( 'Heading font', 'custom-post-image-creator' ); ?></label>
							<select id="cpic-brand-heading-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>" name="heading_font" <?php disabled( ! $cpic_can_edit ); ?>>
								<?php foreach ( $cpic_fonts as $cpic_font ) : ?>
									<option value="<?php echo esc_attr( (string) $cpic_font ); ?>" <?php selected( (string) $cpic_brand['heading_font'], (string) $cpic_font ); ?>><?php echo esc_html( (string) $cpic_font ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>

						<p class="cpic-field">
							<label for="cpic-brand-body-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>"><?php esc_html_e( 'Body font', 'custom-post-image-creator' ); ?></label>
							<select id="cpic-brand-body-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>" name="body_font" <?php disabled( ! $cpic_can_edit ); ?>>
								<?php foreach ( $cpic_fonts as $cpic_font ) : ?>
									<option value="<?php echo esc_attr( (string) $cpic_font ); ?>" <?php selected( (string) $cpic_brand['body_font'], (string) $cpic_font ); ?>><?php echo esc_html( (string) $cpic_font ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					</fieldset>
				</div>

				<fieldset class="cpic-fieldset">
					<legend><?php esc_html_e( 'Colours', 'custom-post-image-creator' ); ?></legend>
					<div class="cpic-colors">
						<?php
						$cpic_color_fields = array(
							'primary_color'   => __( 'Primary', 'custom-post-image-creator' ),
							'secondary_color' => __( 'Secondary', 'custom-post-image-creator' ),
							'accent_color'    => __( 'Accent', 'custom-post-image-creator' ),
						);
						?>
						<?php foreach ( $cpic_color_fields as $cpic_key => $cpic_label ) : ?>
							<p class="cpic-field cpic-field--color">
								<label for="cpic-brand-<?php echo esc_attr( $cpic_key ); ?>-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>"><?php echo esc_html( $cpic_label ); ?></label>
								<input
									id="cpic-brand-<?php echo esc_attr( $cpic_key ); ?>-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>"
									type="color"
									name="<?php echo esc_attr( $cpic_key ); ?>"
									value="<?php echo esc_attr( (string) $cpic_brand[ $cpic_key ] ); ?>"
									<?php disabled( ! $cpic_can_edit ); ?>
								/>
								<code><?php echo esc_html( (string) $cpic_brand[ $cpic_key ] ); ?></code>
							</p>
						<?php endforeach; ?>
					</div>

					<?php if ( ! empty( $cpic_brand['background_palette'] ) ) : ?>
						<p class="cpic-muted">
							<?php esc_html_e( 'Background palette:', 'custom-post-image-creator' ); ?>
							<?php foreach ( (array) $cpic_brand['background_palette'] as $cpic_swatch ) : ?>
								<span class="cpic-swatch" style="background:<?php echo esc_attr( (string) $cpic_swatch ); ?>"></span>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>
				</fieldset>

				<fieldset class="cpic-fieldset">
					<legend><?php esc_html_e( 'Logos', 'custom-post-image-creator' ); ?></legend>
					<div class="cpic-grid cpic-grid--two">
						<?php
						$cpic_logo_fields = array(
							'logo_primary'   => __( 'Primary logo', 'custom-post-image-creator' ),
							'logo_secondary' => __( 'Secondary logo', 'custom-post-image-creator' ),
							'logo_light'     => __( 'Light background logo', 'custom-post-image-creator' ),
							'logo_dark'      => __( 'Dark background logo', 'custom-post-image-creator' ),
						);
						?>
						<?php foreach ( $cpic_logo_fields as $cpic_key => $cpic_label ) : ?>
							<p class="cpic-field cpic-field--media">
								<label for="cpic-brand-<?php echo esc_attr( $cpic_key ); ?>-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>"><?php echo esc_html( $cpic_label ); ?></label>
								<input
									id="cpic-brand-<?php echo esc_attr( $cpic_key ); ?>-<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>"
									type="url"
									name="<?php echo esc_attr( $cpic_key ); ?>"
									value="<?php echo esc_attr( (string) $cpic_brand[ $cpic_key ] ); ?>"
									data-cpic-media
									<?php disabled( ! $cpic_can_edit ); ?>
								/>
								<?php if ( ! empty( $cpic_brand[ $cpic_key ] ) ) : ?>
									<img class="cpic-logo-preview" src="<?php echo esc_url( (string) $cpic_brand[ $cpic_key ] ); ?>" alt="" loading="lazy" />
								<?php endif; ?>
							</p>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<?php if ( $cpic_can_edit ) : ?>
					<p class="cpic-form__actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save brand kit', 'custom-post-image-creator' ); ?></button>
						<?php if ( empty( $cpic_brand['is_default'] ) ) : ?>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'cpic_brand_default', 'brand' => (int) $cpic_brand['id'] ), admin_url( 'admin-post.php' ) ), 'cpic_brand_default_' . (int) $cpic_brand['id'] ) ); ?>"><?php esc_html_e( 'Make default', 'custom-post-image-creator' ); ?></a>
						<?php endif; ?>
						<span class="cpic-muted"><?php echo esc_html( sprintf( /* translators: %s: datetime */ __( 'Updated %s', 'custom-post-image-creator' ), (string) $cpic_brand['updated_at'] ) ); ?></span>
					</p>
				<?php endif; ?>
			</form>
		</section>
	<?php endforeach; ?>

	<?php if ( $cpic_can_edit ) : ?>
		<section class="cpic-card">
			<h2><?php esc_html_e( 'Add a brand kit', 'custom-post-image-creator' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cpic-form">
				<input type="hidden" name="action" value="cpic_save_brand" />
				<?php wp_nonce_field( 'cpic_brand_new' ); ?>
				<p class="cpic-field">
					<label for="cpic-new-brand"><?php esc_html_e( 'Name', 'custom-post-image-creator' ); ?></label>
					<input id="cpic-new-brand" type="text" name="name" required />
				</p>
				<p class="cpic-form__actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create brand kit', 'custom-post-image-creator' ); ?></button>
				</p>
			</form>
		</section>
	<?php endif; ?>
</div>
