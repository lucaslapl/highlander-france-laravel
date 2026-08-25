/**
 * Templates HTML de la page d'administration. CSS inline, zéro dépendance
 * front : évite tout problème de fichiers statiques derrière Passenger.
 */

function esc(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function layout(title, body) {
    return `<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>${esc(title)} — Octave</title>
<style>
    :root {
        --bg: #12141a;
        --panel: #1b1e27;
        --border: #2a2e3d;
        --text: #e6e8ee;
        --muted: #9aa0b0;
        --accent: #5865f2;
        --accent-hover: #4752c4;
        --ok: #3ba55d;
        --warn: #faa61a;
        --err: #ed4245;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        background: var(--bg);
        color: var(--text);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 40px 16px;
    }
    header {
        width: 100%;
        max-width: 760px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 28px;
    }
    h1 { font-size: 1.35rem; }
    h1 span { color: var(--accent); }
    .userbox { display: flex; align-items: center; gap: 10px; color: var(--muted); font-size: .9rem; }
    .userbox img { width: 32px; height: 32px; border-radius: 50%; }
    .card {
        width: 100%;
        max-width: 760px;
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 22px;
        margin-bottom: 18px;
    }
    .card h2 { font-size: 1rem; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 16px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
    .stat { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 14px; }
    .stat .label { font-size: .78rem; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; }
    .stat .value { font-size: 1.25rem; font-weight: 600; word-break: break-all; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: .82rem; font-weight: 600; }
    .badge-ok { background: rgba(59,165,93,.15); color: var(--ok); }
    .badge-warn { background: rgba(250,166,26,.15); color: var(--warn); }
    .badge-err { background: rgba(237,66,69,.15); color: var(--err); }
    button, .btn {
        display: inline-block;
        background: var(--accent);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 11px 20px;
        font-size: .95rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        transition: background .15s;
    }
    button:hover, .btn:hover { background: var(--accent-hover); }
    button:disabled { opacity: .55; cursor: wait; }
    .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--muted); }
    .btn-ghost:hover { background: var(--bg); color: var(--text); }
    .actions { display: flex; gap: 12px; flex-wrap: wrap; }
    .center { text-align: center; }
    .muted { color: var(--muted); font-size: .9rem; }
    .logo { font-size: 2rem; font-weight: 700; margin-bottom: 8px; }
    .logo span { color: var(--accent); }
    .msg-error { border-left: 3px solid var(--err); padding: 10px 14px; background: rgba(237,66,69,.08); border-radius: 6px; margin: 14px 0; word-break: break-word; }
    footer { margin-top: auto; padding-top: 30px; color: var(--muted); font-size: .8rem; }
</style>
</head>
<body>
${body}
<footer>Octave — bot Discord Highlander France</footer>
</body>
</html>`;
}

export function loginPage({ botConnected }) {
    const status = botConnected
        ? '<span class="badge badge-ok">Bot connecté</span>'
        : '<span class="badge badge-warn">Bot en cours de connexion</span>';

    return layout('Connexion', `
<div class="card center">
    <div class="logo">Oct<span>ave</span></div>
    <p class="muted" style="margin-bottom:6px">Page d'administration du bot Discord Highlander France.</p>
    <p style="margin-bottom:22px">${status}</p>
    <a class="btn" href="/login">Se connecter avec Discord</a>
    <p class="muted" style="margin-top:18px">Accès réservé aux administrateurs du serveur.</p>
</div>`);
}

export function dashboardPage({ session, avatar, csrfToken, data }) {
    const botBadge = data.botConnected
        ? '<span class="badge badge-ok" id="bot-badge">Connecté</span>'
        : '<span class="badge badge-warn" id="bot-badge">Connexion…</span>';

    const pushBadge = data.lastPushOk === null
        ? '<span class="badge badge-warn">Aucun push</span>'
        : (data.lastPushOk
            ? '<span class="badge badge-ok">Succès</span>'
            : '<span class="badge badge-err">Échec</span>');

    return layout('Administration', `
<header>
    <h1>Oct<span>ave</span> — Administration</h1>
    <div class="userbox">
        <img src="${esc(avatar)}" alt="">
        <span>${esc(session.username)}</span>
        <form method="post" action="/logout" style="display:inline">
            <button type="submit" class="btn btn-ghost" style="padding:6px 12px;font-size:.82rem">Déconnexion</button>
        </form>
    </div>
</header>

<div class="card">
    <h2>État du bot</h2>
    <div class="grid">
        <div class="stat"><div class="label">Statut</div><div class="value">${botBadge}</div></div>
        <div class="stat"><div class="label">Serveur Discord</div><div class="value">${esc(data.guildName ?? 'Inconnu')}</div></div>
        <div class="stat"><div class="label">Membres</div><div class="value" id="stat-members">${data.memberCount ?? '—'}</div></div>
        <div class="stat"><div class="label">Intervalle de sync</div><div class="value">${esc(data.syncInterval)}</div></div>
    </div>
</div>

<div class="card">
    <h2>Dernier push vers le site</h2>
    <div class="grid">
        <div class="stat"><div class="label">Résultat</div><div class="value" id="push-badge">${pushBadge}</div></div>
        <div class="stat"><div class="label">Date</div><div class="value" id="push-date">${esc(data.lastPushAt ?? 'Jamais')}</div></div>
        <div class="stat"><div class="label">Détail</div><div class="value" id="push-error" style="font-size:.95rem">${esc(data.lastError ?? '—')}</div></div>
    </div>
</div>

<div class="card">
    <h2>Actions</h2>
    <div class="actions">
        <button type="button" id="sync-btn" data-csrf="${esc(csrfToken)}">Forcer une synchronisation</button>
        <a class="btn btn-ghost" href="/health" target="_blank" rel="noopener">Voir /health</a>
    </div>
    <p class="muted" id="sync-result" style="margin-top:14px"></p>
</div>

<script>
(function () {
    var btn = document.getElementById('sync-btn');
    var result = document.getElementById('sync-result');

    btn.addEventListener('click', function () {
        btn.disabled = true;
        result.textContent = 'Synchronisation en cours…';

        fetch('/admin/sync', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent(btn.dataset.csrf)
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (r) {
            if (r.ok && r.j.ok) {
                result.textContent = 'Synchronisation réussie : ' + (r.j.lastKnownCount ?? '?') + ' membres poussés.';
                refresh();
            } else {
                result.textContent = 'Échec : ' + (r.j.error || ('HTTP ' + r.j.status));
            }
        })
        .catch(function () { result.textContent = 'Erreur réseau.'; })
        .finally(function () { btn.disabled = false; });
    });

    function refresh() {
        fetch('/health').then(function (r) { return r.json(); }).then(function (h) {
            document.getElementById('stat-members').textContent = h.lastKnownCount ?? '—';
            var d = h.lastPushAt ? new Date(h.lastPushAt).toLocaleString('fr-FR') : 'Jamais';
            document.getElementById('push-date').textContent = d;
            var badge = h.lastPushOk === null ? 'Aucun push' : (h.lastPushOk ? 'Succès' : 'Échec');
            var cls = h.lastPushOk === null ? 'badge-warn' : (h.lastPushOk ? 'badge-ok' : 'badge-err');
            document.getElementById('push-badge').innerHTML = '<span class="badge ' + cls + '">' + badge + '</span>';
            document.getElementById('push-error').textContent = h.lastError || '—';
            var b = document.getElementById('bot-badge');
            if (h.discord === 'connected') { b.className = 'badge badge-ok'; b.textContent = 'Connecté'; }
        });
    }

    setInterval(refresh, 30000);
})();
</script>`);
}

export function forbiddenPage(username) {
    return layout('Accès refusé', `
<div class="card center">
    <div class="logo">Oct<span>ave</span></div>
    <h2 style="color:var(--err);margin-bottom:10px">403 — Accès refusé</h2>
    <p class="muted" style="margin-bottom:20px">
        Connecté en tant que <strong>${esc(username)}</strong>, mais ce compte ne dispose
        d'aucun rôle autorisé sur le serveur.
    </p>
    <a class="btn btn-ghost" href="/logout">Se déconnecter</a>
</div>`);
}

export function errorPage(message) {
    return layout('Erreur', `
<div class="card center">
    <div class="logo">Oct<span>ave</span></div>
    <h2 style="margin-bottom:10px">Une erreur est survenue</h2>
    <div class="msg-error">${esc(message)}</div>
    <a class="btn btn-ghost" href="/">Retour</a>
</div>`);
}

export function notFoundPage() {
    return layout('Introuvable', `
<div class="card center">
    <div class="logo">Oct<span>ave</span></div>
    <h2 style="margin-bottom:10px">404 — Page introuvable</h2>
    <a class="btn btn-ghost" href="/">Retour</a>
</div>`);
}
