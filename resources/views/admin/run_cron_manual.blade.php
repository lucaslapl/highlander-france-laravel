@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')

<div class="admin-back">
    <a href="/admin/dashboard">
        <i class="fa-solid fa-arrow-left"></i> Retour au Panel Admin
    </a>
</div>

<div class="admin-header" style="--accent: #f39c12;">
    <h2><i class="fa-solid fa-gears"></i> Console d'Exécution des Tâches CRON</h2>
    <p>Sélectionnez et forcez l'exécution de l'un des scripts automatisés du serveur.</p>
    <p><b>NE PAS UTILISER SAUF URGENCE OU SANS Y AVOIR ÉTÉ INVITÉ.</b></p>
</div>

<div class="admin-card">
    <form action="/admin/run-cron-manual" method="POST" class="flex flex-column">
        @csrf

        <label for="cron_action" class="admin-form-label">
            Sélectionner l'opération à lancer :
        </label>

        <select name="cron_action" id="cron_action" class="cron-select" required>
            <option value="" disabled selected>-- Choisir un script --</option>
            <option value="etf2l_matches" {{ $selectedAction === 'etf2l_matches' ? 'selected' : '' }}>Récupération des matchs ETF2L FR (sync_etf2l.php)</option>
            <option value="etf2l_backfill" {{ $selectedAction === 'etf2l_backfill' ? 'selected' : '' }}>Backfill historique ETF2L 180 jours (backfill_etf2l_history.php)</option>
            <option value="index_stats" {{ $selectedAction === 'index_stats' ? 'selected' : '' }}>Mise à jour des stats de la page d'accueil (update_index_stats.php)</option>
            <option value="match_stats" {{ $selectedAction === 'match_stats' ? 'selected' : '' }}>Mise à jour des stats de match pour les joueurs (update_stats.php)</option>
            <option value="sync_with_steam" {{ $selectedAction === 'sync_with_steam' ? 'selected' : '' }}>Synchronisation avec Steam (sync_steam.php)</option>
            <option value="generate_json" {{ $selectedAction === 'generate_json' ? 'selected' : '' }}>Génération du fichier JSON (leaderboard) (generate_json.php)</option>
            <option value="sync_steam_avatars" {{ $selectedAction === 'sync_steam_avatars' ? 'selected' : '' }}>Synchronisation avec Steam (en cas de profils cassés) (sync_steam_avatars.php)</option>
            <option value="backfill_log_dates" {{ $selectedAction === 'backfill_log_dates' ? 'selected' : '' }}>Backfill des dates de matchs (backfill_log_dates.php)</option>
            <option value="migrate_player_match_stats" {{ $selectedAction === 'migrate_player_match_stats' ? 'selected' : '' }}>Migration des stats de match (migrate_player_match_stats.php)</option>
            <option value="backfill_player_match_stats" {{ $selectedAction === 'backfill_player_match_stats' ? 'selected' : '' }}>Backfill des stats de match (backfill_player_match_stats.php)</option>
            <option value="backfill_match_teams" {{ $selectedAction === 'backfill_match_teams' ? 'selected' : '' }}>Backfill des équipes et scores de match (backfill_match_teams.php)</option>
        </select>

        <div>
            <button type="submit" name="trigger_cron" class="admin-btn admin-btn--primary" style="--accent: #f39c12;">
                <i class="fa-solid fa-play"></i> Lancer le script sélectionné
            </button>
        </div>
    </form>

    @if ($executed)
        <hr style="border: 0; border-top: 1px solid #333; margin: 30px 0 20px 0;">

        <h3>Résultat d'exécution : <span style="font-family: monospace; color: #f39c12;">{!! e($availableScripts[$selectedAction] ?? '') !!}</span></h3>

        @if ($returnStatus === 0)
            <span class="status-badge status-success"><i class="fa-solid fa-check"></i> SUCCÈS (Code 0)</span>
        @else
            <span class="status-badge status-error"><i class="fa-solid fa-triangle-exclamation"></i> ÉCHEC (Code {{ (int)$returnStatus }})</span>
        @endif

        <div class="terminal-box">{!! e($output) !!}</div>
    @endif
</div>
@endsection
