{{-- Vue overlay OBS des rosters d'équipes (1920x1080, fond transparent).
     Page autonome : pas de layout site, aucun chrome. Rendue côté serveur
     (aucune donnée chargée en JavaScript) : seule overlay_rosters.js
     interroge /roster-overlay/{token}/version toutes les 5 s — chaque
     enregistrement côté admin bump la version et la page se recharge
     entièrement, comme les autres overlays.
     Une seule équipe est affichée : celle choisie côté admin (bouton
     « Switcher le roster affiché » du panneau de paramétrage — les
     spectateurs ne voient pas l'interaction). Un switch rejoue
     l'animation d'apparition de l'équipe qui arrive ($replay_enter) ;
     tout autre enregistrement reste un rafraîchissement statique (drapeau
     rosters-no-enter). Disposition selon le format : Highlander = grille
     3x3 des neuf classes, 6v6 = six classes alignées sur toute la
     largeur. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <script>
        /* Rechargement automatique après une mise à jour côté admin : le
           rafraîchissement reste statique (le panneau ne rejoue pas son
           animation d'entrée) SAUF si le dernier enregistrement est un
           switch de roster — l'équipe qui arrive apparaît alors avec la
           chorégraphie complète. Le drapeau est posé par
           overlay_rosters.js juste avant le reload, même mécanisme que
           l'overlay de série. */
        try {
            if (sessionStorage.getItem('hlfr-rosters-refresh') === '1') {
                sessionStorage.removeItem('hlfr-rosters-refresh');
                if (! {{ $replay_enter ? 'true' : 'false' }}) {
                    document.documentElement.classList.add('rosters-no-enter');
                }
            }
        } catch (e) {
            /* Session inaccessible : l'animation se rejouera, sans gravité. */
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
        <section class="roster-team anim" style="--d:0.9s;">
            <div class="roster-team__head">
                @if (trim($data['team']['avatar']) !== '')
                    <img class="roster-team__avatar" src="{{ e($data['team']['avatar']) }}" alt="" loading="eager">
                @endif
                <span class="roster-team__name">{{ e($data['team']['name']) }}</span>
            </div>
            {{-- Les cartes entrent en cascade : le panneau d'abord, puis
                 chaque classe décalée, rejoué à chaque switch de roster. --}}
            <ul class="roster-team__classes">
                @foreach ($data['team']['players'] as $i => $player)
                    <li class="roster-class{{ $player['merc'] ? ' roster-class--merc' : '' }} anim" style="--d:{{ 1.2 + $i * 0.08 }}s;">
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
    </div>

</div>

<script src="{{ hlfr_asset('/_js/overlay_rosters.js') }}" defer
        data-token="{{ $token }}" data-version="{{ (int) $version }}"></script>
</body>
</html>
