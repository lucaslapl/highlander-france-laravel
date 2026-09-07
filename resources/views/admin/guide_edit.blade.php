@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@push('styles')
    @foreach ($styles ?? [] as $style)
        @if (preg_match('~^https?://~', $style))
            <link rel="stylesheet" href="{{ e($style) }}">
        @else
            <link rel="stylesheet" href="{{ hlfr_asset($style) }}">
        @endif
    @endforeach
@endpush

@push('scripts')
    @foreach ($scripts ?? [] as $script)
        @if (preg_match('~^https?://~', $script))
            <script src="{{ e($script) }}"></script>
        @else
            <script src="{{ hlfr_asset($script) }}" defer></script>
        @endif
    @endforeach
@endpush

@section('content')
<div class="admin-header" style="--accent:#fbb7fb;">
    <h2><i class="fa-solid fa-pen"></i> {{ $guide === null ? 'Nouveau guide' : 'Éditer : '.$guide['title'] }}</h2>
    <p><a href="/admin/guides">← Retour à la liste</a></p>
</div>

<form action="{{ $guide === null ? '/admin/guides/store' : '/admin/guides/'.(int) $guide['id'].'/update' }}" method="POST" class="admin-form-stack admin-form-stack--wide" style="--accent:#fbb7fb;">
    @csrf

    <div class="form-group">
        <label class="admin-form-label" for="guide-title">Titre</label>
        <input type="text" class="form-control" id="guide-title" name="title" required maxlength="191" value="{{ e(old('title', $guide['title'] ?? '')) }}" placeholder="Ex. : Découvrir le format Highlander">
        <small class="form-hint">Le titre visible par les visiteurs et dans les résultats de recherche. Soyez <span class="hint-label">concis et explicite</span> (idéalement 50 à 60 caractères). Il donne le sujet du guide en un coup d'œil.</small>
    </div>

    <div class="form-group">
        <label class="admin-form-label" for="guide-slug">Slug URL</label>
        <input type="text" class="form-control" id="guide-slug" name="slug" required pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="128" value="{{ e(old('slug', $guide['slug'] ?? '')) }}" placeholder="debuter-tf2-competitif">
        <small class="form-hint">La fin de l'URL du guide (ex. <code>/guides/debuter-tf2-competitif</code>). <span class="hint-label">Uniquement des minuscules, des chiffres et des tirets</span> <code>-</code> entre les mots, sans accents ni espaces. Une fois publié, il est déconseillé de le modifier.</small>
    </div>

    <div class="form-group">
        <label class="admin-form-label" for="guide-meta">Meta description SEO (max 300)</label>
        <input type="text" class="form-control" id="guide-meta" name="meta_description" required maxlength="300" value="{{ e(old('meta_description', $guide['meta_description'] ?? '')) }}" placeholder="Résumé accrocheur du guide..." >
        <small class="form-hint">Le petit texte affiché <span class="hint-label">sous le titre dans Google</span>. Résumez en 1 à 2 phrases l'apport du guide (ce que le lecteur va apprendre). <span class="hint-label">150 à 160 caractères conseillés</span> pour ne pas être tronqué, sans répéter le titre.</small>
    </div>

    <div class="form-group">
        <label class="admin-form-label" for="guide-cat">Catégorie</label>
        <select name="category" id="guide-cat" class="form-control" required>
            @foreach ($categories as $cat)
                <option value="{{ e($cat) }}" {{ old('category', $guide['category'] ?? '') === $cat ? 'selected' : '' }}>{{ e($cat) }}</option>
            @endforeach
        </select>
        <small class="form-hint">Le thème sous lequel le guide sera classé sur la page publique : <code>debuter</code> (prendre en main TF2/Highlander), <code>highlander</code> (le format 9v9 en général), <code>classes</code> (par classe), <code>6v6</code> (le format 6v6), <code>config</code> (configuration, HUD, launcher...).</small>
    </div>

    <div class="form-group">
        <label class="admin-form-label" for="guide-excerpt">Extrait (carte hub)</label>
        <textarea name="excerpt" id="guide-excerpt" class="form-control" rows="3" maxlength="2000" placeholder="1 à 2 phrases résumant le contenu...">{{ e(old('excerpt', $guide['excerpt'] ?? '')) }}</textarea>
        <small class="form-hint">Un résumé court affiché sur la <span class="hint-label">carte du guide dans le hub</span> (/guides). Rédigez 1 à 2 phrases accrocheuses pour donner envie de cliquer.</small>
    </div>

    <div class="form-group">
        <label class="admin-form-label" for="guide-order">Ordre</label>
        <input type="number" class="form-control" id="guide-order" name="sort_order" min="0" max="10000" value="{{ e((string) old('sort_order', $guide['sort_order'] ?? 0)) }}">
        <small class="form-hint">Position du guide dans sa catégorie. <span class="hint-label">Tri ascendant</span> : la valeur la plus petite s'affiche en premier. Laissez 0 pour placer à la fin automatiquement.</small>
    </div>

    <div class="form-group checkbox-group" style="grid-template-columns:1fr;">
        <label class="admin-label"><input type="checkbox" name="is_published" value="1" {{ old('is_published', ($guide['is_published'] ?? 1)) ? 'checked' : '' }}> Publié</label>
    </div>
    <small class="form-hint" style="margin-top:-6px;">Décochez pour masquer (<span class="hint-label">brouillon</span>) le guide sur le site public tout en conservant le contenu.</small>

    <div class="form-group">
        <label class="admin-form-label" for="markdownEditor">Contenu Markdown étendu</label>
        <textarea id="markdownEditor" name="content_markdown" rows="28" required>{{ e(old('content_markdown', $guide['content_markdown'] ?? '')) }}</textarea>
        <small class="form-hint">Le corps du guide, rédigé en <span class="hint-label">Markdown étendu</span>. Utilisez la barre d'outils de l'éditeur et l'aide ci-dessous. Blocs : <code>:::info Titre</code> / <code>:::conseil</code> / <code>:::danger</code> / <code>:::combo</code> / <code>:::flank</code> … <code>:::</code>. Couleurs : <code>&lt;span class="hl-blue"&gt;texte&lt;/span&gt;</code> (hl-blue, hl-red, hl-green, hl-gold). Tableaux GFM supportés.</small>
    </div>

    <details class="md-cheatsheet">
        <summary>Aide Markdown (syntaxe de base et étendue)</summary>
        <div class="md-cheatsheet__body">
            <h4>Syntaxe de base</h4>
            <table>
                <thead><tr><th>Résultat</th><th>Syntaxe</th></tr></thead>
                <tbody>
                    <tr><td>Titre de section</td><td><code>## Mon titre</code> (utilisez ## pour H2, ### pour H3)</td></tr>
                    <tr><td><strong>Gras</strong></td><td><code>**texte en gras**</code></td></tr>
                    <tr><td><em>Italique</em></td><td><code>*texte en italique*</code></td></tr>
                    <tr><td>Liste à puces</td><td><code>- premier point</code></td></tr>
                    <tr><td>Liste numérotée</td><td><code>1. premier point</code></td></tr>
                    <tr><td><a>Lien</a></td><td><code>[texte du lien](https://exemple.fr)</code></td></tr>
                    <tr><td>Image</td><td><code>![description](https://exemple.fr/image.png)</code></td></tr>
                    <tr><td>Citation</td><td><code>&gt; texte cité</code></td></tr>
                    <tr><td>Code en ligne</td><td><code>`votre_code`</code></td></tr>
                    <tr><td>Bloc de code</td><td><code>```langage</code> puis le code puis <code>```</code></td></tr>
                    <tr><td>Tableau</td><td>lignes séparées par des tirets <code>|</code>, ex. <code>| Colonne 1 | Colonne 2 |</code></td></tr>
                </tbody>
            </table>

            <h4>Syntaxe étendue (propre au site)</h4>
            <table>
                <thead><tr><th>Résultat</th><th>Syntaxe</th></tr></thead>
                <tbody>
                    <tr><td>Bloc "info" bleu</td><td><code>:::info Un titre optionnel</code> … contenu … <code>:::</code></td></tr>
                    <tr><td>Bloc "conseil" vert</td><td><code>:::conseil</code> … <code>:::</code></td></tr>
                    <tr><td>Bloc "danger" rouge</td><td><code>:::danger</code> … <code>:::</code></td></tr>
                    <tr><td>Bloc "combo" (équipe)</td><td><code>:::combo</code> … <code>:::</code></td></tr>
                    <tr><td>Bloc "flank" (équipe)</td><td><code>:::flank</code> … <code>:::</code></td></tr>
                    <tr><td>Texte en couleur</td><td><code>&lt;span class="hl-blue"&gt;texte&lt;/span&gt;</code> (hl-blue, hl-red, hl-green, hl-gold)</td></tr>
                </tbody>
            </table>

            <h4>Référence complète</h4>
            <p>Pour une liste exhaustive de la syntaxe, consultez la cheat-sheet officielle :</p>
            <ul>
                <li><a class="cheatsheet-link" href="https://www.markdownguide.org/cheat-sheet/" target="_blank" rel="noopener">Markdown Guide – Cheat Sheet</a> (syntaxe de base étendue GFM)</li>
                <li><a class="cheatsheet-link" href="https://commonmark.org/help/" target="_blank" rel="noopener">CommonMark – référence interactive</a></li>
                <li><a class="cheatsheet-link" href="https://docs.github.com/fr/get-started/writing-on-github/getting-started-with-writing-and-formatting-on-github/basic-writing-and-formatting-syntax" target="_blank" rel="noopener">GitHub – Guide de base Markdown</a> (tableaux, listes de tâches, etc.)</li>
            </ul>
        </div>
    </details>

    <div>
        <button class="admin-btn admin-btn--primary" style="--accent:#fbb7fb;" type="submit">Enregistrer</button>
    </div>
</form>
@endsection
