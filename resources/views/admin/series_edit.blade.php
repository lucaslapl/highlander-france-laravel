@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

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
@elseif ($series['status'] !== 'finished')
    <p style="color:#d9a544; font-size:13px;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        Le suivi n'est pas lancé : l'overlay n'est pas actif et aucun log n'est récupéré.
        Cliquez sur « Lancer le suivi » pour que l'overlay se mette à jour et que la récupération des logs soit effective.
    </p>
@endif

<h3 class="admin-section-title"><i class="fa-solid fa-trophy"></i> Équipes</h3>
@include('admin.partials.etf2l_teams', ['competitions' => $competitions, 'prefix' => 'series'])
<div style="border:2px solid #4cc46a; border-radius:10px; padding:16px; margin-bottom:16px; background:rgba(76,196,106,0.08);">
    <h4 style="margin:0 0 8px;"><i class="fa-solid fa-right-left"></i> L'affichage des logs est inversé ?</h4>
    <p style="margin:0 0 12px; font-size:14px; line-height:1.5;">
        Si les noms d'équipe et avatars apparaissent du mauvais côté par rapport aux logs, ce bouton échange
        <strong>uniquement les noms et les avatars</strong> des équipes A et B — les rosters ne bougent pas (ce sont
        eux qui alignent les logs sur les équipes), le score reste sur son côté, et l'overlay se rafraîchit
        immédiatement dans OBS.
    </p>
    <form method="POST" action="/admin/series/{{ $series['token'] }}/swap"
          onsubmit="return confirm('Intervertir les équipes A et B (noms et avatars) ?');">
        @csrf
        <button type="submit" class="admin-btn admin-btn--primary">
            <i class="fa-solid fa-right-left"></i> Intervertir les équipes A / B
        </button>
    </form>
</div>
<p style="color:#aaa; font-size:13px;">
    Les noms s'affichent tels quels sur l'overlay : utilisez l'acronyme de l'équipe (ex : Inglorious Gamblers → IG),
    l'espace y est très réduit. Modifiables à tout moment, y compris après la création (erreur d'acronyme, alias).
    La couleur (A = côté gauche de l'overlay, B = côté droit) n'a aucune importance sur le match : elle ne fixe
    que l'ordre d'affichage, les logs font ensuite le travail.
</p>
<p style="color:#aaa; font-size:13px;">
    La liaison ETF2L (remplissage assisté ci-dessus) sert de référence aux rosters : elle permet de rafraîchir les
    rosters des deux équipes d'un clic — transferts entre saisons, joueur ajouté en cours de saison — sans ressaisir
    les SteamIDs à la main. Sans liaison, les rosters saisis à la création restent valables tels quels.
</p>
<form method="POST" action="/admin/series/{{ $series['token'] }}/rosters" style="margin:0 0 14px;"
      onsubmit="return confirm('Remplacer les rosters des deux équipes par leurs rosters ETF2L actuels ?');">
    @csrf
    <button type="submit" class="admin-btn"
            title="Récupère les SteamIDs actuels des deux équipes depuis ETF2L (nécessite la liaison des équipes via le remplissage assisté).">
        <i class="fa-solid fa-arrows-rotate"></i> Rafraîchir les rosters depuis ETF2L
    </button>
</form>
<form method="POST" action="/admin/series/{{ $series['token'] }}/teams" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div style="display:flex; gap:16px; flex-wrap:wrap;">
        @foreach (['red' => 'A', 'blue' => 'B'] as $team => $label)
            <div class="js-etf2l-team" style="border:1px solid #333; border-radius:8px; padding:14px; flex:1; min-width:280px;">
                <input type="hidden" name="{{ $team }}_etf2l_id" class="js-team-field-etf2l-id"
                       value="{{ (int) ($series['teams'][$team]['etf2l_id'] ?? 0) ?: '' }}">
                <label class="admin-form-label" for="team-{{ $team }}-name">Nom équipe {{ $label }} (acronyme)</label>
                <input type="text" id="team-{{ $team }}-name" name="{{ $team }}_name" class="form-control js-team-field-name"
                       value="{{ e($series['teams'][$team]['name']) }}" maxlength="64" required>
                <label class="admin-form-label" style="margin-top:10px;" for="team-{{ $team }}-avatar-url">Avatar par URL externe (optionnel)</label>
                <input type="url" id="team-{{ $team }}-avatar-url" name="{{ $team }}_avatar_url" class="form-control js-team-field-avatar"
                       value="{{ e($series['teams'][$team]['avatar_url'] ?? '') }}"
                       placeholder="https://…/logo.png">
            </div>
        @endforeach
    </div>
    <button type="submit" class="admin-btn" style="margin-top:10px;"><i class="fa-solid fa-floppy-disk"></i> Enregistrer les équipes</button>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-tv"></i> Overlay OBS</h3>
<p style="color:#aaa; font-size:13px;">
    Dans OBS Studio, ajoutez une source navigateur web (fond transparent) pointant sur l'URL ci-dessous :
    elle affiche les noms d'équipes, le score de série et l'état de chaque map, et se rafraîchit toute seule
    (polling toutes les 5 secondes — aucun alt-tab pendant le cast).
</p>
<p style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <code style="background:#111; border:1px solid #333; border-radius:6px; padding:8px 12px; font-size:14px;">
        {{ url('/series-overlay/'.$series['token']) }}
    </code>
    <a class="admin-link-btn" href="/series-overlay/{{ $series['token'] }}" target="_blank">Prévisualiser</a>
</p>
<p style="color:#aaa; font-size:13px;">
    Cast sur une SourceTV retardée ? Ajoutez <code>?delay=90</code> à cette URL (et à celle de l'overlay stats
    ci-dessous), avec le retard STV en secondes : les logs rattachés n'apparaissent sur l'overlay qu'une fois
    montrés par le flux retardé, sinon le score avance de 90 s sur les spectateurs. Les points manuels, eux,
    s'affichent dès leur saisie — entrez-les au moment où ils apparaissent sur le stream.
</p>

<h3 class="admin-section-title"><i class="fa-solid fa-table-list"></i> Overlay stats de match (automatique)</h3>
<p style="color:#aaa; font-size:13px;">
    Chaque log logs.tf rattaché à la série régénère automatiquement les stats de la map terminée sur cette page —
    aucun copier-coller d'URL logs.tf, aucune action à faire pendant le cast. Avant le premier log, la page affiche
    les équipes à 0-0 sans stats.
</p>
<p style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <code style="background:#111; border:1px solid #333; border-radius:6px; padding:8px 12px; font-size:14px;">
        {{ url('/series-overlay/'.$series['token'].'/match') }}
    </code>
    <a class="admin-link-btn" href="/series-overlay/{{ $series['token'] }}/match" target="_blank">Prévisualiser</a>
</p>

@if ($has_avatar['red'] || $has_avatar['blue'])
    <h4 style="margin:16px 0 8px;">Avatars uploadés (historique)</h4>
    <p style="color:#777; font-size:12px; margin:0 0 10px;">
        L'upload n'est plus proposé : renseignez une URL externe dans le formulaire « Équipes » ci-dessus.
        Ces avatars historiques, s'il en reste, continuent d'être affichés par l'overlay (l'URL externe prime).
    </p>
    <div style="display:flex; gap:16px; flex-wrap:wrap;">
        @foreach (['red' => 'A', 'blue' => 'B'] as $team => $label)
            @if ($has_avatar[$team])
                <div style="border:1px solid #333; border-radius:8px; padding:14px; flex:1; min-width:300px;">
                    <h4 style="margin:0 0 10px;">Avatar équipe {{ $label }}</h4>
                    <img src="/series-overlay/{{ $series['token'] }}/avatar/{{ $team }}" alt="Avatar {{ $label }}"
                         style="max-width:96px; max-height:96px; display:block; margin-bottom:10px;">
                    <form method="POST" action="/admin/series/{{ $series['token'] }}/avatar/delete">
                        @csrf
                        <input type="hidden" name="team" value="{{ $team }}">
                        <button type="submit" class="admin-btn admin-btn--danger">Supprimer l'avatar</button>
                    </form>
                </div>
            @endif
        @endforeach
    </div>
@endif

<h3 class="admin-section-title"><i class="fa-solid fa-map"></i> Maps de la série</h3>
@if ($series['status'] === 'live')
    <form method="POST" action="/admin/series/{{ $series['token'] }}/reconcile" style="margin:0 0 12px;">
        @csrf
        <button type="submit" class="admin-btn admin-btn--primary"
                title="Interroge logs.tf tout de suite au lieu d'attendre la vérification automatique de la minute suivante.">
            <i class="fa-solid fa-rotate"></i> Chercher les logs maintenant
        </button>
    </form>
@endif
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
                        @if ($map['mode'] === 'double')
                            {{ (int) $map['rounds']['red'] }} - {{ (int) $map['rounds']['blue'] }}
                            <span style="color:#777;">(2 rounds pour décider la map)</span>
                        @else
                            {{ $map['scores'] !== null ? ((int) $map['scores']['red']).' - '.((int) $map['scores']['blue']) : '0 - 0' }}
                            <span style="color:#777;">(première équipe à {{ (int) $map['winlimit'] }} points ; score final ramené par le log en fin de map)</span>
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

@push('styles')
<link rel="stylesheet" href="{{ hlfr_asset('/_css/admin_overlay.css') }}">
@endpush

@push('scripts')
<script src="{{ hlfr_asset('/_js/admin_etf2l_teams.js') }}" defer></script>
@endpush
@endsection
