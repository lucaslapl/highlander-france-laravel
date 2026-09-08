@if (!empty($result['teamA']))
    @php($team = $result['teamA'])
    @include('admin.partials.stats_duel_team')
@endif
@if (!empty($result['teamB']))
    @php($team = $result['teamB'])
    @include('admin.partials.stats_duel_team')
@endif