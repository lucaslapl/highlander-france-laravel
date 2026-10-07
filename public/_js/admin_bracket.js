/* Outil admin « Overlay Bracket » : manipulation du formulaire d'édition
   sans rechargement — ajout / suppression / déplacement des colonnes du
   bracket, des cases de match et des lignes de classement. Les indices des
   noms de champs (columns[i][matches[j]…], rows[k]…) sont réécrits à la
   volée : l'ordre d'affichage suit l'ordre du formulaire, le serveur trie
   par index numérique (ksort) à l'enregistrement, les trous d'indexation
   ne posent donc aucun problème. */

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
        });
    }

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
