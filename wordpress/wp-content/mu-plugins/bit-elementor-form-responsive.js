/**
 * BIT Elementor Form Responsive — matchMedia placeholder/heading swap
 *
 * Troca o atributo `placeholder` de inputs/textareas e o textContent da
 * primeira <option disabled> de selects conforme o breakpoint atual.
 * Também troca o textContent do heading do form (h2/h3/h4 dentro do widget).
 *
 * Ativado apenas em widgets com classe .bit-form-responsive (injetada pelo
 * mu-plugin PHP quando ao menos um campo responsivo está configurado).
 *
 * Plugin: BIT Elementor Form Responsive v1.0.0
 * Author: Daniel Cambría / Bureau de Tecnologia Ltda.
 */
(function () {
    'use strict';

    var MQ = {
        mobile: window.matchMedia('(max-width: 767px)'),
        tablet: window.matchMedia('(max-width: 1024px) and (min-width: 768px)')
    };

    /** Retorna 'mobile' | 'tablet' | 'desktop' conforme o viewport atual. */
    function currentDevice() {
        if (MQ.mobile.matches)  return 'mobile';
        if (MQ.tablet.matches)  return 'tablet';
        return 'desktop';
    }

    /**
     * Aplica os textos responsivos em um único widget .bit-form-responsive.
     *
     * @param {Element} widget - raiz do widget Elementor (tem data-bit-form-name-*)
     */
    function applyResponsiveText(widget) {
        var device = currentDevice();

        // ── 1. Inputs / textareas / selects ──────────────────────────────────
        var fields = widget.querySelectorAll(
            '[data-bit-placeholder-tablet], [data-bit-placeholder-mobile]'
        );

        fields.forEach(function (el) {
            // Cache do placeholder original (desktop) — gravado UMA única vez.
            if (el.dataset.bitPlaceholderDesktop === undefined) {
                el.dataset.bitPlaceholderDesktop = el.getAttribute('placeholder') || '';
            }

            // Resolve o valor para o device atual com fallback encadeado: device → desktop.
            var key = 'bitPlaceholder' + device.charAt(0).toUpperCase() + device.slice(1);
            var val = (el.dataset[key] !== undefined && el.dataset[key] !== '')
                ? el.dataset[key]
                : el.dataset.bitPlaceholderDesktop;

            el.setAttribute('placeholder', val);

            // ── 1a. Selects: troca textContent da <option disabled selected> ──
            if (el.tagName === 'SELECT') {
                // Tenta data-bit-field-options-empty-{device} primeiro (mais específico),
                // depois cai no placeholder como fallback.
                var foeKey  = 'bitFieldOptionsEmpty' + device.charAt(0).toUpperCase() + device.slice(1);
                var foeBase = el.dataset.bitFieldOptionsEmptyDesktop;

                // Cache do empty option text original (desktop) — gravado UMA única vez.
                var emptyOpt = el.querySelector('option[disabled][selected], option[value=""][disabled]');
                if (emptyOpt) {
                    if (el.dataset.bitFieldOptionsEmptyDesktop === undefined) {
                        el.dataset.bitFieldOptionsEmptyDesktop = emptyOpt.textContent;
                    }
                    var foeVal = (el.dataset[foeKey] !== undefined && el.dataset[foeKey] !== '')
                        ? el.dataset[foeKey]
                        : (val !== '' ? val : el.dataset.bitFieldOptionsEmptyDesktop);
                    emptyOpt.textContent = foeVal;
                }
            }
        });

        // ── 2. Form heading ───────────────────────────────────────────────────
        var nameTablet = widget.dataset.bitFormNameTablet;
        var nameMobile = widget.dataset.bitFormNameMobile;

        // Só atua se ao menos um valor responsivo estiver definido no widget root.
        if ((nameTablet === undefined || nameTablet === '') &&
            (nameMobile === undefined || nameMobile === '')) {
            return;
        }

        var heading = widget.querySelector(
            '.elementor-form-heading, ' +
            '.elementor-field-group-html h2, ' +
            '.elementor-field-group-html h3, ' +
            '.elementor-field-group-html h4'
        );

        if (!heading) {
            return;
        }

        // Cache do texto original (desktop) — gravado UMA única vez.
        if (heading.dataset.bitFormNameDesktop === undefined) {
            heading.dataset.bitFormNameDesktop = heading.textContent;
        }

        var headingVal;
        if (device === 'mobile' && nameMobile !== undefined && nameMobile !== '') {
            headingVal = nameMobile;
        } else if (device === 'tablet' && nameTablet !== undefined && nameTablet !== '') {
            headingVal = nameTablet;
        } else {
            headingVal = heading.dataset.bitFormNameDesktop;
        }

        heading.textContent = headingVal;
    }

    /** Inicializa todos os widgets .bit-form-responsive na página. */
    function initAll() {
        document.querySelectorAll('.elementor-widget-form.bit-form-responsive')
            .forEach(applyResponsiveText);
    }

    // ── DOMContentLoaded ────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // ── Resize (debounced) — belt ────────────────────────────────────────────
    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(initAll, 120);
    });

    // ── orientationchange ───────────────────────────────────────────────────
    window.addEventListener('orientationchange', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(initAll, 120);
    });

    // ── matchMedia 'change' — suspenders (mais confiável para device-class transitions) ──
    // Older Safari (pre-14) only fires 'change' once — the resize listener above covers that.
    function addMqListener(mq) {
        if (typeof mq.addEventListener === 'function') {
            mq.addEventListener('change', initAll);
        } else if (typeof mq.addListener === 'function') {
            // deprecated fallback for very old browsers
            mq.addListener(initAll);
        }
    }

    addMqListener(MQ.mobile);
    addMqListener(MQ.tablet);

})();
