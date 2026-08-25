@extends('layouts.admin')

@section('title', $title)
@section('description', $description)

@section('content')

<div class="admin-back">
    <a href="/admin/dashboard">
        <i class="fa-solid fa-arrow-left"></i> Retour au Panel Admin
    </a>
</div>

<div class="admin-header" style="--accent: #3498db;">
    <h2><i class="fa-solid fa-database"></i> Inspecteur de Journaux (Logs)</h2>
    <p>Analyse en direct des rapports d'exécution de l'API et détection des pannes des scripts CRON.</p>
</div>

<div class="log-meta-box">
    <div style="font-size: 14px; color: #ccc;">
        Fichier ciblé : <strong style="color: #fff; font-family: monospace;">cron_debug.log</strong>
        <span style="color: #555; margin: 0 10px;">|</span>
        Taille actuelle : <strong style="color: #3498db;">{!! e($fileSize) !!}</strong>
    </div>

    @if ($fileExists && $bytes > 0)
        <form action="/admin/view-logs" method="POST" onsubmit="return confirm('Êtes-vous sûr de vouloir vider l\'intégralité des logs ? Cette action est irréversible.');">
            @csrf
            <button type="submit" name="clear_logs" class="admin-btn admin-btn--danger">
                <i class="fa-solid fa-trash-can"></i> Nettoyer le journal
            </button>
        </form>
    @endif
</div>

<h3 class="admin-section-title">
    <i class="fa-solid fa-terminal"></i> 100 derniers événements (du plus récent au plus ancien)
</h3>

<div class="log-viewer">{!! e($logContent) !!}</div>
@endsection
