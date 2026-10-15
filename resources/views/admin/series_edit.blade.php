@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

@php
    // Barème des points ETF2L de la série : base 2 pour le 6v6 (Season 53,
    // sans golden cap — nuls en saison régulière, overtime infinie en BO),
    // base 3 (golden cap 2/1) pour le Highlander et les séries sans
    // catégorie rattachée.
    $base2 = ($series['category'] ?? null) === '6v6';
    $endHint = $base2
        ? ($series['wins_needed'] !== null
            ? 'la map part en overtime infinie (winlimit progressif, le point qui l\'atteint la décide)'
            : 'le meneur l\'emporte, une égalité solde la map sur un nul (1-1)')
        : 'le meneur remporte la map, une égalité part en golden cap';
@endphp

<div class="admin-header" style="--accent:#4cc46a;">
    <h2><i class="fa-solid fa-trophy"></i> {{ $series['title'] }}</h2>
    <p>
        {{ strtoupper($series['format']) }} ·
        @if ((int) ($series['etf2l_match_id'] ?? 0) > 0)
            <a href="https://etf2l.org/matches/{{ (int) $series['etf2l_match_id'] }}/" target="_blank" rel="noopener">Match ETF2L #{{ (int) $series['etf2l_match_id'] }}</a>
            ·
        @endif
        score de série (points ETF2L :
        <strong style="color:#e06c5a;">{{ $series['teams']['red']['name'] }} {{ (int) $state['score']['red'] }}</strong>
        -
        <strong style="color:#5885a2;">{{ (int) $state['score']['blue'] }} {{ $series['teams']['blue']['name'] }}</strong>
        <span style="color:#777; font-size:12px;">{{ $base2 ? '2 pts par map gagnée, map nulle 1-1 (sans golden cap)' : '3 pts par map gagnée, golden cap 2/1' }}</span>)
        @if ($state['finished'])
            @if ($state['winner'] !== null)
                — <strong>{{ $series['teams'][$state['winner']]['name'] }} remporte la série</strong>
            @else
                — <strong>série terminée, égalité</strong>
            @endif
        @endif
    </p>
</div>

<div style="display:flex; gap:10px; margin-bottom:24px; flex-wrap:wrap;">
    @if ((int) ($series['etf2l_match_id'] ?? 0) > 0)
        <form method="POST" action="/admin/series/{{ $series['token'] }}/resync"
              onsubmit="return confirm('Resynchroniser depuis ETF2L ? Les noms affichés, avatars et rosters seront remplacés par les données ETF2L actuelles (les maps déjà connues ne sont jamais retirées).');">
            @csrf
            <button type="submit" class="admin-btn admin-btn--primary"
                    title="Relit le match ETF2L : équipes TBD devenues connues, rosters, maps révélées par le pick / ban.">
                <i class="fa-solid fa-arrows-rotate"></i> Resynchroniser depuis ETF2L
            </button>
        </form>
    @endif
    <form method="POST" action="/admin/series/{{ $series['token'] }}/delete"
          onsubmit="return confirm('Supprimer définitivement cette série ?');">
        @csrf
        <button type="submit" class="admin-btn"><i class="fa-solid fa-trash"></i> Supprimer</button>
    </form>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-trophy"></i> Équipes</h3>
<p style="color:#aaa; font-size:13px;">
    Les noms s'affichent tels quels sur l'overlay : utilisez l'acronyme de l'équipe (ex : Inglorious Gamblers → IG),
    l'espace y est très réduit. Le nom complet ETF2L (prérempli au chargement du match, remplacé par la
    resynchronisation) reste en référence sous le champ. Modifiables à tout moment, y compris après la création.
    La couleur (A = côté gauche de l'overlay, B = côté droit) ne fixe que l'ordre d'affichage.
</p>
<p style="color:#aaa; font-size:13px;">
    La liaison ETF2L issue du lien de match permet de resynchroniser la série d'un clic : équipes TBD devenues
    connues, maps révélées par le pick / ban, format détecté du nombre de maps — sans ressaisir quoi que ce soit.
</p>
<form method="POST" action="/admin/series/{{ $series['token'] }}/teams" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div style="display:flex; gap:16px; flex-wrap:wrap;">
        @foreach (['red' => 'A', 'blue' => 'B'] as $team => $label)
            <div class="js-etf2l-team" style="border:1px solid #333; border-radius:8px; padding:14px; flex:1; min-width:280px;">
                <input type="hidden" name="{{ $team }}_etf2l_id" class="js-team-field-etf2l-id"
                       value="{{ (int) ($series['teams'][$team]['etf2l_id'] ?? 0) ?: '' }}">
                <label class="admin-form-label" for="team-{{ $team }}-name">Nom équipe {{ $label }} (acronyme)</label>
                <input type="text" id="team-{{ $team }}-name" name="{{ $team }}_name" class="form-control js-team-field-name"
                       value="{{ $series['teams'][$team]['name'] }}" maxlength="64" required>
                @if (trim((string) ($series['teams'][$team]['etf2l_name'] ?? '')) !== '')
                    <p style="color:#777; font-size:12px; margin:6px 0 0;">
                        Nom complet ETF2L : {{ $series['teams'][$team]['etf2l_name'] }}
                    </p>
                @endif
                <label class="admin-form-label" style="margin-top:10px;" for="team-{{ $team }}-avatar-url">Avatar par URL externe (optionnel)</label>
                <input type="url" id="team-{{ $team }}-avatar-url" name="{{ $team }}_avatar_url" class="form-control js-team-field-avatar"
                       value="{{ $series['teams'][$team]['avatar_url'] ?? '' }}"
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
    Les points s'affichent dès leur saisie — entrez-les au moment où ils apparaissent sur le stream.
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
@php
    // Pool de maps du menu « ajouter » : celui du format du match quand il
    // est connu, les deux sinon (match ETF2L non chargé ou catégorie absente).
    $category = (string) ($series['category'] ?? '');
    $addableMaps = [];
    foreach (['9v9', '6v6'] as $poolCategory) {
        if ($category === '' || $poolCategory === $category) {
            foreach ($mapPools[$poolCategory] ?? [] as $map) {
                $addableMaps[] = $map;
            }
        }
    }
@endphp
<form method="POST" action="/admin/series/{{ $series['token'] }}/map" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin:0 0 12px;">
    @csrf
    <div style="flex:1; min-width:260px;">
        <label class="admin-form-label" for="series-add-map">Ajouter une map</label>
        <select id="series-add-map" name="map" class="form-control" required>
            @forelse ($addableMaps as $map)
                <option value="{{ $map['name'] }}">{{ trim((string) ($map['label'] ?? '')) !== '' ? $map['label'] : $map['name'] }}</option>
            @empty
                <option value="" disabled>Aucune map active pour ce format</option>
            @endforelse
        </select>
    </div>
    <button type="submit" class="admin-btn admin-btn--primary"
            title="BO qui va à sa dernière map, map décidée en cours de série.">
        <i class="fa-solid fa-plus"></i> Ajouter la map
    </button>
</form>
<div class="admin-table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Map</th>
                <th>Mode</th>
                <th>Rounds / score</th>
                <th>Statut</th>
                <th>Gagnant</th>
                <th>Ajustement manuel</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($state['maps'] as $map)
                <tr>
                    <td>{{ $map['name'] }}</td>
                    <td>{{ $map['mode'] === 'double' ? 'Double attaque' : 'Points' }}</td>
                    <td>
                        @if ($map['mode'] === 'double')
                            {{ (int) $map['rounds']['red'] }} - {{ (int) $map['rounds']['blue'] }}
                            <span style="color:#777;">(2 rounds pour décider la map)</span>
                        @else
                            {{ $map['scores'] !== null ? ((int) $map['scores']['red']).' - '.((int) $map['scores']['blue']) : '0 - 0' }}
                            <span style="color:#777;">(première équipe à {{ (int) $map['winlimit'] }} points)</span>
                        @endif
                        @if ($map['golden_cap'])
                            <span style="color:#d9a544;">golden cap en attente — le prochain point la tranche</span>
                        @elseif ($map['overtime'])
                            <span style="color:#7fb3d5;">overtime infinie — le prochain point qui atteint le winlimit la décide</span>
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
                            <span style="color: {{ $map['winner'] === 'red' ? '#e06c5a' : '#5885a2' }};">{{ $series['teams'][$map['winner']]['name'] }}</span>
                            @if ($map['status'] === 'decided')
                                <span style="color:#777; font-size:12px;">({{ $base2 ? '+2' : ($map['decided_by_gc'] ? 'golden cap : +2 / +1' : '+3') }})</span>
                            @endif
                        @elseif ($map['draw'])
                            <span style="color:#777;">Nul (+1 / +1)</span>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if ($map['status'] !== 'decided')
                            <form method="POST" action="/admin/series/{{ $series['token'] }}/point" style="display:inline;">
                                @csrf
                                <input type="hidden" name="map" value="{{ $map['name'] }}">
                                <input type="hidden" name="team" value="red">
                                <input type="hidden" name="note" value="point manuel depuis l'admin">
                                <button type="submit" class="admin-btn" style="color:#e06c5a;">+1 {{ $series['teams']['red']['name'] }}</button>
                            </form>
                            <form method="POST" action="/admin/series/{{ $series['token'] }}/point" style="display:inline;">
                                @csrf
                                <input type="hidden" name="map" value="{{ $map['name'] }}">
                                <input type="hidden" name="team" value="blue">
                                <input type="hidden" name="note" value="point manuel depuis l'admin">
                                <button type="submit" class="admin-btn" style="color:#5885a2;">+1 {{ $series['teams']['blue']['name'] }}</button>
                            </form>
                            @if ($map['mode'] !== 'double' && (int) $map['winlimit'] === 5 && ! $map['golden_cap'])
                                @php
                                    // En overtime infinie (6v6), le bouton sert de rattrapage :
                                    // solder la map pour l'équipe en tête.
                                    $hint = $map['overtime']
                                        ? 'l\'overtime est soldée pour l\'équipe en tête (rattrapage)'
                                        : $endHint;
                                @endphp
                                <form method="POST" action="/admin/series/{{ $series['token'] }}/end" style="display:inline; margin-top:6px;"
                                      onsubmit="return confirm('Match terminé (timelimit 30 min atteint sans winlimit) ? {{ $hint }}.');">
                                    @csrf
                                    <input type="hidden" name="map" value="{{ $map['name'] }}">
                                    <input type="hidden" name="note" value="fin de map déclarée à la main (timelimit)">
                                    <button type="submit" class="admin-btn"
                                            title="Map 5cp à timelimit (30 min) sans winlimit atteint : {{ $hint }}.">
                                        <i class="fa-solid fa-flag-checkered"></i> Match terminé
                                    </button>
                                </form>
                            @endif
                        @endif
                    </td>
                    <td>
                        <form method="POST" action="/admin/series/{{ $series['token'] }}/map/delete"
                              onsubmit="return confirm('Retirer {{ $map['name'] }} de la série ?');">
                            @csrf
                            <input type="hidden" name="map" value="{{ $map['name'] }}">
                            <button type="submit" class="admin-btn" title="Uniquement si aucun point n'a compté sur cette map.">
                                <i class="fa-solid fa-trash"></i> Retirer
                            </button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-clock-rotate-left"></i> Journal des événements</h3>
<p style="color:#777; font-size:13px;">
    Chaque point du score vient d'un événement de ce journal. Annuler un événement le retire du calcul tout en le
    gardant visible ici : l'historique reste auditable en cas de contestation.
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
                            Log logs.tf #{{ (int) ($event['log_id'] ?? 0) }} (historique)
                        @elseif (($event['type'] ?? '') === 'manual')
                            Point manuel
                        @elseif (($event['type'] ?? '') === 'end')
                            Fin de map
                        @else
                            Annulation
                        @endif
                    </td>
                    <td>{{ $event['source'] ?? '' }}</td>
                    <td>
                        @if (($event['type'] ?? '') === 'log')
                            {{ $event['map'] ?? '' }} : {{ (int) ($event['scores']['red'] ?? 0) }}-{{ (int) ($event['scores']['blue'] ?? 0) }}
                            @if (($event['winner'] ?? '') !== '')
                                → {{ $series['teams'][$event['winner']]['name'] ?? '' }}
                            @else
                                → égalité
                            @endif
                        @elseif (($event['type'] ?? '') === 'end')
                            {{ $event['map'] ?? '' }} : timelimit atteint
                        @elseif (($event['type'] ?? '') === 'manual')
                            +1 {{ $series['teams'][$event['team']]['name'] ?? '' }} sur {{ $event['map'] ?? '' }}
                        @else
                            annule l'événement {{ $event['target'] ?? '' }}
                        @endif
                        @if (($event['note'] ?? '') !== '')
                            <span style="color:#777;">({{ $event['note'] }})</span>
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
@endsection
