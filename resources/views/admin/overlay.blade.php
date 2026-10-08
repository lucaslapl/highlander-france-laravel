@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

<div class="admin-header" style="--accent:#9b6dff;">
    <h2><i class="fa-solid fa-tv"></i> Overlay Logs (OBS)</h2>
    <p>Génération d'overlays de stats logs.tf pour les broadcasts OBS : entrez un lien logs.tf, personnalisez, puis pointez l'URL d'overlay dans OBS Studio (source navigateur web, 1920x1080, fond transparent).</p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Générer un overlay</h3>
<form method="POST" action="/admin/overlay/generate" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div class="admin-form-row" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <div style="flex:1; min-width:320px;">
            <label class="admin-form-label" for="overlay-log-input">Lien ou ID du log logs.tf</label>
            <input type="text" id="overlay-log-input" name="log" class="form-control"
                   placeholder="Ex : https://logs.tf/4059225 ou 4059225" required>
        </div>
        <button type="submit" class="admin-btn admin-btn--primary">
            <i class="fa-solid fa-bolt"></i> Générer l'overlay
        </button>
    </div>

    @include('admin.partials.etf2l_teams', ['competitions' => $competitions, 'prefix' => 'overlay'])

    <div class="admin-form-row" style="display:flex; gap:24px; flex-wrap:wrap;">
        @foreach (['red' => 'A', 'blue' => 'B'] as $team => $label)
            <div class="js-etf2l-team" style="flex:1; min-width:300px; border:1px solid #333; border-radius:8px; padding:14px;">
                <h4 style="margin:0 0 10px; color:#bbb;">Équipe {{ $label }}</h4>
                <input type="hidden" name="{{ $team }}_etf2l_id" class="js-team-field-etf2l-id" value="">
                <label class="admin-form-label" for="overlay-{{ $team }}-name">Nom affiché (optionnel)</label>
                <input type="text" id="overlay-{{ $team }}-name" name="{{ $team }}_name" class="form-control js-team-field-name"
                       placeholder="{{ $team === 'red' ? 'RED par défaut' : 'BLU par défaut' }}" maxlength="64">
                <label class="admin-form-label" style="margin-top:10px;" for="overlay-{{ $team }}-avatar">Avatar par URL externe (optionnel)</label>
                <input type="url" id="overlay-{{ $team }}-avatar" name="{{ $team }}_avatar_url" class="form-control js-team-field-avatar"
                       placeholder="https://…/logo.png">
            </div>
        @endforeach
    </div>
    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        Les scores, joueurs, classes et stats medics sont récupérés depuis l'API logs.tf. Noms et avatars saisis ici sont appliqués dès la création : tout est prêt avant le stream, et reste modifiable ensuite.
    </p>
    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        logs.tf classe les équipes en « rouge » et « bleu » arbitrairement : choisissez vos équipes via le remplissage
        assisté ETF2L et leurs rosters alignent automatiquement l'équipe A à gauche — le bouton d'interversion de la
        page de l'overlay reste le repli si les équipes sont saisies à la main.
    </p>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-layer-group"></i> Overlays générés ({{ count($overlays) }})</h3>

@if (empty($overlays))
    <p style="color:#aaa; font-style:italic;">Aucun overlay pour le moment. Générez-en un ci-dessus.</p>
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
                            <a href="/admin/overlay/{{ $entry['token'] }}" class="admin-btn">Modifier</a>
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

@push('scripts')
<script src="{{ hlfr_asset('/_js/admin_etf2l_teams.js') }}" defer></script>
@endpush
@endsection
