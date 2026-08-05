<?php
/**
 * set-complianz-palette.php — aplica a paleta da marca no PAINEL do Complianz
 *
 * Autor : Daniel Cambría — Bureau de Tecnologia
 * Data  : 2026-08-05
 * Versão: 1.1.0
 *
 * CONTEXTO
 *   Até 2026-08-03 as cores do cookie banner vinham do child theme
 *   (css/plugins/complianz.css) com !important, ignorando o que estava
 *   configurado em Complianz → Cookie Banner → Aparência. O painel do plugin
 *   passa a ser a FONTE DA VERDADE das cores do banner; o tema só cuida dos
 *   estados de hover (que o painel não expõe) e os deriva das próprias
 *   variáveis --cmplz_* geradas pelo plugin.
 *
 *   Este script grava no painel a paleta da marca e dispara a regeneração do
 *   CSS do plugin. É idempotente: rodar duas vezes não muda nada além de
 *   incrementar banner_version.
 *
 * POR QUE A PALETA É LIDA DO KIT (v1.1.0 — 2026-08-05)
 *   A v1.0.0 tinha a paleta HARDCODED (#21191B / #F8EAD9 / #392E34 / #FE78A9),
 *   copiada de uma versão antiga do kit. Quando os Global Colors migraram da
 *   paleta quente para a neutra (#1C1C1C / #DADADA / #474747 / #000000), o
 *   tema acompanhou sozinho — ele usa var(--e-global-color-*) — mas o banner
 *   NÃO, porque o Complianz grava hex LITERAL no banco. Resultado: o banner
 *   ficou bege num site preto-e-cinza, sem nenhuma cor bege na paleta.
 *
 *   Para não repetir, a paleta agora é DERIVADA em runtime dos system_colors
 *   do kit Elementor ativo do blog atual. Rodar este script após qualquer
 *   mudança de Global Colors é o que mantém o banner em dia — ver a regra
 *   "Fonte da verdade: Global Colors do Elementor" em wordpress/CLAUDE.md.
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
 * Paleta — LIDA do kit Elementor ativo do blog atual (não hardcode).
 *
 * Mapeamento: primary -> fundo sólido do Aceitar e bordas
 *             secondary ("Offwhite") -> fundo do banner
 *             text -> mensagem, título, links
 * ------------------------------------------------------------------------ */
$kit_id = (int) get_option( 'elementor_active_kit' );
if ( ! $kit_id ) {
	WP_CLI::error( 'Kit Elementor ativo não encontrado neste blog (option elementor_active_kit).' );
}

$kit_settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
if ( empty( $kit_settings['system_colors'] ) || ! is_array( $kit_settings['system_colors'] ) ) {
	WP_CLI::error( sprintf( 'Kit #%d não tem system_colors — paleta indisponível.', $kit_id ) );
}

$kit_colors = array();
foreach ( $kit_settings['system_colors'] as $c ) {
	if ( isset( $c['_id'], $c['color'] ) ) {
		$kit_colors[ $c['_id'] ] = strtoupper( $c['color'] );
	}
}

foreach ( array( 'primary', 'secondary', 'text' ) as $slot ) {
	if ( empty( $kit_colors[ $slot ] ) ) {
		WP_CLI::error( sprintf( 'Kit #%d não define o Global Color "%s".', $kit_id, $slot ) );
	}
}

/**
 * Escurece um hex por um percentual — usado só no toggle desligado, que não
 * tem slot próprio no kit. Mantém a relação da paleta anterior (~22% abaixo
 * do fundo do banner), então o "off" continua legível sem virar quase-preto.
 */
$darken = static function ( $hex, $pct ) {
	$hex = ltrim( (string) $hex, '#' );
	if ( strlen( $hex ) !== 6 ) {
		return '#' . $hex;
	}
	$out = '#';
	foreach ( array( 0, 2, 4 ) as $i ) {
		$v    = (int) round( hexdec( substr( $hex, $i, 2 ) ) * ( 1 - $pct ) );
		$out .= str_pad( dechex( max( 0, min( 255, $v ) ) ), 2, '0', STR_PAD_LEFT );
	}
	return strtoupper( $out );
};

$dark     = $kit_colors['primary'];    // Main color
$offwhite = $kit_colors['secondary'];  // Offwhite
$text     = $kit_colors['text'];       // Text
$inactive = $darken( $offwhite, 0.22 ); // toggle desligado — sem slot no kit

WP_CLI::log( sprintf(
	"Paleta lida do kit #%d: primary=%s secondary=%s text=%s (toggle off derivado=%s)",
	$kit_id, $dark, $offwhite, $text, $inactive
) );

$palette = array(
	// Fundo do banner. border_width é 0 no painel, então a borda acompanha o fundo.
	'colorpalette_background'      => array(
		'color'  => $offwhite,
		'border' => $offwhite,
	),
	// Título, mensagem, categorias, X de fechar (fill=currentColor) e links.
	'colorpalette_text'            => array(
		'color'     => $text,
		'hyperlink' => $text,
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
		'text'       => $text,
	),
	// Personalizar / Salvar preferências — outline.
	'colorpalette_button_settings' => array(
		'background' => $offwhite,
		'border'     => $dark,
		'text'       => $text,
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
