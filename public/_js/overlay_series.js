/* Overlay série (/series-overlay/{token}) : auto-rafraîchissement.
   La page interroge /series-overlay/{token}/version toutes les 5 s ; chaque
   écriture du journal de la série (log logs.tf rattaché par le cron, point
   manuel ou annulation depuis l'admin) bump la version et la page se recharge
   entièrement — OBS n'a jamais besoin d'être touché en direct. */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) {
        return;
    }

    var token = script.dataset.token;
    var version = String(script.dataset.version);

    /* Cast sur SourceTV retardée : la page est chargée avec ?delay=N (retard
       STV en secondes) ; le polling de version doit interroger la même vue
       retardée, on propage donc les paramètres d'URL tels quels. */
    var versionUrl = '/series-overlay/' + token + '/version' + window.location.search;

    /* Animation d'entrée rejouée quand OBS rend la source visible/active. */
    var lastReplay = Date.now();

    function replayEnterAnimation() {
        var panel = document.querySelector('.series-scoreboard');
        if (!panel) {
            return;
        }

        var now = Date.now();
        if (now - lastReplay < 2500) {
            return;
        }
        lastReplay = now;

        panel.style.animation = 'none';
        void panel.offsetWidth;
        panel.style.animation = '';
    }

    if (window.obsstudio) {
        window.obsstudio.onVisibilityChange = function (visible) {
            if (visible) {
                replayEnterAnimation();
            }
        };
        window.obsstudio.onActiveChange = function (active) {
            if (active) {
                replayEnterAnimation();
            }
        };
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            replayEnterAnimation();
        }
    });

    if (!token) {
        return;
    }

    setInterval(function () {
        fetch(versionUrl, { cache: 'no-store' })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (data && String(data.version) !== version) {
                    /* Mise à jour statique : le drapeau posé ici coupe
                       l'animation d'entrée au prochain rendu (voir la vue
                       overlay/series et .series-no-enter dans le CSS). */
                    try {
                        sessionStorage.setItem('hlfr-series-refresh', '1');
                    } catch (e) {
                        /* Session inaccessible : l'animation se rejouera, sans gravité. */
                    }
                    window.location.reload();
                }
            })
            .catch(function () {
                /* Réseau indisponible : on retentera au prochain tick. */
            });
    }, 5000);
})();
