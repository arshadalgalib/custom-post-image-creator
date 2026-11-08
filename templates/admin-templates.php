<?php
/**
 * Templates admin view – list + editor + server-side preview.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$cpic_templates = CPIC_Template_Manager::get_templates();
$cpic_default_id = (int) CPIC_Settings::get_single( 'cpic_default_template_id' );
?>
<div class="cpic-wrap cpic-templates-wrap">
    <div class="cpic-section">
        <h2><?php esc_html_e( 'Template Manager', 'custom-post-image-creator' ); ?></h2>
        <p class="cpic-preview-hint">
            <?php esc_html_e( 'Create unlimited photocard templates. Each template defines the complete card composition (canvas, background, logo, featured image, headline, category, date, author, website name, shapes). Templates are stored as structured configuration, never hard-coded.', 'custom-post-image-creator' ); ?>
        </p>

        <button type="button" id="cpic-tpl-add" class="button button-primary"><?php esc_html_e( 'Add New Template', 'custom-post-image-creator' ); ?></button>
        <button type="button" id="cpic-tpl-import" class="button"><?php esc_html_e( 'Import Template', 'custom-post-image-creator' ); ?></button>
        <input type="file" id="cpic-tpl-import-file" accept="application/json,.json" style="display:none;" />

        <div class="cpic-table-wrapper" style="margin-top:16px;">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Default', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Assigned Categories', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'custom-post-image-creator' ); ?></th>
                    </tr>
                </thead>
                <tbody id="cpic-tpl-list">
                <?php foreach ( $cpic_templates as $cpic_tpl ) : ?>
                    <?php
                    $cpic_tpl_mapped = array();
                    foreach ( cpic()->category_mapper->get_mappings() as $cpic_map ) {
                        if ( (int) $cpic_map['template_id'] === (int) $cpic_tpl['id'] ) {
                            $cpic_term = get_term( (int) $cpic_map['term_id'], 'category' );
                            if ( $cpic_term && ! is_wp_error( $cpic_term ) ) {
                                $cpic_tpl_mapped[] = $cpic_term->name;
                            }
                        }
                    }
                    ?>
                    <tr data-id="<?php echo esc_attr( (int) $cpic_tpl['id'] ); ?>">
                        <td><strong><?php echo esc_html( $cpic_tpl['name'] ); ?></strong>
                            <br /><small class="cpic-hint"><?php echo esc_html( $cpic_tpl['slug'] ); ?></small>
                        </td>
                        <td>
                            <?php if ( ! empty( $cpic_tpl['is_active'] ) ) : ?>
                                <span class="cpic-connected"><?php esc_html_e( 'Active', 'custom-post-image-creator' ); ?></span>
                            <?php else : ?>
                                <span class="cpic-disconnected"><?php esc_html_e( 'Disabled', 'custom-post-image-creator' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( ! empty( $cpic_tpl['is_default'] ) ) : ?>
                                <span class="cpic-connected"><?php esc_html_e( 'Default', 'custom-post-image-creator' ); ?></span>
                            <?php else : ?>
                                <button type="button" class="button button-small cpic-tpl-default" data-id="<?php echo esc_attr( (int) $cpic_tpl['id'] ); ?>"><?php esc_html_e( 'Make Default', 'custom-post-image-creator' ); ?></button>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $cpic_tpl_mapped ? implode( ', ', $cpic_tpl_mapped ) : '—' ); ?></td>
                        <td class="cpic-actions">
                            <button type="button" class="button button-small cpic-tpl-edit" data-id="<?php echo esc_attr( (int) $cpic_tpl['id'] ); ?>"><?php esc_html_e( 'Edit', 'custom-post-image-creator' ); ?></button>
                            <button type="button" class="button button-small cpic-tpl-duplicate" data-id="<?php echo esc_attr( (int) $cpic_tpl['id'] ); ?>"><?php esc_html_e( 'Duplicate', 'custom-post-image-creator' ); ?></button>
                            <button type="button" class="button button-small cpic-tpl-preview" data-id="<?php echo esc_attr( (int) $cpic_tpl['id'] ); ?>"><?php esc_html_e( 'Preview', 'custom-post-image-creator' ); ?></button>
                            <button type="button" class="button button-small cpic-tpl-export" data-id="<?php echo esc_attr( (int) $cpic_tpl['id'] ); ?>"><?php esc_html_e( 'Export', 'custom-post-image-creator' ); ?></button>
                            <?php if ( ! empty( $cpic_tpl['is_active'] ) ) : ?>
                                <button type="button" class="button button-small cpic-tpl-toggle" data-id="<?php echo esc_attr( (int) $cpic_tpl['id'] ); ?>" data-active="0"><?php esc_html_e( 'Disable', 'custom-post-image-creator' ); ?></button>
                            <?php else : ?>
                                <button type="button" class="button button-small cpic-tpl-toggle" data-id="<?php echo esc_attr( (int) $cpic_tpl['id'] ); ?>" data-active="1"><?php esc_html_e( 'Enable', 'custom-post-image-creator' ); ?></button>
                            <?php endif; ?>
                            <button type="button" class="button button-small button-link-delete cpic-tpl-delete" data-id="<?php echo esc_attr( (int) $cpic_tpl['id'] ); ?>"><?php esc_html_e( 'Delete', 'custom-post-image-creator' ); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $cpic_templates ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No templates yet. Create your first template.', 'custom-post-image-creator' ); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Editor -->
    <div class="cpic-section cpic-tpl-editor" id="cpic-tpl-editor" style="display:none;">
        <h2 id="cpic-tpl-editor-title"><?php esc_html_e( 'Edit Template', 'custom-post-image-creator' ); ?></h2>
        <input type="hidden" id="cpic-tpl-id" value="0" />

        <div class="cpic-row">
            <div class="cpic-col cpic-card" style="flex:2 1 640px;">
                <label><?php esc_html_e( 'Template Name', 'custom-post-image-creator' ); ?>
                    <input type="text" id="cpic-tpl-name" value="" />
                </label>

                <h3><?php esc_html_e( 'Canvas', 'custom-post-image-creator' ); ?></h3>
                <label><?php esc_html_e( 'Width (px)', 'custom-post-image-creator' ); ?>
                    <input type="number" id="cpic-tpl-canvas-width" min="200" max="4096" value="1080" />
                </label>
                <label><?php esc_html_e( 'Height (px)', 'custom-post-image-creator' ); ?>
                    <input type="number" id="cpic-tpl-canvas-height" min="200" max="4096" value="1080" />
                </label>
                <label><?php esc_html_e( 'Background Type', 'custom-post-image-creator' ); ?>
                    <select id="cpic-tpl-bg-type">
                        <option value="gradient"><?php esc_html_e( 'Gradient', 'custom-post-image-creator' ); ?></option>
                        <option value="color"><?php esc_html_e( 'Solid Colour', 'custom-post-image-creator' ); ?></option>
                        <option value="image"><?php esc_html_e( 'Image', 'custom-post-image-creator' ); ?></option>
                    </select>
                </label>
                <label id="cpic-tpl-bg-color-row"><?php esc_html_e( 'Solid Colour', 'custom-post-image-creator' ); ?>
                    <input type="text" id="cpic-tpl-bg-color" class="cpic-color-picker" value="#1a1a2e" />
                </label>
                <label id="cpic-tpl-bg-grad-row"><?php esc_html_e( 'Gradient From', 'custom-post-image-creator' ); ?>
                    <input type="text" id="cpic-tpl-bg-grad-from" class="cpic-color-picker" value="#667eea" />
                </label>
                <label id="cpic-tpl-bg-grad-row2"><?php esc_html_e( 'Gradient To', 'custom-post-image-creator' ); ?>
                    <input type="text" id="cpic-tpl-bg-grad-to" class="cpic-color-picker" value="#764ba2" />
                </label>
                <label id="cpic-tpl-bg-img-row"><?php esc_html_e( 'Background Image', 'custom-post-image-creator' ); ?>
                    <input type="text" id="cpic-tpl-bg-img" value="" />
                    <button type="button" class="button button-small cpic-media-bg"><?php esc_html_e( 'Select Image', 'custom-post-image-creator' ); ?></button>
                </label>
            </div>

            <div class="cpic-col cpic-card">
                <h3><?php esc_html_e( 'Live Preview', 'custom-post-image-creator' ); ?></h3>
                <div class="cpic-tpl-preview-box">
                    <img id="cpic-tpl-preview-img" src="" alt="" style="max-width:100%;display:none;" />
                    <p id="cpic-tpl-preview-empty" class="cpic-preview-hint"><?php esc_html_e( 'Save the template first, then click "Preview". Preview uses the real server renderer with sample data.', 'custom-post-image-creator' ); ?></p>
                </div>
                <button type="button" id="cpic-tpl-preview-btn" class="button"><?php esc_html_e( 'Preview Template', 'custom-post-image-creator' ); ?></button>
            </div>
        </div>

        <h3><?php esc_html_e( 'Facebook Caption Content', 'custom-post-image-creator' ); ?></h3>
        <div class="cpic-card">
            <p class="cpic-preview-hint"><?php esc_html_e( 'Choose which news content is included in the Facebook caption for this template. The first paragraph remains enabled by default for existing templates.', 'custom-post-image-creator' ); ?></p>
            <label>
                <input type="checkbox" id="cpic-tpl-caption-headline" value="1" />
                <?php esc_html_e( 'Include news headline', 'custom-post-image-creator' ); ?>
            </label>
            <label>
                <input type="checkbox" id="cpic-tpl-caption-first-paragraph" value="1" checked="checked" />
                <?php esc_html_e( 'Include first paragraph', 'custom-post-image-creator' ); ?>
            </label>
        </div>

        <h3><?php esc_html_e( 'Elements', 'custom-post-image-creator' ); ?></h3>

        <div class="cpic-row">
            <?php
            // Each element is rendered generically and wired in JS.
            $elements = array(
                'featured_image' => __( 'Featured Image', 'custom-post-image-creator' ),
                'headline'       => __( 'Headline', 'custom-post-image-creator' ),
                'category'       => __( 'Category Label', 'custom-post-image-creator' ),
                'date'           => __( 'Date', 'custom-post-image-creator' ),
                'logo'           => __( 'Logo', 'custom-post-image-creator' ),
                'author'         => __( 'Author / Reporter', 'custom-post-image-creator' ),
                'website_name'   => __( 'Website Name', 'custom-post-image-creator' ),
            );
            foreach ( $elements as $key => $label ) :
            ?>
            <div class="cpic-col cpic-card cpic-tpl-element" data-element="<?php echo esc_attr( $key ); ?>">
                <h3>
                    <input type="checkbox" class="cpic-tpl-el-enabled" id="cpic-tpl-<?php echo esc_attr( $key ); ?>-enabled" />
                    <?php echo esc_html( $label ); ?>
                </h3>
                <div class="cpic-tpl-el-fields"></div>
            </div>
            <?php endforeach; ?>
        </div>

        <h3><?php esc_html_e( 'Shapes / Decorative Elements', 'custom-post-image-creator' ); ?></h3>
        <div class="cpic-card">
            <p class="cpic-preview-hint"><?php esc_html_e( 'Optional rectangles, lines and ellipses layered behind/between elements.', 'custom-post-image-creator' ); ?></p>
            <div id="cpic-tpl-shapes"></div>
            <button type="button" id="cpic-tpl-shape-add" class="button"><?php esc_html_e( 'Add Shape', 'custom-post-image-creator' ); ?></button>
        </div>

        <p style="margin-top:16px;">
            <button type="button" id="cpic-tpl-save" class="button button-primary button-hero"><?php esc_html_e( 'Save Template', 'custom-post-image-creator' ); ?></button>
            <button type="button" id="cpic-tpl-cancel" class="button button-hero"><?php esc_html_e( 'Cancel', 'custom-post-image-creator' ); ?></button>
            <span id="cpic-tpl-status"></span>
        </p>
    </div>
</div>
