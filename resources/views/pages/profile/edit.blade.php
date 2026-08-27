@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@push('styles')
<link rel="stylesheet" href="{{ hlfr_asset('/_css/profile.css') }}">
@endpush

@section('content')

<div class="personnal-info">

    <div class="edit-header flex align-center gap-15">
        <a href="/profile/dashboard" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Retour au profil
        </a>
        <h2 style="margin: 0;">Modifier mes informations</h2>
    </div>

    <p class="info-text">Toutes ces informations sont <strong>facultatives</strong>, sauf mention contraire. Elles restent modifiables à tout moment.</p>

    {{-- ─── Pseudo ─── --}}
    <div class="edit-card">
        <h3><i class="fa-solid fa-signature"></i> Pseudo d'affichage</h3>

        @if ($nameChanged === 1)
            <p>Pseudo enregistré : <strong>{!! e($player['display_name']) !!}</strong></p>
            <p class="info-text">Ce pseudo est définitif et ne peut plus être modifié.</p>
        @else
            <p class="info-text"><strong>Attention :</strong> ce changement est <strong>unique et définitif</strong>. Vous ne pourrez plus le modifier par la suite.</p>

            <form action="/profile/update-name" method="POST" class="form-grid form-grid--single">
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

                <button type="submit" class="btn-submit" onclick="return confirm('Êtes-vous sûr ? Ce changement est définitif et unique !');">
                    <i class="fa-solid fa-floppy-disk"></i> Confirmer définitivement
                </button>
            </form>
        @endif
    </div>

    {{-- ─── Nationalité ─── --}}
    <div class="edit-card">
        <h3><i class="fa-solid fa-flag"></i> Nationalité</h3>

        @if ($isLocked && !empty($country))
            <div class="flex align-center gap-10">
                <img loading="lazy" decoding="async" src="/_img/flags/{!! e($country) !!}.gif" alt="{!! e($countries[$country] ?? $country) !!}" class="flag-icon">
                <span>Nationalité enregistrée : <strong>{!! e($countries[$country] ?? strtoupper($country)) !!}</strong></span>
            </div>
        @else
            <form action="/profile/update-country" method="POST" class="form-grid form-grid--inline">
                @csrf
                <p class="info-text" style="margin: 0;">Sélectionnez votre nationalité (ce choix sera <strong>définitif</strong>) :</p>

                <select name="country" required class="select-country">
                    <option value="" disabled selected>Choisir un pays...</option>
                    @foreach ($countries as $code => $name)
                        <option value="{!! e($code) !!}">{!! e($name) !!}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-floppy-disk"></i> Confirmer
                </button>
            </form>
        @endif
    </div>

    {{-- ─── Liens ─── --}}
    <div class="edit-card">
        <h3><i class="fa-solid fa-link"></i> Mes liens</h3>
        <p class="info-text">Affichés sur votre profil public. Laissez vide un champ pour retirer un lien existant.</p>

        <form action="/profile/update-links" method="POST">
            @csrf

            <div class="form-grid">
                @foreach ($profileLinks as $field => $meta)
                    <div class="form-group">
                        <label for="{!! e($field) !!}" class="flex align-center gap-5">
                            <i class="{!! e($meta['icon']) !!} profile-link-icon-label"></i>
                            {!! e($meta['label']) !!}
                        </label>
                        <input
                            type="text"
                            id="{!! e($field) !!}"
                            name="{!! e($field) !!}"
                            value="{!! e($player[$field] ?? '') !!}"
                            placeholder="{!! e($meta['placeholder'] ?? '') !!}"
                            maxlength="{!! e($meta['type'] === 'url' ? 255 : $meta['max_length']) !!}"
                            class="form-control">
                    </div>
                @endforeach
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-floppy-disk"></i> Enregistrer mes liens
            </button>
        </form>
    </div>

    {{-- ─── Infos personnelles ─── --}}
    <div class="edit-card">
        <h3><i class="fa-solid fa-id-card"></i> Infos personnelles &amp; matériel</h3>
        <p class="info-text">Renseignez uniquement ce que vous souhaitez rendre visible sur votre profil public.</p>

        <form action="/profile/update-personal-info" method="POST">
            @csrf

            <div class="form-grid">
                <div class="form-group">
                    <label for="birthdate" class="flex align-center gap-5">
                        <i class="fa-solid fa-cake-candles profile-link-icon-label"></i>
                        Date de naissance
                    </label>
                    <input
                        type="date"
                        id="birthdate"
                        name="birthdate"
                        value="{!! e($player['birthdate'] ?? '') !!}"
                        min="1900-01-01"
                        max="{!! e(now()->toDateString()) !!}"
                        class="form-control">
                </div>

                @foreach ($profileGear as $field => $meta)
                    <div class="form-group">
                        <label for="{!! e($field) !!}" class="flex align-center gap-5">
                            <i class="{!! e($meta['icon']) !!} profile-link-icon-label"></i>
                            {!! e($meta['label']) !!}
                        </label>
                        <input
                            type="text"
                            id="{!! e($field) !!}"
                            name="{!! e($field) !!}"
                            value="{!! e($player[$field] ?? '') !!}"
                            placeholder="Ex. : HyperX Cloud II, Wooting 60HE..."
                            maxlength="100"
                            class="form-control">
                    </div>
                @endforeach
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-floppy-disk"></i> Enregistrer mes informations
            </button>
        </form>
    </div>
</div>

@endsection
