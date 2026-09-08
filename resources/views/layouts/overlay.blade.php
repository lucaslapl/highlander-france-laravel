{{-- Layout overlay OBS : fond sombre (ou transparent), rendu statique sans JS. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title')</title>
    <link rel="stylesheet" href="{{ hlfr_asset('/_css/overlay.css') }}">
    @stack('styles')
</head>
<body class="{{ $background['transparent'] ? 'ov-bg-transparent' : 'ov-bg-dark' }}">
    <div class="ov-wrap">
        @yield('content')
    </div>
</body>
</html>
