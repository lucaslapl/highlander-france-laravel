import crypto from 'node:crypto';

/**
 * Authentification de la page d'administration par OAuth2 Discord.
 *
 * - Sessions en mémoire (un redémarrage du bot déconnecte les admins) ;
 * - `state` OAuth à usage unique et durée limitée contre le CSRF du flux ;
 * - Vérification des droits côté serveur : l'utilisateur doit porter un des
 *   rôles listés dans DISCORD_ADMIN_ROLE_IDS, ou être propriétaire de la
 *   guilde si la liste est vide. Le contrôle utilise le token du bot
 *   (server-to-server), il ne peut pas être falsifié depuis le navigateur.
 */

const SESSION_COOKIE = 'octave_session';
const SESSION_TTL_MS = 12 * 60 * 60 * 1000;
const STATE_TTL_MS = 10 * 60 * 1000;

/** @type {Map<string, object>} id => session */
const sessions = new Map();

/** @type {Map<string, number>} state => expiration */
const loginStates = new Map();

export function createLoginState() {
    const state = crypto.randomBytes(24).toString('hex');

    loginStates.set(state, Date.now() + STATE_TTL_MS);

    return state;
}

export function consumeLoginState(state) {
    if (typeof state !== 'string' || state === '') {
        return false;
    }

    const expiresAt = loginStates.get(state);

    loginStates.delete(state);

    return typeof expiresAt === 'number' && expiresAt > Date.now();
}

export function buildAuthorizeUrl(oauthConfig, state) {
    const params = new URLSearchParams({
        client_id: oauthConfig.clientId,
        redirect_uri: oauthConfig.redirectUri,
        response_type: 'code',
        scope: 'identify',
        state,
    });

    return `https://discord.com/oauth2/authorize?${params.toString()}`;
}

export async function exchangeCode(oauthConfig, code) {
    const body = new URLSearchParams({
        client_id: oauthConfig.clientId,
        client_secret: oauthConfig.clientSecret,
        grant_type: 'authorization_code',
        code,
        redirect_uri: oauthConfig.redirectUri,
    });

    const response = await fetch('https://discord.com/api/v10/oauth2/token', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    });

    if (!response.ok) {
        throw new Error(`Échange du code d'autorisation refusé (HTTP ${response.status})`);
    }

    const data = await response.json();

    return data.access_token;
}

export async function fetchIdentity(accessToken) {
    const response = await fetch('https://discord.com/api/v10/users/@me', {
        headers: { Authorization: `Bearer ${accessToken}` },
    });

    if (!response.ok) {
        throw new Error(`Identité Discord inaccessible (HTTP ${response.status})`);
    }

    return response.json();
}

/**
 * Renvoie le membre de la guilde correspondant à l'utilisateur, ou null s'il
 * n'en fait pas partie. Utilise le token du BOT, pas celui de l'OAuth.
 */
export async function fetchGuildMember(botToken, guildId, userId) {
    const response = await fetch(
        `https://discord.com/api/v10/guilds/${guildId}/members/${userId}`,
        { headers: { Authorization: `Bot ${botToken}` } }
    );

    if (response.status === 404) {
        return null;
    }

    if (!response.ok) {
        throw new Error(`Membre de guilde inaccessible (HTTP ${response.status})`);
    }

    return response.json();
}

export function isAllowedMember(member, guildOwnerId, allowedRoleIds) {
    if (guildOwnerId && member.user?.id === guildOwnerId) {
        return true;
    }

    if (allowedRoleIds.length === 0) {
        return false;
    }

    const roles = Array.isArray(member.roles) ? member.roles : [];

    return roles.some((role) => allowedRoleIds.includes(role));
}

export function createSession(user) {
    const id = crypto.randomBytes(32).toString('hex');

    sessions.set(id, {
        userId: user.id,
        username: user.username,
        avatarHash: user.avatar ?? null,
        csrfToken: crypto.randomBytes(24).toString('hex'),
        createdAt: Date.now(),
        expiresAt: Date.now() + SESSION_TTL_MS,
    });

    return id;
}

export function getSession(request) {
    const sid = parseCookies(request)[SESSION_COOKIE] ?? null;

    if (sid === null) {
        return null;
    }

    const session = sessions.get(sid);

    if (!session) {
        return null;
    }

    if (session.expiresAt < Date.now()) {
        sessions.delete(sid);

        return null;
    }

    return session;
}

export function destroySession(request) {
    const sid = parseCookies(request)[SESSION_COOKIE];

    if (sid !== undefined) {
        sessions.delete(sid);
    }
}

export function sessionCookie(value, maxAgeSeconds, secure = true) {
    const flags = `Path=/; HttpOnly; SameSite=Lax; Max-Age=${maxAgeSeconds}`;

    return `${SESSION_COOKIE}=${value}; ${secure ? 'Secure; ' : ''}${flags}`;
}

export function clearSessionCookie(secure = true) {
    return sessionCookie('', 0, secure);
}

export function avatarUrl(session) {
    if (session.avatarHash === null) {
        return 'https://cdn.discordapp.com/embed/avatars/0.png';
    }

    const ext = session.avatarHash.startsWith('a_') ? 'gif' : 'png';

    return `https://cdn.discordapp.com/avatars/${session.userId}/${session.avatarHash}.${ext}`;
}

function parseCookies(request) {
    const header = request.headers.cookie ?? '';
    const cookies = {};

    for (const part of header.split(';')) {
        const index = part.indexOf('=');

        if (index === -1) {
            continue;
        }

        try {
            cookies[part.slice(0, index).trim()] = decodeURIComponent(part.slice(index + 1).trim());
        } catch {
            // Cookie malformé : ignoré.
        }
    }

    return cookies;
}

// Nettoyage périodique des sessions expirées et des states périmés.
setInterval(() => {
    const now = Date.now();

    for (const [id, session] of sessions) {
        if (session.expiresAt < now) {
            sessions.delete(id);
        }
    }

    for (const [state, expiresAt] of loginStates) {
        if (expiresAt < now) {
            loginStates.delete(state);
        }
    }
}, 10 * 60_000).unref();
