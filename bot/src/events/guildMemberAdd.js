import { Events } from 'discord.js';
import { config } from '../config.js';
import { requestPush } from '../services/siteSync.js';

export default {
    name: Events.GuildMemberAdd,
    async execute(client, member) {
        // Seule la guilde surveillée compte : Octave peut être invité sur
        // d'autres serveurs sans que leurs arrivées déclenchent de push.
        if (member.guild.id !== config.guildId) {
            return;
        }

        requestPush(client, 'join');
    },
};
