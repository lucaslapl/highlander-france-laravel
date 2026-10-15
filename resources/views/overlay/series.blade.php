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
            <div class="series-scoreboard__team series-scoreboard__team--left">
                <span class="series-scoreboard__name">{{ $series['teams']['red']['name'] }}</span>
            </div>
            <div class="series-scoreboard__score">
                <span class="series-scoreboard__score-value">{{ (int) $state['score']['red'] }}</span>
                <span class="series-scoreboard__score-sep">–</span>
                <span class="series-scoreboard__score-value">{{ (int) $state['score']['blue'] }}</span>
            </div>
            <div class="series-scoreboard__team series-scoreboard__team--right">
                <span class="series-scoreboard__name">{{ $series['teams']['blue']['name'] }}</span>
            </div>
        </header>

        <ul class="series-scoreboard__maps">
            @foreach ($state['maps'] as $i => $map)
                @php
                    // Score affiché par map : rounds pour la double attaque
                    // (2-0, 2-1…), points pour les maps à points — score
                    // live des points manuels saisis depuis l'admin.
                    if ($map['mode'] === 'double') {
                        $mapScore = ((int) $map['rounds']['red']).' – '.((int) $map['rounds']['blue']);
                    } elseif ($map['scores'] !== null) {
                        $mapScore = ((int) $map['scores']['red']).' – '.((int) $map['scores']['blue']);
                    } else {
                        $mapScore = '0 – 0';
                    }
                @endphp
                {{-- Aucune teinte d'équipe : la couleur en jeu change plusieurs
                     fois par map, c'est le nom du vainqueur qui identifie la
                     map remportée, pas une couleur. --}}
                <li class="series-map series-map--{{ $map['status'] }}{{ $i === $currentMapIndex ? ' series-map--current' : '' }}{{ $map['thumb'] !== null ? ' series-map--has-thumb' : '' }}"
                    @if ($map['thumb'] !== null)
                        style="--map-thumb: url('{{ $map['thumb'] }}')"
                    @endif>
                    <span class="series-map__name">{{ $map['display'] }}</span>
                    <span class="series-map__score">{{ $mapScore }}</span>
                    @if ($map['winner'] !== null)
                        <span class="series-map__winner">{{ $series['teams'][$map['winner']]['name'] }}</span>
                    @elseif ($map['draw'])
                        {{-- Map nulle (barème base 2, saison régulière 6v6). --}}
                        <span class="series-map__winner">Nul</span>
                    @endif
                    @if ($map['golden_cap'] && $map['status'] !== 'decided')
                        <span class="series-map__gc">GC</span>
                    @elseif ($map['overtime'] && $map['status'] !== 'decided')
                        {{-- Overtime infinie (playoffs 6v6, base 2). --}}
                        <span class="series-map__gc series-map__gc--ot">OT</span>
                    @endif
                </li>
            @endforeach
        </ul>

        @if (! empty($state['finished']))
            <footer class="series-scoreboard__footer">
                @if ($state['winner'] !== null)
                    <span class="series-scoreboard__winner">
                        {{ $series['teams'][$state['winner']]['name'] }}
                    </span>
                @else
                    <span class="series-scoreboard__winner">Égalité</span>
                @endif
            </footer>
        @endif

    </div>
</div>

<script src="{{ hlfr_asset('/_js/overlay_series.js') }}" defer
        data-token="{{ $series['token'] }}" data-version="{{ $version }}"></script>
</body>
</html>
