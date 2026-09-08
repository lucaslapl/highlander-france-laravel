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
    <h2><i class="fa-solid fa-link"></i> Logs officiels de ligue</h2>
    <p>
        Récupère et rattache les logs.tf des matchs officiels ETF2L (Highlander 9v9 / 6v6).
        Seuls ces logs alimentent les stats des <a href="/admin/overlays" target="_blank">overlays OBS</a>;
        les amicaux restent visibles sur le site mais hors overlays.
    </p>
</div>

@if (session('success'))
    <div class="admin-alert admin-alert--success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="admin-alert admin-alert--error">{{ session('error') }}</div>
@endif
@if (session('info'))
    <div class="admin-alert admin-alert--info">{{ session('info') }}</div>
@endif

{{-- Bloc : équipe de France / log d'équipe hors match ETF2L --}}
<div class="admin-card">
    <h3 class="admin-card__title">
        <i class="fa-solid fa-flag"></i> Log d'équipe (hors match ETF2L)
        <span class="status-pill" style="--accent: #2ec4b6;">France & autres</span>
    </h3>
    <p class="admin-hint">
        Pour les matchs officiels hors calendrier ETF2L (ex. équipe de France en Nations Cup) :
        choisissez l'équipe, la catégorie et collez l'URL / l'ID logs.tf.
    </p>
    <form action="/admin/ligue-logs/attach-team-log" method="POST" class="admin-form-row" style="--accent: #2ec4b6;">
        @csrf
        <div class="form-group">
            <label class="admin-form-label" for="team-log-team">Équipe</label>
            <select name="team_id" id="team-log-team" class="form-control" required>
                <optgroup label="Équipes ETF2L programmées">
                    @foreach ($teams as $team)
                        <option value="{{ $team['team_id'] }}">{{ e($team['name']) }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Équipes de France">
                    @foreach ($franceTeams as $ft)
                        <option value="{{ $ft['team_id'] }}" data-category="{{ $ft['category'] }}">{{ e($ft['name']) }}</option>
                    @endforeach
                </optgroup>
            </select>
        </div>
        <div class="form-group">
            <label class="admin-form-label" for="team-log-category">Catégorie</label>
            <select name="category" id="team-log-category" class="form-control">
                <option value="9v9">9v9 — Highlander</option>
                <option value="6s">6s — Sixes</option>
            </select>
        </div>
        <div class="form-group form-group--grow">
            <label class="admin-form-label" for="team-log-url">URL / ID logs.tf</label>
            <input type="text" name="log_url" id="team-log-url" class="form-control"
                   placeholder="https://logs.tf/4114752" required>
        </div>
        <div>
            <button type="submit" class="admin-btn admin-btn--primary" style="--accent: #2ec4b6;">
                <i class="fa-solid fa-paperclip"></i> Rattacher
            </button>
        </div>
    </form>
</div>

{{-- Liste des derniers matchs ETF2L terminés --}}
<div class="admin-card">
    <h3 class="admin-card__title">
        <i class="fa-solid fa-trophy"></i> Derniers matchs ETF2L &
        <span class="status-pill" style="--accent: #2ec4b6;">{{ count($matches) }}</span>
    </h3>

    <div class="admin-table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Match</th>
                    <th>Compétition</th>
                    <th>Date</th>
                    <th>Score</th>
                    <th>Logs officiels</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($matches as $match)
                    <tr>
                        <td>
                            <a href="https://etf2l.org/matches/{{ $match['match_id'] }}/" target="_blank" rel="noopener">
                                {{ $match['match_id'] }}
                            </a>
                            <span class="admin-muted"> · {{ e((string) $match['team1_name']) }} vs {{ e((string) $match['team2_name']) }}</span>
                        </td>
                        <td>{{ e((string) $match['competition_name']) }}</td>
                        <td>
                            @if (!empty($match['match_date']))
                                {{ \Carbon\CarbonImmutable::createFromTimestamp((int) $match['match_date'])->format('d/m/Y') }}
                            @else
                                <span class="admin-muted">—</span>
                            @endif
                        </td>
                        <td class="text-center">{{ (int) $match['r1'] }} – {{ (int) $match['r2'] }}</td>
                        <td>
                            @forelse ($match['logs'] as $log)
                                <span class="liga-log-chip {{ $log['blacklisted'] ? 'liga-log-chip--danger' : '' }}">
                                    <a href="https://logs.tf/{{ $log['log_id'] }}" target="_blank" rel="noopener">
                                        #{{ $log['log_id'] }}
                                    </a>
                                    @if ($log['red_score'] !== null)
                                        <span class="liga-log-score">
                                            <span class="liga-red">{{ $log['red_score'] }}</span>–<span class="liga-blue">{{ $log['blue_score'] }}</span>
                                        </span>
                                    @endif
                                    <span class="liga-log-src" title="Source : {{ $log['source'] }}">
                                        {{ $log['source'] === 'auto' ? 'auto' : 'manuel' }}
                                    </span>
                                    @if ($log['blacklisted'])
                                        <span class="liga-log-bl" title="Log blacklisté (exclu des stats)">blacklisté</span>
                                    @endif
                                    <form action="/admin/ligue-logs/detach" method="POST" class="liga-log-form">
                                        @csrf
                                        <input type="hidden" name="log_id" value="{{ $log['log_id'] }}">
                                        <button type="submit" class="liga-log-remove" title="Retirer le log officiel">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </form>
                                </span>
                            @empty
                                <span class="admin-muted">Aucun log</span>
                            @endforelse
                        </td>
                        <td class="text-center">
                            <div class="liga-actions">
                                <form action="/admin/ligue-logs/scrape-match" method="POST">
                                    @csrf
                                    <input type="hidden" name="match_id" value="{{ $match['match_id'] }}">
                                    <button type="submit" class="admin-btn admin-btn--xs" style="--accent: #2ec4b6;">
                                        <i class="fa-solid fa-wand-magic-sparkles"></i> Récupérer d'ETF2L
                                    </button>
                                </form>
                                <form action="/admin/ligue-logs/attach-to-match" method="POST" class="liga-attach">
                                    @csrf
                                    <input type="hidden" name="match_id" value="{{ $match['match_id'] }}">
                                    <input type="text" name="log_url" class="form-control form-control--sm"
                                           placeholder="URL / ID logs.tf" required>
                                    <button type="submit" class="admin-btn admin-btn--xs" style="--accent: #2ec4b6;">
                                        <i class="fa-solid fa-paperclip"></i> Attacher
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@endsection

@push('styles')
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="stylesheet" href="{{ hlfr_asset('/_css/admin_ligue_logs.css') }}">
@endpush

@push('scripts')
<script src="{{ hlfr_asset('/_js/admin_ligue_logs.js') }}" defer></script>
@endpush
