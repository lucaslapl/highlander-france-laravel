/* Éditeur visuel « Overlay Classement » (/admin/overlay/bracket/{token},
   type table) : l'aperçu reprend l'habillage de l'overlay OBS
   (overlay_table.css — médailles or / argent / bronze pour le top 3,
   alternance violette, colonne « Pén » dès qu'une pénalité existe) et
   se met à jour à chaque saisie. Chaque ligne se clique : l'édition se
   fait dans le panneau latéral (équipe, statistiques, points,
   pénalité), les déplacements et la suppression aussi. Comme l'éditeur
   bracket, l'état est sérialisé au POST dans le schéma historique
   (rows[i][…]) : le contrôleur et le format de stockage ne changent
   pas.

   Le remplissage assisté ETF2L (admin_etf2l_teams.js) équipe le bloc
   .js-etf2l-team du panneau : les valeurs du menu remontent dans l'état
   via le listener 'change' ci-dessous, enregistré après celui du script
   partagé. */

(function () {
    'use strict';

    var dataEl = document.getElementById('bkt-data');
    var form = document.getElementById('bracket-editor');
    if (!dataEl || !form) {
        return;
    }

    var state = JSON.parse(dataEl.textContent || '{}');
    state.rows = Array.isArray(state.rows) ? state.rows : [];

    var selected = null; /* index de la ligne sélectionnée */
    var canvas = document.getElementById('bkt-canvas');
    var rowSection = document.getElementById('bkt-row-section');
    var panelHint = document.getElementById('bkt-panel-hint');

    /* Entier positif plafonné, comme côté serveur (cleanInt). */
    function int(value) {
        var parsed = parseInt(value, 10);
        if (isNaN(parsed)) {
            return 0;
        }

        return Math.max(0, Math.min(9999, parsed));
    }

    function newRow() {
        return { name: '', avatar: '', country: '', played: 0, won: 0, lost: 0, score: 0, penalty: 0 };
    }

    /* La colonne « Pén » n'apparaît que si une pénalité existe, comme
       BracketOverlayController::tableData(). */
    function showPenalty() {
        return state.rows.some(function (row) {
            return int(row.penalty) !== 0;
        });
    }

    /* ── Aperçu (même habillage que l'overlay) ─────────────────────────── */

    function render() {
        canvas.innerHTML = '';

        if (state.rows.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'bkv-empty';
            empty.textContent = 'Aucune ligne — ajoutez une équipe ci-dessous.';
            canvas.appendChild(empty);
            return;
        }

        var standings = document.createElement('div');
        standings.className = 'bkt-standings';
        var table = document.createElement('table');

        var headRow = document.createElement('tr');
        ['', 'Équipe', 'MJ', 'G', 'P', 'Pts'].forEach(function (label) {
            var th = document.createElement('th');
            th.textContent = label;
            if (label === 'Équipe') {
                th.className = 'team-col';
            }
            headRow.appendChild(th);
        });
        if (showPenalty()) {
            var penaltyHead = document.createElement('th');
            penaltyHead.textContent = 'Pén';
            headRow.appendChild(penaltyHead);
        }
        table.appendChild(headRow);

        state.rows.forEach(function (row, index) {
            var rank = index + 1;
            var tr = document.createElement('tr');
            tr.className = selected === index ? 'bkt-row--selected' : '';
            tr.title = 'Cliquer pour éditer cette ligne';

            var cells = [
                String(rank),
                row.name || '—',
                String(int(row.played)),
                String(int(row.won)),
                String(int(row.lost)),
                String(int(row.score))
            ];
            cells.forEach(function (value, column) {
                var td = document.createElement('td');
                if (column === 0) {
                    td.className = 'rank';
                } else if (column === 1) {
                    td.className = 'team-col';
                    var cell = document.createElement('span');
                    cell.className = 'team-cell';
                    if (row.avatar) {
                        var avatar = document.createElement('img');
                        avatar.src = row.avatar;
                        avatar.alt = '';
                        cell.appendChild(avatar);
                    }
                    var name = document.createElement('span');
                    name.className = 'team-name';
                    name.textContent = value;
                    cell.appendChild(name);
                    td.appendChild(cell);
                } else if (column === 5) {
                    td.className = 'points';
                    td.textContent = value;
                } else {
                    td.className = 'numeric';
                    td.textContent = value;
                }
                tr.appendChild(td);
            });

            if (showPenalty()) {
                var penaltyCell = document.createElement('td');
                penaltyCell.className = 'penalty';
                penaltyCell.textContent = String(int(row.penalty));
                tr.appendChild(penaltyCell);
            }

            tr.addEventListener('click', function () {
                selectRow(index);
            });
            table.appendChild(tr);
        });

        standings.appendChild(table);
        canvas.appendChild(standings);
    }

    /* ── Sélection et panneau latéral ──────────────────────────────────── */

    function selectRow(index) {
        selected = index;
        syncPanel();
        render();
    }

    function syncPanel() {
        panelHint.hidden = state.rows.length > 0;
        rowSection.hidden = selected === null || state.rows[selected] === undefined;

        if (rowSection.hidden) {
            return;
        }

        var row = state.rows[selected];
        document.getElementById('bkt-row-title').textContent
            = 'Ligne ' + (selected + 1) + ' — ' + (row.name !== '' ? row.name : '(sans nom)');
        document.getElementById('bkt-name').value = row.name || '';
        document.getElementById('bkt-avatar').value = row.avatar || '';
        document.getElementById('bkt-country').value = row.country || '';
        document.getElementById('bkt-played').value = int(row.played);
        document.getElementById('bkt-won').value = int(row.won);
        document.getElementById('bkt-lost').value = int(row.lost);
        document.getElementById('bkt-score').value = int(row.score);
        document.getElementById('bkt-penalty').value = int(row.penalty);
    }

    /* Saisie dans le panneau → état → aperçu (le panneau n'est pas
       re-rendu, le focus reste là où l'on tape). */
    function bindRow(id, apply) {
        var input = document.getElementById(id);
        if (!input) {
            return;
        }
        ['input', 'change'].forEach(function (event) {
            input.addEventListener(event, function () {
                if (selected === null || state.rows[selected] === undefined) {
                    return;
                }
                apply(state.rows[selected], input);
                render();
            });
        });
    }

    bindRow('bkt-name', function (row, input) { row.name = input.value; });
    bindRow('bkt-avatar', function (row, input) { row.avatar = input.value; });
    bindRow('bkt-country', function (row, input) { row.country = input.value; });
    bindRow('bkt-played', function (row, input) { row.played = int(input.value); });
    bindRow('bkt-won', function (row, input) { row.won = int(input.value); });
    bindRow('bkt-lost', function (row, input) { row.lost = int(input.value); });
    bindRow('bkt-score', function (row, input) { row.score = int(input.value); });
    bindRow('bkt-penalty', function (row, input) { row.penalty = int(input.value); });

    /* Remplissage assisté ETF2L : le script partagé remplit nom / avatar /
       pays sans émettre d'événement sur les champs ; son listener 'change'
       étant enregistré avant le nôtre, les valeurs sont à jour quand on les
       relit ici pour les faire remonter dans l'état. */
    document.addEventListener('change', function (event) {
        if (!event.target.closest || !event.target.closest('.js-etf2l-picker')) {
            return;
        }
        if (selected === null || state.rows[selected] === undefined) {
            return;
        }
        state.rows[selected].name = document.getElementById('bkt-name').value;
        state.rows[selected].avatar = document.getElementById('bkt-avatar').value;
        state.rows[selected].country = document.getElementById('bkt-country').value;
        syncPanel();
        render();
    });

    /* ── Lignes : ajout, déplacement, suppression ──────────────────────── */

    document.getElementById('bkt-add-row').addEventListener('click', function () {
        state.rows.push(newRow());
        selectRow(state.rows.length - 1);
    });

    function moveRow(dir) {
        if (selected === null || state.rows[selected] === undefined) {
            return;
        }
        var neighbor = selected + dir;
        if (neighbor < 0 || neighbor >= state.rows.length) {
            return;
        }
        var swap = state.rows[selected];
        state.rows[selected] = state.rows[neighbor];
        state.rows[neighbor] = swap;
        selected = neighbor;
        syncPanel();
        render();
    }

    document.getElementById('bkt-row-up').addEventListener('click', function () { moveRow(-1); });
    document.getElementById('bkt-row-down').addEventListener('click', function () { moveRow(1); });

    document.getElementById('bkt-row-delete').addEventListener('click', function () {
        if (selected === null || state.rows[selected] === undefined) {
            return;
        }
        state.rows.splice(selected, 1);
        selected = null;
        syncPanel();
        render();
    });

    /* ── Sérialisation au POST (schéma historique) ────────────────────── */

    form.addEventListener('submit', function () {
        var host = document.getElementById('bkt-serialized');
        host.innerHTML = '';

        function add(name, value) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            host.appendChild(input);
        }

        state.rows.forEach(function (row, index) {
            var prefix = 'rows[' + index + ']';
            add(prefix + '[name]', row.name || '');
            add(prefix + '[avatar]', row.avatar || '');
            add(prefix + '[country]', row.country || '');
            add(prefix + '[played]', int(row.played));
            add(prefix + '[won]', int(row.won));
            add(prefix + '[lost]', int(row.lost));
            add(prefix + '[score]', int(row.score));
            add(prefix + '[penalty]', int(row.penalty));
        });
    });

    syncPanel();
    render();
}());
