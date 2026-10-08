{{-- Vue overlay OBS des rosters d'équipes (1920x1080, fond transparent).
     Page autonome : pas de layout site, aucun chrome. Entièrement rendue
     côté serveur (aucune donnée chargée en JavaScript) ; seul
     overlay_rosters.js interroge /roster-overlay/{token}/version toutes
     les 5 s — chaque enregistrement côté admin bump la version et la
     page se recharge entièrement, comme les autres overlays.
     Disposition selon le format : Highlander = deux grilles 3x3 des neuf
     classes (une par équipe), 6v6 = six classes alignées sur toute la
     largeur par équipe. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <script>
        /* Rechargement automatique après une mise à jour côté admin : on
           saute l'animation d'entrée pour que les rosters restent posés
           (le drapeau est posé par overlay_rosters.js juste avant le
           reload, même mécanisme que l'overlay de série). */
        if (sessionStorage.getItem('hlfr-rosters-refresh') === '1') {
            sessionStorage.removeItem('hlfr-rosters-refresh');
            document.documentElement.classList.add('rosters-no-enter');
        }
    </script>
    <link rel="stylesheet" href="{{ hlfr_asset('/_css/overlay_rosters.css') }}">
</head>
<body>
<div id="scene" class="roster-scene roster-scene--{{ $data['format'] === '6v6' ? '6v6' : 'hl' }}">

    <header class="roster-head anim" style="--d:0.2s;">
        @if (trim($data['eyebrow']) !== '')
            <div class="eyebrow">{{ e($data['eyebrow']) }}</div>
        @endif
        <h1>{{ e($data['title']) }}</h1>
        <div class="title-bar"></div>
    </header>

    <div class="roster-teams">
        @foreach (['a' => 'left', 'b' => 'right'] as $side => $sideClass)
            <section class="roster-team roster-team--{{ $sideClass }} anim" style="--d:1s;">
                <div class="roster-team__head">
                    @if (trim($data['teams'][$side]['avatar']) !== '')
                        <img class="roster-team__avatar" src="{{ e($data['teams'][$side]['avatar']) }}" alt="" loading="eager">
                    @endif
                    <span class="roster-team__name">{{ e($data['teams'][$side]['name']) }}</span>
                </div>
                <ul class="roster-team__classes">
                    @foreach ($data['teams'][$side]['players'] as $player)
                        <li class="roster-class{{ $player['merc'] ? ' roster-class--merc' : '' }}">
                            @if ($player['portrait'] !== '')
                                <img class="roster-class__portrait" src="{{ e($player['portrait']) }}"
                                     alt="{{ e(ucfirst($player['class'])) }}" loading="eager">
                            @endif
                            @if ($player['merc'])
                                <span class="roster-class__merc">MERC</span>
                            @endif
                            <span class="roster-class__name">{{ e($player['name']) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

</div>

<script src="{{ hlfr_asset('/_js/overlay_rosters.js') }}" defer
        data-token="{{ $token }}" data-version="{{ (int) $version }}"></script>
</body>
</html>
