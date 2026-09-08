<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;

// ─── CRONTAB Highlander France ──────────────────────────────────────────────
// Une seule entrée crontab est nécessaire en production :
//   * * * * * cd /chemin/vers/highlander-france-laravel && php artisan schedule:run >> /dev/null 2>&1
//
// ⚠️ Ne jamais dupliquer cette entrée (ni via Plesk + cron système en
// parallèle) : chaque invocation concurrente ré-exécuterait les tâches dues.
// En second rempart, chaque tâche est protégée par withoutOverlapping() et
// chaque service lourd par un verrou flock (une seule exécution à la fois).
//
// Les backfills/migrations restent volontairement non programmés
// (opérations ponctuelles) : à lancer à la main via le panel admin
// (/admin/run-cron-manual) ou `php artisan app:backfill-*`.

// Statistiques des matchs joueurs — déclenchées en temps réel par le webhook
// de fin de match (plugin hlfr_match_log). Le CRON ci-dessous ne sert plus que
// de filet de sécurité (toutes les 3 h) si un webhook est manqué.
Schedule::command('app:update-stats')->everyThreeHours()->withoutOverlapping(30);

// Stats de la page d'accueil (filet de sécurité).
Schedule::command('app:update-index-stats')->everyThreeHours()->withoutOverlapping(30);

// Caches JSON du classement (leaderboard) (filet de sécurité).
Schedule::command('app:generate-json')->everyThreeHours()->withoutOverlapping(30);

// Agenda des matchs ETF2L français.
Schedule::command('app:sync-etf2l')->everyThirtyMinutes()->withoutOverlapping(120);

// Niveaux réels des joueurs inscrits (division moyenne ETF2L par mode).
Schedule::command('app:compute-player-levels')->weeklyOn(0, '5:00')->withoutOverlapping(60);

// Palmarès ETF2L des joueurs (classements finaux + playoffs).
Schedule::command('app:compute-player-palmares')->weeklyOn(0, '5:30')->withoutOverlapping(60);

// Chaînes Twitch en direct (badge "EN DIRECT" sur les matchs streamés).
Schedule::command('app:sync-twitch')->everyMinute()->withoutOverlapping(10);

// Import des profils Steam manquants.
Schedule::command('app:sync-steam')->hourly()->withoutOverlapping(30);

// Réparation des profils Steam cassés (avatars/pseudos vides).
Schedule::command('app:sync-steam-avatars')->everySixHours()->withoutOverlapping(30);

// Rosters Équipe de France 6v6 et Highlander (badges).
Schedule::command('app:sync-france')->everySixHours()->withoutOverlapping(30);
