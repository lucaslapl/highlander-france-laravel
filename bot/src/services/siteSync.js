import { config } from '../config.js';

/**
 * État du dernier push, exposé par /health.
 */
const state = {
    lastKnownCount: null,
    lastPushAt: null,
    lastPushOk: null,
    lastError: null,
};

export function healthState() {
    return { ...state };
}

async function fetchMemberCount(client) {
    const guild = client.guilds.cache.get(config.guildId)
        ?? await client.guilds.fetch(config.guildId);

    return guild.memberCount;
}

// Timeout des appels HTTP : un site injoignable qui « pend » ne doit pas
// laisser la promesse en attente indéfinie (les appels s'empileraient).
const FETCH_TIMEOUT_MS = 10_000;

// Rattrapage d'un push échoué : le site peut être brièvement indisponible
// (redéploiement, micro-coupure réseau). Sans ça, le compteur du site reste
// périmé jusqu'au prochain join/leave ou à la sync périodique (6 h).
const RETRY_DELAYS_MS = [30_000, 60_000];

/** Une seule chaîne de rattrapage à la fois, sinon les essais s'empilent. */
let retrying = false;

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function recordFailure(reason, message) {
    state.lastPushAt = Date.now();
    state.lastPushOk = false;
    state.lastError = message;

    console.error(`[siteSync] Échec du push (${reason}) :`, message);
}

/**
 * Une tentative de push. Renvoie true si le site a accepté le compteur.
 * Ne lève jamais : un site injoignable ne doit pas tuer le bot.
 */
async function pushOnce(client, reason) {
    try {
        const count = await fetchMemberCount(client);

        const response = await fetch(config.siteWebhookUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                token: config.siteWebhookToken,
                member_count: count,
                guild_id: config.guildId,
            }),
            signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
        });

        // Corps consommé dans tous les cas, sinon undici garde la connexion.
        await response.text();

        state.lastPushAt = Date.now();
        state.lastKnownCount = count;

        if (!response.ok) {
            state.lastPushOk = false;
            state.lastError = `HTTP ${response.status}`;

            console.error(`[siteSync] Push refusé par le site (${reason}) : HTTP ${response.status}`);

            return false;
        }

        state.lastPushOk = true;
        state.lastError = null;

        console.log(`[siteSync] Compteur poussé (${reason}) : ${count} membres`);

        return true;
    } catch (error) {
        recordFailure(reason, error.message);

        return false;
    }
}

/**
 * Pousse le compteur de membres vers le site.
 * En cas d'échec, deux tentatives de rattrapage espacées de 30 s puis 60 s ;
 * le prochain event ou la sync périodique rattrape ensuite. Le déclenchement
 * manuel (bouton du panel) ne rejoue pas : la requête HTTP ne doit pas
 * traîner, et l'admin peut simplement recliquer.
 */
export async function pushMemberCount(client, reason = 'manual') {
    if (await pushOnce(client, reason)) {
        return;
    }

    if (reason === 'manual' || retrying) {
        return;
    }

    retrying = true;

    try {
        for (const delay of RETRY_DELAYS_MS) {
            console.log(`[siteSync] Rattrapage (${reason}) dans ${Math.round(delay / 1000)} s…`);

            await sleep(delay);

            if (await pushOnce(client, reason)) {
                return;
            }
        }
    } finally {
        retrying = false;
    }
}

let timer = null;

/**
 * Push débounce : en cas de rafale de join/leave (raid), au plus un push
 * par MIN_PUSH_INTERVAL_MS, avec le compte final.
 */
export function requestPush(client, reason = 'debounce') {
    if (timer !== null) {
        return;
    }

    const elapsed = Date.now() - (state.lastPushAt ?? 0);
    const delay = Math.max(0, config.minPushIntervalMs - elapsed);

    timer = setTimeout(() => {
        timer = null;
        void pushMemberCount(client, reason);
    }, delay);
}
