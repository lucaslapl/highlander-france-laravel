import { Events } from 'discord.js';
import { requestPush } from '../services/siteSync.js';

export default {
    name: Events.ShardResume,
    async execute(client) {
        // Reconnexion gateway (potentiellement longue) : des membres ont pu
        // partir pendant la déconnexion, le compteur du site est peut-être
        // périmé. Le push passe par le debounce habituel pour ne pas spammer
        // le site en cas de micro-coupures répétées.
        requestPush(client, 'resume');
    },
};
