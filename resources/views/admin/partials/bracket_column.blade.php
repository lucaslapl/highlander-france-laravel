{{-- Une colonne du bracket dans l'éditeur admin : label, voie (haute / basse /
     finale) et ses cases. Les noms de champs embarquent l'index de colonne :
     l'ordre d'affichage suit l'ordre du formulaire, et les ajouts /
     suppressions / déplacements se font en JavaScript (admin_bracket.js) en
     réécrivant ces indices. Le modèle de case inclus sert à ajouter des
     cases sans recharger la page. --}}
@php
    $cIdx = (string) $index;
    $lane = (string) ($column['lane'] ?? 'upper');
@endphp
<div class="bracket-column" data-index="{{ $cIdx }}"
     style="border:1px solid #444; border-radius:8px; padding:14px; margin-bottom:14px; background:rgba(0,0,0,0.15);">
    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <input type="hidden" name="columns[{{ $cIdx }}][id]" value="{{ e((string) ($column['id'] ?? '')) }}">
        <div style="flex:1; min-width:200px;">
            <label class="admin-form-label">Label du round (ex : « Lower Bracket Final »)</label>
            <input type="text" name="columns[{{ $cIdx }}][label]" class="form-control" maxlength="48"
                   value="{{ e((string) ($column['label'] ?? '')) }}">
        </div>
        <div>
            <label class="admin-form-label">Voie</label>
            <select name="columns[{{ $cIdx }}][lane]" class="form-control">
                <option value="upper" {{ $lane === 'upper' ? 'selected' : '' }}>Haute</option>
                <option value="lower" {{ $lane === 'lower' ? 'selected' : '' }}>Basse</option>
                <option value="final" {{ $lane === 'final' ? 'selected' : '' }}>Finale (grand carte)</option>
            </select>
        </div>
        <button type="button" class="admin-btn js-move" data-target="column" data-dir="-1" title="Monter la colonne">
            <i class="fa-solid fa-arrow-up"></i>
        </button>
        <button type="button" class="admin-btn js-move" data-target="column" data-dir="1" title="Descendre la colonne">
            <i class="fa-solid fa-arrow-down"></i>
        </button>
        <button type="button" class="admin-btn admin-btn--danger js-remove" data-target="column"
                title="Supprimer la colonne et ses cases">
            <i class="fa-solid fa-trash"></i>
        </button>
    </div>

    <div class="bracket-matches" style="margin-top:12px;">
        @foreach (($column['matches'] ?? []) as $match)
            @include('admin.partials.bracket_match', [
                'cIdx' => $cIdx,
                'mIdx' => (string) $loop->index,
                'match' => $match,
                'columnId' => (string) ($column['id'] ?? ''),
                'liveValue' => $liveValue,
                'seriesList' => $seriesList,
            ])
        @endforeach
    </div>

    <button type="button" class="admin-btn js-add-match" style="margin-top:10px;">
        <i class="fa-solid fa-plus"></i> Ajouter une case
    </button>

    {{-- Modèle de case pour cette colonne (index de case remplacé en JS) --}}
    <template class="tpl-bracket-match">
        @include('admin.partials.bracket_match', [
            'cIdx' => $cIdx,
            'mIdx' => '__MIDX__',
            'match' => ['id' => '', 'teams' => [['name' => '', 'avatar' => '', 'country' => '', 'score' => ''], ['name' => '', 'avatar' => '', 'country' => '', 'score' => '']], 'series_token' => null, 'manual_scores' => false],
            'columnId' => (string) ($column['id'] ?? ''),
            'liveValue' => $liveValue,
            'seriesList' => $seriesList,
        ])
    </template>
</div>
