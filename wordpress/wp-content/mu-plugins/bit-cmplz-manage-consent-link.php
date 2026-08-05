<?php
/**
 * Plugin Name: BIT Complianz Manage Consent Link
 * Plugin URI:  https://github.com/Bureau-IT/server-tools
 * Description: Substitui o botao flutuante "Gerenciar consentimento" do Complianz por um link no rodape Elementor. Esconde o container persistente do plugin via CSS e delega o clique de qualquer elemento com classe `cmplz-trigger-banner` ou `href="#cmplz-manage-consent"` para a API publica do Complianz (`cmplz_set_banner_status('show')`), reabrindo o banner com o estado de consentimento preservado.
 * Version:     1.2.0
 * Author:      Bureau IT
 * Author URI:  https://bureau-it.com/
 * License:     GPL-2.0-or-later
 *
 * @since 1.0.0 — Implementacao inicial: JS delegado para .cmplz-trigger-banner.
 * @since 1.1.0 — Adicionado CSS para ocultar #cmplz-manage-consent (container do botao flutuante nativo).
 * @since 1.2.0 — console.warn defensivo quando a API publica do Complianz nao estiver disponivel (plugin desativado / atualizado / com API renomeada).
 *
 * Rollback completo exige tambem reverter `script_center_button=yes` no DB (option `cmplz_options`); apenas remover este arquivo NAO restaura o botao flutuante porque a configuracao do Complianz e separada.
 *
 * Priority 99 nos hooks: garante execucao APOS o tema e APOS o Complianz registrar suas funcoes globais (window.cmplz_set_banner_status), evitando race no momento do click.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_head', function () {
	?>
	<style id="bit-cmplz-hide-floating">
	#cmplz-manage-consent { display: none !important; }
	</style>
	<?php
}, 99 );

add_action( 'wp_footer', function () {
	?>
	<script>
	(function () {
		document.addEventListener('click', function (e) {
			var trigger = e.target.closest('.cmplz-trigger-banner, a[href="#cmplz-manage-consent"]');
			if (!trigger) return;
			e.preventDefault();
			if (typeof window.cmplz_set_banner_status === 'function') {
				window.cmplz_set_banner_status('show');
			} else if (typeof window.show_cookie_banner === 'function') {
				window.show_cookie_banner();
			} else {
				console.warn('[bit-cmplz] Complianz API ausente — banner nao pode ser reaberto. Plugin desativado ou atualizado com API renomeada?');
			}
		});
	})();
	</script>
	<?php
}, 99 );
