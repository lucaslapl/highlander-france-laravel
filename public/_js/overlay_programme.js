/* Overlay « Programme » (/programme-overlay/{token}) : la page vit en
   continu — horloge Europe/Paris (tic à la seconde), décompte avant le
   match (date/heure cible ou durée, avec l'ajustement manuel cumulé
   côté admin) et diaporama de messages en fondu. Le reste est rendu
   côté serveur. Comme les autres overlays, la page interroge
   /programme-overlay/{token}/version toutes les 5 s : chaque
   enregistrement (ou ajustement du décompte) côté admin bump la version
   et la page se recharge entièrement. */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) {
        return;
    }

    var token = script.dataset.token;
    var version = String(script.dataset.version);

    if (!token) {
        return;
    }

    /* ---------- Horloge (Europe/Paris) ---------- */

    var clock = document.getElementById('clock');
    var clockFmt = null;
    if (clock && window.Intl) {
        try {
            clockFmt = new Intl.DateTimeFormat('fr-FR', {
                timeZone: 'Europe/Paris',
                hour: '2-digit',
                minute: '2-digit'
            });
        } catch (e) {
            clockFmt = null;
        }
    }

    function tickClock() {
        if (clockFmt) {
            clock.textContent = clockFmt.format(new Date());
        }
    }

    /* ---------- Décompte ---------- */

    var cd = document.querySelector('.countdown');
    var cdValue = document.getElementById('countdown-value');
    var cdZeroText = (cd && cd.dataset.zeroText) ? cd.dataset.zeroText : '';

    function remainingSeconds() {
        if (!cd) {
            return null;
        }

        var now = Math.floor(Date.now() / 1000);
        var adjust = parseInt(cd.dataset.adjustS || '0', 10) || 0;
        var remaining = 0;

        if (cd.dataset.mode === 'datetime') {
            var target = parseInt(cd.dataset.targetTs || '0', 10) || 0;
            remaining = target - now;
        } else {
            var startedAt = parseInt(cd.dataset.startedAt || '0', 10) || 0;
            var duration = parseInt(cd.dataset.durationS || '0', 10) || 0;
            remaining = duration - (now - startedAt);
        }

        return remaining + adjust;
    }

    function formatRemaining(remaining) {
        if (remaining < 0) {
            remaining = 0;
        }

        var h = Math.floor(remaining / 3600);
        var m = Math.floor((remaining % 3600) / 60);
        var s = remaining % 60;

        function pad(n) {
            return (n < 10 ? '0' : '') + n;
        }

        /* Au-delà d'une heure : HH:MM (le compte des minutes suffit),
           en dessous : MM:SS comme un compte à rebours de broadcast. */
        return h > 0 ? pad(h) + ':' + pad(m) : pad(m) + ':' + pad(s);
    }

    function tickCountdown() {
        if (!cdValue) {
            return;
        }

        var remaining = remainingSeconds();
        if (remaining === null) {
            return;
        }

        if (remaining <= 0 && cdZeroText !== '') {
            cdValue.textContent = cdZeroText;
            cdValue.classList.add('is-zero');
        } else {
            cdValue.textContent = formatRemaining(remaining);
            cdValue.classList.remove('is-zero');
        }
    }

    /* ---------- Diaporama de messages en fondu ---------- */

    var slideshow = document.querySelector('.slideshow');

    function startSlideshow() {
        if (!slideshow) {
            return;
        }

        var messages = slideshow.querySelectorAll('.slideshow-msg');
        if (messages.length < 2) {
            if (messages.length === 1) {
                messages[0].classList.add('is-visible');
            }
            return;
        }

        var displayS = Math.max(2, parseInt(slideshow.dataset.displayS || '8', 10) || 8);
        var fadeS = Math.min(10, Math.max(0, parseInt(slideshow.dataset.fadeS || '1', 10) || 1));
        slideshow.style.setProperty('--fade', fadeS + 's');

        var current = 0;
        var cycleMs = (displayS + fadeS) * 1000;

        setInterval(function () {
            var next = (current + 1) % messages.length;
            messages[current].classList.remove('is-visible');
            messages[next].classList.add('is-visible');
            current = next;
        }, cycleMs);
    }

    /* ---------- Auto-rafraîchissement (polling de version) ---------- */

    setInterval(function () {
        fetch('/programme-overlay/' + token + '/version', { cache: 'no-store' })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (data && String(data.version) !== version) {
                    window.location.reload();
                }
            })
            .catch(function () {
                /* Réseau indisponible : on retentera au prochain tick. */
            });
    }, 5000);

    /* ---------- Démarrage ---------- */

    tickClock();
    tickCountdown();
    startSlideshow();

    setInterval(function () {
        tickClock();
        tickCountdown();
    }, 1000);
})();
