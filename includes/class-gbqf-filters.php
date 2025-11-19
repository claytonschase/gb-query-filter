<?php
namespace GBQF;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles applying URL-based filters to GenerateBlocks queries.
 */
class Filters {

    /**
     * Whether Meta Box integration is enabled from settings.
     *
     * @var bool
     */
    protected $meta_box_enabled;

    public function __construct() {
        $this->meta_box_enabled = Settings::is_metabox_enabled();
        // GenerateBlocks 1.x / original Query Loop filter.
        add_filter( 'generateblocks_query_loop_args', [ $this, 'apply_filters_to_gb_query' ], 10, 2 );

        // GenerateBlocks 2.0+ filter (WP_Query args).
        add_filter( 'generateblocks_query_wp_query_args', [ $this, 'apply_filters_to_gb_query_v2' ], 10, 4 );
    }

    /**
     * Get sanitized search term from our URL parameter.
     *
     * @return string
     */
    protected function get_search_term() {
        if ( isset( $_GET['gbqf_search'] ) && '' !== $_GET['gbqf_search'] ) {
            return sanitize_text_field( wp_unslash( $_GET['gbqf_search'] ) );
        }

        return '';
    }

    /**
     * Get selected category IDs from URL parameter.
     *
     * @return int[] Array of category IDs.
     */
    protected function get_selected_category_ids() {
        if ( ! isset( $_GET['gbqf_cat'] ) ) {
            return [];
        }

        $raw = wp_unslash( $_GET['gbqf_cat'] );

        if ( ! is_array( $raw ) ) {
            $raw = [ $raw ];
        }

        $ids = array_map( 'absint', $raw );
        $ids = array_filter( $ids );

        return array_values( $ids );
    }

    /**
     * Get selected tag IDs from URL parameter.
     *
     * @return int[] Array of tag IDs.
     */
    protected function get_selected_tag_ids() {
        if ( ! isset( $_GET['gbqf_tag'] ) ) {
            return [];
        }

        $raw = wp_unslash( $_GET['gbqf_tag'] );

        if ( ! is_array( $raw ) ) {
            $raw = [ $raw ];
        }

        $ids = array_map( 'absint', $raw );
        $ids = array_filter( $ids );

        return array_values( $ids );
    }

    /**
     * Get extra taxonomy filters from gbqf_tax[taxonomy][].
     *
     * @return array taxonomy_slug => int[] term_ids
     */
    protected function get_extra_tax_filters() {
        if ( ! isset( $_GET['gbqf_tax'] ) || ! is_array( $_GET['gbqf_tax'] ) ) {
            return [];
        }

        $raw = wp_unslash( $_GET['gbqf_tax'] );
        $filters = [];

        foreach ( $raw as $taxonomy => $terms ) {
            $taxonomy = sanitize_key( $taxonomy );

            // We treat category/post_tag with dedicated logic; skip them here.
            if ( in_array( $taxonomy, [ 'category', 'post_tag' ], true ) ) {
                continue;
            }

            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }

            if ( ! is_array( $terms ) ) {
                $terms = [ $terms ];
            }

            $term_ids = array_map( 'absint', $terms );
            $term_ids = array_filter( $term_ids );

            if ( empty( $term_ids ) ) {
                continue;
            }

            $filters[ $taxonomy ] = array_values( $term_ids );
        }

        return $filters;
    }

    /**
     * Get meta filters from URL.
     *
     * Format: gbqf_meta[meta_key]=value
     *
     * @return array[] Array of [ 'key' => string, 'value' => string ].
     */
    protected function get_meta_filters() {
        if ( ! $this->meta_box_enabled ) {
            return [];
        }

        if ( ! isset( $_GET['gbqf_meta'] ) || ! is_array( $_GET['gbqf_meta'] ) ) {
            return [];
        }

        $raw = wp_unslash( $_GET['gbqf_meta'] );
        $filters = [];

        foreach ( $raw as $key => $value ) {
            $key   = sanitize_key( $key );
            $value = is_array( $value ) ? reset( $value ) : $value;
            $value = sanitize_text_field( $value );

            if ( '' === $key || '' === $value ) {
                continue;
            }

            $filters[] = [
                'key'   => $key,
                'value' => $value,
            ];
        }

        return $filters;
    }

    /**
     * Determine if this GB Query Loop should be affected, based on its attributes.
     *
     * We require the block to have the class "gbqf-target" in Additional CSS Class(es).
     *
     * @param array $attributes Block attributes.
     * @return bool
     */
    protected function should_apply_to_attributes( $attributes ) {
        // Apply to all Query Loop blocks (no extra class required).
        return true;
    }

    /**
     * Apply search + taxonomy + meta filters to GenerateBlocks Query Loop args (GB 1.x style).
     *
     * @param array $query_args Existing query args.
     * @param array $attributes Block attributes.
     * @return array
     */
    public function apply_filters_to_gb_query( $query_args, $attributes ) {
        if ( ! $this->should_apply_to_attributes( $attributes ) ) {
            return $query_args;
        }

        $search        = $this->get_search_term();
        $cat_ids       = $this->get_selected_category_ids();
        $tag_ids       = $this->get_selected_tag_ids();
        $extra_tax     = $this->get_extra_tax_filters();
        $meta_filters  = $this->get_meta_filters();

        // Apply search.
        if ( '' !== $search ) {
            $query_args['s'] = $search;
        }

        // Apply categories (if any selected).
        if ( ! empty( $cat_ids ) ) {

            if ( ! empty( $query_args['category__in'] ) ) {
                $existing = (array) $query_args['category__in'];
                $query_args['category__in'] = array_unique(
                    array_merge( $existing, $cat_ids )
                );
            } else {
                $query_args['category__in'] = $cat_ids;
            }
        }

        // Apply tags (if any selected).
        if ( ! empty( $tag_ids ) ) {

            if ( ! empty( $query_args['tag__in'] ) ) {
                $existing = (array) $query_args['tag__in'];
                $query_args['tag__in'] = array_unique(
                    array_merge( $existing, $tag_ids )
                );
            } else {
                $query_args['tag__in'] = $tag_ids;
            }
        }

        // Apply extra taxonomy filters via tax_query.
        if ( ! empty( $extra_tax ) ) {
            $tax_query = [];

            if ( ! empty( $query_args['tax_query'] ) && is_array( $query_args['tax_query'] ) ) {
                $tax_query = $query_args['tax_query'];
            }

            if ( ! empty( $tax_query ) && empty( $tax_query['relation'] ) ) {
                $tax_query['relation'] = 'AND';
            } elseif ( empty( $tax_query ) ) {
                $tax_query['relation'] = 'AND';
            }

            foreach ( $extra_tax as $taxonomy => $term_ids ) {
                $tax_query[] = [
                    'taxonomy' => $taxonomy,
                    'field'    => 'term_id',
                    'terms'    => $term_ids,
                    'operator' => 'IN',
                ];
            }

            $query_args['tax_query'] = $tax_query;
        }

        // Apply meta filters (if any).
        if ( ! empty( $meta_filters ) ) {
            $meta_query = [];

            if ( ! empty( $query_args['meta_query'] ) && is_array( $query_args['meta_query'] ) ) {
                $meta_query = $query_args['meta_query'];
            }

            if ( ! empty( $meta_query ) && empty( $meta_query['relation'] ) ) {
                $meta_query['relation'] = 'AND';
            } elseif ( empty( $meta_query ) ) {
                $meta_query['relation'] = 'AND';
            }

            foreach ( $meta_filters as $filter ) {
                $meta_query[] = [
                    'key'   => $filter['key'],
                    'value' => $filter['value'],
                ];
            }

            $query_args['meta_query'] = $meta_query;
        }

        return $query_args;
    }

    /**
     * Apply filters to GenerateBlocks 2.0+ Query WP_Query args.
     *
     * @param array        $query_args Existing WP_Query args.
     * @param array        $attributes Block attributes.
     * @param array|null   $block      Block data (not needed here).
     * @param int|string   $query_id   GB query id (not needed here).
     * @return array
     */
    public function apply_filters_to_gb_query_v2( $query_args, $attributes, $block, $query_id ) {
        return $this->apply_filters_to_gb_query( $query_args, $attributes );
    }
}
