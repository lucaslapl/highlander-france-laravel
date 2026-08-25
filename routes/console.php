<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;

// ─── CRONTAB Highlander France ──────────────────────────────────────────────
// Une seule entrée crontab est nécessaire en production :
//   * * * * * cd /chemin/vers/highlander-france-laravel && php artisan schedule:run >> /dev/null 2>&1
//
// Les backfills/migrations restent volontairement non programmés
// (opérations ponctuelles) : à lancer à la main via le panel admin
// (/admin/run-cron-manual) ou `php artisan app:backfill-*`.

// Statistiques des matchs joueurs — déclenchées en temps réel par le webhook
// de fin de match (plugin hlfr_match_log). Le CRON ci-dessous ne sert plus que
// de filet de sécurité (toutes les 3 h) si un webhook est manqué.
Schedule::command('app:update-stats')->everyThreeHours();

// Stats de la page d'accueil (filet de sécurité).
Schedule::command('app:update-index-stats')->everyThreeHours();

// Caches JSON du classement (leaderboard) (filet de sécurité).
Schedule::command('app:generate-json')->everyThreeHours();

// Agenda des matchs ETF2L français.
Schedule::command('app:sync-etf2l')->everyThirtyMinutes();

// Chaînes Twitch en direct (badge "EN DIRECT" sur les matchs streamés).
Schedule::command('app:sync-twitch')->everyMinute();

// Import des profils Steam manquants.
Schedule::command('app:sync-steam')->hourly();

// Réparation des profils Steam cassés (avatars/pseudos vides).
Schedule::command('app:sync-steam-avatars')->everySixHours();
