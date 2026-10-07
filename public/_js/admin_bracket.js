/* Outil admin « Overlay Bracket » : manipulation du formulaire d'édition
   sans rechargement — ajout / suppression / déplacement des colonnes du
   bracket, des cases de match et des lignes de classement. Les indices des
   noms de champs (columns[i][matches[j]…], rows[k]…) sont réécrits à la
   volée après chaque ajout / suppression / déplacement : l'ordre visuel
   du formulaire fait l'ordre enregistré, le serveur trie par index
   numérique (ksort), les trous d'indexation ne posent donc aucun
   problème.

   Remplissage assisté : partagé avec les autres outils overlay
   (admin_etf2l_teams.js) — les blocs équipe ajoutés ensuite reçoivent
   aussi le menu de sélection via window.hlfrEtf2lFillTeamPickers(). */

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
                /* Le jeton apparaît comme segment d'index de case
                   ([matches][__MIDX__]) : on cible [__MIDX__] seul, le
                   préfixe « matches » est suivi d'un crochet fermant dans
                   les noms réels. */
                name = name.replace(/\[__MIDX__\]/g, '[' + mIdx + ']');
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

    /* ── Remplissage assisté : équipes ETF2L (admin_etf2l_teams.js) ────── */

    /* Les blocs équipe ajoutés après le chargement des équipes reçoivent
       aussi le menu de sélection. */
    function fillTeamPickers() {
        if (window.hlfrEtf2lFillTeamPickers) {
            window.hlfrEtf2lFillTeamPickers();
        }
    }

    /* ── Déplacement / suppression génériques ──────────────────────────── */

    var selectors = {
        column: '.bracket-column',
        match: '.bracket-match',
        row: '.table-row'
    };

    /* Remplace l'index numérique d'un segment de nom de champ (columns[i] /
       matches][j] / rows[k]) dans les attributs name d'un fragment du
       formulaire. */
    function setNameIndex(root, pattern, replacement) {
        root.querySelectorAll('[name]').forEach(function (el) {
            el.setAttribute('name', (el.getAttribute('name') || '').replace(pattern, replacement));
        });
    }

    /* Réécrit tous les indices du formulaire pour qu'ils suivent l'ordre
       courant du DOM : le serveur trie colonnes, cases et lignes par index
       numérique (ksort), l'ordre enregistré après un déplacement est donc
       bien l'ordre visuel de l'éditeur. Les <template> ne sont pas
       traversés par querySelectorAll : leurs jetons restent intacts. */
    function renumberForm() {
        var columnsHost = document.getElementById('bracket-columns');
        var rowsHost = document.getElementById('table-rows');

        if (columnsHost) {
            Array.prototype.forEach.call(columnsHost.querySelectorAll(':scope > .bracket-column'), function (column, c) {
                setNameIndex(column, /columns\[\d+\]/g, 'columns[' + c + ']');
                column.setAttribute('data-index', String(c));
                Array.prototype.forEach.call(column.querySelectorAll(':scope > .bracket-matches > .bracket-match'), function (match, m) {
                    setNameIndex(match, /\[matches\]\[\d+\]/g, '[matches][' + m + ']');
                });
            });
        }

        if (rowsHost) {
            Array.prototype.forEach.call(rowsHost.querySelectorAll(':scope > .table-row'), function (row, k) {
                setNameIndex(row, /rows\[\d+\]/g, 'rows[' + k + ']');
            });
        }
    }

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
                renumberForm();
            }
            return;
        }

        var remove = event.target.closest('.js-remove');
        if (remove) {
            var sel = selectors[remove.dataset.target];
            var target = remove.closest(sel);
            if (target && target.parentNode.children.length > 0) {
                target.remove();
                renumberForm();
            }
        }
    });
}());
