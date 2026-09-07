@extends('layouts.main')

@section('title', $title)
@section('description', $description)
@section('og_type', 'article')

@push('styles')
<link rel="stylesheet" href="{{ hlfr_asset('/_css/guides.css') }}">
@endpush

@section('content')
<div class="guide-layout">
    <aside class="guide-aside" aria-label="Tous les guides">
        <h2>Guides</h2>
        <ul>
            @foreach ($guides as $g)
                <li><a href="/guides/{{ e($g['slug']) }}" class="{{ $g['slug'] === $guide['slug'] ? 'is-current' : '' }}">{{ e($g['title']) }}</a></li>
            @endforeach
        </ul>
        <h2 style="margin-top:14px;">Aide</h2>
        <ul>
            <li><a href="/faq">FAQ compétitif</a></li>
            <li><a href="https://discord.gg/BMuj3cqUFt" target="_blank" rel="noopener">Discord HL France</a></li>
        </ul>
    </aside>

    <article class="guide-content">
        <p><a href="/guides">← Tous les guides</a> · {{ e($categoryLabel) }}</p>
        <div>{!! $html !!}</div>

        <nav class="guide-nav" aria-label="Guides précédent et suivant">
            <span>@if ($prev)<a href="/guides/{{ e($prev['slug']) }}">← {{ e($prev['title']) }}</a>@endif</span>
            <span>@if ($next)<a href="/guides/{{ e($next['slug']) }}">{{ e($next['title']) }} →</a>@endif</span>
        </nav>
        <p><a href="/faq">Une question ? Consulte la FAQ →</a></p>
    </article>
</div>
@endsection
