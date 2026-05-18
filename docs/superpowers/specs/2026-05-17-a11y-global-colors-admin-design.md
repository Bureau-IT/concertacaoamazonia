# BIT A11y — Cores Globais + Tela de Admin

**Data:** 2026-05-17
**Autor:** Daniel Cambría
**Status:** Approved (design)
**Versão alvo:** `bureau-a11y.php` v2.8.0

## Contexto

O mu-plugin `bureau-a11y` (Concertação) define 8 variáveis CSS hardcoded em
`bureau-a11y/bureau-a11y.css` (`--ba-forest`, `--ba-electric`, etc.) que não
têm relação semântica com as Global Colors do Elementor — fonte da verdade
oficial do projeto, conforme [`wordpress/CLAUDE.md`](../../wordpress/CLAUDE.md).

Mudar a paleta do site no Elementor não reflete no painel a11y. E não existe
forma de o usuário (admin do WordPress) ajustar as cores do painel sem editar
CSS no filesystem.

## Objetivos

1. **Cada slot de cor do a11y referencia uma Global Color do Elementor por padrão.**
   Mudar a paleta no Elementor reflete no painel.
2. **Override por slot via admin.** Tela em Aparência → Acessibilidade permite
   escolher outra Global Color ou inserir cor customizada (com alpha).
3. **Live preview no admin.** Mini-painel a11y renderizado na própria tela,
   atualiza em tempo real ao mudar qualquer cor — antes de salvar.
4. **Fallback robusto.** Se Elementor for desativado ou Global Color for
   deletada, o sistema cai num valor hardcoded sem quebrar visualmente.

## Não-objetivos (YAGNI)

- Network admin (multisite-wide). Per-blog basta — Concertação tem 2 blogs e
  cada um pode ter sua paleta de a11y se quiser.
- Color picker no frontend (usuário final). Apenas administradores editam.
- Presets/temas prontos (ex: "Modo escuro", "Modo claro"). Só edição livre.
- Importar/exportar configuração. Edição manual basta.

## Modelo de cores

### 8 slots semânticos do a11y

| Slot CSS              | Função                       | Default Global Elementor | Fallback hardcoded final |
| --------------------- | ---------------------------- | ------------------------ | ------------------------ |
| `--ba-forest`         | Fundo principal do painel    | Color Extra 1 (`96a86ed`) | `#003A26`                |
| `--ba-surface`        | Fundo dos cards toggle       | (custom rgba)            | `rgba(255,255,255,0.06)` |
| `--ba-electric`       | Cor de destaque/ativo        | accent                   | `#B12B79`                |
| `--ba-electric-glow`  | Glow de hover ativo          | (custom rgba)            | `rgba(177,43,121,0.20)`  |
| `--ba-text`           | Texto principal              | secondary                | `#F6EFEA`                |
| `--ba-muted`          | Texto secundário             | (custom rgba)            | `rgba(246,239,234,0.65)` |
| `--ba-border`         | Bordas/divisórias            | (custom rgba)            | `rgba(246,239,234,0.12)` |
| `--ba-trigger-bg`     | Fundo do botão flutuante     | primary                  | `#005A42`                |

**Rationale (escolha "Dark profundo + accent", opção 2 do playground):**
Color Extra 1 (`#003A26`) como fundo cria distinção clara entre "ferramenta de
acessibilidade" e "conteúdo do site" sem sair da paleta oficial. O accent
magenta nos toggles cumpre a função do verde-elétrico atual mantendo coerência
com a paleta corporativa. `trigger-bg=primary` casa o botão flutuante com o
header do site.

### Cascata CSS (3 camadas)

```css
:root {
  --ba-forest: var(
    --ba-override-forest,
    var(--e-global-color-96a86ed, #003A26)
  );
}
```

1. `--ba-override-forest` — só emitido se admin escolheu modo `custom`
2. `var(--e-global-color-XYZ)` — Global Color mapeada pelo admin (default ou
   alterada)
3. Fallback hardcoded — último recurso (Elementor off / color deletada)

## Componentes

### 1. Settings page

- Hook: `add_theme_page()` em `admin_menu`
- Página: Aparência → Acessibilidade (`appearance_page_bureau-a11y-colors`)
- Capability: `manage_options`
- Settings API: `register_setting( 'bureau_a11y', 'bureau_a11y_colors' )`
- Sanitização por slot:
  - `mode ∈ {global, custom}`
  - `global_id` valida contra IDs reais do kit ativo
  - `custom` valida `^#[0-9a-f]{6}$/i` OU `^rgba\([0-9., ]+\)$`

### 2. Option schema

Key: `bureau_a11y_colors` (per-blog em multisite)

```php
[
  'forest' => [
    'mode'      => 'global',
    'global_id' => '96a86ed',  // Color Extra 1
  ],
  'electric' => [
    'mode'      => 'global',
    'global_id' => 'accent',
  ],
  'surface' => [
    'mode'    => 'custom',
    'custom'  => 'rgba(255,255,255,0.06)',
  ],
  // ... 5 outros slots
]
```

Defaults são definidos em constante PHP `BUREAU_A11Y_DEFAULT_COLORS`; opção
ausente = usar defaults transparentemente.

### 3. Discovery de Global Colors

```php
function bureau_a11y_get_global_colors() {
  $cached = get_transient( 'bureau_a11y_elementor_globals' );
  if ( false !== $cached ) return $cached;

  if ( ! class_exists( '\Elementor\Plugin' ) ) return [];

  $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
  $system = $kit->get_settings_for_display( 'system_colors' ) ?? [];
  $custom = $kit->get_settings_for_display( 'custom_colors' ) ?? [];

  $result = [];
  foreach ( array_merge( $system, $custom ) as $c ) {
    $result[ $c['_id'] ] = [
      'title' => $c['title'],
      'value' => $c['color'],
    ];
  }
  set_transient( 'bureau_a11y_elementor_globals', $result, HOUR_IN_SECONDS );
  return $result;
}
```

**Invalidação:** hook `elementor/core/files/clear_cache` →
`delete_transient( 'bureau_a11y_elementor_globals' )`.

### 4. Emissão do CSS no frontend

Hook: `wp_head` priority 20 (depois do enqueue do a11y, antes de qualquer
widget Elementor).

```php
add_action( 'wp_head', 'bureau_a11y_emit_color_overrides', 20 );
function bureau_a11y_emit_color_overrides() {
  $colors  = wp_parse_args( get_option( 'bureau_a11y_colors', [] ), BUREAU_A11Y_DEFAULT_COLORS );
  $globals = bureau_a11y_get_global_colors();

  $lines = [];
  foreach ( $colors as $slot => $cfg ) {
    $var_name = '--ba-' . str_replace( '_', '-', $slot );
    $fallback = BUREAU_A11Y_DEFAULT_FALLBACKS[ $slot ];

    if ( 'custom' === $cfg['mode'] ) {
      $value = esc_attr( $cfg['custom'] );
      $lines[] = "{$var_name}: {$value};";
    } else {
      $gid = $cfg['global_id'];
      $lines[] = "{$var_name}: var(--e-global-color-{$gid}, {$fallback});";
    }
  }

  if ( ! $lines ) return;
  echo "<style id='bureau-a11y-color-overrides'>:root{" . implode( '', $lines ) . "}</style>\n";
}
```

**Por que inline e não no arquivo CSS:** evita rebuild do `.css` versionado a
cada save. Cascata WP normal (inline > file) garante override correto.

### 5. UI da tela admin (layout 2 colunas)

```
┌─ Cores do painel A11y (esq) ─────────┬─ Pré-visualização (dir) ─┐
│                                       │                          │
│ FUNDO DO PAINEL (forest)              │   ┌────────────────┐    │
│   (●) Global do Elementor             │   │ ♿ Acessibilidade│    │
│       [Color Extra 1 ▼]               │   ├────────────────┤    │
│   ( ) Cor customizada                 │   │  [A−] 100% [A+]│    │
│       [color picker oculto]           │   │  ┌──┐ ┌──┐    │    │
│                                       │   │  └──┘ └──┘    │    │
│ DESTAQUE (electric)                   │   │  BIT v2.8.0  ↻ │    │
│   (●) Global do Elementor             │   └────────────────┘    │
│       [Accent ▼]                      │                          │
│   ( ) Cor customizada                 │   ✓ Atualiza ao vivo     │
│                                       │                          │
│ ... (mais 6 slots)                    │                          │
│                                       │                          │
│ [Restaurar padrões]  [Salvar]         │                          │
└───────────────────────────────────────┴──────────────────────────┘
```

**Dropdown de Global Color** — lista todos os IDs do kit ativo
(`primary | secondary | text | accent | Color Extra 1 | ...`) com chip de
cor e nome. Quando "Cor customizada" selecionado, o picker é revelado;
quando "Global" selecionado, o picker fica oculto (mas valor preservado).

**Color picker** — usa `wp.colorPicker` com plugin
[`wp-color-picker-alpha`](https://github.com/kallookoo/wp-color-picker-alpha)
bundled localmente em `bureau-a11y/vendor/wp-color-picker-alpha/`. Suporta
hex e rgba.

### 6. Live preview

Mini-painel a11y renderizado HTML-inline na própria tela (não iframe):

```html
<div id="bureau-a11y-admin-preview" class="ba-preview-wrap">
  <aside class="ba-preview-panel">
    <!-- markup simplificado do painel real, com prefixo .ba-preview- -->
  </aside>
  <button class="ba-preview-trigger"></button>
</div>
```

CSS do preview é enfileirado **só** na tela admin:
`bureau-a11y/admin-colors.css` (escopo isolado via `#bureau-a11y-admin-preview`).

JS:

```js
function applyToPreview(slot, value) {
  document.getElementById('bureau-a11y-admin-preview')
    .style.setProperty('--ba-' + slot, value);
}

// Listen a todos os pickers/selects e dispara applyToPreview
```

Salvar = form submit normal do Settings API. Sem postback parcial.

## Arquivos

| Arquivo                                          | Mudança                                                  |
| ------------------------------------------------ | -------------------------------------------------------- |
| `bureau-a11y.php`                                | Bump 2.7.0→2.8.0; include admin; novo `wp_head` hook     |
| `bureau-a11y/admin-colors.php` *(novo)*          | Settings page, registro, sanitização, render             |
| `bureau-a11y/admin-colors.css` *(novo)*          | Estilos da tela admin (layout, preview)                  |
| `bureau-a11y/admin-colors.js` *(novo)*           | Live preview, toggle global/custom, alpha picker init    |
| `bureau-a11y/vendor/wp-color-picker-alpha/` *(novo)* | Bundle local da lib (GPLv2-compatible)               |
| `bureau-a11y/bureau-a11y.css`                    | Bump CSS version. Mantém defaults hardcoded como fallback |

**Canonical:** após cada edição, sincronizar arquivos para
`docker-dev/common/mu-plugins/` no server-tools (regra do projeto).

## Edge cases

- **Elementor desativado** → dropdown vazio + aviso "Cores globais
  indisponíveis — ative o Elementor". Força modo `custom` em todos os slots
  visualmente; defaults hardcoded continuam funcionando.
- **Global Color deletada após selecionada** → cascata
  `var(--e-global-color-X, fallback)` cobre.
- **Cache OPcache** → Settings API + `update_option` normais, sem hot reload
  necessário (option vai pra DB, lido fresh em cada request).
- **Multisite blog 1 vs blog 2** → option `bureau_a11y_colors` é per-blog.
- **Cores rgba/alpha** → 4 slots aceitam alpha via `wp-color-picker-alpha`.
- **Performance frontend** → `<style>` inline acrescenta ~400 bytes no
  `<head>`, sem requests extras. Sem impacto perceptível em Core Web Vitals.
- **Reset** → botão "Restaurar padrões" faz `delete_option( 'bureau_a11y_colors' )`,
  voltando ao mapeamento default da tabela acima.

## Critérios de aceitação

1. Painel a11y renderiza com **exatamente** as mesmas cores do default opção 2
   após upgrade (sem mudança visual vs HEAD se admin não tocar em nada).
2. Mudar Global Color `accent` no kit Elementor → cor de destaque do a11y muda
   automaticamente (sem editar nada no admin a11y).
3. Tela em Aparência → Acessibilidade lista os 8 slots com dropdown popular de
   Global Colors + opção custom.
4. Live preview reflete mudança em <100ms ao trocar qualquer cor.
5. Save persiste em `bureau_a11y_colors` option.
6. Restaurar padrões → `delete_option`, recarrega tela com defaults.
7. Desativar Elementor → painel a11y continua funcionando (cores fallback).
8. PHP `php -l` e JS syntax OK em todos os arquivos novos.

## Não inclui (próxima iteração)

- Feature de **ocultar/mostrar botões flutuantes** (brainstorm anterior nesta
  sessão, opção A — botão "Ocultar" no rodapé + mini-pill). Spec separado.
