@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@php
    $withParams = static function (array $overrides) use ($search, $sort, $dir): string {
        $params = array_filter(
            array_merge(['q' => $search, 'sort' => $sort, 'dir' => $dir], $overrides),
            static fn ($v): bool => $v !== '' && $v !== null,
        );
        $query = http_build_query($params);

        return '/joueurs' . ($query !== '' ? '?' . $query : '');
    };
    $pageUrl = static fn (int $p) => $withParams($p > 1 ? ['page' => $p] : []);

    $rolesConfig = [
        'is_founder'   => 'Fondateur',
        'is_admin'     => 'Admin',
        'is_moderator' => 'Modérateur',
        'is_mentor'    => 'Mentor',
        'is_mixer'     => 'Mixer',
    ];

    $divisionBadgeClass = static function (?float $tier): string {
        if ($tier === null) {
            return 'badge-level-unknown';
        }

        return 'badge-level-'.(int) round($tier);
    };

    $hasDivisions = static function (array $player): bool {
        return ! empty($player['hl_division']) || ! empty($player['div6_division']);
    };
@endphp

@section('content')
<div class="etf2l-agenda-container joueurs-container">
    <div class="agenda-header flex space-between align-center">
        <h3><i class="fa-solid fa-users"></i> Joueurs inscrits</h3>
        <span class="badge-live-info">{{ (int) $totalPlayers }} joueur(s)</span>
    </div>

    <form method="GET" action="/joueurs" class="joueurs-search flex align-center gap-10">
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">
        <input type="text" name="q" value="{!! e($search) !!}" placeholder="Rechercher un joueur…" maxlength="50" class="joueurs-search-input">
        <button type="submit" class="joueurs-search-btn"><i class="fa-solid fa-magnifying-glass"></i> Rechercher</button>
        @if ($search !== '')
            <a href="{{ $pageUrl(1) }}" class="joueurs-search-reset" title="Réinitialiser la recherche">
                <i class="fa-solid fa-xmark"></i>
            </a>
        @endif
    </form>

    @if (empty($players))
        <div class="agenda-empty">
            <p><i class="fa-solid fa-circle-info"></i> Aucun joueur trouvé{{ $search !== '' ? ' pour « ' . e($search) . ' »' : '' }}.</p>
        </div>
    @else
        <div class="joueurs-table-wrapper">
            <table class="joueurs-table">
                <thead>
                    <tr>
                        <th class="col-player">
                            <a href="{{ $withParams(['sort' => 'name', 'dir' => $sort === 'name' && $dir === 'asc' ? 'desc' : 'asc']) }}">
                                Joueur
                                @if ($sort === 'name')<i class="fa-solid {{ $dir === 'asc' ? 'fa-arrow-down-a-z' : 'fa-arrow-up-a-z' }}"></i>@endif
                            </a>
                        </th>
                        <th class="col-classes">Classes les plus jouées</th>
                        <th class="col-country">Pays</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($players as $player)
                        <tr>
                            <td class="col-player">
                                <div class="joueurs-player-cell">
                                    <div class="flex align-center gap-10">
                                        @if (!empty($player['avatar']))
                                            <img loading="lazy" decoding="async" src="{!! e($player['avatar']) !!}" alt="" class="joueurs-avatar">
                                        @endif
                                        <span class="joueurs-name">
                                            @if (!empty($player['profile_url']))
                                                <a href="{!! e($player['profile_url']) !!}">{!! e($player['final_name']) !!}</a>
                                            @else
                                                {!! e($player['final_name']) !!}
                                            @endif
                                        </span>
                                    </div>
                                    @if (!empty($player['hl_division']) || !empty($player['div6_division']) || !empty($player['is_founder']) || !empty($player['is_admin']) || !empty($player['is_moderator']) || !empty($player['is_mentor']) || !empty($player['is_mixer']))
                                        <div class="joueurs-badges">
                                            @foreach ($rolesConfig as $dbKey => $label)
                                                @if (!empty($player[$dbKey]))
                                                    <span class="badge-staff badge-{{ $dbKey === 'is_moderator' ? 'moderator' : str_replace('is_', '', $dbKey) }}">{!! e($label) !!}</span>
                                                @endif
                                            @endforeach

                                            @if (!empty($player['hl_division']))
                                                <span class="badge-staff badge-level {{ $divisionBadgeClass($player['hl_tier'] ?? null) }}" title="Division moyenne Highlander, pondérée sur les 4 dernières saisons officielles">HL&thinsp;·&thinsp;{!! e($player['hl_division']) !!}</span>
                                            @endif

                                            @if (!empty($player['div6_division']))
                                                <span class="badge-staff badge-level {{ $divisionBadgeClass($player['div6_tier'] ?? null) }}" title="Division moyenne 6v6, pondérée sur les 4 dernières saisons officielles">6s&thinsp;·&thinsp;{!! e($player['div6_division']) !!}</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td class="col-classes">
                                @if (!empty($player['classes']))
                                    <div class="joueurs-classes">
                                        @foreach ($player['classes'] as $class)
                                            @if ($class !== '' && is_file(public_path('/_img/classes/') . $class . '.png'))
                                                <img loading="lazy" decoding="async" src="/_img/classes/{{ e($class) }}.png" alt="{!! ucfirst(e($class)) !!}" class="joueurs-class-icon" title="{!! ucfirst(e($class)) !!}">
                                            @endif
                                        @endforeach
                                    </div>
                                @else
                                    <span class="division-none">—</span>
                                @endif
                            </td>
                            <td class="col-country">
                                <img loading="lazy" decoding="async" src="{!! e($player['flag_url']) !!}" alt="{!! e($player['country_label']) !!}" class="joueurs-flag" title="{!! e($player['country_label']) !!}">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($totalPages > 1)
        <div class="pagination">
            @php
                $start = max(1, $currentPage - 2);
                $end = min($totalPages, $currentPage + 2);
            @endphp
            @if ($currentPage > 1)
                <a href="{{ $pageUrl($currentPage - 1) }}" class="page-btn nav">&laquo; Précédent</a>
            @endif
            @if ($start > 1)
                <a href="{{ $pageUrl(1) }}" class="page-btn">1</a>
                @if ($start > 2)<span class="page-ellipsis">…</span>@endif
            @endif
            @for ($p = $start; $p <= $end; $p++)
                @if ($p === $currentPage)
                    <span class="page-btn active">{{ $p }}</span>
                @else
                    <a href="{{ $pageUrl($p) }}" class="page-btn">{{ $p }}</a>
                @endif
            @endfor
            @if ($end < $totalPages)
                @if ($end < $totalPages - 1)<span class="page-ellipsis">…</span>@endif
                <a href="{{ $pageUrl($totalPages) }}" class="page-btn">{{ $totalPages }}</a>
            @endif
            @if ($currentPage < $totalPages)
                <a href="{{ $pageUrl($currentPage + 1) }}" class="page-btn nav">Suivant &raquo;</a>
            @endif
        </div>
    @endif
</div>
@endsection
