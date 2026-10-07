{{-- Une case de match dans l'éditeur admin : deux équipes (nom, avatar, pays,
     score), attachement à une série pour le score live, forçage manuel et
     désignation du match « EN DIRECT ». Radio et cases à cocher partagent
     leurs noms entre toutes les cases du formulaire. --}}
@php
    $teams = $match['teams'] ?? [['name' => '', 'avatar' => '', 'country' => '', 'score' => ''], ['name' => '', 'avatar' => '', 'country' => '', 'score' => '']];
    $seriesToken = (string) ($match['series_token'] ?? '');
    $manual = ! empty($match['manual_scores']);
    $matchId = (string) ($match['id'] ?? '');
    $isLive = $liveValue !== '' && $liveValue === ((string) ($columnId ?? '')).':'.$matchId;
@endphp
<div class="bracket-match"
     style="border:1px solid #555; border-radius:8px; padding:12px; margin-top:10px;{{ $isLive ? ' border-color:#ff5555; box-shadow:0 0 10px rgba(255,60,60,0.3);' : '' }}">
    <input type="hidden" name="columns[{{ $cIdx }}][matches][{{ $mIdx }}][id]" value="{{ e($matchId) }}">
    <div style="display:flex; gap:8px; align-items:center; justify-content:space-between; margin-bottom:8px;">
        <strong style="color:#bbb;">Case de match</strong>
        <div style="display:flex; gap:6px;">
            <button type="button" class="admin-btn js-move" data-target="match" data-dir="-1" title="Monter la case">
                <i class="fa-solid fa-arrow-up"></i>
            </button>
            <button type="button" class="admin-btn js-move" data-target="match" data-dir="1" title="Descendre la case">
                <i class="fa-solid fa-arrow-down"></i>
            </button>
            <button type="button" class="admin-btn admin-btn--danger js-remove" data-target="match" title="Supprimer la case">
                <i class="fa-solid fa-trash"></i>
            </button>
        </div>
    </div>

    <div style="display:flex; gap:14px; flex-wrap:wrap;">
        @foreach ([0 => 'A', 1 => 'B'] as $i => $label)
            @php
                $team = $teams[$i] ?? ['name' => '', 'avatar' => '', 'country' => '', 'score' => ''];
                $n = $i + 1;
            @endphp
            <div class="js-etf2l-team" style="flex:1; min-width:260px; border:1px solid #333; border-radius:8px; padding:12px;">
                <h5 style="margin:0 0 8px; color:#bbb;">Équipe {{ $label }}</h5>
                <label class="admin-form-label">Nom</label>
                <input type="text" name="columns[{{ $cIdx }}][matches][{{ $mIdx }}][team{{ $n }}_name]"
                       class="form-control js-team-field-name" maxlength="64" value="{{ e((string) ($team['name'] ?? '')) }}">
                <label class="admin-form-label" style="margin-top:8px;">Avatar par URL (optionnel)</label>
                <input type="url" name="columns[{{ $cIdx }}][matches][{{ $mIdx }}][team{{ $n }}_avatar]"
                       class="form-control js-team-field-avatar" maxlength="500" placeholder="https://…/logo.png"
                       value="{{ e((string) ($team['avatar'] ?? '')) }}">
                <div style="display:flex; gap:10px; margin-top:8px;">
                    <div style="flex:1;">
                        <label class="admin-form-label">Pays (optionnel)</label>
                        <input type="text" name="columns[{{ $cIdx }}][matches][{{ $mIdx }}][team{{ $n }}_country]"
                               class="form-control js-team-field-country" maxlength="32" placeholder="France"
                               value="{{ e((string) ($team['country'] ?? '')) }}">
                    </div>
                    <div style="width:110px;">
                        <label class="admin-form-label">Score</label>
                        <input type="text" name="columns[{{ $cIdx }}][matches][{{ $mIdx }}][team{{ $n }}_score]"
                               class="form-control" maxlength="3" inputmode="numeric"
                               value="{{ e((string) ($team['score'] ?? '')) }}">
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div style="display:flex; gap:18px; flex-wrap:wrap; align-items:flex-end; margin-top:10px;">
        <div style="flex:1; min-width:260px;">
            <label class="admin-form-label">Série suivie pour le score live (optionnel)</label>
            <select name="columns[{{ $cIdx }}][matches][{{ $mIdx }}][series_token]" class="form-control">
                <option value="">— Aucune (score saisi ci-dessus) —</option>
                @foreach (($seriesList ?? []) as $series)
                    <option value="{{ e((string) ($series['token'] ?? '')) }}" {{ $seriesToken === (string) ($series['token'] ?? '') ? 'selected' : '' }}>
                        {{ e((string) ($series['title'] ?? '')) }}
                        — {{ e((string) ($series['teams']['red']['name'] ?? '')) }} vs {{ e((string) ($series['teams']['blue']['name'] ?? '')) }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="admin-form-label" style="margin-bottom:0;">
                <input type="checkbox" name="columns[{{ $cIdx }}][matches][{{ $mIdx }}][manual_scores]" value="1"
                       {{ $manual ? 'checked' : '' }}>
                Score manuel forcé (ignorer la série)
            </label>
        </div>
        <div>
            <label class="admin-form-label" style="margin-bottom:0;">
                <input type="radio" name="live_match" value="{{ e((string) ($columnId ?? '')).':'.e($matchId) }}"
                       {{ $isLive ? 'checked' : '' }}>
                EN DIRECT
            </label>
        </div>
    </div>

    @if ($matchId === '')
        <p style="color:#888; font-size:12px; margin:8px 0 0;">
            Case nouvellement ajoutée : enregistrez le formulaire pour la désigner « EN DIRECT » ou l'attacher à une
            série (les identifiants internes sont créés à l'enregistrement).
        </p>
    @endif
</div>
