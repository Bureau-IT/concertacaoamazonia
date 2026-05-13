<?php
/**
 * Plugin Name: BIT JetEngine Map Popup Error Fallback
 * Description: Renderiza mensagem amigavel no popup do mapa (Atlas Cultural) quando a chamada REST falha OU quando o cliente JS passa objeto bruto ao setContent. v1.3.0 patcha L.Popup.prototype.setContent (camada Leaflet) — robusto a JetEngine 3.7.x (popup interno const, nao acessivel via window) e v3.8.x (envelope {success, html}).
 * Version: 1.3.0
 * Author: Daniel Cambria
 *
 * Estrategia em 3 camadas de defesa:
 *
 * 1. Patch L.Popup.prototype.setContent (camada mais baixa). Detecta:
 *    - jqXHR-like (readyState/status/statusText) -> mensagem amigavel + console.warn
 *    - envelope REST {success, html} (JetEngine v3+) -> extrai .html
 *    - objeto outro -> "[object Object]" friendly fallback
 *
 * 2. Patch window.JetLeafletPopup se exposto (JetEngine antigo).
 *
 * 3. Interceptacao $.ajaxSetup global: se response do endpoint /get-map-marker-info
 *    eh objeto bruto, transforma em HTML antes do .done callback chamar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', function () {
	if ( ! wp_script_is( 'jet-maps-listings', 'enqueued' ) ) {
		return;
	}
	?>
	<script>
	(function () {
		var FRIENDLY_ERROR_HTML =
			'<div class="bit-popup-error" role="status" aria-live="polite" style="padding:16px;font-family:inherit;max-width:240px;">'
			+ '<p style="margin:0 0 8px;font-weight:600;">Nao foi possivel carregar.</p>'
			+ '<p style="margin:0;font-size:.9em;opacity:.75;">Recarregue a pagina para tentar de novo.</p>'
			+ '</div>';

		function looksLikeError( c ) {
			return c && typeof c === 'object' && typeof c.nodeType === 'undefined'
				&& ( 'readyState' in c || 'status' in c || 'statusText' in c );
		}

		function looksLikeRestEnvelope( c ) {
			return c && typeof c === 'object' && typeof c.nodeType === 'undefined'
				&& c.success === true && typeof c.html === 'string';
		}

		function normalizeContent( content ) {
			if ( looksLikeError( content ) ) {
				if ( window.console && typeof console.warn === 'function' ) {
					console.warn( '[Atlas Popup] Falha REST', content.status, content.statusText );
				}
				return FRIENDLY_ERROR_HTML;
			}
			if ( looksLikeRestEnvelope( content ) ) {
				return content.html;
			}
			// Objeto generico (nao-erro, nao-envelope, nao-DOM) -> friendly fallback
			if ( content && typeof content === 'object' && typeof content.nodeType === 'undefined' ) {
				if ( window.console && typeof console.warn === 'function' ) {
					console.warn( '[Atlas Popup] Conteudo invalido (objeto)', content );
				}
				return FRIENDLY_ERROR_HTML;
			}
			// String "[object Object]" literal (coercao ja aconteceu antes)
			if ( typeof content === 'string' && content.indexOf( '[object Object]' ) === 0 ) {
				if ( window.console && typeof console.warn === 'function' ) {
					console.warn( '[Atlas Popup] String "[object Object]" detectada' );
				}
				return FRIENDLY_ERROR_HTML;
			}
			return content;
		}

		// === Camada 1: patch L.Popup.prototype.setContent ===
		function patchLeafletPopup() {
			if ( typeof window.L === 'undefined' || ! window.L.Popup || ! window.L.Popup.prototype ) {
				return false;
			}
			if ( window.L.Popup.prototype._bitPatched ) {
				return true;
			}
			var origSetContent = window.L.Popup.prototype.setContent;
			window.L.Popup.prototype.setContent = function ( content ) {
				return origSetContent.call( this, normalizeContent( content ) );
			};
			window.L.Popup.prototype._bitPatched = true;
			return true;
		}

		// === Camada 2: patch window.JetLeafletPopup (se exposto) ===
		function patchJetPopup() {
			if ( typeof window.JetLeafletPopup !== 'function' ) {
				return;
			}
			if ( window.JetLeafletPopup._bitPatched ) {
				return;
			}
			var OriginalPopup = window.JetLeafletPopup;
			var WrappedPopup = function ( data ) {
				var instance = OriginalPopup.call( this, data );
				if ( ! instance || typeof instance.setContent !== 'function' ) {
					return instance;
				}
				var origSetContent = instance.setContent;
				instance.setContent = function ( content ) {
					return origSetContent.call( this, normalizeContent( content ) );
				};
				return instance;
			};
			WrappedPopup._bitPatched = true;
			window.JetLeafletPopup = WrappedPopup;
		}

		function applyPatches() {
			patchLeafletPopup();
			patchJetPopup();
		}

		// Aplica imediatamente se Leaflet ja carregou
		applyPatches();

		// Retry: Leaflet pode carregar tardiamente
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', applyPatches, { once: true } );
		}
		window.addEventListener( 'load', applyPatches, { once: true } );

		// Backup: poll por 5s caso scripts atrasem
		var tries = 0;
		var poll = setInterval( function () {
			if ( patchLeafletPopup() || ++tries > 20 ) {
				clearInterval( poll );
			}
		}, 250 );
	})();
	</script>
	<?php
}, 99 );
