/* global giodcFeeAdmin, giodcPickerData, jQuery */
( function ( $ ) {
    'use strict';

    // -----------------------------------------------------------------------
    // Shared: add a tag + hidden input for a selected item
    // -----------------------------------------------------------------------
    function addItem( id, text, $tags, $wrap, selected ) {
        if ( selected[ id ] ) {
            return;
        }
        selected[ id ] = text;

        var $tag    = $( '<span class="giodc-tag"></span>' );
        var $label  = $( '<span class="giodc-tag__label"></span>' ).text( text );
        var $remove = $( '<button type="button" class="giodc-tag__remove" aria-label="Remove">&times;</button>' );

        $remove.on( 'click', function () {
            delete selected[ id ];
            $tag.remove();
            $wrap.find( 'input[type="hidden"][name="object_ids[]"]' ).filter( function () {
                return String( $( this ).val() ) === String( id );
            } ).remove();
        } );

        $tag.append( $label, $remove );
        $tags.append( $tag );
        $wrap.append( $( '<input type="hidden" name="object_ids[]">' ).val( id ) );
    }

    // -----------------------------------------------------------------------
    // Shared: render a results <ul> from a data array
    // -----------------------------------------------------------------------
    function renderResults( data, $results, $tags, $wrap, $input, selected ) {
        $results.empty();

        var items = ( data || [] ).filter( function ( item ) {
            return ! selected[ item.id ];
        } );

        if ( ! items.length ) {
            $results.append(
                $( '<li class="giodc-picker__no-results"></li>' )
                    .text( giodcFeeAdmin.i18n.noResults )
            );
        } else {
            $.each( items, function ( i, item ) {
                var $li = $( '<li class="giodc-picker__item" tabindex="-1"></li>' )
                    .attr( 'data-id', item.id )
                    .text( item.text );

                $li.on( 'mousedown', function ( e ) {
                    e.preventDefault();
                    addItem( item.id, item.text, $tags, $wrap, selected );
                    $input.val( '' ).focus();
                    $results.hide();
                } );

                $results.append( $li );
            } );
        }
        $results.show();
    }

    // -----------------------------------------------------------------------
    // Shared: keyboard navigation (arrows / Escape) within results
    // -----------------------------------------------------------------------
    function handleKeydown( e, $results, $input ) {
        if ( ! $results.is( ':visible' ) ) {
            return;
        }
        var $items   = $results.find( '.giodc-picker__item' );
        var $focused = $results.find( '.giodc-picker__item.is-focused' );

        if ( e.key === 'ArrowDown' ) {
            e.preventDefault();
            if ( ! $focused.length ) {
                $items.first().addClass( 'is-focused' ).focus();
            } else {
                var $next = $focused.removeClass( 'is-focused' ).next( '.giodc-picker__item' );
                ( $next.length ? $next : $items.first() ).addClass( 'is-focused' ).focus();
            }
        } else if ( e.key === 'ArrowUp' ) {
            e.preventDefault();
            if ( $focused.length ) {
                var $prev = $focused.removeClass( 'is-focused' ).prev( '.giodc-picker__item' );
                ( $prev.length ? $prev : $input ).focus();
            }
        } else if ( e.key === 'Escape' ) {
            $results.hide();
            $input.focus();
        }
    }

    // -----------------------------------------------------------------------
    // Product picker – AJAX search
    // -----------------------------------------------------------------------
    function initProductPicker() {
        var $wrap = $( '#giodc-product-picker' );
        if ( ! $wrap.length ) {
            return;
        }

        var selected = {};
        var $tags    = $wrap.find( '.giodc-picker__tags' );
        var $input   = $wrap.find( '.giodc-picker__search' );
        var $results = $wrap.find( '.giodc-picker__results' );
        var timer;

        if ( window.giodcPickerData && window.giodcPickerData.selectedProducts ) {
            $.each( window.giodcPickerData.selectedProducts, function ( id, text ) {
                addItem( parseInt( id, 10 ), text, $tags, $wrap, selected );
            } );
        }

        function doSearch( term ) {
            $.ajax( {
                url:      giodcFeeAdmin.ajaxUrl,
                dataType: 'json',
                data: {
                    action: giodcFeeAdmin.searchProductsAction,
                    nonce:  giodcFeeAdmin.nonce,
                    term:   term
                },
                success: function ( data ) {
                    renderResults( data, $results, $tags, $wrap, $input, selected );
                }
            } );
        }

        $input.on( 'focus', function () {
            doSearch( $( this ).val().trim() );
        } ).on( 'input', function () {
            clearTimeout( timer );
            var term = $( this ).val().trim();
            timer = setTimeout( function () { doSearch( term ); }, 300 );
        } ).on( 'keydown', function ( e ) {
            handleKeydown( e, $results, $input );
        } );

        $results.on( 'keydown', '.giodc-picker__item', function ( e ) {
            handleKeydown( e, $results, $input );
        } );

        $( document ).on( 'click.giodcProduct', function ( e ) {
            if ( ! $wrap.is( e.target ) && ! $wrap.has( e.target ).length ) {
                $results.hide();
            }
        } );
    }

    // -----------------------------------------------------------------------
    // Category picker – local filter over pre-loaded list
    // -----------------------------------------------------------------------
    function initCategoryPicker() {
        var $wrap = $( '#giodc-category-picker' );
        if ( ! $wrap.length ) {
            return;
        }

        var allCats  = ( window.giodcPickerData && window.giodcPickerData.allCategories ) ? window.giodcPickerData.allCategories : [];
        var selected = {};
        var $tags    = $wrap.find( '.giodc-picker__tags' );
        var $input   = $wrap.find( '.giodc-picker__search' );
        var $results = $wrap.find( '.giodc-picker__results' );

        if ( window.giodcPickerData && window.giodcPickerData.selectedCategoryIds ) {
            $.each( window.giodcPickerData.selectedCategoryIds, function ( i, id ) {
                var matches = allCats.filter( function ( c ) { return String( c.id ) === String( id ); } );
                if ( matches.length ) {
                    addItem( parseInt( id, 10 ), matches[ 0 ].text, $tags, $wrap, selected );
                }
            } );
        }

        function doFilter( term ) {
            var lc       = term.toLowerCase();
            var filtered = term.length
                ? allCats.filter( function ( c ) { return c.text.toLowerCase().indexOf( lc ) !== -1; } )
                : allCats;
            renderResults( filtered, $results, $tags, $wrap, $input, selected );
        }

        $input.on( 'focus', function () {
            doFilter( $( this ).val().trim() );
        } ).on( 'input', function () {
            doFilter( $( this ).val().trim() );
        } ).on( 'keydown', function ( e ) {
            handleKeydown( e, $results, $input );
        } );

        $results.on( 'keydown', '.giodc-picker__item', function ( e ) {
            handleKeydown( e, $results, $input );
        } );

        $( document ).on( 'click.giodcCategory', function ( e ) {
            if ( ! $wrap.is( e.target ) && ! $wrap.has( e.target ).length ) {
                $results.hide();
            }
        } );
    }

    // -----------------------------------------------------------------------
    // Toggle product / category rows based on rule_type radio
    // -----------------------------------------------------------------------
    function initRuleTypeToggle() {
        var $radios        = $( 'input[name="rule_type"]' );
        var $rowProducts   = $( '#giodc-row-products' );
        var $rowCategories = $( '#giodc-row-categories' );

        if ( ! $radios.length ) {
            return;
        }

        function applyToggle( type ) {
            if ( 'product' === type ) {
                $rowProducts.show();
                $rowCategories.hide();
                $( '#giodc-category-picker input[name="object_ids[]"]' ).prop( 'disabled', true );
                $( '#giodc-product-picker  input[name="object_ids[]"]' ).prop( 'disabled', false );
            } else {
                $rowProducts.hide();
                $rowCategories.show();
                $( '#giodc-product-picker  input[name="object_ids[]"]' ).prop( 'disabled', true );
                $( '#giodc-category-picker input[name="object_ids[]"]' ).prop( 'disabled', false );
            }
        }

        applyToggle( $radios.filter( ':checked' ).val() );

        $radios.on( 'change', function () {
            applyToggle( $( this ).val() );
        } );
    }

    // -----------------------------------------------------------------------
    // Form validation: require at least one object_id before submit
    // -----------------------------------------------------------------------
    function initFormValidation() {
        $( '#giodc-fee-form' ).on( 'submit', function ( e ) {
            var type    = $( 'input[name="rule_type"]:checked' ).val();
            var pickId  = 'product' === type ? '#giodc-product-picker' : '#giodc-category-picker';
            var hasObjs = $( pickId + ' input[type="hidden"][name="object_ids[]"]:not(:disabled)' ).length > 0;

            if ( ! hasObjs ) {
                e.preventDefault();
                alert( 'Please select at least one ' + ( 'product' === type ? 'product' : 'category' ) + '.' );
                $( pickId + ' .giodc-picker__search' ).focus();
                return false;
            }

            var hasTier = false;
            $( '.giodc-tier-input' ).each( function () {
                if ( $( this ).val() !== '' && parseFloat( $( this ).val() ) >= 0 ) {
                    hasTier = true;
                    return false;
                }
            } );

            if ( ! hasTier ) {
                e.preventDefault();
                alert( 'Please define at least one fee tier amount.' );
                return false;
            }
        } );
    }

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------
    $( function () {
        initProductPicker();
        initCategoryPicker();
        initRuleTypeToggle();
        initFormValidation();
    } );

}( jQuery ) );
