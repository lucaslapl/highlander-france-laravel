(function () {
    'use strict';

    const POLL_INTERVAL = 20000; // 20 s

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function fetchLiveMatches() {
        return fetch('/api/live-matches', { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (json) { return json.data || []; });
    }

    // ---- Badge "EN DIRECT" dans la barre de navigation ----
    function updateNavBadge(matches) {
        const badge = document.getElementById('liveNavBadge');
        const mapEl = document.getElementById('liveNavMap');
        if (!badge || !mapEl) {
            return;
        }

        if (matches.length === 0) {
            badge.hidden = true;
            return;
        }

        // Le match le plus récemment mis à jour.
        const latest = matches.reduce(function (a, b) {
            return (b.updated_at || 0) > (a.updated_at || 0) ? b : a;
        }, matches[0]);

        let label = latest.map || '';
        if (latest.scores && latest.scores.red !== undefined && latest.scores.blue !== undefined) {
            label += ' ' + latest.scores.red + ' - ' + latest.scores.blue;
        }

        mapEl.textContent = label;
        badge.href = '/live/' + encodeURIComponent(latest.server);
        badge.hidden = false;
    }

    // ---- Page détail (mise à jour du score, des joueurs, fin de match) ----
    function playerRow(p, rank) {
        const cls = escapeHtml(p.class || '');
        const iconExists = cls !== '';
        const pseudo = p.display_name || p.name || 'Joueur Steam';
        const pseudoHtml = escapeHtml(pseudo);
        const icon = iconExists
            ? '<img src="/_img/classes/' + cls + '.png" alt="' + cls + '" class="class-icon" title="' + cls + '">'
            : '<span class="class-unknown">?</span>';
        const avatar = p.avatar
            ? '<img src="' + escapeHtml(p.avatar) + '" alt="Avatar de ' + pseudoHtml + '" class="player-avatar">'
            : '';
        const link = p.steamid64
            ? '<a href="/profile/' + escapeHtml(p.steamid64) + '" class="player-link">' + pseudoHtml + '</a>'
            : '<span class="player-link">' + pseudoHtml + '</span>';

        return '<tr>'
            + '<td>' + rank + '</td>'
            + '<td>' + icon + '</td>'
            + '<td><div class="player-cell flex align-center gap-10">' + avatar + link + '</div></td>'
            + '<td>' + (parseInt(p.score, 10) || 0) + '</td>'
            + '</tr>';
    }

    function renderTeamTbody(teamEl, players, team) {
        const tbody = teamEl.querySelector('tbody.live-tbody');
        if (!tbody) {
            return;
        }

        const list = (players || []).filter(function (p) { return (p.team || '') === team; });

        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="no-data">Aucun joueur en jeu.</td></tr>';
            return;
        }

        // Tri par score décroissant.
        list.sort(function (a, b) { return (parseInt(b.score, 10) || 0) - (parseInt(a.score, 10) || 0); });

        tbody.innerHTML = list.map(function (p, i) { return playerRow(p, i + 1); }).join('');
    }

    function showMatchEnded(detail) {
        detail.innerHTML = '<div class="live-ended">'
            + '<p class="live-ended-title"><i class="fa-solid fa-circle-check"></i> Le match est terminé.</p>'
            + '<p><a href="/match-logs" class="btn-match-link">Voir les derniers matchs</a></p>'
            + '</div>';
    }

    function updateDetail(matches) {
        const detail = document.getElementById('liveMatchDetail');
        if (!detail) {
            return;
        }

        const server = detail.getAttribute('data-server') || '';
        const current = matches.filter(function (m) { return m.server === server; })[0];

        if (!current) {
            showMatchEnded(detail);
            return;
        }

        const red = (current.scores && current.scores.red) || 0;
        const blue = (current.scores && current.scores.blue) || 0;

        const elRed = document.getElementById('liveScoreRed');
        const elBlue = document.getElementById('liveScoreBlue');
        const teamRed = document.getElementById('liveTeamScoreRed');
        const teamBlue = document.getElementById('liveTeamScoreBlue');
        const count = document.getElementById('livePlayerCount');

        if (elRed) { elRed.textContent = red; }
        if (elBlue) { elBlue.textContent = blue; }
        if (teamRed) { teamRed.textContent = red; }
        if (teamBlue) { teamBlue.textContent = blue; }

        const players = current.players || [];
        if (count) { count.textContent = players.length; }

        const teams = detail.querySelectorAll('.matchlog-team');
        if (teams[0]) { renderTeamTbody(teams[0], players, 'red'); }
        if (teams[1]) { renderTeamTbody(teams[1], players, 'blue'); }
    }

    // ---- Boucle ----
    const detailSeen = !!document.getElementById('liveMatchDetail');

    function tick() {
        fetchLiveMatches()
            .then(function (matches) {
                updateNavBadge(matches);
                if (detailSeen) {
                    updateDetail(matches);
                }
            })
            .catch(function () { /* on garde l'état affiché */ });
    }

    tick();
    setInterval(tick, POLL_INTERVAL);
})();
