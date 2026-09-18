@php
    $profileTeams = collect($managedTeams ?? [])->filter(
        fn ($team) => !empty($team['name']) && !empty($team['team_url'])
    );
    if ($profileTeams->isEmpty()) {
        return;
    }
@endphp

<div class="profile-teams">
    <h4><i class="fa-solid fa-users-between-lines"></i> Équipe(s) FR actuelle(s)</h4>

    <div class="profile-teams-list">
        @foreach ($profileTeams as $team)
            <a href="{!! e($team['team_url']) !!}" class="profile-team" title="{!! e($team['name']) !!}">
                <span class="profile-team__logo">
                    @if (! empty($team['logo_url']))
                        <img loading="lazy" decoding="async" src="{!! e($team['logo_url']) !!}" alt="Logo {!! e($team['name']) !!}" width="38" height="38">
                    @else
                        <span class="profile-team__initials">{{ e($team['tag'] !== null && $team['tag'] !== '' ? $team['tag'] : mb_substr((string) $team['name'], 0, 2)) }}</span>
                    @endif
                </span>

                <span class="profile-team__body">
                    <span class="profile-team__name">
                        {{ e($team['name']) }}
                        @if (! empty($team['is_leader']))
                            <i class="fa-solid fa-star profile-team__leader" title="Leader de l'équipe"></i>
                        @endif
                    </span>
                    <span class="profile-team__meta">
                        @include('partials.flag', ['country' => $team['country'] ?? null, 'label' => (string) ($team['country'] ?? '')])
                        @if (! empty($team['division_label']))
                            <span class="profile-team__division">{{ e($team['division_label']) }}</span>
                        @endif
                        @if (! empty($team['class_label']))
                            <span class="profile-team__class">{{ e($team['class_label']) }}</span>
                        @endif
                        <span class="profile-team__status status-{{ e($team['status'] ?? 'starter') }}">{{ e($team['status_label']) }}</span>
                    </span>
                </span>
            </a>
        @endforeach
    </div>
</div>