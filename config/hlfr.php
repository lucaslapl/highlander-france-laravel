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

    // Liens externes renseignés volontairement par les joueurs sur leur profil.
    // 'domains' : whitelist de domaines autorisés pour valider l'URL saisie.
    // Le champ discord_tag est un pseudo (pas une URL), d'où 'type' => 'tag'.
    'profile_links' => [
        'etf2l_url' => [
            'label' => 'Profil ETF2L',
            'icon' => 'fa-solid fa-shield-halved',
            'type' => 'url',
            'domains' => ['etf2l.org'],
            'placeholder' => 'https://etf2l.org/forum/user/12345/',
        ],
        'rgl_url' => [
            'label' => 'Profil RGL.gg',
            'icon' => 'fa-solid fa-trophy',
            'type' => 'url',
            'domains' => ['rgl.gg'],
            'placeholder' => 'https://rgl.gg/Public/PlayerProfile?p=76561198012345678',
        ],
        'logstf_url' => [
            'label' => 'Profil logs.tf',
            'icon' => 'fa-solid fa-chart-line',
            'type' => 'url',
            'domains' => ['logs.tf'],
            'placeholder' => 'https://logs.tf/profile/76561198012345678',
        ],
        'twitch_url' => [
            'label' => 'Chaîne Twitch',
            'icon' => 'fa-brands fa-twitch',
            'type' => 'url',
            'domains' => ['twitch.tv'],
            'placeholder' => 'https://www.twitch.tv/pseudo',
        ],
        'x_url' => [
            'label' => 'Compte X / Twitter',
            'icon' => 'fa-brands fa-x-twitter',
            'type' => 'url',
            'domains' => ['x.com', 'twitter.com'],
            'placeholder' => 'https://x.com/pseudo',
        ],
        'youtube_url' => [
            'label' => 'Chaîne YouTube',
            'icon' => 'fa-brands fa-youtube',
            'type' => 'url',
            'domains' => ['youtube.com', 'youtu.be'],
            'placeholder' => 'https://www.youtube.com/@pseudo',
        ],
        'discord_tag' => [
            'label' => 'Pseudo Discord',
            'icon' => 'fa-brands fa-discord',
            'type' => 'tag',
            'max_length' => 64,
            'placeholder' => 'pseudo',
        ],
    ],

    // Matériel renseigné volontairement par les joueurs sur leur profil.
    'profile_gear' => [
        'gear_keyboard' => ['label' => 'Clavier', 'icon' => 'fa-solid fa-keyboard'],
        'gear_mouse' => ['label' => 'Souris', 'icon' => 'fa-solid fa-computer-mouse'],
        'gear_monitor' => ['label' => 'Écran', 'icon' => 'fa-solid fa-desktop'],
    ],

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
