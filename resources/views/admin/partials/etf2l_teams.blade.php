{{-- Boîte « Remplissage assisté (équipes ETF2L) » partagée par les
     outils overlay (Bracket, Pick/Ban) : choisir une
     compétition puis charger ses équipes — un menu de sélection apparaît
     alors dans chaque bloc .js-etf2l-team du formulaire pour remplir nom,
     avatar (et pays le cas échéant) d'un clic (admin_etf2l_teams.js). Le
     menu lie aussi le bloc à l'équipe ETF2L choisie, pour rafraîchir les
     rosters d'un clic entre deux saisons. L'outil Scores ne l'utilise
     pas : ses équipes viennent du lien de match ETF2L
     (admin_series_match.js), le remplissage assisté reste le repli des
     autres outils. L'outil Overlay Logs ne l'utilise pas non plus : les
     noms d'équipes y sont identifiés automatiquement depuis les joueurs
     du log. Les contrôleurs passent la liste des compétitions depuis
     Etf2lTeamService::competitionsBothFormats() (Highlander puis 6v6,
     marquées « (6v6) » dans le menu) ou ::competitions() pour l'outil
     Overlay Rosters, dont le format est déjà fixé par l'overlay ; une
     API indisponible masque le chargement sans bloquer la saisie
     manuelle.
     Variables attendues : $competitions, $prefix (préfixe d'identifiants
     unique par page : bracket, pickban). --}}
<h3 class="admin-section-title"><i class="fa-solid fa-user-group"></i> Remplissage assisté (équipes ETF2L)</h3>
<div class="admin-form-row js-etf2l-fill" style="border:1px solid #9b6dff; border-radius:8px; padding:14px; background:rgba(155,109,255,0.08); margin-bottom:14px;">
    @if (count($competitions) > 0)
        <p style="margin:0 0 10px; color:#bbb; font-size:13px;">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            Choisissez une compétition puis chargez ses équipes : un menu de sélection apparaît dans chaque bloc
            équipe du formulaire pour remplir nom et avatar d'un clic — et lier l'équipe à son roster ETF2L
            (alignement automatique des couleurs de logs sur les côtés A / B). La saisie manuelle reste possible à tout moment.
        </p>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <div style="flex:2; min-width:280px;">
                <label class="admin-form-label" for="{{ $prefix }}-teams-competition">Compétition ETF2L</label>
                @php
                    // Le marqueur « (6v6) » n'apparaît que si le menu mélange les deux
                    // formats : l'éditeur Overlay Rosters reçoit une liste mono-format
                    // (celle de son overlay) et n'a pas besoin de la précision.
                    $mixedFormats = count(array_unique(array_column($competitions, 'type'))) > 1;
                @endphp
                <select id="{{ $prefix }}-teams-competition" class="form-control js-etf2l-competition">
                    @foreach ($competitions as $competition)
                        <option value="{{ (int) $competition['id'] }}">
                            {{ e($competition['name']) }}@if ($mixedFormats && ($competition['type'] ?? '') === '6v6') (6v6)@endif{{ $competition['archived'] ? ' (archivée)' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="button" id="{{ $prefix }}-load-teams" class="admin-btn admin-btn--primary js-etf2l-load">
                <i class="fa-solid fa-cloud-arrow-down"></i> Charger les équipes
            </button>
        </div>
        <p id="{{ $prefix }}-teams-status" class="js-etf2l-status" style="margin:10px 0 0; font-size:13px; color:#888;"></p>
    @else
        <p style="margin:0; color:#888; font-size:13px;">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            Liste des compétitions ETF2L indisponible pour le moment : remplissez les équipes à la main, ou
            réessayez plus tard.
        </p>
    @endif
</div>
