@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@section('content')
<h1>Maps ETF2L — Saison en cours</h1>
<p class="etf2l-maps-intro">Toutes les maps officielles ETF2L de la saison en cours, hébergées sur Highlander France. Téléchargement direct en <code>.bsp</code> — déposez via SFTP dans <code>storage/app/public/etf2l-maps/6v6/</code> et <code>9v9/</code>.</p>

@php
    $renderGrid = function(array $maps): string {
        ob_start();
        foreach ($maps as $m) {
            $exists = $m['exists'] ?? false;
            $size = $m['size_human'] ?? null;
            echo '<div class="etf2l-map-card'.($exists ? '' : ' is-missing').'">';
            echo '<div class="etf2l-map-card-name">'.e($m['label']).'</div>';
            echo '<div class="etf2l-map-card-file">'.e($m['file']).($size ? ' <span class="etf2l-map-size">· '.$size.'</span>' : '').'</div>';
            if ($exists) {
                echo '<a class="etf2l-map-dl" href="'.e($m['url']).'" download><i class="fa-solid fa-download"></i> Télécharger .bsp</a>';
            } else {
                echo '<span class="etf2l-map-missing"><i class="fa-solid fa-triangle-exclamation"></i> Bientôt disponible</span>';
            }
            echo '</div>';
        }
        return ob_get_clean();
    };
@endphp

<section class="etf2l-maps-section">
    <h2><i class="fa-solid fa-users"></i> 6v6 — Sixes</h2>
    <div class="etf2l-maps-grid">
        {!! $renderGrid($maps6v6) !!}
    </div>
</section>

<section class="etf2l-maps-section">
    <h2><i class="fa-solid fa-people-group"></i> 9v9 — Highlander</h2>
    <div class="etf2l-maps-grid">
        {!! $renderGrid($maps9v9) !!}
    </div>
</section>

<p class="etf2l-maps-help"><i class="fa-solid fa-circle-info"></i> Placez les fichiers dans <code>TF/tf/maps/</code>. Les maps manquantes apparaîtront automatiquement après dépôt SFTP.</p>
@endsection
