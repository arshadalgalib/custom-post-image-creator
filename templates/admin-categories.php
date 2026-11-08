<?php
/**
 * Category Mapping admin view.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$cpic_templates = CPIC_Template_Manager::get_templates( true );
$cpic_default_id = (int) CPIC_Settings::get_single( 'cpic_default_template_id' );
$cpic_mapped     = cpic()->category_mapper->get_mapped_terms();
$cpic_categories = get_terms(
    array(
        'taxonomy'   => 'category',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    )
);
?>
<div class="cpic-wrap">
    <div class="cpic-section">
        <h2><?php esc_html_e( 'Category → Template Mapping', 'custom-post-image-creator' ); ?></h2>

        <p class="cpic-preview-hint">
            <?php esc_html_e( 'Assign a photocard template to each WordPress category. When a post belongs to several mapped categories, the mapping with the lowest Priority number (highest priority) decides the template — never a random choice. If no category matches, the Default template is used.', 'custom-post-image-creator' ); ?>
        </p>

        <div class="cpic-card" style="margin-bottom:16px;">
            <label><?php esc_html_e( 'Default Template (fallback when no category matches)', 'custom-post-image-creator' ); ?>
                <select id="cpic-default-template-id">
                    <option value=""><?php esc_html_e( '— Select default template —', 'custom-post-image-creator' ); ?></option>
                    <?php foreach ( $cpic_templates as $cpic_tpl ) : ?>
                        <option value="<?php echo esc_attr( (int) $cpic_tpl['id'] ); ?>" <?php selected( $cpic_default_id, (int) $cpic_tpl['id'] ); ?>>
                            <?php echo esc_html( $cpic_tpl['name'] ); ?><?php echo ! empty( $cpic_tpl['is_default'] ) ? ' (' . esc_html__( 'Default', 'custom-post-image-creator' ) . ')' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="cpic-table-wrapper">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Category', 'custom-post-image-creator' ); ?></th>
                        <th><?php esc_html_e( 'Template', 'custom-post-image-creator' ); ?></th>
                        <th style="width:120px;"><?php esc_html_e( 'Priority', 'custom-post-image-creator' ); ?></th>
                        <th style="width:140px;"><?php esc_html_e( 'Action', 'custom-post-image-creator' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( is_wp_error( $cpic_categories ) || empty( $cpic_categories ) ) : ?>
                    <tr><td colspan="4"><?php esc_html_e( 'No categories found.', 'custom-post-image-creator' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $cpic_categories as $cpic_cat ) : ?>
                        <?php
                        $cpic_map     = isset( $cpic_mapped[ (int) $cpic_cat->term_id ] ) ? $cpic_mapped[ (int) $cpic_cat->term_id ] : null;
                        $cpic_map_tid = $cpic_map ? (int) $cpic_map['template_id'] : 0;
                        $cpic_prio    = $cpic_map ? (int) $cpic_map['priority'] : 1;
                        ?>
                        <tr data-term="<?php echo esc_attr( (int) $cpic_cat->term_id ); ?>">
                            <td><strong><?php echo esc_html( $cpic_cat->name ); ?></strong>
                                <br /><small class="cpic-hint"><?php echo esc_html( sprintf( __( '%d posts', 'custom-post-image-creator' ), (int) $cpic_cat->count ) ); ?></small>
                            </td>
                            <td>
                                <select class="cpic-cat-template" data-term="<?php echo esc_attr( (int) $cpic_cat->term_id ); ?>">
                                    <option value=""><?php esc_html_e( '— No template —', 'custom-post-image-creator' ); ?></option>
                                    <?php foreach ( $cpic_templates as $cpic_tpl ) : ?>
                                        <option value="<?php echo esc_attr( (int) $cpic_tpl['id'] ); ?>" <?php selected( $cpic_map_tid, (int) $cpic_tpl['id'] ); ?>>
                                            <?php echo esc_html( $cpic_tpl['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" class="cpic-cat-priority" data-term="<?php echo esc_attr( (int) $cpic_cat->term_id ); ?>" min="1" value="<?php echo esc_attr( $cpic_prio ); ?>" />
                            </td>
                            <td>
                                <button type="button" class="button button-small cpic-cat-save" data-term="<?php echo esc_attr( (int) $cpic_cat->term_id ); ?>"><?php esc_html_e( 'Save Row', 'custom-post-image-creator' ); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p style="margin-top:16px;">
            <button type="button" id="cpic-cat-save-all" class="button button-primary button-hero"><?php esc_html_e( 'Save All Mappings', 'custom-post-image-creator' ); ?></button>
            <span id="cpic-cat-status"></span>
        </p>
    </div>
</div>