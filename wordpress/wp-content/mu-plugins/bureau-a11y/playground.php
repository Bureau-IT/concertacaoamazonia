<?php
/**
 * Bureau A11y Playground
 *
 * Carrega WordPress completo e deixa o mu-plugin injetar o painel via wp_footer.
 * URL: /wp-content/mu-plugins/bureau-a11y/playground.php
 */
define( 'WP_USE_THEMES', false );
require_once __DIR__ . '/../../../wp-load.php';

// Dispara wp_enqueue_scripts para que bureau_a11y_enqueue_assets registre os assets
do_action( 'wp_enqueue_scripts' );
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bureau A11y v<?php echo esc_html( BUREAU_A11Y_VERSION ); ?> + VLibras — Playground</title>
<?php wp_head(); ?>
<style>
/* ============================================================
   PLAYGROUND CHROME
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }

body {
    margin: 0;
    font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
    background: #1a2e20;
    color: #F0EDE1;
    min-height: 100vh;
}

#pg-toolbar {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 99999999;
    background: rgba(9,28,16,0.97);
    border-bottom: 1px solid rgba(189,248,57,0.2);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 1rem;
    font-size: 12px;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}

#pg-toolbar strong { color: #BDF839; font-size: 13px; margin-right: 0.25rem; }

.pg-btn {
    padding: 0.3rem 0.75rem;
    border-radius: 6px;
    border: 1px solid rgba(189,248,57,0.3);
    background: rgba(189,248,57,0.08);
    color: #BDF839;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
    font-family: inherit;
}
.pg-btn:hover { background: rgba(189,248,57,0.2); }
.pg-btn.active { background: rgba(189,248,57,0.25); box-shadow: 0 0 0 1px #BDF839; }

.pg-sep { width: 1px; height: 18px; background: rgba(255,255,255,0.1); flex-shrink: 0; }
.pg-tag {
    font-size: 10px;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    background: rgba(255,255,255,0.06);
    color: rgba(240,237,225,0.5);
    white-space: nowrap;
}
.pg-tag.ok  { background: rgba(189,248,57,0.12); color: #BDF839; }
.pg-tag.err { background: rgba(255,80,80,0.12);  color: #ff8080; }

#pg-page {
    padding: 5rem 2rem 4rem;
    max-width: 820px;
    margin: 0 auto;
}

#pg-page h1 { font-size: 2rem; color: #BDF839; margin-bottom: 0.5rem; }
#pg-page h2 { font-size: 1.2rem; color: #F0EDE1; margin: 2rem 0 0.5rem; }
#pg-page p  { color: rgba(240,237,225,0.75); line-height: 1.7; margin-bottom: 1rem; }
#pg-page a  { color: #BDF839; }
#pg-page img { max-width: 100%; border-radius: 8px; }

.pg-card {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1rem;
}

body.pg-mobile { max-width: 375px; margin: 0 auto;
    border-left:  1px solid rgba(189,248,57,0.15);
    border-right: 1px solid rgba(189,248,57,0.15); }
body.pg-light  { background: #f5f5f0; color: #1a2e20; }
body.pg-light #pg-page p  { color: #444; }
body.pg-light #pg-page h1 { color: #0B4334; }
body.pg-light #pg-page h2 { color: #1a2e20; }
</style>
</head>
<body>

<!-- Toolbar -->
<div id="pg-toolbar">
    <strong>A11y v<?php echo esc_html( BUREAU_A11Y_VERSION ); ?> + VLibras</strong>
    <span class="pg-tag">Playground</span>
    <div class="pg-sep"></div>
    <button class="pg-btn" onclick="document.body.classList.toggle('pg-mobile');this.classList.toggle('active')">Mobile 375px</button>
    <button class="pg-btn" onclick="document.body.classList.toggle('pg-light');this.classList.toggle('active')">Fundo claro</button>
    <button class="pg-btn" onclick="pgTogglePanel()">Toggle A11y</button>
    <div class="pg-sep"></div>
    <span class="pg-tag" id="pg-rv-tag">ResponsiveVoice: —</span>
    <span class="pg-tag" id="pg-vl-tag">VLibras: —</span>
</div>

<!-- Simulated page structure -->
<header data-elementor-type="header" style="background:rgba(9,28,16,0.7);padding:0.75rem 1.5rem;border-bottom:1px solid rgba(189,248,57,0.2);display:flex;align-items:center;justify-content:space-between;margin-top:40px;">
    <strong style="color:#BDF839">Concertação Amazônia [header]</strong>
    <nav>
        <a href="#" style="color:#F0EDE1;margin:0 0.5rem">Home</a>
        <a href="#" style="color:#F0EDE1;margin:0 0.5rem">Sobre</a>
        <a href="#" style="color:#F0EDE1;margin:0 0.5rem">Cultura</a>
    </nav>
</header>

<div id="pg-page">
    <h1>Concertação Amazônia</h1>
    <p>Playground do Bureau A11y + VLibras. O painel é injetado pelo mu-plugin real via <code>wp_footer</code> — sempre sincronizado com o PHP de produção.</p>

    <div class="pg-card">
        <h2>Tipografia</h2>
        <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Vivamus lacinia odio vitae vestibulum. Donec in efficitur leo, in commodo orci.</p>
        <p><a href="#">Link para testar "Destacar links"</a> — mais <a href="#">outro link</a>.</p>
        <p>Texto com <strong>negrito</strong> e <em>itálico</em> para testar dislexia e espaçamento.</p>
    </div>

    <div class="pg-card">
        <h2>Parágrafo longo (TTS hover)</h2>
        <p>A Concertação pela Amazônia é uma iniciativa que reúne organizações da sociedade civil, movimentos sociais, povos indígenas e comunidades tradicionais em torno de uma agenda comum para a proteção e o desenvolvimento sustentável da Amazônia brasileira.</p>
        <p>O objetivo central é construir uma visão compartilhada sobre o futuro da região, promovendo o diálogo entre diferentes atores e fortalecendo a capacidade de incidência coletiva nas políticas públicas nacionais e internacionais que afetam o bioma.</p>
    </div>

    <div class="pg-card">
        <h2>Imagem</h2>
        <img src="https://picsum.photos/seed/amazonia/600/200" alt="Floresta Amazônica (imagem de teste)" loading="lazy">
        <p style="font-size:11px;opacity:0.5;margin-top:0.5rem">Imagem de teste para "Sem imagens"</p>
    </div>

    <div class="pg-card">
        <h2>Checklist VLibras</h2>
        <p>
            ☐ Botão VLibras aparece no lado direito (posição original)<br>
            ☐ Clicar no botão abre o painel VLibras normalmente<br>
            ☐ Minimizar via X interno fecha o painel<br>
            ☐ Clicar no botão novamente reabre o painel<br>
            ☐ A11y não interfere no comportamento do VLibras
        </p>
    </div>
</div>

<footer data-elementor-type="footer" style="background:rgba(9,28,16,0.7);padding:0.75rem 1.5rem;border-top:1px solid rgba(189,248,57,0.15);text-align:center;font-size:12px;color:rgba(240,237,225,0.5);margin-top:2rem;">
    Footer — Bureau A11y Playground
</footer>

<script>
function pgTogglePanel() {
    var p = document.getElementById('bureau-a11y-panel');
    var t = document.getElementById('bureau-a11y-trigger');
    if (!p) return;
    var hidden = p.getAttribute('aria-hidden') === 'true';
    p.setAttribute('aria-hidden', hidden ? 'false' : 'true');
    if (t) t.setAttribute('aria-expanded', hidden ? 'true' : 'false');
}

// Status tags
window.addEventListener('load', function () {
    // ResponsiveVoice
    var rvTag = document.getElementById('pg-rv-tag');
    if (typeof responsiveVoice !== 'undefined' && rvTag) {
        rvTag.textContent = 'ResponsiveVoice: OK';
        rvTag.className = 'pg-tag ok';
    } else if (rvTag) {
        rvTag.textContent = 'ResponsiveVoice: offline';
        rvTag.className = 'pg-tag err';
    }

    // VLibras — poll até o widget aparecer
    var vlTag = document.getElementById('pg-vl-tag');
    if (vlTag) {
        vlTag.textContent = 'VLibras: aguardando…';
        var attempts = 0;
        var t = setInterval(function () {
            attempts++;
            if (document.querySelector('[vw-access-button] img')) {
                vlTag.textContent = 'VLibras: OK';
                vlTag.className = 'pg-tag ok';
                clearInterval(t);
            } else if (attempts > 30) {
                vlTag.textContent = 'VLibras: timeout';
                vlTag.className = 'pg-tag err';
                clearInterval(t);
            }
        }, 500);
    }
});
</script>

<?php wp_footer(); ?>
</body>
</html>
