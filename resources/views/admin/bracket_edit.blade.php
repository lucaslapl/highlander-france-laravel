@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

@php
    $isBracket = ($bracket['kind'] ?? 'bracket') === 'table' ? false : true;
    $live = $bracket['live'] ?? null;
    $liveValue = is_array($live) ? ((string) ($live['column'] ?? '')).':'.((string) ($live['match'] ?? '')) : '';
    $etf2l = $bracket['etf2l'] ?? null;
@endphp

<div class="admin-header" style="--accent:#9b6dff;">
    <h2>
        <i class="fa-solid fa-sitemap"></i>
        Overlay {{ $isBracket ? 'Bracket' : 'Classement' }} — {{ e($bracket['title'] !== '' ? $bracket['title'] : $bracket['token']) }}
    </h2>
    <p>Modifiez les titres, {{ $isBracket ? 'les colonnes, les cases et le match « EN DIRECT »' : 'les lignes du classement et le top X surligné' }}. L'overlay OBS se rafraîchit tout seul à chaque enregistrement.</p>
</div>

<div style="border:2px solid #9b6dff; border-radius:10px; padding:16px; margin-bottom:24px; background:rgba(155,109,255,0.08);">
    <h4 style="margin:0 0 8px;"><i class="fa-solid fa-link"></i> URL de l'overlay (OBS)</h4>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="text" class="form-control overlay-url-input" readonly value="{{ $overlay_url }}"
               onclick="this.select();" style="flex:1; min-width:320px;">
        <button type="button" class="admin-btn" onclick="navigator.clipboard.writeText('{{ $overlay_url }}');">
            <i class="fa-solid fa-copy"></i> Copier
        </button>
        <a href="{{ $overlay_url }}" target="_blank" rel="noopener" class="admin-btn">Ouvrir</a>
    </div>
    <p style="color:#777; font-size:13px; margin:10px 0 0;">
        Dans OBS : Sources → + → Navigateur web → collez l'URL, largeur 1920, hauteur 1080. Le match « EN DIRECT »
        attaché à une série suit aussi les points manuels, la réconciliation logs.tf et le webhook match-ended : le
        bracket se rafraîchit alors sans rien toucher.
    </p>
</div>

@if (session('success'))
    <p style="color:#8c8;">{{ e(session('success')) }}</p>
@endif
@if (session('error'))
    <p style="color:#ff8080;">{{ e(session('error')) }}</p>
@endif

<form method="POST" action="/admin/overlay/bracket/{{ $bracket['token'] }}/update" class="admin-form-stack admin-form-stack--wide" id="bracket-editor">
    @csrf

    <h3 class="admin-section-title"><i class="fa-solid fa-heading"></i> Titres</h3>
    <div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap;">
        <div style="flex:1; min-width:260px;">
            <label class="admin-form-label">Sur-titre (petit texte au-dessus du titre)</label>
            <input type="text" name="eyebrow" class="form-control" maxlength="96"
                   value="{{ e((string) ($bracket['eyebrow'] ?? '')) }}"
                   placeholder="ETF2L Highlander Saison 36">
        </div>
        <div style="flex:1; min-width:200px;">
            <label class="admin-form-label">Titre *</label>
            <input type="text" name="title" class="form-control" maxlength="96" required
                   value="{{ e((string) ($bracket['title'] ?? '')) }}" placeholder="Division 1">
        </div>
        <div style="flex:1; min-width:160px;">
            <label class="admin-form-label">Mot accentué (en rose dans le titre)</label>
            <input type="text" name="accent" class="form-control" maxlength="48"
                   value="{{ e((string) ($bracket['accent'] ?? '')) }}" placeholder="Playoffs">
        </div>
    </div>

    @if ($isBracket)

        <h3 class="admin-section-title"><i class="fa-solid fa-table-columns"></i> Colonnes du bracket</h3>
        <p style="color:#777; font-size:13px; margin:0 0 10px;">
            L'ordre des colonnes ci-dessous (de gauche à droite) fait l'ordre d'affichage ; la voie détermine la bande
            verticale : « Haute » en haut, « Basse » en bas, « Finale » en grande carte centrale à droite. Le câblage
            des connecteurs est automatique : chaque case est alimentée par la case située au-dessus d'elle dans la
            colonne précédente (deux par deux).
        </p>
        <div id="bracket-columns">
            @foreach ($bracket['columns'] as $column)
                @include('admin.partials.bracket_column', ['column' => $column, 'index' => $loop->index, 'liveValue' => $liveValue, 'seriesList' => $seriesList])
            @endforeach
        </div>
        <div style="margin-top:12px;">
            <button type="button" class="admin-btn" id="bracket-add-column"><i class="fa-solid fa-plus"></i> Ajouter une colonne</button>
        </div>

        {{-- Modèles pour l'ajout de colonnes / cases en JavaScript --}}
        <template id="tpl-bracket-column">
            @include('admin.partials.bracket_column', [
                'column' => ['id' => '', 'label' => '', 'lane' => 'upper', 'matches' => [['id' => '', 'teams' => [['name' => '', 'avatar' => '', 'country' => '', 'score' => ''], ['name' => '', 'avatar' => '', 'country' => '', 'score' => '']], 'series_token' => null, 'manual_scores' => false]]],
                'index' => '__CIDX__',
                'liveValue' => $liveValue,
                'seriesList' => $seriesList,
                'tpl' => true,
            ])
        </template>

    @else

        <h3 class="admin-section-title"><i class="fa-solid fa-list-ol"></i> Lignes du classement</h3>
        <p style="color:#777; font-size:13px; margin:0 0 10px;">
            L'ordre du formulaire fait le classement (le rang est calculé à l'affichage). Les équipes du top X sont
            surlignées en vert sur l'overlay ; 0 désactive le surlignage.
        </p>
        <div class="admin-form-row" style="max-width:220px;">
            <label class="admin-form-label">Top X surligné</label>
            <input type="number" name="top_x" class="form-control" min="0" max="20"
                   value="{{ (int) ($bracket['top_x'] ?? 0) }}">
        </div>
        <div id="table-rows">
            @foreach ($bracket['rows'] as $row)
                @include('admin.partials.bracket_row', ['row' => $row, 'index' => $loop->index])
            @endforeach
        </div>
        <div style="margin-top:12px;">
            <button type="button" class="admin-btn" id="table-add-row"><i class="fa-solid fa-plus"></i> Ajouter une ligne</button>
        </div>
        <template id="tpl-table-row">
            @include('admin.partials.bracket_row', [
                'row' => ['name' => '', 'avatar' => '', 'country' => '', 'played' => 0, 'won' => 0, 'lost' => 0, 'score' => 0, 'penalty' => 0],
                'index' => '__RIDX__',
                'tpl' => true,
            ])
        </template>

    @endif

    <div style="margin-top:18px;">
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
    </div>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-rotate"></i> Actions</h3>
<div style="display:flex; gap:12px; flex-wrap:wrap;">
    @if (! empty($etf2l))
        <form method="POST" action="/admin/overlay/bracket/{{ $bracket['token'] }}/resync"
              onsubmit="return confirm('Re-synchroniser depuis l\'API ETF2L ? {{ $isBracket ? 'Les colonnes et scores seront remplacés (vos éditions manuelles depuis l\'import seront perdues) et le match « EN DIRECT » réinitialisé.' : 'Les lignes seront remplacées.' }}');">
            @csrf
            <button type="submit" class="admin-btn">
                <i class="fa-solid fa-arrows-rotate"></i> Re-synchroniser depuis ETF2L
            </button>
        </form>
    @endif
    <a href="/admin/overlay/bracket" class="admin-btn"><i class="fa-solid fa-arrow-left"></i> Retour à la liste</a>
    <form method="POST" action="/admin/overlay/bracket/{{ $bracket['token'] }}/delete"
          onsubmit="return confirm('Supprimer cet overlay ?');">
        @csrf
        <button type="submit" class="admin-btn admin-btn--danger"><i class="fa-solid fa-trash"></i> Supprimer</button>
    </form>
</div>

<script src="{{ hlfr_asset('/_js/admin_bracket.js') }}" defer></script>
@endsection
