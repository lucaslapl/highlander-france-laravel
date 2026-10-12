@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

<div class="admin-header" style="--accent:#9b6dff;">
    <h2><i class="fa-solid fa-tv"></i> Overlay Logs (OBS)</h2>
    <p>Overlay de stats logs.tf pour les broadcasts OBS : une URL unique pour tous les logs. Pointez-la dans OBS Studio (source navigateur web, 1920x1080, fond transparent) une seule fois, puis chargez chaque log ici — le visuel se met à jour tout seul, l'URL ne change jamais.</p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-link"></i> URL de l'overlay (OBS)</h3>
<div class="admin-form-stack admin-form-stack--wide">
    <div class="admin-form-row" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="text" class="form-control overlay-url-input" readonly value="{{ $overlay_url }}" onclick="this.select();" style="flex:1; min-width:320px;">
        <button type="button" class="admin-btn" onclick="navigator.clipboard.writeText('{{ $overlay_url }}');">
            <i class="fa-solid fa-copy"></i> Copier
        </button>
        <a href="{{ $overlay_url }}" target="_blank" rel="noopener" class="admin-btn">Ouvrir</a>
    </div>
    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        Dans OBS : Sources → + → Navigateur web → collez l'URL, largeur 1920, hauteur 1080. À ne faire qu'une seule fois : l'overlay se met à
        jour tout seul dès qu'un log est chargé, jamais besoin de toucher à OBS en cours de stream.
    </p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-bolt"></i> Charger un log</h3>
<form method="POST" action="/admin/overlay/load" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div class="admin-form-row" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <div style="flex:1; min-width:320px;">
            <label class="admin-form-label" for="overlay-log-input">Lien ou ID du log logs.tf</label>
            <input type="text" id="overlay-log-input" name="log" class="form-control"
                   placeholder="Ex : https://logs.tf/4059225 ou 4059225" required>
        </div>
        <button type="submit" class="admin-btn admin-btn--primary">
            <i class="fa-solid fa-bolt"></i> Charger le log
        </button>
    </div>
    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        Les scores, joueurs, classes et stats medics sont récupérés depuis l'API logs.tf. Noms et avatars d'équipes ETF2L
        sont identifiés automatiquement à partir des joueurs du log ; sans équipe identifiable, RED / BLU s'affichent.
    </p>
</form>

@if ($current !== null)
    <h3 class="admin-section-title"><i class="fa-solid fa-tv"></i> Log courant</h3>
    <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:8px;">
        <span>
            <a href="https://logs.tf/{{ (int) $current['log_id'] }}" target="_blank" rel="noopener">#{{ (int) $current['log_id'] }}</a>
            · {{ e($current['title'] !== '' ? $current['title'] : 'logs.tf #'.$current['log_id']) }}
            · {{ e($current['map']) }}
            · score {{ (int) $current['teams']['red']['score'] }} - {{ (int) $current['teams']['blue']['score'] }}
        </span>
        <form method="POST" action="/admin/overlay/refresh">
            @csrf
            <button type="submit" class="admin-btn">
                <i class="fa-solid fa-arrows-rotate"></i> Relire le log courant (stats à jour)
            </button>
        </form>
    </div>
    <div class="overlay-preview-wrap">
        <iframe src="{{ $overlay_url }}" class="overlay-preview" title="Aperçu de l'overlay" scrolling="no" tabindex="-1"></iframe>
    </div>
@else
    <p style="color:#aaa; font-style:italic;">Aucun log chargé pour l'instant : l'overlay OBS reste transparent jusqu'au premier chargement.</p>
@endif

<h3 class="admin-section-title"><i class="fa-solid fa-layer-group"></i> Logs déjà générés ({{ count($logs) }})</h3>

@if (empty($logs))
    <p style="color:#aaa; font-style:italic;">Aucun log pour l'instant. Chargez-en un ci-dessus.</p>
@else
    <p style="color:#777; font-size:13px; margin:0 0 10px;">
        Chaque log chargé rejoint cette liste (anciens overlays à token individuel compris) : le bouton « Charger ce log »
        recharge son log dans l'overlay unique, sans toucher à l'URL OBS et sans rappel logs.tf.
    </p>
    <div class="admin-table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Log</th>
                    <th>Titre</th>
                    <th>Map</th>
                    <th>Date du match</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($logs as $entry)
                    <tr>
                        <td><a href="https://logs.tf/{{ (int) $entry['log_id'] }}" target="_blank" rel="noopener">#{{ (int) $entry['log_id'] }}</a></td>
                        <td>{{ e($entry['title'] !== '' ? $entry['title'] : 'logs.tf #'.$entry['log_id']) }}</td>
                        <td>{{ e($entry['map']) }}</td>
                        <td>{{ (int) $entry['date'] > 0 ? date('d/m/Y H:i', (int) $entry['date']) : '—' }}</td>
                        <td style="white-space:nowrap;">
                            <form method="POST" action="/admin/overlay/{{ $entry['token'] }}/load" style="display:inline;">
                                @csrf
                                <button type="submit" class="admin-btn admin-btn--primary">
                                    <i class="fa-solid fa-bolt"></i> Charger ce log
                                </button>
                            </form>
                            <form method="POST" action="/admin/overlay/{{ $entry['token'] }}/delete"
                                  style="display:inline;"
                                  onsubmit="return confirm('Supprimer ce log de l\'historique ?');">
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
