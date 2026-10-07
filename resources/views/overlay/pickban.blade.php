{{-- Vue overlay OBS du pick / ban de maps (1920x1080, fond transparent).
     Page autonome : pas de layout site, aucun chrome. Entièrement rendue
     côté serveur (aucune donnée chargée en JavaScript) ; seul
     overlay_pickban.js interroge /pickban-overlay/{token}/version toutes
     les 5 s — chaque enregistrement côté admin bump la version et la
     page se recharge entièrement, comme les autres overlays. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ hlfr_asset('/_css/overlay_pickban.css') }}">
</head>
<body>
<div id="scene">
    <header class="anim" style="--d:1s;">
        @if (trim($data['eyebrow']) !== '')
            <div class="eyebrow">{{ e($data['eyebrow']) }}</div>
        @endif
        <h1>{{ e($data['title']) }}</h1>
        <div class="title-bar"></div>
    </header>

    <div id="cards">
        @foreach ($data['cards'] as $card)
            <div class="card anim action-{{ $card['action'] }}">
                <div class="map-name">
                    @if (trim($card['map']) !== '')
                        {{ e(strtoupper($card['map'])) }}
                    @else
                        <span class="map-unknown">—</span>
                    @endif
                </div>
                <div class="map-shot">
                    @if (trim($card['image']) !== '')
                        <img src="{{ e($card['image']) }}" alt="" loading="eager">
                    @endif
                </div>
                <div class="card-team">
                    @if (trim($card['team']['avatar']) !== '')
                        <img class="team-avatar" src="{{ e($card['team']['avatar']) }}" alt="" loading="eager">
                    @endif
                    @if (trim($card['team']['name']) !== '')
                        <span class="team-name">{{ e($card['team']['name']) }}</span>
                    @endif
                </div>
                <div class="card-action">{{ $card['action'] === 'pick' ? 'PICK' : 'BAN' }}</div>
            </div>
        @endforeach
    </div>
</div>

<script src="{{ hlfr_asset('/_js/overlay_pickban.js') }}" defer
        data-token="{{ $token }}" data-version="{{ (int) $version }}"></script>
</body>
</html>
