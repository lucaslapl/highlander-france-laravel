@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@php
    // URL préservant recherche + tri, avec page optionnelle.
    $withParams = static function (array $overrides) use ($search, $sort, $dir): string {
        $params = array_filter(
            array_merge(['q' => $search, 'sort' => $sort, 'dir' => $dir], $overrides),
            static fn ($v): bool => $v !== '' && $v !== null,
        );
        $query = http_build_query($params);

        return '/joueurs' . ($query !== '' ? '?' . $query : '');
    };
    $pageUrl = static fn (int $p) => $withParams($p > 1 ? ['page' => $p] : []);
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
                        <th class="col-division">
                            <a href="{{ $withParams(['sort' => 'hl', 'dir' => $sort === 'hl' && $dir === 'asc' ? 'desc' : 'asc']) }}">
                                Div. HL
                                @if ($sort === 'hl')<i class="fa-solid fa-sort{{ $dir === 'asc' ? '-down' : '-up' }}"></i>@endif
                            </a>
                        </th>
                        <th class="col-division">
                            <a href="{{ $withParams(['sort' => 'div6', 'dir' => $sort === 'div6' && $dir === 'asc' ? 'desc' : 'asc']) }}">
                                Div. 6s
                                @if ($sort === 'div6')<i class="fa-solid fa-sort{{ $dir === 'asc' ? '-down' : '-up' }}"></i>@endif
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
                            </td>
                            <td class="col-division">
                                @if (!empty($player['hl_division']))
                                    <span class="division-badge division-hl">{{ e($player['hl_division']) }}</span>
                                @else
                                    <span class="division-none">—</span>
                                @endif
                            </td>
                            <td class="col-division">
                                @if (!empty($player['div6_division']))
                                    <span class="division-badge division-6s">{{ e($player['div6_division']) }}</span>
                                @else
                                    <span class="division-none">—</span>
                                @endif
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
                                @if (!empty($player['flag_url']))
                                    <img loading="lazy" decoding="async" src="{!! e($player['flag_url']) !!}" alt="{!! e($player['country_label']) !!}" class="joueurs-flag" title="{!! e($player['country_label']) !!}">
                                @else
                                    <span class="division-none">—</span>
                                @endif
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
