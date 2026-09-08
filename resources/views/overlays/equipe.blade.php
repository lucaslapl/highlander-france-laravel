@extends('layouts.overlay')

@section('title', 'Overlay équipe')

@section('content')
    <div class="ov-title">{{ $team['name'] }} <span class="ov-badge">{{ $category === '6s' ? '6s' : '9v9' }}</span></div>
    <div class="ov-subtitle">Stats officielles · logs ETF2L / France</div>

    <div class="ov-cards">
        <div class="ov-card">
            <div class="ov-card__value">{{ $stats['matches'] }}</div>
            <div class="ov-card__label">Parties</div>
        </div>
        <div class="ov-card">
            <div class="ov-card__value">{{ $stats['kills'] }}</div>
            <div class="ov-card__label">Destructions</div>
        </div>
        <div class="ov-card">
            <div class="ov-card__value">{{ $stats['kd'] }}</div>
            <div class="ov-card__label">K/D</div>
        </div>
        <div class="ov-card">
            <div class="ov-card__value">{{ number_format($stats['dpm'], 0, ',', ' ') }}</div>
            <div class="ov-card__label">DPM</div>
        </div>
        <div class="ov-card">
            <div class="ov-card__value">{{ $stats['winrate'] !== null ? $stats['winrate'] . '%' : '—' }}</div>
            <div class="ov-card__label">Winrate ({{ $stats['wins'] }}V/{{ $stats['losses'] }}D)</div>
        </div>
        <div class="ov-card">
            <div class="ov-card__value">{{ $stats['airshots'] }}</div>
            <div class="ov-card__label">Aériens</div>
        </div>
        <div class="ov-card">
            <div class="ov-card__value">{{ $stats['captures'] }}</div>
            <div class="ov-card__label">Captures</div>
        </div>
    </div>

    @if ($rows === [])
        <div class="ov-empty">Aucune donnée officielle pour cette équipe pour le moment.</div>
    @else
        <table class="ov-table">
            <thead>
                <tr>
                    <th>Joueur</th>
                    <th class="num">Parties</th>
                    <th class="num">Destr.</th>
                    <th class="num">Décès</th>
                    <th class="num">K/D</th>
                    <th class="num">DPM</th>
                    <th class="num">Dégâts</th>
                    <th class="num">Winrate</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>
                            <div class="ov-player">
                                @if ($row['avatar'])
                                    <img class="ov-avatar" src="{{ $row['avatar'] }}" alt="" loading="lazy">
                                @endif
                                <span class="ov-name">{{ $row['name'] ?: $row['steamid'] }}</span>
                            </div>
                        </td>
                        <td class="num">{{ $row['matches'] }}</td>
                        <td class="num">{{ $row['kills'] }}</td>
                        <td class="num">{{ $row['deaths'] }}</td>
                        <td class="num">{{ $row['kd'] }}</td>
                        <td class="num">{{ number_format($row['dpm'], 0, ',', ' ') }}</td>
                        <td class="num">{{ number_format($row['dmg'], 0, ',', ' ') }}</td>
                        <td class="num">{{ $row['winrate'] !== null ? $row['winrate'] . '%' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="ov-footer">Highlander France · stats officielles</div>
@endsection
