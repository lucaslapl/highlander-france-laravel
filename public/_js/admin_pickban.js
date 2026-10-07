/* Éditeur « Overlay Pick/Ban » (/admin/overlay/pickban/{token}) :
   remplissage des cartes depuis la liste des maps officielles (nom +
   miniature), et contrôle en direct du format strict : le compte de
   cartes PICK / BAN doit correspondre au format choisi, sinon le
   formulaire ne part pas (le serveur revalide). Le remplissage assisté
   des équipes (compétition → équipes ETF2L) vit dans
   admin_etf2l_teams.js, partagé avec les autres outils overlay. */
(function () {
    'use strict';

    var configEl = document.getElementById('pickban-config');
    var config = {};
    try {
        config = JSON.parse(configEl ? (configEl.textContent || '{}') : '{}') || {};
    } catch (e) {
        config = {};
    }
    var defaultActions = config.defaultActions || {};
    var formats = config.formats || {};

    /* ── Contrôle du format strict (comptes PICK / BAN) ───────────────── */

    function currentFormat() {
        var format = document.getElementById('editor-format');
        return format ? String(format.value) : '3_3';
    }

    function updateCountStatus() {
        var picks = 0;
        var bans = 0;
        document.querySelectorAll('.js-pickban-action').forEach(function (select) {
            if (select.value === 'pick') {
                picks++;
            } else {
                bans++;
            }
        });

        var expected = formats[currentFormat()] || { picks: 3, bans: 3 };
        var ok = picks === expected.picks && bans === expected.bans;

        var status = document.getElementById('pickban-count-status');
        if (status) {
            status.textContent = picks + ' PICK / ' + bans + ' BAN — attendu : ' + expected.picks + ' / ' + expected.bans;
            status.style.color = ok ? '#8c8' : '#ff8080';
        }

        return ok;
    }

    /* ── Cartes : sélection de map (nom + miniature) ─────────────────── */

    document.addEventListener('change', function (event) {
        var select = event.target.closest ? event.target.closest('.js-pickban-map-select') : null;
        if (!select) {
            return;
        }

        var option = select.options[select.selectedIndex];
        var row = select.closest('.pickban-card-edit');
        if (!row || !option || option.value === '') {
            return;
        }

        var name = row.querySelector('.js-pickban-map-name');
        var image = row.querySelector('.js-pickban-map-image');
        if (name && option.dataset.label) {
            name.value = option.dataset.label;
        }
        if (image && option.dataset.image) {
            image.value = option.dataset.image;
        }
    });

    /* ── Cartes : action / format ────────────────────────────────────── */

    document.querySelectorAll('.js-pickban-action').forEach(function (select) {
        select.addEventListener('change', updateCountStatus);
    });

    var formatSelect = document.getElementById('editor-format');
    if (formatSelect) {
        formatSelect.addEventListener('change', function () {
            var pattern = defaultActions[currentFormat()] || [];
            var actions = document.querySelectorAll('.js-pickban-action');
            pattern.forEach(function (action, i) {
                if (actions[i]) {
                    actions[i].value = action;
                }
            });
            updateCountStatus();
        });
    }

    var form = document.getElementById('pickban-editor');
    if (form) {
        form.addEventListener('submit', function (event) {
            if (!updateCountStatus()) {
                event.preventDefault();
                window.scrollTo(0, document.body.scrollHeight);
            }
        });
    }

    updateCountStatus();

})();
