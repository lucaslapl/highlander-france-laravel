{{-- Layout principal du site. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="{{ $noIndex ?? false ? 'noindex, nofollow' : 'index, follow' }}">
    <link rel="canonical" href="{{ canonical_url() }}">
    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="@yield('description', site_description())">
    <meta name="theme-color" content="#14161a">
    <meta name="generator" content="Highlander France">

    <!-- Facebook Meta Tags -->
    <meta property="og:url" content="{{ current_url() }}">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:title" content="@yield('title', config('app.name'))">
    <meta property="og:description" content="@yield('description', site_description())">
    <meta property="og:image" content="@yield('og_image', site_url() . '/_img/meta-bg-hlfr.jpg')">

    <!-- Twitter Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta property="twitter:domain" content="highlanderfrance.tf">
    <meta property="twitter:url" content="{{ current_url() }}">
    <meta name="twitter:title" content="@yield('title', config('app.name'))">
    <meta name="twitter:description" content="@yield('description', site_description())">
    <meta name="twitter:image" content="@yield('og_image', site_url() . '/_img/meta-bg-hlfr.jpg')">

    <!-- Structured data -->
    <script type="application/ld+json">
    {
        "@@context": "https://schema.org",
        "@type": "WebSite",
        "name": {!! json_encode(config('app.name'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!},
        "url": {!! json_encode(site_url(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!},
        "inLanguage": "fr",
        "publisher": {
            "@type": "Organization",
            "name": {!! json_encode(config('app.name'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!},
            "url": {!! json_encode(site_url(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!},
            "logo": {
                "@type": "ImageObject",
                "url": {!! json_encode(site_url() . '/_img/hf.webp', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
            }
        },
        "potentialAction": {
            "@type": "SearchAction",
            "target": {!! json_encode(site_url() . '/joueurs?q={search_term_string}', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!},
            "query-input": "required name=search_term_string"
        }
    }
    </script>
    <script type="application/ld+json">
    {
        "@@context": "https://schema.org",
        "@type": "Organization",
        "name": {!! json_encode(config('app.name'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!},
        "url": {!! json_encode(site_url(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!},
        "logo": {!! json_encode(site_url() . '/_img/hf.webp', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!},
        "sameAs": [
            "https://discord.gg/BMuj3cqUFt"
        ]
    }
    </script>
    @if (!empty($breadcrumbs))
    <script type="application/ld+json">
        {!! json_encode(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => collect($breadcrumbs)->map(fn($c, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['url']])->all()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
    @endif
    @if (!empty($structuredData))
    <script type="application/ld+json">
        {!! json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
    @endif

    <!-- Favicon standard -->
    <link rel="shortcut icon" href="{{ e(site_url()) }}/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ e(site_url()) }}/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ e(site_url()) }}/favicon-16x16.png">
    <link rel="icon" type="image/x-icon" href="{{ e(site_url()) }}/favicon.ico">

    <!-- Apple Touch Icon (iPhone/iPad) -->
    <link rel="apple-touch-icon" href="{{ e(site_url()) }}/apple-touch-icon.png">

    <!-- Android Chrome -->
    <link rel="icon" type="image/png" sizes="192x192" href="{{ e(site_url()) }}/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="{{ e(site_url()) }}/android-chrome-512x512.png">

    <!-- Web App Manifest -->
    <link rel="manifest" href="/site.webmanifest">

    <!-- Préconnexions aux origines tierces utilisées -->
    <link rel="preconnect" href="https://www.googletagmanager.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://kit.fontawesome.com" crossorigin>
    <link rel="preconnect" href="https://ka-f.fontawesome.com" crossorigin>

    <link rel="stylesheet" href="{{ hlfr_asset('/_css/main.css') }}">
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

<main id="main">
    <section id="content">
        @yield('content')
    </section>
</main>

@include('partials.footer')

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://kit.fontawesome.com/2f306d349c.js" crossorigin="anonymous"></script>
<script src="{{ hlfr_asset('/_js/main.js') }}" defer></script>
<script src="{{ hlfr_asset('/_js/live_match.js') }}" defer></script>
@stack('scripts')
</body>
</html>
