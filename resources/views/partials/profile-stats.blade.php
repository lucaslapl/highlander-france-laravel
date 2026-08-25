
<div class="profile-tabs">
    <button type="button" class="profile-tab-btn active" onclick="switchProfileMode(this, '9v9', '{!! e($steamid64) !!}')">Highlander (9v9)</button>
    <button type="button" class="profile-tab-btn" onclick="switchProfileMode(this, '6s', '{!! e($steamid64) !!}')">Sixes (6v6)</button>
</div>

<br>

<div class="player-stats">
    <h3 id="stats-title">Stats - Highlander</h3>

    <div class="stats-grid stats-key">
        <div class="box-stats matches-played">
            <p class="stat-label">Matchs joués</p>
            <p class="stat-value"><b id="stat-total-matches">{{ (int)$stats['total_matches'] }}</b></p>
        </div>

        <div class="box-stats damage-dealt">
            <p class="stat-label">Dégâts / min</p>
            <p class="stat-value"><span id="stat-total-damage">{{ number_format((float)$stats['average_dpm'], 1, ',', ' ') }}</span></p>
        </div>

        <div class="box-stats kills">
            <p class="stat-label">Kills</p>
            <p class="stat-value"><span id="stat-total-kills">{{ (int)$stats['total_kills'] }}</span></p>
        </div>

        <div class="box-stats deaths">
            <p class="stat-label">Morts</p>
            <p class="stat-value"><span id="stat-total-deaths">{{ (int)$stats['total_deaths'] }}</span></p>
        </div>

        <div class="box-stats kd-ratio">
            <p class="stat-label">Ratio K/D</p>
            <p class="stat-value"><span id="stat-kd-ratio">{{ (float)$stats['kd_ratio'] }}</span></p>
        </div>
    </div>

    <div class="stats-grid stats-combat">
        <div class="box-stats combat-airshots">
            <p class="stat-label">Airshots</p>
            <p class="stat-value"><span id="stat-combat-airshots">{{ (int)$stats['total_airshots'] }}</span></p>
        </div>
    </div>

    <div class="stats-grid stats-lists">
        <div class="box-stats classes-played">
            <p class="box-title">Classes jouées</p>
            <div id="classes-container">
                @if (empty($stats['classes_played']))
                    <p class="no-data">Aucune donnée de classe pour le moment.</p>
                @else
                    <ul class="stats-list">
                        @foreach ($stats['classes_played'] as $class)
                            @php
    $classNameBrut = e($class['class_played']); 
@endphp
                            <li class="flex space-between align-center">
                                <div class="flex align-center gap-10">
                                    <img loading="lazy" decoding="async" src="/_img/classes/{{ $classNameBrut }}.png" alt="{{ ucfirst($classNameBrut) }}" class="class-icon" title="{{ ucfirst($classNameBrut) }}">
                                </div>
                                <span class="stat-value">{{ (int)$class['total'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="box-stats maps-played">
            <p class="box-title">Maps jouées</p>
            <div id="maps-container">
                @if (empty($stats['top_maps']))
                    <p class="no-data">Aucune donnée de map pour le moment.</p>
                @else
                    <div class="maps-chart">
                        <canvas id="maps-chart-canvas"></canvas>
                    </div>
                @endif
            </div>
        </div>

        <div class="box-stats classes-killed">
            <p class="box-title">Classes tuées</p>
            <div id="classes-killed-container">
                @if (empty($stats['classes_killed']))
                    <p class="no-data">Aucune donnée de classe tuée pour le moment.</p>
                @else
                    <div class="classes-killed-chart">
                        <canvas id="classes-killed-chart-canvas"></canvas>
                    </div>
                    <ul class="classes-killed-legend"></ul>
                @endif
            </div>
        </div>
    </div>

    <div class="recent-matches">
        <h3 id="recent-title">Matchs Récents (9v9)</h3>
        <div id="recent-container">
            @if (empty($stats['recent_matches']))
                <p class="no-data">Aucun match enregistré pour le moment.</p>
            @else
                <table class="matches-table">
                    <thead>
                        <tr>
                            <th>Classe</th>
                            <th>Résultat</th>
                            <th>Map</th>
                            <th>K/D/A</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stats['recent_matches'] as $match)
                            @php
    $mId = e((string)$match['match_id']);
                            $cPlayed = e($match['class_played']);
                            $won = $match['won'] ?? null;
                            $resultClass = $won === 1 ? 'result-win' : ($won === 0 ? 'result-loss' : 'result-unknown');
                            $resultLabel = $won === 1 ? 'Victoire' : ($won === 0 ? 'Défaite' : '—');
                            
@endphp
                            <tr class="match-row" data-href="/log/{{ $mId }}">
                                <td data-label="Classe">
                                    <img loading="lazy" decoding="async" src="/_img/classes/{{ $cPlayed }}.png" alt="{{ ucfirst($cPlayed) }}" class="class-icon" title="Joué en {{ ucfirst($cPlayed) }}">
                                    <span>{{ ucfirst($cPlayed) }}</span>
                                </td>
                                <td data-label="Résultat"><span class="match-result {{ $resultClass }}">{{ $resultLabel }}</span></td>
                                <td data-label="Map">{!! e($match['map_name']) !!}</td>
                                <td data-label="K/D/A">{{ (int)$match['kills'] }} / {{ (int)$match['deaths'] }} / {{ (int)$match['assists'] }}</td>
                                <td data-label="Date">{!! e($match['match_date'] ?? '—') !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>
