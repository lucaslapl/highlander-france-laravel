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
    <h2><i class="fa-solid fa-user-chart"></i> Stats joueur</h2>
    <p>
        Calculez des statistiques (K/D, DPM, dégâts, soins reçus, winrate) pour un joueur
        à partir de <b>logs logs.tf</b> saisis manuellement. La récupération et le calcul
        se font <b>en arrière-plan</b>. Ces chiffres permettront de présenter les joueurs
        sur les streams des matchs Highlander France.
    </p>
</div>

@include('admin.partials.alerts')

{{-- Formulaire de saisie --}}
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

{{-- Progression du calcul en arrière-plan --}}
<div id="ps-progress-card" class="admin-card" hidden>
    <h3 class="admin-card__title">
        <i class="fa-solid fa-spinner ps-spin"></i> Calcul en cours
    </h3>
    <div class="ps-progress">
        <div class="ps-progress__bar">
            <div class="ps-progress__fill" id="ps-progress-fill"></div>
        </div>
        <div class="ps-progress__text" id="ps-progress-text">Démarrage…</div>
    </div>
</div>

{{-- Résultat (rempli en JS) --}}
<div id="ps-result" hidden></div>

@endsection

@push('styles')
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="stylesheet" href="{{ hlfr_asset('/_css/admin_player_stats.css') }}">
@endpush

@push('scripts')
<script src="{{ hlfr_asset('/_js/admin_player_stats.js') }}" defer></script>
@endpush