@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
<div class="admin-header" style="--accent:#fbb7fb;">
    <h2><i class="fa-solid fa-pen"></i> {{ $guide === null ? 'Nouveau guide' : 'Éditer : '.$guide['title'] }}</h2>
    <p><a href="/admin/guides">← Retour à la liste</a></p>
</div>

<form action="{{ $guide === null ? '/admin/guides/store' : '/admin/guides/'.(int) $guide['id'].'/update' }}" method="POST" class="admin-form-stack" style="--accent:#fbb7fb;max-width:900px;">
    @csrf
    <div class="form-group">
        <label class="admin-form-label" for="guide-slug">Slug URL</label>
        <input type="text" class="form-control" id="guide-slug" name="slug" required pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="128" value="{{ e(old('slug', $guide['slug'] ?? '')) }}" placeholder="debuter-tf2-competitif">
    </div>
    <div class="form-group">
        <label class="admin-form-label" for="guide-title">Titre</label>
        <input type="text" class="form-control" id="guide-title" name="title" required maxlength="191" value="{{ e(old('title', $guide['title'] ?? '')) }}">
    </div>
    <div class="form-group">
        <label class="admin-form-label" for="guide-meta">Meta description SEO (max 300)</label>
        <input type="text" class="form-control" id="guide-meta" name="meta_description" required maxlength="300" value="{{ e(old('meta_description', $guide['meta_description'] ?? '')) }}">
    </div>
    <div class="form-group">
        <label class="admin-form-label" for="guide-cat">Catégorie</label>
        <select name="category" id="guide-cat" class="form-control" required>
            @foreach ($categories as $cat)
                <option value="{{ e($cat) }}" {{ old('category', $guide['category'] ?? '') === $cat ? 'selected' : '' }}>{{ e($cat) }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group">
        <label class="admin-form-label" for="guide-excerpt">Extrait (carte hub)</label>
        <textarea name="excerpt" id="guide-excerpt" class="form-control" rows="2" maxlength="2000">{{ e(old('excerpt', $guide['excerpt'] ?? '')) }}</textarea>
    </div>
    <div class="form-group">
        <label class="admin-form-label" for="guide-order">Ordre</label>
        <input type="number" class="form-control" id="guide-order" name="sort_order" min="0" max="10000" value="{{ e((string) old('sort_order', $guide['sort_order'] ?? 0)) }}">
    </div>
    <div class="form-group checkbox-group">
        <label class="admin-label"><input type="checkbox" name="is_published" value="1" {{ old('is_published', ($guide['is_published'] ?? 1)) ? 'checked' : '' }}> Publié</label>
    </div>
    <div class="form-group">
        <label class="admin-form-label" for="markdownEditor">Contenu Markdown étendu</label>
        <textarea id="markdownEditor" name="content_markdown" rows="30" required>{{ e(old('content_markdown', $guide['content_markdown'] ?? '')) }}</textarea>
        <p>Blocs : <code>:::info Titre</code> / <code>:::conseil</code> / <code>:::danger</code> / <code>:::combo</code> / <code>:::flank</code> … <code>:::</code>. Couleurs : <code>&lt;span class="hl-blue"&gt;texte&lt;/span&gt;</code> (hl-blue, hl-red, hl-green, hl-gold). Tableaux GFM supportés.</p>
    </div>
    <div>
        <button class="admin-btn admin-btn--primary" style="--accent:#fbb7fb;" type="submit">Enregistrer</button>
    </div>
</form>
@endsection
