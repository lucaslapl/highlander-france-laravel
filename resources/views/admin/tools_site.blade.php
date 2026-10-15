@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')

<div class="admin-back">
    <a href="/admin/dashboard">
        <i class="fa-solid fa-arrow-left"></i> Retour au Panel Admin
    </a>
</div>

<div class="admin-header" style="--accent: #f39c12;">
    <h2><i class="fa-solid fa-gears"></i> Gestion du site</h2>
    <p>Outils de gestion du contenu et de l'infrastructure interne de Highlander France.</p>
</div>

<div class="admin-cards-grid">

    <div class="admin-action-card" style="--accent: #f39c12;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-clock-rotate-left"></i> Logs des matchs joués</h4>
        <p class="admin-action-card__desc">Liste des matchs joués avec nombre de joueurs et durée, avec alertes orange (match court, effectif incomplet).</p>
        <a href="/admin/match-logs" class="admin-link-btn">Voir les logs</a>
    </div>

    <div class="admin-action-card" style="--accent: #f35f5f;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-ban"></i> Logs blacklistés</h4>
        <p class="admin-action-card__desc">Exclure des logs logs.tf des stats et de la page Match Stats, avec motif et traçabilité.</p>
        <a href="/admin/manage-blacklist" class="admin-link-btn">Gérer la blacklist</a>
    </div>

    <div class="admin-action-card" style="--accent: #8a5cf5;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-map-location-dot"></i> Maps ETF2L</h4>
        <p class="admin-action-card__desc">Upload des cartes en .bsp avec miniatures pour la page Maps (6v6 / 9v9).</p>
        <a href="/admin/maps" class="admin-link-btn">Gérer les maps</a>
    </div>

    <div class="admin-action-card" style="--accent: #fbb7fb;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-book-open"></i> Guides & FAQ</h4>
        <p class="admin-action-card__desc">Guides SEO (Markdown étendu, tableaux, blocs couleur) et FAQ par cluster d’intentions.</p>
        <a href="/admin/guides" class="admin-link-btn">Gérer les guides</a>
    </div>

    <div class="admin-action-card" style="--accent: #2ec4b6;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-users-between-lines"></i> Équipes FR</h4>
        <p class="admin-action-card__desc">Import d'équipes ETF2L, roster, leaders, division et logo pour la sidebar d'accueil et les pages /equipes.</p>
        <a href="/admin/equipes" class="admin-link-btn">Gérer les équipes</a>
    </div>

    <div class="admin-action-card" style="--accent: #3498db;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-database"></i> Logs du site <i class="fa-solid fa-triangle-exclamation" style="color: #f39c12;" title="Outil interne : manipuler avec précaution"></i></h4>
        <p class="admin-action-card__desc">Liste des logs du site avec détails et filtres.</p>
        <a href="/admin/view-logs" class="admin-link-btn">Ouvrir l'inspecteur log</a>
    </div>

    <div class="admin-action-card" style="--accent: #f39c12;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-rotate"></i> Tâches CRON <i class="fa-solid fa-triangle-exclamation" style="color: #f39c12;" title="Outil interne : manipuler avec précaution"></i></h4>
        <p class="admin-action-card__desc">NE PAS UTILISER SAUF URGENCE OU SANS Y AVOIR ÉTÉ INVITÉ</p>
        <a href="/admin/run-cron-manual" class="admin-link-btn">Panel CRON</a>
    </div>

    <div class="admin-action-card" style="--accent: #9b59b6;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-flask"></i> Simulateur d'API <i class="fa-solid fa-triangle-exclamation" style="color: #f39c12;" title="Outil interne : manipuler avec précaution"></i></h4>
        <p class="admin-action-card__desc">Simuler un match en direct, des streams Twitch ou créer des matchs ETF2L factices pour tester les affichages sans serveur réel.</p>
        <a href="/admin/api-test" class="admin-link-btn">Ouvrir le simulateur</a>
    </div>

</div>

@endsection
