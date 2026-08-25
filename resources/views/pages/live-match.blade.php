@extends('layouts.main')

@section('title', $title)
@section('description', $description)


@php
function liveRowHtml(array $p, int $rank): string
{
    $classKey = htmlspecialchars((string)($p['class'] ?? ''));
    $iconPath = '/_img/classes/' . $classKey . '.png';
    $iconExists = $classKey !== '' && is_file(public_path('/_img/classes/') . $classKey . '.png');

    $pseudo = !empty($p['display_name']) ? $p['display_name'] : ($p['name'] ?? '');
    $pseudo = !empty($pseudo) ? $pseudo : 'Joueur Steam';
    $pseudoDisplay = htmlspecialchars($pseudo);
    $score = (int)($p['score'] ?? 0);

    $iconHtml = $iconExists
        ? '<img src="' . $iconPath . '" alt="' . ucfirst($classKey) . '" class="class-icon" title="' . ucfirst($classKey) . '">'
        : '<span class="class-unknown" title="Aucune classe">?</span>';

    $avatarHtml = !empty($p['avatar'])
        ? '<img src="' . htmlspecialchars($p['avatar']) . '" alt="Avatar de ' . $pseudoDisplay . '" class="player-avatar">'
        : '';

    $linkHtml = !empty($p['steamid64'])
        ? '<a href="/profile/' . htmlspecialchars($p['steamid64']) . '" class="player-link">' . $pseudoDisplay . '</a>'
        : '<span class="player-link">' . $pseudoDisplay . '</span>';

    return '<tr>'
        . '<td>' . $rank . '</td>'
        . '<td>' . $iconHtml . '</td>'
        . '<td><div class="player-cell flex align-center gap-10">' . $avatarHtml . $linkHtml . '</div></td>'
        . '<td data-sort-val="' . $score . '">' . $score . '</td>'
        . '</tr>';
}

function liveRowsHtml(array $players): string
{
    if ($players === []) {
        return '<tbody class="live-tbody"><tr><td colspan="4" class="no-data">Aucun joueur en jeu.</td></tr></tbody>';
    }

    $html = '<tbody class="live-tbody">';
    foreach ($players as $i => $p) {
        $html .= liveRowHtml($p, $i + 1);
    }

    return $html . '</tbody>';
}

$stv = $entry['stv'] ?? null;
@endphp

@section('content')
<div class="matchlog-header">

    <a href="/" class="matchlog-back">
        <i class="fa-solid fa-arrow-left"></i> Retour à l'accueil
    </a>

    <div class="matchlog-title flex align-center gap-10">
        <h2>{!! e($mapDisplay) !!}</h2>
        <span class="live-badge"><span class="live-dot"></span> EN DIRECT</span>
    </div>

    <div class="matchlog-meta flex align-center wrap">
        <span class="matchlog-meta-item">
            <i class="fa-solid fa-server"></i> {!! e($server) !!}
        </span>
        <span class="matchlog-meta-item">
            <i class="fa-regular fa-clock"></i> Début : {!! e($startedAt) !!}
        </span>
        <span class="matchlog-meta-item">
            <i class="fa-solid fa-users"></i> <span id="livePlayerCount">{{ (int)$playerCount }}</span> joueurs
        </span>
    </div>

    <?php if (is_array($stv) && !empty($stv['connect'])): ?>
        <div class="live-stv">
            <a href="{!! e($stv['connect']) !!}" class="live-stv-btn" rel="noopener">
                <i class="fa-solid fa-video"></i> Voir via SourceTV
            </a>
            <?php if (!empty($stv['ip'])): ?>
                <span class="live-stv-hint">{!! e($stv['ip']) !!}:{{ (int)($stv['port'] ?? 0) }}</span>
            <?php endif; ?>
            <?php if (!empty($stv['password'])): ?>
                <span class="live-stv-hint">Mot de passe : {!! e($stv['password']) !!}</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<div id="liveMatchDetail" data-server="{!! e($server) !!}">

    <div class="matchlog-scorebar flex align-center justify-center">
        <span class="score-team score-red">RED</span>
        <span class="score-value score-red" id="liveScoreRed">{{ (int)$redScore }}</span>
        <span class="score-sep">-</span>
        <span class="score-value score-blue" id="liveScoreBlue">{{ (int)$blueScore }}</span>
        <span class="score-team score-blue">BLU</span>
    </div>

    <div class="matchlog-teams">
        <div class="matchlog-team team-red">
            <div class="matchlog-team-head">
                <div class="team-head-left flex align-center gap-10">
                    <span class="team-name">RED</span>
                </div>
                <span class="team-score" id="liveTeamScoreRed">{{ (int)$redScore }}</span>
            </div>
            <div class="matchlog-table-wrapper">
                <table class="matchlog-table">
                    <thead><tr>
                        <th>#</th>
                        <th>Classe</th>
                        <th>Joueur</th>
                        <th>Score</th>
                    </tr></thead>
                    {{ liveRowsHtml($redPlayers) }}
                </table>
            </div>
        </div>

        <div class="matchlog-team team-blue">
            <div class="matchlog-team-head">
                <div class="team-head-left flex align-center gap-10">
                    <span class="team-name">BLU</span>
                </div>
                <span class="team-score" id="liveTeamScoreBlue">{{ (int)$blueScore }}</span>
            </div>
            <div class="matchlog-table-wrapper">
                <table class="matchlog-table">
                    <thead><tr>
                        <th>#</th>
                        <th>Classe</th>
                        <th>Joueur</th>
                        <th>Score</th>
                    </tr></thead>
                    {{ liveRowsHtml($bluePlayers) }}
                </table>
            </div>
        </div>
    </div>

</div>
@endsection
