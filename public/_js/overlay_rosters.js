/* Overlay rosters (/roster-overlay/{token}) : bascule d'équipe et
   auto-rafraîchissement.

   Un seul roster est affiché à la fois : le bouton sous le panneau
   intervertit les équipes A et B (classe html.rosters-active-b, cliquable
   depuis OBS via « Interagir » avec la source navigateur) et mémorise la
   sélection en sessionStorage — le script d'amorce de la vue la restaure
   au rechargement, aucun flash.

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

    /* Bascule d'équipe : la classe html.rosters-active-b pilotée ici est
       la même que celle posée par le script d'amorce de la vue. */
    var switchButton = document.querySelector('.js-roster-switch');

    if (switchButton) {
        switchButton.addEventListener('click', function () {
            var activeB = document.documentElement.classList.toggle('rosters-active-b');

            try {
                sessionStorage.setItem('hlfr-rosters-team', activeB ? 'b' : 'a');
            } catch (e) {
                /* Session inaccessible : la bascule ne survivra pas au
                   rechargement de rafraîchissement, sans gravité. */
            }
        });
    }

    /* Animation d'entrée rejouée quand OBS rend la source visible/active. */
    var lastReplay = Date.now();

    function replayEnterAnimation() {
        var panel = document.querySelector('.anim');
        if (!panel) {
            return;
        }

        var now = Date.now();
        if (now - lastReplay < 2500) {
            return;
        }
        lastReplay = now;

        document.querySelectorAll('.anim').forEach(function (element) {
            element.style.animation = 'none';
            element.style.opacity = '1';
            void element.offsetWidth;
            element.style.animation = '';
            element.style.opacity = '';
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
