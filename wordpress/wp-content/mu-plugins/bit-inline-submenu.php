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
 * Version:     1.4.1
 * Author:      Bureau IT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BIT_INLINE_SUBMENU_VERSION', '1.4.1' );

// ── CSS ──────────────────────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'bit-inline-submenu',
        WPMU_PLUGIN_URL . '/bit-inline-submenu.css',
        [],
        filemtime( WPMU_PLUGIN_DIR . '/bit-inline-submenu.css' )
    );
} );


// ── JS ───────────────────────────────────────────────────────────────────────
add_action( 'wp_footer', function () { ?>
<script id="bit-inline-submenu-js">
(function () {
  function init() {
    var widget = document.querySelector('.menu-submenu-inline');
    if (!widget) return;

    // ── Calcular posição top = altura do header ────────────────────────────
    var header = document.querySelector('.elementor-location-header');
    function updateTop() {
      if (!header) return;
      var h = header.getBoundingClientRect().height;
      document.documentElement.style.setProperty('--bit-submenu-top', h + 'px');
    }
    updateTop();

    if (window.ResizeObserver && header) {
      new ResizeObserver(updateTop).observe(header);
    }

    // ── Hover: div.bit-hover-bar no body ───────────────────────────────────
    // O CSS do Elementor bloqueia display nos .sub-menu internos com alta
    // especificidade. Solução: criar um div novo no body — Elementor não tem
    // regras para esse elemento, sem conflito de CSS.
    var hoverTimeout = null;
    var hoverBar = document.createElement('div');
    hoverBar.className = 'bit-hover-bar';
    document.body.appendChild(hoverBar);

    // Herdar CSS vars do widget (cores configuradas pelo child theme)
    var wComputed = getComputedStyle(widget);
    ['--bis-bg','--bis-bg-hover','--bis-text','--bis-text-hover','--bis-text-hover-weight',
     '--bis-text-active','--bis-border-active','--bit-submenu-height'].forEach(function(v) {
      var val = wComputed.getPropertyValue(v).trim();
      if (val) hoverBar.style.setProperty(v, val);
    });

    function openHover(li) {
      clearTimeout(hoverTimeout);

      var sub = li.querySelector('.sub-menu');
      if (!sub) return;

      // Clonar itens do sub-menu para a barra hover
      hoverBar.innerHTML = '';
      var ul = document.createElement('ul');
      sub.querySelectorAll('li').forEach(function(item) {
        ul.appendChild(item.cloneNode(true));
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

    if (!activeParent) return;

    // Injetar barra in-flow após o header (rola com a página — não é sticky)
    var activeSub = activeParent.querySelector('.sub-menu');
    if (activeSub && header) {
      var bar = document.createElement('div');
      bar.className = 'bit-subnav-bar';

      // Clonar itens do sub-menu para a barra in-flow
      var ul = document.createElement('ul');
      activeSub.querySelectorAll('li').forEach(function(li) {
        ul.appendChild(li.cloneNode(true));
      });
      bar.appendChild(ul);

      // Herdar CSS vars do widget
      ['--bis-bg','--bis-bg-hover','--bis-text','--bis-text-hover','--bis-text-hover-weight',
       '--bis-text-active','--bis-border-active','--bit-submenu-height'].forEach(function(v) {
        var val = wComputed.getPropertyValue(v).trim();
        if (val) bar.style.setProperty(v, val);
      });

      // Inserir após o header (in-flow — rola com a página naturalmente)
      header.insertAdjacentElement('afterend', bar);
    }
  }

  if (document.readyState !== 'loading') {
    init();
  } else {
    document.addEventListener('DOMContentLoaded', init);
  }
})();
</script>
<?php }, 20 );
