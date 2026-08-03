<?php
/**
 * set-complianz-palette.php — aplica a paleta da marca no PAINEL do Complianz
 *
 * Autor : Daniel Cambría — Bureau de Tecnologia
 * Data  : 2026-08-03
 * Versão: 1.0.0
 *
 * CONTEXTO
 *   Até 2026-08-03 as cores do cookie banner vinham do child theme
 *   (css/plugins/complianz.css) com !important, ignorando o que estava
 *   configurado em Complianz → Cookie Banner → Aparência. O painel do plugin
 *   passa a ser a FONTE DA VERDADE das cores do banner; o tema só cuida dos
 *   estados de hover (que o painel não expõe) e os deriva das próprias
 *   variáveis --cmplz_* geradas pelo plugin.
 *
 *   Este script grava no painel a paleta da marca (Global Colors de PROD) e
 *   dispara a regeneração do CSS do plugin. É idempotente: rodar duas vezes
 *   não muda nada além de incrementar banner_version.
 *
 * USO (um blog por execução — o Complianz lê $wpdb->prefix do blog atual)
 *   Dev, blog 1:
 *     ./common/bin/docker-dev.sh wp --url="https://cambrasmax.local:8484" \
 *         --user=1 eval-file scripts/set-complianz-palette.php
 *   Dev, blog 2 (/cultura/):
 *     ./common/bin/docker-dev.sh wp --url="https://cambrasmax.local:8484/cultura/" \
 *         --user=1 eval-file scripts/set-complianz-palette.php
 *
 *   Prod (via SSH, após scp para /tmp):
 *     sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
 *         --url='https://concertacaoamazonia.com.br' --user=1 \
 *         eval-file /tmp/set-complianz-palette.php
 *
 *   DRY-RUN (mostra o diff, não grava): CMPLZ_PALETTE_APPLY não definido.
 *   APLICAR: CMPLZ_PALETTE_APPLY=1
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Este script roda somente via WP-CLI.\n" );
}

$apply = (bool) getenv( 'CMPLZ_PALETTE_APPLY' );

/* ---------------------------------------------------------------------------
 * Paleta da marca — espelha os Global Colors do kit de PROD
 *   primary   #21191B  (quase-preto)
 *   secondary #F8EAD9  (offwhite / bege)
 *   text      #392E34  (escuro suave)
 *   accent    #FE78A9  (rosa — não usado no banner)
 * ------------------------------------------------------------------------ */
$dark     = '#21191B';
$offwhite = '#F8EAD9';
$inactive = '#B8AA9B'; // bege escurecido — toggle desligado

$palette = array(
	// Fundo do banner. border_width é 0 no painel, então a borda acompanha o fundo.
	'colorpalette_background'      => array(
		'color'  => $offwhite,
		'border' => $offwhite,
	),
	// Título, mensagem, categorias, X de fechar (fill=currentColor) e links.
	'colorpalette_text'            => array(
		'color'     => $dark,
		'hyperlink' => $dark,
	),
	// Toggles das categorias (só aparecem em "Personalizar").
	'colorpalette_toggles'         => array(
		'background' => $dark,
		'bullet'     => $offwhite,
		'inactive'   => $inactive,
	),
	// Aceitar — sólido escuro. Também pinta o thumb da scrollbar do banner.
	'colorpalette_button_accept'   => array(
		'background' => $dark,
		'border'     => $dark,
		'text'       => $offwhite,
	),
	// Rejeitar — outline (fundo = fundo do banner).
	'colorpalette_button_deny'     => array(
		'background' => $offwhite,
		'border'     => $dark,
		'text'       => $dark,
	),
	// Personalizar / Salvar preferências — outline.
	'colorpalette_button_settings' => array(
		'background' => $offwhite,
		'border'     => $dark,
		'text'       => $dark,
	),
);

if ( ! class_exists( 'CMPLZ_COOKIEBANNER' ) ) {
	WP_CLI::error( 'Classe CMPLZ_COOKIEBANNER não encontrada — o Complianz está ativo neste blog?' );
}

$banners = cmplz_get_cookiebanners();
if ( empty( $banners ) ) {
	WP_CLI::error( 'Nenhum cookie banner encontrado neste blog.' );
}

WP_CLI::log( sprintf(
	"Blog %d (%s) — %d banner(s) — modo: %s",
	get_current_blog_id(),
	home_url(),
	count( $banners ),
	$apply ? 'APLICAR' : 'DRY-RUN'
) );

foreach ( $banners as $row ) {
	$banner = new CMPLZ_COOKIEBANNER( $row->ID );
	WP_CLI::log( sprintf( "\n  Banner #%d \"%s\" (version %d)", $banner->ID, $banner->title, $banner->banner_version ) );

	$changed = false;
	foreach ( $palette as $field => $wanted ) {
		$current = is_array( $banner->{$field} ) ? $banner->{$field} : array();
		foreach ( $wanted as $key => $value ) {
			$before = $current[ $key ] ?? '(vazio)';
			if ( strcasecmp( (string) $before, $value ) === 0 ) {
				WP_CLI::log( sprintf( '    = %-34s %s', "$field.$key", $value ) );
				continue;
			}
			WP_CLI::log( sprintf( '    ~ %-34s %s -> %s', "$field.$key", $before, $value ) );
			$current[ $key ] = $value;
			$changed         = true;
		}
		$banner->{$field} = $current;
	}

	if ( ! $changed ) {
		WP_CLI::log( '    nada a fazer (já na paleta da marca)' );
		continue;
	}

	if ( ! $apply ) {
		WP_CLI::log( '    DRY-RUN — nada gravado. Rode com CMPLZ_PALETTE_APPLY=1' );
		continue;
	}

	$banner->save(); // save() já chama generate_css() no final
	WP_CLI::success( sprintf( 'Banner #%d gravado e CSS regenerado (version %d).', $banner->ID, $banner->banner_version ) );
}
