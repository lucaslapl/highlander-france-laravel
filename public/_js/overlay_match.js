/* Overlay OBS (/overlay/{token}) : auto-rafraîchissement.
   La page interroge /overlay/{token}/version toutes les 5 s ; si l'admin a
   régénéré ou modifié l'overlay depuis le panel, la page se recharge
   entièrement — OBS n'a donc jamais besoin d'être touché en direct. */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) {
        return;
    }

    var token = script.dataset.token;
    var version = String(script.dataset.version);

    /* Animation d'apparition : rejouée quand OBS rend la source visible/active
       (API window.obsstudio exposée par obs-browser). Dans un navigateur
       classique, l'animation CSS joue une fois au chargement et c'est tout. */
    var lastReplay = Date.now();

    function replayEnterAnimation() {
        var stage = document.querySelector('.overlay-stage');
        if (!stage) {
            return;
        }

        /* Anti-rebond : obs-browser peut signaler visibilité ET activation dans
           la même seconde ; on ignore aussi les rejeux trop rapprochés. */
        var now = Date.now();
        if (now - lastReplay < 2500) {
            return;
        }
        lastReplay = now;

        /* Couper les animations puis les réactiver (avec un reflow entre les
           deux) force le navigateur à les rejouer depuis le début. */
        stage.classList.add('overlay-stage--no-anim');
        void stage.offsetWidth;
        stage.classList.remove('overlay-stage--no-anim');
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

    /* Filet de sécurité : obs-browser passe aussi la page en visibilityState
       hidden/visible quand la source cesse ou reprend le rendu. */
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            replayEnterAnimation();
        }
    });

    if (!token) {
        return;
    }

    setInterval(function () {
        fetch('/overlay/' + token + '/version', { cache: 'no-store' })
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
})();
