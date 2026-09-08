import { Events } from 'discord.js';
import { config } from '../config.js';
import { pushMemberCount } from '../services/siteSync.js';
import { startTwitchMonitor } from '../services/twitchMonitor.js';

export default {
    name: Events.ClientReady,
    once: true,
    async execute(client) {
        console.log(`[bot] Connecté en tant que ${client.user.tag}`);

        // Sync initiale : rattrape les changements survenus pendant l'arrêt.
        await pushMemberCount(client, 'startup');

        if (config.syncIntervalMinutes > 0) {
            setInterval(() => {
                void pushMemberCount(client, 'periodic');
            }, config.syncIntervalMinutes * 60_000);
        }

        // Moniteur Twitch : détection des streams en direct pour annonces auto.
        startTwitchMonitor(client);
    },
};
