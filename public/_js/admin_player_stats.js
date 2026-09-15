/* Outil admin « Stats joueur » (/admin/stats-joueur) : saisie d'un joueur +
   de logs logs.tf, lancement du calcul en arrière-plan et suivi par polling.
   Aucun framework : fetch vanilla + DOM. */

(function () {
    'use strict';

    var POLL_INTERVAL_MS = 1500;
    var POLL_TIMEOUT_MS = 120000;

    var form = document.getElementById('ps-form');
    var steamInput = document.getElementById('ps-steam');
    var logsList = document.getElementById('ps-logs-list');
    var submitBtn = document.getElementById('ps-submit');
    var errorBox = document.getElementById('ps-error');
    var progressCard = document.getElementById('ps-progress-card');
    var progressFill = document.getElementById('ps-progress-fill');
    var progressText = document.getElementById('ps-progress-text');
    var resultWrap = document.getElementById('ps-result');

    var pollTimer = null;
    var pollStartedAt = 0;

    /* ─── Token CSRF ────────────────────────────────────────────────────── */
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /* ─── Lignes de logs dynamiques ─────────────────────────────────────── */
    function addLogRow(value) {
        var row = document.createElement('div');
        row.className = 'ps-log-row';

        var input = document.createElement('input');
        input.type = 'text';
        input.name = 'logs[]';
        input.className = 'form-control';
        input.placeholder = 'logs.tf/12345678 ou 12345678';
        if (value) { input.value = value; }
        row.appendChild(input);

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'ps-log-remove';
        remove.title = 'Retirer ce log';
        remove.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        remove.addEventListener('click', function () {
            row.remove();
            refreshRemoveButtons();
        });
        row.appendChild(remove);

        logsList.appendChild(row);
        refreshRemoveButtons();
    }

    function refreshRemoveButtons() {
        var rows = Array.prototype.slice.call(logsList.querySelectorAll('.ps-log-row'));
        rows.forEach(function (row) {
            var btn = row.querySelector('.ps-log-remove');
            if (btn) { btn.style.visibility = rows.length > 1 ? 'visible' : 'hidden'; }
        });
    }

    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'ps-log-add';
    addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Ajouter un log';
    addBtn.addEventListener('click', function () { addLogRow(''); });
    logsList.after(addBtn);

    /* ─── Helpers d'affichage ───────────────────────────────────────────── */
    function showError(msg) {
        errorBox.hidden = false;
        errorBox.textContent = msg;
    }

    function clearError() {
        errorBox.hidden = true;
        errorBox.textContent = '';
    }

    function showProgress(total, done, message) {
        progressCard.hidden = false;
        resultWrap.hidden = true;

        var pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
        progressFill.style.width = pct + '%';
        progressText.textContent = (total > 0 ? done + '/' + total + ' log(s) — ' : '') + message;
    }

    function esc(value) {
        return String(value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }

    /* Formats un nombre en français (ex : 128 400). */
    function fmt(value) {
        if (value === null || value === undefined) { return '—'; }
        return Number(value).toLocaleString('fr-FR');
    }

    /* ─── Requêtes ──────────────────────────────────────────────────────── */
    function postJson(url, fd) {
        return fetch(url, {
            method: 'POST',
            body: fd,
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken(),
            },
            credentials: 'same-origin',
        }).then(function (res) { return res.json().catch(function () { return null; }); });
    }

    function getJson(url) {
        return fetch(url, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        }).then(function (res) {
            if (res.status === 404) { return { status: 'notfound' }; }
            return res.json().catch(function () { return { status: 'notfound' }; });
        });
    }

    /* ─── Lancement du calcul ───────────────────────────────────────────── */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearError();

        var rows = Array.prototype.slice.call(logsList.querySelectorAll('input[name="logs[]"]'));
        var filled = rows.filter(function (r) { return r.value.trim() !== ''; });

        if (!steamInput.value.trim()) { showError('Renseignez le SteamID du joueur.'); return; }
        if (filled.length === 0) { showError('Saisissez au moins un log logs.tf.'); return; }
        if (!form.querySelector('input[name="stats[]"]:checked')) { showError('Cochez au moins une statistique à calculer.'); return; }

        var fd = new FormData();
        fd.append('steam', steamInput.value.trim());
        filled.forEach(function (r) { fd.append('logs[]', r.value.trim()); });
        Array.prototype.forEach.call(form.querySelectorAll('input[name="stats[]"]:checked'), function (c) {
            fd.append('stats[]', c.value);
        });

        submitBtn.disabled = true;

        postJson('/admin/stats-joueur/start', fd).then(function (data) {
            submitBtn.disabled = false;
            if (!data || !data.ok || !data.token) {
                showError((data && data.message) || 'Impossible de lancer le calcul.');
                return;
            }
            pollStartedAt = Date.now();
            showProgress(data.log_count, 0, 'Lancement du calcul…');
            poll(data.token);
        }).catch(function () {
            submitBtn.disabled = false;
            showError('Erreur réseau pendant le lancement du calcul.');
        });
    });

    /* ─── Polling du statut ─────────────────────────────────────────────── */
    function poll(token) {
        if (pollTimer) { clearTimeout(pollTimer); }

        getJson('/admin/stats-joueur/status/' + token).then(function (data) {
            var status = data ? data.status : 'notfound';

            if (status === 'running') {
                showProgress(data.logs_total || 0, data.logs_done || 0, data.message || 'Calcul en cours…');
                if (Date.now() - pollStartedAt > POLL_TIMEOUT_MS) {
                    progressFill.style.width = '100%';
                    progressText.textContent = 'Le calcul semble bloqué. Relancez le calcul.';
                    return;
                }
                pollTimer = setTimeout(function () { poll(token); }, POLL_INTERVAL_MS);
                return;
            }

            if (status === 'done') {
                renderResult(data.result || {}, null);
                return;
            }

            if (status === 'error') {
                renderResult(data.result || {}, data.error || 'Le calcul a échoué.');
                return;
            }

            // Job introuvable (purge ou serveur relancé) : on attend un peu.
            if (Date.now() - pollStartedAt > 10000) {
                progressCard.hidden = true;
                showError('Job introuvable. Relancez le calcul.');
                return;
            }
            pollTimer = setTimeout(function () { poll(token); }, POLL_INTERVAL_MS);
        }).catch(function () {
            if (Date.now() - pollStartedAt > 20000) {
                progressCard.hidden = true;
                showError('Plus de réponse du serveur. Relancez le calcul.');
                return;
            }
            pollTimer = setTimeout(function () { poll(token); }, POLL_INTERVAL_MS);
        });
    }

    /* ─── Rendu du résultat ─────────────────────────────────────────────── */
    function renderResult(result, errorMessage) {
        progressCard.hidden = true;
        resultWrap.hidden = false;

        var logs = result.logs || [];
        var stats = result.stats || {};
        var player = result.player || {};

        var html = '';

        if (errorMessage) {
            html += '<div class="admin-alert admin-alert--error"><i class="fa-solid fa-circle-xmark"></i> '
                + esc(errorMessage) + '</div>';
        }

        html += '<div class="admin-card">';
        html += '<h3 class="admin-card__title"><i class="fa-solid fa-user-chart"></i> Résultat</h3>';
        html += '<div class="ps-result-header">';
        html += '<span class="ps-player">Joueur : <code>' + esc(player.steamid || '') + '</code></span>';
        html += '<span class="ps-player">' + (result.logs_usable || 0) + '/' + (result.logs_total || 0)
            + ' log(s) pris en compte</span>';
        html += '</div>';

        var cards = buildStatCards(stats);

        if (cards.length > 0) {
            html += '<div class="ps-result-grid">' + cards.join('') + '</div>';
        } else if (result.logs_usable === 0) {
            html += '<p class="ps-warn"><i class="fa-solid fa-triangle-exclamation"></i> '
                + 'Aucune statistique à afficher : le joueur n\'a été trouvé dans aucun log exploitable.</p>';
        }

        if (logs.length > 0) {
            html += buildLogsTable(logs);
        }

        html += '</div>';

        resultWrap.innerHTML = html;
    }

    function buildStatCards(stats) {
        var cards = [];

        if (stats.kd !== undefined) {
            cards.push(card('Ratio K/D', fmt(stats.kd)));
        }
        if (stats.dpm !== undefined) {
            cards.push(card('DPM moyen', stats.dpm !== null ? fmt(stats.dpm) : '—'));
        }
        if (stats.dmg_total !== undefined) {
            var dmgSub = stats.dmg_avg !== null ? 'Moyenne : ' + fmt(stats.dmg_avg) + ' / log' : null;
            cards.push(card('Dégâts', fmt(stats.dmg_total), dmgSub));
        }
        if (stats.heal !== undefined) {
            cards.push(card('Soins reçus', fmt(stats.heal)));
        }
        if (stats.winrate !== undefined) {
            cards.push(card('Winrate', stats.winrate !== null ? stats.winrate + ' %' : '—'));
        }

        return cards;
    }

    function card(label, value, sub) {
        return '<div class="ps-result-card">'
            + '<div class="ps-result-card__label">' + esc(label) + '</div>'
            + '<div class="ps-result-card__value">' + esc(value) + '</div>'
            + (sub ? '<div class="ps-result-card__sub">' + esc(sub) + '</div>' : '')
            + '</div>';
    }

    function buildLogsTable(logs) {
        var rows = logs.map(function (log) {
            var useState = '';
            var stateTd = '';
            var meta = '';

            if (!log.found) {
                stateTd = '<span style="color:#f35f5f;">Introuvable</span>';
                meta = log.error ? '<br><small style="color:#f35f5f;">' + esc(log.error) + '</small>' : '';
                useState = ' class="ps-log-muted"';
            } else if (!log.player_present) {
                stateTd = '<span style="color:#f39c12;">Joueur absent</span>';
                useState = ' class="ps-log-muted"';
            } else {
                stateTd = '<span style="color:#5cb85c;">Présent</span>';
            }

            return '<tr' + useState + '>'
                + '<td><a href="https://logs.tf/' + (log.log_id | 0) + '" target="_blank" rel="noopener">'
                + '#' + (log.log_id | 0) + '</a>' + meta + '</td>'
                + '<td>' + stateTd + '</td>'
                + '<td>' + (log.player_present ? fmt(log.dmg) : '—') + '</td>'
                + '<td>' + (log.player_present ? fmt(log.kills) : '—') + '</td>'
                + '<td>' + (log.player_present ? fmt(log.deaths) : '—') + '</td>'
                + '<td>' + (log.player_present ? fmt(log.dapm) : '—') + '</td>'
                + '<td>' + (log.player_present ? fmt(log.heal) : '—') + '</td>'
                + '<td>' + (log.player_present ? wonLabel(log.won) : '—') + '</td>'
                + '</tr>';
        });

        return '<div class="admin-table-scroll"><table class="admin-table">'
            + '<thead><tr>'
            + '<th>Log</th><th>Statut</th><th>Dégâts</th><th>Éliminations</th><th>Décès</th><th>DPM</th><th>Soins</th><th>Résultat</th>'
            + '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
    }

    function wonLabel(won) {
        if (won === null || won === undefined) { return '—'; }
        return won === 1 ? 'Victoire' : 'Défaite';
    }

    /* ─── Initialisation ────────────────────────────────────────────────── */
    addLogRow('');
    addLogRow('');
    addLogRow('');
})();