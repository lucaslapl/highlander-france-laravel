/* Outil admin « Stats joueur / Équipe » (/admin/stats-joueur) :
   - onglet Joueur : saisie d'un joueur + logs logs.tf, calcul en arrière-plan ;
   - onglet Équipe : sélection d'une équipe ETF2L → préparation synchrone
     (roster + compétitions + logs découverts) → calcul asynchrone par mode.
   Suivi par polling. Aucun framework : fetch vanilla + DOM. */

(function () {
    'use strict';

    var POLL_INTERVAL_MS = 1500;
    var POLL_TIMEOUT_MS = 180000;

    /* ─── Éléments partagés ────────────────────────────────────────────── */
    var tabsEl = document.getElementById('ps-tabs');
    var errorBox = document.getElementById('ps-error');
    var progressCard = document.getElementById('ps-progress-card');
    var progressFill = document.getElementById('ps-progress-fill');
    var progressText = document.getElementById('ps-progress-text');
    var progressTimer = document.getElementById('ps-progress-timer');
    var logsDetailEl = document.getElementById('ps-logs-detail');
    var resultWrap = document.getElementById('ps-result');

    var pollTimer = null;
    var pollStartedAt = 0;
    var timerInterval = null;
    var timerStartAt = 0;

    /* ─── Éléments onglet Joueur ───────────────────────────────────────── */
    var form = document.getElementById('ps-form');
    var steamInput = document.getElementById('ps-steam');
    var logsList = document.getElementById('ps-logs-list');
    var submitBtn = document.getElementById('ps-submit');

    /* ─── Éléments onglet Équipe ───────────────────────────────────────── */
    var teamForm = document.getElementById('ps-team-form');
    var teamInput = document.getElementById('ps-team-input');
    var teamIdInput = document.getElementById('ps-team-id');
    var teamLoadBtn = document.getElementById('ps-team-load');
    var teamLoading = document.getElementById('ps-team-loading');
    var teamError = document.getElementById('ps-team-error');
    var teamPanels = document.getElementById('ps-team-panels');
    var teamSubmitBtn = document.getElementById('ps-team-submit');

    var selectedTeam = null;          // suggestion autocomplete résolue
    var teamSuggestions = [];
    var teamData = null;              // réponse de prepareTeam
    var compsByMode = { '9v9': [], '6s': [] };

    /* ─── Token CSRF ────────────────────────────────────────────────────── */
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /* ─── Onglets ───────────────────────────────────────────────────────── */
    if (tabsEl) {
        tabsEl.addEventListener('click', function (e) {
            var btn = e.target.closest('.ps-tab');
            if (!btn) { return; }
            var target = btn.getAttribute('data-panel-target');
            Array.prototype.forEach.call(tabsEl.querySelectorAll('.ps-tab'), function (t) {
                t.classList.toggle('is-active', t === btn);
            });
            ['player', 'team'].forEach(function (p) {
                var panel = document.getElementById('ps-panel-' + p);
                if (panel) { panel.hidden = (p !== target); panel.classList.toggle('is-active', p === target); }
            });
        });
    }

    /* ─── Lignes de logs dynamiques (Joueur) ────────────────────────────── */
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
    if (logsList) { logsList.after(addBtn); }

    /* ─── Helpers d'affichage ───────────────────────────────────────────── */
    function showError(msg) {
        errorBox.hidden = false;
        errorBox.textContent = msg;
    }

    function clearError() {
        errorBox.hidden = true;
        errorBox.textContent = '';
    }

    function showProgress(total, done, message, logsDetail) {
        progressCard.hidden = false;
        resultWrap.hidden = true;

        var pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
        progressFill.style.width = pct + '%';
        progressText.textContent = (total > 0 ? done + '/' + total + ' log(s) — ' : '') + message;
        renderLogsDetail(logsDetail);
    }

    /* Formatte le temps écoulé en français (ex : 1 min 12 s). */
    function fmtElapsed(sec) {
        if (sec < 60) { return sec + ' s'; }
        var m = Math.floor(sec / 60);
        var s = sec % 60;
        return m + ' min ' + (s < 10 ? '0' : '') + s + ' s';
    }

    function startTimer() {
        stopTimer();
        timerStartAt = Date.now();
        timerInterval = setInterval(function () {
            if (progressTimer) {
                progressTimer.textContent = 'Temps écoulé : ' + fmtElapsed(Math.floor((Date.now() - timerStartAt) / 1000));
            }
        }, 1000);
    }

    function stopTimer() {
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
        if (progressTimer) { progressTimer.textContent = ''; }
    }

    /* Rendu de la liste détaillée par log (statut en direct). */
    function renderLogsDetail(logsDetail) {
        if (!logsDetailEl || !Array.isArray(logsDetail) || logsDetail.length === 0) {
            if (logsDetailEl) { logsDetailEl.innerHTML = ''; }
            return;
        }

        var items = logsDetail.map(function (entry) {
            var status = entry.log_status || 'pending';
            var icon = '';
            var label = '';

            if (status === 'fetching') {
                icon = '<i class="fa-solid fa-spinner ps-spin ps-detail-icon" style="color:#ffb14d;"></i>';
                label = 'Récupération…';
            } else if (status === 'found') {
                icon = '<i class="fa-solid fa-circle-check ps-detail-icon" style="color:#5cb85c;"></i>';
                label = 'Joueur(s) trouvé(s)';
            } else if (status === 'absent') {
                icon = '<i class="fa-solid fa-circle-minus ps-detail-icon" style="color:#999;"></i>';
                label = 'Aucun joueur du roster';
            } else if (status === 'error') {
                icon = '<i class="fa-solid fa-circle-xmark ps-detail-icon" style="color:#f35f5f;"></i>';
                label = 'Erreur';
            } else {
                icon = '<i class="fa-regular fa-circle ps-detail-icon" style="color:#555;"></i>';
                label = 'En attente';
            }

            return '<div class="ps-detail-row">'
                + '<a href="https://logs.tf/' + (entry.log_id | 0) + '" target="_blank" rel="noopener">'
                + '#' + (entry.log_id | 0) + '</a>'
                + '<span class="ps-detail-state">' + icon + ' ' + esc(label) + '</span>'
                + '</div>';
        });

        logsDetailEl.innerHTML = '<div class="ps-detail-header">Détail par log</div>' + items.join('');
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

    function fmtDate(sec) {
        if (!sec) { return '—'; }
        var d = new Date(sec * 1000);
        return d.toLocaleDateString('fr-FR');
    }

    function modeLabel(mode) {
        return mode === '6s' ? '6s (6v6)' : '9v9 (Highlander)';
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
            if (res.status === 401 || res.status === 403) {
                return { status: 'forbidden' };
            }
            return res.json().catch(function () { return { status: 'notfound' }; });
        });
    }

    /* ─── Lancement du calcul (Joueur) ──────────────────────────────────── */
    if (form) {
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
                startTimer();
                showProgress(data.log_count, 0, 'Lancement du calcul…', []);
                poll(data.token);
            }).catch(function () {
                submitBtn.disabled = false;
                showError('Erreur réseau pendant le lancement du calcul.');
            });
        });
    }

    /* ─── Onglet Équipe : autocomplete ──────────────────────────────────── */
    if (teamInput) {
        teamInput.addEventListener('input', function () {
            selectedTeam = null;
            teamErrorMessage('');

            var q = teamInput.value.trim();
            if (q.length < 2) { teamSuggestions = []; return; }

            window.clearTimeout(teamInput._debounce);
            teamInput._debounce = window.setTimeout(function () {
                getJson('/admin/stats-joueur/teams/search?q=' + encodeURIComponent(q)).then(function (rows) {
                    if (!Array.isArray(rows)) { return; }
                    teamSuggestions = rows;
                    var list = document.getElementById('ps-team-list');
                    if (list) {
                        list.innerHTML = rows.map(function (r) {
                            return '<option value="' + esc(r.name) + '">' + esc((r.tag ? '[' + r.tag + '] ' : '') + r.name) + '</option>';
                        }).join('');
                    }
                    // Résolution directe si la saisie correspond exactement à une suggestion.
                    rows.forEach(function (r) {
                        if (r.name.toLowerCase() === q.toLowerCase()) { selectedTeam = r; }
                    });
                });
            }, 250);
        });
    }

    function teamErrorMessage(msg) {
        if (!teamError) { return; }
        teamError.hidden = !msg;
        teamError.textContent = msg;
    }

    function resolveTeamId() {
        if (selectedTeam) { return selectedTeam.id; }
        var id = parseInt(teamIdInput ? teamIdInput.value : '', 10);
        return (id && id > 0) ? id : null;
    }

    /* ─── Onglet Équipe : préparation synchrone ─────────────────────────── */
    if (teamForm) {
        teamForm.addEventListener('submit', function (e) {
            e.preventDefault();
            teamErrorMessage('');

            var teamId = resolveTeamId();
            if (!teamId) {
                teamErrorMessage('Saisissez un nom d\'équipe reconnu ou un ID ETF2L numérique.');
                return;
            }

            teamLoadBtn.disabled = true;
            teamLoading.hidden = false;

            var fd = new FormData();
            fd.append('team', String(teamId));

            postJson('/admin/stats-joueur/equipe/prepare', fd).then(function (data) {
                teamLoadBtn.disabled = false;
                teamLoading.hidden = true;

                if (!data || !data.ok || !data.team) {
                    teamErrorMessage((data && data.message) || 'Impossible de charger l\'équipe.');
                    return;
                }

                teamData = data;
                teamInput.value = data.team.name;
                if (teamIdInput) { teamIdInput.value = data.team.id; }
                selectedTeam = { id: data.team.id, name: data.team.name };
                renderTeamPanels(data);
            }).catch(function () {
                teamLoadBtn.disabled = false;
                teamLoading.hidden = true;
                teamErrorMessage('Erreur réseau pendant le chargement de l\'équipe.');
            });
        });
    }

    function checkedValues(selector, mode) {
        var nodes = Array.prototype.slice.call(document.querySelectorAll(selector));
        return nodes.filter(function (el) { return el.dataset.mode === mode && el.checked; })
            .map(function (el) { return el.value; });
    }

    /* Construit les sections par mode (compétition + stats + logs). */
    function renderTeamPanels(data) {
        compsByMode = { '9v9': [], '6s': [] };
        var hasAny = false;
        var html = '';
        var modes = ['9v9', '6s'];

        modes.forEach(function (mode) {
            var comps = (data.competitions && data.competitions[mode]) || [];
            var logs = data.logs.filter(function (l) { return l.mode === mode; });
            if (comps.length === 0 && logs.length === 0) { return; }
            compsByMode[mode] = comps;
            hasAny = true;

            html += '<div class="admin-card ps-team-mode">';
            html += '<h4 class="ps-team-mode__title"><i class="fa-solid ' + (mode === '6s' ? 'fa-bolt' : 'fa-shield-halved') + '"></i> ' + esc(modeLabel(mode)) + '</h4>';

            if (comps.length) {
                html += '<div class="form-group">';
                html += '<label class="admin-form-label" for="ps-comp-' + mode + '">Compétition (winrate officiel)</label>';
                html += '<select id="ps-comp-' + mode + '" class="form-control">';
                html += '<option value="0">— Choisir une compétition (facultatif) —</option>';
                comps.forEach(function (c) {
                    html += '<option value="' + c.id + '">' + esc(c.name)
                        + ' — ' + c.wins + 'V / ' + c.losses + 'D'
                        + (c.winrate !== null ? ' (' + c.winrate + ' %)' : '')
                        + '</option>';
                });
                html += '</select></div>';
            }

            html += statsChecksBlock(mode);
            html += logsChecksBlock(mode, logs);
            html += manualLogsBlock(mode);

            html += '</div>';
        });

        teamPanels.innerHTML = hasAny ? html : '<p class="ps-warn">Aucun résultat ni log découvert pour cette équipe (roster vide ou équipe absente des compétitions ETF2L).</p>';
        teamSubmitBtn.hidden = !hasAny;
    }

    function statsChecksBlock(mode) {
        var labels = window.PS_STAT_LABELS || {};
        var html = '<div class="form-group"><span class="admin-form-label">Stats à calculer</span><div class="ps-checks">';
        Object.keys(labels).forEach(function (key) {
            html += '<label class="ps-check"><input type="checkbox" class="ps-team-stat" data-mode="' + mode + '" value="' + esc(key) + '" checked><span>' + esc(labels[key]) + '</span></label>';
        });
        html += '</div></div>';
        return html;
    }

    function logsChecksBlock(mode, logs) {
        if (logs.length === 0) {
            return '<p class="ps-warn"><i class="fa-solid fa-triangle-exclamation"></i> Aucun log découvert pour le mode '
                + esc(modeLabel(mode)) + ' (décochez/limitez si faux positifs).</p>';
        }

        var html = '<div class="form-group"><span class="admin-form-label">Logs logs.tf découverts (' + logs.length + ')</span>';
        html += '<div class="ps-team-logs">';
        logs.forEach(function (l) {
            var src = l.source === 'tf2esports'
                ? ' <span class="ps-source-tag">tf2esports</span>'
                : '';
            html += '<label class="ps-team-log">'
                + '<input type="checkbox" class="ps-team-log-check" data-mode="' + mode + '" value="' + (l.id | 0) + '" checked>'
                + '<a href="https://logs.tf/' + (l.id | 0) + '" target="_blank" rel="noopener">#' + (l.id | 0) + '</a>'
                + '<span class="ps-team-log__title">' + esc(l.title) + src + '</span>'
                + '<span class="ps-team-log__meta">' + esc(l.map) + ' · ' + fmtDate(l.date) + ' · ' + (l.players | 0) + ' joueurs</span>'
                + '</label>';
        });
        html += '</div></div>';
        return html;
    }

    /* Champ de saisie manuelle d'IDs/URLs logs.tf, pour ce mode. */
    function manualLogsBlock(mode) {
        return '<div class="form-group">'
            + '<label class="admin-form-label" for="ps-team-manual-' + mode + '">IDs/URLs logs.tf à ajouter (facultatif)</label>'
            + '<textarea id="ps-team-manual-' + mode + '" class="ps-team-manual form-control" rows="2" data-mode="' + mode + '" '
            + 'placeholder="Un log par ligne : https://logs.tf/12345678 ou 12345678"></textarea>'
            + '</div>';
    }

    /* Extrait des IDs logs.tf depuis une saisie libre (IDs ou URLs, séparés). */
    function parseLogIds(raw) {
        var ids = [];
        String(raw || '').split(/[\s,;]+/).forEach(function (token) {
            if (!token) { return; }
            var m = token.match(/^(?:https?:\/\/logs\.tf\/)?(\d{4,10})$/i);
            if (m) {
                var n = parseInt(m[1], 10);
                if (ids.indexOf(n) === -1) { ids.push(n); }
            }
        });
        return ids;
    }

    /* ─── Onglet Équipe : lancement du calcul ───────────────────────────── */
    if (teamSubmitBtn) {
        teamSubmitBtn.addEventListener('click', function () {
            if (!teamData) { teamErrorMessage('Chargez d\'abord l\'équipe.'); return; }

            var modes = {};
            ['9v9', '6s'].forEach(function (mode) {
                var sel = document.getElementById('ps-comp-' + mode);
                var selectedId = sel ? sel.value : '0';
                var comp = null;
                (compsByMode[mode] || []).forEach(function (c) {
                    if (String(c.id) === String(selectedId)) { comp = c; }
                });
                var logs = checkedValues('.ps-team-log-check', mode).map(function (v) { return parseInt(v, 10); });
                var manualEl = document.getElementById('ps-team-manual-' + mode);
                parseLogIds(manualEl ? manualEl.value : '').forEach(function (id) {
                    if (logs.indexOf(id) === -1) { logs.push(id); }
                });
                var stats = checkedValues('.ps-team-stat', mode);
                if (comp || logs.length) { modes[mode] = { competition: comp, logs: logs, stats: stats }; }
            });

            var fd = new FormData();
            fd.append('team[id]', String(teamData.team.id));
            fd.append('team[name]', teamData.team.name);
            teamData.team.players.forEach(function (p, i) {
                fd.append('players[' + i + '][steamid64]', p.steamid64);
            });
            Object.keys(modes).forEach(function (mode) {
                var m = modes[mode];
                if (m.competition) { fd.append('modes[' + mode + '][competition][id]', String(m.competition.id)); }
                m.logs.forEach(function (id) { fd.append('modes[' + mode + '][logs][]', String(id)); });
                m.stats.forEach(function (s) { fd.append('modes[' + mode + '][stats][]', s); });
            });

            if (Object.keys(modes).length === 0) {
                teamErrorMessage('Cochez au moins un log ou une compétition pour lancer le calcul.');
                return;
            }

            teamSubmitBtn.disabled = true;

            postJson('/admin/stats-joueur/equipe/start', fd).then(function (data) {
                teamSubmitBtn.disabled = false;
                if (!data || !data.ok || !data.token) {
                    teamErrorMessage((data && data.message) || 'Impossible de lancer le calcul.');
                    return;
                }
                pollStartedAt = Date.now();
                startTimer();
                showProgress(data.log_count, 0, 'Lancement du calcul…', []);
                poll(data.token);
            }).catch(function () {
                teamSubmitBtn.disabled = false;
                teamErrorMessage('Erreur réseau pendant le lancement du calcul.');
            });
        });
    }

    /* ─── Polling du statut ─────────────────────────────────────────────── */
    function poll(token) {
        if (pollTimer) { clearTimeout(pollTimer); }

        getJson('/admin/stats-joueur/status/' + token).then(function (data) {
            var status = data ? data.status : 'notfound';

            if (status === 'running') {
                showProgress(data.logs_total || 0, data.logs_done || 0, data.message || 'Calcul en cours…', data.logs_detail);
                if (Date.now() - pollStartedAt > POLL_TIMEOUT_MS) {
                    progressFill.style.width = '100%';
                    progressText.textContent = 'Le calcul semble bloqué. Relancez le calcul.';
                    stopTimer();
                    return;
                }
                pollTimer = setTimeout(function () { poll(token); }, POLL_INTERVAL_MS);
                return;
            }

            if (status === 'done') {
                stopTimer();
                renderGenericResult(data.result || {});
                return;
            }

            if (status === 'error') {
                stopTimer();
                renderGenericResult(data.result || {}, data.error || 'Le calcul a échoué.');
                return;
            }

            if (status === 'forbidden') {
                stopTimer();
                progressCard.hidden = true;
                showError('Session expirée ou accès refusé. Rafraîchissez la page et reconnectez-vous au panel admin.');
                return;
            }

            if (Date.now() - pollStartedAt > 10000) {
                stopTimer();
                progressCard.hidden = true;
                showError('Job introuvable. Relancez le calcul.');
                return;
            }
            pollTimer = setTimeout(function () { poll(token); }, POLL_INTERVAL_MS);
        }).catch(function () {
            if (Date.now() - pollStartedAt > 20000) {
                stopTimer();
                progressCard.hidden = true;
                showError('Erreur réseau ou serveur injoignable. Vérifiez votre connexion puis relancez le calcul.');
                return;
            }
            pollTimer = setTimeout(function () { poll(token); }, POLL_INTERVAL_MS);
        });
    }

    /* ─── Rendu du résultat (détecte joueur vs équipe) ──────────────────── */
    function renderGenericResult(result, errorMessage) {
        progressCard.hidden = true;
        resultWrap.hidden = false;
        if (logsDetailEl) { logsDetailEl.innerHTML = ''; }

        if (result && result.modes) {
            renderTeamResult(result, errorMessage);
        } else {
            renderPlayerResult(result || {}, errorMessage);
        }
    }

    /* ─── Résultat joueur ───────────────────────────────────────────────── */
    function renderPlayerResult(result, errorMessage) {
        var logs = result.logs || [];
        var stats = result.stats || {};
        var player = result.player || {};

        var html = '';

        if (errorMessage) {
            html += '<div class="admin-alert admin-alert--error"><i class="fa-solid fa-circle-xmark"></i> '
                + esc(errorMessage) + '</div>';
        }

        html += '<div class="admin-card">';
        html += '<h3 class="admin-card__title"><i class="fa-solid fa-user-chart"></i> Résultat joueur</h3>';
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

    /* ─── Résultat équipe (winrate officiel affiché en fin + grille par mode) ── */
    function renderTeamResult(result, errorMessage) {
        var team = result.team || {};
        var modes = result.modes || {};

        var html = '';

        if (errorMessage) {
            html += '<div class="admin-alert admin-alert--error"><i class="fa-solid fa-circle-xmark"></i> '
                + esc(errorMessage) + '</div>';
        }

        html += '<div class="admin-card">';
        html += '<h3 class="admin-card__title"><i class="fa-solid fa-users"></i> Résultat équipe</h3>';
        html += '<div class="ps-result-header">';
        html += '<span class="ps-player">Équipe : <code>' + esc(team.name || '') + '</code></span>';
        html += '<span class="ps-player">' + (team.id ? '(ETF2L #' + team.id + ')' : '') + '</span>';
        html += '</div>';

        var modeOrder = (Object.prototype.hasOwnProperty.call(modes, '9v9') ? ['9v9'] : []).concat(
            Object.prototype.hasOwnProperty.call(modes, '6s') ? ['6s'] : []
        );

        if (modeOrder.length === 0) {
            html += '<p class="ps-warn"><i class="fa-solid fa-triangle-exclamation"></i> Aucune donnée à afficher pour ce mode sélectionné.</p>';
        }

        modeOrder.forEach(function (mode) {
            html += buildTeamModeSection(mode, modes[mode]);
        });

        html += '</div>';

        resultWrap.innerHTML = html;
    }

    function buildTeamModeSection(mode, m) {
        var comp = m.competition || null;
        var players = m.players || [];
        var totals = m.totals || {};
        var logs = m.logs || [];

        var html = '<div class="ps-team-result">';
        html += '<h4 class="ps-team-mode__title"><i class="fa-solid ' + (mode === '6s' ? 'fa-bolt' : 'fa-shield-halved') + '"></i> '
            + esc(modeLabel(mode)) + '</h4>';

        /* Winrate officiel (compétition sélectionnée). */
        if (comp) {
            html += '<div class="ps-result-grid">';
            html += card('Winrate officiel', comp.winrate !== null ? comp.winrate + ' %' : '—');
            html += card('Bilan', comp.wins + 'V / ' + comp.losses + 'D' + (comp.draws ? ' / ' + comp.draws + 'N' : ''), comp.name);
            html += card('Matchs (officiels)', comp.total);
            html += card('Joueurs pris en compte', players.length);
            html += '</div>';
        } else {
            html += '<p class="ps-warn"><i class="fa-solid fa-circle-info"></i> Aucune compétition officielle sélectionnée pour le winrate.</p>';
        }

        /* Totaux d'équipe. */
        html += '<div class="ps-totals">'
            + '<span>K/D équipe : <b>' + fmt(totals.kd) + '</b></span>'
            + '<span>Éliminations : <b>' + fmt(totals.kills) + '</b></span>'
            + '<span>Décès : <b>' + fmt(totals.deaths) + '</b></span>'
            + '<span>Dégâts : <b>' + fmt(totals.dmg) + '</b></span>'
            + '</div>';

        /* Grille joueurs. */
        if (players.length) {
            var rows = players.map(function (p) {
                return '<tr>'
                    + '<td>' + esc(p.name) + '</td>'
                    + '<td>' + esc(p.role) + '</td>'
                    + '<td>' + (p.matches | 0) + '</td>'
                    + '<td>' + fmt(p.kd) + '</td>'
                    + '<td>' + (p.dpm !== null ? fmt(p.dpm) : '—') + '</td>'
                    + '<td>' + fmt(p.dmg) + '</td>'
                    + '<td>' + fmt(p.heal) + '</td>'
                    + '</tr>';
            });
            html += '<div class="admin-table-scroll"><table class="admin-table">'
                + '<thead><tr><th>Joueur</th><th>Rôle</th><th>Matchs</th><th>K/D</th><th>DPM</th><th>Dégâts</th><th>Soins</th></tr></thead>'
                + '<tbody>' + rows.join('') + '</tbody></table></div>';
        } else {
            html += '<p class="ps-warn">Aucun joueur trouvé dans les logs sélectionnés pour ce mode.</p>';
        }

        /* Détail par log. */
        if (logs.length) {
            html += buildTeamLogsList(logs);
        }

        html += '</div>';

        return html;
    }

    function buildTeamLogsList(logs) {
        var items = logs.map(function (log) {
            var n = log.present | 0;
            var badge = !log.found
                ? '<span style="color:#f35f5f;">Erreur</span>'
                : (n > 0 ? '<span style="color:#5cb85c;">' + n + ' joueur(s)</span>' : '<span style="color:#f39c12;">Aucun</span>');
            return '<div class="ps-detail-row">'
                + '<a href="https://logs.tf/' + (log.log_id | 0) + '" target="_blank" rel="noopener">#' + (log.log_id | 0) + '</a>'
                + '<span class="ps-detail-state">' + badge + '</span>'
                + '</div>';
        });

        return '<div class="ps-logs-detail"><div class="ps-detail-header">Détail par log</div>' + items.join('') + '</div>';
    }

    /* ─── Initialisation ────────────────────────────────────────────────── */
    addLogRow('');
    addLogRow('');
    addLogRow('');
})();