@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@push('styles')
<link rel="stylesheet" href="{{ hlfr_asset('/_css/guides.css') }}">
@endpush

@section('content')
<div class="guides-hero">
    <h1>FAQ — TF2 compétitif</h1>
    <p>Découverte, Highlander 9v9, recrutement, apprentissage, ETF2L et compétition France. Pour aller plus loin : <a href="/guides">les guides complets</a>.</p>
</div>

<div class="faq-search">
    <input type="search" id="faqSearch" placeholder="Rechercher : uber, trial, ETF2L, demoreview…" aria-label="Rechercher dans la FAQ">
</div>

@foreach ($grouped as $cluster => $items)
    @if (count($items) > 0)
        <section class="faq-cluster" data-cluster="{{ e($cluster) }}">
            <h2>{{ e($clusters[$cluster] ?? $cluster) }}</h2>
            @foreach ($items as $item)
                <details class="faq-item" data-question="{{ e(mb_strtolower($item['question'].' '.strip_tags($item['answer_html']))) }}">
                    <summary>{{ e($item['question']) }}</summary>
                    <div class="faq-item__answer">{!! $item['answer_html'] !!}</div>
                </details>
            @endforeach
        </section>
    @endif
@endforeach

<p class="guide-more"><a href="/guides/debuter-tf2-competitif">Commencer par le guide Débuter →</a></p>
@endsection

@push('scripts')
<script>
document.getElementById('faqSearch')?.addEventListener('input', (e) => {
    const q = e.target.value.trim().toLowerCase();
    document.querySelectorAll('.faq-item').forEach((el) => {
        el.style.display = q === '' || (el.dataset.question || '').includes(q) ? '' : 'none';
    });
    document.querySelectorAll('.faq-cluster').forEach((section) => {
        const visible = [...section.querySelectorAll('.faq-item')].some((el) => el.style.display !== 'none');
        section.style.display = visible ? '' : 'none';
    });
});
</script>
@endpush
