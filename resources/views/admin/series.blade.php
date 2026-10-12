@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

<div class="admin-header" style="--accent:#4cc46a;">
    <h2><i class="fa-solid fa-trophy"></i> Overlay Scores</h2>
    <p>Overlay de score de série (playoffs) rempli depuis un lien de match ETF2L : collez le lien de la page du match (ex : https://etf2l.org/matches/93233/), chargez-le, et le titre, les équipes (acronymes, avatars, rosters) et les maps connues se remplissent tout seuls. Les points de maps se saisissent à la main dans le détail de la série, un par map gagnée — chaque point est visible et annulable dans le journal.</p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-plus"></i> Créer une série</h3>
<form method="POST" action="/admin/series/create" class="admin-form-stack admin-form-stack--wide">
    @csrf

    <div class="admin-form-row js-series-match" style="border:1px solid #4cc46a; border-radius:8px; padding:14px; background:rgba(76,196,106,0.08);">
        <label class="admin-form-label" for="series-match-url">Lien du match ETF2L</label>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <input type="url" id="series-match-url" name="match_url" class="form-control" style="flex:1; min-width:300px;"
                   placeholder="https://etf2l.org/matches/93233/" required>
            <button type="button" id="series-load-match" class="admin-btn admin-btn--primary">
                <i class="fa-solid fa-cloud-arrow-down"></i> Charger le match
            </button>
        </div>
        <p style="color:#777; font-size:12px; margin:6px 0 0;">
            Le lien génère le titre du suivi (« Compétition — A vs B ») et préremplit équipes, avatars et rosters —
            tout reste corrigible avant de créer (mercs, équipe TBD en playoffs, API indisponible : la saisie manuelle prend le relais).
        </p>
        <p id="series-match-status" style="margin:10px 0 0; font-size:13px; color:#888;"></p>
        <p id="series-match-title" style="margin:6px 0 0; font-size:13px; color:#bbb;"></p>
    </div>

    <div class="admin-form-row" style="display:flex; gap:24px; flex-wrap:wrap;">
        @foreach (['red' => 'A', 'blue' => 'B'] as $team => $label)
            <div class="js-etf2l-team js-etf2l-team--{{ $team }}" style="flex:1; min-width:300px; border:1px solid #333; border-radius:8px; padding:14px;">
                <h4 style="margin:0 0 10px; color: {{ $team === 'red' ? '#e06c5a' : '#5885a2' }};">Équipe {{ $label }}</h4>
                <p style="color:#777; font-size:12px; margin:0 0 10px;">
                    La couleur n'a aucune importance sur le match : elle ne fait que fixer l'ordre d'affichage
                    {{ $team === 'red' ? 'à gauche' : 'à droite' }} sur l'overlay.
                </p>
                <label class="admin-form-label" for="series-{{ $team }}-name">Nom affiché</label>
                <input type="hidden" name="{{ $team }}_etf2l_id" class="js-team-field-etf2l-id" value="">
                <input type="text" id="series-{{ $team }}-name" name="{{ $team }}_name" class="form-control js-team-field-name"
                       placeholder="Ex : IG" maxlength="64" required>
                <p style="color:#777; font-size:12px; margin:6px 0 0;">
                    Prérempli avec le nom complet ETF2L de l'équipe : raccourcissez-le (acronyme, ex : Inglorious
                    Gamblers → IG), l'espace disponible sur l'overlay est très réduit — il reste modifiable à tout moment.
                </p>
                <label class="admin-form-label" style="margin-top:10px;" for="series-{{ $team }}-players">Roster (SteamIDs)</label>
                <textarea id="series-{{ $team }}-players" name="{{ $team }}_players" class="form-control js-team-field-players" rows="3"
                          placeholder="76561198012345678&#10;STEAM_1:0:12345" required></textarea>
                <p style="color:#777; font-size:12px; margin:6px 0 0;">
                    Prérempli depuis ETF2L au chargement du match (corrigible à la main — mercs, saison passée…).
                    Formats acceptés : SteamID64, STEAM_1:X:Y ou [U:1:N], un par ligne ou séparés par des virgules.
                </p>
                <label class="admin-form-label" style="margin-top:10px;" for="series-{{ $team }}-avatar-url">Avatar par URL externe (optionnel)</label>
                <input type="url" id="series-{{ $team }}-avatar-url" name="{{ $team }}_avatar_url" class="form-control js-team-field-avatar"
                       placeholder="https://…/logo.png">
            </div>
        @endforeach
    </div>

    <div class="admin-form-row" style="display:flex; gap:24px; flex-wrap:wrap;">
        <div style="min-width:180px;">
            <label class="admin-form-label" for="series-format">Format de la série</label>
            <select id="series-format" name="format" class="form-control">
                <option value="bo3">BO3 (première à 2 maps)</option>
                <option value="bo5">BO5 (première à 3 maps)</option>
                <option value="fixed">Classique (deux maps)</option>
            </select>
        </div>
        <div style="flex:1; min-width:320px;">
            <label class="admin-form-label" for="series-map-pool">Pool de maps</label>
            <select id="series-map-pool" class="form-control js-series-map-pool">
                <option value="9v9">Highlander (9v9)</option>
                <option value="6v6">6v6</option>
            </select>
            <p style="color:#777; font-size:12px; margin:6px 0 0;">
                Préréglé sur le format du match ETF2L chargé ; les maps connues d'ETF2L sont déjà ajoutées, complétez
                avec celles du pick / ban via le menu ci-dessous.
            </p>
        </div>
    </div>

    <div class="admin-form-row js-series-maps" style="border:1px solid #333; border-radius:8px; padding:14px;">
        <label class="admin-form-label" for="series-map-select">Maps de la série</label>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <div style="flex:1; min-width:260px;">
                <select id="series-map-select" class="form-control js-series-map-select">
                    @foreach (['9v9', '6v6'] as $category)
                        <optgroup label="{{ $category === '9v9' ? 'Highlander (9v9)' : '6v6' }}" data-category="{{ $category }}">
                            @foreach ($mapPools[$category] ?? [] as $map)
                                <option value="{{ e($map['name']) }}" data-category="{{ $category }}">
                                    {{ e(trim((string) ($map['label'] ?? '')) !== '' ? $map['label'] : $map['name']) }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <button type="button" class="admin-btn admin-btn--primary js-series-map-add">
                <i class="fa-solid fa-plus"></i> Ajouter la map
            </button>
        </div>
        <ul class="js-series-map-list" style="list-style:none; margin:12px 0 0; padding:0; display:flex; flex-direction:column; gap:6px;"></ul>
        <p class="js-series-map-empty" style="color:#d9a544; font-size:13px; margin:10px 0 0;">
            Aucune map pour le moment : ajoutez-en au moins une (elles pourront être complétées après création, ou
            resynchronisées depuis ETF2L).
        </p>
    </div>

    <div class="admin-form-row" style="display:flex; justify-content:flex-end;">
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-plus"></i> Créer la série</button>
    </div>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-layer-group"></i> Séries ({{ count($seriesList) }})</h3>

@if (empty($seriesList))
    <p style="color:#aaa; font-style:italic;">Aucune série pour le moment. Créez-en une ci-dessus.</p>
@else
    <div class="admin-table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Série</th>
                    <th>Match ETF2L</th>
                    <th>Format</th>
                    <th>Créée le</th>
                    <th>Mise à jour</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($seriesList as $entry)
                    <tr>
                        <td><a href="/admin/series/{{ $entry['token'] }}">{{ e($entry['title']) }}</a></td>
                        <td>
                            @if ((int) ($entry['etf2l_match_id'] ?? 0) > 0)
                                <a href="https://etf2l.org/matches/{{ (int) $entry['etf2l_match_id'] }}/" target="_blank" rel="noopener">#{{ (int) $entry['etf2l_match_id'] }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ strtoupper($entry['format']) }}</td>
                        <td>{{ date('d/m/Y H:i', (int) $entry['created_at']) }}</td>
                        <td>{{ date('d/m/Y H:i', (int) $entry['updated_at']) }}</td>
                        <td><a class="admin-link-btn" href="/admin/series/{{ $entry['token'] }}">Ouvrir</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@push('styles')
<link rel="stylesheet" href="{{ hlfr_asset('/_css/admin_overlay.css') }}">
@endpush

@push('scripts')
<script src="{{ hlfr_asset('/_js/admin_series_match.js') }}" defer></script>
@endpush
@endsection
