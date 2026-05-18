/**
 * Bureau A11y — JS da tela de admin.
 *
 * - Inicializa wp-color-picker em cada slot
 * - Sincroniza radios global/custom com data-mode no .bureau-a11y-slot
 * - Live preview: aplica setProperty nos slots do #bureau-a11y-admin-preview
 *   ao mudar select, radio ou picker
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var preview = document.getElementById( 'bureau-a11y-admin-preview' );
		var panel   = preview ? preview.querySelector( '.bureau-a11y-admin-preview__panel' ) : null;
		var trigger = preview ? preview.querySelector( '.bureau-a11y-admin-preview__trigger' ) : null;
		var globals = ( window.bureauA11yAdmin && window.bureauA11yAdmin.globals ) || {};

		if ( ! panel ) {
			return;
		}

		function applyToPreview( slot, value ) {
			var varName = '--ba-override-' + slot.replace( /_/g, '-' );
			panel.style.setProperty( varName, value );
			if ( trigger ) {
				trigger.style.setProperty( varName, value );
			}
		}

		function resolveSlotValue( $slot ) {
			var slot = $slot.data( 'slot' );
			var mode = $slot.find( 'input[type="radio"]:checked' ).val();
			if ( 'global' === mode ) {
				var gid = $slot.find( '.bureau-a11y-slot__select' ).val();
				var info = globals[ gid ];
				return info ? info.value : '';
			}
			return $slot.find( '.bureau-a11y-slot__picker' ).val();
		}

		function updateSlot( $slot ) {
			var slot = $slot.data( 'slot' );
			var mode = $slot.find( 'input[type="radio"]:checked' ).val();
			$slot.attr( 'data-mode', mode );
			var value = resolveSlotValue( $slot );
			if ( value ) {
				applyToPreview( slot, value );
			}
		}

		// Inicializa color pickers
		$( '.bureau-a11y-slot__picker' ).wpColorPicker( {
			change: function ( event, ui ) {
				var $slot = $( event.target ).closest( '.bureau-a11y-slot' );
				// Defer pra wpColorPicker terminar de aplicar o valor no input
				setTimeout( function () { updateSlot( $slot ); }, 10 );
			},
			clear: function () {
				var $slot = $( this ).closest( '.bureau-a11y-slot' );
				setTimeout( function () { updateSlot( $slot ); }, 10 );
			},
		} );

		// Radio change
		$( '.bureau-a11y-slot input[type="radio"]' ).on( 'change', function () {
			updateSlot( $( this ).closest( '.bureau-a11y-slot' ) );
		} );

		// Select change
		$( '.bureau-a11y-slot__select' ).on( 'change', function () {
			updateSlot( $( this ).closest( '.bureau-a11y-slot' ) );
		} );

		// Inicializa preview com valores atuais
		$( '.bureau-a11y-slot' ).each( function () {
			updateSlot( $( this ) );
		} );
	} );

} )( jQuery );
