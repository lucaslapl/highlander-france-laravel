@extends('layouts.overlay')

@section('title', 'Overlay joueur')

@section('content')
    <div class="ov-title">
        {{ $player['name'] ?: $steamid64 }}
    </div>
    <div class="ov-subtitle">Stats officielles · Highlander France</div>

    @foreach (['9v9' => 'Highlander 9v9', '6s' => 'Sixes 6s'] as $category => $label)
        @php $s = $stats[$category]; @endphp
        <div class="ov-side-label ov-side-label--{{ $category === '6s' ? 'blue' : 'red' }}">
            <span>{{ $label }}</span>
        </div>
        @if ($s['matches'] === 0)
            <div class="ov-empty">Aucune partie officielle.</div>
        @else
            <div class="ov-cards">
                <div class="ov-card">
                    <div class="ov-card__value">{{ $s['matches'] }}</div>
                    <div class="ov-card__label">Parties</div>
                </div>
                <div class="ov-card">
                    <div class="ov-card__value">{{ $s['kills'] }}</div>
                    <div class="ov-card__label">Destructions</div>
                </div>
                <div class="ov-card">
                    <div class="ov-card__value">{{ $s['kd'] }}</div>
                    <div class="ov-card__label">K/D</div>
                </div>
                <div class="ov-card">
                    <div class="ov-card__value">{{ number_format($s['dpm'], 0, ',', ' ') }}</div>
                    <div class="ov-card__label">DPM</div>
                </div>
                <div class="ov-card">
                    <div class="ov-card__value">{{ $s['winrate'] !== null ? $s['winrate'] . '%' : '—' }}</div>
                    <div class="ov-card__label">Winrate ({{ $s['wins'] }}V/{{ $s['losses'] }}D)</div>
                </div>
                <div class="ov-card">
                    <div class="ov-card__value">{{ $s['airshots'] }}</div>
                    <div class="ov-card__label">Aériens</div>
                </div>
                <div class="ov-card">
                    <div class="ov-card__value">{{ $s['captures'] }}</div>
                    <div class="ov-card__label">Captures</div>
                </div>
                <div class="ov-card">
                    <div class="ov-card__value">{{ number_format($s['heal'], 0, ',', ' ') }}</div>
                    <div class="ov-card__label">Soins</div>
                </div>
            </div>
        @endif
    @endforeach

    <div class="ov-footer">Highlander France · stats officielles</div>
@endsection
