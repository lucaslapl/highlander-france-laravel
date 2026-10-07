@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

<div class="admin-header" style="--accent:#9b6dff;">
    <h2><i class="fa-solid fa-map"></i> Overlay Pick/Ban (OBS)</h2>
    <p>Overlays de pick / ban de maps pour les broadcasts OBS : créez l'overlay, choisissez le format (3 picks + 3 bans ou 5 picks + 1 ban), remplissez les équipes et les cartes, puis pointez l'URL d'overlay dans OBS Studio (source navigateur web, 1920x1080, fond transparent).</p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Créer un overlay</h3>
<form method="POST" action="/admin/overlay/pickban/create" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label class="admin-form-label" for="pickban-format">Format *</label>
            <select name="format" id="pickban-format" class="form-control">
                <option value="3_3">3 picks + 3 bans</option>
                <option value="5_1">5 picks + 1 ban</option>
            </select>
        </div>
        <div style="flex:1; min-width:280px;">
            <label class="admin-form-label" for="pickban-eyebrow">Sur-titre (ex : « ETF2L Highlander Saison 36 - Division 1 - Finale »)</label>
            <input type="text" name="eyebrow" id="pickban-eyebrow" class="form-control" maxlength="96">
        </div>
        <div style="flex:1; min-width:200px;">
            <label class="admin-form-label" for="pickban-title">Titre (ex : « Picks &amp; Bans ») *</label>
            <input type="text" name="title" id="pickban-title" class="form-control" maxlength="96" required>
        </div>
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-plus"></i> Créer</button>
    </div>
    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        L'overlay démarre avec six cartes vides pré-remplies d'actions PICK / BAN selon le format. Les équipes (noms + avatars)
        peuvent ensuite être piochées dans une compétition ETF2L, et les maps dans la liste des maps officielles du site.
    </p>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-layer-group"></i> Overlays ({{ count($pickbans) }})</h3>

@if (session('success'))
    <p style="color:#8c8;">{{ e(session('success')) }}</p>
@endif

@if (empty($pickbans))
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
                @foreach ($pickbans as $entry)
                    <tr>
                        <td>{{ ($entry['format'] ?? '') === '5_1' ? '5 picks + 1 ban' : '3 picks + 3 bans' }}</td>
                        <td>{{ e(trim((string) ($entry['title'] ?? '')) !== '' ? $entry['title'] : '(sans titre)') }}</td>
                        <td>{{ date('d/m/Y H:i', (int) ($entry['created_at'] ?? 0)) }}</td>
                        <td>{{ date('d/m/Y H:i', (int) ($entry['updated_at'] ?? 0)) }}</td>
                        <td style="max-width:340px;">
                            <input type="text" class="form-control overlay-url-input" readonly
                                   value="{{ url('/pickban-overlay/'.$entry['token']) }}"
                                   onclick="this.select();">
                        </td>
                        <td style="white-space:nowrap;">
                            <a href="/admin/overlay/pickban/{{ $entry['token'] }}" class="admin-btn">Modifier</a>
                            <form method="POST" action="/admin/overlay/pickban/{{ $entry['token'] }}/delete"
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
