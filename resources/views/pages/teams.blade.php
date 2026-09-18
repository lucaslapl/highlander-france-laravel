@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@section('content')
<div class="teams-page">
    <header class="teams-header">
        <h1><i class="fa-solid fa-users-between-lines"></i> Équipes françaises</h1>
        <p>Les équipes francophones actives en compétitif 9v9, classées par division. Cliquez sur une équipe pour découvrir son roster, sa présentation et ses derniers résultats.</p>
    </header>

    @php
        $grouped = [];
        foreach ($teams as $team) {
            $grouped[$team['division'] ?? ''][] = $team;
        }
    @endphp

    @if (empty($grouped))
        <p class="no-data">Aucune équipe active pour le moment. Revenez bientôt !</p>
    @else
        @foreach ($grouped as $divisionKey => $divisionTeams)
            <section class="teams-group">
                <h2 class="teams-group__title">{{ e($divisions[$divisionKey] ?? 'Autres') }}</h2>
                <div class="teams-cards">
                    @foreach ($divisionTeams as $team)
                        <a href="/equipes/{{ e($team['slug']) }}" class="team-card" title="{{ e($team['name']) }}">
                            <span class="team-card__logo">
                                @if (! empty($team['logo_url']))
                                    <img loading="lazy" decoding="async" src="{{ e($team['logo_url']) }}" alt="" width="56" height="56">
                                @else
                                    {{ e($team['tag'] !== null && $team['tag'] !== '' ? $team['tag'] : mb_substr((string) $team['name'], 0, 3)) }}
                                @endif
                            </span>
                            <span class="team-card__body">
                                <span class="team-card__name">
                                    {{ e($team['name']) }}
                                    @if (! empty($team['tag']))
                                        <span class="team-card__tag">[{{ e($team['tag']) }}]</span>
                                    @endif
                                </span>
                                <span class="team-card__meta">
                                    @include('partials.flag', ['country' => $team['country'] ?? null, 'label' => (string) ($team['country'] ?? '')])
                                    <span>{{ (int) $team['member_count'] }} joueur{{ (int) $team['member_count'] > 1 ? 's' : '' }}</span>
                                </span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
</div>
@endsection