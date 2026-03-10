/**
 * Bureau A11y - Accessibility Module
 * Version: 1.0.0
 * Author: Bureau de Tecnologia Ltda.
 *
 * Three modules: Toggle, Speech (ResponsiveVoice lazy-load), VLibras
 * Zero dependencies, vanilla JS.
 */
(function () {
    'use strict';

    var A11Y_MODE_KEY = 'a11yMode';

    /* ======================================================================
       TOGGLE MODULE: a11y mode on/off with localStorage persistence
       ====================================================================== */
    var Toggle = {
        init: function () {
            var button = document.getElementById('ucpa-a11y-newbutton');
            if (!button) return;

            // Restore saved preference
            if (localStorage.getItem(A11Y_MODE_KEY) === 'enabled') {
                document.documentElement.classList.add('a11y');
            }

            button.addEventListener('click', function () {
                var isEnabled = document.documentElement.classList.toggle('a11y');
                localStorage.setItem(A11Y_MODE_KEY, isEnabled ? 'enabled' : 'disabled');
                if (!isEnabled) {
                    Speech.cancel();
                }
            });
        }
    };

    /* ======================================================================
       SPEECH MODULE: ResponsiveVoice lazy-loaded on first use
       ====================================================================== */
    var Speech = {
        _loaded: false,
        _loading: false,
        _queue: null,

        _loadScript: function (callback) {
            if (this._loaded) { callback(); return; }
            if (this._loading) { return; }
            this._loading = true;

            var key = (typeof bureauA11y !== 'undefined' && bureauA11y.rvKey)
                ? bureauA11y.rvKey
                : '';
            var s = document.createElement('script');
            s.src = 'https://code.responsivevoice.org/responsivevoice.js?key=' + key;
            s.onload = function () {
                Speech._loaded = true;
                Speech._loading = false;
                callback();
            };
            document.body.appendChild(s);
        },

        speak: function (text) {
            if (!text) return;
            this._queue = text;

            var doSpeak = function () {
                if (!Speech._queue) return;
                var t = Speech._queue;
                Speech._queue = null;
                if (typeof responsiveVoice !== 'undefined') {
                    responsiveVoice.speak(t, 'Brazilian Portuguese Male', {
                        onend: function () {
                            Speech._onEnd();
                        }
                    });
                }
            };

            if (this._loaded) {
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
            this._onEnd();
        },

        _onEnd: function () {
            var btn = document.getElementById('a11y-Play-Stop-Button');
            if (btn) {
                btn.setAttribute('aria-label', 'Tocar texto selecionado');
                btn.classList.remove('playing');
            }
        },

        _isPlaying: false,

        init: function () {
            var btn = document.getElementById('a11y-Play-Stop-Button');
            if (!btn) return;

            btn.addEventListener('click', function () {
                if (Speech._isPlaying) {
                    Speech.cancel();
                    Speech._isPlaying = false;
                } else {
                    var text = window.getSelection ? window.getSelection().toString() : '';
                    if (text) {
                        btn.setAttribute('aria-label', 'Parar de tocar');
                        btn.classList.add('playing');
                        Speech._isPlaying = true;
                        Speech.speak(text);
                    }
                }
            });

            // Tooltip hover effect
            btn.addEventListener('mouseover', function () {
                btn.classList.add('hover');
            });
            btn.addEventListener('mouseout', function () {
                setTimeout(function () {
                    btn.classList.remove('hover');
                }, 1200);
            });

            // Reset state when speech ends
            var origOnEnd = Speech._onEnd;
            Speech._onEnd = function () {
                Speech._isPlaying = false;
                origOnEnd();
            };
        }
    };

    /* ======================================================================
       VLIBRAS MODULE: Initialize widget with custom SVG button
       ====================================================================== */
    var Libras = {
        init: function () {
            if (typeof VLibras === 'undefined') return;

            new VLibras.Widget('https://vlibras.gov.br/app');

            // Wait for VLibras to inject its button, then replace with custom SVG
            var observer = new MutationObserver(function (mutations, obs) {
                var accessBtn = document.querySelector('[vw-access-button] img.access-button');
                if (accessBtn) {
                    Libras._insertSVG();
                    obs.disconnect();
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        },

        _insertSVG: function () {
            var container = document.querySelector('[vw-access-button]');
            if (!container) return;

            var NS = 'http://www.w3.org/2000/svg';
            var svg = document.createElementNS(NS, 'svg');
            svg.setAttribute('class', 'a11y-group a11y-button--SVG-hands');
            svg.setAttribute('xmlns', NS);
            svg.setAttribute('width', '40');
            svg.setAttribute('height', '40');
            svg.setAttribute('viewBox', '0 0 40 40');
            svg.setAttribute('fill', 'none');

            var title = document.createElementNS(NS, 'title');
            title.textContent = 'Conteúdo acessível em Libras usando o VLibras Widget com opções dos Avatares Ícaro, Hosana ou Guga.';
            svg.appendChild(title);

            var rect = document.createElementNS(NS, 'rect');
            rect.setAttribute('width', '40');
            rect.setAttribute('height', '40');
            rect.setAttribute('rx', '8');
            rect.setAttribute('fill', 'url(#bureau-a11y-grad)');
            svg.appendChild(rect);

            var path = document.createElementNS(NS, 'path');
            path.setAttribute('d', 'M14.3515 8.00885C14.2659 8.02229 14.0952 8.12725 13.9722 8.24213C13.7501 8.44966 13.7489 8.4543 13.7954 8.95863C13.8212 9.23785 13.873 9.84616 13.9104 10.3105C14.2022 13.9301 14.2501 15.014 14.1218 15.0933C13.906 15.2267 13.787 15.0254 11.7744 11.1229C11.0845 9.7851 10.953 9.64412 10.5264 9.78488C9.84976 10.0082 9.90301 10.3457 10.984 12.685C11.1227 12.9851 11.2361 13.2431 11.2361 13.2584C11.2361 13.2738 11.3578 13.5507 11.5068 13.8737C12.3139 15.6253 12.5242 16.3377 12.2637 16.4377C12.0791 16.5085 11.7503 16.3324 11.48 16.0181C11.3425 15.8582 11.1733 15.664 11.1041 15.5866C11.0348 15.5093 10.8383 15.2718 10.6674 15.059C10.4965 14.8462 10.3404 14.6563 10.3205 14.6369C10.3006 14.6176 9.97505 14.2328 9.59714 13.7818C9.21915 13.3308 8.86093 12.943 8.80099 12.92C8.45635 12.7878 8 13.0487 8 13.3779C8 13.6653 8.6909 14.8215 10.129 16.9405C11.0303 18.2684 11.2421 18.7465 11.4461 19.9131C11.8781 22.3846 12.466 23.8997 13.2266 24.5023C13.7444 24.9126 13.759 24.9156 14.9781 24.8635L16.0946 24.8158L16.943 23.6835C17.4096 23.0607 17.828 22.5037 17.8729 22.4456C18.1983 22.0239 19.0448 20.5299 19.0448 20.3774C19.0448 20.3556 18.6049 20.3579 18.0673 20.3823C17.4053 20.4125 16.9455 20.3987 16.6427 20.3399C16.1178 20.2377 15.4567 19.9369 15.094 19.6351C14.5216 19.1588 14.3534 18.3134 14.7102 17.7047C14.9767 17.2499 15.3462 17.0548 16.1229 16.9585C16.7506 16.8808 17.4221 16.6978 18.3941 16.3399L18.9041 16.1521V15.3306C18.9041 14.489 19.0647 11.5539 19.1866 10.1698C19.2823 9.08146 19.2839 9.09328 19.0167 8.8261C18.8321 8.64157 18.7189 8.58691 18.5211 8.58691C18.0228 8.58691 17.9956 8.65571 17.5021 11.1555C17.2578 12.3932 17.0138 13.6591 16.9598 13.9686C16.842 14.6442 16.7495 14.8932 16.5939 14.9529C16.3906 15.031 16.2003 14.7126 16.1089 14.1418C16.0627 13.8531 15.9423 13.0945 15.8413 12.4561C15.6543 11.2736 15.2804 9.05832 15.2005 8.6586C15.1525 8.41877 14.8376 8.05999 14.6342 8.01349C14.5644 7.99752 14.4372 7.99541 14.3515 8.00885ZM23.723 15.2495C23.41 15.3238 22.5004 15.6532 22.0856 15.8425C21.8429 15.9533 21.6258 16.0439 21.6033 16.0439C21.5808 16.0439 20.988 16.3278 20.286 16.6749C18.8037 17.4076 17.0871 18.0136 16.4932 18.0138C16.0849 18.0139 15.6455 18.1946 15.6116 18.3762C15.5739 18.5794 16.0014 18.9814 16.4285 19.1441C16.8072 19.2883 16.9061 19.2943 18.2628 19.2542C19.4753 19.2186 19.7325 19.2289 19.9271 19.3213C20.2619 19.4801 20.3539 19.7508 20.243 20.2514C20.13 20.7618 19.5007 22.024 18.9935 22.7575C18.6639 23.2342 16.7021 25.845 15.5625 27.3235C15.1558 27.8512 15.1376 28.12 15.4851 28.4676C15.9352 28.9176 15.8771 28.9577 18.2957 26.5266C20.5532 24.2577 20.7416 24.1089 20.8437 24.5159C20.9058 24.7631 20.92 24.727 19.9164 26.8681C19.7465 27.2303 19.6076 27.5367 19.6076 27.5488C19.6076 27.561 19.493 27.8185 19.353 28.1212C18.2031 30.607 18.1562 30.774 18.489 31.197C18.608 31.3484 18.6899 31.3796 18.962 31.3778C19.1429 31.3766 19.3398 31.337 19.3994 31.2898C19.459 31.2427 19.8045 30.666 20.1673 30.0082C20.8028 28.8558 21.1759 28.1882 21.492 27.6375C22.6596 25.6031 22.6539 25.6114 22.8653 25.6114C23.0004 25.6114 23.0041 25.6442 22.9623 26.4732C22.9242 27.2301 22.8281 28.1321 22.5632 30.2193C22.4631 31.0073 22.4759 31.6696 22.5942 31.8257C22.7334 32.0094 23.2169 32.0613 23.4383 31.9163C23.6656 31.7673 23.7716 31.3909 24.035 29.7972C24.593 26.419 24.7329 25.7764 24.9539 25.5765C25.0901 25.4532 25.0998 25.4533 25.2421 25.5821C25.323 25.6554 25.4182 25.7695 25.4536 25.8357C25.4891 25.9018 25.5956 26.6936 25.6905 27.5952C25.9573 30.1327 25.9803 30.2641 26.1891 30.4479C26.4199 30.6511 26.6555 30.6487 26.897 30.4411C27.0809 30.2829 27.0915 30.2366 27.1524 29.3331C27.1873 28.8145 27.2018 27.7887 27.1845 27.0535C27.1509 25.6219 27.2168 24.4232 27.3521 24.0053C27.3981 23.8633 27.6176 23.3198 27.84 22.7974C28.0624 22.2751 28.3782 21.4362 28.5417 20.9332C29.0456 19.3828 29.0076 18.138 28.4388 17.5662C28.318 17.4448 27.9543 17.2033 27.6306 17.0296C27.2171 16.8078 26.828 16.5127 26.3226 16.0375C25.7453 15.4948 25.5399 15.3452 25.2827 15.2804C24.9702 15.2018 24.0047 15.1826 23.723 15.2495ZM29.2115 17.3062C29.1841 17.3926 29.2353 17.6285 29.3384 17.8907C29.5664 18.4703 29.6183 18.9806 29.5192 19.6669C29.3915 20.5513 29.3968 20.6483 29.5752 20.6737C29.8161 20.708 29.9389 20.4344 30.0285 19.663C30.1261 18.8228 30.0404 18.1825 29.7513 17.5916C29.5434 17.1667 29.2971 17.0365 29.2115 17.3062ZM30.4891 17.549C30.4684 17.6029 30.5153 17.8483 30.5933 18.0942C30.7771 18.6736 30.7418 19.5889 30.5087 20.2863C30.3628 20.7228 30.3585 20.7749 30.4599 20.8763C30.7 21.1165 30.946 20.7848 31.1134 19.995C31.2908 19.1582 31.216 18.1188 30.9415 17.6059C30.8481 17.4314 30.5487 17.3937 30.4891 17.549ZM11.0411 23.5833C10.8962 23.7282 10.9436 23.9348 11.2481 24.4858C11.7649 25.421 13.0134 26.4918 13.3049 26.25C13.5114 26.0786 13.4297 25.9206 12.9961 25.653C12.4543 25.3186 11.9773 24.7755 11.6197 24.0858C11.3346 23.536 11.2029 23.4216 11.0411 23.5833ZM10.041 24.0274C9.92968 24.1616 10.0187 24.4934 10.3433 25.1541C10.7889 26.0611 11.7528 26.9914 12.1062 26.8557C12.2933 26.784 12.2451 26.5002 12.0237 26.3694C11.4365 26.0225 10.866 25.2432 10.5674 24.3803C10.4121 23.9312 10.225 23.8057 10.041 24.0274Z');
            path.setAttribute('fill', '#FDFDFD');
            svg.appendChild(path);

            var defs = document.createElementNS(NS, 'defs');
            var grad = document.createElementNS(NS, 'linearGradient');
            grad.setAttribute('id', 'bureau-a11y-grad');
            grad.setAttribute('x1', '42');
            grad.setAttribute('y1', '0');
            grad.setAttribute('x2', '-9.5');
            grad.setAttribute('y2', '43');
            grad.setAttribute('gradientUnits', 'userSpaceOnUse');

            var btnEl = document.querySelector('aside#a11yButtons button.a11y-button');
            var color1 = btnEl ? getComputedStyle(btnEl).getPropertyValue('--color1').trim() : '#174538';
            var color2 = btnEl ? getComputedStyle(btnEl).getPropertyValue('--color2').trim() : '#0e2d24';

            var stop1 = document.createElementNS(NS, 'stop');
            stop1.setAttribute('stop-color', color1);
            grad.appendChild(stop1);

            var stop2 = document.createElementNS(NS, 'stop');
            stop2.setAttribute('offset', '1');
            stop2.setAttribute('stop-color', color2);
            grad.appendChild(stop2);

            defs.appendChild(grad);
            svg.appendChild(defs);

            container.insertBefore(svg, container.firstChild);

            var accessImg = container.querySelector('img.access-button');
            if (accessImg) {
                accessImg.style.display = 'none';
            }
        }
    };

    /* ======================================================================
       BACK TO TOP MODULE
       ====================================================================== */
    var BackToTop = {
        init: function () {
            var btn = document.querySelector('[data-id="a11yBackToTopButton"]');
            if (!btn) return;

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });

            window.addEventListener('scroll', function () {
                btn.style.opacity = (document.documentElement.scrollTop > 30) ? '1' : '0';
            }, { passive: true });
        }
    };

    /* ======================================================================
       INIT: Wire everything up on DOMContentLoaded
       ====================================================================== */
    document.addEventListener('DOMContentLoaded', function () {
        Toggle.init();
        Speech.init();
        BackToTop.init();
        Libras.init();
    });
})();
