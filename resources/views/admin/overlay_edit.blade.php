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
    <h4 style="margin:0 0 8px;"><i class="fa-solid fa-file-import"></i> Charger un nouveau log</h4>
    <p style="margin:0 0 12px; font-size:14px; line-height:1.5;">
        L'URL OBS de cet overlay ne change jamais : chargez ici le log de chaque map ou match, et le visuel sous OBS se
        met à jour tout seul (les équipes suivent les couleurs rouge / bleu du log). Noms et avatars d'équipes ETF2L
        sont identifiés automatiquement à partir des joueurs du log ; sans équipe identifiable, RED / BLU s'affichent.
    </p>
    <form method="POST" action="/admin/overlay/{{ $overlay['token'] }}/load" class="admin-form-row"
          style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        @csrf
        <div style="flex:1; min-width:320px;">
            <label class="admin-form-label" for="overlay-load-log-input">Lien ou ID du log logs.tf</label>
            <input type="text" id="overlay-load-log-input" name="log" class="form-control"
                   placeholder="Ex : https://logs.tf/4059225 ou 4059225" required>
        </div>
        <button type="submit" class="admin-btn admin-btn--primary">
            <i class="fa-solid fa-bolt"></i> Charger le log
        </button>
    </form>
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
        Dans OBS : Sources → + → Navigateur web → collez l'URL, largeur 1920, hauteur 1080. À ne faire qu'une seule fois :
        l'overlay se met à jour tout seul dès qu'un log est chargé ou que vous modifiez les stats ici, jamais besoin de
        toucher à OBS en cours de stream.
    </p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-rotate"></i> Actions</h3>
<div style="display:flex; gap:12px; flex-wrap:wrap;">
    <form method="POST" action="/admin/overlay/{{ $overlay['token'] }}/refresh">
        @csrf
        <button type="submit" class="admin-btn">
            <i class="fa-solid fa-arrows-rotate"></i> Relire le log logs.tf courant (stats à jour)
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
