@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')

<div class="admin-back admin-back--split">
    <a href="/admin/dashboard">
        <i class="fa-solid fa-arrow-left"></i> Retour au Panel Admin
    </a>
    <a href="/etf2l/maps" target="_blank">
        <i class="fa-solid fa-eye"></i> Voir la page publique
    </a>
</div>

<div class="admin-header" style="--accent: #8a5cf5;">
    <h2><i class="fa-solid fa-map-location-dot"></i> Gestion des maps ETF2L</h2>
    <p>
        Ajoutez, modifiez ou supprimez les maps de la page « Maps » (6v6 / 9v9).
        Les fichiers <code>.bsp</code> sont limités à 100 Mo, les miniatures à 10 Mo.
    </p>
</div>

{{-- Formulaire d'ajout : drag & drop .bsp + thumbnail --}}
<div class="admin-card">
    <h3 class="admin-card__title">
        <i class="fa-solid fa-plus"></i> Ajouter une map
    </h3>

    <form id="map-add-form" action="/admin/maps/store" method="POST"
          enctype="multipart/form-data" class="admin-form-stack"
          style="--accent: #8a5cf5;">
        @csrf
        <input type="hidden" name="bsp_pending" id="bsp_pending" value="">

        <div class="adm-maps-form-grid">
            <div class="form-group">
                <label class="admin-form-label" for="map-name">Nom de la map</label>
                <input type="text" name="name" id="map-name" class="form-control" required
                       pattern="[a-z0-9_]+" placeholder="cp_process_f12"
                       title="Uniquement des minuscules, chiffres et underscores.">
            </div>
            <div class="form-group">
                <label class="admin-form-label" for="map-label">Nom affiché</label>
                <input type="text" name="label" id="map-label" class="form-control" required
                       placeholder="cp_process_f12">
            </div>
            <div class="form-group">
                <label class="admin-form-label" for="map-category">Catégorie</label>
                <select name="category" id="map-category" class="form-control">
                    <option value="6v6">6v6 — Sixes</option>
                    <option value="9v9">9v9 — Highlander</option>
                </select>
            </div>
            <div class="form-group checkbox-group" style="margin-top: 26px;">
                <label class="admin-label">
                    <input type="checkbox" name="is_active" id="map-is-active" value="1" checked>
                    Map active sur la page publique
                </label>
            </div>
        </div>

        {{-- Zone drag & drop .bsp --}}
        <div id="bsp-dropzone" class="adm-maps-dropzone">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <p class="adm-maps-dropzone-title">Glissez-déposez le fichier <code>.bsp</code> ici</p>
            <p class="adm-maps-dropzone-sub">ou cliquez pour sélectionner (max 100 Mo)</p>
            <input type="file" name="bsp" id="bsp-input" class="adm-maps-dropzone-input"
                   accept=".bsp,application/octet-stream" hidden>
            <div id="bsp-progress" class="adm-maps-progress" hidden>
                <div class="adm-maps-progress-bar"><span id="bsp-progress-fill" style="width: 0%;"></span></div>
                <p id="bsp-progress-text" class="adm-maps-progress-text"></p>
            </div>
        </div>
        <p id="bsp-status" class="adm-maps-file-status"></p>

        {{-- Miniatura --}}
        <div class="form-group">
            <label class="admin-form-label" for="map-thumbnail">Miniature (optionnel, jpg/png/webp, max 10 Mo)</label>
            <input type="file" name="thumbnail" id="map-thumbnail" class="form-control"
                   accept="image/jpeg,image/png,image/webp">
        </div>

        <div>
            <button type="submit" id="map-submit-btn" class="admin-btn admin-btn--primary" style="--accent: #8a5cf5;">
                <i class="fa-solid fa-plus"></i> Ajouter la map
            </button>
            <span id="map-add-error" class="adm-maps-form-error"></span>
        </div>
    </form>
</div>

{{-- Liste des maps 6v6 --}}
<div class="admin-card">
    <h3 class="admin-card__title">
        <i class="fa-solid fa-users"></i> 6v6 — Sixes
        <span class="status-pill" style="--accent: #8a5cf5;">{{ count($maps6v6) }}</span>
    </h3>
    <div class="admin-table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 64px;">Miniature</th>
                    <th>Nom</th>
                    <th>Fichier .bsp</th>
                    <th class="text-center">Statut</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody id="maps-6v6" data-category="6v6">
                @foreach ($maps6v6 as $map)
                    @include('admin.partials.map_row', ['map' => $map])
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Liste des maps 9v9 --}}
<div class="admin-card">
    <h3 class="admin-card__title">
        <i class="fa-solid fa-people-group"></i> 9v9 — Highlander
        <span class="status-pill" style="--accent: #8a5cf5;">{{ count($maps9v9) }}</span>
    </h3>
    <div class="admin-table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 64px;">Miniature</th>
                    <th>Nom</th>
                    <th>Fichier .bsp</th>
                    <th class="text-center">Statut</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody id="maps-9v9" data-category="9v9">
                @foreach ($maps9v9 as $map)
                    @include('admin.partials.map_row', ['map' => $map])
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Modal d'édition --}}
<div id="map-edit-modal" class="adm-maps-modal" hidden>
    <div class="adm-maps-modal-box admin-card" role="dialog" aria-modal="true" aria-labelledby="map-edit-title">
        <div class="adm-maps-modal-head">
            <h3 id="map-edit-title"><i class="fa-solid fa-pen"></i> Modifier la map</h3>
            <button type="button" class="adm-maps-modal-close" id="map-edit-close" title="Fermer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form id="map-edit-form" action="" method="POST" enctype="multipart/form-data" class="admin-form-stack"
              style="--accent: #8a5cf5;">
            @csrf
            <div class="adm-maps-form-grid">
                <div class="form-group">
                    <label class="admin-form-label" for="edit-label">Nom affiché</label>
                    <input type="text" name="label" id="edit-label" class="form-control" required placeholder="cp_process_f12">
                </div>
                <div class="form-group">
                    <label class="admin-form-label" for="edit-category">Catégorie</label>
                    <select name="category" id="edit-category" class="form-control">
                        <option value="6v6">6v6 — Sixes</option>
                        <option value="9v9">9v9 — Highlander</option>
                    </select>
                </div>
                <div class="form-group checkbox-group" style="margin-top: 26px;">
                    <label class="admin-label">
                        <input type="checkbox" name="is_active" id="edit-is-active" value="1">
                        Map active sur la page publique
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="admin-form-label" for="edit-thumbnail">
                    Remplacer la miniature (jpg/png/webp, max 10 Mo)
                </label>
                <input type="file" name="thumbnail" id="edit-thumbnail" class="form-control"
                       accept="image/jpeg,image/png,image/webp">
            </div>

            <p class="adm-maps-edit-info">
                <i class="fa-solid fa-circle-info"></i>
                Le nom de la map et le fichier <code>.bsp</code> ne peuvent pas être modifiés ici.
                Supprimez puis ré-ajoutez la map pour les changer.
            </p>

            <div>
                <button type="submit" class="admin-btn admin-btn--primary" style="--accent: #8a5cf5;">
                    <i class="fa-solid fa-check"></i> Enregistrer
                </button>
                <button type="button" class="admin-btn" id="map-edit-cancel">Annuler</button>
                <span id="map-edit-error" class="adm-maps-form-error"></span>
            </div>
        </form>
    </div>
</div>

@endsection

@push('styles')
<meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@push('scripts')
<script src="{{ hlfr_asset('/_js/admin_maps.js') }}" defer></script>
@endpush