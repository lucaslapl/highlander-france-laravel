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
    <h2><i class="fa-solid fa-shuffle"></i> Stats équipes</h2>
    <p>
        Statistiques officielles de deux équipes ETF2L sur leurs
        <b>3 dernières saisons jouées</b>. Les logs.tf manquants sont rattachés
        (auto via la page ETF2L ou manuellement) puis le calcul est relancé
        directement ici. Ce moteur alimentera les futurs overlays.
    </p>
</div>

@include('admin.partials.alerts')

{{-- Formulaire de sélection des deux équipes --}}
<div class="admin-card">
    <h3 class="admin-card__title">
        <i class="fa-solid fa-people-group"></i> Équipes
    </h3>
    <form action="/admin/stats-duel/run" method="POST" class="admin-form-row" style="--accent: #2ec4b6;">
        @csrf
        <div class="form-group">
            <label class="admin-form-label" for="sd-team1">Équipe 1 (ID ETF2L)</label>
            <input type="number" name="team_1" id="sd-team1" class="form-control" min="1" required
                   list="sd-teams" value="{{ $form['t1'] ?: '' }}" placeholder="Ex : 15176">
        </div>
        <div class="form-group">
            <label class="admin-form-label" for="sd-mode1">Mode équipe 1</label>
            <select name="mode_1" id="sd-mode1" class="form-control">
                <option value="" {{ $form['mode1'] === '' ? 'selected' : '' }}>Auto</option>
                <option value="9v9" {{ $form['mode1'] === '9v9' ? 'selected' : '' }}>9v9 — Highlander</option>
                <option value="6s" {{ $form['mode1'] === '6s' ? 'selected' : '' }}>6s — Sixes</option>
            </select>
        </div>
        <div class="form-group">
            <label class="admin-form-label" for="sd-team2">Équipe 2 (ID ETF2L)</label>
            <input type="number" name="team_2" id="sd-team2" class="form-control" min="1" required
                   list="sd-teams" value="{{ $form['t2'] ?: '' }}" placeholder="Ex : 332">
        </div>
        <div class="form-group">
            <label class="admin-form-label" for="sd-mode2">Mode équipe 2</label>
            <select name="mode_2" id="sd-mode2" class="form-control">
                <option value="" {{ $form['mode2'] === '' ? 'selected' : '' }}>Auto</option>
                <option value="9v9" {{ $form['mode2'] === '9v9' ? 'selected' : '' }}>9v9 — Highlander</option>
                <option value="6s" {{ $form['mode2'] === '6s' ? 'selected' : '' }}>6s — Sixes</option>
            </select>
        </div>
        <datalist id="sd-teams">
            @foreach ($teamOptions as $team)
                <option value="{{ (int) $team['team_id'] }}">{{ e((string) $team['name']) }}</option>
            @endforeach
        </datalist>
        <div>
            <button type="submit" class="admin-btn admin-btn--primary" style="--accent: #2ec4b6;">
                <i class="fa-solid fa-calculator"></i> Calculer
            </button>
        </div>
    </form>
</div>

@if ($error !== null)
    <div class="admin-card">
        <p style="color: #e74c3c;"><i class="fa-solid fa-triangle-exclamation"></i> {{ e($error) }}</p>
    </div>
@endif

@if ($teamA !== null || $teamB !== null)
    <div class="sd-grid">
        @if ($teamA !== null)
            @php($team = $teamA)
            @include('admin.partials.stats_duel_team')
        @endif
        @if ($teamB !== null)
            @php($team = $teamB)
            @include('admin.partials.stats_duel_team')
        @endif
    </div>
@endif

{{-- Gestion des équipes blacklistées --}}
<div class="admin-card">
    <h3 class="admin-card__title">
        <i class="fa-solid fa-ban"></i> Équipes blacklistées
        <span class="status-pill" style="--accent: #e74c3c;">{{ count($blacklistedTeams) }}</span>
    </h3>
    <p class="admin-hint">
        Une équipe blacklistée ne compte plus : ses logs sont exclus des stats, même
        si elle apparaît dans la fenêtre de l'équipe d'en face.
    </p>
    <form action="/admin/stats-duel/blacklist-team" method="POST" class="admin-form-row" style="--accent: #e74c3c;">
        @csrf
        <div class="form-group">
            <label class="admin-form-label" for="sdb-team">ID équipe ETF2L</label>
            <input type="number" name="team_id" id="sdb-team" class="form-control" min="1" required
                   list="sd-teams" placeholder="Ex : 100">
        </div>
        <div class="form-group form-group--grow">
            <label class="admin-form-label" for="sdb-reason">Raison (facultatif)</label>
            <input type="text" name="reason" id="sdb-reason" class="form-control" placeholder="Ex : Équipe non française">
        </div>
        <div>
            <button type="submit" class="admin-btn admin-btn--danger" style="--accent: #e74c3c;">
                <i class="fa-solid fa-ban"></i> Blacklister
            </button>
        </div>
    </form>

    @if ($blacklistedTeams === [])
        <p class="admin-hint" style="margin-bottom: 0;">Aucune équipe blacklistée.</p>
    @else
        <div class="admin-table-scroll">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Équipe</th>
                        <th>ID</th>
                        <th>Raison</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($blacklistedTeams as $blTeam)
                        <tr>
                            <td>{{ e($blTeam['name']) }}</td>
                            <td>
                                <a href="https://etf2l.org/tf2/team/{{ (int) $blTeam['team_id'] }}/" target="_blank" rel="noopener" class="admin-mono">
                                    {{ (int) $blTeam['team_id'] }}
                                </a>
                            </td>
                            <td style="color: #ccc;">{{ e($blTeam['reason'] ?: '—') }}</td>
                            <td class="text-center">
                                <form action="/admin/stats-duel/unblacklist-team" method="POST"
                                      onsubmit="return confirm('Retirer cette équipe de la blacklist ?');">
                                    @csrf
                                    <input type="hidden" name="team_id" value="{{ (int) $blTeam['team_id'] }}">
                                    <button type="submit" class="admin-btn admin-btn--success">
                                        <i class="fa-solid fa-rotate-left"></i> Restaurer
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@endsection

@push('styles')
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="stylesheet" href="{{ hlfr_asset('/_css/admin_stats_duel.css') }}">
@endpush

@push('scripts')
<script src="{{ hlfr_asset('/_js/admin_stats_duel.js') }}" defer></script>
@endpush
