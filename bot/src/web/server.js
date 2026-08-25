import * as auth from './auth.js';
import * as views from './views.js';

/**
 * Routeur HTTP de l'app. Reçoit un objet `deps` mutable, rempli au fil du
 * bootstrap par index.js : le routeur doit donc tolérer que certaines
 * dépendances soient encore absentes (démarrage dégradé).
 *
 * deps = {
 *   boot:            { stage, errors },
 *   config:          objet config ou null,
 *   client:          Client Discord ou null,
 *   pushMemberCount: fonction ou null,
 *   readSyncHealth:  fonction ou null,
 * }
 */
export function createRequestHandler(deps) {
    return async (req, res) => {
        try {
            await route(req, res, deps);
        } catch (error) {
            console.error('[web] Erreur inattendue :', error);

            if (!res.headersSent) {
                sendHtml(res, 500, views.errorPage(error.message));
            } else {
                res.end();
            }
        }
    };
}

async function route(req, res, deps) {
    const url = new URL(req.url, 'http://internal');
    const path = url.pathname;

    if (req.method === 'GET' && path === '/health') {
        return health(res, deps);
    }

    if (req.method === 'GET' && path === '/') {
        return home(req, res, deps);
    }

    if (req.method === 'GET' && path === '/login') {
        return login(res, deps);
    }

    if (req.method === 'GET' && path === '/callback') {
        return callback(url, req, res, deps);
    }

    if (req.method === 'POST' && path === '/logout') {
        return logout(req, res, deps);
    }

    if (req.method === 'POST' && path === '/admin/sync') {
        return forceSync(req, res, deps);
    }

    sendHtml(res, 404, views.notFoundPage());
}

function health(res, deps) {
    const healthy = deps.boot.errors.length === 0;

    const payload = {
        status: healthy ? 'ok' : 'degraded',
        stage: deps.boot.stage,
        ...(healthy ? {} : { errors: deps.boot.errors }),
        discord: !deps.client
            ? 'absent'
            : (deps.client.isReady() ? 'connected' : 'connecting'),
        ...(deps.readSyncHealth ? deps.readSyncHealth() : {}),
    };

    sendJson(res, 200, payload);
}

async function home(req, res, deps) {
    const session = auth.getSession(req);

    if (!session) {
        return sendHtml(res, 200, views.loginPage({
            botConnected: Boolean(deps.client?.isReady()),
        }));
    }

    const data = {
        botConnected: Boolean(deps.client?.isReady()),
        guildName: null,
        memberCount: null,
        syncInterval: deps.config
            ? `${deps.config.syncIntervalMinutes} min`
            : '—',
        lastPushAt: null,
        lastPushOk: null,
        lastError: null,
    };

    if (deps.readSyncHealth) {
        const sync = deps.readSyncHealth();

        data.memberCount = sync.lastKnownCount;
        data.lastPushAt = sync.lastPushAt ? new Date(sync.lastPushAt).toLocaleString('fr-FR') : null;
        data.lastPushOk = sync.lastPushOk;
        data.lastError = sync.lastError;
    }

    try {
        const guild = deps.client?.guilds.cache.get(deps.config?.guildId)
            ?? await deps.client?.guilds.fetch(deps.config?.guildId);

        data.guildName = guild?.name ?? null;
    } catch {
        // Guilde pas encore en cache (bot en connexion) : affichage « Inconnu ».
    }

    sendHtml(res, 200, views.dashboardPage({
        session,
        avatar: auth.avatarUrl(session),
        csrfToken: session.csrfToken,
        data,
    }));
}

function login(res, deps) {
    const oauth = oauthConfig(deps);

    if (oauth === null) {
        return sendHtml(res, 503, views.errorPage(
            "La page d'administration n'est pas configurée : variables "
            + 'DISCORD_OAUTH_CLIENT_ID, DISCORD_OAUTH_CLIENT_SECRET et '
            + 'OAUTH_REDIRECT_URI requises.'
        ));
    }

    const state = auth.createLoginState();

    res.writeHead(302, {
        Location: auth.buildAuthorizeUrl(oauth, state),
    });

    res.end();
}

async function callback(url, req, res, deps) {
    const oauth = oauthConfig(deps);

    if (oauth === null) {
        return sendHtml(res, 503, views.errorPage("OAuth2 non configuré."));
    }

    const code = url.searchParams.get('code');
    const state = url.searchParams.get('state');

    if (!auth.consumeLoginState(state)) {
        return sendHtml(res, 400, views.errorPage(
            'State OAuth invalide ou expiré. Relancez la connexion depuis la page d\'accueil.'
        ));
    }

    if (!code) {
        return sendHtml(res, 400, views.errorPage('Aucun code d\'autorisation reçu de Discord.'));
    }

    const accessToken = await auth.exchangeCode(oauth, code);
    const identity = await auth.fetchIdentity(accessToken);

    let allowed = false;
    let denialReason = null;

    try {
        const member = await auth.fetchGuildMember(
            deps.config.discordToken,
            deps.config.guildId,
            identity.id
        );

        if (member === null) {
            denialReason = "Ce compte n'est pas membre du serveur surveillé.";
        } else {
            const ownerId = await guildOwnerId(deps);

            allowed = auth.isAllowedMember(member, ownerId, deps.config.adminRoleIds);
        }
    } catch (error) {
        console.error('[web] Vérification des rôles impossible :', error.message);
        denialReason = 'Vérification des rôles impossible pour le moment.';
    }

    if (!allowed) {
        return sendHtml(res, 403, views.forbiddenPage(identity.username));
    }

    const sessionId = auth.createSession(identity);
    const secure = oauth.redirectUri.startsWith('https');

    res.writeHead(303, {
        Location: '/',
        'Set-Cookie': auth.sessionCookie(sessionId, 12 * 60 * 60, secure),
    });

    res.end();
}

function logout(req, res, deps) {
    auth.destroySession(req);

    const secure = (deps.config?.oauthRedirectUri ?? '').startsWith('https');

    res.writeHead(303, {
        Location: '/',
        'Set-Cookie': auth.clearSessionCookie(secure),
    });

    res.end();
}

async function forceSync(req, res, deps) {
    const session = auth.getSession(req);

    if (!session) {
        return sendJson(res, 401, { ok: false, error: 'Session invalide.' });
    }

    const body = await readBody(req);

    if (body.get('csrf_token') !== session.csrfToken) {
        return sendJson(res, 403, { ok: false, error: 'Jeton CSRF invalide.' });
    }

    if (!deps.client || !deps.pushMemberCount) {
        return sendJson(res, 503, { ok: false, error: 'Bot pas encore prêt.' });
    }

    await deps.pushMemberCount(deps.client, 'manual');

    sendJson(res, 200, { ok: true, ...(deps.readSyncHealth ? deps.readSyncHealth() : {}) });
}

function oauthConfig(deps) {
    const config = deps.config;

    if (!config || !config.oauthClientId || !config.oauthClientSecret || !config.oauthRedirectUri) {
        return null;
    }

    return {
        clientId: config.oauthClientId,
        clientSecret: config.oauthClientSecret,
        redirectUri: config.oauthRedirectUri,
    };
}

async function guildOwnerId(deps) {
    const guild = deps.client?.guilds.cache.get(deps.config.guildId)
        ?? await deps.client?.guilds.fetch(deps.config.guildId);

    return guild?.ownerId ?? null;
}

function readBody(req) {
    return new Promise((resolve, reject) => {
        let raw = '';

        req.on('data', (chunk) => {
            raw += chunk;

            if (raw.length > 10_000) {
                reject(new Error('Corps de requête trop volumineux.'));
                req.destroy();
            }
        });

        req.on('end', () => resolve(new URLSearchParams(raw)));
        req.on('error', reject);
    });
}

function sendJson(res, status, payload) {
    res.writeHead(status, { 'Content-Type': 'application/json' });

    res.end(JSON.stringify(payload));
}

function sendHtml(res, status, html) {
    res.writeHead(status, { 'Content-Type': 'text/html; charset=utf-8' });

    res.end(html);
}
