<?php
/**
 * Design studio.
 *
 * The canvas is painted by resources/js/studio.js from the same render plan the
 * server hands a renderer, so the editor and the saved PNG cannot drift apart.
 * Everything the editor needs to boot is printed once as JSON; the interface
 * itself is built by JavaScript from that state.
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
$cpic_fonts     = $container->get( 'fonts' );

$cpic_post_id     = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_template_id = isset( $_GET['template'] ) ? (int) $_GET['template'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cpic_starter     = isset( $_GET['starter'] ) ? sanitize_key( wp_unslash( (string) $_GET['starter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$cpic_all_templates = $cpic_templates->all( array( 'active' => true ) );

// Resolve the design to start from, in order of explicitness.
$cpic_document = null;
$cpic_current  = null;
$cpic_origin   = 'blank';

if ( $cpic_template_id > 0 ) {
	$cpic_current = $cpic_templates->get( $cpic_template_id );
	if ( $cpic_current ) {
		$cpic_document = $cpic_current['document'];
		$cpic_origin   = 'template';
	}
}

if ( null === $cpic_document && '' !== $cpic_starter ) {
	foreach ( $cpic_library->designs() as $cpic_starter_key => $cpic_design ) {
		if ( (string) $cpic_starter_key === $cpic_starter ) {
			$cpic_document = $cpic_design['document'];
			$cpic_origin   = 'starter';
			break;
		}
	}
}

if ( null === $cpic_document && $cpic_post_id > 0 ) {
	$cpic_for_post = $cpic_templates->for_post( $cpic_post_id );
	if ( $cpic_for_post ) {
		$cpic_document = $cpic_for_post['document'];
		$cpic_current  = $cpic_for_post;
		$cpic_origin   = 'template';
	}
}

if ( null === $cpic_document ) {
	$cpic_default = $cpic_templates->default_template();
	if ( $cpic_default ) {
		$cpic_document = $cpic_default['document'];
		$cpic_current  = $cpic_default;
		$cpic_origin   = 'template';
	}
}

if ( null === $cpic_document ) {
	$cpic_document = $cpic_schema->blank( 'square' );
}

// A starter or a brand-new design is not a saved template yet: the studio must
// ask for an explicit first save instead of silently creating templates.
if ( in_array( $cpic_origin, array( 'starter', 'blank' ), true ) ) {
	$cpic_current = null;
}

$cpic_posts = get_posts(
	array(
		'numberposts'      => 30,
		'post_status'      => array( 'publish', 'future', 'draft', 'pending' ),
		'post_type'        => array_values( array_filter( (array) $container->get( 'settings' )->get( 'cpic_post_types' ) ) ),
		'suppress_filters' => false,
		'orderby'          => 'date',
		'order'            => 'DESC',
	)
);

$cpic_post_rows = array();
foreach ( $cpic_posts as $cpic_post ) {
	$cpic_post_rows[] = array(
		'id'        => (int) $cpic_post->ID,
		'title'     => (string) get_the_title( $cpic_post ),
		'status'    => (string) $cpic_post->post_status,
		'date'      => (string) get_the_date( 'Y-m-d', $cpic_post ),
		'thumbnail' => (string) get_the_post_thumbnail_url( $cpic_post, 'thumbnail' ),
		'editUrl'   => (string) get_edit_post_link( (int) $cpic_post->ID, 'raw' ),
	);
}

$cpic_brand_rows = array();
foreach ( $cpic_brands->select( array(), 'is_default DESC, name ASC' ) as $cpic_row ) {
	$cpic_row          = $cpic_brands->hydrate( $cpic_row );
	$cpic_brand_rows[] = array(
		'id'   => (int) $cpic_row['id'],
		'name' => (string) $cpic_row['name'],
	);
}

$cpic_template_rows = array();
foreach ( $cpic_all_templates as $cpic_template ) {
	$cpic_template_rows[] = array(
		'id'        => (int) $cpic_template['id'],
		'name'      => (string) $cpic_template['name'],
		'format'    => isset( $cpic_template['summary']['format'] ) ? (string) $cpic_template['summary']['format'] : 'square',
		'width'     => isset( $cpic_template['summary']['width'] ) ? (int) $cpic_template['summary']['width'] : 1080,
		'height'    => isset( $cpic_template['summary']['height'] ) ? (int) $cpic_template['summary']['height'] : 1080,
		'collection' => isset( $cpic_template['collection'] ) ? (string) $cpic_template['collection'] : '',
		'revision'  => isset( $cpic_template['revision'] ) ? (int) $cpic_template['revision'] : 1,
		'updatedAt' => isset( $cpic_template['updated_at'] ) ? (string) $cpic_template['updated_at'] : '',
	);
}

$cpic_starter_rows = array();
foreach ( $cpic_library->designs() as $cpic_key => $cpic_design ) {
	$cpic_starter_doc     = isset( $cpic_design['document'] ) ? $cpic_design['document'] : array();
	$cpic_starter_rows[] = array(
		'key'         => (string) $cpic_key,
		'name'        => (string) $cpic_design['name'],
		'description' => isset( $cpic_design['description'] ) ? (string) $cpic_design['description'] : '',
		'collection'  => isset( $cpic_design['collection'] ) ? (string) $cpic_design['collection'] : '',
		'format'      => isset( $cpic_starter_doc['canvas']['format'] ) ? (string) $cpic_starter_doc['canvas']['format'] : 'square',
		// The document ships with the page: opening a starter is instant and
		// cannot fail half-way through on a slow connection.
		'document'    => $cpic_starter_doc,
	);
}

$cpic_font_rows = array();
foreach ( $cpic_fonts->manifest() as $cpic_font ) {
	if ( empty( $cpic_font['available'] ) ) {
		continue;
	}
	$cpic_font_rows[] = array(
		'family'  => (string) $cpic_font['family'],
		'weight'  => (int) $cpic_font['weight'],
		'label'   => isset( $cpic_font['label'] ) ? (string) $cpic_font['label'] : (string) $cpic_font['family'],
		'bengali' => ! empty( $cpic_font['bengali'] ),
	);
}

$cpic_destination_rows = array();
foreach ( $container->get( 'destinations' )->select( array(), 'priority ASC, id ASC' ) as $cpic_destination ) {
	$cpic_destination_rows[] = array(
		'id'       => (int) $cpic_destination['id'],
		'label'    => isset( $cpic_destination['label'] ) ? (string) $cpic_destination['label'] : '',
		'provider' => isset( $cpic_destination['provider'] ) ? (string) $cpic_destination['provider'] : '',
		'status'   => isset( $cpic_destination['status'] ) ? (string) $cpic_destination['status'] : '',
	);
}

$cpic_state = array(
	'postId'       => $cpic_post_id,
	'templateId'   => $cpic_current ? (int) $cpic_current['id'] : 0,
	'templateName' => $cpic_current ? (string) $cpic_current['name'] : __( 'Untitled design', 'custom-post-image-creator' ),
	'revision'     => $cpic_current && isset( $cpic_current['revision'] ) ? (int) $cpic_current['revision'] : 0,
	'origin'       => $cpic_origin,
	'document'     => $cpic_document,
	'formats'      => \CPIC\Rendering\Formats::all(),
	'brands'       => $cpic_brand_rows,
	'templates'    => $cpic_template_rows,
	'starters'     => $cpic_starter_rows,
	'posts'        => $cpic_post_rows,
	'fonts'        => $cpic_font_rows,
	'destinations' => $cpic_destination_rows,
	'campaigns'    => array(),
	'can'          => array(
		'save'    => $container->get( 'capabilities' )->can( 'cpic_manage_templates' ),
		'publish' => $container->get( 'capabilities' )->can( 'cpic_publish' ),
		'ai'      => $container->get( 'capabilities' )->can( 'cpic_use_ai' ),
	),
	'endpoints'    => array(
		'plan'      => '/' . CPIC_REST_NAMESPACE . '/render/plan',
		'templates' => '/' . CPIC_REST_NAMESPACE . '/templates',
		'artifacts' => '/' . CPIC_REST_NAMESPACE . '/artifacts',
		'publish'   => '/' . CPIC_REST_NAMESPACE . '/publishing/publish',
	),
);

$cpic_is_new = ! $cpic_current;

/*
 * No admin header here on purpose: the studio is an application shell, not a
 * settings page. The stylesheet owns every pixel inside .cpic-studio-app.
 */
?>
<div class="wrap cpic-wrap cpic-studio cpic-studio-app" id="cpic-studio" data-studio-mode="full">
	<script type="application/json" id="cpic-studio-state"><?php echo wp_json_encode( $cpic_state ); ?></script>

	<div class="cpic-app" id="cpic-app">
		<?php /* ── Header ─────────────────────────────────────────── */ ?>
		<header class="cpic-app__header" role="banner">
			<div class="cpic-app__header-left">
				<button type="button" class="cpic-icon-btn cpic-app__panel-toggle" id="cpic-toggle-left" aria-label="<?php esc_attr_e( 'Toggle tools panel', 'custom-post-image-creator' ); ?>" aria-expanded="true" title="<?php esc_attr_e( 'Design, elements and layers', 'custom-post-image-creator' ); ?>">
					<span class="cpic-svg" data-icon="panel-left" aria-hidden="true"></span>
				</button>
				<span class="cpic-app__brand">
					<span class="cpic-svg" data-icon="logo" aria-hidden="true"></span>
					<span class="cpic-app__brand-text"><?php esc_html_e( 'Design Studio', 'custom-post-image-creator' ); ?></span>
				</span>

				<div class="cpic-app__doc">
					<button type="button" class="cpic-app__doc-name" id="cpic-doc-name" title="<?php esc_attr_e( 'Rename this design', 'custom-post-image-creator' ); ?>">
						<?php echo esc_html( $cpic_state['templateName'] ); ?>
					</button>
					<input type="text" class="cpic-app__doc-input" id="cpic-doc-input" value="<?php echo esc_attr( $cpic_state['templateName'] ); ?>" aria-label="<?php esc_attr_e( 'Design name', 'custom-post-image-creator' ); ?>" hidden />
					<span class="cpic-save-state" id="cpic-save-state" data-state="<?php echo $cpic_is_new ? 'unsaved' : 'saved'; ?>" role="status" aria-live="polite">
						<span class="cpic-save-state__dot" aria-hidden="true"></span>
						<span class="cpic-save-state__text"><?php echo $cpic_is_new ? esc_html__( 'Not saved yet', 'custom-post-image-creator' ) : esc_html__( 'Saved', 'custom-post-image-creator' ); ?></span>
					</span>
				</div>
			</div>

			<div class="cpic-app__header-center">
				<div class="cpic-history" role="group" aria-label="<?php esc_attr_e( 'History', 'custom-post-image-creator' ); ?>">
					<button type="button" class="cpic-icon-btn" id="cpic-undo" disabled title="<?php esc_attr_e( 'Undo (Ctrl+Z)', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Undo', 'custom-post-image-creator' ); ?>">
						<span class="cpic-svg" data-icon="undo" aria-hidden="true"></span>
					</button>
					<button type="button" class="cpic-icon-btn" id="cpic-redo" disabled title="<?php esc_attr_e( 'Redo (Ctrl+Shift+Z)', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Redo', 'custom-post-image-creator' ); ?>">
						<span class="cpic-svg" data-icon="redo" aria-hidden="true"></span>
					</button>
				</div>

				<div class="cpic-formats" id="cpic-formats" role="group" aria-label="<?php esc_attr_e( 'Output format', 'custom-post-image-creator' ); ?>">
					<?php foreach ( \CPIC\Rendering\Formats::all() as $cpic_key => $cpic_preset ) : ?>
						<?php
						$cpic_active = isset( $cpic_document['canvas']['format'] ) && (string) $cpic_key === (string) $cpic_document['canvas']['format'];
						?>
						<button
							type="button"
							class="cpic-format"
							data-format="<?php echo esc_attr( (string) $cpic_key ); ?>"
							data-label="<?php echo esc_attr( (string) $cpic_preset['label'] ); ?>"
							data-width="<?php echo esc_attr( (string) (int) $cpic_preset['width'] ); ?>"
							data-height="<?php echo esc_attr( (string) (int) $cpic_preset['height'] ); ?>"
							data-ratio="<?php echo esc_attr( (string) $cpic_preset['ratio'] ); ?>"
							aria-pressed="<?php echo $cpic_active ? 'true' : 'false'; ?>"
							title="<?php echo esc_attr( sprintf( '%1$s — %2$d × %3$d', $cpic_preset['label'], (int) $cpic_preset['width'], (int) $cpic_preset['height'] ) ); ?>"
						>
							<span class="cpic-format__shape" data-ratio="<?php echo esc_attr( (string) $cpic_preset['ratio'] ); ?>" aria-hidden="true"></span>
							<span class="cpic-format__text">
								<span class="cpic-format__label"><?php echo esc_html( (string) $cpic_preset['label'] ); ?></span>
								<span class="cpic-format__dims"><?php echo esc_html( (int) $cpic_preset['width'] . '×' . (int) $cpic_preset['height'] ); ?></span>
							</span>
						</button>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="cpic-app__header-right">
				<button type="button" class="cpic-btn cpic-btn--ghost" id="cpic-preview" title="<?php esc_attr_e( 'Render a full-size preview', 'custom-post-image-creator' ); ?>">
					<span class="cpic-svg" data-icon="eye" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Preview', 'custom-post-image-creator' ); ?></span>
				</button>

				<?php if ( $cpic_state['can']['save'] ) : ?>
					<button type="button" class="cpic-btn" id="cpic-save" title="<?php esc_attr_e( 'Save design (Ctrl+S)', 'custom-post-image-creator' ); ?>">
						<span class="cpic-svg" data-icon="save" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Save', 'custom-post-image-creator' ); ?></span>
					</button>
				<?php endif; ?>

				<div class="cpic-menu-wrap">
					<button type="button" class="cpic-btn" id="cpic-export" aria-haspopup="menu" aria-expanded="false" title="<?php esc_attr_e( 'Export and download', 'custom-post-image-creator' ); ?>">
						<span class="cpic-svg" data-icon="download" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Export', 'custom-post-image-creator' ); ?></span>
						<span class="cpic-svg cpic-svg--caret" data-icon="chevron-down" aria-hidden="true"></span>
					</button>
				</div>

				<?php if ( $cpic_state['can']['publish'] ) : ?>
					<button type="button" class="cpic-btn cpic-btn--primary" id="cpic-publish" title="<?php esc_attr_e( 'Publish this image', 'custom-post-image-creator' ); ?>">
						<span class="cpic-svg" data-icon="send" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Publish', 'custom-post-image-creator' ); ?></span>
					</button>
				<?php endif; ?>

				<div class="cpic-menu-wrap">
					<button type="button" class="cpic-icon-btn" id="cpic-more" aria-haspopup="menu" aria-expanded="false" aria-label="<?php esc_attr_e( 'More actions', 'custom-post-image-creator' ); ?>" title="<?php esc_attr_e( 'More actions', 'custom-post-image-creator' ); ?>">
						<span class="cpic-svg" data-icon="more" aria-hidden="true"></span>
					</button>
				</div>

				<button type="button" class="cpic-icon-btn cpic-app__panel-toggle" id="cpic-toggle-right" aria-label="<?php esc_attr_e( 'Toggle inspector panel', 'custom-post-image-creator' ); ?>" aria-expanded="true" title="<?php esc_attr_e( 'Properties', 'custom-post-image-creator' ); ?>">
					<span class="cpic-svg" data-icon="panel-right" aria-hidden="true"></span>
				</button>
			</div>
		</header>

		<div class="cpic-app__body">
			<?php /* ── Left panel ───────────────────────────────────── */ ?>
			<aside class="cpic-panel cpic-panel--left" id="cpic-panel-left" aria-label="<?php esc_attr_e( 'Tools and layers', 'custom-post-image-creator' ); ?>">
				<div class="cpic-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Tools', 'custom-post-image-creator' ); ?>">
					<button type="button" role="tab" class="cpic-tab is-active" data-tab="design" aria-selected="true" title="<?php esc_attr_e( 'Designs', 'custom-post-image-creator' ); ?>">
						<span class="cpic-svg" data-icon="template" aria-hidden="true"></span><span><?php esc_html_e( 'Design', 'custom-post-image-creator' ); ?></span>
					</button>
					<button type="button" role="tab" class="cpic-tab" data-tab="elements" aria-selected="false" title="<?php esc_attr_e( 'Elements', 'custom-post-image-creator' ); ?>">
						<span class="cpic-svg" data-icon="shapes" aria-hidden="true"></span><span><?php esc_html_e( 'Elements', 'custom-post-image-creator' ); ?></span>
					</button>
					<button type="button" role="tab" class="cpic-tab" data-tab="layers" aria-selected="false" title="<?php esc_attr_e( 'Layers', 'custom-post-image-creator' ); ?>">
						<span class="cpic-svg" data-icon="layers" aria-hidden="true"></span><span><?php esc_html_e( 'Layers', 'custom-post-image-creator' ); ?></span>
					</button>
				</div>

				<div class="cpic-panel__body">
					<section class="cpic-tabpanel" data-panel="design" role="tabpanel">
						<div class="cpic-panel__scroll" id="cpic-panel-design">
							<div class="cpic-panel__section">
								<div class="cpic-panel__heading">
									<span><?php esc_html_e( 'Start a new design', 'custom-post-image-creator' ); ?></span>
								</div>
								<div class="cpic-quick-formats" id="cpic-new-formats" role="group" aria-label="<?php esc_attr_e( 'New design format', 'custom-post-image-creator' ); ?>"></div>
								<label class="cpic-field">
									<span class="cpic-field__label"><?php esc_html_e( 'Name', 'custom-post-image-creator' ); ?></span>
									<input type="text" id="cpic-new-name" placeholder="<?php esc_attr_e( 'Untitled design', 'custom-post-image-creator' ); ?>" />
								</label>
								<button type="button" class="cpic-btn cpic-btn--primary cpic-btn--block" id="cpic-new-design">
									<span class="cpic-svg" data-icon="plus" aria-hidden="true"></span>
									<span><?php esc_html_e( 'Create design', 'custom-post-image-creator' ); ?></span>
								</button>
							</div>

							<div class="cpic-panel__section">
								<div class="cpic-panel__heading">
									<span><?php esc_html_e( 'Saved designs', 'custom-post-image-creator' ); ?></span>
									<span class="cpic-badge" id="cpic-template-count"></span>
								</div>
								<div class="cpic-search">
									<span class="cpic-svg" data-icon="search" aria-hidden="true"></span>
									<input type="search" id="cpic-template-search" placeholder="<?php esc_attr_e( 'Search designs', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Search designs', 'custom-post-image-creator' ); ?>" />
								</div>
								<ul class="cpic-design-list" id="cpic-design-list"></ul>
							</div>

							<div class="cpic-panel__section">
								<div class="cpic-panel__heading">
									<span><?php esc_html_e( 'Starter layouts', 'custom-post-image-creator' ); ?></span>
								</div>
								<p class="cpic-panel__hint"><?php esc_html_e( 'Starting from a starter replaces the current design. You are asked before anything is lost.', 'custom-post-image-creator' ); ?></p>
								<ul class="cpic-design-list" id="cpic-starter-list"></ul>
							</div>
						</div>
					</section>

					<section class="cpic-tabpanel" data-panel="elements" role="tabpanel" hidden>
						<div class="cpic-panel__scroll">
							<div class="cpic-panel__section">
								<div class="cpic-panel__heading"><span><?php esc_html_e( 'Text', 'custom-post-image-creator' ); ?></span></div>
								<div class="cpic-element-grid" id="cpic-elements-text"></div>
							</div>
							<div class="cpic-panel__section">
								<div class="cpic-panel__heading"><span><?php esc_html_e( 'Media', 'custom-post-image-creator' ); ?></span></div>
								<div class="cpic-element-grid" id="cpic-elements-media"></div>
							</div>
							<div class="cpic-panel__section">
								<div class="cpic-panel__heading"><span><?php esc_html_e( 'Shapes', 'custom-post-image-creator' ); ?></span></div>
								<div class="cpic-element-grid" id="cpic-elements-shapes"></div>
							</div>
						</div>
					</section>

					<section class="cpic-tabpanel" data-panel="layers" role="tabpanel" hidden>
						<div class="cpic-panel__toolbar" id="cpic-layer-toolbar">
							<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" data-layer-action="forward" title="<?php esc_attr_e( 'Move forward', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Move forward', 'custom-post-image-creator' ); ?>">
								<span class="cpic-svg" data-icon="arrow-up" aria-hidden="true"></span>
							</button>
							<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" data-layer-action="backward" title="<?php esc_attr_e( 'Move backward', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Move backward', 'custom-post-image-creator' ); ?>">
								<span class="cpic-svg" data-icon="arrow-down" aria-hidden="true"></span>
							</button>
							<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" data-layer-action="front" title="<?php esc_attr_e( 'Bring to front', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Bring to front', 'custom-post-image-creator' ); ?>">
								<span class="cpic-svg" data-icon="to-front" aria-hidden="true"></span>
							</button>
							<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" data-layer-action="back" title="<?php esc_attr_e( 'Send to back', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Send to back', 'custom-post-image-creator' ); ?>">
								<span class="cpic-svg" data-icon="to-back" aria-hidden="true"></span>
							</button>
							<span class="cpic-panel__toolbar-spacer"></span>
							<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" data-layer-action="duplicate" title="<?php esc_attr_e( 'Duplicate', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Duplicate layer', 'custom-post-image-creator' ); ?>">
								<span class="cpic-svg" data-icon="copy" aria-hidden="true"></span>
							</button>
							<button type="button" class="cpic-icon-btn cpic-icon-btn--sm cpic-icon-btn--danger" data-layer-action="delete" title="<?php esc_attr_e( 'Delete', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Delete layer', 'custom-post-image-creator' ); ?>">
								<span class="cpic-svg" data-icon="trash" aria-hidden="true"></span>
							</button>
						</div>
						<ul class="cpic-layer-list" id="cpic-layers" aria-label="<?php esc_attr_e( 'Layers, topmost first', 'custom-post-image-creator' ); ?>"></ul>
						<p class="cpic-panel__hint cpic-panel__hint--footer" id="cpic-layer-hint"><?php esc_html_e( 'Drag to reorder. Double-click a name to rename it.', 'custom-post-image-creator' ); ?></p>
					</section>
				</div>
			</aside>

			<?php /* ── Canvas workspace ─────────────────────────────── */ ?>
			<main class="cpic-workspace" id="cpic-workspace" aria-label="<?php esc_attr_e( 'Design canvas', 'custom-post-image-creator' ); ?>">
				<div class="cpic-workspace__bar" role="toolbar" aria-label="<?php esc_attr_e( 'Canvas tools', 'custom-post-image-creator' ); ?>">
					<div class="cpic-tool-group">
						<button type="button" class="cpic-tool is-active" data-tool="select" title="<?php esc_attr_e( 'Select (V)', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Select tool', 'custom-post-image-creator' ); ?>" aria-pressed="true">
							<span class="cpic-svg" data-icon="cursor" aria-hidden="true"></span>
						</button>
						<button type="button" class="cpic-tool" data-tool="pan" title="<?php esc_attr_e( 'Pan (H or hold Space)', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Pan tool', 'custom-post-image-creator' ); ?>" aria-pressed="false">
							<span class="cpic-svg" data-icon="hand" aria-hidden="true"></span>
						</button>
					</div>

					<span class="cpic-tool-sep" aria-hidden="true"></span>

					<div class="cpic-tool-group">
						<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" id="cpic-zoom-out" title="<?php esc_attr_e( 'Zoom out (Ctrl+-)', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Zoom out', 'custom-post-image-creator' ); ?>">
							<span class="cpic-svg" data-icon="minus" aria-hidden="true"></span>
						</button>
						<button type="button" class="cpic-zoom-value" id="cpic-zoom-value" title="<?php esc_attr_e( 'Reset to 100%', 'custom-post-image-creator' ); ?>">100%</button>
						<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" id="cpic-zoom-in" title="<?php esc_attr_e( 'Zoom in (Ctrl++)', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Zoom in', 'custom-post-image-creator' ); ?>">
							<span class="cpic-svg" data-icon="plus" aria-hidden="true"></span>
						</button>
						<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" id="cpic-zoom-fit" title="<?php esc_attr_e( 'Fit to screen (Shift+1)', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Fit to screen', 'custom-post-image-creator' ); ?>">
							<span class="cpic-svg" data-icon="fit" aria-hidden="true"></span>
						</button>
						<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" id="cpic-zoom-actual" title="<?php esc_attr_e( 'Actual size (Shift+0)', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Actual size', 'custom-post-image-creator' ); ?>">
							<span class="cpic-svg" data-icon="one-to-one" aria-hidden="true"></span>
						</button>
					</div>

					<span class="cpic-tool-sep" aria-hidden="true"></span>

					<div class="cpic-tool-group">
						<button type="button" class="cpic-tool cpic-tool--wide" id="cpic-toggle-grid" title="<?php esc_attr_e( 'Show grid', 'custom-post-image-creator' ); ?>" aria-pressed="false">
							<span class="cpic-svg" data-icon="grid" aria-hidden="true"></span>
							<span class="cpic-tool__label"><?php esc_html_e( 'Grid', 'custom-post-image-creator' ); ?></span>
						</button>
						<button type="button" class="cpic-tool cpic-tool--wide" id="cpic-toggle-rulers" title="<?php esc_attr_e( 'Show rulers', 'custom-post-image-creator' ); ?>" aria-pressed="true">
							<span class="cpic-svg" data-icon="ruler" aria-hidden="true"></span>
							<span class="cpic-tool__label"><?php esc_html_e( 'Rulers', 'custom-post-image-creator' ); ?></span>
						</button>
						<button type="button" class="cpic-tool cpic-tool--wide" id="cpic-toggle-safe" title="<?php esc_attr_e( 'Show safe area', 'custom-post-image-creator' ); ?>" aria-pressed="true">
							<span class="cpic-svg" data-icon="safe" aria-hidden="true"></span>
							<span class="cpic-tool__label"><?php esc_html_e( 'Safe area', 'custom-post-image-creator' ); ?></span>
						</button>
						<button type="button" class="cpic-tool cpic-tool--wide" id="cpic-toggle-snap" title="<?php esc_attr_e( 'Snap to guides and grid', 'custom-post-image-creator' ); ?>" aria-pressed="true">
							<span class="cpic-svg" data-icon="magnet" aria-hidden="true"></span>
							<span class="cpic-tool__label"><?php esc_html_e( 'Snap', 'custom-post-image-creator' ); ?></span>
						</button>
					</div>

					<span class="cpic-tool-sep" aria-hidden="true"></span>

					<div class="cpic-tool-group">
						<button type="button" class="cpic-tool cpic-tool--wide" id="cpic-shortcuts" title="<?php esc_attr_e( 'Keyboard shortcuts', 'custom-post-image-creator' ); ?>">
							<span class="cpic-svg" data-icon="keyboard" aria-hidden="true"></span>
							<span class="cpic-tool__label"><?php esc_html_e( 'Shortcuts', 'custom-post-image-creator' ); ?></span>
						</button>
					</div>
				</div>

				<div class="cpic-canvas-area" id="cpic-canvas-area">
					<div class="cpic-rulers" id="cpic-rulers" hidden aria-hidden="true">
						<div class="cpic-ruler cpic-ruler--corner"></div>
						<canvas class="cpic-ruler cpic-ruler--h" id="cpic-ruler-h"></canvas>
						<canvas class="cpic-ruler cpic-ruler--v" id="cpic-ruler-v"></canvas>
					</div>

					<div class="cpic-viewport" id="cpic-viewport" tabindex="0" aria-label="<?php esc_attr_e( 'Canvas viewport. Use the arrow keys to nudge the selection.', 'custom-post-image-creator' ); ?>">
						<div class="cpic-canvas-scaler" id="cpic-canvas-scaler">
						<div class="cpic-canvas-frame" id="cpic-canvas-frame" data-zoom="fit">
							<canvas id="cpic-canvas" width="1080" height="1080" aria-label="<?php esc_attr_e( 'Design canvas', 'custom-post-image-creator' ); ?>" role="img"></canvas>
							<div class="cpic-overlay" id="cpic-overlay" aria-hidden="true">
								<canvas id="cpic-overlay-canvas"></canvas>
							</div>
							<div class="cpic-selection-toolbar" id="cpic-selection-toolbar" hidden>
								<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" data-sel-action="duplicate" title="<?php esc_attr_e( 'Duplicate (Ctrl+D)', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Duplicate', 'custom-post-image-creator' ); ?>">
									<span class="cpic-svg" data-icon="copy" aria-hidden="true"></span>
								</button>
								<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" data-sel-action="lock" title="<?php esc_attr_e( 'Lock or unlock', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Lock or unlock', 'custom-post-image-creator' ); ?>">
									<span class="cpic-svg" data-icon="lock" aria-hidden="true"></span>
								</button>
								<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" data-sel-action="hide" title="<?php esc_attr_e( 'Hide', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Hide', 'custom-post-image-creator' ); ?>">
									<span class="cpic-svg" data-icon="eye-off" aria-hidden="true"></span>
								</button>
								<span class="cpic-selection-toolbar__sep" aria-hidden="true"></span>
								<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" data-sel-action="front" title="<?php esc_attr_e( 'Bring to front', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Bring to front', 'custom-post-image-creator' ); ?>">
									<span class="cpic-svg" data-icon="to-front" aria-hidden="true"></span>
								</button>
								<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" data-sel-action="back" title="<?php esc_attr_e( 'Send to back', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Send to back', 'custom-post-image-creator' ); ?>">
									<span class="cpic-svg" data-icon="to-back" aria-hidden="true"></span>
								</button>
								<span class="cpic-selection-toolbar__sep" aria-hidden="true"></span>
								<button type="button" class="cpic-icon-btn cpic-icon-btn--sm cpic-icon-btn--danger" data-sel-action="delete" title="<?php esc_attr_e( 'Delete (Del)', 'custom-post-image-creator' ); ?>" aria-label="<?php esc_attr_e( 'Delete', 'custom-post-image-creator' ); ?>">
									<span class="cpic-svg" data-icon="trash" aria-hidden="true"></span>
								</button>
							</div>
							<div class="cpic-canvas-loading" id="cpic-canvas-loading" hidden>
								<span class="cpic-spinner" aria-hidden="true"></span>
								<span><?php esc_html_e( 'Rendering…', 'custom-post-image-creator' ); ?></span>
							</div>
						</div>
						</div>
					</div>

					<div class="cpic-workspace__status" id="cpic-canvas-meta" aria-live="polite"></div>
				</div>

				<div class="cpic-toasts" id="cpic-toasts" role="status" aria-live="polite"></div>
			</main>

			<?php /* ── Right panel ──────────────────────────────────── */ ?>
			<aside class="cpic-panel cpic-panel--right" id="cpic-panel-right" aria-label="<?php esc_attr_e( 'Properties', 'custom-post-image-creator' ); ?>">
				<div class="cpic-inspector__head">
					<span class="cpic-inspector__title" id="cpic-inspector-title"><?php esc_html_e( 'Canvas', 'custom-post-image-creator' ); ?></span>
					<span class="cpic-inspector__badge" id="cpic-inspector-badge"></span>
				</div>
				<div class="cpic-inspector" id="cpic-inspector"></div>
			</aside>
		</div>

		<?php /* ── Floating UI ──────────────────────────────────────── */ ?>
		<div class="cpic-menu" id="cpic-menu" role="menu" hidden></div>
		<div class="cpic-context-menu" id="cpic-context-menu" role="menu" hidden></div>

		<div class="cpic-modal" id="cpic-modal" hidden>
			<div class="cpic-modal__backdrop" data-modal-close="1"></div>
			<div class="cpic-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="cpic-modal-title">
				<div class="cpic-modal__head">
					<h2 class="cpic-modal__title" id="cpic-modal-title"></h2>
					<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" data-modal-close="1" aria-label="<?php esc_attr_e( 'Close', 'custom-post-image-creator' ); ?>">
						<span class="cpic-svg" data-icon="close" aria-hidden="true"></span>
					</button>
				</div>
				<div class="cpic-modal__body" id="cpic-modal-body"></div>
				<div class="cpic-modal__foot" id="cpic-modal-foot"></div>
			</div>
		</div>

		<div class="cpic-onboarding" id="cpic-onboarding" hidden>
			<div class="cpic-onboarding__dialog" role="dialog" aria-modal="true" aria-labelledby="cpic-onboarding-title">
				<h2 id="cpic-onboarding-title"><?php esc_html_e( 'Welcome to the Design Studio', 'custom-post-image-creator' ); ?></h2>
				<p><?php esc_html_e( 'A quick tour of the places you will spend the most time.', 'custom-post-image-creator' ); ?></p>
				<ul class="cpic-onboarding__list">
					<li>
						<span class="cpic-svg" data-icon="template" aria-hidden="true"></span>
						<span><strong><?php esc_html_e( 'Design tab', 'custom-post-image-creator' ); ?></strong> — <?php esc_html_e( 'start from a saved design or a starter layout.', 'custom-post-image-creator' ); ?></span>
					</li>
					<li>
						<span class="cpic-svg" data-icon="shapes" aria-hidden="true"></span>
						<span><strong><?php esc_html_e( 'Elements tab', 'custom-post-image-creator' ); ?></strong> — <?php esc_html_e( 'add Bengali headlines, images, logos and shapes.', 'custom-post-image-creator' ); ?></span>
					</li>
					<li>
						<span class="cpic-svg" data-icon="layers" aria-hidden="true"></span>
						<span><strong><?php esc_html_e( 'Layers tab', 'custom-post-image-creator' ); ?></strong> — <?php esc_html_e( 'reorder, rename, hide and lock anything on the canvas.', 'custom-post-image-creator' ); ?></span>
					</li>
					<li>
						<span class="cpic-svg" data-icon="move" aria-hidden="true"></span>
						<span><strong><?php esc_html_e( 'Canvas', 'custom-post-image-creator' ); ?></strong> — <?php esc_html_e( 'drag to move, handles to resize and rotate, Space + drag to pan, Ctrl + wheel to zoom.', 'custom-post-image-creator' ); ?></span>
					</li>
					<li>
						<span class="cpic-svg" data-icon="sliders" aria-hidden="true"></span>
						<span><strong><?php esc_html_e( 'Inspector', 'custom-post-image-creator' ); ?></strong> — <?php esc_html_e( 'position, typography, fill, border, shadow and effects for the selection.', 'custom-post-image-creator' ); ?></span>
					</li>
				</ul>
				<div class="cpic-onboarding__actions">
					<button type="button" class="cpic-btn" id="cpic-onboarding-shortcuts"><?php esc_html_e( 'See all shortcuts', 'custom-post-image-creator' ); ?></button>
					<button type="button" class="cpic-btn cpic-btn--primary" id="cpic-onboarding-dismiss"><?php esc_html_e( 'Start designing', 'custom-post-image-creator' ); ?></button>
				</div>
			</div>
		</div>

		<?php /* ── Preview / result sheet ───────────────────────────── */ ?>
		<section class="cpic-result" id="cpic-result" hidden>
			<div class="cpic-result__head">
				<h2 class="cpic-result__title"><?php esc_html_e( 'Latest render', 'custom-post-image-creator' ); ?></h2>
				<button type="button" class="cpic-icon-btn cpic-icon-btn--sm" id="cpic-result-close" aria-label="<?php esc_attr_e( 'Close', 'custom-post-image-creator' ); ?>">
					<span class="cpic-svg" data-icon="close" aria-hidden="true"></span>
				</button>
			</div>
			<div class="cpic-result__body" id="cpic-result-body"></div>
		</section>
	</div>
</div>
