/* Préremplissage « lien de match ETF2L » de l'outil Overlay Scores
   (formulaire de création /admin/series) : le bouton « Charger le
   match » interroge /admin/series/etf2l-match?match_url=… puis
   remplit les blocs équipe (nom complet ETF2L, à raccourcir à la
   main — l'API ne remonte pas d'acronyme ; avatar) et la liste des
   maps connues d'ETF2L. Le format de la série est détecté du nombre
   de maps (2 → classique, 3 → BO3, 5 → BO5). Le pool de maps
   (9v9 / 6v6) suit le format du match ; la liste .js-series-map-list
   garde une rangée par map (champ maps[] + bouton de retrait),
   alimentée aussi par le menu .js-series-map-select. Tout reste
   corrigible à la main avant l'envoi : équipes TBD, mercs, API
   indisponible. */

(function () {
    'use strict';

    var box = document.querySelector('.js-series-match');
    if (!box) {
        return;
    }

    var matchUrlInput = document.getElementById('series-match-url');
    var loadButton = document.getElementById('series-load-match');
    var statusLine = document.getElementById('series-match-status');
    var titleLine = document.getElementById('series-match-title');
    var mapSelect = document.querySelector('.js-series-map-select');
    var mapAddButton = document.querySelector('.js-series-map-add');
    var mapList = document.querySelector('.js-series-map-list');
    var mapEmptyLine = document.querySelector('.js-series-map-empty');
    var poolSelect = document.querySelector('.js-series-map-pool');

    /* Noms de maps ETF2L : caractères sûrs pour un sélecteur
       d'attribut (la validation serveur impose le même motif). */
    function safeMapName(name) {
        return /^[a-z0-9_]+$/.test(name);
    }

    /* ── Liste des maps ─────────────────────────────────────────────── */

    function mapRowExists(name) {
        return mapList.querySelectorAll('input[value="' + name + '"]').length > 0;
    }

    function refreshMapEmptyLine() {
        if (mapEmptyLine) {
            mapEmptyLine.style.display = mapList.children.length === 0 ? '' : 'none';
        }
    }

    function addMapRow(name, label) {
        name = String(name || '').trim().toLowerCase();
        if (!safeMapName(name) || mapRowExists(name)) {
            return;
        }

        var row = document.createElement('li');
        row.style.cssText = 'display:flex; gap:10px; align-items:center; border:1px solid #333; border-radius:6px; padding:8px 10px;';

        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'maps[]';
        input.value = name;
        row.appendChild(input);

        var nameSpan = document.createElement('span');
        nameSpan.textContent = label || name;
        nameSpan.style.flex = '1';
        row.appendChild(nameSpan);

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'admin-btn';
        remove.innerHTML = '<i class="fa-solid fa-trash"></i> Retirer';
        remove.addEventListener('click', function () {
            row.remove();
            refreshMapEmptyLine();
        });
        row.appendChild(remove);

        mapList.appendChild(row);
        refreshMapEmptyLine();
    }

    /* Étiquette lisible d'une map du pool (« koth_product_final » →
       « Product »), telle qu'administrée dans la table des maps ;
       repli sur le nom brut si la map n'est pas dans le pool. */
    function poolLabel(name) {
        if (!mapSelect || !safeMapName(name)) {
            return name;
        }
        var option = mapSelect.querySelector('option[value="' + name + '"]');

        return option ? option.textContent : name;
    }

    if (mapAddButton && mapSelect) {
        mapAddButton.addEventListener('click', function () {
            if (!mapSelect.value) {
                return;
            }
            addMapRow(mapSelect.value, poolLabel(mapSelect.value));
        });
    }

    /* ── Pool de maps (Highlander 9v9 / 6v6) ────────────────────────── */

    function applyPool(category) {
        if (!poolSelect || !mapSelect) {
            return;
        }
        if (category) {
            poolSelect.value = category;
        }
        var current = poolSelect.value;
        mapSelect.querySelectorAll('optgroup[data-category]').forEach(function (group) {
            group.style.display = group.dataset.category === current ? '' : 'none';
        });
        var first = mapSelect.querySelector('optgroup[data-category="' + current + '"] option');
        if (first) {
            mapSelect.value = first.value;
        }
    }

    if (poolSelect) {
        poolSelect.addEventListener('change', function () {
            applyPool();
        });
        applyPool();
    }

    /* Format de série détecté du nombre de maps du match (page de
       match ETF2L) : deux → classique, trois → BO3, cinq → BO5.
       Autre compte (maps non encore révélées par le pick / ban) :
       le choix manuel actuel reste en place. */
    function detectFormat(mapCount) {
        if (formatSelect && mapCount === 2) {
            formatSelect.value = 'fixed';
        } else if (formatSelect && mapCount === 3) {
            formatSelect.value = 'bo3';
        } else if (formatSelect && mapCount === 5) {
            formatSelect.value = 'bo5';
        }
    }

    /* ── Chargement du match ETF2L ──────────────────────────────────── */

    function fillTeamSide(side, team) {
        var block = document.querySelector('.js-etf2l-team--' + side);
        if (!block) {
            return;
        }

        var name = block.querySelector('.js-team-field-name');
        var avatar = block.querySelector('.js-team-field-avatar');
        var etf2lId = block.querySelector('.js-team-field-etf2l-id');

        if (team !== null) {
            if (name) {
                name.value = team.name;
            }
            if (avatar && team.avatar) {
                avatar.value = team.avatar;
            }
            if (etf2lId) {
                etf2lId.value = team.id || '';
            }
        }
    }

    function clearMaps() {
        while (mapList && mapList.firstChild) {
            mapList.removeChild(mapList.firstChild);
        }
        refreshMapEmptyLine();
    }

    if (loadButton && matchUrlInput) {
        loadButton.addEventListener('click', function () {
            var url = matchUrlInput.value.trim();
            if (!url) {
                if (statusLine) {
                    statusLine.style.color = '#ff8080';
                    statusLine.textContent = 'Collez d\'abord un lien de match ETF2L.';
                }

                return;
            }

            loadButton.disabled = true;
            if (statusLine) {
                statusLine.style.color = '#888';
                statusLine.textContent = 'Chargement du match ETF2L…';
            }
            if (titleLine) {
                titleLine.textContent = '';
            }

            fetch('/admin/series/etf2l-match?match_url=' + encodeURIComponent(url), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    var match = data && data.match;
                    if (!match) {
                        if (statusLine) {
                            statusLine.style.color = '#ff8080';
                            statusLine.textContent = (data && data.error) || 'Match introuvable, réessayez plus tard.';
                        }

                        return;
                    }

                    if (statusLine) {
                        statusLine.style.color = '#8c8';
                        statusLine.textContent = 'Match ETF2L #' + match.id + ' chargé'
                            + (match.competition && match.competition.name ? ' — ' + match.competition.name : '')
                            + '.';
                    }
                    if (titleLine) {
                        titleLine.textContent = 'Titre du suivi : ' + match.title;
                    }

                    fillTeamSide('red', match.teams && match.teams[0] ? match.teams[0] : null);
                    fillTeamSide('blue', match.teams && match.teams[1] ? match.teams[1] : null);

                    clearMaps();
                    (match.maps || []).forEach(function (mapName) {
                        addMapRow(mapName, poolLabel(mapName));
                    });
                    detectFormat((match.maps || []).length);
                    if (match.category) {
                        applyPool(match.category);
                    }
                })
                .catch(function () {
                    if (statusLine) {
                        statusLine.style.color = '#ff8080';
                        statusLine.textContent = 'Chargement impossible, réessayez plus tard.';
                    }
                })
                .finally(function () {
                    loadButton.disabled = false;
                });
        });
    }
}());
