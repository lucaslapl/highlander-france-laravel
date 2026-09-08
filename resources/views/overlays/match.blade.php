@extends('layouts.overlay')

@section('title', 'Overlay match')

@section('content')
    @if ($match === [])
        <div class="ov-empty">Match introuvable.</div>
    @else
        <div class="ov-title">
            {{ $match['team1_name'] }}
            <span class="ov-chip-red">{{ (int) $match['r1'] }}</span>
            <span class="ov-muted">–</span>
            <span class="ov-chip-blue">{{ (int) $match['r2'] }}</span>
            {{ $match['team2_name'] }}
        </div>
        <div class="ov-subtitle">{{ $match['competition_name'] ?? 'ETF2L' }}</div>

        @forelse ($maps as $index => $map)
            <div class="ov-side-label ov-side-label--{{ $index % 2 === 0 ? 'red' : 'blue' }}">
                <span>Match {{ $index + 1 }}</span>
                <span class="ov-score">
                    @if ($map['red_score'] !== null)
                        <span class="ov-chip-red">{{ $map['red_score'] }}</span> – <span class="ov-chip-blue">{{ $map['blue_score'] }}</span>
                    @else
                        —
                    @endif
                </span>
            </div>

            @php $sb = $map['scoreboard']; @endphp
            <table class="ov-table">
                <thead>
                    <tr>
                        <th>RED</th>
                        <th class="num">K</th>
                        <th class="num">D</th>
                        <th class="num">Dégâts</th>
                        <th class="num">DPM</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_slice($sb['red'], 0, 9) as $p)
                        <tr>
                            <td><span class="ov-chip-red">▲</span> {{ $p['name'] }}</td>
                            <td class="num">{{ $p['kills'] }}</td>
                            <td class="num">{{ $p['deaths'] }}</td>
                            <td class="num">{{ number_format($p['dmg'], 0, ',', ' ') }}</td>
                            <td class="num">{{ $p['dapm'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <table class="ov-table">
                <thead>
                    <tr>
                        <th>BLU</th>
                        <th class="num">K</th>
                        <th class="num">D</th>
                        <th class="num">Dégâts</th>
                        <th class="num">DPM</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_slice($sb['blue'], 0, 9) as $p)
                        <tr>
                            <td><span class="ov-chip-blue">▲</span> {{ $p['name'] }}</td>
                            <td class="num">{{ $p['kills'] }}</td>
                            <td class="num">{{ $p['deaths'] }}</td>
                            <td class="num">{{ number_format($p['dmg'], 0, ',', ' ') }}</td>
                            <td class="num">{{ $p['dapm'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @empty
            <div class="ov-empty">Aucun log officiel rattaché à ce match.</div>
        @endforelse
    @endif

    <div class="ov-footer">Highlander France · log officiel</div>
@endsection
