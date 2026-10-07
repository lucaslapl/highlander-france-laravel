/* Outil admin « Overlay Bracket » : manipulation du formulaire d'édition
   sans rechargement — ajout / suppression / déplacement des colonnes du
   bracket, des cases de match et des lignes de classement. Les indices des
   noms de champs (columns[i][matches[j]…], rows[k]…) sont réécrits à la
   volée : l'ordre d'affichage suit l'ordre du formulaire, le serveur trie
   par index numérique (ksort) à l'enregistrement, les trous d'indexation
   ne posent donc aucun problème.

   Remplissage assisté : le bouton « Charger les équipes » interroge
   /admin/overlay/bracket/teams et insère un menu de sélection dans chaque
   bloc équipe (case de match ou ligne de classement) qui remplit nom,
   avatar et pays d'un clic ; les blocs ajoutés ensuite reçoivent aussi le
   menu. */

(function () {
    'use strict';

    var seq = 0;

    /* Index unique, assez grand pour ne jamais entrer en collision avec
       les indices initiaux (position dans le formulaire). */
    function nextIndex() {
        seq += 1;
        return 100000 + (Date.now() % 100000) * 10 + seq;
    }

    /* Réécrit les jetons __CIDX__ / __MIDX__ / __RIDX__ des attributs name
       d'un fragment cloné (et de ses <template> internes pour __CIDX__). */
    function renameTokens(root, cIdx, mIdx) {
        root.querySelectorAll('[name]').forEach(function (el) {
            var name = el.getAttribute('name') || '';
            if (cIdx !== null) {
                name = name.replace(/columns\[__CIDX__\]/g, 'columns[' + cIdx + ']');
                name = name.replace(/rows\[__RIDX__\]/g, 'rows[' + cIdx + ']');
            }
            if (mIdx !== null) {
                name = name.replace(/matches\[__MIDX__\]/g, 'matches[' + mIdx + ']');
            }
            el.setAttribute('name', name);
        });

        /* Les <template> de cases embarqués dans une colonne clonée gardent
           leur jeton d'index de case : seule la colonne est re-numérotée. */
        if (cIdx !== null) {
            root.querySelectorAll('template').forEach(function (tpl) {
                (tpl.content || tpl).querySelectorAll('[name]').forEach(function (el) {
                    el.setAttribute('name', (el.getAttribute('name') || '')
                        .replace(/columns\[__CIDX__\]/g, 'columns[' + cIdx + ']'));
                });
            });
        }
    }

    /* ── Colonnes du bracket ───────────────────────────────────────────── */

    var addColumn = document.getElementById('bracket-add-column');
    if (addColumn) {
        addColumn.addEventListener('click', function () {
            var tpl = document.getElementById('tpl-bracket-column');
            var host = document.getElementById('bracket-columns');
            if (!tpl || !host) {
                return;
            }
            var clone = tpl.content.firstElementChild.cloneNode(true);
            var cIdx = String(nextIndex());
            renameTokens(clone, cIdx, String(nextIndex()));
            host.appendChild(clone);
            fillTeamPickers();
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.js-add-match');
        if (!button) {
            return;
        }
        var column = button.closest('.bracket-column');
        var tpl = column ? column.querySelector(':scope > template.tpl-bracket-match') : null;
        var host = column ? column.querySelector('.bracket-matches') : null;
        if (!tpl || !host) {
            return;
        }
        var clone = tpl.content.firstElementChild.cloneNode(true);
        renameTokens(clone, null, String(nextIndex()));
        host.appendChild(clone);
        fillTeamPickers();
    });

    /* ── Lignes du classement ──────────────────────────────────────────── */

    var addRow = document.getElementById('table-add-row');
    if (addRow) {
        addRow.addEventListener('click', function () {
            var tpl = document.getElementById('tpl-table-row');
            var host = document.getElementById('table-rows');
            if (!tpl || !host) {
                return;
            }
            var clone = tpl.content.firstElementChild.cloneNode(true);
            renameTokens(clone, String(nextIndex()), null);
            host.appendChild(clone);
            fillTeamPickers();
        });
    }

    /* ── Remplissage assisté : équipes ETF2L ────────────────────────────── */

    /* Équipes chargées [{name, avatar, country}] ; le menu de sélection
       inséré dans chaque bloc équipe (case de match ou ligne de
       classement) remplit nom, avatar et pays d'un clic. Les blocs
       clonés reçoivent aussi le menu via fillTeamPickers(). */
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

    var loadTeams = document.getElementById('bracket-load-teams');
    if (loadTeams) {
        loadTeams.addEventListener('click', function () {
            var competition = document.getElementById('bracket-teams-competition');
            var status = document.getElementById('bracket-teams-status');
            if (!competition || !competition.value) {
                return;
            }
            loadTeams.disabled = true;
            if (status) {
                status.style.color = '#888';
                status.textContent = 'Chargement des équipes…';
            }
            fetch('/admin/overlay/bracket/teams?competition_id=' + encodeURIComponent(competition.value), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    etf2lTeams = data.teams || [];
                    if (etf2lTeams.length === 0) {
                        if (status) {
                            status.style.color = '#ff8080';
                            status.textContent = 'Aucune équipe trouvée (API ETF2L indisponible ou compétition vide ?).';
                        }
                        return;
                    }
                    if (status) {
                        status.style.color = '#8c8';
                        status.textContent = etf2lTeams.length + ' équipe(s) chargée(s) — utilisez le menu de chaque case / ligne.';
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
    }

    document.addEventListener('change', function (event) {
        var select = event.target.closest('.js-etf2l-picker');
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
        var name = block ? block.querySelector('.js-team-field-name') : null;
        var avatar = block ? block.querySelector('.js-team-field-avatar') : null;
        var country = block ? block.querySelector('.js-team-field-country') : null;
        if (name) {
            name.value = team ? team.name : '';
        }
        if (avatar) {
            avatar.value = team ? team.avatar : '';
        }
        if (country) {
            country.value = team ? team.country : '';
        }
    });

    /* ── Déplacement / suppression génériques ──────────────────────────── */

    var selectors = {
        column: '.bracket-column',
        match: '.bracket-match',
        row: '.table-row'
    };

    document.addEventListener('click', function (event) {
        var move = event.target.closest('.js-move');
        if (move) {
            var selector = selectors[move.dataset.target];
            var item = move.closest(selector);
            var sibling = move.dataset.dir === '-1' ? item.previousElementSibling : item.nextElementSibling;
            if (item && sibling) {
                if (move.dataset.dir === '-1') {
                    item.parentNode.insertBefore(item, sibling);
                } else {
                    item.parentNode.insertBefore(sibling, item);
                }
            }
            return;
        }

        var remove = event.target.closest('.js-remove');
        if (remove) {
            var sel = selectors[remove.dataset.target];
            var target = remove.closest(sel);
            if (target && target.parentNode.children.length > 0) {
                target.remove();
            }
        }
    });
}());
