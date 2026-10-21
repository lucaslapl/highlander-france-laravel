{{-- État vide d'un overlay à URL fixe (/overlay/match-logs ou
     /overlay/match-logs-2) : aucun log n'a encore été chargé sur ce slot
     depuis l'outil « Overlay Logs ». Page 100 % transparente pour OBS,
     sans moindre pixel : elle poll son endpoint de version et se
     recharge toute seule dès que le premier log arrive. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Overlay stats - Highlander France</title>
</head>
<body>
<script src="{{ hlfr_asset('/_js/overlay_match.js') }}" defer
        data-token="{{ $token }}"
        data-version="0"
        data-version-url="{{ $baseUrl }}/version"></script>
</body>
</html>
