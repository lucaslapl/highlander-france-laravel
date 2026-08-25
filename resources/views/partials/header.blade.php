@php
    $currentPath = request()->path() === '/' ? '/' : '/' . request()->path();
    $currentPath = ($currentPath === '/index' || $currentPath === '/index.php') ? '/' : $currentPath;
    $isLoggedIn = \App\Services\Auth::isLoggedIn();
@endphp
<header id="header" fetchpriority="high">
    <div class="head-content flex space-between align-center">
        <div class="flex justify-center align-center">
            <a href="https://highlanderfrance.tf">
                <img class="header-logo" src="/_img/hf.webp" alt="Logo Highlander France" aria-label="Redirection vers la page d'accueil">
            </a>
            <h1>
                Highlander France
            </h1>
        </div>
    </div>
</header>

<nav id="nav" aria-label="Navigation principale">
    <div class="nav-content">
        <button class="burger-menu" id="burgerToggle" type="button" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="nav-menu">
            <span class="bar"></span>
            <span class="bar"></span>
            <span class="bar"></span>
        </button>

        <a href="/index" class="nav-brand" aria-label="Highlander France — Retour à l'accueil">
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
                <li><a href="/index" class="{{ $currentPath === '/' ? 'active' : '' }}">Accueil</a></li>
                <li><a href="/staff" class="{{ $currentPath === '/staff' ? 'active' : '' }}">L'équipe</a></li>
                <li><a href="/hall-of-fame" class="{{ $currentPath === '/hall-of-fame' ? 'active' : '' }}">Hall of Fame</a></li>
                <li><a href="/match-logs" class="{{ $currentPath === '/match-logs' ? 'active' : '' }}">Match Stats</a></li>
            </ul>

            <div class="nav-right">
                <div id="session-profile">
                    @if ($isLoggedIn)
                        <a href="/profile/dashboard" class="{{ $currentPath === '/profile/dashboard' ? 'active' : '' }}">Mon Profil</a>
                        <a href="/logout">Déconnexion</a>
                    @else
                        <a href="/login" class="btn-steam-login">
                            <i class="fa-brands fa-steam"></i>
                            <span>Connexion via Steam</span>
                        </a>
                    @endif
                </div>
                <a class="nav-discord discord-link" href="https://discord.gg/BMuj3cqUFt" target="_blank" rel="noopener">
                    <i class="fa-brands fa-discord" aria-hidden="true"></i>
                    <span class="discord-label">Discord</span>
                </a>
            </div>
        </div>
    </div>
</nav>
