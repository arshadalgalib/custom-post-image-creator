<?php
/**
 * Category mapper – manages category → template mappings with explicit
 * priority and resolves the template for a post deterministically.
 *
 * Rule: when a post has multiple mapped categories, the mapping with the
 * lowest priority number (highest priority) wins.
 *
 * @package CustomPostImageCreator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPIC_Category_Mapper {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cpic_category_mappings';
    }

    /* ─── Read ─────────────────────────────────────────── */

    /**
     * Get all mappings.
     *
     * @return array
     */
    public function get_mappings() {
        global $wpdb;
        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY priority ASC, id ASC", ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Get a single mapping for a term.
     *
     * @param int $term_id Term ID.
     * @return array|null
     */
    public function get_mapping( $term_id ) {
        global $wpdb;
        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE term_id = %d", (int) $term_id ), ARRAY_A );
        return $row ? $row : null;
    }

    /**
     * Resolve the template for a post.
     *
     * Deterministic priority: among the post's mapped categories, the one
     * with the lowest priority value wins. Falls back to the default template.
     *
     * @param int $post_id Post ID.
     * @return array|null Template record (with config) or null.
     */
    public function resolve_template_for_post( $post_id ) {
        $mapping = $this->get_active_mapping_for_post( $post_id );
        if ( $mapping ) {
            $template = CPIC_Template_Manager::get_template( (int) $mapping['template_id'] );
            if ( $template && ! empty( $template['is_active'] ) ) {
                return $template;
            }
        }
        return CPIC_Template_Manager::get_default_template();
    }

    /** Return the highest-priority mapping whose template is currently active. */
    private function get_active_mapping_for_post( $post_id ) {
        $categories = wp_get_post_categories( $post_id );
        if ( empty( $categories ) || is_wp_error( $categories ) ) {
            return null;
        }
        $mappings = array();
        foreach ( $categories as $term_id ) {
            $mapping = $this->get_mapping( $term_id );
            if ( $mapping ) { $mappings[] = $mapping; }
        }
        usort( $mappings, function ( $left, $right ) {
            $priority_cmp = (int) $left['priority'] <=> (int) $right['priority'];
            return 0 !== $priority_cmp ? $priority_cmp : ( (int) $left['term_id'] <=> (int) $right['term_id'] );
        } );
        foreach ( $mappings as $mapping ) {
            $template = CPIC_Template_Manager::get_template( (int) $mapping['template_id'] );
            if ( $template && ! empty( $template['is_active'] ) ) {
                return $mapping;
            }
        }
        return null;
    }

    /**
     * The category label (first mapped category name) used on the photocard.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    public function resolve_category_label( $post_id ) {
        $categories = wp_get_post_categories( $post_id );
        if ( empty( $categories ) || is_wp_error( $categories ) ) {
            return '';
        }

        // Prefer the category that would have been used for the template.
        $selected = $this->resolve_selected_term_id( $post_id );
        if ( $selected ) {
            $term = get_term( $selected, 'category' );
            if ( $term && ! is_wp_error( $term ) ) {
                return $term->name;
            }
        }

        $first = get_term( $categories[0], 'category' );
        return ( $first && ! is_wp_error( $first ) ) ? $first->name : '';
    }

    /**
     * Return the term id chosen by priority rules (mapped category with the
     * highest priority). Returns 0 if no mapped category exists.
     *
     * @param int $post_id Post ID.
     * @return int
     */
    public function resolve_selected_term_id( $post_id ) {
        $mapping = $this->get_active_mapping_for_post( $post_id );
        return $mapping ? (int) $mapping['term_id'] : 0;
    }

    /* ─── Write ────────────────────────────────────────── */

    /**
     * Set (upsert) a mapping for a term.
     *
     * @param int $term_id      Term ID.
     * @param int $template_id  Template ID.
     * @param int $priority     Priority (lower = higher priority).
     * @return bool
     */
    public function set_mapping( $term_id, $template_id, $priority ) {
        global $wpdb;
        $table = self::table();

        $term_id     = (int) $term_id;
        $template_id = (int) $template_id;
        $priority    = max( 1, (int) $priority );

        $template = CPIC_Template_Manager::get_template( $template_id );
        $term = get_term( $term_id, 'category' );
        if ( ! $term_id || ! $template_id || ! $template || empty( $template['is_active'] ) || ! $term || is_wp_error( $term ) ) {
            return false;
        }

        $existing = $this->get_mapping( $term_id );

        if ( $existing ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $result = $wpdb->update(
                $table,
                array( 'template_id' => $template_id, 'priority' => $priority ),
                array( 'term_id' => $term_id ),
                array( '%d', '%d' ),
                array( '%d' )
            );
            return false !== $result;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->insert(
            $table,
            array(
                'term_id'     => $term_id,
                'template_id' => $template_id,
                'priority'    => $priority,
                'created_at'  => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%d', '%s' )
        );

        return false !== $result;
    }

    /**
     * Remove a mapping for a term.
     *
     * @param int $term_id Term ID.
     * @return bool
     */
    public function remove_mapping( $term_id ) {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->delete( $table, array( 'term_id' => (int) $term_id ), array( '%d' ) );
    }

    /**
     * Remove all mappings pointing to a template (used when deleting a template).
     *
     * @param int $template_id Template ID.
     * @return bool
     */
    public function remove_mappings_for_template( $template_id ) {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->delete( $table, array( 'template_id' => (int) $template_id ), array( '%d' ) );
    }

    /**
     * Map a template to multiple categories at once.
     *
     * @param array $term_ids    List of term ids.
     * @param int   $template_id Template id.
     * @param int   $start       Starting priority.
     * @return int Number of mappings written.
     */
    public function set_many( $term_ids, $template_id, $start = 1 ) {
        $count = 0;
        $i     = $start;
        if ( is_array( $term_ids ) ) {
            foreach ( $term_ids as $term_id ) {
                if ( $this->set_mapping( $term_id, $template_id, $i ) ) {
                    $count++;
                }
                $i++;
            }
        }
        return $count;
    }

    /**
     * List of WordPress categories that currently have a mapping.
     *
     * @return array term_id => mapping row
     */
    public function get_mapped_terms() {
        $mapped = array();
        foreach ( $this->get_mappings() as $m ) {
            $mapped[ (int) $m['term_id'] ] = $m;
        }
        return $mapped;
    }
}