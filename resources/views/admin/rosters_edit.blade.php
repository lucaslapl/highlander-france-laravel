@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

@php
    $formatLabel = ($overlay['format'] ?? '') === '6v6' ? '6v6' : 'Highlander';
@endphp

<div class="admin-header" style="--accent:#e97fff;">
    <h2><i class="fa-solid fa-users"></i> {{ e($overlay['title']) }}</h2>
    <p>
        {{ $formatLabel }} ·
        {{ count($slots) }} classes par équipe —
        affectez chaque joueur à sa classe, cochez « merc » le cas échéant.
    </p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-tv"></i> Overlay OBS</h3>
<p style="color:#aaa; font-size:13px;">
    Dans OBS Studio, ajoutez une source navigateur web (fond transparent) pointant sur l'URL ci-dessous :
    elle affiche les noms d'équipes et chaque joueur devant son portrait de classe, et se rafraîchit toute seule
    (polling toutes les 5 secondes — aucun alt-tab pendant le cast).
</p>
<p style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <code style="background:#111; border:1px solid #333; border-radius:6px; padding:8px 12px; font-size:14px;">
        {{ $overlay_url }}
    </code>
    <a class="admin-link-btn" href="/roster-overlay/{{ $overlay['token'] }}" target="_blank">Prévisualiser</a>
</p>

@php
    $displayed = ($overlay['displayed'] ?? 'a') === 'b' ? 'b' : 'a';
    $displayedName = (string) ($overlay['teams'][$displayed]['name'] ?? '');
    $hidden = $displayed === 'a' ? 'b' : 'a';
    $hiddenName = (string) ($overlay['teams'][$hidden]['name'] ?? '');
@endphp
<div style="border:2px solid #e97fff; border-radius:10px; padding:16px; margin-bottom:16px; background:rgba(233,127,255,0.08);">
    <h4 style="margin:0 0 8px;"><i class="fa-solid fa-right-left"></i> Roster affiché</h4>
    <p style="margin:0 0 12px; font-size:14px; line-height:1.5;">
        L'overlay n'affiche qu'une équipe à la fois : actuellement
        <strong>l'équipe {{ strtoupper($displayed) }}{{ $displayedName !== '' ? ' ('.$displayedName.')' : '' }}</strong>.
        Ce bouton bascule l'affichage vers l'équipe {{ strtoupper($hidden) }}{{ $hiddenName !== '' ? ' ('.$hiddenName.')' : '' }} —
        l'overlay se rafraîchit tout seul et rejoue l'animation d'apparition. La bascule se joue ici, côté réglages :
        les spectateurs ne voient jamais l'interaction, seulement le résultat.
    </p>
    <form method="POST" action="/admin/overlay/rosters/{{ $overlay['token'] }}/switch">
        @csrf
        <button type="submit" class="admin-btn admin-btn--primary">
            <i class="fa-solid fa-right-left"></i> Switcher le roster affiché
        </button>
    </form>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-user-group"></i> Équipes et classes</h3>
@include('admin.partials.etf2l_teams', ['competitions' => $competitions, 'prefix' => 'rosters'])
<p style="color:#aaa; font-size:13px;">
    Le remplissage assisté pioche les équipes dans une compétition ETF2L du format du match. Une fois l'équipe
    choisie, un menu apparaît dans chaque classe pour affecter un joueur du roster ETF2L de l'équipe (pseudo
    officiel) — tout reste modifiable à la main ensuite, et chaque joueur sans compte ETF2L (merc, pseudo
    temporaire) se saisit directement dans le champ de sa classe.
</p>

<form method="POST" action="/admin/overlay/rosters/{{ $overlay['token'] }}/update" class="admin-form-stack admin-form-stack--wide">
    @csrf
    <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:14px;">
        <div style="flex:1; min-width:280px;">
            <label class="admin-form-label" for="rosters-eyebrow">Sur-titre</label>
            <input type="text" name="eyebrow" id="rosters-eyebrow" class="form-control" maxlength="96"
                   value="{{ e($overlay['eyebrow'] ?? '') }}">
        </div>
        <div style="flex:1; min-width:200px;">
            <label class="admin-form-label" for="rosters-title">Titre *</label>
            <input type="text" name="title" id="rosters-title" class="form-control" maxlength="96"
                   value="{{ e($overlay['title'] ?? '') }}" required>
        </div>
    </div>

    <div style="display:flex; gap:16px; flex-wrap:wrap;">
        @foreach (['a' => 'A', 'b' => 'B'] as $side => $label)
            <div class="js-etf2l-team" style="border:1px solid #333; border-radius:8px; padding:14px; flex:1; min-width:320px;">
                <h4 style="margin:0 0 10px;">Équipe {{ $label }}</h4>
                <input type="hidden" name="team_{{ $side }}[etf2l_id]" class="js-team-field-etf2l-id"
                       value="{{ (int) ($overlay['teams'][$side]['etf2l_id'] ?? 0) ?: '' }}">
                <label class="admin-form-label" for="team-{{ $side }}-name">Nom équipe {{ $label }}</label>
                <input type="text" id="team-{{ $side }}-name" name="team_{{ $side }}[name]" class="form-control js-team-field-name"
                       value="{{ e($overlay['teams'][$side]['name'] ?? '') }}" maxlength="64">
                <label class="admin-form-label" style="margin-top:10px;" for="team-{{ $side }}-avatar">Avatar par URL externe (optionnel)</label>
                <input type="url" id="team-{{ $side }}-avatar" name="team_{{ $side }}[avatar]" class="form-control js-team-field-avatar"
                       value="{{ e($overlay['teams'][$side]['avatar'] ?? '') }}"
                       placeholder="https://…/logo.png">

                <h4 style="margin:14px 0 8px;">Classes</h4>
                <div class="js-roster-slots">
                    @foreach ($slots as $i => $slot)
                        @php
                            $playerName = (string) ($overlay['teams'][$side]['players'][$i]['name'] ?? '');
                            $isMerc = (bool) ($overlay['teams'][$side]['players'][$i]['merc'] ?? false);
                        @endphp
                        {{-- flex-wrap : le menu de joueur ETF2L inséré par
                             admin_rosters.js dans le slot passe sous la
                             ligne sans écraser les champs. --}}
                        <div class="js-roster-slot" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:6px;">
                            <img src="/_img/classes_portraits/{{ $slot['class'] }}.png" alt="{{ $slot['label'] }}"
                                 style="width:28px; height:28px; object-fit:contain; flex:none;"
                                 title="{{ $slot['label'] }}">
                            <span style="flex:0 0 86px; font-size:13px; color:#bbb;">{{ $slot['label'] }}</span>
                            <input type="text" name="players_{{ $side }}[{{ $i }}][name]"
                                   class="form-control js-roster-slot-name" value="{{ e($playerName) }}"
                                   maxlength="40" placeholder="Pseudo du joueur">
                            <label style="flex:none; font-size:13px; color:#bbb; display:flex; gap:5px; align-items:center;">
                                <input type="hidden" name="players_{{ $side }}[{{ $i }}][merc]" value="0">
                                <input type="checkbox" name="players_{{ $side }}[{{ $i }}][merc]" value="1"
                                       @checked($isMerc)> merc
                            </label>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    <button type="submit" class="admin-btn admin-btn--primary" style="margin-top:10px;">
        <i class="fa-solid fa-floppy-disk"></i> Enregistrer l'overlay
    </button>
</form>

<p style="margin-top:24px;">
    <a class="admin-link-btn" href="/admin/overlay/rosters"><i class="fa-solid fa-arrow-left"></i> Retour aux rosters</a>
</p>

@push('styles')
<link rel="stylesheet" href="{{ hlfr_asset('/_css/admin_overlay.css') }}">
@endpush

{{-- Ordre important : admin_etf2l_teams.js remplit le champ de liaison ETF2L
     au changement d'équipe, admin_rosters.js lit ensuite ce champ pour
     charger le roster et proposer les joueurs de chaque classe. --}}
@push('scripts')
<script src="{{ hlfr_asset('/_js/admin_etf2l_teams.js') }}" defer></script>
<script src="{{ hlfr_asset('/_js/admin_rosters.js') }}" defer></script>
@endpush
@endsection
