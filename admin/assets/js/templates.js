/**
 * TCGiant Sync - the Listing Templates screen.
 *
 * The preview shows the description only inside an iframe whose sandbox
 * allows nothing, set through srcdoc; every message from the server is put on
 * the page as text, never as markup.
 *
 * @package TCGiant_Sync
 */
jQuery( function ( $ ) {
	'use strict';

	var cfg = window.tcgiantTemplates || {};
	var text = cfg.text || {};
	var editors = {};

	// ── The code panes: WordPress's bundled CodeMirror ─────────────────────────
	function codePane( id, settings ) {
		var el = document.getElementById( id );
		if ( ! el || ! settings || ! window.wp || ! wp.codeEditor ) {
			return null;
		}
		return wp.codeEditor.initialize( el, settings ).codemirror;
	}
	if ( cfg.codeEditor ) {
		editors.html = codePane( 'tc-tpl-html', cfg.codeEditor.html );
		editors.css = codePane( 'tc-tpl-css', cfg.codeEditor.css );
	}

	/** Copy CodeMirror's text back into the textareas, for a save or a preview. */
	function syncPanes() {
		if ( editors.html ) {
			editors.html.save();
		}
		if ( editors.css ) {
			editors.css.save();
		}
	}
	$( '#tc-template-form' ).on( 'submit', syncPanes );

	// Changes not saved: ask before the page is left.
	var dirty = false;
	$.each( editors, function ( name, editor ) {
		if ( editor ) {
			editor.on( 'change', function () { dirty = true; } );
		}
	} );
	$( '#tc-template-form' ).on( 'input change', ':input', function () { dirty = true; } );
	$( '#tc-template-form' ).on( 'submit', function () { dirty = false; } );
	$( window ).on( 'beforeunload', function () {
		return dirty ? true : undefined;
	} );

	// ── Insert a placeholder where the cursor is ─────────────────────────────
	var lastPane = 'html';
	$( '#tc-tpl-css' ).on( 'focus', function () { lastPane = 'css'; } );
	$( '#tc-tpl-html' ).on( 'focus', function () { lastPane = 'html'; } );
	if ( editors.css ) {
		editors.css.on( 'focus', function () { lastPane = 'css'; } );
	}
	if ( editors.html ) {
		editors.html.on( 'focus', function () { lastPane = 'html'; } );
	}

	$( document ).on( 'click', '.tc-insert', function () {
		var code = String( $( this ).data( 'insert' ) );
		var pane = ( 'css' === lastPane && 0 === code.indexOf( '{{design.' ) ) ? 'css' : 'html';
		if ( editors[ pane ] ) {
			editors[ pane ].replaceSelection( code );
			editors[ pane ].focus();
			return;
		}
		var area = document.getElementById( 'tc-tpl-' + pane );
		if ( ! area ) {
			return;
		}
		var start = area.selectionStart || 0;
		area.value = area.value.slice( 0, start ) + code + area.value.slice( area.selectionEnd || start );
		area.focus();
		area.selectionStart = area.selectionEnd = start + code.length;
	} );

	// ── Colours: the picker and the #rrggbb box stay together ──────────────────
	$( document ).on( 'input change', '.tc-colour-picker', function () {
		$( '#' + $( this ).data( 'for' ) ).val( this.value );
	} );
	$( document ).on( 'input change', '.tc-colour-text', function () {
		if ( /^#[0-9a-fA-F]{6}$/.test( this.value ) ) {
			$( this ).siblings( '.tc-colour-picker' ).val( this.value.toLowerCase() );
		}
	} );

	// ── Images from the Media Library: only the attachment ID is kept ──────────
	$( document ).on( 'click', '.tc-image-choose', function ( e ) {
		e.preventDefault();
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var field = $( this ).closest( '.tc-image-field' );
		var frame = wp.media( { title: text.pick || '', library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			var chosen = frame.state().get( 'selection' ).first().toJSON();
			// A change, for the prompt before leaving with it unsaved.
			field.find( '.tc-image-id' ).val( parseInt( chosen.id, 10 ) || 0 ).trigger( 'change' );
			field.find( '.tc-image-preview' ).attr( 'src', chosen.url || '' ).prop( 'hidden', ! chosen.url );
		} );
		frame.open();
	} );
	$( document ).on( 'click', '.tc-image-clear', function ( e ) {
		e.preventDefault();
		var field = $( this ).closest( '.tc-image-field' );
		field.find( '.tc-image-id' ).val( 0 ).trigger( 'change' );
		field.find( '.tc-image-preview' ).attr( 'src', '' ).prop( 'hidden', true );
	} );

	// ── The category map: another row ────────────────────────────────────────
	$( '#tc-category-add' ).on( 'click', function () {
		var body = $( '#tc-category-map tbody' );
		var row = body.find( 'tr' ).last().clone();
		var index = body.find( 'tr' ).length;
		row.find( 'select, input' ).each( function () {
			this.name = this.name.replace( /\[category_map\]\[\d+\]/, '[category_map][' + index + ']' );
			if ( 'number' === this.type ) {
				this.value = 10;
			} else {
				this.value = '0';
			}
		} );
		body.append( row );
	} );

	// ── The preview ──────────────────────────────────────────────────────────
	// ── Switching from WP-Lister: the source is the one being filled in ────────
	// Above the preview's early return: the Convert view has no preview.
	$( '.tc-convert-form' ).on( 'change input', 'input[type="file"], select, textarea', function () {
		$( this ).closest( '.tc-field' ).find( 'input[type="radio"][name="source"]' ).prop( 'checked', true );
	} );

	var panel = $( '#tc-preview' );
	if ( ! panel.length ) {
		return;
	}

	var frameEl = document.getElementById( 'tc-preview-frame' );
	var productSelect = $( '#tc-preview-product' );

	function listInto( list, items, empty ) {
		list.empty();
		if ( ! items || ! items.length ) {
			$( '<li/>' ).addClass( 'is-empty' ).text( empty ).appendTo( list );
			return;
		}
		$.each( items, function ( i, item ) {
			$( '<li/>' ).text( String( item ) ).appendTo( list );
		} );
	}

	function request( extra ) {
		syncPanes();
		var data = {
			_ajax_nonce: cfg.nonce,
			product_id: productSelect.val() || '',
			template_id: panel.data( 'template' ) || 0
		};
		if ( panel.data( 'draft' ) ) {
			var form = $( '#tc-template-form' );
			data.draft = 1;
			$.each( form.serializeArray(), function ( i, field ) {
				if ( '_wpnonce' === field.name || 'action' === field.name || '_wp_http_referer' === field.name ) {
					return;
				}
				data[ field.name ] = field.value;
			} );
		}
		return $.extend( data, extra );
	}

	function loadProducts( term, preview ) {
		$.post( cfg.ajaxUrl, { action: 'tcgiant_tpl_products', _ajax_nonce: cfg.nonce, term: term || '' } ).done( function ( r ) {
			if ( ! r || ! r.success ) {
				return;
			}
			var keep = productSelect.val();
			if ( preview && ! keep && r.data.products.length ) {
				// The first of the products just loaded, previewed at once.
				setTimeout( function () { $( '#tc-preview-run' ).trigger( 'click' ); }, 0 );
			}
			productSelect.empty();
			$.each( r.data.products, function ( i, p ) {
				$( '<option/>' ).val( p.id ).text( p.name + ( p.sku ? ' (' + p.sku + ')' : '' ) ).appendTo( productSelect );
			} );
			if ( keep && productSelect.find( 'option[value="' + keep + '"]' ).length ) {
				productSelect.val( keep );
			}
		} );
	}

	var searchTimer = null;
	$( '#tc-preview-search' ).on( 'input', function () {
		var term = this.value;
		clearTimeout( searchTimer );
		searchTimer = setTimeout( function () { loadProducts( term ); }, 300 );
	} );
	if ( ! productSelect.val() ) {
		loadProducts( '', true );
	}

	$( '#tc-preview-run' ).on( 'click', function () {
		var count = $( '#tc-preview-count' );
		count.removeClass( 'is-over' ).text( text.loading || '' );
		$.post( cfg.ajaxUrl, request( { action: 'tcgiant_tpl_preview' } ) ).done( function ( r ) {
			if ( ! r || ! r.success ) {
				count.text( ( r && r.data && r.data.message ) ? r.data.message : ( text.failed || '' ) );
				return;
			}
			// The bytes eBay would get, in a sandbox that runs nothing.
			frameEl.srcdoc = r.data.html;
			count.toggleClass( 'is-over', !! r.data.over ).text(
				String( text.count || '%1$s / %2$s' ).replace( '%1$s', r.data.chars.toLocaleString() ).replace( '%2$s', r.data.limit.toLocaleString() ) + ( r.data.none ? ( text.plain || '' ) : '' )
			);
			listInto( $( '#tc-preview-warnings' ), r.data.warnings, text.clean || '' );
			listInto( $( '#tc-preview-removed' ), r.data.removed, text.none || '' );
		} ).fail( function () {
			count.text( text.failed || '' );
		} );
	} );

	$( '.tc-preview-devices button' ).on( 'click', function () {
		$( '.tc-preview-devices button' ).removeClass( 'is-active' ).attr( 'aria-pressed', 'false' );
		$( this ).addClass( 'is-active' ).attr( 'aria-pressed', 'true' );
		frameEl.style.width = String( $( this ).data( 'width' ) );
	} );

	$( '#tc-preview-check' ).on( 'click', function () {
		// Not connected, the server says so and asks eBay nothing: no confirm.
		if ( cfg.connected && ! window.confirm( text.confirm || '' ) ) {
			return;
		}
		var btn = $( this );
		var out = $( '#tc-preview-check-result' );
		btn.prop( 'disabled', true );
		out.removeClass( 'is-ok is-bad' ).text( text.checking || '' );
		$.post( cfg.ajaxUrl, request( { action: 'tcgiant_tpl_check_ebay' } ) ).done( function ( r ) {
			var ok = !! ( r && r.success );
			out.addClass( ok ? 'is-ok' : 'is-bad' ).text( ( r && r.data && r.data.message ) ? r.data.message : ( text.failed || '' ) );
			btn.prop( 'disabled', r && r.data && 0 === r.data.left );
			if ( r && r.data && 'number' === typeof r.data.left && text.left ) {
				$( '#tc-preview-check-left' ).text( String( text.left ).replace( '%1$d', r.data.left ).replace( '%2$d', cfg.checkCap ) );
			}
		} ).fail( function () {
			out.addClass( 'is-bad' ).text( text.failed || '' );
			btn.prop( 'disabled', false );
		} );
	} );

	if ( productSelect.val() ) {
		$( '#tc-preview-run' ).trigger( 'click' );
	}

} );
