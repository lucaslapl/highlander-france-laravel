@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@section('content')

<h1>L'équipe Highlander France</h1>
<div id="staff">

    @if (!empty($groups['founders']))
    <h3>Fondateurs</h3>
    <hr>
    <p>Les joueurs passionnés à l'initiative de ce projet !</p>
    <div class="staff-role flex space-around align-center wrap">
        @foreach ($groups['founders'] as $f)
        <div class="staff-member">
            <img loading="lazy" decoding="async" src="{!! e($f['avatar']) !!}" alt="Avatar de {!! e($f['final_name']) !!}">
            <a href="{!! e($f['profile_url']) !!}">
                <h4>{!! e($f['final_name']) !!}</h4>
            </a>
        </div>
        @endforeach
    </div>
    @endif

    @if (!empty($groups['moderators']))
    <h3>Modération</h3>
    <hr>
    <p>L'équipe en charge du respect des règles et de la bonne ambiance au sein de la communauté.</p>
    <div class="staff-role flex space-around align-center wrap">
        @foreach ($groups['moderators'] as $m)
        <div class="staff-member">
            <img loading="lazy" decoding="async" src="{!! e($m['avatar']) !!}" alt="Avatar de {!! e($m['final_name']) !!}">
            <a href="{!! e($m['profile_url']) !!}">
                <h4>{!! e($m['final_name']) !!}</h4>
            </a>
        </div>
        @endforeach
    </div>
    @endif

    <div id="sous-staff" class="flex space-around">

        <div id="mentors">
            <h3>Mentors</h3>
            <hr>
            <p>Les joueurs expérimentés qui accompagnent les nouveaux venus dans leur progression en compétitif !</p>
            <div class="staff-role">
                @if (empty($groups['mentors']))
                    <p class="no-data">Aucun mentor enregistré pour le moment.</p>
                @else
                    @foreach ($groups['mentors'] as $me)
                    <div class="staff-member flex align-center">
                        <img loading="lazy" decoding="async" class="staff-pic" src="{!! e($me['avatar']) !!}" alt="Avatar de {!! e($me['final_name']) !!}">
                        <a href="{!! e($me['profile_url']) !!}">
                            <h4>{!! e($me['final_name']) !!}</h4>
                        </a>
                    </div>
                    @endforeach
                @endif
            </div>
        </div>

        <div id="mixers">
            <h3>Lanceurs de mix</h3>
            <hr>
            <p>Les joueurs qui organisent les mixs pour permettre à tous de jouer en compétitif dans une ambiance conviviale !</p>
            <div class="staff-role">
                @if (empty($groups['mixers']))
                    <p class="no-data">Aucun lanceur de mix enregistré pour le moment.</p>
                @else
                    @foreach ($groups['mixers'] as $mi)
                    <div class="staff-member flex align-center">
                        <img loading="lazy" decoding="async" class="staff-pic" src="{!! e($mi['avatar']) !!}" alt="Avatar de {!! e($mi['final_name']) !!}">
                        <a href="{!! e($mi['profile_url']) !!}">
                            <h4>{!! e($mi['final_name']) !!}</h4>
                        </a>
                    </div>
                    @endforeach
                @endif
            </div>
        </div>

    </div>
</div>

@push('scripts')
@include('partials.scroll-animation')
@endpush
@endsection
