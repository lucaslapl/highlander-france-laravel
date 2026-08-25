<?php

// Configuration métier Highlander France (port de config/app.php de l'ancien MVC).

return [

    // Répertoire des données applicatives : caches JSON, logs CRON, état live.
    // Surcharger avec HLFR_DATA_DIR pour pointer vers un répertoire existant
    // (ex. l'ancien _scripts/ pendant une transition).
    'data_dir' => env('HLFR_DATA_DIR') ?: storage_path('app/hlfr'),

    // Environnement sans bundle CA (WAMP) : vérification SSL désactivée.
    // Doit rester à true en production.
    'curl_verify_ssl' => (bool) env('CURL_VERIFY_SSL', true),

    // Clé API Steam (https://steamcommunity.com/dev/apikey).
    // Toujours lire via config() : après `php artisan config:cache`,
    // env() ne retourne plus rien depuis le code applicatif.
    'steam_api_key' => env('STEAM_API_KEY', ''),

    // Durée minimale d'un log (secondes) : en dessous, blacklist automatique.
    'min_match_length' => (int) env('MIN_MATCH_LENGTH', 300),

    // Token partagé avec le plugin SourceMod hlfr_match_log.
    'server_webhook_token' => env('SERVER_WEBHOOK_TOKEN', ''),

    // IP autorisées pour les webhooks serveurs (séparées par des virgules).
    'server_webhook_allowed_ips' => env('SERVER_WEBHOOK_ALLOWED_IPS', ''),

    // Token partagé avec le bot Discord.
    'discord_webhook_token' => env('DISCORD_WEBHOOK_TOKEN', ''),

    // API Twitch (https://dev.twitch.tv/console/apps, type Website, catégorie Other).
    // Détection des chaînes en direct : badge "EN DIRECT" sur les matchs streamés.
    // twitch_channels : logins minuscules séparés par des virgules.
    'twitch_client_id' => env('TWITCH_CLIENT_ID', ''),
    'twitch_client_secret' => env('TWITCH_CLIENT_SECRET', ''),
    'twitch_channels' => array_values(array_filter(array_map(
        static fn (string $c): string => mb_strtolower(trim($c)),
        explode(',', (string) env('TWITCH_CHANNELS', ''))
    ))),

    // ID du serveur Discord attendu (optionnel).
    'discord_guild_id' => env('DISCORD_GUILD_ID', ''),

    // Nationalités proposées sur le profil (codes -> libellés).
    'countries' => [
        'fr' => 'France',
        'be' => 'Belgique',
        'sw' => 'Suisse',
        'lu' => 'Luxembourg',
        'uk' => 'Royaume-Uni',
        'eu' => 'Europe',
        'al' => 'Algérie',
        'mo' => 'Maroc',
        'tu' => 'Tunisie',
        'ca' => 'Canada',
        'breizh' => 'Bretagne',
    ],

];
