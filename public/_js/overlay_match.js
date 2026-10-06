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
