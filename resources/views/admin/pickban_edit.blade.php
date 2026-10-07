@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

@php
    $format = (string) ($overlay['format'] ?? '3_3');
@endphp

<div class="admin-header" style="--accent:#9b6dff;">
    <h2>
        <i class="fa-solid fa-map"></i>
        Overlay Pick/Ban — {{ e($overlay['title'] !== '' ? $overlay['title'] : $overlay['token']) }}
    </h2>
    <p>Modifiez le format, les titres, les équipes et les six cartes (map, capture, action PICK/BAN, équipe). Le format est strict : le compte de cartes PICK et BAN doit correspondre exactement. L'overlay OBS se rafraîchit tout seul à chaque enregistrement.</p>
</div>

<div style="border:2px solid #9b6dff; border-radius:10px; padding:16px; margin-bottom:24px; background:rgba(155,109,255,0.08);">
    <h4 style="margin:0 0 8px;"><i class="fa-solid fa-link"></i> URL de l'overlay (OBS)</h4>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="text" class="form-control overlay-url-input" readonly value="{{ $overlay_url }}"
               onclick="this.select();" style="flex:1; min-width:320px;">
        <button type="button" class="admin-btn" onclick="navigator.clipboard.writeText('{{ $overlay_url }}');">
            <i class="fa-solid fa-copy"></i> Copier
        </button>
        <a href="{{ $overlay_url }}" target="_blank" rel="noopener" class="admin-btn">Ouvrir</a>
    </div>
    <p style="color:#777; font-size:13px; margin:10px 0 0;">
        Dans OBS : Sources → + → Navigateur web → collez l'URL, largeur 1920, hauteur 1080. L'overlay se rafraîchit
        automatiquement dès que vous enregistrez ici.
    </p>
</div>

@if (session('success'))
    <p style="color:#8c8;">{{ e(session('success')) }}</p>
@endif
@if (session('error'))
    <p style="color:#ff8080;">{{ e(session('error')) }}</p>
@endif

<form method="POST" action="/admin/overlay/pickban/{{ $overlay['token'] }}/update" class="admin-form-stack admin-form-stack--wide" id="pickban-editor">
    @csrf

    <h3 class="admin-section-title"><i class="fa-solid fa-heading"></i> Format et titres</h3>
    <div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap;">
        <div>
            <label class="admin-form-label" for="editor-format">Format *</label>
            <select name="format" id="editor-format" class="form-control">
                @foreach ($formats as $key => $spec)
                    <option value="{{ $key }}" @selected($format === $key)>
                        {{ $spec['picks'] }} picks + {{ $spec['bans'] }} bans
                    </option>
                @endforeach
            </select>
        </div>
        <div style="flex:1; min-width:280px;">
            <label class="admin-form-label" for="editor-eyebrow">Sur-titre (petit texte au-dessus du titre)</label>
            <input type="text" name="eyebrow" id="editor-eyebrow" class="form-control" maxlength="96"
                   value="{{ e((string) ($overlay['eyebrow'] ?? '')) }}"
                   placeholder="ETF2L Highlander Saison 36 - Division 1 - Finale">
        </div>
        <div style="flex:1; min-width:200px;">
            <label class="admin-form-label" for="editor-title">Titre *</label>
            <input type="text" name="title" id="editor-title" class="form-control" maxlength="96" required
                   value="{{ e((string) ($overlay['title'] ?? '')) }}" placeholder="Picks & Bans">
        </div>
    </div>
    <p style="color:#777; font-size:13px; margin:6px 0 0;">
        Changer de format répartit à nouveau les actions PICK / BAN par défaut sur les six cartes (le contenu des cartes est conservé).
    </p>

    <h3 class="admin-section-title"><i class="fa-solid fa-users"></i> Équipes</h3>

    @if (! empty($competitions))
        <div style="border:1px solid #333; border-radius:8px; padding:14px; margin-bottom:14px;">
            <h4 style="margin:0 0 8px; color:#bbb;"><i class="fa-solid fa-wand-magic-sparkles"></i> Remplissage assisté ETF2L</h4>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <label class="admin-form-label" for="pickban-teams-competition">Compétition ETF2L</label>
                <select id="pickban-teams-competition" class="form-control" style="max-width:420px;">
                    @foreach ($competitions as $competition)
                        <option value="{{ (int) $competition['id'] }}">{{ e($competition['name']) }}</option>
                    @endforeach
                </select>
                <button type="button" class="admin-btn" id="pickban-load-teams"><i class="fa-solid fa-download"></i> Charger les équipes</button>
                <span id="pickban-teams-status" style="color:#777; font-size:13px;"></span>
            </div>
        </div>
    @endif

    <div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap;">
        @foreach (['a' => 'A', 'b' => 'B'] as $side => $label)
            <div style="flex:1; min-width:300px; border:1px solid #333; border-radius:8px; padding:14px;">
                <h4 style="margin:0 0 10px; color:#bbb;">Équipe {{ $label }}</h4>
                <div class="js-etf2l-team" data-side="{{ $side }}" style="margin-bottom:10px;"></div>
                <label class="admin-form-label" for="team-{{ $side }}-name">Nom affiché</label>
                <input type="text" name="team_{{ $side }}[name]" id="team-{{ $side }}-name" class="form-control"
                       maxlength="64" value="{{ e((string) ($overlay['teams'][$side]['name'] ?? '')) }}"
                       placeholder="Ex : DD14">
                <label class="admin-form-label" style="margin-top:10px;" for="team-{{ $side }}-avatar">Avatar (URL)</label>
                <input type="text" name="team_{{ $side }}[avatar]" id="team-{{ $side }}-avatar" class="form-control"
                       maxlength="500" value="{{ e((string) ($overlay['teams'][$side]['avatar'] ?? '')) }}"
                       placeholder="https://…/logo.png">
            </div>
        @endforeach
    </div>

    <h3 class="admin-section-title"><i class="fa-solid fa-map-location-dot"></i> Cartes (ordre d'affichage gauche → droite)</h3>

    <div id="pickban-cards">
        @foreach ($overlay['cards'] ?? [] as $index => $card)
            <div class="pickban-card-edit" style="border:1px solid #333; border-radius:8px; padding:12px; margin-bottom:10px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
                <div style="width:44px; text-align:center; font-weight:800; color:#bbb;">{{ $index + 1 }}</div>
                <div>
                    <label class="admin-form-label">Map (liste des maps officielles)</label>
                    <select class="form-control js-pickban-map-select" data-index="{{ $index }}">
                        <option value="">— Choisir une map —</option>
                        @foreach ($maps as $map)
                            <option value="{{ e($map['name']) }}" data-label="{{ e($map['label']) }}" data-image="{{ e($map['image']) }}">{{ e($map['label']) }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="flex:1; min-width:180px;">
                    <label class="admin-form-label">Nom affiché</label>
                    <input type="text" name="cards[{{ $index }}][map]" class="form-control js-pickban-map-name" maxlength="64"
                           value="{{ e((string) ($card['map'] ?? '')) }}" placeholder="pl_upward">
                </div>
                <div style="flex:1; min-width:220px;">
                    <label class="admin-form-label">Capture (URL)</label>
                    <input type="text" name="cards[{{ $index }}][image]" class="form-control js-pickban-map-image" maxlength="500"
                           value="{{ e((string) ($card['image'] ?? '')) }}" placeholder="https://…/upward.jpg">
                </div>
                <div>
                    <label class="admin-form-label">Action</label>
                    <select name="cards[{{ $index }}][action]" class="form-control js-pickban-action">
                        <option value="pick" @selected(($card['action'] ?? '') === 'pick')>PICK</option>
                        <option value="ban" @selected(($card['action'] ?? '') !== 'pick')>BAN</option>
                    </select>
                </div>
                <div>
                    <label class="admin-form-label">Équipe</label>
                    <select name="cards[{{ $index }}][team]" class="form-control js-pickban-team">
                        <option value="" @selected(($card['team'] ?? '') === '')>— Aucune —</option>
                        <option value="a" @selected(($card['team'] ?? '') === 'a')>A</option>
                        <option value="b" @selected(($card['team'] ?? '') === 'b')>B</option>
                    </select>
                </div>
            </div>
        @endforeach
    </div>

    <p id="pickban-count-status" style="color:#777; font-size:13px; margin:6px 0 0;"></p>

    <div style="margin-top:18px;">
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
    </div>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-rotate"></i> Actions</h3>
<div style="display:flex; gap:12px; flex-wrap:wrap;">
    <a href="/admin/overlay/pickban" class="admin-btn"><i class="fa-solid fa-arrow-left"></i> Retour à la liste</a>
    <form method="POST" action="/admin/overlay/pickban/{{ $overlay['token'] }}/delete"
          onsubmit="return confirm('Supprimer cet overlay ?');">
        @csrf
        <button type="submit" class="admin-btn admin-btn--danger"><i class="fa-solid fa-trash"></i> Supprimer</button>
    </form>
</div>

<script type="application/json" id="pickban-config">{!! json_encode(['defaultActions' => $defaultActions, 'formats' => $formats], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
<script src="{{ hlfr_asset('/_js/admin_pickban.js') }}" defer></script>
@endsection
