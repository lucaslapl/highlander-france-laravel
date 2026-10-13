@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

<div class="admin-header" style="--accent:#9b6dff;">
    <h2><i class="fa-solid fa-calendar-days"></i> Overlay Programme (OBS)</h2>
    <p>Ecrans d'attente / de présentation pour les broadcasts OBS : horloge, décompte avant le match, équipes du jour, bandeau de bienvenue avec diaporama de messages et programme de la semaine. Créez l'overlay, réglez le contenu, puis pointez l'URL d'overlay dans OBS Studio (source navigateur web, 1920x1080, fond transparent).</p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Créer un overlay</h3>
<form method="POST" action="/admin/overlay/programme/create" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:1; min-width:280px;">
            <label class="admin-form-label" for="programme-name">Nom (repère dans la liste, ex : « Programme du dimanche ») *</label>
            <input type="text" name="name" id="programme-name" class="form-control" maxlength="96" required>
        </div>
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-plus"></i> Créer</button>
    </div>
    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        L'overlay démarre avec un décompte d'une heure, le bandeau de bienvenue classique et un message de diaporama
        par défaut — tout est modifiable dans l'éditeur. Les équipes peuvent être piochées dans un match ETF2L.
    </p>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-layer-group"></i> Overlays ({{ count($programmes) }})</h3>

@if (session('success'))
    <p style="color:#8c8;">{{ e(session('success')) }}</p>
@endif

@if (empty($programmes))
    <p style="color:#aaa; font-style:italic;">Aucun overlay pour le moment. Créez-en un ci-dessus.</p>
@else
    <div class="admin-table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Créé le</th>
                    <th>Mis à jour</th>
                    <th>URL overlay (OBS)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($programmes as $entry)
                    <tr>
                        <td>{{ e(trim((string) ($entry['name'] ?? '')) !== '' ? $entry['name'] : '(sans nom)') }}</td>
                        <td>{{ date('d/m/Y H:i', (int) ($entry['created_at'] ?? 0)) }}</td>
                        <td>{{ date('d/m/Y H:i', (int) ($entry['updated_at'] ?? 0)) }}</td>
                        <td style="max-width:340px;">
                            <input type="text" class="form-control overlay-url-input" readonly
                                   value="{{ url('/programme-overlay/'.$entry['token']) }}"
                                   onclick="this.select();">
                        </td>
                        <td style="white-space:nowrap;">
                            <a href="/admin/overlay/programme/{{ $entry['token'] }}" class="admin-btn">Modifier</a>
                            <form method="POST" action="/admin/overlay/programme/{{ $entry['token'] }}/delete"
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
