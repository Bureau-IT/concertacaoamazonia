<?php
/**
 * Bureau A11y — Tela de admin de cores.
 *
 * Adiciona "Aparência → Acessibilidade" pra editar os 8 slots semânticos
 * de cor do painel a11y. Cada slot pode ser:
 *   - Global Color do Elementor (dropdown)
 *   - Cor customizada (color picker com alpha)
 *
 * A option `bureau_a11y_colors` é per-blog em multisite.
 *
 * Spec: docs/superpowers/specs/2026-05-17-a11y-global-colors-admin-design.md
 *
 * @package BureauA11y
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Labels amigáveis dos slots, na ordem em que aparecem na tela.
const BUREAU_A11Y_SLOT_LABELS = [
	'forest'         => [ 'label' => 'Fundo do painel',          'hint' => 'Cor de fundo principal do painel a11y' ],
	'text'           => [ 'label' => 'Texto principal',          'hint' => 'Cor do texto, ícones e títulos do painel' ],
	'electric'       => [ 'label' => 'Cor de destaque',          'hint' => 'Botões ativos, foco, ações principais' ],
	'electric_glow'  => [ 'label' => 'Brilho do destaque',       'hint' => 'Halo translúcido em torno de elementos ativos' ],
	'surface'        => [ 'label' => 'Fundo dos toggles',        'hint' => 'Cor de fundo dos cards de toggle (transparente)' ],
	'muted'          => [ 'label' => 'Texto secundário',         'hint' => 'Labels, descrições, texto auxiliar' ],
	'border'         => [ 'label' => 'Bordas e divisórias',      'hint' => 'Linhas internas do painel' ],
	'trigger_bg'     => [ 'label' => 'Fundo do botão flutuante', 'hint' => 'Cor do botão a11y e back-to-top ancorados na lateral' ],
];

/**
 * Registra a página em Aparência → Acessibilidade.
 */
add_action( 'admin_menu', 'bureau_a11y_register_admin_page' );
function bureau_a11y_register_admin_page() {
	add_theme_page(
		__( 'Acessibilidade — Cores', 'bureau-a11y' ),
		__( 'Acessibilidade', 'bureau-a11y' ),
		'manage_options',
		'bureau-a11y-colors',
		'bureau_a11y_render_admin_page'
	);
}

/**
 * Registra a option na Settings API.
 */
add_action( 'admin_init', 'bureau_a11y_register_settings' );
function bureau_a11y_register_settings() {
	register_setting(
		'bureau_a11y_colors_group',
		'bureau_a11y_colors',
		[
			'type'              => 'array',
			'sanitize_callback' => 'bureau_a11y_sanitize_colors',
			'default'           => [],
		]
	);
}

/**
 * Sanitização da option.
 *
 * Aceita apenas slots conhecidos. Para cada slot:
 *   - mode ∈ {global, custom}
 *   - se global: global_id é string alfanumérica curta (do kit Elementor)
 *   - se custom: valida formato hex (#RGB/#RRGGBB) ou rgba(...)
 */
function bureau_a11y_sanitize_colors( $input ) {
	if ( ! is_array( $input ) ) {
		return [];
	}

	$clean   = [];
	$globals = bureau_a11y_get_global_colors();

	foreach ( BUREAU_A11Y_DEFAULT_COLORS as $slot => $default ) {
		if ( ! isset( $input[ $slot ] ) || ! is_array( $input[ $slot ] ) ) {
			continue;
		}
		$cfg  = $input[ $slot ];
		$mode = isset( $cfg['mode'] ) && in_array( $cfg['mode'], [ 'global', 'custom' ], true ) ? $cfg['mode'] : 'global';

		if ( 'global' === $mode ) {
			$gid = isset( $cfg['global_id'] ) ? sanitize_text_field( $cfg['global_id'] ) : '';
			// Só aceita IDs reais do kit (se o Elementor estiver acessível)
			if ( $gid !== '' && ( empty( $globals ) || isset( $globals[ $gid ] ) ) ) {
				$clean[ $slot ] = [ 'mode' => 'global', 'global_id' => $gid ];
			}
		} else {
			$raw = isset( $cfg['custom'] ) ? trim( (string) $cfg['custom'] ) : '';
			if ( bureau_a11y_is_valid_color( $raw ) ) {
				$clean[ $slot ] = [ 'mode' => 'custom', 'custom' => $raw ];
			}
		}
	}

	return $clean;
}

/**
 * Aceita #RGB, #RRGGBB, #RRGGBBAA, rgba(r,g,b,a) e rgb(r,g,b).
 */
function bureau_a11y_is_valid_color( $value ) {
	if ( $value === '' ) {
		return false;
	}
	if ( preg_match( '/^#([a-f0-9]{3}|[a-f0-9]{6}|[a-f0-9]{8})$/i', $value ) ) {
		return true;
	}
	if ( preg_match( '/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/i', $value ) ) {
		return true;
	}
	return false;
}

/**
 * Enqueue assets da tela admin — só na nossa página.
 */
add_action( 'admin_enqueue_scripts', 'bureau_a11y_admin_enqueue' );
function bureau_a11y_admin_enqueue( $hook ) {
	if ( 'appearance_page_bureau-a11y-colors' !== $hook ) {
		return;
	}

	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );

	wp_enqueue_style(
		'bureau-a11y-admin',
		BUREAU_A11Y_URL . 'admin-colors.css',
		[ 'wp-color-picker' ],
		BUREAU_A11Y_CSS_VERSION
	);

	// O preview reusa o CSS do painel real (com cascata var(--ba-*))
	wp_enqueue_style(
		'bureau-a11y-frontend',
		BUREAU_A11Y_URL . 'bureau-a11y.css',
		[],
		BUREAU_A11Y_CSS_VERSION
	);

	wp_enqueue_script(
		'bureau-a11y-admin',
		BUREAU_A11Y_URL . 'admin-colors.js',
		[ 'wp-color-picker' ],
		BUREAU_A11Y_JS_VERSION,
		true
	);

	wp_localize_script( 'bureau-a11y-admin', 'bureauA11yAdmin', [
		'globals' => bureau_a11y_get_global_colors(),
		'slots'   => array_keys( BUREAU_A11Y_DEFAULT_COLORS ),
	] );
}

/**
 * Render da página.
 */
function bureau_a11y_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$config  = bureau_a11y_get_colors_config();
	$globals = bureau_a11y_get_global_colors();
	?>
	<div class="wrap bureau-a11y-admin">
		<h1><?php esc_html_e( 'Acessibilidade — Cores do painel', 'bureau-a11y' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Configure as 8 cores semânticas do painel de acessibilidade. Por padrão, cada cor referencia uma Global Color do Elementor — mudar a paleta do site no Elementor reflete aqui. Use "Cor customizada" pra fixar uma cor independente da paleta global.', 'bureau-a11y' ); ?>
		</p>

		<?php if ( empty( $globals ) ) : ?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'Elementor não está disponível.', 'bureau-a11y' ); ?></strong>
			<?php esc_html_e( 'Dropdowns de Global Color estão vazios. Você ainda pode usar cores customizadas; os fallbacks hardcoded garantem que o painel continua visível.', 'bureau-a11y' ); ?></p>
		</div>
		<?php endif; ?>

		<form method="post" action="options.php" class="bureau-a11y-admin-form">
			<?php settings_fields( 'bureau_a11y_colors_group' ); ?>

			<div class="bureau-a11y-admin-grid">
				<div class="bureau-a11y-admin-col">
					<?php foreach ( BUREAU_A11Y_SLOT_LABELS as $slot => $meta ) : ?>
						<?php bureau_a11y_render_slot_field( $slot, $meta, $config[ $slot ], $globals ); ?>
					<?php endforeach; ?>

					<p class="submit">
						<?php submit_button( __( 'Salvar cores', 'bureau-a11y' ), 'primary', 'submit', false ); ?>
						<button type="submit" name="bureau_a11y_reset" value="1" class="button button-secondary" formnovalidate>
							<?php esc_html_e( 'Restaurar padrões', 'bureau-a11y' ); ?>
						</button>
					</p>
				</div>

				<div class="bureau-a11y-admin-preview-col">
					<h2><?php esc_html_e( 'Pré-visualização', 'bureau-a11y' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Atualiza em tempo real ao mudar qualquer cor (não precisa salvar).', 'bureau-a11y' ); ?></p>
					<?php bureau_a11y_render_admin_preview(); ?>
				</div>
			</div>
		</form>
	</div>
	<?php
}

/**
 * Render de um slot (linha com nome + radios global/custom + dropdown + picker).
 */
function bureau_a11y_render_slot_field( $slot, $meta, $cfg, $globals ) {
	$mode      = $cfg['mode'];
	$global_id = isset( $cfg['global_id'] ) ? $cfg['global_id'] : '';
	$custom    = isset( $cfg['custom'] ) ? $cfg['custom'] : '';
	$fallback  = BUREAU_A11Y_FALLBACK_COLORS[ $slot ];
	$name      = 'bureau_a11y_colors[' . esc_attr( $slot ) . ']';
	?>
	<div class="bureau-a11y-slot" data-slot="<?php echo esc_attr( $slot ); ?>">
		<div class="bureau-a11y-slot__header">
			<span class="bureau-a11y-slot__title"><?php echo esc_html( $meta['label'] ); ?></span>
			<span class="bureau-a11y-slot__var">--ba-<?php echo esc_html( str_replace( '_', '-', $slot ) ); ?></span>
		</div>
		<p class="bureau-a11y-slot__hint"><?php echo esc_html( $meta['hint'] ); ?></p>

		<label class="bureau-a11y-slot__mode">
			<input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="global" <?php checked( $mode, 'global' ); ?> />
			<?php esc_html_e( 'Global do Elementor', 'bureau-a11y' ); ?>
		</label>

		<select name="<?php echo esc_attr( $name ); ?>[global_id]" class="bureau-a11y-slot__select" <?php echo empty( $globals ) ? 'disabled' : ''; ?>>
			<?php if ( empty( $globals ) ) : ?>
				<option value=""><?php esc_html_e( '(Elementor indisponível)', 'bureau-a11y' ); ?></option>
			<?php else : ?>
				<?php foreach ( $globals as $gid => $info ) : ?>
					<option value="<?php echo esc_attr( $gid ); ?>" data-color="<?php echo esc_attr( $info['value'] ); ?>" <?php selected( $global_id, $gid ); ?>>
						<?php echo esc_html( $info['title'] . ' (' . $info['value'] . ')' ); ?>
					</option>
				<?php endforeach; ?>
			<?php endif; ?>
		</select>

		<label class="bureau-a11y-slot__mode">
			<input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="custom" <?php checked( $mode, 'custom' ); ?> />
			<?php esc_html_e( 'Cor customizada', 'bureau-a11y' ); ?>
		</label>

		<input type="text" name="<?php echo esc_attr( $name ); ?>[custom]"
			value="<?php echo esc_attr( $custom !== '' ? $custom : $fallback ); ?>"
			data-alpha-enabled="true"
			class="bureau-a11y-slot__picker" />
	</div>
	<?php
}

/**
 * Mini-painel preview — clone simplificado do painel real, usando as
 * mesmas CSS vars (--ba-*). JS atualiza setProperty em #bureau-a11y-admin-preview.
 */
function bureau_a11y_render_admin_preview() {
	?>
	<div id="bureau-a11y-admin-preview" class="bureau-a11y-admin-preview">
		<aside id="bureau-a11y-panel" class="bureau-a11y-admin-preview__panel" role="presentation">
			<div class="ba-panel__header">
				<span class="ba-panel__title">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="18" height="18" aria-hidden="true">
						<path d="M256,112a56,56,0,1,1,56-56A56.06,56.06,0,0,1,256,112Z"/>
						<path d="M432,112.8l-.45.12c-1,.28-2,.58-3,.89-18.61,5.46-108.93,30.92-172.56,30.92-59.13,0-141.28-22-167.56-29.47-2.79-.81-5.46-1.65-8-2.58-19-5-32,14.3-32,31.94,0,17.47,15.7,25.79,31.55,31.76v.28l95.22,29.74c9.73,3.73,12.33,7.54,13.6,10.84,4.13,10.59.83,31.56-.34,38.88l-5.8,45L150.05,477.44c-.1.49-.19,1-.27,1.47-.08.42-.16.85-.23,1.27-2.32,16.15,9.54,31.82,32,31.82,19.6,0,28.25-13.53,32-31.94,0,0,28-157.57,42-157.57s42.84,157.57,42.84,157.57c3.75,18.41,12.4,31.94,32,31.94,22.52,0,34.38-15.74,32-31.94-.21-1.38-.46-2.74-.76-4.06L329,301.27l-5.79-45c-4.19-26.21-.82-34.87.32-36.9,0,0,0-.1.08-.15,1.08-2,6-6.48,17.48-10.79l89.28-31.21c.54-.16,1.09-.34,1.62-.52,16-6,32-14.3,32-31.93S451,107.81,432,112.8Z"/>
					</svg>
					Acessibilidade
				</span>
			</div>
			<div style="padding: 12px;">
				<div style="background: var(--ba-surface); border: 1px solid var(--ba-border); border-radius: 8px; padding: 10px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
					<span style="font-size: 10px; color: var(--ba-muted); text-transform: uppercase;">Tamanho</span>
					<span style="background: var(--ba-electric); color: var(--ba-forest); width: 30px; height: 24px; display: flex; align-items: center; justify-content: center; border-radius: 4px; font-weight: 700; font-size: 11px;">A+</span>
				</div>
				<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
					<div style="background: var(--ba-surface); border: 1px solid var(--ba-border); border-radius: 8px; padding: 14px 8px; text-align: center; color: var(--ba-text); font-size: 11px;">Sem imagens</div>
					<div style="background: var(--ba-electric-glow); border: 1px solid var(--ba-electric); border-radius: 8px; padding: 14px 8px; text-align: center; color: var(--ba-electric); font-size: 11px;">Sem animações</div>
				</div>
			</div>
			<div class="ba-panel__footer">
				<span style="color: var(--ba-muted); font-size: 10px;">BIT A11y v<?php echo esc_html( BUREAU_A11Y_VERSION ); ?></span>
				<span style="color: var(--ba-muted); font-size: 10px;">↻ Redefinir</span>
			</div>
		</aside>
		<button class="bureau-a11y-admin-preview__trigger" aria-hidden="true">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" aria-hidden="true">
				<path d="M256,112a56,56,0,1,1,56-56A56.06,56.06,0,0,1,256,112Z"/>
				<path d="M432,112.8l-.45.12c-1,.28-2,.58-3,.89-18.61,5.46-108.93,30.92-172.56,30.92-59.13,0-141.28-22-167.56-29.47-2.79-.81-5.46-1.65-8-2.58-19-5-32,14.3-32,31.94,0,17.47,15.7,25.79,31.55,31.76v.28l95.22,29.74c9.73,3.73,12.33,7.54,13.6,10.84,4.13,10.59.83,31.56-.34,38.88l-5.8,45L150.05,477.44c-.1.49-.19,1-.27,1.47-.08.42-.16.85-.23,1.27-2.32,16.15,9.54,31.82,32,31.82,19.6,0,28.25-13.53,32-31.94,0,0,28-157.57,42-157.57s42.84,157.57,42.84,157.57c3.75,18.41,12.4,31.94,32,31.94,22.52,0,34.38-15.74,32-31.94-.21-1.38-.46-2.74-.76-4.06L329,301.27l-5.79-45c-4.19-26.21-.82-34.87.32-36.9,0,0,0-.1.08-.15,1.08-2,6-6.48,17.48-10.79l89.28-31.21c.54-.16,1.09-.34,1.62-.52,16-6,32-14.3,32-31.93S451,107.81,432,112.8Z"/>
			</svg>
		</button>
	</div>
	<?php
}

/**
 * Reset (botão "Restaurar padrões"). Roda antes do save da Settings API.
 */
add_action( 'admin_init', 'bureau_a11y_handle_reset', 9 );
function bureau_a11y_handle_reset() {
	if ( ! isset( $_POST['bureau_a11y_reset'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_POST['option_page'] ) || $_POST['option_page'] !== 'bureau_a11y_colors_group' ) {
		return;
	}
	check_admin_referer( 'bureau_a11y_colors_group-options' );

	delete_option( 'bureau_a11y_colors' );

	wp_safe_redirect( add_query_arg( [ 'page' => 'bureau-a11y-colors', 'reset' => '1' ], admin_url( 'themes.php' ) ) );
	exit;
}
