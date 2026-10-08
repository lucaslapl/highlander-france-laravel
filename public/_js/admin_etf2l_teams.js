/* Remplissage assisté « équipes ETF2L » partagé par tous les outils
   overlay (Logs, Scores, Bracket, Pick/Ban) : le bouton « Charger les
   équipes » de la boîte .js-etf2l-fill (partial admin/partials/etf2l_teams)
   interroge /admin/overlay/etf2l/teams?competition_id=N puis insère un
   menu de sélection dans chaque bloc .js-etf2l-team du formulaire, qui
   remplit les champs .js-team-field-name / .js-team-field-avatar /
   .js-team-field-country d'un clic. Le champ caché optionnel
   .js-team-field-etf2l-id reçoit l'identifiant ETF2L de l'équipe
   choisie (liaison côté serveur pour l'alignement automatique des
   couleurs de logs sur les rosters), et le champ optionnel
   .js-team-field-players (séries) est rempli avec les SteamIDs du
   roster via /admin/overlay/etf2l/roster?team_id=N. Les scripts qui
   clonent des blocs équipe (éditeur bracket) équipent les nouveaux
   blocs en appelant window.hlfrEtf2lFillTeamPickers(). */

(function () {
    'use strict';

    /* Équipes chargées [{id, name, avatar, country}] ; le menu inséré dans
       chaque bloc équipe remplit nom, avatar et pays d'un clic. */
    var etf2lTeams = [];

    function fillTeamPickers() {
        if (etf2lTeams.length === 0) {
            return;
        }
        document.querySelectorAll('.js-etf2l-team').forEach(function (block) {
            if (block.querySelector('.js-etf2l-picker')) {
                return;
            }
            var host = document.createElement('div');
            host.style.margin = '0 0 8px';
            var select = document.createElement('select');
            select.className = 'form-control js-etf2l-picker';
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '— Choisir une équipe ETF2L —';
            select.appendChild(placeholder);
            etf2lTeams.forEach(function (team) {
                var option = document.createElement('option');
                option.value = team.name;
                option.textContent = team.name;
                select.appendChild(option);
            });
            host.appendChild(select);
            block.insertBefore(host, block.firstChild);
        });
    }

    /* Point d'entrée pour les scripts qui ajoutent des blocs équipe après
       coup (éditeur bracket : colonnes, cases, lignes de classement). */
    window.hlfrEtf2lFillTeamPickers = fillTeamPickers;

    document.querySelectorAll('.js-etf2l-load').forEach(function (loadTeams) {
        loadTeams.addEventListener('click', function () {
            var box = loadTeams.closest('.js-etf2l-fill');
            var competition = box ? box.querySelector('.js-etf2l-competition') : null;
            var status = box ? box.querySelector('.js-etf2l-status') : null;
            if (!competition || !competition.value) {
                return;
            }
            loadTeams.disabled = true;
            if (status) {
                status.style.color = '#888';
                status.textContent = 'Chargement des équipes…';
            }
            fetch('/admin/overlay/etf2l/teams?competition_id=' + encodeURIComponent(competition.value), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    etf2lTeams = (data && data.teams) || [];
                    if (etf2lTeams.length === 0) {
                        if (status) {
                            status.style.color = '#ff8080';
                            status.textContent = 'Aucune équipe trouvée (API ETF2L indisponible ou compétition vide ?).';
                        }
                        return;
                    }
                    if (status) {
                        status.style.color = '#8c8';
                        status.textContent = etf2lTeams.length + ' équipe(s) chargée(s) — utilisez le menu de chaque bloc équipe.';
                    }
                    fillTeamPickers();
                })
                .catch(function () {
                    if (status) {
                        status.style.color = '#ff8080';
                        status.textContent = 'Chargement impossible, réessayez plus tard.';
                    }
                })
                .finally(function () {
                    loadTeams.disabled = false;
                });
        });
    });

    document.addEventListener('change', function (event) {
        var select = event.target.closest ? event.target.closest('.js-etf2l-picker') : null;
        if (!select) {
            return;
        }
        var block = select.closest('.js-etf2l-team');
        var team = null;
        for (var i = 0; i < etf2lTeams.length; i++) {
            if (etf2lTeams[i].name === select.value) {
                team = etf2lTeams[i];
                break;
            }
        }
        ['name', 'avatar', 'country'].forEach(function (field) {
            var input = block ? block.querySelector('.js-team-field-' + field) : null;
            if (input) {
                input.value = team ? (team[field] || '') : '';
            }
        });

        // Liaison ETF2L : l'identifiant suit l'équipe choisie (vide si le
        // menu revient sur le choix neutre), le serveur s'en sert pour
        // récupérer le roster et aligner les couleurs des logs.
        var etf2lIdInput = block ? block.querySelector('.js-team-field-etf2l-id') : null;
        if (etf2lIdInput) {
            etf2lIdInput.value = team ? (team.id || '') : '';
        }

        // Roster : uniquement pour les blocs qui exposent un champ de
        // joueurs (séries) — les SteamIDs remplissent alors le champ,
        // prêts à être corrigés à la main (mercs, saisons passées).
        var playersField = block ? block.querySelector('.js-team-field-players') : null;
        if (playersField) {
            fillRosterPlayers(playersField, team ? team.id : 0);
        }
    });

    function fillRosterPlayers(textarea, teamId) {
        if (!teamId) {
            textarea.value = '';

            return;
        }
        textarea.readOnly = true;
        fetch('/admin/overlay/etf2l/roster?team_id=' + encodeURIComponent(teamId), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                var roster = (data && data.roster) || null;
                textarea.value = roster && roster.players ? roster.players.join('\n') : '';
                textarea.placeholder = roster ? '' : 'Roster ETF2L indisponible : saisissez les SteamIDs à la main.';
            })
            .catch(function () {
                textarea.value = '';
                textarea.placeholder = 'Roster ETF2L indisponible : saisissez les SteamIDs à la main.';
            })
            .finally(function () {
                textarea.readOnly = false;
            });
    }
}());
