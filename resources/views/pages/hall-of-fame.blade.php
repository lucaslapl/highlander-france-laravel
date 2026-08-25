@extends('layouts.main')

@section('title', $title)
@section('description', $description)

@push('scripts')
<script src="/_js/leaderboard.js"></script>
<script src="/_js/search_players.js"></script>
@endpush

@section('content')
<div class="leaderboard-filter flex space-around align-center">
    <div class="leaderboard-tabs" id="leaderboard-mode-tabs">
        <button class="tab-btn active" onclick="switchLeaderboard(this, '9v9')">Highlander (9v9)</button>
        <button class="tab-btn" onclick="switchLeaderboard(this, '6s')">Sixes (6v6)</button>
    </div>

    <div class="search-container">
        <input type="text" id="player-search-input" placeholder="Rechercher un joueur..." autocomplete="off">
        <div id="search-results-dropdown" class="search-dropdown" style="display: none;"></div>
    </div>
</div>

<div class="leaderboard-filter flex space-around align-center">
    <div class="leaderboard-tabs" id="leaderboard-category-tabs">
        <button class="tab-btn active" onclick="switchCategory(this, 'matches')">Matchs</button>
        <button class="tab-btn" onclick="switchCategory(this, 'kills')">Kills</button>
        <button class="tab-btn" onclick="switchCategory(this, 'heal')">Heal</button>
        <button class="tab-btn" onclick="switchCategory(this, 'dpm')">DPM</button>
    </div>
</div>

<div class="leaderboard-note">
    Tu ne te vois pas dans le classement ? <a href="/login">Connecte-toi avec ton compte Steam</a> pour apparaître dans les statistiques de la communauté.
</div>

<div class="leaderboard-container">
    <table id="leaderboard-table">
        <thead id="leaderboard-thead">
            <tr>
                <th>Rang</th>
                <th>Joueur</th>
                <th>Matchs</th>
            </tr>
        </thead>
        <tbody id="leaderboard-body">
        </tbody>
    </table>
</div>

@push('scripts')
@include('partials.scroll-animation')
@include('partials.hall-of-fame-script')
@endpush
@endsection
