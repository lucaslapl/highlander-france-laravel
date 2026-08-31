@php
$rolesConfig = [
    'is_founder'   => ['label' => 'Fondateur',   'class' => 'badge-founder'],
    'is_admin'     => ['label' => 'Admin',       'class' => 'badge-admin'],
    'is_moderator' => ['label' => 'Modérateur',  'class' => 'badge-moderator'],
    'is_mentor'    => ['label' => 'Mentor',      'class' => 'badge-mentor'],
    'is_mixer'     => ['label' => 'Mixer',       'class' => 'badge-mixer'],
];
@endphp
<div class="personnal-info__top">
    <div class="profile-header flex align-center">
        <img src="/img/avatar/{!! e($steamid64 ?? $player['steamid'] ?? '') !!}" alt="Avatar de {!! e($playerName) !!}" class="profile-avatar" loading="lazy" decoding="async">

        <div class="flex flex-column justify-center gap-5" style="align-items: flex-start;">
            <div class="flex align-center gap-10">
                <h1 style="margin: 0; display: flex; align-items: center; gap: 10px; font-size:1.4em;">
                    {!! e($playerName) !!}
                    @if (!empty($country) && $country !== 'unknown' && !empty($player['country_locked']))
                        <img src="/_img/flags/{!! e($country) !!}.gif" alt="{!! e($countries[$country] ?? $country) !!}" class="flag-icon">
                    @endif
                </h1>
                @if ($dateFormatee)
                    <span style="font-size: 0.85rem; color: #888;">inscrit le {!! e($dateFormatee) !!}</span>
                @endif
            </div>

            <div class="staff-badges-container">
                @foreach ($rolesConfig as $dbKey => $badgeInfo)
                    @if (isset($player[$dbKey]) && ($player[$dbKey] == 1 || $player[$dbKey] === true))
                        <span class="badge-staff {{ $badgeInfo['class'] }}">
                            {!! e($badgeInfo['label']) !!}
                        </span>
                    @endif
                @endforeach
            </div>

            @php
                $validLevels = collect($etf2lLevels ?? [])->filter(fn($l) => !empty($l['division_label']));
                $hasFranceHl = !empty($franceBadges['highlander']);
                $hasFrance6v6 = !empty($franceBadges['6v6']);
                $hasFrance = $hasFranceHl || $hasFrance6v6;
            @endphp
            @if ($validLevels->isNotEmpty() || $hasFrance)
                <div class="division-badges-container">
                    <span class="division-badges-label">Divisions</span>
                    <div class="division-badges-list">
                        @foreach ($validLevels as $level)
                            @php
                                $modeLabel = $level['game_mode'] === '9v9' ? 'HL' : '6s';
                                $lastYear = !empty($level['last_match_time']) ? date('Y', (int) $level['last_match_time']) : null;
                                $tooltip = 'Division moyenne ETF2L en ' . ($level['game_mode'] === '9v9' ? 'Highlander' : '6v6')
                                    . ', calculée sur ses ' . (int) $level['nb_competitions'] . ' dernière(s) saison(s) officielle(s) avec son équipe'
                                    . ' (' . (int) $level['nb_matchs_comptes'] . ' matchs — remplacements et forfaits exclus)'
                                    . ($lastYear !== null ? ', données jusqu\'en ' . $lastYear : '') . '.';
                                $tier = isset($level['tier_moyen']) ? (int) round((float) $level['tier_moyen']) : null;
                            @endphp
                            <span class="division-badge division-tier-{{ $tier !== null ? $tier : 'unknown' }}" title="{{ $tooltip }}">
                                <span class="division-badge-mode">{{ $modeLabel }}</span>
                                <span class="division-badge-sep"></span>
                                <span class="division-badge-division">{{ $level['division_label'] }}</span>
                            </span>
                        @endforeach
                        @if ($hasFranceHl)
                            <span class="division-badge badge-france badge-france-highlander" title="Membre actuel de l'équipe de France Highlander (ETF2L #15176)">Équipe de France Highlander</span>
                        @endif
                        @if ($hasFrance6v6)
                            <span class="division-badge badge-france badge-france-6v6" title="Membre actuel de l'équipe de France 6v6 (ETF2L #332)">Équipe de France 6v6</span>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    @include('partials.activity-calendar')
</div>
