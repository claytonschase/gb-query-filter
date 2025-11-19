<?php
namespace GBQF;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blocks {

    public function __construct() {
        add_action( 'init', [ $this, 'register_blocks' ] );
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
    }

    public function register_blocks() {

        wp_register_script(
            'gbqf-frontend',
            GBQF_PLUGIN_URL . 'assets/js/gbqf-frontend.js',
            [],
            GBQF_VERSION,
            true
        );

        wp_register_style(
            'gbqf-frontend',
            GBQF_PLUGIN_URL . 'assets/css/gbqf-frontend.css',
            [],
            GBQF_VERSION
        );

        wp_register_style(
            'gbqf-editor',
            GBQF_PLUGIN_URL . 'assets/css/gbqf-editor.css',
            [],
            GBQF_VERSION
        );

        wp_register_style(
            'gbqf-editor',
            GBQF_PLUGIN_URL . 'assets/css/gbqf-editor.css',
            [],
            GBQF_VERSION
        );

        wp_register_script(
            'gbqf-query-filter-block',
            GBQF_PLUGIN_URL . 'assets/js/gbqf-query-filter-block.js',
            [ 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n' ],
            GBQF_VERSION,
            true
        );

        // Provide public taxonomies (excluding category/post_tag) to the editor.
        $tax_objects = get_taxonomies(
            [
                'public'   => true,
                '_builtin' => false,
            ],
            'objects'
        );
        // Also include public built-in taxonomies, then filter out category/post_tag.
        $tax_objects = array_merge( get_taxonomies( [ 'public' => true, '_builtin' => true ], 'objects' ), $tax_objects );

        $taxonomies_for_editor = [];
        foreach ( $tax_objects as $slug => $tax_obj ) {
            if ( in_array( $slug, [ 'category', 'post_tag' ], true ) ) {
                continue;
            }
            $label = isset( $tax_obj->labels->name ) ? $tax_obj->labels->name : $slug;
            $taxonomies_for_editor[] = [
                'slug'  => $slug,
                'label' => $label,
            ];
        }

        wp_add_inline_script(
            'gbqf-query-filter-block',
            'window.GBQF_TAXONOMIES = ' . wp_json_encode( $taxonomies_for_editor ) . ';',
            'before'
        );

        register_block_type(
            'gbqf/query-filter',
            [
                'editor_script'   => 'gbqf-query-filter-block',
                'style'           => 'gbqf-frontend',
                'editor_style'    => [ 'gbqf-frontend', 'gbqf-editor' ],
                'render_callback' => [ $this, 'render_query_filter_block' ],
                'attributes'      => [
                    'targetId'            => [ 'type' => 'string',  'default' => '' ],
                    'enableSearch'        => [ 'type' => 'boolean', 'default' => true ],
                    'enableCategories'    => [ 'type' => 'boolean', 'default' => false ],
                    'enableTags'          => [ 'type' => 'boolean', 'default' => false ],
                    'categoriesControlType' => [ 'type' => 'string',  'default' => 'checkboxes' ],
                    'tagsControlType'       => [ 'type' => 'string',  'default' => 'checkboxes' ],
                    'extraTaxonomiesControlType' => [ 'type' => 'string', 'default' => 'checkboxes' ],
                    'enableAjax'          => [ 'type' => 'boolean', 'default' => true ],
                    'enableExtraTaxonomies' => [ 'type' => 'boolean', 'default' => false ],
                    'enableApplyButton'   => [ 'type' => 'boolean', 'default' => true ],
                    'extraTaxonomies'     => [ 'type' => 'string',  'default' => '' ],
                    'enableMetaBoxFilter' => [ 'type' => 'boolean', 'default' => false ],
                    // One or more Meta Box field IDs, comma-separated.
                    'metaBoxFieldId'      => [ 'type' => 'string',  'default' => '' ],
                    // Repeater-style Meta Box fields with control overrides.
                    'metaBoxFields'       => [ 'type' => 'array',   'default' => [] ],
                ],
            ]
        );

        $meta_box_enabled = Settings::is_metabox_enabled();

        wp_add_inline_script(
            'gbqf-query-filter-block',
            'window.GBQF_ENABLE_METABOX = ' . ( $meta_box_enabled ? 'true' : 'false' ) . ';',
            'before'
        );
    }

    /**
     * Enqueue editor-only assets.
     */
    public function enqueue_editor_assets() {
        wp_enqueue_style( 'gbqf-editor' );

        // Provide Meta Box fields to the editor, if Meta Box is active.
        $mb_fields_for_editor = [];
        $meta_box_enabled     = Settings::is_metabox_enabled();

        if ( $meta_box_enabled && function_exists( 'rwmb_get_registry' ) ) {
            $registry = rwmb_get_registry( 'meta_box' );
            if ( $registry && method_exists( $registry, 'all' ) ) {
                $meta_boxes = $registry->all();
                if ( is_array( $meta_boxes ) ) {
                    foreach ( $meta_boxes as $box ) {
                        $box_fields = [];
                        if ( is_object( $box ) && ! empty( $box->fields ) && is_array( $box->fields ) ) {
                            $box_fields = $box->fields;
                        } elseif ( is_array( $box ) && ! empty( $box['fields'] ) && is_array( $box['fields'] ) ) {
                            $box_fields = $box['fields'];
                        }

                        if ( empty( $box_fields ) ) {
                            continue;
                        }

                        foreach ( $box_fields as $field ) {
                            if ( empty( $field['id'] ) ) {
                                continue;
                            }
                            $id   = (string) $field['id'];
                            $name = ! empty( $field['name'] ) ? $field['name'] : $id;
                            $type = isset( $field['type'] ) ? $field['type'] : '';
                            $mb_fields_for_editor[] = [
                                'id'   => $id,
                                'name' => $name,
                                'type' => $type,
                            ];
                        }
                    }
                }
            }

            // Fallback: use field registry directly if meta-box registry returned nothing.
            if ( empty( $mb_fields_for_editor ) ) {
                $field_registry = rwmb_get_registry( 'field' );
                if ( $field_registry && is_object( $field_registry ) ) {
                    $fields = [];
                    if ( method_exists( $field_registry, 'all' ) ) {
                        $fields = $field_registry->all();
                    } elseif ( method_exists( $field_registry, 'get_all' ) ) {
                        $fields = $field_registry->get_all();
                    }

                    if ( is_array( $fields ) ) {
                        foreach ( $fields as $field_id => $field ) {
                            $id   = '';
                            $name = '';
                            $type = '';

                            if ( is_array( $field ) ) {
                                $id   = $field_id;
                                $name = ! empty( $field['name'] ) ? $field['name'] : $id;
                                $type = isset( $field['type'] ) ? $field['type'] : '';
                            } elseif ( is_object( $field ) ) {
                                $id   = isset( $field->id ) ? $field->id : $field_id;
                                $name = ! empty( $field->name ) ? $field->name : $id;
                                $type = isset( $field->type ) ? $field->type : '';
                            }

                            if ( '' === $id ) {
                                continue;
                            }

                            $mb_fields_for_editor[] = [
                                'id'   => $id,
                                'name' => $name,
                                'type' => $type,
                            ];
                        }
                    }
                }
            }
        }

        wp_add_inline_script(
            'gbqf-query-filter-block',
            'window.GBQF_META_FIELDS = ' . wp_json_encode( $mb_fields_for_editor ) . ';',
            'before'
        );
    }

    public function render_query_filter_block( $attributes, $content ) {

        $target_id          = isset( $attributes['targetId'] ) ? sanitize_key( $attributes['targetId'] ) : '';
        $enable_search      = array_key_exists( 'enableSearch', $attributes ) ? (bool) $attributes['enableSearch'] : true;
        $enable_categories  = array_key_exists( 'enableCategories', $attributes ) ? (bool) $attributes['enableCategories'] : true;
        $enable_tags        = array_key_exists( 'enableTags', $attributes ) ? (bool) $attributes['enableTags'] : true;
        $cats_control_type  = isset( $attributes['categoriesControlType'] ) ? $attributes['categoriesControlType'] : 'checkboxes';
        $tags_control_type  = isset( $attributes['tagsControlType'] ) ? $attributes['tagsControlType'] : 'checkboxes';
        $extra_control_type = isset( $attributes['extraTaxonomiesControlType'] ) ? $attributes['extraTaxonomiesControlType'] : 'checkboxes';
        $enable_ajax        = array_key_exists( 'enableAjax', $attributes ) ? (bool) $attributes['enableAjax'] : true;
        $enable_extra_tax   = array_key_exists( 'enableExtraTaxonomies', $attributes ) ? (bool) $attributes['enableExtraTaxonomies'] : false;
        $enable_apply       = array_key_exists( 'enableApplyButton', $attributes ) ? (bool) $attributes['enableApplyButton'] : true;
        $extra_taxonomies   = isset( $attributes['extraTaxonomies'] ) ? (string) $attributes['extraTaxonomies'] : '';
        $enable_mb_filter   = array_key_exists( 'enableMetaBoxFilter', $attributes ) ? (bool) $attributes['enableMetaBoxFilter'] : false;
        $mb_field_ids_raw   = isset( $attributes['metaBoxFieldId'] ) ? (string) $attributes['metaBoxFieldId'] : '';
        $mb_fields_attr     = ( isset( $attributes['metaBoxFields'] ) && is_array( $attributes['metaBoxFields'] ) ) ? $attributes['metaBoxFields'] : [];

        $auto_apply = ! $enable_apply;

        wp_enqueue_script( 'gbqf-frontend' );
        wp_enqueue_style( 'gbqf-frontend' );

        $wrapper = [
            'class' => 'gbqf-query-filter-block',
        ];

        if ( $target_id ) {
            $wrapper['data-gbqf-target-id'] = esc_attr( $target_id );
        }

        $wrapper['data-gbqf-auto-apply'] = $auto_apply ? '1' : '0';
        $wrapper['data-gbqf-enable-ajax'] = $enable_ajax ? '1' : '0';

        $attr_pairs = [];
        foreach ( $wrapper as $k => $v ) {
            $attr_pairs[] = sprintf( '%s="%s"', esc_attr( $k ), esc_attr( $v ) );
        }

        $html = '<div ' . implode( ' ', $attr_pairs ) . '>';

        // -----------------------
        // CURRENT FILTER VALUES
        // -----------------------
        $current_search = '';
        if ( isset( $_GET['gbqf_search'] ) ) {
            $current_search = sanitize_text_field( wp_unslash( $_GET['gbqf_search'] ) );
        }

        $selected_cats = [];
        if ( isset( $_GET['gbqf_cat'] ) ) {
            $raw = wp_unslash( $_GET['gbqf_cat'] );
            if ( ! is_array( $raw ) ) {
                $raw = [ $raw ];
            }
            $selected_cats = array_map( 'absint', $raw );
            $selected_cats = array_filter( $selected_cats );
        }

        $selected_tags = [];
        if ( isset( $_GET['gbqf_tag'] ) ) {
            $raw = wp_unslash( $_GET['gbqf_tag'] );
            if ( ! is_array( $raw ) ) {
                $raw = [ $raw ];
            }
            $selected_tags = array_map( 'absint', $raw );
            $selected_tags = array_filter( $selected_tags );
        }

        // Extra tax selections: gbqf_tax[tax_slug][] = term_id
        $selected_extra = [];
        if ( isset( $_GET['gbqf_tax'] ) && is_array( $_GET['gbqf_tax'] ) ) {
            $raw_tax = wp_unslash( $_GET['gbqf_tax'] );
            foreach ( $raw_tax as $tax_slug => $terms ) {
                $tax_slug = sanitize_key( $tax_slug );
                if ( ! is_array( $terms ) ) {
                    $terms = [ $terms ];
                }
                $ids = array_map( 'absint', $terms );
                $ids = array_filter( $ids );
                if ( ! empty( $ids ) ) {
                    $selected_extra[ $tax_slug ] = array_values( $ids );
                }
            }
        }

        // -----------------------
        // META BOX FIELD IDS & VALUES (multiple)
        // -----------------------
        // Effective fields: prefer metaBoxFields (with controlType), else fallback to metaBoxFieldId CSV (auto control).
        $effective_mb_fields = [];

        if ( $enable_mb_filter && ! empty( $mb_fields_attr ) ) {
            foreach ( $mb_fields_attr as $entry ) {
                if ( empty( $entry['id'] ) ) {
                    continue;
                }
                $effective_mb_fields[] = [
                    'id'           => (string) $entry['id'],
                    'controlType'  => isset( $entry['controlType'] ) ? (string) $entry['controlType'] : 'auto',
                ];
            }
        }

        if ( $enable_mb_filter && empty( $effective_mb_fields ) && '' !== trim( $mb_field_ids_raw ) ) {
            $parts = explode( ',', $mb_field_ids_raw );
            foreach ( $parts as $field_id ) {
                $field_id = trim( $field_id );
                if ( '' === $field_id ) {
                    continue;
                }
                $effective_mb_fields[] = [
                    'id'          => $field_id,
                    'controlType' => 'auto',
                ];
            }
        }

        // Maintain legacy CSV for consistency.
        $mb_field_ids = [];
        foreach ( $effective_mb_fields as $entry ) {
            if ( ! in_array( $entry['id'], $mb_field_ids, true ) ) {
                $mb_field_ids[] = $entry['id'];
            }
        }

        // Selected values for each Meta Box field.
        $selected_mb_values = [];
        if ( ! empty( $mb_field_ids ) && isset( $_GET['gbqf_meta'] ) && is_array( $_GET['gbqf_meta'] ) ) {
            $raw_meta = wp_unslash( $_GET['gbqf_meta'] );
            foreach ( $mb_field_ids as $field_id ) {
                if ( isset( $raw_meta[ $field_id ] ) ) {
                    $selected_mb_values[ $field_id ] = sanitize_text_field(
                        is_array( $raw_meta[ $field_id ] )
                            ? reset( $raw_meta[ $field_id ] )
                            : $raw_meta[ $field_id ]
                    );
                }
            }
        }

        // -----------------------
        // RESET URL / ACTIVE?
        // -----------------------
        $current_url = '';
        if ( isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ) ) {
            $current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        }

        $reset_url = $current_url ? remove_query_arg(
            [ 'gbqf_search', 'gbqf_cat', 'gbqf_tag', 'gbqf_meta', 'gbqf_tax' ],
            $current_url
        ) : '';

        $has_mb_filter_value = false;
        foreach ( $selected_mb_values as $val ) {
            if ( '' !== $val ) {
                $has_mb_filter_value = true;
                break;
            }
        }

        $has_filters =
            ( '' !== $current_search ) ||
            ! empty( $selected_cats ) ||
            ! empty( $selected_tags ) ||
            ! empty( $selected_extra ) ||
            $has_mb_filter_value;

        // -----------------------
        // TAXONOMY TERMS
        // -----------------------
        $categories = [];
        if ( $enable_categories ) {
            $categories = get_terms(
                [
                    'taxonomy'   => 'category',
                    'hide_empty' => true,
                ]
            );
            if ( is_wp_error( $categories ) ) {
                $categories = [];
            }
        }

        $tags = [];
        if ( $enable_tags ) {
            $tags = get_terms(
                [
                    'taxonomy'   => 'post_tag',
                    'hide_empty' => true,
                ]
            );
            if ( is_wp_error( $tags ) ) {
                $tags = [];
            }
        }

        // Parse extra taxonomies string into valid slugs.
        $extra_tax_slugs = [];
        if ( $enable_extra_tax && '' !== trim( $extra_taxonomies ) ) {
            $parts = explode( ',', $extra_taxonomies );
            foreach ( $parts as $slug ) {
                $slug = trim( strtolower( $slug ) );
                if ( '' === $slug ) {
                    continue;
                }
                if ( in_array( $slug, [ 'category', 'post_tag' ], true ) ) {
                    continue;
                }
                if ( ! taxonomy_exists( $slug ) ) {
                    continue;
                }
                if ( ! in_array( $slug, $extra_tax_slugs, true ) ) {
                    $extra_tax_slugs[] = $slug;
                }
            }
        }

        // Preload extra taxonomy objects and terms.
        $extra_taxonomies_data = [];
        foreach ( $extra_tax_slugs as $slug ) {
            $tax_obj = get_taxonomy( $slug );
            if ( ! $tax_obj ) {
                continue;
            }
            $terms = get_terms(
                [
                    'taxonomy'   => $slug,
                    'hide_empty' => true,
                ]
            );
            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            $extra_taxonomies_data[ $slug ] = [
                'object' => $tax_obj,
                'terms'  => $terms,
            ];
        }

// -----------------------
// LOAD META BOX FIELD DEFINITIONS (registry + fallback)
// -----------------------
        $mb_fields_data = []; // field_id => [ 'label', 'has_options', 'options', 'selected', 'control_type' ]

        if ( $enable_mb_filter && ! empty( $effective_mb_fields ) ) {

            foreach ( $effective_mb_fields as $entry ) {
                $field_id    = $entry['id'];
                $control_sel = isset( $entry['controlType'] ) ? $entry['controlType'] : 'auto';
                $label       = $field_id;
                $has_options = false;
                $options     = [];
                $field       = null;

        // 1) Try Meta Box field registry first (your Meta Box expects at least 2 args).
        if ( function_exists( 'rwmb_get_registry' ) ) {
            $registry = \rwmb_get_registry( 'field' );
            if ( $registry && is_object( $registry ) && method_exists( $registry, 'get' ) ) {
                // Most common object type is "post". If your fields are on terms/users/etc.,
                // this can be adjusted later.
                try {
                    $field = $registry->get( $field_id, 'post' );
                } catch ( \Throwable $e ) {
                    // Silently fail over to rwmb_get_field_settings().
                    $field = null;
                }
            }
        }

        // 2) Fallback to rwmb_get_field_settings if registry gave us nothing useful.
        if ( ! is_array( $field ) && function_exists( 'rwmb_get_field_settings' ) ) {
            $field = \rwmb_get_field_settings( $field_id, [], null );
        }

        if ( is_array( $field ) ) {
            if ( ! empty( $field['name'] ) ) {
                $label = $field['name'];
            }
            if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
                $has_options = true;
                $options     = $field['options'];
            }
        }

        $selected = isset( $selected_mb_values[ $field_id ] )
            ? (string) $selected_mb_values[ $field_id ]
            : '';

                $mb_fields_data[ $field_id ] = [
                    'label'        => $label,
                    'has_options'  => $has_options,
                    'options'      => $options,
                    'selected'     => $selected,
                    'control_type' => $control_sel,
                ];
            }
        }

        // -----------------------
        // FORM START
        // -----------------------
        $html .= '<form class="gbqf-filter-form" method="get">';

        // SEARCH FIELD
        if ( $enable_search ) {
            $html .= '<div class="gbqf-filter-field gbqf-filter-search">';
            // Label visually hidden, placeholder provides prompt.
            $html .= '<label for="gbqf_search_input" class="screen-reader-text">' . esc_html__( 'Search', 'gb-query-filters' ) . '</label>';
            $html .= '<input type="text" id="gbqf_search_input" name="gbqf_search" value="' . esc_attr( $current_search ) . '" placeholder="' . esc_attr__( 'Search', 'gb-query-filters' ) . '" />';
            $html .= '</div>';
        }

        // CATEGORIES FIELD
        if ( $enable_categories && ! empty( $categories ) ) {
            $html .= '<div class="gbqf-filter-field gbqf-filter-categories">';
            $html .= '<span class="gbqf-filter-label">' . esc_html__( 'Categories', 'gb-query-filters' ) . '</span>';

            if ( 'select' === $cats_control_type ) {
                $html .= '<select name="gbqf_cat[]" aria-label="' . esc_attr__( 'Categories', 'gb-query-filters' ) . '">';
                $html .= '<option value="">' . esc_html__( 'Any', 'gb-query-filters' ) . '</option>';
                foreach ( $categories as $cat ) {
                    $cat_id  = (int) $cat->term_id;
                    $selected = in_array( $cat_id, $selected_cats, true ) ? 'selected' : '';
                    $html .= '<option value="' . esc_attr( $cat_id ) . '" ' . $selected . '>' . esc_html( $cat->name ) . '</option>';
                }
                $html .= '</select>';
            } else {
                $html .= '<div class="gbqf-filter-options">';
                foreach ( $categories as $cat ) {
                    $cat_id   = (int) $cat->term_id;
                    $checked  = in_array( $cat_id, $selected_cats, true );
                    $field_id = 'gbqf_cat_' . $cat_id;

                    $html .= '<label for="' . esc_attr( $field_id ) . '" class="gbqf-filter-option">';
                    $html .= '<input type="checkbox" id="' . esc_attr( $field_id ) . '" name="gbqf_cat[]" value="' . esc_attr( $cat_id ) . '" ' . checked( $checked, true, false ) . ' />';
                    $html .= '<span>' . esc_html( $cat->name ) . '</span>';
                    $html .= '</label>';
                }
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        // TAGS FIELD
        if ( $enable_tags && ! empty( $tags ) ) {
            $html .= '<div class="gbqf-filter-field gbqf-filter-tags">';
            $html .= '<span class="gbqf-filter-label">' . esc_html__( 'Tags', 'gb-query-filters' ) . '</span>';

            if ( 'select' === $tags_control_type ) {
                $html .= '<select name="gbqf_tag[]" aria-label="' . esc_attr__( 'Tags', 'gb-query-filters' ) . '">';
                $html .= '<option value="">' . esc_html__( 'Any', 'gb-query-filters' ) . '</option>';
                foreach ( $tags as $tag ) {
                    $tag_id  = (int) $tag->term_id;
                    $selected = in_array( $tag_id, $selected_tags, true ) ? 'selected' : '';
                    $html .= '<option value="' . esc_attr( $tag_id ) . '" ' . $selected . '>' . esc_html( $tag->name ) . '</option>';
                }
                $html .= '</select>';
            } else {
                $html .= '<div class="gbqf-filter-options">';
                foreach ( $tags as $tag ) {
                    $tag_id   = (int) $tag->term_id;
                    $checked  = in_array( $tag_id, $selected_tags, true );
                    $field_id = 'gbqf_tag_' . $tag_id;

                    $html .= '<label for="' . esc_attr( $field_id ) . '" class="gbqf-filter-option">';
                    $html .= '<input type="checkbox" id="' . esc_attr( $field_id ) . '" name="gbqf_tag[]" value="' . esc_attr( $tag_id ) . '" ' . checked( $checked, true, false ) . ' />';
                    $html .= '<span>' . esc_html( $tag->name ) . '</span>';
                    $html .= '</label>';
                }
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        // EXTRA TAXONOMIES
        if ( ! empty( $extra_taxonomies_data ) ) {
            foreach ( $extra_taxonomies_data as $slug => $data ) {
                $tax_obj = $data['object'];
                $terms   = $data['terms'];

                $label = $tax_obj->labels->name ?? $slug;

                $html .= '<div class="gbqf-filter-field gbqf-filter-tax gbqf-filter-tax-' . esc_attr( $slug ) . '">';
                $html .= '<span class="gbqf-filter-label">' . esc_html( $label ) . '</span>';

                $selected_for_tax = isset( $selected_extra[ $slug ] ) ? (array) $selected_extra[ $slug ] : [];

                if ( 'select' === $extra_control_type ) {
                    $html .= '<select name="gbqf_tax[' . esc_attr( $slug ) . '][]" aria-label="' . esc_attr( $label ) . '">';
                    $html .= '<option value="">' . esc_html__( 'Any', 'gb-query-filters' ) . '</option>';
                    foreach ( $terms as $term ) {
                        $term_id  = (int) $term->term_id;
                        $selected = in_array( $term_id, $selected_for_tax, true ) ? 'selected' : '';
                        $html .= '<option value="' . esc_attr( $term_id ) . '" ' . $selected . '>' . esc_html( $term->name ) . '</option>';
                    }
                    $html .= '</select>';
                } else {
                    $html .= '<div class="gbqf-filter-options">';

                    foreach ( $terms as $term ) {
                        $term_id  = (int) $term->term_id;
                        $checked  = in_array( $term_id, $selected_for_tax, true );
                        $field_id = 'gbqf_tax_' . $slug . '_' . $term_id;

                        $html .= '<label for="' . esc_attr( $field_id ) . '" class="gbqf-filter-option">';
                        $html .= '<input type="checkbox" id="' . esc_attr( $field_id ) . '" name="gbqf_tax[' . esc_attr( $slug ) . '][]" value="' . esc_attr( $term_id ) . '" ' . checked( $checked, true, false ) . ' />';
                        $html .= '<span>' . esc_html( $term->name ) . '</span>';
                        $html .= '</label>';
                    }

                    $html .= '</div>';
                }

                $html .= '</div>';
            }
        }

        // META BOX FIELDS – ONE UI GROUP PER FIELD
        if ( $enable_mb_filter && ! empty( $mb_fields_data ) ) {
            foreach ( $mb_fields_data as $field_id => $field_data ) {
                $label       = $field_data['label'];
                $has_options = $field_data['has_options'];
                $options     = $field_data['options'];
                $selected    = $field_data['selected'];

                $html .= '<div class="gbqf-filter-field gbqf-filter-metabox gbqf-filter-metabox-' . esc_attr( $field_id ) . '">';
                $html .= '<label for="gbqf_mb_' . esc_attr( $field_id ) . '">';
                $html .= esc_html( $label );
                $html .= '</label>';

                if ( $has_options && ! empty( $options ) ) {
                    $control_type = ! empty( $field_data['control_type'] ) ? $field_data['control_type'] : 'auto';

                    if ( 'radio' === $control_type ) {
                        $html .= '<div class="gbqf-filter-options">';
                        $html .= '<label class="gbqf-filter-option">';
                        $html .= '<input type="radio" name="gbqf_meta[' . esc_attr( $field_id ) . ']" value="" ' . checked( $selected, '', false ) . ' />';
                        $html .= '<span>' . esc_html__( 'Any', 'gb-query-filters' ) . '</span>';
                        $html .= '</label>';

                        foreach ( $options as $opt_value => $opt_label ) {
                            $opt_value = (string) $opt_value;
                            $opt_label = is_array( $opt_label ) && isset( $opt_label['label'] ) ? $opt_label['label'] : $opt_label;
                            $html     .= '<label class="gbqf-filter-option">';
                            $html     .= '<input type="radio" name="gbqf_meta[' . esc_attr( $field_id ) . ']" value="' . esc_attr( $opt_value ) . '" ' . checked( $selected, $opt_value, false ) . ' />';
                            $html     .= '<span>' . esc_html( $opt_label ) . '</span>';
                            $html     .= '</label>';
                        }

                        $html .= '</div>';
                    } elseif ( 'text' === $control_type ) {
                        $html .= '<input type="text" id="gbqf_mb_' . esc_attr( $field_id ) . '" name="gbqf_meta[' . esc_attr( $field_id ) . ']" value="' . esc_attr( $selected ) . '" />';
                    } else {
                        $html .= '<select id="gbqf_mb_' . esc_attr( $field_id ) . '" name="gbqf_meta[' . esc_attr( $field_id ) . ']">';
                        $html .= '<option value="">' . esc_html__( 'Any', 'gb-query-filters' ) . '</option>';

                        foreach ( $options as $opt_value => $opt_label ) {
                            $opt_value      = (string) $opt_value;
                            $opt_label      = is_array( $opt_label ) && isset( $opt_label['label'] ) ? $opt_label['label'] : $opt_label;
                            $selected_attr  = selected( $selected, $opt_value, false );
                            $html          .= '<option value="' . esc_attr( $opt_value ) . '" ' . $selected_attr . '>' . esc_html( $opt_label ) . '</option>';
                        }

                        $html .= '</select>';
                    }
                } else {
                    // Fallback: plain text input for this meta key.
                    $html .= '<input type="text" id="gbqf_mb_' . esc_attr( $field_id ) . '" name="gbqf_meta[' . esc_attr( $field_id ) . ']" value="' . esc_attr( $selected ) . '" />';
                }

                $html .= '</div>';
            }
        }

        // ACTIONS
        $html .= '<div class="gbqf-filter-actions">';

        if ( $enable_apply ) {
            $html .= '<button type="submit">' . esc_html__( 'Apply', 'gb-query-filters' ) . '</button>';
        }

        if ( $reset_url ) {
            $reset_class = 'gbqf-filter-reset';
            if ( ! $has_filters ) {
                $reset_class .= ' is-hidden';
            }
            $html .= ' <a class="' . esc_attr( $reset_class ) . '" href="' . esc_url( $reset_url ) . '">';
            $html .= esc_html__( 'Reset', 'gb-query-filters' );
            $html .= '</a>';
        }

        $html .= '</div>'; // .gbqf-filter-actions

        $html .= '</div>';

        return $html;
    }
}
