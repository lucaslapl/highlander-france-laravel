@php
    $validPalmares = collect($palmares ?? [])->filter(fn ($p) => !empty($p['placement']) || !empty($p['playoff_round']));
    if ($validPalmares->isEmpty()) {
        return;
    }

    $grouped = $validPalmares->groupBy('game_mode');
    $modeLabels = ['9v9' => 'Highlander', '6s' => '6v6'];
    $modeOrder = ['9v9', '6s'];

    $placementIcons = [
        1 => ['icon' => 'fa-solid fa-trophy', 'class' => 'palmares-gold',   'label' => '1er'],
        2 => ['icon' => 'fa-solid fa-medal',  'class' => 'palmares-silver', 'label' => '2ème'],
        3 => ['icon' => 'fa-solid fa-award',  'class' => 'palmares-bronze', 'label' => '3ème'],
    ];
@endphp

<div class="profile-palmares">
    <h4><i class="fa-solid fa-trophy"></i> Palmarès</h4>

    @foreach ($modeOrder as $mode)
        @if (! isset($grouped[$mode]))
            @continue
        @endif

        @php
            $entries = $grouped[$mode];
            $sorted = $entries->sortByDesc('season_time')->values();
        @endphp

        <div class="palmares-section">
            <span class="palmares-mode">{{ $modeLabels[$mode] ?? $mode }}</span>

            @foreach ($sorted as $entry)
                @php
                    $placement = $entry['placement'] ?? null;
                    $playoffRound = $entry['playoff_round'] ?? null;
                    $wonPlayoff = !empty($entry['won_playoff']);

                    // Fallback : dériver le placement si non stocké en base.
                    if ($placement === null && $playoffRound !== null) {
                        if ($wonPlayoff && in_array($playoffRound, ['Grande Finale', 'Finale'], true)) {
                            $placement = 1;
                        } elseif (! $wonPlayoff && in_array($playoffRound, ['Grande Finale', 'Finale'], true)) {
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
