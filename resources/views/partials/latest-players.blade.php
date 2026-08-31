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
                        <img loading="lazy" decoding="async" src="/img/avatar/{{ e($player['steamid64']) }}" alt="" class="latest-player-avatar">
                        @if ($player['flag_url'])
                            <img loading="lazy" decoding="async" src="{{ $player['flag_url'] }}" alt="" class="latest-player-flag" title="">
                        @endif
                        <span class="latest-player-name">{{ $player['name'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>