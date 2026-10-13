{{-- Vue overlay OBS « Programme » (1920x1080, fond transparent).
     Page autonome : pas de layout site, aucun chrome. Le contenu est
     rendu côté serveur ; overlay_programme.js fait vivre la page :
     horloge Europe/Paris (tic à la seconde), décompte avant le match
     (date/heure cible ou durée, ajustable côté admin), diaporama de
     messages en fondu et polling de /programme-overlay/{token}/version
     toutes les 5 s — chaque enregistrement (ou ajustement du décompte)
     côté admin bump la version et la page se recharge entièrement,
     comme les autres overlays.

     L'icône note de musique (haut gauche) est un repère fixe : le plugin
     OBS de musique du caster se pose dessus ; fond et logo Highlander
     France sont ajoutés dans OBS, jamais ici. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ hlfr_asset('/_css/overlay_programme.css') }}">
</head>
<body>
<div id="scene">

    {{-- ── Haut gauche : horloge / musique / décompte, puis équipes du
         jour à droite dans leur propre colonne, séparées par une
         bordure verticale rose/violet. --}}
    <div class="top-left">
        <div class="tl-main">
            <div class="tl-row anim" style="--d:.2s;">
                <span class="clock" id="clock">--:--</span>
                <span class="tl-sep"></span>
                <span class="music-slot" title="Emplacement du plugin musique OBS">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M9 3v10.55A4 4 0 1 0 11 17V7h4a2 2 0 1 0 0-4H9z"/>
                    </svg>
                </span>
            </div>

            <div class="countdown anim" style="--d:.5s;"
                 data-mode="{{ $data['countdown_mode'] }}"
                 data-target-ts="{{ $data['countdown_target_ts'] }}"
                 data-started-at="{{ $data['countdown_started_at'] }}"
                 data-duration-s="{{ $data['countdown_duration_s'] }}"
                 data-adjust-s="{{ $data['countdown_adjust_s'] }}"
                 data-zero-text="{{ e($data['countdown_zero_text']) }}">
                @if (trim($data['countdown_label']) !== '')
                    <span class="cd-label">{{ e($data['countdown_label']) }}</span>
                @endif
                <span class="cd-value" id="countdown-value">--:--</span>
            </div>
        </div>

        @if (trim($data['team_a']) !== '' || trim($data['team_b']) !== '')
            <div class="tl-teams anim" style="--d:.8s;">
                @foreach ([['name' => $data['team_a'], 'avatar' => $data['team_a_avatar']], ['name' => $data['team_b'], 'avatar' => $data['team_b_avatar']]] as $team)
                    @if (trim($team['name']) !== '')
                        <div class="team-row">
                            <img class="team-avatar"
                                 src="{{ trim($team['avatar']) !== '' ? e($team['avatar']) : hlfr_asset('/_img/av_default.png') }}"
                                 alt="" loading="eager"
                                 onerror="this.onerror=null; this.src='{{ hlfr_asset('/_img/av_default.png') }}';">
                            <span class="team-name">{{ e($team['name']) }}</span>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>

    {{-- ── Bas centre : bandeau de bienvenue + diaporama de messages ── --}}
    <div class="bottom-banner">
        @if (trim($data['welcome']) !== '')
            <div class="welcome anim" style="--d:1.1s;">{{ e($data['welcome']) }}</div>
        @endif
        @if (count($data['messages']) > 0)
            <div class="slideshow anim" style="--d:1.4s;"
                 data-display-s="{{ $data['slideshow_display_s'] }}"
                 data-fade-s="{{ $data['slideshow_fade_s'] }}">
                @foreach ($data['messages'] as $i => $message)
                    <span class="slideshow-msg{{ $i === 0 ? ' is-visible' : '' }}">{{ e($message) }}</span>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ── Bas droite : programme de la semaine ── --}}
    @if (count($data['schedule']) > 0)
        <div class="schedule anim" style="--d:1.7s;">
            <h2 class="schedule-title">Programmation de la semaine</h2>
            @foreach ($data['schedule'] as $entry)
                <div class="schedule-entry">
                    <div class="schedule-when">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 2v2h10V2h2v2h1a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1V2h2zM4 9v11h16V9H4zm6 2h4v2h-4v-2z"/></svg>
                        <span class="schedule-day">{{ e($entry['day']) }}</span>
                        @if (trim($entry['time']) !== '')
                            <span class="schedule-time">- {{ e($entry['time']) }}</span>
                        @endif
                    </div>
                    @if (trim($entry['label']) !== '')
                        <div class="schedule-label">{{ e($entry['label']) }}</div>
                    @endif
                    @if (trim($entry['team_a']) !== '' || trim($entry['team_b']) !== '')
                        <div class="schedule-teams">
                            <span class="schedule-team">{{ e($entry['team_a']) }}</span>
                            <span class="schedule-vs">-</span>
                            <span class="schedule-team">{{ e($entry['team_b']) }}</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

</div>

<script src="{{ hlfr_asset('/_js/overlay_programme.js') }}" defer
        data-token="{{ $token }}" data-version="{{ (int) $version }}"></script>
</body>
</html>
