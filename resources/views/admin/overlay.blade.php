@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

<div class="admin-header" style="--accent:#9b6dff;">
    <h2><i class="fa-solid fa-tv"></i> Overlay Logs (OBS)</h2>
    <p>Génération d'overlays de stats logs.tf pour les broadcasts OBS : créez un overlay, pointez son URL dans OBS Studio (source navigateur web, 1920x1080, fond transparent) une seule fois, puis chargez chaque nouveau log depuis la page de l'overlay — le visuel se met à jour tout seul, l'URL ne change jamais.</p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Créer un overlay</h3>
<form method="POST" action="/admin/overlay/generate" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div class="admin-form-row" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <div style="flex:1; min-width:320px;">
            <label class="admin-form-label" for="overlay-log-input">Lien ou ID du log logs.tf</label>
            <input type="text" id="overlay-log-input" name="log" class="form-control"
                   placeholder="Ex : https://logs.tf/4059225 ou 4059225" required>
        </div>
        <button type="submit" class="admin-btn admin-btn--primary">
            <i class="fa-solid fa-bolt"></i> Créer l'overlay
        </button>
    </div>
    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        Les scores, joueurs, classes et stats medics sont récupérés depuis l'API logs.tf. Noms et avatars d'équipes
        ETF2L sont identifiés automatiquement à partir des joueurs du log ; sans équipe identifiable, RED / BLU
        s'affichent. Les logs suivants se chargent ensuite depuis la page de l'overlay, sans toucher à OBS.
    </p>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-layer-group"></i> Overlays générés ({{ count($overlays) }})</h3>

@if (empty($overlays))
    <p style="color:#aaa; font-style:italic;">Aucun overlay pour le moment. Créez-en un ci-dessus.</p>
@else
    <div class="admin-table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Log</th>
                    <th>Titre</th>
                    <th>Map</th>
                    <th>Date du match</th>
                    <th>URL overlay (OBS)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($overlays as $entry)
                    <tr>
                        <td><a href="https://logs.tf/{{ (int) $entry['log_id'] }}" target="_blank" rel="noopener">#{{ (int) $entry['log_id'] }}</a></td>
                        <td>{{ e($entry['title'] !== '' ? $entry['title'] : 'logs.tf #'.$entry['log_id']) }}</td>
                        <td>{{ e($entry['map']) }}</td>
                        <td>{{ (int) $entry['date'] > 0 ? date('d/m/Y H:i', (int) $entry['date']) : '—' }}</td>
                        <td style="max-width:340px;">
                            <input type="text" class="form-control overlay-url-input" readonly
                                   value="{{ url('/overlay/'.$entry['token']) }}"
                                   onclick="this.select();">
                        </td>
                        <td style="white-space:nowrap;">
                            <a href="/admin/overlay/{{ $entry['token'] }}" class="admin-btn">Charger un log / modifier</a>
                            <form method="POST" action="/admin/overlay/{{ $entry['token'] }}/delete"
                                  style="display:inline;"
                                  onsubmit="return confirm('Supprimer cet overlay ?');">
                                @csrf
                                <button type="submit" class="admin-btn admin-btn--danger">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@push('styles')
<link rel="stylesheet" href="{{ hlfr_asset('/_css/admin_overlay.css') }}">
@endpush
@endsection
