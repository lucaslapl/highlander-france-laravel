@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

<div class="admin-header" style="--accent:#4cc46a;">
    <h2><i class="fa-solid fa-trophy"></i> Overlay Scores</h2>
    <p>Overlay de score de série (playoffs) avec suivi automatique via logs.tf : lancez le suivi et chaque log uploadé par le serveur de match met à jour les maps gagnées — sans aucun alt-tab pendant le cast. L'overlay n'est actif et les logs ne sont récupérés qu'après avoir cliqué sur « Lancer le suivi » dans le détail de la série. Les ajustements manuels (contestation, log manquant) restent possibles dans le détail de chaque série.</p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-plus"></i> Créer une série</h3>
<form method="POST" action="/admin/series/create" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div class="admin-form-row">
        <label class="admin-form-label" for="series-title">Titre de la série</label>
        <input type="text" id="series-title" name="title" class="form-control"
               placeholder="Ex : Demi-finale playoffs HLFR — Les Baguettes vs Escouade 6" required>
        <p style="color:#777; font-size:12px; margin:6px 0 0;">
            Sans importance sur l'overlay produit : le titre ne s'affiche nulle part dans OBS, il sert uniquement de repère visuel sur le site.
        </p>
    </div>

    <div class="admin-form-row" style="display:flex; gap:24px; flex-wrap:wrap;">
        @foreach (['red' => 'A', 'blue' => 'B'] as $team => $label)
            <div style="flex:1; min-width:300px; border:1px solid #333; border-radius:8px; padding:14px;">
                <h4 style="margin:0 0 10px; color: {{ $team === 'red' ? '#e06c5a' : '#5885a2' }};">Équipe {{ $label }}</h4>
                <p style="color:#777; font-size:12px; margin:0 0 10px;">
                    La couleur n'a aucune importance sur le match : elle ne fait que fixer l'ordre d'affichage
                    {{ $team === 'red' ? 'à gauche' : 'à droite' }} sur l'overlay — ensuite les logs font le travail,
                    quel que soit le côté où le jeu place réellement les équipes.
                </p>
                <label class="admin-form-label" for="series-{{ $team }}-name">Nom de l'équipe (acronyme)</label>
                <input type="text" id="series-{{ $team }}-name" name="{{ $team }}_name" class="form-control"
                       placeholder="Ex : IG" maxlength="64" required>
                <p style="color:#777; font-size:12px; margin:6px 0 0;">
                    Utilisez l'acronyme de l'équipe (ex : Inglorious Gamblers devient IG) : l'espace disponible sur
                    l'overlay est très réduit, un nom complet ne tiendra pas.
                </p>
                <label class="admin-form-label" style="margin-top:10px;" for="series-{{ $team }}-players">Joueurs du match (SteamIDs)</label>
                <textarea id="series-{{ $team }}-players" name="{{ $team }}_players" class="form-control" rows="3"
                          placeholder="76561198012345678&#10;STEAM_1:0:12345" required></textarea>
                <p style="color:#777; font-size:12px; margin:6px 0 0;">
                    Un à deux SteamIDs de joueurs qui vont jouer le match suffisent : ils servent uniquement à
                    retrouver avec suffisamment de fiabilité les logs correspondant aux matchs joués.
                    Formats acceptés : SteamID64, STEAM_1:X:Y ou [U:1:N].
                </p>
                <label class="admin-form-label" style="margin-top:10px;" for="series-{{ $team }}-avatar-url">Avatar par URL externe (optionnel)</label>
                <input type="url" id="series-{{ $team }}-avatar-url" name="{{ $team }}_avatar_url" class="form-control"
                       placeholder="https://…/logo.png">
                @include('admin.partials.avatar_memory', ['team' => $team, 'label' => $label])
            </div>
        @endforeach
    </div>

    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        Chaque équipe saisie avec son avatar est mémorisée (visuel + nom) et proposée en un clic lors des prochaines créations — la même mémoire que l'outil Overlay Logs.
    </p>

    <div class="admin-form-row" style="display:flex; gap:24px; flex-wrap:wrap;">
        <div style="min-width:180px;">
            <label class="admin-form-label" for="series-format">Format de la série</label>
            <select id="series-format" name="format" class="form-control">
                <option value="bo3">BO3 (première à 2 maps)</option>
                <option value="bo5">BO5 (première à 3 maps)</option>
                <option value="fixed">Classique (deux maps)</option>
            </select>
        </div>
        <div style="flex:1; min-width:280px;">
            <label class="admin-form-label" for="series-maps">Maps de la série (une par ligne)</label>
            <textarea id="series-maps" name="maps" class="form-control" rows="4"
                      placeholder="pl_upward_f10&#10;koth_product_final&#10;cp_steel_f12" required></textarea>
            <p style="color:#777; font-size:12px; margin:6px 0 0;">
                Mode déduit automatiquement : pl_ et A/D (steel…) en double attaque, 5cp/KOTH en log unique. Pour forcer : « cp_steel_f12 double ».
            </p>
        </div>
    </div>
    <div class="admin-form-row" style="display:flex; justify-content:flex-end;">
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-plus"></i> Créer la série</button>
    </div>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-layer-group"></i> Séries ({{ count($seriesList) }})</h3>

@if (empty($seriesList))
    <p style="color:#aaa; font-style:italic;">Aucune série pour le moment. Créez-en une ci-dessus.</p>
@else
    <div class="admin-table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Série</th>
                    <th>Format</th>
                    <th>Statut</th>
                    <th>Créée le</th>
                    <th>Mise à jour</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($seriesList as $entry)
                    <tr>
                        <td><a href="/admin/series/{{ $entry['token'] }}">{{ e($entry['title']) }}</a></td>
                        <td>{{ strtoupper($entry['format']) }}</td>
                        <td>
                            @if ($entry['status'] === 'live')
                                <span style="color:#4cc46a; font-weight:600;">EN DIRECT</span>
                            @elseif ($entry['status'] === 'finished')
                                <span style="color:#888;">Terminée</span>
                            @else
                                <span style="color:#d9a544;">À venir</span>
                            @endif
                        </td>
                        <td>{{ date('d/m/Y H:i', (int) $entry['created_at']) }}</td>
                        <td>{{ date('d/m/Y H:i', (int) $entry['updated_at']) }}</td>
                        <td><a class="admin-link-btn" href="/admin/series/{{ $entry['token'] }}">Ouvrir</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@push('styles')
<link rel="stylesheet" href="{{ hlfr_asset('/_css/admin_overlay.css') }}">
@endpush

@push('scripts')
<script src="{{ hlfr_asset('/_js/admin_overlay.js') }}" defer></script>
@endpush
@endsection
