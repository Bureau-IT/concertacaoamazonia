<?php
/**
 * Plugin Name: Bureau A11y
 * Description: Acessibilidade profissional: mini-app com tabs, grid de cards, lupa, libras, modo dislexia, filtros de cor, régua de leitura, TTS e logo Bureau IT.
 * Version: 2.6.1
 * Author: Bureau de Tecnologia Ltda.
 *
 * @package BureauA11y
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BUREAU_A11Y_VERSION', '2.6.1' );
define( 'BUREAU_A11Y_CSS_VERSION', '2.5.22' );
define( 'BUREAU_A11Y_JS_VERSION', '2.5.25' );
define( 'BUREAU_A11Y_RV_KEY', 'rS4GfS4a' );
define( 'BUREAU_A11Y_DIR', __DIR__ . '/bureau-a11y/' );
define( 'BUREAU_A11Y_URL', plugin_dir_url( __FILE__ ) . 'bureau-a11y/' );

/**
 * Enqueue CSS e JS assets
 *
 * Plus Jakarta Sans é self-hosted no tema child desde 2026-05-02 (variable font subset
 * Latin, ~27KB). Spec: docs/superpowers/specs/2026-05-02-self-host-plus-jakarta-sans-design.md
 * Removido preconnect/enqueue para fonts.googleapis.com — site não depende mais de CDN externo.
 */
add_action( 'wp_enqueue_scripts', 'bureau_a11y_enqueue_assets' );
function bureau_a11y_enqueue_assets() {
	if ( is_admin() ) {
		return;
	}

	wp_enqueue_style(
		'bureau-a11y',
		BUREAU_A11Y_URL . 'bureau-a11y.css',
		[],
		BUREAU_A11Y_CSS_VERSION
	);

	wp_add_inline_style( 'bureau-a11y',
		'#bureau-a11y-panel { z-index: 2147483647 !important; overflow: hidden !important; }' .
		'html > body > header { z-index: 999999 !important; }'
	);

	wp_enqueue_script(
		'bureau-a11y',
		BUREAU_A11Y_URL . 'bureau-a11y.js',
		[],
		BUREAU_A11Y_JS_VERSION,
		true
	);

	wp_localize_script( 'bureau-a11y', 'bureauA11y', [
		'rvKey' => BUREAU_A11Y_RV_KEY,
		'lang'  => get_locale(),
	] );
}

/**
 * Render the a11y panel HTML no footer
 */
add_action( 'wp_footer', 'bureau_a11y_render_buttons', 50 );
function bureau_a11y_render_buttons() {
	if ( is_admin() ) {
		return;
	}
	?>
	<!-- Bureau A11y v2.5.10 -->
	<div id="bureau-a11y-ruler" aria-hidden="true"></div>

	<button
		id="bureau-a11y-trigger"
		aria-label="<?php esc_attr_e( 'Abrir painel de acessibilidade', 'bureau-a11y' ); ?>"
		aria-expanded="false"
		aria-controls="bureau-a11y-panel"
	>
		<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" aria-hidden="true" focusable="false">
			<path d="M256,112a56,56,0,1,1,56-56A56.06,56.06,0,0,1,256,112Z"/>
			<path d="M432,112.8l-.45.12h0l-.42.13c-1,.28-2,.58-3,.89-18.61,5.46-108.93,30.92-172.56,30.92-59.13,0-141.28-22-167.56-29.47a73.79,73.79,0,0,0-8-2.58c-19-5-32,14.3-32,31.94,0,17.47,15.7,25.79,31.55,31.76v.28l95.22,29.74c9.73,3.73,12.33,7.54,13.6,10.84,4.13,10.59.83,31.56-.34,38.88l-5.8,45L150.05,477.44q-.15.72-.27,1.47l-.23,1.27h0c-2.32,16.15,9.54,31.82,32,31.82,19.6,0,28.25-13.53,32-31.94h0s28-157.57,42-157.57,42.84,157.57,42.84,157.57h0c3.75,18.41,12.4,31.94,32,31.94,22.52,0,34.38-15.74,32-31.94-.21-1.38-.46-2.74-.76-4.06L329,301.27l-5.79-45c-4.19-26.21-.82-34.87.32-36.9a1.09,1.09,0,0,0,.08-.15c1.08-2,6-6.48,17.48-10.79l89.28-31.21a16.9,16.9,0,0,0,1.62-.52c16-6,32-14.3,32-31.93S451,107.81,432,112.8Z"/>
		</svg>
	</button>

	<button
		id="bureau-a11y-back-to-top"
		aria-label="<?php esc_attr_e( 'Voltar ao topo', 'bureau-a11y' ); ?>"
		data-id="a11yBackToTopButton"
	>
		<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 115.4 122.88" aria-hidden="true" focusable="false">
			<path d="M24.94 67.88A14.66 14.66 0 0 1 4.38 47L47.83 4.21a14.66 14.66 0 0 1 20.56 0L111 46.15a14.66 14.66 0 0 1-20.54 20.91l-18-17.69-.29 59.17c-.1 19.28-29.42 19-29.33-.25l.3-58.29-18.2 17.88Z"/>
		</svg>
	</button>

	<aside
		id="bureau-a11y-panel"
		role="dialog"
		aria-label="<?php esc_attr_e( 'Painel de Acessibilidade', 'bureau-a11y' ); ?>"
		inert
		translate="no"
		class="notranslate"
	>
		<!-- Header -->
		<div class="ba-panel__header" id="bureau-a11y-drag-handle"
			title="<?php esc_attr_e( 'Arraste para mover · Alt+Setas para mover por teclado', 'bureau-a11y' ); ?>"
			aria-label="<?php esc_attr_e( 'Cabeçalho do painel — arraste ou use Alt+Setas para mover', 'bureau-a11y' ); ?>">
			<span class="ba-panel__title">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="20" height="20" aria-hidden="true">
					<path d="M256,112a56,56,0,1,1,56-56A56.06,56.06,0,0,1,256,112Z"/>
					<path d="M432,112.8l-.45.12h0l-.42.13c-1,.28-2,.58-3,.89-18.61,5.46-108.93,30.92-172.56,30.92-59.13,0-141.28-22-167.56-29.47a73.79,73.79,0,0,0-8-2.58c-19-5-32,14.3-32,31.94,0,17.47,15.7,25.79,31.55,31.76v.28l95.22,29.74c9.73,3.73,12.33,7.54,13.6,10.84,4.13,10.59.83,31.56-.34,38.88l-5.8,45L150.05,477.44q-.15.72-.27,1.47l-.23,1.27h0c-2.32,16.15,9.54,31.82,32,31.82,19.6,0,28.25-13.53,32-31.94h0s28-157.57,42-157.57,42.84,157.57,42.84,157.57h0c3.75,18.41,12.4,31.94,32,31.94,22.52,0,34.38-15.74,32-31.94-.21-1.38-.46-2.74-.76-4.06L329,301.27l-5.79-45c-4.19-26.21-.82-34.87.32-36.9a1.09,1.09,0,0,0,.08-.15c1.08-2,6-6.48,17.48-10.79l89.28-31.21a16.9,16.9,0,0,0,1.62-.52c16-6,32-14.3,32-31.93S451,107.81,432,112.8Z"/>
				</svg>
				<?php esc_html_e( 'Acessibilidade', 'bureau-a11y' ); ?>
			</span>
			<div class="ba-header-actions">
				<button class="ba-hints-btn" id="ba-hints-toggle" aria-pressed="true"
					aria-label="<?php esc_attr_e( 'Mostrar ou ocultar descrições de ajuda', 'bureau-a11y' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="12" cy="12" r="10"/>
						<path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
						<line x1="12" y1="17" x2="12.01" y2="17"/>
					</svg>
				</button>
				<button class="ba-panel__close" id="bureau-a11y-close" aria-label="<?php esc_attr_e( 'Fechar painel', 'bureau-a11y' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
						<path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" fill="none"/>
					</svg>
				</button>
			</div>
		</div>

		<!-- Widget superdimensionado -->
		<div class="ba-widget-size-row">
			<span class="ba-widget-size-label"><?php esc_html_e( 'Painel ampliado', 'bureau-a11y' ); ?></span>
			<button id="ba-widget-size-btn" class="ba-switch" role="switch" aria-pressed="false"
				aria-label="<?php esc_attr_e( 'Ampliar o painel de acessibilidade', 'bureau-a11y' ); ?>">
				<span class="ba-switch__thumb"></span>
			</button>
		</div>

		<!-- Tab Bar -->
		<nav class="ba-tab-bar" role="tablist" aria-label="<?php esc_attr_e( 'Categorias de acessibilidade', 'bureau-a11y' ); ?>">
			<button class="ba-tab-btn" role="tab" data-tab="visual"
				aria-selected="true" tabindex="0"
				id="ba-tab-btn-visual" aria-controls="ba-tabpanel-visual">
				<span class="ba-tab-icon">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
						<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
					</svg>
				</span>
				<?php esc_html_e( 'Visual', 'bureau-a11y' ); ?>
			</button>
			<button class="ba-tab-btn" role="tab" data-tab="contraste"
				aria-selected="false" tabindex="-1"
				id="ba-tab-btn-contraste" aria-controls="ba-tabpanel-contraste">
				<span class="ba-tab-icon">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="13.5" cy="6.5" r="1" fill="currentColor"/><circle cx="17.5" cy="10.5" r="1" fill="currentColor"/><circle cx="8.5" cy="7.5" r="1" fill="currentColor"/><circle cx="6.5" cy="12.5" r="1" fill="currentColor"/>
						<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>
					</svg>
				</span>
				<?php esc_html_e( 'Cores', 'bureau-a11y' ); ?>
			</button>
			<button class="ba-tab-btn" role="tab" data-tab="leitura"
				aria-selected="false" tabindex="-1"
				id="ba-tab-btn-leitura" aria-controls="ba-tabpanel-leitura">
				<span class="ba-tab-icon">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
						<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
					</svg>
				</span>
				<?php esc_html_e( 'Leitura', 'bureau-a11y' ); ?>
			</button>
		</nav>

		<!-- Tab: VISUAL -->
		<div class="ba-tab-content is-active" data-tab="visual" role="tabpanel"
			id="ba-tabpanel-visual" aria-labelledby="ba-tab-btn-visual" tabindex="0">
			<div class="ba-features-grid">

				<!-- Zoom Stepper -->
				<div class="ba-feature--full">
					<div class="ba-zoom-wrapper">
						<span class="ba-zoom-label"><?php esc_html_e( 'Tamanho do texto', 'bureau-a11y' ); ?></span>
						<div class="ba-zoom-stepper">
							<button class="ba-zoom-btn" data-action="zoom-out" aria-label="<?php esc_attr_e( 'Diminuir texto', 'bureau-a11y' ); ?>">A−</button>
							<span class="ba-zoom-value" id="ba-zoom-value">100%</span>
							<button class="ba-zoom-btn" data-action="zoom-in" aria-label="<?php esc_attr_e( 'Aumentar texto', 'bureau-a11y' ); ?>">A+</button>
						</div>
					</div>
				</div>

				<!-- TODO: reimplementar Lupa de cursor com melhorias de acessibilidade e performance -->

				<!-- Sem imagens -->
				<button class="ba-toggle" id="ba-toggle-hideImages" data-feature="hideImages"
					data-tooltip="<?php esc_attr_e( 'Oculta imagens e vídeos', 'bureau-a11y' ); ?>"
					data-desc="<?php esc_attr_e( 'Oculta todas as imagens e vídeos da página, mantendo apenas o conteúdo textual. Útil para reduzir distrações visuais ou carregar páginas em conexões lentas.', 'bureau-a11y' ); ?>"
					aria-pressed="false">
					<span class="ba-toggle__icon">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="currentColor">
							<path d="M7.828 5l-1-1H22v15.172l-1-1v-.69l-3.116-3.117-.395.296-.714-.714.854-.64a.503.503 0 0 1 .657.046L21 16.067V5zM3 20v-.519l2.947-2.947a1.506 1.506 0 0 0 .677.163 1.403 1.403 0 0 0 .997-.415l2.916-2.916-.706-.707-2.916 2.916a.474.474 0 0 1-.678-.048.503.503 0 0 0-.704.007L3 18.067V5.828l-1-1V21h16.172l-1-1zM17 8.5A1.5 1.5 0 1 1 15.5 7 1.5 1.5 0 0 1 17 8.5zm-1 0a.5.5 0 1 0-.5.5.5.5 0 0 0 .5-.5zm5.646 13.854l.707-.707-20-20-.707.707z"/>
						</svg>
					</span>
					<span class="ba-toggle__label"><?php esc_html_e( 'Sem imagens', 'bureau-a11y' ); ?></span>
				</button>

				<!-- Sem animações -->
				<button class="ba-toggle" id="ba-toggle-stopAnimations" data-feature="stopAnimations"
					data-tooltip="<?php esc_attr_e( 'Para todas as animações e transições', 'bureau-a11y' ); ?>"
					data-desc="<?php esc_attr_e( 'Para todas as animações, transições e efeitos de rolagem da página. Recomendado para pessoas com epilepsia fotossensível, enxaqueca ou ansiedade visual.', 'bureau-a11y' ); ?>"
					aria-pressed="false">
					<span class="ba-toggle__icon">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
							<circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/>
						</svg>
					</span>
					<span class="ba-toggle__label"><?php esc_html_e( 'Sem animações', 'bureau-a11y' ); ?></span>
				</button>

	
				<!-- Cursor Grande -->
				<button class="ba-toggle" id="ba-toggle-largerCursor" data-feature="largerCursor"
					data-tooltip="<?php esc_attr_e( 'Aumenta o tamanho do cursor', 'bureau-a11y' ); ?>"
					data-desc="<?php esc_attr_e( 'Exibe um cursor de mouse maior em toda a página, melhorando a visibilidade do ponteiro para pessoas com baixa visão.', 'bureau-a11y' ); ?>"
					aria-pressed="false">
					<span class="ba-toggle__icon">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="24" height="24" aria-hidden="true" focusable="false">
							<path d="M6 4L6 36L13.5 28.5L17.5 41L25.5 38L21.5 25.5L34 25.5Z" fill="currentColor" stroke="currentColor" stroke-width="1" stroke-linejoin="round"/>
						</svg>
					</span>
					<span class="ba-toggle__label"><?php esc_html_e( 'Cursor grande', 'bureau-a11y' ); ?></span>
				</button>

			</div>
		</div>

		<!-- Tab: CONTRASTE -->
		<div class="ba-tab-content" data-tab="contraste" role="tabpanel"
			id="ba-tabpanel-contraste" aria-labelledby="ba-tab-btn-contraste" tabindex="0">
			<div class="ba-features-grid">

				<!-- Alto Contraste -->
				<button class="ba-toggle" id="ba-toggle-highContrast" data-feature="highContrast"
					data-tooltip="<?php esc_attr_e( 'Fundo preto, texto branco', 'bureau-a11y' ); ?>"
					data-desc="<?php esc_attr_e( 'Aplica fundo preto com texto branco de máximo contraste. Indicado para baixa visão, daltonismo severo e ambientes com muita luz.', 'bureau-a11y' ); ?>"
					aria-pressed="false">
					<span class="ba-toggle__icon">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="26" height="26" aria-hidden="true" focusable="false" fill="currentColor">
							<path d="M128,64.26A63.74,63.74,0,1,0,191.74,128,63.7,63.7,0,0,0,128,64.26Zm5,117.29V74.45a53.78,53.78,0,0,1,0,107.1Z"/>
						</svg>
					</span>
					<span class="ba-toggle__label"><?php esc_html_e( 'Alto Contraste', 'bureau-a11y' ); ?></span>
				</button>

				<!-- TODO: reimplementar Dark Mode como feature nativa do tema (não via CSS filter) -->

				<!-- Escala de Cinza -->
				<button class="ba-toggle" id="ba-toggle-grayscale" data-feature="grayscale"
					data-tooltip="<?php esc_attr_e( 'Remove todas as cores', 'bureau-a11y' ); ?>"
					data-desc="<?php esc_attr_e( 'Remove todas as cores da página, exibindo apenas tons de cinza. Auxilia quem tem dificuldade em distinguir cores específicas.', 'bureau-a11y' ); ?>"
					aria-pressed="false">
					<span class="ba-toggle__icon">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 26 26" width="26" height="26" fill="none" aria-hidden="true" focusable="false">
							<circle cx="5.5" cy="13" r="4" fill="currentColor"/>
							<circle cx="13" cy="13" r="4" fill="currentColor" opacity="0.45"/>
							<circle cx="20.5" cy="13" r="4" stroke="currentColor" stroke-width="1.5"/>
						</svg>
					</span>
					<span class="ba-toggle__label"><?php esc_html_e( 'Escala de Cinza', 'bureau-a11y' ); ?></span>
				</button>


				<!-- Daltonismo -->
				<div class="ba-feature--full">
					<span class="ba-cb-label"><?php esc_html_e( 'Daltonismo', 'bureau-a11y' ); ?></span>
					<div class="ba-cb-group">
						<button class="ba-toggle" id="ba-toggle-cb-protanopia"
							data-feature="colorBlind" data-cb-type="protanopia"
							data-tooltip="<?php esc_attr_e( 'Deficiência em vermelho', 'bureau-a11y' ); ?>"
							aria-pressed="false">
							<span class="ba-toggle__icon">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 26 26" width="26" height="26" fill="none" aria-hidden="true" focusable="false">
									<path d="M2 13 C4.5 7 8.5 5 13 5 C17.5 5 21.5 7 24 13 C21.5 19 17.5 21 13 21 C8.5 21 4.5 19 2 13 Z" stroke="currentColor" stroke-width="1.5"/>
									<circle cx="13" cy="13" r="3" fill="currentColor"/>
									<polygon points="5,3 2,9 8,9" fill="currentColor"/>
								</svg>
							</span>
							<span class="ba-toggle__label"><?php esc_html_e( 'Protanopia', 'bureau-a11y' ); ?></span>
						</button>
						<button class="ba-toggle" id="ba-toggle-cb-deuteranopia"
							data-feature="colorBlind" data-cb-type="deuteranopia"
							data-tooltip="<?php esc_attr_e( 'Deficiência em verde', 'bureau-a11y' ); ?>"
							aria-pressed="false">
							<span class="ba-toggle__icon">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 26 26" width="26" height="26" fill="none" aria-hidden="true" focusable="false">
									<path d="M2 13 C4.5 7 8.5 5 13 5 C17.5 5 21.5 7 24 13 C21.5 19 17.5 21 13 21 C8.5 21 4.5 19 2 13 Z" stroke="currentColor" stroke-width="1.5"/>
									<circle cx="13" cy="13" r="3" fill="currentColor"/>
									<polygon points="13,2 10,8 16,8" fill="currentColor"/>
								</svg>
							</span>
							<span class="ba-toggle__label"><?php esc_html_e( 'Deuteranopia', 'bureau-a11y' ); ?></span>
						</button>
						<button class="ba-toggle" id="ba-toggle-cb-tritanopia"
							data-feature="colorBlind" data-cb-type="tritanopia"
							data-tooltip="<?php esc_attr_e( 'Deficiência em azul', 'bureau-a11y' ); ?>"
							aria-pressed="false">
							<span class="ba-toggle__icon">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 26 26" width="26" height="26" fill="none" aria-hidden="true" focusable="false">
									<path d="M2 13 C4.5 7 8.5 5 13 5 C17.5 5 21.5 7 24 13 C21.5 19 17.5 21 13 21 C8.5 21 4.5 19 2 13 Z" stroke="currentColor" stroke-width="1.5"/>
									<circle cx="13" cy="13" r="3" fill="currentColor"/>
									<polygon points="21,3 18,9 24,9" fill="currentColor"/>
								</svg>
							</span>
							<span class="ba-toggle__label"><?php esc_html_e( 'Tritanopia', 'bureau-a11y' ); ?></span>
						</button>
					</div>
				</div>

			</div>
		</div>

		<!-- Tab: LEITURA -->
		<div class="ba-tab-content" data-tab="leitura" role="tabpanel"
			id="ba-tabpanel-leitura" aria-labelledby="ba-tab-btn-leitura" tabindex="0">
			<div class="ba-features-grid">

				<!-- Fonte disléxica -->
				<button class="ba-toggle" id="ba-toggle-dyslexicFont" data-feature="dyslexicFont"
					data-tooltip="<?php esc_attr_e( 'Fonte OpenDyslexic para leitura', 'bureau-a11y' ); ?>"
					data-desc="<?php esc_attr_e( 'Substitui a tipografia da página pela fonte OpenDyslexic, desenvolvida especificamente para facilitar a leitura de pessoas com dislexia.', 'bureau-a11y' ); ?>"
					aria-pressed="false">
					<span class="ba-toggle__icon">Aa</span>
					<span class="ba-toggle__label"><?php esc_html_e( 'Fonte disléxica', 'bureau-a11y' ); ?></span>
				</button>

				<!-- Espaçamento -->
				<button class="ba-toggle" id="ba-toggle-textSpacing" data-feature="textSpacing"
					data-tooltip="<?php esc_attr_e( 'Aumenta espaçamento entre letras e linhas', 'bureau-a11y' ); ?>"
					data-desc="<?php esc_attr_e( 'Aumenta o espaçamento entre letras, palavras e linhas de texto. Melhora significativamente a legibilidade para dislexia, baixa visão e cansaço visual.', 'bureau-a11y' ); ?>"
					aria-pressed="false">
					<span class="ba-toggle__icon">↔</span>
					<span class="ba-toggle__label"><?php esc_html_e( 'Espaçamento', 'bureau-a11y' ); ?></span>
				</button>

				<!-- TTS -->
				<div class="ba-feature--full">
					<div class="ba-tts-wrapper">
						<button class="ba-toggle ba-tts-hover-btn" id="ba-toggle-ttsHoverMode" data-feature="ttsHoverMode"
							aria-pressed="false"
							data-tooltip="<?php esc_attr_e( 'Passe o mouse sobre qualquer parágrafo para ouvir o texto', 'bureau-a11y' ); ?>"
							data-desc="<?php esc_attr_e( 'Lê em voz alta qualquer texto ao passar o cursor sobre ele. Pressione ESC para sair do modo leitura.', 'bureau-a11y' ); ?>">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
								<rect x="9" y="2" width="6" height="11" rx="3"/>
								<path d="M19 10a7 7 0 0 1-14 0"/>
								<line x1="12" y1="19" x2="12" y2="22"/>
								<line x1="8" y1="22" x2="16" y2="22"/>
							</svg>
							<span class="ba-toggle__label"><?php esc_html_e( 'Falar o texto', 'bureau-a11y' ); ?></span>
						</button>
						<div class="ba-tts-options">
							<div class="ba-tts-options-row">
								<span class="ba-label"><?php esc_html_e( 'Voz', 'bureau-a11y' ); ?></span>
								<div class="ba-pill-group">
									<input type="radio" id="ba-tts-voice-female" name="ba-tts-voice" value="female" checked autocomplete="off">
									<label for="ba-tts-voice-female" class="ba-pill">♀ <?php esc_html_e( 'Feminina', 'bureau-a11y' ); ?></label>
									<input type="radio" id="ba-tts-voice-male" name="ba-tts-voice" value="male" autocomplete="off">
									<label for="ba-tts-voice-male" class="ba-pill">♂ <?php esc_html_e( 'Masculina', 'bureau-a11y' ); ?></label>
								</div>
							</div>
							<div class="ba-tts-options-row">
								<label class="ba-label" for="ba-tts-rate"><?php esc_html_e( 'Vel.', 'bureau-a11y' ); ?></label>
								<input id="ba-tts-rate" class="ba-slider" type="range" min="0.5" max="1.5" step="0.05" value="1.25"
									aria-label="<?php esc_attr_e( 'Velocidade da voz', 'bureau-a11y' ); ?>">
							</div>
						</div>
						<div class="ba-tts-attribution">
							<img src="<?php echo esc_url( BUREAU_A11Y_URL . 'resvoice-logo.svg' ); ?>" alt="ResponsiveVoice" class="ba-tts-rv-logo" aria-hidden="true" loading="lazy">
						</div>
					</div>
				</div>

				<!-- Régua de leitura -->
				<button class="ba-toggle" id="ba-toggle-readingRuler" data-feature="readingRuler"
					data-tooltip="<?php esc_attr_e( 'Linha guia que segue o cursor', 'bureau-a11y' ); ?>"
					data-desc="<?php esc_attr_e( 'Exibe uma faixa horizontal colorida que segue o cursor verticalmente, ajudando a não perder a linha durante a leitura de textos longos.', 'bureau-a11y' ); ?>"
					aria-pressed="false">
					<span class="ba-toggle__icon">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
							<path d="M21.3 8.7 8.7 21.3c-1 1-2.5 1-3.4 0l-2.6-2.6c-1-1-1-2.5 0-3.4L15.3 2.7c1-1 2.5-1 3.4 0l2.6 2.6c1 1 1 2.5 0 3.4z"/>
							<path d="m7.5 10.5 2 2"/><path d="m10.5 7.5 2 2"/><path d="m13.5 4.5 2 2"/>
						</svg>
					</span>
					<span class="ba-toggle__label"><?php esc_html_e( 'Régua de leitura', 'bureau-a11y' ); ?></span>
				</button>

				<!-- Destacar links -->
				<button class="ba-toggle" id="ba-toggle-highlightLinks" data-feature="highlightLinks"
					data-tooltip="<?php esc_attr_e( 'Destaca todos os links da página', 'bureau-a11y' ); ?>"
					data-desc="<?php esc_attr_e( 'Contorna todos os links da página com uma borda verde e fundo suave, facilitando sua identificação e distinção do texto comum.', 'bureau-a11y' ); ?>"
					aria-pressed="false">
					<span class="ba-toggle__icon">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
							<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
							<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
						</svg>
					</span>
					<span class="ba-toggle__label"><?php esc_html_e( 'Destacar links', 'bureau-a11y' ); ?></span>
				</button>

				<!-- Guia de foco -->
				<button class="ba-toggle" id="ba-toggle-focusGuide" data-feature="focusGuide"
					data-tooltip="<?php esc_attr_e( 'Indicador visual de foco por teclado', 'bureau-a11y' ); ?>"
					data-desc="<?php esc_attr_e( 'Realça com contorno azul vibrante o elemento atualmente focado pelo teclado. Essencial para quem navega sem mouse, usando Tab e setas.', 'bureau-a11y' ); ?>"
					aria-pressed="false">
					<span class="ba-toggle__icon">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
							<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M8 14h8"/>
						</svg>
					</span>
					<span class="ba-toggle__label"><?php esc_html_e( 'Guia de foco', 'bureau-a11y' ); ?></span>
				</button>

				<!-- Libras (VLibras) — ao lado do Guia de foco -->
				<button class="ba-toggle ba-libras-card" id="ba-toggle-libras" data-feature="libras"
					data-tooltip="<?php esc_attr_e( 'Abre o painel de tradução em Libras', 'bureau-a11y' ); ?>"
					data-desc="<?php esc_attr_e( 'Ativa o assistente de tradução para Língua Brasileira de Sinais (Libras) via VLibras — plataforma do Governo Federal.', 'bureau-a11y' ); ?>"
					aria-pressed="false">
					<span class="ba-toggle__icon">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40" width="48" height="48" aria-hidden="true" focusable="false" fill="none">
							<path d="M14.3515 8.00885C14.2659 8.02229 14.0952 8.12725 13.9722 8.24213C13.7501 8.44966 13.7489 8.4543 13.7954 8.95863C13.8212 9.23785 13.873 9.84616 13.9104 10.3105C14.2022 13.9301 14.2501 15.014 14.1218 15.0933C13.906 15.2267 13.787 15.0254 11.7744 11.1229C11.0845 9.7851 10.953 9.64412 10.5264 9.78488C9.84976 10.0082 9.90301 10.3457 10.984 12.685C11.1227 12.9851 11.2361 13.2431 11.2361 13.2584C11.2361 13.2738 11.3578 13.5507 11.5068 13.8737C12.3139 15.6253 12.5242 16.3377 12.2637 16.4377C12.0791 16.5085 11.7503 16.3324 11.48 16.0181C11.3425 15.8582 11.1733 15.664 11.1041 15.5866C11.0348 15.5093 10.8383 15.2718 10.6674 15.059C10.4965 14.8462 10.3404 14.6563 10.3205 14.6369C10.3006 14.6176 9.97505 14.2328 9.59714 13.7818C9.21915 13.3308 8.86093 12.943 8.80099 12.92C8.45635 12.7878 8 13.0487 8 13.3779C8 13.6653 8.6909 14.8215 10.129 16.9405C11.0303 18.2684 11.2421 18.7465 11.4461 19.9131C11.8781 22.3846 12.466 23.8997 13.2266 24.5023C13.7444 24.9126 13.759 24.9156 14.9781 24.8635L16.0946 24.8158L16.943 23.6835C17.4096 23.0607 17.828 22.5037 17.8729 22.4456C18.1983 22.0239 19.0448 20.5299 19.0448 20.3774C19.0448 20.3556 18.6049 20.3579 18.0673 20.3823C17.4053 20.4125 16.9455 20.3987 16.6427 20.3399C16.1178 20.2377 15.4567 19.9369 15.094 19.6351C14.5216 19.1588 14.3534 18.3134 14.7102 17.7047C14.9767 17.2499 15.3462 17.0548 16.1229 16.9585C16.7506 16.8808 17.4221 16.6978 18.3941 16.3399L18.9041 16.1521V15.3306C18.9041 14.489 19.0647 11.5539 19.1866 10.1698C19.2823 9.08146 19.2839 9.09328 19.0167 8.8261C18.8321 8.64157 18.7189 8.58691 18.5211 8.58691C18.0228 8.58691 17.9956 8.65571 17.5021 11.1555C17.2578 12.3932 17.0138 13.6591 16.9598 13.9686C16.842 14.6442 16.7495 14.8932 16.5939 14.9529C16.3906 15.031 16.2003 14.7126 16.1089 14.1418C16.0627 13.8531 15.9423 13.0945 15.8413 12.4561C15.6543 11.2736 15.2804 9.05832 15.2005 8.6586C15.1525 8.41877 14.8376 8.05999 14.6342 8.01349C14.5644 7.99752 14.4372 7.99541 14.3515 8.00885ZM23.723 15.2495C23.41 15.3238 22.5004 15.6532 22.0856 15.8425C21.8429 15.9533 21.6258 16.0439 21.6033 16.0439C21.5808 16.0439 20.988 16.3278 20.286 16.6749C18.8037 17.4076 17.0871 18.0136 16.4932 18.0138C16.0849 18.0139 15.6455 18.1946 15.6116 18.3762C15.5739 18.5794 16.0014 18.9814 16.4285 19.1441C16.8072 19.2883 16.9061 19.2943 18.2628 19.2542C19.4753 19.2186 19.7325 19.2289 19.9271 19.3213C20.2619 19.4801 20.3539 19.7508 20.243 20.2514C20.13 20.7618 19.5007 22.024 18.9935 22.7575C18.6639 23.2342 16.7021 25.845 15.5625 27.3235C15.1558 27.8512 15.1376 28.12 15.4851 28.4676C15.9352 28.9176 15.8771 28.9577 18.2957 26.5266C20.5532 24.2577 20.7416 24.1089 20.8437 24.5159C20.9058 24.7631 20.92 24.727 19.9164 26.8681C19.7465 27.2303 19.6076 27.5367 19.6076 27.5488C19.6076 27.561 19.493 27.8185 19.353 28.1212C18.2031 30.607 18.1562 30.774 18.489 31.197C18.608 31.3484 18.6899 31.3796 18.962 31.3778C19.1429 31.3766 19.3398 31.337 19.3994 31.2898C19.459 31.2427 19.8045 30.666 20.1673 30.0082C20.8028 28.8558 21.1759 28.1882 21.492 27.6375C22.6596 25.6031 22.6539 25.6114 22.8653 25.6114C23.0004 25.6114 23.0041 25.6442 22.9623 26.4732C22.9242 27.2301 22.8281 28.1321 22.5632 30.2193C22.4631 31.0073 22.4759 31.6696 22.5942 31.8257C22.7334 32.0094 23.2169 32.0613 23.4383 31.9163C23.6656 31.7673 23.7716 31.3909 24.035 29.7972C24.593 26.419 24.7329 25.7764 24.9539 25.5765C25.0901 25.4532 25.0998 25.4533 25.2421 25.5821C25.323 25.6554 25.4182 25.7695 25.4536 25.8357C25.4891 25.9018 25.5956 26.6936 25.6905 27.5952C25.9573 30.1327 25.9803 30.2641 26.1891 30.4479C26.4199 30.6511 26.6555 30.6487 26.897 30.4411C27.0809 30.2829 27.0915 30.2366 27.1524 29.3331C27.1873 28.8145 27.2018 27.7887 27.1845 27.0535C27.1509 25.6219 27.2168 24.4232 27.3521 24.0053C27.3981 23.8633 27.6176 23.3198 27.84 22.7974C28.0624 22.2751 28.3782 21.4362 28.5417 20.9332C29.0456 19.3828 29.0076 18.138 28.4388 17.5662C28.318 17.4448 27.9543 17.2033 27.6306 17.0296C27.2171 16.8078 26.828 16.5127 26.3226 16.0375C25.7453 15.4948 25.5399 15.3452 25.2827 15.2804C24.9702 15.2018 24.0047 15.1826 23.723 15.2495Z" fill="currentColor"/>
						</svg>
					</span>
					<span class="ba-toggle__label"><?php esc_html_e( 'Libras', 'bureau-a11y' ); ?></span>
				</button>


								<!-- Altura de linha -->
				<button class="ba-toggle ba-feature--full" id="ba-toggle-lineHeight" data-feature="lineHeight"
					data-tooltip="<?php esc_attr_e( 'Aumenta o espaço entre linhas de texto', 'bureau-a11y' ); ?>"
					data-desc="<?php esc_attr_e( 'Aumenta progressivamente o espaçamento entre as linhas: 1.5× → 1.7× → 2.2×. Melhora a leitura para dislexia e baixa visão.', 'bureau-a11y' ); ?>"
					aria-pressed="false" data-lh="0">
					<span class="ba-lh-visual" aria-hidden="true">
						<span class="ba-lh-bar"></span>
						<span class="ba-lh-bar"></span>
						<span class="ba-lh-bar"></span>
					</span>
					<span class="ba-toggle__label"><?php esc_html_e( 'Altura de linha', 'bureau-a11y' ); ?></span>
				</button>

			</div>
		</div>

		<!-- Footer -->
		<div class="ba-panel__footer">
			<a href="https://bureau-it.com" target="_blank" rel="noopener" class="ba-footer-logo"
				aria-label="Bureau de Tecnologia Ltda.">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 123.81 123.81"
					height="34" aria-hidden="true">
					<polygon fill="rgba(240,237,225,0.65)"
						points="16.59 44.68 16.59 87.58 28.69 71.86 45.68 71.86 16.59 44.68"/>
					<path fill="rgba(240,237,225,0.65)"
						d="M67.86,72.32q-5,0-7.55-2.33c-1.72-1.55-2.58-3.95-2.58-7.22v-9.2H54.59V45h3.14V38.29H68.89V45h6.17v8.57H68.89v6.72a2.73,2.73,0,0,0,.69,2.12,2.93,2.93,0,0,0,2,.61A8.43,8.43,0,0,0,75,62.23V70.8a12.7,12.7,0,0,1-3.18,1.1A17.78,17.78,0,0,1,67.86,72.32Z"/>
					<rect fill="rgba(240,237,225,0.65)" x="41.13" y="36.23" width="11.16" height="6.86"/>
					<polygon fill="rgba(240,237,225,0.65)"
						points="41.13 45 41.13 64.03 49.44 71.73 52.29 71.73 52.29 45 41.13 45"/>
					<path fill="rgba(240,237,225,0.65)" d="M78.18,73.48h29v5.58h-29Z"/>
				</svg>
				<span class="ba-footer-version">BIT A11y v<?php echo esc_html( BUREAU_A11Y_VERSION ); ?></span>
			</a>
			<button id="ba-reset-btn" class="ba-reset-btn"
				aria-label="<?php esc_attr_e( 'Restaurar padrões de acessibilidade', 'bureau-a11y' ); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
					<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 7M21 3v6h-6M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 17M3 21v-6h6"
						stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
				</svg>
				<span class="ba-reset-label"><?php esc_html_e( 'Redefinir', 'bureau-a11y' ); ?></span>
			</button>
		</div>

	</aside>

	<!-- VLibras container -->
	<div vw class="enabled" id="bureau-vlibras-container">
		<div vw-access-button class="active"></div>
		<div vw-plugin-wrapper>
			<div class="vw-plugin-top-wrapper"></div>
		</div>
	</div>
	<script src="https://vlibras.gov.br/app/vlibras-plugin.js"></script>
	<script>new window.VLibras.Widget('https://vlibras.gov.br/app');</script>

	<!-- SVG filters for color blindness -->
	<svg id="bureau-a11y-color-filters" xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true">
		<defs>
			<filter id="ba-filter-protanopia">
				<feColorMatrix type="matrix" values="0.567 0.433 0 0 0  0.558 0.442 0 0 0  0 0.242 0.758 0 0  0 0 0 1 0"/>
			</filter>
			<filter id="ba-filter-deuteranopia">
				<feColorMatrix type="matrix" values="0.625 0.375 0 0 0  0.7 0.3 0 0 0  0 0.3 0.7 0 0  0 0 0 1 0"/>
			</filter>
			<filter id="ba-filter-tritanopia">
				<feColorMatrix type="matrix" values="0.95 0.05 0 0 0  0 0.433 0.567 0 0  0 0.475 0.525 0 0  0 0 0 1 0"/>
			</filter>
		</defs>
	</svg>
	<?php
}
