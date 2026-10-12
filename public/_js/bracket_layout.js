/* Moteur de layout de bracket partagé entre l'overlay OBS
   (overlay_bracket.js) et l'éditeur visuel admin (admin_bracket_editor.js).
   Il consomme le modèle de données des deux pages — {columns: [{lane:
   upper|lower|final, matches: […]}]} — et calcule les positions des cases,
   des étiquettes de round et des connecteurs, sans aucune constante liée
   à une forme de bracket donnée :

   - au sein d'une voie, la première colonne sert de base régulière, puis
     chaque case est centrée sur la moyenne des centres de ses
     alimentantes (câblage standard floor(i/2), rattrapé sur la dernière
     case disponible si la colonne suivante en compte moins — pré-remplissage,
     BYE) ;
   - les voies haute et basse sont empilées, la grande finale se place à
     droite des voies, centrée sur l'ensemble, avec ses cases compactes
     additionnelles empilées dessous (ex : match de 3è place) ;
   - tout est normalisé en (0,0) puis mis à l'échelle par HlfrBracket.fit()
   (translate + scale du conteneur) : le même moteur rend n'importe quelle
   forme de bracket sans retouche. */

(function () {
    'use strict';

    var DEFAULTS = {
        cardW: 380,
        cardH: 112,
        gfW: 400,
        gfH: 292,
        gfSmallH: 160,
        gapX: 130,
        gapY: 40,
        gfSmallGap: 110,
        laneGap: 90,
        labelH: 38
    };

    function options(overrides) {
        var merged = {};
        Object.keys(DEFAULTS).forEach(function (key) {
            merged[key] = DEFAULTS[key];
        });
        Object.keys(overrides || {}).forEach(function (key) {
            merged[key] = overrides[key];
        });

        return merged;
    }

    /* Centres verticaux des cases d'une voie, colonne par colonne :
       moyenne des alimentantes pour chaque case, repli sur l'espacement
       régulier si elle n'en a pas, puis anti-chevauchement (formes
       manuelles où les compteurs ne suivent pas les puissances de deux). */
    function laneCenters(counts, strideY) {
        var centers = [];
        counts.forEach(function (n, depth) {
            centers.push([]);
            for (var j = 0; j < n; j++) {
                centers[depth].push(depth === 0 ? (j + 0.5) * strideY : 0);
            }
        });

        for (var depth = 1; depth < counts.length; depth++) {
            var previous = centers[depth - 1];
            var current = centers[depth];

            for (var j = 0; j < current.length; j++) {
                var sum = 0;
                var feeders = 0;
                for (var i = 0; i < previous.length; i++) {
                    if (Math.min(Math.floor(i / 2), current.length - 1) === j) {
                        sum += previous[i];
                        feeders++;
                    }
                }
                current[j] = feeders > 0
                    ? sum / feeders
                    : ((j === 0
                        ? (previous.length > 0 ? previous[previous.length - 1] : (j + 0.5) * strideY)
                        : current[j - 1]) + strideY);
            }

            for (var k = 1; k < current.length; k++) {
                current[k] = Math.max(current[k], current[k - 1] + strideY);
            }
        }

        return centers;
    }

    /**
     * Calcule le layout complet d'un bracket.
     *
     * @param {Object} data      Colonnes au format {columns: [...]}.
     * @param {Object} overrides Dimensions des cartes / espacements.
     * @return {Object} {cards, labels, links, bounds} — positions absolues
     *                  normalisées en (0,0), bounds = {w, h}.
     */
    function layout(data, overrides) {
        var opts = options(overrides);
        var strideX = opts.cardW + opts.gapX;
        var strideY = opts.cardH + opts.gapY;

        var lanes = { upper: [], lower: [], final: [] };
        (((data && data.columns) || [])).forEach(function (column) {
            var lane = lanes[column.lane] ? column.lane : 'upper';
            lanes[lane].push(column);
        });

        var centers = {
            upper: laneCenters(lanes.upper.map(function (c) { return (c.matches || []).length; }), strideY),
            lower: laneCenters(lanes.lower.map(function (c) { return (c.matches || []).length; }), strideY)
        };

        /* Hauteur de chaque voie (étendue réelle des cases posées). */
        function laneHeight(lane) {
            var max = 0;
            centers[lane].forEach(function (row) {
                row.forEach(function (center) {
                    max = Math.max(max, center + opts.cardH / 2);
                });
            });

            return max;
        }

        var upperH = laneHeight('upper');
        var lowerH = laneHeight('lower');
        var lowerTop = upperH > 0 ? upperH + opts.laneGap : 0;
        var stackH = upperH > 0 && lowerH > 0 ? lowerTop + lowerH : upperH + lowerH;

        /* La voie « final » : la première case est la grande finale, les
           suivantes (ex : match de 3è place) sont des cartes compactes
           empilées dessous, même largeur, nettement détachées de la
           grande finale (gfSmallGap). L'étiquette est rendue dans la
           carte (gf-head côté overlay) ; le bloc entier (grande finale +
           cartes compactes) est centré sur l'empilement des voies. */
        var finalOffset = Math.max(lanes.upper.length, lanes.lower.length, 0) * strideX;
        var finalCount = lanes.final.length > 0 ? (lanes.final[0].matches || []).length : 0;
        var finalBlockH = opts.gfH + Math.max(0, finalCount - 1) * (opts.gfSmallH + opts.gfSmallGap);
        var gfTop = (stackH - finalBlockH) / 2;

        var cards = [];
        var labels = [];
        var placed = { upper: [], lower: [], final: [] };

        function placeLane(lane, top) {
            lanes[lane].forEach(function (column, depth) {
                var n = (column.matches || []).length;

                /* Colonne vide : aucune case, mais l'étiquette reste posée
                   en tête de voie — l'éditeur admin peut ainsi la
                   sélectionner et y recréer des cases ; l'overlay
                   l'ignore (label.empty). */
                if (n === 0) {
                    labels.push({
                        column: column,
                        columnId: String(column.id || ''),
                        lane: lane,
                        depth: depth,
                        text: String(column.label || ''),
                        empty: true,
                        x: depth * strideX,
                        y: top - opts.labelH
                    });
                    return;
                }

                var columnTop = Infinity;
                (column.matches || []).forEach(function (match, index) {
                    var y = top + centers[lane][depth][index] - opts.cardH / 2;
                    columnTop = Math.min(columnTop, y);

                    var card = {
                        column: column,
                        columnId: String(column.id || ''),
                        match: match,
                        matchId: String(match.id || ''),
                        lane: lane,
                        depth: depth,
                        index: index,
                        gf: false,
                        x: depth * strideX,
                        y: y,
                        w: opts.cardW,
                        h: opts.cardH
                    };
                    cards.push(card);
                    placed[lane].push(card);
                });

                labels.push({
                    column: column,
                    columnId: String(column.id || ''),
                    lane: lane,
                    depth: depth,
                    text: String(column.label || ''),
                    empty: false,
                    x: depth * strideX,
                    y: columnTop - opts.labelH
                });
            });
        }

        placeLane('upper', 0);
        placeLane('lower', lowerTop);

        /* Voie « final » : grandes cartes, étiquette rendue dans la carte
           (gf-head côté overlay), pas d'étiquette posée au-dessus. */
        lanes.final.forEach(function (column, depth) {
            var x = finalOffset + depth * (opts.gfW + opts.gapX);

            /* Colonne finale vide : aucune case, mais l'étiquette reste
               posée au-dessus de son emplacement — l'éditeur admin en a
               besoin pour sélectionner la colonne et y recréer la grande
               finale ; l'overlay l'ignore (label.empty). */
            if ((column.matches || []).length === 0) {
                labels.push({
                    column: column,
                    columnId: String(column.id || ''),
                    lane: 'final',
                    depth: depth,
                    text: String(column.label || ''),
                    empty: true,
                    x: x,
                    y: gfTop - opts.labelH
                });
                return;
            }

            (column.matches || []).forEach(function (match, index) {
                var big = index === 0;
                var card = {
                    column: column,
                    columnId: String(column.id || ''),
                    match: match,
                    matchId: String(match.id || ''),
                    lane: 'final',
                    depth: depth,
                    index: index,
                    gf: big,
                    small: !big,
                    x: x,
                    y: big
                        ? gfTop
                        : gfTop + opts.gfH + opts.gfSmallGap + (index - 1) * (opts.gfSmallH + opts.gfSmallGap),
                    w: opts.gfW,
                    h: big ? opts.gfH : opts.gfSmallH
                };
                cards.push(card);
                placed.final.push(card);
            });
        });

        /* Connecteurs : au sein d'une voie, chaque case alimente la case
           floor(i/2) de la colonne suivante (rattrapée sur la dernière
           disponible) ; la grande finale reçoit la dernière case de
           chaque voie peuplée en double élimination, ou toutes les cases
           de la dernière colonne de la voie unique en simple
           élimination. */
        var links = [];

        function connect(source, target) {
            if (!source || !target) {
                return;
            }
            links.push({ source: source, target: target });
        }

        ['upper', 'lower'].forEach(function (lane) {
            for (var depth = 1; depth < lanes[lane].length; depth++) {
                var sources = placed[lane].filter(function (card) { return card.depth === depth - 1; });
                var targets = placed[lane].filter(function (card) { return card.depth === depth; });
                sources.forEach(function (source) {
                    connect(source, targets[Math.min(Math.floor(source.index / 2), targets.length - 1)]);
                });
            }
        });

        /* Alimentation de la grande finale (première case de la voie
           « final ») :
           - double élimination (voies haute et basse peuplées) : la
             dernière case de la colonne la plus profonde de chaque voie
             (WB Final et LB Final) ;
           - simple élimination (une seule voie peuplée) : toutes les
             cases de la dernière colonne de cette voie — les deux
             demi-finales alimentent la finale, pas seulement la
             dernière. La profondeur réelle des cases compte : une
             colonne vide en bout de voie ne doit pas couper le lien. */
        if (placed.final.length > 0) {
            var singleLane = placed.upper.length > 0
                ? placed.lower.length === 0
                : placed.lower.length > 0;

            ['upper', 'lower'].forEach(function (lane) {
                if (placed[lane].length === 0) {
                    return;
                }

                var maxDepth = -1;
                placed[lane].forEach(function (card) {
                    maxDepth = Math.max(maxDepth, card.depth);
                });
                var deepest = placed[lane].filter(function (card) {
                    return card.depth === maxDepth;
                });

                if (singleLane) {
                    deepest.forEach(function (card) {
                        connect(card, placed.final[0]);
                    });
                } else {
                    connect(deepest[deepest.length - 1], placed.final[0]);
                }
            });
        }

        /* Normalisation en (0,0) : les étiquettes dépassent au-dessus des
           cases, la grande finale peut déborder de l'empilement des voies. */
        var minX = Infinity;
        var minY = Infinity;
        var maxX = -Infinity;
        var maxY = -Infinity;

        cards.forEach(function (card) {
            minX = Math.min(minX, card.x);
            minY = Math.min(minY, card.y);
            maxX = Math.max(maxX, card.x + card.w);
            maxY = Math.max(maxY, card.y + card.h);
        });

        /* Les étiquettes comptent dans les bornes : celles des colonnes
           vides (sans carte) réserveraient sinon l'emplacement de leur
           colonne et déborderaient du conteneur mis à l'échelle. Largeur
           nominale = la carte qui s'y posera. */
        labels.forEach(function (label) {
            minX = Math.min(minX, label.x);
            minY = Math.min(minY, label.y);
            maxX = Math.max(maxX, label.x + (label.lane === 'final' ? opts.gfW : opts.cardW));
        });

        if (cards.length > 0) {
            cards.forEach(function (card) {
                card.x -= minX;
                card.y -= minY;
            });
            labels.forEach(function (label) {
                label.x -= minX;
                label.y -= minY;
            });

            /* Chemins recalculés depuis les positions normalisées. */
            links = links.map(function (link) {
                var source = link.source;
                var target = link.target;
                var x1 = source.x + source.w;
                var y1 = source.y + source.h / 2;
                var x2 = target.x;
                var y2 = target.y + target.h / 2;
                var midX = x1 + (x2 - x1) / 2;
                return {
                    source: source,
                    target: target,
                    d: 'M' + x1 + ' ' + y1 + 'H' + midX + (Math.abs(y2 - y1) > 2 ? 'V' + y2 : '') + 'H' + x2
                };
            });
        }

        return {
            cards: cards,
            labels: labels,
            links: links,
            bounds: cards.length > 0 ? { w: maxX - minX, h: maxY - minY } : { w: 0, h: 0 }
        };
    }

    /* Ajuste un layout à une zone d'affichage : échelle (plafonnée par
       maxScale) et décalages pour centrer, à appliquer en transform
       « translate(x, y) scale(s) » sur un conteneur à transform-origin
       0 0. */
    function fit(bounds, viewport, overrides) {
        var maxScale = (overrides && overrides.maxScale) || 1;
        var w = Math.max(bounds.w, 1);
        var h = Math.max(bounds.h, 1);
        var scale = Math.min(viewport.w / w, viewport.h / h, maxScale);

        return {
            scale: scale,
            x: viewport.x + (viewport.w - w * scale) / 2,
            y: viewport.y + (viewport.h - h * scale) / 2
        };
    }

    window.HlfrBracket = {
        layout: layout,
        fit: fit,
        DEFAULTS: DEFAULTS
    };
}());
