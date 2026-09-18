@extends('layouts.main')

@section('title', $title)
@section('description', $description)
@if (! empty($og_image))
    @section('og_image', $og_image)
@endif

@push('styles')
<link rel="stylesheet" href="{{ hlfr_asset('/_css/guides.css') }}">
@endpush

@section('content')
<div class="teams-page">
    <p class="teams-back"><a href="/equipes"><i class="fa-solid fa-arrow-left"></i> Toutes les équipes</a></p>

    <header class="team-hero">
        <div class="team-hero__logo">
            @if (! empty($team['logo_url']))
                <img src="{{ e($team['logo_url']) }}" alt="Logo {{ e($team['name']) }}">
            @else
                <span class="team-hero__initials">{{ e($team['tag'] !== null && $team['tag'] !== '' ? $team['tag'] : mb_substr((string) $team['name'], 0, 3)) }}</span>
            @endif
        </div>

        <div class="team-hero__info">
            <h1>
                {{ e($team['name']) }}
                @if (! empty($team['tag']))
                    <span class="team-hero__tag">[{{ e($team['tag']) }}]</span>
                @endif
            </h1>

            <p class="team-hero__meta">
                @include('partials.flag', ['country' => $team['country'] ?? null, 'label' => (string) ($team['country'] ?? '')])
                @if (! empty($team['division_label']))
                    <span class="team-division">{{ e($team['division_label']) }}</span>
                @endif
                @if (! empty($team['format_label']))
                    <span class="team-format">{{ e($team['format_label']) }}</span>
                @endif
                <span><i class="fa-solid fa-users"></i> {{ count($members) }} joueur{{ count($members) > 1 ? 's' : '' }}</span>
                <a href="https://etf2l.org/teams/{{ (int) $team['etf2l_team_id'] }}/" target="_blank" rel="noopener">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> ETF2L
                </a>
            </p>

            @if (! empty($team['slogan']))
                <p class="team-hero__slogan">{{ e($team['slogan']) }}</p>
            @endif
        </div>

        @if ($canEdit)
            <a href="/equipes/{{ e($team['slug']) }}/editer" class="team-edit-btn"><i class="fa-solid fa-pen"></i> Éditer l'équipe</a>
        @endif
    </header>

    <div class="team-layout">
        <div class="team-main">
            @if (! empty($team['description_html']))
                <section class="team-panel">
                    <h2 class="team-panel__title"><i class="fa-solid fa-file-lines"></i> Présentation</h2>
                    <div class="guide-content">{!! $team['description_html'] !!}</div>
                </section>
            @endif

            <section class="team-panel">
                <h2 class="team-panel__title"><i class="fa-solid fa-trophy"></i> Derniers matchs</h2>

                @if (empty($recent))
                    <p class="no-data">Aucun match récent trouvé sur ETF2L.</p>
                @else
                    <ul class="team-matches">
                        @foreach ($recent as $match)
                            <li class="team-match">
                                @if ($match['won'] === true)
                                    <span class="team-result result-win">V</span>
                                @elseif ($match['won'] === false)
                                    <span class="team-result result-loss">D</span>
                                @else
                                    <span class="team-result result-draw">—</span>
                                @endif

                                <div class="team-match__body">
                                    <a href="{{ e($match['link_url']) }}"
                                       class="team-match__opponent"
                                       @if (! empty($match['link_external'])) target="_blank" rel="noopener" @endif>
                                        @include('partials.flag', ['country' => $match['opponent']['country'] ?? null, 'label' => (string) ($match['opponent']['country'] ?? '')])
                                        {{ e($match['opponent']['name']) }}
                                    </a>
                                    <span class="team-match__competition">
                                        {{ e($match['competition_name']) }}@if ($match['round'] !== '') · {{ e($match['round']) }}@endif
                                    </span>
                                </div>

                                <div class="team-match__right">
                                    <span class="team-match__score">
                                        @if ($match['score_ours'] !== null && $match['score_theirs'] !== null)
                                            <b>{{ (int) $match['score_ours'] }}</b> - {{ (int) $match['score_theirs'] }}
                                        @else
                                            —
                                        @endif
                                    </span>
                                    <span class="team-match__date">{{ date('d/m/Y', (int) $match['time']) }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <aside class="team-aside">
            <section class="team-panel">
                <h2 class="team-panel__title"><i class="fa-solid fa-people-group"></i> Roster</h2>

                @if (empty($members))
                    <p class="no-data">Aucun joueur inscrit au roster pour le moment.</p>
                @else
                    <ul class="team-roster">
                        @foreach ($members as $member)
                            <li class="team-roster__item">
                                <img loading="lazy" decoding="async" src="{{ e($member['avatar_url']) }}" alt="" class="team-roster__avatar" width="40" height="40">
                                <div class="team-roster__id">
                                    <span class="team-roster__name">
                                        @if (! empty($member['profile_url']))
                                            <a href="{{ e($member['profile_url']) }}">{{ e($member['final_name']) }}</a>
                                        @else
                                            {{ e($member['final_name']) }}
                                        @endif
                                        @if ($member['is_leader'])
                                            <i class="fa-solid fa-star team-roster__leader" title="Leader"></i>
                                        @endif
                                    </span>
                                    <span class="team-roster__meta">
                                        @include('partials.flag', ['country' => $member['country'] ?? null, 'label' => (string) ($member['country'] ?? '')])
                                        @if (! empty($member['class_label']))
                                            <span class="team-roster__class">{{ e($member['class_label']) }}</span>
                                        @endif
                                        <span class="team-roster__status status-{{ e($member['status']) }}">{{ e($member['status_label']) }}</span>
                                    </span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </aside>
    </div>
</div>
@endsection