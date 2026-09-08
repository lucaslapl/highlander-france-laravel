<div class="sidebar-card latest-players">
    <div class="sidebar-card-header">
        <h3><i class="fa-solid fa-user-plus"></i> Derniers inscrits</h3>
    </div>

    @if (empty($latestPlayers))
        <div class="sidebar-card-empty">
            <p><i class="fa-solid fa-circle-info"></i> Aucun joueur pour le moment.</p>
        </div>
    @else
        <ul class="latest-players-list">
            @foreach ($latestPlayers as $player)
                <li>
                    <a href="{{ e($player['profile_url']) }}" class="latest-player" title="{{ e($player['name']) }}">
                        <img loading="lazy" decoding="async" src="{{ e($player['avatar'] ?? avatar_url($player['steamid64'] ?? null)) }}" alt="" class="latest-player-avatar" width="32" height="32" onerror="this.onerror=null;this.src='/img/avatar/{{ e($player['steamid64']) }}'">
                        @php $lpSprite = \App\Services\CountryFlags::spriteClass($player['country'] ?? null); @endphp
                        @if ($lpSprite !== null)
                            <span class="hlfr-sprite {{ $lpSprite }} latest-player-flag" role="img" aria-label=""></span>
                        @elseif ($player['flag_url'])
                            <img loading="lazy" decoding="async" src="{{ $player['flag_url'] }}" alt="" class="latest-player-flag" title="" width="20" height="13">
                        @endif
                        <span class="latest-player-name">{{ $player['name'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>