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
    <h2><i class="fa-solid fa-pen"></i> {{ $item === null ? 'Nouvelle question FAQ' : 'Éditer la question' }}</h2>
    <p><a href="/admin/guides">← Retour à la liste</a></p>
</div>

<form action="{{ $item === null ? '/admin/faq/store' : '/admin/faq/'.(int) $item['id'].'/update' }}" method="POST" class="admin-form-stack admin-form-stack--wide" style="--accent:#fbb7fb;">
    @csrf

    <div class="form-group">
        <label class="admin-form-label" for="faq-cluster">Cluster (thème)</label>
        <select name="cluster" id="faq-cluster" class="form-control" required>
            @foreach ($clusters as $key => $label)
                <option value="{{ e($key) }}" {{ old('cluster', $item['cluster'] ?? '') === $key ? 'selected' : '' }}>{{ e($label) }}</option>
            @endforeach
        </select>
        <small class="form-hint">La rubrique dans laquelle la question sera classée sur la FAQ publique : <code>Découverte</code> (les bases), <code>Highlander</code> (le format 9v9), <code>Recrutement</code> (équipes/recrutement), <code>Apprentissage</code> (progresser), <code>Compétition</code> (ligues, matchs).</small>
    </div>

    <div class="form-group">
        <label class="admin-form-label" for="faq-q">Question</label>
        <textarea class="form-control" id="faq-q" name="question" required maxlength="300" rows="3" placeholder="Ex. : Comment rejoindre une équipe ?">{{ e(old('question', $item['question'] ?? '')) }}</textarea>
        <small class="form-hint">La question telle que les visiteurs la poseraient. <span class="hint-label">Soignez-la</span> : une question claire et précise est mieux comprise et mieux référencée. Formulez-la de façon naturelle (ex. « Comment puis-je... ? »).</small>
    </div>

    <div class="form-group">
        <label class="admin-form-label" for="faq-kw">Mots-clés SEO (séparés par des virgules)</label>
        <textarea class="form-control" id="faq-kw" name="keywords" maxlength="300" rows="2" placeholder="fortress forever, highlander, équipe, recrutement">{{ e(old('keywords', $item['keywords'] ?? '')) }}</textarea>
        <small class="form-hint">Termes associés à la question, utilisés pour améliorer le <span class="hint-label">référencement SEO</span> et le tri. Séparez-les par des <code>,</code>. <span class="hint-label">3 à 5 mots-clés</span> pertinents suffisent (ex. <code>highlander, 9v9, format</code>). Champ optionnel.</small>
    </div>

    <div class="form-group">
        <label class="admin-form-label" for="faq-order">Ordre</label>
        <input type="number" class="form-control" id="faq-order" name="sort_order" min="0" max="10000" value="{{ e((string) old('sort_order', $item['sort_order'] ?? 0)) }}">
        <small class="form-hint">Position de la question dans son cluster. <span class="hint-label">Tri ascendant</span> : la valeur la plus petite s'affiche en premier. Laissez 0 pour placer à la fin automatiquement.</small>
    </div>

    <div class="form-group checkbox-group" style="grid-template-columns:1fr;">
        <label class="admin-label"><input type="checkbox" name="is_published" value="1" {{ old('is_published', ($item['is_published'] ?? 1)) ? 'checked' : '' }}> Publié</label>
    </div>
    <small class="form-hint" style="margin-top:-6px;">Décochez pour masquer (<span class="hint-label">brouillon</span>) la question sur la FAQ publique tout en conservant le contenu.</small>

    <div class="form-group">
        <label class="admin-form-label" for="markdownEditor">Réponse Markdown</label>
        <textarea id="markdownEditor" name="answer_markdown" rows="16">{{ e(old('answer_markdown', $item['answer_markdown'] ?? '')) }}</textarea>
        <small class="form-hint">La réponse, rédigée en <span class="hint-label">Markdown étendu</span> (mêmes règles que les guides : blocs <code>:::info</code>/<code>:::conseil</code>/<code>:::danger</code>/<code>:::combo</code>/<code>:::flank</code>, couleurs <code>hl-*</code>, tableaux). Soyez précis et allez droit au but.</small>
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
