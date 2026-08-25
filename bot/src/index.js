import http from 'node:http';
import { readdirSync } from 'node:fs';
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { createRequestHandler } from './web/server.js';

/**
 * Bootstrap « incassable » : le serveur HTTP démarre avec les seuls modules
 * natifs de Node, puis chaque étape (dotenv, config, discord.js, events,
 * login) est chargée dynamiquement et isolée. Le moindre échec est reporté
 * par /health au lieu de tuer le process (Passenger renverrait un 500 muet).
 */
const __dirname = path.dirname(fileURLToPath(import.meta.url));

const boot = {
    stage: 'http',
    errors: [],
};

// Dépendances remplies progressivement par main() ; le routeur web tolère
// qu'elles soient absentes pendant le démarrage.
const deps = {
    boot,
    config: null,
    client: null,
    pushMemberCount: null,
    readSyncHealth: null,
};

function fail(stage, error) {
    const message = error && error.message ? error.message : String(error);

    boot.stage = stage;
    boot.errors.push(`${stage} : ${message}`);

    console.error(`[bot] Échec à l'étape ${stage} :`, message);
}

let readSyncHealth = null;

const port = Number.parseInt(process.env.PORT ?? '', 10) || 3000;

const server = http.createServer(createRequestHandler(deps));

server.listen(port, () => {
    console.log(`[bot] Serveur HTTP keep-alive en écoute sur le port ${port}`);
});

process.on('unhandledRejection', (reason) => {
    console.error('[bot] Rejet de promesse non géré :', reason);
});

process.on('uncaughtException', (error) => {
    console.error('[bot] Exception non interceptée :', error);
});

async function main() {
    // .env local (optionnel : en prod les variables viennent de l'UI Plesk).
    try {
        const dotenvModule = await import('dotenv');
        const loadEnv = dotenvModule.default?.config ?? dotenvModule.config;

        loadEnv();
    } catch (error) {
        fail('dotenv', error);
    }

    let config;
    let configErrors;

    try {
        boot.stage = 'config';
        ({ config, configErrors } = await import('./config.js'));

        deps.config = config;
    } catch (error) {
        return fail('config', error);
    }

    console.log(
        `[bot] Démarrage — node ${process.version}, guild ${config.guildId || '(non défini)'}, `
        + `webhook ${config.siteWebhookUrl || '(non défini)'}, port ${port}`
    );

    if (configErrors.length > 0) {
        console.error('[config] Démarrage dégradé :');

        for (const error of configErrors) {
            boot.errors.push(error);
            console.error(`[config] - ${error}`);
        }
    }

    let Client;
    let GatewayIntentBits;

    try {
        boot.stage = 'discord.js';
        ({ Client, GatewayIntentBits } = await import('discord.js'));
    } catch (error) {
        return fail('discord.js', error);
    }

    const client = new Client({
        intents: [
            GatewayIntentBits.Guilds,
            GatewayIntentBits.GuildMembers,
        ],
    });

    deps.client = client;

    try {
        boot.stage = 'siteSync';
        ({ healthState: readSyncHealth, pushMemberCount: deps.pushMemberCount } =
            await import('./services/siteSync.js'));

        deps.readSyncHealth = readSyncHealth;
    } catch (error) {
        return fail('siteSync', error);
    }

    try {
        boot.stage = 'events';

        const eventsDir = path.join(__dirname, 'events');

        for (const file of readdirSync(eventsDir).filter((f) => f.endsWith('.js'))) {
            const event = (await import(pathToFileURL(path.join(eventsDir, file)))).default;

            if (event.once) {
                client.once(event.name, (...args) => event.execute(...args));
            } else {
                client.on(event.name, (...args) => event.execute(...args));
            }
        }
    } catch (error) {
        return fail('events', error);
    }

    function shutdown(signal) {
        console.log(`[bot] Arrêt propre (${signal})...`);
        client.destroy();
        server.close(() => process.exit(0));
        setTimeout(() => process.exit(0), 3000).unref();
    }

    process.on('SIGTERM', () => shutdown('SIGTERM'));
    process.on('SIGINT', () => shutdown('SIGINT'));

    if (configErrors.length > 0) {
        return;
    }

    // Login avec retry : une erreur (token invalide, coupure réseau...) ne
    // doit jamais tuer le process sous peine de voir Passenger boucler.
    boot.stage = 'login';

    async function loginWithRetry() {
        try {
            await client.login(config.discordToken);

            console.log('[bot] Login Discord réussi');
        } catch (error) {
            console.error('[bot] Échec du login Discord :', error.message, '— nouvelle tentative dans 60 s');
            setTimeout(() => void loginWithRetry(), 60_000);
        }
    }

    void loginWithRetry();
}

void main();
