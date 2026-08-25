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
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-30553SX3GJ"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-30553SX3GJ');
    </script>
</head>
<body>
@if (session('error'))
    <div style="background: #3d1c1c; color: #e74c3c; border: 1px solid #c0392b; padding: 12px 15px; border-radius: 4px; margin: 20px auto; max-width: 1200px; font-size: 14px;">
        <i class="fa-solid fa-circle-xmark"></i> {{ session('error') }}
    </div>
@endif
@if (session('success'))
    <div style="background: #1c3d1c; color: #2ecc71; border: 1px solid #27ae60; padding: 12px 15px; border-radius: 4px; margin: 20px auto; max-width: 1200px; font-size: 14px;">
        <i class="fa-solid fa-circle-check"></i> {{ session('success') }}
    </div>
@endif

@include('partials.header')

<main id="main" class="admin-main">
    @yield('content')
</main>

@include('partials.footer')

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://kit.fontawesome.com/2f306d349c.js" crossorigin="anonymous"></script>
<script src="{{ hlfr_asset('/_js/main.js') }}" defer></script>
@stack('scripts')
</body>
</html>
