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
        <h4 class="admin-action-card__title"><i class="fa-solid fa-tv"></i> Overlay Logs (OBS)</h4>
        <p class="admin-action-card__desc">Génération d'overlays de stats logs.tf pour OBS : équipes, scores, joueurs et medics.</p>
        <a href="/admin/overlay" class="admin-link-btn">Gérer les overlays</a>
    </div>

    <div class="admin-action-card" style="--accent: #4cc46a;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-trophy"></i> Overlay Scores</h4>
        <p class="admin-action-card__desc">Overlay de score de série (playoffs) avec suivi logs.tf automatique pendant le cast, et ajustements manuels.</p>
        <a href="/admin/series" class="admin-link-btn">Gérer les overlays de scores</a>
    </div>
</div>
@endsection
