@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@php
@endphp

@section('content')
<div class="matchlog-back-wrap">
    <a href="/" class="matchlog-back">
        <i class="fa-solid fa-arrow-left"></i> Retour à l'accueil
    </a>
</div>

<div class="etf2l-agenda-container">
    <div class="agenda-header flex space-between align-center">
        <h1 style="font-size:1.4em;"><i class="fa-solid fa-calendar-days"></i> Matchs Équipes FR (ETF2L)</h1>
        <span class="badge-live-info">{{ (int)$totalMatches }} match(s)</span>
    </div>

    @if (empty($matches))
        <div class="agenda-empty">
            <p><i class="fa-solid fa-circle-info"></i> Aucun match passé pour le moment.</p>
        </div>
    @else
        <div class="agenda-list">
            @foreach ($matches as $match)
                @php
    $dt = new DateTime('@' . (int)$match['match_date']);
                $dt->setTimezone(new DateTimeZone('Europe/Paris'));
                $dateMatch = $dt->format('d/m/Y');
                $heureMatch = $dt->format('H:i');
                $flag1 = \App\Services\CountryFlags::flag($match['team1_country'] ?? null);
                $flag2 = \App\Services\CountryFlags::flag($match['team2_country'] ?? null);

                $r1 = isset($match['r1']) && $match['r1'] !== null ? (int)$match['r1'] : null;
                $r2 = isset($match['r2']) && $match['r2'] !== null ? (int)$match['r2'] : null;
                $hasScore = $r1 !== null && $r2 !== null;
                $win1 = $hasScore && $r1 > $r2;
                $win2 = $hasScore && $r2 > $r1;
                
@endphp
                <div class="agenda-item flex align-center">

                    <div class="match-date-box text-center">
                        <span class="match-date">{{ $dateMatch }}</span>
                        <span class="match-hour">{{ $heureMatch }}</span>
                    </div>

                    <div class="match-details flex-1">
                        <div class="competition-title">{!! e($match['competition_name']) !!}</div>
                        <div class="teams-line flex align-center">

                            <span class="team-name text-right flex align-center justify-end gap-10{{ $win1 ? ' winner' : '' }}">
                                <img loading="lazy" decoding="async" src="{!! e($flag1) !!}" alt="{!! ucfirst(e($match['team1_country'])) !!}" class="team-flag" title="{!! ucfirst(e($match['team1_country'])) !!}">
                                <span class="truncate-text">{!! e($match['team1_name']) !!}</span>
                            </span>

                            @if ($hasScore)
                                <span class="agenda-score flex align-center gap-10">
                                    <span class="score-value{{ $win1 ? ' score-winner' : '' }}">{{ $r1 }}</span>
                                    <span class="score-sep">-</span>
                                    <span class="score-value{{ $win2 ? ' score-winner' : '' }}">{{ $r2 }}</span>
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
                        <a href="/match/{{ (int)$match['match_id'] }}" class="btn-match-link" title="Voir le match et les rosters">
                            <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    </div>

                </div>
            @endforeach
        </div>
    @endif

    @if ($totalPages > 1)
        <div class="pagination">
            @php
    $pageUrl = static fn(int $p): string => '/matchs' . ($p > 1 ? '?page=' . $p : '');
            $start = max(1, $currentPage - 2);
            $end = min($totalPages, $currentPage + 2);
            
@endphp
            @if ($currentPage > 1)
                <a href="{{ $pageUrl($currentPage - 1) }}" class="page-btn nav">&laquo; Précédent</a>
            @endif
            @if ($start > 1)
                <a href="{{ $pageUrl(1) }}" class="page-btn">1</a>
                @if ($start > 2)<span class="page-ellipsis">…</span>@endif
            @endif
            @php
    for ($p = $start; $p <= $end; $p++): 
@endphp
                @if ($p === $currentPage)
                    <span class="page-btn active">{{ $p }}</span>
                @else
                    <a href="{{ $pageUrl($p) }}" class="page-btn">{{ $p }}</a>
                @endif
            @php
    endfor; 
@endphp
            @if ($end < $totalPages)
                @if ($end < $totalPages - 1)<span class="page-ellipsis">…</span>@endif
                <a href="{{ $pageUrl($totalPages) }}" class="page-btn">{{ $totalPages }}</a>
            @endif
            @if ($currentPage < $totalPages)
                <a href="{{ $pageUrl($currentPage + 1) }}" class="page-btn nav">Suivant &raquo;</a>
            @endif
        </div>
    @endif
</div>

@endsection
