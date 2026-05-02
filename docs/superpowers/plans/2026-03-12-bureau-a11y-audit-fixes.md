# Bureau A11y — Audit Fixes Implementation Plan

> **For agentic workers:** REQUIRED: Use `superpowers:subagent-driven-development` (if subagents available) or `superpowers:executing-plans` to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Corrigir os 18 problemas identificados em 4 análises técnicas paralelas (bugs, WCAG, arquitetura, performance) no plugin Bureau A11y v2.3.14.

**Architecture:** Todas as mudanças são nos mesmos 3 arquivos canônicos em `docker-dev/common/mu-plugins/`. Após cada chunk, sincronizar para `sites/concertacao/wordpress/wp-content/mu-plugins/` e flush de caches. Sem build system — ES5 puro, edições diretas nos arquivos.

**Tech Stack:** ES5 IIFE (bureau-a11y.js), CSS3 (bureau-a11y.css), PHP 8.3 mu-plugin (bureau-a11y.php), WordPress multisite, WP Rocket, Redis, Docker (container: `www2-concertacao-dev-wordpress`).

---

## Macro de sync e flush de caches

Usar após cada chunk. Definida aqui para não repetir:

```bash
# SYNC_AND_FLUSH — executar após alterações
cp /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bureau-a11y.php \
   /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/wordpress/wp-content/mu-plugins/ && \
cp -r /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bureau-a11y/ \
   /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/wordpress/wp-content/mu-plugins/ && \
docker exec -u www-data www2-concertacao-dev-wordpress wp eval \
    'opcache_reset(); if(function_exists("rocket_clean_domain")) rocket_clean_domain(); if(function_exists("rocket_clean_minify")) rocket_clean_minify();' && \
docker exec -u www-data www2-concertacao-dev-wordpress wp cache flush
```

---

## Arquivos alterados

| Arquivo | Chunks |
|---------|--------|
| `common/mu-plugins/bureau-a11y/bureau-a11y.css` | 1, 4, 5 |
| `common/mu-plugins/bureau-a11y/bureau-a11y.js` | 2, 3, 4, 5, 6 |
| `common/mu-plugins/bureau-a11y.php` | 1, 6 |

---

## Chunk 1: CSS Alinhamento de Versão + Fixes de Contraste + will-change

**Arquivos:** `bureau-a11y.css`, `bureau-a11y.php`
**Prioridade:** P0-10 (versão desalinhada), P1-6 (logo contraste), P1-7 (highlightLinks), P1-11 (will-change)
**Tempo estimado:** 20 min

---

### Task 1.1: Alinhar CSS de v2.3.13 para v2.3.14

**Arquivo:** `common/mu-plugins/bureau-a11y/bureau-a11y.css:2`

O CSS ficou em 2.3.13 enquanto JS e PHP foram para 2.3.14 na última release. Cache busting falha silenciosamente.

- [ ] **Editar line 2 do CSS**

```
Antes: * Version: 2.3.13
Depois: * Version: 2.3.14
```

---

### Task 1.2: Corrigir contraste do logo Bureau no footer

**Arquivo:** `common/mu-plugins/bureau-a11y.php:433–441`

Contraste atual: 1.08:1 (falha WCAG 1.4.3 AA — mínimo 3:1 para UI components).
Fix: aumentar opacity de 0.25 para 0.65 → resultado: ~6.8:1.

- [ ] **Editar PHP — trocar fill em todos os 5 polígonos/paths do SVG**

Localizar a seção do SVG footer (logo Bureau, a partir da linha 431). São 5 atributos `fill="rgba(240,237,225,0.25)"`:

```php
// Antes (5 ocorrências):
fill="rgba(240,237,225,0.25)"

// Depois (todas):
fill="rgba(240,237,225,0.65)"
```

Usar replace_all no Edit tool para substituir todas de uma vez no arquivo `bureau-a11y.php`.

---

### Task 1.3: Corrigir contraste do background da feature highlightLinks

**Arquivo:** `common/mu-plugins/bureau-a11y/bureau-a11y.css:159`

O `outline` já está correto (14:1 contraste). O `background` a 8% opacity é quase invisível (1.26:1). Aumentar para 20% para dar feedback visual sem violar contraste do texto sobre ele.

- [ ] **Editar CSS line 159**

```css
/* Antes: */
background: rgba(189,248,57,0.08) !important;

/* Depois: */
background: rgba(189,248,57,0.18) !important;
```

---

### Task 1.4: Corrigir will-change: top → will-change: transform (régua de leitura)

**Arquivo:** `common/mu-plugins/bureau-a11y/bureau-a11y.css` — bloco `#bureau-a11y-ruler` (~linha 923)

`will-change: top` não ativa GPU compositing — `top` é layout (CPU). O Chunk 4 migrará o JS para `transform: translateY()`, o que exige que o elemento tenha `top: 0` como âncora.

Ambas as mudanças no mesmo bloco são feitas em um único edit:

- [ ] **Editar CSS — substituir o bloco `#bureau-a11y-ruler` completo**

Localizar:
```css
#bureau-a11y-ruler {
    position: fixed;
    left: 0;
    right: 0;
    height: 40px;
    background: rgba(189,248,57,0.08);
    border-top: 2px solid rgba(189,248,57,0.35);
    border-bottom: 2px solid rgba(189,248,57,0.35);
    pointer-events: none;
    z-index: 9999997;
    display: none;
    will-change: top;
}
```

Substituir por:
```css
#bureau-a11y-ruler {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 40px;
    background: rgba(189,248,57,0.08);
    border-top: 2px solid rgba(189,248,57,0.35);
    border-bottom: 2px solid rgba(189,248,57,0.35);
    pointer-events: none;
    z-index: 9999997;
    display: none;
    will-change: transform;
}
```

---

### Task 1.5: Bump de versão para 2.3.15

> Bump feito **antes** do sync para que o browser veja a versão correta no Network tab.

- [ ] **Atualizar versão em 3 locais:**

`bureau-a11y.css` line 2:
```
* Version: 2.3.15
```

`bureau-a11y.js` line 3:
```
 * Version: 2.3.15
```

`bureau-a11y.php` lines 5, 15 e 79:
```php
// line 5:
 * Version: 2.3.15
// line 15:
define( 'BUREAU_A11Y_VERSION', '2.3.15' );
// line 79:
<!-- Bureau A11y v2.3.15 -->
```

---

### Task 1.6: Sync, flush de caches, verificação e commit

- [ ] **Abrir `docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y/playground.html` no browser** para verificação visual antes de sincronizar:
  - Logo Bureau no rodapé: mais opaco vs antes
  - Ativar "Destacar links": links com outline verde + fundo levemente verde visível

- [ ] **Executar SYNC_AND_FLUSH**

- [ ] **Verificar no browser (https://cambrasmax.local:8484)**
  - DevTools → Network → `bureau-a11y.css` → confirmar `?ver=2.3.15`
  - Footer do painel: logo Bureau opaco (contraste melhorado)
  - Feature "Destacar links": outline verde visível nos links
  - DevTools → Elements → `#bureau-a11y-ruler` → `will-change: transform; top: 0px`

- [ ] **Commit**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git add docker-dev/common/mu-plugins/bureau-a11y.php \
        docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y.css \
        docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y.js
git commit -m "$(cat <<'EOF'
fix(bureau-a11y): v2.3.15 — alinha CSS 2.3.13→2.3.14, fixes de contraste e will-change

- CSS: alinha versão de 2.3.13 para 2.3.14 (estava desalinhada)
- PHP: logo footer rgba(0.25) → rgba(0.65) — contraste 1.08:1 → 6.8:1 (WCAG 1.4.3)
- CSS: highlightLinks background 8% → 18% opacity — melhor feedback visual
- CSS: will-change: top → will-change: transform na régua de leitura

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Chunk 2: JavaScript Safety Fixes

**Arquivos:** `bureau-a11y.js`
**Prioridade:** P0-4 (localStorage sem try/catch), P0-5 (TTS duplo _onEnd), P2-14 (reset sem confirmação)
**Tempo estimado:** 30 min

---

### Task 2.1: Proteger acessos diretos ao localStorage no bloco Hints

**Arquivo:** `common/mu-plugins/bureau-a11y/bureau-a11y.js:1430–1465`

O bloco `(function () { var HINTS_KEY = ...` acessa `localStorage` diretamente sem try/catch. Em modo privado (Safari, Firefox, Chrome) isso lança `SecurityError` e **quebra todo o plugin** — nada abaixo desta linha executa.

- [ ] **Editar JS — envolver as duas chamadas em try/catch**

Localizar (linha ~1433):
```javascript
var show      = localStorage.getItem(HINTS_KEY) !== 'false';
```

Substituir por:
```javascript
var show;
try { show = localStorage.getItem(HINTS_KEY) !== 'false'; } catch (e) { show = true; }
```

Localizar (linha ~1461):
```javascript
localStorage.setItem(HINTS_KEY, state ? 'true' : 'false');
```

Substituir por:
```javascript
try { localStorage.setItem(HINTS_KEY, state ? 'true' : 'false'); } catch (e) { /* modo privado */ }
```

---

### Task 2.2: Corrigir dupla chamada de `_onEnd()` no Speech TTS

**Arquivo:** `common/mu-plugins/bureau-a11y/bureau-a11y.js:740–778`

Se o `onerror` do ResponsiveVoice disparar após o `onend`, `_onEnd()` é chamado 2×, deixando `_isPlaying = true` permanentemente e o label do botão travado em "Parar de falar".

Fix: adicionar closure `_safeEnd` com flag `_ended` que executa `_onEnd()` no máximo 1×.

- [ ] **Editar JS — substituir o bloco `var doSpeak`**

Localizar:
```javascript
                    var doSpeak = function () {
                        if (!self._queue) return;
                        var t = self._queue;
                        self._queue = null;

                        if (typeof responsiveVoice !== 'undefined' && !self._webSpeechFallback) {
                            var _rvTimeout = setTimeout(function () {
                                // RV carregou mas não falou (key inválida/domínio não autorizado)
                                // NÃO chamar _onEnd() aqui — mantém _isPlaying=true enquanto WebSpeech fala
                                self._webSpeechFallback = true;
                                if ('speechSynthesis' in window) {
                                    self._speakWebSpeech(t, function () { self._onEnd(); });
                                } else {
                                    self._onEnd();
                                }
                            }, 2500);
                            responsiveVoice.speak(t, self._getVoiceName(), {
                                rate: self._ttsRate,
                                onstart: function () { clearTimeout(_rvTimeout); },
                                onend: function () { clearTimeout(_rvTimeout); self._onEnd(); },
                                onerror: function () {
                                    clearTimeout(_rvTimeout);
                                    self._webSpeechFallback = true;
                                    if ('speechSynthesis' in window) {
                                        self._speakWebSpeech(t, function () { self._onEnd(); });
                                    } else { self._onEnd(); }
                                }
                            });
                        } else if ('speechSynthesis' in window) {
                            self._speakWebSpeech(t, function () { self._onEnd(); });
                        }
                    };
```

Substituir por:
```javascript
                    var doSpeak = function () {
                        if (!self._queue) return;
                        var t = self._queue;
                        self._queue = null;

                        // Guard: _onEnd() executado no máximo 1x por utterance
                        var _ended = false;
                        var _safeEnd = function () {
                            if (_ended) return;
                            _ended = true;
                            self._onEnd();
                        };

                        if (typeof responsiveVoice !== 'undefined' && !self._webSpeechFallback) {
                            var _rvTimeout = setTimeout(function () {
                                // RV carregou mas não falou (key inválida/domínio não autorizado)
                                self._webSpeechFallback = true;
                                if ('speechSynthesis' in window) {
                                    self._speakWebSpeech(t, _safeEnd);
                                } else {
                                    _safeEnd();
                                }
                            }, 2500);
                            responsiveVoice.speak(t, self._getVoiceName(), {
                                rate: self._ttsRate,
                                onstart: function () { clearTimeout(_rvTimeout); },
                                onend: function () { clearTimeout(_rvTimeout); _safeEnd(); },
                                onerror: function () {
                                    clearTimeout(_rvTimeout);
                                    self._webSpeechFallback = true;
                                    if ('speechSynthesis' in window) {
                                        self._speakWebSpeech(t, _safeEnd);
                                    } else { _safeEnd(); }
                                }
                            });
                        } else if ('speechSynthesis' in window) {
                            self._speakWebSpeech(t, _safeEnd);
                        }
                    };
```

---

### Task 2.3: Adicionar confirmação ao botão Reset

**Arquivo:** `common/mu-plugins/bureau-a11y/bureau-a11y.js:1421–1427`

O reset atual ocorre sem confirmação. Um clique acidental apaga todas as preferências.

- [ ] **Editar JS — adicionar `window.confirm` antes de reset**

Localizar:
```javascript
        var resetBtn = document.getElementById('ba-reset-btn');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                Store.reset();
                location.reload();
            });
        }
```

Substituir por:
```javascript
        var resetBtn = document.getElementById('ba-reset-btn');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                if (!window.confirm('Restaurar todas as configurações de acessibilidade para o padrão?')) return;
                Store.reset();
                location.reload();
            });
        }
```

---

### Task 2.4: Sync, flush e verificação

- [ ] **Executar SYNC_AND_FLUSH**

- [ ] **Verificar:**
  - Modo privado: abrir aba privada em `https://cambrasmax.local:8484`, verificar que o painel A11y carrega normalmente (antes quebrava)
  - TTS: ativar hover-to-read, passar sobre um parágrafo, observar que botão volta ao estado "off" corretamente ao terminar
  - Reset: clicar no botão reset (ícone circular no footer do painel) — deve aparecer `confirm` dialog

---

### Task 2.5: Bump para v2.3.16 + commit

- [ ] **Atualizar versão para 2.3.16** nos 3 locais (JS line 3, CSS line 2, PHP lines 5/15/79)

- [ ] **Commit**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git add docker-dev/common/mu-plugins/bureau-a11y.php \
        docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y.css \
        docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y.js
git commit -m "$(cat <<'EOF'
fix(bureau-a11y): v2.3.16 — safety fixes JS: localStorage, TTS duplo _onEnd, reset confirm

- localStorage: hints block envolto em try/catch — evita crash em modo privado
- TTS: _safeEnd guard previne _onEnd() duplo (onerror + onend simultâneos)
- Reset: adiciona window.confirm para evitar reset acidental

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Chunk 3: VLibras — Memory Leaks e Race Condition

**Arquivos:** `bureau-a11y.js`
**Prioridade:** P0-1 (MutationObservers sem disconnect), P1-8 (load race condition)
**Tempo estimado:** 45 min

---

### Task 3.1: Armazenar MutationObservers do VLibras para permitir disconnect

**Arquivo:** `common/mu-plugins/bureau-a11y/bureau-a11y.js` — módulo `Libras` (a partir da linha ~1034)

Atualmente 3 MutationObservers são criados sem armazenar referência. Quando VLibras é desativado e reativado, os observers anteriores ficam ativos forever — leak progressivo.

- [ ] **Adicionar `_observers: []` ao objeto Libras**

Localizar o início do objeto `Libras`:
```javascript
        Libras: {
            _loaded: false,
            _vwOpen: false,
```

Adicionar `_observers: [],` logo após `_vwOpen: false,`:
```javascript
        Libras: {
            _loaded: false,
            _vwOpen: false,
            _observers: [],
```

---

### Task 3.2: Criar método `_addObserver()` e `_disconnectObservers()`

- [ ] **Adicionar os dois métodos ao objeto Libras**, logo após a propriedade `_observers` (antes de `_enable`):

```javascript
            _addObserver: function (observer) {
                this._observers.push(observer);
            },

            _disconnectObservers: function () {
                this._observers.forEach(function (o) { o.disconnect(); });
                this._observers = [];
            },
```

---

### Task 3.3: Substituir `new MutationObserver(...)` por wrappers que registram na lista

**Arquivo:** linhas ~1108–1113, ~1143–1150, ~1155–1165 da função `_positionBtn()`

São 3 ocorrências de `new MutationObserver(...).observe(...)`. Cada uma deve ser registrada via `self._addObserver()`.

- [ ] **Editar — observer 1 (container transform)**

Localizar:
```javascript
                new MutationObserver(function () {
                    if (_reapplyTransform) return;
                    _reapplyTransform = true;
                    container.style.setProperty('transform', 'none', 'important');
                    _reapplyTransform = false;
                }).observe(container, { attributes: true, attributeFilter: ['style'] });
```

Substituir por:
```javascript
                var _obs1 = new MutationObserver(function () {
                    if (_reapplyTransform) return;
                    _reapplyTransform = true;
                    container.style.setProperty('transform', 'none', 'important');
                    _reapplyTransform = false;
                });
                _obs1.observe(container, { attributes: true, attributeFilter: ['style'] });
                self._addObserver(_obs1);
```

- [ ] **Editar — observer 2 (botão estilo)**

Localizar:
```javascript
                new MutationObserver(function () {
                    if (_reapplyBtn) return;
                    _reapplyBtn = true;
                    Object.keys(btnStyles).forEach(function (prop) {
                        vwBtn.style.setProperty(prop, btnStyles[prop], 'important');
                    });
                    _reapplyBtn = false;
                }).observe(vwBtn, { attributes: true, attributeFilter: ['style'] });
```

Substituir por:
```javascript
                var _obs2 = new MutationObserver(function () {
                    if (_reapplyBtn) return;
                    _reapplyBtn = true;
                    Object.keys(btnStyles).forEach(function (prop) {
                        vwBtn.style.setProperty(prop, btnStyles[prop], 'important');
                    });
                    _reapplyBtn = false;
                });
                _obs2.observe(vwBtn, { attributes: true, attributeFilter: ['style'] });
                self._addObserver(_obs2);
```

- [ ] **Editar — observer 3 (classe active do SDK — adicionado em v2.3.14)**

Localizar:
```javascript
                // Sincronizar _vwOpen com estado real do SDK via classe 'active' em [vw]
                var vwRoot = document.querySelector('[vw]');
                if (vwRoot) {
                    new MutationObserver(function () {
                        var isOpen = vwRoot.classList.contains('active');
                        if (isOpen === self._vwOpen) return; // sem mudança
                        self._vwOpen = isOpen;
                        if (isOpen) {
                            Panel.close();
                            if (vwWrapper) vwWrapper.style.removeProperty('display');
                        } else {
                            if (vwWrapper) vwWrapper.style.setProperty('display', 'none', 'important');
                        }
                    }).observe(vwRoot, { attributes: true, attributeFilter: ['class'] });
                }
```

Substituir por:
```javascript
                // Sincronizar _vwOpen com estado real do SDK via classe 'active' em [vw]
                var vwRoot = document.querySelector('[vw]');
                if (vwRoot) {
                    var _obs3 = new MutationObserver(function () {
                        var isOpen = vwRoot.classList.contains('active');
                        if (isOpen === self._vwOpen) return; // sem mudança
                        self._vwOpen = isOpen;
                        if (isOpen) {
                            Panel.close();
                            if (vwWrapper) vwWrapper.style.removeProperty('display');
                        } else {
                            if (vwWrapper) vwWrapper.style.setProperty('display', 'none', 'important');
                        }
                    });
                    _obs3.observe(vwRoot, { attributes: true, attributeFilter: ['class'] });
                    self._addObserver(_obs3);
                }
```

---

### Task 3.4: Chamar `_disconnectObservers()` no método `_disable()`

- [ ] **Editar — adicionar disconnect no início de `_disable()`**

Localizar (linhas 1061–1064 exatas):
```javascript
            _disable: function () {
                var container = document.getElementById('bureau-vlibras-container');
                if (container) container.style.display = 'none';
            },
```

Substituir por:
```javascript
            _disable: function () {
                this._disconnectObservers();
                var container = document.getElementById('bureau-vlibras-container');
                if (container) container.style.display = 'none';
            },
```

---

### Task 3.5: Adicionar helper `_waitForEl()` para resolver race condition no load

**Arquivo:** mesmo JS — módulo `Libras`

Quando VLibras SDK é carregado async em mobile lento, `_positionBtn()` pode ser chamado antes dos elementos `[vw-access-button]` e `[vw]` existirem. O fix: retry com MutationObserver no body até o elemento aparecer.

- [ ] **Adicionar método `_waitForEl` ao objeto Libras** (após `_disconnectObservers`):

```javascript
            // Aguarda elemento aparecer no DOM (máx 8s) antes de prosseguir
            _waitForEl: function (selector, cb) {
                var el = document.querySelector(selector);
                if (el) { cb(el); return; }
                var deadline = Date.now() + 8000;
                var obs = new MutationObserver(function () {
                    var found = document.querySelector(selector);
                    if (found) {
                        obs.disconnect();
                        cb(found);
                    } else if (Date.now() > deadline) {
                        obs.disconnect();
                    }
                });
                obs.observe(document.body, { childList: true, subtree: true });
            },
```

---

### Task 3.6: Substituir inline MutationObserver em `_initWidget()` por `_waitForEl`

O `_initWidget` atual (linhas 1071–1078) já usa um MutationObserver para aguardar `[vw-access-button] img` antes de chamar `_positionBtn()`. Substituir pelo `_waitForEl` recém-criado para eliminar o observer inline e usar a abstração com timeout.

- [ ] **Editar — substituir o bloco observer em `_initWidget()`**

Localizar (linhas 1071–1078 exatas):
```javascript
                var observer = new MutationObserver(function (mutations, obs) {
                    var accessBtn = document.querySelector('[vw-access-button] img');
                    if (accessBtn) {
                        Libras._positionBtn();
                        obs.disconnect();
                    }
                });
                observer.observe(document.body, { childList: true, subtree: true });
```

Substituir por:
```javascript
                Libras._waitForEl('[vw-access-button] img', function () {
                    Libras._positionBtn();
                });
```

---

### Task 3.7: Sync, flush e verificação

- [ ] **Executar SYNC_AND_FLUSH**

- [ ] **Verificar:**
  - Abrir VLibras, fechar via botão X interno, reabrir — painel deve reabrir normalmente (regressão do v2.3.13 não volta)
  - DevTools → Memory → tirar heap snapshot antes e depois de ativar/desativar VLibras 5× — sem crescimento de MutationObserver entries
  - DevTools → Performance → gravar 10s com VLibras ativo — sem CPU spike de observers

---

### Task 3.8: Bump para v2.3.17 + commit

- [ ] **Atualizar versão para 2.3.17** nos 3 locais

- [ ] **Commit**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git add docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y.js
git commit -m "$(cat <<'EOF'
fix(bureau-a11y): v2.3.17 — VLibras memory leaks e race condition

- Libras._observers[]: armazena 3 MutationObservers para poder desconectar
- _disconnectObservers(): chamado em _disable() — sem leak ao reativar
- _waitForEl(): helper com retry 8s — resolve race condition em mobile lento
- _initWidget: usa _waitForEl antes de _positionBtn()

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Chunk 4: Performance — requestAnimationFrame no mousemove

**Arquivos:** `bureau-a11y.js`, `bureau-a11y.css`
**Prioridade:** P0-2 (INP jank 80-150ms)
**Tempo estimado:** 30 min

---

### Task 4.1: RAF + clientX + _rafId no Magnifier

**Arquivo:** `common/mu-plugins/bureau-a11y/bureau-a11y.js` — objeto `Magnifier` (~linha 425)

Problema duplo: 60 calls/s sem RAF → jank; `e.pageX/pageY` inclui scroll offset → cursor desalinhado.

- [ ] **Adicionar `_rafId: null,` ao objeto Magnifier**

Localizar o início do objeto `Magnifier`:
```javascript
        Magnifier: {
            _onMove: null,
```

Adicionar `_rafId: null,`:
```javascript
        Magnifier: {
            _onMove: null,
            _rafId: null,
```

- [ ] **Substituir o handler de mousemove em `_bind()`**

Localizar (linhas 483–487):
```javascript
                this._onMove = function (e) {
                    document.body.style.transformOrigin = e.pageX + 'px ' + e.pageY + 'px';
                    document.body.style.transform = 'scale(' + SCALE + ')';
                };
                document.addEventListener('mousemove', this._onMove, { passive: true });
```

Substituir por:
```javascript
                this._onMove = function (e) {
                    if (self._rafId) cancelAnimationFrame(self._rafId);
                    var cx = e.clientX, cy = e.clientY;
                    self._rafId = requestAnimationFrame(function () {
                        document.body.style.transformOrigin = cx + 'px ' + cy + 'px';
                        document.body.style.transform = 'scale(' + SCALE + ')';
                        self._rafId = null;
                    });
                };
                document.addEventListener('mousemove', this._onMove, { passive: true });
```

- [ ] **Cancelar RAF pendente em `_unbind()`**

Localizar o início de `_unbind()` no Magnifier (linha ~490):
```javascript
            _unbind: function () {
                document.documentElement.classList.remove('ba-magnifier');
```

Adicionar cancelamento no início:
```javascript
            _unbind: function () {
                if (this._rafId) { cancelAnimationFrame(this._rafId); this._rafId = null; }
                document.documentElement.classList.remove('ba-magnifier');
```

---

### Task 4.2: RAF + transform + _rafId na Reading Ruler

**Arquivo:** `common/mu-plugins/bureau-a11y/bureau-a11y.js` — objeto `ReadingRuler` (~linha 975)

Problema: 60 calls/s sem RAF; usa `style.top` (CPU layout) em vez de `transform` (GPU composite).
O CSS já foi preparado no Chunk 1 (top: 0 + will-change: transform).

- [ ] **Adicionar `_rafId: null,` ao objeto ReadingRuler**

Localizar:
```javascript
        ReadingRuler: {
            _ruler: null,
            _active: false,
            _handler: null,
```

Adicionar `_rafId: null,`:
```javascript
        ReadingRuler: {
            _ruler: null,
            _active: false,
            _handler: null,
            _rafId: null,
```

- [ ] **Substituir o handler de mousemove em `_bind()`**

Localizar (linhas 998–1005):
```javascript
            _bind: function () {
                var self = this;
                this._handler = function (e) {
                    if (self._ruler) {
                        self._ruler.style.top = (e.clientY - 20) + 'px';
                    }
                };
                document.addEventListener('mousemove', this._handler, { passive: true });
            },
```

Substituir por:
```javascript
            _bind: function () {
                var self = this;
                this._handler = function (e) {
                    if (self._rafId) cancelAnimationFrame(self._rafId);
                    var cy = e.clientY;
                    self._rafId = requestAnimationFrame(function () {
                        if (self._ruler) {
                            self._ruler.style.transform = 'translateY(' + (cy - 20) + 'px)';
                        }
                        self._rafId = null;
                    });
                };
                document.addEventListener('mousemove', this._handler, { passive: true });
            },
```

- [ ] **Cancelar RAF + limpar transform em `_unbind()`**

Localizar (linhas 1008–1013):
```javascript
            _unbind: function () {
                if (this._handler) {
                    document.removeEventListener('mousemove', this._handler);
                    this._handler = null;
                }
            }
```

Substituir por:
```javascript
            _unbind: function () {
                if (this._rafId) { cancelAnimationFrame(this._rafId); this._rafId = null; }
                if (this._handler) {
                    document.removeEventListener('mousemove', this._handler);
                    this._handler = null;
                }
                if (this._ruler) {
                    this._ruler.style.transform = '';
                }
            }
```

---

### Task 4.3: Sync, flush e verificação de performance

- [ ] **Abrir `playground.html` para teste visual rápido antes de sincronizar:**
  - Ativar Lupa e mover mouse: zoom suave sem jank
  - Ativar Régua: linha verde segue o cursor suavemente

- [ ] **Executar SYNC_AND_FLUSH**

- [ ] **Verificar no browser (https://cambrasmax.local:8484):**
  - Ativar Lupa de cursor: mover mouse rapidamente — zoom deve acompanhar sem jank visual
  - Ativar Régua de leitura: mover mouse — régua deve seguir suavemente
  - DevTools → Performance → gravar 5s com lupa ativa — frames no mousemove ≤16ms (antes eram 12-16ms; com RAF devem ser <4ms)
  - DevTools → Elements → `#bureau-a11y-ruler` ao mover mouse: ver `transform: translateY(...)` mutando (não `top`)

---

### Task 4.4: Bump para v2.3.18 + commit

- [ ] **Atualizar versão para 2.3.18** nos 3 locais

- [ ] **Commit**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git add docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y.js \
        docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y.css
git commit -m "$(cat <<'EOF'
perf(bureau-a11y): v2.3.18 — requestAnimationFrame no mousemove (lupa + régua)

- Magnifier: RAF + cancelAnimationFrame, usa clientX/clientY (fix scroll offset)
- ReadingRuler: RAF + transform translateY em vez de top (GPU composite)
- CSS: ruler usa top:0 como âncora para transform

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Chunk 5: WCAG — Alternativa de Teclado para Drag + Focus Mobile

**Arquivos:** `bureau-a11y.js`, `bureau-a11y.css`
**Prioridade:** P0-3 (WCAG 2.1.1 Nível A — teclado), P2-17 (focus visible mobile)
**Tempo estimado:** 45 min

---

### Task 5.1: Adicionar controle de teclado (Alt+Setas) para mover o painel

**Arquivo:** `common/mu-plugins/bureau-a11y/bureau-a11y.js` — método `_initDrag()` (~linha 242)

Usuários keyboard-only precisam conseguir mover o painel. Implementação: Alt+Arrow move 20px por pressão; boundary checking idêntico ao drag com mouse.

- [ ] **Adicionar listener de teclado no final de `_initDrag()`**

Localizar o final do método `_initDrag()` (após `pointerup` handler, antes do `},`):
```javascript
            handle.addEventListener('pointerup', function () {
                if (!self._dragging) return;
                self._dragging = false;
                panel.style.transition = '';
                var rect = panel.getBoundingClientRect();
                Store.set('panelX', Math.round(rect.left));
                Store.set('panelY', Math.round(rect.top));
            });
        },
```

Inserir o listener de teclado entre o `pointerup` e o fechamento do método:
```javascript
            handle.addEventListener('pointerup', function () {
                if (!self._dragging) return;
                self._dragging = false;
                panel.style.transition = '';
                var rect = panel.getBoundingClientRect();
                Store.set('panelX', Math.round(rect.left));
                Store.set('panelY', Math.round(rect.top));
            });

            // Alternativa de teclado: Alt+Setas move o painel (WCAG 2.1.1)
            document.addEventListener('keydown', function (e) {
                if (!self.isOpen) return;
                if (!e.altKey || ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].indexOf(e.key) === -1) return;
                e.preventDefault();
                var step = 20;
                var rect = panel.getBoundingClientRect();
                var x = rect.left;
                var y = rect.top;
                if (e.key === 'ArrowUp')    y -= step;
                if (e.key === 'ArrowDown')  y += step;
                if (e.key === 'ArrowLeft')  x -= step;
                if (e.key === 'ArrowRight') x += step;
                x = Math.max(0, Math.min(x, window.innerWidth - panel.offsetWidth));
                y = Math.max(0, Math.min(y, window.innerHeight - panel.offsetHeight));
                panel.style.right = 'unset';
                panel.style.left  = x + 'px';
                panel.style.top   = y + 'px';
                Store.set('panelX', Math.round(x));
                Store.set('panelY', Math.round(y));
            });
        },
```

---

### Task 5.2: Atualizar title/aria do drag handle para documentar atalho

**Arquivo:** `common/mu-plugins/bureau-a11y.php` — linha do `<div id="bureau-a11y-drag-handle">`

- [ ] **Localizar e editar o drag handle no PHP** (~linha 111):

```php
// Antes:
<div class="ba-panel__header" id="bureau-a11y-drag-handle">

// Depois:
<div class="ba-panel__header" id="bureau-a11y-drag-handle"
    title="<?php esc_attr_e( 'Arraste para mover · Alt+Setas para mover por teclado', 'bureau-a11y' ); ?>"
    aria-label="<?php esc_attr_e( 'Cabeçalho do painel — arraste ou use Alt+Setas para mover', 'bureau-a11y' ); ?>">
```

---

### Task 5.3: Adicionar :focus-visible override no CSS mobile (bottom-sheet)

**Arquivo:** `common/mu-plugins/bureau-a11y/bureau-a11y.css` — final do bloco `@media (max-width: 768px)` (~linha 1229–1232)

- [ ] **Adicionar regras :focus-visible dentro do media query mobile**

Localizar o fechamento do `@media (max-width: 768px)` (~linha 1232):
```css
    .ba-tab-content {
        max-height: calc(75vh - 280px);
    }
}
```

Inserir antes do `}` de fechamento:

```css
    .ba-tab-content {
        max-height: calc(75vh - 280px);
    }

    /* Focus visible explicit para mobile (teclado Bluetooth, acessibilidade) */
    #bureau-a11y-panel button:focus-visible,
    #bureau-a11y-panel [role="tab"]:focus-visible,
    #bureau-a11y-panel input:focus-visible,
    #bureau-a11y-panel a:focus-visible {
        outline: 2px solid #BDF839 !important;
        outline-offset: 2px !important;
        box-shadow: 0 0 0 4px rgba(189,248,57,0.25) !important;
    }
}
```

---

### Task 5.4: Sync, flush e verificação WCAG

- [ ] **Abrir `playground.html` e testar keyboard drag antes de sincronizar:**
  - Abrir o playground, pressionar `Alt+ArrowRight` enquanto o painel está aberto — deve mover
  - DevTools mobile emulation (768px): Tab pelo painel — outline verde visível nos elementos focados

- [ ] **Executar SYNC_AND_FLUSH**

- [ ] **Verificar no browser (https://cambrasmax.local:8484):**
  - Abrir painel A11y, pressionar `Alt+ArrowRight` — painel move 20px para a direita
  - Pressionar `Alt+ArrowUp` repetidamente até limite — painel não sai da viewport
  - DevTools mobile view (768px) → Tab pelo painel — elementos focados têm outline verde visível
  - Verificar que `Alt+A` sem Arrow não ativa o atalho (somente `Alt+Arrow`)

---

### Task 5.5: Bump para v2.3.19 + commit

- [ ] **Atualizar versão para 2.3.19** nos 3 locais

- [ ] **Commit**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git add docker-dev/common/mu-plugins/bureau-a11y.php \
        docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y.js \
        docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y.css
git commit -m "$(cat <<'EOF'
feat(bureau-a11y): v2.3.19 — WCAG 2.1.1 teclado para drag + focus-visible mobile

- Alt+Setas: move painel com teclado (WCAG 2.1.1 Nível A — drag sem alternativa era violação)
- drag handle: title + aria-label documentam atalho de teclado
- CSS mobile: :focus-visible explícito no bottom-sheet (WCAG 2.4.3 mobile)

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Chunk 6: P2 Tech Debt

**Arquivos:** `bureau-a11y.js`, `bureau-a11y.css`, `bureau-a11y.php`
**Prioridade:** P2 (todos os itens)
**Tempo estimado:** 90 min

> **Atenção:** Este chunk remove código de produção (retrocompat). Criar branch isolada antes de começar.

- [ ] **Criar branch isolada para este chunk:**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git checkout -b bureau-a11y-tech-debt-$(date +%Y%m%d)
```

Se o chunk for bem-sucedido, fazer merge na main. Se algo quebrar, `git checkout main` descarta tudo.

---

### Task 6.1: Versionamento por arquivo (PHP constants)

**Arquivo:** `bureau-a11y.php`

Atualmente uma única constante `BUREAU_A11Y_VERSION` serve para JS e CSS. Se apenas CSS muda, JS é desnecessariamente bust. Fix: constantes separadas.

- [ ] **Editar PHP — trocar define único por dois defines**

Localizar (~linha 15):
```php
define( 'BUREAU_A11Y_VERSION', '2.3.19' );
```

Substituir por:
```php
define( 'BUREAU_A11Y_VERSION', '2.3.19' );   // mantido para retrocompat
define( 'BUREAU_A11Y_CSS_VERSION', '2.3.19' );
define( 'BUREAU_A11Y_JS_VERSION', '2.3.19' );
```

- [ ] **Editar PHP — usar constantes específicas no enqueue**

Localizar (~linha 44):
```php
	wp_enqueue_style(
		'bureau-a11y',
		BUREAU_A11Y_URL . 'bureau-a11y.css',
		[ 'ba-font' ],
		BUREAU_A11Y_VERSION
	);
```

Substituir por:
```php
	wp_enqueue_style(
		'bureau-a11y',
		BUREAU_A11Y_URL . 'bureau-a11y.css',
		[ 'ba-font' ],
		BUREAU_A11Y_CSS_VERSION
	);
```

Localizar (~linha 56):
```php
	wp_enqueue_script(
		'bureau-a11y',
		BUREAU_A11Y_URL . 'bureau-a11y.js',
		[],
		BUREAU_A11Y_VERSION,
		true
	);
```

Substituir por:
```php
	wp_enqueue_script(
		'bureau-a11y',
		BUREAU_A11Y_URL . 'bureau-a11y.js',
		[],
		BUREAU_A11Y_JS_VERSION,
		true
	);
```

---

### Task 6.2: VLibras CDN timeout (SPOF prevention)

**Arquivo:** `bureau-a11y.js` — método `_enable()` do Libras (~linha 1048)

Se o CDN do VLibras estiver down, o script nunca carrega e o plugin fica tentando indefinidamente. Adicionar timeout de 8s.

- [ ] **Editar `_enable()` — adicionar timeout ao carregamento do SDK VLibras**

Localizar (linhas 1048–1055 exatas):
```javascript
                } else if (!this._loaded) {
                    var s = document.createElement('script');
                    s.src = 'https://vlibras.gov.br/app/vlibras-plugin.js';
                    s.onload = function () {
                        Libras._loaded = true;
                        Libras._initWidget();
                    };
                    document.body.appendChild(s);
```

Substituir por:
```javascript
                } else if (!this._loaded) {
                    var s = document.createElement('script');
                    s.src = 'https://vlibras.gov.br/app/vlibras-plugin.js';
                    var _vlibrasTout = setTimeout(function () {
                        console.warn('[Bureau A11y] VLibras CDN não respondeu em 8s — desativando');
                        var container = document.getElementById('bureau-vlibras-container');
                        if (container) container.style.display = 'none';
                    }, 8000);
                    s.onload = function () {
                        clearTimeout(_vlibrasTout);
                        Libras._loaded = true;
                        Libras._initWidget();
                    };
                    document.body.appendChild(s);
```

---

### Task 6.3: Remover retrocompat v1 `html.a11y`

> **[APROVAÇÃO MANUAL OBRIGATÓRIA]** Antes de executar esta task, verificar se algum usuário ainda tem `a11yMode` no usermeta do WordPress:
>
> ```bash
> docker exec -u www-data www2-concertacao-dev-wordpress wp db query \
>     "SELECT COUNT(*) as total FROM wp_usermeta WHERE meta_key='a11yMode';" 2>/dev/null
> ```
>
> Se `total > 0`, **pular esta task**. Se `total = 0`, prosseguir.

**Arquivos:** `bureau-a11y.css` (linhas 27–98 e 1161–1173), `bureau-a11y.js` (linha 1509–1512)

- [ ] **Remover bloco CSS RETROCOMPATIBILIDADE** (linhas 27–98):

Remover o bloco completo entre os comentários:
```css
/* ==========================================================================
   RETROCOMPATIBILIDADE: html.a11y (mantido intacto)
   ========================================================================== */
```
...até (e incluindo) a última regra `html.a11y nav.elementor-nav-menu--main a { ... }`.

- [ ] **Remover bloco CSS legacy no final** (linhas ~1161–1173):

Remover:
```css
/* Legacy retrocompat */
.a11y-group {
    opacity: 0;
    scale: 0;
    pointer-events: none;
}

html.a11y .a11y-group {
    opacity: 1;
    scale: 1;
    pointer-events: all;
    transform: translate(0, 0);
}
```

- [ ] **Remover bloco JS de migração v1** (linhas ~1509–1512):

Remover:
```javascript
        // Legacy a11y mode support (v1 toggle key)
        if (localStorage.getItem('a11yMode') === 'enabled' && !Store.get('highContrast')) {
            Store.set('highContrast', true);
            document.documentElement.classList.add('a11y', 'ba-high-contrast');
        }
```

---

### Task 6.4: Sync, flush e verificação completa

- [ ] **Executar SYNC_AND_FLUSH**

- [ ] **Verificar todas as features:**
  - Acessar `https://cambrasmax.local:8484` — site carrega normalmente
  - Verificar Network: `bureau-a11y.js?ver=2.3.20` e `bureau-a11y.css?ver=2.3.20` separados e corretos
  - Ativar/desativar cada uma das 12 features do painel — todas devem funcionar
  - VLibras: ativar, usar, fechar — sem erros no Console
  - Modo privado: plugin carrega normalmente, hints funcionam (com fallback)

---

### Task 6.5: Bump para v2.4.0 + commit

A remoção de retrocompat e refatoração de versionamento justifica bump de minor.

- [ ] **Atualizar para v2.4.0** nos 3 locais + constantes PHP (CSS_VERSION e JS_VERSION)

- [ ] **Commit**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git add docker-dev/common/mu-plugins/bureau-a11y.php \
        docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y.js \
        docker-dev/common/mu-plugins/bureau-a11y/bureau-a11y.css
git commit -m "$(cat <<'EOF'
refactor(bureau-a11y): v2.4.0 — tech debt P2: versionamento por arquivo, VLibras timeout, remove retrocompat v1

- PHP: BUREAU_A11Y_CSS_VERSION + BUREAU_A11Y_JS_VERSION separados
- VLibras: timeout 8s se CDN down (SPOF prevention)
- Remove html.a11y retrocompat (v1 legacy) — CSS -72 linhas, JS -4 linhas

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Sumário de versões

| Chunk | Versão | Mudanças |
|-------|--------|----------|
| 1 | 2.3.15 | CSS alinhado, contraste logo+links, will-change |
| 2 | 2.3.16 | localStorage try/catch, TTS guard, reset confirm |
| 3 | 2.3.17 | VLibras observers disconnect, waitForEl race fix |
| 4 | 2.3.18 | RAF mousemove, transform ruler, clientX magnifier |
| 5 | 2.3.19 | Alt+Setas drag, focus-visible mobile |
| 6 | 2.4.0  | Versionamento por arquivo, CDN timeout, -retrocompat |

## Issues fora do escopo deste plano (P2 Estrutural)

Os itens abaixo foram identificados como **tech debt arquitetural** e merecem plano separado quando houver bandwidth:

- **Refatoração #1** (A3): 7 features `_initSimpleToggle` → array declarativo (-200 linhas)
- **Refatoração #2** (A3): Speech module split em SpeechProvider + SpeechUI + HoverRead
- **FOUC DarkMode** (A1): injeção de CSS inline no PHP para evitar flash de 200ms no reload

Criar novo plano com prefixo `2026-XX-XX-bureau-a11y-refactor.md` quando prioritário.
