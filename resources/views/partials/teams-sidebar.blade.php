{{-- Encadré sidebar : équipes françaises actives (ordre Prem > High > Low > Open). --}}
<div class="sidebar-card teams-sidebar">
    <div class="sidebar-card-header">
        <h3><i class="fa-solid fa-users-between-lines"></i> Équipes FR</h3>
    </div>

    @php
        $sidebarTeams = $teamsSidebar ?? [];
        $sidebarDivisions = $divisions ?? config('hlfr.team_divisions', []);
    @endphp

    @if (empty($sidebarTeams))
        <div class="sidebar-card-empty">
            <p><i class="fa-solid fa-circle-info"></i> Aucune équipe active pour le moment.</p>
        </div>
    @else
        <ul class="teams-list">
            @foreach ($sidebarTeams as $team)
                <li>
                    <a href="/equipes/{{ e($team['slug']) }}" class="team-item" title="{{ e($team['name']) }}">
                        @if (! empty($team['logo_url']))
                            <img loading="lazy" decoding="async" src="{{ e($team['logo_url']) }}" alt="" class="team-item-logo" width="30" height="30">
                        @else
                            <span class="team-item-tag">{{ e($team['tag'] !== null && $team['tag'] !== '' ? $team['tag'] : mb_substr((string) $team['name'], 0, 3)) }}</span>
                        @endif
                        <span class="team-item-name">{{ e($team['name']) }}</span>
                        @if (! empty($team['format_label']))
                            <span class="team-item-format" title="Format de jeu">{{ e($team['format_label']) }}</span>
                        @endif
                        @if (! empty($sidebarDivisions[$team['division'] ?? '']))
                            <span class="team-item-division">{{ e($sidebarDivisions[$team['division']]) }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
        <a href="/equipes" class="teams-sidebar-more">Toutes les équipes <i class="fa-solid fa-arrow-right"></i></a>
    @endif
</div>