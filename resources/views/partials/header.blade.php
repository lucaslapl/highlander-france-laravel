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
                @php $isCommunauteActive = in_array($currentPath, ['/joueurs', '/hall-of-fame', '/match-logs']) || str_starts_with($currentPath, '/log/'); @endphp
                <li class="nav-dropdown{{ $isCommunauteActive ? ' is-active' : '' }}">
                    <button type="button" class="nav-dropdown-toggle{{ $isCommunauteActive ? ' active' : '' }}" aria-expanded="false" aria-haspopup="true" aria-controls="communaute-submenu">
                        Communauté <i class="fa-solid fa-chevron-down nav-dropdown-chevron" aria-hidden="true"></i>
                    </button>
                    <ul id="communaute-submenu" class="nav-dropdown-menu" role="menu">
                        <li role="none"><a role="menuitem" href="/joueurs" class="{{ $currentPath === '/joueurs' ? 'active' : '' }}">Joueurs inscrits</a></li>
                        <li role="none"><a role="menuitem" href="/hall-of-fame" class="{{ $currentPath === '/hall-of-fame' ? 'active' : '' }}">Hall of Fame</a></li>
                        <li role="none"><a role="menuitem" href="/match-logs" class="{{ $currentPath === '/match-logs' || str_starts_with($currentPath, '/log/') ? 'active' : '' }}">Match Logs</a></li>
                    </ul>
                </li>
                @php $isEtf2lActive = str_starts_with($currentPath, '/match') || str_starts_with($currentPath, '/etf2l'); @endphp
                <li class="nav-dropdown{{ $isEtf2lActive ? ' is-active' : '' }}">
                    <button type="button" class="nav-dropdown-toggle{{ $isEtf2lActive ? ' active' : '' }}" aria-expanded="false" aria-haspopup="true" aria-controls="etf2l-submenu">
                        ETF2L <i class="fa-solid fa-chevron-down nav-dropdown-chevron" aria-hidden="true"></i>
                    </button>
                    <ul id="etf2l-submenu" class="nav-dropdown-menu" role="menu">
                        <li role="none"><a role="menuitem" href="/matchs" class="{{ $currentPath === '/matchs' || str_starts_with($currentPath, '/match/') ? 'active' : '' }}">Matchs FR</a></li>
                        <li role="none"><a role="menuitem" href="/etf2l/maps" class="{{ $currentPath === '/etf2l/maps' ? 'active' : '' }}">Maps</a></li>
                    </ul>
                </li>
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
