@extends('layouts.overlay')

@section('title', 'Overlay scoreboard')

@section('content')
    @php $sb = $scoreboard; @endphp

    <div class="ov-title">Scoreboard <span class="ov-badge">#{{ $logId }}</span></div>
    <div class="ov-subtitle">
        @if ($sb['redScore'] !== null)
            <span class="ov-chip-red">{{ $sb['redScore'] }}</span>
            <span class="ov-muted">–</span>
            <span class="ov-chip-blue">{{ $sb['blueScore'] }}</span>
        @else
            Log officiel
        @endif
    </div>

    <div class="ov-side-label ov-side-label--red">
        <span>RED</span>
    </div>
    <table class="ov-table">
        <thead>
            <tr>
                <th>Joueur</th>
                <th class="num">K</th>
                <th class="num">D</th>
                <th class="num">K/D</th>
                <th class="num">Dégâts</th>
                <th class="num">DPM</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sb['red'] as $p)
                <tr>
                    <td>
                        <div class="ov-player">
                            @if ($p['avatar'])
                                <img class="ov-avatar" src="{{ $p['avatar'] }}" alt="" loading="lazy">
                            @endif
                            <span class="ov-name">{{ $p['name'] }}</span>
                        </div>
                    </td>
                    <td class="num">{{ $p['kills'] }}</td>
                    <td class="num">{{ $p['deaths'] }}</td>
                    <td class="num">{{ $p['kd'] }}</td>
                    <td class="num">{{ number_format($p['dmg'], 0, ',', ' ') }}</td>
                    <td class="num">{{ $p['dapm'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="ov-empty">Aucun joueur RED.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="ov-side-label ov-side-label--blue">
        <span>BLU</span>
    </div>
    <table class="ov-table">
        <thead>
            <tr>
                <th>Joueur</th>
                <th class="num">K</th>
                <th class="num">D</th>
                <th class="num">K/D</th>
                <th class="num">Dégâts</th>
                <th class="num">DPM</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sb['blue'] as $p)
                <tr>
                    <td>
                        <div class="ov-player">
                            @if ($p['avatar'])
                                <img class="ov-avatar" src="{{ $p['avatar'] }}" alt="" loading="lazy">
                            @endif
                            <span class="ov-name">{{ $p['name'] }}</span>
                        </div>
                    </td>
                    <td class="num">{{ $p['kills'] }}</td>
                    <td class="num">{{ $p['deaths'] }}</td>
                    <td class="num">{{ $p['kd'] }}</td>
                    <td class="num">{{ number_format($p['dmg'], 0, ',', ' ') }}</td>
                    <td class="num">{{ $p['dapm'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="ov-empty">Aucun joueur BLU.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="ov-footer">Highlander France · log officiel #{{ $logId }}</div>
@endsection
