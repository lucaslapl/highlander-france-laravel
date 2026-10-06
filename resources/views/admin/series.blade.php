@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
<div class="admin-header" style="--accent:#4cc46a;">
    <h2><i class="fa-solid fa-trophy"></i> Séries de matchs (playoffs)</h2>
    <p>Suivi automatique du score des séries via logs.tf : lancez le suivi, et chaque log uploadé par le serveur de match met à jour les maps gagnées — sans aucun alt-tab pendant le cast. Les ajustements manuels (contestation, log manquant) restent possibles dans le détail de chaque série.</p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-plus"></i> Créer une série</h3>
<form method="POST" action="/admin/series/create" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div class="admin-form-row">
        <label class="admin-form-label" for="series-title">Titre de la série</label>
        <input type="text" id="series-title" name="title" class="form-control"
               placeholder="Ex : Demi-finale playoffs HLFR — Les Baguettes vs Escouade 6" required>
    </div>

    <div class="admin-form-row" style="display:flex; gap:24px; flex-wrap:wrap;">
        @foreach (['red' => 'Rouge', 'blue' => 'Bleue'] as $team => $label)
            <div style="flex:1; min-width:300px; border:1px solid #333; border-radius:8px; padding:14px;">
                <h4 style="margin:0 0 10px; color: {{ $team === 'red' ? '#e06c5a' : '#5885a2' }};">Équipe {{ $label }}</h4>
                <label class="admin-form-label" for="series-{{ $team }}-name">Nom de l'équipe</label>
                <input type="text" id="series-{{ $team }}-name" name="{{ $team }}_name" class="form-control"
                       placeholder="Ex : Les Baguettes" maxlength="64" required>
                <label class="admin-form-label" style="margin-top:10px;" for="series-{{ $team }}-players">Roster (SteamIDs, un par ligne)</label>
                <textarea id="series-{{ $team }}-players" name="{{ $team }}_players" class="form-control" rows="5"
                          placeholder="76561198012345678&#10;STEAM_1:0:12345&#10;[U:1:24680]" required></textarea>
                <p style="color:#777; font-size:12px; margin:6px 0 0;">
                    Formats acceptés : SteamID64, STEAM_1:X:Y ou [U:1:N]. Les mercs sont tolérés (3 max par équipe).
                </p>
            </div>
        @endforeach
    </div>

    <div class="admin-form-row" style="display:flex; gap:24px; flex-wrap:wrap;">
        <div style="min-width:180px;">
            <label class="admin-form-label" for="series-format">Format de la série</label>
            <select id="series-format" name="format" class="form-control">
                <option value="bo3">BO3 (première à 2 maps)</option>
                <option value="bo5">BO5 (première à 3 maps)</option>
                <option value="fixed">Maps fixes (toutes jouées)</option>
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
@endsection
