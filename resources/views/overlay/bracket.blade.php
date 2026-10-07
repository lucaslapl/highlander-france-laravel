{{-- Vue overlay OBS du bracket de playoffs (1920x1080, fond transparent).
     Page autonome : pas de layout site, aucun chrome. Le header est rendu
     côté serveur ; les cartes, étiquettes et connecteurs sont posés par
     overlay_bracket.js (positionnement libre, câblage standard du bracket),
     à partir du JSON #bracket-data — scores déjà résolus (statut du match
     live, score de la série attachée le cas échéant). --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ hlfr_asset('/_css/overlay_bracket.css') }}">
</head>
<body>
<div id="scene">
    <header class="anim" style="--d:1.2s;">
        @if (trim($data['eyebrow']) !== '')
            <div class="eyebrow">{{ e($data['eyebrow']) }}</div>
        @endif
        <h1>
            {{ e($data['title']) }}
            @if (trim($data['accent']) !== '')
                <em>{{ e($data['accent']) }}</em>
            @endif
        </h1>
        <div class="title-bar"></div>
    </header>
    <div id="bracket"></div>
</div>

<script type="application/json" id="bracket-data">{!! json_encode($data, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
<script src="{{ hlfr_asset('/_js/overlay_bracket.js') }}" defer
        data-token="{{ $token }}" data-version="{{ (int) $version }}"></script>
</body>
</html>
