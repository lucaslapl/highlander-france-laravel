/* Overlay pick / ban de maps (/pickban-overlay/{token}) :
   auto-rafraîchissement. La page interroge
   /pickban-overlay/{token}/version toutes les 5 s ; chaque
   enregistrement côté admin bump la version et la page se recharge
   entièrement, comme l'overlay bracket. Le contenu lui-même est rendu
   côté serveur : rien à construire ici. */
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
        fetch('/pickban-overlay/' + token + '/version', { cache: 'no-store' })
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
