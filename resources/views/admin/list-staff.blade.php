@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')

<div class="admin-back">
    <a href="/admin/dashboard">
        <i class="fa-solid fa-arrow-left"></i> Retour au Panel Admin
    </a>
</div>

<div class="admin-header" style="--accent: #00bc8c;">
    <h2><i class="fa-solid fa-user-shield"></i> Gestion de l'équipe staff</h2>
    <p>Vue d'ensemble de tous les comptes possédant un rang particulier sur Highlander France.</p>
</div>

@if (empty($staff))
    <div class="admin-empty">
        Aucun membre du staff trouvé dans la base de données.
    </div>
@else
    <table class="admin-table">
        <thead>
            <tr>
                <th>Membre</th>
                <th>SteamID64</th>
                <th>Rôles actifs</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($staff as $member)
                <tr>
                    <td>
                        <img loading="lazy" decoding="async" src="{!! e($member['avatar']) !!}" alt="Avatar" class="staff-avatar">
                        <strong style="color: #fff;">{!! e($member['final_name']) !!}</strong>
                    </td>

                    <td style="font-family: monospace; color: #aaa; font-size: 13px;">
                        {!! e($member['steamid64']) !!}
                    </td>

                    <td>
                        {{ (int)$member['is_admin'] === 1 ? '<span class="badge badge-admin">ADMIN</span>' : '<span class="badge badge-disabled">ADMIN</span>' }}
                        {{ (int)$member['is_founder'] === 1 ? '<span class="badge badge-founder">FONDATEUR</span>' : '<span class="badge badge-disabled">FONDATEUR</span>' }}
                        {{ (int)$member['is_moderator'] === 1 ? '<span class="badge badge-moderator">MODO</span>' : '<span class="badge badge-disabled">MODO</span>' }}
                        {{ (int)$member['is_mentor'] === 1 ? '<span class="badge badge-mentor">MENTOR</span>' : '<span class="badge badge-disabled">MENTOR</span>' }}
                        {{ (int)$member['is_mixer'] === 1 ? '<span class="badge badge-mixer">MIXER</span>' : '<span class="badge badge-disabled">MIXER</span>' }}
                    </td>

                    <td class="text-center">
                        <a href="/admin/manage-player/{!! e($member['steamid64']) !!}" class="admin-btn">
                            <i class="fa-solid fa-user-gear" style="color: #ff4444;"></i> Modifier les rôles
                        </a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
@endsection
