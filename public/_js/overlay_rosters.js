/* Overlay rosters (/roster-overlay/{token}) : auto-rafraîchissement.
   La page interroge /roster-overlay/{token}/version toutes les 5 s ;
   chaque enregistrement côté admin (affectation d'une classe, merc,
   renommage d'équipe) bump la version et la page se recharge entièrement
   — OBS n'a jamais besoin d'être touché en direct. Le drapeau posé juste
   avant le reload coupe l'animation d'entrée au rendu suivant (voir la vue
   overlay/rosters et .rosters-no-enter dans le CSS). */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) {
        return;
    }

    var token = script.dataset.token;
    var version = String(script.dataset.version);

    /* Animation d'entrée rejouée quand OBS rend la source visible/active. */
    var lastReplay = Date.now();

    function replayEnterAnimation() {
        var panels = document.querySelectorAll('.anim');
        if (panels.length === 0) {
            return;
        }

        var now = Date.now();
        if (now - lastReplay < 2500) {
            return;
        }
        lastReplay = now;

        panels.forEach(function (panel) {
            panel.style.animation = 'none';
            panel.style.opacity = '1';
            void panel.offsetWidth;
            panel.style.animation = '';
            panel.style.opacity = '';
        });
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
        fetch('/roster-overlay/' + token + '/version', { cache: 'no-store' })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (data && String(data.version) !== version) {
                    try {
                        sessionStorage.setItem('hlfr-rosters-refresh', '1');
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
