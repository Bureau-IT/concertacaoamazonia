<?php
/**
 * Plugin Name: BIT JetEngine Map Reset Button
 * Description: Adiciona botão "Resetar posição" dentro da barra de zoom (+/-) do widget JetEngine Maps Listing e substitui os ícones +/- do Leaflet por SVGs Lucide. Fecha popup aberto antes de centralizar.
 * Version: 1.2.2
 * Author: Bureau de Tecnologia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}

	$lang = '';
	if ( has_filter( 'wpml_current_language' ) ) {
		$lang = (string) apply_filters( 'wpml_current_language', null );
	}
	if ( $lang === '' ) {
		$locale = get_locale();
		$lang   = ( strpos( $locale, 'en' ) === 0 ) ? 'en' : 'pt';
	}
	$label = ( $lang === 'en' ) ? 'Reset map position' : 'Resetar posição do mapa';
	?>
	<style id="bit-jet-map-reset-css">
		/* Botao reset herda visual nativo do leaflet-control-zoom (+/-) */
		.leaflet-control-zoom a.bit-leaflet-reset {
			display: flex;
			align-items: center;
			justify-content: center;
			text-decoration: none;
			font-size: 0;
		}
		.leaflet-control-zoom a.bit-leaflet-reset svg,
		.leaflet-control-zoom a.bit-leaflet-zoomin svg,
		.leaflet-control-zoom a.bit-leaflet-zoomout svg {
			width: 16px;
			height: 16px;
			display: block;
			pointer-events: none;
		}
		/* Esconde texto +/- nativo quando substituimos por SVG */
		.leaflet-control-zoom a.bit-leaflet-zoomin,
		.leaflet-control-zoom a.bit-leaflet-zoomout {
			font-size: 0 !important;
		}
	</style>
	<script id="bit-jet-map-reset-js">
	(function ($) {
		if (!window.jQuery || !window.elementorFrontend) return;
		var SVG_NS = 'http://www.w3.org/2000/svg';
		var LABEL = <?php echo wp_json_encode( $label ); ?>;

		// Polling constants — Leaflet/JetEngine podem demorar a inicializar
		// dependendo de Elementor Pro Delay JS + condições de rede.
		var POLL_INTERVAL_MS  = 100;   // intervalo entre tentativas
		var MAX_POLL_ATTEMPTS = 40;    // 40 × 100ms = 4s timeout total
		var FLY_DURATION_S    = 0.6;   // duração da animação flyTo (Leaflet default ~0.25s)

		function buildSvg(viewBox, strokeWidth) {
			var svg = document.createElementNS(SVG_NS, 'svg');
			svg.setAttribute('viewBox', viewBox || '0 0 24 24');
			svg.setAttribute('fill', 'none');
			svg.setAttribute('stroke', 'currentColor');
			svg.setAttribute('stroke-width', strokeWidth || '2');
			svg.setAttribute('stroke-linecap', 'round');
			svg.setAttribute('stroke-linejoin', 'round');
			svg.setAttribute('aria-hidden', 'true');
			return svg;
		}

		function buildHomeIcon() {
			// Lucide "home"
			var svg = buildSvg('0 0 24 24', '2');
			var path = document.createElementNS(SVG_NS, 'path');
			path.setAttribute('d', 'm3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z');
			var poly = document.createElementNS(SVG_NS, 'polyline');
			poly.setAttribute('points', '9 22 9 12 15 12 15 22');
			svg.appendChild(path);
			svg.appendChild(poly);
			return svg;
		}

		function buildPlusIcon() {
			// Lucide "plus" — mesmo SVG do playground
			var svg = buildSvg('0 0 24 24', '2.5');
			var path = document.createElementNS(SVG_NS, 'path');
			path.setAttribute('d', 'M12 5v14M5 12h14');
			svg.appendChild(path);
			return svg;
		}

		function buildMinusIcon() {
			// Lucide "minus"
			var svg = buildSvg('0 0 24 24', '2.5');
			var path = document.createElementNS(SVG_NS, 'path');
			path.setAttribute('d', 'M5 12h14');
			svg.appendChild(path);
			return svg;
		}

		function replaceZoomIcons(zoomCtrl) {
			var zoomIn = zoomCtrl.querySelector('.leaflet-control-zoom-in');
			var zoomOut = zoomCtrl.querySelector('.leaflet-control-zoom-out');
			if (zoomIn && !zoomIn.dataset.bitIconReplaced) {
				zoomIn.classList.add('bit-leaflet-zoomin');
				zoomIn.textContent = '';
				zoomIn.appendChild(buildPlusIcon());
				zoomIn.dataset.bitIconReplaced = '1';
			}
			if (zoomOut && !zoomOut.dataset.bitIconReplaced) {
				zoomOut.classList.add('bit-leaflet-zoomout');
				zoomOut.textContent = '';
				zoomOut.appendChild(buildMinusIcon());
				zoomOut.dataset.bitIconReplaced = '1';
			}
		}

		function attachResetButton($scope) {
			var $map = $scope.find('.jet-map-listing').first();
			if (!$map.length) return;

			var attempts = 0;
			var poll = setInterval(function () {
				var map = $map.data('mapInstance');
				var container = map && map.getContainer ? map.getContainer() : null;
				var zoomCtrl = container ? container.querySelector('.leaflet-control-zoom') : null;

				if (!map || !zoomCtrl) {
					if (++attempts > MAX_POLL_ATTEMPTS) {
						clearInterval(poll);
						if (window.console && console.warn) {
							console.warn('[BIT Map Reset] polling esgotado após ' + (MAX_POLL_ATTEMPTS * POLL_INTERVAL_MS) + 'ms — Leaflet/JetEngine não inicializou. Botão home não foi anexado.');
						}
					}
					return;
				}
				clearInterval(poll);

				if ($map.data('bitResetAttached')) return;
				$map.data('bitResetAttached', true);

				replaceZoomIcons(zoomCtrl);

				var initialCenter = map.getCenter();
				var initialZoom = map.getZoom();

				// Cria <a> com mesmas classes/estilos que .leaflet-control-zoom-in/out
				var btn = document.createElement('a');
				btn.className = 'leaflet-control-zoom-reset bit-leaflet-reset';
				btn.href = '#';
				btn.setAttribute('role', 'button');   // setAttribute garante atributo HTML real (não só propriedade JS expando)
				btn.setAttribute('aria-label', LABEL);
				btn.title = LABEL;
				btn.appendChild(buildHomeIcon());

				L.DomEvent.disableClickPropagation(btn);
				L.DomEvent.on(btn, 'click', function (e) {
					L.DomEvent.preventDefault(e);
					if (map.closePopup) map.closePopup();
					map.flyTo(initialCenter, initialZoom, { duration: FLY_DURATION_S });
					btn.blur();
				});

				// Anexa apos o ultimo botao da barra (.leaflet-control-zoom-out)
				zoomCtrl.appendChild(btn);
			}, POLL_INTERVAL_MS);
		}

		$(window).on('elementor/frontend/init', function () {
			elementorFrontend.hooks.addAction(
				'frontend/element_ready/jet-engine-maps-listing.default',
				attachResetButton
			);
		});
	})(jQuery);
	</script>
	<?php
}, 99 );
