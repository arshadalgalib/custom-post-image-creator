<?php
/**
 * Design studio.
 *
 * The canvas is drawn by resources/js/studio.js from the same render plan the
 * server would hand a renderer, so what the editor sees is what gets produced.
 *
 * @package CustomPostImageCreator
 *
 * @var \CPIC\Core\Container $container Container.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cpic_templates = $container->get( 'template_manager' );
$cpic_brands    = $container->get( 'brands' );
$cpic_library   = $container->get( 'starter_library' );
$cpic_schema    = $container->get( 'template_schema' );

$cpic_post_id     = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_template_id = isset( $_GET['template'] ) ? (int) $_GET['template'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_starter     = isset( $_GET['starter'] ) ? sanitize_key( wp_unslash( (string) $_GET['starter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$cpic_all_templates = $cpic_templates->all( array( 'active' => true ) );

// Resolve the design to start from, in order of explicitness.
$cpic_document = null;
$cpic_current  = null;

if ( $cpic_template_id > 0 ) {
	$cpic_current = $cpic_templates->get( $cpic_template_id );
	if ( $cpic_current ) {
		$cpic_document = $cpic_current['document'];
	}
}

if ( null === $cpic_document && '' !== $cpic_starter ) {
	foreach ( $cpic_library->designs() as $cpic_starter_key => $cpic_design ) {
		if ( (string) $cpic_starter_key === $cpic_starter ) {
			$cpic_document = $cpic_design['document'];
			break;
		}
	}
}

if ( null === $cpic_document && $cpic_post_id > 0 ) {
	$cpic_for_post = $cpic_templates->for_post( $cpic_post_id );
	if ( $cpic_for_post ) {
		$cpic_document = $cpic_for_post['document'];
		$cpic_current  = $cpic_for_post;
	}
}

if ( null === $cpic_document ) {
	$cpic_default = $cpic_templates->default_template();
	if ( $cpic_default ) {
		$cpic_document = $cpic_default['document'];
		$cpic_current  = $cpic_default;
	}
}

if ( null === $cpic_document ) {
	$cpic_document = $cpic_schema->blank( 'square' );
}

$cpic_posts = get_posts(
	array(
		'numberposts'      => 20,
		'post_status'      => array( 'publish', 'future', 'draft', 'pending' ),
		'post_type'        => array_values( array_filter( (array) $container->get( 'settings' )->get( 'cpic_post_types' ) ) ),
		'suppress_filters' => false,
		'orderby'          => 'date',
		'order'            => 'DESC',
	)
);

$cpic_brand_rows = array();
foreach ( $cpic_brands->select( array(), 'is_default DESC, name ASC' ) as $cpic_row ) {
	$cpic_row         = $cpic_brands->hydrate( $cpic_row );
	$cpic_brand_rows[] = array(
		'id'   => (int) $cpic_row['id'],
		'name' => (string) $cpic_row['name'],
	);
}

$cpic_state = array(
	'postId'      => $cpic_post_id,
	'templateId'  => $cpic_current ? (int) $cpic_current['id'] : 0,
	'templateName' => $cpic_current ? (string) $cpic_current['name'] : __( 'Untitled design', 'custom-post-image-creator' ),
	'document'    => $cpic_document,
	'formats'     => \CPIC\Rendering\Formats::all(),
	'brands'      => $cpic_brand_rows,
	'campaigns'   => array(),
	'can'         => array(
		'save'    => $container->get( 'capabilities' )->can( 'cpic_manage_templates' ),
		'publish' => $container->get( 'capabilities' )->can( 'cpic_publish' ),
		'ai'      => $container->get( 'capabilities' )->can( 'cpic_use_ai' ),
	),
	'endpoints'   => array(
		'plan'     => '/' . CPIC_REST_NAMESPACE . '/render/plan',
		'templates' => '/' . CPIC_REST_NAMESPACE . '/templates',
		'artifacts' => '/' . CPIC_REST_NAMESPACE . '/artifacts',
		'publish'  => '/' . CPIC_REST_NAMESPACE . '/publishing/publish',
	),
);

$cpic_title    = __( 'Design studio', 'custom-post-image-creator' );
$cpic_subtitle = __( 'Edit the design, then render it in this browser so Bengali text is shaped correctly.', 'custom-post-image-creator' );

require __DIR__ . '/_header.php';
?>
<div class="wrap cpic-wrap cpic-studio" id="cpic-studio">
	<script type="application/json" id="cpic-studio-state"><?php echo wp_json_encode( $cpic_state ); ?></script>

	<div class="cpic-studio__toolbar">
		<label class="cpic-field cpic-field--inline">
			<span><?php esc_html_e( 'Design', 'custom-post-image-creator' ); ?></span>
			<select id="cpic-template-select">
				<option value="0" <?php selected( 0, $cpic_state['templateId'] ); ?>><?php esc_html_e( 'Unsaved new design', 'custom-post-image-creator' ); ?></option>
				<?php foreach ( $cpic_all_templates as $cpic_template ) : ?>
					<option value="<?php echo esc_attr( (string) (int) $cpic_template['id'] ); ?>" <?php selected( (int) $cpic_template['id'], $cpic_state['templateId'] ); ?>>
						<?php echo esc_html( (string) $cpic_template['name'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label class="cpic-field cpic-field--inline">
			<span><?php esc_html_e( 'Post', 'custom-post-image-creator' ); ?></span>
			<select id="cpic-post-select">
				<option value="0"><?php esc_html_e( 'No post (sample text)', 'custom-post-image-creator' ); ?></option>
				<?php foreach ( $cpic_posts as $cpic_post ) : ?>
					<option value="<?php echo esc_attr( (string) (int) $cpic_post->ID ); ?>" <?php selected( (int) $cpic_post->ID, $cpic_post_id ); ?>>
						<?php echo esc_html( get_the_title( $cpic_post ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label class="cpic-field cpic-field--inline">
			<span><?php esc_html_e( 'Brand', 'custom-post-image-creator' ); ?></span>
			<select id="cpic-brand-select">
				<option value="0"><?php esc_html_e( 'Design default', 'custom-post-image-creator' ); ?></option>
				<?php foreach ( $cpic_brand_rows as $cpic_brand ) : ?>
					<option value="<?php echo esc_attr( (string) $cpic_brand['id'] ); ?>"><?php echo esc_html( $cpic_brand['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>

		<div class="cpic-studio__formats" role="group" aria-label="<?php esc_attr_e( 'Output format', 'custom-post-image-creator' ); ?>">
			<?php foreach ( \CPIC\Rendering\Formats::all() as $cpic_key => $cpic_preset ) : ?>
				<button type="button" class="button cpic-format" data-format="<?php echo esc_attr( (string) $cpic_key ); ?>"<?php echo ( isset( $cpic_document['canvas']['format'] ) && $cpic_key === $cpic_document['canvas']['format'] ) ? ' aria-pressed="true"' : ' aria-pressed="false"'; ?>>
					<?php echo esc_html( (string) $cpic_preset['label'] ); ?>
				</button>
			<?php endforeach; ?>
		</div>

		<div class="cpic-studio__actions">
			<button type="button" class="button" id="cpic-render"><?php esc_html_e( 'Render preview', 'custom-post-image-creator' ); ?></button>
			<?php if ( $cpic_state['can']['save'] ) : ?>
				<button type="button" class="button button-primary" id="cpic-save"><?php esc_html_e( 'Save design', 'custom-post-image-creator' ); ?></button>
			<?php endif; ?>
			<?php if ( $cpic_state['can']['publish'] ) : ?>
				<button type="button" class="button" id="cpic-publish"><?php esc_html_e( 'Publish image', 'custom-post-image-creator' ); ?></button>
			<?php endif; ?>
			<span class="cpic-studio__status" id="cpic-status" role="status" aria-live="polite"></span>
		</div>
	</div>

	<div class="cpic-studio__body">
		<aside class="cpic-studio__layers" aria-label="<?php esc_attr_e( 'Layers', 'custom-post-image-creator' ); ?>">
			<h2><?php esc_html_e( 'Layers', 'custom-post-image-creator' ); ?></h2>
			<ul class="cpic-layer-list" id="cpic-layers"></ul>
			<p class="cpic-muted cpic-studio__hint" id="cpic-layer-hint"><?php esc_html_e( 'Select a layer to edit it. Drag on the canvas to move it.', 'custom-post-image-creator' ); ?></p>
		</aside>

		<div class="cpic-studio__stage">
			<div class="cpic-canvas-frame" id="cpic-canvas-frame">
				<canvas id="cpic-canvas" width="1080" height="1080" aria-label="<?php esc_attr_e( 'Design canvas', 'custom-post-image-creator' ); ?>"></canvas>
				<div class="cpic-canvas-frame__loading" id="cpic-canvas-loading" hidden><?php esc_html_e( 'Rendering…', 'custom-post-image-creator' ); ?></div>
			</div>
			<p class="cpic-muted cpic-studio__canvas-meta" id="cpic-canvas-meta"></p>
		</div>

		<aside class="cpic-studio__inspector" aria-label="<?php esc_attr_e( 'Element properties', 'custom-post-image-creator' ); ?>">
			<h2><?php esc_html_e( 'Properties', 'custom-post-image-creator' ); ?></h2>
			<div id="cpic-inspector">
				<p class="cpic-muted"><?php esc_html_e( 'Select an element on the canvas to edit it.', 'custom-post-image-creator' ); ?></p>
			</div>
		</aside>
	</div>

	<section class="cpic-card cpic-studio__result" id="cpic-result" hidden>
		<h2><?php esc_html_e( 'Latest render', 'custom-post-image-creator' ); ?></h2>
		<div id="cpic-result-body"></div>
	</section>
</div>
