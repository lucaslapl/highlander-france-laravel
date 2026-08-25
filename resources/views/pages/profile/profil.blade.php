@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@push('styles')
@once
<link rel="stylesheet" href="/_css/profile.css">
@endonce
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="/_js/profil.js"></script>
@endpush

@section('content')

<div class="personnal-info">
    @if (\App\Services\Auth::isAdmin())
        <div class="admin-profile-box" style="background: #2c1a1a; border: 1px solid #ff4444; padding: 15px; margin: 15px 0 15px 0; border-radius: 5px;">
            <h4 style="color: #ff4444; margin-top: 0;"><i class="fa-solid fa-screwdriver-wrench"></i> Outils d'administration</h4>
            <p>Vous visualisez le profil de : <strong>{!! e($playerName) !!}</strong></p>
            <p>SteamID64 : <code>{!! e($steamid64) !!}</code></p>
            <p>SteamID3 : <code>{!! e($steamid3) !!}</code></p>

            <a href="/admin/manage-player/{!! e($steamid64) !!}" class="btn-admin" style="background: #ff4444; color: white; padding: 8px 12px; text-decoration: none; border-radius: 4px; display: inline-block;">
                <i class="fa-solid fa-user-gear"></i> Gérer ce joueur
            </a>
        </div>
    @endif

    @include('partials.profile-header')

    <a href="https://steamcommunity.com/profiles/{!! e($steamid64) !!}" target="_blank" class="steam-profile-link" style="margin-top: 15px; display: inline-block;">
        <i class="fab fa-steam"></i> Profil Steam
    </a>
</div>

<br>

@include('partials.profile-initial-data')

@include('partials.profile-stats')
@endsection
