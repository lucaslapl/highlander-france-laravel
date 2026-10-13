/* Éditeur « Overlay Programme » (/admin/overlay/programme/{token}) :
   - bascule du mode de décompte (date/heure cible ou durée) ;
   - remplissage assisté ETF2L : le bouton « Charger le match »
     interroge /admin/overlay/programme/etf2l-match?match_url=… puis
     propose de renseigner les équipes du jour ou d'ajouter une entrée
     au programme de la semaine (compétition en libellé, noms
     complets ETF2L à raccourcir à la main). Match introuvable ou API
     indisponible : message explicite, la saisie manuelle reste
     possible ;
   - entrées du programme de la semaine ajoutées / retirées à la
     volée (le gabarit <template> de la page fournit le rendu).

   Conso modèle admin_series_match.js — tout reste corrigible à la
   main avant l'envoi du formulaire. */

(function () {
    'use strict';

    var editor = document.getElementById('programme-editor');
    if (!editor) {
        return;
    }

    /* ── Mode de décompte ───────────────────────────────────────── */

    var datetimeBox = document.querySelector('.js-programme-datetime');
    var durationBox = document.querySelector('.js-programme-duration');

    function refreshMode() {
        var checked = editor.querySelector('input.js-programme-mode:checked');
        var isDatetime = checked && checked.value === 'datetime';
        if (datetimeBox) {
            datetimeBox.style.display = isDatetime ? 'flex' : 'none';
        }
        if (durationBox) {
            durationBox.style.display = isDatetime ? 'none' : '';
        }
    }

    editor.querySelectorAll('input.js-programme-mode').forEach(function (radio) {
        radio.addEventListener('change', refreshMode);
    });

    /* ── Programme de la semaine : lignes dynamiques ─────────────── */

    var scheduleBox = document.getElementById('programme-schedule');
    var scheduleTemplate = document.getElementById('programme-schedule-template');
    var scheduleAdd = document.getElementById('programme-schedule-add');

    function nextIndex() {
        return scheduleBox ? (parseInt(scheduleBox.dataset.next || '0', 10) || 0) : 0;
    }

    function addScheduleRow(values) {
        if (!scheduleBox || !scheduleTemplate) {
            return;
        }

        var index = nextIndex();
        var html = scheduleTemplate.innerHTML.replace(/__INDEX__/g, String(index));
        var holder = document.createElement('div');
        holder.innerHTML = html;
        var row = holder.firstElementChild;
        if (!row) {
            return;
        }

        if (values) {
            var day = row.querySelector('input[name="schedule[' + index + '][day]"]');
            var time = row.querySelector('input[name="schedule[' + index + '][time]"]');
            var label = row.querySelector('input[name="schedule[' + index + '][label]"]');
            var teamA = row.querySelector('.js-programme-row-team-a');
            var teamB = row.querySelector('.js-programme-row-team-b');
            if (day) { day.value = values.day || ''; }
            if (time) { time.value = values.time || ''; }
            if (label) { label.value = values.label || ''; }
            if (teamA) { teamA.value = values.team_a || ''; }
            if (teamB) { teamB.value = values.team_b || ''; }
        }

        wireRow(row);
        scheduleBox.appendChild(row);
        scheduleBox.dataset.next = String(index + 1);
    }

    function wireRow(row) {
        var remove = row.querySelector('.js-programme-schedule-remove');
        if (remove) {
            remove.addEventListener('click', function () {
                row.remove();
            });
        }
    }

    scheduleBox.querySelectorAll('.js-programme-schedule-row').forEach(wireRow);

    if (scheduleAdd) {
        scheduleAdd.addEventListener('click', function () {
            addScheduleRow(null);
        });
    }

    /* ── Remplissage assisté ETF2L ──────────────────────────────── */

    var matchBox = document.querySelector('.js-programme-match');
    var matchUrlInput = document.getElementById('programme-match-url');
    var loadButton = document.getElementById('programme-load-match');
    var statusLine = document.getElementById('programme-match-status');
    var actionsBox = document.getElementById('programme-match-actions');
    var titleLine = document.getElementById('programme-match-title');

    var loadedMatch = null;

    if (!matchBox || !matchUrlInput || !loadButton) {
        refreshMode();
        return;
    }

    function teamName(index) {
        var team = loadedMatch && loadedMatch.teams && loadedMatch.teams[index];
        return (team && team.name) ? team.name : 'TBD';
    }

    loadButton.addEventListener('click', function () {
        var url = matchUrlInput.value.trim();
        if (url === '') {
            statusLine.textContent = 'Collez d\'abord un lien de match ETF2L (https://etf2l.org/matches/…).';
            return;
        }

        statusLine.textContent = 'Chargement du match…';
        if (actionsBox) {
            actionsBox.style.display = 'none';
        }

        fetch('/admin/overlay/programme/etf2l-match?match_url=' + encodeURIComponent(url), { cache: 'no-store' })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (!data || !data.match) {
                    loadedMatch = null;
                    statusLine.textContent = (data && data.error)
                        ? data.error
                        : 'Match introuvable ou API indisponible, réessayez plus tard.';
                    return;
                }

                loadedMatch = data.match;
                statusLine.textContent = '';
                if (titleLine) {
                    titleLine.textContent = loadedMatch.title || 'Match ETF2L';
                }
                if (actionsBox) {
                    actionsBox.style.display = 'flex';
                }
            })
            .catch(function () {
                loadedMatch = null;
                statusLine.textContent = 'Chargement impossible (réseau), réessayez plus tard.';
            });
    });

    var fillTeams = document.getElementById('programme-match-fill-teams');
    if (fillTeams) {
        fillTeams.addEventListener('click', function () {
            if (!loadedMatch) {
                return;
            }

            var teamA = document.getElementById('editor-team-a');
            var teamB = document.getElementById('editor-team-b');
            if (teamA) { teamA.value = teamName(0); }
            if (teamB) { teamB.value = teamName(1); }
        });
    }

    var addSchedule = document.getElementById('programme-match-add-schedule');
    if (addSchedule) {
        addSchedule.addEventListener('click', function () {
            if (!loadedMatch) {
                return;
            }

            addScheduleRow({
                day: '',
                time: '',
                label: (loadedMatch.competition && loadedMatch.competition.name) || '',
                team_a: teamName(0),
                team_b: teamName(1)
            });
        });
    }

    refreshMode();
})();
