@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.panel_back_link')

<link rel="stylesheet" href="{{ hlfr_asset('/_css/admin_overlay.css') }}">

@php
    $isBracket = ($bracket['kind'] ?? 'bracket') === 'table' ? false : true;
@endphp

<div class="admin-header" style="--accent:#9b6dff;">
    <h2>
        <i class="fa-solid fa-sitemap"></i>
        Overlay {{ $isBracket ? 'Bracket' : 'Classement' }} — {{ e($bracket['title'] !== '' ? $bracket['title'] : $bracket['token']) }}
    </h2>
    <p>
        {{ $isBracket
            ? 'Éditez le bracket directement sur son rendu : générez un squelette, puis cliquez sur une case ou une colonne pour la remplir et la déplacer.'
            : 'Modifiez les titres et les lignes du classement. L\'overlay OBS se rafraîchit tout seul à chaque enregistrement.' }}
    </p>
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
        Dans OBS : Sources → + → Navigateur web → collez l'URL, largeur 1920, hauteur 1080. Le match « EN DIRECT »
        attaché à une série suit aussi les points manuels, la réconciliation logs.tf et le webhook match-ended : le
        bracket se rafraîchit alors sans rien toucher.
    </p>
</div>

@if (session('success'))
    <p style="color:#8c8;">{{ e(session('success')) }}</p>
@endif
@if (session('error'))
    <p style="color:#ff8080;">{{ e(session('error')) }}</p>
@endif

<form method="POST" action="/admin/overlay/bracket/{{ $bracket['token'] }}/update" class="admin-form-stack admin-form-stack--wide" id="bracket-editor">
    @csrf

    <h3 class="admin-section-title"><i class="fa-solid fa-heading"></i> Titres</h3>
    <div class="admin-form-row" style="display:flex; gap:16px; flex-wrap:wrap;">
        <div style="flex:1; min-width:260px;">
            <label class="admin-form-label">Sur-titre (petit texte au-dessus du titre)</label>
            <input type="text" name="eyebrow" class="form-control" maxlength="96"
                   value="{{ e((string) ($bracket['eyebrow'] ?? '')) }}"
                   placeholder="ETF2L Highlander Saison 36">
        </div>
        <div style="flex:1; min-width:200px;">
            <label class="admin-form-label">Titre *</label>
            <input type="text" name="title" class="form-control" maxlength="96" required
                   value="{{ e((string) ($bracket['title'] ?? '')) }}" placeholder="Division 1">
        </div>
        <div style="flex:1; min-width:160px;">
            <label class="admin-form-label">Mot accentué (en rose dans le titre)</label>
            <input type="text" name="accent" class="form-control" maxlength="48"
                   value="{{ e((string) ($bracket['accent'] ?? '')) }}" placeholder="Playoffs">
        </div>
    </div>

    @include('admin.partials.etf2l_teams', ['competitions' => $competitions, 'prefix' => 'bracket'])

    @if ($isBracket)

        <h3 class="admin-section-title"><i class="fa-solid fa-sitemap"></i> Bracket</h3>
        <p style="color:#777; font-size:13px; margin:0 0 12px;">
            Générez d'abord un squelette, puis complétez case par case : chaque clic sur une case ouvre son édition à
            droite, chaque clic sur une étiquette de round ouvre l'édition de la colonne. Voies : « Haute » et « Basse »
            sont les voies gagnante / perdante d'une <strong>double élimination</strong> (WB / LB) ; pour une élimination
            <strong>simple</strong>, laissez toutes les colonnes en voie « Haute » — les connecteurs suivront tout seuls.
            « Finale » place la grande carte à droite, alimentée automatiquement. Le <strong>système suisse</strong>
            génère ceil(log2(N)) rounds indépendants (sans connecteurs) du nombre d'équipes saisi ; à partir de
            7 cases par round (14 équipes), le round se pose en double colonne pour ne pas étirer l'overlay.
        </p>
        <noscript>
            <p style="color:#ff8080;">L'éditeur visuel nécessite JavaScript : sans lui, les cases du bracket ne seraient pas enregistrées.</p>
        </noscript>

        <div class="bkv-generator">
            <div id="bkv-gen-teams-wrap">
                <label class="admin-form-label" for="bkv-gen-teams">Équipes</label>
                <select id="bkv-gen-teams" class="form-control">
                    <option value="4">4</option>
                    <option value="8" selected>8</option>
                    <option value="16">16</option>
                </select>
            </div>
            <div id="bkv-gen-swiss-teams-wrap" hidden>
                <label class="admin-form-label" for="bkv-gen-swiss-teams">Nombre d'équipes (saisie manuelle)</label>
                <input type="number" id="bkv-gen-swiss-teams" class="form-control" min="2" max="64" value="8">
            </div>
            <div>
                <label class="admin-form-label" for="bkv-gen-type">Format</label>
                <select id="bkv-gen-type" class="form-control">
                    <option value="double" selected>Double élimination (WB / LB / grande finale)</option>
                    <option value="single">Simple élimination</option>
                    <option value="swiss">Système suisse (rounds indépendants)</option>
                </select>
            </div>
            <button type="button" class="admin-btn" id="bkv-gen-run">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Générer le squelette
            </button>
        </div>

        <div class="bkv-layout">
            <div class="bkv-canvas" id="bkv-canvas"></div>
            <aside class="bkv-panel">
                <div class="bkv-panel-hint" id="bkv-panel-hint">
                    Cliquez sur une case du bracket pour éditer ses équipes, son score, sa série ou la marquer
                    « EN DIRECT » ; cliquez sur une étiquette de round pour éditer la colonne.
                </div>

                <div id="bkv-column-section" hidden>
                    <h4 id="bkv-column-title">Colonne</h4>
                    <label class="admin-form-label">Label du round (ex : « LB Final »)</label>
                    <input type="text" id="bkv-column-label" class="form-control" maxlength="48">
                    <label class="admin-form-label">Voie</label>
                    <select id="bkv-column-lane" class="form-control">
                        <option value="upper">Haute</option>
                        <option value="lower">Basse</option>
                        <option value="final">Finale (grande carte)</option>
                    </select>
                    <div class="bkv-panel-buttons">
                        <button type="button" class="admin-btn" id="bkv-column-left" title="Déplacer la colonne vers la gauche">
                            <i class="fa-solid fa-arrow-left"></i> Gauche
                        </button>
                        <button type="button" class="admin-btn" id="bkv-column-right" title="Déplacer la colonne vers la droite">
                            <i class="fa-solid fa-arrow-right"></i> Droite
                        </button>
                        <button type="button" class="admin-btn admin-btn--danger" id="bkv-column-delete">
                            <i class="fa-solid fa-trash"></i> Supprimer
                        </button>
                    </div>
                </div>

                <div id="bkv-match-section" hidden>
                    <h4 id="bkv-match-title">Case</h4>

                    <div id="bkv-match-label-row" hidden>
                        <label class="admin-form-label">Titre de la carte</label>
                        <input type="text" id="bkv-match-label" class="form-control" maxlength="48"
                               placeholder="Ex : Match de 3è place — vide pour le titre de la colonne (grande finale)">
                    </div>

                    @foreach ([1 => 'A', 2 => 'B'] as $side => $label)
                        <div class="js-etf2l-team" style="border:1px solid #333; border-radius:8px; padding:12px; margin-top:10px;">
                            <h5 style="margin:0 0 8px; color:#bbb;">Équipe {{ $label }}</h5>
                            <label class="admin-form-label">Nom</label>
                            <input type="text" id="bkv-t{{ $side }}-name" class="form-control js-team-field-name" maxlength="64">
                            <label class="admin-form-label" style="margin-top:8px;">Avatar par URL (optionnel)</label>
                            <input type="url" id="bkv-t{{ $side }}-avatar" class="form-control js-team-field-avatar"
                                   maxlength="500" placeholder="https://…/logo.png">
                            <label class="admin-form-label" style="margin-top:8px;">Pays (optionnel)</label>
                            <input type="text" id="bkv-t{{ $side }}-country" class="form-control js-team-field-country"
                                   maxlength="32" placeholder="France">
                            <label class="admin-form-label" style="margin-top:8px;">Score</label>
                            <input type="text" id="bkv-t{{ $side }}-score" class="form-control" maxlength="3"
                                   inputmode="numeric">
                        </div>
                    @endforeach

                    <label class="admin-form-label" style="margin-top:12px;">Série suivie pour le score live (optionnel)</label>
                    <select id="bkv-match-series" class="form-control">
                        <option value="">— Aucune (score saisi ci-dessus) —</option>
                        @foreach (($seriesList ?? []) as $series)
                            <option value="{{ e((string) ($series['token'] ?? '')) }}">
                                {{ e((string) ($series['title'] ?? '')) }}
                                — {{ e((string) ($series['teams']['red']['name'] ?? '')) }} vs {{ e((string) ($series['teams']['blue']['name'] ?? '')) }}
                            </option>
                        @endforeach
                    </select>
                    <label class="admin-form-label" style="margin-top:12px; margin-bottom:6px;">
                        <input type="checkbox" id="bkv-match-manual" value="1">
                        Score manuel forcé (ignorer la série)
                    </label>
                    <label class="admin-form-label" style="margin-bottom:6px;">
                        <input type="checkbox" id="bkv-match-live" value="1">
                        Match « EN DIRECT » (badge rouge, score suivi en direct)
                    </label>
                    <div class="bkv-panel-buttons">
                        <button type="button" class="admin-btn" id="bkv-match-up" title="Monter la case dans sa colonne">
                            <i class="fa-solid fa-arrow-up"></i>
                        </button>
                        <button type="button" class="admin-btn" id="bkv-match-down" title="Descendre la case dans sa colonne">
                            <i class="fa-solid fa-arrow-down"></i>
                        </button>
                        <button type="button" class="admin-btn admin-btn--danger" id="bkv-match-delete">
                            <i class="fa-solid fa-trash"></i> Supprimer la case
                        </button>
                    </div>
                </div>

                {{-- Visible dès qu'une finale est sélectionnée (colonne ou case) :
                     ajoute une carte compacte sous la grande finale. --}}
                <button type="button" class="admin-btn" id="bkv-add-third" hidden style="margin-top:12px;">
                    <i class="fa-solid fa-plus"></i> Ajouter un match de 3è place
                </button>
            </aside>
        </div>

        <div class="bkv-toolbar">
            <button type="button" class="admin-btn" id="bkv-add-column"><i class="fa-solid fa-plus"></i> Ajouter une colonne</button>
            <button type="button" class="admin-btn" id="bkv-add-match" disabled>
                <i class="fa-solid fa-plus"></i> Ajouter une case à cette colonne
            </button>
        </div>

        {{-- État de l'éditeur : colonnes et match EN DIRECT, sérialisés en
             POST au moment d'enregistrer (admin_bracket_editor.js). --}}
        <script type="application/json" id="bkv-data">{!! json_encode([
            'columns' => $bracket['columns'] ?? [],
            'live' => $bracket['live'] ?? null,
            'default_avatar' => hlfr_asset('/_img/av_default.png'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
        <div id="bkv-serialized" aria-hidden="true"></div>

    @else

        <h3 class="admin-section-title"><i class="fa-solid fa-list-ol"></i> Classement</h3>
        <p style="color:#777; font-size:13px; margin:0 0 12px;">
            L'ordre des lignes fait le classement (le rang est calculé à l'affichage) : cliquez sur une ligne pour
            la remplir, la déplacer ou la supprimer. L'aperçu reprend l'habillage de l'overlay — médailles pour
            le top 3, colonne « Pén » si une pénalité existe.
        </p>
        <noscript>
            <p style="color:#ff8080;">L'éditeur visuel nécessite JavaScript : sans lui, les lignes du classement ne seraient pas enregistrées.</p>
        </noscript>

        <div class="bkv-layout">
            <div class="bkv-canvas bkt-canvas" id="bkt-canvas"></div>
            <aside class="bkv-panel">
                <div class="bkv-panel-hint" id="bkt-panel-hint">
                    Cliquez sur une ligne du classement pour éditer son équipe, ses statistiques et ses points.
                </div>

                <div id="bkt-row-section" hidden>
                    <h4 id="bkt-row-title">Ligne</h4>

                    <div class="js-etf2l-team" style="border:1px solid #333; border-radius:8px; padding:12px;">
                        <h5 style="margin:0 0 8px; color:#bbb;">Équipe</h5>
                        <label class="admin-form-label">Nom</label>
                        <input type="text" id="bkt-name" class="form-control js-team-field-name" maxlength="64">
                        <label class="admin-form-label" style="margin-top:8px;">Avatar par URL (optionnel)</label>
                        <input type="url" id="bkt-avatar" class="form-control js-team-field-avatar"
                               maxlength="500" placeholder="https://…/logo.png">
                        <label class="admin-form-label" style="margin-top:8px;">Pays (optionnel)</label>
                        <input type="text" id="bkt-country" class="form-control js-team-field-country"
                               maxlength="32" placeholder="France">
                    </div>

                    <label class="admin-form-label" style="margin-top:12px;">Statistiques</label>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <div style="flex:1; min-width:64px;">
                            <label class="admin-form-label">MJ</label>
                            <input type="number" id="bkt-played" class="form-control" min="0" max="9999">
                        </div>
                        <div style="flex:1; min-width:64px;">
                            <label class="admin-form-label">G</label>
                            <input type="number" id="bkt-won" class="form-control" min="0" max="9999">
                        </div>
                        <div style="flex:1; min-width:64px;">
                            <label class="admin-form-label">P</label>
                            <input type="number" id="bkt-lost" class="form-control" min="0" max="9999">
                        </div>
                        <div style="flex:1; min-width:64px;">
                            <label class="admin-form-label">Pts</label>
                            <input type="number" id="bkt-score" class="form-control" min="0" max="9999">
                        </div>
                        <div style="flex:1; min-width:64px;">
                            <label class="admin-form-label">Pén</label>
                            <input type="number" id="bkt-penalty" class="form-control" min="0" max="9999">
                        </div>
                    </div>

                    <div class="bkv-panel-buttons">
                        <button type="button" class="admin-btn" id="bkt-row-up" title="Monter la ligne">
                            <i class="fa-solid fa-arrow-up"></i>
                        </button>
                        <button type="button" class="admin-btn" id="bkt-row-down" title="Descendre la ligne">
                            <i class="fa-solid fa-arrow-down"></i>
                        </button>
                        <button type="button" class="admin-btn admin-btn--danger" id="bkt-row-delete">
                            <i class="fa-solid fa-trash"></i> Supprimer la ligne
                        </button>
                    </div>
                </div>
            </aside>
        </div>

        <div class="bkv-toolbar">
            <button type="button" class="admin-btn" id="bkt-add-row"><i class="fa-solid fa-plus"></i> Ajouter une ligne</button>
        </div>

        {{-- État de l'éditeur : lignes du classement, sérialisées en
             POST au moment d'enregistrer (admin_table_editor.js). --}}
        <script type="application/json" id="bkt-data">{!! json_encode([
            'rows' => $bracket['rows'] ?? [],
            'default_avatar' => hlfr_asset('/_img/av_default.png'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
        <div id="bkt-serialized" aria-hidden="true"></div>

    @endif

    <div style="margin-top:18px;">
        <button type="submit" class="admin-btn admin-btn--primary"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
    </div>
</form>

<h3 class="admin-section-title"><i class="fa-solid fa-rotate"></i> Actions</h3>
<div style="display:flex; gap:12px; flex-wrap:wrap;">
    <a href="/admin/overlay/bracket" class="admin-btn"><i class="fa-solid fa-arrow-left"></i> Retour à la liste</a>
    <form method="POST" action="/admin/overlay/bracket/{{ $bracket['token'] }}/delete"
          onsubmit="return confirm('Supprimer cet overlay ?');">
        @csrf
        <button type="submit" class="admin-btn admin-btn--danger"><i class="fa-solid fa-trash"></i> Supprimer</button>
    </form>
</div>

<script src="{{ hlfr_asset('/_js/admin_etf2l_teams.js') }}" defer></script>
@if ($isBracket)
    <script src="{{ hlfr_asset('/_js/bracket_layout.js') }}" defer></script>
    <script src="{{ hlfr_asset('/_js/admin_bracket_editor.js') }}" defer></script>
@else
    <script src="{{ hlfr_asset('/_js/admin_table_editor.js') }}" defer></script>
@endif
@endsection
