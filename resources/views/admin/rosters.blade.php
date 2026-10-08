@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

<div class="admin-header" style="--accent:#e97fff;">
    <h2><i class="fa-solid fa-users"></i> Overlay Rosters (OBS)</h2>
    <p>Overlays de présentation des rosters des deux équipes pour les broadcasts OBS : créez l'overlay, choisissez le format (Highlander ou 6v6), remplissez les équipes puis affectez chaque joueur à sa classe, et pointez l'URL d'overlay dans OBS Studio (source navigateur web, 1920x1080, fond transparent). L'overlay n'affiche qu'une équipe à la fois ; le bouton « Switcher le roster affiché » de la page de réglages bascule vers l'autre — les spectateurs ne voient jamais l'interaction.</p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Créer un overlay</h3>
<form method="POST" action="/admin/overlay/rosters/create" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label class="admin-form-label" for="rosters-format">Format *</label>
            <select name="format" id="rosters-format" class="form-control">
                @foreach ($formats as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div style="flex:1; min-width:280px;">
            <label class="admin-form-label" for="rosters-eyebrow">Sur-titre (ex : « ETF2L Highlander Saison 40 - Division 1 »)</label>
            <input type="text" name="eyebrow" id="rosters-eyebrow" class="form-control" maxlength="96">
        </div>
        <div style="flex:1; min-width:200px;">
            <label class="admin-form-label" for="rosters-title">Titre (ex : « Rosters ») *</label>
            <input type="text" name="title" id="rosters-title" class="form-control" maxlength="96" required>
        </div>
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-plus"></i> Créer</button>
    </div>
    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        Le format fixe la disposition de l'overlay (grille 3x3 des neuf classes en Highlander,
        six classes alignées sur toute la largeur en 6v6) et n'est plus modifiable ensuite. L'overlay
        démarre avec des classes vides : les équipes peuvent être piochées dans une compétition ETF2L
        du même format, et chaque classe reçoit son joueur depuis le roster de l'équipe — tout reste
        saisissable à la main (mercs, joueur sans compte ETF2L).
    </p>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-layer-group"></i> Overlays ({{ count($rosters) }})</h3>

@if (session('success'))
    <p style="color:#8c8;">{{ e(session('success')) }}</p>
@endif

@if (empty($rosters))
    <p style="color:#aaa; font-style:italic;">Aucun overlay pour le moment. Créez-en un ci-dessus.</p>
@else
    <div class="admin-table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Format</th>
                    <th>Titre</th>
                    <th>Créé le</th>
                    <th>Mis à jour</th>
                    <th>URL overlay (OBS)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rosters as $entry)
                    <tr>
                        <td>{{ ($entry['format'] ?? '') === '6v6' ? '6v6' : 'Highlander' }}</td>
                        <td>{{ e(trim((string) ($entry['title'] ?? '')) !== '' ? $entry['title'] : '(sans titre)') }}</td>
                        <td>{{ date('d/m/Y H:i', (int) ($entry['created_at'] ?? 0)) }}</td>
                        <td>{{ date('d/m/Y H:i', (int) ($entry['updated_at'] ?? 0)) }}</td>
                        <td style="max-width:340px;">
                            <input type="text" class="form-control overlay-url-input" readonly
                                   value="{{ url('/roster-overlay/'.$entry['token']) }}"
                                   onclick="this.select();">
                        </td>
                        <td style="white-space:nowrap;">
                            <a href="/admin/overlay/rosters/{{ $entry['token'] }}" class="admin-btn">Modifier</a>
                            <form method="POST" action="/admin/overlay/rosters/{{ $entry['token'] }}/delete"
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
@endsection
