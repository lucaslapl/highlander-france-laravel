@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@php

    /** Badge Vainqueur/Perdant/Égalité ("win"|"loss"|"draw"|null). */
    $resultBadge = static function (?string $result): string {
        if ($result === null) {
            return '';
        }
        $label = $result === 'win' ? 'Vainqueur' : ($result === 'loss' ? 'Perdant' : 'Égalité');

        return '<span class="team-result result-' . e($result) . '">' . e($label) . '</span>';
    };
@endphp


@section('content')
<div class="etf2l-match-header">

    <a href="/" class="matchlog-back">
        <i class="fa-solid fa-arrow-left"></i> Retour à l'accueil
    </a>

    <div class="etf2l-match-title flex align-center gap-10 wrap">
        <h1>
            <span class="team-name">{!! e($match['team1_name']) !!}</span>
            <span class="vs-separator">VS</span>
            <span class="team-name">{!! e($match['team2_name']) !!}</span>
        </h1>
    </div>

    <div class="matchlog-meta flex align-center wrap" data-match-id="{{ (int)$match['match_id'] }}">
        <span class="matchlog-meta-item">
            <i class="fa-regular fa-calendar"></i> {!! e($dateMatch) !!}
        </span>
        <span class="matchlog-meta-item">
            <i class="fa-regular fa-clock"></i> {!! e($heureMatch) !!}
        </span>
        <span class="matchlog-meta-item">
            <i class="fa-solid fa-trophy"></i> {!! e($match['competition_name']) !!}
        </span>
        <a href="https://etf2l.org/matches/{{ (int)$match['match_id'] }}" target="_blank" rel="noopener" class="btn-match-link">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Voir sur ETF2L
        </a>
        <a class="badge-twitch-live" hidden target="_blank" rel="noopener"><i class="fa-brands fa-twitch"></i> EN DIRECT</a>
    </div>

</div>

@if (!empty($mapsData['maps']))
<div class="etf2l-maps-panel">
    <div class="etf2l-maps-head flex align-center gap-10">
        <span class="team-name">{!! e($match['team1_name']) !!}</span>
        {!! $resultBadge($result1 ?? null) !!}
        <span class="vs-separator">VS</span>
        {!! $resultBadge($result2 ?? null) !!}
        <span class="team-name">{!! e($match['team2_name']) !!}</span>
    </div>

    <div class="etf2l-maps-list">
        @foreach ($mapsData['maps'] as $map)
            <div class="etf2l-map-row flex align-center">
                <span class="etf2l-map-label">
                    @if (count($mapsData['maps']) > 1)<b>M{{ (int)$map['order'] }}</b> — @endif
                    {!! e($map['map_display']) !!}
                </span>
                @if (isset($map['team1']) && isset($map['team2']))
                    <span class="etf2l-map-score">
                        <span class="score-value">{{ (int)$map['team1'] }}</span>
                        <span class="score-sep">-</span>
                        <span class="score-value">{{ (int)$map['team2'] }}</span>
                        @if (!empty($map['golden_cap']))
                            <span class="badge-gc">Golden Cap</span>
                        @endif
                    </span>
                @elseif (!empty($map['forfeit']) || !empty($mapsData['is_forfeit']))
                    <span class="etf2l-map-score etf2l-map-pending" title="Forfait / score global uniquement">Forfait</span>
                @else
                    <span class="etf2l-map-score etf2l-map-pending">À jouer</span>
                @endif
            </div>
        @endforeach
    </div>

    @if ($mapsData['r1'] !== null && $mapsData['r2'] !== null)
        <div class="etf2l-map-total flex align-center">
            <span class="etf2l-map-label"><b>Total</b></span>
            <span class="etf2l-map-score">
                <span class="score-value">{{ (int)$mapsData['r1'] }}</span>
                <span class="score-sep">-</span>
                <span class="score-value">{{ (int)$mapsData['r2'] }}</span>
            </span>
        </div>
    @endif
</div>
@endif

<div class="etf2l-roster-grid">
    @foreach ($teams as $team)
        <div class="etf2l-roster-panel">
            <div class="etf2l-roster-head flex align-center gap-10">
                @php
    $flag = \App\Services\CountryFlags::flag($team['country'] ?? null); 
@endphp
                <img loading="lazy" decoding="async" src="{!! e($flag) !!}" alt="{!! e($team['country'] ?? '') !!}" class="team-flag" title="{!! e($team['country'] ?? '') !!}">
                <span class="team-name">{!! e($team['name']) !!}</span>
                {!! $resultBadge(($team['key'] ?? '') === 'team1' ? ($result1 ?? null) : ($result2 ?? null)) !!}
                @if (!empty($team['tag']))
                    <span class="badge-live-info">[{!! e($team['tag']) !!}]</span>
                @endif
            </div>

            @if (empty($team['players']))
                <p class="no-data">Aucun joueur répertorié pour cette équipe.</p>
            @else
                <ul class="etf2l-roster-list">
                    @foreach ($team['players'] as $player)
                        @php
    $pFlag = \App\Services\CountryFlags::flag($player['country'] ?? null); 
@endphp
                        <li class="etf2l-roster-item flex align-center gap-10">
                            <img loading="lazy" decoding="async" src="{!! e($pFlag) !!}" alt="{!! e($player['country'] ?? '') !!}" class="team-flag" title="{!! e($player['country'] ?? '') !!}">
                            <a href="{!! e($player['profile_url']) !!}" class="roster-player-link" {{ $player['exists_on_site'] ? '' : 'target="_blank" rel="noopener"' }}>
                                {!! e($player['name']) !!}
                                @if (!$player['exists_on_site'])
                                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.6em; vertical-align: middle;"></i>
                                @else
                                    <i class="fa-solid fa-user" style="font-size: 0.6em; vertical-align: middle;"></i>
                                @endif
                            </a>
                            <span class="roster-player-role">{!! e($player['role']) !!}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach
</div>

@push('scripts')
@include('partials.twitch-live-script')
@endpush
@endsection
