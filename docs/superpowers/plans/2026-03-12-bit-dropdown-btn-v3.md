# BIT Dropdown Button v3.0.0 — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Atualizar o widget Elementor `bit_dropdown_btn` para usar criptografia de links via JetElements Download Handler, Icon Picker nativo e controles de estilo completos.

**Architecture:** O mu-plugin `bit-dropdown-btn.php` registra um widget Elementor nativo que usa `jet_elements_download_handler()->get_download_link($id)` para gerar URLs ofuscadas `?jet_download=HASH`. O CSS é ajustado para remover valores hardcoded substituídos pelos seletores Elementor.

**Tech Stack:** PHP 8.3, WordPress mu-plugin, Elementor Pro, JetElements (CrocoBl), CSS custom properties.

---

## File Structure

| Arquivo | Responsabilidade |
|---|---|
| `wordpress/wp-content/mu-plugins/bit-dropdown-btn.php` | Widget Elementor completo + enqueue CSS |
| `wordpress/wp-content/mu-plugins/bit-dropdown-btn.css` | Estilos do componente (sem valores hardcoded de dimensões) |
| `docker-dev/common/mu-plugins/bit-dropdown-btn.php` | Espelho canônico do server-tools |
| `docker-dev/common/mu-plugins/bit-dropdown-btn.css` | Espelho canônico do server-tools |

---

## Chunk 1: CSS — remover hardcoded, normalizar ícone

**Files:**
- Modify: `wordpress/wp-content/mu-plugins/bit-dropdown-btn.css`

### Task 1: Ajustar CSS

- [ ] **Step 1: Abrir o arquivo CSS**

  Arquivo: `wordpress/wp-content/mu-plugins/bit-dropdown-btn.css`

  Localizar o bloco `.dropdown-btn-container button.dropdown-btn-toggle` (por volta da linha 28). Ele contém atualmente:
  ```css
  width           : 220px;
  padding         : 15px 25px;
  border-radius   : 4px;
  ```

- [ ] **Step 2: Remover `width`, `padding` e `border-radius` do toggle (normal)**

  Remover as três linhas abaixo do seletor `.dropdown-btn-container button.dropdown-btn-toggle`:
  - `width           : 220px;`
  - `padding         : 15px 25px;`
  - `border-radius   : 4px;`

  Esses valores passam a ser controlados pelos seletores Elementor com defaults definidos no widget.

- [ ] **Step 3: Ajustar `border-radius` no estado hover**

  Localizar `.dropdown-btn-container:hover button.dropdown-btn-toggle`. Ele contém `border-radius: 4px 4px 0 0;` — **manter este**, pois é lógica de layout (achatar a borda inferior quando o menu está aberto), não um valor de design.

  Remover apenas `border-radius: 4px;` do bloco `:focus` e `:focus-visible` se existir como valor duplicado com o normal. Verificar os blocos:
  - `.dropdown-btn-container button.dropdown-btn-toggle:focus` — remover `border-radius: 4px;`
  - `.dropdown-btn-container:hover button.dropdown-btn-toggle:focus` — manter `border-radius: 4px 4px 0 0;`

- [ ] **Step 4: Atualizar bloco `.dropdown-btn-icon` para suportar SVG inline e Font Awesome**

  Localizar o bloco `.dropdown-btn-container .dropdown-btn-icon` (atual v2.0.0):
  ```css
  .dropdown-btn-container .dropdown-btn-icon {
      display     : inline-flex;
      align-items : center;
      width       : 30px;
      height      : 30px;
      flex-shrink : 0;
      color       : var(--btn-normal-icn);
      transition  : color 0.3s ease;
  }
  ```

  Adicionar logo após este bloco a regra de normalização para `svg` e `i` dentro do ícone:
  ```css
  .dropdown-btn-container .dropdown-btn-icon svg,
  .dropdown-btn-container .dropdown-btn-icon i {
      display : block;
      width   : 100%;
      height  : 100%;
  }
  ```

- [ ] **Step 5: Atualizar cabeçalho do arquivo — versão e descrição**

  Alterar linha 2: `Versão: 2.0.0` → `Versão: 3.0.0`

  Alterar linha 4-10 (bloco de requisitos):
  ```css
  /**
   * BIT Dropdown Button — Estilos
   * Versão: 3.0.0
   *
   * CSS variables do child theme (style.css :root):
   *   --btn-normal-bg, --btn-normal-txt, --btn-normal-bdr
   *   --btn-normal-bg-hv, --btn-normal-border-hv, --btn-normal-txt-hv
   *   --btn-normal-icn, --btn-normal-icon-hv
   *   --e-global-typography-text-font-family
   *
   * Dimensões (width, padding, border-radius) controladas via Elementor.
   * Ícone: SVG inline ou Font Awesome via Elementor Icon Picker.
   */
  ```

- [ ] **Step 6: Verificar sintaxe CSS**

  ```bash
  # Verificar se não há erros de sintaxe óbvios
  grep -n "border-radius\s*:\s*4px;" \
    /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/wordpress/wp-content/mu-plugins/bit-dropdown-btn.css
  ```

  Esperado: zero resultados (a única ocorrência deve ser `4px 4px 0 0`, não `4px` isolado).

- [ ] **Step 7: Commit do CSS**

  ```bash
  cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
  git add wordpress/wp-content/mu-plugins/bit-dropdown-btn.css
  git commit -m "style(dropdown-btn): remove hardcoded dimensions, normaliza ícone svg/i (v3.0.0)"
  ```

---

## Chunk 2: PHP — widget Elementor v3.0.0 completo

**Files:**
- Modify: `wordpress/wp-content/mu-plugins/bit-dropdown-btn.php`

### Task 2: Reescrever o widget PHP

- [ ] **Step 1: Substituir o conteúdo completo do arquivo**

  Escrever o arquivo `wordpress/wp-content/mu-plugins/bit-dropdown-btn.php` com o conteúdo abaixo (substitui integralmente a v2.0.0):

  ```php
  <?php
  /**
   * Plugin Name: BIT Dropdown Button
   * Description: Widget Elementor nativo "BIT Download Button" — dropdown com múltiplos
   *              arquivos, links criptografados via JetElements Download Handler.
   *              Fallback para URL direta quando JetElements indisponível.
   *              Ícone configurável via Icon Picker nativo do Elementor.
   * Version:     3.0.0
   * Author:      Bureau IT
   * Network:     true
   */

  if ( ! defined( 'ABSPATH' ) ) {
      exit;
  }

  add_action( 'wp_enqueue_scripts', function () {
      wp_enqueue_style(
          'bit-dropdown-btn',
          WPMU_PLUGIN_URL . '/bit-dropdown-btn.css',
          [ 'hello-elementor-child' ],
          filemtime( WPMU_PLUGIN_DIR . '/bit-dropdown-btn.css' )
      );
  } );

  add_action( 'elementor/widgets/register', function ( $widgets_manager ) {
      if ( ! class_exists( '\Elementor\Widget_Base' ) ) return;

      class Bit_Dropdown_Btn_Widget extends \Elementor\Widget_Base {

          public function get_name()       { return 'bit_dropdown_btn'; }
          public function get_title()      { return 'BIT Download Button'; }
          public function get_icon()       { return 'eicon-button'; }
          public function get_categories() { return [ 'general' ]; }
          public function get_keywords()   { return [ 'download', 'dropdown', 'button', 'bit', 'bureau' ]; }

          /**
           * SVG padrão (círculo com seta apontando para baixo).
           * Usado quando o controle ICONS não tiver seleção.
           * fill-rule="evenodd" garante o interior vazado correto.
           */
          private function get_default_icon_svg(): string {
              return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 22 22" '
                   . 'aria-hidden="true" focusable="false" fill="currentColor">'
                   . '<path fill-rule="evenodd" d="M11,0C4.92,0,0,4.92,0,11s4.92,11,11,11,11-4.92,11-11'
                   . 'S17.08,0,11,0Zm.13,8.42h3.5l-1.75,3.03-1.75,3.03-1.75-3.03-1.75-3.03h3.5Z"/>'
                   . '</svg>';
          }

          protected function register_controls() {

              // ── Tab Content ──────────────────────────────────────────────────
              $this->start_controls_section( 'content_section', [
                  'label' => 'Botão',
                  'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
              ] );

              $this->add_control( 'label', [
                  'label'       => 'Rótulo do botão',
                  'type'        => \Elementor\Controls_Manager::TEXT,
                  'default'     => 'DOWNLOAD',
                  'label_block' => true,
                  'ai'          => [ 'active' => false ],
              ] );

              $repeater = new \Elementor\Repeater();

              $repeater->add_control( 'item_label', [
                  'label'       => 'Rótulo',
                  'type'        => \Elementor\Controls_Manager::TEXT,
                  'default'     => 'Português',
                  'label_block' => true,
                  'ai'          => [ 'active' => false ],
              ] );

              $repeater->add_control( 'item_file', [
                  'label'       => 'Arquivo (biblioteca de mídia)',
                  'type'        => \Elementor\Controls_Manager::MEDIA,
                  'description' => 'Selecione um arquivo. O link será criptografado automaticamente via JetElements.',
              ] );

              $repeater->add_control( 'item_fallback_url', [
                  'label'         => 'URL alternativa',
                  'type'          => \Elementor\Controls_Manager::URL,
                  'description'   => 'Usada quando nenhum arquivo for selecionado ou JetElements estiver inativo.',
                  'show_external' => true,
                  'ai'            => [ 'active' => false ],
              ] );

              $this->add_control( 'links', [
                  'label'       => 'Links do dropdown',
                  'type'        => \Elementor\Controls_Manager::REPEATER,
                  'fields'      => $repeater->get_controls(),
                  'default'     => [
                      [ 'item_label' => 'Português', 'item_file' => [ 'id' => 0, 'url' => '' ], 'item_fallback_url' => [ 'url' => '' ] ],
                      [ 'item_label' => 'English',   'item_file' => [ 'id' => 0, 'url' => '' ], 'item_fallback_url' => [ 'url' => '' ] ],
                  ],
                  'title_field' => '{{{ item_label }}}',
              ] );

              $this->end_controls_section();

              // ── Tab Style — Botão Dimensões ──────────────────────────────────
              $this->start_controls_section( 'style_btn_dims', [
                  'label' => 'Botão — Dimensões',
                  'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
              ] );

              $this->add_control( 'btn_width', [
                  'label'      => 'Largura',
                  'type'       => \Elementor\Controls_Manager::SLIDER,
                  'size_units' => [ 'px', '%' ],
                  'range'      => [
                      'px' => [ 'min' => 100, 'max' => 600, 'step' => 5 ],
                      '%'  => [ 'min' => 20,  'max' => 100, 'step' => 5 ],
                  ],
                  'default'    => [ 'unit' => 'px', 'size' => 220 ],
                  'selectors'  => [
                      '{{WRAPPER}} .dropdown-btn-toggle' => 'width: {{SIZE}}{{UNIT}};',
                  ],
              ] );

              $this->add_control( 'btn_border_radius', [
                  'label'      => 'Border radius',
                  'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                  'size_units' => [ 'px', '%' ],
                  'default'    => [
                      'top'      => 4,
                      'right'    => 4,
                      'bottom'   => 4,
                      'left'     => 4,
                      'unit'     => 'px',
                      'isLinked' => true,
                  ],
                  'selectors'  => [
                      '{{WRAPPER}} .dropdown-btn-toggle' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                  ],
              ] );

              $this->add_control( 'btn_padding', [
                  'label'      => 'Padding',
                  'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                  'size_units' => [ 'px', 'em' ],
                  'default'    => [
                      'top'      => 15,
                      'right'    => 25,
                      'bottom'   => 15,
                      'left'     => 25,
                      'unit'     => 'px',
                      'isLinked' => false,
                  ],
                  'selectors'  => [
                      '{{WRAPPER}} .dropdown-btn-toggle' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                  ],
              ] );

              $this->add_group_control(
                  \Elementor\Group_Control_Box_Shadow::get_type(),
                  [
                      'name'     => 'btn_box_shadow',
                      'selector' => '{{WRAPPER}} .dropdown-btn-toggle',
                  ]
              );

              $this->end_controls_section();

              // ── Tab Style — Cores Normal ─────────────────────────────────────
              $this->start_controls_section( 'style_colors_normal', [
                  'label' => 'Cores — Normal',
                  'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
              ] );

              $this->add_control( 'color_bg', [
                  'label'     => 'Fundo',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  'selectors' => [ '{{WRAPPER}}' => '--btn-normal-bg: {{VALUE}};' ],
              ] );

              $this->add_control( 'color_txt', [
                  'label'     => 'Texto',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  'selectors' => [ '{{WRAPPER}}' => '--btn-normal-txt: {{VALUE}};' ],
              ] );

              $this->add_control( 'color_bdr', [
                  'label'     => 'Borda',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  'selectors' => [ '{{WRAPPER}}' => '--btn-normal-bdr: {{VALUE}};' ],
              ] );

              $this->end_controls_section();

              // ── Tab Style — Cores Hover ──────────────────────────────────────
              $this->start_controls_section( 'style_colors_hover', [
                  'label' => 'Cores — Hover',
                  'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
              ] );

              $this->add_control( 'color_bg_hv', [
                  'label'     => 'Fundo (hover)',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  'selectors' => [ '{{WRAPPER}}' => '--btn-normal-bg-hv: {{VALUE}};' ],
              ] );

              $this->add_control( 'color_txt_hv', [
                  'label'     => 'Texto (hover)',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  'selectors' => [ '{{WRAPPER}}' => '--btn-normal-txt-hv: {{VALUE}};' ],
              ] );

              $this->add_control( 'color_bdr_hv', [
                  'label'     => 'Borda (hover)',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  // Mantém --btn-normal-border-hv (inconsistência herdada do CSS — não alterar)
                  'selectors' => [ '{{WRAPPER}}' => '--btn-normal-border-hv: {{VALUE}};' ],
              ] );

              $this->end_controls_section();

              // ── Tab Style — Ícone ────────────────────────────────────────────
              $this->start_controls_section( 'style_icon', [
                  'label' => 'Ícone',
                  'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
              ] );

              $this->add_control( 'icon', [
                  'label'   => 'Ícone',
                  'type'    => \Elementor\Controls_Manager::ICONS,
                  'default' => [ 'value' => 'eicon-download', 'library' => 'eicons' ],
              ] );

              $this->add_control( 'icon_size', [
                  'label'      => 'Tamanho',
                  'type'       => \Elementor\Controls_Manager::SLIDER,
                  'size_units' => [ 'px' ],
                  'range'      => [ 'px' => [ 'min' => 12, 'max' => 64, 'step' => 1 ] ],
                  'default'    => [ 'unit' => 'px', 'size' => 30 ],
                  'selectors'  => [
                      '{{WRAPPER}} .dropdown-btn-icon svg, {{WRAPPER}} .dropdown-btn-icon i' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
                  ],
              ] );

              $this->add_control( 'color_icn', [
                  'label'     => 'Cor (normal)',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  'selectors' => [ '{{WRAPPER}}' => '--btn-normal-icn: {{VALUE}};' ],
              ] );

              $this->add_control( 'color_icn_hv', [
                  'label'     => 'Cor (hover)',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  'selectors' => [ '{{WRAPPER}}' => '--btn-normal-icon-hv: {{VALUE}};' ],
              ] );

              $this->end_controls_section();

              // ── Tab Style — Tipografia ───────────────────────────────────────
              $this->start_controls_section( 'style_typography', [
                  'label' => 'Tipografia',
                  'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
              ] );

              $this->add_group_control(
                  \Elementor\Group_Control_Typography::get_type(),
                  [
                      'name'     => 'label_typography',
                      'label'    => 'Label do botão',
                      'selector' => '{{WRAPPER}} .dropdown-btn-label',
                  ]
              );

              $this->add_group_control(
                  \Elementor\Group_Control_Typography::get_type(),
                  [
                      'name'     => 'menu_typography',
                      'label'    => 'Itens do menu',
                      'selector' => '{{WRAPPER}} .dropdown-btn-menu a',
                  ]
              );

              $this->end_controls_section();

              // ── Tab Style — Menu Dropdown ────────────────────────────────────
              $this->start_controls_section( 'style_menu', [
                  'label' => 'Menu Dropdown',
                  'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
              ] );

              $this->add_control( 'menu_min_width', [
                  'label'      => 'Largura mínima',
                  'type'       => \Elementor\Controls_Manager::SLIDER,
                  'size_units' => [ 'px', '%' ],
                  'range'      => [
                      'px' => [ 'min' => 100, 'max' => 600, 'step' => 5 ],
                      '%'  => [ 'min' => 50,  'max' => 200, 'step' => 5 ],
                  ],
                  'selectors'  => [
                      '{{WRAPPER}} .dropdown-btn-menu' => 'min-width: {{SIZE}}{{UNIT}};',
                  ],
              ] );

              $this->add_control( 'menu_item_padding', [
                  'label'      => 'Padding do item',
                  'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                  'size_units' => [ 'px', 'em' ],
                  'default'    => [
                      'top'      => 12,
                      'right'    => 20,
                      'bottom'   => 12,
                      'left'     => 20,
                      'unit'     => 'px',
                      'isLinked' => false,
                  ],
                  'selectors'  => [
                      '{{WRAPPER}} .dropdown-btn-menu a' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                  ],
              ] );

              $this->add_control( 'menu_color_bg', [
                  'label'     => 'Fundo',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  'selectors' => [ '{{WRAPPER}} .dropdown-btn-menu' => 'background-color: {{VALUE}};' ],
              ] );

              $this->add_control( 'menu_color_txt', [
                  'label'     => 'Texto',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  'selectors' => [ '{{WRAPPER}} .dropdown-btn-menu a' => 'color: {{VALUE}};' ],
              ] );

              $this->add_control( 'menu_color_txt_hv', [
                  'label'     => 'Texto (hover)',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  'selectors' => [ '{{WRAPPER}} .dropdown-btn-menu a:hover' => 'color: {{VALUE}};' ],
              ] );

              $this->add_control( 'menu_color_bg_hv', [
                  'label'     => 'Fundo (hover)',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  'selectors' => [ '{{WRAPPER}} .dropdown-btn-menu a:hover' => 'background-color: {{VALUE}};' ],
              ] );

              $this->add_control( 'menu_color_bdr', [
                  'label'     => 'Borda',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  'selectors' => [ '{{WRAPPER}} .dropdown-btn-menu' => 'border-color: {{VALUE}};' ],
              ] );

              $this->add_control( 'menu_color_divider', [
                  'label'     => 'Separador entre itens',
                  'type'      => \Elementor\Controls_Manager::COLOR,
                  'selectors' => [ '{{WRAPPER}} .dropdown-btn-menu a' => 'border-bottom-color: {{VALUE}};' ],
              ] );

              $this->end_controls_section();
          }

          protected function render() {
              $s     = $this->get_settings_for_display();
              $label = esc_html( ! empty( $s['label'] ) ? $s['label'] : 'Download' );
              $links = ! empty( $s['links'] ) ? $s['links'] : [];

              echo '<div class="dropdown-btn-wrapper">';
              echo '<div class="dropdown-btn-container">';
              echo '<button class="dropdown-btn-toggle" type="button">';
              echo '<div class="dropdown-btn-content">';
              echo '<span class="dropdown-btn-label">' . $label . '</span>';

              // Ícone — tenta Icon Picker, cai para SVG padrão
              echo '<span class="dropdown-btn-icon">';
              $icon_settings = $s['icon'] ?? [];
              $icon_html     = '';
              if ( ! empty( $icon_settings['value'] ) ) {
                  ob_start();
                  \Elementor\Icons_Manager::render_icon( $icon_settings, [ 'aria-hidden' => 'true' ] );
                  $icon_html = ob_get_clean();
              }
              echo ! empty( $icon_html ) ? $icon_html : $this->get_default_icon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
              echo '</span>';

              echo '</div>'; // .dropdown-btn-content
              echo '</button>';

              // Menu dropdown
              if ( ! empty( $links ) ) {
                  echo '<div class="dropdown-btn-menu">';
                  foreach ( $links as $link ) {
                      $item_label    = esc_html( $link['item_label'] ?? '' );
                      $attachment_id = (int) ( $link['item_file']['id'] ?? 0 );
                      $fallback_url  = $link['item_fallback_url']['url'] ?? '';
                      $is_external   = ! empty( $link['item_fallback_url']['is_external'] );

                      if ( $attachment_id > 0
                           && function_exists( 'jet_elements_download_handler' )
                           && jet_elements_download_handler() instanceof Jet_Elements_Download_Handler ) {
                          // Link criptografado via JetElements
                          $href   = esc_url( jet_elements_download_handler()->get_download_link( $attachment_id ) );
                          $target = '';
                      } elseif ( ! empty( $fallback_url ) ) {
                          // URL direta (fallback)
                          $href   = esc_url( $fallback_url );
                          $target = $is_external ? ' target="_blank"' : '';
                      } else {
                          continue; // nenhuma URL válida — não renderiza este item
                      }

                      echo '<a href="' . $href . '" rel="nofollow noopener"' . $target . '>'
                         . $item_label . '</a>';
                  }
                  echo '</div>'; // .dropdown-btn-menu
              }

              echo '</div>'; // .dropdown-btn-container
              echo '</div>'; // .dropdown-btn-wrapper
          }
      }

      $widgets_manager->register( new Bit_Dropdown_Btn_Widget() );
  } );
  ```

- [ ] **Step 2: Verificar sintaxe PHP**

  ```bash
  php -l /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/wordpress/wp-content/mu-plugins/bit-dropdown-btn.php
  ```

  Esperado: `No syntax errors detected in ...`

- [ ] **Step 3: Commit do PHP**

  ```bash
  cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
  git add wordpress/wp-content/mu-plugins/bit-dropdown-btn.php
  git commit -m "feat(dropdown-btn): widget v3 com JetElements encryption, icon picker e controles completos"
  ```

---

## Chunk 3: Mirror, verificação e commit final

**Files:**
- Modify: `docker-dev/common/mu-plugins/bit-dropdown-btn.php`
- Modify: `docker-dev/common/mu-plugins/bit-dropdown-btn.css`

### Task 3: Copiar arquivos para common/mu-plugins

- [ ] **Step 1: Copiar PHP para o espelho**

  ```bash
  cp \
    /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/wordpress/wp-content/mu-plugins/bit-dropdown-btn.php \
    /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bit-dropdown-btn.php
  ```

- [ ] **Step 2: Copiar CSS para o espelho**

  ```bash
  cp \
    /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/wordpress/wp-content/mu-plugins/bit-dropdown-btn.css \
    /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bit-dropdown-btn.css
  ```

- [ ] **Step 3: Commit no repositório server-tools**

  ```bash
  cd /Users/dcambria/scripts/server-tools/v2
  git add docker-dev/common/mu-plugins/bit-dropdown-btn.php \
          docker-dev/common/mu-plugins/bit-dropdown-btn.css
  git commit -m "feat(mu-plugins): atualiza bit-dropdown-btn para v3.0.0 (JetElements encryption)"
  ```

### Task 4: Verificação manual no Elementor

- [ ] **Step 1: Confirmar que o site está rodando**

  ```bash
  /Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh status
  ```

  Esperado: containers `nginx`, `php`, `mysql`, `redis` com status `Up`.

- [ ] **Step 2: Limpar cache do OPcache e WordPress**

  ```bash
  /Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh all-cache-flush
  ```

- [ ] **Step 3: Verificar que o widget aparece no Elementor**

  Acessar o editor Elementor em qualquer página. Na barra de busca de widgets, digitar `BIT Download`. Esperado: widget "BIT Download Button" aparece na lista.

- [ ] **Step 4: Inserir widget e configurar links com attachments**

  Inserir o widget em uma página de teste. Na aba Conteúdo, configurar 2 itens no repeater, selecionando arquivos PDF da biblioteca de mídia no campo "Arquivo (biblioteca de mídia)".

- [ ] **Step 5: Inspecionar HTML gerado**

  No preview, clicar com botão direito → "Inspecionar". Verificar que os links `<a>` contêm `?jet_download=` e **não** IDs numéricos diretos.

  ```
  # Esperado:
  <a href="http://site.local/?jet_download=abc123sha1hash" rel="nofollow noopener">Português</a>

  # Não esperado:
  <a href="/wp-content/uploads/arquivo.pdf">Português</a>
  ```

- [ ] **Step 5b: Clicar em um link e verificar download**

  Clicar em um dos links no preview. Esperado: o arquivo inicia o download (sem erro 404 nem redirect quebrado). Verificar que o arquivo correto é servido.

- [ ] **Step 6: Testar hover e dropdown**

  Passar o mouse sobre o botão no preview. Esperado: dropdown aparece, cores mudam, ícone muda de cor.

- [ ] **Step 7: Testar controles de estilo**

  Na aba Estilo, alterar cada seção:
  - Botão — Dimensões: alterar largura → botão redimensiona no preview
  - Cores — Normal: alterar fundo → cor muda
  - Ícone: trocar para outro ícone via picker → ícone muda
  - Tipografia: alterar fonte do label → fonte muda
  - Menu Dropdown: alterar padding dos itens → espaçamento muda

- [ ] **Step 8: Testar fallback URL**

  Editar um item do repeater: limpar o campo "Arquivo", preencher apenas "URL alternativa". Verificar no HTML que o link aparece como URL direta (`href="https://..."`), não criptografada.

- [ ] **Step 9: Testar fallback JetElements inativo**

  Desativar o plugin JetElements via WP Admin → Plugins. Recarregar o preview do Elementor. Esperado: widget renderiza sem erro PHP fatal (verificar logs em `logs/php/error.log`). Os links com attachment ID devem desaparecer (nenhuma URL → item pulado), e os links com `item_fallback_url` devem aparecer normalmente.

  Reativar JetElements ao finalizar.

- [ ] **Step 10: Confirmar ícone padrão**

  Quando o controle de ícone estiver com o default `eicon-download` (Elementor icons), verificar se o ícone renderiza. Se a library `eicons` não retornar HTML (possível em alguns ambientes locais sem Font Awesome carregado), o SVG fallback deve aparecer. Verificar no HTML:
  - Com eicons carregado: `<i class="eicon-download" aria-hidden="true"></i>` dentro de `.dropdown-btn-icon`
  - Com fallback SVG: `<svg ... fill-rule="evenodd" ...>` dentro de `.dropdown-btn-icon`
