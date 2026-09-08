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
        Journal : <strong style="color: #fff; font-family: monospace;">admin_logs</strong>
        <span style="color: #555; margin: 0 10px;">|</span>
        Entrées enregistrées : <strong style="color: #3498db;">{{ number_format($total, 0, ',', ' ') }}</strong>
    </div>

    @if ($total > 0)
        <form action="/admin/view-logs" method="POST" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer l\'intégralité des logs ? Cette action est irréversible.');">
            @csrf
            <button type="submit" name="clear_logs" class="admin-btn admin-btn--danger">
                <i class="fa-solid fa-trash-can"></i> Nettoyer le journal
            </button>
        </form>
    @endif
</div>

<div class="log-filters" style="display: flex; gap: 12px; align-items: center; margin-bottom: 16px; flex-wrap: wrap;">
    <form action="/admin/view-logs" method="GET" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <select name="script" class="admin-input" style="min-width: 220px;">
            <option value="">— Tous les scripts —</option>
            @foreach ($scripts as $script)
                <option value="{{ $script }}" @selected($scriptFilter === $script)>{{ $script }}</option>
            @endforeach
        </select>
        <select name="status" class="admin-input" style="min-width: 160px;">
            <option value="">— Tous les statuts —</option>
            <option value="success" @selected($statusFilter === 'success')>Succès</option>
            <option value="failed" @selected($statusFilter === 'failed')>Échec</option>
            <option value="ignored" @selected($statusFilter === 'ignored')>Ignoré</option>
            <option value="started" @selected($statusFilter === 'started')>En cours</option>
        </select>
        <button type="submit" class="admin-btn"><i class="fa-solid fa-filter"></i> Filtrer</button>
    </form>
</div>

<h3 class="admin-section-title">
    <i class="fa-solid fa-terminal"></i> Derniers événements (du plus récent au plus ancien)
</h3>

<div class="admin-table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Statut</th>
                <th>Script</th>
                <th>Détail</th>
                <th>Origine</th>
                <th>IP</th>
                <th class="text-center">Durée</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                @php
                    $badgeColors = [
                        'success' => '#27ae60',
                        'failed' => '#c0392b',
                        'ignored' => '#f39c12',
                        'started' => '#7f8c8d',
                    ];
                    $originLabels = [
                        'cli' => 'SERVER (CLI / CRON)',
                        'webhook' => 'SERVER WEBHOOK',
                    ];
                    $origin = $originLabels[$log->context] ?? trim(($log->user_name ?? 'Visiteur') . ($log->user_steamid ? ' (' . $log->user_steamid . ')' : ''));
                @endphp
                <tr>
                    <td style="white-space: nowrap;">{{ $log->date_display }}</td>
                    <td><span class="badge" style="background: {{ $badgeColors[$log->status] ?? '#555' }}; color: #fff;">{{ strtoupper($log->status) }}</span></td>
                    <td style="font-family: monospace;">{{ $log->script }}</td>
                    <td>{{ $log->message ?? '—' }}</td>
                    <td>{{ $origin }}</td>
                    <td>{{ $log->ip ?? '—' }}</td>
                    <td class="text-center">{{ $log->duration_display ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center">Aucun enregistrement trouvé.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($logs->hasPages())
    <div style="display: flex; justify-content: center; align-items: center; gap: 16px; margin-top: 20px;">
        @if ($logs->onFirstPage())
            <span class="admin-btn" style="opacity: 0.4;"><i class="fa-solid fa-chevron-left"></i> Précédent</span>
        @else
            <a href="{{ $logs->previousPageUrl() }}" class="admin-btn"><i class="fa-solid fa-chevron-left"></i> Précédent</a>
        @endif

        <span style="color: #ccc;">Page {{ $logs->currentPage() }} / {{ $logs->lastPage() }}</span>

        @if ($logs->hasMorePages())
            <a href="{{ $logs->nextPageUrl() }}" class="admin-btn">Suivant <i class="fa-solid fa-chevron-right"></i></a>
        @else
            <span class="admin-btn" style="opacity: 0.4;">Suivant <i class="fa-solid fa-chevron-right"></i></span>
        @endif
    </div>
@endif
@endsection
