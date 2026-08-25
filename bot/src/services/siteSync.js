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

/**
 * Pousse le compteur de membres vers le site.
 * Ne lève jamais : un site injoignable ne doit pas tuer le bot,
 * le prochain event ou la sync périodique rattrapera.
 */
export async function pushMemberCount(client, reason = 'manual') {
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
        });

        state.lastPushAt = Date.now();
        state.lastKnownCount = count;

        if (!response.ok) {
            state.lastPushOk = false;
            state.lastError = `HTTP ${response.status}`;

            console.error(`[siteSync] Push refusé par le site (${reason}) : HTTP ${response.status}`);

            return;
        }

        state.lastPushOk = true;
        state.lastError = null;

        console.log(`[siteSync] Compteur poussé (${reason}) : ${count} membres`);
    } catch (error) {
        state.lastPushAt = Date.now();
        state.lastPushOk = false;
        state.lastError = error.message;

        console.error(`[siteSync] Échec du push (${reason}) :`, error.message);
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
