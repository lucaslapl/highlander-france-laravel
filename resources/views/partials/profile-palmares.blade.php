@php
    $validPalmares = collect($palmares ?? [])->filter(fn ($p) => !empty($p['placement']) || !empty($p['playoff_round']));
    if ($validPalmares->isEmpty()) {
        return;
    }

    $grouped = $validPalmares->groupBy('game_mode');
    $modeLabels = ['9v9' => 'Highlander', '6s' => '6v6'];

    $placementIcons = [
        1 => ['icon' => 'fa-solid fa-trophy', 'class' => 'palmares-gold',   'label' => '1er'],
        2 => ['icon' => 'fa-solid fa-medal',  'class' => 'palmares-silver', 'label' => '2ème'],
        3 => ['icon' => 'fa-solid fa-award',  'class' => 'palmares-bronze', 'label' => '3ème'],
    ];
@endphp

<div class="profile-palmares">
    <h4><i class="fa-solid fa-trophy"></i> Palmarès</h4>

    @foreach ($grouped as $mode => $entries)
        @php
            $sorted = $entries->sortBy([
                fn ($a, $b) => ($b['computed_at'] ?? 0) <=> ($a['computed_at'] ?? 0),
                fn ($a, $b) => ($a['tier'] ?? 0) <=> ($b['tier'] ?? 0),
            ])->values();
        @endphp

        <div class="palmares-section">
            <span class="palmares-mode">{{ $modeLabels[$mode] ?? $mode }}</span>

            @foreach ($sorted as $entry)
                @php
                    $placement = $entry['placement'] ?? null;
                    $playoffRound = $entry['playoff_round'] ?? null;
                    $wonPlayoff = !empty($entry['won_playoff']);

                    // Grand Finals gagnées → 1ère place, perdues → 2ème place.
                    if ($placement === null && $playoffRound !== null) {
                        if ($wonPlayoff && preg_match('/grand\s*final/i', $playoffRound)) {
                            $placement = 1;
                        } elseif (! $wonPlayoff && preg_match('/grand\s*final/i', $playoffRound)) {
                            $placement = 2;
                        }
                    }

                    $pInfo = $placement !== null ? ($placementIcons[$placement] ?? null) : null;
                @endphp

                <div class="palmares-entry">
                    @if ($pInfo !== null)
                        <i class="{{ $pInfo['icon'] }} palmares-medal-icon {{ $pInfo['class'] }}" title="{{ $pInfo['label'] }}"></i>
                    @else
                        <span class="palmares-medal-placeholder"></span>
                    @endif

                    <span class="palmares-text">
                        <strong>{{ e($entry['competition_name']) }}</strong>
                        <span class="palmares-division">{{ e($entry['division_name']) }}</span>
                        <span class="palmares-sep">·</span>
                        <span class="palmares-team">{{ e($entry['team_name']) }}</span>
                    </span>

                    @if ($playoffRound !== null)
                        <span class="palmares-playoff {{ $wonPlayoff ? 'palmares-playoff-won' : '' }}">
                            {{ e($playoffRound) }}
                            @if ($wonPlayoff)
                                <i class="fa-solid fa-check"></i>
                            @endif
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</div>
