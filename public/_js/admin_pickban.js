/* Éditeur « Overlay Pick/Ban » (/admin/overlay/pickban/{token}) :
   remplissage assisté des équipes via l'API ETF2L (compétition → équipes,
   même mécanique que l'éditeur bracket), remplissage des cartes depuis la
   liste des maps officielles (nom + miniature), et contrôle en direct du
   format strict : le compte de cartes PICK / BAN doit correspondre au
   format choisi, sinon le formulaire ne part pas (le serveur revalide). */
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

    var etf2lTeams = [];

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

    /* ── Remplissage assisté ETF2L (équipes) ─────────────────────────── */

    function buildTeamPickers() {
        if (etf2lTeams.length === 0) {
            return;
        }

        document.querySelectorAll('.js-etf2l-team').forEach(function (block) {
            if (block.querySelector('.js-etf2l-picker')) {
                return;
            }

            var side = block.dataset.side === 'b' ? 'b' : 'a';
            var select = document.createElement('select');
            select.className = 'form-control js-etf2l-picker';
            select.dataset.side = side;

            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '— Piocher une équipe ETF2L —';
            select.appendChild(placeholder);

            etf2lTeams.forEach(function (team) {
                var option = document.createElement('option');
                option.value = team.name;
                option.textContent = team.name;
                option.dataset.avatar = team.avatar || '';
                select.appendChild(option);
            });

            block.appendChild(select);
        });
    }

    var loadTeams = document.getElementById('pickban-load-teams');
    if (loadTeams) {
        loadTeams.addEventListener('click', function () {
            var competition = document.getElementById('pickban-teams-competition');
            var status = document.getElementById('pickban-teams-status');
            if (!competition || !competition.value) {
                return;
            }

            status.textContent = 'Chargement…';
            fetch('/admin/overlay/pickban/teams?competition_id=' + encodeURIComponent(competition.value), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    etf2lTeams = (data && data.teams) || [];
                    if (etf2lTeams.length === 0) {
                        status.textContent = 'Aucune équipe trouvée (API indisponible ou compétition inconnue).';
                        status.style.color = '#ff8080';
                        return;
                    }
                    status.textContent = etf2lTeams.length + ' équipe(s) chargée(s) — utilisez le menu de chaque équipe.';
                    status.style.color = '#777';
                    buildTeamPickers();
                })
                .catch(function () {
                    status.textContent = 'Erreur réseau : réessayez.';
                    status.style.color = '#ff8080';
                });
        });
    }

    document.addEventListener('change', function (event) {
        var select = event.target.closest ? event.target.closest('.js-etf2l-picker') : null;
        if (!select) {
            return;
        }

        var side = select.dataset.side === 'b' ? 'b' : 'a';
        var team = null;
        for (var i = 0; i < etf2lTeams.length; i++) {
            if (etf2lTeams[i].name === select.value) {
                team = etf2lTeams[i];
                break;
            }
        }

        var name = document.getElementById('team-' + side + '-name');
        var avatar = document.getElementById('team-' + side + '-avatar');
        if (name) {
            name.value = team ? team.name : '';
        }
        if (avatar) {
            avatar.value = team ? (team.avatar || '') : '';
        }
    });
})();
