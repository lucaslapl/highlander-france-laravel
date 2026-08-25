@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@section('content')
<h2>Stats des Matchs</h2>
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

        </tbody>
    </table>
</div>

<div id="pagination" class="pagination"></div>

@push('scripts')
@include('partials.scroll-animation')
@include('partials.match-logs-script')
@endpush
@endsection
