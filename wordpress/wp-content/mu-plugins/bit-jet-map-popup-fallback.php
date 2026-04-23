<?php
/**
 * Plugin Name: BIT JetEngine Map Popup Error Fallback
 * Description: Renderiza mensagem amigavel no popup do mapa (Atlas Cultural) quando a chamada REST falha (timeout, 5xx, offline, rate limit, nonce invalido). Patch JS injetado no wp_footer que intercepta setContent do JetLeafletPopup e substitui objetos jqXHR-like por HTML de erro legivel. Cobre todos os modos de falha do .fail() handler do JetEngine Maps.
 * Version: 1.0.0
 * Author: Daniel Cambria
 *
 * Contexto: o JetEngine Maps Listings JS passa o objeto jqXHR direto para
 * setContent quando a request REST falha. O setContent atribui o valor a
 * uma propriedade HTML, forcando coercao do objeto para a string literal
 * "[object Object]". Este fix intercepta setContent, detecta objetos que
 * parecem jqXHR (tem readyState/status/statusText) e renderiza mensagem
 * amigavel em vez da coercao padrao.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', function () {
	if ( ! wp_script_is( 'jet-engine-maps-frontend', 'enqueued' ) ) {
		return;
	}
	?>
	<script>
	(function () {
		function patchPopup() {
			if ( typeof window.JetLeafletPopup !== 'function' ) {
				return;
			}

			var OriginalPopup = window.JetLeafletPopup;

			window.JetLeafletPopup = function ( data ) {
				var instance = OriginalPopup.call( this, data );

				if ( ! instance || typeof instance.setContent !== 'function' ) {
					return instance;
				}

				var originalSetContent = instance.setContent;

				instance.setContent = function ( content ) {
					var isObject   = content && typeof content === 'object' && typeof content.nodeType === 'undefined';
					var looksLikeError = isObject && ( 'readyState' in content || 'status' in content || 'statusText' in content );

					if ( looksLikeError ) {
						if ( window.console && typeof console.warn === 'function' ) {
							console.warn( '[Atlas Popup] Falha REST', content.status, content.statusText );
						}

						content = '<div class="bit-popup-error" style="padding:16px;font-family:inherit;max-width:240px;">'
							+ '<p style="margin:0 0 8px;font-weight:600;">Nao foi possivel carregar.</p>'
							+ '<p style="margin:0;font-size:.9em;opacity:.75;">Tente novamente em instantes.</p>'
							+ '</div>';
					}

					return originalSetContent.call( this, content );
				};

				return instance;
			};
		}

		if ( typeof window.JetLeafletPopup === 'function' ) {
			patchPopup();
		} else {
			document.addEventListener( 'DOMContentLoaded', patchPopup, { once: true } );
		}
	})();
	</script>
	<?php
}, 99 );
