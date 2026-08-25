# Highlander France — Laravel

Refonte du site MVC maison sous **Laravel 13** (PHP 8.3+, MySQL 8).

> Projet de démonstration — publié à titre de portfolio. Tous droits
> réservés, aucune réutilisation autorisée.

## Composants

- le **site Laravel** (`app/`, `resources/views/`, `routes/`) ;
- deux **plugins SourceMod** (`plugins/`) qui poussent les données via webhooks tokenisés ;
- un **bot Discord « Octave »** (`bot/`, Node.js / discord.js v14).

## Correspondance ancien → nouveau

| Ancien (MVC)                        | Nouveau (Laravel)                                    |
|-------------------------------------|------------------------------------------------------|
| `config/routes.php`                 | `routes/web.php` (URLs strictement identiques)       |
| `config/.env`                       | `.env`                                               |
| `config/app.php` (constantes)       | `config/hlfr.php` + helpers `app/Support/helpers.php`|
| `app/Core/*`                        | Noyau Laravel (Router, Request, View, Database)      |
| `app/Controllers/*`                 | `app/Http/Controllers/*`                             |
| `app/Models/*Repository`            | `app/Models/*Repository` (DB facade, MySQL)          |
| `app/Services/*`                    | `app/Services/*` (port quasi verbatim)               |
| `bin/*.php` (11 scripts CRON)       | Commandes artisan `php artisan app:*`                |
| `deploy/crontab.txt` (6 entrées)    | `routes/console.php` + 1 seule entrée crontab        |
| `_scripts/stats.db` (SQLite)        | MySQL `highlander_france` (+ import via artisan)     |
| `_scripts/*.json` (caches)          | `storage/app/hlfr/`                                  |
| `app/Views/*.php`                   | `resources/views/**/*.blade.php`                     |
| `_css/ _js/ _img/ _fonts/`          | `public/_css/ public/_js/ …` (chemins inchangés)     |
| CSRF maison (`Services/Csrf`)       | CSRF Laravel (`@csrf`, exemptions : `api/server/*`, `api/discord/*`) |
| Sessions PHP natives                | Sessions Laravel (fichiers)                          |

## Installation (développement)

```bash
composer install
cp .env.example .env        # renseigner STEAM_API_KEY, tokens webhooks…
php artisan key:generate
# Créer la base MySQL puis :
php artisan migrate
# Import de l'historique de l'ancienne base SQLite :
php artisan app:import-legacy ../highlander-france-mvc/_scripts/stats.db
php artisan serve           # http://127.0.0.1:8000
```

## Tâches CRON (une seule entrée crontab)

```
* * * * * cd <APP_ROOT> && php artisan schedule:run >> /dev/null 2>&1
```

Planification détaillée dans `routes/console.php`. Les backfills ponctuels
restent manuels : panel admin (`/admin/run-cron-manual`) ou :

```bash
php artisan app:sync-etf2l              # agenda ETF2L
php artisan app:update-stats            # stats logs.tf
php artisan app:generate-json           # caches leaderboard
php artisan app:backfill-etf2l-history  # historique 180 jours
php artisan app:import-legacy <stats.db>
```
