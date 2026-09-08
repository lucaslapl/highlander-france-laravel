@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')
@include('admin.partials.alerts')
<div class="admin-header" style="--accent:#fbb7fb;">
    <h2><i class="fa-solid fa-book-open"></i> Guides & FAQ</h2>
    <p>Contenus Markdown étendus : tableaux, blocs <code>:::info|conseil|danger|combo|flank</code> et couleurs <code>&lt;span class="hl-blue|hl-red|hl-green|hl-gold"&gt;</code>.</p>
    <p>
        <a class="admin-btn" href="/admin/guides/nouveau">+ Nouveau guide</a>
        <a class="admin-btn" href="/admin/faq/nouveau">+ Question FAQ</a>
        <a class="admin-btn" href="/guides" target="_blank" rel="noopener">Voir le hub public</a>
    </p>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-book"></i> Guides ({{ count($guides) }})</h3>
<div class="admin-table-scroll">
    <table class="admin-table">
        <thead><tr><th>Titre</th><th>Slug</th><th>Catégorie</th><th>Ordre</th><th>Publié</th><th>Actions</th></tr></thead>
        <tbody>
        @foreach ($guides as $guide)
            <tr>
                <td><a href="/guides/{{ e($guide['slug']) }}" target="_blank" rel="noopener">{{ e($guide['title']) }}</a></td>
                <td><code>{{ e($guide['slug']) }}</code></td>
                <td>{{ e($guide['category']) }}</td>
                <td>{{ (int) $guide['sort_order'] }}</td>
                <td>{{ (int) $guide['is_published'] ? 'Oui' : 'Non' }}</td>
                <td>
                    <a class="btn-icon" href="/admin/guides/{{ (int) $guide['id'] }}/edit" title="Éditer"><i class="fa-solid fa-pen"></i></a>
                    <form action="/admin/guides/{{ (int) $guide['id'] }}/toggle" method="POST" style="display:inline;">@csrf<button class="btn-icon" title="Publier/masquer"><i class="fa-solid fa-eye"></i></button></form>
                    <form action="/admin/guides/{{ (int) $guide['id'] }}/delete" method="POST" style="display:inline;" onsubmit="return confirm('Supprimer ce guide ?');">@csrf<button class="btn-icon" title="Supprimer"><i class="fa-solid fa-trash"></i></button></form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<h3 class="admin-section-title"><i class="fa-solid fa-circle-question"></i> FAQ ({{ count($faqItems) }})</h3>
<div class="admin-table-scroll">
    <table class="admin-table">
        <thead><tr><th>Question</th><th>Cluster</th><th>Ordre</th><th>Publié</th><th>Actions</th></tr></thead>
        <tbody>
        @foreach ($faqItems as $item)
            <tr>
                <td>{{ e($item['question']) }}</td>
                <td>{{ e($clusters[$item['cluster']] ?? $item['cluster']) }}</td>
                <td>{{ (int) $item['sort_order'] }}</td>
                <td>{{ (int) $item['is_published'] ? 'Oui' : 'Non' }}</td>
                <td>
                    <a class="btn-icon" href="/admin/faq/{{ (int) $item['id'] }}/edit" title="Éditer"><i class="fa-solid fa-pen"></i></a>
                    <form action="/admin/faq/{{ (int) $item['id'] }}/toggle" method="POST" style="display:inline;">@csrf<button class="btn-icon" title="Publier/masquer"><i class="fa-solid fa-eye"></i></button></form>
                    <form action="/admin/faq/{{ (int) $item['id'] }}/delete" method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cette question ?');">@csrf<button class="btn-icon" title="Supprimer"><i class="fa-solid fa-trash"></i></button></form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
