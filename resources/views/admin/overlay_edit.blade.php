@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@php
    $style = is_array($overlay['style'] ?? null) ? $overlay['style'] : [];
    $panel = (bool) ($style['panel'] ?? true);
    $opacity = (int) ($style['opacity'] ?? 70);
    $blur = (int) ($style['blur'] ?? 6);
@endphp

@include('admin.partials.panel_back_link')

<div class="admin-header" style="--accent:#9b6dff;">
    <h2><i class="fa-solid fa-tv"></i> Overlay — logs.tf #{{ (int) $overlay['log_id'] }}</h2>
    <p>{{ e($overlay['title']) }} · {{ e($overlay['map']) }} · score {{ (int) $overlay['teams']['red']['score'] }} - {{ (int) $overlay['teams']['blue']['score'] }}</p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-pen-to-square"></i> Personnalisation</h3>
<form method="POST" action="/admin/overlay/{{ $overlay['token'] }}/update" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div class="admin-form-row" style="display:flex; gap:24px; flex-wrap:wrap;">
        @foreach (['red' => 'Rouge', 'blue' => 'Bleue'] as $team => $label)
            <div style="flex:1; min-width:300px; border:1px solid #333; border-radius:8px; padding:14px;">
                <h4 style="margin:0 0 10px; color: {{ $team === 'red' ? '#e06c5a' : '#5885a2' }};">Équipe {{ $label }} — {{ (int) $overlay['teams'][$team]['score'] }} pts</h4>
                <label class="admin-form-label">Nom affiché</label>
                <input type="text" name="{{ $team }}_name" class="form-control"
                       value="{{ e($overlay['teams'][$team]['name']) }}" maxlength="64" required>
                <label class="admin-form-label" style="margin-top:10px;">Avatar par URL externe (optionnel)</label>
                <input type="url" name="{{ $team }}_avatar_url" class="form-control"
                       value="{{ e($overlay['teams'][$team]['avatar_url'] ?? '') }}"
                       placeholder="https://…/logo.png">
            </div>
        @endforeach
    </div>

    <div class="admin-form-row" style="border:1px solid #333; border-radius:8px; padding:14px;">
        <h4 style="margin:0 0 10px;">Style / transparence</h4>
        <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-end;">
            <div>
                <label class="admin-form-label">Panneaux de fond</label>
                <label style="display:flex; gap:6px; align-items:center; font-size:14px;">
                    <input type="checkbox" name="panel" value="1" {{ $panel ? 'checked' : '' }}>
                    Afficher les panneaux translucides
                </label>
            </div>
            <div>
                <label class="admin-form-label" for="overlay-opacity">Opacité (0-100 %)</label>
                <input type="number" id="overlay-opacity" name="opacity" class="form-control"
                       value="{{ $opacity }}" min="0" max="100" style="width:110px;">
            </div>
            <div>
                <label class="admin-form-label" for="overlay-blur">Flou d'arrière-plan (0-20 px)</label>
                <input type="number" id="overlay-blur" name="blur" class="form-control"
                       value="{{ $blur }}" min="0" max="20" style="width:110px;">
            </div>
        </div>
    </div>

    <div>
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
    </div>
</form>

<div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap; margin-top:24px;">
    @foreach (['red' => 'Rouge', 'blue' => 'Bleue'] as $team => $label)
        <div style="border:1px solid #333; border-radius:8px; padding:14px; flex:1; min-width:300px;">
            <h4 style="margin:0 0 10px;">Avatar équipe {{ $label }} (upload)</h4>
            @if ($has_avatar[$team])
                <img src="/overlay/{{ $overlay['token'] }}/avatar/{{ $team }}" alt="Avatar {{ $label }}"
                     style="max-width:96px; max-height:96px; display:block; margin-bottom:10px;">
                <form method="POST" action="/admin/overlay/{{ $overlay['token'] }}/avatar/delete" style="margin-bottom:8px;">
                    @csrf
                    <input type="hidden" name="team" value="{{ $team }}">
                    <button type="submit" class="admin-btn admin-btn--danger">Supprimer l'avatar</button>
                </form>
            @endif
            <form method="POST" action="/admin/overlay/{{ $overlay['token'] }}/avatar" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="team" value="{{ $team }}">
                <input type="file" name="avatar" accept=".jpeg,.jpg,.png,.webp" required style="font-size:13px;">
                <button type="submit" class="admin-btn" style="margin-top:8px;">Envoyer</button>
            </form>
            <p style="color:#777; font-size:12px; margin:8px 0 0;">jpeg, png ou webp — 2 Mo max. L'URL externe, si renseignée, prime sur l'upload.</p>
        </div>
    @endforeach
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
        Dans OBS : Sources → + → Navigateur web → collez l'URL, largeur 1920, hauteur 1080. Cochez bien « Rafraîchir le navigateur lorsque la scène devient active » si vous le souhaitez ; l'overlay se met à jour tout seul dès que vous modifiez les stats ici.
    </p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-rotate"></i> Actions</h3>
<div style="display:flex; gap:12px; flex-wrap:wrap;">
    <form method="POST" action="/admin/overlay/{{ $overlay['token'] }}/refresh">
        @csrf
        <button type="submit" class="admin-btn">
            <i class="fa-solid fa-arrows-rotate"></i> Relire le log logs.tf (stats à jour)
        </button>
    </form>
    <form method="POST" action="/admin/overlay/{{ $overlay['token'] }}/swap"
          onsubmit="return confirm('Intervertir les équipes Rouge et Bleue (noms et avatars) ?');">
        @csrf
        <button type="submit" class="admin-btn">
            <i class="fa-solid fa-right-left"></i> Intervertir Rouge / Bleue
        </button>
    </form>
    <a href="/admin/overlay" class="admin-btn"><i class="fa-solid fa-arrow-left"></i> Retour à la liste</a>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-tv"></i> Aperçu</h3>
<div class="overlay-preview-wrap">
    <iframe src="{{ $overlay_url }}" class="overlay-preview" title="Aperçu de l'overlay" scrolling="no" tabindex="-1"></iframe>
</div>

@push('styles')
<link rel="stylesheet" href="{{ hlfr_asset('/_css/admin_overlay.css') }}">
@endpush
@endsection
