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
        <img src="{!! e($player['avatar']) !!}" alt="Avatar de {!! e($playerName) !!}" class="profile-avatar">

        <div class="flex flex-column justify-center gap-5" style="align-items: flex-start;">
            <div class="flex align-center gap-10">
                <h2 style="margin: 0; display: flex; align-items: center; gap: 10px;">
                    {!! e($playerName) !!}
                    @if (!empty($country))
                        <img src="/_img/flags/{!! e($country) !!}.gif" alt="{!! e($countries[$country] ?? $country) !!}" class="flag-icon">
                    @endif
                </h2>
                @if ($dateFormatee)
                    <span style="font-size: 0.85rem; color: #888;">inscrit le {!! e($dateFormatee) !!}</span>
                @endif
            </div>

            <div class="staff-badges-container">
                @foreach (($etf2lLevels ?? []) as $level)
                    @if (!empty($level['division_label']))
                        @php
                            $modeLabel = $level['game_mode'] === '9v9' ? 'HL' : '6s';
                            $tooltip = 'Division moyenne ETF2L en ' . ($level['game_mode'] === '9v9' ? 'Highlander' : '6v6')
                                . ', calculée sur ses ' . (int) $level['nb_competitions'] . ' dernière(s) compétition(s) avec son équipe officielle'
                                . ' (' . (int) $level['nb_matchs_comptes'] . ' matchs — remplacements et forfaits exclus).';
                        @endphp
                        <span class="badge-staff badge-level" title="{{ $tooltip }}">
                            {{ $modeLabel }} · {{ $level['division_label'] }}
                        </span>
                    @endif
                @endforeach

                @foreach ($rolesConfig as $dbKey => $badgeInfo)
                    @if (isset($player[$dbKey]) && ($player[$dbKey] == 1 || $player[$dbKey] === true))
                        <span class="badge-staff {{ $badgeInfo['class'] }}">
                            {!! e($badgeInfo['label']) !!}
                        </span>
                    @endif
                @endforeach
            </div>
        </div>
    </div>

    @include('partials.activity-calendar')
</div>
