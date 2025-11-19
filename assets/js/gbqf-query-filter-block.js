( function ( wp ) {
    const { registerBlockType } = wp.blocks;
    const { __ } = wp.i18n;
    const blockEditor = wp.blockEditor || wp.editor;
    const { InspectorControls, useBlockProps } = blockEditor;
    const { PanelBody, TextControl, ToggleControl, FormTokenField, SelectControl, Button, ComboboxControl } = wp.components;
    const ServerSideRender = wp.serverSideRender || ( wp.components && wp.components.ServerSideRender );
    const { Fragment, createElement: el } = wp.element;

    const blockIcon = el(
        'svg',
        {
            viewBox: '0 0 24 24',
            xmlns: 'http://www.w3.org/2000/svg',
            fillRule: 'evenodd',
            clipRule: 'evenodd',
            strokeLinejoin: 'round',
            strokeMiterlimit: '2',
        },
        el( 'path', {
            d: 'M9.484 15.696 8.773 15l-2.552 2.607-1.539-1.452-.698.709L6.234 19l3.25-3.304Zm0-5L8.773 10l-2.552 2.607-1.539-1.452-.698.709L6.234 14l3.25-3.304Zm0-5L8.773 5 6.221 7.607 4.682 6.155l-.698.709L6.234 9l3.25-3.304ZM20 17h-8v1h8v-1Zm0-5h-8v1h8v-1Zm0-5h-8v1h8V7Zm4-5H0v20h24V2Zm-1 19H1V3h22v18Z',
            fill: 'currentColor',
        } )
    );

    const metaBoxIntegrationEnabled =
        typeof window !== 'undefined' && typeof window.GBQF_ENABLE_METABOX !== 'undefined'
            ? !! window.GBQF_ENABLE_METABOX
            : true;

    const settingsUrl =
        typeof window !== 'undefined' && window.GBQF_SETTINGS_URL
            ? window.GBQF_SETTINGS_URL
            : '';

    registerBlockType( 'gbqf/query-filter', {
        title: __( 'GB Query Filter', 'gb-query-filters' ),
        icon: { src: blockIcon },
        category: 'widgets',

        attributes: {
            // Target Query Block ID (Element ID, no "#").
            targetId: {
                type: 'string',
                default: '',
            },

            // UI toggles.
            enableSearch: {
                type: 'boolean',
                default: true,
            },
            enableCategories: {
                type: 'boolean',
                default: false,
            },
            enableTags: {
                type: 'boolean',
                default: false,
            },

            // Control types.
            categoriesControlType: {
                type: 'string',
                default: 'checkboxes',
            },
            tagsControlType: {
                type: 'string',
                default: 'checkboxes',
            },
            extraTaxonomiesControlType: {
                type: 'string',
                default: 'checkboxes',
            },

            enableAjax: {
                type: 'boolean',
                default: false,
            },

            enableExtraTaxonomies: {
                type: 'boolean',
                default: false,
            },

            // Apply button toggle.
            enableApplyButton: {
                type: 'boolean',
                default: false, // default: manual Apply button.
            },

            // Comma-separated list of additional taxonomies (slugs).
            extraTaxonomies: {
                type: 'string',
                default: '',
            },

            // Meta Box integration.
            enableMetaBoxFilter: {
                type: 'boolean',
                default: false,
            },

            // One or more Meta Box field IDs (comma-separated).
            metaBoxFieldId: {
                type: 'string',
                default: '',
            },
            // Repeater-style Meta Box fields with control overrides.
            metaBoxFields: {
                type: 'array',
                default: [],
            },
        },

        edit( props ) {
            const { attributes, setAttributes } = props;
            const {
                targetId,
                enableSearch,
                enableCategories,
                enableTags,
                categoriesControlType,
                tagsControlType,
                extraTaxonomiesControlType,
                enableAjax,
                enableExtraTaxonomies,
                enableApplyButton,
                extraTaxonomies,
                enableMetaBoxFilter,
                metaBoxFieldId,
                metaBoxFields,
            } = attributes;

            if ( ! metaBoxIntegrationEnabled && enableMetaBoxFilter ) {
                setAttributes( { enableMetaBoxFilter: false } );
            }

            const blockProps = useBlockProps ? useBlockProps() : {};
            // Debug: verify localized Meta Box fields.
            /* eslint-disable no-console */
            console.log( 'GBQF_META_FIELDS', window.GBQF_META_FIELDS );
            /* eslint-enable no-console */

            const suggestedTargetId =
                targetId ||
                'gbqf-loop-' + ( props.clientId ? props.clientId.slice( 0, 8 ) : 'auto' );

            const cleanId = ( value ) => value.replace( /^#+/, '' ).trim();

            const onChangeExtraTaxonomies = ( value ) => {
                const cleaned = value
                    .split( ',' )
                    .map( ( v ) => v.trim().toLowerCase() )
                    .filter( Boolean )
                    .join( ', ' );
                setAttributes( { extraTaxonomies: cleaned } );
            };

            const onChangeMetaBoxFieldId = ( value ) => {
                // Allow one or more IDs, separated by commas; keep user casing.
                setAttributes( { metaBoxFieldId: value } );
            };

            const taxonomyOptions = Array.isArray( window.GBQF_TAXONOMIES )
                ? window.GBQF_TAXONOMIES
                : [];

            const taxonomySlugToLabel = {};
            taxonomyOptions.forEach( ( tax ) => {
                taxonomySlugToLabel[ tax.slug ] = tax.label;
            } );

            const extraTaxTokens = () => {
                if ( ! extraTaxonomies ) {
                    return [];
                }
                return extraTaxonomies
                    .split( ',' )
                    .map( ( v ) => v.trim() )
                    .filter( Boolean )
                    .map( ( slug ) => {
                        const label = taxonomySlugToLabel[ slug ] || slug;
                        return `${ label } (${ slug })`;
                    } );
            };

            const onChangeExtraTaxonomiesTokens = ( tokens ) => {
                const slugs = tokens
                    .map( ( token ) => {
                        const match = token.match( /\(([^)]+)\)\s*$/ );
                        if ( match && match[1] ) {
                            return match[1].trim();
                        }
                        return token.trim();
                    } )
                    .filter( Boolean );
                setAttributes( {
                    extraTaxonomies: slugs.join( ', ' ),
                } );
            };

            const metaFieldOptions = Array.isArray( window.GBQF_META_FIELDS )
                ? ( metaBoxIntegrationEnabled ? window.GBQF_META_FIELDS : [] )
                : [];

            const metaFieldIdToLabel = {};
            metaFieldOptions.forEach( ( field ) => {
                metaFieldIdToLabel[ field.id ] = field.name || field.id;
            } );

            // Initialize metaBoxFields from legacy CSV if needed.
            if (
                enableMetaBoxFilter &&
                ( ! metaBoxFields || metaBoxFields.length === 0 ) &&
                metaBoxFieldId &&
                metaBoxFieldId.trim()
            ) {
                const ids = metaBoxFieldId
                    .split( ',' )
                    .map( ( v ) => v.trim() )
                    .filter( Boolean )
                    .map( ( id ) => ( { id, controlType: 'auto' } ) );
                if ( ids.length ) {
                    setAttributes( { metaBoxFields: ids } );
                }
            }

            const syncMetaBoxFieldId = ( fields ) => {
                const csv = fields
                    .map( ( f ) => ( f.id || '' ).trim() )
                    .filter( Boolean )
                    .join( ', ' );
                setAttributes( { metaBoxFieldId: csv, metaBoxFields: fields } );
            };

            const renderMetaFieldRow = ( field, index ) => {
                const fieldLabel = metaFieldIdToLabel[ field.id ] || field.id || '';
                return el(
                    'div',
                    {
                        key: `mb-field-${ index }`,
                        style: {
                            display: 'flex',
                            flexDirection: 'column',
                            gap: '8px',
                            padding: '10px',
                            border: '1px solid #e2e2e2',
                            borderRadius: '4px',
                            marginBottom: '5px',
                        },
                        className: 'gbqf-mb-field-row gbqf-metabox-row',
                    },
                    el(
                        'div',
                        { className: 'gbqf-metabox-field-id' },
                        el( TextControl, {
                            label: __( 'Meta Box field', 'gb-query-filters' ),
                            value: field.id,
                            onChange: ( v ) => {
                                const next = [ ...metaBoxFields ];
                                next[ index ] = { ...field, id: v };
                                syncMetaBoxFieldId( next );
                            },
                            placeholder: __( 'e.g. project_status', 'gb-query-filters' ),
                            help: __( 'Enter the Meta Box field ID', 'gb-query-filters' ),
                        } )
                    ),
                    el(
                        'div',
                        { className: 'gbqf-metabox-control-type' },
                        el( SelectControl, {
                            label: __( 'Control type', 'gb-query-filters' ),
                            value: field.controlType || 'auto',
                            options: [
                                { label: __( 'Auto (based on options)', 'gb-query-filters' ), value: 'auto' },
                                { label: __( 'Select dropdown', 'gb-query-filters' ), value: 'select' },
                                { label: __( 'Radio buttons', 'gb-query-filters' ), value: 'radio' },
                                { label: __( 'Text input', 'gb-query-filters' ), value: 'text' },
                            ],
                            onChange: ( v ) => {
                                const next = [ ...metaBoxFields ];
                                next[ index ] = { ...field, controlType: v };
                                syncMetaBoxFieldId( next );
                            },
                        } )
                    ),
                    el(
                        'div',
                        { className: 'gbqf-metabox-remove' },
                        el(
                            Button,
                            {
                                isDestructive: true,
                                variant: 'secondary',
                                icon: 'trash',
                                label: __( 'Remove field', 'gb-query-filters' ),
                                onClick: () => {
                                    const next = metaBoxFields.filter( ( _, i ) => i !== index );
                                    syncMetaBoxFieldId( next );
                                },
                            },
                            ''
                        )
                    )
                );
            };

            return el(
                Fragment,
                null,

                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: __( 'Link Filter to GB Query', 'gb-query-filters' ), initialOpen: true },
                        el( 'div', { className: 'gbqf-control-group gbqf-control-group--advanced' },
                            el( TextControl, {
                                label: __( 'Target Query Block ID', 'gb-query-filters' ),
                                value: targetId,
                                onChange: ( v ) => setAttributes( { targetId: cleanId( v ) } ),
                                placeholder: __( 'e.g. projects-loop', 'gb-query-filters' ),
                                className: 'gbqf-control-full',
                            } )
                        ),
                        el(
                            'div',
                            { className: 'gbqf-control-group gbqf-control-group--advanced' },
                            el(
                                'p',
                                { className: 'gbqf-control-helper' },
                                __(
                                    'Copy this auto-generated ID and paste it into the GenerateBlocks Query Loop HTML Anchor.',
                                    'gb-query-filters'
                                )
                            ),
                            el(
                                'div',
                                { className: 'gbqf-suggested-id' },
                                el( 'code', null, suggestedTargetId ),
                                el(
                                    Button,
                                    {
                                        variant: 'secondary',
                                        onClick: async () => {
                                            const cleanIdVal = cleanId( suggestedTargetId );
                                            setAttributes( { targetId: cleanIdVal } );
                                            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                                                try {
                                                    await navigator.clipboard.writeText( cleanIdVal );
                                                } catch ( e ) {
                                                    // Ignore clipboard errors.
                                                }
                                            }
                                        },
                                        style: { marginLeft: '10px' },
                                    },
                                    __( 'Copy ID', 'gb-query-filters' )
                                )
                            )
                        )
                    ),

                    el(
                        PanelBody,
                        { title: __( 'Filter Controls', 'gb-query-filters' ), initialOpen: false },
                        el( 'div', { className: 'gbqf-control-group gbqf-control-group--filter' },
                            el( ToggleControl, {
                                label: __( 'Enable Search Filter', 'gb-query-filters' ),
                                checked: enableSearch,
                                onChange: ( checked ) => setAttributes( { enableSearch: checked } ),
                                className: 'gbqf-control-full',
                            } )
                        ),

                        el(
                            'div',
                            { className: 'gbqf-control-group gbqf-control-group--tax' },
                            el( ToggleControl, {
                                label: __( 'Enable Categories Filter', 'gb-query-filters' ),
                                checked: enableCategories,
                                onChange: ( checked ) =>
                                    setAttributes( { enableCategories: checked } ),
                                className: 'gbqf-control-full',
                            } ),
                            enableCategories &&
                                el( 'label', { style: { display: 'block', fontSize: '12px', opacity: 0.8, marginBottom: '4px' } },
                                    __( 'Categories control', 'gb-query-filters' )
                                ),
                            enableCategories &&
                                el( 'select', {
                                    value: categoriesControlType,
                                    onChange: ( e ) =>
                                        setAttributes( { categoriesControlType: e.target.value } ),
                                    className: 'gbqf-control-full',
                                },
                                    el( 'option', { value: 'checkboxes' }, __( 'Checkboxes', 'gb-query-filters' ) ),
                                    el( 'option', { value: 'select' }, __( 'Select dropdown', 'gb-query-filters' ) )
                                )
                        ),

                        el(
                            'div',
                            { className: 'gbqf-control-group gbqf-control-group--tax' },
                            el( ToggleControl, {
                                label: __( 'Enable Tags Filter', 'gb-query-filters' ),
                                checked: enableTags,
                                onChange: ( checked ) =>
                                    setAttributes( { enableTags: checked } ),
                                className: 'gbqf-control-full',
                            } ),
                            enableTags &&
                                el( 'label', { style: { display: 'block', fontSize: '12px', opacity: 0.8, marginBottom: '4px' } },
                                    __( 'Tags control', 'gb-query-filters' )
                                ),
                            enableTags &&
                                el( 'select', {
                                    value: tagsControlType,
                                    onChange: ( e ) =>
                                        setAttributes( { tagsControlType: e.target.value } ),
                                    className: 'gbqf-control-full',
                                },
                                    el( 'option', { value: 'checkboxes' }, __( 'Checkboxes', 'gb-query-filters' ) ),
                                    el( 'option', { value: 'select' }, __( 'Select dropdown', 'gb-query-filters' ) )
                                )
                        ),

                        el(
                            'div',
                            { className: 'gbqf-control-group gbqf-control-group--tax' },
                            el( ToggleControl, {
                                label: __( 'Enable Additional Taxonomies', 'gb-query-filters' ),
                                checked: enableExtraTaxonomies,
                                onChange: ( checked ) =>
                                    setAttributes( { enableExtraTaxonomies: checked } ),
                                help: __(
                                    'Use one or more additional taxonomies as filters.',
                                    'gb-query-filters'
                                ),
                                className: 'gbqf-control-full',
                            } ),
                            enableExtraTaxonomies &&
                                el( 'label', { style: { display: 'block', fontSize: '12px', opacity: 0.8, marginBottom: '4px' } },
                                    __( 'Additional taxonomies control', 'gb-query-filters' )
                                ),
                            enableExtraTaxonomies &&
                                el( 'select', {
                                    value: extraTaxonomiesControlType,
                                    onChange: ( e ) =>
                                        setAttributes( { extraTaxonomiesControlType: e.target.value } ),
                                    className: 'gbqf-control-full',
                                },
                                    el( 'option', { value: 'checkboxes' }, __( 'Checkboxes', 'gb-query-filters' ) ),
                                    el( 'option', { value: 'select' }, __( 'Select dropdown', 'gb-query-filters' ) )
                                ),
                            enableExtraTaxonomies &&
                                el(
                                    Fragment,
                                    null,
                                    el( TextControl, {
                                        label: __( 'Additional taxonomies', 'gb-query-filters' ),
                                        help: __(
                                            'Choose additional taxonomies to filter by. Default category and post tag are configured separately.',
                                            'gb-query-filters'
                                        ),
                                        value: '',
                                        onChange: () => {},
                                        style: { display: 'none' },
                                    } ),
                                    el( FormTokenField, {
                                        value: extraTaxTokens(),
                                        suggestions: taxonomyOptions.map(
                                            ( tax ) => `${ tax.label } (${ tax.slug })`
                                        ),
                                        onChange: onChangeExtraTaxonomiesTokens,
                                        label: __( 'Additional taxonomies', 'gb-query-filters' ),
                                        __experimentalShowHowTo: false,
                                        className: 'gbqf-control-full',
                                    } )
                                )
                        ),

                        el( 'div', { className: 'gbqf-control-group gbqf-control-group--filter' },
                            el( ToggleControl, {
                                label: __( 'Enable AJAX Updates', 'gb-query-filters' ),
                                checked: enableAjax,
                                onChange: ( checked ) => setAttributes( { enableAjax: checked } ),
                                help: __(
                                    'When on, filters update results without a full page reload.',
                                    'gb-query-filters'
                                ),
                                className: 'gbqf-control-full',
                            } )
                        ),

                        el( 'div', { className: 'gbqf-control-group gbqf-control-group--filter' },
                            el( ToggleControl, {
                                label: __( 'Show Apply Button', 'gb-query-filters' ),
                                checked: enableApplyButton,
                                onChange: ( checked ) =>
                                    setAttributes( { enableApplyButton: checked } ),
                                help: enableApplyButton
                                    ? __(
                                          'When enabled, users click Apply to update results. Disable to auto-apply filters on change.',
                                          'gb-query-filters'
                                      )
                                    : __(
                                          'Filters auto-apply on change. Search/text fields still require Enter.',
                                          'gb-query-filters'
                                      ),
                                className: 'gbqf-control-full',
                            } )
                        )
                    ),

                    metaBoxIntegrationEnabled &&
                        el(
                            PanelBody,
                            { title: __( 'Meta Box Filters', 'gb-query-filters' ), initialOpen: false },
                            el( ToggleControl, {
                                label: __( 'Enable Meta Box Field Filters', 'gb-query-filters' ),
                                checked: enableMetaBoxFilter,
                                onChange: ( checked ) =>
                                    setAttributes( { enableMetaBoxFilter: checked } ),
                                help: __(
                                    'Use one or more Meta Box fields as additional filters.',
                                    'gb-query-filters'
                                ),
                                className: 'gbqf-control-full',
                            } ),
                            enableMetaBoxFilter &&
                                el(
                                    Fragment,
                                    null,
                                    el(
                                        'p',
                                        { className: 'gbqf-control-helper' },
                                        __(
                                            'Add one or more Meta Box fields to use as filters.',
                                            'gb-query-filters'
                                        )
                                    ),
                                    el(
                                        'div',
                                        { className: 'gbqf-metabox-repeater' },
                                        ( metaBoxFields || [] ).map( ( field, idx ) => renderMetaFieldRow( field, idx ) )
                                    ),
                                    el(
                                        Button,
                                        {
                                            variant: 'secondary',
                                            onClick: () => {
                                                const next = [ ...metaBoxFields, { id: '', controlType: 'auto' } ];
                                                syncMetaBoxFieldId( next );
                                            },
                                            className: 'gbqf-control-full',
                                        },
                                        __( '+ Add Meta Box field', 'gb-query-filters' )
                                    )
                                )
                        ),

                ),

                // BLOCK PREVIEW IN EDITOR: render front-end HTML
                ( ServerSideRender
                    ? el( ServerSideRender, {
                        block: 'gbqf/query-filter',
                        attributes,
                        className: ( blockProps.className ? blockProps.className + ' ' : '' ) + 'gbqf-debug-test gbqf-editor-root',
                    } )
                    : el(
                        'div',
                        {
                            ...blockProps,
                            className: ( blockProps.className ? blockProps.className + ' ' : '' ) + 'gbqf-debug-test gbqf-editor-root',
                        },
                        __(
                            'Preview unavailable (ServerSideRender not present).',
                            'gb-query-filters'
                        )
                    )
                )
            );
        },

        save() {
            return null;
        },
    } );
} )( window.wp );
