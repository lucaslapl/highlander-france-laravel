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

    // L'API ETF2L renvoie des noms parfois déjà encodés en entités HTML ("&amp;", "&#039;", …).
    // {{ }} ré-échappe la sortie : on décode donc une première fois pour éviter un
    // affichage littéral de "&amp;" (surtout ne PAS wrapper dans e() ici, sinon double-échappement).
    $esc = fn ($s) => html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
@endphp

<div class="profile-palmares">
    <h4><i class="fa-solid fa-trophy"></i> Palmarès</h4>

    @foreach ($modeOrder as $mode)
        @if (! isset($grouped[$mode]))
            @continue
        @endif

        @php
            $entries = $grouped[$mode];
            $seasonEntries = $entries->filter(fn ($e) => stripos($e['competition_name'] ?? '', 'Nations Cup') === false)->sortByDesc('season_time')->values();
            $nationsEntries = $entries->filter(fn ($e) => stripos($e['competition_name'] ?? '', 'Nations Cup') !== false)->sortByDesc('season_time')->values();
            $lastYear = null;
        @endphp

        <div class="palmares-section">
            <span class="palmares-mode">{{ $modeLabels[$mode] ?? $mode }}</span>

            @foreach ($seasonEntries as $entry)
                @php
                    $placement = $entry['placement'] ?? null;
                    $playoffRound = $entry['playoff_round'] ?? null;
                    $wonPlayoff = !empty($entry['won_playoff']);
                    if ($placement === null && $playoffRound !== null) {
                        if ($wonPlayoff && in_array($playoffRound, ['Grande Finale', 'Finale'], true)) {
                            $placement = 1;
                        } elseif (! $wonPlayoff && in_array($playoffRound, ['Grande Finale', 'Finale'], true)) {
                            $placement = 2;
                        }
                    }
                    $pInfo = $placement !== null ? ($placementIcons[$placement] ?? null) : null;
                    $year = !empty($entry['season_time']) ? date('Y', (int) $entry['season_time']) : null;
                    $isNewYear = $year !== null && $year !== $lastYear;
                    if ($isNewYear) {
                        $lastYear = $year;
                    }
                @endphp
                <div class="palmares-entry">
                    <span class="palmares-year {{ $isNewYear ? '' : 'palmares-year-empty' }}">{{ $year }}</span>
                    @if ($pInfo !== null)
                        <i class="{{ $pInfo['icon'] }} palmares-medal-icon {{ $pInfo['class'] }}" title="{{ $pInfo['label'] }}"></i>
                    @else
                        <span class="palmares-medal-placeholder"></span>
                    @endif
                    <span class="palmares-text">
                        <strong>{{ $esc($entry['competition_name']) }}</strong>
                        <span class="palmares-division">{{ $esc($entry['division_name']) }}</span>
                        <span class="palmares-sep">·</span>
                        <span class="palmares-team">{{ $esc($entry['team_name']) }}</span>
                    </span>
                    @if ($playoffRound !== null)
                        <span class="palmares-playoff {{ $wonPlayoff ? 'palmares-playoff-won' : '' }}">
                            {{ $esc($playoffRound) }}
                            @if ($wonPlayoff)
                                <i class="fa-solid fa-check"></i>
                            @endif
                        </span>
                    @endif
                </div>
            @endforeach

            @if ($nationsEntries->isNotEmpty())
                @php $lastYearNations = null; @endphp
                <div class="palmares-nations">
                    <span class="palmares-nations-label"><i class="fa-solid fa-flag"></i> Coupe des Nations</span>
                    @foreach ($nationsEntries as $entry)
                        @php
                            $placement = $entry['placement'] ?? null;
                            $playoffRound = $entry['playoff_round'] ?? null;
                            $wonPlayoff = !empty($entry['won_playoff']);
                            if ($placement === null && $playoffRound !== null) {
                                if ($wonPlayoff && in_array($playoffRound, ['Grande Finale', 'Finale'], true)) {
                                    $placement = 1;
                                } elseif (! $wonPlayoff && in_array($playoffRound, ['Grande Finale', 'Finale'], true)) {
                                    $placement = 2;
                                }
                            }
                            $pInfo = $placement !== null ? ($placementIcons[$placement] ?? null) : null;
                            $year = !empty($entry['season_time']) ? date('Y', (int) $entry['season_time']) : null;
                            $isNewYear = $year !== null && $year !== $lastYearNations;
                            if ($isNewYear) {
                                $lastYearNations = $year;
                            }
                        @endphp
                        <div class="palmares-entry palmares-entry-nations">
                            <span class="palmares-year {{ $isNewYear ? '' : 'palmares-year-empty' }}">{{ $year }}</span>
                            @if ($pInfo !== null)
                                <i class="{{ $pInfo['icon'] }} palmares-medal-icon {{ $pInfo['class'] }}" title="{{ $pInfo['label'] }}"></i>
                            @else
                                <span class="palmares-medal-placeholder"></span>
                            @endif
                            <span class="palmares-text">
                                <strong>{{ $esc($entry['competition_name']) }}</strong>
                                <span class="palmares-division">{{ $esc($entry['division_name']) }}</span>
                                <span class="palmares-sep">·</span>
                                <span class="palmares-team">{{ $esc($entry['team_name']) }}</span>
                            </span>
                            @if ($playoffRound !== null)
                                <span class="palmares-playoff {{ $wonPlayoff ? 'palmares-playoff-won' : '' }}">
                                    {{ $esc($playoffRound) }}
                                    @if ($wonPlayoff)
                                        <i class="fa-solid fa-check"></i>
                                    @endif
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach
</div>
