@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
<div class="admin-header" style="--accent:#9b6dff;">
    <h2><i class="fa-solid fa-tv"></i> Overlays Stream</h2>
    <p>Les cinq outils d'overlay OBS pour les broadcasts Highlander France, au même endroit.</p>
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

    <div class="admin-action-card" style="--accent: #e97fff;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-sitemap"></i> Overlay Bracket</h4>
        <p class="admin-action-card__desc">Brackets de playoffs et classements (poules) construits à la main, éditables, avec match « EN DIRECT » rattachable à une série.</p>
        <a href="/admin/overlay/bracket" class="admin-link-btn">Gérer les brackets</a>
    </div>

    <div class="admin-action-card" style="--accent: #fbb7fb;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-map"></i> Overlay Pick/Ban</h4>
        <p class="admin-action-card__desc">Pick / ban de maps (3 picks + 3 bans ou 5 picks + 1 ban) avec remplissage assisté ETF2L : équipes, avatars et maps officielles.</p>
        <a href="/admin/overlay/pickban" class="admin-link-btn">Gérer les pick/ban</a>
    </div>

    <div class="admin-action-card" style="--accent: #ffb3ec;">
        <h4 class="admin-action-card__title"><i class="fa-solid fa-users"></i> Overlay Rosters</h4>
        <p class="admin-action-card__desc">Présentation des rosters (une équipe à la fois, switch depuis les réglages) : Highlander 3x3 ou 6v6 en ligne, remplissage assisté ETF2L, bustes de classes et badge merc.</p>
        <a href="/admin/overlay/rosters" class="admin-link-btn">Gérer les rosters</a>
    </div>
</div>
@endsection
