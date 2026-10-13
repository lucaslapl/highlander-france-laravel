@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

@php
    // Préparation des valeurs du formulaire : décompte (mode, cible ou
    // durée, offset d'ajustement) et réglages du diaporama. Les
    // entrées du programme viennent de old() en priorité : après un
    // échec d'enregistrement (back()->withInput()), les rangées saisies
    // — y compris celles ajoutées en JavaScript — sont repeuplées au
    // lieu d'être perdues.
    $countdown = is_array($overlay['countdown'] ?? null) ? $overlay['countdown'] : [];
    $mode = old('countdown_mode', ($countdown['mode'] ?? 'duration') === 'datetime' ? 'datetime' : 'duration');
    $targetDate = '';
    $targetTime = '';
    if (! empty($countdown['target_ts'])) {
        $target = (new \DateTimeImmutable('@'.(int) $countdown['target_ts']))->setTimezone(new \DateTimeZone('Europe/Paris'));
        $targetDate = $target->format('Y-m-d');
        $targetTime = $target->format('H:i');
    }
    $durationMin = (int) round(((int) ($countdown['duration_s'] ?? 3600)) / 60);
    $adjustS = (int) ($countdown['adjust_s'] ?? 0);
    $slideshow = is_array($overlay['slideshow'] ?? null) ? $overlay['slideshow'] : [];
    $savedSchedule = is_array($overlay['schedule'] ?? null) ? $overlay['schedule'] : [];
    $oldSchedule = old('schedule');
    $schedule = is_array($oldSchedule) ? $oldSchedule : $savedSchedule;
@endphp

<div class="admin-header" style="--accent:#9b6dff;">
    <h2>
        <i class="fa-solid fa-calendar-days"></i>
        Overlay Programme — {{ trim((string) ($overlay['name'] ?? '')) !== '' ? $overlay['name'] : $overlay['token'] }}
    </h2>
    <p>Modifiez le décompte, les équipes du jour, le bandeau de bienvenue, les messages du diaporama et le programme de la semaine. L'overlay OBS se rafraîchit tout seul à chaque enregistrement (ou ajustement du décompte).</p>
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
        Dans OBS : Sources → + → Navigateur web → collez l'URL, largeur 1920, hauteur 1080. Le fond (image de scène) et
        le logo Highlander France sont ajoutés dans OBS ; la page est entièrement transparente.
    </p>
</div>

@if (session('success'))
    <p style="color:#8c8;">{{ session('success') }}</p>
@endif
@if (session('error'))
    <p style="color:#ff8080;">{{ session('error') }}</p>
@endif

<form method="POST" action="/admin/overlay/programme/{{ $overlay['token'] }}/update" class="admin-form-stack admin-form-stack--wide" id="programme-editor">
    @csrf

    <h3 class="admin-section-title"><i class="fa-solid fa-heading"></i> Nom et bandeau de bienvenue</h3>
    <div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap;">
        <div style="flex:1; min-width:240px;">
            <label class="admin-form-label" for="editor-name">Nom (repère dans la liste) *</label>
            <input type="text" name="name" id="editor-name" class="form-control" maxlength="96" required
                   value="{{ old('name', (string) ($overlay['name'] ?? '')) }}">
        </div>
        <div style="flex:1; min-width:280px;">
            <label class="admin-form-label" for="editor-welcome">Message de bienvenue (bas de l'écran) *</label>
            <input type="text" name="welcome" id="editor-welcome" class="form-control" maxlength="120" required
                   value="{{ old('welcome', (string) ($overlay['welcome'] ?? '')) }}">
        </div>
    </div>

    <h3 class="admin-section-title"><i class="fa-regular fa-hourglass-half"></i> Décompte</h3>
    <div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap;">
        <div>
            <label class="admin-form-label">Mode</label>
            <label style="display:block; margin:4px 0;">
                <input type="radio" name="countdown_mode" value="datetime" class="js-programme-mode" @checked($mode === 'datetime')}>
                Date et heure de début (Europe/Paris)
            </label>
            <label style="display:block; margin:4px 0;">
                <input type="radio" name="countdown_mode" value="duration" class="js-programme-mode" @checked($mode !== 'datetime')}>
                Durée (repart de zéro à chaque enregistrement)
            </label>
        </div>
        <div class="js-programme-datetime" style="display:flex; gap:10px; @if ($mode !== 'datetime') display:none; @endif">
            <div>
                <label class="admin-form-label" for="editor-countdown-date">Date</label>
                <input type="date" name="countdown_date" id="editor-countdown-date" class="form-control"
                       value="{{ old('countdown_date', $targetDate) }}">
            </div>
            <div>
                <label class="admin-form-label" for="editor-countdown-time">Heure</label>
                <input type="time" name="countdown_time" id="editor-countdown-time" class="form-control"
                       value="{{ old('countdown_time', $targetTime) }}">
            </div>
        </div>
        <div class="js-programme-duration" style="@if ($mode === 'datetime') display:none; @endif">
            <label class="admin-form-label" for="editor-countdown-duration">Durée (minutes)</label>
            <input type="number" name="countdown_duration_min" id="editor-countdown-duration" class="form-control"
                   min="0" max="10080" value="{{ old('countdown_duration_min', (string) $durationMin) }}">
        </div>
        <div style="flex:1; min-width:200px;">
            <label class="admin-form-label" for="editor-countdown-label">Libellé au-dessus du décompte</label>
            <input type="text" name="countdown_label" id="editor-countdown-label" class="form-control" maxlength="48"
                   value="{{ old('countdown_label', (string) ($countdown['label'] ?? 'Début dans')) }}">
        </div>
        <div style="flex:1; min-width:200px;">
            <label class="admin-form-label" for="editor-countdown-zero">Texte une fois le décompte écoulé</label>
            <input type="text" name="countdown_zero_text" id="editor-countdown-zero" class="form-control" maxlength="48"
                   value="{{ old('countdown_zero_text', (string) ($countdown['zero_text'] ?? 'EN DIRECT')) }}" placeholder="EN DIRECT">
        </div>
    </div>

    <h3 class="admin-section-title"><i class="fa-solid fa-users"></i> Équipes du jour</h3>

    <div style="border:1px solid #333; border-radius:8px; padding:14px; margin-bottom:14px;" class="js-programme-match">
        <label class="admin-form-label" for="programme-match-url">Remplissage assisté : lien du match ETF2L</label>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <input type="text" id="programme-match-url" class="form-control" style="flex:1; min-width:280px;"
                   placeholder="https://etf2l.org/matches/93233/">
            <button type="button" class="admin-btn" id="programme-load-match"><i class="fa-solid fa-cloud-arrow-down"></i> Charger le match</button>
        </div>
        <p id="programme-match-status" style="color:#777; font-size:13px; margin:8px 0 0;"></p>
        <div id="programme-match-actions" style="display:none; margin-top:10px; gap:10px; flex-wrap:wrap; align-items:center;">
            <span id="programme-match-title" style="color:#c9b8ff;"></span>
            <button type="button" class="admin-btn" id="programme-match-fill-teams">Renseigner les équipes du jour</button>
            <button type="button" class="admin-btn" id="programme-match-add-schedule">Ajouter au programme de la semaine</button>
        </div>
    </div>

    <div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap;">
        <div style="flex:1; min-width:220px;">
            <label class="admin-form-label" for="editor-team-a">Équipe 1</label>
            <input type="text" name="team_a" id="editor-team-a" class="form-control" maxlength="64"
                   value="{{ old('team_a', (string) ($overlay['team_a'] ?? '')) }}">
        </div>
        <div style="flex:1; min-width:220px;">
            <label class="admin-form-label" for="editor-team-a-avatar">Avatar équipe 1 (URL image)</label>
            <input type="text" name="team_a_avatar" id="editor-team-a-avatar" class="form-control js-programme-team-a-avatar" maxlength="500"
                   value="{{ old('team_a_avatar', (string) ($overlay['team_a_avatar'] ?? '')) }}"
                   placeholder="https://steamcdn… (repli : avatar par défaut)">
        </div>
        <div style="flex:1; min-width:220px;">
            <label class="admin-form-label" for="editor-team-b">Équipe 2</label>
            <input type="text" name="team_b" id="editor-team-b" class="form-control" maxlength="64"
                   value="{{ old('team_b', (string) ($overlay['team_b'] ?? '')) }}">
        </div>
        <div style="flex:1; min-width:220px;">
            <label class="admin-form-label" for="editor-team-b-avatar">Avatar équipe 2 (URL image)</label>
            <input type="text" name="team_b_avatar" id="editor-team-b-avatar" class="form-control js-programme-team-b-avatar" maxlength="500"
                   value="{{ old('team_b_avatar', (string) ($overlay['team_b_avatar'] ?? '')) }}"
                   placeholder="https://steamcdn… (repli : avatar par défaut)">
        </div>
    </div>

    <h3 class="admin-section-title"><i class="fa-regular fa-comments"></i> Diaporama de messages (sous le bandeau)</h3>
    <div class="admin-form-row">
        <label class="admin-form-label" for="editor-messages">Messages — un par ligne, enchaînés en fondu</label>
        <textarea name="messages" id="editor-messages" class="form-control" rows="5" style="width:100%;">{{ old('messages', implode("\n", is_array($overlay['messages'] ?? null) ? $overlay['messages'] : [])) }}</textarea>
    </div>
    <div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap;">
        <div>
            <label class="admin-form-label" for="editor-display-s">Durée d'affichage de chaque message (secondes)</label>
            <input type="number" name="slideshow_display_s" id="editor-display-s" class="form-control"
                   min="2" max="120" value="{{ old('slideshow_display_s', (string) ($slideshow['display_s'] ?? 8)) }}">
        </div>
        <div>
            <label class="admin-form-label" for="editor-fade-s">Durée du fondu (secondes)</label>
            <input type="number" name="slideshow_fade_s" id="editor-fade-s" class="form-control"
                   min="0" max="10" value="{{ old('slideshow_fade_s', (string) ($slideshow['fade_s'] ?? 1)) }}">
        </div>
    </div>

    <h3 class="admin-section-title"><i class="fa-regular fa-calendar-check"></i> Programme de la semaine</h3>
    <p style="color:#777; font-size:13px; margin:0 0 10px;">
        Une entrée par match à caster : date (ex : « Dimanche 11 Octobre »), heure (ex : « 21h »), libellé du tour /
        division (ex : « Finale Loser Bracket - Prem ») et les deux équipes. L'ordre du formulaire fait l'ordre
        d'affichage ; les entrées vides sont ignorées.
    </p>

    <div style="border:1px solid #333; border-radius:8px; padding:14px; margin-bottom:14px;">
        <label class="admin-form-label">Import des prochains matchs ETF2L (équipes FR, table synchronisée du site)</label>
        <div class="js-programme-upcoming">
            <button type="button" class="admin-btn" id="programme-upcoming-load"><i class="fa-solid fa-cloud-arrow-down"></i> Charger les prochains matchs</button>
            <span id="programme-upcoming-status" style="color:#777; font-size:13px;"></span>
            <div id="programme-upcoming-list" style="margin-top:10px;"></div>
        </div>
    </div>

    <div id="programme-schedule" data-next="{{ count($schedule) }}">
        @foreach ($schedule as $i => $entry)
            @include('admin.partials.programme_schedule_row', ['i' => $i, 'entry' => $entry])
        @endforeach
    </div>
    <p>
        <button type="button" class="admin-btn" id="programme-schedule-add"><i class="fa-solid fa-plus"></i> Ajouter une entrée</button>
    </p>

    <p style="margin-top:18px;">
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
    </p>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-stopwatch"></i> Ajuster le décompte à la volée</h3>
<p style="color:#777; font-size:13px;">
    Sans recharger le formulaire : chaque clic ajoute ou retire du temps au décompte affiché (offset cumulé actuel :
    <strong>{{ $adjustS >= 0 ? '+' : '' }}{{ $adjustS }} s</strong>) et l'overlay OBS se resynchronise dans les
    secondes qui suivent. Le bouton « Repartir de zéro » annule l'offset ; pour changer la cible ou la durée, utilisez
    le formulaire ci-dessus.
</p>
<div style="display:flex; gap:10px; flex-wrap:wrap;">
    @foreach ([300 => '+5 min', 60 => '+1 min', -60 => '-1 min', -300 => '-5 min'] as $delta => $label)
        <form method="POST" action="/admin/overlay/programme/{{ $overlay['token'] }}/countdown">
            @csrf
            <input type="hidden" name="delta_s" value="{{ $delta }}">
            <button type="submit" class="admin-btn">{{ $label }}</button>
        </form>
    @endforeach
    <form method="POST" action="/admin/overlay/programme/{{ $overlay['token'] }}/countdown">
        @csrf
        <input type="hidden" name="reset" value="1">
        <button type="submit" class="admin-btn">Repartir de zéro</button>
    </form>
</div>

<template id="programme-schedule-template">
    @include('admin.partials.programme_schedule_row', ['i' => '__INDEX__', 'entry' => null])
</template>

<script src="{{ hlfr_asset('/_js/admin_programme.js') }}" defer></script>
@endsection
