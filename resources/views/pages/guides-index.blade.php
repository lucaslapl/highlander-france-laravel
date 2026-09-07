@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@push('styles')
<link rel="stylesheet" href="{{ hlfr_asset('/_css/guides.css') }}">
@endpush

@section('content')
<div class="guides-hero">
    <h1>Guides TF2 compétitif</h1>
    <p>Débuter en compétitif, comprendre le Highlander 9v9, maîtriser les 9 classes, découvrir le 6v6 et optimiser sa config. Rédigés par Highlander France à partir de nos guides Steam et de la formation « L’highlander comme si vous y étiez ».</p>
    <p><a href="/faq">Voir la FAQ compétitif TF2 →</a></p>
</div>

<div class="guides-grid">
    @foreach ($guides as $guide)
        <article class="guide-card">
            <span class="guide-card__cat">{{ $categories[$guide['category']] ?? $guide['category'] }}</span>
            <h2><a href="/guides/{{ e($guide['slug']) }}">{{ e($guide['title']) }}</a></h2>
            @if (! empty($guide['excerpt']))
                <p>{{ e($guide['excerpt']) }}</p>
            @endif
            <a class="guide-card__link" href="/guides/{{ e($guide['slug']) }}">Lire le guide →</a>
        </article>
    @endforeach
</div>
@endsection
