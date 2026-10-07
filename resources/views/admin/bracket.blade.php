@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

<div class="admin-header" style="--accent:#9b6dff;">
    <h2><i class="fa-solid fa-sitemap"></i> Overlay Bracket (OBS)</h2>
    <p>Overlays de playoffs (bracket) et de classements (table) pour les broadcasts OBS : créez à la main ou importez une compétition ETF2L, ajustez colonnes, équipes et scores, puis pointez l'URL d'overlay dans OBS Studio (source navigateur web, 1920x1080, fond transparent).</p>
</div>

@php
    $divisions = session('bracket_divisions');
@endphp

<h3 class="admin-section-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Créer un overlay vide</h3>
<form method="POST" action="/admin/overlay/bracket/create" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label class="admin-form-label">Type</label>
            <select name="kind" class="form-control">
                <option value="bracket">Bracket de playoffs</option>
                <option value="table">Tableau de classement (poule)</option>
            </select>
        </div>
        <div style="flex:1; min-width:260px;">
            <label class="admin-form-label">Sur-titre (ex : « ETF2L Highlander Saison 36 »)</label>
            <input type="text" name="eyebrow" class="form-control" maxlength="96">
        </div>
        <div style="flex:1; min-width:200px;">
            <label class="admin-form-label">Titre (ex : « Division 1 ») *</label>
            <input type="text" name="title" class="form-control" maxlength="96" required>
        </div>
        <div style="flex:1; min-width:160px;">
            <label class="admin-form-label">Mot accentué (ex : « Playoffs »)</label>
            <input type="text" name="accent" class="form-control" maxlength="48">
        </div>
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-plus"></i> Créer</button>
    </div>
    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        Un bracket démarre avec une colonne et une case vide ; un classement démarre vide. Tout s'ajoute et se renomme ensuite dans l'éditeur (colonnes, rounds, équipes, scores).
    </p>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-cloud-arrow-down"></i> Importer depuis l'API ETF2L</h3>
<form method="POST" action="/admin/overlay/bracket/import" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label class="admin-form-label">Type</label>
            <select name="kind" class="form-control">
                <option value="bracket">Bracket (résultats groupés par round)</option>
                <option value="table">Classement (table d'une division)</option>
            </select>
        </div>
        @if (count($competitions) > 0)
            <div style="flex:2; min-width:320px;">
                <label class="admin-form-label">Compétition ETF2L (moins de 200 jours)</label>
                <select name="competition_id" class="form-control" required>
                    @foreach ($competitions as $competition)
                        <option value="{{ (int) $competition['id'] }}">
                            {{ e($competition['name']) }}{{ $competition['archived'] ? ' (archivée)' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
        @else
            <div style="flex:2; min-width:320px;">
                <label class="admin-form-label">ID de la compétition ETF2L (liste indisponible)</label>
                <input type="number" name="competition_id" class="form-control" min="1" required
                       placeholder="Ex : 1050">
            </div>
        @endif
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-bolt"></i> Importer</button>
    </div>

    @if (! empty($divisions) && is_array($divisions))
        <div class="admin-form-row" style="border:1px solid #9b6dff; border-radius:8px; padding:14px; background:rgba(155,109,255,0.08);">
            <h4 style="margin:0 0 10px;">Cette compétition a plusieurs divisions — choisissez celle à importer :</h4>
            <input type="hidden" name="competition_id" value="{{ (int) ($divisions['competition_id'] ?? 0) }}">
            <div style="display:flex; flex-wrap:wrap; gap:10px;">
                @foreach (($divisions['divisions'] ?? []) as $division)
                    <label style="border:1px solid #444; border-radius:6px; padding:8px 14px; cursor:pointer;">
                        <input type="radio" name="division" value="{{ e($division) }}" required> {{ e($division) }}
                    </label>
                @endforeach
            </div>
            <input type="hidden" name="kind" value="table">
            <button type="submit" class="admin-btn admin-btn--primary" style="margin-top:10px;">
                <i class="fa-solid fa-bolt"></i> Importer le classement
            </button>
        </div>
    @endif

    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        Bracket : les résultats de la compétition deviennent les colonnes (une par round — « Upper Bracket Final », « Lower Bracket Semi-Final », etc.), avec équipes, avatars et scores ; les rounds « Lower » passent en voie basse, la grande finale est mise en valeur. Classement : la table de la division (rang, drapeau, avatar, MJ/G/P, points). Tout reste éditable après l'import, et le bouton de re-synchronisation de l'éditeur relit l'API.
    </p>
    @if (session('error'))
        <p style="color:#ff8080; margin:6px 0 0;">{{ e(session('error')) }}</p>
    @endif
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-layer-group"></i> Overlays ({{ count($brackets) }})</h3>

@if (session('success'))
    <p style="color:#8c8;">{{ e(session('success')) }}</p>
@endif

@if (empty($brackets))
    <p style="color:#aaa; font-style:italic;">Aucun overlay pour le moment. Créez-en un ou importez une compétition ETF2L ci-dessus.</p>
@else
    <div class="admin-table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Titre</th>
                    <th>Créé le</th>
                    <th>Mis à jour</th>
                    <th>URL overlay (OBS)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($brackets as $entry)
                    <tr>
                        <td>{{ ($entry['kind'] ?? '') === 'table' ? 'Classement' : 'Bracket' }}</td>
                        <td>{{ e(trim((string) ($entry['title'] ?? '')) !== '' ? $entry['title'] : '(sans titre)') }}</td>
                        <td>{{ date('d/m/Y H:i', (int) ($entry['created_at'] ?? 0)) }}</td>
                        <td>{{ date('d/m/Y H:i', (int) ($entry['updated_at'] ?? 0)) }}</td>
                        <td style="max-width:340px;">
                            <input type="text" class="form-control overlay-url-input" readonly
                                   value="{{ url('/bracket-overlay/'.$entry['token']) }}"
                                   onclick="this.select();">
                        </td>
                        <td style="white-space:nowrap;">
                            <a href="/admin/overlay/bracket/{{ $entry['token'] }}" class="admin-btn">Modifier</a>
                            <form method="POST" action="/admin/overlay/bracket/{{ $entry['token'] }}/delete"
                                  style="display:inline;"
                                  onsubmit="return confirm('Supprimer cet overlay ?');">
                                @csrf
                                <button type="submit" class="admin-btn admin-btn--danger">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
