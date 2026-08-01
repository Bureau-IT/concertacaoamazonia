<?php
/**
 * Plugin Name: BIT Inline Submenu
 * Description: Barra de submenu horizontal abaixo do header.
 *              Hover: div.bit-hover-bar injetada no body via JS (overlay fixed).
 *              Página ativa: div.bit-subnav-bar injetada após o header (in-flow).
 *              Diamante indicador: seta acima da barra aponta para o item pai.
 *              Cores on-the-box: bg derivado da cor primária via color-mix.
 *              Ativação: CSS class "menu-submenu-inline" no widget Nav Menu do Elementor.
 *              Funciona em qualquer site — sem seletores Elementor por ID.
 * Version:     1.8.1
 * Author:      Bureau IT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BIT_INLINE_SUBMENU_VERSION', '1.8.1' );

// ── CSS ──────────────────────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'bit-inline-submenu',
        WPMU_PLUGIN_URL . '/bit-inline-submenu.css',
        [],
        filemtime( WPMU_PLUGIN_DIR . '/bit-inline-submenu.css' )
    );
} );

// CSS também no editor Elementor (preview iframe usa enqueue separado)
add_action( 'elementor/editor/after_enqueue_styles', function () {
    wp_enqueue_style(
        'bit-inline-submenu-editor',
        WPMU_PLUGIN_URL . '/bit-inline-submenu.css',
        [],
        filemtime( WPMU_PLUGIN_DIR . '/bit-inline-submenu.css' )
    );
} );
add_action( 'elementor/preview/enqueue_styles', function () {
    wp_enqueue_style(
        'bit-inline-submenu-preview',
        WPMU_PLUGIN_URL . '/bit-inline-submenu.css',
        [],
        filemtime( WPMU_PLUGIN_DIR . '/bit-inline-submenu.css' )
    );
} );


// ── JS ───────────────────────────────────────────────────────────────────────
// wp_footer dispara em frontend, em preview iframe (?elementor-preview=ID) e
// também no canvas. Não dispara no iframe do painel admin (?action=elementor).
add_action( 'wp_footer', 'bit_inline_submenu_print_script' );

function bit_inline_submenu_print_script() { ?>
<script id="bit-inline-submenu-js">
(function () {
  var isEditor = !!(window.elementorFrontend && window.elementorFrontend.isEditMode && window.elementorFrontend.isEditMode());

  function init() {
    var widget = document.querySelector('.menu-submenu-inline');
    if (!widget) return;

    // ── Calcular posição top = base do header ─────────────────────────────
    // No frontend: âncora = .elementor-location-header (fixed/sticky) — a barra
    // in-flow é inserida APÓS o header (afterend), ficando logo abaixo dele.
    // No editor: header não existe — usar o próprio widget como âncora, assim
    // a barra in-flow aparece colada abaixo do menu, não no fim do container.
    var headerEl = document.querySelector('.elementor-location-header');
    var anchorEl = headerEl || widget;
    var header = anchorEl; // mantido para compat. com resto do script
    function updateTop() {
      if (!header) return;
      var bottom = header.getBoundingClientRect().bottom;
      document.documentElement.style.setProperty(
        '--bit-submenu-top',
        bottom + 'px'
      );
    }
    updateTop();

    if (window.ResizeObserver && header) {
      new ResizeObserver(updateTop).observe(header);
    }

    // ── data-text nos links do menu principal (ghost-text anti-shift) ──────
    widget.querySelectorAll('.elementor-nav-menu > li > a.elementor-item').forEach(function(a) {
      a.dataset.text = a.textContent.trim();
    });

    // ── Hover: div.bit-hover-bar no body ───────────────────────────────────
    // O CSS do Elementor bloqueia display nos .sub-menu internos com alta
    // especificidade. Solução: criar um div novo no body — Elementor não tem
    // regras para esse elemento, sem conflito de CSS.
    var hoverTimeout = null;
    var hoverBar = document.createElement('div');
    hoverBar.className = 'bit-hover-bar';
    document.body.appendChild(hoverBar);

    // Herdar CSS vars do widget — copia computed style do {{WRAPPER}} do
    // Elementor para a hover-bar no body (que está fora do escopo do widget).
    // Lista completa de vars que o CSS do submenu consome.
    var BIS_VARS = [
      '--bit-submenu-height',
      '--bis-bg','--bis-bg-hover','--bis-diamond',
      '--bis-text','--bis-text-hover','--bis-text-active','--bis-border-active',
      '--bis-font-family','--bis-font-size','--bis-font-weight',
      '--bis-text-hover-weight','--bis-text-active-weight'
    ];
    function copyVars(target) {
      var wComputed = getComputedStyle(widget);
      BIS_VARS.forEach(function(v) {
        var val = wComputed.getPropertyValue(v).trim();
        if (val) target.style.setProperty(v, val);
      });
    }
    var wComputed = getComputedStyle(widget);
    copyVars(hoverBar);

    function openHover(li) {
      clearTimeout(hoverTimeout);

      var sub = li.querySelector('.sub-menu');
      if (!sub) return;

      // Clonar itens do sub-menu para a barra hover
      hoverBar.innerHTML = '';
      var ul = document.createElement('ul');
      sub.querySelectorAll('li').forEach(function(item) {
        var cloned = item.cloneNode(true);
        var a = cloned.querySelector('a');
        if (a) { a.dataset.text = a.textContent.trim(); }
        ul.appendChild(cloned);
      });
      hoverBar.appendChild(ul);

      // Posicionar diamante no centro horizontal do item pai
      var rect = li.getBoundingClientRect();
      hoverBar.style.setProperty('--bit-arrow-x', (rect.left + rect.width / 2 - 7) + 'px');
      hoverBar.classList.add('bit-hover-bar--active');
    }

    function closeHover() {
      hoverTimeout = setTimeout(function () {
        hoverBar.classList.remove('bit-hover-bar--active');
      }, 400);
    }

    // Cancelar fechamento quando mouse entra na barra hover
    hoverBar.addEventListener('mouseenter', function() { clearTimeout(hoverTimeout); });
    hoverBar.addEventListener('mouseleave', closeHover);

    // Manter hover-bar grudada no header durante scroll
    window.addEventListener('scroll', function() {
      if (!header) return;
      var hBottom = header.getBoundingClientRect().bottom;
      if (hBottom < 0) {
        // Header saiu do viewport → fechar hover-bar imediatamente
        if (hoverBar.classList.contains('bit-hover-bar--active')) {
          clearTimeout(hoverTimeout);
          hoverBar.classList.remove('bit-hover-bar--active');
        }
      } else {
        // Atualizar posição para grudar no header
        hoverBar.style.top = hBottom + 'px';
      }
    }, { passive: true });

    widget.querySelectorAll(
      '.elementor-nav-menu > li.menu-item-has-children'
    ).forEach(function (li) {
      var sub = li.querySelector('.sub-menu');
      if (!sub) return;
      li.addEventListener('mouseenter', function () { openHover(li); });
      li.addEventListener('mouseleave', closeHover);
    });

    // ── Página ativa ───────────────────────────────────────────────────────
    // Primeiro: classes WP (post/page items)
    var activeParent = widget.querySelector(
      '.elementor-nav-menu > li.menu-item-has-children.current-menu-ancestor,' +
      '.elementor-nav-menu > li.menu-item-has-children.current-menu-parent,' +
      '.elementor-nav-menu > li.menu-item-has-children.current-menu-item'
    );

    // Fallback: custom URL items não recebem classes WP — detectar por URL.
    // Pega o match mais específico (linkPath mais longo) para evitar que
    // um item pai (/cultura/) sobreponha um filho (/cultura/conhecimento/).
    if (!activeParent) {
      var curPath = window.location.pathname.replace(/\/$/, '') || '/';
      var bestLen = 0;
      widget.querySelectorAll(
        '.elementor-nav-menu > li.menu-item-has-children'
      ).forEach(function(li) {
        var a = li.querySelector(':scope > a');
        if (!a) return;
        var href = a.getAttribute('href') || '';
        var linkPath = href.replace(/^https?:\/\/[^\/]+/, '').replace(/\/$/, '') || '/';
        if (linkPath !== '/' &&
            (curPath === linkPath || curPath.startsWith(linkPath + '/')) &&
            linkPath.length > bestLen) {
          activeParent = li;
          bestLen = linkPath.length;
        }
      });
    }

    // No editor: forçar exibição do primeiro item-com-filhos como "ativo"
    // mesmo sem URL match — assim o preview sempre mostra a barra in-flow.
    if (!activeParent && isEditor) {
      activeParent = widget.querySelector('.elementor-nav-menu > li.menu-item-has-children');
    }

    if (!activeParent) return;

    // Injetar barra in-flow após o header (rola com a página — não é sticky)
    var activeSub = activeParent.querySelector('.sub-menu');
    if (activeSub && header) {
      // Remover barra antiga (re-init no editor após edição)
      var oldBar = document.querySelector('.bit-subnav-bar');
      if (oldBar) oldBar.remove();

      var bar = document.createElement('div');
      bar.className = 'bit-subnav-bar';

      // Clonar itens do sub-menu para a barra in-flow
      var ul = document.createElement('ul');
      activeSub.querySelectorAll('li').forEach(function(li) {
        var cloned = li.cloneNode(true);
        var a = cloned.querySelector('a');
        if (a) { a.dataset.text = a.textContent.trim(); }
        ul.appendChild(cloned);
      });
      bar.appendChild(ul);

      // Herdar CSS vars do widget — mesma lista BIS_VARS da hover-bar
      copyVars(bar);

      // Inserir após o header (in-flow — rola com a página naturalmente)
      header.insertAdjacentElement('afterend', bar);
    }
  }

  // ── Cleanup: remover .bit-hover-bar e .bit-subnav-bar antes de re-init ─
  // Necessário no editor: Elementor re-renderiza widget após cada mudança,
  // chamando init() de novo. Sem cleanup, acumula múltiplas barras.
  function cleanup() {
    document.querySelectorAll('.bit-hover-bar, .bit-subnav-bar').forEach(function(el) { el.remove(); });
  }

  function reinit() {
    cleanup();
    init();
  }

  if (document.readyState !== 'loading') {
    init();
  } else {
    document.addEventListener('DOMContentLoaded', init);
  }

  // No editor: o widget é renderizado VIA JS pelo Elementor depois do
  // DOMContentLoaded — o init inicial pode rodar antes do widget existir.
  // Esperar elementorFrontend ficar disponível e registrar re-render hook.
  function attachEditorHooks() {
    if (!window.elementorFrontend || !window.elementorFrontend.hooks) {
      return setTimeout(attachEditorHooks, 200);
    }
    window.elementorFrontend.hooks.addAction(
      'frontend/element_ready/nav-menu.default',
      function () { setTimeout(reinit, 100); }
    );
    // Forçar primeira renderização caso o widget já esteja no DOM
    setTimeout(reinit, 200);
  }

  // Detectar editor por: contexto isEditor OU URL contém elementor-preview
  // (window.elementorFrontend pode não estar pronto ao avaliar `isEditor` inicial)
  if (window.location.search.indexOf('elementor-preview') !== -1 ||
      window.parent !== window) {
    attachEditorHooks();
  }
})();
</script>
<?php }
