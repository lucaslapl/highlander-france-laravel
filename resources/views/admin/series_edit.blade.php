@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
<div class="admin-header" style="--accent:#4cc46a;">
    <h2><i class="fa-solid fa-trophy"></i> {{ e($series['title']) }}</h2>
    <p>
        {{ strtoupper($series['format']) }} ·
        @if ($series['status'] === 'live')
            <span style="color:#4cc46a; font-weight:600;">SUIVI ACTIF</span>
        @elseif ($series['status'] === 'finished')
            <span style="color:#888;">Terminée</span>
        @else
            <span style="color:#d9a544;">Suivi non lancé</span>
        @endif
        — score de série :
        <strong style="color:#e06c5a;">{{ e($series['teams']['red']['name']) }} {{ (int) $state['score']['red'] }}</strong>
        -
        <strong style="color:#5885a2;">{{ (int) $state['score']['blue'] }} {{ e($series['teams']['blue']['name']) }}</strong>
        @if ($state['finished'])
            @if ($state['winner'] !== null)
                — <strong>{{ e($series['teams'][$state['winner']]['name']) }} remporte la série</strong>
            @else
                — <strong>série terminée, égalité</strong>
            @endif
        @endif
    </p>
</div>

<div style="display:flex; gap:10px; margin-bottom:24px; flex-wrap:wrap;">
    @if ($series['status'] !== 'live')
        <form method="POST" action="/admin/series/{{ $series['token'] }}/status">
            @csrf
            <input type="hidden" name="status" value="live">
            <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-play"></i> Lancer le suivi</button>
        </form>
    @else
        <form method="POST" action="/admin/series/{{ $series['token'] }}/status">
            @csrf
            <input type="hidden" name="status" value="upcoming">
            <button type="submit" class="admin-btn"><i class="fa-solid fa-pause"></i> Mettre en pause</button>
        </form>
        <form method="POST" action="/admin/series/{{ $series['token'] }}/status">
            @csrf
            <input type="hidden" name="status" value="finished">
            <button type="submit" class="admin-btn"><i class="fa-solid fa-flag-checkered"></i> Terminer</button>
        </form>
    @endif
    <form method="POST" action="/admin/series/{{ $series['token'] }}/delete"
          onsubmit="return confirm('Supprimer définitivement cette série ?');">
        @csrf
        <button type="submit" class="admin-btn"><i class="fa-solid fa-trash"></i> Supprimer</button>
    </form>
</div>

@if ($series['status'] === 'live')
    <p style="color:#aaa; font-size:13px;">
        Suivi actif depuis le {{ date('d/m/Y H:i', (int) ($series['started_at'] ?? $series['created_at'])) }} :
        chaque nouveau log logs.tf des deux rosters met à jour les maps automatiquement (vérification chaque minute).
    </p>
@endif

<h3 class="admin-section-title"><i class="fa-solid fa-map"></i> Maps de la série</h3>
<div class="admin-table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Map</th>
                <th>Mode</th>
                <th>Rounds / score</th>
                <th>Statut</th>
                <th>Gagnant</th>
                <th>Logs</th>
                <th>Ajustement manuel</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($state['maps'] as $map)
                <tr>
                    <td>{{ e($map['name']) }}</td>
                    <td>{{ $map['mode'] === 'double' ? 'Double attaque' : 'Log unique' }}</td>
                    <td>
                        {{ (int) $map['rounds']['red'] }} - {{ (int) $map['rounds']['blue'] }}
                        @if ($map['mode'] === 'single' && $map['scores'] !== null)
                            <span style="color:#777;">(score du log : {{ (int) $map['scores']['red'] }}-{{ (int) $map['scores']['blue'] }})</span>
                        @endif
                        @if ($map['golden_cap'])
                            <span style="color:#d9a544;">golden cap en attente</span>
                        @endif
                    </td>
                    <td>
                        @if ($map['status'] === 'decided')
                            <span style="color:#4cc46a;">Décidée</span>
                        @else
                            <span style="color:#888;">En cours</span>
                        @endif
                    </td>
                    <td>
                        @if ($map['winner'] !== null)
                            <span style="color: {{ $map['winner'] === 'red' ? '#e06c5a' : '#5885a2' }};">{{ e($series['teams'][$map['winner']]['name']) }}</span>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @forelse ($map['log_ids'] as $logId)
                            <a href="https://logs.tf/{{ $logId }}" target="_blank">#{{ $logId }}</a>@if (! $loop->last), @endif
                        @empty
                            —
                        @endforelse
                    </td>
                    <td>
                        @if ($map['status'] !== 'decided')
                            <form method="POST" action="/admin/series/{{ $series['token'] }}/point" style="display:inline;">
                                @csrf
                                <input type="hidden" name="map" value="{{ $map['name'] }}">
                                <input type="hidden" name="team" value="red">
                                <input type="hidden" name="note" value="point manuel depuis l'admin">
                                <button type="submit" class="admin-btn" style="color:#e06c5a;">+1 {{ e($series['teams']['red']['name']) }}</button>
                            </form>
                            <form method="POST" action="/admin/series/{{ $series['token'] }}/point" style="display:inline;">
                                @csrf
                                <input type="hidden" name="map" value="{{ $map['name'] }}">
                                <input type="hidden" name="team" value="blue">
                                <input type="hidden" name="note" value="point manuel depuis l'admin">
                                <button type="submit" class="admin-btn" style="color:#5885a2;">+1 {{ e($series['teams']['blue']['name']) }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-clock-rotate-left"></i> Journal des événements</h3>
<p style="color:#777; font-size:13px;">
    Chaque point du score vient d'un événement de ce journal (source logs.tf ou manuelle). Annuler un événement
    le retire du calcul tout en le gardant visible ici : l'historique reste auditable en cas de contestation.
</p>
<div class="admin-table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Heure</th>
                <th>Événement</th>
                <th>Source</th>
                <th>Détails</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse (array_reverse($series['journal'] ?? []) as $event)
                <tr style=" {{ ($event['type'] ?? '') === 'void' ? 'color:#888;' : '' }}">
                    <td>{{ date('H:i:s', (int) ($event['at'] ?? 0)) }}</td>
                    <td>
                        @if (($event['type'] ?? '') === 'log')
                            Log logs.tf #{{ (int) ($event['log_id'] ?? 0) }}
                        @elseif (($event['type'] ?? '') === 'manual')
                            Point manuel
                        @else
                            Annulation
                        @endif
                    </td>
                    <td>{{ e($event['source'] ?? '') }}</td>
                    <td>
                        @if (($event['type'] ?? '') === 'log')
                            {{ e($event['map'] ?? '') }} : {{ (int) ($event['scores']['red'] ?? 0) }}-{{ (int) ($event['scores']['blue'] ?? 0) }}
                            @if (($event['winner'] ?? '') !== '')
                                → {{ e($series['teams'][$event['winner']]['name'] ?? '') }}
                            @else
                                → égalité
                            @endif
                        @elseif (($event['type'] ?? '') === 'manual')
                            +1 {{ e($series['teams'][$event['team']]['name'] ?? '') }} sur {{ e($event['map'] ?? '') }}
                        @else
                            annule l'événement {{ e($event['target'] ?? '') }}
                        @endif
                        @if (($event['note'] ?? '') !== '')
                            <span style="color:#777;">({{ e($event['note']) }})</span>
                        @endif
                    </td>
                    <td>
                        @if (($event['type'] ?? '') !== 'void')
                            <form method="POST" action="/admin/series/{{ $series['token'] }}/void">
                                @csrf
                                <input type="hidden" name="event" value="{{ $event['id'] }}">
                                <button type="submit" class="admin-btn" title="Retire cet événement du calcul du score">
                                    <i class="fa-solid fa-rotate-left"></i> Annuler
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="color:#aaa; font-style:italic;">Aucun événement pour le moment.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<p style="margin-top:24px;">
    <a class="admin-link-btn" href="/admin/series"><i class="fa-solid fa-arrow-left"></i> Retour aux séries</a>
</p>
@endsection
