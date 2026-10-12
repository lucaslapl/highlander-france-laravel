/* Éditeur visuel « Overlay Bracket » (/admin/overlay/bracket/{token}) :
   le bracket se construit sur son rendu, avec le même moteur que l'overlay
   OBS (bracket_layout.js). Trois briques :

   - générateur de squelette : le nombre d'équipes et le format (simple /
     double élimination) posent d'un coup toutes les colonnes des voies
     haute / basse et la grande finale, labels inclus — plus de cases à
     créer une par une ;
   - canvas interactif : chaque case et chaque étiquette de round se
     cliquent ; l'édition se fait dans le panneau latéral (équipes, scores,
     série suivie, match « EN DIRECT », déplacements) et le rendu suit en
     direct, connecteurs compris ;
   - sérialisation : à l'enregistrement, l'état est converti dans le
     schéma POST historique (columns[i][matches][j][team1_…] + live_match),
     le contrôleur et le format de stockage ne changent pas.

   Le remplissage assisté ETF2L (admin_etf2l_teams.js) équipe les blocs
   .js-etf2l-team du panneau : le menu y remplit nom / avatar / pays d'un
   clic, les valeurs remontent dans l'état via le listener 'change' ci-
   dessous (enregistré après celui du script partagé). */

(function () {
    'use strict';

    var dataEl = document.getElementById('bkv-data');
    var form = document.getElementById('bracket-editor');
    if (!dataEl || !form) {
        return;
    }

    var SVG_NS = 'http://www.w3.org/2000/svg';

    /* Cartes plus compactes que l'overlay : le canvas est une carte
       d'ensemble, le détail vit dans le panneau latéral. */
    var EDITOR_OPTS = {
        cardW: 240,
        cardH: 78,
        gfW: 260,
        gfH: 200,
        gfSmallH: 100,
        gapX: 70,
        gapY: 26,
        laneGap: 60,
        labelH: 26
    };

    /* Formes de squelettes : compteurs de cases par colonne. */
    var SHAPES = {
        single: { 4: [2, 1], 8: [4, 2, 1], 16: [8, 4, 2, 1] },
        double: {
            4: { upper: [2, 1], lower: [1, 1] },
            8: { upper: [4, 2, 1], lower: [2, 2, 1, 1] },
            16: { upper: [8, 4, 2, 1], lower: [4, 4, 2, 2, 1, 1] }
        }
    };

    /* ── État ───────────────────────────────────────────────────────────── */

    var state = JSON.parse(dataEl.textContent || '{}');
    state.columns = Array.isArray(state.columns) ? state.columns : [];
    state.live = state.live || null;

    /* Identifiants manquants (payloads très anciens) : générés à la volée,
       le serveur en régénère de toute façon s'ils manquent au POST. */
    state.columns.forEach(function (column) {
        if (!column.id) {
            column.id = newId('c');
        }
        (column.matches || []).forEach(function (match) {
            if (!match.id) {
                match.id = newId('m');
            }
        });
    });

    var selected = null; /* {type: 'column'|'match', columnId, matchId} */

    var canvas = document.getElementById('bkv-canvas');
    var addMatchBtn = document.getElementById('bkv-add-match');
    var columnSection = document.getElementById('bkv-column-section');
    var matchSection = document.getElementById('bkv-match-section');
    var panelHint = document.getElementById('bkv-panel-hint');

    function newId(prefix) {
        return prefix + Math.random().toString(16).slice(2, 10);
    }

    function newTeam() {
        return { name: '', avatar: '', country: '', score: '' };
    }

    function newMatch() {
        return { id: newId('m'), label: '', teams: [newTeam(), newTeam()], series_token: null, manual_scores: false };
    }

    function newColumn(lane) {
        return { id: newId('c'), label: '', lane: lane || 'upper', matches: [newMatch()] };
    }

    function findColumn(columnId) {
        for (var i = 0; i < state.columns.length; i++) {
            if (String(state.columns[i].id) === String(columnId)) {
                return state.columns[i];
            }
        }

        return null;
    }

    function findMatch(columnId, matchId) {
        var column = findColumn(columnId);
        if (!column) {
            return null;
        }
        var matches = column.matches || [];
        for (var i = 0; i < matches.length; i++) {
            if (String(matches[i].id) === String(matchId)) {
                return matches[i];
            }
        }

        return null;
    }

    function isLive(columnId, matchId) {
        return state.live !== null
            && String(state.live.column) === String(columnId)
            && String(state.live.match) === String(matchId);
    }

    /* Statut d'une équipe déduit des scores, comme le fait le serveur
       (BracketOverlayController::teamView) pour l'overlay. */
    function teamStatus(team, other) {
        var own = team && team.score !== '' && team.score !== undefined && team.score !== null
            ? String(team.score) : '';
        var theirs = other && other.score !== '' && other.score !== undefined && other.score !== null
            ? String(other.score) : '';
        if (own === '' || theirs === '') {
            return 'pending';
        }

        return parseInt(own, 10) > parseInt(theirs, 10) ? 'win' : (parseInt(own, 10) < parseInt(theirs, 10) ? 'lose' : 'even');
    }

    /* ── Canvas interactif ──────────────────────────────────────────────── */

    function render() {
        canvas.innerHTML = '';

        var result = HlfrBracket.layout(state, EDITOR_OPTS);
        if (result.cards.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'bkv-empty';
            empty.textContent = 'Aucune case — générez un squelette ci-dessus ou ajoutez une colonne.';
            canvas.appendChild(empty);
            return;
        }

        var f = HlfrBracket.fit(result.bounds, {
            x: 20,
            y: 20,
            w: Math.max(canvas.clientWidth - 40, 100),
            h: Math.max(canvas.clientHeight - 40, 100)
        }, { maxScale: 1.2 });

        var wrap = document.createElement('div');
        wrap.className = 'bkv-wrap';
        wrap.style.width = result.bounds.w + 'px';
        wrap.style.height = result.bounds.h + 'px';
        wrap.style.transform = 'translate(' + f.x + 'px, ' + f.y + 'px) scale(' + f.scale + ')';

        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('width', String(result.bounds.w));
        svg.setAttribute('height', String(result.bounds.h));
        svg.setAttribute('viewBox', '0 0 ' + result.bounds.w + ' ' + result.bounds.h);
        wrap.appendChild(svg);

        result.links.forEach(function (link) {
            var path = document.createElementNS(SVG_NS, 'path');
            path.setAttribute('d', link.d);
            path.setAttribute('class', 'bkv-link');
            svg.appendChild(path);
        });

        result.labels.forEach(function (label) {
            var el = document.createElement('div');
            el.className = 'bkv-label'
                + (label.lane === 'lower' ? ' bkv-label--lower' : '')
                + (selected && selected.type === 'column' && String(selected.columnId) === String(label.columnId)
                    ? ' bkv-label--selected'
                    : '');
            el.style.left = label.x + 'px';
            el.style.top = label.y + 'px';
            el.textContent = label.text || '(round)';
            el.addEventListener('click', function () {
                selectColumn(label.columnId);
            });
            wrap.appendChild(el);
        });

        result.cards.forEach(function (card) {
            wrap.appendChild(buildCard(card));
        });

        canvas.appendChild(wrap);
    }

    function buildCard(card) {
        var el = document.createElement('div');
        el.className = 'bkv-card'
            + (card.lane === 'lower' ? ' bkv-card--lower' : '')
            + (isLive(card.columnId, card.matchId) ? ' bkv-card--live' : '')
            + (selected && selected.type === 'match' && String(selected.columnId) === String(card.columnId)
                && String(selected.matchId) === String(card.matchId) ? ' bkv-card--selected' : '');
        el.style.left = card.x + 'px';
        el.style.top = card.y + 'px';
        el.style.width = card.w + 'px';
        el.style.height = card.h + 'px';

        if (card.gf || card.small) {
            var head = document.createElement('div');
            head.className = 'bkv-head' + (card.small ? ' bkv-head--small' : '');
            head.textContent = (card.match.label || '')
                || (card.gf ? (card.column.label || 'Grand Final') : 'Match de 3è place');
            el.appendChild(head);
        }

        var teams = (card.match.teams || []).slice(0, 2);
        while (teams.length < 2) {
            teams.push({});
        }
        teams.forEach(function (team, side) {
            var row = document.createElement('div');
            row.className = 'bkv-team bkv-team--' + teamStatus(team, teams[1 - side]);

            if (team.avatar) {
                var avatar = document.createElement('img');
                avatar.src = team.avatar;
                avatar.alt = '';
                row.appendChild(avatar);
            }

            var name = document.createElement('span');
            name.className = 'name';
            name.textContent = team.name || '—';
            row.appendChild(name);

            var score = document.createElement('span');
            score.className = 'score';
            score.textContent = team.score === '' || team.score === undefined || team.score === null ? '–' : String(team.score);
            row.appendChild(score);

            el.appendChild(row);
        });

        if (isLive(card.columnId, card.matchId)) {
            var live = document.createElement('span');
            live.className = 'bkv-live';
            live.textContent = 'EN DIRECT';
            el.appendChild(live);
        }

        el.addEventListener('click', function () {
            selectMatch(card.columnId, card.matchId);
        });

        return el;
    }

    /* ── Sélection et panneau latéral ───────────────────────────────────── */

    function hidePanelSections() {
        columnSection.hidden = true;
        matchSection.hidden = true;
        panelHint.hidden = true;
    }

    function selectColumn(columnId) {
        selected = { type: 'column', columnId: String(columnId) };
        syncPanel();
        render();
    }

    function selectMatch(columnId, matchId) {
        selected = { type: 'match', columnId: String(columnId), matchId: String(matchId) };
        syncPanel();
        render();
    }

    function syncPanel() {
        hidePanelSections();
        addMatchBtn.disabled = true;
        document.getElementById('bkv-add-third').hidden = true;
        document.getElementById('bkv-match-label-row').hidden = true;

        if (selected === null) {
            panelHint.hidden = false;
            return;
        }

        var column = findColumn(selected.columnId);
        if (!column) {
            selected = null;
            panelHint.hidden = false;
            return;
        }

        if (selected.type === 'column') {
            columnSection.hidden = false;
            document.getElementById('bkv-column-title').textContent
                = 'Colonne — ' + (column.label !== '' ? column.label : '(sans label)');
            document.getElementById('bkv-column-label').value = column.label || '';
            document.getElementById('bkv-column-lane').value = column.lane || 'upper';
            addMatchBtn.disabled = false;

            /* Le bouton « match de 3è place » n'existe que sur les colonnes
               de la voie finale (grande carte) : il ajoute une case compacte
               empilée dessous. */
            if ((column.lane || 'upper') === 'final') {
                document.getElementById('bkv-add-third').hidden = false;
            }
            return;
        }

        var match = findMatch(selected.columnId, selected.matchId);
        if (!match) {
            selected = null;
            panelHint.hidden = false;
            return;
        }

        matchSection.hidden = false;
        var index = (column.matches || []).indexOf(match);
        document.getElementById('bkv-match-title').textContent
            = 'Case ' + (index + 1) + ' — ' + (column.label !== '' ? column.label : '(sans label)');

        /* Titre de carte pour les cases de la voie finale (grande finale,
           match de 3è place). */
        if ((column.lane || 'upper') === 'final') {
            document.getElementById('bkv-match-label-row').hidden = false;
            document.getElementById('bkv-match-label').value = match.label || '';
        }

        var teams = (match.teams || []).slice(0, 2);
        while (teams.length < 2) {
            teams.push({});
        }
        [1, 2].forEach(function (side) {
            var team = teams[side - 1] || {};
            document.getElementById('bkv-t' + side + '-name').value = team.name || '';
            document.getElementById('bkv-t' + side + '-avatar').value = team.avatar || '';
            document.getElementById('bkv-t' + side + '-country').value = team.country || '';
            document.getElementById('bkv-t' + side + '-score').value
                = team.score === undefined || team.score === null ? '' : String(team.score);
        });

        document.getElementById('bkv-match-series').value = match.series_token || '';
        document.getElementById('bkv-match-manual').checked = !!match.manual_scores;
        document.getElementById('bkv-match-live').checked = isLive(selected.columnId, selected.matchId);
    }

    /* Saisie dans le panneau → état → rafraîchit le canvas (le panneau
       n'est pas re-rendu, le focus reste là où l'on tape). */
    [1, 2].forEach(function (side) {
        function teamOf(match) {
            match.teams = (match.teams || []).slice(0, 2);
            while (match.teams.length < 2) {
                match.teams.push(newTeam());
            }

            return match.teams[side - 1];
        }

        function bind(id, apply) {
            var input = document.getElementById(id);
            if (!input) {
                return;
            }
            ['input', 'change'].forEach(function (event) {
                input.addEventListener(event, function () {
                    if (selected === null || selected.type !== 'match') {
                        return;
                    }
                    var match = findMatch(selected.columnId, selected.matchId);
                    if (match) {
                        apply(match, input);
                        render();
                    }
                });
            });
        }

        bind('bkv-t' + side + '-name', function (match, input) { teamOf(match).name = input.value; });
        bind('bkv-t' + side + '-avatar', function (match, input) { teamOf(match).avatar = input.value; });
        bind('bkv-t' + side + '-country', function (match, input) { teamOf(match).country = input.value; });
        bind('bkv-t' + side + '-score', function (match, input) { teamOf(match).score = input.value; });
    });

    /* Titre de carte d'une case de la voie finale (match de 3è place…). */
    document.getElementById('bkv-match-label').addEventListener('input', function () {
        if (selected === null || selected.type !== 'match') {
            return;
        }
        var match = findMatch(selected.columnId, selected.matchId);
        if (match) {
            match.label = this.value;
            render();
        }
    });

    document.getElementById('bkv-match-series').addEventListener('change', function () {
        if (selected === null || selected.type !== 'match') {
            return;
        }
        var match = findMatch(selected.columnId, selected.matchId);
        if (match) {
            match.series_token = this.value !== '' ? this.value : null;
            render();
        }
    });

    document.getElementById('bkv-match-manual').addEventListener('change', function () {
        if (selected === null || selected.type !== 'match') {
            return;
        }
        var match = findMatch(selected.columnId, selected.matchId);
        if (match) {
            match.manual_scores = this.checked;
            render();
        }
    });

    document.getElementById('bkv-match-live').addEventListener('change', function () {
        if (selected === null || selected.type !== 'match') {
            return;
        }
        if (this.checked) {
            state.live = { column: selected.columnId, match: selected.matchId };
        } else if (isLive(selected.columnId, selected.matchId)) {
            state.live = null;
        }
        syncPanel();
        render();
    });

    /* Remplissage assisté ETF2L : le script partagé remplit nom / avatar /
       pays sans émettre d'événement sur les champs ; son listener 'change'
       étant enregistré avant le nôtre, les valeurs sont à jour quand on
       relit les champs ici pour les faire remonter dans l'état. */
    document.addEventListener('change', function (event) {
        if (!event.target.closest || !event.target.closest('.js-etf2l-picker')) {
            return;
        }
        if (selected === null || selected.type !== 'match') {
            return;
        }
        var match = findMatch(selected.columnId, selected.matchId);
        if (!match) {
            return;
        }
        [1, 2].forEach(function (side) {
            match.teams = (match.teams || []).slice(0, 2);
            while (match.teams.length < 2) {
                match.teams.push(newTeam());
            }
            match.teams[side - 1].name = document.getElementById('bkv-t' + side + '-name').value;
            match.teams[side - 1].avatar = document.getElementById('bkv-t' + side + '-avatar').value;
            match.teams[side - 1].country = document.getElementById('bkv-t' + side + '-country').value;
        });
        render();
    });

    /* ── Colonnes : édition, déplacement, suppression, ajout ────────────── */

    document.getElementById('bkv-column-label').addEventListener('input', function () {
        if (selected === null || selected.type !== 'column') {
            return;
        }
        var column = findColumn(selected.columnId);
        if (column) {
            column.label = this.value;
            document.getElementById('bkv-column-title').textContent
                = 'Colonne — ' + (column.label !== '' ? column.label : '(sans label)');
            render();
        }
    });

    document.getElementById('bkv-column-lane').addEventListener('change', function () {
        if (selected === null || selected.type !== 'column') {
            return;
        }
        var column = findColumn(selected.columnId);
        if (column) {
            column.lane = this.value;
            render();
        }
    });

    /* Déplacement gauche / droite : la colonne est permutée avec sa plus
       proche voisine de même voie (l'ordre dans le tableau fait la
       profondeur de chaque voie). */
    function moveColumn(dir) {
        var column = findColumn(selected.columnId);
        if (!column) {
            return;
        }
        var index = state.columns.indexOf(column);
        var neighbor = index;
        do {
            neighbor += dir;
        } while (neighbor >= 0 && neighbor < state.columns.length
            && (state.columns[neighbor].lane || 'upper') !== (column.lane || 'upper'));

        if (neighbor < 0 || neighbor >= state.columns.length) {
            return;
        }
        var swap = state.columns[index];
        state.columns[index] = state.columns[neighbor];
        state.columns[neighbor] = swap;
        render();
    }

    document.getElementById('bkv-column-left').addEventListener('click', function () { moveColumn(-1); });
    document.getElementById('bkv-column-right').addEventListener('click', function () { moveColumn(1); });

    document.getElementById('bkv-column-delete').addEventListener('click', function () {
        if (selected === null || selected.type !== 'column') {
            return;
        }
        var column = findColumn(selected.columnId);
        if (!column || !confirm('Supprimer cette colonne et toutes ses cases ?')) {
            return;
        }
        if (state.live !== null && String(state.live.column) === String(column.id)) {
            state.live = null;
        }
        state.columns.splice(state.columns.indexOf(column), 1);
        selected = null;
        syncPanel();
        render();
    });

    document.getElementById('bkv-add-column').addEventListener('click', function () {
        var column = newColumn('upper');
        state.columns.push(column);
        selectColumn(column.id);
    });

    addMatchBtn.addEventListener('click', function () {
        if (selected === null || selected.type !== 'column') {
            return;
        }
        var column = findColumn(selected.columnId);
        if (!column) {
            return;
        }
        column.matches = column.matches || [];
        var match = newMatch();
        column.matches.push(match);
        selectMatch(column.id, match.id);
    });

    /* « Ajouter un match de 3è place » (colonnes de la voie finale) :
       une case compacte empilée sous la grande finale, titre modifiable —
       le match reste éditable comme n'importe quelle case. */
    document.getElementById('bkv-add-third').addEventListener('click', function () {
        if (selected === null || selected.type !== 'column') {
            return;
        }
        var column = findColumn(selected.columnId);
        if (!column || (column.lane || 'upper') !== 'final') {
            return;
        }
        column.matches = column.matches || [];
        var match = newMatch();
        match.label = 'Match de 3è place';
        column.matches.push(match);
        selectMatch(column.id, match.id);
    });

    /* ── Cases : déplacement et suppression ────────────────────────────── */

    function moveMatch(dir) {
        if (selected === null || selected.type !== 'match') {
            return;
        }
        var column = findColumn(selected.columnId);
        var match = findMatch(selected.columnId, selected.matchId);
        if (!column || !match) {
            return;
        }
        var index = (column.matches || []).indexOf(match);
        var neighbor = index + dir;
        if (index < 0 || neighbor < 0 || neighbor >= (column.matches || []).length) {
            return;
        }
        var swap = column.matches[index];
        column.matches[index] = column.matches[neighbor];
        column.matches[neighbor] = swap;
        syncPanel();
        render();
    }

    document.getElementById('bkv-match-up').addEventListener('click', function () { moveMatch(-1); });
    document.getElementById('bkv-match-down').addEventListener('click', function () { moveMatch(1); });

    document.getElementById('bkv-match-delete').addEventListener('click', function () {
        if (selected === null || selected.type !== 'match') {
            return;
        }
        var column = findColumn(selected.columnId);
        var match = findMatch(selected.columnId, selected.matchId);
        if (!column || !match) {
            return;
        }
        if (isLive(selected.columnId, selected.matchId)) {
            state.live = null;
        }
        (column.matches || []).splice((column.matches || []).indexOf(match), 1);
        selected = null;
        syncPanel();
        render();
    });

    /* ── Générateur de squelette ────────────────────────────────────────── */

    function roundLabel(count) {
        var labels = { 1: 'Finale', 2: 'Demi-finales', 4: 'Quarts de finale', 8: 'Huitièmes de finale' };
        return labels[count] || ('Tour ' + count);
    }

    function makeColumn(lane, label, matchCount) {
        var column = { id: newId('c'), label: label, lane: lane, matches: [] };
        for (var i = 0; i < matchCount; i++) {
            column.matches.push(newMatch());
        }

        return column;
    }

    document.getElementById('bkv-gen-run').addEventListener('click', function () {
        var teams = parseInt(document.getElementById('bkv-gen-teams').value, 10);
        var type = document.getElementById('bkv-gen-type').value;
        if (!confirm('Générer un squelette remplace le contenu actuel du bracket (cases, équipes, scores, match « EN DIRECT »). Continuer ?')) {
            return;
        }

        var columns = [];

        if (type === 'single') {
            var counts = (SHAPES.single[teams] || SHAPES.single[8]);
            counts.forEach(function (count, index) {
                var isFinal = index === counts.length - 1;
                columns.push(makeColumn(isFinal ? 'final' : 'upper', isFinal ? 'Finale' : roundLabel(count), count));
            });
        } else {
            var shape = SHAPES.double[teams] || SHAPES.double[8];

            shape.upper.forEach(function (count, index) {
                columns.push(makeColumn('upper', index === shape.upper.length - 1 && count === 1 ? 'WB Final' : 'WB R' + (index + 1), count));
            });
            shape.lower.forEach(function (count, index) {
                columns.push(makeColumn('lower', index === shape.lower.length - 1 && count === 1 ? 'LB Final' : 'LB R' + (index + 1), count));
            });
            columns.push(makeColumn('final', 'Grand Final', 1));
        }

        state.columns = columns;
        state.live = null;
        selected = null;
        syncPanel();
        render();
    });

    /* ── Sérialisation au POST (schéma historique) ─────────────────────── */

    form.addEventListener('submit', function () {
        var host = document.getElementById('bkv-serialized');
        host.innerHTML = '';

        function add(name, value) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            host.appendChild(input);
        }

        state.columns.forEach(function (column, ci) {
            var base = 'columns[' + ci + ']';
            add(base + '[id]', column.id || '');
            add(base + '[label]', column.label || '');
            add(base + '[lane]', column.lane || 'upper');

            (column.matches || []).forEach(function (match, mi) {
                var prefix = base + '[matches][' + mi + ']';
                add(prefix + '[id]', match.id || '');
                add(prefix + '[label]', match.label || '');

                [1, 2].forEach(function (side) {
                    match.teams = (match.teams || []).slice(0, 2);
                    while (match.teams.length < 2) {
                        match.teams.push(newTeam());
                    }
                    var team = match.teams[side - 1] || {};
                    add(prefix + '[team' + side + '_name]', team.name || '');
                    add(prefix + '[team' + side + '_avatar]', team.avatar || '');
                    add(prefix + '[team' + side + '_country]', team.country || '');
                    add(prefix + '[team' + side + '_score]',
                        team.score === undefined || team.score === null ? '' : String(team.score));
                });

                add(prefix + '[series_token]', match.series_token || '');
                add(prefix + '[manual_scores]', match.manual_scores ? '1' : '0');
            });
        });

        add('live_match', state.live !== null ? state.live.column + ':' + state.live.match : '');
    });

    window.addEventListener('resize', function () {
        render();
    });

    syncPanel();
    render();
}());
