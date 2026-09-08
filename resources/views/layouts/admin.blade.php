{{-- Layout du panel d'administration. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="@yield('description', 'Panel d\'administration Highlander France.')">

    <link rel="shortcut icon" href="https://highlanderfrance.tf/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="https://highlanderfrance.tf/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="https://highlanderfrance.tf/favicon-16x16.png">
    <link rel="apple-touch-icon" href="https://highlanderfrance.tf/apple-touch-icon.png">

    <link rel="stylesheet" href="{{ hlfr_asset('/_css/main.css') }}">
    <link rel="stylesheet" href="{{ hlfr_asset('/_css/admin.css') }}">
    @stack('styles')

    <!-- Google tag (gtag.js) -->
    <script defer src="https://www.googletagmanager.com/gtag/js?id=G-30553SX3GJ"></script>
    <script defer>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-30553SX3GJ');
    </script>
</head>
<body>
@include('partials.header')

<main id="main" class="admin-main">
    @yield('content')
</main>

@include('partials.footer')

<script src="https://kit.fontawesome.com/2f306d349c.js" crossorigin="anonymous" defer></script>
<script src="{{ hlfr_asset('/_js/main.js') }}" defer></script>
@stack('scripts')
</body>
</html>
