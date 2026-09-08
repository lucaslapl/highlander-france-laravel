@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@section('content')

<h1>L'équipe Highlander France</h1>
<div id="staff" class="staff-categories">

    <article class="staff-category">
        <h3>Fondateurs</h3>
        <hr>
        <p>Les joueurs passionnés à l'initiative de ce projet !</p>
        <div class="staff-role">
            @if (empty($groups['founders']))
                <p class="no-data">Aucun fondateur enregistré pour le moment.</p>
            @else
                @foreach ($groups['founders'] as $f)
                <div class="staff-member">
                    <img loading="lazy" decoding="async" src="{!! e($f['avatar_url'] ?? $f['avatar']) !!}" alt="Avatar de {!! e($f['final_name']) !!}" width="64" height="64">
                    <div class="staff-name-group">
                        <a href="{!! e($f['profile_url']) !!}">
                            <h4>{!! e($f['final_name']) !!}</h4>
                        </a>
                    </div>
                </div>
                @endforeach
            @endif
        </div>
    </article>

    @if (!empty($groups['moderators']))
    <article class="staff-category">
        <h3>Modération</h3>
        <hr>
        <p>L'équipe en charge du respect des règles et de la bonne ambiance.</p>
        <div class="staff-role">
            @foreach ($groups['moderators'] as $m)
            <div class="staff-member">
                <img loading="lazy" decoding="async" src="{!! e($m['avatar_url'] ?? $m['avatar']) !!}" alt="Avatar de {!! e($m['final_name']) !!}" width="64" height="64">
                <div class="staff-name-group">
                    <a href="{!! e($m['profile_url']) !!}">
                        <h4>{!! e($m['final_name']) !!}</h4>
                    </a>
                </div>
            </div>
            @endforeach
        </div>
    </article>
    @endif

    <article class="staff-category">
        <h3>Mentors</h3>
        <hr>
        <p>Les joueurs expérimentés qui accompagnent les nouveaux venus.</p>
        <div class="staff-role">
            @if (empty($groups['mentors']))
                <p class="no-data">Aucun mentor enregistré pour le moment.</p>
            @else
                @foreach ($groups['mentors'] as $me)
                <div class="staff-member">
                    <img loading="lazy" decoding="async" src="{!! e($me['avatar_url'] ?? $me['avatar']) !!}" alt="Avatar de {!! e($me['final_name']) !!}" width="64" height="64">
                    <div class="staff-name-group">
                        <a href="{!! e($me['profile_url']) !!}">
                            <h4>{!! e($me['final_name']) !!}</h4>
                        </a>
                    </div>
                </div>
                @endforeach
            @endif
        </div>
    </article>

    <article class="staff-category">
        <h3>Lanceurs de mix</h3>
        <hr>
        <p>Les joueurs qui organisent les mixs dans une ambiance conviviale !</p>
        <div class="staff-role">
            @if (empty($groups['mixers']))
                <p class="no-data">Aucun lanceur de mix enregistré pour le moment.</p>
            @else
                @foreach ($groups['mixers'] as $mi)
                <div class="staff-member">
                    <img loading="lazy" decoding="async" src="{!! e($mi['avatar_url'] ?? $mi['avatar']) !!}" alt="Avatar de {!! e($mi['final_name']) !!}" width="64" height="64">
                    <div class="staff-name-group">
                        <a href="{!! e($mi['profile_url']) !!}">
                            <h4>{!! e($mi['final_name']) !!}</h4>
                        </a>
                    </div>
                </div>
                @endforeach
            @endif
        </div>
    </article>

    <article class="staff-category">
        <h3>Twitch Highlander France</h3>
        <hr>
        <p>Les casters et l'équipe de production qui font vivre nos streams !</p>
        <div class="staff-role">
            @if (empty($groups['twitch']))
                <p class="no-data">Aucun membre stream enregistré pour le moment.</p>
            @else
                @foreach ($groups['twitch'] as $tw)
                <div class="staff-member">
                    <img loading="lazy" decoding="async" src="{!! e($tw['avatar_url'] ?? $tw['avatar']) !!}" alt="Avatar de {!! e($tw['final_name']) !!}" width="64" height="64">
                    <div class="staff-name-group">
                        <a href="{!! e($tw['profile_url']) !!}">
                            <h4>{!! e($tw['final_name']) !!}</h4>
                        </a>
                        @if ((int) $tw['is_caster'] === 1 || (int) $tw['is_producer'] === 1)
                        <div class="twitch-badges">
                            @if ((int) $tw['is_caster'] === 1)
                            <span class="twitch-badge twitch-badge-caster">Caster</span>
                            @endif
                            @if ((int) $tw['is_producer'] === 1)
                            <span class="twitch-badge twitch-badge-producer">Production</span>
                            @endif
                        </div>
                        @endif
                    </div>
                </div>
                @endforeach
            @endif
        </div>
    </article>

</div>

@push('scripts')
@include('partials.scroll-animation')
@endpush
@endsection