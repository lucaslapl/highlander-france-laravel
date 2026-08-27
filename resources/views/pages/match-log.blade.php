@extends('layouts.main')

@section('title', $title)
@section('description', $description)
@section('og_type', 'article')

@php
function matchRowHtml(array $p, int $rank): string
{
    $classPlayed = htmlspecialchars($p['class_played']);
    $iconPath = '/_img/classes/' . $classPlayed . '.png';
    $iconExists = is_file(public_path('/_img/classes/') . $classPlayed . '.png');
    $pseudo = !empty($p['display_name']) ? $p['display_name'] : ($p['name'] ?? '');
    $pseudo = !empty($pseudo) ? $pseudo : 'Joueur Steam';
    $pseudoDisplay = htmlspecialchars($pseudo);
    $steamid64 = \App\Services\SteamId::toSteamId64($p['steamid']);
    $kills = (int)$p['kills'];
    $deaths = (int)$p['deaths'];
    $kd = $deaths > 0 ? round($kills / $deaths, 2) : ($kills > 0 ? (float)$kills : 0);

    $iconHtml = $iconExists
        ? '<img src="' . $iconPath . '" alt="' . ucfirst($classPlayed) . '" class="class-icon" title="' . ucfirst($classPlayed) . '">'
        : '<span class="class-unknown" title="' . ucfirst($classPlayed) . '">?</span>';

    $avatarHtml = !empty($p['avatar'])
        ? '<img src="' . htmlspecialchars($p['avatar']) . '" alt="Avatar de ' . $pseudoDisplay . '" class="player-avatar">'
        : '';

    $linkHtml = $steamid64
        ? '<a href="/profile/' . $steamid64 . '" class="player-link">' . $pseudoDisplay . '</a>'
        : '<span class="player-link">' . $pseudoDisplay . '</span>';

    return '<tr>'
        . '<td>' . $rank . '</td>'
        . '<td>' . $iconHtml . '</td>'
        . '<td><div class="player-cell flex align-center gap-10">' . $avatarHtml . $linkHtml . '</div></td>'
        . '<td data-sort-val="' . $kills . '">' . $kills . '</td>'
        . '<td data-sort-val="' . $deaths . '">' . $deaths . '</td>'
        . '<td data-sort-val="' . (int)$p['assists'] . '">' . (int)$p['assists'] . '</td>'
        . '<td data-sort-val="' . (int)$p['dmg'] . '" class="col-dmg">' . number_format((int)$p['dmg'], 0, ',', ' ') . '</td>'
        . '<td data-sort-val="' . (int)$p['heal'] . '">' . number_format((int)$p['heal'], 0, ',', ' ') . '</td>'
        . '<td data-sort-val="' . (int)$p['headshots'] . '">' . (int)$p['headshots'] . '</td>'
        . '<td data-sort-val="' . (int)$p['longest_killstreak'] . '">' . (int)$p['longest_killstreak'] . '</td>'
        . '<td data-sort-val="' . $kd . '">' . $kd . '</td>'
        . '</tr>';
}

function matchTableHeadHtml(): string
{
    return '<thead><tr>'
        . '<th>#</th>'
        . '<th data-sort="text">Classe</th>'
        . '<th data-sort="text">Joueur</th>'
        . '<th data-sort="num">Kills</th>'
        . '<th data-sort="num">Morts</th>'
        . '<th data-sort="num">Assists</th>'
        . '<th data-sort="num">Dégâts</th>'
        . '<th data-sort="num">Soins</th>'
        . '<th data-sort="num">Headshots</th>'
        . '<th data-sort="num">Killstreak</th>'
        . '<th data-sort="num">K/D</th>'
        . '</tr></thead>';
}

function matchRowsHtml(array $players): string
{
    if ($players === []) {
        return '<tbody><tr><td colspan="11" class="no-data">Aucun joueur dans cette équipe.</td></tr></tbody>';
    }

    $html = '<tbody>';
    foreach ($players as $i => $p) {
        $html .= matchRowHtml($p, $i + 1);
    }

    return $html . '</tbody>';
}
@endphp

@section('content')
<div class="matchlog-header">

    <a href="/match-logs" class="matchlog-back">
        <i class="fa-solid fa-arrow-left"></i> Retour aux matchs
    </a>

    <div class="matchlog-title flex align-center gap-10">
        <h2>{!! e($mapDisplay) !!}</h2>
        <span class="matchlog-mode {{ $gameMode === '6S' ? 'mode-6s' : 'mode-9v9' }}">{{ $gameMode === '6S' ? '6v6' : '9v9' }}</span>
    </div>

    <div class="matchlog-meta flex align-center wrap">
        <?php if ($matchDate): ?>
            <span class="matchlog-meta-item">
                <i class="fa-regular fa-calendar"></i> {!! e($matchDate) !!}
            </span>
        <?php endif; ?>

        <?php if ($durationDisplay): ?>
            <span class="matchlog-meta-item">
                <i class="fa-regular fa-clock"></i> {!! e($durationDisplay) !!}
            </span>
        <?php endif; ?>

        <span class="matchlog-meta-item">
            <i class="fa-solid fa-users"></i> {{ (int)$playerCount }} joueurs
        </span>

        <a href="https://logs.tf/{{ (int)$logId }}" target="_blank" class="matchlog-logs-tf" rel="noopener">
            <img loading="lazy" decoding="async" src="/_img/logo-logstf.png" alt="Voir sur logs.tf" class="logs-tf-logo">
            Voir sur logs.tf
        </a>
    </div>

    <?php if ($isAdmin): ?>
        <div class="matchlog-admin" style="margin-top: 12px;">
            <button type="button" class="btn-blacklist" data-log-id="{{ (int)$logId }}" data-log-title="Log #{{ (int)$logId }} ({!! e($mapDisplay) !!})">
                <i class="fa-solid fa-ban"></i> Blacklister ce log
            </button>
        </div>
    <?php endif; ?>

</div>

<?php if ($hasTeamData): ?>

    <?php if ($redScore !== null && $blueScore !== null): ?>
        <div class="matchlog-scorebar flex align-center justify-center">
            <span class="score-team score-{{ $teamPanels[0]['key'] }}">{{ $teamPanels[0]['name'] }}</span>
            <span class="score-value score-{{ $teamPanels[0]['key'] }}">{{ $teamPanels[0]['score'] }}</span>
            <span class="score-sep">-</span>
            <span class="score-value score-{{ $teamPanels[1]['key'] }}">{{ $teamPanels[1]['score'] }}</span>
            <span class="score-team score-{{ $teamPanels[1]['key'] }}">{{ $teamPanels[1]['name'] }}</span>
        </div>
    <?php endif; ?>

    <div class="matchlog-teams">
        <?php foreach ($teamPanels as $panel): ?>
            <div class="matchlog-team team-{{ $panel['key'] }}">
                <div class="matchlog-team-head">
                    <div class="team-head-left flex align-center gap-10">
                        <span class="team-name">{{ $panel['name'] }}</span>
                        <?php if ($panel['result']): ?>
                            <span class="team-result result-{{ $panel['result'] }}">
                                {{ $panel['result'] === 'win' ? 'Vainqueur' : ($panel['result'] === 'loss' ? 'Perdant' : 'Égalité') }}
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($panel['score'] !== null): ?>
                        <span class="team-score">{{ $panel['score'] }}</span>
                    <?php endif; ?>
                </div>
                <div class="matchlog-table-wrapper">
                    <table class="matchlog-table">
                        {!! matchTableHeadHtml() !!}
                        {!! matchRowsHtml($panel['players']) !!}
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($otherPlayers !== []): ?>
        <div class="matchlog-team-unassigned">
            <h3>Sans équipe</h3>
            <div class="matchlog-table-wrapper">
                <table class="matchlog-table">
                    {!! matchTableHeadHtml() !!}
                    {!! matchRowsHtml($otherPlayers) !!}
                </table>
            </div>
        </div>
    <?php endif; ?>

<?php else: ?>

    <div class="matchlog-table-wrapper">
        <table id="matchlogTable" class="matchlog-table">
            {!! matchTableHeadHtml() !!}
            {!! matchRowsHtml($players) !!}
        </table>
    </div>

<?php endif; ?>
@endsection

@push('scripts')
@include('partials.match-log-script')
@endpush
