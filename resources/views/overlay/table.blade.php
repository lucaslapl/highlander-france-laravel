{{-- Vue overlay OBS du tableau de classement « poule » (1920x1080, fond
     transparent). Page autonome : pas de layout site, aucun chrome. Le
     tableau est rendu côté serveur (rang, drapeau, avatar, surlignage du
     top X) ; l'auto-rafraîchissement est porté par overlay_bracket.js
     (polling de version commun aux deux formats). --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ hlfr_asset('/_css/overlay_table.css') }}">
</head>
<body>
<div id="scene">
    <header class="anim" style="--d:.2s;">
        @if (trim($data['eyebrow']) !== '')
            <div class="eyebrow">{{ e($data['eyebrow']) }}</div>
        @endif
        <h1>
            {{ e($data['title']) }}
            @if (trim($data['accent']) !== '')
                <em>{{ e($data['accent']) }}</em>
            @endif
        </h1>
        <div class="title-bar"></div>
    </header>

    <div class="standings anim" style="--d:.9s;">
        <table>
            <thead>
                <tr>
                    <th></th>
                    <th class="team-col">Équipe</th>
                    <th>MJ</th>
                    <th>G</th>
                    <th>P</th>
                    <th>Pts</th>
                    @if ($data['show_penalty'])
                        <th>Pén</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($data['rows'] as $row)
                    <tr class="{{ $row['qualified'] ? 'qualified' : '' }}">
                        <td class="rank">{{ (int) $row['rank'] }}</td>
                        <td class="team-col">
                            <span class="team-cell">
                                <img class="flag" src="{{ e($row['flag']) }}" alt="" loading="lazy">
                                @if ($row['avatar'] !== '')
                                    <img class="avatar" src="{{ e($row['avatar']) }}" alt="" loading="lazy">
                                @endif
                                <span class="team-name">{{ e($row['name']) }}</span>
                            </span>
                        </td>
                        <td class="numeric">{{ (int) $row['played'] }}</td>
                        <td class="numeric">{{ (int) $row['won'] }}</td>
                        <td class="numeric">{{ (int) $row['lost'] }}</td>
                        <td class="points">{{ (int) $row['score'] }}</td>
                        @if ($data['show_penalty'])
                            <td class="penalty">{{ (int) $row['penalty'] }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<script src="{{ hlfr_asset('/_js/overlay_bracket.js') }}" defer
        data-token="{{ $token }}" data-version="{{ (int) $version }}"></script>
</body>
</html>
