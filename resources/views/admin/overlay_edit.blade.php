@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

<div class="admin-header" style="--accent:#9b6dff;">
    <h2><i class="fa-solid fa-tv"></i> Overlay Logs — logs.tf #{{ (int) $overlay['log_id'] }}</h2>
    <p>{{ e($overlay['title']) }} · {{ e($overlay['map']) }} · score {{ (int) $overlay['teams']['red']['score'] }} - {{ (int) $overlay['teams']['blue']['score'] }}</p>
</div>

<div style="border:2px solid #9b6dff; border-radius:10px; padding:16px; margin-bottom:24px; background:rgba(155,109,255,0.08);">
    <h4 style="margin:0 0 8px;"><i class="fa-solid fa-right-left"></i> Les équipes sont-elles du bon côté ?</h4>
    <p style="margin:0 0 12px; font-size:14px; line-height:1.5;">
        logs.tf classe les deux équipes en « rouge » et « bleu » de façon arbitraire, sans lien avec votre layout de
        broadcast : l'équipe A affichée à gauche de l'overlay n'est donc pas forcément celle que vous voulez à gauche.
        Ce bouton échange <strong>uniquement les noms et les avatars</strong> des équipes A et B — les scores, les stats
        des joueurs et des medics ne bougent pas, et l'overlay se rafraîchit immédiatement dans OBS.
    </p>
    <form method="POST" action="/admin/overlay/{{ $overlay['token'] }}/swap"
          onsubmit="return confirm('Intervertir les équipes A et B (noms et avatars) ?');">
        @csrf
        <button type="submit" class="admin-btn admin-btn--primary">
            <i class="fa-solid fa-right-left"></i> Intervertir les équipes A / B
        </button>
    </form>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-pen-to-square"></i> Personnalisation</h3>
<form method="POST" action="/admin/overlay/{{ $overlay['token'] }}/update" class="admin-form-stack admin-form-stack--wide">
    @csrf
    @if (! empty($avatar_urls))
        <datalist id="overlay-avatar-urls">
            @foreach ($avatar_urls as $url)
                <option value="{{ e($url) }}"></option>
            @endforeach
        </datalist>
    @endif
    <div class="admin-form-row" style="display:flex; gap:24px; flex-wrap:wrap;">
        @foreach (['red' => 'A', 'blue' => 'B'] as $team => $label)
            <div style="flex:1; min-width:300px; border:1px solid #333; border-radius:8px; padding:14px;">
                <h4 style="margin:0 0 10px; color:#bbb;">Équipe {{ $label }} — {{ (int) $overlay['teams'][$team]['score'] }} pts</h4>
                <label class="admin-form-label">Nom affiché</label>
                <input type="text" name="{{ $team }}_name" class="form-control"
                       value="{{ e($overlay['teams'][$team]['name']) }}" maxlength="64" required>
                <label class="admin-form-label" style="margin-top:10px;">Avatar par URL externe (optionnel)</label>
                <input type="url" name="{{ $team }}_avatar_url" class="form-control"
                       value="{{ e($overlay['teams'][$team]['avatar_url'] ?? '') }}"
                       list="overlay-avatar-urls" placeholder="https://…/logo.png">
            </div>
        @endforeach
    </div>
    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        Les URL d'avatars utilisées sont mémorisées et re-proposées (saisie prédictive) lors des prochaines générations.
    </p>

    <div>
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
    </div>
</form>

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
