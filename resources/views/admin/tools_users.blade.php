@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@push('scripts')
<script src="/_js/admin_player_search.js"></script>
@endpush

@section('content')

<div class="admin-back">
    <a href="/admin/dashboard">
        <i class="fa-solid fa-arrow-left"></i> Retour au Panel Admin
    </a>
</div>

<div class="admin-header" style="--accent: #ff4444;">
    <h2><i class="fa-solid fa-users"></i> Gestion des utilisateurs</h2>
    <p>Outils de modération et de gestion des rôles des membres de Highlander France.</p>
</div>

<div class="admin-cards-grid">

    <div class="admin-action-card" style="--accent: #ff4444;">
        <div>
            <h4 class="admin-action-card__title"><i class="fa-solid fa-users-gear"></i> Modération des joueurs</h4>
            <p class="admin-action-card__desc">Rechercher un profil, attribuer/retirer des rôles, changer le pseudo ou nationalité d'un joueur (ou réinitialiser la restriction associée).</p>
        </div>
        <div class="search-container">
            <input type="text" id="player-search-input" placeholder="Rechercher un joueur..." autocomplete="off">
            <div id="search-results-dropdown" class="search-dropdown" style="display: none;"></div>
        </div>
    </div>

    <div class="admin-action-card" style="--accent: #00bc8c;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-user-shield"></i> Staff & Rôles</h4>
        <p class="admin-action-card__desc">Liste complète des utilisateurs possédant un rôle staff pour vérifier les permissions globales.</p>
        <a href="/admin/list-staff" class="admin-link-btn">Voir l'équipe</a>
    </div>

</div>

@endsection
