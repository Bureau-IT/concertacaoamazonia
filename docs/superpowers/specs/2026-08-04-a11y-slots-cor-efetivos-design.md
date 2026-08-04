# BIT A11y — fazer os 8 slots de cor do admin valerem de verdade

**Data:** 2026-08-04
**Autor:** Daniel Cambría
**Escopo:** dev primeiro; prod em etapa separada
**Arquivos:** `wordpress/wp-content/mu-plugins/bureau-a11y/bureau-a11y.css`, `wordpress/wp-content/mu-plugins/bureau-a11y.php`

---

## 1. Problema

A tela `Aparência → Acessibilidade` (`admin-colors.php`) oferece 8 slots semânticos de cor
para o painel a11y. O pipeline PHP funciona — verificado empiricamente no `<head>` de dev:

```
blog 1: <style id='bureau-a11y-color-overrides'>:root{--ba-forest:#474747;
        --ba-override-surface:rgba(255,255,255,0.06);--ba-electric:#474747;
        --ba-electric-glow:#FFFFFF;--ba-text:#DADADA;--ba-muted:#DADADA;
        --ba-border:#1C1C1C;--ba-trigger-bg:#1C1C1C;}</style>
```

Mas `bureau-a11y.css` consome pouco dessas vars e escreve cor literal na maior parte do
chrome. Contagem no arquivo (v2.5.22 do header; `BUREAU_A11Y_CSS_VERSION = 2.8.0`):

| Slot no admin | var | usos no CSS | veredito |
|---|---|---|---|
| Fundo do painel | `--ba-forest` | 3 | funciona |
| Texto principal | `--ba-text` | 12 | funciona (+4 offwhite literal) |
| Texto secundário | `--ba-muted` | 12 | funciona (+4 literal) |
| Fundo dos toggles | `--ba-surface` | 3 | funciona |
| Fundo do botão flutuante | `--ba-trigger-bg` | 2 | funciona |
| **Cor de destaque** | `--ba-electric` | **4** | **inerte na prática** |
| **Brilho do destaque** | `--ba-electric-glow` | **0** | **slot morto** |
| **Bordas e divisórias** | `--ba-border` | **0** | **slot morto** |

Contra isso: **33× `#BDF839`**, **30× `rgba(189,248,57,α)`**, 4× `#F0EDE1`,
4× `rgba(240,237,225,α)`, 1× `#0B4334`, 2× `#005A42`.

Consequência prática: mudar "Cor de destaque" no admin não muda nada visível; mudar
"Bordas e divisórias" ou "Brilho do destaque" também não. O verde-limão `#BDF839`
(cor de marca BIT) aparece no painel independente da paleta do site.

## 2. Objetivo

Substituir os literais do **chrome do painel e dos botões flutuantes** por `var(--ba-*)`,
de forma que os 8 slots do admin passem a ter efeito real — sem alterar a aparência atual
quando os slots estão nos valores que hoje estão hardcoded.

Não-objetivos: mudar a lógica PHP (já correta), tocar prod nesta etapa, refatorar qualquer
coisa fora da família de cor.

## 3. Regras de mapeamento

Quatro regras mecânicas:

| Hardcode | Vira | Slot que passa a valer |
|---|---|---|
| `#BDF839` | `var(--ba-electric)` | Cor de destaque |
| `rgba(189,248,57,α)` | `color-mix(in srgb, var(--ba-electric) α%, transparent)` | Cor de destaque |
| halos `box-shadow: 0 0 Npx rgba(189,248,57,α)` | `var(--ba-electric-glow)` | Brilho do destaque |
| divisórias estruturais `rgba(255,255,255,0.06)` | `var(--ba-border)` | Bordas e divisórias |
| bordas de card, e `background` de hover, com `rgba(255,255,255,α)` | `color-mix(in srgb, var(--ba-text) α%, transparent)` | Texto principal |
| `#F0EDE1` | `var(--ba-text)` | Texto principal |
| `rgba(240,237,225,0.28\|0.35)` | `color-mix(in srgb, var(--ba-muted) α%, transparent)` | Texto secundário |
| `#0B4334` (fundo do toggle ativo) | `color-mix(in srgb, var(--ba-electric) 15%, var(--ba-forest))` | Destaque + Fundo |
| `#005A42` (mini-pill focus) | `var(--ba-trigger-bg)` | Botão flutuante |
| `#ffffff` (borda da mini-pill) | `var(--ba-text)` | Texto principal |

`color-mix(in srgb, …)` é Baseline desde 2023 (Chrome 111, Safari 16.2, Firefox 113) e já
tem precedente no próprio arquivo (`.ba-lh-bar`, linhas ~1497-1503).

### Distinção "divisória estrutural" × "borda de card"

`--ba-border` recebe apenas as **4 linhas que separam regiões do painel**, todas hoje em
`rgba(255,255,255,0.06)`:

| Linha | Seletor | Declaração |
|---|---|---|
| 307 | `.ba-panel__header` | `border-bottom` |
| 437 | `.ba-tab-bar` | `border-bottom` |
| 762 | `.ba-tts-attribution` | `border-top` |
| 892 | `.ba-panel__footer` | `border-top` |

Essas são literalmente "bordas e divisórias" no vocabulário do admin.

Todo o resto do alpha branco — bordas de card (`.ba-toggle` 0.07, `.ba-zoom-wrapper` 0.07,
`.ba-tts-wrapper` 0.07, `.ba-pill` 0.08) e estados hover, incluindo os `background`
(`.ba-toggle:hover` 0.08, `.ba-hide-btn--header:hover` 0.08) e `border-color`
(`.ba-toggle:hover` 0.15) — deriva de `--ba-text` via `color-mix`, preservando exatamente a
escada de alpha atual. Mapear tudo para `--ba-border` colapsaria quatro níveis em um e
achataria a hierarquia visual.

## 4. Fora de escopo (deliberado)

| Linhas | O que é | Por quê fica como está |
|---|---|---|
| 98, 101 | `html.ba-high-contrast` — `#000` / `#fff` / `#ffff00` | O modo Alto Contraste existe para **ignorar** a paleta do site. Tematizar quebra o contrato WCAG. |
| 130 | `html.ba-focus-guide :focus-visible` — `#0060df` | O rótulo do card promete "contorno azul vibrante". |
| 123-126 | `html.ba-highlight-links a` | Decisão do usuário: lime fixo. Precisa se destacar de links já coloridos pelo tema. |
| 1112-1120 | `#bureau-a11y-ruler` (régua de leitura) | Decisão do usuário: lime fixo. |
| 1421-1422 | `html.ba-tts-reading ::selection` | Mesma família — pinta a página, não o painel. |
| 1416 | cursor da lupa (SVG em `data:` URI) | `data:` URI não resolve CSS custom property. |
| 655-656 | pulse âmbar `rgba(255,200,0,α)` | Sinalização própria, fora da família lime. |

Critério: **o que pinta o chrome do painel segue a paleta; o que pinta a página do usuário
mantém cor fixa de alto contraste.**

## 5. Cache-busting

`BUREAU_A11Y_CSS_VERSION` vai de `2.8.0` → `2.9.0` em `bureau-a11y.php`. Sem isso o
browser serve o CSS antigo do cache e a mudança não aparece.

Em dev, `opcache.validate_timestamps=1` + `revalidate_freq=2s` cuida do PHP. Em prod, esta
mudança exigirá `reload` do PHP-FPM e invalidação do CSS no CloudFront — fora desta etapa.

## 6. Validação (empírica, no browser)

**Passo 1 — provar que não houve regressão visual.**
Setar no admin de dev: `Cor de destaque = #BDF839`, `Brilho do destaque = rgba(189,248,57,0.20)`,
`Bordas e divisórias = rgba(255,255,255,0.06)`. Screenshot do painel aberto (aba Visual)
antes e depois do refactor — devem ser equivalentes.

**Passo 2 — provar que o slot passou a valer.**
Trocar `Cor de destaque` para `#B12B79` (o magenta real de prod). Abas, `A+`/`A−`, halos,
toggle ativo e pills devem virar magenta.

Screenshots vão em `tmp/` do site e são removidos ao final (regra do `sites/CLAUDE.md`).

### Nota sobre a paleta de dev

O kit Elementor em dev está em grayscale (`accent = #474747`, `primary = #1C1C1C`) — o
problema conhecido de kit revertido para defaults. O admin de dev hoje aponta
`Cor de destaque → accent`, ou seja `#474747`.

Sem intervenção, o painel em dev ficaria cinza-chumbo monocromático depois do refactor —
tecnicamente correto, visualmente ruim, e inútil para comparação. É exatamente por isso que
o Passo 1 fixa um custom `#BDF839`.

## 7. Etapas posteriores (não incluídas)

1. Copiar `bureau-a11y.css` + `bureau-a11y.php` para `docker-dev/common/mu-plugins/` e
   commitar no repo do server-tools (regra do `sites/CLAUDE.md`).
2. Deploy em prod: rsync/scp, `reload` do PHP-FPM, invalidação do CSS no CloudFront.
   Em prod a paleta do kit está correta, então convém revisar a config dos 8 slots antes —
   com os slots efetivos, escolhas que hoje não têm efeito passarão a ter.
