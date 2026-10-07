{{-- Boîte « Remplissage assisté (équipes ETF2L) » partagée par tous les
     outils overlay (Logs, Scores, Bracket, Pick/Ban) : choisir une
     compétition puis charger ses équipes — un menu de sélection apparaît
     alors dans chaque bloc .js-etf2l-team du formulaire pour remplir nom,
     avatar (et pays le cas échéant) d'un clic (admin_etf2l_teams.js).
     Les contrôleurs passent la liste des compétitions depuis
     Etf2lTeamService::competitions() ; une API indisponible masque le
     chargement sans bloquer la saisie manuelle.
     Variables attendues : $competitions, $prefix (préfixe d'identifiants
     unique par page : overlay, series, bracket, pickban). --}}
<h3 class="admin-section-title"><i class="fa-solid fa-user-group"></i> Remplissage assisté (équipes ETF2L)</h3>
<div class="admin-form-row js-etf2l-fill" style="border:1px solid #9b6dff; border-radius:8px; padding:14px; background:rgba(155,109,255,0.08); margin-bottom:14px;">
    @if (count($competitions) > 0)
        <p style="margin:0 0 10px; color:#bbb; font-size:13px;">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            Choisissez une compétition puis chargez ses équipes : un menu de sélection apparaît dans chaque bloc
            équipe du formulaire pour remplir nom et avatar d'un clic. La saisie manuelle reste possible à tout moment.
        </p>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <div style="flex:2; min-width:280px;">
                <label class="admin-form-label" for="{{ $prefix }}-teams-competition">Compétition ETF2L</label>
                <select id="{{ $prefix }}-teams-competition" class="form-control js-etf2l-competition">
                    @foreach ($competitions as $competition)
                        <option value="{{ (int) $competition['id'] }}">
                            {{ e($competition['name']) }}{{ $competition['archived'] ? ' (archivée)' : '' }}
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
