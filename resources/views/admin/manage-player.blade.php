@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')

<div class="admin-back admin-back--split">
    <a href="/admin/dashboard">
        <i class="fa-solid fa-arrow-left"></i> Retour au Panel Admin
    </a>
    <a href="/profile/{!! e($target['steamid64']) !!}">
        Voir le profil public <i class="fa-solid fa-arrow-right-to-bracket"></i>
    </a>
</div>

<div class="admin-header" style="--accent: #ff4444;">
    <h2><i class="fa-solid fa-user-gear"></i> Panel d'édition de compte utilisateur</h2>
</div>

<div class="player-profile-header">
    <img src="{!! e($target['player']['avatar'] ?? '/_img/default_avatar.jpg') !!}" alt="Avatar" class="player-avatar">
    <div>
        <h3 style="margin: 0 0 5px 0; color: #fff; font-size: 20px;">{!! e($target['final_name']) !!}</h3>
        <p style="font-family: monospace; color: #888; font-size: 13px;">SteamID64 : {!! e($target['steamid64']) !!}</p>
        <p style="font-family: monospace; color: #888; font-size: 13px;">SteamID3 : {!! e($target['player']['steamid']) !!}</p>
        <span style="display: block; margin-top: 5px; font-size: 12px; color: #aaa;">
            {{ (int)$target['player']['is_admin'] === 1 ? '<span class="badge badge-admin">Admin</span>' : '' }}
            {{ (int)$target['player']['is_founder'] === 1 ? '<span class="badge badge-founder">Fondateur</span>' : '' }}
            {{ (int)$target['player']['is_moderator'] === 1 ? '<span class="badge badge-moderator">Modérateur</span>' : '' }}
            {{ (int)$target['player']['is_mentor'] === 1 ? '<span class="badge badge-mentor">Mentor</span>' : '' }}
            {{ (int)$target['player']['is_mixer'] === 1 ? '<span class="badge badge-mixer">Lanceur de Mix</span>' : '' }}
        </span>
    </div>
</div>

<div class="admin-card">
    <form action="/api/admin/player-update" method="POST">
        @csrf
        <input type="hidden" name="target_steamid" value="{!! e($target['steamid64']) !!}">

        <div class="form-section">
            <h3><i class="fa-solid fa-id-card"></i> Informations du Profil</h3>
            <div class="form-grid-2">

                <div class="form-group">
                    <label for="display_name">Pseudo enregistré sur le site :</label>
                    <input type="text" name="display_name" id="display_name" class="form-control"
                        value="{!! e($target['player']['display_name'] ?? '') !!}" required>
                </div>

                <div class="form-group">
                    <label for="country">Nationalité :</label>
                    <select name="country" id="country" class="form-control">
                        @foreach (COUNTRIES as $value => $label)
                            <option value="{!! e($value) !!}" {{ $target['current_country'] === $value ? 'selected' : '' }}>
                                {!! e($label) !!}
                            </option>
                        @endforeach

                        @if (!array_key_exists($target['current_country'], COUNTRIES) && !empty($target['player']['country']))
                            <option value="{!! e($target['current_country']) !!}" selected>
                                {!! e(ucfirst((string)$target['player']['country'])) !!} (Actuel)
                            </option>
                        @endif
                    </select>
                </div>

            </div>
        </div>

        <div class="form-section">
            <h3><i class="fa-solid fa-users-viewfinder"></i> Gestion des rôles Staff</h3>
            <div class="checkbox-group">
                <label class="admin-label">
                    <input type="checkbox" name="is_founder" value="1" {{ (int)$target['player']['is_founder'] === 1 ? 'checked' : '' }}>
                    <span>Fondateur</span>
                </label>

                <label class="admin-label">
                    <input type="checkbox" name="is_moderator" value="1" {{ (int)$target['player']['is_moderator'] === 1 ? 'checked' : '' }}>
                    <span>Modérateur</span>
                </label>

                <label class="admin-label">
                    <input type="checkbox" name="is_mentor" value="1" {{ (int)$target['player']['is_mentor'] === 1 ? 'checked' : '' }}>
                    <span>Mentor</span>
                </label>

                <label class="admin-label">
                    <input type="checkbox" name="is_mixer" value="1" {{ (int)$target['player']['is_mixer'] === 1 ? 'checked' : '' }}>
                    <span>Lanceur de Mix</span>
                </label>
            </div>
        </div>

        <div class="form-section">
            <h3><i class="fa-solid fa-shield-halved"></i> Modération avancée</h3>
            <div style="display: flex; flex-direction: column; gap: 12px;">

                <label class="admin-label" style="justify-content: space-between; width: 100%; box-sizing: border-box;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" name="reset_name_change" value="1">
                        <div>
                            <strong>Forcer la réinitialisation du changement de pseudo</strong><br>
                            <span style="font-size: 12px; color: #aaa;">Cocher la case pour permettre au joueur de modifier de lui-même à nouveau son pseudo depuis son profil.</span>
                        </div>
                    </div>
                    <div>
                        @if ((int)$target['player']['name_changed'] === 1)
                            <span class="badge-status" style="background: #d9534f; color: #fff;">Déjà utilisé</span>
                        @else
                            <span class="badge-status" style="background: #5cb85c; color: #fff;">Libre</span>
                        @endif
                    </div>
                </label>

                <label class="admin-label" style="justify-content: space-between; width: 100%; box-sizing: border-box;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" name="reset_country_change" value="1">
                        <div>
                            <strong>Forcer la réinitialisation du changement de nationalité</strong><br>
                            <span style="font-size: 12px; color: #aaa;">Cocher la case pour permettre au joueur de modifier de lui-même à nouveau son drapeau/pays depuis son profil.</span>
                        </div>
                    </div>
                    <div>
                        @if (isset($target['player']['country_locked']) && (int)$target['player']['country_locked'] === 1)
                            <span class="badge-status" style="background: #d9534f; color: #fff;">Déjà utilisé</span>
                        @else
                            <span class="badge-status" style="background: #5cb85c; color: #fff;">Libre</span>
                        @endif
                    </div>
                </label>

            </div>
        </div>

        <div style="margin-top: 10px;">
            <button type="submit" class="admin-btn admin-btn--primary">
                <i class="fa-solid fa-floppy-disk"></i> Enregistrer les modifications
            </button>
        </div>
    </form>
</div>
@endsection
