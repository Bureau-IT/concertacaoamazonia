/**
 * Bureau A11y - Accessibility Module
 * Version: 2.5.23
 * Author: Bureau de Tecnologia Ltda.
 *
 * Modules: Store, Panel, FilterMutex, Features (Zoom, Magnifier, DyslexicFont,
 * TextSpacing, HideImages, StopAnimations, HighContrast, DarkMode, Grayscale,
 * Invert, ColorBlind, FocusMode, Speech+Hover, ReadingRuler, HighlightLinks,
 * FocusGuide, Libras), BackToTop
 *
 * Zero external dependencies at load time. All third-party libs lazy-loaded.
 */
(function () {
    'use strict';

    /* ======================================================================
       VLIBRAS CLICK BYPASS — registrado ANTES do VLibras (que é lazy-loaded)
       para interceptar clicks no painel e bloqueá-los antes que o VLibras
       os capture via seu próprio document capture listener. Despacha
       'ba:click' (custom event que VLibras desconhece) no target original,
       permitindo que os handlers do painel respondam normalmente.
       ====================================================================== */
    var _baInterceptActive = false;
    document.addEventListener('click', function (e) {
        if (_baInterceptActive) return;
        if (!document.querySelector('[vp]')) return; // VLibras não carregado
        var panel = document.getElementById('bureau-a11y-panel');
        if (!panel || !panel.contains(e.target)) return;
        e.stopImmediatePropagation();
        _baInterceptActive = true;
        e.target.dispatchEvent(new CustomEvent('ba:click', { bubbles: true, cancelable: false }));
        _baInterceptActive = false;
    }, true);

    /* ======================================================================
       STORE: localStorage persistence for all preferences
       ====================================================================== */
    var STORE_KEY = 'bureau_a11y_prefs';

    var DEFAULTS = {
        zoom:           100,
        magnifier:      false,
        dyslexicFont:   false,
        textSpacing:    false,
        hideImages:     false,
        stopAnimations: false,
        darkMode:       false,
        highContrast:   false,
        grayscale:      false,
        invert:         false,
        colorBlind:     null,
        tts:            false,
        ttsRate:        1.25,
        ttsVoice:       'female',
        ttsHoverMode:   false,
        readingRuler:   false,
        highlightLinks: false,
        focusGuide:     false,
        libras:         false,
        panelX:         null,
        panelY:         null,
        largerCursor:   false,
        lineHeight:     0,
        widgetLarge:    false
    };

    var Store = {
        _data: null,

        _load: function () {
            if (this._data) return;
            try {
                var raw = localStorage.getItem(STORE_KEY);
                this._data = raw ? JSON.parse(raw) : {};
            } catch (e) {
                this._data = {};
            }
        },

        _save: function () {
            try {
                localStorage.setItem(STORE_KEY, JSON.stringify(this._data));
            } catch (e) { /* quota exceeded or private mode */ }
        },

        get: function (key) {
            this._load();
            return key in this._data ? this._data[key] : DEFAULTS[key];
        },

        set: function (key, value) {
            this._load();
            this._data[key] = value;
            this._save();
        },

        reset: function () {
            this._data = {};
            try { localStorage.removeItem(STORE_KEY); } catch (e) {}
        }
    };

    // Apply critical classes immediately (before DOM) to avoid FOUC
    (function applyEarlyClasses() {
        Store._load();
        var html = document.documentElement;
        var prefs = Store._data;
        var get = function (k) { return k in prefs ? prefs[k] : DEFAULTS[k]; };

        if (get('highContrast'))   { html.classList.add('a11y', 'ba-high-contrast'); }
        if (get('darkMode'))       { html.classList.add('ba-dark-mode'); }
        if (get('grayscale'))      { html.classList.add('ba-grayscale'); }
        if (get('invert'))         { html.classList.add('ba-invert'); }
        if (get('stopAnimations')) { html.classList.add('ba-stop-animations'); }
        if (get('dyslexicFont'))   { html.classList.add('ba-dyslexic'); }
        if (get('textSpacing'))    { html.classList.add('ba-text-spacing'); }
        if (get('hideImages'))     { html.classList.add('ba-hide-images'); }
        if (get('highlightLinks')) { html.classList.add('ba-highlight-links'); }
        if (get('focusGuide'))     { html.classList.add('ba-focus-guide'); }
        if (get('readingRuler'))   { html.classList.add('ba-reading-ruler'); }
        if (get('largerCursor'))   { html.classList.add('ba-larger-cursor'); }
        var lh = get('lineHeight');
        if (lh === 1) { html.classList.add('ba-lh-1'); }
        else if (lh === 2) { html.classList.add('ba-lh-2'); }
        else if (lh === 3) { html.classList.add('ba-lh-3'); }

        var cb = get('colorBlind');
        if (cb) { html.classList.add('ba-cb-' + cb); }

        var zoom = get('zoom');
        if (zoom !== 100) {
            // document.body pode não existir ainda (script no <head>)
            if (document.body) {
                document.body.style.fontSize = zoom + '%';
            } else {
                document.addEventListener('DOMContentLoaded', function () {
                    if (document.body) document.body.style.fontSize = zoom + '%';
                });
            }
        }
    })();

    // Auto-apply prefers-reduced-motion (without persisting)
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        if (!Store.get('stopAnimations')) {
            document.documentElement.classList.add('ba-stop-animations');
        }
    }

    /* ======================================================================
       PANEL: open/close + drag
       ====================================================================== */
    var Panel = {
        el: null,
        trigger: null,
        closeBtn: null,
        dragHandle: null,
        _dragging: false,
        _dragOffX: 0,
        _dragOffY: 0,

        init: function () {
            this.el       = document.getElementById('bureau-a11y-panel');
            this.trigger  = document.getElementById('bureau-a11y-trigger');
            this.closeBtn = document.getElementById('bureau-a11y-close');
            this.dragHandle = document.getElementById('bureau-a11y-drag-handle');

            if (!this.el || !this.trigger) return;

            // Elevar painel, trigger e back-to-top permanentemente para <html>
            // Isso os isola de qualquer filter CSS aplicado ao body (dark mode, grayscale, etc.)
            var _permanentEls = ['bureau-a11y-panel', 'bureau-a11y-trigger', 'bureau-a11y-back-to-top'];
            _permanentEls.forEach(function (id) {
                var el = document.getElementById(id);
                if (el && el.parentNode !== document.documentElement) {
                    document.documentElement.appendChild(el);
                }
            });

            // Restore saved panel position
            // PANEL_LAYOUT_VER: incrementar ao mudar o lado/posição default do painel.
            // Invalida posições salvas de layouts anteriores automaticamente.
            var PANEL_LAYOUT_VER = '6'; // '4'=direita-mid, '5'=esquerda-do-trigger, '6'=ancorado-direita
            if (Store.get('panelLayoutVer') !== PANEL_LAYOUT_VER) {
                Store.set('panelLayoutVer', PANEL_LAYOUT_VER);
                Store.set('panelX', null);
                Store.set('panelY', null);
            }
            var savedX = Store.get('panelX');
            var savedY = Store.get('panelY');
            if (savedX !== null && savedY !== null) {
                this.el.style.left = savedX + 'px';
                this.el.style.top  = savedY + 'px';
                this.el.style.right = 'unset';
            }

            var self = this;

            _addInteraction(this.trigger, function () {
                self.toggle();
            });

            if (this.closeBtn) {
                _addInteraction(this.closeBtn, function () {
                    self.close();
                });
            }

            // Delegação de fallback — garante que X sempre funcione
            this.el.addEventListener('click', function (e) {
                var btn = e.target.closest('#bureau-a11y-close');
                if (btn) self.close();
            });
            this.el.addEventListener('ba:click', function (e) {
                var btn = e.target.closest('#bureau-a11y-close');
                if (btn) self.close();
            });

            // Close on Escape
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && self.el.getAttribute('aria-hidden') === 'false') {
                    self.close();
                }
            });

            // Keyboard shortcut: Alt+Shift+A
            document.addEventListener('keydown', function (e) {
                if ((e.altKey || e.metaKey) && e.shiftKey && e.code === 'KeyA') {
                    e.preventDefault();
                    self.toggle();
                }
            });

            // Drag (desktop only)
            if (this.dragHandle && window.innerWidth > 768) {
                this._initDrag();
            }

            // Swipe (mobile only)
            if (window.innerWidth < 768) {
                this._initSwipe();
            }
        },

        open: function () {
            if (!this.el) return;
            var panel = this.el;
            var self = this;

            // Posicionamento inicial: ancorado à direita (borda direita alinhada ao trigger).
            // O painel cresce para a ESQUERDA ao expandir (ba-widget-large), sem salto visual.
            // Só aplica se não houver posição manual salva (drag anterior).
            var hasDragPos = panel.style.left && panel.style.left !== 'unset';
            if (!hasDragPos && self.trigger && window.innerWidth > 768) {
                var tRect = self.trigger.getBoundingClientRect();
                var panelH = panel.offsetHeight || 480;
                // Borda direita do painel = borda esquerda do trigger
                var rightPx = Math.max(15, window.innerWidth - tRect.left);
                var y = Math.max(15, Math.min(
                    tRect.top + tRect.height / 2 - panelH / 2,
                    window.innerHeight - panelH - 15
                ));
                panel.style.left  = 'unset';
                panel.style.right = rightPx + 'px';
                panel.style.top   = y + 'px';
            }

            this.el.setAttribute('aria-hidden', 'false');
            if (this.trigger) this.trigger.setAttribute('aria-expanded', 'true');

            // Clamp dentro do viewport após o painel ter dimensões reais
            requestAnimationFrame(function () {
                self._clampToViewport();
            });
            var firstBtn = this.el.querySelector('.ba-tab-btn[aria-selected="true"]');
            if (firstBtn) firstBtn.focus();
        },

        close: function () {
            if (!this.el) return;
            this.el.setAttribute('aria-hidden', 'true');
            if (this.trigger) {
                this.trigger.setAttribute('aria-expanded', 'false');
                this.trigger.focus();
            }
        },

        toggle: function () {
            if (!this.el) return;
            if (this.el.getAttribute('aria-hidden') === 'false') {
                this.close();
            } else {
                this.open();
            }
        },

        // Reclampa o painel dentro do viewport respeitando gap mínimo do trigger
        _clampToViewport: function () {
            var panel = this.el;
            var trigger = this.trigger;
            if (!panel || !trigger) return;

            var pw = panel.offsetWidth;
            var ph = panel.offsetHeight;
            var vh = window.innerHeight;
            var vw = window.innerWidth;
            var y = parseFloat(panel.style.top) || 0;
            y = Math.max(15, Math.min(y, vh - ph - 15));
            panel.style.top = y + 'px';

            var usesRight = panel.style.right && panel.style.right !== 'unset';
            if (usesRight) {
                // Modo ancorado à direita: clamp para não sair do viewport
                var r = parseFloat(panel.style.right) || 0;
                r = Math.max(15, Math.min(r, vw - pw - 15));
                panel.style.right = r + 'px';
            } else {
                // Modo esquerda (após drag): limite direito = borda esquerda do trigger com 8px
                var tRect = trigger.getBoundingClientRect();
                var maxX = tRect.left - pw - 8;
                var x = parseFloat(panel.style.left) || 0;
                x = Math.max(15, Math.min(x, maxX));
                panel.style.left = x + 'px';
            }
        },

        _initDrag: function () {
            var self = this;
            var panel = this.el;
            var handle = this.dragHandle;

            handle.addEventListener('pointerdown', function (e) {
                if (e.button !== 0) return;
                // Não iniciar drag se o clique for num botão dentro do handle
                if (e.target.closest('button, [role="button"]')) return;
                self._dragging = true;
                // Converter de right-based para left-based para movimento fluido
                var rect = panel.getBoundingClientRect();
                if (panel.style.right && panel.style.right !== 'unset') {
                    panel.style.left  = rect.left + 'px';
                    panel.style.right = 'unset';
                }
                self._dragOffX = e.clientX - rect.left;
                self._dragOffY = e.clientY - rect.top;
                handle.setPointerCapture(e.pointerId);
                panel.style.transition = 'none';
                e.preventDefault();
            });

            handle.addEventListener('pointermove', function (e) {
                if (!self._dragging) return;
                var x = e.clientX - self._dragOffX;
                var y = e.clientY - self._dragOffY;
                x = Math.max(15, Math.min(x, window.innerWidth  - panel.offsetWidth  - 15));
                y = Math.max(15, Math.min(y, window.innerHeight - panel.offsetHeight - 15));
                panel.style.left = x + 'px';
                panel.style.top  = y + 'px';
            });

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
                x = Math.max(15, Math.min(x, window.innerWidth  - panel.offsetWidth  - 15));
                y = Math.max(15, Math.min(y, window.innerHeight - panel.offsetHeight - 15));
                panel.style.right = 'unset';
                panel.style.left  = x + 'px';
                panel.style.top   = y + 'px';
                Store.set('panelX', Math.round(x));
                Store.set('panelY', Math.round(y));
            });
        },

        _initSwipe: function () {
            var swipeStartX = 0;
            var panel = this.el;

            panel.addEventListener('touchstart', function (e) {
                swipeStartX = e.touches[0].clientX;
            }, { passive: true });

            panel.addEventListener('touchend', function (e) {
                var delta = e.changedTouches[0].clientX - swipeStartX;
                if (Math.abs(delta) < 50) return;
                Tabs.swipe(delta < 0 ? 1 : -1);
            }, { passive: true });
        }
    };

    /* ======================================================================
       TABS: WAI-ARIA tablist — JS-driven, Arrow key navigation
       ====================================================================== */
    var Tabs = {
        _tabs:   null,
        _panels: null,

        init: function () {
            this._tabs   = Array.prototype.slice.call(document.querySelectorAll('.ba-tab-btn[role="tab"]'));
            this._panels = Array.prototype.slice.call(document.querySelectorAll('.ba-tab-content[data-tab]'));

            if (!this._tabs.length) return;

            var self = this;

            var initialTab = Store.get('tab') || 'visual';
            this._activate(initialTab, false);

            // Click (+ pointerup para contornar interceptação do VLibras)
            this._tabs.forEach(function (tab) {
                _addInteraction(tab, function () {
                    self._activate(tab.getAttribute('data-tab'), true);
                });
            });

            // Arrow keys (WAI-ARIA tablist pattern: Left/Right, Home, End)
            this._tabs.forEach(function (tab, idx) {
                tab.addEventListener('keydown', function (e) {
                    var count = self._tabs.length;
                    var nextIdx = -1;
                    if (e.key === 'ArrowRight') nextIdx = (idx + 1) % count;
                    else if (e.key === 'ArrowLeft') nextIdx = (idx - 1 + count) % count;
                    else if (e.key === 'Home') nextIdx = 0;
                    else if (e.key === 'End') nextIdx = count - 1;
                    if (nextIdx >= 0) {
                        e.preventDefault();
                        self._activate(self._tabs[nextIdx].getAttribute('data-tab'), true);
                        self._tabs[nextIdx].focus();
                    }
                });
            });
        },

        _activate: function (tabId, persist) {
            this._tabs.forEach(function (tab) {
                var active = tab.getAttribute('data-tab') === tabId;
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.setAttribute('tabindex', active ? '0' : '-1');
            });

            this._panels.forEach(function (panel) {
                var active = panel.getAttribute('data-tab') === tabId;
                panel.classList.toggle('is-active', active);
            });

            if (persist) Store.set('tab', tabId);
        },

        swipe: function (dir) {
            var cur = document.querySelector('.ba-tab-btn[aria-selected="true"]');
            if (!cur) return;
            var idx = this._tabs.indexOf(cur);
            var next = idx + dir;
            if (next < 0 || next >= this._tabs.length) return;
            this._activate(this._tabs[next].getAttribute('data-tab'), true);
        }
    };

    /* ======================================================================
       FILTER MUTEX: grayscale / invert / colorBlind são exclusivos
       ====================================================================== */
    var FilterMutex = {
        _filterClasses: ['ba-grayscale', 'ba-invert', 'ba-cb-protanopia', 'ba-cb-deuteranopia', 'ba-cb-tritanopia'],

        clearFilters: function (except) {
            var html = document.documentElement;
            this._filterClasses.forEach(function (cls) {
                if (cls !== except) html.classList.remove(cls);
            });
            Store.set('grayscale', false);
            Store.set('invert', false);
            Store.set('colorBlind', null);
        }
    };

    /* ======================================================================
       VLIBRAS HELPERS
       ====================================================================== */

    /**
     * Ativa a legenda do VLibras por padrão ao abrir o widget.
     * VLibras não persiste preferência de legenda em localStorage, então
     * aguardamos o widget carregar e clicamos no botão se ainda não estiver ativo.
     */
    function _enableVLibrasSubtitles() {
        var attempts = 0;
        var timer = setInterval(function () {
            attempts++;
            if (attempts > 20) { clearInterval(timer); return; } // timeout 5s
            // Cancela se VLibras foi fechado enquanto aguardávamos — evita click
            // em estado fechado que pode reabrir o widget acidentalmente.
            var vwWrapper = document.querySelector('[vw-plugin-wrapper]');
            if (vwWrapper && !vwWrapper.classList.contains('active')) {
                clearInterval(timer);
                return;
            }
            var subtitlesBtn = document.querySelector('.vpw-controls-subtitles');
            if (!subtitlesBtn) return;
            clearInterval(timer);
            var controls = document.querySelector('.vpw-controls');
            if (controls && !controls.classList.contains('vpw-subtitles')) {
                subtitlesBtn.click();
            }
        }, 250);
    }

    /* ======================================================================
       FEATURES: individual feature handlers
       ====================================================================== */
    var Features = {

        // ---- ZOOM ----
        Zoom: {
            _value: 100,
            init: function () {
                this._value = Store.get('zoom') || 100;
                this._apply(this._value);
                this._updateDisplay();

                var self = this;
                var btnOut = document.querySelector('[data-action="zoom-out"]');
                var btnIn  = document.querySelector('[data-action="zoom-in"]');

                if (btnOut) {
                    _addInteraction(btnOut, function () { self._step(-10); });
                }
                if (btnIn) {
                    _addInteraction(btnIn, function () { self._step(10); });
                }
            },

            _step: function (delta) {
                this._value = Math.max(80, Math.min(200, this._value + delta));
                this._apply(this._value);
                this._updateDisplay();
                Store.set('zoom', this._value);
            },

            _apply: function (v) {
                document.body.style.fontSize = v + '%';
            },

            _updateDisplay: function () {
                var el = document.getElementById('ba-zoom-value');
                if (el) el.textContent = this._value + '%';
            }
        },

        // ---- MAGNIFIER: lupa de cursor ----
        // CSS zoom (afeta layout → scroll funciona) | +/− tamanho | ESC sai
        Magnifier: {
            _active:   false,
            _zoom:     2,
            _lensSize: 340,
            _cx: 0, _cy: 0,
            _lens: null,
            _hint: null,
            _handlers: null,

            init: function () {
                this._cx = window.innerWidth  / 2;
                this._cy = window.innerHeight / 2;
                var self = this;
                var btn  = document.getElementById('ba-toggle-magnifier');

                document.addEventListener('keydown', function (e) {
                    if (!self._active) return;
                    if (e.key === 'Escape') {
                        self._deactivate(btn);
                    } else if (e.key === '+' || e.key === '=' || e.key === 'Add') {
                        e.preventDefault();
                        self._lensSize = Math.min(600, self._lensSize + 40);
                        self._updateLensSize();
                    } else if (e.key === '-' || e.key === 'Subtract') {
                        e.preventDefault();
                        self._lensSize = Math.max(100, self._lensSize - 40);
                        self._updateLensSize();
                    }
                });

                if (btn) {
                    _updateToggleUI(btn, false);
                    _addInteraction(btn, function () {
                        if (self._active) self._deactivate(btn);
                        else self._activate(btn);
                    });
                }
            },

            _getLens: function () {
                if (this._lens) return this._lens;
                var el = document.createElement('div');
                el.id = 'ba-magnifier-ring';
                el.setAttribute('aria-hidden', 'true');
                el.style.cssText = [
                    'position:fixed',
                    'border-radius:50%',
                    'border:3px solid rgba(189,248,57,0.9)',
                    'box-shadow:0 0 0 9999px rgba(0,0,0,0.45)',
                    'pointer-events:none',
                    'z-index:2147483646',
                    'transform:translate(-50%,-50%)',
                    'will-change:left,top',
                    'transition:width 0.12s ease,height 0.12s ease',
                    'display:none'
                ].join(';');
                document.documentElement.appendChild(el);
                this._lens = el;
                this._updateLensSize();
                return el;
            },

            _getHint: function () {
                if (this._hint) return this._hint;
                var el = document.createElement('div');
                el.id = 'ba-magnifier-hint';
                el.setAttribute('aria-hidden', 'true');
                el.style.cssText = [
                    'position:fixed',
                    'bottom:16px',
                    'left:50%',
                    'transform:translateX(-50%)',
                    'background:rgba(9,28,16,0.94)',
                    'color:#BDF839',
                    'font-family:Plus Jakarta Sans,system-ui,sans-serif',
                    'font-size:12px',
                    'font-weight:500',
                    'line-height:1',
                    'padding:8px 18px',
                    'border-radius:999px',
                    'border:1px solid rgba(189,248,57,0.3)',
                    'box-shadow:0 4px 20px rgba(0,0,0,0.5)',
                    'pointer-events:none',
                    'z-index:2147483647',
                    'white-space:nowrap',
                    'display:none',
                    'letter-spacing:0.03em'
                ].join(';');
                el.innerHTML = '<span style="opacity:.55;color:#f0ede1">+&thinsp;/&thinsp;&minus;</span> tamanho da lupa &nbsp;<span style="opacity:.35">|</span>&nbsp; <span style="opacity:.55;color:#f0ede1">ESC</span> sair';
                document.documentElement.appendChild(el);
                this._hint = el;
                return el;
            },

            _updateLensSize: function () {
                if (!this._lens) return;
                this._lens.style.width  = this._lensSize + 'px';
                this._lens.style.height = this._lensSize + 'px';
            },

            _activate: function (btn) {
                this._active = true;
                var self = this;
                var lens = this._getLens();
                var hint = this._getHint();
                this._updateLensSize();
                lens.style.left = this._cx + 'px';
                lens.style.top  = this._cy + 'px';
                lens.style.display = 'block';
                hint.style.display = 'block';

                // CSS zoom afeta layout → área de scroll expande 2×, rolagem funciona normalmente.
                // Ajusta scroll para manter o ponto sob o cursor fixo no viewport após zoom.
                var scrollX = window.scrollX || window.pageXOffset;
                var scrollY = window.scrollY || window.pageYOffset;
                var docX = this._cx + scrollX;
                var docY = this._cy + scrollY;
                document.body.style.zoom = String(this._zoom);
                window.scrollTo(
                    Math.max(0, docX * this._zoom - this._cx),
                    Math.max(0, docY * this._zoom - this._cy)
                );

                var onMove = function (e) {
                    self._cx = e.clientX;
                    self._cy = e.clientY;
                    lens.style.left = e.clientX + 'px';
                    lens.style.top  = e.clientY + 'px';
                };
                document.addEventListener('mousemove', onMove);
                this._handlers = { move: onMove };

                document.documentElement.classList.add('ba-magnifier');
                _updateToggleUI(btn, true);
                var lbl = btn && btn.querySelector('.ba-toggle__label');
                if (lbl) lbl.textContent = 'Lupa ativa';
            },

            _deactivate: function (btn) {
                this._active = false;
                if (this._handlers) {
                    document.removeEventListener('mousemove', this._handlers.move);
                    this._handlers = null;
                }
                document.body.style.zoom = '';
                if (this._lens) this._lens.style.display = 'none';
                if (this._hint) this._hint.style.display = 'none';
                document.documentElement.classList.remove('ba-magnifier');
                _updateToggleUI(btn, false);
                var lbl = btn && btn.querySelector('.ba-toggle__label');
                if (lbl) lbl.textContent = 'Lupa';
            }
        },

        // ---- DYSLEXIC FONT ----
        DyslexicFont: {
            init: function () {
                _initSimpleToggle('dyslexicFont', 'ba-dyslexic', 'ba-toggle-dyslexicFont');
            }
        },

        // ---- TEXT SPACING ----
        TextSpacing: {
            init: function () {
                _initSimpleToggle('textSpacing', 'ba-text-spacing', 'ba-toggle-textSpacing');
            }
        },

        // ---- HIDE IMAGES ----
        HideImages: {
            init: function () {
                _initSimpleToggle('hideImages', 'ba-hide-images', 'ba-toggle-hideImages');
            }
        },

        // ---- STOP ANIMATIONS ----
        StopAnimations: {
            init: function () {
                _initSimpleToggle('stopAnimations', 'ba-stop-animations', 'ba-toggle-stopAnimations');
            }
        },

        // ---- HIGH CONTRAST ----
        HighContrast: {
            init: function () {
                var active = Store.get('highContrast');
                var btn = document.getElementById('ba-toggle-highContrast');
                if (btn) {
                    _updateToggleUI(btn, active);
                    _addInteraction(btn, function () {
                        active = !active;
                        Store.set('highContrast', active);
                        document.documentElement.classList.toggle('ba-high-contrast', active);
                        // Retrocompat
                        document.documentElement.classList.toggle('a11y', active);
                        _updateToggleUI(btn, active);
                    });
                }
            }
        },

        // ---- DARK MODE ----
        DarkMode: {
            _isPageDark: function () {
                var bg = window.getComputedStyle(document.body).backgroundColor;
                var m = bg.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([\d.]+))?\s*\)/i);
                if (!m) return false;
                // Alpha < 0.1 = fundo transparente → assume página clara (usa invert)
                var alpha = m[4] !== undefined ? parseFloat(m[4]) : 1;
                if (alpha < 0.1) return false;
                return (0.299 * +m[1] + 0.587 * +m[2] + 0.114 * +m[3]) / 255 < 0.4;
            },
            init: function () {
                var active = Store.get('darkMode');
                var btn = document.getElementById('ba-toggle-darkMode');
                var self = this;
                if (active) self._apply();
                if (btn) {
                    _updateToggleUI(btn, active);
                    _addInteraction(btn, function () {
                        active = !active;
                        Store.set('darkMode', active);
                        if (active) self._apply(); else self._remove();
                        _updateToggleUI(btn, active);
                    });
                }
            },
            _apply: function () {
                var html = document.documentElement;
                html.classList.add('ba-dark-mode');
                html.classList.add(this._isPageDark() ? 'ba-dark-mode--dim' : 'ba-dark-mode--invert');
                FilterProtection.activate();
            },
            _remove: function () {
                var html = document.documentElement;
                html.classList.remove('ba-dark-mode', 'ba-dark-mode--dim', 'ba-dark-mode--invert');
                FilterProtection.deactivate();
            }
        },

        // ---- GRAYSCALE ----
        Grayscale: {
            init: function () { _initFilterToggle('grayscale', 'ba-grayscale', 'ba-toggle-grayscale'); }
        },

        // ---- INVERT ----
        Invert: {
            init: function () { _initFilterToggle('invert', 'ba-invert', 'ba-toggle-invert'); }
        },

        // ---- COLOR BLIND ----
        ColorBlind: {
            init: function () {
                var active = Store.get('colorBlind');
                // Se havia filtro ativo ao carregar, proteger elementos
                if (active) FilterProtection.activate();

                var btns = document.querySelectorAll('[data-feature="colorBlind"]');
                btns.forEach(function (btn) {
                    var type = btn.getAttribute('data-cb-type');
                    _updateToggleUI(btn, active === type);

                    _addInteraction(btn, function () {
                        var currentType = Store.get('colorBlind');
                        if (currentType === type) {
                            // Desativar — remover filtro e desproteger
                            document.documentElement.classList.remove('ba-cb-' + type);
                            Store.set('colorBlind', null);
                            _updateToggleUI(btn, false);
                            FilterProtection.deactivate();
                        } else {
                            // Trocar tipo ou ativar novo — count permanece em 1
                            if (!currentType) FilterProtection.activate();
                            FilterMutex.clearFilters('ba-cb-' + type);
                            document.documentElement.classList.add('ba-cb-' + type);
                            Store.set('colorBlind', type);
                            _refreshFilterButtons();
                        }
                    });
                });
            }
        },

        // ---- SPEECH ----
        Speech: {
            _loaded: false,
            _loading: false,
            _queue: null,
            _isPlaying: false,
            _hoverTimer: null,
            _hoverActive: false,
            _ttsVoice: 'female',
            _ttsRate: 1.25,
            _voicesReady: false,
            _webSpeechFallback: false,

            _loadScript: function (callback) {
                if (this._loaded) { callback(); return; }
                if (this._loading) { return; }
                this._loading = true;
                var key = (typeof bureauA11y !== 'undefined' && bureauA11y.rvKey) ? bureauA11y.rvKey : '';
                var s = document.createElement('script');
                s.src = 'https://code.responsivevoice.org/responsivevoice.js?key=' + key;
                s.onload = function () {
                    Speech._loaded = true;
                    Speech._loading = false;
                    callback();
                };
                s.onerror = function () {
                    Speech._loading = false;
                    Speech._webSpeechFallback = true;
                    callback();
                };
                document.body.appendChild(s);
            },

            _getVoiceName: function () {
                return this._ttsVoice === 'male'
                    ? 'Brazilian Portuguese Male'
                    : 'Brazilian Portuguese Female';
            },

            // Seleciona voz WebSpeech por gênero — fallback quando RV não disponível
            _getWebSpeechVoice: function (gender) {
                if (!('speechSynthesis' in window)) return null;
                var voices = speechSynthesis.getVoices().filter(function (v) {
                    return v.lang === 'pt-BR' || v.lang === 'pt_BR';
                });
                if (!voices.length) return null;
                // Nomes masculinos e femininos conhecidos (macOS/Chrome pt-BR)
                var maleNames   = ['Eddy', 'Grandpa', 'Reed', 'Rocko'];
                var femaleNames = ['Luciana', 'Flo', 'Grandma', 'Sandy', 'Shelley'];
                var preferred   = gender === 'male' ? maleNames : femaleNames;
                for (var i = 0; i < preferred.length; i++) {
                    for (var j = 0; j < voices.length; j++) {
                        if (voices[j].name.indexOf(preferred[i]) !== -1) return voices[j];
                    }
                }
                return voices[0]; // fallback para primeira voz disponível
            },

            _speakWebSpeech: function (text, onEnd) {
                var self = this;
                speechSynthesis.cancel();
                var u = new SpeechSynthesisUtterance(text);
                u.lang  = 'pt-BR';
                u.rate  = self._ttsRate;
                var v = self._getWebSpeechVoice(self._ttsVoice);
                if (v) u.voice = v;
                u.onend = onEnd;
                speechSynthesis.speak(u);
            },

            speak: function (text) {
                if (!text) return;
                var self = this;
                this._queue = text;

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

                if (this._loaded || this._webSpeechFallback) {
                    doSpeak();
                } else {
                    this._loadScript(doSpeak);
                }
            },

            cancel: function () {
                this._queue = null;
                if (this._loaded && typeof responsiveVoice !== 'undefined') {
                    responsiveVoice.cancel();
                }
                if ('speechSynthesis' in window) {
                    speechSynthesis.cancel();
                }
                this._onEnd();
            },

            _onEnd: function () {
                Speech._isPlaying = false;
                document.documentElement.classList.remove('ba-tts-reading');
                var btn = document.getElementById('ba-toggle-ttsHoverMode');
                if (btn) {
                    var lbl = btn.querySelector('.ba-toggle__label');
                    if (lbl) lbl.textContent = 'Falar o texto';
                    _updateToggleUI(btn, Speech._hoverActive);
                }
            },

            _initHoverRead: function () {
                var self = this;
                var targets = 'p, li, h1, h2, h3, h4, h5, h6, blockquote, td';
                var _lastEl = null;

                document.addEventListener('mouseover', function (e) {
                    if (!self._hoverActive) return;
                    var el = e.target.closest(targets);
                    if (!el || el === _lastEl) return; // não resetar dentro do mesmo elemento
                    _lastEl = el;
                    clearTimeout(self._hoverTimer);
                    self._hoverTimer = setTimeout(function () {
                        var text = el.innerText || el.textContent;
                        if (text && text.trim()) {
                            self.cancel();
                            self._isPlaying = true;
                            document.documentElement.classList.add('ba-tts-reading');
                            self.speak(text.trim());
                            if (self._updateTtsLabel) self._updateTtsLabel();
                        }
                    }, 600);
                });

                document.addEventListener('mouseout', function (e) {
                    if (!self._hoverActive) return;
                    var el = _lastEl;
                    if (el && e.relatedTarget && el.contains(e.relatedTarget)) return;
                    clearTimeout(self._hoverTimer);
                    _lastEl = null;
                });
            },

            init: function () {
                var self = this;
                this._ttsVoice = Store.get('ttsVoice');
                this._ttsRate  = parseFloat(Store.get('ttsRate')) || 1.25;

                // Precarregar vozes WebSpeech e sinalizar quando prontas
                if ('speechSynthesis' in window) {
                    var voices = speechSynthesis.getVoices();
                    if (voices.length > 0) {
                        self._voicesReady = true;
                    } else if (speechSynthesis.onvoiceschanged !== undefined) {
                        speechSynthesis.onvoiceschanged = function () {
                            speechSynthesis.getVoices();
                            self._voicesReady = true;
                            // Remover loader do botão quando vozes prontas
                            var btn = document.getElementById('ba-toggle-ttsHoverMode');
                            if (btn) btn.classList.remove('ba-tts-loading');
                        };
                    } else {
                        // Browser sem onvoiceschanged (Firefox): timeout curto
                        setTimeout(function () { self._voicesReady = true; }, 500);
                    }
                }

                // Cache selection globalmente — abertura do painel limpa getSelection()
                var _selCache = '';
                document.addEventListener('selectionchange', function () {
                    var sel = window.getSelection ? window.getSelection().toString() : '';
                    if (sel && sel.trim()) _selCache = sel.trim();
                });

                // Hover mode toggle
                var hoverBtn = document.getElementById('ba-toggle-ttsHoverMode');

                function _updateTtsLabel() {
                    if (!hoverBtn) return;
                    var label = hoverBtn.querySelector('.ba-toggle__label');
                    if (label) label.textContent = self._isPlaying ? 'Parar de falar' : 'Falar o texto';
                    _updateToggleUI(hoverBtn, self._hoverActive || self._isPlaying);
                }
                self._updateTtsLabel = _updateTtsLabel;

                function _showTtsHint() {
                    if (document.getElementById('ba-tts-hint')) return;
                    var h = document.createElement('div');
                    h.id = 'ba-tts-hint';
                    h.setAttribute('aria-hidden', 'true');
                    h.textContent = 'Pressione ESC para sair do modo Leitura';
                    document.documentElement.appendChild(h);
                }
                function _removeTtsHint() {
                    var h = document.getElementById('ba-tts-hint');
                    if (h && h.parentNode) h.parentNode.removeChild(h);
                }

                // ESC hierárquico: 1º para voz, 2º sai do hover mode
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        if (self._isPlaying) {
                            // 1º ESC: para voz, mantém hover mode ativo
                            self.cancel();
                            _updateTtsLabel();
                        } else if (self._hoverActive) {
                            // 2º ESC: sai do hover mode
                            self._hoverActive = false;
                            Store.set('ttsHoverMode', false);
                            if (hoverBtn) _updateToggleUI(hoverBtn, false);
                            document.documentElement.classList.remove('ba-tts-hover-active', 'ba-tts-reading');
                            self.cancel();
                            _removeTtsHint();
                            _updateTtsLabel();
                        }
                    }
                });

                if (hoverBtn) {
                    this._hoverActive = Store.get('ttsHoverMode');
                    _updateToggleUI(hoverBtn, this._hoverActive);
                    if (this._hoverActive) {
                        document.documentElement.classList.add('ba-tts-hover-active');
                        _showTtsHint();
                        if (!self._voicesReady) hoverBtn.classList.add('ba-tts-loading');
                    }
                    _addInteraction(hoverBtn, function () {
                        if (self._isPlaying) {
                            self.cancel();
                            _updateTtsLabel();
                            return;
                        }
                        self._hoverActive = !self._hoverActive;
                        Store.set('ttsHoverMode', self._hoverActive);
                        _updateToggleUI(hoverBtn, self._hoverActive);
                        document.documentElement.classList.toggle('ba-tts-hover-active', self._hoverActive);
                        if (!self._hoverActive) {
                            document.documentElement.classList.remove('ba-tts-reading');
                            hoverBtn.classList.remove('ba-tts-loading');
                            _removeTtsHint();
                        } else {
                            _showTtsHint();
                            if (!self._voicesReady) hoverBtn.classList.add('ba-tts-loading');
                        }
                    });
                }

                // Voice radio pills (substitui select)
                var voiceRadios = document.querySelectorAll('[name="ba-tts-voice"]');
                function _applyVoiceRadio() {
                    voiceRadios.forEach(function (radio) {
                        radio.checked = (radio.value === self._ttsVoice);
                    });
                }
                _applyVoiceRadio();
                // Re-aplicar no window.load: browser form restoration pode sobrescrever após DOMContentLoaded
                window.addEventListener('load', _applyVoiceRadio, { once: true });
                voiceRadios.forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        if (this.checked) {
                            self._ttsVoice = this.value;
                            Store.set('ttsVoice', this.value);
                        }
                    });
                });

                // Rate slider
                var rateSlider = document.getElementById('ba-tts-rate');
                if (rateSlider) {
                    rateSlider.value = this._ttsRate;
                    rateSlider.addEventListener('input', function () {
                        self._ttsRate = parseFloat(rateSlider.value);
                        Store.set('ttsRate', self._ttsRate);
                    });
                }

                this._initHoverRead();
            }
        },

        // ---- READING RULER ----
        ReadingRuler: {
            _ruler: null,
            _active: false,
            _handler: null,
            _rafId: null,

            init: function () {
                this._ruler = document.getElementById('bureau-a11y-ruler');
                this._active = Store.get('readingRuler');

                var self = this;
                var btn = document.getElementById('ba-toggle-readingRuler');
                if (btn) {
                    _updateToggleUI(btn, this._active);
                    _addInteraction(btn, function () {
                        self._active = !self._active;
                        Store.set('readingRuler', self._active);
                        document.documentElement.classList.toggle('ba-reading-ruler', self._active);
                        _updateToggleUI(btn, self._active);
                        if (self._active) self._bind();
                        else self._unbind();
                    });
                }

                if (this._active) this._bind();
            },

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
        },

        // ---- HIGHLIGHT LINKS ----
        HighlightLinks: {
            init: function () {
                _initSimpleToggle('highlightLinks', 'ba-highlight-links', 'ba-toggle-highlightLinks');
            }
        },

        // ---- FOCUS GUIDE ----
        FocusGuide: {
            init: function () {
                _initSimpleToggle('focusGuide', 'ba-focus-guide', 'ba-toggle-focusGuide');
            }
        },

        // ---- LIBRAS ----
        Libras: {
            init: function () {
                var btn = document.getElementById('ba-toggle-libras');
                var active = false;
                Store.set('libras', false);  // nunca restaura ao recarregar

                if (btn) {
                    _updateToggleUI(btn, active);
                    _addInteraction(btn, function () {
                        active = !active;
                        Store.set('libras', active);
                        _updateToggleUI(btn, active);
                        // VLibras é controlado pelo click handler interno em [vw-access-button].
                        // Apenas toggle de classe [vw].active NÃO inicializa o iframe — o click é obrigatório.
                        var vwBtn = document.querySelector('[vw-access-button]');
                        if (!vwBtn) return;
                        var vwWrapper = document.querySelector('[vw-plugin-wrapper]');
                        var isOpen = vwWrapper && vwWrapper.classList.contains('active');
                        if (active !== isOpen) {
                            // Ao desativar: sai do modo selectText (cursor de tradução)
                            // antes de fechar o widget para não deixar o cursor ativo na página.
                            if (!active) {
                                var selectTextControls = document.querySelector('[vp] .vpw-controls.vpw-selectText');
                                if (selectTextControls) {
                                    var ctrlBtn = selectTextControls.querySelector('.vpw-controls-button');
                                    if (ctrlBtn) ctrlBtn.click();
                                }
                            }
                            vwBtn.click();
                        }
                        // Ativar legenda por padrão ao abrir VLibras
                        if (active) {
                            _enableVLibrasSubtitles();
                        }
                    });

                    // Observa quando VLibras abre/fecha pelo botão nativo e sincroniza o toggle do a11y.
                    var vwWrapper = document.querySelector('[vw-plugin-wrapper]');
                    if (vwWrapper && window.MutationObserver) {
                        new MutationObserver(function () {
                            var isOpen = vwWrapper.classList.contains('active');
                            if (active !== isOpen) {
                                active = isOpen;
                                Store.set('libras', active);
                                _updateToggleUI(btn, active);
                                // Garante legenda ao abrir pelo botão nativo também
                                if (active) {
                                    _enableVLibrasSubtitles();
                                }
                            }
                        }).observe(vwWrapper, { attributes: true, attributeFilter: ['class'] });
                    }
                }
            }
        }
    };

    // ---- CURSOR GRANDE ----
    Features.LargerCursor = {
        init: function () {
            _initSimpleToggle('largerCursor', 'ba-larger-cursor', 'ba-toggle-largerCursor');
        }
    };

    // ---- ALTURA DE LINHA — cicla 3 tamanhos ----
    Features.LineHeight = {
        _btns: ['ba-lh-1', 'ba-lh-2', 'ba-lh-3'],

        init: function () {
            var self = this;
            var btn = document.getElementById('ba-toggle-lineHeight');
            if (!btn) return;

            var level = Store.get('lineHeight') || 0;
            self._apply(level);
            self._updateUI(btn, level);

            _addInteraction(btn, function () {
                level = (level + 1) % 4; // 0→1→2→3→0
                Store.set('lineHeight', level);
                self._apply(level);
                self._updateUI(btn, level);
            });
        },

        _apply: function (level) {
            var html = document.documentElement;
            html.classList.remove('ba-lh-1', 'ba-lh-2', 'ba-lh-3');
            if (level > 0) html.classList.add('ba-lh-' + level);
        },

        _updateUI: function (btn, level) {
            var labels = ['Altura de linha', '1.5×', '1.7×', '2.2×'];
            btn.setAttribute('data-lh', level);
            var sub = btn.querySelector('.ba-toggle__label');
            if (sub) sub.textContent = level === 0 ? 'Altura de linha' : 'Linha: ' + labels[level];
            _updateToggleUI(btn, level > 0);
        }
    };

    // ---- WIDGET SUPERDIMENSIONADO ----
    Features.WidgetLarge = {
        init: function () {
            var btn = document.getElementById('ba-widget-size-btn');
            if (!btn) return;
            var panel = document.getElementById('bureau-a11y-panel');
            var active = Store.get('widgetLarge') || false;

            if (panel && active) panel.classList.add('ba-widget-large');
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');

            _addInteraction(btn, function () {
                active = !active;
                Store.set('widgetLarge', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                if (panel) {
                    // Se painel foi arrastado (usa 'left'), manter borda direita fixa ao expandir/encolher.
                    // Se usa 'right' (padrão), CSS transition cuida naturalmente — sem setTimeout.
                    var usesLeft = panel.style.left && panel.style.left !== 'unset';
                    var rightEdge = usesLeft ? parseFloat(panel.style.left) + panel.offsetWidth : null;
                    panel.classList.toggle('ba-widget-large', active);
                    if (usesLeft && rightEdge !== null) {
                        // Aguarda a transição CSS de width (0.22s) completar antes de reposicionar.
                        setTimeout(function () {
                            panel.style.left = (rightEdge - panel.offsetWidth) + 'px';
                            Panel._clampToViewport();
                        }, 260);
                    }
                }
            });
        }
    };

    // Keep Speech and Libras accessible via alias
    var Speech = Features.Speech;
    var Libras = Features.Libras;

    /* ======================================================================
       FILTER PROTECTION: eleva elementos a11y para <html> durante filtros CSS
       ====================================================================== */
    var FilterProtection = {
        // painel/trigger/back-to-top já elevados permanentemente para <html> no Panel.init()
        _ids: ['bureau-a11y-ruler', 'ba-tooltip-tip'],
        _els: [],
        _count: 0,

        activate: function () {
            this._count++;
            if (this._count === 1) this._elevate();
        },

        deactivate: function () {
            if (this._count <= 0) return;
            this._count--;
            if (this._count === 0) this._restore();
        },

        _elevate: function () {
            var self = this;
            self._els = [];
            self._ids.forEach(function (id) {
                var el = document.getElementById(id);
                if (el && el.parentNode !== document.documentElement) {
                    document.documentElement.appendChild(el);
                    self._els.push(el);
                }
            });
        },

        _restore: function () {
            var self = this;
            self._els.forEach(function (el) {
                if (el.parentNode === document.documentElement) {
                    document.body.appendChild(el);
                }
            });
            self._els = [];
        }
    };

    /* ======================================================================
       BACK TO TOP
       ====================================================================== */
    var BackToTop = {
        init: function () {
            var btn = document.getElementById('bureau-a11y-back-to-top');
            if (!btn) return;

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });

            window.addEventListener('scroll', function () {
                if (document.documentElement.scrollTop > 300) {
                    btn.classList.add('is-visible');
                } else {
                    btn.classList.remove('is-visible');
                }
            }, { passive: true });
        }
    };

    /* ======================================================================
       HELPERS
       ====================================================================== */
    function _updateToggleUI(btn, active) {
        if (!btn) return;
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        btn.classList.toggle('ba-toggle--active', !!active);
    }

    function _initSimpleToggle(storeKey, cssClass, btnId) {
        var active = Store.get(storeKey);
        var btn = document.getElementById(btnId);
        if (btn) {
            _updateToggleUI(btn, active);
            _addInteraction(btn, function () {
                active = !active;
                Store.set(storeKey, active);
                document.documentElement.classList.toggle(cssClass, active);
                _updateToggleUI(btn, active);
            });
        }
    }

    function _initFilterToggle(storeKey, cssClass, btnId) {
        var active = Store.get(storeKey);
        var btn = document.getElementById(btnId);
        if (active) FilterProtection.activate();
        if (btn) {
            _updateToggleUI(btn, active);
            _addInteraction(btn, function () {
                active = !active;
                Store.set(storeKey, active);
                document.documentElement.classList.toggle(cssClass, active);
                if (active) FilterProtection.activate();
                else FilterProtection.deactivate();
                _updateToggleUI(btn, active);
            });
        }
    }

    /**
     * Registra handler para click (mouse/teclado) E ba:click (custom event
     * despachado pelo bypass do VLibras quando este intercepta o click nativo).
     * Com VLibras inativo: apenas 'click' dispara. Com VLibras ativo: capture
     * listener acima bloqueia o click e despacha 'ba:click' no target.
     */
    function _addInteraction(el, fn) {
        if (!el) return;
        el.addEventListener('click', fn);
        el.addEventListener('ba:click', fn);
    }

    function _refreshFilterButtons() {
        var cbActive = Store.get('colorBlind');
        document.querySelectorAll('[data-feature="colorBlind"]').forEach(function (b) {
            _updateToggleUI(b, b.getAttribute('data-cb-type') === cbActive);
        });
        var grBtn = document.getElementById('ba-toggle-grayscale');
        if (grBtn) _updateToggleUI(grBtn, Store.get('grayscale'));
        var invBtn = document.getElementById('ba-toggle-invert');
        if (invBtn) _updateToggleUI(invBtn, Store.get('invert'));
    }

    /* ======================================================================
       INIT
       ====================================================================== */
    document.addEventListener('DOMContentLoaded', function () {
        Panel.init();
        Tabs.init();
        BackToTop.init();

        // Init all features
        Features.Zoom.init();
        Features.Magnifier.init();
        Features.DyslexicFont.init();
        Features.TextSpacing.init();
        Features.HideImages.init();
        Features.StopAnimations.init();
        Features.HighContrast.init();
        Features.DarkMode.init();
        Features.Grayscale.init();
        Features.Invert.init();
        Features.ColorBlind.init();
        Features.Speech.init();
        Features.ReadingRuler.init();
        Features.HighlightLinks.init();
        Features.FocusGuide.init();
        Features.Libras.init();
        Features.LargerCursor.init();
        Features.LineHeight.init();
        Features.WidgetLarge.init();

        // Reset button — reset direto, sem confirmação
        var resetBtn = document.getElementById('ba-reset-btn');
        if (resetBtn) {
            _addInteraction(resetBtn, function () {
                Store.reset();
                location.reload();
            });
        }

        // Hints + Tooltip unificados com first-open hint
        (function () {
            var HINTS_KEY = 'bureau_a11y_hints';
            var hintsBtn  = document.getElementById('ba-hints-toggle');
            var show;
            try { show = localStorage.getItem(HINTS_KEY) !== 'false'; } catch (e) { show = true; }

            var tip = document.createElement('div');
            tip.id = 'ba-tooltip-tip';
            tip.setAttribute('aria-hidden', 'true');
            // Append to <html> (não body) para ficar acima do painel no z-index
            document.documentElement.appendChild(tip);

            function hideTip() { tip.classList.remove('is-visible'); }

            function showTip(content, targetEl) {
                tip.textContent = content;
                tip.classList.add('is-visible');
                requestAnimationFrame(function () {
                    var rect = targetEl.getBoundingClientRect();
                    var tw = tip.offsetWidth;
                    var th = tip.offsetHeight;
                    var top = rect.top - th - 8;
                    if (top < 8) top = rect.bottom + 8;
                    var left = rect.left + (rect.width / 2) - (tw / 2);
                    if (left < 8) left = 8;
                    if (left + tw > window.innerWidth - 8) left = window.innerWidth - tw - 8;
                    tip.style.top = top + 'px';
                    tip.style.left = left + 'px';
                });
            }

            function applyHints(state) {
                if (hintsBtn) hintsBtn.setAttribute('aria-pressed', state ? 'true' : 'false');
                try { localStorage.setItem(HINTS_KEY, state ? 'true' : 'false'); } catch (e) { /* modo privado */ }
            }

            applyHints(show);

            if (hintsBtn) {
                _addInteraction(hintsBtn, function () {
                    show = !show;
                    applyHints(show);
                    hideTip();
                });
            }

            document.querySelectorAll('.ba-toggle[data-desc], .ba-toggle[data-tooltip]').forEach(function (el) {
                el.addEventListener('mouseenter', function () {
                    if (!show) return;
                    var content = el.getAttribute('data-desc') || el.getAttribute('data-tooltip');
                    if (!content) return;
                    showTip(content, el);
                });
                el.addEventListener('mouseleave', hideTip);
            });

            // First-open hint: mostra tooltip no ? durante 3s na primeira abertura
            var _hintShown = false;
            var panel = document.getElementById('bureau-a11y-panel');
            if (panel && hintsBtn) {
                var obs = new MutationObserver(function () {
                    if (_hintShown) return;
                    if (panel.getAttribute('aria-hidden') === 'false') {
                        _hintShown = true;
                        obs.disconnect();
                        setTimeout(function () {
                            showTip('Clique em ? para ocultar as dicas', hintsBtn);
                            setTimeout(hideTip, 3000);
                        }, 600);
                    }
                });
                obs.observe(panel, { attributes: true, attributeFilter: ['aria-hidden'] });
            }
        }());

        // Restore high contrast retrocompat class if needed
        if (Store.get('highContrast')) {
            document.documentElement.classList.add('a11y', 'ba-high-contrast');
        }

        /* ==================================================================
           VLIBRAS HOVER SHIELD — MutationObserver
           VLibras percorre o DOM e adiciona vw-text--hover em elementos,
           ativando seu tooltip de tradução mesmo com Libras desativado.
           Remove a classe em tempo real do painel e do trigger para que
           o hover do VLibras nunca interaja com a UI de acessibilidade.
           ================================================================== */
        if (window.MutationObserver) {
            var _baVLibrasShieldNodes = [
                document.getElementById('bureau-a11y-panel'),
                document.getElementById('bureau-a11y-trigger')
            ].filter(Boolean);

            if (_baVLibrasShieldNodes.length) {
                var _baVLibrasShieldObs = new MutationObserver(function (mutations) {
                    mutations.forEach(function (m) {
                        if (m.type === 'attributes' && m.attributeName === 'class') {
                            var el = m.target;
                            if (el.classList.contains('vw-text--hover')) {
                                el.classList.remove('vw-text--hover');
                            }
                        }
                    });
                });
                _baVLibrasShieldNodes.forEach(function (root) {
                    _baVLibrasShieldObs.observe(root, {
                        attributes: true,
                        attributeFilter: ['class'],
                        subtree: true
                    });
                });
            }
        }

        /* ==================================================================
           VLIBRAS CONTAINER STYLE SHIELD — MutationObserver
           O SDK VLibras injeta style="left:initial;right:0px;top:50%;
           bottom:initial;transform:translateY(calc(-50% - 10px))" no
           #bureau-vlibras-container após o carregamento, sobrescrevendo
           o posicionamento CSS (left:11px;bottom:11px) e causando CLS 0.304.
           Este observer reverte o atributo style ao detectar a injeção.
           ================================================================== */
        var _baVLibrasContainer = document.getElementById('bureau-vlibras-container');
        if (_baVLibrasContainer && window.MutationObserver) {
            var _baContainerStyleObs = new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    if (m.attributeName === 'style') {
                        var el = m.target;
                        /* SDK sempre injeta right:0px — é o sinal de que ele tomou o controle */
                        if (el.style.right === '0px') {
                            el.style.cssText = '';
                        }
                    }
                });
            });
            _baContainerStyleObs.observe(_baVLibrasContainer, {
                attributes: true,
                attributeFilter: ['style']
            });
        }
    });

})();
