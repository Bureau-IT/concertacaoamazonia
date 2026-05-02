# Theme Modularization Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Desmembrar `style.css` (~1511 linhas) e `functions.php` (~677 linhas) do tema filho `hello-elementor-child` em módulos separados por responsabilidade — globais reutilizáveis vs. específicos por página/plugin.

**Architecture:**
- `style.css` mantém apenas o header WordPress obrigatório + variáveis CSS `:root` (configuração global de cores)
- Arquivos CSS modulares em `css/` com carregamento condicional via PHP
- `functions.php` mantém apenas o kernel global; lógica específica vai para `inc/`

**Tech Stack:** PHP 8.3, WordPress multisite (blog_id 1+2), CSS custom properties, `wp_enqueue_scripts`

---

## Inventário atual

### style.css — seções identificadas

| Seção | Linhas | Natureza |
|-------|--------|----------|
| 1. Variáveis `:root` (Elementor Global Colors) | 21–221 | **Global** |
| 2. Estilos base (html, body, tipografia, links, scrollbar) | 224–317 | **Global** |
| 3. Componentes (labels) | 320–328 | **Global** |
| 4. Plugin: The Events Calendar | 330–666 | Plugin-específico |
| 5. Plugin: JetEngine / JetSearch | 668–746 | Plugin-específico |
| 6. Admin: WP Admin Bar | 748–1011 | Admin-específico |
| 7. Componentes especiais (Cookie Consent básico) | 1012–1023 | Plugin-específico |
| 8. Páginas específicas (home banner, pêndulo, estudos, artistas) | 1025–1199 | **Página-específico** |
| 9. Header & Menu | 1201–1386 | Componente global |
| 10. JetMenu Painel (placeholder vazio) | 1388–1392 | — |
| 11. Header /cultura/ (blog_id=2) + WPML | 1393–1434 | Subsite-específico |
| 12. Complianz Cookie Banner | 1435–1511 | Plugin-específico |

### functions.php — seções identificadas

| Seção | Natureza |
|-------|----------|
| Enqueue parent+child styles + slick.js | **Global kernel** |
| Custom fonts (Franie, Just Sans, Roboto) | **Global kernel** |
| Viewport fix | **Global kernel** |
| Admin CSS enqueue | **Global kernel** |
| JetEngine: maps-listings + CCT alphabet filter | **Global kernel** |
| TEC: hooks + event display functions (5 funções) | Plugin-específico → `inc/events-calendar.php` |
| Gravity Forms CSS (is_page contato) | Página-específico → `inc/page-contato.php` |
| SVG shortcode + bureau_logo shortcode | **Global kernel** |
| Performance: dequeue homepage assets | **Global kernel** |
| Performance: dequeue unused Jet assets | **Global kernel** |
| Lazy loading | **Global kernel** |
| Elementor Pro SVG bug fix | **Global kernel** |
| Multisite: shared upload symlinks | **Global kernel** |

---

## Estrutura alvo

```
hello-elementor-child/
├── style.css                  # SOMENTE: header WP + @import parent + :root vars
├── functions.php              # Kernel global (enqueue manager + hooks globais)
├── css/
│   ├── base.css               # html, body, tipografia, links, scrollbar, labels
│   ├── header-menu.css        # Seções 9 + 11 (header, menu desktop/mobile, cultura, WPML)
│   ├── plugins/
│   │   ├── tec.css            # The Events Calendar (seção 4 completa)
│   │   ├── jetengine.css      # JetEngine/JetSearch (seção 5)
│   │   └── complianz.css      # Cookie banner (seção 12 + 7)
│   ├── admin/
│   │   └── admin-bar.css      # WP Admin Bar (seção 6)
│   └── pages/
│       ├── home.css           # Seções 8.1 + 8.2 (banner, pêndulo)
│       ├── estudos.css        # Seções 8.3 + 8.4 (listings, destaque)
│       └── artistas.css       # Seções 8.5 + 8.6 (dynamic link, single artist)
└── inc/
    ├── events-calendar.php    # Todas as funções PHP do TEC
    └── page-contato.php       # Gravity Forms CSS da página contato
```

---

## Task 1: Criar arquivos CSS modulares (extrair do style.css)

**Files:**
- Create: `css/base.css`
- Create: `css/header-menu.css`
- Create: `css/plugins/tec.css`
- Create: `css/plugins/jetengine.css`
- Create: `css/plugins/complianz.css`
- Create: `css/admin/admin-bar.css`
- Create: `css/pages/home.css`
- Create: `css/pages/estudos.css`
- Create: `css/pages/artistas.css`

### Step 1: Criar estrutura de diretórios

```bash
cd wordpress/wp-content/themes/hello-elementor-child
mkdir -p css/plugins css/admin css/pages inc
```

### Step 2: Criar `css/base.css`

Conteúdo: seções 2.1 a 2.6 + seção 3 do `style.css` atual.

```css
/* ==========================================================================
   BASE STYLES
   Estilos globais de html, body, tipografia, links, seleção, scrollbar
   ========================================================================== */

/* --- HTML & Body --- */
html {
    font-size         : 16px;
    scrollbar-gutter  : stable;
    scroll-behavior   : smooth;
}

@media (prefers-reduced-motion: no-preference) {
    :has(:target) {
        scroll-behavior   : smooth;
        scroll-padding-top: 3rem;
    }
}

html body.elementor-default {
    background-color: var(--ucpa-color-offwhite);
    overflow-x      : hidden;
    position        : relative;
}

@media (max-width: 768px) {
    html, body {
        overflow-x: hidden;
    }
}

html body.elementor-kit-2553.elementor-page-26826 {
    background-color: var(--ucpa-color-offwhite);
}

/* --- Tipografia --- */
h1, h2, h3, h4, h5, h6 {
    text-wrap: balance;
}

#content .tituloPagina {
    margin-top: 5rem;
}

figcaption {
    text-align: center;
    color     : var(--e-global-color-text);
}

/* --- Links --- */
html body a[href*="mailto"]:hover {
    color: currentColor;
}

.elementor-kit-2553 a:hover {
    color: var(--e-global-color-text);
}

/* --- Seleção de Texto --- */
::selection {
    background-color: var(--ucpa-color-white);
    color           : var(--ucpa-color-accent);
}

@supports (color: color-mix(in oklch, black 50%, white)) {
    ::selection {
        background-color: color-mix(in oklch, var(--ucpa-color-main) 90%, black 10%);
        color           : color-mix(in oklch, var(--ucpa-color-main) 10%, white 90%);
    }
}

/* --- Outline --- */
* {
    outline-color: var(--e-global-color-accent);
}

/* --- Scrollbar --- */
::-webkit-scrollbar {
    width    : 7px;
    transform: width 1s ease;
}

::-webkit-scrollbar-track {
    background   : var(--ucpa-color-white);
    border-radius: 0;
}

::-webkit-scrollbar-thumb {
    background   : var(--ucpa-color-accent);
    border-radius: 0;
}

::-webkit-scrollbar-thumb:hover {
    background: var(--ucpa-color-accent);
}

/* --- Labels --- */
html body.elementor-kit-2553 label {
    color: var(--ucpa-color-main, #000);
}
```

### Step 3: Criar `css/header-menu.css`

Conteúdo: seção 9 (linhas 1201–1386) + seção 11 (linhas 1393–1434) do `style.css` atual.

Copiar exatamente o conteúdo das seções 9 e 11 do style.css para este arquivo.

### Step 4: Criar `css/plugins/tec.css`

Conteúdo: seção 4 completa (linhas 330–666) do `style.css` atual.

Copiar exatamente o conteúdo da seção 4.

### Step 5: Criar `css/plugins/jetengine.css`

Conteúdo: seção 5 (linhas 668–746) do `style.css` atual.

Copiar exatamente o conteúdo da seção 5.

### Step 6: Criar `css/plugins/complianz.css`

Conteúdo: seção 7 (linhas 1012–1023) + seção 12 (linhas 1435–1511) do `style.css` atual.

```css
/* ==========================================================================
   COMPLIANZ - Cookie Banner
   Paleta: Primary #174538 | Petróleo #0F4C5C | Off-white #FFFCF7 | Bege #EFEDE0
   ========================================================================== */

/* --- Cookie Consent genérico (plugin anterior) --- */
html body #cn-accept-cookie,
.cookie-notice-container #cn-accept-cookie {
    background: var(--ucpa-color-accent);
    color     : var(--ucpa-color-white);
}

/* [Seguido pelo conteúdo completo da seção 12] */
```

### Step 7: Criar `css/admin/admin-bar.css`

Conteúdo: seção 6 completa (linhas 748–1011) do `style.css` atual.

### Step 8: Criar `css/pages/home.css`

Conteúdo: seções 8.1 e 8.2 (linhas 1029–1096) do `style.css` atual.

```css
/* ==========================================================================
   HOME PAGE
   ========================================================================== */

/* --- Banner --- */
/* [conteúdo seção 8.1] */

/* --- Pêndulo --- */
/* [conteúdo seção 8.2] */
```

### Step 9: Criar `css/pages/estudos.css`

Conteúdo: seções 8.3 e 8.4 (linhas 1098–1145) do `style.css` atual.

### Step 10: Criar `css/pages/artistas.css`

Conteúdo: seções 8.5 e 8.6 (linhas 1147–1199) do `style.css` atual.

### Step 11: Commit inicial dos arquivos CSS

```bash
git add css/
git commit -m "feat(theme): criar módulos CSS — base, header, plugins, pages"
```

---

## Task 2: Atualizar style.css — manter somente vars + header

**Files:**
- Modify: `style.css`

### Step 1: Reescrever style.css

O novo `style.css` deve conter SOMENTE:
1. O header obrigatório do WordPress (Theme Name, etc.)
2. O `@import` do tema pai
3. Toda a seção 1 (`:root` com as variáveis CSS) — linhas 12–221
4. Um segundo bloco `:root` com variáveis do menu (atual linha 1208–1220) — mover para cá

**Nota crítica:** As variáveis CSS do menu (`--submenu-texto`, etc.) estão na seção 9 do style.css. Movê-las para o `:root` principal no style.css, já que são variáveis globais.

```css
/*
Theme Name: Hello Elementor Child
Description: Tema filho do Hello Elementor com customizações para o site
Author: Daniel Cambría + Bureau IT
Version: 2.0.0
Template: hello-elementor
*/

@import url("../hello-elementor/style.css");

/* ==========================================================================
   VARIÁVEIS CSS GLOBAIS
   Sincronizado com: Elementor Kit ID 2553
   Fonte da verdade: Elementor > Site Settings > Global Colors
   ========================================================================== */

:root {
    /* [TODO: colar aqui TODA a seção 1 do style.css atual — linhas 22-221] */
    /* [TODO: adicionar as variáveis do menu que estavam na seção 9] */

    /* =========================================
       VARIÁVEIS DO MENU (movidas da seção 9)
       ========================================= */
    --submenu-texto                  : var(--e-global-color-6f8d79f, #D6F395);
    --submenu-cultura-texto          : var(--e-global-color-6f8d79f, #D6F395);
    --submenu-block-size             : 85px;
    --submenu-gap                    : 0rem;
    --submenu-background             : var(--header-background-submenu, #900042);
    --submenu-cultura-background     : var(--header-background-submenu-cultura, #F0C400);
    --menu-mobile-background         : var(--main-color, #900042);
    --menu-mobile-cultura-background : var(--header-background-submenu-cultura, #F0C400);
    --menu-mobile-cultura-text       : var(--main-color, #900042);
    --menu-mobile-background-hover   : color-mix(in lab, var(--header-background-submenu, #900042) 80%, black 20%);
    --menu-mobile-background-text    : var(--white, #FFFFFF);
}
```

### Step 2: Verificar que style.css não tem mais CSS fora de :root

```bash
# Contar linhas depois da refatoração — deve ser ~220 linhas
wc -l wordpress/wp-content/themes/hello-elementor-child/style.css
```

Esperado: < 230 linhas.

### Step 3: Commit

```bash
git add wordpress/wp-content/themes/hello-elementor-child/style.css
git commit -m "refactor(theme): style.css agora contém apenas vars :root globais"
```

---

## Task 3: Criar arquivos PHP modulares (extrair de functions.php)

**Files:**
- Create: `inc/events-calendar.php`
- Create: `inc/page-contato.php`

### Step 1: Criar `inc/events-calendar.php`

Mover para este arquivo todas as funções TEC de `functions.php`:
- `bureau_it_custom_event_display()` + seus `add_action()`
- Filtros `tec_events_get_time_range_separator` e `tec_events_get_date_time_separator`
- `bureau_it_is_edital()`
- `bureau_it_filter_edital_schedule()` + `add_filter()`
- `bureau_it_filter_edital_short_schedule()` + `add_filter()`
- `bureau_it_format_event_date()`
- O `require_once` do `tribe/hooks.php` (atualmente na linha 244)

```php
<?php
/**
 * The Events Calendar — hooks e funções customizadas
 *
 * @package HelloElementorChild
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Carregar hooks customizados do TEC (templates override)
$tribe_hooks_file = get_stylesheet_directory() . '/tribe/hooks.php';
if (file_exists($tribe_hooks_file)) {
    require_once $tribe_hooks_file;
}

// [Colar todas as funções TEC aqui]
```

### Step 2: Criar `inc/page-contato.php`

Mover a função `bureau_it_gform_contact_css()` com seu `add_action`.

```php
<?php
/**
 * Página Contato — Gravity Forms CSS customizado
 *
 * @package HelloElementorChild
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_head', 'bureau_it_gform_contact_css');
function bureau_it_gform_contact_css() {
    if (!is_page('contato') && !is_page('contact')) {
        return;
    }
    // [Colar o CSS inline aqui]
}
```

### Step 3: Commit

```bash
git add inc/
git commit -m "feat(theme): extrair TEC e page-contato para inc/"
```

---

## Task 4: Atualizar functions.php — enqueue manager + require inc/

**Files:**
- Modify: `functions.php`

### Step 1: Substituir o enqueue de CSS

O `hello_elementor_child_enqueue_scripts()` atual só carrega `style.css`. Agora precisa carregar os módulos CSS com condições corretas.

```php
add_action('wp_enqueue_scripts', 'hello_elementor_child_enqueue_scripts');
function hello_elementor_child_enqueue_scripts() {
    $theme_uri = get_stylesheet_directory_uri();
    $ver       = wp_get_theme()->get('Version');

    // 1. Parent theme
    wp_enqueue_style(
        'hello-elementor-parent',
        get_template_directory_uri() . '/style.css'
    );

    // 2. Child theme vars (style.css = apenas :root vars)
    wp_enqueue_style(
        'hello-elementor-child',
        get_stylesheet_directory_uri() . '/style.css',
        ['hello-elementor-parent'],
        $ver
    );

    // 3. Base global
    wp_enqueue_style(
        'conc-base',
        "$theme_uri/css/base.css",
        ['hello-elementor-child'],
        $ver
    );

    // 4. Header & Menu
    wp_enqueue_style(
        'conc-header-menu',
        "$theme_uri/css/header-menu.css",
        ['conc-base'],
        $ver
    );

    // 5. Plugin: The Events Calendar (só se ativo)
    if (class_exists('Tribe__Events__Main')) {
        wp_enqueue_style(
            'conc-tec',
            "$theme_uri/css/plugins/tec.css",
            ['conc-base'],
            $ver
        );
    }

    // 6. Plugin: JetEngine / JetSearch (só se ativo)
    if (class_exists('Jet_Engine')) {
        wp_enqueue_style(
            'conc-jetengine',
            "$theme_uri/css/plugins/jetengine.css",
            ['conc-base'],
            $ver
        );
    }

    // 7. Plugin: Complianz (só se ativo)
    if (class_exists('COMPLIANZ')) {
        wp_enqueue_style(
            'conc-complianz',
            "$theme_uri/css/plugins/complianz.css",
            ['conc-base'],
            $ver
        );
    }

    // 8. Home page
    if (is_front_page() || is_home()) {
        wp_enqueue_style(
            'conc-page-home',
            "$theme_uri/css/pages/home.css",
            ['conc-base'],
            $ver
        );
    }

    // 9. Estudos (post type ou página por slug)
    if (is_singular('estudos') || is_page('estudos') || is_post_type_archive('estudos')) {
        wp_enqueue_style(
            'conc-page-estudos',
            "$theme_uri/css/pages/estudos.css",
            ['conc-base'],
            $ver
        );
    }

    // 10. Artistas / Linha das Artes
    if (is_singular('linha-das-artes') || is_singular('artistas')) {
        wp_enqueue_style(
            'conc-page-artistas',
            "$theme_uri/css/pages/artistas.css",
            ['conc-base'],
            $ver
        );
    }

    // Slick.js (footer)
    add_action('wp_footer', 'bureau_it_print_slick_js', 1);
}
```

**ATENÇÃO:** Verificar os slugs/post types corretos para estudos e artistas no banco antes de usar no condicional. Use `std wp post-type list` ou query no DB para confirmar.

### Step 2: Adicionar enqueue do admin-bar.css (substituir admin_enqueue_scripts)

O atual `bureau_it_admin_css()` carrega `admin-style.css`. Separar a admin bar (frontend) do CSS de backend:

```php
// Admin bar no frontend (visível quando logado)
add_action('wp_enqueue_scripts', 'bureau_it_enqueue_admin_bar_css', 999);
function bureau_it_enqueue_admin_bar_css() {
    if (!is_admin_bar_showing()) {
        return;
    }
    wp_enqueue_style(
        'conc-admin-bar',
        get_stylesheet_directory_uri() . '/css/admin/admin-bar.css',
        ['hello-elementor-child'],
        wp_get_theme()->get('Version')
    );
}
```

### Step 3: Remover as funções TEC e contato do functions.php

Remover:
- `require_once $tribe_hooks_file` (agora em inc/events-calendar.php)
- `bureau_it_custom_event_display()` e seus add_action
- Filtros de separadores TEC
- `bureau_it_is_edital()`
- `bureau_it_filter_edital_schedule()` e `bureau_it_filter_edital_short_schedule()`
- `bureau_it_format_event_date()`
- `bureau_it_gform_contact_css()`

### Step 4: Adicionar requires no início de functions.php (após o guard ABSPATH)

```php
// Módulos específicos
require_once get_stylesheet_directory() . '/inc/events-calendar.php';
require_once get_stylesheet_directory() . '/inc/page-contato.php';
```

### Step 5: Verificar que nenhuma função ficou duplicada

```bash
grep -n "function bureau_it_" wordpress/wp-content/themes/hello-elementor-child/functions.php | wc -l
grep -n "function bureau_it_" wordpress/wp-content/themes/hello-elementor-child/inc/events-calendar.php | wc -l
grep -n "function bureau_it_" wordpress/wp-content/themes/hello-elementor-child/inc/page-contato.php | wc -l
```

### Step 6: Commit

```bash
git add wordpress/wp-content/themes/hello-elementor-child/functions.php
git commit -m "refactor(theme): functions.php como enqueue manager, TEC e contato em inc/"
```

---

## Task 5: Verificar no browser — sem regressões

### Step 1: Flush caches

```bash
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh all-cache-flush
```

### Step 2: Verificar páginas críticas

Abrir no browser (https://cambrasmax.local:8484) e confirmar visual OK:

- [ ] Home (`/`)
- [ ] Participantes (`/sobre-nos/participantes/`)
- [ ] Eventos (`/eventos-calendario/`)
- [ ] Uma página de artista ou linha-das-artes
- [ ] `/cultura/` (subsite blog_id=2)
- [ ] Qualquer página com formulário de contato

### Step 3: Confirmar CSS carregado no DevTools

Em cada página, Network > Filter: `.css` — verificar que os módulos esperados aparecem:
- `style.css` (vars only)
- `base.css`
- `header-menu.css`
- Plugins condicionais (`tec.css` só em /eventos/, etc.)
- Pages condicionais (`home.css` só na home)

### Step 4: Verificar admin bar CSS

Logar e confirmar que a admin bar ainda tem os estilos customizados.

### Step 5: Commit final

```bash
git add .
git commit -m "feat(theme): v2.0.0 — modularização CSS/PHP (base, plugins, pages, inc)"
```

---

## Notas de Implementação

### Sobre o carregamento condicional de estudos/artistas

Os slugs de post type precisam ser confirmados com:

```bash
docker exec -u www-data www2-concertacao-dev-wordpress wp post-type list --fields=name,label
```

Se a página de estudos for uma Elementor page (não CPT), usar `is_page()` com o ID ou slug dela.

### Sobre o Elementor Kit e inline CSS

O Elementor usa `css_print_method=internal` (inline). Isso significa que as variáveis do `:root` no `style.css` são complementadas pelo CSS gerado pelo Elementor Kit. A ordem de carregamento importa — o `style.css` deve ser carregado ANTES do CSS do Elementor para que as variáveis globais estejam disponíveis.

### Sobre múltiplos :root no style.css

O segundo bloco `:root` das variáveis do menu (atualmente na seção 9) deve ser **consolidado** no `:root` principal durante a Task 2. CSS não tem problema com múltiplos `:root` (eles se somam), mas é mais limpo ter um único bloco.

### Compatibilidade com subsites (/cultura/)

O subsite blog_id=2 usa o mesmo tema filho. Os arquivos `css/header-menu.css` já contém os estilos da seção 11 (cultura), então funciona automaticamente. Se no futuro o cultura tiver estilos muito distintos, criar `css/pages/cultura.css` com condicional `get_current_blog_id() === 2`.

---

## Rollback

Se algo quebrar:

```bash
# Ver o estado anterior
git log --oneline -5

# Reverter para antes das tasks
git checkout HEAD~N -- wordpress/wp-content/themes/hello-elementor-child/style.css
git checkout HEAD~N -- wordpress/wp-content/themes/hello-elementor-child/functions.php
```

Os arquivos CSS/PHP antigos ficam preservados no histórico do git até o cleanup final.
