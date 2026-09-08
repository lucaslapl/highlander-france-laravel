import dotenv from 'dotenv';

dotenv.config();

function intOr(name, fallback) {
    const parsed = Number.parseInt(process.env[name] ?? '', 10);

    return Number.isFinite(parsed) ? parsed : fallback;
}

export const config = {
    discordToken: process.env.DISCORD_TOKEN ?? '',
    guildId: process.env.GUILD_ID ?? '',
    siteWebhookUrl: process.env.SITE_WEBHOOK_URL ?? '',
    siteWebhookToken: process.env.SITE_WEBHOOK_TOKEN ?? '',
    port: intOr('PORT', 3000),
    syncIntervalMinutes: intOr('SYNC_INTERVAL_MINUTES', 360),
    minPushIntervalMs: intOr('MIN_PUSH_INTERVAL_SECONDS', 60) * 1000,

    // Page d'administration (OAuth2 Discord). Absentes = panel désactivé
    // (le bot continue de fonctionner, seule la page web est indisponible).
    oauthClientId: process.env.DISCORD_OAUTH_CLIENT_ID ?? '',
    oauthClientSecret: process.env.DISCORD_OAUTH_CLIENT_SECRET ?? '',
    oauthRedirectUri: process.env.OAUTH_REDIRECT_URI ?? '',
    adminRoleIds: (process.env.DISCORD_ADMIN_ROLE_IDS ?? '')
        .split(',')
        .map((id) => id.trim())
        .filter(Boolean),

    // Annonce automatique de stream Twitch (poll du /api/twitch-live du site).
    twitchLiveApiUrl: process.env.TWITCH_LIVE_API_URL ?? 'https://highlanderfrance.tf/api/twitch-live',
    twitchLiveApiToken: process.env.TWITCH_LIVE_API_TOKEN ?? '',
    streamAnnounceChannelId: process.env.STREAM_ANNOUNCE_CHANNEL_ID ?? '1477355924002701313',
    streamAnnounceMention: process.env.STREAM_ANNOUNCE_MENTION ?? '@everyone',
    streamAnnounceMessage: process.env.STREAM_ANNOUNCE_MESSAGE
        ?? '🔴 **Le stream Highlander France est en direct !**\n\n**{title}**\n👁 {viewers} spectateurs\n\n🔗 {url}',
};

/**
 * Variables manquantes : ne bloque pas le démarrage (Passenger afficherait
 * une erreur générique illisible) mais dégrade l'app, qui le signale
 * clairement via /health.
 */
export const configErrors = Object.entries({
    DISCORD_TOKEN: config.discordToken,
    GUILD_ID: config.guildId,
    SITE_WEBHOOK_URL: config.siteWebhookUrl,
    SITE_WEBHOOK_TOKEN: config.siteWebhookToken,
})
    .filter(([, value]) => value === '')
    .map(([name]) => `Variable d'environnement manquante : ${name}`);
