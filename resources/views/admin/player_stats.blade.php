@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')

<div class="admin-back">
    <a href="/admin/dashboard">
        <i class="fa-solid fa-arrow-left"></i> Retour au Panel Admin
    </a>
</div>

<div class="admin-header" style="--accent: #ff6b9d;">
    <h2><i class="fa-solid fa-user-chart"></i> Stats joueur / équipe</h2>
    <p>
        Calculez des statistiques (K/D, DPM, dégâts, soins reçus, winrate) pour un <b>joueur</b>
        à partir de logs logs.tf saisis manuellement, ou pour toute une <b>équipe</b> à partir
        de son roster ETF2L. La récupération et le calcul se font <b>en arrière-plan</b>.
        Ces chiffres permettront de présenter les joueurs/équipes sur les streams des matchs
        Highlander France.
    </p>
</div>

@include('admin.partials.alerts')

{{-- Onglets Joueur / Équipe --}}
<div id="ps-tabs" class="ps-tabs">
    <button type="button" class="ps-tab is-active" data-panel-target="player">
        <i class="fa-solid fa-user"></i> Joueur
    </button>
    <button type="button" class="ps-tab" data-panel-target="team">
        <i class="fa-solid fa-users"></i> Équipe
    </button>
</div>

{{-- Panel Joueur (existant) --}}
<div id="ps-panel-player" class="ps-panel is-active">

    <div class="admin-card">
        <h3 class="admin-card__title">
            <i class="fa-solid fa-bullseye"></i> Paramètres du calcul
        </h3>

        <form id="ps-form" class="admin-form-stack admin-form-stack--wide" style="--accent: #ff6b9d;">

            <div class="form-group">
                <label class="admin-form-label" for="ps-steam">Joueur (SteamID)</label>
                <input type="text" name="steam" id="ps-steam" class="form-control" required
                       placeholder="SteamID64, SteamID3, SteamID2 ou URL steamcommunity.com/profiles/…">
                <span class="form-hint">
                    Formats acceptés : <code>76561198012345678</code>, <code>[U:1:12345678]</code>,
                    <code>STEAM_1:1:6172839</code> ou <code>steamcommunity.com/profiles/76561198012345678</code>.
                </span>
            </div>

            <div class="form-group">
                <span class="admin-form-label">Logs logs.tf (ID ou URL)</span>
                <div id="ps-logs-list"></div>
                <span class="form-hint">
                    Ex : <code>https://logs.tf/12345678</code> ou <code>12345678</code>. Un log par match.
                </span>
            </div>

            <div class="form-group">
                <span class="admin-form-label">Stats à calculer</span>
                <div class="ps-checks">
                    @foreach ($statLabels as $key => $label)
                        <label class="ps-check">
                            <input type="checkbox" name="stats[]" value="{{ $key }}" checked>
                            <span>{{ e($label) }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="ps-actions">
                <button type="submit" id="ps-submit" class="admin-btn admin-btn--primary" style="--accent: #ff6b9d;">
                    <i class="fa-solid fa-calculator"></i> Calculer
                </button>
            </div>

            <div id="ps-error" class="admin-alert admin-alert--error" hidden></div>
        </form>
    </div>

</div>

{{-- Panel Équipe --}}
<div id="ps-panel-team" class="ps-panel" hidden>

    <div class="admin-card">
        <h3 class="admin-card__title">
            <i class="fa-solid fa-shield-halved"></i> Équipe (roster ETF2L)
        </h3>

        <form id="ps-team-form" class="admin-form-stack admin-form-stack--wide" style="--accent: #ff6b9d;">

            <div class="form-group">
                <label class="admin-form-label" for="ps-team-input">Nom de l'équipe (ETFA… autocomplete)</label>
                <input type="text" id="ps-team-input" class="form-control" list="ps-team-list" autocomplete="off"
                       placeholder="Tapez le nom ou le tag d'une équipe ETF2L">
                <datalist id="ps-team-list"></datalist>
            </div>

            <div class="form-group">
                <label class="admin-form-label" for="ps-team-id">Ou ID ETF2L direct</label>
                <input type="number" id="ps-team-id" class="form-control" min="1"
                       placeholder="ex : 15176">
                <span class="form-hint">
                    Sert si l'équipe n'est pas (encore) en base locale — elle sera alors récupérée
                    à la volée depuis l'API ETF2L.
                </span>
            </div>

            <div class="ps-actions">
                <button type="submit" id="ps-team-load" class="admin-btn admin-btn--primary" style="--accent: #ff6b9d;">
                    <i class="fa-solid fa-magnifying-glass"></i> Charger l'équipe
                </button>
                <span id="ps-team-loading" class="ps-team-loading" hidden>
                    <i class="fa-solid fa-spinner ps-spin"></i> Récupération roster / résultats / logs…
                </span>
            </div>

            <div id="ps-team-error" class="admin-alert admin-alert--error" hidden></div>
        </form>

        {{-- Résultat de la préparation (rempli en JS) --}}
        <div id="ps-team-panels"></div>

        <div class="ps-actions" style="margin-top:16px;">
            <button type="button" id="ps-team-submit" class="admin-btn admin-btn--primary" style="--accent: #ff6b9d;" hidden>
                <i class="fa-solid fa-calculator"></i> Calculer les stats de l'équipe
            </button>
        </div>
    </div>

</div>

{{-- Progression du calcul en arrière-plan (joueur + équipe, rempli en JS) --}}
<div id="ps-progress-card" class="admin-card" hidden>
    <h3 class="admin-card__title">
        <i class="fa-solid fa-spinner ps-spin"></i> Calcul en cours
        <span id="ps-progress-timer" class="ps-timer"></span>
    </h3>
    <div class="ps-progress">
        <div class="ps-progress__bar">
            <div class="ps-progress__fill" id="ps-progress-fill"></div>
        </div>
        <div class="ps-progress__text" id="ps-progress-text">Démarrage…</div>
    </div>
    <div id="ps-logs-detail" class="ps-logs-detail"></div>
</div>

{{-- Résultat (rempli en JS) --}}
<div id="ps-result" hidden></div>

@endsection

@push('styles')
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="stylesheet" href="{{ hlfr_asset('/_css/admin_player_stats.css') }}">
@endpush

@push('scripts')
<script>
    window.PS_STAT_LABELS = {!! json_encode($statLabels) !!};
</script>
<script src="{{ hlfr_asset('/_js/admin_player_stats.js') }}" defer></script>
@endpush