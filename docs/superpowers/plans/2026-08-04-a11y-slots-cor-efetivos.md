# BIT A11y — Slots de Cor Efetivos: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer os 8 slots de cor da tela `Aparência → Acessibilidade` terem efeito real no painel a11y, substituindo ~70 cores literais em `bureau-a11y.css` por `var(--ba-*)` / `color-mix()`.

**Architecture:** O pipeline PHP (`option bureau_a11y_colors` → `<style id='bureau-a11y-color-overrides'>:root{…}`) já funciona e **não é alterado**. Toda a mudança é no CSS consumidor. Como cada substituição é *dentro da linha* (nenhuma linha é inserida ou removida), **os números de linha permanecem estáveis do começo ao fim** — o que permite usar um conjunto explícito de linhas protegidas como mecanismo de exclusão preciso e auditável.

**Tech Stack:** CSS (`color-mix(in srgb, …)`, Baseline 2023), PHP (só a constante de versão), Perl (script de transformação one-shot), Bash (harness de verificação), Playwright MCP (validação visual).

## Global Constraints

- **Escopo:** somente dev. Prod não é tocado neste plano.
- **Spec:** `/Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/docs/superpowers/specs/2026-08-04-a11y-slots-cor-efetivos-design.md`
- **Arquivo alvo:** `wordpress/wp-content/mu-plugins/bureau-a11y/bureau-a11y.css` (1587 linhas)
- **Linhas PROTEGIDAS — nunca podem ser alteradas:** `26, 32, 97, 101, 110, 111, 124, 126, 130, 636, 1119, 1120, 1416, 1422`
- **`BUREAU_A11Y_CSS_VERSION`:** `2.8.0` → `2.9.0` em `bureau-a11y.php:24`
- **Arquivos temporários:** vão em `tmp/` do site e são removidos ao final (regra do `sites/CLAUDE.md`)
- **Comandos WP-CLI:** sempre via `docker exec -u www-data concertacao-dev-wordpress wp --path=/var/www/html --url="https://cambrasmax.local:8484" …`
- **Cor de identidade (para provar não-regressão):** `electric=#BDF839`, `electric_glow=rgba(189,248,57,0.20)`, `border=rgba(255,255,255,0.06)`
- **Cor de prova (para provar que o slot vale):** `electric=#B12B79`

### Por que cada linha protegida é protegida

| Linha | Conteúdo | Razão |
|---|---|---|
| 26, 32 | definições `--ba-surface` / `--ba-trigger-bg` no `:root` | São a *fonte* da cascata. Substituir criaria referência circular. |
| 97, 101, 110, 111 | `html.ba-high-contrast` (`#000`/`#fff`) | Alto Contraste existe para **ignorar** a paleta. Contrato WCAG. |
| 124, 126 | `html.ba-highlight-links a` | Decisão do usuário: lime fixo. |
| 130 | `html.ba-focus-guide` (`#0060df`) | O rótulo do card promete "contorno azul vibrante". |
| 636 | `#ba-tooltip-tip { color: #fff }` | Tooltip preto/branco de contraste fixo, autocontido. |
| 1119, 1120 | `#bureau-a11y-ruler` | Decisão do usuário: lime fixo. |
| 1416 | cursor da lupa (SVG em `data:` URI) | `data:` URI não resolve CSS custom property. |
| 1422 | `html.ba-tts-reading ::selection` | Pinta a página, não o painel. |

### Tabela de transformação

| # | Padrão | Vira | Linhas |
|---|---|---|---|
| 1 | `rgba(189,248,57,α)` em `box-shadow` com blur ≠ 0 | `var(--ba-electric-glow)` | 163, 233, 1179 |
| 2 | `rgba(255,255,255,0.06)` (divisórias estruturais) | `var(--ba-border)` | 307, 437, 762, 892 |
| 3 | `#BDF839` | `var(--ba-electric)` | global |
| 4 | `rgba(189,248,57,α)` restante | `color-mix(in srgb, var(--ba-electric) α%, transparent)` | global |
| 5 | `rgba(255,255,255,α)` restante | `color-mix(in srgb, var(--ba-text) α%, transparent)` | global |
| 6 | `#F0EDE1`, `#ffffff` | `var(--ba-text)` | global |
| 7 | `rgba(240,237,225,α)` | `color-mix(in srgb, var(--ba-muted) α%, transparent)` | global |
| 8 | `#0B4334` | `color-mix(in srgb, var(--ba-electric) 15%, var(--ba-forest))` | 606 |
| 9 | `#005A42` | `var(--ba-trigger-bg)` | 1013 |

**Refinamento sobre o spec:** a linha 191 (`#bureau-a11y-trigger::after`, halo do badge, alpha `0.6`) **não** vai para `--ba-electric-glow`. O badge sinaliza "feature ativa"; mandá-lo para o slot de brilho o rebaixaria de 0.6 para 0.2 e enfraqueceria o sinal. Ela cai na regra 4 (`color-mix … 60%`), preservando a proeminência. O slot de brilho fica com os 3 halos de *hover* (163, 233, 1179).

**Delta visual aceito e conhecido:** a linha 233 tinha alpha `0.15` e passa a usar o slot de brilho (`0.20` na config de identidade). Diferença imperceptível num halo de 12px; é o custo inerente de três intensidades passarem a compartilhar um slot.

---

### Task 1: Harness de verificação

Um script que checa mecanicamente as três invariantes do refactor. Ele **falha hoje** (~70 violações) e deve passar ao final. É o "teste" desta mudança.

**Files:**
- Create: `tmp/verify-a11y-vars.sh`

**Interfaces:**
- Consumes: nada (primeira task)
- Produces: `tmp/verify-a11y-vars.sh` — invocado como `bash tmp/verify-a11y-vars.sh <caminho-do-css>`; exit 0 = todas as invariantes OK, exit 1 = violação. Usado pelas Tasks 3 e 4.

- [ ] **Step 1: Escrever o harness**

Criar `tmp/verify-a11y-vars.sh`:

```bash
#!/usr/bin/env bash
# Verifica as invariantes do refactor de cores do BIT A11y.
# Uso: bash tmp/verify-a11y-vars.sh <caminho-do-bureau-a11y.css>
set -euo pipefail

CSS="${1:?uso: $0 <caminho-do-css>}"
PROTECTED="26 32 97 101 110 111 124 126 130 636 1119 1120 1416 1422"
SLOTS="forest surface electric electric-glow text muted border trigger-bg"
fail=0

echo "=== A) Hardcodes em escopo (fora das linhas protegidas) — esperado: 0 ==="
viol=$(awk -v prot="$PROTECTED" '
    BEGIN { n = split(prot, a, " "); for (i = 1; i <= n; i++) p[a[i]] = 1 }
    !(FNR in p) { print FNR ": " $0 }
' "$CSS" | grep -nE '#BDF839|rgba\(189,248,57|#F0EDE1|rgba\(240,237,225|rgba\(255,255,255|#0B4334|#005A42|#ffffff' || true)

if [[ -n "$viol" ]]; then
    echo "$viol"
    echo "FALHOU: $(printf '%s\n' "$viol" | wc -l | tr -d ' ') hardcode(s) restante(s)"
    fail=1
else
    echo "OK: nenhum hardcode em escopo"
fi

echo
echo "=== B) Linhas protegidas idênticas ao HEAD do git — esperado: todas ==="
for n in $PROTECTED; do
    atual=$(sed -n "${n}p" "$CSS")
    orig=$(git show "HEAD:wordpress/wp-content/mu-plugins/bureau-a11y/bureau-a11y.css" | sed -n "${n}p")
    if [[ "$atual" != "$orig" ]]; then
        echo "FALHOU L$n alterada"
        echo "  HEAD:  $orig"
        echo "  atual: $atual"
        fail=1
    fi
done
[[ $fail -eq 0 ]] && echo "OK: 14 linhas protegidas intactas"

echo
echo "=== C) Todos os 8 slots consumidos ao menos 1x — esperado: todos ==="
for s in $SLOTS; do
    # -1 desconta a definição no :root; --ba-border/-glow não têm def com var(--ba-X)
    n=$(grep -c -- "var(--ba-$s[,)]" "$CSS" || true)
    if [[ "$n" -lt 1 ]]; then
        echo "FALHOU --ba-$s: 0 usos (slot morto)"
        fail=1
    else
        printf "OK   --ba-%-14s %s uso(s)\n" "$s" "$n"
    fi
done

echo
if [[ $fail -eq 0 ]]; then echo "TODAS AS INVARIANTES OK"; else echo "HARNESS FALHOU"; fi
exit $fail
```

- [ ] **Step 2: Rodar e confirmar que FALHA hoje**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
bash tmp/verify-a11y-vars.sh wordpress/wp-content/mu-plugins/bureau-a11y/bureau-a11y.css; echo "exit=$?"
```

Esperado: `exit=1`. Bloco A lista ~70 violações. Bloco B passa (nada mudou ainda). Bloco C reporta `--ba-electric-glow: 0 usos` e `--ba-border: 0 usos`.

- [ ] **Step 3: Commit**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
git add -f tmp/verify-a11y-vars.sh
git commit -m "test(a11y): harness de verificacao dos slots de cor

Checa 3 invariantes: (A) zero hardcode em escopo fora das linhas
protegidas, (B) as 14 linhas protegidas identicas ao HEAD, (C) os 8
slots --ba-* consumidos ao menos 1x. Falha hoje com ~70 violacoes.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Baseline visual com a config de identidade

Antes de mexer no CSS, fixar no admin as cores que hoje estão hardcoded. Assim o "depois" é comparável ao "antes" maçã com maçã — sem isso o painel em dev viraria cinza-chumbo (o kit Elementor de dev está em grayscale: `accent = #474747`).

**Files:**
- Modify: option `bureau_a11y_colors` do blog 1 (via WP-CLI, não arquivo)
- Create: `tmp/a11y-antes.png`

**Interfaces:**
- Consumes: nada
- Produces: `tmp/a11y-antes.png` — screenshot de referência usado na Task 5. Config de identidade gravada na option do blog 1.

- [ ] **Step 1: Salvar a config atual para restaurar depois**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
docker exec -u www-data concertacao-dev-wordpress wp --path=/var/www/html \
  --url="https://cambrasmax.local:8484" option get bureau_a11y_colors --format=json \
  > tmp/a11y-colors-original.json
cat tmp/a11y-colors-original.json
```

Esperado: o JSON com `forest=accent`, `electric=accent`, etc.

- [ ] **Step 2: Gravar a config de identidade**

```bash
docker exec -u www-data concertacao-dev-wordpress wp --path=/var/www/html \
  --url="https://cambrasmax.local:8484" option update bureau_a11y_colors --format=json <<'JSON'
{"forest":{"mode":"custom","custom":"#003A26"},
 "surface":{"mode":"custom","custom":"rgba(255,255,255,0.06)"},
 "electric":{"mode":"custom","custom":"#BDF839"},
 "electric_glow":{"mode":"custom","custom":"rgba(189,248,57,0.20)"},
 "text":{"mode":"custom","custom":"#F0EDE1"},
 "muted":{"mode":"custom","custom":"rgba(240,237,225,0.65)"},
 "border":{"mode":"custom","custom":"rgba(255,255,255,0.06)"},
 "trigger_bg":{"mode":"custom","custom":"#005A42"}}
JSON
```

- [ ] **Step 3: Confirmar que o `<head>` emite os overrides esperados**

```bash
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh cache-flush
curl -sk "https://cambrasmax.local:8484/" | grep -o "<style id='bureau-a11y-color-overrides'>[^<]*"
```

Esperado: `--ba-override-electric:#BDF839`, `--ba-override-border:rgba(255,255,255,0.06)`, `--ba-override-electric-glow:rgba(189,248,57,0.20)` presentes (modo `custom` emite `--ba-override-*`).

- [ ] **Step 4: Screenshot "antes"**

Via Playwright MCP: navegar para `https://cambrasmax.local:8484/`, clicar em `#bureau-a11y-trigger`, aguardar o painel, screenshot em `tmp/a11y-antes.png`.

Registrar também os valores computados de referência:

```js
// browser_evaluate
() => {
  const q = s => document.querySelector(s);
  const cs = el => el ? getComputedStyle(el) : null;
  return {
    panelBg:    cs(q('#bureau-a11y-panel'))?.backgroundColor,
    tabAtiva:   cs(q('.ba-tab-btn[aria-selected="true"]'))?.color,
    zoomBtnBg:  cs(q('.ba-zoom-btn'))?.backgroundColor,
    zoomBtnCor: cs(q('.ba-zoom-btn'))?.color,
    headerBorda:cs(q('.ba-panel__header'))?.borderBottomColor,
    triggerBg:  cs(q('#bureau-a11y-trigger'))?.backgroundColor,
  };
}
```

Esperado (config de identidade): `zoomBtnCor` = `rgb(189, 248, 57)`, `headerBorda` = `rgba(255,255,255,0.06)`, `triggerBg` = `rgb(0, 90, 66)`. Anotar o objeto retornado — é a baseline da Task 5.

---

### Task 3: Refactor da família lime (`#BDF839` + `rgba(189,248,57,α)`)

**Files:**
- Create: `tmp/refactor-a11y-cores.pl`
- Modify: `wordpress/wp-content/mu-plugins/bureau-a11y/bureau-a11y.css`

**Interfaces:**
- Consumes: `tmp/verify-a11y-vars.sh` (Task 1)
- Produces: `tmp/refactor-a11y-cores.pl` — filtro stdin→stdout que aplica as 9 regras da tabela de transformação. Reutilizado na Task 4 (o mesmo script cobre as duas famílias; esta task valida a metade lime, a próxima o resto).

- [ ] **Step 1: Escrever o script de transformação**

Criar `tmp/refactor-a11y-cores.pl`:

```perl
#!/usr/bin/env perl
# Substitui cores literais do chrome do BIT A11y por var(--ba-*) / color-mix().
# Filtro stdin -> stdout. Toda substituicao e DENTRO da linha: nenhuma linha
# e inserida ou removida, entao os numeros de linha ficam estaveis.
# Spec: docs/superpowers/specs/2026-08-04-a11y-slots-cor-efetivos-design.md
use strict;
use warnings;

# Linhas intocaveis (ver "Global Constraints" do plano).
my %PROTECTED = map { $_ => 1 } (26, 32, 97, 101, 110, 111, 124, 126, 130, 636, 1119, 1120, 1416, 1422);

# Halos de hover (box-shadow com blur != 0) -> slot "Brilho do destaque".
# A 191 fica FORA: e o halo do badge (alpha 0.6) e cai na regra generica
# para nao perder proeminencia.
my %GLOW = map { $_ => 1 } (163, 233, 1179);

# Divisorias estruturais -> slot "Bordas e divisorias".
my %BORDER = map { $_ => 1 } (307, 437, 762, 892);

# 0.06 -> "6", 0.35 -> "35", 0 -> "0" (evita 6.000000000000001 do ponto flutuante)
sub pct { return sprintf('%g', shift() * 100); }

while (my $l = <STDIN>) {
    if ($PROTECTED{$.}) { print $l; next; }

    # 1. halo de hover -> --ba-electric-glow (antes da regra 4, que e mais ampla)
    $l =~ s/(0 0 (?:8|12|16)px )rgba\(189,248,57,[0-9.]+\)/$1var(--ba-electric-glow)/g
        if $GLOW{$.};

    # 2. divisoria estrutural -> --ba-border (antes da regra 5)
    $l =~ s/rgba\(255,255,255,0\.06\)/var(--ba-border)/g
        if $BORDER{$.};

    # 3. lime solido -> --ba-electric
    $l =~ s/#BDF839/var(--ba-electric)/g;

    # 4. lime com alpha -> color-mix sobre --ba-electric
    $l =~ s/rgba\(189,248,57,([0-9.]+)\)/'color-mix(in srgb, var(--ba-electric) ' . pct($1) . '%, transparent)'/ge;

    # 5. branco com alpha -> color-mix sobre --ba-text
    $l =~ s/rgba\(255,255,255,([0-9.]+)\)/'color-mix(in srgb, var(--ba-text) ' . pct($1) . '%, transparent)'/ge;

    # 6. offwhite solido -> --ba-text
    $l =~ s/#F0EDE1/var(--ba-text)/g;
    $l =~ s/#ffffff/var(--ba-text)/g;

    # 7. offwhite com alpha -> color-mix sobre --ba-muted
    $l =~ s/rgba\(240,237,225,([0-9.]+)\)/'color-mix(in srgb, var(--ba-muted) ' . pct($1) . '%, transparent)'/ge;

    # 8. fundo do toggle ativo -> destaque misturado no fundo
    $l =~ s/#0B4334/color-mix(in srgb, var(--ba-electric) 15%, var(--ba-forest))/g;

    # 9. fundo de foco da mini-pill -> --ba-trigger-bg
    $l =~ s/#005A42/var(--ba-trigger-bg)/g;

    print $l;
}
```

- [ ] **Step 2: Dry-run — inspecionar o diff antes de gravar**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/wordpress/wp-content/mu-plugins/bureau-a11y
perl ../../../../../tmp/refactor-a11y-cores.pl < bureau-a11y.css > /tmp/a11y-novo.css
diff <(cat bureau-a11y.css) /tmp/a11y-novo.css | head -80
echo "--- total de linhas alteradas ---"
diff bureau-a11y.css /tmp/a11y-novo.css | grep -c '^<'
echo "--- contagem de linhas deve ser IDENTICA ---"
wc -l bureau-a11y.css /tmp/a11y-novo.css
```

Esperado: ~66 linhas alteradas; `wc -l` idêntico nos dois arquivos (prova que nenhuma linha foi inserida/removida e os números de linha se mantiveram estáveis).

- [ ] **Step 3: Aplicar**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
perl tmp/refactor-a11y-cores.pl \
  < wordpress/wp-content/mu-plugins/bureau-a11y/bureau-a11y.css \
  > tmp/bureau-a11y.css.new
mv tmp/bureau-a11y.css.new wordpress/wp-content/mu-plugins/bureau-a11y/bureau-a11y.css
```

- [ ] **Step 4: Rodar o harness — deve PASSAR inteiro**

```bash
bash tmp/verify-a11y-vars.sh wordpress/wp-content/mu-plugins/bureau-a11y/bureau-a11y.css; echo "exit=$?"
```

Esperado: `exit=0`. Bloco A: nenhum hardcode. Bloco B: 14 linhas intactas. Bloco C: os 8 slots com ≥1 uso — em particular `--ba-electric-glow` com 3 e `--ba-border` com 4.

Se o bloco B falhar, o script mexeu numa linha protegida: conferir se os números em `%PROTECTED` batem com o arquivo atual.

- [ ] **Step 5: Sanity check de sintaxe — parênteses balanceados nas linhas tocadas**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
awk '/color-mix/ {
    n = gsub(/\(/, "("); m = gsub(/\)/, ")")
    if (n != m) printf "L%d desbalanceada (%d abre, %d fecha): %s\n", FNR, n, m, $0
}' wordpress/wp-content/mu-plugins/bureau-a11y/bureau-a11y.css
echo "exit=$? (sem saida acima = todas balanceadas)"
```

Esperado: nenhuma saída.

- [ ] **Step 6: Commit**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
git add -f tmp/refactor-a11y-cores.pl wordpress/wp-content/mu-plugins/bureau-a11y/bureau-a11y.css
git commit -m "refactor(a11y): cores do chrome do painel passam a vir dos slots do admin

Substitui ~66 cores literais por var(--ba-*) / color-mix(in srgb, ...):
33x #BDF839 -> --ba-electric, 30x rgba(189,248,57,a) -> color-mix sobre
--ba-electric (preservando a escada de alpha), 3 halos de hover ->
--ba-electric-glow, 4 divisorias -> --ba-border, offwhite -> --ba-text/
--ba-muted, #0B4334 -> color-mix(electric 15%, forest), #005A42 ->
--ba-trigger-bg.

Os 8 slots ficam vivos: --ba-electric-glow e --ba-border saem de 0 usos.

Fora de escopo por contrato de acessibilidade (alto contraste, guia de
foco) ou decisao de produto (destacar links, regua de leitura, selection
do TTS): 14 linhas protegidas, verificadas pelo harness.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Bump da versão do CSS

Sem isso o browser serve o CSS antigo do cache e a mudança não aparece.

**Files:**
- Modify: `wordpress/wp-content/mu-plugins/bureau-a11y.php:24`

**Interfaces:**
- Consumes: CSS refatorado (Task 3)
- Produces: `BUREAU_A11Y_CSS_VERSION = '2.9.0'` — o `?ver=` do `<link>` do CSS, que a Task 5 confere no HTML

- [ ] **Step 1: Editar a constante**

Em `bureau-a11y.php:24`, trocar:

```php
define( 'BUREAU_A11Y_CSS_VERSION', '2.8.0' );
```

por:

```php
define( 'BUREAU_A11Y_CSS_VERSION', '2.9.0' );
```

- [ ] **Step 2: Confirmar que o HTML serve a nova versão**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh cache-flush
curl -sk "https://cambrasmax.local:8484/" | grep -o "bureau-a11y\.css?ver=[0-9.]*"
```

Esperado: `bureau-a11y.css?ver=2.9.0`.

- [ ] **Step 3: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bureau-a11y.php
git commit -m "chore(a11y): CSS_VERSION 2.8.0 -> 2.9.0 (cache-bust do refactor de cores)

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Validação — Passo 1 (não-regressão) e Passo 2 (o slot vale)

**Files:**
- Create: `tmp/a11y-depois.png`, `tmp/a11y-magenta.png`
- Modify: option `bureau_a11y_colors` (temporariamente, restaurada no fim)

**Interfaces:**
- Consumes: `tmp/a11y-antes.png` + os valores computados de referência (Task 2); CSS refatorado (Task 3); `?ver=2.9.0` (Task 4)
- Produces: nada consumido depois; a option volta ao valor de `tmp/a11y-colors-original.json`

- [ ] **Step 1: Screenshot "depois" com a config de identidade**

Config ainda é a de identidade da Task 2. Via Playwright MCP: navegar para `https://cambrasmax.local:8484/` com cache desabilitado, clicar em `#bureau-a11y-trigger`, screenshot em `tmp/a11y-depois.png`.

- [ ] **Step 2: Reexecutar os valores computados e comparar com a baseline**

Rodar o mesmo `browser_evaluate` do Step 4 da Task 2. Comparar com o objeto anotado lá.

Esperado — **iguais**, com uma única exceção conhecida e aceita:

| Chave | Antes | Depois | Veredito |
|---|---|---|---|
| `panelBg` | `rgb(0, 58, 38)` | `rgb(0, 58, 38)` | igual |
| `tabAtiva` | `rgb(189, 248, 57)` | `rgb(189, 248, 57)` | igual |
| `zoomBtnBg` | `rgba(189, 248, 57, 0.15)` | `color-mix` resolve para o mesmo rgba | igual |
| `zoomBtnCor` | `rgb(189, 248, 57)` | `rgb(189, 248, 57)` | igual |
| `headerBorda` | `rgba(255, 255, 255, 0.06)` | `rgba(255, 255, 255, 0.06)` | igual |
| `triggerBg` | `rgb(0, 90, 66)` | `rgb(0, 90, 66)` | igual |

Se `zoomBtnBg` vier como `rgb(...)` opaco em vez de `rgba(...)`, o `color-mix` com `transparent` está sendo achatado — investigar antes de seguir.

- [ ] **Step 3: Comparar os dois screenshots visualmente**

Ler `tmp/a11y-antes.png` e `tmp/a11y-depois.png` e conferir: painel verde-escuro, aba "VISUAL" lime, `A−`/`A+` lime, cards de toggle com borda tênue, footer com `BIT A11y v2.9.9` apagado. Devem ser equivalentes. A única diferença tolerada é o halo de hover da linha 233 (`0.15` → `0.20`), que só aparece com o mouse sobre o botão flutuante.

- [ ] **Step 4: Passo 2 — trocar só o destaque para magenta**

```bash
docker exec -u www-data concertacao-dev-wordpress wp --path=/var/www/html \
  --url="https://cambrasmax.local:8484" option patch update bureau_a11y_colors electric \
  --format=json '{"mode":"custom","custom":"#B12B79"}'
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh cache-flush
```

- [ ] **Step 5: Screenshot magenta e conferir que o painel respondeu**

Via Playwright MCP: recarregar, abrir o painel, screenshot em `tmp/a11y-magenta.png`.

Esperado: aba ativa, `A−`/`A+` (texto, fundo e borda), ponto do toggle ativo, borda do card ativo e pills selecionadas **todos magenta**. O fundo do painel segue verde-escuro (slot `forest` não mudou) e as divisórias seguem brancas tênues (slot `border` não mudou) — isso prova que os slots são independentes, não um switch global.

Também confirmar via computed style:

```js
() => getComputedStyle(document.querySelector('.ba-zoom-btn')).color
```

Esperado: `rgb(177, 43, 121)`.

- [ ] **Step 6: Restaurar a config original**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
docker exec -i -u www-data concertacao-dev-wordpress wp --path=/var/www/html \
  --url="https://cambrasmax.local:8484" option update bureau_a11y_colors --format=json \
  < tmp/a11y-colors-original.json
docker exec -u www-data concertacao-dev-wordpress wp --path=/var/www/html \
  --url="https://cambrasmax.local:8484" option get bureau_a11y_colors --format=json
```

Esperado: idêntico ao conteúdo de `tmp/a11y-colors-original.json`.

**Nota:** com a config original restaurada, o painel em dev fica cinza-chumbo — o kit Elementor de dev está em grayscale (`accent = #474747`). Isso é o comportamento correto do refactor, não um bug. Reportar ao usuário e perguntar se ele quer fixar um custom em dev.

---

### Task 6: Sincronizar a cópia canônica em `common/mu-plugins/`

Regra do `sites/CLAUDE.md`: todo mu-plugin modificado deve ser commitado em `docker-dev/common/mu-plugins/`. Não toca prod.

**Files:**
- Modify: `/Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y.css`
- Modify: `/Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bureau-a11y.php`

**Interfaces:**
- Consumes: os dois arquivos finais das Tasks 3 e 4
- Produces: nada

- [ ] **Step 1: Copiar e confirmar que ficaram idênticos**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev
SITE="sites/concertacao/wordpress/wp-content/mu-plugins"
cp "$SITE/bureau-a11y/bureau-a11y.css" common/mu-plugins/bureau-a11y/bureau-a11y.css
cp "$SITE/bureau-a11y.php"             common/mu-plugins/bureau-a11y.php
diff -q "$SITE/bureau-a11y/bureau-a11y.css" common/mu-plugins/bureau-a11y/bureau-a11y.css && echo "CSS identico"
diff -q "$SITE/bureau-a11y.php"             common/mu-plugins/bureau-a11y.php             && echo "PHP identico"
```

Esperado: `CSS identico` e `PHP identico`.

- [ ] **Step 2: Commit no repo do server-tools**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git add docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y.css docker-dev/common/mu-plugins/bureau-a11y.php
git commit -m "refactor(a11y): sincroniza canonical — slots de cor efetivos + CSS 2.9.0

Espelha o refactor feito em sites/concertacao: ~66 cores literais do
chrome do painel passam a vir dos 8 slots de Aparencia > Acessibilidade
via var(--ba-*) / color-mix. Prod nao tocado.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [ ] **Step 3: Limpar os temporários**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
git rm --cached tmp/verify-a11y-vars.sh tmp/refactor-a11y-cores.pl
mv tmp/a11y-antes.png tmp/a11y-depois.png tmp/a11y-magenta.png \
   tmp/a11y-colors-original.json tmp/verify-a11y-vars.sh tmp/refactor-a11y-cores.pl \
   ~/.Trash/ 2>/dev/null || true
git commit -m "chore(a11y): remove artefatos temporarios da validacao

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Self-Review

**1. Cobertura do spec:**

| Seção do spec | Task |
|---|---|
| §3 regras de mapeamento (9 regras) | Task 3, Step 1 (script Perl, regras 1–9) |
| §3.1 divisória estrutural × borda de card | Task 3, `%BORDER` = 307/437/762/892; resto cai na regra 5 |
| §4 fora de escopo (7 grupos) | Task 3, `%PROTECTED` (14 linhas); verificado no bloco B do harness |
| §5 cache-busting | Task 4 |
| §6 validação em 2 passos | Task 5 |
| §6 nota sobre a paleta de dev | Task 2 (config de identidade) + Task 5, Step 6 (nota final) |
| §7.1 cópia canônica | Task 6 |
| §7.2 deploy em prod | Deliberadamente fora — o spec marca como etapa posterior |

Sem lacunas.

**2. Placeholders:** nenhum "TBD"/"TODO"/"similar à Task N". Todo passo de código tem o código.

**3. Consistência de nomes e tipos:** `tmp/verify-a11y-vars.sh` (Tasks 1, 3), `tmp/refactor-a11y-cores.pl` (Tasks 3, 6), `tmp/a11y-colors-original.json` (Tasks 2, 5, 6), `tmp/a11y-antes.png` (Tasks 2, 5) — nomes idênticos em todas as referências. `%PROTECTED` do Perl e `PROTECTED` do Bash listam o mesmo conjunto de 14 linhas.

**4. Correção encontrada e aplicada:** o Step 6 da Task 5 precisa de `docker exec -i` (não só `-u www-data`) para o redirecionamento de stdin do JSON funcionar — sem o `-i` o `wp option update` recebe stdin vazio e grava a option como vazia, apagando a config. Já corrigido no plano acima.
