@php
    $currentPath = request()->path() === '/' ? '/' : '/' . request()->path();
    $currentPath = ($currentPath === '/index' || $currentPath === '/index.php') ? '/' : $currentPath;
    $isLoggedIn = \App\Services\Auth::isLoggedIn();
@endphp
<header id="header" fetchpriority="high">
    <div class="head-content flex space-between align-center">
        <div class="flex justify-center align-center">
            <a href="https://highlanderfrance.tf">
                <img class="header-logo" src="/_img/hf.webp" alt="Logo Highlander France" aria-label="Redirection vers la page d'accueil" fetchpriority="high" width="64" height="64">
            </a>
            @if (($isHome ?? ($currentPath === '/')) === true)
                <h1>Highlander France</h1>
            @else
                <div class="site-title">Highlander France</div>
            @endif
        </div>
    </div>
    @if ($currentPath === '/')
        @include('partials.twitch-header-player')
    @endif
</header>

<nav id="nav" aria-label="Navigation principale">
    <div class="nav-content">
        <button class="burger-menu" id="burgerToggle" type="button" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="nav-menu">
            <span class="bar"></span>
            <span class="bar"></span>
            <span class="bar"></span>
        </button>

        <a href="/" class="nav-brand" aria-label="Highlander France — Retour à l'accueil">
            <img src="/_img/hf.webp" alt="" width="40" height="40">
        </a>

        <a href="/live" id="liveNavBadge" class="nav-live-badge" hidden>
            <span class="live-dot" aria-hidden="true"></span>
            <span class="live-text">MIX EN COURS</span>
            <span class="live-map" id="liveNavMap"></span>
            <i class="fa-solid fa-chevron-right live-chevron" aria-hidden="true"></i>
        </a>

        <div class="nav-menu" id="nav-menu">
            <ul class="nav-links">
                <li><a href="/" class="{{ $currentPath === '/' ? 'active' : '' }}">Accueil</a></li>
                <li><a href="/staff" class="{{ $currentPath === '/staff' ? 'active' : '' }}">L'équipe</a></li>
                <li><a href="/joueurs" class="{{ $currentPath === '/joueurs' ? 'active' : '' }}">Joueurs</a></li>
                <li><a href="/hall-of-fame" class="{{ $currentPath === '/hall-of-fame' ? 'active' : '' }}">Hall of Fame</a></li>
                <li><a href="/match-logs" class="{{ $currentPath === '/match-logs' ? 'active' : '' }}">Match Logs</a></li>
            </ul>

            <div class="nav-right">
                <div id="session-profile">
                    @if ($isLoggedIn)
                        <a href="/profile/dashboard" class="{{ $currentPath === '/profile/dashboard' ? 'active' : '' }}">Mon Profil</a>
                        <form action="/logout" method="POST" style="display:inline">
                            @csrf
                            <button type="submit" class="nav-logout-btn">Déconnexion</button>
                        </form>
                    @else
                        <a href="/login" class="btn-steam-login">
                            <i class="fa-brands fa-steam"></i>
                            <span>Connexion via Steam</span>
                        </a>
                    @endif
                </div>
                <div class="nav-socials" aria-label="Réseaux sociaux">
                    <a class="nav-social nav-social--discord" href="https://discord.gg/BMuj3cqUFt" target="_blank" rel="noopener" aria-label="Discord Highlander France">
                        <i class="fa-brands fa-discord" aria-hidden="true"></i>
                    </a>
                    <a class="nav-social nav-social--twitch" href="https://www.twitch.tv/highlanderfrance" target="_blank" rel="noopener" aria-label="Chaîne Twitch highlanderfrance">
                        <i class="fa-brands fa-twitch" aria-hidden="true"></i>
                    </a>
                    <a class="nav-social nav-social--youtube" href="https://www.youtube.com/@HighlanderFrance" target="_blank" rel="noopener" aria-label="Chaîne YouTube @HighlanderFrance">
                        <i class="fa-brands fa-youtube" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</nav>
