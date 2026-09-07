@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
<div class="admin-header" style="--accent:#fbb7fb;">
    <h2><i class="fa-solid fa-pen"></i> {{ $item === null ? 'Nouvelle question FAQ' : 'Éditer la question' }}</h2>
    <p><a href="/admin/guides">← Retour à la liste</a></p>
</div>

<form action="{{ $item === null ? '/admin/faq/store' : '/admin/faq/'.(int) $item['id'].'/update' }}" method="POST" class="admin-form-stack" style="--accent:#fbb7fb;max-width:900px;">
    @csrf
    <div class="form-group">
        <label class="admin-form-label" for="faq-cluster">Cluster</label>
        <select name="cluster" id="faq-cluster" class="form-control" required>
            @foreach ($clusters as $key => $label)
                <option value="{{ e($key) }}" {{ old('cluster', $item['cluster'] ?? '') === $key ? 'selected' : '' }}>{{ e($label) }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group">
        <label class="admin-form-label" for="faq-q">Question</label>
        <input type="text" class="form-control" id="faq-q" name="question" required maxlength="300" value="{{ e(old('question', $item['question'] ?? '')) }}">
    </div>
    <div class="form-group">
        <label class="admin-form-label" for="faq-kw">Mots-clés SEO (séparés par des virgules)</label>
        <input type="text" class="form-control" id="faq-kw" name="keywords" maxlength="300" value="{{ e(old('keywords', $item['keywords'] ?? '')) }}">
    </div>
    <div class="form-group">
        <label class="admin-form-label" for="faq-order">Ordre</label>
        <input type="number" class="form-control" id="faq-order" name="sort_order" min="0" max="10000" value="{{ e((string) old('sort_order', $item['sort_order'] ?? 0)) }}">
    </div>
    <div class="form-group checkbox-group">
        <label class="admin-label"><input type="checkbox" name="is_published" value="1" {{ old('is_published', ($item['is_published'] ?? 1)) ? 'checked' : '' }}> Publié</label>
    </div>
    <div class="form-group">
        <label class="admin-form-label" for="markdownEditor">Réponse Markdown</label>
        <textarea id="markdownEditor" name="answer_markdown" rows="12" required>{{ e(old('answer_markdown', $item['answer_markdown'] ?? '')) }}</textarea>
    </div>
    <div>
        <button class="admin-btn admin-btn--primary" style="--accent:#fbb7fb;" type="submit">Enregistrer</button>
    </div>
</form>
@endsection
