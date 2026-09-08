@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')

<div class="admin-back admin-back--split">
    <a href="/admin/dashboard">
        <i class="fa-solid fa-arrow-left"></i> Retour au Panel Admin
    </a>
</div>

<div class="admin-header" style="--accent: #2ec4b6;">
    <h2><i class="fa-solid fa-tv"></i> Overlays OBS</h2>
    <p>
        URLs des overlays à copier dans OBS Studio (source navigateur, rendu statique).
        Ajoutez <code>?bg=transparent</code> pour un fond transparent. Les textes restent lisibles.
    </p>
</div>

{{-- Sélecteur + aperçu --}}
<div class="admin-card">
    <h3 class="admin-card__title">
        <i class="fa-solid fa-eye"></i> Aperçu & URL
    </h3>

    <div class="admin-form-row" style="--accent: #2ec4b6;">
        <div class="form-group">
            <label class="admin-form-label" for="ov-type">Type</label>
            <select id="ov-type" class="form-control">
                <option value="equipe">Équipe (ETF2L / France)</option>
                <option value="match">Match ETF2L</option>
                <option value="joueur">Joueur</option>
                <option value="scoreboard">Scoreboard (log)</option>
            </select>
        </div>
        <div class="form-group form-group--grow" id="ov-target-wrap">
            <label class="admin-form-label" for="ov-target">Cible</label>
            <select id="ov-target" class="form-control"></select>
        </div>
        <div class="form-group" id="ov-category-wrap" hidden>
            <label class="admin-form-label" for="ov-category">Catégorie</label>
            <select id="ov-category" class="form-control">
                <option value="9v9">9v9 — Highlander</option>
                <option value="6s">6s — Sixes</option>
            </select>
        </div>
        <div class="form-group checkbox-group" style="margin-top: 26px;">
            <label class="admin-label">
                <input type="checkbox" id="ov-transparent" value="1">
                Fond transparent
            </label>
        </div>
    </div>

    <p class="admin-hint">URL à coller dans OBS :</p>
    <div class="ov-url-row">
        <code id="ov-url" class="ov-url"></code>
        <button type="button" class="admin-btn admin-btn--primary" id="ov-copy" style="--accent: #2ec4b6;">
            <i class="fa-solid fa-copy"></i> Copier
        </button>
    </div>

    <div class="ov-preview">
        <iframe id="ov-frame" src="about:blank" title="Aperçu de l'overlay" loading="lazy"></iframe>
    </div>
</div>

<script>
window.OV_DATA = {!! json_encode([
    'teams' => $teams,
    'franceTeams' => $franceTeams,
    'players' => $players,
    'matches' => $matches,
    'logs' => $logs,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};
</script>

@endsection

@push('styles')
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="stylesheet" href="{{ hlfr_asset('/_css/admin_overlays.css') }}">
@endpush

@push('scripts')
<script src="{{ hlfr_asset('/_js/admin_overlays.js') }}" defer></script>
@endpush