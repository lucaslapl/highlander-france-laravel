import { Events } from 'discord.js';
import { requestPush } from '../services/siteSync.js';

export default {
    name: Events.GuildMemberAdd,
    async execute(member) {
        requestPush(member.client, 'join');
    },
};
