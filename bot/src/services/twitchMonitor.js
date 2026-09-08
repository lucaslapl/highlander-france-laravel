import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { config } from '../config.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, '..', '..', 'data');
const CONFIG_FILE = path.join(DATA_DIR, 'stream_config.json');

const TARGET_LOGIN = 'highlanderfrance';
const POLL_INTERVAL_MS = 60_000;

/**
 * État interne du moniteur, exposé au dashboard.
 */
const monitorState = {
    active: false,
    lastPollAt: null,
    lastPollOk: null,
    lastError: null,
    currentlyLive: false,
    lastAnnouncedTitle: null,
    lastAnnouncedAt: null,
};

/**
 * Config d'annonce persistée (survivant aux redémarrages).
 */
let streamConfig = {
    channelId: config.streamAnnounceChannelId,
    mention: config.streamAnnounceMention,
    message: config.streamAnnounceMessage,
};

function ensureDataDir() {
    if (!existsSync(DATA_DIR)) {
        mkdirSync(DATA_DIR, { recursive: true });
    }
}

function loadStreamConfig() {
    try {
        ensureDataDir();

        if (existsSync(CONFIG_FILE)) {
            const data = JSON.parse(readFileSync(CONFIG_FILE, 'utf-8'));
            streamConfig = { ...streamConfig, ...data };
        }
    } catch {
        // Config corrompue : on garde les valeurs par défaut.
    }
}

function saveStreamConfig() {
    try {
        ensureDataDir();
        writeFileSync(CONFIG_FILE, JSON.stringify(streamConfig, null, 2), 'utf-8');
    } catch (error) {
        console.error('[twitchMonitor] Échec sauvegarde config :', error.message);
    }
}

/**
 * Retourne l'état du moniteur (exposé via /health et le dashboard).
 */
export function getMonitorState() {
    return { ...monitorState };
}

/**
 * Retourne la config d'annonce courante.
 */
export function getStreamConfig() {
    return { ...streamConfig };
}

/**
 * Met à jour la config d'annonce et la persiste.
 */
export function updateStreamConfig(patch) {
    if (typeof patch.channelId === 'string') {
        streamConfig.channelId = patch.channelId;
    }
    if (typeof patch.mention === 'string') {
        streamConfig.mention = patch.mention;
    }
    if (typeof patch.message === 'string') {
        streamConfig.message = patch.message;
    }

    saveStreamConfig();
}

/**
 * Formate le message d'annonce à partir du template et des données du stream.
 */
function formatMessage(template, stream) {
    return template
        .replace(/\{title\}/g, stream.title || 'Stream en cours')
        .replace(/\{viewers\}/g, String(stream.viewers ?? 0))
        .replace(/\{url\}/g, stream.url || 'https://www.twitch.tv/highlanderfrance')
        .replace(/\{channel\}/g, stream.login || TARGET_LOGIN);
}

/**
 * Envoie un message d'annonce dans le salon configuré.
 * Si `testMode` est vrai, le message est préfixé par [TEST].
 * Renvoie true en cas de succès.
 */
async function sendAnnouncement(client, stream, testMode = false) {
    const channelId = streamConfig.channelId;

    if (!channelId) {
        console.error('[twitchMonitor] Aucun salon configuré (STREAM_ANNOUNCE_CHANNEL_ID).');
        return false;
    }

    try {
        const channel = await client.channels.fetch(channelId);

        if (!channel) {
            console.error(`[twitchMonitor] Salon ${channelId} introuvable.`);
            return false;
        }

        const mention = streamConfig.mention || '';
        const body = formatMessage(streamConfig.message, stream);
        let content = mention ? `${mention}\n${body}` : body;

        if (testMode) {
            content = `[TEST] ${content}`;
        }

        await channel.send({ content });

        console.log(`[twitchMonitor] Annonce envoyée dans #${channel.name} : "${stream.title}"`);

        monitorState.lastAnnouncedTitle = stream.title;
        monitorState.lastAnnouncedAt = Date.now();

        return true;
    } catch (error) {
        console.error('[twitchMonitor] Échec envoi annonce :', error.message);
        return false;
    }
}

/**
 * Interroge l'API /api/twitch-live du site et renvoie la liste des chaînes
 * en direct. Met à jour `lastPollOk` / `lastError`. Renvoie [] en cas d'échec.
 */
async function fetchLiveChannels() {
    monitorState.lastPollAt = Date.now();

    try {
        const headers = { 'Accept': 'application/json' };

        if (config.twitchLiveApiToken) {
            headers['Authorization'] = `Bearer ${config.twitchLiveApiToken}`;
        }

        const response = await fetch(config.twitchLiveApiUrl, {
            method: 'GET',
            headers,
            signal: AbortSignal.timeout(10_000),
        });

        if (!response.ok) {
            monitorState.lastPollOk = false;
            monitorState.lastError = `HTTP ${response.status}`;
            console.error(`[twitchMonitor] API refusée : HTTP ${response.status}`);

            return [];
        }

        const body = await response.json();

        monitorState.lastPollOk = true;
        monitorState.lastError = null;

        return body?.data?.channels ?? [];
    } catch (error) {
        monitorState.lastPollOk = false;
        monitorState.lastError = error.message;
        console.error('[twitchMonitor] Erreur de poll :', error.message);

        return [];
    }
}

/**
 * Récupère l'état des streams depuis l'API du site et détecte les transitions.
 */
async function poll(client) {
    const channels = await fetchLiveChannels();

    const targetLive = channels.some(
        (ch) => (ch.login ?? '').toLowerCase() === TARGET_LOGIN
    );

    const wasLive = monitorState.currentlyLive;

    if (targetLive && !wasLive) {
        const stream = channels.find(
            (ch) => (ch.login ?? '').toLowerCase() === TARGET_LOGIN
        );

        if (stream) {
            await sendAnnouncement(client, stream);
        }
    }

    monitorState.currentlyLive = targetLive;
}

/**
 * Démarre le moniteur : charge la config persistée, lance le poll périodique.
 *
 * Le premier poll marque l'état courant SANS announcement pour éviter les
 * fausses annonces au démarrage du bot (un stream en cours depuis un moment
 * ne doit pas déclencher de notification).
 */
export function startTwitchMonitor(client) {
    loadStreamConfig();

    monitorState.active = true;

    console.log(
        `[twitchMonitor] Moniteur démarré — salon ${streamConfig.channelId || '(non défini)'}`
    );

    void poll(client);

    setInterval(() => {
        void poll(client);
    }, POLL_INTERVAL_MS);
}

/**
 * Envoie un message de test dans le salon configuré. Utilise les données
 * réelles du stream s'il est en direct, sinon un fallback « hors ligne ».
 * Le contenu est préfixé par [TEST] pour le distinguer d'une vraie annonce.
 */
export async function sendTestMessage(client) {
    const channels = await fetchLiveChannels();
    const stream = channels.find(
        (ch) => (ch.login ?? '').toLowerCase() === TARGET_LOGIN
    );

    if (stream) {
        return sendAnnouncement(client, stream, true);
    }

    return sendAnnouncement(client, {
        title: 'Test d\'annonce stream — Highlander France (stream hors ligne)',
        viewers: 0,
        url: 'https://www.twitch.tv/highlanderfrance',
        login: TARGET_LOGIN,
    }, true);
}
