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
            <a href="/admin/dashboard" class="btn-admin" style="background: #ff4444; color: white; padding: 8px 12px; text-decoration: none; border-radius: 4px; display: inline-block;">
                <i class="fa-solid fa-user-gear"></i> Panel d'administration
            </a>
        </div>
    @endif

    @include('partials.profile-header')

    @include('partials.profile-about')

    <div class="dashboard-actions flex align-center gap-15">
        <a href="/profile/edit" class="btn-edit-profile">
            <i class="fa-solid fa-pen-to-square"></i> Modifier mes informations
        </a>
        <span class="steamid-hint">SteamID : {!! e($steamid3) !!}</span>
    </div>

    @if (empty($country) || $nameChanged === 0)
        <p class="info-text">
            <i class="fa-solid fa-circle-info"></i>
            Votre profil est incomplet
            @if ($nameChanged === 0 && empty($country))
                (pseudo d'affichage et nationalité non renseignés)
            @elseif ($nameChanged === 0)
                (pseudo d'affichage non renseigné)
            @else
                (nationalité non renseignée)
            @endif
            — <a href="/profile/edit">complétez-le ici</a>.
        </p>
    @endif
</div>

<br>

@include('partials.profile-initial-data')

@include('partials.profile-stats')
@endsection
