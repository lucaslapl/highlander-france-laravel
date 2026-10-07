# AGENTS.md

## Project snapshot

Laravel 13 (PHP 8.3+) rewrite of a legacy home-made MVC site for "Highlander France", a francophone competitive Team Fortress 2 community. Production: `highlanderfrance.tf` (MySQL 8) / local dev: WAMP + SQLite.

Three components live in this repo:
- **Laravel website** (repo root) — the main app
- **`bot/`** — "Octave" Discord bot (Node.js ESM, discord.js v14)
- **`plugins/`** — three SourceMod Pawn server plugins (`hlfr_etf2l_rename`, `hlfr_live_match`, `hlfr_match_log`) pushing data via tokenized webhooks

All UI strings, comments, and docblocks are in **French**. Read `README.md`, `bot/README.md`, and `plugins/*/README.md` before touching those areas.

## Local environment

- **Docker is mandatory.** Never run `composer test`, `php artisan`, `vendor/bin/pint`, or `npm` directly on the host. Always use Docker:
  - Tests : `docker compose run --rm test`
  - Dev server : `docker compose up app`
  - Pint : `docker compose run --rm test vendor/bin/pint --test`
  - Build assets : `docker compose run --rm test npm run build`
  - Shell : `docker compose run --rm test sh`

## Architecture rules (do not break these)

- **No Eloquent for domain data.** Business data is accessed via repository classes (`*Repository`, e.g. `PlayerRepository`) using the `DB` query builder facade. `User` is the only Eloquent model. Do not introduce Eloquent models for the domain without being asked.
- **Legacy fidelity is intentional.** Keep legacy table/column names, routes, and URLs. Use the compatibility helpers in `app/Support/helpers.php`: `site_url()`, `e()`, `hlfr_asset()` (versioned asset URLs with `?v=filemtime`), `hlfr_data_path()`.
- **State lives in JSON caches** under `storage/app/hlfr/` (config `hlfr.data_dir`, resolvable via `hlfr_data_path()`). APIs regenerate these caches from the DB on cold deploy ("self-healing"). Always use this directory for app data files — never invent new cache locations.
- **Real-time pipeline is webhook-driven.** Match stats update on `POST /api/server/match-ended` (shared token + IP allowlist, CSRF-exempt). The scheduled commands (`app:update-stats`, etc.) are only safety nets. Don't duplicate or restructure this pipeline.
- **Frontend is vanilla JS/CSS** in `public/_css`, `public/_js`. Vite/Tailwind 4 only compiles `resources/css/app.css`. No JS framework; keep using `hlfr_asset()` for cache busting.
- **Concurrency is guarded** with `flock()` lock files (services/webhooks) and `withoutOverlapping()` (see `routes/console.php`). Any new scheduled task or service must follow the same pattern.
- **OBS overlays are token-URL, session-less pages.** `/overlay/{token}` (match stats) and `/series-overlay/{token}` (series scoreboard) render fully transparent 1920x1080 pages (Blade views in `resources/views/overlay/`, CSS in `public/_css/overlay_*.css`); OBS cannot authenticate, so access relies on a random 16-char hex token and exposes no sensitive data. Self-refresh is done by polling `/version` — each journal event (logs.tf log, manual point, cancellation) bumps the version. Admin tooling for casters/prod lives under `/admin/overlay` behind the `overlay-tools` middleware (admins + caster/prod roles, nothing else of `/admin/*`) — keep that restriction intact.

## Workflow rules

- **Always end a file-modification session with a commit message.** After any session that modifies files, the agent must provide a ready-to-use commit message, written per Conventional Commits (`type(scope): description`, e.g. `feat(overlay): ...`, `fix(bot): ...`, `docs: ...`), in English lowercase like the existing history. The agent drafts the message but never runs `git commit` (nor `git push`) without explicit validation from the maintainer.

## Required verification

Run before finishing any change:
1. `composer test` — PHPUnit suites (SQLite `:memory:`, `FROM_UNIXTIME` shimmed in)
2. `vendor/bin/pint` — Laravel Pint code style
3. `npm run build` — only if resources changed

## Code conventions

- Use `declare(strict_types=1)` and type declarations on every parameter/return in new or edited PHP.
- Follow PSR-12 (Pint defaults): class/namespace `App\`, repositories in `app/Models`, business logic in `app/Services`, cron logic in `app/Services/Crons`, commands in `app/Console/Commands` (`app:*` prefix).
- Write comments/docblocks in French, following the existing style; prefer that to code being opaque or a French comment being omitted.
- Tests are PHPUnit with Pest-style attributes, in `tests/Unit` and `tests/Feature`. Add/extend tests when changing behavior (reference existing suites like `ComputePlayerLevelsServiceTest`).
- Don't commit `storage/app/hlfr/` artifacts, logs, or generated files.

## Security (strict)

- `.env` contains **live production credentials** (APP_KEY, Steam/Twitch API keys, webhook tokens, DB password). NEVER modify `.env`, print its contents, log secrets, or commit them. `.env.example` is the only safe reference.
- Never log or expose webhook tokens, API keys, or the APP_KEY in code, errors, or test fixtures.
- Webhook endpoints are unauthenticated by session but protected by shared token + IP allowlist (`config/hlfr.php`). Preserve that model.
- Externally sourced values (profile links, guide content) get whitelist/validation treatment — keep hardening them; do not weaken it.