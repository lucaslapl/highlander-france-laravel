/* Éditeur de l'outil « Overlay Rosters » : affectation assistée des
   joueurs aux classes. Quand une équipe est choisie via le remplissage
   assisté (menu .js-etf2l-picker de admin_etf2l_teams.js, qui renseigne
   le champ caché de liaison ETF2L du bloc), ce script charge le roster
   ETF2L de l'équipe (SteamIDs + pseudos officiels via
   /admin/overlay/etf2l/roster) et insère un menu dans chaque classe du
   bloc : choisir un joueur y reporte son pseudo, prêt à être corrigé à
   la main (le drapeau merc reste un choix du caster). Aucun roster sans
   liaison ETF2L : la saisie manuelle des pseudos reste possible. */

(function () {
    'use strict';

    /* Le champ de liaison est rempli par admin_etf2l_teams.js au même
       changement (son écouteur s'attache en premier : le script doit
       être chargé avant celui-ci, voir la vue admin/rosters_edit). */
    document.addEventListener('change', function (event) {
        var select = event.target.closest ? event.target.closest('.js-etf2l-picker') : null;
        if (!select) {
            return;
        }
        var block = select.closest('.js-etf2l-team');
        if (!block || !block.querySelector('.js-roster-slots')) {
            return;
        }

        var etf2lId = block.querySelector('.js-team-field-etf2l-id');
        var teamId = etf2lId ? parseInt(etf2lId.value, 10) : 0;

        removeSlotPickers(block);
        setRosterStatus(block, '', '');

        if (teamId > 0) {
            loadRoster(block, teamId);
        }
    });

    /* Choisir un joueur dans le menu d'une classe : son pseudo officiel
       ETF2L remplit le champ de la classe (le menu vit dans le slot, le
       champ est donc son voisin dans le même bloc .js-roster-slot), le
       retour au choix neutre ne vide rien (le pseudo saisi à la main
       reste maître). */
    document.addEventListener('change', function (event) {
        var picker = event.target.closest ? event.target.closest('.js-roster-slot-picker') : null;
        if (!picker) {
            return;
        }

        var slot = picker.closest('.js-roster-slot');
        var nameInput = slot ? slot.querySelector('.js-roster-slot-name') : null;
        if (nameInput && picker.value !== '') {
            nameInput.value = picker.value;
        }
    });

    function loadRoster(block, teamId) {
        setRosterStatus(block, 'Chargement du roster…', '#888');

        fetch('/admin/overlay/etf2l/roster?team_id=' + encodeURIComponent(teamId), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                var roster = (data && data.roster) || null;
                if (!roster || !roster.players || roster.players.length === 0) {
                    setRosterStatus(block, 'Roster ETF2L indisponible : saisissez les pseudos à la main.', '#ff8080');

                    return;
                }
                setRosterStatus(block, roster.players.length + ' joueur(s) dans le roster — choisissez chaque joueur dans sa classe.', '#8c8');
                insertSlotPickers(block, roster);
            })
            .catch(function () {
                setRosterStatus(block, 'Roster ETF2L indisponible : saisissez les pseudos à la main.', '#ff8080');
            });
    }

    function insertSlotPickers(block, roster) {
        block.querySelectorAll('.js-roster-slot').forEach(function (slot) {
            var host = document.createElement('div');
            host.style.cssText = 'width:100%; margin-top:6px;';

            var select = document.createElement('select');
            select.className = 'form-control js-roster-slot-picker';
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '— Choisir un joueur —';
            select.appendChild(placeholder);

            roster.players.forEach(function (steamid) {
                var option = document.createElement('option');
                option.value = (roster.names && roster.names[steamid]) || steamid;
                option.textContent = option.value;
                select.appendChild(option);
            });

            host.appendChild(select);
            /* Le menu vit DANS le slot : le gestionnaire de changement le
               remonte jusqu'au champ de nom du même slot (un menu inséré
               à côté du slot ne serait rattaché à aucun champ, et le
               pseudo ne serait jamais enregistré). */
            slot.appendChild(host);
        });
    }

    function removeSlotPickers(block) {
        block.querySelectorAll('.js-roster-slot-picker').forEach(function (select) {
            select.parentNode.remove();
        });
    }

    function setRosterStatus(block, text, color) {
        var status = block.querySelector('.js-roster-status');
        if (!status) {
            status = document.createElement('p');
            status.className = 'js-roster-status';
            status.style.cssText = 'margin:8px 0 0; font-size:13px;';
            var slots = block.querySelector('.js-roster-slots');
            slots.parentNode.insertBefore(status, slots);
        }
        status.textContent = text;
        status.style.color = color;
    }
}());
