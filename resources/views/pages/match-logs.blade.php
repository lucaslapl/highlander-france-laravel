@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@section('content')
<h1>Stats des Matchs</h1>
<p>Consultez les logs détaillés des matchs de Highlander France.</p>

<div id="filters">
    <input type="text" id="filter-date" placeholder="Rechercher par date (ex: 27/04)">
    <input type="text" id="filter-map" placeholder="Rechercher une map…">
</div>

<div class="table-scroll">
    <table id="logsTable" border="0" cellspacing="20">
        <thead>
            <tr>
                <th>Date</th>
                <th>Carte</th>
                <th>Titre</th>
            </tr>
        </thead>
        <tbody id="logs">
            @forelse ($initialLogs as $log)
                <tr>
                    <td>{{ $log['date'] ? date('d/m/Y H:i', (int)$log['date']) : '' }}</td>
                    <td>{{ e($log['map'] ?? '') }}</td>
                    <td>
                        <div class="log-title-cell flex align-center gap-10">
                            <a class="log-link" href="/log/{{ (int)$log['id'] }}">{{ e($log['title'] ?? 'Log #'.(int)$log['id']) }}</a>
                            <a class="log-external" href="https://logs.tf/{{ (int)$log['id'] }}" target="_blank" rel="noopener" title="Voir sur logs.tf"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3">Chargement des logs...</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div id="pagination" class="pagination"></div>
<noscript>
    <p style="margin-top:12px;">JavaScript requis pour les filtres. <a href="/log/{{ !empty($initialLogs[0]['id']) ? (int)$initialLogs[0]['id'] : '' }}">Voir le dernier log</a></p>
</noscript>

@push('scripts')
@include('partials.scroll-animation')
@include('partials.match-logs-script')
@endpush
@endsection
