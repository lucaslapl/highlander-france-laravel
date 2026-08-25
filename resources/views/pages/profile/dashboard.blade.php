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

    <h3>Informations personnelles</h3>
    <p>SteamID : {!! e($steamid3) !!}</p>

    <br>

    <div class="dashboard-box">
        <h3>Votre pseudo</h3>

        @if ($nameChanged === 1)
            <p>Pseudo enregistré : <strong>{!! e($player['display_name']) !!}</strong></p>
        @else
            <p class="info-text"><strong>Attention :</strong> Ce changement est <strong>unique et définitif</strong>. Vous ne pourrez plus le modifier par la suite.</p>

            <form action="/profile/update-name" method="POST" class="flex flex-column gap-10">
                @csrf
                <div class="form-group">
                    <label for="display_name">Nouveau pseudo :</label>
                    <input
                        type="text"
                        id="display_name"
                        name="display_name"
                        value="{!! e($player['display_name'] ?? $player['name']) !!}"
                        maxlength="32"
                        required
                        class="form-control">
                </div>

                <button type="submit" name="action" value="update_name" class="btn-submit" onclick="return confirm('Êtes-vous sûr ? Ce changement est définitif et unique !');" style="background: #525252; border: 1px solid #333; color: white; padding: 8px; border-radius: 4px;width: 190px;">
                    <i class="fa-solid fa-floppy-disk"></i> Confirmer définitivement
                </button>
            </form>
        @endif
    </div>

    <h3>Nationalité</h3>

    @if ($isLocked && !empty($country))
        <div class="flex align-center gap-10">
            <img loading="lazy" decoding="async" src="/_img/flags/{!! e($country) !!}.gif" alt="{!! e($countries[$country] ?? $country) !!}" class="flag-icon">
            <span>Nationalité enregistrée : <strong>{!! e($countries[$country] ?? strtoupper($country)) !!}</strong></span>
        </div>
    @else
        <form action="/profile/update-country" method="POST" class="country-form">
            @csrf
            <p>Sélectionnez votre nationalité (ce choix sera <strong>définitif</strong>) :</p>

            <div class="flex align-center gap-10">
                <select name="country" required class="select-country">
                    <option value="" disabled selected>Choisir un pays...</option>
                    @foreach ($countries as $code => $name)
                        <option value="{!! e($code) !!}">{!! e($name) !!}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn-submit-country">Confirmer</button>
            </div>
        </form>
    @endif
</div>

<br>

@include('partials.profile-initial-data')

@include('partials.profile-stats')
@endsection
