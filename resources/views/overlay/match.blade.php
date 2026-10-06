{{-- Vue overlay OBS (1920x1080, fond transparent). Page autonome : pas de
     layout site, aucun chrome — tout est composé dans le stage. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ hlfr_asset('/_css/overlay_match.css') }}">
</head>
<body>
@php
    // Style réglé depuis le panel : opacité des panneaux et flou d'arrière-plan.
    $style = is_array($overlay['style'] ?? null) ? $overlay['style'] : [];
    $opacity = (int) ($style['opacity'] ?? 70);
    $blur = (int) ($style['blur'] ?? 6);
    $panel = (bool) ($style['panel'] ?? true);

    $fmt = static fn (int $n): string => number_format($n, 0, ',', ' ');
    $medicLength = static function (?float $v): string {
        return $v === null ? '—' : number_format($v, 1, ',', ' ').' s';
    };
@endphp
<div class="overlay-stage{{ $panel ? '' : ' overlay-stage--no-panel' }}"
     style="--overlay-opacity: {{ $opacity }}%; --overlay-blur: {{ $blur }}px;">

    <header class="overlay-header">
        @foreach (['blue', 'red'] as $team)
            @php
                $teamData = $overlay['teams'][$team];
            @endphp
            <div class="overlay-team overlay-team--{{ $team }}">
                @if (! empty($avatars[$team]))
                    <img class="overlay-team__avatar" src="{{ $avatars[$team] }}" alt="">
                @endif
                <span class="overlay-team__name">{{ e($teamData['name']) }}</span>
                <span class="overlay-team__score overlay-team__score--{{ $team }}">{{ (int) $teamData['score'] }}</span>
            </div>
            @if ($team === 'blue')
                <div class="overlay-header__center">
                    <span class="overlay-header__vs">vs</span>
                </div>
            @endif
        @endforeach
    </header>

    @if (($overlay['map'] ?? '') !== '')
        <p class="overlay-map">
            {{ e($overlay['map']) }}
            @if ((int) ($overlay['length'] ?? 0) > 0)
                · {{ intdiv((int) $overlay['length'], 60) }} min
            @endif
        </p>
    @endif

    <main class="overlay-teams">
        @foreach (['blue', 'red'] as $team)
            @if (($overlay['players'][$team] ?? []) === [])
                @continue
            @endif
            <section class="overlay-panel overlay-panel--{{ $team }}">
                <table class="overlay-stats">
                    {{-- En table-layout:fixed, les largeurs de colonnes se règlent
                         UNIQUEMENT ici (colgroup) : un seul endroit à modifier. --}}
                    <colgroup>
                        <col class="c-class">
                        <col class="c-name">
                        <col class="c-kad">
                        <col class="c-kad">
                        <col class="c-kad">
                        <col class="c-dmg">
                        <col class="c-dpm">
                        <col class="c-hr">
                        <col class="c-dt">
                        <col class="c-kd">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="c-class"></th>
                            <th class="c-name" data-key="name">Joueur</th>
                            <th class="c-kad" data-key="kills">K</th>
                            <th class="c-kad" data-key="assists">A</th>
                            <th class="c-kad" data-key="deaths">D</th>
                            <th class="c-dmg" data-key="dmg">DMG</th>
                            <th class="c-dpm" data-key="dapm">DPM</th>
                            <th class="c-hr" data-key="hr">HEAL</th>
                            <th class="c-dt" data-key="dt">DT</th>
                            <th class="c-kd" data-key="kd">K/D</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($overlay['players'][$team] as $player)
                            @php
                                $best = is_array($player['best'] ?? null) ? $player['best'] : [];
                                $bestClass = static fn (string $stat): string => in_array($stat, $best, true) ? ' best' : '';
                                $classIcon = '/_img/classes/'.e($player['class']).'.png';
                            @endphp
                            {{-- Valeurs brutes en data-* : le JS de tri a besoin
                                 de nombres, pas des cellules formatées. --}}
                            <tr data-name="{{ e($player['name']) }}"
                                data-kills="{{ (int) $player['kills'] }}"
                                data-assists="{{ (int) $player['assists'] }}"
                                data-deaths="{{ (int) $player['deaths'] }}"
                                data-dmg="{{ (int) $player['dmg'] }}"
                                data-dapm="{{ (int) $player['dapm'] }}"
                                data-hr="{{ (int) $player['hr'] }}"
                                data-dt="{{ (int) $player['dt'] }}"
                                data-kd="{{ (float) $player['kd'] }}">
                                <td class="c-class">
                                    @if (is_file(public_path('/_img/classes/').(string) $player['class'].'.png'))
                                        <img src="{{ $classIcon }}" alt="" title="{{ ucfirst(e($player['class'])) }}">
                                    @endif
                                </td>
                                <td class="c-name">{{ e($player['name']) }}</td>
                                <td class="num c-kad{{ $bestClass('kills') }}">{{ $fmt((int) $player['kills']) }}</td>
                                <td class="num c-kad{{ $bestClass('assists') }}">{{ $fmt((int) $player['assists']) }}</td>
                                <td class="num c-kad{{ $bestClass('deaths') }}">{{ $fmt((int) $player['deaths']) }}</td>
                                <td class="num c-dmg{{ $bestClass('dmg') }}">{{ $fmt((int) $player['dmg']) }}</td>
                                <td class="num c-dpm{{ $bestClass('dapm') }}">{{ $fmt((int) $player['dapm']) }}</td>
                                <td class="num c-hr{{ $bestClass('hr') }}">{{ $fmt((int) $player['hr']) }}</td>
                                <td class="num c-dt{{ $bestClass('dt') }}">{{ $fmt((int) $player['dt']) }}</td>
                                <td class="num c-kd{{ $bestClass('kd') }}">{{ number_format((float) $player['kd'], 2, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endforeach
    </main>

    <footer class="overlay-medics">
        @foreach (['blue', 'red'] as $team)
            @php
                $medic = $overlay['medics'][$team] ?? null;
            @endphp
            <section class="overlay-medic overlay-medic--{{ $team }}">
                <span class="overlay-medic__icon"><img src="/_img/classes/medic.png" alt="Medic"></span>
                @if ($medic === null || (int) ($medic['count'] ?? 0) === 0)
                    <span class="overlay-medic__empty">Aucun medic</span>
                @else
                    <div class="overlay-medic__stat">
                        <span class="overlay-medic__value">{{ $fmt((int) $medic['heal']) }}</span>
                        <span class="overlay-medic__label">Healing</span>
                    </div>
                    <div class="overlay-medic__stat">
                        <span class="overlay-medic__value">{{ $fmt((int) $medic['ubers']) }}</span>
                        <span class="overlay-medic__label">Ubers</span>
                    </div>
                    <div class="overlay-medic__stat">
                        <span class="overlay-medic__value">{{ $fmt((int) $medic['drops']) }}</span>
                        <span class="overlay-medic__label">Drop{{ (int) $medic['drops'] > 1 ? 's' : '' }}</span>
                    </div>
                    <div class="overlay-medic__stat">
                        <span class="overlay-medic__value">{{ $medicLength($medic['avg_uber_length'] ?? null) }}</span>
                        <span class="overlay-medic__label">Durée Uber</span>
                    </div>
                @endif
            </section>
        @endforeach
    </footer>
</div>

<script src="{{ hlfr_asset('/_js/overlay_match.js') }}" defer
        data-token="{{ $overlay['token'] }}" data-version="{{ (int) ($overlay['version'] ?? 0) }}"></script>
</body>
</html>
