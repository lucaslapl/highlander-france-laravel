@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')

<div class="admin-back">
    <a href="/admin/dashboard">
        <i class="fa-solid fa-arrow-left"></i> Retour au Panel Admin
    </a>
</div>

<div class="admin-header" style="--accent: #9b59b6;">
    <h2><i class="fa-solid fa-flask"></i> Simulateur d'API</h2>
    <p>Injection de données de test dans les caches du site : mixs en cours, streams Twitch, matchs ETF2L.</p>
    <p><span class="admin-legend">Attention</span> ces données sont visibles par tous les visiteurs tant qu'elles sont actives (TTL 2 min pour le live, 15 min pour Twitch).</p>
</div>

<div class="api-tabs" style="display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap;">
    <button type="button" class="admin-btn api-tab-btn active" data-tab="live" style="--accent: #9b59b6;"><i class="fa-solid fa-tower-broadcast"></i> Mix en cours</button>
    <button type="button" class="admin-btn api-tab-btn" data-tab="etf2l" style="--accent: #9b59b6;"><i class="fa-solid fa-calendar-days"></i> ETF2L</button>
    <button type="button" class="admin-btn api-tab-btn" data-tab="twitch" style="--accent: #9b59b6;"><i class="fa-brands fa-twitch"></i> Streams Twitch</button>
    <button type="button" class="admin-btn api-tab-btn" data-tab="explorer" style="--accent: #9b59b6;"><i class="fa-solid fa-code"></i> Explorateur GET</button>
</div>

{{-- ─── Onglet 1 : simulation d'un match live ───────────────────────────── --}}
<div class="api-tab-panel" id="tab-live">

    <div class="admin-card">
        <h3 class="admin-card__title"><i class="fa-solid fa-play"></i> Démarrer / mettre à jour un match simulé</h3>
        <form id="live-form" onsubmit="return false;">
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="admin-form-label" for="live-server">Nom du serveur</label>
                    <input type="text" id="live-server" class="form-control" value="sim-fr-1" maxlength="64" required>
                </div>
                <div class="form-group">
                    <label class="admin-form-label" for="live-map">Carte</label>
                    <input type="text" id="live-map" class="form-control" value="pl_upward" maxlength="64" list="tf2-maps">
                    <datalist id="tf2-maps">
                        <option value="pl_upward"><option value="pl_badwater"><option value="pl_borneo">
                        <option value="pl_swiftwater_ultimate"><option value="cp_steel"><option value="cp_gullywash_final1">
                        <option value="cp_process_final"><option value="koth_product_rc8"><option value="koth_lazarus">
                        <option value="ctf_turbine">
                    </datalist>
                </div>
                <div class="form-group">
                    <label class="admin-form-label" for="live-score-red">Score RED</label>
                    <input type="number" id="live-score-red" class="form-control" value="2" min="0" max="99">
                </div>
                <div class="form-group">
                    <label class="admin-form-label" for="live-score-blue">Score BLU</label>
                    <input type="number" id="live-score-blue" class="form-control" value="1" min="0" max="99">
                </div>
                <div class="form-group">
                    <label class="admin-form-label" for="live-minutes">Match démarré depuis (minutes)</label>
                    <input type="number" id="live-minutes" class="form-control" value="15" min="0" max="180">
                </div>
                <div class="form-group">
                    <label class="admin-form-label" for="live-stv">STV connect (optionnel)</label>
                    <input type="text" id="live-stv" class="form-control" placeholder="connect stv.example.com:27020; password xxx" maxlength="512">
                </div>
            </div>

            <div class="form-group">
                <label class="admin-form-label" for="live-players-source">Joueurs</label>
                <select id="live-players-source" class="cron-select">
                    <option value="auto">Générer 18 joueurs fictifs (9v9)</option>
                    <option value="db">Joueurs réels de la base (test enrichissement avatars/pseudos)</option>
                    <option value="none">Aucun joueur</option>
                </select>
            </div>

            <div id="live-db-players" style="display: none; margin-bottom: 15px;">
                <p class="admin-form-label">Sélectionner jusqu'à 18 joueurs (cochés en premier = équipe RED) :</p>
                <div style="display: flex; flex-wrap: wrap; gap: 6px 16px;">
                    @foreach ($dbPlayers as $player)
                        <label style="font-size: 13px; cursor: pointer;">
                            <input type="checkbox" class="live-db-player" value="{{ $player['steamid'] }}"> {{ $player['name'] }}
                        </label>
                    @endforeach
                </div>
                @if ($dbPlayers === [])
                    <p class="admin-empty">Aucun joueur en base.</p>
                @endif
            </div>

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="admin-btn admin-btn--primary" id="btn-live-start" style="--accent: #27ae60;">
                    <i class="fa-solid fa-tower-broadcast"></i> Injecter / mettre à jour
                </button>
                <button type="button" class="admin-btn" id="btn-live-heartbeat" style="--accent: #f39c12;">
                    <i class="fa-solid fa-heart-pulse"></i> Heartbeat
                </button>
                <button type="button" class="admin-btn admin-btn--danger" id="btn-live-end" style="--accent: #c0392b;">
                    <i class="fa-solid fa-flag-checkered"></i> Terminer le match
                </button>
                <button type="button" class="admin-btn admin-btn--danger" id="btn-live-purge" style="--accent: #7f8c8d;">
                    <i class="fa-solid fa-trash"></i> Purger tout le cache live
                </button>
            </div>
        </form>
    </div>

    <div class="admin-card">
        <h3 class="admin-card__title"><i class="fa-solid fa-eye"></i> État actuel (<code>/api/live-matches</code>)</h3>
        <div id="live-state-box">
            @forelse ($liveServers as $server => $entry)
                <p>
                    <span class="status-badge status-success"><i class="fa-solid fa-circle" style="font-size: 8px;"></i> LIVE</span>
                    <a href="/live/{{ urlencode($server) }}" target="_blank"><b>{{ $server }}</b></a>
                    — {{ $entry['map'] }} · RED {{ $entry['scores']['red'] }} – {{ $entry['scores']['blue'] }} BLU
                    · {{ count($entry['players']) }} joueurs · maj {{ date('H:i:s', (int) $entry['updated_at']) }}
                </p>
            @empty
                <p class="admin-empty">Aucun mix en cours actuellement.</p>
            @endforelse
        </div>
    </div>

    <div class="admin-card" id="live-result-card" style="display: none;">
        <h3 class="admin-card__title"><i class="fa-solid fa-terminal"></i> Réponse & équivalent webhook</h3>
        <pre class="terminal-box" id="live-result-output"></pre>
    </div>
</div>

{{-- ─── Onglet 2 : matchs ETF2L ────────────────────────────────────────── --}}
<div class="api-tab-panel" id="tab-etf2l" style="display: none;">

    <div class="admin-card">
        <h3 class="admin-card__title"><i class="fa-solid fa-plus"></i> Créer un match ETF2L factice</h3>
        <p style="font-size: 13px; color: #aaa;">Les IDs ≥ 900000000 sont réservés au simulateur : le sync cron réel ne les écrasera jamais. Utile pour tester les pages /matchs, /match/{id} et l'association des streams.</p>
        <form id="etf2l-form" onsubmit="return false;">
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="admin-form-label" for="etf2l-id">ID (vide = aléatoire)</label>
                    <input type="number" id="etf2l-id" class="form-control" min="900000000" max="999999999" placeholder="ex. 900000001">
                </div>
                <div class="form-group">
                    <label class="admin-form-label" for="etf2l-offset">Décalage horaire (minutes, négatif = passé)</label>
                    <input type="number" id="etf2l-offset" class="form-control" value="0">
                </div>
                <div class="form-group">
                    <label class="admin-form-label" for="etf2l-team1">Équipe 1</label>
                    <input type="text" id="etf2l-team1" class="form-control" value="Les Baguettes" maxlength="128">
                </div>
                <div class="form-group">
                    <label class="admin-form-label" for="etf2l-team2">Équipe 2</label>
                    <input type="text" id="etf2l-team2" class="form-control" value="Croissant Crew" maxlength="128">
                </div>
                <div class="form-group">
                    <label class="admin-form-label" for="etf2l-comp">Compétition</label>
                    <input type="text" id="etf2l-comp" class="form-control" value="ETF2L Highlander Premier" maxlength="128">
                </div>
                <div class="form-group">
                    <label class="admin-form-label" for="etf2l-maps">Cartes (séparées par des virgules)</label>
                    <input type="text" id="etf2l-maps" class="form-control" value="pl_upward, cp_steel" placeholder="pl_upward, cp_steel">
                </div>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="admin-btn admin-btn--primary" id="btn-etf2l-create" style="--accent: #27ae60;">
                    <i class="fa-solid fa-floppy-disk"></i> Enregistrer le match factice
                </button>
                <button type="button" class="admin-btn admin-btn--danger" id="btn-etf2l-delete" style="--accent: #7f8c8d;">
                    <i class="fa-solid fa-trash"></i> Supprimer un ID factice
                </button>
            </div>
        </form>
        <div id="etf2l-result" style="margin-top: 12px;"></div>
    </div>

    <div class="admin-card">
        <h3 class="admin-card__title"><i class="fa-solid fa-hourglass-half"></i> Matchs à venir en base</h3>
        <div class="admin-table-scroll">
            <table class="admin-table">
                <thead>
                    <tr><th>ID</th><th>Date</th><th>Équipes</th><th>Compétition</th><th class="text-center">Action</th></tr>
                </thead>
                <tbody>
                    @forelse ($etf2lUpcoming as $match)
                        <tr>
                            <td>
                                <a href="/match/{{ $match['match_id'] }}" target="_blank">#{{ $match['match_id'] }}</a>
                                @if ($match['match_id'] >= 900000000)
                                    <span class="badge" style="background:#6c3483;">factice</span>
                                @endif
                            </td>
                            <td>{{ date('d/m H:i', (int) $match['match_date']) }}</td>
                            <td>{{ $match['team1_name'] ?? '?' }} vs {{ $match['team2_name'] ?? '?' }}</td>
                            <td>{{ $match['competition_name'] ?? '—' }}</td>
                            <td class="text-center">
                                @if ($match['match_id'] >= 900000000)
                                    <button type="button" class="btn-icon btn-etf2l-del" data-match-id="{{ $match['match_id'] }}" title="Supprimer ce match factice"><i class="fa-solid fa-trash"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center" style="color:#aaa; font-style: italic;">Aucun match à venir.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="admin-card">
        <h3 class="admin-card__title"><i class="fa-solid fa-clock-rotate-left"></i> Matchs récemment terminés (72 h)</h3>
        <ul style="margin: 0; padding-left: 18px; font-size: 14px;">
            @forelse ($etf2lRecent as $match)
                <li>
                    <a href="/match/{{ $match['match_id'] }}" target="_blank">#{{ $match['match_id'] }}</a>
                    — {{ $match['team1_name'] ?? '?' }} <b>{{ $match['r1'] ?? '-' }}</b> : <b>{{ $match['r2'] ?? '-' }}</b> {{ $match['team2_name'] ?? '?' }}
                    ({{ date('d/m H:i', (int) $match['match_date']) }})
                </li>
            @empty
                <li class="admin-empty">Aucun match récent.</li>
            @endforelse
        </ul>
    </div>
</div>

{{-- ─── Onglet 3 : streams Twitch ──────────────────────────────────────── --}}
<div class="api-tab-panel" id="tab-twitch" style="display: none;">

    <div class="admin-card">
        <h3 class="admin-card__title"><i class="fa-brands fa-twitch"></i> Simuler une chaîne en direct</h3>
        <p style="font-size: 13px; color: #aaa;">Écrit directement dans <code>cache_twitch_live.json</code>. L'association à un match ETF2L suit la même règle que le vrai service : titre contenant les deux noms d'équipes (association forte), sinon une seule correspondance ambiguïté exclue.</p>
        <form id="twitch-form" onsubmit="return false;">
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="admin-form-label" for="twitch-login">Chaîne (login)</label>
                    @if ($twitchChannels !== [])
                        <select id="twitch-login" class="cron-select">
                            @foreach ($twitchChannels as $channel)
                                <option value="{{ $channel }}">{{ $channel }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="text" id="twitch-login" class="form-control" placeholder="ma_chaine_twitch">
                        <small style="color: #e67e22;">TWITCH_CHANNELS est vide dans .env : le front pourrait ne pas afficher le badge.</small>
                    @endif
                </div>
                <div class="form-group">
                    <label class="admin-form-label" for="twitch-viewers">Spectateurs</label>
                    <input type="number" id="twitch-viewers" class="form-control" value="42" min="0">
                </div>
            </div>
            <div class="form-group">
                <label class="admin-form-label" for="twitch-title">Titre du stream</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="twitch-title" class="form-control" value="Team Fortress 2 Highlander" maxlength="200" style="flex: 1;">
                    <select id="twitch-title-from-match" class="cron-select" style="width: auto;">
                        <option value="">Préremplir depuis…</option>
                        @foreach ($etf2lUpcoming as $match)
                            @if (!is_null($match['r1'])) @continue @endif
                            @php
                                $inWindow = $match['match_date'] <= time() + 4 * 3600 && $match['match_date'] >= time() - 4 * 3600;
                            @endphp
                            <option value="{{ e(($match['team1_name'] ?? '').' vs '.($match['team2_name'] ?? '')) }}">#{{ $match['match_id'] }} — {{ $match['team1_name'] ?? '?' }} vs {{ $match['team2_name'] ?? '?' }} ({{ date('d/m H:i', (int) $match['match_date']) }}){{ $inWindow ? '' : ' — hors fenêtre ±4h' }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-group checkbox-group">
                <label style="cursor: pointer;">
                    <input type="checkbox" id="twitch-auto-match" checked> Association automatique aux matchs ETF2L (par titre)
                </label>
                <label style="cursor: pointer;">
                    <input type="checkbox" id="twitch-replace-all" checked> Remplacer tout le cache (retire les chaînes simulées précédemment)
                </label>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="admin-btn admin-btn--primary" id="btn-twitch-simulate" style="--accent: #9146ff;">
                    <i class="fa-solid fa-circle-play"></i> Passer la chaîne EN DIRECT
                </button>
                <button type="button" class="admin-btn admin-btn--danger" id="btn-twitch-reset" style="--accent: #7f8c8d;">
                    <i class="fa-solid fa-power-off"></i> Réinitialiser le cache Twitch
                </button>
            </div>
        </form>
    </div>

    <div class="admin-card">
        <h3 class="admin-card__title"><i class="fa-solid fa-eye"></i> État actuel (<code>/api/twitch-live</code>)</h3>
        <div id="twitch-state-box">
            @if ($twitchStatus['stale'])
                <p class="admin-empty">Cache périmé (> 15 min) : aucun badge ne serait affiché.</p>
            @elseif ($twitchStatus['channels'] === [])
                <p class="admin-empty">Aucune chaîne en direct actuellement.</p>
            @else
                @foreach ($twitchStatus['channels'] as $channel)
                    <p>
                        <span class="status-badge status-error"><i class="fa-solid fa-circle" style="font-size: 8px;"></i> LIVE</span>
                        <a href="{{ $channel['url'] }}" target="_blank"><b>{{ $channel['display_name'] }}</b></a>
                        — {{ $channel['viewers'] }} viewers — « {{ $channel['title'] }} »
                        @if (!empty($channel['matched_match_ids']))
                            · associé au(x) match(s) #{{ implode(', #', $channel['matched_match_ids']) }}
                        @else
                            · aucune association
                        @endif
                    </p>
                @endforeach
            @endif
        </div>
    </div>

    <div class="admin-card" id="twitch-result-card" style="display: none;">
        <h3 class="admin-card__title"><i class="fa-solid fa-terminal"></i> Réponse</h3>
        <pre class="terminal-box" id="twitch-result-output"></pre>
    </div>
</div>

{{-- ─── Onglet 4 : explorateur GET ─────────────────────────────────────── --}}
<div class="api-tab-panel" id="tab-explorer" style="display: none;">
    <div class="admin-card">
        <h3 class="admin-card__title"><i class="fa-solid fa-code"></i> Appeler un endpoint public</h3>
        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
            <select id="explorer-endpoint" class="cron-select" style="min-width: 320px;">
                <option value="/api/live-matches">GET /api/live-matches</option>
                <option value="/api/twitch-live">GET /api/twitch-live</option>
                <option value="/api/index-stats">GET /api/index-stats</option>
                <option value="/api/logs">GET /api/logs</option>
                <option value="/api/leaderboard?mode=9v9&category=matches">GET /api/leaderboard (9v9, matches)</option>
                <option value="/api/leaderboard?mode=6s&category=kills">GET /api/leaderboard (6s, kills)</option>
                <option value="/api/search-players?q=test">GET /api/search-players?q=test</option>
            </select>
            <button type="button" class="admin-btn admin-btn--primary" id="btn-explorer-fetch" style="--accent: #2980b9;">
                <i class="fa-solid fa-paper-plane"></i> Envoyer
            </button>
        </div>
        <pre class="terminal-box" id="explorer-output" style="margin-top: 15px; min-height: 120px;">— La réponse apparaîtra ici —</pre>
    </div>
</div>

<script>
(function () {
    "use strict";

    const CSRF = "{{ csrf_token() }}";

    // ── Onglets ──
    document.querySelectorAll(".api-tab-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            document.querySelectorAll(".api-tab-btn").forEach(b => b.classList.remove("active"));
            document.querySelectorAll(".api-tab-panel").forEach(p => p.style.display = "none");
            btn.classList.add("active");
            document.getElementById("tab-" + btn.dataset.tab).style.display = "";
        });
    });

    function post(url, data) {
        return fetch(url, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-Token": CSRF
            },
            body: JSON.stringify(data || {})
        }).then(function (res) {
            return res.json().catch(function () { return { success: false, message: "Réponse illisible." }; });
        });
    }

    // ── Onglet Live ──
    const playersSource = document.getElementById("live-players-source");
    playersSource.addEventListener("change", function () {
        document.getElementById("live-db-players").style.display =
            this.value === "db" ? "" : "none";
    });

    function liveFormData() {
        return {
            server: document.getElementById("live-server").value.trim(),
            map: document.getElementById("live-map").value.trim(),
            score_red: document.getElementById("live-score-red").value,
            score_blue: document.getElementById("live-score-blue").value,
            minutes_elapsed: document.getElementById("live-minutes").value,
            stv: document.getElementById("live-stv").value.trim(),
            players_source: playersSource.value,
            steamids: Array.from(document.querySelectorAll(".live-db-player:checked")).map(c => c.value)
        };
    }

    function showLiveResult(res) {
        const card = document.getElementById("live-result-card");
        card.style.display = "";
        let out = res.message || "(aucun message)";
        if (res.payload) { out += "\n\n--- Équivalent webhook ---\n" + res.payload; }
        if (res.state) { out += "\n\n--- État stocké ---\n" + JSON.stringify(res.state, null, 2); }
        document.getElementById("live-result-output").textContent = out;
    }

    document.getElementById("btn-live-start").addEventListener("click", function () {
        post("/admin/api-test/live/start", liveFormData()).then(function (res) {
            alert(res.message);
            if (res.success) { showLiveResult(res); refreshLiveState(); }
        });
    });

    document.getElementById("btn-live-heartbeat").addEventListener("click", function () {
        post("/admin/api-test/live/heartbeat", { server: document.getElementById("live-server").value.trim() })
            .then(function (res) { alert(res.message); if (res.success) { refreshLiveState(); } });
    });

    document.getElementById("btn-live-end").addEventListener("click", function () {
        post("/admin/api-test/live/end", { server: document.getElementById("live-server").value.trim() })
            .then(function (res) { alert(res.message); if (res.success) { refreshLiveState(); } });
    });

    document.getElementById("btn-live-purge").addEventListener("click", function () {
        if (!confirm("Purger TOUT le cache des mixs en cours ?")) { return; }
        post("/admin/api-test/live/purge").then(function (res) { alert(res.message); if (res.success) { refreshLiveState(); } });
    });

    function refreshLiveState() {
        fetch("/api/live-matches").then(r => r.json()).then(function (json) {
            const box = document.getElementById("live-state-box");
            const rows = json.data || [];
            if (rows.length === 0) {
                box.innerHTML = '<p class="admin-empty">Aucun mix en cours actuellement.</p>';
                return;
            }
            box.innerHTML = rows.map(function (m) {
                return '<p><span class="status-badge status-success"><i class="fa-solid fa-circle" style="font-size: 8px;"></i> LIVE</span> '
                    + '<a href="/live/' + encodeURIComponent(m.server) + '" target="_blank"><b>' + m.server + '</b></a>'
                    + ' — ' + m.map + ' · RED ' + m.scores.red + ' – ' + m.scores.blue + ' BLU'
                    + ' · ' + m.players.length + ' joueurs</p>';
            }).join("");
        });
    }

    // ── Onglet ETF2L ──
    document.getElementById("btn-etf2l-create").addEventListener("click", function () {
        post("/admin/api-test/etf2l", {
            match_id: document.getElementById("etf2l-id").value,
            team1_name: document.getElementById("etf2l-team1").value,
            team2_name: document.getElementById("etf2l-team2").value,
            competition: document.getElementById("etf2l-comp").value,
            maps: document.getElementById("etf2l-maps").value,
            date_offset_min: document.getElementById("etf2l-offset").value
        }).then(function (res) {
            document.getElementById("etf2l-result").innerHTML =
                '<span class="status-badge ' + (res.success ? "status-success" : "status-error") + '">' + res.message + "</span>";
            if (res.success) { setTimeout(function () { location.reload(); }, 800); }
        });
    });

    function deleteFakeMatch(matchId) {
        if (!confirm("Supprimer le match factice #" + matchId + " ?")) { return; }
        post("/admin/api-test/etf2l/delete", { match_id: matchId }).then(function (res) {
            alert(res.message);
            if (res.success) { location.reload(); }
        });
    }

    document.getElementById("btn-etf2l-delete").addEventListener("click", function () {
        const id = parseInt(document.getElementById("etf2l-id").value, 10);
        if (!id || id < 900000000) { alert("Renseignez un ID factice (≥ 900000000)."); return; }
        deleteFakeMatch(id);
    });

    document.querySelectorAll(".btn-etf2l-del").forEach(function (btn) {
        btn.addEventListener("click", function () { deleteFakeMatch(btn.dataset.matchId); });
    });

    // ── Onglet Twitch ──
    const titleFromMatch = document.getElementById("twitch-title-from-match");
    titleFromMatch.addEventListener("change", function () {
        if (this.value !== "") {
            document.getElementById("twitch-title").value = this.value;
            this.value = "";
        }
    });

    document.getElementById("btn-twitch-simulate").addEventListener("click", function () {
        const loginEl = document.getElementById("twitch-login");
        const login = loginEl.tagName === "SELECT" ? loginEl.value : loginEl.value.trim();

        post("/admin/api-test/twitch", {
            login: login,
            title: document.getElementById("twitch-title").value,
            viewers: document.getElementById("twitch-viewers").value,
            auto_match: document.getElementById("twitch-auto-match").checked,
            replace_all: document.getElementById("twitch-replace-all").checked
        }).then(function (res) {
            document.getElementById("twitch-result-card").style.display = "";
            let out = res.message || "";
            if (Array.isArray(res.candidates)) {
                out += "\n\n--- Candidats examinés (fenêtre ±4h, sans scores) ---";
                out += res.candidates.length === 0
                    ? "\n(aucun — crée un match factice dans la fenêtre, onglet ETF2L)"
                    : "\n" + res.candidates.map(function (c) {
                        return "#" + c.match_id + " " + c.team1 + " vs " + c.team2
                            + " (" + new Date(c.match_date * 1000).toLocaleString() + ")"
                            + (c.matched ? "  ✓ ASSOCIÉ" : "  ✗ pas dans le titre");
                    }).join("\n");
            }
            out += "\n\n" + JSON.stringify(res.state, null, 2);
            document.getElementById("twitch-result-output").textContent = out;
            if (res.success) { refreshTwitchState(); }
        });
    });

    document.getElementById("btn-twitch-reset").addEventListener("click", function () {
        post("/admin/api-test/twitch/reset").then(function (res) {
            alert(res.message);
            if (res.success) { refreshTwitchState(); }
        });
    });

    function refreshTwitchState() {
        fetch("/api/twitch-live").then(r => r.json()).then(function (json) {
            const data = json.data || {};
            const box = document.getElementById("twitch-state-box");

            if (data.stale) { box.innerHTML = '<p class="admin-empty">Cache périmé (> 15 min).</p>'; return; }
            if (!data.channels || data.channels.length === 0) {
                box.innerHTML = '<p class="admin-empty">Aucune chaîne en direct actuellement.</p>';
                return;
            }
            box.innerHTML = data.channels.map(function (c) {
                const assoc = (c.matched_match_ids && c.matched_match_ids.length > 0)
                    ? "· associé au(x) match(s) #" + c.matched_match_ids.join(", #")
                    : "· aucune association";
                return '<p><span class="status-badge status-error"><i class="fa-solid fa-circle" style="font-size: 8px;"></i> LIVE</span> '
                    + '<a href="' + c.url + '" target="_blank"><b>' + c.display_name + "</b></a>"
                    + " — " + c.viewers + ' viewers — « ' + c.title + " » " + assoc + "</p>";
            }).join("");
        });
    }

    // ── Onglet Explorateur ──
    document.getElementById("btn-explorer-fetch").addEventListener("click", function () {
        const url = document.getElementById("explorer-endpoint").value;
        const out = document.getElementById("explorer-output");
        out.textContent = "Chargement…";
        fetch(url)
            .then(function (res) { return res.text(); })
            .then(function (txt) {
                try { out.textContent = JSON.stringify(JSON.parse(txt), null, 2); }
                catch (e) { out.textContent = txt; }
            })
            .catch(function () { out.textContent = "Erreur réseau."; });
    });
})();
</script>
@endsection
