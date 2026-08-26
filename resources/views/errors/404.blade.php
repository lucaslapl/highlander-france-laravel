@extends('layouts.main')

@section('title', '404 - ' . config('app.name'))

@section('content')
<div class="error-page" style="text-align: center; padding: 60px 20px;">
    <h2 style="font-size: 3rem; margin: 0;">404</h2>
    <p>Page introuvable.</p>
    <p><a href="/" style="color: #ff7b00;">Retour à l'accueil</a></p>
    <div style="margin-top: 40px; display: flex; flex-wrap: wrap; justify-content: center; gap: 12px;">
        <a href="/matchs" style="color: #ff7b00;">Matchs ETF2L</a>
        <a href="/match-logs" style="color: #ff7b00;">Match Stats</a>
        <a href="/staff" style="color: #ff7b00;">L'équipe</a>
        <a href="/joueurs" style="color: #ff7b00;">Joueurs</a>
        <a href="/hall-of-fame" style="color: #ff7b00;">Hall of Fame</a>
    </div>
</div>
@endsection
