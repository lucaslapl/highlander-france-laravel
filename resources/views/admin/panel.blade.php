@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
<div class="admin-header" style="--accent:#9b6dff;">
    <h2><i class="fa-solid fa-screwdriver-wrench"></i> Panel admin</h2>
    <p>Outils d'overlay OBS pour les broadcasts Highlander France.</p>
</div>

<div class="admin-cards-grid">
    <div class="admin-action-card" style="--accent: #9b6dff;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-tv"></i> Overlay match (OBS)</h4>
        <p class="admin-action-card__desc">Génération d'overlays de stats logs.tf pour OBS : équipes, scores, joueurs et medics, avec transparence réglable.</p>
        <a href="/admin/overlay" class="admin-link-btn">Gérer les overlays</a>
    </div>

    <div class="admin-action-card" style="--accent: #4cc46a;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-trophy"></i> Séries de matchs (playoffs)</h4>
        <p class="admin-action-card__desc">Suivi du score d'une série de playoffs via logs.tf, rafraîchi automatiquement pendant le cast, avec ajustements manuels.</p>
        <a href="/admin/series" class="admin-link-btn">Gérer les séries</a>
    </div>
</div>
@endsection
