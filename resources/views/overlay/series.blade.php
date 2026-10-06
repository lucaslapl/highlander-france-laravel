{{-- Vue overlay OBS du scoreboard de série (1920x1080, fond transparent).
     Page autonome : pas de layout site, aucun chrome — le panneau est ancré
     en haut à gauche du canevas pour être positionné/redimensionné dans OBS
     via la taille de la source navigateur. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <script>
        /* Rechargement automatique après une mise à jour de score : on saute
           l'animation d'entrée pour que le panneau reste statique (le drapeau
           est posé par overlay_series.js juste avant le reload). */
        if (sessionStorage.getItem('hlfr-series-refresh') === '1') {
            sessionStorage.removeItem('hlfr-series-refresh');
            document.documentElement.classList.add('series-no-enter');
        }
    </script>
    <link rel="stylesheet" href="{{ hlfr_asset('/_css/overlay_series.css') }}">
</head>
<body>
@php
    // Première map non décidée = map en cours (ou prochaine à jouer) ;
    // les suivantes restent à venir. Le point d'arrêt esthétique « série
    // terminée » s'affiche quand le calcul le dit.
    $currentMapIndex = null;
    foreach ($state['maps'] as $i => $map) {
        if ($map['status'] !== 'decided') {
            $currentMapIndex = $i;
            break;
        }
    }
@endphp
<div class="series-overlay-stage">

    <div class="series-scoreboard{{ ! empty($state['finished']) ? ' series-scoreboard--over' : '' }}">

        <header class="series-scoreboard__header">
            <div class="series-scoreboard__team series-scoreboard__team--red">
                <span class="series-scoreboard__name">{{ e($series['teams']['red']['name']) }}</span>
            </div>
            <div class="series-scoreboard__score">
                <span class="series-scoreboard__score-value series-scoreboard__score-value--red">{{ (int) $state['score']['red'] }}</span>
                <span class="series-scoreboard__score-sep">–</span>
                <span class="series-scoreboard__score-value series-scoreboard__score-value--blue">{{ (int) $state['score']['blue'] }}</span>
            </div>
            <div class="series-scoreboard__team series-scoreboard__team--blue">
                <span class="series-scoreboard__name">{{ e($series['teams']['blue']['name']) }}</span>
            </div>
        </header>

        <ul class="series-scoreboard__maps">
            @foreach ($state['maps'] as $i => $map)
                @php
                    // Score affiché par map : rounds pour la double attaque
                    // (2-0, 2-1…), score du log pour les maps à log unique.
                    // Une map unique sans score en journal (point manuel, log
                    // en cours) affiche 0-0 comme les autres lignes.
                    if ($map['mode'] === 'double') {
                        $mapScore = ((int) $map['rounds']['red']).' – '.((int) $map['rounds']['blue']);
                    } elseif ($map['scores'] !== null) {
                        $mapScore = ((int) $map['scores']['red']).' – '.((int) $map['scores']['blue']);
                    } else {
                        $mapScore = '0 – 0';
                    }
                @endphp
                <li class="series-map series-map--{{ $map['status'] }}{{ $map['winner'] !== null ? ' series-map--won-'.$map['winner'] : '' }}{{ $i === $currentMapIndex ? ' series-map--current' : '' }}{{ $map['thumb'] !== null ? ' series-map--has-thumb' : '' }}"
                    @if ($map['thumb'] !== null)
                        style="--map-thumb: url('{{ $map['thumb'] }}')"
                    @endif>
                    <span class="series-map__name">{{ e($map['display']) }}</span>
                    <span class="series-map__score">{{ $mapScore }}</span>
                    @if ($map['golden_cap'] && $map['status'] !== 'decided')
                        <span class="series-map__gc">GC</span>
                    @endif
                </li>
            @endforeach
        </ul>

        @if (! empty($state['finished']))
            <footer class="series-scoreboard__footer">
                @if ($state['winner'] !== null)
                    <span class="series-scoreboard__winner series-scoreboard__winner--{{ $state['winner'] }}">
                        {{ e($series['teams'][$state['winner']]['name']) }}
                    </span>
                @else
                    <span class="series-scoreboard__winner">Égalité</span>
                @endif
            </footer>
        @endif

    </div>
</div>

<script src="{{ hlfr_asset('/_js/overlay_series.js') }}" defer
        data-token="{{ $series['token'] }}" data-version="{{ (int) ($series['version'] ?? 0) }}"></script>
</body>
</html>
