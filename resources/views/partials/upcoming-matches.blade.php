<div class="etf2l-agenda-container">
    <div class="agenda-header flex space-between align-center">
        <h3><i class="fa-solid fa-calendar-days"></i> Matchs Équipes FR (ETF2L)</h3>
        <span class="badge-live-info">Prochains matchs</span>
    </div>

    @if (empty($prochainsMatchs))
        <div class="agenda-empty">
            <p><i class="fa-solid fa-circle-info"></i> Aucun match de prévu pour le moment.</p>
        </div>
    @else
        <div class="agenda-list">
            @foreach ($prochainsMatchs as $match)
                @php
    $dt = new DateTime('@' . $match['match_date']);
                $dt->setTimezone(new DateTimeZone('Europe/Paris'));
                $dateMatch = $dt->format('d/m');
                $heureMatch = $dt->format('H:i');
                $flag1 = \App\Services\CountryFlags::flag($match['team1_country'] ?? null);
                $flag2 = \App\Services\CountryFlags::flag($match['team2_country'] ?? null);
                
@endphp
                <div class="agenda-item flex align-center">

                    <div class="match-date-box text-center">
                        <span class="match-date">{{ $dateMatch }}</span>
                        <span class="match-hour">{{ $heureMatch }}</span>
                    </div>

                    <div class="match-details flex-1">
                        <div class="competition-title">{!! e($match['competition_name']) !!}</div>
                        <div class="teams-line flex align-center">

                            <span class="team-name text-right flex align-center justify-end gap-10">
                                <img loading="lazy" decoding="async" src="{!! e($flag1) !!}" alt="{!! ucfirst(e($match['team1_country'])) !!}" class="team-flag" title="{!! ucfirst(e($match['team1_country'])) !!}">
                                <span class="truncate-text">{!! e($match['team1_name']) !!}</span>
                            </span>

                            <span class="vs-separator">VS</span>

                            <span class="team-name text-left flex align-center gap-10">
                                <span class="truncate-text">{!! e($match['team2_name']) !!}</span>
                                <img loading="lazy" decoding="async" src="{!! e($flag2) !!}" alt="{!! ucfirst(e($match['team2_country'])) !!}" class="team-flag" title="{!! ucfirst(e($match['team2_country'])) !!}">
                            </span>

                        </div>
                    </div>

                    <div class="match-action">
                        <a href="/match/{{ (int)$match['match_id'] }}" class="btn-match-link" title="Voir le match et les rosters">
                            <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    </div>

                </div>
            @endforeach
        </div>
    @endif

    @if (!empty($matchsRecents))
        <div class="agenda-recent">
            <div class="agenda-subtitle"><i class="fa-solid fa-flag-checkered"></i> Terminés depuis moins de 48 h</div>
            <div class="agenda-list">
                @foreach ($matchsRecents as $match)
                    @php
    $dt = new DateTime('@' . (int)$match['match_date']);
                    $dt->setTimezone(new DateTimeZone('Europe/Paris'));
                    $flag1 = \App\Services\CountryFlags::flag($match['team1_country'] ?? null);
                    $flag2 = \App\Services\CountryFlags::flag($match['team2_country'] ?? null);

                    $r1 = isset($match['r1']) && $match['r1'] !== null ? (int)$match['r1'] : null;
                    $r2 = isset($match['r2']) && $match['r2'] !== null ? (int)$match['r2'] : null;
                    $hasScore = $r1 !== null && $r2 !== null;
                    $win1 = $hasScore && $r1 > $r2;
                    $win2 = $hasScore && $r2 > $r1;
                    $resClass = !$hasScore ? ' res-noscore' : ($win1 ? ' res-win1' : ($win2 ? ' res-win2' : ' res-draw'));
                    
@endphp
                    <div class="agenda-item agenda-item-compact flex align-center{{ $resClass }}">

                        <div class="match-date-box text-center">
                            <span class="match-date">{{ $dt->format('d/m') }}</span>
                            <span class="match-hour">{{ $dt->format('H:i') }}</span>
                        </div>

                        <div class="match-details flex-1">
                            <div class="teams-line flex align-center">

                                <span class="team-name text-right flex align-center justify-end gap-10{{ $win1 ? ' winner' : '' }}">
                                    <img loading="lazy" decoding="async" src="{!! e($flag1) !!}" alt="{!! ucfirst(e($match['team1_country'])) !!}" class="team-flag" title="{!! ucfirst(e($match['team1_country'])) !!}">
                                    <span class="truncate-text">{!! e($match['team1_name']) !!}</span>
                                </span>

                                @if ($hasScore)
                                    <span class="agenda-score flex align-center gap-10">
                                        <span class="score-value{{ $win1 ? ' score-win' : (!$win2 ? '' : ' score-loss') }}">{{ $r1 }}</span>
                                        <span class="score-sep">-</span>
                                        <span class="score-value{{ $win2 ? ' score-win' : (!$win1 ? '' : ' score-loss') }}">{{ $r2 }}</span>
                                    </span>
                                @else
                                    <span class="vs-separator">VS</span>
                                @endif

                                <span class="team-name text-left flex align-center gap-10{{ $win2 ? ' winner' : '' }}">
                                    <span class="truncate-text">{!! e($match['team2_name']) !!}</span>
                                    <img loading="lazy" decoding="async" src="{!! e($flag2) !!}" alt="{!! ucfirst(e($match['team2_country'])) !!}" class="team-flag" title="{!! ucfirst(e($match['team2_country'])) !!}">
                                </span>

                            </div>
                        </div>

                        <div class="match-action">
                            <a href="/match/{{ (int)$match['match_id'] }}" class="btn-match-link" title="Voir le résultat et les rosters">
                                <i class="fa-solid fa-chevron-right"></i>
                            </a>
                        </div>

                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="agenda-footer">
        <a href="/matchs" class="agenda-footer-link">
            <i class="fa-solid fa-clock-rotate-left"></i> Voir tous les matchs passés
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>
</div>
